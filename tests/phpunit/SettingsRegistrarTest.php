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
		$this->assertSame(
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_pattern_retrieval_backend' ],
				'default'           => Config::PATTERN_BACKEND_QDRANT,
				'option_group'      => Config::OPTION_GROUP,
				'option_name'       => Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
			],
			$settings[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ]
		);
		$this->assertSame( 'array', $settings[ Guidelines::OPTION_BLOCKS ]['type'] );
		$this->assertSame(
			[ Settings::class, 'sanitize_guideline_blocks' ],
			$settings[ Guidelines::OPTION_BLOCKS ]['sanitize_callback']
		);
		$this->assertSame( 'boolean', $settings[ Config::OPTION_BLOCK_STRUCTURAL_ACTIONS ]['type'] );
		$this->assertSame(
			[ Settings::class, 'sanitize_block_structural_actions_enabled' ],
			$settings[ Config::OPTION_BLOCK_STRUCTURAL_ACTIONS ]['sanitize_callback']
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
				'flavor_agent_pattern_retrieval',
				'flavor_agent_qdrant',
				'flavor_agent_cloudflare_pattern_ai_search',
				'flavor_agent_pattern_recommendations',
				'flavor_agent_cloudflare',
				'flavor_agent_guidelines',
				'flavor_agent_experimental_features',
			],
			array_keys( $sections )
		);
		$this->assertArrayNotHasKey( 'flavor_agent_azure', $sections );
		$this->assertSame( 'Pattern Storage', $sections['flavor_agent_pattern_retrieval']['title'] );
		$this->assertArrayHasKey(
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
			$fields['flavor_agent_pattern_retrieval']
		);
		$this->assertSame(
			[
				Config::PATTERN_BACKEND_QDRANT => 'Qdrant vector storage',
				Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH => 'Cloudflare AI Search managed index',
			],
			$fields['flavor_agent_pattern_retrieval'][ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ]['args']['choices']
		);
		$this->assertArrayHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
			$fields['flavor_agent_cloudflare_pattern_ai_search']
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID,
			$fields['flavor_agent_cloudflare_pattern_ai_search']
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE,
			$fields['flavor_agent_cloudflare_pattern_ai_search']
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN,
			$fields['flavor_agent_cloudflare_pattern_ai_search']
		);
		$this->assertArrayHasKey(
			Guidelines::OPTION_ADDITIONAL,
			$fields['flavor_agent_guidelines']
		);
		$this->assertArrayHasKey(
			Config::OPTION_BLOCK_STRUCTURAL_ACTIONS,
			$fields['flavor_agent_experimental_features']
		);
		$this->assertSame(
			[ Settings::class, 'render_checkbox_field' ],
			$fields['flavor_agent_experimental_features'][ Config::OPTION_BLOCK_STRUCTURAL_ACTIONS ]['callback']
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
			'flavor_agent_qdrant_key',
		];

		foreach ( $secret_options as $option_name ) {
			$this->assertArrayHasKey( $option_name, $settings );
			$this->assertFalse( $settings[ $option_name ]['autoload'] ?? true );
		}
	}
}
