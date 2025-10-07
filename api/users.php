<?php

namespace PostAnalyzer\API;

defined('ABSPATH') || exit;

/**
 * REST endpoint that returns a list of users for the admin UI dropdown.
 */

class Users
{

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route(
            'postanalyzer/v1',
            '/users',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_users'],
                // Allow editors/admins to use the users endpoint in the admin UI
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
                'args' => [
                    'per_page' => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => 100,
                    ],
                    'role' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => '',
                    ],
                ],
            ]
        );
    }

    public function get_users($request)
    {
        $per_page = (int) $request->get_param('per_page');
        $role = $request->get_param('role');

        $args = [
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => ['ID', 'display_name', 'user_email', 'user_login'],
        ];

        // Set number of users to return
        if ($per_page > 0) {
            $args['number'] = $per_page;
        }

        // Filter by role if specified
        if (!empty($role)) {
            $args['role'] = $role;
        } else {
            // By default, get users who can write posts
            $args['who'] = 'authors'; // This gets users with author, editor, and admin roles
        }

        $users_query = new \WP_User_Query($args);
        $users = $users_query->get_results();

        $items = [];

        if (!empty($users)) {
            foreach ($users as $user) {
                $items[] = [
                    'id'    => (int) $user->ID,
                    'name'  => $user->display_name ?: $user->user_login,
                    'email' => $user->user_email,
                ];
            }
        }

        return rest_ensure_response($items);
    }
}
