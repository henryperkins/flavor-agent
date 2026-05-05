<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\MCP\ServerBootstrap;
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

final class CapturingMcpAdapter {
	/** @var array<int, array<int, mixed>> */
	public array $calls = [];

	public function create_server( mixed ...$args ): self {
		$this->calls[] = $args;

		return $this;
	}
}
