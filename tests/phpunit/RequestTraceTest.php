<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\RequestTrace;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RequestTraceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}
	}

	protected function setUp(): void {
		WordPressTestState::$filters['flavor_agent_diagnostic_trace_enabled'] = [];
		WordPressTestState::$filters['flavor_agent_diagnostic_trace']         = [];
		WordPressTestState::$do_action_counts                                 = [];

		$reflection = new ReflectionClass( RequestTrace::class );
		$active     = $reflection->getProperty( 'active' );
		$active->setAccessible( true );
		$active->setValue( null, [] );
	}

	public function test_diagnostic_trace_does_not_enable_log_writes_when_only_wp_debug_is_set(): void {
		$this->assertTrue( WP_DEBUG, 'precondition: WP_DEBUG must be true for this test.' );
		$this->assertFalse(
			defined( 'FLAVOR_AGENT_ENABLE_DIAGNOSTIC_TRACE' ),
			'precondition: explicit diagnostic trace constant must be undefined.'
		);

		$captured_input = null;
		add_filter(
			'flavor_agent_diagnostic_trace_enabled',
			static function ( $enabled ) use ( &$captured_input ) {
				$captured_input = $enabled;

				return false;
			}
		);

		RequestTrace::start( 'request-trace-test' );
		RequestTrace::finish();

		$this->assertFalse(
			$captured_input,
			'WP_DEBUG alone must not pre-enable error_log writes; only the explicit diagnostic constant or filter override should.'
		);
	}

	public function test_is_consumed_returns_false_when_no_observer_or_log_filter(): void {
		$this->assertFalse( RequestTrace::is_consumed() );
	}

	public function test_is_consumed_returns_true_when_action_listener_attached(): void {
		add_action( 'flavor_agent_diagnostic_trace', static fn () => null );

		$this->assertTrue( RequestTrace::is_consumed() );
	}

	public function test_is_consumed_returns_true_when_log_filter_enables_writes(): void {
		add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_true' );

		$this->assertTrue( RequestTrace::is_consumed() );
	}
}
