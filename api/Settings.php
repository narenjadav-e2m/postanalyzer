<?php

namespace PostAnalyzer\API;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

defined('ABSPATH') || exit;

/**
 * REST endpoint for PostAnalyzer settings management.
 */
class Settings
{
    const OPTION_NAME = 'postanalyzer_settings';
    const ALLOWED_PLATFORMS = ['chatgpt', 'gemini', 'groq'];

    private $httpClient;

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Initialize Guzzle client with default options
        $this->httpClient = new Client([
            'timeout' => 10,
            'verify' => true,
            'http_errors' => false, // Don't throw exceptions on 4xx/5xx responses
        ]);
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
                    'ai_platform' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function ($value) {
                            return in_array($value, self::ALLOWED_PLATFORMS);
                        },
                    ],
                    'api_keys' => [
                        'required'          => true,
                        'type'              => 'object',
                        'validate_callback' => function ($value) {
                            return is_array($value);
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
        $ai_platform = $request->get_param('ai_platform');
        $api_keys = $request->get_param('api_keys');
        $author_id = $request->get_param('author_id');

        // Validate selected platform has an API key
        if (!isset($api_keys[$ai_platform]) || empty(trim($api_keys[$ai_platform]))) {
            return new \WP_Error(
                'invalid_api_key',
                sprintf('API key for %s cannot be empty', ucfirst($ai_platform)),
                ['status' => 400]
            );
        }

        // Verify the author exists
        $user = get_user_by('id', $author_id);
        if (!$user) {
            return new \WP_Error(
                'invalid_author',
                'Selected author does not exist',
                ['status' => 400]
            );
        }

        // Test the API key for the selected platform
        $active_api_key = trim($api_keys[$ai_platform]);
        $validation_result = $this->validate_api_key($ai_platform, $active_api_key);

        if (!$validation_result['valid']) {
            return new \WP_Error(
                'invalid_api_key_test',
                $validation_result['message'],
                ['status' => 400]
            );
        }

        // Prepare API keys array (only store non-empty keys)
        $encrypted_keys = [];
        foreach (self::ALLOWED_PLATFORMS as $platform) {
            if (isset($api_keys[$platform]) && !empty(trim($api_keys[$platform]))) {
                // Encrypt API keys before storage
                $encrypted_keys[$platform] = $this->encrypt_api_key(trim($api_keys[$platform]));
            }
        }

        // Prepare settings array
        $settings = [
            'ai_platform' => $ai_platform,
            'api_keys'    => $encrypted_keys,
            'author_id'   => $author_id,
            'updated_at'  => current_time('mysql'),
            'updated_by'  => get_current_user_id(),
        ];

        // Encode as JSON for storage
        $json_settings = wp_json_encode($settings);

        if ($json_settings === false) {
            return new \WP_Error(
                'json_encode_error',
                'Failed to encode settings',
                ['status' => 500]
            );
        }

        // Save to options table
        $saved = update_option(self::OPTION_NAME, $json_settings);

        if ($saved || get_option(self::OPTION_NAME) === $json_settings) {
            return rest_ensure_response([
                'success' => true,
                'message' => sprintf('Settings saved successfully! %s API key is valid and ready to use.', ucfirst($ai_platform)),
                'data'    => [
                    'ai_platform' => $ai_platform,
                    'author_id'   => $author_id,
                    'author_name' => $user->display_name,
                    'api_key_valid' => true,
                    'api_key_info' => $validation_result['info'] ?? []
                ],
            ]);
        } else {
            return new \WP_Error(
                'save_failed',
                'Failed to save settings',
                ['status' => 500]
            );
        }
    }

    /**
     * Validate API key by making a test request to the respective platform
     */
    private function validate_api_key($platform, $api_key)
    {
        switch ($platform) {
            case 'chatgpt':
                return $this->validate_openai_key($api_key);

            case 'gemini':
                return $this->validate_gemini_key($api_key);

            case 'groq':
                return $this->validate_groq_key($api_key);

            default:
                return [
                    'valid' => false,
                    'message' => 'Unknown platform'
                ];
        }
    }

    /**
     * Validate OpenAI (ChatGPT) API key
     */
    private function validate_openai_key($api_key)
    {
        try {
            $response = $this->httpClient->get('https://api.openai.com/v1/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $status_code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($status_code === 401) {
                return [
                    'valid' => false,
                    'message' => 'Invalid OpenAI API key. Please check your key and try again.'
                ];
            }

            if ($status_code === 200 && isset($data['data'])) {
                return [
                    'valid' => true,
                    'message' => 'OpenAI API key is valid',
                    'info' => [
                        'models_available' => count($data['data']),
                        'includes_gpt4' => $this->check_model_availability($data['data'], 'gpt-4')
                    ]
                ];
            }

            return [
                'valid' => false,
                'message' => 'Unexpected response from OpenAI API: ' . $status_code
            ];
        } catch (ClientException $e) {
            $status_code = $e->getResponse()->getStatusCode();
            if ($status_code === 401) {
                return [
                    'valid' => false,
                    'message' => 'Invalid OpenAI API key. Please check your key and try again.'
                ];
            }
            return [
                'valid' => false,
                'message' => 'OpenAI API Error: ' . $e->getMessage()
            ];
        } catch (ConnectException $e) {
            return [
                'valid' => false,
                'message' => 'Failed to connect to OpenAI API. Please check your internet connection.'
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Error validating OpenAI API key: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate Google Gemini API key
     */
    private function validate_gemini_key($api_key)
    {
        try {
            $response = $this->httpClient->get('https://generativelanguage.googleapis.com/v1beta/models', [
                'query' => ['key' => $api_key],
            ]);

            $status_code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($status_code == 400 || $status_code == 403) {
                return [
                    'valid' => false,
                    'message' => 'Invalid Gemini API key. Please check your key and try again.'
                ];
            }

            if ($status_code === 200 && isset($data['models'])) {
                return [
                    'valid' => true,
                    'message' => 'Gemini API key is valid',
                    'info' => [
                        'models_available' => count($data['models'])
                    ]
                ];
            }

            return [
                'valid' => false,
                'message' => 'Unexpected response from Gemini API: ' . $status_code
            ];
        } catch (ClientException $e) {
            $status_code = $e->getResponse()->getStatusCode();
            if ($status_code == 400 || $status_code == 403) {
                return [
                    'valid' => false,
                    'message' => 'Invalid Gemini API key. Please check your key and try again.'
                ];
            }
            return [
                'valid' => false,
                'message' => 'Gemini API Error: ' . $e->getMessage()
            ];
        } catch (ConnectException $e) {
            return [
                'valid' => false,
                'message' => 'Failed to connect to Gemini API. Please check your internet connection.'
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Error validating Gemini API key: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate Groq API key
     */
    private function validate_groq_key($api_key)
    {
        try {
            $response = $this->httpClient->get('https://api.groq.com/openai/v1/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $status_code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($status_code === 401) {
                return [
                    'valid' => false,
                    'message' => 'Invalid Groq API key. Please check your key and try again.'
                ];
            }

            if ($status_code === 200 && isset($data['data'])) {
                return [
                    'valid' => true,
                    'message' => 'Groq API key is valid',
                    'info' => [
                        'models_available' => count($data['data']),
                        'includes_mixtral' => $this->check_model_availability($data['data'], 'mixtral'),
                        'includes_llama' => $this->check_model_availability($data['data'], 'llama')
                    ]
                ];
            }

            return [
                'valid' => false,
                'message' => 'Unexpected response from Groq API: ' . $status_code
            ];
        } catch (ClientException $e) {
            $status_code = $e->getResponse()->getStatusCode();
            if ($status_code === 401) {
                return [
                    'valid' => false,
                    'message' => 'Invalid Groq API key. Please check your key and try again.'
                ];
            }
            return [
                'valid' => false,
                'message' => 'Groq API Error: ' . $e->getMessage()
            ];
        } catch (ConnectException $e) {
            return [
                'valid' => false,
                'message' => 'Failed to connect to Groq API. Please check your internet connection.'
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Error validating Groq API key: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if a specific model is available in the models list
     */
    private function check_model_availability($models, $model_keyword)
    {
        foreach ($models as $model) {
            if (stripos($model['id'], $model_keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    public function get_settings($request)
    {
        $json_settings = get_option(self::OPTION_NAME, '');

        if (empty($json_settings)) {
            return rest_ensure_response([
                'ai_platform' => '',
                'author_id'   => 0,
            ]);
        }

        $settings = json_decode($json_settings, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'json_decode_error',
                'Failed to decode settings',
                ['status' => 500]
            );
        }

        // Mask API keys for display
        $masked_keys = [];
        if (isset($settings['api_keys']) && is_array($settings['api_keys'])) {
            foreach ($settings['api_keys'] as $platform => $encrypted_key) {
                if (!empty($encrypted_key)) {
                    $decrypted = $this->decrypt_api_key($encrypted_key);
                    $masked_keys[$platform] = $decrypted;
                }
            }
        }

        // Don't expose actual API keys
        unset($settings['api_keys']);
        $settings['api_keys_masked'] = $masked_keys;

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
     * Encrypt API key for secure storage
     */
    private function encrypt_api_key($api_key)
    {
        // Use WordPress salts for encryption
        $key = wp_salt('auth');
        $encrypted = openssl_encrypt($api_key, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
        return base64_encode($encrypted);
    }

    /**
     * Decrypt API key
     */
    private function decrypt_api_key($encrypted_key)
    {
        $key = wp_salt('auth');
        $decoded = base64_decode($encrypted_key);
        return openssl_decrypt($decoded, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
    }

    /**
     * Helper method to get the active API key (for internal use)
     */
    public static function get_active_api_key()
    {
        $instance = new self();
        $json_settings = get_option(self::OPTION_NAME, '');

        if (empty($json_settings)) {
            return '';
        }

        $settings = json_decode($json_settings, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($settings['ai_platform']) || !isset($settings['api_keys'])) {
            return '';
        }

        $platform = $settings['ai_platform'];

        if (isset($settings['api_keys'][$platform])) {
            return $instance->decrypt_api_key($settings['api_keys'][$platform]);
        }

        return '';
    }

    /**
     * Helper method to get the active AI platform
     */
    public static function get_active_platform()
    {
        $json_settings = get_option(self::OPTION_NAME, '');

        if (empty($json_settings)) {
            return 'groq'; // Default platform
        }

        $settings = json_decode($json_settings, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($settings['ai_platform'])) {
            return 'groq';
        }

        return $settings['ai_platform'];
    }

    /**
     * Helper method to get the author ID (for internal use)
     */
    public static function get_author_id()
    {
        $json_settings = get_option(self::OPTION_NAME, '');

        if (empty($json_settings)) {
            return 0;
        }

        $settings = json_decode($json_settings, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($settings['author_id'])) {
            return 0;
        }

        return (int) $settings['author_id'];
    }
}
