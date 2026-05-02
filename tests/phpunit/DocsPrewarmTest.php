<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DocsPrewarmTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	// ------------------------------------------------------------------
	// Warm set definition
	// ------------------------------------------------------------------

	public function test_warm_set_covers_expected_block_template_and_navigation_entities(): void {
		$warm_set = AISearchClient::get_warm_set();

		$this->assertArrayHasKey( 'core/paragraph', $warm_set );
		$this->assertArrayHasKey( 'core/heading', $warm_set );
		$this->assertArrayHasKey( 'core/image', $warm_set );
		$this->assertArrayHasKey( 'core/group', $warm_set );
		$this->assertArrayHasKey( 'core/columns', $warm_set );
		$this->assertArrayHasKey( 'core/button', $warm_set );
		$this->assertArrayHasKey( 'core/list', $warm_set );
		$this->assertArrayHasKey( 'core/cover', $warm_set );
		$this->assertArrayHasKey( 'core/template-part', $warm_set );
		$this->assertArrayHasKey( 'core/navigation', $warm_set );
		$this->assertArrayHasKey( 'template:single', $warm_set );
		$this->assertArrayHasKey( 'template:page', $warm_set );
		$this->assertArrayHasKey( 'template:archive', $warm_set );
		$this->assertArrayHasKey( 'template:home', $warm_set );
		$this->assertArrayHasKey( 'template:404', $warm_set );
		$this->assertArrayHasKey( 'template:index', $warm_set );
		$this->assertArrayHasKey( 'template:search', $warm_set );
		$this->assertArrayHasKey( 'guidance:block-editor', $warm_set );
		$this->assertArrayHasKey( 'guidance:global-styles', $warm_set );
		$this->assertArrayHasKey( 'guidance:style-book', $warm_set );
		$this->assertArrayHasKey( 'guidance:template', $warm_set );
		$this->assertArrayHasKey( 'guidance:template-part', $warm_set );
		$this->assertCount( 22, $warm_set );

		foreach ( $warm_set as $entity_key => $query ) {
			$this->assertNotEmpty( $query, "Query for {$entity_key} must not be empty." );
			$this->assertIsString( $query );
		}
	}

	// ------------------------------------------------------------------
	// Prewarm execution
	// ------------------------------------------------------------------

	public function test_prewarm_seeds_entity_cache_for_all_warm_set_entities(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		$summary = AISearchClient::prewarm();

		$this->assertSame( count( AISearchClient::get_warm_set() ), $summary['warmed'] );
		$this->assertSame( 0, $summary['failed'] );
		$this->assertSame( 0, $summary['skipped'] );

		foreach ( AISearchClient::get_warm_set() as $entity_key => $query ) {
			$cached = AISearchClient::maybe_search_entity( $entity_key );
			$this->assertNotEmpty( $cached, "Entity cache for {$entity_key} should be seeded." );
			$this->assertSame( 'chunk-1', $cached[0]['id'] );
		}
	}

	public function test_prewarm_records_state_with_timestamp_and_summary(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		AISearchClient::prewarm();

		$state = AISearchClient::get_prewarm_state();

		$this->assertNotEmpty( $state['timestamp'] );
		$this->assertNotEmpty( $state['fingerprint'] );
		$this->assertSame( count( AISearchClient::get_warm_set() ), $state['warmed'] );
		$this->assertSame( 0, $state['failed'] );
		$this->assertSame( 0, $state['skipped'] );
		$this->assertSame( 'ok', $state['status'] );
	}

	public function test_prewarm_skips_built_in_public_search_endpoint_by_default(): void {
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		$summary = AISearchClient::prewarm();

		$this->assertSame( 0, $summary['warmed'] );
		$this->assertSame( 0, $summary['failed'] );
		$this->assertSame( 0, $summary['skipped'] );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertSame( 'off', AISearchClient::get_prewarm_state()['status'] );
	}

	public function test_prewarm_can_use_built_in_public_search_endpoint_when_explicitly_enabled(): void {
		add_filter( 'flavor_agent_cloudflare_ai_search_allow_public_prewarm', '__return_true' );

		try {
			$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

			$summary = AISearchClient::prewarm();

			$this->assertSame( count( AISearchClient::get_warm_set() ), $summary['warmed'] );
			$this->assertSame( 0, $summary['failed'] );
			$this->assertSame( 0, $summary['skipped'] );
			$this->assertSame(
				'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search',
				WordPressTestState::$last_remote_post['url']
			);
			$this->assertArrayNotHasKey(
				'Authorization',
				WordPressTestState::$last_remote_post['args']['headers']
			);
		} finally {
			remove_filter( 'flavor_agent_cloudflare_ai_search_allow_public_prewarm', '__return_true' );
		}
	}

	// ------------------------------------------------------------------
	// Throttling
	// ------------------------------------------------------------------

	public function test_prewarm_throttled_with_same_credentials_within_window(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		$first = AISearchClient::prewarm();
		$this->assertSame( count( AISearchClient::get_warm_set() ), $first['warmed'] );
		$first_state_timestamp = AISearchClient::get_prewarm_state()['timestamp'];

		$call_count_after_first = count( WordPressTestState::$remote_post_calls );

		// Second prewarm with same credentials within throttle window.
		$second = AISearchClient::prewarm();

		$this->assertSame( 0, $second['warmed'] );
		$this->assertSame( 0, $second['failed'] );
		$this->assertSame( count( AISearchClient::get_warm_set() ), $second['skipped'] );

		// No additional Cloudflare calls made.
		$this->assertCount( $call_count_after_first, WordPressTestState::$remote_post_calls );

		$state = AISearchClient::get_prewarm_state();
		$this->assertSame( 'throttled', $state['status'] );
		$this->assertSame( count( AISearchClient::get_warm_set() ), $state['skipped'] );
		$this->assertSame( $first_state_timestamp, $state['timestamp'] );
	}

	public function test_prewarm_runs_again_when_credentials_change(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) * 2 );

		AISearchClient::prewarm();

		// Change credentials.
		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_api_token'] = 'new-token-abc';

		$second = AISearchClient::prewarm();

		$this->assertSame( count( AISearchClient::get_warm_set() ), $second['warmed'] );
		$this->assertSame( 0, $second['skipped'] );
	}

	public function test_prewarm_runs_again_when_throttle_window_expires(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		AISearchClient::prewarm();

		// Simulate expired throttle by backdating the visible timestamp.
		$state              = WordPressTestState::$options['flavor_agent_docs_prewarm_state'];
		$expired_timestamp  = gmdate( 'Y-m-d H:i:s', time() - 7200 );
		$state['timestamp'] = $expired_timestamp;
		WordPressTestState::$options['flavor_agent_docs_prewarm_state'] = $state;

		// Re-prime responses for the second run.
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		$second = AISearchClient::prewarm();

		$this->assertSame( count( AISearchClient::get_warm_set() ), $second['warmed'] );
		$this->assertSame( 0, $second['skipped'] );
	}

	// ------------------------------------------------------------------
	// Partial failure
	// ------------------------------------------------------------------

	public function test_prewarm_partial_failure_records_both_successes_and_errors(): void {
		$this->configure_cloudflare();

		$warm_set = AISearchClient::get_warm_set();
		$total    = count( $warm_set );

		// First entity succeeds, second fails, rest succeed.
		$responses = [];

		$i = 0;
		foreach ( $warm_set as $entity_key => $query ) {
			if ( $i === 1 ) {
				$responses[] = new \WP_Error( 'http_request_failed', 'Timeout on entity.' );
			} else {
				$responses[] = $this->build_successful_response();
			}
			++$i;
		}

		WordPressTestState::$remote_post_responses = $responses;

		$summary = AISearchClient::prewarm();

		$this->assertSame( $total - 1, $summary['warmed'] );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( 0, $summary['skipped'] );

		// Check entity-level detail.
		$found_error = false;
		foreach ( $summary['entities'] as $entity_key => $status_text ) {
			if ( str_starts_with( $status_text, 'error:' ) ) {
				$found_error = true;
			}
		}
		$this->assertTrue( $found_error, 'At least one entity should have error status.' );

		$state = AISearchClient::get_prewarm_state();
		$this->assertSame( 'partial', $state['status'] );
	}

	public function test_prewarm_all_failures_records_failed_status(): void {
		$this->configure_cloudflare();

		$warm_set  = AISearchClient::get_warm_set();
		$responses = [];

		foreach ( $warm_set as $entity_key => $query ) {
			$responses[] = new \WP_Error( 'http_request_failed', 'Total failure.' );
		}

		WordPressTestState::$remote_post_responses = $responses;

		$summary = AISearchClient::prewarm();

		$this->assertSame( 0, $summary['warmed'] );
		$this->assertSame( count( $warm_set ), $summary['failed'] );

		$state = AISearchClient::get_prewarm_state();
		$this->assertSame( 'failed', $state['status'] );
	}

	// ------------------------------------------------------------------
	// schedule_prewarm()
	// ------------------------------------------------------------------

	public function test_schedule_prewarm_creates_cron_event_when_configured(): void {
		$this->configure_cloudflare();

		AISearchClient::schedule_prewarm();

		$this->assertArrayHasKey(
			AISearchClient::PREWARM_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
		$event = WordPressTestState::$scheduled_events[ AISearchClient::PREWARM_CRON_HOOK ];
		$this->assertSame( AISearchClient::PREWARM_CRON_HOOK, $event['hook'] );
		$this->assertGreaterThan( time() - 10, $event['timestamp'] );
	}

	public function test_schedule_prewarm_does_not_create_event_when_using_built_in_public_search_endpoint_by_default(): void {
		AISearchClient::schedule_prewarm();

		$this->assertArrayNotHasKey(
			AISearchClient::PREWARM_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
	}

	public function test_schedule_prewarm_can_use_public_endpoint_when_explicitly_enabled(): void {
		add_filter( 'flavor_agent_cloudflare_ai_search_allow_public_prewarm', '__return_true' );

		try {
			AISearchClient::schedule_prewarm();

			$this->assertArrayHasKey(
				AISearchClient::PREWARM_CRON_HOOK,
				WordPressTestState::$scheduled_events
			);
		} finally {
			remove_filter( 'flavor_agent_cloudflare_ai_search_allow_public_prewarm', '__return_true' );
		}
	}

	public function test_schedule_prewarm_does_not_double_schedule(): void {
		$this->configure_cloudflare();

		AISearchClient::schedule_prewarm();
		$first_timestamp = WordPressTestState::$scheduled_events[ AISearchClient::PREWARM_CRON_HOOK ]['timestamp'];

		// Calling again should not overwrite the scheduled event.
		AISearchClient::schedule_prewarm();

		$this->assertSame(
			$first_timestamp,
			WordPressTestState::$scheduled_events[ AISearchClient::PREWARM_CRON_HOOK ]['timestamp']
		);
	}

	public function test_schedule_prewarm_does_not_rearm_within_throttle_window_after_prewarm_runs(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		AISearchClient::prewarm();
		AISearchClient::schedule_prewarm();

		$this->assertArrayNotHasKey(
			AISearchClient::PREWARM_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
	}

	public function test_schedule_prewarm_accepts_explicit_credential_changes_even_when_current_config_is_throttled(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		AISearchClient::prewarm();
		AISearchClient::schedule_prewarm(
			'account-123',
			'wp-dev-docs',
			'changed-token'
		);

		$this->assertArrayHasKey(
			AISearchClient::PREWARM_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
	}

	// ------------------------------------------------------------------
	// should_prewarm()
	// ------------------------------------------------------------------

	public function test_should_prewarm_returns_true_when_never_run(): void {
		$this->configure_cloudflare();

		$this->assertTrue( AISearchClient::should_prewarm() );
	}

	public function test_should_prewarm_returns_false_when_using_built_in_public_search_endpoint_by_default(): void {
		$this->assertFalse( AISearchClient::should_prewarm() );
	}

	public function test_should_prewarm_returns_false_within_throttle_window(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		AISearchClient::prewarm();

		$this->assertFalse( AISearchClient::should_prewarm() );
	}

	public function test_should_prewarm_returns_true_after_credential_change(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		AISearchClient::prewarm();

		WordPressTestState::$options['flavor_agent_cloudflare_ai_search_api_token'] = 'changed-token';

		$this->assertTrue( AISearchClient::should_prewarm() );
	}

	// ------------------------------------------------------------------
	// get_prewarm_state() diagnostics
	// ------------------------------------------------------------------

	public function test_get_prewarm_state_returns_off_when_prewarm_is_not_configured(): void {
		$state = AISearchClient::get_prewarm_state();

		$this->assertSame( '', $state['timestamp'] );
		$this->assertSame( '', $state['fingerprint'] );
		$this->assertSame( 0, $state['warmed'] );
		$this->assertSame( 0, $state['failed'] );
		$this->assertSame( 0, $state['skipped'] );
		$this->assertSame( 'off', $state['status'] );
	}

	public function test_schedule_prewarm_records_off_state_when_prewarm_is_not_configured(): void {
		AISearchClient::schedule_prewarm();

		$state = AISearchClient::get_prewarm_state();

		$this->assertSame( '', $state['timestamp'] );
		$this->assertSame( 'not_configured', $state['fingerprint'] );
		$this->assertSame( 'off', $state['status'] );
	}

	public function test_get_prewarm_state_reflects_successful_run(): void {
		$this->configure_cloudflare();
		$this->prime_successful_search_responses( count( AISearchClient::get_warm_set() ) );

		AISearchClient::prewarm();

		$state = AISearchClient::get_prewarm_state();

		$this->assertSame( 'ok', $state['status'] );
		$this->assertGreaterThan( 0, $state['warmed'] );
	}

	// ------------------------------------------------------------------
	// Resilience: failed prewarm does not break normal flows
	// ------------------------------------------------------------------

	public function test_failed_prewarm_does_not_break_recommendation_flows(): void {
		$this->configure_cloudflare();

		// All prewarm calls fail.
		$responses = [];
		foreach ( AISearchClient::get_warm_set() as $entity_key => $query ) {
			$responses[] = new \WP_Error( 'http_request_failed', 'Connection refused.' );
		}
		WordPressTestState::$remote_post_responses = $responses;

		$summary = AISearchClient::prewarm();
		$this->assertSame( 0, $summary['warmed'] );
		$this->assertGreaterThan( 0, $summary['failed'] );

		// Normal cache-only maybe_search still works fine.
		$this->assertSame( [], AISearchClient::maybe_search( 'some query', 4 ) );
		$this->assertSame( [], AISearchClient::maybe_search_entity( 'core/paragraph' ) );
		$this->assertSame(
			[],
			AISearchClient::maybe_search_with_entity_fallback( 'some query', 'core/paragraph', 4 )
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function configure_cloudflare(): void {
		WordPressTestState::$options = array_merge(
			WordPressTestState::$options,
			[
				'flavor_agent_cloudflare_ai_search_account_id'  => 'account-123',
				'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
				'flavor_agent_cloudflare_ai_search_api_token'   => 'token-xyz',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_successful_response(): array {
		return [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'search_query' => 'rewritten query',
						'chunks'       => [
							[
								'id'    => 'chunk-1',
								'score' => 0.88,
								'item'  => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/core-blocks',
									'metadata' => [],
								],
								'text'  => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/core-blocks/\"\n---\nBlock editor best practices for core blocks.",
							],
						],
					],
				]
			),
		];
	}

	private function prime_successful_search_responses( int $count ): void {
		$responses = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$responses[] = $this->build_successful_response();
		}
		WordPressTestState::$remote_post_responses = $responses;
	}
}
