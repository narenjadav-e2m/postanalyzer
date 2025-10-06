<?php

/**
 * REST endpoint that returns a list of posts for the admin UI dropdown.
 */

if (! class_exists('PostAnalyzer_Posts_REST')) {

    class PostAnalyzer_Posts_REST
    {

        public function __construct()
        {
            add_action('rest_api_init', [$this, 'register_routes']);
        }

        public function register_routes()
        {
            register_rest_route(
                'postanalyzer/v1',
                '/posts',
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_posts'],
                    // Allow editors/admins to use the posts endpoint in the admin UI
                    'permission_callback' => function () {
                        return current_user_can('edit_posts');
                    },
                    'args' => [
                        'per_page' => [
                            'required' => true,
                            'type'     => 'integer',
                            'default'  => 50,
                        ],
                    ],
                ]
            );
        }

        public function get_posts($request)
        {
            $per_page = (int) $request->get_param('per_page');
            if ($per_page == 0) {
                $per_page = -1;
            }
            $q = new WP_Query([
                'post_type'      => 'post',
                'posts_per_page' => $per_page,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ]);

            $items = [];
            if ($q->have_posts()) {
                foreach ($q->posts as $id) {
                    $items[] = [
                        'id'    => (int) $id,
                        'title' => recursive_html_entity_decode( get_the_title($id) ?: sprintf(__('Post #%d', 'postanalyzer'), $id) ),
                        'date'  => get_the_date('c', $id),
                        'slug'  => get_post_field('post_name', $id),
                        'link'  => get_permalink($id),
                        'status'  => ucfirst(get_post_status($id)),
                    ];
                }
            }

            return rest_ensure_response($items);
        }
    }

    new PostAnalyzer_Posts_REST();
}
