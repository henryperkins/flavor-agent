<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AI\FeatureBootstrap;
use FlavorAgent\AI\FlavorAgentFeature;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class FeatureBootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_register_feature_class_adds_flavor_agent_feature(): void {
		$classes = FeatureBootstrap::register_feature_class( [] );

		$this->assertSame( FlavorAgentFeature::class, $classes['flavor-agent'] ?? null );
	}

	public function test_feature_metadata_matches_ai_settings_contract(): void {
		$feature = new FlavorAgentFeature();

		$this->assertSame( 'flavor-agent', FlavorAgentFeature::get_id() );
		$this->assertSame( 'Flavor Agent', $feature->get_label() );
		$this->assertStringContainsString(
			'AI-assisted recommendations',
			$feature->get_description()
		);
		$this->assertSame( 'editor', $feature->get_category() );
		$this->assertSame( 'experimental', $feature->get_stability() );
	}

	public function test_feature_registers_editor_assets(): void {
		$feature = new FlavorAgentFeature();

		$feature->register();

		$this->assertNotEmpty( WordPressTestState::$filters['enqueue_block_editor_assets'][10] ?? [] );
	}

	public function test_global_helper_ability_registration_registers_recommendation_abilities_when_enabled(): void {
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		FeatureBootstrap::register_global_ability_category();
		FeatureBootstrap::register_global_helper_abilities();

		$this->assertArrayHasKey( 'flavor-agent', WordPressTestState::$registered_ability_categories );
		$this->assertArrayHasKey( 'flavor-agent/introspect-block', WordPressTestState::$registered_abilities );
		$this->assertArrayHasKey( 'flavor-agent/recommend-block', WordPressTestState::$registered_abilities );
	}

	public function test_global_helper_ability_registration_skips_recommendation_abilities_when_feature_options_are_missing(): void {
		FeatureBootstrap::register_global_ability_category();
		FeatureBootstrap::register_global_helper_abilities();

		$this->assertArrayHasKey( 'flavor-agent', WordPressTestState::$registered_ability_categories );
		$this->assertArrayHasKey( 'flavor-agent/introspect-block', WordPressTestState::$registered_abilities );
		$this->assertArrayNotHasKey( 'flavor-agent/recommend-block', WordPressTestState::$registered_abilities );
	}

	public function test_global_helper_ability_registration_skips_recommendation_abilities_when_feature_is_disabled(): void {
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => false,
		];

		FeatureBootstrap::register_global_ability_category();
		FeatureBootstrap::register_global_helper_abilities();

		$this->assertArrayHasKey( 'flavor-agent', WordPressTestState::$registered_ability_categories );
		$this->assertArrayHasKey( 'flavor-agent/introspect-block', WordPressTestState::$registered_abilities );
		$this->assertArrayNotHasKey( 'flavor-agent/recommend-block', WordPressTestState::$registered_abilities );
	}

	public function test_feature_filters_can_force_recommendation_ability_registration(): void {
		\add_filter( 'wpai_features_enabled', static fn (): bool => true );
		\add_filter(
			'wpai_feature_flavor-agent_enabled',
			static fn (): bool => true
		);

		FeatureBootstrap::register_global_helper_abilities();

		$this->assertArrayHasKey( 'flavor-agent/introspect-block', WordPressTestState::$registered_abilities );
		$this->assertArrayHasKey( 'flavor-agent/recommend-block', WordPressTestState::$registered_abilities );
	}
}
