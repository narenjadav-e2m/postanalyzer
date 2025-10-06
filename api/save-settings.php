<?php

/**
 * REST endpoint for PostAnalyzer settings management.
 */

if (! class_exists('PostAnalyzer_Settings_REST')) {

    class PostAnalyzer_Settings_REST
    {
        const OPTION_NAME = 'postanalyzer_settings';

        public function __construct()
        {
            add_action('rest_api_init', [$this, 'register_routes']);
        }

        public function register_routes()
        {
            // Save settings endpoint
            register_rest_route(
                'postanalyzer/v1',
                '/save-settings',
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_settings'],
                    'permission_callback' => function () {
                        return current_user_can('manage_options');
                    },
                    'args' => [
                        'api_key' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                            'validate_callback' => function ($value) {
                                return !empty(trim($value));
                            },
                        ],
                        'author_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                            'validate_callback' => function ($value) {
                                return $value > 0;
                            },
                        ],
                    ],
                ]
            );

            // Get settings endpoint
            register_rest_route(
                'postanalyzer/v1',
                '/get-settings',
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => function () {
                        return current_user_can('edit_posts');
                    },
                ]
            );
        }

        public function save_settings($request)
        {
            $api_key = $request->get_param('api_key');
            $author_id = $request->get_param('author_id');

            // Additional validation
            if (empty(trim($api_key))) {
                return new WP_Error(
                    'invalid_api_key',
                    'API key cannot be empty',
                    ['status' => 400]
                );
            }

            // Verify the author exists
            $user = get_user_by('id', $author_id);
            if (!$user) {
                return new WP_Error(
                    'invalid_author',
                    'Selected author does not exist',
                    ['status' => 400]
                );
            }

            // Prepare settings array
            $settings = [
                'api_key'    => trim($api_key),
                'author_id'  => $author_id,
                'updated_at' => current_time('mysql'),
                'updated_by' => get_current_user_id(),
            ];

            // Save to options table
            $saved = update_option(self::OPTION_NAME, $settings);

            if ($saved || get_option(self::OPTION_NAME) == $settings) {
                return rest_ensure_response([
                    'success' => true,
                    'message' => 'Settings saved successfully',
                    'data'    => [
                        'author_id' => $author_id,
                        'author_name' => $user->display_name,
                    ],
                ]);
            } else {
                return new WP_Error(
                    'save_failed',
                    'Failed to save settings',
                    ['status' => 500]
                );
            }
        }

        public function get_settings($request)
        {
            $settings = get_option(self::OPTION_NAME, []);

            // Don't expose the full API key for security
            if (!empty($settings['api_key'])) {
                $settings['api_key_masked'] = substr($settings['api_key'], 0, 10) . str_repeat('*', strlen($settings['api_key']) - 10);
                unset($settings['api_key']); // Remove actual key from response
            }

            // Add author name if author_id exists
            if (!empty($settings['author_id'])) {
                $user = get_user_by('id', $settings['author_id']);
                if ($user) {
                    $settings['author_name'] = $user->display_name;
                }
            }

            return rest_ensure_response($settings);
        }

        /**
         * Helper method to get the API key (for internal use)
         */
        public static function get_api_key()
        {
            $settings = get_option(self::OPTION_NAME, []);
            return isset($settings['api_key']) ? $settings['api_key'] : '';
        }

        /**
         * Helper method to get the author ID (for internal use)
         */
        public static function get_author_id()
        {
            $settings = get_option(self::OPTION_NAME, []);
            return isset($settings['author_id']) ? (int) $settings['author_id'] : 0;
        }
    }

    new PostAnalyzer_Settings_REST();
}
