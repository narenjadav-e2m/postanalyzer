<?php

namespace PostAnalyzer\API;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint: POST /wp-json/postanalyzer/v1/analyze-post
 *
 * @package PostAnalyzer
 * @since   2.0.0
 */
class Analyze_Post {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'postanalyzer/v1',
			'/analyze-post',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => static fn( $v ) => abs( (int) $v ),
					],
				],
			]
		);
	}

	// ── Main handler ─────────────────────────────────────────────────────────

	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_status, [ 'publish', 'pending', 'draft', 'private', 'future' ], true ) ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid or inaccessible post.', 'postanalyzer' ), [ 'status' => 404 ] );
		}

		// Object-level check: the route only verifies the generic edit_posts cap, so
		// without this a contributor could analyze (and ship to the AI) other users'
		// private or draft content they cannot edit.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to analyze this post.', 'postanalyzer' ), [ 'status' => 403 ] );
		}

		$seo        = $this->collect_seo( $post_id );
		$images     = $this->collect_images( $post );
		$word_count = $this->count_words( $post->post_content );

		$ai = new AI_Helper();

		// Count missing alt text across attached images *and* the featured image.
		$alt_pool = $images['attached'];
		if ( ! empty( $images['featured'] ) ) {
			$alt_pool[] = $images['featured'];
		}
		$missing_alt = $this->count_missing_alt( $alt_pool );

		$url_suggestions = $ai->generate_url_suggestions( [
			'post_id'  => $post_id,
			'title'    => $post->post_title,
			'content'  => $post->post_content,
			'keywords' => $seo['keywords'],
		] );

		$ai_suggestions = $ai->generate_ai_suggestions( [
			'title'              => $post->post_title,
			'excerpt'            => get_the_excerpt( $post ),
			'content'            => $post->post_content,
			'seo_title'          => $seo['title'],
			'seo_description'    => $seo['description'],
			'keywords'           => $seo['keywords'],
			'word_count'         => $word_count,
			'has_featured_image' => ! empty( $images['featured'] ),
			'missing_alt_count'  => $missing_alt,
		] );

		if ( is_wp_error( $url_suggestions ) ) {
			$url_suggestions = [];
		}
		if ( is_wp_error( $ai_suggestions ) ) {
			$ai_suggestions = [ 'AI suggestions unavailable: ' . $ai_suggestions->get_error_message() ];
		}

		$response = [
			'url'             => get_the_permalink( $post_id ) ?: '',
			'title'           => get_the_title( $post_id ),
			'excerpt'         => $post->post_excerpt,
			'slug'            => $post->post_name,
			'author'          => get_the_author_meta( 'display_name', $post->post_author ),
			'published_date'  => get_the_date( 'F j, Y g:i A', $post_id ),
			'updated_date'    => get_the_modified_date( 'F j, Y g:i A', $post_id ),
			'post_status'     => $post->post_status,
			'post_type'       => $post->post_type,
			'categories'      => get_the_category_list( ', ', '', $post_id ) ?: '',
			'tags'            => get_the_tag_list( '', ', ', '', $post_id ) ?: '',
			'word_count'      => $word_count,
			'seo'             => $seo,
			'featured_image'  => $images['featured'],
			'attached_images' => $images['attached'],
			'url_suggestions' => $url_suggestions,
			'ai_suggestions'  => $ai_suggestions,
		];

		$response = \PostAnalyzer\Plugin::recursive_html_entity_decode( $response );

		return rest_ensure_response( $response );
	}

	// ── SEO ───────────────────────────────────────────────────────────────────

	private function collect_seo( int $post_id ): array {
		$seo_title  = (string) get_post_meta( $post_id, 'rank_math_title', true );
		$seo_desc   = (string) get_post_meta( $post_id, 'rank_math_description', true );
		$focus_kw   = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		$robots_raw = get_post_meta( $post_id, 'rank_math_robots', true );

		$robots = is_array( $robots_raw )
			? $robots_raw
			: array_filter( array_map( 'trim', explode( ',', (string) $robots_raw ) ) );

		$keywords = $focus_kw
			? array_values( array_filter( array_map( 'trim', explode( ',', $focus_kw ) ) ) )
			: [];

		$issues = $this->evaluate_seo_issues( $seo_title, $seo_desc );

		return [
			'title'         => $seo_title,
			'description'   => $seo_desc,
			'keywords'      => $keywords,
			'focus_keyword' => $focus_kw,
			'robots'      => array_values( $robots ),
			'is_noindex'  => in_array( 'noindex',  $robots, true ) ? 'yes' : 'no',
			'is_nofollow' => in_array( 'nofollow', $robots, true ) ? 'yes' : 'no',
			'issues'      => $issues,
			'score'       => $this->seo_score( $seo_title, $seo_desc, $keywords, $issues ),
		];
	}

	private function evaluate_seo_issues( string $seo_title, string $seo_desc ): array {
		$issues = [];

		if ( $seo_title === '' ) {
			$issues[] = __( 'Missing SEO title', 'postanalyzer' );
		} else {
			$len = mb_strlen( $seo_title );
			if ( $len < 30 ) $issues[] = __( 'SEO title is too short (< 30 chars)', 'postanalyzer' );
			if ( $len > 70 ) $issues[] = __( 'SEO title is too long (> 70 chars)', 'postanalyzer' );
		}

		if ( $seo_desc === '' ) {
			$issues[] = __( 'Missing meta description', 'postanalyzer' );
		} else {
			$len = mb_strlen( $seo_desc );
			if ( $len < 120 ) $issues[] = __( 'Meta description is too short (< 120 chars)', 'postanalyzer' );
			if ( $len > 320 ) $issues[] = __( 'Meta description is too long (> 320 chars)', 'postanalyzer' );
		}

		return $issues;
	}

	private function seo_score( string $title, string $desc, array $keywords, array $issues ): int {
		$score = 100;
		$score -= count( $issues ) * 15;
		if ( empty( $keywords ) ) $score -= 10;
		return max( 0, $score );
	}

	// ── Word count ────────────────────────────────────────────────────────────

	private function count_words( string $content ): int {
		$clean = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		if ( $clean === '' ) {
			return 0;
		}
		// Unicode-aware: str_word_count() undercounts CJK and other non-Latin scripts.
		if ( preg_match_all( '/[\p{L}\p{N}]+/u', $clean, $m ) ) {
			return count( $m[0] );
		}
		return str_word_count( $clean );
	}

	// ── Images ────────────────────────────────────────────────────────────────

	private function collect_images( \WP_Post $post ): array {
		$post_id     = $post->ID;
		$featured_id = (int) get_post_thumbnail_id( $post_id );
		$featured    = $featured_id ? $this->attachment_data( $featured_id ) : null;

		$seen_ids  = $featured_id ? [ $featured_id ] : [];
		$seen_srcs = [];
		$attached  = [];

		// 1. Parent-attached media library images.
		$parent_ids = get_posts( [
			'post_type'      => 'attachment',
			'posts_per_page' => -1,
			'post_parent'    => $post_id,
			'post_mime_type' => 'image',
			'fields'         => 'ids',
		] );

		foreach ( $parent_ids as $att_id ) {
			$this->maybe_add_image( (int) $att_id, $attached, $seen_ids, $seen_srcs, $featured_id );
		}

		// 2. <img> tags in content.
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $m ) ) {
			foreach ( $m[1] as $src ) {
				$att_id = (int) attachment_url_to_postid( $src );
				if ( $att_id ) {
					$this->maybe_add_image( $att_id, $attached, $seen_ids, $seen_srcs, $featured_id );
				} else {
					$norm = $this->normalize_url( $src );
					if ( ! in_array( $norm, $seen_srcs, true ) ) {
						$seen_srcs[] = $norm;
						$attached[]  = [
							'id'       => null,
							'src'      => esc_url_raw( $src ),
							'alt'      => '',
							'title'    => '',
							'caption'  => '',
							'filename' => basename( (string) parse_url( $src, PHP_URL_PATH ) ),
							'width'    => null,
							'height'   => null,
							'type'     => 'external',
						];
					}
				}
			}
		}

		// 3. Gutenberg blocks.
		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			$this->images_from_blocks( parse_blocks( $post->post_content ), $attached, $seen_ids, $seen_srcs, $featured_id );
		}

		return [
			'featured' => $featured,
			'attached' => array_values( $attached ),
		];
	}

	private function maybe_add_image( int $att_id, array &$list, array &$seen_ids, array &$seen_srcs, int $featured_id ): void {
		if ( $att_id === $featured_id || in_array( $att_id, $seen_ids, true ) ) return;
		$data = $this->attachment_data( $att_id );
		if ( ! $data ) return;
		$norm = $this->normalize_url( $data['src'] );
		if ( in_array( $norm, $seen_srcs, true ) ) return;
		$seen_ids[]  = $att_id;
		$seen_srcs[] = $norm;
		$list[]      = $data;
	}

	private function images_from_blocks( array $blocks, array &$list, array &$seen_ids, array &$seen_srcs, int $featured_id ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';

			if ( $name === 'core/image' ) {
				$att_id = (int) ( $block['attrs']['id'] ?? 0 );
				if ( ! $att_id && ! empty( $block['innerHTML'] ) ) {
					preg_match( '/wp-image-(\d+)/', $block['innerHTML'], $mx );
					$att_id = isset( $mx[1] ) ? (int) $mx[1] : 0;
				}
				if ( $att_id ) $this->maybe_add_image( $att_id, $list, $seen_ids, $seen_srcs, $featured_id );

			} elseif ( $name === 'core/gallery' ) {
				foreach ( ( $block['attrs']['ids'] ?? [] ) as $id ) {
					$this->maybe_add_image( (int) $id, $list, $seen_ids, $seen_srcs, $featured_id );
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$this->images_from_blocks( $block['innerBlocks'], $list, $seen_ids, $seen_srcs, $featured_id );
				}

			} elseif ( $name === 'core/media-text' && ! empty( $block['attrs']['mediaId'] ) ) {
				$this->maybe_add_image( (int) $block['attrs']['mediaId'], $list, $seen_ids, $seen_srcs, $featured_id );

			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$this->images_from_blocks( $block['innerBlocks'], $list, $seen_ids, $seen_srcs, $featured_id );
			}
		}
	}

	private function attachment_data( int $att_id ): ?array {
		if ( get_post_type( $att_id ) !== 'attachment' ) return null;
		$att      = get_post( $att_id );
		if ( ! $att ) return null;
		$url      = wp_get_attachment_url( $att_id );
		$meta     = wp_get_attachment_metadata( $att_id );
		$alt      = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
		$filepath = get_attached_file( $att_id );

		return [
			'id'          => $att_id,
			'src'         => $url ?: '',
			'alt'         => (string) $alt,
			'title'       => $att->post_title ?: '',
			'caption'     => $att->post_excerpt ?: '',
			'description' => $att->post_content ?: '',
			'filename'    => $filepath ? basename( $filepath ) : basename( $url ?: '' ),
			'width'       => $meta['width']  ?? null,
			'height'      => $meta['height'] ?? null,
			'type'        => 'media-library',
			'mime_type'   => $att->post_mime_type,
			'file_size'   => ( $filepath && file_exists( $filepath ) ) ? filesize( $filepath ) : null,
			'upload_date' => $att->post_date,
			'sizes'       => isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : [],
		];
	}

	private function normalize_url( string $url ): string {
		$url    = preg_replace( '/-\d+x\d+(\.[^.]+)$/', '$1', $url );
		$parsed = parse_url( $url );
		return ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' ) . ( $parsed['path'] ?? '' );
	}

	private function count_missing_alt( array $images ): int {
		return count( array_filter( $images, static fn( $img ) => empty( $img['alt'] ) ) );
	}
}
