<?php

namespace PostAnalyzer\API;

defined('ABSPATH') || exit;

class AI_Helper
{
    // Hardcoded endpoints + models (no Settings model options)
    private string $gemini_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
    private string $openai_endpoint = 'https://api.openai.com/v1/chat/completions';
    private string $groq_endpoint   = 'https://api.groq.com/openai/v1/chat/completions';

    private string $openai_model = 'gpt-4o-mini';
    private string $groq_model   = 'llama-3.1-8b-instant';

    private function request_by_active_platform(string $prompt, string $mode = 'slugs', int $retry = 2)
    {
        $platform = Settings::get_active_platform();

        return match (strtolower((string)$platform)) {
            'gemini' => $this->request_gemini($prompt, $mode, $retry),
            'openai' => $this->request_openai($prompt, $mode, $retry),
            'groq'   => $this->request_groq($prompt, $mode, $retry),
            default  => new \WP_Error('unsupported_platform', 'Unsupported AI platform: ' . $platform),
        };
    }

    /**
     * @return array|\WP_Error Array of slug suggestions, or WP_Error on failure
     */
    public function generate_url_suggestions(array $ctx)
    {
        $prompt = $this->build_slug_prompt($ctx);
        $slugs = $this->request_by_active_platform($prompt, 'slugs', 2);

        if (is_wp_error($slugs)) return $slugs;

        // convert slugs to full URLs (if you do this in helper)
        return $this->slugs_to_urls($slugs, $ctx['post_id'] ?? 0);
    }

    public function generate_ai_suggestions(array $ctx)
    {
        $prompt = $this->build_suggestions_prompt($ctx);
        return $this->request_by_active_platform($prompt, 'suggestions', 2);
    }


    private function request_gemini(string $prompt, string $mode = 'slugs', int $retry = 2)
    {
        $api_key = Settings::get_active_api_key();
        $url = add_query_arg(['key' => $api_key], $this->gemini_endpoint);

        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => 700,
            ],
        ];

        return $this->post_and_parse(
            platform: 'gemini',
            url: $url,
            headers: ['Content-Type' => 'application/json'],
            payload: $payload,
            retry: $retry,
            extract_text: fn(array $data) => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            mode: $mode
        );
    }

    private function request_openai(string $prompt, string $mode = 'slugs', int $retry = 2)
    {
        $api_key = Settings::get_active_api_key();

        $payload = [
            'model' => $this->openai_model,
            'messages' => [
                ['role' => 'system', 'content' => 'Return ONLY valid JSON. No markdown.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
            'max_tokens'  => 700,
        ];

        return $this->post_and_parse(
            platform: 'openai',
            url: $this->openai_endpoint,
            headers: [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            payload: $payload,
            retry: $retry,
            extract_text: fn(array $data) => $data['choices'][0]['message']['content'] ?? '',
            mode: $mode
        );
    }

    private function request_groq(string $prompt, string $mode = 'slugs', int $retry = 2)
    {
        $api_key = Settings::get_active_api_key();

        $payload = [
            'model' => $this->groq_model,
            'messages' => [
                ['role' => 'system', 'content' => 'Return ONLY valid JSON. No markdown.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
            'max_tokens'  => 700,
            'response_format' => ['type' => 'json_object'],
        ];

        return $this->post_and_parse(
            platform: 'groq',
            url: $this->groq_endpoint,
            headers: [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            payload: $payload,
            retry: $retry,
            extract_text: fn(array $data) => $data['choices'][0]['message']['content'] ?? '',
            mode: $mode
        );
    }

    /**
     * Shared HTTP+retry+JSON parse.
     *
     * @param callable $extract_text fn(array $data): string
     * @return array|\WP_Error
     */
    private function post_and_parse(
        string $platform,
        string $url,
        array $headers,
        array $payload,
        int $retry,
        callable $extract_text,
        string $mode = 'slugs'
    ) {
        $attempt = 0;

        do {
            $attempt++;

            $response = wp_remote_post($url, [
                'timeout' => 20,
                'headers' => $headers,
                'body'    => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                if ($attempt > $retry) {
                    return new \WP_Error($platform . '_http_error', $response->get_error_message());
                }
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);

            if ($code < 200 || $code >= 300) {
                if ($attempt > $retry) {
                    return new \WP_Error(
                        $platform . '_api_error',
                        strtoupper($platform) . ' API request failed with status ' . $code,
                        ['response_body' => $body]
                    );
                }
                continue;
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                if ($attempt > $retry) {
                    return new \WP_Error($platform . '_bad_json', strtoupper($platform) . ' returned invalid JSON.', ['raw' => $body]);
                }
                continue;
            }

            $text = (string) $extract_text($data);

            // More robust: handle JSON wrapped in extra text
            $parsed = $this->extract_json_object($text) ?? $this->parse_ai_json($text);

            if ($mode === 'slugs') {
                $items = $parsed['suggestions'] ?? [];
                if (is_array($items)) {
                    $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', $items))));
                    if (!empty($slugs)) return $slugs;
                }
            } else { // suggestions mode
                if ($mode === 'suggestions') {
                    $items = $parsed['suggestions'] ?? [];
                    if (is_array($items)) {

                        $allowed = [
                            'strong' => [],
                            'em'     => [],
                            'code'   => [],
                            'br'     => [],
                        ];

                        $clean = array_values(array_filter(array_map(function ($s) use ($allowed) {
                            $s = (string) $s;

                            // Remove any disallowed HTML but keep <strong>/<em>/<code>/<br>
                            $s = wp_kses($s, $allowed);

                            // Trim + normalize spaces (don’t kill <br>)
                            $s = trim(preg_replace('/[ \t]+/', ' ', $s));
                            $s = preg_replace('/\s*\n\s*/', ' ', $s);

                            // Optional: prevent bullet-looking output even if model slips
                            $s = preg_replace('/^\s*[-•]+\s*/', '', $s);

                            return $s;
                        }, $items)));

                        $clean = array_slice($clean, 0, 10);
                        if (!empty($clean)) return $clean;
                    }
                }
            }

            if ($attempt > $retry) {
                return new \WP_Error(
                    $platform . '_no_valid_output',
                    strtoupper($platform) . ' did not return valid output.',
                    ['raw_text' => $text, 'mode' => $mode]
                );
            }
        } while ($attempt <= $retry);

        return new \WP_Error($platform . '_no_valid_output', 'AI did not return valid output');
    }

    private function build_slug_prompt(array $context): string
    {
        $title    = isset($context['title']) ? wp_strip_all_tags((string) $context['title']) : '';
        $content  = isset($context['content']) ? wp_strip_all_tags((string) $context['content']) : '';
        $keywords = isset($context['keywords']) ? (array) $context['keywords'] : [];

        $keywords_str = implode(', ', array_map('sanitize_text_field', $keywords));

        return
            "You are an SEO assistant.\n" .
            "Generate 5 SEO-friendly URL slugs for a WordPress post.\n\n" .
            "Rules:\n" .
            "- output ONLY valid JSON\n" .
            "- no markdown, no backticks\n" .
            "- JSON format: {\"suggestions\":[\"slug-one\",\"slug-two\",...]} \n" .
            "- slugs must be lowercase, hyphen-separated, no special characters\n" .
            "- each suggestion must be unique\n\n" .
            "Title: {$title}\n" .
            "Keywords: {$keywords_str}\n" .
            "Content (excerpt): " . mb_substr($content, 0, 700);
    }

    /**
     * Build prompt for AI suggestions.
     */
    private function build_suggestions_prompt(array $context): string
    {
        $title       = isset($context['title']) ? wp_strip_all_tags((string) $context['title']) : '';
        $excerpt     = isset($context['excerpt']) ? wp_strip_all_tags((string) $context['excerpt']) : '';
        $content     = isset($context['content']) ? wp_strip_all_tags((string) $context['content']) : '';
        $seo_title   = isset($context['seo_title']) ? wp_strip_all_tags((string) $context['seo_title']) : '';
        $seo_desc    = isset($context['seo_description']) ? wp_strip_all_tags((string) $context['seo_description']) : '';
        $keywords    = $context['keywords'] ?? [];
        $keywordsArr = is_array($keywords) ? $keywords : array_map('trim', explode(',', (string) $keywords));
        $keywordsArr = array_values(array_filter(array_map('sanitize_text_field', $keywordsArr)));

        $word_count  = isset($context['word_count']) ? (int) $context['word_count'] : 0;
        $has_featured = !empty($context['has_featured_image']) ? 'yes' : 'no';
        $missing_alt_count = isset($context['missing_alt_count']) ? (int) $context['missing_alt_count'] : 0;

        $keywords_str = implode(', ', $keywordsArr);

        return
            $prompt =
            "You are a senior SEO strategist and technical WordPress content auditor with 15+ years of experience in on-page SEO, content optimization, and search intent alignment.\n\n" .

            "Your task is to analyze the provided WordPress post data and generate precise, high-impact, actionable improvements that increase:\n" .
            "- Search engine rankings\n" .
            "- Click-through rate (CTR)\n" .
            "- Content depth and topical authority\n" .
            "- Technical SEO quality\n" .
            "- User engagement and readability\n\n" .

            "Focus only on practical improvements that the editor can immediately apply.\n\n" .

            "--------------------------------------------------\n" .
            "OUTPUT RULES (STRICT — DO NOT VIOLATE):\n\n" .
            "- Output ONLY valid JSON.\n" .
            "- No markdown.\n" .
            "- No explanations.\n" .
            "- No extra text.\n" .
            "- EXACT JSON structure:\n" .
            "  {\"suggestions\":[\"...\",\"...\",\"...\"]}\n\n" .
            "- 'suggestions' must contain 5 to 10 items.\n" .
            "- Each suggestion MUST be a complete sentence.\n" .
            "- Each suggestion MUST be INNER HTML only.\n" .
            "- DO NOT wrap suggestions in <p>, <div>, <ul>, <li>, etc.\n" .
            "- Allowed HTML tags ONLY: <strong>, <em>, <code>, <br>.\n" .
            "- Do NOT use any other HTML tags.\n" .
            "- No links.\n" .
            "- No images.\n" .
            "- No emojis.\n" .
            "- No leading hyphens, bullets, or numbering.\n" .
            "- Do NOT include any additional JSON keys.\n\n" .

            "If data is missing, base suggestions only on available fields.\n\n" .

            "--------------------------------------------------\n" .
            "EVALUATION FRAMEWORK:\n\n" .

            "1. Title Optimization\n" .
            "   - Keyword placement\n" .
            "   - Emotional triggers\n" .
            "   - Power words\n" .
            "   - Length optimization (50–60 characters ideal)\n\n" .

            "2. Meta Description Optimization\n" .
            "   - CTR improvement\n" .
            "   - Clear benefit\n" .
            "   - Call-to-action\n" .
            "   - 150–160 characters target\n\n" .

            "3. Keyword Usage\n" .
            "   - Primary keyword presence in title, intro, subheadings\n" .
            "   - Semantic keyword opportunities\n" .
            "   - Keyword stuffing risks\n\n" .

            "4. Content Quality\n" .
            "   - Depth vs word count\n" .
            "   - Missing sections\n" .
            "   - Structural improvements (H2/H3 recommendations)\n" .
            "   - Search intent alignment\n\n" .

            "5. Internal SEO Signals\n" .
            "   - Featured image usage\n" .
            "   - Missing alt text issues\n" .
            "   - Readability improvements\n" .
            "   - Snippet optimization opportunities\n\n" .

            "6. Engagement & Conversion\n" .
            "   - Missing CTA\n" .
            "   - FAQ opportunities\n" .
            "   - Featured snippet formatting\n" .
            "   - Suggest list formatting where useful (describe only, do not use <ul> tags)\n\n" .

            "Only suggest improvements that are relevant to the provided data.\n" .
            "Avoid generic advice like 'improve SEO' — every suggestion must be specific.\n\n" .

            "--------------------------------------------------\n" .
            "POST DATA:\n" .
            "Title: {$title}\n" .
            "Excerpt: {$excerpt}\n" .
            "SEO Title: {$seo_title}\n" .
            "SEO Description: {$seo_desc}\n" .
            "Keywords: {$keywords_str}\n" .
            "Word count: {$word_count}\n" .
            "Has featured image: {$has_featured}\n" .
            "Images missing alt text: {$missing_alt_count}\n\n" .

            "--------------------------------------------------\n" .
            "CONTENT (trimmed):\n" .
            "{$content}";
    }

    /**
     * Convert slug strings into absolute URLs.
     *
     * @param string[] $slugs
     * @param int      $post_id Optional: used to preserve post type base in URL.
     * @return string[]
     */
    private function slugs_to_urls(array $slugs, int $post_id = 0): array
    {
        $urls = [];

        foreach ($slugs as $slug) {
            $slug = sanitize_title($slug);
            if ($slug === '') {
                continue;
            }

            // Try to respect CPT rewrite base if post_id is provided.
            if ($post_id > 0) {
                $post = get_post($post_id);
                if ($post) {
                    $ptype = get_post_type_object($post->post_type);
                    $rewrite_slug = $ptype->rewrite['slug'] ?? '';
                    if (!empty($rewrite_slug)) {
                        $urls[] = home_url('/' . trim($rewrite_slug, '/') . '/' . $slug . '/');
                        continue;
                    }
                }
            }

            // Fallback: plain /{slug}/
            $urls[] = home_url('/' . $slug . '/');
        }

        return array_values(array_unique($urls));
    }

    private function parse_ai_json(string $text): array
    {
        $text = trim($text);

        // Remove markdown fences if provider adds them anyway
        $text = preg_replace('/^```(json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $json = json_decode($text, true);
        return is_array($json) ? $json : [];
    }

    private function extract_json_object(string $text): ?array
    {
        $text = trim($text);

        // Quick path
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fallback: grab first {...}
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
