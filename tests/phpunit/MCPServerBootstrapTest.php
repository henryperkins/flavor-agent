<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/support/CapturingMcpAdapter.php';

use FlavorAgent\MCP\ServerBootstrap;
use FlavorAgent\Tests\Support\CapturingMcpAdapter;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class MCPServerBootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
	}

	public function test_register_no_ops_when_mcp_adapter_is_missing(): void {
		ServerBootstrap::register();

		$this->assertTrue( true, 'Missing MCP Adapter should not throw or register anything.' );
	}

	public function test_register_creates_dedicated_server_with_recommendation_tools(): void {
		$adapter = new CapturingMcpAdapter();

		ServerBootstrap::register( $adapter );

		$this->assertCount( 1, $adapter->calls );
		$call = $adapter->calls[0];

		$this->assertSame( 'flavor-agent', $call[0] );
		$this->assertSame( 'mcp', $call[1] );
		$this->assertSame( 'flavor-agent', $call[2] );
		$this->assertContains( 'flavor-agent/recommend-block', $call[9] );
		$this->assertContains( 'flavor-agent/recommend-template', $call[9] );
		$this->assertContains( 'flavor-agent/recommend-style', $call[9] );
		$this->assertCount( 7, $call[9] );
		$this->assertSame( [], $call[10] );
		$this->assertSame( [], $call[11] );
		$this->assertIsCallable( $call[12] );
	}

	public function test_register_exposes_registration_failures_to_action_listeners(): void {
		$failure          = new \WP_Error( 'mcp_failed', 'MCP registration failed.' );
		$adapter          = new class( $failure ) {
			public function __construct( private \WP_Error $failure ) {}

			public function create_server( mixed ...$args ): \WP_Error {
				unset( $args );

				return $this->failure;
			}
		};
		$captured_failure = null;

		add_action(
			'flavor_agent_mcp_server_registration_failed',
			static function ( \WP_Error $result ) use ( &$captured_failure ): void {
				$captured_failure = $result;
			}
		);
		add_filter( 'flavor_agent_mcp_server_registration_failure_logging_enabled', '__return_false' );

		try {
			ServerBootstrap::register( $adapter );
		} finally {
			remove_filter( 'flavor_agent_mcp_server_registration_failure_logging_enabled', '__return_false' );
		}

		$this->assertSame( $failure, $captured_failure );
	}

	public function test_register_logs_registration_failures_by_default(): void {
		$failure = new \WP_Error( 'mcp_failed', 'MCP registration failed.' );
		$adapter = new class( $failure ) {
			public function __construct( private \WP_Error $failure ) {}

			public function create_server( mixed ...$args ): \WP_Error {
				unset( $args );

				return $this->failure;
			}
		};

		$log_file        = \tempnam( \sys_get_temp_dir(), 'flavor-agent-mcp-log-' );
		$previous_log    = \ini_get( 'error_log' );
		$previous_errors = \ini_get( 'log_errors' );

		\ini_set( 'log_errors', '1' );
		\ini_set( 'error_log', $log_file );

		try {
			ServerBootstrap::register( $adapter );
		} finally {
			\ini_set( 'error_log', false === $previous_log ? '' : (string) $previous_log );
			\ini_set( 'log_errors', false === $previous_errors ? '' : (string) $previous_errors );
		}

		$contents = \is_string( $log_file ) && \file_exists( $log_file )
			? (string) \file_get_contents( $log_file )
			: '';

		if ( \is_string( $log_file ) && \file_exists( $log_file ) ) {
			\unlink( $log_file );
		}

		$this->assertStringContainsString( '[flavor-agent] MCP server registration failed: mcp_failed - MCP registration failed.', $contents );
	}

	public function test_register_allows_registration_failure_logging_to_be_disabled(): void {
		$failure = new \WP_Error( 'mcp_failed', 'MCP registration failed.' );
		$adapter = new class( $failure ) {
			public function __construct( private \WP_Error $failure ) {}

			public function create_server( mixed ...$args ): \WP_Error {
				unset( $args );

				return $this->failure;
			}
		};

		$captured_failure = null;
		$log_file         = \tempnam( \sys_get_temp_dir(), 'flavor-agent-mcp-log-' );
		$previous_log     = \ini_get( 'error_log' );
		$previous_errors  = \ini_get( 'log_errors' );

		\add_filter( 'flavor_agent_mcp_server_registration_failure_logging_enabled', '__return_false' );
		\add_action(
			'flavor_agent_mcp_server_registration_failed',
			static function ( \WP_Error $result ) use ( &$captured_failure ): void {
				$captured_failure = $result;
			}
		);

		\ini_set( 'log_errors', '1' );
		\ini_set( 'error_log', $log_file );

		try {
			ServerBootstrap::register( $adapter );
		} finally {
			\remove_filter( 'flavor_agent_mcp_server_registration_failure_logging_enabled', '__return_false' );
			\ini_set( 'error_log', false === $previous_log ? '' : (string) $previous_log );
			\ini_set( 'log_errors', false === $previous_errors ? '' : (string) $previous_errors );
		}

		$contents = \is_string( $log_file ) && \file_exists( $log_file )
			? (string) \file_get_contents( $log_file )
			: '';

		if ( \is_string( $log_file ) && \file_exists( $log_file ) ) {
			\unlink( $log_file );
		}

		$this->assertSame( $failure, $captured_failure );
		$this->assertSame( '', \trim( $contents ) );
	}

	public function test_transport_gate_allows_post_or_theme_capability(): void {
		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertTrue( ServerBootstrap::can_access_transport() );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( ServerBootstrap::can_access_transport() );

		WordPressTestState::$capabilities = [
			'edit_posts'         => false,
			'edit_theme_options' => false,
		];
		$this->assertFalse( ServerBootstrap::can_access_transport() );
	}

	public function test_register_skips_when_feature_toggle_is_disabled(): void {
		WordPressTestState::$options['wpai_feature_flavor-agent_enabled'] = false;
		$adapter = new CapturingMcpAdapter();

		ServerBootstrap::register( $adapter );

		$this->assertSame( [], $adapter->calls );
	}
}
