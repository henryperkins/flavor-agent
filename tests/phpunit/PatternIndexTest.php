<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternIndexTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->configure_backends();
	}

	public function test_compute_fingerprint_is_order_stable_and_changes_when_pattern_data_changes(): void {
		$hero    = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );
		$utility = $this->pattern_fixture( 'theme/header-utility', 'Header Utility', 'Utility copy' );

		$baseline = PatternIndex::compute_fingerprint( [ $hero, $utility ] );

		$this->assertSame( $baseline, PatternIndex::compute_fingerprint( [ $utility, $hero ] ) );

		$changed               = $utility;
		$changed['content']    = 'Updated utility copy';
		$changed['categories'] = [ 'marketing', 'utility' ];

		$this->assertNotSame( $baseline, PatternIndex::compute_fingerprint( [ $hero, $changed ] ) );
	}

	public function test_pattern_uuid_is_deterministic_and_build_embedding_text_includes_expected_fields(): void {
		$uuid = PatternIndex::pattern_uuid( 'theme/hero' );

		$this->assertSame( $uuid, PatternIndex::pattern_uuid( 'theme/hero' ) );
		$this->assertNotSame( $uuid, PatternIndex::pattern_uuid( 'theme/footer-callout' ) );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$uuid
		);

		$content = str_repeat( 'x', 520 );
		$text    = PatternIndex::build_embedding_text(
			[
				'title'            => 'Hero',
				'description'      => 'Editorial hero',
				'categories'       => [ 'marketing', 'featured' ],
				'blockTypes'       => [ 'core/group', 'core/heading' ],
				'templateTypes'    => [ 'home', 'front-page' ],
				'patternOverrides' => [
					'hasOverrides'       => true,
					'overrideAttributes' => [
						'core/heading' => [ 'content' ],
					],
				],
				'content'          => $content,
			]
		);

		$lines = explode( "\n", $text );

		$this->assertSame( 'Hero', $lines[0] );
		$this->assertSame( 'Editorial hero', $lines[1] );
		$this->assertSame( 'Categories: marketing, featured', $lines[2] );
		$this->assertSame( 'Block types: core/group, core/heading', $lines[3] );
		$this->assertSame( 'Template types: home, front-page', $lines[4] );
		$this->assertSame( 'Layout traits: simple, text-focused, showcase', $lines[5] );
		$this->assertSame( 'Pattern overrides: yes', $lines[6] );
		$this->assertSame( 'Override-ready core/heading: content', $lines[7] );
		$this->assertSame( str_repeat( 'x', 500 ), $lines[8] );
	}

	public function test_compute_fingerprint_changes_when_pattern_override_metadata_changes(): void {
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$baseline = PatternIndex::compute_fingerprint( [ $hero ] );

		$override_ready                     = $hero;
		$override_ready['patternOverrides'] = [
			'hasOverrides'          => true,
			'blockCount'            => 1,
			'blockNames'            => [ 'core/heading' ],
			'bindableAttributes'    => [ 'core/heading' => [ 'content' ] ],
			'overrideAttributes'    => [ 'core/heading' => [ 'content' ] ],
			'usesDefaultBinding'    => false,
			'unsupportedAttributes' => [],
		];

		$this->assertNotSame( $baseline, PatternIndex::compute_fingerprint( [ $override_ready ] ) );
	}

	public function test_mark_dirty_uses_stale_when_a_usable_index_exists_and_uninitialized_otherwise(): void {
		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'         => 'ready',
					'last_synced_at' => '2026-03-24T00:00:00+00:00',
				]
			)
		);

		PatternIndex::mark_dirty();

		$this->assertSame( 'stale', PatternIndex::get_state()['status'] );

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'         => 'ready',
					'last_synced_at' => null,
				]
			)
		);

		PatternIndex::mark_dirty();

		$this->assertSame( 'uninitialized', PatternIndex::get_state()['status'] );
	}

	public function test_synced_pattern_changes_mark_index_dirty_and_schedule_sync(): void {
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );
		$this->save_ready_state_for_patterns( $this->current_patterns() );
		WordPressTestState::$scheduled_events = [];

		PatternIndex::handle_synced_pattern_change(
			51,
			(object) [
				'ID'        => 51,
				'post_type' => 'wp_block',
			],
			true
		);

		$state = PatternIndex::get_state();

		$this->assertSame( 'stale', $state['status'] );
		$this->assertSame( 'synced_pattern_changed', $state['stale_reason'] );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );

		$this->save_ready_state_for_patterns( $this->current_patterns() );
		WordPressTestState::$scheduled_events = [];

		PatternIndex::handle_synced_pattern_change(
			52,
			(object) [
				'ID'        => 52,
				'post_type' => 'post',
			],
			true
		);

		$this->assertSame( 'ready', PatternIndex::get_state()['status'] );
		$this->assertSame( [], WordPressTestState::$scheduled_events );
	}

	public function test_runtime_state_marks_legacy_collection_names_stale_with_a_compatibility_reason(): void {
		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'               => 'ready',
					'qdrant_url'           => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'    => 'flavor-agent-patterns-legacy',
					'openai_provider'      => Provider::NATIVE,
					'openai_endpoint'      => 'https://api.openai.com',
					'embedding_model'      => 'text-embedding-3-large',
					'embedding_dimension'  => 2,
					'embedding_signature'  => EmbeddingClient::build_signature_for_dimension( 2 )['signature_hash'],
					'last_synced_at'       => '2026-03-24T00:00:00+00:00',
					'stale_reason'         => '',
					'stale_reasons'        => [],
					'pattern_fingerprints' => [],
				]
			)
		);

		$state = PatternIndex::get_runtime_state();

		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'collection_name_changed', $state['stale_reasons'] );
		$this->assertTrue( PatternIndex::has_compatibility_drift( $state ) );
	}

	public function test_runtime_state_treats_embedding_endpoint_drift_as_compatibility_drift(): void {
		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'               => 'ready',
					'qdrant_url'           => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'    => QdrantClient::get_collection_name(
						EmbeddingClient::build_signature_for_dimension( 2 )
					),
					'openai_provider'      => Provider::NATIVE,
					'openai_endpoint'      => 'https://old.example.test',
					'embedding_model'      => 'text-embedding-3-large',
					'embedding_dimension'  => 2,
					'embedding_signature'  => EmbeddingClient::build_signature_for_dimension( 2 )['signature_hash'],
					'last_synced_at'       => '2026-03-24T00:00:00+00:00',
					'stale_reason'         => '',
					'stale_reasons'        => [],
					'pattern_fingerprints' => [],
				]
			)
		);

		$state = PatternIndex::get_runtime_state();

		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'openai_endpoint_changed', $state['stale_reasons'] );
		$this->assertTrue( PatternIndex::has_compatibility_drift( $state ) );
	}

	public function test_collection_name_changes_when_embedding_signature_changes(): void {
		$small = QdrantClient::get_collection_name(
			EmbeddingClient::build_signature_for_dimension( 2 )
		);
		$large = QdrantClient::get_collection_name(
			EmbeddingClient::build_signature_for_dimension( 3 )
		);

		$this->assertNotSame( $small, $large );
	}

	public function test_schedule_sync_requires_backends_and_respects_existing_events_cooldown_and_force(): void {
		WordPressTestState::$options = [];

		PatternIndex::schedule_sync();

		$this->assertSame( [], WordPressTestState::$scheduled_events );

		$this->configure_backends();
		PatternIndex::schedule_sync();

		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );

		$scheduled = WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'];

		PatternIndex::schedule_sync();

		$this->assertSame( $scheduled, WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'] );

		WordPressTestState::$scheduled_events = [];
		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'last_attempt_at' => gmdate( 'c' ),
				]
			)
		);

		PatternIndex::schedule_sync();

		$this->assertSame( [], WordPressTestState::$scheduled_events );

		PatternIndex::schedule_sync( true );

		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
	}

	public function test_sync_no_ops_when_state_matches_current_patterns_and_backend_configuration(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->register_pattern( 'theme/footer-callout', $this->pattern_fixture( 'theme/footer-callout', 'Footer Callout', 'Footer copy' ) );

		$patterns = $this->current_patterns();

		$this->save_ready_state_for_patterns( $patterns );
		$this->queue_signature_probe();

		$result = PatternIndex::sync();

		$this->assertSame(
			[
				'indexed'     => 2,
				'removed'     => 0,
				'fingerprint' => PatternIndex::compute_fingerprint( $patterns ),
				'status'      => 'ready',
			],
			$result
		);
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sync_performs_full_reindex_when_no_usable_index_exists(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->register_pattern( 'theme/footer-callout', $this->pattern_fixture( 'theme/footer-callout', 'Footer Callout', 'Footer copy' ) );

		$this->queue_signature_probe();
		$this->queue_ensure_collection( false );
		$this->queue_scroll( [] );
		$this->queue_embeddings(
			[
				[ 0.11, 0.22 ],
				[ 0.33, 0.44 ],
			]
		);
		$this->queue_qdrant_success( '/points', 'PUT' );

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertSame( 2, $result['indexed'] );
		$this->assertSame( 0, $result['removed'] );
		$this->assertSame( 'ready', $result['status'] );
		$this->assertSame( 'ready', $state['status'] );
		$this->assertSame( 2, $state['indexed_count'] );
		$this->assertCount( 2, $state['pattern_fingerprints'] );

		$upsert_call = $this->find_remote_post_call( '/points', 'PUT' );
		$upsert_body = $this->decode_request_body( $upsert_call );

		$this->assertSame(
			[
				PatternIndex::pattern_uuid( 'theme/hero' ),
				PatternIndex::pattern_uuid( 'theme/footer-callout' ),
			],
			array_column( $upsert_body['points'] ?? [], 'id' )
		);
		$this->assertSame(
			$this->collection_url(),
			WordPressTestState::$remote_get_calls[0]['url'] ?? null
		);
	}

	public function test_sync_indexes_registered_and_synced_patterns(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		WordPressTestState::$posts = [
			51 => (object) [
				'ID'                => 51,
				'post_type'         => 'wp_block',
				'post_title'        => 'Synced Hero',
				'post_name'         => 'synced-hero',
				'post_content'      => '<!-- wp:cover --><div class="wp-block-cover">Synced hero</div><!-- /wp:cover -->',
				'post_status'       => 'publish',
				'post_author'       => 7,
				'post_date_gmt'     => '2026-04-20 00:00:00',
				'post_modified_gmt' => '2026-04-21 00:00:00',
			],
		];

		$this->queue_signature_probe();
		$this->queue_ensure_collection( false );
		$this->queue_scroll( [] );
		$this->queue_embeddings(
			[
				[ 0.11, 0.22 ],
				[ 0.33, 0.44 ],
			]
		);
		$this->queue_qdrant_success( '/points', 'PUT' );

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertSame( 2, $result['indexed'] );
		$this->assertSame( 2, $state['indexed_count'] );
		$this->assertCount( 2, $state['pattern_fingerprints'] );

		$upsert_call      = $this->find_remote_post_call( '/points', 'PUT' );
		$upsert_body      = $this->decode_request_body( $upsert_call );
		$payloads_by_name = [];

		foreach ( $upsert_body['points'] ?? [] as $point ) {
			$payload                                       = is_array( $point['payload'] ?? null ) ? $point['payload'] : [];
			$payloads_by_name[ (string) $payload['name'] ] = $payload;
		}

		$this->assertArrayHasKey( 'theme/hero', $payloads_by_name );
		$this->assertArrayHasKey( 'core/block/51', $payloads_by_name );
		$this->assertSame( 'registered', $payloads_by_name['theme/hero']['source'] ?? '' );
		$this->assertSame( 'user', $payloads_by_name['core/block/51']['type'] ?? '' );
		$this->assertSame( 'synced', $payloads_by_name['core/block/51']['source'] ?? '' );
		$this->assertSame( 51, $payloads_by_name['core/block/51']['syncedPatternId'] ?? 0 );
		$this->assertSame( 'synced', $payloads_by_name['core/block/51']['syncStatus'] ?? '' );
		$this->assertStringContainsString( 'Synced hero', $payloads_by_name['core/block/51']['content'] ?? '' );
	}

	public function test_sync_performs_incremental_reindex_and_deletes_removed_patterns(): void {
		$hero   = $this->pattern_fixture( 'theme/hero', 'Hero', 'Updated hero copy' );
		$footer = $this->pattern_fixture( 'theme/footer-callout', 'Footer Callout', 'Footer copy' );

		$this->register_pattern( 'theme/hero', $hero );
		$this->register_pattern( 'theme/footer-callout', $footer );
		$current_patterns = $this->current_patterns();
		$patterns_by_name = [];
		foreach ( $current_patterns as $pattern ) {
			$patterns_by_name[ (string) $pattern['name'] ] = $pattern;
		}

		$hero_uuid    = PatternIndex::pattern_uuid( 'theme/hero' );
		$footer_uuid  = PatternIndex::pattern_uuid( 'theme/footer-callout' );
		$removed_uuid = PatternIndex::pattern_uuid( 'theme/retired-pattern' );

		$this->save_ready_state_for_patterns(
			$current_patterns,
			[
				'fingerprint'          => 'outdated-fingerprint',
				'indexed_count'        => 3,
				'pattern_fingerprints' => [
					$hero_uuid    => 'outdated-hero-fingerprint',
					$footer_uuid  => $this->expected_pattern_fingerprint( $patterns_by_name['theme/footer-callout'] ?? [] ),
					$removed_uuid => 'removed-pattern-fingerprint',
				],
			]
		);

		$this->queue_signature_probe();
		$this->queue_ensure_collection();
		$this->queue_scroll(
			[
				$this->scroll_point( $hero_uuid, 'theme/hero' ),
				$this->scroll_point( $footer_uuid, 'theme/footer-callout' ),
				$this->scroll_point( $removed_uuid, 'theme/retired-pattern' ),
			]
		);
		$this->queue_embeddings(
			[
				[ 0.21, 0.22 ],
			]
		);
		$this->queue_qdrant_success( '/points', 'PUT' );
		$this->queue_qdrant_success( '/points/delete', 'POST' );

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertSame( 1, $result['indexed'] );
		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( 2, $state['indexed_count'] );
		$this->assertCount( 2, $state['pattern_fingerprints'] );

		$upsert_call = $this->find_remote_post_call( '/points', 'PUT' );
		$upsert_body = $this->decode_request_body( $upsert_call );
		$this->assertSame( [ $hero_uuid ], array_column( $upsert_body['points'] ?? [], 'id' ) );

		$delete_call = $this->find_remote_post_call( '/points/delete', 'POST' );
		$delete_body = $this->decode_request_body( $delete_call );
		$this->assertSame( [ $removed_uuid ], $delete_body['points'] ?? null );
	}

	public function test_sync_persists_error_state_when_collection_creation_fails(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );

		$this->queue_signature_probe();
		$this->queue_ensure_collection( false, false );

		$this->assert_sync_failure( 'qdrant_create_error', 'Collection create failed' );
	}

	public function test_sync_schedules_retry_when_signature_probe_is_rate_limited(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );

		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 429 ],
			'headers'  => [ 'retry-after' => '17' ],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Embedding probe rate limited',
					],
				]
			),
		];

		$this->assert_retryable_sync_failure( 'rate_limited', 'Embedding probe rate limited', 17 );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_sync_schedules_retry_when_qdrant_collection_creation_is_rate_limited(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );

		$this->queue_signature_probe();
		WordPressTestState::$remote_get_responses[]  = $this->qdrant_response(
			404,
			[
				'status' => [
					'error' => 'Collection not found',
				],
			]
		);
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 429 ],
			'headers'  => [ 'retry-after' => '11' ],
			'body'     => wp_json_encode(
				[
					'status' => [
						'error' => 'Collection create rate limited',
					],
				]
			),
		];

		$this->assert_retryable_sync_failure( 'qdrant_rate_limited', 'Collection create rate limited', 11 );
	}

	public function test_sync_persists_error_state_when_scroll_fails(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );

		$this->queue_signature_probe();
		$this->queue_ensure_collection();
		$this->queue_qdrant_error( '/points/scroll', 'POST', 'qdrant_scroll_error', 'Scroll failed' );

		$this->assert_sync_failure( 'qdrant_scroll_error', 'Scroll failed' );
	}

	public function test_sync_persists_error_state_when_embedding_fails(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );

		$this->queue_signature_probe();
		$this->queue_ensure_collection();
		$this->queue_scroll( [] );
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 500 ],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Embedding failed',
					],
				]
			),
		];

		$this->assert_sync_failure( 'embedding_error', 'Embedding failed' );
	}

	public function test_sync_persists_error_state_when_upsert_fails(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );

		$this->queue_signature_probe();
		$this->queue_ensure_collection();
		$this->queue_scroll( [] );
		$this->queue_embeddings(
			[
				[ 0.11, 0.22 ],
			]
		);
		$this->queue_qdrant_error( '/points', 'PUT', 'qdrant_upsert_error', 'Upsert failed' );

		$this->assert_sync_failure( 'qdrant_upsert_error', 'Upsert failed' );
	}

	public function test_sync_persists_error_state_when_delete_fails(): void {
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );
		$current_patterns = $this->current_patterns();
		$patterns_by_name = [];
		foreach ( $current_patterns as $pattern ) {
			$patterns_by_name[ (string) $pattern['name'] ] = $pattern;
		}

		$hero_uuid    = PatternIndex::pattern_uuid( 'theme/hero' );
		$removed_uuid = PatternIndex::pattern_uuid( 'theme/retired-pattern' );

		$this->save_ready_state_for_patterns(
			$current_patterns,
			[
				'fingerprint'          => 'outdated-fingerprint',
				'indexed_count'        => 2,
				'pattern_fingerprints' => [
					$hero_uuid    => $this->expected_pattern_fingerprint( $patterns_by_name['theme/hero'] ?? [] ),
					$removed_uuid => 'removed-pattern-fingerprint',
				],
			]
		);

		$this->queue_signature_probe();
		$this->queue_ensure_collection();
		$this->queue_scroll(
			[
				$this->scroll_point( $hero_uuid, 'theme/hero' ),
				$this->scroll_point( $removed_uuid, 'theme/retired-pattern' ),
			]
		);
		$this->queue_qdrant_error( '/points/delete', 'POST', 'qdrant_delete_error', 'Delete failed' );

		$this->assert_sync_failure( 'qdrant_delete_error', 'Delete failed' );
	}

	public function test_sync_returns_sync_locked_without_remote_work_when_lock_is_held(): void {
		WordPressTestState::$transients['flavor_agent_sync_lock'] = time();

		$result = PatternIndex::sync();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sync_locked', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	private function configure_backends(): void {
		WordPressTestState::$options = array_merge(
			WordPressTestState::$options,
			[
				Provider::OPTION_NAME                   => Provider::NATIVE,
				'flavor_agent_openai_native_api_key'    => 'native-key',
				'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
				'flavor_agent_openai_native_chat_model' => 'gpt-5.4',
				'flavor_agent_qdrant_url'               => 'https://example.cloud.qdrant.io:6333',
				'flavor_agent_qdrant_key'               => 'qdrant-key',
			]
		);
	}

	private function register_pattern( string $name, array $properties ): void {
		\WP_Block_Patterns_Registry::get_instance()->register( $name, $properties );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pattern_fixture( string $name, string $title, string $content ): array {
		return [
			'name'          => $name,
			'title'         => $title,
			'description'   => "{$title} description",
			'categories'    => [ 'marketing' ],
			'blockTypes'    => [ 'core/group' ],
			'templateTypes' => [ 'home' ],
			'content'       => "<!-- wp:paragraph --><p>{$content}</p><!-- /wp:paragraph -->",
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function current_patterns(): array {
		return \FlavorAgent\Context\ServerCollector::for_patterns();
	}

	/**
	 * @param array<int, array<string, mixed>> $patterns
	 */
	private function save_ready_state_for_patterns( array $patterns, array $overrides = [] ): void {
		$embedding_config     = Provider::embedding_configuration();
		$embedding_signature  = EmbeddingClient::build_signature_for_dimension( 2, $embedding_config );
		$pattern_fingerprints = [];

		foreach ( $patterns as $pattern ) {
			$pattern_fingerprints[ PatternIndex::pattern_uuid( $pattern['name'] ) ] =
				$this->expected_pattern_fingerprint( $pattern );
		}

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'               => 'ready',
					'fingerprint'          => PatternIndex::compute_fingerprint( $patterns ),
					'qdrant_url'           => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'    => QdrantClient::get_collection_name( $embedding_signature ),
					'openai_provider'      => $embedding_config['provider'],
					'openai_endpoint'      => $embedding_config['endpoint'],
					'embedding_model'      => $embedding_config['model'],
					'embedding_dimension'  => 2,
					'embedding_signature'  => $embedding_signature['signature_hash'],
					'last_synced_at'       => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'      => '2000-01-01T00:00:00+00:00',
					'indexed_count'        => count( $patterns ),
					'last_error'           => null,
					'stale_reason'         => '',
					'stale_reasons'        => [],
					'pattern_fingerprints' => $pattern_fingerprints,
				],
				$overrides
			)
		);
	}

	private function queue_ensure_collection( bool $collection_exists = true, bool $creation_succeeds = true ): void {
		WordPressTestState::$remote_get_responses[] = $this->qdrant_response(
			$collection_exists ? 200 : 404,
			$collection_exists
				? [
					'status' => 'ok',
					'result' => [
						'config' => [
							'params' => [
								'vectors' => [
									'size'     => 2,
									'distance' => 'Cosine',
								],
							],
						],
					],
				]
				: [
					'status' => [
						'error' => 'Collection not found',
					],
				]
		);

		if ( ! $collection_exists ) {
			WordPressTestState::$remote_post_responses[] = $creation_succeeds
				? $this->qdrant_response(
					200,
					[
						'status' => 'ok',
						'result' => [],
					]
				)
				: $this->qdrant_response( 500, [ 'status' => [ 'error' => 'Collection create failed' ] ] );
		}

		if ( $collection_exists || $creation_succeeds ) {
			for ( $i = 0; $i < 4; $i++ ) {
				$this->queue_qdrant_success( '/index', 'PUT' );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $points
	 */
	private function queue_scroll( array $points ): void {
		WordPressTestState::$remote_post_responses[] = $this->qdrant_response(
			200,
			[
				'status' => 'ok',
				'result' => [
					'points'           => $points,
					'next_page_offset' => null,
				],
			]
		);
	}

	/**
	 * @param array<int, array<int, float>> $vectors
	 */
	private function queue_embeddings( array $vectors ): void {
		WordPressTestState::$remote_post_responses[] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'data' => array_map(
						static fn( array $vector ): array => [
							'embedding' => $vector,
						],
						$vectors
					),
				]
			),
		];
	}

	private function queue_signature_probe(): void {
		$this->queue_embeddings(
			[
				[ 0.01, 0.02 ],
			]
		);
	}

	private function queue_qdrant_success( string $url_suffix, string $method ): void {
		WordPressTestState::$remote_post_responses[] = $this->qdrant_response(
			200,
			[
				'status' => 'ok',
				'result' => [],
			]
		);
	}

	private function queue_qdrant_error( string $url_suffix, string $method, string $error_code, string $message ): void {
		WordPressTestState::$remote_post_responses[] = $this->qdrant_response(
			500,
			[
				'status' => [
					'error' => $message,
				],
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function qdrant_response( int $status, array $body ): array {
		return [
			'response' => [ 'code' => $status ],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * @return array{id: string, payload: array{name: string}}
	 */
	private function scroll_point( string $id, string $name ): array {
		return [
			'id'      => $id,
			'payload' => [
				'name' => $name,
			],
		];
	}

	private function collection_url(): string {
		$embedding_signature = EmbeddingClient::build_signature_for_dimension( 2 );

		return rtrim( (string) get_option( 'flavor_agent_qdrant_url', '' ), '/' )
			. '/collections/'
			. QdrantClient::get_collection_name( $embedding_signature );
	}

	private function assert_sync_failure( string $expected_code, string $expected_message ): void {
		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertSame( $expected_message, $result->get_error_message() );
		$this->assertSame( 'error', $state['status'] );
		$this->assertSame( $expected_message, $state['last_error'] );
		$this->assertSame( $expected_code, $state['last_error_code'] );
		$this->assertFalse( (bool) $state['last_error_retryable'] );
		$this->assertNull( $state['last_error_retry_after'] );
		$this->assertSame( [], WordPressTestState::$scheduled_events );
	}

	private function assert_retryable_sync_failure( string $expected_code, string $expected_message, int $retry_after ): void {
		$before = time();
		$result = PatternIndex::sync();
		$after  = time();
		$state  = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertSame( $expected_message, $result->get_error_message() );
		$this->assertSame( 'error', $state['status'] );
		$this->assertSame( $expected_message, $state['last_error'] );
		$this->assertSame( $expected_code, $state['last_error_code'] );
		$this->assertSame( 429, $state['last_error_status'] );
		$this->assertTrue( PatternIndex::has_retryable_error( $state ) );
		$this->assertSame( $retry_after, $state['last_error_retry_after'] );
		$this->assertNotNull( $state['last_attempt_at'] );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );

		$scheduled = WordPressTestState::$scheduled_events[ PatternIndex::CRON_HOOK ]['timestamp'];
		$this->assertGreaterThanOrEqual( $before + $retry_after, $scheduled );
		$this->assertLessThanOrEqual( $after + $retry_after, $scheduled );
	}

	/**
	 * @param array<string, mixed> $call
	 * @return array<string, mixed>
	 */
	private function decode_request_body( array $call ): array {
		$decoded = json_decode( (string) ( $call['args']['body'] ?? '' ), true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function find_remote_post_call( string $url_suffix, string $method ): array {
		foreach ( WordPressTestState::$remote_post_calls as $call ) {
			if (
				( $call['args']['method'] ?? 'POST' ) === $method
				&& str_ends_with( (string) ( $call['url'] ?? '' ), $url_suffix )
			) {
				return $call;
			}
		}

		$this->fail( "Could not find remote {$method} call ending with {$url_suffix}." );
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private function expected_pattern_fingerprint( array $pattern ): string {
		$categories     = $pattern['categories'] ?? [];
		$block_types    = $pattern['blockTypes'] ?? [];
		$template_types = $pattern['templateTypes'] ?? [];

		sort( $categories );
		sort( $block_types );
		sort( $template_types );

		return md5(
			implode(
				'|',
				[
					$pattern['name'] ?? '',
					$pattern['title'] ?? '',
					$pattern['description'] ?? '',
					implode( ',', $categories ),
					implode( ',', $block_types ),
					implode( ',', $template_types ),
					$this->expected_pattern_override_fingerprint( $pattern['patternOverrides'] ?? [] ),
					(string) ( $pattern['syncedPatternId'] ?? 0 ),
					$pattern['syncStatus'] ?? '',
					$pattern['wpPatternSyncStatus'] ?? '',
					md5( $pattern['content'] ?? '' ),
					(string) PatternIndex::EMBEDDING_RECIPE_VERSION,
				]
			)
		);
	}

	/**
	 * @param array<string, mixed> $pattern_overrides
	 */
	private function expected_pattern_override_fingerprint( array $pattern_overrides ): string {
		$parts = [];

		$parts[] = ! empty( $pattern_overrides['hasOverrides'] ) ? '1' : '0';
		$parts[] = ! empty( $pattern_overrides['usesDefaultBinding'] ) ? '1' : '0';
		$parts[] = (string) (int) ( $pattern_overrides['blockCount'] ?? 0 );

		$block_names = is_array( $pattern_overrides['blockNames'] ?? null )
			? $pattern_overrides['blockNames']
			: [];
		sort( $block_names );
		$parts[] = implode( ',', $block_names );

		foreach ( [ 'bindableAttributes', 'overrideAttributes', 'unsupportedAttributes' ] as $map_key ) {
			$map = is_array( $pattern_overrides[ $map_key ] ?? null )
				? $pattern_overrides[ $map_key ]
				: [];
			ksort( $map );

			foreach ( $map as $block_name => $attributes ) {
				if ( ! is_string( $block_name ) || ! is_array( $attributes ) ) {
					continue;
				}

				sort( $attributes );
				$parts[] = $map_key . ':' . $block_name . ':' . implode( ',', $attributes );
			}
		}

		return implode( '|', $parts );
	}
}
