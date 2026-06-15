<?php

namespace PostAnalyzer\API;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints for inline, AI-assisted editing of report fields.
 *
 *   POST /wp-json/postanalyzer/v1/suggest-field
 *   POST /wp-json/postanalyzer/v1/update-field
 *
 * Both are gated by the per-post `edit_post` capability and a strict field
 * allow-list so the routes can never write arbitrary post meta.
 *
 * @package PostAnalyzer
 * @since   2.1.0
 */
class Edit_Field {

	/**
	 * Editable fields and where each value is stored.
	 *
	 * 'meta'        → post meta key (update_post_meta on the post)
	 * 'post'        → wp_update_post column on the post
	 * 'attachment'  → wp_update_post column on the attachment
	 * 'attach_meta' → attachment meta key (update_post_meta on the attachment)
	 */
	private const FIELDS = [
		'post_title'      => [ 'kind' => 'post',        'column' => 'post_title',   'sanitize' => 'text' ],
		'post_excerpt'    => [ 'kind' => 'post',        'column' => 'post_excerpt', 'sanitize' => 'textarea' ],
		'slug'            => [ 'kind' => 'post',        'column' => 'post_name',    'sanitize' => 'slug' ],
		'seo_title'       => [ 'kind' => 'meta',        'key'    => 'rank_math_title',         'sanitize' => 'text' ],
		'seo_description' => [ 'kind' => 'meta',        'key'    => 'rank_math_description',   'sanitize' => 'textarea' ],
		'focus_keyword'   => [ 'kind' => 'meta',        'key'    => 'rank_math_focus_keyword', 'sanitize' => 'text' ],
		'alt'             => [ 'kind' => 'attach_meta', 'key'    => '_wp_attachment_image_alt', 'sanitize' => 'text' ],
		'image_title'     => [ 'kind' => 'attachment',  'column' => 'post_title',   'sanitize' => 'text' ],
	];

	/** Fields that operate on an attachment and therefore need attachment_id. */
	private const ATTACHMENT_FIELDS = [ 'alt', 'image_title' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$args = [
			'post_id'       => [
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => static fn( $v ) => abs( (int) $v ),
			],
			'field'         => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => sanitize_key( (string) $v ),
				'validate_callback' => static fn( $v ) => isset( self::FIELDS[ $v ] ),
			],
			'attachment_id' => [
				'required'          => false,
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => static fn( $v ) => abs( (int) $v ),
			],
		];

		register_rest_route( 'postanalyzer/v1', '/suggest-field', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'suggest_field' ],
			'permission_callback' => [ $this, 'can_edit' ],
			'args'                => $args,
		] );

		register_rest_route( 'postanalyzer/v1', '/update-field', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'update_field' ],
			'permission_callback' => [ $this, 'can_edit' ],
			'args'                => $args + [
				'value' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );
	}

	/** Per-post capability check used by both routes. */
	public function can_edit( \WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
	}

	// ── Suggest ────────────────────────────────────────────────────────────────

	public function suggest_field( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$field   = (string) $request->get_param( 'field' );
		$att_id  = (int) $request->get_param( 'attachment_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post.', 'postanalyzer' ), [ 'status' => 404 ] );
		}

		$ctx = $this->build_context( $post, $field, $att_id );

		$ai          = new AI_Helper();
		$suggestions = $ai->generate_field_suggestions( $field, $ctx );

		if ( is_wp_error( $suggestions ) ) {
			return new \WP_Error(
				'suggest_failed',
				/* translators: %s: AI error message. */
				sprintf( __( 'Could not generate suggestions: %s', 'postanalyzer' ), $suggestions->get_error_message() ),
				[ 'status' => 502 ]
			);
		}

		$suggestions = \PostAnalyzer\Plugin::recursive_html_entity_decode( $suggestions );

		return rest_ensure_response( [ 'suggestions' => array_values( $suggestions ) ] );
	}

	// ── Update ───────────────────────────────────────────────────────────────

	public function update_field( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$field   = (string) $request->get_param( 'field' );
		$att_id  = (int) $request->get_param( 'attachment_id' );
		$raw     = (string) $request->get_param( 'value' );

		$spec  = self::FIELDS[ $field ];
		$value = $this->sanitize_value( $raw, $spec['sanitize'] );

		if ( $field === 'post_title' && $value === '' ) {
			return new \WP_Error( 'empty_title', __( 'The post title cannot be empty.', 'postanalyzer' ), [ 'status' => 400 ] );
		}

		// Attachment-targeted fields.
		if ( in_array( $field, self::ATTACHMENT_FIELDS, true ) ) {
			if ( $att_id < 1 || get_post_type( $att_id ) !== 'attachment' ) {
				return new \WP_Error( 'invalid_attachment', __( 'A valid media-library image is required to edit this field.', 'postanalyzer' ), [ 'status' => 400 ] );
			}

			if ( $spec['kind'] === 'attach_meta' ) {
				update_post_meta( $att_id, $spec['key'], $value );
			} else { // attachment column
				$result = wp_update_post( [ 'ID' => $att_id, $spec['column'] => $value ], true );
				if ( is_wp_error( $result ) ) {
					return new \WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
				}
			}

			return rest_ensure_response( [ 'success' => true, 'value' => $value ] );
		}

		// Post-targeted fields.
		switch ( $spec['kind'] ) {
			case 'meta':
				update_post_meta( $post_id, $spec['key'], $value );
				break;

			case 'post':
				$result = wp_update_post( [ 'ID' => $post_id, $spec['column'] => $value ], true );
				if ( is_wp_error( $result ) ) {
					return new \WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
				}
				break;
		}

		return rest_ensure_response( [ 'success' => true, 'value' => $value ] );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	private function sanitize_value( string $value, string $type ): string {
		return match ( $type ) {
			'slug'     => sanitize_title( $value ),
			'textarea' => sanitize_textarea_field( $value ),
			default    => sanitize_text_field( $value ),
		};
	}

	/**
	 * Context passed to the AI for field suggestions.
	 *
	 * @return array<string,mixed>
	 */
	private function build_context( \WP_Post $post, string $field, int $att_id ): array {
		$ctx = [
			'title'    => $post->post_title,
			'content'  => $post->post_content,
			'keywords' => (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
		];

		$ctx['current'] = match ( $field ) {
			'post_title'      => $post->post_title,
			'post_excerpt'    => $post->post_excerpt,
			'slug'            => $post->post_name,
			'seo_title'       => (string) get_post_meta( $post->ID, 'rank_math_title', true ),
			'seo_description' => (string) get_post_meta( $post->ID, 'rank_math_description', true ),
			'focus_keyword'   => (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
			default           => '',
		};

		if ( in_array( $field, self::ATTACHMENT_FIELDS, true ) && $att_id > 0 ) {
			$att = get_post( $att_id );
			if ( $att ) {
				$file = get_attached_file( $att_id );
				$ctx['filename'] = $file ? basename( $file ) : '';
				$ctx['caption']  = $att->post_excerpt;
				$ctx['current']  = $field === 'alt'
					? (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true )
					: $att->post_title;
			}
		}

		return $ctx;
	}
}
