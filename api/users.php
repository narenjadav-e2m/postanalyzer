<?php

namespace PostAnalyzer\API;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint: GET /wp-json/postanalyzer/v1/users
 *
 * @package PostAnalyzer
 * @since   2.0.0
 */
class Users {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'postanalyzer/v1',
			'/users',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_users' ],
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'args'                => [
					'per_page' => [
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 500,
						'sanitize_callback' => static fn( $v ) => abs( (int) $v ),
					],
					'role'     => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static fn( $v ) => sanitize_key( (string) $v ),
					],
				],
			]
		);
	}

	public function get_users( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$role     = (string) $request->get_param( 'role' );

		$args = [
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => $per_page,
			'fields'  => [ 'ID', 'display_name', 'user_email', 'user_login' ],
		];

		if ( ! empty( $role ) ) {
			$args['role'] = $role;
		} else {
			$args['capability'] = [ 'publish_posts' ];
		}

		$users_query = new \WP_User_Query( $args );
		$users       = $users_query->get_results();

		$items = [];
		foreach ( (array) $users as $user ) {
			$items[] = [
				'id'    => (int) $user->ID,
				'name'  => $user->display_name ?: $user->user_login,
				'email' => $user->user_email,
				'login' => $user->user_login,
			];
		}

		return rest_ensure_response( $items );
	}
}
