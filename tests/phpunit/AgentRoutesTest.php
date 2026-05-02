<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\REST\Agent_Controller;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AgentRoutesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		Agent_Controller::register_routes();
	}

	public function test_register_routes_exposes_the_expected_contract(): void {
		$registered_routes = array_keys( WordPressTestState::$rest_routes );
		sort( $registered_routes );

		$this->assertSame(
			[
				'/flavor-agent/v1/activity',
				'/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/undo',
				'/flavor-agent/v1/recommend-block',
				'/flavor-agent/v1/recommend-content',
				'/flavor-agent/v1/recommend-navigation',
				'/flavor-agent/v1/recommend-patterns',
				'/flavor-agent/v1/recommend-style',
				'/flavor-agent/v1/recommend-template',
				'/flavor-agent/v1/recommend-template-part',
				'/flavor-agent/v1/sync-patterns',
			],
			$registered_routes
		);

		$this->assertRouteMethods( '/flavor-agent/v1/recommend-block', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/recommend-content', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/recommend-patterns', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/recommend-navigation', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/recommend-style', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/recommend-template', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/recommend-template-part', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/sync-patterns', [ 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity', [ 'GET', 'POST' ] );
		$this->assertRouteMethods( '/flavor-agent/v1/activity/(?P<id>[A-Za-z0-9._:-]+)/undo', [ 'POST' ] );
	}

	public function test_recommendation_routes_enforce_capabilities_before_handlers_run(): void {
		$this->assertForbidden(
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-block',
				[
					'clientId'      => 'block-1',
					'editorContext' => [
						'block' => [
							'name' => 'core/paragraph',
						],
					],
				]
			)
		);

		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$this->assertNotForbidden(
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-block',
				[
					'clientId'             => 'block-1',
					'editorContext'        => [
						'block' => [
							'name' => 'core/paragraph',
						],
					],
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertForbidden(
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-template',
				[
					'templateRef'          => 'theme//home',
					'resolveSignatureOnly' => true,
				]
			)
		);

		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];

		$this->assertNotForbidden(
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-template',
				[
					'templateRef'          => 'theme//home',
					'resolveSignatureOnly' => true,
				]
			)
		);

		$this->assertForbidden(
			$this->dispatch_route( 'POST', '/flavor-agent/v1/sync-patterns' )
		);

		WordPressTestState::$capabilities = [
			'manage_options' => true,
		];

		$this->assertNotForbidden(
			$this->dispatch_route( 'POST', '/flavor-agent/v1/sync-patterns' )
		);
	}

	public function test_activity_routes_sanitize_context_before_permission_checks(): void {
		ActivityRepository::install();

		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$this->assertForbidden(
			$this->dispatch_route(
				'GET',
				'/flavor-agent/v1/activity',
				[
					'global' => 'true',
				]
			)
		);

		WordPressTestState::$capabilities = [
			'manage_options' => true,
		];

		$response = $this->dispatch_route(
			'GET',
			'/flavor-agent/v1/activity',
			[
				'global' => 'true',
			]
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_required_arguments_and_validation_are_enforced_at_the_route_boundary(): void {
		ActivityRepository::install();
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		$this->assertRestErrorCode(
			'rest_missing_callback_param',
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-block',
				[
					'clientId' => 'block-1',
				]
			)
		);

		$this->assertRestErrorCode(
			'rest_missing_callback_param',
			$this->dispatch_route( 'POST', '/flavor-agent/v1/recommend-patterns' )
		);

		$this->assertRestErrorCode(
			'rest_invalid_param',
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-template',
				[
					'templateRef' => '',
					'document'    => [
						'scopeKey' => 'wp_template:theme//home',
						'postType' => 'wp_template',
						'entityId' => 'theme//home',
					],
				]
			)
		);

		$this->assertRestErrorCode(
			'rest_invalid_param',
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-template-part',
				[
					'templatePartRef' => '',
					'document'        => [
						'scopeKey' => 'wp_template_part:theme//header',
						'postType' => 'wp_template_part',
						'entityId' => 'theme//header',
					],
				]
			)
		);

		$this->assertSame(
			[],
			WordPressTestState::$db_tables[ ActivityRepository::table_name() ] ?? []
		);

		$this->assertRestErrorCode(
			'rest_missing_callback_param',
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-style',
				[
					'scope' => [
						'type' => 'global',
					],
				]
			)
		);

		$this->assertRestErrorCode(
			'rest_invalid_param',
			$this->dispatch_route(
				'POST',
				'/flavor-agent/v1/recommend-template',
				[
					'templateRef'         => 'theme//home',
					'visiblePatternNames' => [ 'theme/hero', 123 ],
				]
			)
		);
	}

	public function test_route_sanitizers_normalize_request_values_before_callbacks(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$request  = null;
		$response = $this->dispatch_route(
			'POST',
			'/flavor-agent/v1/recommend-block',
			[
				'clientId'             => ' <b>client-123</b> ',
				'editorContext'        => [
					'block' => [
						'name' => 'core/paragraph',
					],
				],
				'prompt'               => "Tighten <b>copy</b>\nwithout shouting.",
				'resolveSignatureOnly' => 'yes',
			],
			$request
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'client-123', $response->get_data()['clientId'] ?? null );
		$this->assertSame( "Tighten copy\nwithout shouting.", $request?->get_param( 'prompt' ) );
		$this->assertTrue( $request?->get_param( 'resolveSignatureOnly' ) );
	}

	private function assertRouteMethods( string $route, array $expected_methods ): void {
		$registered = WordPressTestState::$rest_routes[ $route ]['endpoints'] ?? [];
		$methods    = [];

		foreach ( $registered as $endpoint ) {
			foreach ( $this->normalize_methods( $endpoint['methods'] ?? 'GET' ) as $method ) {
				$methods[] = $method;
			}
		}

		sort( $methods );
		sort( $expected_methods );

		$this->assertSame( $expected_methods, array_values( array_unique( $methods ) ) );
	}

	private function dispatch_route(
		string $method,
		string $path,
		array $params = [],
		?\WP_REST_Request &$captured_request = null
	): \WP_REST_Response|\WP_Error {
		$route = $this->find_registered_route( $path, $params );
		$this->assertNotNull( $route, 'Expected route to be registered: ' . $path );

		$endpoint = $this->find_endpoint_for_method( $route, $method );
		$this->assertNotNull( $endpoint, 'Expected method to be registered: ' . $method );

		$request = new \WP_REST_Request( strtoupper( $method ), $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( (string) $key, $value );
		}

		foreach ( $route['matches'] as $key => $value ) {
			if ( is_string( $key ) ) {
				$request->set_param( $key, $value );
			}
		}

		$args = is_array( $endpoint['args'] ?? null ) ? $endpoint['args'] : [];

		foreach ( $args as $name => $schema ) {
			if ( ! $request->has_param( (string) $name ) && array_key_exists( 'default', $schema ) ) {
				$request->set_param( (string) $name, $schema['default'] );
			}
		}

		foreach ( $args as $name => $schema ) {
			if ( ! empty( $schema['required'] ) && ! $request->has_param( (string) $name ) ) {
				return new \WP_Error(
					'rest_missing_callback_param',
					'Missing parameter(s): ' . (string) $name,
					[
						'status' => 400,
						'params' => [ (string) $name ],
					]
				);
			}
		}

		foreach ( $args as $name => $schema ) {
			if ( ! $request->has_param( (string) $name ) ) {
				continue;
			}

			if (
				is_callable( $schema['validate_callback'] ?? null )
				&& true !== $this->invoke_callback( $schema['validate_callback'], [ $request->get_param( (string) $name ), $request, (string) $name ] )
			) {
				return new \WP_Error(
					'rest_invalid_param',
					'Invalid parameter(s): ' . (string) $name,
					[
						'status' => 400,
						'params' => [ (string) $name ],
					]
				);
			}
		}

		foreach ( $args as $name => $schema ) {
			if ( ! $request->has_param( (string) $name ) || ! is_callable( $schema['sanitize_callback'] ?? null ) ) {
				continue;
			}

			$request->set_param(
				(string) $name,
				$this->invoke_callback(
					$schema['sanitize_callback'],
					[ $request->get_param( (string) $name ), $request, (string) $name ]
				)
			);
		}

		$captured_request = $request;
		$permission       = $this->invoke_callback(
			$endpoint['permission_callback'] ?? '__return_true',
			[ $request ]
		);

		if ( true !== $permission ) {
			return $permission instanceof \WP_Error
				? $permission
				: new \WP_Error(
					'rest_forbidden',
					'Sorry, you are not allowed to do that.',
					[ 'status' => 403 ]
				);
		}

		return $this->invoke_callback( $endpoint['callback'], [ $request ] );
	}

	private function find_registered_route( string $path, array $params ): ?array {
		foreach ( WordPressTestState::$rest_routes as $registered_path => $route ) {
			if ( $registered_path === $path ) {
				return array_merge( $route, [ 'matches' => [] ] );
			}

			$pattern = '#^' . str_replace( '\(\?P<', '(?P<', preg_quote( $registered_path, '#' ) ) . '$#';
			$pattern = str_replace( '\>\[A\-Za\-z0\-9\._\:\-\]\+', '>[A-Za-z0-9._:-]+', $pattern );

			if ( 1 === preg_match( $pattern, $path, $matches ) ) {
				$route['matches'] = array_filter(
					$matches,
					static fn( $key ): bool => is_string( $key ),
					ARRAY_FILTER_USE_KEY
				);

				return $route;
			}
		}

		unset( $params );

		return null;
	}

	private function find_endpoint_for_method( array $route, string $method ): ?array {
		$method = strtoupper( $method );

		foreach ( $route['endpoints'] ?? [] as $endpoint ) {
			if ( in_array( $method, $this->normalize_methods( $endpoint['methods'] ?? 'GET' ), true ) ) {
				return $endpoint;
			}
		}

		return null;
	}

	private function normalize_methods( mixed $methods ): array {
		if ( is_array( $methods ) ) {
			return array_map( 'strtoupper', array_map( 'strval', $methods ) );
		}

			$method_tokens = preg_split( '/[\s,|]+/', strtoupper( (string) $methods ) );

			return array_values(
				array_filter(
					array_map(
						'trim',
						false === $method_tokens ? [] : $method_tokens
					)
				)
			);
	}

	private function invoke_callback( callable $callback, array $args ): mixed {
		$reflection = is_array( $callback )
			? new \ReflectionMethod( $callback[0], $callback[1] )
			: new \ReflectionFunction( $callback );

		return $callback( ...array_slice( $args, 0, $reflection->getNumberOfParameters() ) );
	}

	private function assertForbidden( mixed $response ): void {
		$this->assertRestErrorCode( 'rest_forbidden', $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	private function assertNotForbidden( mixed $response ): void {
		$this->assertFalse(
			$response instanceof \WP_Error && 'rest_forbidden' === $response->get_error_code(),
			$response instanceof \WP_Error ? $response->get_error_message() : ''
		);
	}

	private function assertRestErrorCode( string $code, mixed $response ): void {
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
	}
}
