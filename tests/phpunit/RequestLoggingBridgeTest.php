<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\RequestLoggingBridge;
use FlavorAgent\Support\FlavorAgentRequestTag;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class RequestLoggingBridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	protected function tearDown(): void {
		if ( \class_exists( FlavorAgentRequestTag::class ) ) {
			FlavorAgentRequestTag::finish();
		}

		parent::tearDown();
	}

	public function test_missing_core_request_logging_keeps_flavor_agent_diagnostics(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		$this->assertFalse( RequestLoggingBridge::is_core_logging_class_available() );
		$this->assertFalse( RequestLoggingBridge::is_core_logging_enabled() );
		$this->assertTrue( RequestLoggingBridge::should_persist_request_diagnostic() );
	}

	public function test_available_but_disabled_core_request_logging_keeps_flavor_agent_diagnostics(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		WordPressTestState::$options = [
			'wpai_features_enabled'                   => true,
			'wpai_feature_ai-request-logging_enabled' => false,
		];

		$this->assertTrue( RequestLoggingBridge::is_core_logging_class_available() );
		$this->assertFalse( RequestLoggingBridge::is_core_logging_enabled() );
		$this->assertTrue( RequestLoggingBridge::should_persist_request_diagnostic() );
	}

	public function test_enabled_core_request_logging_suppresses_flavor_agent_request_diagnostics(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		WordPressTestState::$options = [
			'wpai_features_enabled'                   => true,
			'wpai_feature_ai-request-logging_enabled' => true,
		];

		$this->assertTrue( RequestLoggingBridge::is_core_logging_enabled() );
		$this->assertFalse( RequestLoggingBridge::should_persist_request_diagnostic() );
	}

	public function test_filter_can_force_request_diagnostics_when_core_logging_is_enabled(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		\add_filter( 'flavor_agent_persist_request_diagnostic_with_core_logging', '__return_true' );
		WordPressTestState::$options = [
			'wpai_features_enabled'                   => true,
			'wpai_feature_ai-request-logging_enabled' => true,
		];

		$this->assertTrue( RequestLoggingBridge::should_persist_request_diagnostic() );
	}

	public function test_register_adds_core_logging_hooks_when_available(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );

		RequestLoggingBridge::register();

		$this->assertNotFalse(
			\has_action( 'wpai_request_log_context', [ RequestLoggingBridge::class, 'inject_flavor_agent_context' ] )
		);
		$this->assertNotFalse(
			\has_action( 'wpai_request_logged', [ RequestLoggingBridge::class, 'capture_log_id' ] )
		);
	}

	public function test_context_injection_returns_unchanged_context_without_active_tag(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		$context = [
			'source' => [
				'slug' => 'flavor-agent',
			],
		];

		$this->assertSame(
			$context,
			RequestLoggingBridge::inject_flavor_agent_context( $context, [], [] )
		);
	}

	public function test_context_injection_uses_active_tag_regardless_of_source_slug(): void {
		// Logging_Http_Transporter's source attribution silently misfires when
		// the plugin is mounted via symlink — the real backtrace path doesn't
		// start with WP_PLUGIN_DIR, so the row gets attributed to whichever
		// non-skipped frame is next (commonly wp-includes/abilities-api/...).
		// The active FlavorAgentRequestTag is the authoritative signal that
		// this request originated from Flavor Agent, so the bridge must inject
		// its metadata regardless of what slug the transporter assigned.
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );
		$this->assertTrue( \class_exists( FlavorAgentRequestTag::class ) );

		FlavorAgentRequestTag::start(
			new FlavorAgentRequestTag(
				'template',
				'flavor-agent/recommend-template',
				'wp_template:theme//home',
				[ 'scopeKey' => 'wp_template:theme//home' ],
				'request-token-1'
			)
		);

		$context = RequestLoggingBridge::inject_flavor_agent_context(
			[
				'source' => [
					'slug' => 'wordpress',
					'file' => 'wp-includes/abilities-api/class-wp-ability.php',
				],
			],
			[],
			[]
		);

		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Core source slug is lower-case.
		$this->assertSame( 'wordpress', $context['source']['slug'] ?? null );
		$this->assertSame( 'request-token-1', $context['flavor_agent']['requestToken'] ?? null );
		$this->assertSame( 'template', $context['flavor_agent']['surface'] ?? null );
	}

	public function test_context_injection_adds_flavor_agent_request_metadata(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );
		$this->assertTrue( \class_exists( FlavorAgentRequestTag::class ) );

		FlavorAgentRequestTag::start(
			new FlavorAgentRequestTag(
				'template',
				'flavor-agent/recommend-template',
				'wp_template:theme//home',
				[
					'scopeKey' => 'wp_template:theme//home',
					'postType' => 'wp_template',
				],
				'request-token-1'
			)
		);

		$context = RequestLoggingBridge::inject_flavor_agent_context(
			[
				'source' => [
					'slug' => 'flavor-agent',
				],
			],
			[],
			[]
		);

		$this->assertSame( 'template', $context['flavor_agent']['surface'] ?? null );
		$this->assertSame( 'flavor-agent/recommend-template', $context['flavor_agent']['abilityName'] ?? null );
		$this->assertSame( 'wp_template:theme//home', $context['flavor_agent']['scopeKey'] ?? null );
		$this->assertSame( 'request-token-1', $context['flavor_agent']['requestToken'] ?? null );
		$this->assertSame(
			[
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
			],
			$context['flavor_agent']['documentRef'] ?? null
		);
	}

	public function test_capture_and_consume_log_id_by_request_token(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		RequestLoggingBridge::capture_log_id(
			'11111111-1111-4111-8111-111111111111',
			[
				'context' => \wp_json_encode(
					[
						'flavor_agent' => [
							'requestToken' => 'request-token-1',
						],
					]
				),
			]
		);

		$this->assertSame(
			'11111111-1111-4111-8111-111111111111',
			RequestLoggingBridge::consume_log_id( 'request-token-1' )
		);
		$this->assertNull( RequestLoggingBridge::consume_log_id( 'request-token-1' ) );
	}

	public function test_capture_log_id_map_evicts_old_entries(): void {
		$this->assertTrue( \class_exists( RequestLoggingBridge::class ) );

		for ( $i = 1; $i <= 51; $i++ ) {
			RequestLoggingBridge::capture_log_id(
				'log-' . $i,
				[
					'context' => [
						'flavor_agent' => [
							'requestToken' => 'request-token-' . $i,
						],
					],
				]
			);
		}

		$this->assertNull( RequestLoggingBridge::consume_log_id( 'request-token-1' ) );
		$this->assertSame( 'log-2', RequestLoggingBridge::consume_log_id( 'request-token-2' ) );
		$this->assertSame( 'log-51', RequestLoggingBridge::consume_log_id( 'request-token-51' ) );
	}
}
