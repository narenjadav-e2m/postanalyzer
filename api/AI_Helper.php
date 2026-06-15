<?php

namespace PostAnalyzer\API;

defined( 'ABSPATH' ) || exit;

/**
 * AI_Helper — unified AI platform abstraction.
 *
 * Supports: Gemini, OpenAI (ChatGPT), Groq.
 * Handles: prompt building, HTTP dispatch, retry logic, JSON parsing.
 *
 * @package PostAnalyzer
 * @since   2.0.0
 */
class AI_Helper {

	// ── Platform endpoints & models ───────────────────────────────────────────

	private const ENDPOINTS = [
		'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
		'openai' => 'https://api.openai.com/v1/chat/completions',
		'groq'   => 'https://api.groq.com/openai/v1/chat/completions',
	];

	private const MODELS = [
		'openai' => 'gpt-4o-mini',
		'groq'   => 'llama-3.3-70b-versatile',
	];

	private const MAX_RETRIES    = 2;
	private const TIMEOUT        = 25;
	private const MAX_TOKENS     = 800;
	private const TEMPERATURE    = 0.4;
	private const MAX_SUGGESTIONS = 10;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Generate SEO-friendly URL slug suggestions.
	 *
	 * @param array{title:string,content:string,keywords:string|array,post_id?:int} $ctx
	 * @return string[]|\WP_Error
	 */
	public function generate_url_suggestions( array $ctx ): array|\WP_Error {
		$prompt = $this->build_slug_prompt( $ctx );
		$result = $this->dispatch( $prompt, 'slugs' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->slugs_to_urls( $result, (int) ( $ctx['post_id'] ?? 0 ) );
	}

	/**
	 * Generate AI-powered content improvement suggestions.
	 *
	 * @param array $ctx
	 * @return string[]|\WP_Error
	 */
	public function generate_ai_suggestions( array $ctx ): array|\WP_Error {
		$prompt = $this->build_suggestions_prompt( $ctx );
		return $this->dispatch( $prompt, 'suggestions' );
	}

	/**
	 * Generate replacement suggestions for a single editable field
	 * (post_title, post_excerpt, slug, seo_title, seo_description,
	 * focus_keyword, alt, image_title).
	 *
	 * @param string $field
	 * @param array  $ctx
	 * @return string[]|\WP_Error
	 */
	public function generate_field_suggestions( string $field, array $ctx ): array|\WP_Error {
		$prompt = $this->build_field_prompt( $field, $ctx );
		$result = $this->dispatch( $prompt, 'field' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		switch ( $field ) {
			case 'slug':
				$result = array_values( array_unique( array_filter( array_map( 'sanitize_title', $result ) ) ) );
				break;

			// Rank Math's recommended ranges. Order in-range options first so the
			// user is offered length-valid choices that won't re-trip the SEO
			// "too short / too long" checks in Analyze_Post::evaluate_seo_issues().
			case 'seo_description':
				$result = $this->prefer_length( $result, 120, 160 );
				break;

			case 'seo_title':
				$result = $this->prefer_length( $result, 50, 60 );
				break;
		}

		return array_slice( $result, 0, 5 );
	}

	/**
	 * Reorder suggestions so those whose character length falls within
	 * [$min, $max] come first; the rest follow, sorted by how close they are to
	 * the midpoint. Nothing is dropped — the user always sees options even if the
	 * model ignored the length rule.
	 *
	 * @param string[] $items
	 * @return string[]
	 */
	private function prefer_length( array $items, int $min, int $max ): array {
		$target = intdiv( $min + $max, 2 );

		$scored = array_map(
			static fn( $s ) => [ 's' => $s, 'len' => mb_strlen( (string) $s ) ],
			$items
		);

		$in_range = array_values( array_filter( $scored, static fn( $x ) => $x['len'] >= $min && $x['len'] <= $max ) );
		$rest     = array_values( array_filter( $scored, static fn( $x ) => $x['len'] < $min || $x['len'] > $max ) );

		usort( $rest, static fn( $a, $b ) => abs( $a['len'] - $target ) <=> abs( $b['len'] - $target ) );

		return array_map( static fn( $x ) => $x['s'], array_merge( $in_range, $rest ) );
	}

	// ── Dispatch ──────────────────────────────────────────────────────────────

	private function dispatch( string $prompt, string $mode ): array|\WP_Error {
		$platform = Settings::get_active_platform();

		return match ( strtolower( (string) $platform ) ) {
			'gemini' => $this->call_gemini( $prompt, $mode ),
			'openai' => $this->call_openai( $prompt, $mode ),
			'groq'   => $this->call_groq( $prompt, $mode ),
			default  => new \WP_Error(
				'unsupported_platform',
				sprintf( 'Unsupported AI platform: %s', esc_html( $platform ) )
			),
		};
	}

	// ── Platform callers ──────────────────────────────────────────────────────

	private function call_gemini( string $prompt, string $mode ): array|\WP_Error {
		$key = Settings::get_active_api_key();
		$url = add_query_arg( [ 'key' => $key ], self::ENDPOINTS['gemini'] );

		return $this->post_and_parse(
			platform: 'gemini',
			url:      $url,
			headers:  [ 'Content-Type' => 'application/json' ],
			payload:  [
				'contents'         => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ] ],
				'generationConfig' => [
					'temperature'     => self::TEMPERATURE,
					'maxOutputTokens' => self::MAX_TOKENS,
				],
			],
			extract: static fn( array $d ) => $d['candidates'][0]['content']['parts'][0]['text'] ?? '',
			mode:    $mode,
		);
	}

	private function call_openai( string $prompt, string $mode ): array|\WP_Error {
		return $this->post_and_parse(
			platform: 'openai',
			url:      self::ENDPOINTS['openai'],
			headers:  [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . Settings::get_active_api_key(),
			],
			payload:  [
				'model'       => self::MODELS['openai'],
				'messages'    => [
					[ 'role' => 'system', 'content' => 'Return ONLY valid JSON. No markdown, no extra text.' ],
					[ 'role' => 'user',   'content' => $prompt ],
				],
				'temperature' => self::TEMPERATURE,
				'max_tokens'  => self::MAX_TOKENS,
			],
			extract: static fn( array $d ) => $d['choices'][0]['message']['content'] ?? '',
			mode:    $mode,
		);
	}

	private function call_groq( string $prompt, string $mode ): array|\WP_Error {
		return $this->post_and_parse(
			platform: 'groq',
			url:      self::ENDPOINTS['groq'],
			headers:  [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . Settings::get_active_api_key(),
			],
			payload:  [
				'model'           => self::MODELS['groq'],
				'messages'        => [
					[ 'role' => 'system', 'content' => 'Return ONLY valid JSON. No markdown, no extra text.' ],
					[ 'role' => 'user',   'content' => $prompt ],
				],
				'temperature'     => self::TEMPERATURE,
				'max_tokens'      => self::MAX_TOKENS,
				'response_format' => [ 'type' => 'json_object' ],
			],
			extract: static fn( array $d ) => $d['choices'][0]['message']['content'] ?? '',
			mode:    $mode,
		);
	}

	// ── Core HTTP + retry + parse ─────────────────────────────────────────────

	private function post_and_parse(
		string   $platform,
		string   $url,
		array    $headers,
		array    $payload,
		callable $extract,
		string   $mode
	): array|\WP_Error {

		$last_error = null;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {

			$response = wp_remote_post( $url, [
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			] );

			if ( is_wp_error( $response ) ) {
				$last_error = new \WP_Error(
					$platform . '_http_error',
					$response->get_error_message()
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				$last_error = new \WP_Error(
					$platform . '_api_error',
					sprintf( '%s API returned HTTP %d', strtoupper( $platform ), $code ),
					[ 'body' => substr( $body, 0, 500 ) ]
				);
				continue;
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				$last_error = new \WP_Error( $platform . '_bad_json', 'Invalid JSON response from ' . strtoupper( $platform ) );
				continue;
			}

			$text   = (string) $extract( $data );
			$parsed = $this->extract_json_object( $text );

			if ( $parsed === null ) {
				$last_error = new \WP_Error( $platform . '_parse_error', 'Could not parse JSON from AI response.', [ 'raw' => substr( $text, 0, 300 ) ] );
				continue;
			}

			$items = $parsed['suggestions'] ?? [];

			if ( ! is_array( $items ) || empty( $items ) ) {
				$last_error = new \WP_Error( $platform . '_no_suggestions', 'AI returned an empty suggestions array.' );
				continue;
			}

			return match ( $mode ) {
				'slugs' => $this->clean_slugs( $items ),
				'field' => $this->clean_field( $items ),
				default => $this->clean_suggestions( $items ),
			};
		}

		return $last_error ?? new \WP_Error( $platform . '_failed', 'AI request failed after ' . self::MAX_RETRIES . ' attempts.' );
	}

	// ── Output cleaners ───────────────────────────────────────────────────────

	private function clean_slugs( array $items ): array {
		$slugs = array_values( array_unique( array_filter(
			array_map( 'sanitize_title', $items )
		) ) );
		return array_slice( $slugs, 0, 5 );
	}

	/**
	 * Plain-text cleaner for single-field replacement suggestions.
	 * Unlike clean_suggestions(), strips ALL HTML — these become field values.
	 */
	private function clean_field( array $items ): array {
		$clean = array_values( array_unique( array_filter( array_map( static function ( $s ) {
			$s = wp_strip_all_tags( (string) $s );
			$s = trim( preg_replace( '/\s+/', ' ', $s ) );
			$s = preg_replace( '/^\s*[-•*\d.)\]]+\s*/', '', $s ); // drop list markers
			return trim( $s, " \t\n\r\0\x0B\"'" );
		}, $items ) ) ) );

		// Keep a wider pool than the 5 we ultimately show, so callers can filter
		// (e.g. by length for seo_description) and still surface enough options.
		return array_slice( $clean, 0, 12 );
	}

	private function clean_suggestions( array $items ): array {
		$allowed = [ 'strong' => [], 'em' => [], 'code' => [], 'br' => [] ];

		$clean = array_values( array_filter( array_map( static function ( $s ) use ( $allowed ) {
			$s = wp_kses( (string) $s, $allowed );
			$s = trim( preg_replace( '/[ \t]+/', ' ', $s ) );
			$s = preg_replace( '/\s*\n\s*/', ' ', $s );
			$s = preg_replace( '/^\s*[-•*\d.]+\s*/', '', $s );
			return $s;
		}, $items ) ) );

		return array_slice( $clean, 0, self::MAX_SUGGESTIONS );
	}

	// ── Prompts ───────────────────────────────────────────────────────────────

	private function build_slug_prompt( array $ctx ): string {
		$title    = wp_strip_all_tags( (string) ( $ctx['title']    ?? '' ) );
		$content  = wp_strip_all_tags( (string) ( $ctx['content']  ?? '' ) );
		$keywords = is_array( $ctx['keywords'] ?? null )
			? implode( ', ', $ctx['keywords'] )
			: (string) ( $ctx['keywords'] ?? '' );

		return <<<PROMPT
You are an SEO specialist. Generate exactly 5 SEO-friendly URL slugs for a WordPress post.

STRICT OUTPUT RULES:
- Output ONLY valid JSON: {"suggestions":["slug-one","slug-two","slug-three","slug-four","slug-five"]}
- No markdown, no backticks, no explanations
- Slugs: lowercase, hyphens only, no special chars, 3-8 words each, unique

Post Title: {$title}
Focus Keywords: {$keywords}
Content excerpt: {$content}
PROMPT;
	}

	private function build_suggestions_prompt( array $ctx ): string {
		$title       = wp_strip_all_tags( (string) ( $ctx['title']           ?? '' ) );
		$excerpt     = wp_strip_all_tags( (string) ( $ctx['excerpt']         ?? '' ) );
		$seo_title   = wp_strip_all_tags( (string) ( $ctx['seo_title']       ?? '' ) );
		$seo_desc    = wp_strip_all_tags( (string) ( $ctx['seo_description'] ?? '' ) );
		$word_count  = (int) ( $ctx['word_count'] ?? 0 );
		$has_img     = ! empty( $ctx['has_featured_image'] ) ? 'yes' : 'no';
		$missing_alt = (int) ( $ctx['missing_alt_count'] ?? 0 );
		$content     = mb_substr( wp_strip_all_tags( (string) ( $ctx['content'] ?? '' ) ), 0, 1500 );

		$kw_arr = is_array( $ctx['keywords'] ?? null )
			? $ctx['keywords']
			: array_filter( array_map( 'trim', explode( ',', (string) ( $ctx['keywords'] ?? '' ) ) ) );
		$keywords = implode( ', ', $kw_arr );

		return <<<PROMPT
You are a senior SEO strategist and WordPress content auditor.

Analyze the post data below and return 5-10 specific, actionable improvement suggestions focused on:
- SEO title & meta description optimization
- Keyword usage and semantic coverage
- Content depth, structure, and readability
- Image optimization and accessibility
- CTR and engagement improvements

STRICT OUTPUT RULES:
- Output ONLY valid JSON: {"suggestions":["suggestion 1","suggestion 2",...]}
- No markdown, no backticks, no leading bullets or numbers
- Allowed HTML inside suggestion strings ONLY: <strong>, <em>, <code>, <br>
- Each suggestion must be a complete, specific sentence
- No generic advice — be specific to the data provided

POST DATA:
Title: {$title}
SEO Title: {$seo_title}
Meta Description: {$seo_desc}
Keywords: {$keywords}
Excerpt: {$excerpt}
Word Count: {$word_count}
Has Featured Image: {$has_img}
Images Missing Alt Text: {$missing_alt}
Content (excerpt): {$content}
PROMPT;
	}

	/**
	 * Build a per-field replacement prompt. All fields share the same strict
	 * JSON {"suggestions":[...]} contract enforced in build/dispatch.
	 */
	private function build_field_prompt( string $field, array $ctx ): string {
		$title    = wp_strip_all_tags( (string) ( $ctx['title']    ?? '' ) );
		$content  = mb_substr( wp_strip_all_tags( (string) ( $ctx['content'] ?? '' ) ), 0, 1800 );
		$keywords = is_array( $ctx['keywords'] ?? null )
			? implode( ', ', $ctx['keywords'] )
			: (string) ( $ctx['keywords'] ?? '' );
		$current  = wp_strip_all_tags( (string) ( $ctx['current'] ?? '' ) );

		// Image-specific context (alt / image_title).
		$filename = (string) ( $ctx['filename'] ?? '' );
		$caption  = (string) ( $ctx['caption']  ?? '' );

		// Over-generate for the length-sensitive SEO fields so the caller can
		// rank/keep the ones that fall inside the recommended character range.
		$count = in_array( $field, [ 'seo_description', 'seo_title' ], true ) ? 8 : 5;

		$rules = <<<RULES
STRICT OUTPUT RULES:
- Output ONLY valid JSON in the exact shape: {"suggestions":["option one","option two", ...]}
- {$count} distinct options, each plain text (no HTML, no markdown, no backticks, no numbering, no surrounding quotes)
RULES;

		$instruction = match ( $field ) {
			'post_title'      => "Write {$count} alternative, compelling WordPress post titles (about 50–60 characters) that reflect the actual content below and include the focus keyword naturally. No clickbait.",
			'post_excerpt'    => "Write {$count} concise post excerpts, each 1–2 sentences (~30–40 words), accurately summarizing the content below.",
			'slug'            => "Write {$count} SEO-friendly URL slugs: lowercase, hyphen-separated, 3–7 words, focus-keyword-first, no stop-word padding.",
			'seo_title'       => "Write {$count} SEO meta titles. Each MUST be 50–60 characters (count characters carefully — never under 50 or over 60). Start with the focus keyword, reflect the content below, and be click-worthy.",
			'seo_description' => "Write {$count} SEO meta descriptions for this exact post.\n"
				. "HARD LENGTH RULE: each description MUST be between 120 and 160 characters. Count the characters before answering. Anything under 120 characters is INVALID — do not output it. Aim for ~150 characters.\n"
				. "Each must: (1) lead with or include the focus keyword, (2) accurately summarize THIS post using its title and content below (do not invent facts), (3) use active voice, (4) end with a soft call-to-action (e.g. \"Learn how\", \"Find out\", \"Get the details\"). Make them genuinely search-optimized and distinct from each other — not generic filler.",
			'focus_keyword'   => "Suggest {$count} focus keywords or short keyphrases (2–4 words) this post genuinely targets, based on the content below.",
			'alt'             => "Write {$count} descriptive, accessible alt-text options for the image below (max 125 characters, describe what the image shows, no \"image of\").",
			'image_title'     => "Write {$count} short, descriptive image titles (about 50–60 characters).",
			default           => "Write {$count} improved alternatives for this field.",
		};

		$image_block = ( $field === 'alt' || $field === 'image_title' )
			? "Image filename: {$filename}\nImage caption: {$caption}\n"
			: '';

		return <<<PROMPT
You are an expert WordPress SEO strategist and copy editor. Base every suggestion on the real post title and content provided — never generic boilerplate.
{$instruction}

{$rules}

POST CONTEXT:
Post Title: {$title}
Focus Keyword(s): {$keywords}
Current value: {$current}
{$image_block}Post content (excerpt): {$content}
PROMPT;
	}

	// ── JSON parsing ──────────────────────────────────────────────────────────

	private function extract_json_object( string $text ): ?array {
		$text = trim( $text );

		// Strip markdown fences.
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		$text = trim( $text );

		// Direct parse.
		$decoded = json_decode( $text, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return $decoded;
		}

		// Grab first {...} block.
		if ( preg_match( '/\{(?:[^{}]|(?R))*\}/s', $text, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	// ── Slug → URL ────────────────────────────────────────────────────────────

	private function slugs_to_urls( array $slugs, int $post_id = 0 ): array {
		$urls = [];

		$rewrite_base = '';
		if ( $post_id > 0 ) {
			$post  = get_post( $post_id );
			$ptype = $post ? get_post_type_object( $post->post_type ) : null;
			$rewrite_base = $ptype->rewrite['slug'] ?? '';
		}

		foreach ( $slugs as $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( $slug === '' ) continue;

			$urls[] = $rewrite_base
				? home_url( '/' . trim( $rewrite_base, '/' ) . '/' . $slug . '/' )
				: home_url( '/' . $slug . '/' );
		}

		return array_values( array_unique( $urls ) );
	}
}
