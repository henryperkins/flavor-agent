<?php
/**
 * Unit tests for the Jetpack AI provider adapter's response parsing and error
 * normalization. The transport itself (Client::wpcom_json_api_request_as_blog)
 * requires the Jetpack connection package, so these tests exercise the pure
 * parse_response() path via reflection with stubbed wp_remote_* responses.
 */

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\JetpackAIProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

final class JetpackAIProviderTest extends TestCase {

	/**
	 * Invoke the private parse_response() with a stubbed wp_remote-style array.
	 *
	 * @param array<string, mixed> $response
	 * @return string|WP_Error
	 */
	private function parse( array $response ): mixed {
		$method = new ReflectionMethod( JetpackAIProvider::class, 'parse_response' );
		$method->setAccessible( true );

		return $method->invoke( null, $response );
	}

	/**
	 * @param int                  $code
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function http( int $code, array $body ): array {
		return [
			'response' => [ 'code' => $code ],
			'body'     => (string) wp_json_encode( $body ),
		];
	}

	public function test_extracts_text_from_chat_completions_shape(): void {
		$result = $this->parse(
			$this->http(
				200,
				[
					'choices' => [
						[
							'message' => [
								'role'    => 'assistant',
								'content' => '  Hello world  ',
							],
						],
					],
				]
			)
		);

		$this->assertSame( 'Hello world', $result );
	}

	public function test_extracts_text_from_choice_text_field(): void {
		$result = $this->parse(
			$this->http( 200, [ 'choices' => [ [ 'text' => 'From choice text' ] ] ] )
		);

		$this->assertSame( 'From choice text', $result );
	}

	public function test_extracts_text_from_flat_completion_shape(): void {
		$result = $this->parse( $this->http( 200, [ 'completion' => 'Flat completion' ] ) );

		$this->assertSame( 'Flat completion', $result );
	}

	public function test_empty_content_returns_empty_response_error(): void {
		$result = $this->parse(
			$this->http( 200, [ 'choices' => [ [ 'message' => [ 'content' => '   ' ] ] ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_response', $result->get_error_code() );
	}

	public function test_unreadable_body_returns_invalid_response_error(): void {
		$result = $this->parse(
			[
				'response' => [ 'code' => 200 ],
				'body'     => 'not json',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'jetpack_ai_invalid_response', $result->get_error_code() );
	}

	public function test_http_402_maps_to_over_limit_error(): void {
		$result = $this->parse(
			$this->http( 402, [ 'message' => 'You have used all your requests.' ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'jetpack_ai_over_limit', $result->get_error_code() );
		$this->assertSame( 'You have used all your requests.', $result->get_error_message() );
	}

	public function test_request_limit_error_code_maps_to_over_limit(): void {
		$result = $this->parse(
			$this->http(
				400,
				[
					'code'    => 'jetpack_ai_request_limit_reached',
					'message' => 'Limit reached.',
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'jetpack_ai_over_limit', $result->get_error_code() );
	}

	public function test_generic_http_error_carries_status_and_message(): void {
		$result = $this->parse(
			$this->http( 500, [ 'message' => 'Upstream exploded.' ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'jetpack_ai_request_failed', $result->get_error_code() );
		$this->assertSame( 'Upstream exploded.', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 500, $data['status'] ?? null );
	}

	public function test_http_error_without_message_uses_status_fallback(): void {
		$result = $this->parse( $this->http( 503, [] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'jetpack_ai_request_failed', $result->get_error_code() );
		$this->assertStringContainsString( '503', $result->get_error_message() );
	}

	public function test_wp_error_transport_failure_is_normalized(): void {
		$method = new ReflectionMethod( JetpackAIProvider::class, 'parse_response' );
		$method->setAccessible( true );

		$transport_error = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
		$result          = $method->invoke( null, $transport_error );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'jetpack_ai_request_failed', $result->get_error_code() );
		$this->assertSame( 'cURL error 28: timeout', $result->get_error_message() );
	}
}
