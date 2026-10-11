<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Admin\Settings\Registrar;
use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Settings;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class SettingsRegistrarTest extends TestCase {


	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_register_settings_registers_core_options_with_expected_sanitizers(): void {
		Registrar::register_settings();

		$settings = $GLOBALS['wp_registered_settings'];

		$this->assertSame(
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_openai_provider' ],
				'default'           => WorkersAIEmbeddingConfiguration::PROVIDER,
				'option_group'      => Config::OPTION_GROUP,
				'option_name'       => Provider::OPTION_NAME,
			],
			$settings[ Provider::OPTION_NAME ]
		);
		$this->assertArrayNotHasKey( Config::OPTION_PATTERN_RETRIEVAL_BACKEND, $settings );
		$this->assertArrayNotHasKey( 'flavor_agent_qdrant_url', $settings );
		$this->assertArrayNotHasKey( 'flavor_agent_qdrant_key', $settings );
		$this->assertArrayNotHasKey( 'flavor_agent_pattern_recommendation_threshold', $settings );
		$this->assertSame( 'array', $settings[ Guidelines::OPTION_BLOCKS ]['type'] );
		$this->assertSame(
			[ Settings::class, 'sanitize_guideline_blocks' ],
			$settings[ Guidelines::OPTION_BLOCKS ]['sanitize_callback']
		);
		$this->assertArrayNotHasKey( 'flavor_agent_block_structural_actions_enabled', $settings );
		$this->assertArrayHasKey( Config::OPTION_DUAL_LOG_REQUEST_DIAGNOSTICS, $settings );
		$this->assertSame( 'boolean', $settings[ Config::OPTION_DUAL_LOG_REQUEST_DIAGNOSTICS ]['type'] );
		$this->assertSame(
			[ Settings::class, 'sanitize_dual_log_request_diagnostics' ],
			$settings[ Config::OPTION_DUAL_LOG_REQUEST_DIAGNOSTICS ]['sanitize_callback']
		);
		$this->assertSame( 'string', $settings[ Config::OPTION_REASONING_EFFORT ]['type'] );
		$this->assertSame(
			[ Settings::class, 'sanitize_reasoning_effort' ],
			$settings[ Config::OPTION_REASONING_EFFORT ]['sanitize_callback']
		);
		$this->assertSame( 'medium', $settings[ Config::OPTION_REASONING_EFFORT ]['default'] );

		foreach ( $settings as $setting ) {
			$this->assertSame( Config::OPTION_GROUP, $setting['option_group'] );
		}
	}

	public function test_register_settings_registers_expected_sections_and_critical_fields(): void {
		Registrar::register_settings();

		$settings = $GLOBALS['wp_registered_settings'];
		$sections = $GLOBALS['wp_settings_sections'][ Config::PAGE_SLUG ] ?? [];
		$fields   = $GLOBALS['wp_settings_fields'][ Config::PAGE_SLUG ] ?? [];

		$this->assertSame(
			[
				'flavor_agent_cloudflare_workers_ai',
				'flavor_agent_cloudflare_pattern_ai_search',
				'flavor_agent_pattern_recommendations',
				'flavor_agent_cloudflare',
				'flavor_agent_guidelines',
				'flavor_agent_experimental_features',
			],
			array_keys( $sections )
		);
		$this->assertArrayNotHasKey( 'flavor_agent_azure', $sections );
		$this->assertArrayNotHasKey( 'flavor_agent_pattern_retrieval', $sections );
		$this->assertArrayNotHasKey( 'flavor_agent_qdrant', $sections );
		$this->assertSame(
			[ Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH, 'flavor_agent_pattern_max_recommendations' ],
			array_keys( $fields['flavor_agent_pattern_recommendations'] )
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
			$fields['flavor_agent_cloudflare_pattern_ai_search'] ?? []
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID,
			$fields['flavor_agent_cloudflare_pattern_ai_search'] ?? []
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE,
			$fields['flavor_agent_cloudflare_pattern_ai_search'] ?? []
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN,
			$fields['flavor_agent_cloudflare_pattern_ai_search'] ?? []
		);
		$this->assertArrayHasKey(
			Guidelines::OPTION_ADDITIONAL,
			$fields['flavor_agent_guidelines']
		);
		$this->assertArrayNotHasKey(
			'flavor_agent_block_structural_actions_enabled',
			$fields['flavor_agent_experimental_features']
		);
		$this->assertArrayHasKey(
			Config::OPTION_DUAL_LOG_REQUEST_DIAGNOSTICS,
			$fields['flavor_agent_experimental_features']
		);
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_ai_search_account_id', $settings );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_ai_search_instance_id', $settings );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_ai_search_api_token', $settings );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_ai_search_account_id', $fields['flavor_agent_cloudflare'] );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_ai_search_instance_id', $fields['flavor_agent_cloudflare'] );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_ai_search_api_token', $fields['flavor_agent_cloudflare'] );
	}

	public function test_secret_settings_are_registered_without_autoload(): void {
		Registrar::register_settings();

		$settings       = $GLOBALS['wp_registered_settings'];
		$secret_options = [
			'flavor_agent_cloudflare_workers_ai_api_token',
		];

		foreach ( $secret_options as $option_name ) {
			$this->assertArrayHasKey( $option_name, $settings );
			$this->assertFalse( $settings[ $option_name ]['autoload'] ?? true );
		}
	}
}
