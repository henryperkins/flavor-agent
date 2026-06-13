<?php
/**
 * Jetpack AI provider adapter.
 *
 * Lets Flavor Agent run on WordPress.com / Jetpack-connected sites where the
 * WordPress AI Client feature plugin ("ai") is not installable. Instead of the
 * AI Client connector framework, text generation is routed over the existing
 * Jetpack connection to WordPress.com's hosted Jetpack AI service using the
 * site's blog token -- no per-provider API key, drawing on the site's Jetpack
 * AI entitlement.
 *
 * This is intentionally a thin, self-contained adapter so it imposes no
 * dependency on the AI Client SDK classes, which are absent in this mode.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JetpackAIProvider {

	public const PROVIDER_SLUG = 'jetpack_ai';

	private const QUERY_PATH_TEMPLATE = '/sites/%d/jetpack-ai-query';
	private const WPCOM_API_VERSION   = '2';
	private const DEFAULT_TIMEOUT     = 90;
	private const FEATURE_SLUG        = 'flavor-agent';

	/**
	 * Whether this site can route text generation through Jetpack AI.
	 *
	 * Requires the Jetpack connection package (so the signed blog-token request
	 * helper exists) and an established, ready connection.
	 */
	public static function is_available(): bool {
		if ( ! class_exists( '\\Automattic\\Jetpack\\Connection\\Client' ) ) {
			return false;
		}

		if ( ! class_exists( '\\Jetpack_Options' ) ) {
			return false;
		}

		if ( null === self::blog_id() ) {
			return false;
		}

		return self::connection_ready();
	}

	/**
	 * Generate text via Jetpack AI.
	 *
	 * @param array<string, mixed>|null $schema    Optional JSON schema. When
	 *                                             present the request asks for a
	 *                                             JSON object response; Flavor
	 *                                             Agent validates the shape
	 *                                             downstream (json_object mode).
	 * @return string|\WP_Error Generated text, or a WP_Error on failure.
	 */
	public static function chat(
		string $system_prompt,
		string $user_prompt,
		?array $schema = null,
		?int $timeout_seconds = null
	): string|\WP_Error {
		if ( ! self::is_available() ) {
			return new \WP_Error(
				'jetpack_ai_unavailable',
				'Jetpack AI is unavailable. Connect Jetpack to enable AI features on this site.',
				[ 'status' => 503 ]
			);
		}

		$blog_id = self::blog_id();
		$timeout = max( 1, (int) ( $timeout_seconds ?? self::DEFAULT_TIMEOUT ) );

		$body = [
			'messages' => self::build_messages( $system_prompt, $user_prompt, $schema ),
			'feature'  => self::FEATURE_SLUG,
			'stream'   => false,
		];

		if ( is_array( $schema ) && [] !== $schema ) {
			// json_object mode: request structured JSON without binding Jetpack
			// AI to the full schema. Flavor Agent's parsers validate the result.
			$body['response_format'] = [ 'type' => 'json_object' ];
		}

		/**
		 * Filter the Jetpack AI request body before dispatch.
		 *
		 * @param array<string, mixed>      $body
		 * @param array<string, mixed>|null $schema
		 */
		$body = (array) apply_filters( 'flavor_agent_jetpack_ai_request_body', $body, $schema );

		$response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog(
			sprintf( self::QUERY_PATH_TEMPLATE, $blog_id ),
			self::WPCOM_API_VERSION,
			[
				'method'  => 'POST',
				'timeout' => $timeout,
				'headers' => [ 'Content-Type' => 'application/json' ],
			],
			$body,
			'wpcom'
		);

		return self::parse_response( $response );
	}

	/**
	 * @param array<string, mixed>|null $schema
	 * @return array<int, array<string, string>>
	 */
	private static function build_messages( string $system_prompt, string $user_prompt, ?array $schema ): array {
		$messages = [];

		$system_prompt = trim( $system_prompt );

		if ( is_array( $schema ) && [] !== $schema ) {
			$schema_json = wp_json_encode( $schema );

			if ( is_string( $schema_json ) ) {
				$schema_instruction = 'Respond with a single JSON object that conforms to this JSON schema. '
					. 'Return only JSON, with no surrounding prose or code fences. Schema: ' . $schema_json;

				$system_prompt = '' === $system_prompt
					? $schema_instruction
					: $system_prompt . "\n\n" . $schema_instruction;
			}
		}

		if ( '' !== $system_prompt ) {
			$messages[] = [
				'role'    => 'system',
				'content' => $system_prompt,
			];
		}

		$messages[] = [
			'role'    => 'user',
			'content' => $user_prompt,
		];

		return $messages;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response Raw wp_remote_* style response.
	 */
	private static function parse_response( mixed $response ): string|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return self::normalize_transport_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return self::normalize_http_error( $code, is_array( $data ) ? $data : [] );
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'jetpack_ai_invalid_response',
				'Jetpack AI returned an unreadable response.',
				[ 'status' => 502 ]
			);
		}

		$text = self::extract_text( $data );

		if ( '' === $text ) {
			return new \WP_Error(
				'empty_response',
				'Jetpack AI returned an empty response.',
				[ 'status' => 502 ]
			);
		}

		return $text;
	}

	/**
	 * Pull generated text out of the various shapes Jetpack AI may return
	 * (chat-completions style choices, or a flat completion string).
	 *
	 * @param array<string, mixed> $data
	 */
	private static function extract_text( array $data ): string {
		$choices = $data['choices'] ?? null;
		if ( is_array( $choices ) && isset( $choices[0] ) && is_array( $choices[0] ) ) {
			$message = $choices[0]['message'] ?? null;
			if ( is_array( $message ) && isset( $message['content'] ) && is_string( $message['content'] ) ) {
				return trim( $message['content'] );
			}

			if ( isset( $choices[0]['text'] ) && is_string( $choices[0]['text'] ) ) {
				return trim( $choices[0]['text'] );
			}
		}

		foreach ( [ 'completion', 'content', 'text', 'output_text' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return trim( $data[ $key ] );
			}
		}

		return '';
	}

	private static function normalize_transport_error( \WP_Error $error ): \WP_Error {
		return new \WP_Error(
			'jetpack_ai_request_failed',
			$error->get_error_message(),
			[ 'status' => 502 ]
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function normalize_http_error( int $code, array $data ): \WP_Error {
		$message = '';

		if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
			$message = trim( $data['message'] );
		} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			$message = trim( $data['error'] );
		}

		if ( '' === $message ) {
			$message = sprintf( 'Jetpack AI request failed with status %d.', $code );
		}

		// Surface quota exhaustion with a recognizable code so callers can prompt
		// an upgrade rather than treating it as a generic failure.
		$wpcom_error = isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '';
		if ( 402 === $code || 'jetpack_ai_request_limit_reached' === $wpcom_error ) {
			return new \WP_Error(
				'jetpack_ai_over_limit',
				'' !== $message ? $message : 'This site has reached its Jetpack AI request limit.',
				[ 'status' => 402 ]
			);
		}

		return new \WP_Error(
			'jetpack_ai_request_failed',
			$message,
			[ 'status' => $code ]
		);
	}

	private static function blog_id(): ?int {
		if ( ! class_exists( '\\Jetpack_Options' ) ) {
			return null;
		}

		$blog_id = (int) \Jetpack_Options::get_option( 'id' );

		return $blog_id > 0 ? $blog_id : null;
	}

	private static function connection_ready(): bool {
		if ( class_exists( '\\Automattic\\Jetpack\\Connection\\Manager' ) ) {
			try {
				$manager = new \Automattic\Jetpack\Connection\Manager( self::FEATURE_SLUG );

				if ( is_callable( [ $manager, 'is_connected' ] ) ) {
					return (bool) $manager->is_connected();
				}
			} catch ( \Throwable $throwable ) {
				// Fall through to the legacy check below.
			}
		}

		if ( class_exists( '\\Jetpack' ) && is_callable( [ '\\Jetpack', 'is_connection_ready' ] ) ) {
			return (bool) \Jetpack::is_connection_ready();
		}

		return false;
	}
}
