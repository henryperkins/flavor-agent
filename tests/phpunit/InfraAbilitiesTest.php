<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\InfraAbilities;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Embeddings\EmbeddingClient;
use FlavorAgent\Embeddings\QdrantClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class InfraAbilitiesTest extends TestCase {


	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	protected function tearDown(): void {
		parent::tearDown();
	}

	public function test_check_status_marks_cloudflare_docs_backend_as_configured_without_marking_plugin_ready(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'     => true,
			'manage_options' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertNull( $status['model'] );
		$this->assertContains( 'flavor-agent/search-wordpress-docs', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_ai_search']['configured'] );
		$this->assertSame(
			'c5d54c4a-27df-4034-80da-ca6054684fcd',
			$status['backends']['cloudflare_ai_search']['instanceId']
		);
		$this->assertIsArray( $status['backends']['cloudflare_ai_search']['runtime'] ?? null );
		$this->assertSame( [], $status['backends']['cloudflare_ai_search']['lastSourceTypes'] ?? null );
	}

	public function test_check_status_includes_new_design_helpers_for_editors(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertContains( 'flavor-agent/get-pattern', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/list-synced-patterns', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-synced-pattern', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/list-template-parts', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/list-allowed-blocks', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-active-theme', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-theme-presets', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-theme-styles', $status['availableAbilities'] );
	}

	public function test_check_status_reports_preview_recommend_block_for_post_editors(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertContains( 'flavor-agent/preview-recommend-block', $status['availableAbilities'] );
		$this->assertNotContains( 'flavor-agent/preview-recommend-template', $status['availableAbilities'], 'Template previews require edit_theme_options.' );
		$this->assertNotContains( 'flavor-agent/preview-recommend-style', $status['availableAbilities'] );
	}

	public function test_check_status_reports_theme_scoped_previews_for_theme_editors(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];

		$status = InfraAbilities::check_status( [] );

		foreach (
			[
				'flavor-agent/preview-recommend-navigation',
				'flavor-agent/preview-recommend-style',
				'flavor-agent/preview-recommend-template',
				'flavor-agent/preview-recommend-template-part',
			] as $ability_id
		) {
			$this->assertContains( $ability_id, $status['availableAbilities'], "{$ability_id} should be available to edit_theme_options users." );
		}

		$this->assertNotContains( 'flavor-agent/preview-recommend-block', $status['availableAbilities'], 'preview-recommend-block requires edit_posts.' );
	}

	public function test_check_status_keeps_list_template_parts_available_for_theme_only_users(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertContains( 'flavor-agent/list-template-parts', $status['availableAbilities'] );
		$this->assertNotContains( 'flavor-agent/get-pattern', $status['availableAbilities'] );
	}

	public function test_check_status_uses_generic_wordpress_ai_client_without_selected_connector(): void {
		WordPressTestState::$capabilities        = [
			'edit_posts' => true,
		];
		WordPressTestState::$options             = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
		WordPressTestState::$ai_client_supported = true;
		add_filter( 'wpai_has_ai_credentials', '__return_true' );

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertTrue( $status['surfaces']['block']['available'] );
		$this->assertSame( 'connectors', $status['surfaces']['block']['owner'] );
		$this->assertTrue( $status['backends']['wordpress_ai_client']['configured'] );
	}

	public function test_check_status_filters_admin_only_docs_ability_for_non_admin_users(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertNotContains( 'flavor-agent/search-wordpress-docs', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_ai_search']['configured'] );
	}

	public function test_check_status_reports_workers_ai_backend_for_patterns(): void {
		WordPressTestState::$capabilities        = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];
		WordPressTestState::$options             = [
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
		];
		WordPressTestState::$ai_client_supported = true;
		$this->save_ready_qdrant_pattern_index();

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template-part', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-navigation', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-style', $status['availableAbilities'] );
		$this->assertArrayNotHasKey( 'openai_native', $status['backends'] );
		$this->assertSame(
			'@cf/qwen/qwen3-embedding-0.6b',
			$status['backends']['cloudflare_workers_ai']['embeddingModel']
		);
		$this->assertTrue( $status['backends']['cloudflare_workers_ai']['configured'] );
	}

	public function test_check_status_hides_recommendation_surfaces_when_ai_feature_is_disabled(): void {
		WordPressTestState::$capabilities                   = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
			'manage_options'     => true,
		];
		WordPressTestState::$connectors                     = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
			WordPressTestState::$options                    = [
				'flavor_agent_openai_provider'      => 'cloudflare_workers_ai',
				'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
				'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
				'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
				'flavor_agent_qdrant_url'           => 'https://example.cloud.qdrant.io:6333',
				'flavor_agent_qdrant_key'           => 'qdrant-key',
				'wpai_features_enabled'             => true,
				'wpai_feature_flavor-agent_enabled' => false,
			];
			WordPressTestState::$ai_client_supported        = true;
			WordPressTestState::$ai_client_provider_support = [
				'openai' => true,
			];

			$status = InfraAbilities::check_status( [] );

			$this->assertFalse( $status['configured'] );
			$this->assertSame( 'ai_feature_disabled', $status['surfaces']['block']['reason'] );
			$this->assertFalse( $status['surfaces']['block']['available'] );
			$this->assertFalse( $status['surfaces']['template']['available'] );
			$this->assertFalse( $status['surfaces']['pattern']['available'] );

			foreach (
			[
				'flavor-agent/recommend-block',
				'flavor-agent/recommend-content',
				'flavor-agent/recommend-patterns',
				'flavor-agent/recommend-template',
				'flavor-agent/recommend-template-part',
				'flavor-agent/recommend-navigation',
				'flavor-agent/recommend-style',
			] as $ability_id
			) {
				$this->assertNotContains( $ability_id, $status['availableAbilities'] );
			}

			$this->assertContains( 'flavor-agent/introspect-block', $status['availableAbilities'] );
			$this->assertArrayNotHasKey( 'openai_native', $status['backends'] );
			$this->assertTrue( $status['backends']['cloudflare_workers_ai']['configured'] );
	}

	public function test_check_status_uses_generic_chat_and_workers_ai_embeddings_for_patterns(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		WordPressTestState::$options = [
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
		];

		WordPressTestState::$ai_client_supported = true;
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		$this->save_ready_qdrant_pattern_index();

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertTrue( $status['surfaces']['template']['available'] );
		$this->assertTrue( $status['surfaces']['pattern']['available'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-navigation', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-style', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_workers_ai']['configured'] );
		$this->assertSame(
			'@cf/qwen/qwen3-embedding-0.6b',
			$status['backends']['cloudflare_workers_ai']['embeddingModel']
		);
	}

	public function test_check_status_allows_cloudflare_pattern_ai_search_without_qdrant(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		WordPressTestState::$options = [
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => PatternSearchInstanceManager::credential_signature(
				'account-123',
				'token-xyz',
				'@cf/qwen/qwen3-embedding-0.6b'
			),
		];

		WordPressTestState::$ai_client_supported = true;
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		$this->save_ready_cloudflare_ai_search_pattern_index();

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertTrue( $status['surfaces']['pattern']['available'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertArrayNotHasKey( 'openai_native', $status['backends'] );
		$this->assertFalse( $status['backends']['qdrant']['configured'] );
	}

	public function test_check_status_reports_workers_ai_embeddings_for_patterns(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		WordPressTestState::$options = [
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'connectors_ai_openai_api_key'                 => 'connector-key',
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
		];

		WordPressTestState::$connectors = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];

		WordPressTestState::$ai_client_supported = true;
		$this->save_ready_qdrant_pattern_index();

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertTrue( $status['surfaces']['pattern']['available'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_workers_ai']['configured'] );
		$this->assertSame(
			'@cf/qwen/qwen3-embedding-0.6b',
			$status['backends']['cloudflare_workers_ai']['embeddingModel']
		);
	}

	public function test_check_status_does_not_advertise_pattern_recommendations_before_first_sync(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		WordPressTestState::$options = [
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'connectors_ai_openai_api_key'                 => 'connector-key',
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
		];

		WordPressTestState::$connectors = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];

		WordPressTestState::$ai_client_supported = true;

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertFalse( $status['surfaces']['pattern']['available'] );
		$this->assertSame( 'needs_sync', $status['surfaces']['pattern']['reason'] );
		$this->assertNotContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertSame( 'uninitialized', PatternIndex::get_state()['status'] );
	}

	public function test_check_status_uses_provider_managed_model_for_selected_connector_provider(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		WordPressTestState::$options = [
			'flavor_agent_openai_provider'      => 'anthropic',
			'flavor_agent_qdrant_url'           => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'           => 'qdrant-key',
			'connectors_ai_anthropic_api_key'   => 'anthropic-key',
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		WordPressTestState::$connectors = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'description'    => 'Anthropic connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];

		WordPressTestState::$ai_client_supported = true;

		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertTrue( $status['surfaces']['template']['available'] );
		$this->assertTrue( $status['surfaces']['navigation']['available'] );
		$this->assertFalse( $status['surfaces']['pattern']['available'] );
		$this->assertSame( 'pattern_backend_unconfigured', $status['surfaces']['pattern']['reason'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-navigation', $status['availableAbilities'] );
		$this->assertNotContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
	}

	public function test_check_status_marks_navigation_surface_unavailable_without_theme_capability(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];
		WordPressTestState::$options      = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['surfaces']['navigation']['available'] );
		$this->assertFalse( $status['surfaces']['globalStyles']['available'] );
		$this->assertSame(
			'missing_theme_capability',
			$status['surfaces']['navigation']['reason']
		);
		$this->assertSame(
			'missing_theme_capability',
			$status['surfaces']['globalStyles']['reason']
		);
		$this->assertNotContains(
			'flavor-agent/recommend-navigation',
			$status['availableAbilities']
		);
		$this->assertNotContains(
			'flavor-agent/recommend-style',
			$status['availableAbilities']
		);
	}

	public function test_check_status_marks_pattern_surface_unavailable_without_plugin_backends(): void {
		WordPressTestState::$capabilities               = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];
		WordPressTestState::$options                    = [
			'flavor_agent_openai_provider'      => 'openai',
			'connectors_ai_openai_api_key'      => 'connector-key',
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
		WordPressTestState::$connectors                 = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['surfaces']['pattern']['available'] );
		$this->assertSame(
			'pattern_backend_unconfigured',
			$status['surfaces']['pattern']['reason']
		);
		$this->assertSame(
			'plugin_settings',
			$status['surfaces']['pattern']['owner']
		);
		$this->assertTrue( $status['surfaces']['template']['available'] );
		$this->assertTrue( $status['surfaces']['globalStyles']['available'] );
		$this->assertNotContains(
			'flavor-agent/recommend-patterns',
			$status['availableAbilities']
		);
	}

	public function test_check_status_marks_block_surface_unavailable_without_connectors_chat(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];
		WordPressTestState::$options      = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertFalse( $status['surfaces']['block']['available'] );
		$this->assertSame(
			'block_backend_unconfigured',
			$status['surfaces']['block']['reason']
		);
		$this->assertSame(
			'connectors',
			$status['surfaces']['block']['owner']
		);
		$this->assertNotContains(
			'flavor-agent/recommend-block',
			$status['availableAbilities']
		);
		$this->assertFalse( $status['surfaces']['globalStyles']['available'] );
		$this->assertSame(
			'missing_theme_capability',
			$status['surfaces']['globalStyles']['reason']
		);
	}

	private function save_ready_qdrant_pattern_index(): void {
		$embedding_config    = Provider::embedding_configuration();
		$embedding_signature = EmbeddingClient::build_signature_for_dimension( 2, $embedding_config );

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'               => 'ready',
					'pattern_backend'      => Config::PATTERN_BACKEND_QDRANT,
					'qdrant_url'           => (string) get_option( 'flavor_agent_qdrant_url', '' ),
					'qdrant_collection'    => QdrantClient::get_collection_name( $embedding_signature ),
					'openai_provider'      => $embedding_config['provider'],
					'openai_endpoint'      => $embedding_config['endpoint'],
					'embedding_model'      => $embedding_config['model'],
					'embedding_dimension'  => 2,
					'embedding_signature'  => $embedding_signature['signature_hash'],
					'last_synced_at'       => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'      => '2000-01-01T00:00:00+00:00',
					'indexed_count'        => 1,
					'stale_reason'         => '',
					'stale_reasons'        => [],
					'pattern_fingerprints' => [],
				]
			)
		);
	}

	private function save_ready_cloudflare_ai_search_pattern_index(): void {
		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'                         => 'ready',
					'pattern_backend'                => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
					'qdrant_url'                     => '',
					'qdrant_collection'              => '',
					'cloudflare_ai_search_namespace' => Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE,
					'cloudflare_ai_search_instance'  => (string) get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID, '' ),
					'cloudflare_ai_search_signature' => PatternSearchInstanceManager::credential_signature(
						(string) get_option( 'flavor_agent_cloudflare_workers_ai_account_id', '' ),
						(string) get_option( 'flavor_agent_cloudflare_workers_ai_api_token', '' ),
						(string) get_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '' )
					),
					'last_synced_at'                 => '2026-03-24T00:00:00+00:00',
					'last_attempt_at'                => '2000-01-01T00:00:00+00:00',
					'indexed_count'                  => 1,
					'stale_reason'                   => '',
					'stale_reasons'                  => [],
					'pattern_fingerprints'           => [],
				]
			)
		);
	}
}
