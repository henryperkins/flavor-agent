<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternIndexCloudflareRegressionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => PatternSearchInstanceManager::credential_signature(
				'account-123',
				'token-xyz',
				'@cf/qwen/qwen3-embedding-0.6b'
			),
		];
	}

	public function test_incremental_sync_uploads_only_changed_patterns_and_removes_only_retired_items(): void {
		$this->register_pattern( 'theme/hero', 'Original hero copy' );
		$this->register_pattern( 'theme/footer', 'Unchanged footer copy' );
		$this->register_pattern( 'theme/retired', 'Retired copy' );
		$original_items = $this->completed_items_for_current_patterns();

		$this->queue_item_list( [] );
		$this->queue_successful_writes( 3 );
		$this->queue_item_list( $original_items );
		$initial_result = PatternIndex::sync();
		$this->assertSame( 'ready', $initial_result['status'] );
		$this->assertSame( 3, $initial_result['indexed'] );
		$initial_state = PatternIndex::get_state();

		\WP_Block_Patterns_Registry::get_instance()->reset();
		$this->register_pattern( 'theme/hero', 'Updated hero copy' );
		$this->register_pattern( 'theme/footer', 'Unchanged footer copy' );
		PatternIndex::mark_dirty();
		WordPressTestState::$remote_get_calls  = [];
		WordPressTestState::$remote_post_calls = [];
		$this->queue_item_list( $original_items );
		$this->queue_successful_writes( 2 );
		$this->queue_item_list( $this->completed_items_for_current_patterns() );

		$result       = PatternIndex::sync();
		$state        = PatternIndex::get_state();
		$hero_uuid    = PatternIndex::pattern_uuid( 'theme/hero' );
		$footer_uuid  = PatternIndex::pattern_uuid( 'theme/footer' );
		$retired_uuid = PatternIndex::pattern_uuid( 'theme/retired' );

		$this->assertSame( 'ready', $result['status'] );
		$this->assertSame( 1, $result['indexed'] );
		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( 2, $state['indexed_count'] );
		$this->assertCount( 2, $state['pattern_fingerprints'] );
		$this->assertNotSame( $initial_state['pattern_fingerprints'][ $hero_uuid ], $state['pattern_fingerprints'][ $hero_uuid ] );
		$this->assertSame( $initial_state['pattern_fingerprints'][ $footer_uuid ], $state['pattern_fingerprints'][ $footer_uuid ] );
		$this->assertArrayNotHasKey( $retired_uuid, $state['pattern_fingerprints'] );
		$this->assertCount( 2, WordPressTestState::$remote_get_calls );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );

		$upload = WordPressTestState::$remote_post_calls[0];
		$this->assertSame( 'POST', $upload['args']['method'] ?? 'POST' );
		$this->assertStringContainsString( '/ai-search/namespaces/default/instances/' . PatternSearchInstanceManager::managed_instance_id() . '/items', $upload['url'] );
		$this->assertStringContainsString( 'filename="' . $hero_uuid . '.md"', $upload['args']['body'] );
		$this->assertStringContainsString( 'Updated hero copy', $upload['args']['body'] );
		$this->assertStringNotContainsString( 'filename="' . $footer_uuid . '.md"', $upload['args']['body'] );
		$this->assertStringNotContainsString( 'Unchanged footer copy', $upload['args']['body'] );

		$delete = WordPressTestState::$remote_post_calls[1];
		$this->assertSame( 'DELETE', $delete['args']['method'] );
		$this->assertStringContainsString( '/items/cloudflare-' . $retired_uuid, $delete['url'] );
		$this->assertFalse( get_transient( 'flavor_agent_sync_lock' ) );
	}

	/** @dataProvider rate_limited_phases */
	public function test_rate_limited_sync_preserves_retry_after_and_schedules_recovery( string $phase, int $retry_after ): void {
		$this->register_pattern( 'theme/hero', 'Hero copy' );
		$error_response = [
			'response' => [ 'code' => 429 ],
			'headers'  => [ 'retry-after' => (string) $retry_after ],
			'body'     => wp_json_encode( [ 'error' => [ 'message' => 'Cloudflare pattern sync rate limited' ] ] ),
		];

		if ( 'list' === $phase ) {
			WordPressTestState::$remote_get_responses[] = $error_response;
		} else {
			$this->queue_item_list( [] );
			WordPressTestState::$remote_post_responses[] = $error_response;
		}

		$before = time();
		$result = PatternIndex::sync();
		$after  = time();
		$state  = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 'Cloudflare pattern sync rate limited', $result->get_error_message() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( $retry_after, $result->get_error_data()['retry_after'] );
		$this->assertSame( 'error', $state['status'] );
		$this->assertSame( 'rate_limited', $state['last_error_code'] );
		$this->assertSame( 'Cloudflare pattern sync rate limited', $state['last_error'] );
		$this->assertSame( 429, $state['last_error_status'] );
		$this->assertTrue( PatternIndex::has_retryable_error( $state ) );
		$this->assertSame( $retry_after, $state['last_error_retry_after'] );
		$this->assertNotEmpty( $state['last_attempt_at'] );
		$this->assertNull( $state['last_synced_at'] );
		$this->assertFalse( PatternIndex::recommendation_index_readiness()['ready'] );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
		$scheduled = WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'];
		$this->assertGreaterThanOrEqual( $before + $retry_after, $scheduled );
		$this->assertLessThanOrEqual( $after + $retry_after, $scheduled );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
		$this->assertCount( 'list' === $phase ? 0 : 1, WordPressTestState::$remote_post_calls );
		$this->assertFalse( get_transient( 'flavor_agent_sync_lock' ) );
	}

	public static function rate_limited_phases(): array {
		return [
			'listing' => [ 'list', 11 ],
			'upload'  => [ 'upload', 17 ],
		];
	}

	public function test_full_reindex_interrupted_by_a_throttled_delete_resumes_without_reuploading(): void {
		$this->register_pattern( 'theme/hero', 'Hero copy' );
		$this->register_pattern( 'theme/footer', 'Footer copy' );
		$hero_uuid    = PatternIndex::pattern_uuid( 'theme/hero' );
		$footer_uuid  = PatternIndex::pattern_uuid( 'theme/footer' );
		$retired_uuid = PatternIndex::pattern_uuid( 'theme/retired' );
		// Rotated credentials invalidate the catalog signature and force a full rebuild.
		PatternIndex::save_state(
			[
				'status'                         => 'ready',
				'pattern_backend'                => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
				'fingerprint'                    => 'previous-catalog',
				'cloudflare_ai_search_namespace' => PatternSearchInstanceManager::managed_namespace(),
				'cloudflare_ai_search_instance'  => PatternSearchInstanceManager::managed_instance_id(),
				'cloudflare_ai_search_signature' => 'previous-signature',
				'last_synced_at'                 => '2026-10-01T00:00:00+00:00',
				'indexed_count'                  => 3,
				'pattern_fingerprints'           => [
					$hero_uuid    => 'previous-hero',
					$footer_uuid  => 'previous-footer',
					$retired_uuid => 'previous-retired',
				],
			]
		);

		$this->queue_item_list(
			[
				$this->remote_item( $hero_uuid ),
				$this->remote_item( $footer_uuid ),
				$this->remote_item( $retired_uuid ),
			]
		);
		$this->queue_successful_writes( 2 );
		WordPressTestState::$remote_post_responses[] = $this->instance_overloaded_response( '7' );

		$before = time();
		$first  = PatternIndex::sync();
		$after  = time();
		$paused = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $first );
		$this->assertSame( 'rate_limited', $first->get_error_code() );
		$this->assertSame( 7114, $first->get_error_data()['remote_error_code'] );
		$this->assertSame(
			'Cloudflare Pattern AI Search item delete is temporarily rate limited (Cloudflare error 7114: ai_search_instance_overloaded).',
			$first->get_error_message()
		);
		$this->assertSame( 'error', $paused['status'] );
		$this->assertTrue( PatternIndex::has_retryable_error( $paused ) );
		$this->assertSame( 7, $paused['last_error_retry_after'] );
		$this->assertSame( PatternSearchInstanceManager::managed_instance_id(), $paused['cloudflare_ai_search_instance'] );
		$this->assertNotSame( 'previous-signature', $paused['cloudflare_ai_search_signature'] );
		$this->assertSame( 'previous-retired', $paused['pattern_fingerprints'][ $retired_uuid ] );
		$this->assertNotSame( 'previous-hero', $paused['pattern_fingerprints'][ $hero_uuid ] );
		$this->assertNotSame( 'previous-footer', $paused['pattern_fingerprints'][ $footer_uuid ] );
		$scheduled = WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'];
		$this->assertGreaterThanOrEqual( $before + 7, $scheduled );
		$this->assertLessThanOrEqual( $after + 7, $scheduled );

		WordPressTestState::$remote_get_calls  = [];
		WordPressTestState::$remote_post_calls = [];
		$this->queue_item_list(
			[
				$this->remote_item( $hero_uuid, 'queued' ),
				$this->remote_item( $footer_uuid, 'queued' ),
				$this->remote_item( $retired_uuid ),
			]
		);
		$this->queue_successful_writes( 1 );
		$this->queue_item_list( [ $this->remote_item( $hero_uuid ), $this->remote_item( $footer_uuid ) ] );

		$second = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertIsArray( $second );
		$this->assertSame( 'ready', $second['status'] );
		$this->assertSame( 0, $second['indexed'] );
		$this->assertSame( 1, $second['removed'] );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls, 'A resumed rebuild must not upload accepted patterns again.' );
		$this->assertSame( 'DELETE', WordPressTestState::$remote_post_calls[0]['args']['method'] );
		$this->assertStringContainsString( '/items/cloudflare-' . $retired_uuid, WordPressTestState::$remote_post_calls[0]['url'] );
		$this->assertSame( 'ready', $state['status'] );
		$this->assertNotNull( $state['last_synced_at'] );
		$this->assertSame( 2, $state['indexed_count'] );
		$this->assertSame( $paused['cloudflare_ai_search_signature'], $state['cloudflare_ai_search_signature'] );
		$this->assertSame(
			[
				$hero_uuid   => $paused['pattern_fingerprints'][ $hero_uuid ],
				$footer_uuid => $paused['pattern_fingerprints'][ $footer_uuid ],
			],
			$state['pattern_fingerprints']
		);
	}

	public function test_incremental_sync_interrupted_by_a_throttled_delete_resumes_with_only_remaining_writes(): void {
		$this->register_pattern( 'theme/hero', 'Original hero copy' );
		$this->register_pattern( 'theme/footer', 'Footer copy' );
		$this->register_pattern( 'theme/retired-a', 'Retired copy A' );
		$this->register_pattern( 'theme/retired-b', 'Retired copy B' );
		$this->queue_item_list( [] );
		$this->queue_successful_writes( 4 );
		$this->queue_item_list( $this->completed_items_for_current_patterns() );
		$this->assertSame( 'ready', PatternIndex::sync()['status'] );
		$initial = PatternIndex::get_state();

		$hero_uuid      = PatternIndex::pattern_uuid( 'theme/hero' );
		$footer_uuid    = PatternIndex::pattern_uuid( 'theme/footer' );
		$retired_a_uuid = PatternIndex::pattern_uuid( 'theme/retired-a' );
		$retired_b_uuid = PatternIndex::pattern_uuid( 'theme/retired-b' );
		\WP_Block_Patterns_Registry::get_instance()->reset();
		$this->register_pattern( 'theme/hero', 'Updated hero copy' );
		$this->register_pattern( 'theme/footer', 'Footer copy' );
		PatternIndex::mark_dirty();
		WordPressTestState::$remote_post_calls = [];
		$this->queue_item_list(
			[
				$this->remote_item( $hero_uuid ),
				$this->remote_item( $footer_uuid ),
				$this->remote_item( $retired_a_uuid ),
				$this->remote_item( $retired_b_uuid ),
			]
		);
		$this->queue_successful_writes( 2 );
		WordPressTestState::$remote_post_responses[] = $this->instance_overloaded_response( '3' );

		$first  = PatternIndex::sync();
		$paused = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $first );
		$this->assertCount( 3, WordPressTestState::$remote_post_calls );
		$this->assertNotSame( $initial['pattern_fingerprints'][ $hero_uuid ], $paused['pattern_fingerprints'][ $hero_uuid ] );
		$this->assertSame( $initial['pattern_fingerprints'][ $footer_uuid ], $paused['pattern_fingerprints'][ $footer_uuid ] );
		$this->assertArrayNotHasKey( $retired_a_uuid, $paused['pattern_fingerprints'] );
		$this->assertArrayHasKey( $retired_b_uuid, $paused['pattern_fingerprints'] );
		$this->assertNotNull( $paused['last_synced_at'], 'The previous catalog stays usable while the incremental sync waits.' );

		WordPressTestState::$remote_post_calls = [];
		$this->queue_item_list(
			[
				$this->remote_item( $hero_uuid, 'queued' ),
				$this->remote_item( $footer_uuid ),
				$this->remote_item( $retired_b_uuid ),
			]
		);
		$this->queue_successful_writes( 1 );
		$this->queue_item_list( $this->completed_items_for_current_patterns() );

		$second = PatternIndex::sync();

		$this->assertIsArray( $second );
		$this->assertSame( 'ready', $second['status'] );
		$this->assertSame( 0, $second['indexed'] );
		$this->assertSame( 1, $second['removed'] );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame( 'DELETE', WordPressTestState::$remote_post_calls[0]['args']['method'] );
		$this->assertStringContainsString( '/items/cloudflare-' . $retired_b_uuid, WordPressTestState::$remote_post_calls[0]['url'] );
		$this->assertSame(
			[ $hero_uuid, $footer_uuid ],
			array_keys( PatternIndex::get_state()['pattern_fingerprints'] )
		);
	}

	public function test_rate_limited_sync_waits_for_the_full_cloudflare_retry_after(): void {
		$this->register_pattern( 'theme/hero', 'Hero copy' );
		$this->queue_item_list( [] );
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 429 ],
			'headers'  => [ 'retry-after' => '240' ],
			'body'     => wp_json_encode(
				[
					'success' => false,
					'errors'  => [
						[
							'code'    => 971,
							'message' => 'Please wait and consider throttling your request speed',
						],
					],
				]
			),
		];

		$before = time();
		$result = PatternIndex::sync();
		$after  = time();
		$state  = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 60, $result->get_error_data()['retry_after'], 'Foreground callers keep their bounded wait.' );
		$this->assertSame( 240, $state['last_error_retry_after'] );
		$scheduled = WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'];
		$this->assertGreaterThanOrEqual( $before + 240, $scheduled );
		$this->assertLessThanOrEqual( $after + 240, $scheduled );
	}

	public function test_rate_limited_sync_without_retry_after_backs_off_before_retrying(): void {
		$this->register_pattern( 'theme/hero', 'Hero copy' );
		$this->queue_item_list( [] );
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 429 ],
			'body'     => '',
		];

		$before = time();
		$result = PatternIndex::sync();
		$after  = time();
		$state  = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Cloudflare Pattern AI Search item upload is temporarily rate limited.', $result->get_error_message() );
		$this->assertTrue( PatternIndex::has_retryable_error( $state ) );
		$this->assertSame( 30, $state['last_error_retry_after'] );
		$scheduled = WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'];
		$this->assertGreaterThanOrEqual( $before + 30, $scheduled );
		$this->assertLessThanOrEqual( $after + 30, $scheduled );
	}

	public function test_exhausted_cloudflare_quota_stops_the_sync_without_scheduling_retries(): void {
		$this->register_pattern( 'theme/hero', 'Hero copy' );
		$this->queue_item_list( [] );
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 429 ],
			'body'     => wp_json_encode(
				[
					'success' => false,
					'errors'  => [
						[
							'code'    => 7116,
							'message' => 'monthly_ingestion_quota_exceeded',
						],
					],
				]
			),
		];

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'quota_exceeded', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertStringContainsString( 'Cloudflare error 7116: monthly_ingestion_quota_exceeded', $result->get_error_message() );
		$this->assertSame( 'error', $state['status'] );
		$this->assertSame( 'quota_exceeded', $state['last_error_code'] );
		$this->assertFalse( PatternIndex::has_retryable_error( $state ) );
		$this->assertNull( $state['last_error_retry_after'] );
		$this->assertArrayNotHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
	}

	private function register_pattern( string $name, string $copy ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			[
				'title'         => $name,
				'description'   => 'Description for ' . $name,
				'categories'    => [ 'marketing' ],
				'blockTypes'    => [ 'core/group' ],
				'templateTypes' => [ 'home' ],
				'content'       => '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->',
			]
		);
	}

	/** @return array<int, array<string, string>> */
	private function completed_items_for_current_patterns(): array {
		return array_map(
			static function ( array $pattern ): array {
				$uuid = PatternIndex::pattern_uuid( $pattern['name'] );
				return [
					'id'     => 'cloudflare-' . $uuid,
					'key'    => $uuid . '.md',
					'status' => 'completed',
				];
			},
			ServerCollector::for_patterns()
		);
	}

	/** @return array<string, string> */
	private function remote_item( string $uuid, string $status = 'completed' ): array {
		return [
			'id'     => 'cloudflare-' . $uuid,
			'key'    => $uuid . '.md',
			'status' => $status,
		];
	}

	/** @return array<string, mixed> */
	private function instance_overloaded_response( string $retry_after ): array {
		return [
			'response' => [ 'code' => 429 ],
			'headers'  => [ 'retry-after' => $retry_after ],
			'body'     => wp_json_encode(
				[
					'success' => false,
					'errors'  => [
						[
							'code'    => 7114,
							'message' => 'ai_search_instance_overloaded',
						],
					],
				]
			),
		];
	}

	/** @param array<int, array<string, string>> $items */
	private function queue_item_list( array $items ): void {
		WordPressTestState::$remote_get_responses[] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result'      => $items,
					'result_info' => [
						'count'       => count( $items ),
						'page'        => 1,
						'total_count' => count( $items ),
						'per_page'    => 50,
					],
				]
			),
		];
	}

	private function queue_successful_writes( int $count ): void {
		for ( $index = 0; $index < $count; $index++ ) {
			WordPressTestState::$remote_post_responses[] = [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'success' => true ] ),
			];
		}
	}
}
