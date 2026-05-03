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

	public function test_throwable_context_returns_canonical_shape(): void {
		$throwable = new \RuntimeException( 'boom' );
		$context   = RequestTrace::throwable_context( $throwable );

		$this->assertSame( \RuntimeException::class, $context['throwable']['class'] );
		$this->assertSame( 'boom', $context['throwable']['message'] );
		$this->assertNotEmpty( $context['throwable']['file'] );
		$this->assertIsInt( $context['throwable']['line'] );
	}

	public function test_event_truncates_strings_beyond_max_bytes(): void {
		$captured = [];
		add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
		add_action(
			'flavor_agent_diagnostic_trace',
			static function ( array $entry ) use ( &$captured ): void {
				$captured[] = $entry;
			},
			10,
			1
		);

		RequestTrace::start( 'test-surface', [], 'trace.start' );
		RequestTrace::event(
			'trace.event',
			[ 'longString' => str_repeat( 'a', 1000 ) ]
		);
		RequestTrace::finish();

		$event_entry = $captured[1] ?? [];
		$this->assertStringEndsWith( '...', $event_entry['context']['longString'] ?? '' );
		$this->assertSame( 303, strlen( $event_entry['context']['longString'] ?? '' ) );
	}

	public function test_event_truncates_arrays_beyond_max_items(): void {
		$captured = [];
		add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
		add_action(
			'flavor_agent_diagnostic_trace',
			static function ( array $entry ) use ( &$captured ): void {
				$captured[] = $entry;
			}
		);

		RequestTrace::start( 'test-surface', [], 'trace.start' );
		RequestTrace::event( 'trace.event', [ 'items' => range( 1, 100 ) ] );
		RequestTrace::finish();

		$event_entry = $captured[1] ?? [];
		$this->assertSame( 70, $event_entry['context']['items']['_truncated'] ?? null );
	}

	public function test_event_caps_recursion_depth(): void {
		$captured = [];
		add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
		add_action(
			'flavor_agent_diagnostic_trace',
			static function ( array $entry ) use ( &$captured ): void {
				$captured[] = $entry;
			}
		);

		$deep = 'leaf';
		for ( $i = 0; $i < 10; $i++ ) {
			$deep = [ 'next' => $deep ];
		}

		RequestTrace::start( 'test-surface', [], 'trace.start' );
		RequestTrace::event( 'trace.event', [ 'tree' => $deep ] );
		RequestTrace::finish();

		$walked = $captured[1]['context']['tree'] ?? null;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertIsArray( $walked );
			$walked = $walked['next'];
		}
		$this->assertSame( '[truncated]', $walked );
	}

	public function test_should_write_error_log_returns_false_when_filter_throws(): void {
		add_filter(
			'flavor_agent_diagnostic_trace_enabled',
			static function (): bool {
				throw new \RuntimeException( 'filter exploded' );
			}
		);

		RequestTrace::start( 'test-surface', [], 'trace.start' );

		$this->expectNotToPerformAssertions();

		RequestTrace::finish();
	}

	public function test_is_active_toggles_with_start_and_finish(): void {
		$this->assertFalse( RequestTrace::is_active() );

		RequestTrace::start( 'test-surface' );
		$this->assertTrue( RequestTrace::is_active() );

		RequestTrace::finish();
		$this->assertFalse( RequestTrace::is_active() );
	}
}
