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
