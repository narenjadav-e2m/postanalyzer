<?php

namespace PostAnalyzer\API;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint: GET /wp-json/postanalyzer/v1/posts
 *
 * Returns a paginated list of posts for the admin UI selector.
 * Supports multiple post types via the postanalyzer_post_types filter.
 *
 * @package PostAnalyzer
 * @since   2.0.0
 */
class Posts {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'postanalyzer/v1',
			'/posts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_posts' ],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => [
					'per_page'  => [
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => -1,
						'sanitize_callback' => static fn( $v ) => (int) $v,
					],
					'page'      => [
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => static fn( $v ) => abs( (int) $v ),
					],
					'search'    => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static fn( $v ) => sanitize_text_field( (string) $v ),
					],
					'post_type' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static fn( $v ) => sanitize_key( (string) $v ),
					],
					'status'    => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static fn( $v ) => sanitize_key( (string) $v ),
					],
				],
			]
		);
	}

	public function get_posts( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page  = (int) $request->get_param( 'per_page' );
		$page      = (int) $request->get_param( 'page' );
		$search    = (string) $request->get_param( 'search' );
		$post_type = (string) $request->get_param( 'post_type' );
		$status    = (string) $request->get_param( 'status' );

		// Allowed post types (filterable).
		$allowed_types = (array) apply_filters( 'postanalyzer_post_types', [ 'post', 'page' ] );
		$query_types   = ( $post_type && in_array( $post_type, $allowed_types, true ) )
			? [ $post_type ]
			: $allowed_types;

		// Allowed statuses.
		$all_statuses   = [ 'publish', 'pending', 'draft', 'future', 'private' ];
		$query_statuses = ( $status && in_array( $status, $all_statuses, true ) )
			? [ $status ]
			: $all_statuses;

		// The admin UI requests every post (per_page <= 0) and filters client-side.
		// Cap that "fetch all" so a large site cannot exhaust memory; filterable for
		// installs that genuinely need more.
		$max_items = (int) apply_filters( 'postanalyzer_max_posts', 2000 );
		$limit     = $per_page > 0 ? min( $per_page, $max_items ) : $max_items;

		$query_args = [
			'post_type'      => $query_types,
			'posts_per_page' => $limit,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => $query_statuses,
			'fields'         => 'ids',
			'no_found_rows'  => $per_page <= 0,
		];

		if ( $search !== '' ) {
			$query_args['s'] = $search;
		}

		$q = new \WP_Query( $query_args );

		$items = [];
		foreach ( $q->posts as $id ) {
			$items[] = [
				'id'     => (int) $id,
				'title'  => get_the_title( $id ) ?: sprintf( __( 'Post #%d', 'postanalyzer' ), $id ),
				'date'   => get_the_date( 'c', $id ),
				'slug'   => get_post_field( 'post_name', $id ),
				'link'   => get_permalink( $id ) ?: '',
				'status' => ucfirst( get_post_status( $id ) ?: 'unknown' ),
				'type'   => get_post_type( $id ) ?: 'post',
			];
		}

		$items = \PostAnalyzer\Plugin::recursive_html_entity_decode( $items );

		$response = rest_ensure_response( $items );

		if ( ! $query_args['no_found_rows'] ) {
			$response->header( 'X-WP-Total',      (string) $q->found_posts );
			$response->header( 'X-WP-TotalPages', (string) $q->max_num_pages );
		}

		return $response;
	}
}
