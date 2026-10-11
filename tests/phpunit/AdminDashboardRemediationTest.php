<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Admin\Settings\Help;
use FlavorAgent\Admin\Settings\Page;
use FlavorAgent\Admin\Settings\State;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Guidelines;
use FlavorAgent\Guidelines\GuidelinesRepository;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Settings;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AdminDashboardRemediationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		$_POST              = [];
		$_GET               = [];
		$GLOBALS['submenu'] = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		unset( $GLOBALS['submenu'] );
		parent::tearDown();
	}

	public function test_active_shared_guidance_is_separate_from_editable_fallback_and_saves_do_not_change_it(): void {
		$this->seed_shared_guidelines();
		WordPressTestState::$options[ Guidelines::OPTION_COPY ]   = 'Legacy copy.';
		WordPressTestState::$options[ Guidelines::OPTION_BLOCKS ] = [ 'core/quote' => 'Legacy quote.' ];
		$before_posts = serialize( WordPressTestState::$posts );
		$output       = $this->render_page();

		$this->assertStringContainsString( 'data-guidelines-active-preview', $output );
		$this->assertStringContainsString( 'Shared copy.', $output );
		$this->assertStringContainsString( 'Legacy fallback guidelines (inactive)', $output );
		$this->assertStringContainsString( 'Export Fallback JSON', $output );
		$this->assertStringContainsString( 'including unsaved edits', $output );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*name="flavor_agent_guideline_copy"[^>]*>Legacy copy\.<\/textarea>/', $output );
		$this->assertSame( [ 'core/quote' => 'Legacy quote.' ], $this->block_form_values( $output ) );

		update_option( Guidelines::OPTION_COPY, Settings::sanitize_guideline_copy( 'Changed fallback.' ) );
		update_option( Guidelines::OPTION_BLOCKS, Settings::sanitize_guideline_blocks( '{}' ) );

		$this->assertSame( 'Shared copy.', Guidelines::get_all()['copy'] );
		$this->assertSame( [ 'core/paragraph' => 'Shared paragraph.' ], Guidelines::get_block_guidelines() );
		$this->assertSame( $before_posts, serialize( WordPressTestState::$posts ) );
		$this->assertSame( [], WordPressTestState::$updated_posts );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );

		$_GET = [ 'settings-updated' => 'true' ];
		ob_start();
		Page::render_settings_save_summary( [ 'changed_sections' => [ Config::GROUP_GUIDELINES => true ] ] );
		$summary = (string) ob_get_clean();
		$this->assertStringContainsString( 'Legacy fallback guidelines saved. Active shared or provider guidelines are unchanged.', $summary );
	}

	public function test_empty_shared_storage_keeps_populated_fallback_active(): void {
		WordPressTestState::$registered_post_types['wp_knowledge'] = [ 'show_in_rest' => true ];
		WordPressTestState::$options[ Guidelines::OPTION_COPY ]    = 'Active fallback.';
		$output = $this->render_page();

		$this->assertStringContainsString( 'Flavor Agent guidelines are active.', $output );
		$this->assertStringContainsString( 'These fallback guidelines remain active until shared Guidelines contain content.', $output );
		$this->assertStringNotContainsString( 'data-guidelines-fallback', $output );
		$this->assertStringContainsString( 'Export JSON', $output );
		$this->assertSame( 'Active fallback.', Guidelines::get_all()['copy'] );
	}

	public function test_empty_shared_and_empty_fallback_explain_how_fallback_can_become_active(): void {
		WordPressTestState::$registered_post_types['wp_knowledge'] = [ 'show_in_rest' => true ];
		$output = $this->render_page();

		$this->assertStringContainsString( 'Add fallback guidance below and save it to use it in Flavor Agent', $output );
		$this->assertStringNotContainsString( 'Legacy fallback guidelines (inactive)', $output );
		update_option( Guidelines::OPTION_COPY, 'New fallback.' );
		$this->assertSame( 'legacy_options', Guidelines::storage_status()['source'] );
		$this->assertSame( 'New fallback.', Guidelines::get_all()['copy'] );
	}

	public function test_custom_repository_is_read_only_and_does_not_offer_unrelated_shared_management(): void {
		$repository = new class() implements GuidelinesRepository {
			public function source(): string {
				return 'custom';
			}

			public function get_all(): array {
				return [
					'site'       => '',
					'copy'       => 'Custom copy.',
					'images'     => '',
					'additional' => '',
					'blocks'     => [],
				];
			}
		};
		add_filter( 'flavor_agent_guidelines_repository', static fn() => $repository );
		WordPressTestState::$registered_post_types['wp_knowledge'] = [ 'show_in_rest' => true ];
		WordPressTestState::$capabilities['manage_options']        = true;
		$GLOBALS['submenu']['options-general.php'][]               = [ 'Guidelines', 'manage_options', 'guidelines-wp-admin' ];
		WordPressTestState::$options[ Guidelines::OPTION_COPY ]    = 'Legacy copy.';
		$output = $this->render_page();

		$this->assertStringContainsString( 'A custom Guidelines provider is active.', $output );
		$this->assertStringContainsString( 'Custom copy.', $output );
		$this->assertStringContainsString( 'Legacy fallback guidelines (inactive)', $output );
		$this->assertStringNotContainsString( 'Manage Shared Guidelines', $output );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*name="flavor_agent_guideline_copy"[^>]*>Legacy copy\.<\/textarea>/', $output );
	}

	public function test_shared_management_link_requires_registered_route_and_permission(): void {
		$this->seed_shared_guidelines();
		$this->assertStringNotContainsString( 'Manage Shared Guidelines', $this->render_page() );
		$this->assertStringContainsString( 'No accessible shared Guidelines management screen is registered', $this->render_page() );
		$GLOBALS['submenu']['options-general.php'][] = [ 'Guidelines', 'manage_options', 'guidelines-wp-admin' ];
		$this->assertSame( '', State::get_shared_guidelines_url() );
		WordPressTestState::$capabilities['manage_options'] = true;
		$this->assertSame( admin_url( 'options-general.php?page=guidelines-wp-admin' ), State::get_shared_guidelines_url() );
		$this->assertStringContainsString( 'Manage Shared Guidelines', $this->render_page() );
	}

	public function test_presence_is_configured_and_error_recovery_identifies_settings_to_review(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'mock-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'mock-token',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => PatternSearchInstanceManager::credential_signature( 'mock-account', 'mock-token', '@cf/qwen/qwen3-embedding-0.6b' ),
			'flavor_agent_pattern_index_state'             => [
				'pattern_backend' => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
				'last_error'      => 'Managed index sync failed.',
				'status'          => 'error',
			],
		];
		$state                       = State::get_page_state();
		$output                      = $this->render_page();

		$this->assertSame( 'Configured', State::get_embedding_overview_status( $state )['label'] );
		$this->assertSame( 'neutral', State::get_embedding_overview_status( $state )['tone'] );
		$this->assertStringContainsString( 'Configured does not confirm current connectivity.', $output );
		$this->assertStringContainsString( 'Managed pattern index configured. Check Pattern Sync for the latest catalog result.', $output );
		$this->assertStringNotContainsString( 'Qdrant', $output );
		$this->assertStringContainsString( 'Review Cloudflare credentials', $output );
		$this->assertStringContainsString( 'check sync status before starting another sync', $output );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_editor_handoff_is_permission_gated_and_explains_prerequisites_and_consequences(): void {
		$this->enable_editor_recommendations();
		$this->assertSame( '', State::get_editor_handoff_url() );
		$this->assertStringNotContainsString( '>Open Post Editor<', $this->render_page() );
		$this->assertStringContainsString( 'Ask an administrator for permission to create and edit posts', $this->render_page() );
		WordPressTestState::$capabilities['edit_posts'] = true;
		$output = $this->render_page();

		$this->assertSame( admin_url( 'post-new.php' ), State::get_editor_handoff_url() );
		$this->assertStringContainsString( 'Open Post Editor', $output );
		$this->assertStringContainsString( 'open AI Recommendations in its settings', $output );
		$this->assertStringContainsString( 'Text generation requires a configured provider in Connectors.', $output );
		$this->assertStringContainsString( 'Opening the editor does not request recommendations, apply changes, or publish content.', $output );
		$this->assertStringContainsString( 'Open Post Editor', Help::get_contextual_help_sidebar() );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_initial_sync_state_contains_only_display_fields_and_explains_check_consequences(): void {
		WordPressTestState::$options['flavor_agent_pattern_index_state'] = [
			'status'               => 'indexing',
			'indexed_count'        => 7,
			'pattern_backend'      => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'last_synced_at'       => null,
			'pattern_fingerprints' => [ 'private-pattern' => 'private-fingerprint' ],
			'api_token'            => 'unexpected-secret',
			'openai_endpoint'      => 'private-endpoint',
		];
		$output = $this->render_page();
		$this->assertSame( 1, preg_match( '/data-pattern-runtime-state="([^"]*)"/', $output, $matches ) );
		$display_state = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
		$this->assertSame( 'indexing', $display_state['status'] );
		$this->assertSame( 7, $display_state['indexed_count'] );
		$this->assertArrayNotHasKey( 'pattern_fingerprints', $display_state );
		$this->assertArrayNotHasKey( 'api_token', $display_state );
		$this->assertArrayNotHasKey( 'openai_endpoint', $display_state );
		$this->assertStringNotContainsString( 'unexpected-secret', $output );
		$this->assertStringContainsString( 'Checking status may continue an already queued sync.', $output );
		$this->assertStringContainsString( 'id="flavor-agent-sync-check-button"', $output );
	}

	public function test_legacy_storage_is_not_a_picker_and_reading_settings_does_not_provision_or_change_the_selector(): void {
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ]      = 'qdrant';
		WordPressTestState::$options['flavor_agent_qdrant_url']                       = 'https://retired.example.test';
		WordPressTestState::$options['flavor_agent_qdrant_key']                       = 'retired-key';
		WordPressTestState::$options['flavor_agent_pattern_recommendation_threshold'] = 0.91;
		$output = $this->render_page();

		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, State::get_selected_pattern_backend() );
		$this->assertSame( 'qdrant', WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] );
		$this->assertStringNotContainsString( 'Qdrant', $output );
		$this->assertStringNotContainsString( 'retired-key', $output );
		$this->assertStringNotContainsString( 'name="' . Config::OPTION_PATTERN_RETRIEVAL_BACKEND . '"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_qdrant_url"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_pattern_recommendation_threshold"', $output );
		$this->assertStringContainsString( 'name="' . Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH . '"', $output );
		$this->assertStringContainsString( 'Existing vectors from an older storage setup are not copied.', $output );
		$this->assertSame( [], WordPressTestState::$scheduled_events );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_retired_catalog_is_not_presented_as_available_while_cloudflare_rebuild_is_needed(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'mock-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'mock-token',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => PatternSearchInstanceManager::credential_signature( 'mock-account', 'mock-token', '@cf/qwen/qwen3-embedding-0.6b' ),
			'flavor_agent_pattern_index_state'             => [
				'pattern_backend'   => 'qdrant',
				'status'            => 'ready',
				'indexed_count'     => 12,
				'last_synced_at'    => '2026-10-10T12:00:00Z',
				'qdrant_collection' => 'retired-index',
			],
		];
		$output                      = $this->render_page();
		$this->assertStringContainsString( 'Pattern recommendations are unavailable until the catalog is rebuilt in Cloudflare AI Search.', $output );
		$this->assertStringContainsString( 'existing vectors are not copied', $output );
		$this->assertStringNotContainsString( 'Pattern recommendations are available but out of date.', $output );
		$this->assertStringNotContainsString( 'retired-index', $output );
		$this->assertStringNotContainsString( 'data-pattern-metric="embedding_dimension"', $output );
		$this->assertStringNotContainsString( 'data-pattern-metric="qdrant_collection"', $output );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_cloudflare_compatibility_drift_is_unavailable_and_needs_sync_even_with_a_saved_timestamp(): void {
		foreach ( [ 'cloudflare_ai_search_namespace', 'cloudflare_ai_search_instance', 'cloudflare_ai_search_signature', 'all_metadata' ] as $field ) {
			$this->seed_cloudflare_catalog();
			if ( 'all_metadata' === $field ) {
				unset(
					WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['cloudflare_ai_search_namespace'],
					WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['cloudflare_ai_search_instance'],
					WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['cloudflare_ai_search_signature']
				);
			} else {
				WordPressTestState::$options[ PatternIndex::STATE_OPTION ][ $field ] = 'previous-value';
			}
			$output = $this->render_page();
			$state  = State::get_page_state();
			$this->assertFalse( PatternIndex::recommendation_index_readiness( $state['pattern_state'] )['ready'], $field );
			$this->assertSame( 'Needs sync', State::get_pattern_overview_status( $state )['label'], $field );
			$this->assertStringContainsString( 'Pattern recommendations are unavailable until the catalog is rebuilt or revalidated in Cloudflare AI Search.', $output, $field );
			$this->assertStringContainsString( 'Review and save Cloudflare settings, then sync the catalog.', $output, $field );
			$this->assertStringNotContainsString( 'Pattern recommendations are available but out of date.', $output, $field );
			$this->assertStringNotContainsString( 'Pattern recommendations are syncing now.', $output, $field );
			$this->assertSame( 1, preg_match( '/data-pattern-runtime-state="([^"]*)"/', $output, $matches ) );
			$display = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
			$this->assertFalse( $display['recommendations_ready'], $field );
			$this->assertArrayNotHasKey( 'cloudflare_ai_search_signature', $display );
		}
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_usable_registry_only_stale_catalog_and_actual_indexing_keep_their_distinct_states(): void {
		$this->seed_cloudflare_catalog();
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['status']        = 'stale';
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['stale_reason']  = 'pattern_registry_changed';
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['stale_reasons'] = [ 'pattern_registry_changed' ];
		$state  = State::get_page_state();
		$output = $this->render_page();
		$this->assertTrue( PatternIndex::recommendation_index_readiness( $state['pattern_state'] )['ready'] );
		$this->assertSame( 'Refresh needed', State::get_pattern_overview_status( $state )['label'] );
		$this->assertStringContainsString( 'Pattern recommendations are available but out of date.', $output );

		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['status']        = 'indexing';
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['stale_reason']  = 'cloudflare_ai_search_signature_changed';
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['stale_reasons'] = [ 'cloudflare_ai_search_signature_changed' ];
		$this->assertSame( 'Syncing', State::get_pattern_overview_status( State::get_page_state() )['label'] );
		$this->assertStringContainsString( 'Pattern recommendations are syncing now.', $this->render_page() );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_scheduled_sync_retry_renders_as_retrying_until_an_error_needs_attention(): void {
		$this->seed_cloudflare_catalog();
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ] = array_merge(
			WordPressTestState::$options[ PatternIndex::STATE_OPTION ],
			[
				'status'                 => 'error',
				'last_synced_at'         => null,
				'last_error'             => 'Cloudflare Pattern AI Search item delete is temporarily rate limited (Cloudflare error 7114: ai_search_instance_overloaded).',
				'last_error_code'        => 'rate_limited',
				'last_error_status'      => 429,
				'last_error_retryable'   => true,
				'last_error_retry_after' => 30,
			]
		);
		$state  = State::get_page_state();
		$output = $this->render_page();

		$this->assertSame( State::make_badge( 'Retrying', 'warning' ), State::get_pattern_overview_status( $state ) );
		$this->assertMatchesRegularExpression( '/badge--warning"[^>]*data-pattern-status-badge="panel"[^>]*>\s*Retrying\s*</', $output );
		$this->assertStringContainsString( 'Pattern catalog sync paused after a temporary Cloudflare error and resumes automatically.', $output );
		$this->assertStringContainsString( 'keeps the progress it already made', $output );
		$this->assertStringContainsString( 'ai_search_instance_overloaded', $output );
		$this->assertStringNotContainsString( 'Pattern recommendations need attention before use.', $output );
		$this->assertStringNotContainsString( 'Review Cloudflare credentials', $output );
		$this->assertStringNotContainsString( 'flavor-agent-sync-panel__metric-value--error', $output );
		$this->assertSame( 1, preg_match( '/data-pattern-runtime-state="([^"]*)"/', $output, $matches ) );
		$this->assertTrue( json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true )['last_error_retryable'] );

		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['last_error_retryable']   = false;
		WordPressTestState::$options[ PatternIndex::STATE_OPTION ]['last_error_retry_after'] = null;
		$output = $this->render_page();

		$this->assertSame( 'Needs attention', State::get_pattern_overview_status( State::get_page_state() )['label'] );
		$this->assertMatchesRegularExpression( '/badge--error"[^>]*data-pattern-status-badge="panel"[^>]*>\s*Error\s*</', $output );
		$this->assertStringContainsString( 'Pattern recommendations need attention before use.', $output );
		$this->assertStringContainsString( 'Review Cloudflare credentials', $output );
		$this->assertStringContainsString( 'flavor-agent-sync-panel__metric-value--error', $output );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_post_editor_handoff_requires_actual_create_permission_even_when_editing_is_allowed(): void {
		$this->enable_editor_recommendations();
		WordPressTestState::$post_type_objects['post']               = (object) [
			'name' => 'post',
			'cap'  => (object) [
				'edit_posts'   => 'edit_posts',
				'create_posts' => 'create_restricted_posts',
			],
		];
		WordPressTestState::$capabilities['edit_posts']              = true;
		WordPressTestState::$capabilities['create_restricted_posts'] = false;

		$this->assertSame( '', State::get_editor_handoff_url() );
		$this->assertStringNotContainsString( 'Open Post Editor', $this->render_page() );
		$this->assertStringNotContainsString( 'Open Post Editor', Help::get_contextual_help_sidebar() );

		WordPressTestState::$capabilities['create_restricted_posts'] = true;
		$this->assertSame( admin_url( 'post-new.php' ), State::get_editor_handoff_url() );
		$this->assertStringContainsString( 'Open Post Editor', $this->render_page() );
		$this->assertSame( [], WordPressTestState::$inserted_posts );
	}

	public function test_disabled_feature_offers_only_verified_ai_settings_recovery(): void {
		WordPressTestState::$capabilities['edit_posts']       = true;
		WordPressTestState::$capabilities['manage_options']   = true;
		WordPressTestState::$options['wpai_features_enabled'] = true;
		$this->assertSame( '', State::get_editor_handoff_url() );
		$this->assertSame( '', State::get_ai_settings_url() );
		$this->assertStringContainsString( 'No accessible AI settings screen is registered', $this->render_page() );

		$GLOBALS['submenu']['options-general.php'][] = [ 'AI', 'manage_options', 'ai-wp-admin' ];
		$output                                      = $this->render_page();
		$this->assertStringContainsString( 'Flavor Agent editor recommendations are disabled.', $output );
		$this->assertStringContainsString( 'Enable Flavor Agent in Settings &gt; AI', $output );
		$this->assertStringContainsString( 'Open AI Settings', $output );
		$this->assertStringContainsString( 'options-general.php?page=ai-wp-admin', $output );
		$this->assertStringNotContainsString( 'Open Post Editor', $output );
		$this->assertStringNotContainsString( 'select a block, and open AI Recommendations', $output );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );

		$this->enable_editor_recommendations();
		$this->assertSame( admin_url( 'post-new.php' ), State::get_editor_handoff_url() );
		add_filter( 'wpai_feature_flavor-agent_enabled', '__return_false' );
		$this->assertSame( '', State::get_editor_handoff_url() );
	}

	public function test_ai_settings_destination_requires_registration_and_permission_and_prefers_current_route(): void {
		WordPressTestState::$capabilities['manage_options'] = true;
		$this->assertSame( '', State::get_ai_settings_url() );
		$GLOBALS['submenu']['options-general.php'][] = [ 'AI', 'manage_options', 'ai' ];
		$this->assertSame( admin_url( 'options-general.php?page=ai' ), State::get_ai_settings_url() );
		$GLOBALS['submenu']['options-general.php'][] = [ 'AI', 'manage_options', 'ai-wp-admin' ];
		$this->assertSame( admin_url( 'options-general.php?page=ai-wp-admin' ), State::get_ai_settings_url() );
		WordPressTestState::$capabilities['manage_options'] = false;
		$this->assertSame( '', State::get_ai_settings_url() );
		$this->assertStringNotContainsString( 'Open AI Settings', $this->render_page() );
		$this->assertStringNotContainsString( 'Open AI Settings', Help::get_contextual_help_sidebar() );
	}

	private function enable_editor_recommendations(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_flavor-agent_enabled', true );
	}

	private function seed_cloudflare_catalog(): void {
		$instance                    = PatternSearchInstanceManager::managed_instance_id();
		$signature                   = PatternSearchInstanceManager::credential_signature( 'mock-account', 'mock-token', '@cf/qwen/qwen3-embedding-0.6b' );
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'mock-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'mock-token',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => $instance,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => $signature,
			PatternIndex::STATE_OPTION                     => [
				'pattern_backend'                => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
				'status'                         => 'ready',
				'last_synced_at'                 => '2026-10-10T12:00:00Z',
				'indexed_count'                  => 3,
				'cloudflare_ai_search_namespace' => PatternSearchInstanceManager::managed_namespace(),
				'cloudflare_ai_search_instance'  => $instance,
				'cloudflare_ai_search_signature' => $signature,
			],
		];
	}

	private function seed_shared_guidelines(): void {
		WordPressTestState::$registered_post_types['wp_knowledge'] = [ 'show_in_rest' => true ];
		WordPressTestState::$posts[201]                            = (object) [
			'ID'           => 201,
			'post_type'    => 'wp_knowledge',
			'post_status'  => 'publish',
			'post_name'    => 'guideline-copy',
			'post_content' => 'Shared copy.',
		];
		WordPressTestState::$posts[202]                            = (object) [
			'ID'           => 202,
			'post_type'    => 'wp_knowledge',
			'post_status'  => 'publish',
			'post_name'    => 'guideline-block-core_paragraph',
			'post_content' => 'Shared paragraph.',
		];
	}

	private function render_page(): string {
		ob_start();
		Settings::render_page();
		return (string) ob_get_clean();
	}

	private function block_form_values( string $output ): array {
		$this->assertSame( 1, preg_match( '/<textarea[^>]*name="flavor_agent_guideline_blocks"[^>]*>(.*?)<\/textarea>/s', $output, $matches ) );
		return json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
	}
}
