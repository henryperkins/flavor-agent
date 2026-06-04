<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/support/editor-surface-capabilities-bootstrap.php';

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class EditorSurfaceCapabilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
	}

	public function test_non_admin_site_editor_does_not_receive_configuration_links(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => false,
		];

		$capabilities = \flavor_agent_get_editor_surface_capabilities(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertSame( [], $capabilities['block']['actions'] );
		$this->assertSame(
			'Block recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['block']['message']
		);
		$this->assertSame( '', $capabilities['pattern']['configurationLabel'] );
		$this->assertSame( '', $capabilities['pattern']['configurationUrl'] );
		$this->assertSame(
			'Pattern recommendations are not configured yet. Ask an administrator to configure Pattern Storage in Settings > Flavor Agent and a text-generation provider in Settings > Connectors.',
			$capabilities['pattern']['message']
		);
		$this->assertSame( [], $capabilities['content']['actions'] );
		$this->assertSame( '', $capabilities['content']['configurationLabel'] );
		$this->assertSame(
			'Content recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['content']['message']
		);
		$this->assertSame( '', $capabilities['template']['configurationLabel'] );
		$this->assertSame( '', $capabilities['templatePart']['configurationLabel'] );
		$this->assertSame( [], $capabilities['navigation']['actions'] );
		$this->assertSame( '', $capabilities['navigation']['configurationLabel'] );
		$this->assertSame( [], $capabilities['globalStyles']['actions'] );
		$this->assertSame( '', $capabilities['globalStyles']['configurationLabel'] );
		$this->assertFalse( $capabilities['styleBook']['available'] );
		$this->assertSame( [], $capabilities['styleBook']['actions'] );
		$this->assertSame( '', $capabilities['styleBook']['configurationLabel'] );
		$this->assertSame(
			'Navigation recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['navigation']['message']
		);
		$this->assertSame(
			'Global Styles recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['globalStyles']['message']
		);
		$this->assertSame(
			'Style Book recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['styleBook']['message']
		);
	}

	public function test_admin_receives_configuration_links_for_unavailable_surfaces(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => true,
		];

		$capabilities = \flavor_agent_get_editor_surface_capabilities(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['block']['actions']
		);
		$this->assertSame(
			'Settings > Connectors',
			$capabilities['template']['configurationLabel']
		);
		$this->assertSame(
			'https://example.test/wp-admin/options-connectors.php',
			$capabilities['template']['configurationUrl']
		);
		$this->assertSame(
			'Settings > Flavor Agent',
			$capabilities['pattern']['configurationLabel']
		);
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['content']['actions']
		);
		$this->assertSame( 'Settings > Connectors', $capabilities['content']['configurationLabel'] );
		$this->assertSame(
			'Configure a text-generation provider in Settings > Connectors to enable content recommendations.',
			$capabilities['content']['message']
		);
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['navigation']['actions']
		);
		$this->assertSame(
			'Settings > Connectors',
			$capabilities['globalStyles']['configurationLabel']
		);
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['globalStyles']['actions']
		);
		$this->assertFalse( $capabilities['styleBook']['available'] );
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['styleBook']['actions']
		);
		$this->assertSame( 'Settings > Connectors', $capabilities['styleBook']['configurationLabel'] );
		$this->assertSame(
			'Configure a text-generation provider in Settings > Connectors to enable Style Book recommendations.',
			$capabilities['styleBook']['message']
		);
	}

	public function test_admin_bootstrap_data_includes_connector_approval_url(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => true,
		];

		$data = \flavor_agent_get_editor_bootstrap_data(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertArrayHasKey( 'connectorApprovalUrl', $data );
		$this->assertStringContainsString(
			'tools.php?page=ai-connector-approval',
			$data['connectorApprovalUrl']
		);
	}

	public function test_non_admin_bootstrap_data_keeps_empty_connector_approval_url(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => false,
		];

		$data = \flavor_agent_get_editor_bootstrap_data(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertArrayHasKey( 'connectorApprovalUrl', $data );
		$this->assertSame( '', $data['connectorApprovalUrl'] );
	}

	public function test_pattern_capability_uses_cloudflare_ai_search_unavailable_copy(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => true,
		];
		WordPressTestState::$options      = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'wpai_features_enabled'                  => true,
			'wpai_feature_flavor-agent_enabled'      => true,
		];

		$capabilities = \flavor_agent_get_editor_surface_capabilities(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertFalse( $capabilities['pattern']['available'] );
		$this->assertSame( 'pattern_backend_unconfigured', $capabilities['pattern']['reason'] );
		$this->assertSame(
			'Pattern recommendations need Cloudflare AI Search Pattern Storage in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.',
			$capabilities['pattern']['message']
		);
	}

	public function test_pattern_surface_requires_synced_index_after_backends_are_configured(): void {
		WordPressTestState::$capabilities        = [
			'edit_theme_options' => true,
			'manage_options'     => true,
		];
		WordPressTestState::$options             = [
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'connectors_ai_openai_api_key'                 => 'connector-key',
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
		];
		WordPressTestState::$connectors          = [
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

		$capabilities = \flavor_agent_get_editor_surface_capabilities(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertFalse( $capabilities['pattern']['available'] );
		$this->assertSame( 'needs_sync', $capabilities['pattern']['reason'] );
	}

	public function test_pattern_surface_exposes_runtime_signature_when_index_is_usable(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => true,
		];
		WordPressTestState::$options      = [
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'connectors_ai_openai_api_key'                 => 'connector-key',
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
		];
		WordPressTestState::$connectors   = [
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

		PatternIndex::save_state(
			array_merge(
				PatternIndex::get_state(),
				[
					'status'         => 'ready',
					'fingerprint'    => 'fingerprint-123',
					'last_synced_at' => '2026-06-04T00:00:00+00:00',
					'indexed_count'  => 3,
				]
			)
		);

		$capabilities = \flavor_agent_get_editor_surface_capabilities(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $capabilities['pattern']['patternRuntimeSignature'] ?? '' );
	}
}
