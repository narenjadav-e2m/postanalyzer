<?php

namespace PostAnalyzer\API;

defined( 'ABSPATH' ) || exit;

/**
 * Settings REST endpoints and persistent config helpers.
 *
 * @package PostAnalyzer
 * @since   2.0.0
 */
class Settings {

	const OPTION_NAME       = 'postanalyzer_settings';
	const ALLOWED_PLATFORMS = [ 'openai', 'gemini', 'groq' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {

		$admin_perm = static fn() => current_user_can( 'manage_options' );

		register_rest_route( 'postanalyzer/v1', '/save-settings', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_settings' ],
			'permission_callback' => $admin_perm,
			'args'                => [
				'ai_platform' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => static fn( $v ) => sanitize_text_field( (string) $v ),
					'validate_callback' => static fn( $v ) => in_array( $v, self::ALLOWED_PLATFORMS, true ),
				],
				'api_keys'    => [
					'required'          => true,
					'type'              => 'object',
					'validate_callback' => static fn( $v ) => is_array( $v ),
				],
				'author_id'   => [
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => static fn( $v ) => abs( (int) $v ),
				],
			],
		] );

		register_rest_route( 'postanalyzer/v1', '/get-settings', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_settings' ],
			'permission_callback' => $admin_perm,
		] );

		register_rest_route( 'postanalyzer/v1', '/validate-key', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'validate_key' ],
			'permission_callback' => $admin_perm,
			'args'                => [
				'platform' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => static fn( $v ) => sanitize_text_field( (string) $v ),
					'validate_callback' => static fn( $v ) => in_array( $v, self::ALLOWED_PLATFORMS, true ),
				],
				'api_key'  => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => static fn( $v ) => sanitize_text_field( (string) $v ),
				],
			],
		] );
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$platform  = $request->get_param( 'ai_platform' );
		$raw_keys  = (array) $request->get_param( 'api_keys' );
		$author_id = (int) $request->get_param( 'author_id' );

		$existing      = $this->load_raw_settings();
		$existing_keys = $existing['api_keys'] ?? [];

		$active_key = trim( (string) ( $raw_keys[ $platform ] ?? '' ) );

		// A new key for the active platform is only required when none is stored yet.
		if ( $active_key === '' && empty( $existing_keys[ $platform ] ) ) {
			return new \WP_Error(
				'missing_api_key',
				sprintf( __( 'API key for %s is required.', 'postanalyzer' ), ucfirst( $platform ) ),
				[ 'status' => 400 ]
			);
		}

		// Only ping the provider when the user actually supplied a new key.
		if ( $active_key !== '' ) {
			$validation = $this->validate_api_key( $platform, $active_key );
			if ( ! $validation['valid'] ) {
				return new \WP_Error( 'invalid_api_key', $validation['message'], [ 'status' => 422 ] );
			}
		}

		// Start from what is stored and only overwrite keys the user re-entered.
		// Empty fields mean "unchanged" — never clobber a saved key with a blank
		// or a masked placeholder echoed back from get_settings().
		$encrypted_keys = $existing_keys;
		foreach ( $raw_keys as $p => $key ) {
			$key = trim( (string) $key );
			if ( $key !== '' ) {
				$encrypted_keys[ sanitize_key( $p ) ] = self::encrypt( $key );
			}
		}

		$new_settings = [
			'ai_platform' => $platform,
			'api_keys'    => $encrypted_keys,
			'author_id'   => $author_id,
			'updated_at'  => current_time( 'mysql' ),
		];

		update_option( self::OPTION_NAME, wp_json_encode( $new_settings ), false );

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'Settings saved successfully.', 'postanalyzer' ),
		] );
	}

	public function get_settings(): \WP_REST_Response|\WP_Error {
		$settings = $this->load_raw_settings();

		if ( empty( $settings ) ) {
			return rest_ensure_response( [
				'ai_platform'    => 'groq',
				'author_id'      => 0,
				'api_keys_saved' => [],
			] );
		}

		// Report only *which* platforms have a stored key — never any portion of
		// the key itself. Exposing even a prefix/suffix leaks an admin-level secret.
		$saved_platforms = [];
		foreach ( $settings['api_keys'] ?? [] as $platform => $encrypted ) {
			if ( ! empty( $encrypted ) ) {
				$saved_platforms[ $platform ] = true;
			}
		}

		$response = [
			'ai_platform'    => $settings['ai_platform'] ?? 'groq',
			'author_id'      => (int) ( $settings['author_id'] ?? 0 ),
			'api_keys_saved' => $saved_platforms,
			'updated_at'     => $settings['updated_at'] ?? '',
		];

		if ( ! empty( $settings['author_id'] ) ) {
			$user = get_user_by( 'id', $settings['author_id'] );
			if ( $user ) {
				$response['author_name'] = $user->display_name;
			}
		}

		return rest_ensure_response( $response );
	}

	public function validate_key( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->validate_api_key(
			$request->get_param( 'platform' ),
			$request->get_param( 'api_key' )
		);
		return rest_ensure_response( $result );
	}

	// ── Key validation ────────────────────────────────────────────────────────

	private function validate_api_key( string $platform, string $key ): array {
		if ( empty( $key ) ) {
			return [ 'valid' => false, 'message' => 'API key cannot be empty.' ];
		}

		return match ( $platform ) {
			'gemini' => $this->ping_gemini( $key ),
			'openai' => $this->ping_openai( $key ),
			'groq'   => $this->ping_groq( $key ),
			default  => [ 'valid' => false, 'message' => 'Unknown platform.' ],
		};
	}

	private function ping_gemini( string $key ): array {
		$url      = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode( $key );
		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );

		if ( is_wp_error( $response ) ) {
			return [ 'valid' => false, 'message' => 'Connection failed: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 400 && isset( $body['error']['message'] ) && str_contains( $body['error']['message'], 'API_KEY_INVALID' ) ) {
			return [ 'valid' => false, 'message' => 'Invalid Gemini API key.' ];
		}

		return $code === 200
			? [ 'valid' => true,  'message' => 'Gemini API key is valid.' ]
			: [ 'valid' => false, 'message' => 'Unexpected response from Gemini (HTTP ' . $code . ').' ];
	}

	private function ping_openai( string $key ): array {
		$response = wp_remote_get( 'https://api.openai.com/v1/models', [
			'timeout' => 10,
			'headers' => [ 'Authorization' => 'Bearer ' . $key ],
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'valid' => false, 'message' => 'Connection failed: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code === 401 ) return [ 'valid' => false, 'message' => 'Invalid OpenAI API key.' ];
		if ( $code === 200 ) return [ 'valid' => true,  'message' => 'OpenAI API key is valid.' ];

		return [ 'valid' => false, 'message' => 'Unexpected response from OpenAI (HTTP ' . $code . ').' ];
	}

	private function ping_groq( string $key ): array {
		$response = wp_remote_get( 'https://api.groq.com/openai/v1/models', [
			'timeout' => 10,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'valid' => false, 'message' => 'Connection failed: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code === 401 ) return [ 'valid' => false, 'message' => 'Invalid Groq API key.' ];
		if ( $code === 200 ) return [ 'valid' => true,  'message' => 'Groq API key is valid.' ];

		return [ 'valid' => false, 'message' => 'Unexpected response from Groq (HTTP ' . $code . ').' ];
	}

	// ── Encryption ────────────────────────────────────────────────────────────

	private const CIPHER     = 'AES-256-CBC';
	private const ENC_PREFIX = 'v2:'; // Random-IV format marker.

	private static function encrypt( string $value ): string {
		$key = substr( wp_salt( 'auth' ), 0, 32 );
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( $enc === false ) return '';
		// Prepend the IV so each ciphertext is unique even for identical keys.
		return self::ENC_PREFIX . base64_encode( $iv . $enc );
	}

	private static function decrypt( string $encrypted ): string {
		$key = substr( wp_salt( 'auth' ), 0, 32 );

		// New format: "v2:" . base64( iv(16) . ciphertext ).
		if ( str_starts_with( $encrypted, self::ENC_PREFIX ) ) {
			$decoded = base64_decode( substr( $encrypted, strlen( self::ENC_PREFIX ) ), true );
			if ( $decoded === false || strlen( $decoded ) <= 16 ) return '';
			$iv     = substr( $decoded, 0, 16 );
			$cipher = substr( $decoded, 16 );
			$result = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
			return $result !== false ? $result : '';
		}

		// Legacy format: static IV from wp_salt('secure_auth'). Kept so keys saved
		// before the random-IV change still decrypt; they upgrade on next save.
		$iv      = substr( wp_salt( 'secure_auth' ), 0, 16 );
		$decoded = base64_decode( $encrypted, true );
		if ( $decoded === false ) return '';
		$result = openssl_decrypt( $decoded, self::CIPHER, $key, 0, $iv );
		return $result !== false ? $result : '';
	}

	// ── Static config accessors ───────────────────────────────────────────────

	public static function get_active_platform(): string {
		$settings = self::load_static_settings();
		return $settings['ai_platform'] ?? 'groq';
	}

	public static function get_active_api_key(): string {
		$settings  = self::load_static_settings();
		$platform  = $settings['ai_platform'] ?? '';
		$encrypted = $settings['api_keys'][ $platform ] ?? '';

		if ( empty( $encrypted ) ) return '';

		return self::decrypt( $encrypted );
	}

	public static function get_author_id(): int {
		$settings = self::load_static_settings();
		return (int) ( $settings['author_id'] ?? 0 );
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	private function load_raw_settings(): array {
		return self::load_static_settings();
	}

	private static function load_static_settings(): array {
		$json    = get_option( self::OPTION_NAME, '' );
		if ( empty( $json ) ) return [];
		$decoded = json_decode( $json, true );
		return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $decoded : [];
	}
}
