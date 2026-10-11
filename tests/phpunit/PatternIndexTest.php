<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternIndexTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->configure_cloudflare_ai_search_backends();
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
		$this->assertSame( 'Design metadata: sectionRole=unknown; layoutShape=unknown; visualDensity=sparse; colorMood=unknown; typographyRole=unknown; interactionRole=static-content; templateAreaAffinity=content; blockComplexity=low; contentSpecificity=generic', $lines[6] );
		$this->assertSame( 'Pattern overrides: yes', $lines[7] );
		$this->assertSame( 'Override-ready core/heading: content', $lines[8] );
		$this->assertSame( str_repeat( 'x', 500 ), $lines[9] );
	}

	public function test_pattern_design_metadata_identifies_hero_cover_shape_and_mood(): void {
		$metadata = \FlavorAgent\Patterns\PatternDesignMetadata::extract(
			[
				'name'       => 'theme/hero',
				'categories' => [ 'featured' ],
				'content'    => '<!-- wp:cover {"dimRatio":70,"backgroundColor":"contrast"} --><!-- wp:heading --><h2>Hero</h2><!-- /wp:heading --><!-- wp:buttons --><!-- /wp:buttons --><!-- /wp:cover -->',
			]
		);

		$this->assertSame( 'hero', $metadata['sectionRole'] );
		$this->assertSame( 'cover', $metadata['layoutShape'] );
		$this->assertSame( 'dark', $metadata['colorMood'] );
		$this->assertSame( 'marketing', $metadata['typographyRole'] );
		$this->assertSame( 'content', $metadata['templateAreaAffinity'] );
		$this->assertContains( 'contrast', $metadata['styleTokenUsage'] );
	}

	public function test_pattern_design_metadata_counts_only_column_opening_blocks_for_layout_shape(): void {
		$metadata = \FlavorAgent\Patterns\PatternDesignMetadata::extract(
			[
				'name'    => 'theme/two-columns',
				'content' => '<!-- wp:columns --><!-- wp:column --><!-- wp:paragraph --><p>Left</p><!-- /wp:paragraph --><!-- /wp:column --><!-- wp:column --><!-- wp:paragraph --><p>Right</p><!-- /wp:paragraph --><!-- /wp:column --><!-- /wp:columns -->',
			]
		);

		$this->assertSame( 'two-column', $metadata['layoutShape'] );
	}

	public function test_pattern_design_metadata_color_mood_tolerates_whitespace_in_block_attributes(): void {
		// Hand-authored patterns may pretty-print block attribute JSON with spaces;
		// the mood heuristic must still recognize the background color / overlay.
		$metadata = \FlavorAgent\Patterns\PatternDesignMetadata::extract(
			[
				'name'    => 'theme/spaced-hero',
				'content' => '<!-- wp:cover {"dimRatio": 80, "backgroundColor": "contrast"} --><!-- /wp:cover -->',
			]
		);

		$this->assertSame( 'dark', $metadata['colorMood'] );
	}

	public function test_embedding_text_includes_pattern_design_metadata(): void {
		$text = PatternIndex::build_embedding_text(
			[
				'name'       => 'theme/footer-links',
				'title'      => 'Footer Links',
				'categories' => [ 'footer' ],
				'content'    => '<!-- wp:group --><!-- wp:navigation /--><!-- wp:social-links /--><!-- /wp:group -->',
			]
		);

		$this->assertStringContainsString( 'Design metadata:', $text );
		$this->assertStringContainsString( 'templateAreaAffinity=footer', $text );
		$this->assertStringContainsString( 'interactionRole=navigation', $text );
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

	public function test_compute_fingerprint_handles_pattern_with_non_array_taxonomy_fields(): void {
		// WooCommerce registers patterns with `templateTypes` as a string slug
		// (or empty string), and occasionally non-array categories. The
		// fingerprint pipeline must treat those defensively rather than
		// fataling on PHP 8 strict-types coercion.
		$broken                  = $this->pattern_fixture( 'plugin/coming-soon', 'Coming Soon', 'Hello' );
		$broken['categories']    = '';
		$broken['blockTypes']    = null;
		$broken['templateTypes'] = 'coming-soon';

		$fingerprint = PatternIndex::compute_fingerprint( [ $broken ] );

		$this->assertIsString( $fingerprint );
		$this->assertNotSame( '', $fingerprint );

		$normalized                  = $broken;
		$normalized['categories']    = [];
		$normalized['blockTypes']    = [];
		$normalized['templateTypes'] = [ 'coming-soon' ];

		$this->assertSame(
			$fingerprint,
			PatternIndex::compute_fingerprint( [ $normalized ] ),
			'String/null taxonomy fields must produce the same fingerprint as the equivalent array form.'
		);
	}

	public function test_mark_dirty_uses_stale_when_a_usable_index_exists_and_uninitialized_otherwise(): void {
		$this->save_ready_cloudflare_ai_search_state_for_patterns( [] );

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
		$this->save_ready_cloudflare_ai_search_state_for_patterns( $this->current_patterns() );
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

		$this->save_ready_cloudflare_ai_search_state_for_patterns( $this->current_patterns() );
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

	public function test_runtime_state_marks_workers_ai_model_change_as_cloudflare_signature_drift(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->save_ready_cloudflare_ai_search_state_for_patterns( $this->current_patterns() );

		WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_embedding_model'] = '@cf/baai/bge-large-en-v1.5';

		$state = PatternIndex::get_runtime_state();

		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'cloudflare_ai_search_signature_changed', $state['stale_reasons'] );
		$this->assertTrue( PatternIndex::has_compatibility_drift( $state ) );
	}

	public function test_schedule_sync_requires_backends_and_respects_existing_events_cooldown_and_force(): void {
		WordPressTestState::$options = [];

		PatternIndex::schedule_sync();

		$this->assertSame( [], WordPressTestState::$scheduled_events );

		$this->configure_cloudflare_ai_search_backends();
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

	public function test_recommendation_backends_configured_accepts_cloudflare_ai_search_without_qdrant(): void {
		$this->configure_cloudflare_ai_search_backends();
		WordPressTestState::$ai_client_supported = true;

		$this->assertSame( '', get_option( 'flavor_agent_qdrant_url', '' ) );
		$this->assertSame( '', get_option( 'flavor_agent_qdrant_key', '' ) );
		$this->assertTrue( PatternIndex::recommendation_backends_configured() );
	}

	public function test_sync_no_ops_when_state_matches_current_patterns_and_backend_configuration(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->register_pattern( 'theme/footer-callout', $this->pattern_fixture( 'theme/footer-callout', 'Footer Callout', 'Footer copy' ) );

		$patterns = $this->current_patterns();

		$this->save_ready_cloudflare_ai_search_state_for_patterns( $patterns );

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
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sync_refreshes_stale_compatible_state_without_reuploading(): void {
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->register_pattern( 'theme/footer-callout', $this->pattern_fixture( 'theme/footer-callout', 'Footer Callout', 'Footer copy' ) );

		$patterns = $this->current_patterns();

		$this->save_ready_cloudflare_ai_search_state_for_patterns(
			$patterns,
			[
				'status'        => 'stale',
				'stale_reason'  => 'pattern_registry_changed',
				'stale_reasons' => [ 'pattern_registry_changed' ],
			]
		);
		$this->queue_cloudflare_completed_items_for_patterns( $patterns );

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertSame(
			[
				'indexed'     => 0,
				'removed'     => 0,
				'fingerprint' => PatternIndex::compute_fingerprint( $patterns ),
				'status'      => 'ready',
			],
			$result
		);
		$this->assertSame( 'ready', $state['status'] );
		$this->assertSame( [], $state['stale_reasons'] );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sync_returns_sync_locked_without_remote_work_when_lock_is_held(): void {
		WordPressTestState::$transients['flavor_agent_sync_lock'] = time();

		$result = PatternIndex::sync();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sync_locked', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_cloudflare_ai_search_sync_uploads_current_registered_patterns_and_records_state(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->register_pattern( 'theme/footer-callout', $this->pattern_fixture( 'theme/footer-callout', 'Footer Callout', 'Footer copy' ) );

		$patterns = $this->current_patterns();

		$this->queue_cloudflare_item_list( [] );
		$this->queue_cloudflare_success_responses( 2 );
		$this->queue_cloudflare_completed_items_for_patterns( $patterns );

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertSame( 2, $result['indexed'] );
		$this->assertSame( 0, $result['removed'] );
		$this->assertSame( 'ready', $result['status'] );
		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, $state['pattern_backend'] );
		$this->assertSame( 'default', $state['cloudflare_ai_search_namespace'] );
		$this->assertSame( PatternSearchInstanceManager::managed_instance_id(), $state['cloudflare_ai_search_instance'] );
		$this->assertNotSame( '', $state['cloudflare_ai_search_signature'] );
		$this->assertSame( PatternIndex::compute_fingerprint( $patterns ), $state['fingerprint'] );
		$this->assertSame( 2, $state['indexed_count'] );
		$this->assertNotEmpty( $state['last_synced_at'] );
		$this->assertCount( 2, $state['pattern_fingerprints'] );
		$this->assertCount( 2, WordPressTestState::$remote_get_calls );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );

		$request_bodies = $this->remote_post_request_bodies();
		$this->assertStringNotContainsString( '/embeddings', (string) $request_bodies );
		$this->assertStringNotContainsString( 'qdrant', (string) $request_bodies );

		$hero_uuid   = PatternIndex::pattern_uuid( 'theme/hero' );
		$footer_uuid = PatternIndex::pattern_uuid( 'theme/footer-callout' );
		$this->assertStringContainsString( 'filename="' . $hero_uuid . '.md"', (string) $request_bodies );
		$this->assertStringContainsString( 'filename="' . $footer_uuid . '.md"', (string) $request_bodies );
		$this->assertStringContainsString( "name=\"wait_for_completion\"\r\n\r\nfalse", (string) $request_bodies );
	}

	public function test_cloudflare_ai_search_sync_uploads_pattern_design_metadata(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->register_pattern(
			'theme/hero',
			[
				'title'      => 'Hero',
				'categories' => [ 'featured' ],
				'content'    => '<!-- wp:cover {"dimRatio":70,"backgroundColor":"contrast"} --><!-- wp:heading --><h2>Hero</h2><!-- /wp:heading --><!-- wp:buttons --><!-- /wp:buttons --><!-- /wp:cover -->',
			]
		);

		$this->queue_cloudflare_item_list( [] );
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_completed_items_for_patterns( $this->current_patterns() );

		$result = PatternIndex::sync();

		$this->assertSame( 1, $result['indexed'] );

		$request_bodies = $this->remote_post_request_bodies();

		$this->assertStringContainsString( 'Design metadata:', (string) $request_bodies );
		$this->assertStringContainsString( 'sectionRole=hero', (string) $request_bodies );
		$this->assertStringContainsString( 'layoutShape=cover', (string) $request_bodies );
		$this->assertStringContainsString( 'styleTokenUsage=contrast', (string) $request_bodies );
	}

	public function test_cloudflare_ai_search_sync_uploads_public_safe_synced_patterns_only(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		WordPressTestState::$posts     = [
			61 => $this->synced_post( 61, 'Public Synced Pattern', 'Public synced copy', 'publish' ),
			62 => $this->synced_post( 62, 'Public Partial Pattern', 'Public partial copy', 'publish' ),
			63 => $this->synced_post( 63, 'Public Unsynced Pattern', 'Public unsynced copy', 'publish' ),
			64 => $this->synced_post( 64, 'Private Synced Pattern', 'Private synced copy', 'private' ),
			65 => $this->synced_post( 65, 'Draft Synced Pattern', 'Draft synced copy', 'draft' ),
			66 => $this->synced_post( 66, 'Trashed Synced Pattern', 'Trashed synced copy', 'trash' ),
		];
		WordPressTestState::$post_meta = [
			62 => [
				'wp_pattern_sync_status' => 'partial',
			],
			63 => [
				'wp_pattern_sync_status' => 'unsynced',
			],
		];

		$this->queue_cloudflare_item_list( [] );
		$this->queue_cloudflare_success_responses( 4 );
		$this->queue_cloudflare_completed_items_for_patterns( $this->current_patterns() );

		$result = PatternIndex::sync();

		$this->assertSame( 4, $result['indexed'] );
		$this->assertCount( 4, WordPressTestState::$remote_post_calls );

		$request_bodies = $this->remote_post_request_bodies();

		$this->assertStringContainsString( 'Public Synced Pattern', (string) $request_bodies );
		$this->assertStringContainsString( 'Public Partial Pattern', (string) $request_bodies );
		$this->assertStringContainsString( 'Public Unsynced Pattern', (string) $request_bodies );
		$this->assertStringContainsString( '"pattern_name":"core/block/61"', (string) $request_bodies );
		$this->assertStringContainsString( '"pattern_name":"core/block/62"', (string) $request_bodies );
		$this->assertStringContainsString( '"pattern_name":"core/block/63"', (string) $request_bodies );
		$this->assertStringNotContainsString( 'Private Synced Pattern', (string) $request_bodies );
		$this->assertStringNotContainsString( 'Private synced copy', (string) $request_bodies );
		$this->assertStringNotContainsString( 'Draft Synced Pattern', (string) $request_bodies );
		$this->assertStringNotContainsString( 'Draft synced copy', (string) $request_bodies );
		$this->assertStringNotContainsString( 'Trashed Synced Pattern', (string) $request_bodies );
		$this->assertStringNotContainsString( 'Trashed synced copy', (string) $request_bodies );
	}

	public function test_cloudflare_ai_search_sync_deletes_stale_remote_items(): void {
		$this->configure_cloudflare_ai_search_backends();
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );

		$current_patterns = $this->current_patterns();
		$hero_uuid        = PatternIndex::pattern_uuid( 'theme/hero' );
		$removed_uuid     = PatternIndex::pattern_uuid( 'theme/retired-pattern' );

		$this->save_ready_cloudflare_ai_search_state_for_patterns(
			$current_patterns,
			[
				'fingerprint'          => 'outdated-fingerprint',
				'indexed_count'        => 2,
				'pattern_fingerprints' => [
					$hero_uuid    => $this->expected_pattern_fingerprint( $hero ),
					$removed_uuid => 'removed-pattern-fingerprint',
				],
			]
		);

		$this->queue_cloudflare_item_records(
			[
				[
					'id'     => 'cloudflare-hero-item',
					'key'    => $hero_uuid . '.md',
					'status' => 'completed',
				],
				[
					'id'     => 'cloudflare-removed-item',
					'key'    => $removed_uuid . '.md',
					'status' => 'completed',
				],
			]
		);
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_item_records(
			[
				[
					'id'     => 'cloudflare-hero-item',
					'key'    => $hero_uuid . '.md',
					'status' => 'completed',
				],
			]
		);

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertSame( 0, $result['indexed'] );
		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( 1, $state['indexed_count'] );
		$this->assertArrayNotHasKey( $removed_uuid, $state['pattern_fingerprints'] );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );

		$delete_call = $this->find_remote_post_call(
			sprintf(
				'/ai-search/namespaces/default/instances/%s/items/%s',
				PatternSearchInstanceManager::managed_instance_id(),
				rawurlencode( 'cloudflare-removed-item' )
			),
			'DELETE'
		);
		$this->assertSame( 'DELETE', $delete_call['args']['method'] ?? null );
	}

	public function test_cloudflare_ai_search_sync_preserves_owner_marker_and_unknown_remote_items(): void {
		$this->configure_cloudflare_ai_search_backends();
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );

		$current_patterns = $this->current_patterns();
		$hero_uuid        = PatternIndex::pattern_uuid( 'theme/hero' );
		$removed_uuid     = PatternIndex::pattern_uuid( 'theme/retired-pattern' );

		$this->save_ready_cloudflare_ai_search_state_for_patterns(
			$current_patterns,
			[
				'fingerprint'          => 'outdated-fingerprint',
				'indexed_count'        => 2,
				'pattern_fingerprints' => [
					$hero_uuid    => $this->expected_pattern_fingerprint( $hero ),
					$removed_uuid => 'removed-pattern-fingerprint',
				],
			]
		);

		$this->queue_cloudflare_item_records(
			[
				[
					'id'     => 'cloudflare-hero-item',
					'key'    => $hero_uuid . '.md',
					'status' => 'completed',
				],
				[
					'id'     => 'cloudflare-owner-marker-123',
					'key'    => PatternSearchInstanceManager::OWNER_MARKER_NAME . '.md',
					'status' => 'completed',
				],
				[
					'id'     => 'cloudflare-removed-item',
					'key'    => $removed_uuid . '.md',
					'status' => 'completed',
				],
				[
					'id'     => 'manual-operator-note',
					'key'    => 'manual-operator-note.md',
					'status' => 'completed',
				],
			]
		);
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_item_records(
			[
				[
					'id'     => 'cloudflare-hero-item',
					'key'    => $hero_uuid . '.md',
					'status' => 'completed',
				],
				[
					'id'     => 'cloudflare-owner-marker-123',
					'key'    => PatternSearchInstanceManager::OWNER_MARKER_NAME . '.md',
					'status' => 'completed',
				],
				[
					'id'     => 'manual-operator-note',
					'key'    => 'manual-operator-note.md',
					'status' => 'completed',
				],
			]
		);

		$result = PatternIndex::sync();

		$this->assertSame( 0, $result['indexed'] );
		$this->assertSame( 1, $result['removed'] );
		$this->assertNull(
			$this->find_optional_remote_post_call(
				sprintf(
					'/ai-search/namespaces/default/instances/%s/items/%s',
					PatternSearchInstanceManager::managed_instance_id(),
					rawurlencode( PatternSearchInstanceManager::OWNER_MARKER_NAME )
				),
				'DELETE'
			)
		);
		$this->assertNull(
			$this->find_optional_remote_post_call(
				sprintf(
					'/ai-search/namespaces/default/instances/%s/items/%s',
					PatternSearchInstanceManager::managed_instance_id(),
					rawurlencode( 'cloudflare-owner-marker-123' )
				),
				'DELETE'
			)
		);
		$this->assertNull(
			$this->find_optional_remote_post_call(
				sprintf(
					'/ai-search/namespaces/default/instances/%s/items/%s',
					PatternSearchInstanceManager::managed_instance_id(),
					rawurlencode( 'manual-operator-note' )
				),
				'DELETE'
			)
		);
		$this->assertIsArray(
			$this->find_remote_post_call(
				sprintf(
					'/ai-search/namespaces/default/instances/%s/items/%s',
					PatternSearchInstanceManager::managed_instance_id(),
					rawurlencode( 'cloudflare-removed-item' )
				),
				'DELETE'
			)
		);
	}

	public function test_cloudflare_ai_search_sync_keeps_indexing_while_uploaded_items_are_pending(): void {
		$this->configure_cloudflare_ai_search_backends();
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );

		$hero_uuid = PatternIndex::pattern_uuid( 'theme/hero' );

		$this->queue_cloudflare_item_list( [] );
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_item_records(
			[
				[
					'id'     => 'cloudflare-hero-item',
					'key'    => $hero_uuid . '.md',
					'status' => 'queued',
				],
			]
		);

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertSame( 'indexing', $result['status'] );
		$this->assertSame( 1, $result['pending'] );
		$this->assertSame( 'indexing', $state['status'] );
		$this->assertNull( $state['last_synced_at'] );
		$this->assertNotFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );
	}

	public function test_cloudflare_ai_search_sync_does_not_reupload_when_items_remain_pending_across_cron_retries(): void {
		$this->configure_cloudflare_ai_search_backends();
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );
		$this->register_pattern( 'theme/hero', $hero );

		$hero_uuid    = PatternIndex::pattern_uuid( 'theme/hero' );
		$pending_item = [
			'id'     => 'cloudflare-hero-item',
			'key'    => $hero_uuid . '.md',
			'status' => 'pending',
		];

		// Cycle 1: empty list -> upload -> re-list (pending).
		$this->queue_cloudflare_item_list( [] );
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_item_records( [ $pending_item ] );

		// Cycle 2 stubs cover both behaviors so the bug surfaces as the upload
		// assertion rather than as a missing-stub error: with the fix, only the
		// first GET is consumed; without it, the loop also re-uploads and re-lists.
		$this->queue_cloudflare_item_records( [ $pending_item ] );
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_item_records( [ $pending_item ] );

		$first_result = PatternIndex::sync();
		$this->assertSame( 'indexing', $first_result['status'] );
		$this->assertSame( 1, $first_result['pending'] );

		$first_state = PatternIndex::get_state();
		$this->assertSame( 1, count( $first_state['pattern_fingerprints'] ) );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );

		$second_result = PatternIndex::sync();
		$this->assertSame( 'indexing', $second_result['status'] );

		$this->assertCount(
			1,
			WordPressTestState::$remote_post_calls,
			'Pending items must not trigger re-upload on subsequent sync cycles.'
		);

		$second_state = PatternIndex::get_state();
		$this->assertSame( 'indexing', $second_state['status'] );
		$this->assertNull( $second_state['last_synced_at'] );
		$this->assertSame( 1, count( $second_state['pattern_fingerprints'] ) );
		$this->assertSame( 1, $second_state['indexed_count'] );
	}

	public function test_cloudflare_ai_search_sync_retries_workers_ai_capacity_failures(): void {
		$this->configure_cloudflare_ai_search_backends();
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );

		$hero_uuid  = PatternIndex::pattern_uuid( 'theme/hero' );
		$error_item = [
			'id'     => 'cloudflare-hero-error',
			'key'    => $hero_uuid . '.md',
			'status' => 'error',
			'error'  => 'workers_ai_out_of_capacity_error',
		];

		$this->queue_cloudflare_item_records( [ $error_item ] );
		$this->queue_cloudflare_success_responses( 2 );
		$this->queue_cloudflare_item_records( [ $error_item ] );

		$result = PatternIndex::sync();
		$state  = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cloudflare_pattern_ai_search_item_processing_error', $result->get_error_code() );
		$this->assertSame( 'error', $state['status'] );
		$this->assertTrue( PatternIndex::has_retryable_error( $state ) );
		$this->assertSame( 60, $state['last_error_retry_after'] );
		$this->assertNull( $state['last_synced_at'] );
		$this->assertNotFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );
		$this->assertIsArray(
			$this->find_remote_post_call(
				sprintf(
					'/ai-search/namespaces/default/instances/%s/items/%s',
					PatternSearchInstanceManager::managed_instance_id(),
					rawurlencode( 'cloudflare-hero-error' )
				),
				'DELETE'
			)
		);
	}

	public function test_cloudflare_ai_search_processing_failure_clears_previous_ready_timestamp(): void {
		$this->configure_cloudflare_ai_search_backends();
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );

		$current_patterns = $this->current_patterns();
		$this->save_ready_cloudflare_ai_search_state_for_patterns( $current_patterns );
		PatternIndex::mark_dirty( 'manual_cloudflare_ai_search_status_verification' );

		$hero_uuid  = PatternIndex::pattern_uuid( 'theme/hero' );
		$error_item = [
			'id'     => 'cloudflare-hero-error',
			'key'    => $hero_uuid . '.md',
			'status' => 'error',
			'error'  => 'workers_ai_out_of_capacity_error',
		];

		$this->queue_cloudflare_item_records( [ $error_item ] );
		$this->queue_cloudflare_success_responses( 2 );
		$this->queue_cloudflare_item_records( [ $error_item ] );

		$result    = PatternIndex::sync();
		$readiness = PatternIndex::recommendation_index_readiness();
		$state     = PatternIndex::get_state();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'error', $state['status'] );
		$this->assertNull( $state['last_synced_at'] );
		$this->assertFalse( $readiness['ready'] );
		$this->assertSame( 'index_warming', $readiness['reason'] );
	}

	public function test_cloudflare_ai_search_sync_reindexes_when_embedding_model_changes(): void {
		$this->configure_cloudflare_ai_search_backends();
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );

		$this->register_pattern( 'theme/hero', $hero );

		$current_patterns = $this->current_patterns();
		$this->save_ready_cloudflare_ai_search_state_for_patterns(
			$current_patterns,
			[
				'pattern_fingerprints' => [
					PatternIndex::pattern_uuid( 'theme/hero' ) => $this->expected_pattern_fingerprint( $hero ),
				],
			]
		);

		update_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '@cf/baai/bge-m3', false );
		update_option(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			PatternSearchInstanceManager::credential_signature(
				'account-123',
				'token-xyz',
				'@cf/baai/bge-m3'
			),
			false
		);

		$this->queue_cloudflare_item_list( [ PatternIndex::pattern_uuid( 'theme/hero' ) ] );
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_completed_items_for_patterns( $current_patterns );

		$result = PatternIndex::sync();

		$this->assertSame( 1, $result['indexed'] );
		$this->assertIsArray(
			$this->find_remote_post_call(
				sprintf(
					'/ai-search/namespaces/default/instances/%s/items',
					PatternSearchInstanceManager::managed_instance_id()
				),
				'POST'
			)
		);
	}

	public function test_cloudflare_ai_search_namespace_drift_marks_ready_index_stale(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->save_ready_cloudflare_ai_search_state_for_patterns(
			$this->current_patterns(),
			[
				'cloudflare_ai_search_namespace' => '',
			]
		);

		$state = PatternIndex::get_runtime_state();

		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'cloudflare_ai_search_namespace_changed', $state['stale_reasons'] );
		$this->assertTrue( PatternIndex::has_compatibility_drift( $state ) );
	}

	/** @dataProvider compatibility_sync_jobs */
	public function test_stale_cloudflare_compatibility_requires_sync_unless_a_job_is_queued_or_running( bool $queued, bool $running, string $expected_reason ): void {
		$this->save_ready_cloudflare_ai_search_state_for_patterns( [], [ 'cloudflare_ai_search_signature' => 'old-signature' ] );
		if ( $queued ) {
			wp_schedule_single_event( time() + 5, PatternIndex::CRON_HOOK );
		}
		if ( $running ) {
			set_transient( 'flavor_agent_sync_lock', time(), 300 );
		}

		$readiness = PatternIndex::recommendation_index_readiness();
		$this->assertFalse( $readiness['ready'] );
		$this->assertSame( $expected_reason, $readiness['reason'] );
		$this->assertSame( 'stale', $readiness['state']['status'] );
		$this->assertContains( 'cloudflare_ai_search_signature_changed', $readiness['state']['stale_reasons'] );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public static function compatibility_sync_jobs(): array {
		return [
			'no job'  => [ false, false, 'needs_sync' ],
			'queued'  => [ true, false, 'index_warming' ],
			'running' => [ false, true, 'index_warming' ],
		];
	}

	public function test_cloudflare_ai_search_workers_ai_token_change_marks_signature_drift(): void {
		$this->configure_cloudflare_ai_search_backends();
		$this->register_pattern( 'theme/hero', $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' ) );
		$this->save_ready_cloudflare_ai_search_state_for_patterns( $this->current_patterns() );
		WordPressTestState::$scheduled_events = [];

		WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_api_token'] = 'rotated-token';
		PatternIndex::handle_dependency_change(
			'token-xyz',
			'rotated-token',
			'flavor_agent_cloudflare_workers_ai_api_token'
		);

		$state = PatternIndex::get_state();

		$this->assertSame( 'stale', $state['status'] );
		$this->assertContains( 'cloudflare_ai_search_signature_changed', $state['stale_reasons'] );
	}

	/** @dataProvider cloudflare_sync_failure_phases */
	public function test_cloudflare_sync_records_list_upload_and_delete_failures( string $phase ): void {
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );
		$this->register_pattern( 'theme/hero', $hero );
		$error_response = [
			'response' => [ 'code' => 500 ],
			'body'     => wp_json_encode( [ 'errors' => [ [ 'message' => 'Sync failed' ] ] ] ),
		];

		if ( 'list' === $phase ) {
			WordPressTestState::$remote_get_responses[] = $error_response;
		} elseif ( 'upload' === $phase ) {
			$this->queue_cloudflare_item_list( [] );
			WordPressTestState::$remote_post_responses[] = $error_response;
		} else {
			$old_uuid = PatternIndex::pattern_uuid( 'theme/retired' );
			$this->save_ready_cloudflare_ai_search_state_for_patterns( $this->current_patterns() );
			$state                                      = PatternIndex::get_state();
			$state['fingerprint']                       = 'old-fingerprint';
			$state['pattern_fingerprints'][ $old_uuid ] = 'old-pattern';
			PatternIndex::save_state( $state );
			$this->queue_cloudflare_item_records(
				[
					[
						'id'     => 'hero-item',
						'key'    => PatternIndex::pattern_uuid( 'theme/hero' ) . '.md',
						'status' => 'completed',
					],
					[
						'id'     => 'old-item',
						'key'    => $old_uuid . '.md',
						'status' => 'completed',
					],
				]
			);
			WordPressTestState::$remote_post_responses[] = $error_response;
		}

		$this->assert_sync_failure( 'cloudflare_pattern_ai_search_' . $phase . '_error', 'Sync failed' );
		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, PatternIndex::get_state()['pattern_backend'] );
	}

	public static function cloudflare_sync_failure_phases(): array {
		return [ [ 'list' ], [ 'upload' ], [ 'delete' ] ];
	}

	public function test_cloudflare_sync_removes_a_previously_indexed_pattern_that_is_now_private(): void {
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );
		$this->register_pattern( 'theme/hero', $hero );
		WordPressTestState::$posts = [ $this->synced_post( 52, 'Private pattern', 'Private copy', 'private' ) ];
		$private_uuid              = PatternIndex::pattern_uuid( 'core/block/52' );
		$this->save_ready_cloudflare_ai_search_state_for_patterns( $this->current_patterns() );
		$state                = PatternIndex::get_state();
		$state['fingerprint'] = 'old-fingerprint';
		$state['pattern_fingerprints'][ $private_uuid ] = 'previously-public-pattern';
		PatternIndex::save_state( $state );
		$this->queue_cloudflare_item_records(
			[
				[
					'id'     => 'hero-item',
					'key'    => PatternIndex::pattern_uuid( 'theme/hero' ) . '.md',
					'status' => 'completed',
				],
				[
					'id'     => 'private-item',
					'key'    => $private_uuid . '.md',
					'status' => 'completed',
				],
			]
		);
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_completed_items_for_patterns( $this->current_patterns() );

		$result = PatternIndex::sync();
		$this->assertSame( 0, $result['indexed'] );
		$this->assertSame( 1, $result['removed'] );
		$this->assertArrayNotHasKey( $private_uuid, PatternIndex::get_state()['pattern_fingerprints'] );
		$this->assertSame( 'DELETE', WordPressTestState::$remote_post_calls[0]['args']['method'] );
		$this->assertStringContainsString( '/items/private-item', WordPressTestState::$remote_post_calls[0]['url'] );
		$this->assertStringNotContainsString( 'Private copy', $this->remote_post_request_bodies() );
	}

	/** @dataProvider legacy_index_states */
	public function test_legacy_index_states_require_explicit_resync_without_reusing_counts_or_readiness( string $status, ?string $backend, ?string $synced_at ): void {
		$legacy = [
			'status'               => $status,
			'qdrant_url'           => 'https://old-store.invalid',
			'qdrant_collection'    => 'old-patterns',
			'embedding_model'      => 'old-model',
			'embedding_dimension'  => 2,
			'embedding_signature'  => 'old-signature',
			'last_synced_at'       => $synced_at,
			'indexed_count'        => 42,
			'fingerprint'          => 'old-fingerprint',
			'pattern_fingerprints' => [ 'old-pattern' => 'old-fingerprint' ],
		];
		if ( null !== $backend ) {
			$legacy['pattern_backend'] = $backend;
		}
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ] = $legacy;

		foreach ( [ PatternIndex::get_runtime_state(), PatternIndex::recommendation_index_readiness( $legacy )['state'] ] as $state ) {
			$this->assertSame( 'stale', $state['status'] );
			$this->assertSame( 'pattern_store_migration_required', $state['stale_reason'] );
			$this->assertNull( $state['last_synced_at'] );
			$this->assertSame( 0, $state['indexed_count'] );
			$this->assertSame( [], $state['pattern_fingerprints'] );
			$this->assertFalse( PatternIndex::has_usable_index( $state ) );
			$this->assertTrue( PatternIndex::has_compatibility_drift( $state ) );
			$this->assertArrayNotHasKey( 'qdrant_collection', $state );
			$this->assertArrayNotHasKey( 'qdrant_url', $state );
			$this->assertArrayNotHasKey( 'embedding_dimension', $state );
		}
		$readiness = PatternIndex::recommendation_index_readiness();
		$this->assertFalse( $readiness['ready'] );
		$this->assertSame( 'needs_sync', $readiness['reason'] );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );

		PatternIndex::save_state( PatternIndex::get_state() );
		$this->assertFalse( PatternIndex::recommendation_index_readiness()['ready'] );
		$this->assertArrayNotHasKey( 'qdrant_collection', WordPressTestState::$options[ PatternIndex::STATE_OPTION ] );
	}

	public static function legacy_index_states(): array {
		$states = [];
		foreach ( [ 'ready', 'indexing', 'error' ] as $status ) {
			foreach ( [ 'qdrant', null, 'unknown_backend', Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH ] as $backend ) {
				foreach ( [ null, '2026-03-24T00:00:00+00:00' ] as $synced_at ) {
					$states[] = [ $status, $backend, $synced_at ];
				}
			}
		}
		return $states;
	}

	public function test_legacy_index_cannot_start_cloudflare_work_from_activation_changes_or_old_cron(): void {
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = 'qdrant';
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]               = [
			'status'         => 'error',
			'last_synced_at' => '2026-03-24T00:00:00+00:00',
			'indexed_count'  => 42,
		];

		PatternIndex::activate();
		PatternIndex::handle_registry_change();
		$this->assertFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );

		wp_schedule_single_event( time() - 1, PatternIndex::CRON_HOOK );
		$result = PatternIndex::run_due_sync();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_store_migration_required', $result->get_error_code() );
		$this->assertFalse( wp_next_scheduled( PatternIndex::CRON_HOOK ) );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertSame( 'needs_sync', PatternIndex::recommendation_index_readiness()['reason'] );
	}

	public function test_explicit_legacy_resync_queues_without_remote_work_and_becomes_ready_only_after_cloudflare_processing(): void {
		$hero = $this->pattern_fixture( 'theme/hero', 'Hero', 'Hero copy' );
		$this->register_pattern( 'theme/hero', $hero );
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = 'qdrant';
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]               = [
			'pattern_backend'      => 'qdrant',
			'status'               => 'ready',
			'last_synced_at'       => '2026-03-24T00:00:00+00:00',
			'indexed_count'        => 42,
			'pattern_fingerprints' => [ 'old-pattern' => 'old-fingerprint' ],
		];
		$queued = PatternIndex::enqueue_sync();
		$this->assertTrue( $queued['queued'] );
		$this->assertSame( 'indexing', PatternIndex::get_runtime_state()['status'] );
		$this->assertFalse( PatternIndex::recommendation_index_readiness()['ready'] );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );

		$this->queue_cloudflare_item_list( [] );
		$this->queue_cloudflare_success_responses( 1 );
		$this->queue_cloudflare_completed_items_for_patterns( $this->current_patterns() );
		$result = PatternIndex::sync( true );
		$this->assertSame( 'ready', $result['status'] );
		$this->assertSame( 1, PatternIndex::get_state()['indexed_count'] );
		$this->assertTrue( PatternIndex::recommendation_index_readiness()['ready'] );
		$this->assertSame( 'qdrant', get_option( Config::OPTION_PATTERN_RETRIEVAL_BACKEND ) );
		foreach ( WordPressTestState::$remote_post_calls as $call ) {
			$this->assertSame( 'POST', $call['args']['method'] ?? 'POST' );
			$this->assertStringContainsString( '/ai-search/', $call['url'] );
		}
	}

	/** @dataProvider empty_legacy_index_states */
	public function test_legacy_selection_with_no_index_cannot_run_old_cron_until_explicit_enqueue( bool $stored_empty_index ): void {
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = 'qdrant';
		if ( $stored_empty_index ) {
			WordPressTestState::$options[ PatternIndex::STATE_OPTION ] = [];
		}
		wp_schedule_single_event( time() - 1, PatternIndex::CRON_HOOK );

		$result = PatternIndex::run_due_sync();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_store_migration_required', $result->get_error_code() );
		$this->assertSame( 'needs_sync', PatternIndex::recommendation_index_readiness()['reason'] );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );

		$queued = PatternIndex::enqueue_sync();
		$this->assertTrue( $queued['queued'] );
		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, PatternIndex::get_state()['pattern_backend'] );
		$this->queue_cloudflare_item_list( [] );
		$result = PatternIndex::sync( true );
		$this->assertSame( 'ready', $result['status'] );
		$this->assertTrue( PatternIndex::recommendation_index_readiness()['ready'] );
		$this->assertSame( 'qdrant', get_option( Config::OPTION_PATTERN_RETRIEVAL_BACKEND ) );
	}

	public static function empty_legacy_index_states(): array {
		return [
			'absent' => [ false ],
			'empty'  => [ true ],
		];
	}

	public function test_absent_selector_and_absent_index_keep_fresh_cloudflare_first_use_state(): void {
		unset( WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] );
		$state = PatternIndex::get_state();
		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, $state['pattern_backend'] );
		$this->assertSame( 'uninitialized', $state['status'] );
		$this->assertSame( 'needs_sync', PatternIndex::recommendation_index_readiness()['reason'] );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_cloudflare_ready_without_confirmed_sync_is_not_reported_ready(): void {
		$this->save_ready_cloudflare_ai_search_state_for_patterns(
			[],
			[
				'last_synced_at' => null,
				'indexed_count'  => 42,
			]
		);
		$state = PatternIndex::get_runtime_state();
		$this->assertSame( 'uninitialized', $state['status'] );
		$this->assertSame( 0, $state['indexed_count'] );
		$this->assertSame( 'needs_sync', PatternIndex::recommendation_index_readiness()['reason'] );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_corrupt_selector_blocks_cloudflare_readiness_and_enqueue_without_network(): void {
		$this->save_ready_cloudflare_ai_search_state_for_patterns( [] );
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = '<>';
		$this->assertFalse( PatternIndex::recommendation_backends_configured() );
		$this->assertSame( 'pattern_backend_unconfigured', PatternIndex::recommendation_index_readiness()['reason'] );
		$this->assertInstanceOf( \WP_Error::class, PatternIndex::enqueue_sync() );
		$this->assertSame( [], WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	private function configure_cloudflare_ai_search_backends(): void {
			WordPressTestState::$options = array_merge(
				WordPressTestState::$options,
				[
					'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
					'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
					'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
					Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
					Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
					Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => PatternSearchInstanceManager::credential_signature(
						'account-123',
						'token-xyz',
						'@cf/qwen/qwen3-embedding-0.6b'
					),
				]
			);
			wp_cache_delete( 'notoptions', 'options' );
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

	private function synced_post( int $id, string $title, string $copy, string $status ): object {
		return (object) [
			'ID'                => $id,
			'post_type'         => 'wp_block',
			'post_title'        => $title,
			'post_name'         => 'synced-pattern-' . $id,
			'post_content'      => '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->',
			'post_status'       => $status,
			'post_author'       => 7,
			'post_date_gmt'     => '2026-04-20 00:00:00',
			'post_modified_gmt' => '2026-04-21 00:00:00',
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
	private function save_ready_cloudflare_ai_search_state_for_patterns( array $patterns, array $overrides = [] ): void {
		$pattern_fingerprints = [];

		foreach ( $patterns as $pattern ) {
			$pattern_fingerprints[ PatternIndex::pattern_uuid( $pattern['name'] ) ] =
				$this->expected_pattern_fingerprint( $pattern );
		}

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'                         => 'ready',
					'pattern_backend'                => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
					'fingerprint'                    => PatternIndex::compute_fingerprint( $patterns ),
					'cloudflare_ai_search_namespace' => 'default',
					'cloudflare_ai_search_instance'  => (string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID, '' ),
					'cloudflare_ai_search_signature' => $this->expected_cloudflare_ai_search_signature(),
					'last_synced_at'                 => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'                => '2000-01-01T00:00:00+00:00',
					'indexed_count'                  => count( $patterns ),
					'last_error'                     => null,
					'stale_reason'                   => '',
					'stale_reasons'                  => [],
					'pattern_fingerprints'           => $pattern_fingerprints,
				],
				$overrides
			)
		);
	}

	/**
	 * @param string[] $item_ids
	 */
	private function queue_cloudflare_item_list( array $item_ids ): void {
		WordPressTestState::$remote_get_responses[] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'result'      => array_map(
						static fn( string $item_id ): array => [ 'id' => $item_id ],
						$item_ids
					),
					'result_info' => [
						'count'       => count( $item_ids ),
						'page'        => 1,
						'total_count' => count( $item_ids ),
						'per_page'    => 50,
					],
				]
			),
		];
	}

	private function queue_cloudflare_success_responses( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			WordPressTestState::$remote_post_responses[] = [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'success' => true ] ),
			];
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	private function queue_cloudflare_item_records( array $items ): void {
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

	/**
	 * @param array<int, array<string, mixed>> $patterns
	 */
	private function queue_cloudflare_completed_items_for_patterns( array $patterns ): void {
		$this->queue_cloudflare_item_records(
			array_map(
				static function ( array $pattern ): array {
					$uuid = PatternIndex::pattern_uuid( (string) ( $pattern['name'] ?? '' ) );

					return [
						'id'     => 'cloudflare-' . $uuid,
						'key'    => $uuid . '.md',
						'status' => 'completed',
					];
				},
				$patterns
			)
		);
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

	/**
	 * @param array<string, mixed> $call
	 * @return array<string, mixed>
	 */
	private function decode_request_body( array $call ): array {
		$decoded = json_decode( (string) ( $call['args']['body'] ?? '' ), true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	private function remote_post_request_bodies(): string {
		return implode(
			"\n",
			array_map(
				static fn( array $call ): string => (string) ( $call['args']['body'] ?? '' ),
				WordPressTestState::$remote_post_calls
			)
		);
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

	private function find_optional_remote_post_call( string $url_suffix, string $method ): ?array {
		foreach ( WordPressTestState::$remote_post_calls as $call ) {
			if (
				str_ends_with( (string) ( $call['url'] ?? '' ), $url_suffix )
				&& strtoupper( (string) ( $call['args']['method'] ?? 'POST' ) ) === strtoupper( $method )
			) {
				return $call;
			}
		}

		return null;
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

	private function expected_cloudflare_ai_search_signature(): string {
		return PatternSearchInstanceManager::credential_signature(
			(string) get_option( 'flavor_agent_cloudflare_workers_ai_account_id', '' ),
			(string) get_option( 'flavor_agent_cloudflare_workers_ai_api_token', '' ),
			(string) get_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '' )
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
