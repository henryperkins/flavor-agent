<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration as AbilityRegistration;
use FlavorAgent\AgentsAPI\AgentDefinition;
use FlavorAgent\AgentsAPI\Compatibility;
use FlavorAgent\AgentsAPI\Registration;
use FlavorAgent\AI\FeatureBootstrap;
use FlavorAgent\Tests\Support\AgentsApiTestState;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 registration: one optional agent, a least-privilege tool profile, and
 * a mutation boundary the definition cannot cross.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentsApiRegistrationTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once __DIR__ . '/support/agents-api-stubs.php';
	}

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		AgentsApiTestState::reset();

		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		$this->pin_supported_version();
		$this->register_flavor_agent_abilities();
	}

	protected function tearDown(): void {
		AgentsApiTestState::reset();

		parent::tearDown();
	}

	public function test_registers_one_agent_inside_the_upstream_registration_window(): void {
		$this->fire_registration_hook();

		$this->assertArrayHasKey( AgentDefinition::SLUG, AgentsApiTestState::$registered_agents );
		$this->assertCount( 1, AgentsApiTestState::$registered_agents );
		$this->assertSame(
			1,
			WordPressTestState::$do_action_counts[ Registration::REGISTERED_ACTION ] ?? 0
		);
	}

	public function test_does_not_register_outside_the_registration_window(): void {
		// Upstream `_doing_it_wrong()`s and refuses definitions registered from
		// another hook, so calling `register()` directly must be a no-op rather
		// than a warning plus a silently-dropped agent.
		Registration::register();

		$this->assertSame( [], AgentsApiTestState::$registered_agents );
		$this->assertFalse( Registration::should_register() );
	}

	public function test_carries_source_provenance(): void {
		$this->fire_registration_hook();

		$meta = AgentsApiTestState::$registered_agents[ AgentDefinition::SLUG ]['meta'];

		$this->assertSame( 'flavor-agent/flavor-agent.php', $meta['source_plugin'] );
		$this->assertSame( 'bundled-agent', $meta['source_type'] );
		$this->assertSame( 'flavor-agent', $meta['source_package'] );
		$this->assertNotSame( '', $meta['source_version'] );
	}

	public function test_declares_only_the_intended_read_and_recommend_allowlist(): void {
		$this->fire_registration_hook();

		$tools = $this->registered_config()['enabled_tools'];

		$this->assertSame( AgentDefinition::tool_allowlist(), $tools );

		foreach ( \array_keys( AbilityRegistration::recommendation_ability_classes() ) as $ability ) {
			$this->assertContains( $ability, $tools );
		}

		foreach ( \array_keys( AbilityRegistration::preview_recommendation_ability_classes() ) as $ability ) {
			$this->assertContains( $ability, $tools );
		}

		$this->assertContains( 'flavor-agent/check-status', $tools );
		$this->assertContains( 'flavor-agent/get-activity', $tools );
		$this->assertContains( 'flavor-agent/list-activity', $tools );
	}

	public function test_declares_no_mutation_or_apply_tool(): void {
		$this->fire_registration_hook();

		$config = $this->registered_config();

		foreach ( AgentDefinition::forbidden_abilities() as $forbidden ) {
			$this->assertNotContains( $forbidden, $config['enabled_tools'] );
			$this->assertNotContains( $forbidden, $config['tool_policy']['tools'] );
			$this->assertContains( $forbidden, $config['tool_policy']['deny'] );
		}
	}

	public function test_denies_the_upstream_generic_dispatch_meta_abilities(): void {
		$this->fire_registration_hook();

		$config = $this->registered_config();

		foreach ( AgentDefinition::denied_runtime_abilities() as $dispatch_tool ) {
			$this->assertNotContains( $dispatch_tool, $config['enabled_tools'] );
			$this->assertContains(
				$dispatch_tool,
				$config['tool_policy']['deny'],
				'agents/ability-call reaches any ability by name, so allow-mode alone does not bound this agent.'
			);
		}
	}

	public function test_a_site_filter_cannot_add_a_dispatch_meta_ability(): void {
		\add_filter(
			AgentDefinition::TOOL_ALLOWLIST_FILTER,
			static function ( array $allowlist ): array {
				$allowlist[] = 'agents/ability-call';

				return $allowlist;
			}
		);

		$this->fire_registration_hook();

		$this->assertNotContains( 'agents/ability-call', $this->registered_config()['enabled_tools'] );
	}

	public function test_a_site_filter_cannot_add_a_mutation_tool(): void {
		\add_filter(
			AgentDefinition::TOOL_ALLOWLIST_FILTER,
			static function ( array $allowlist ): array {
				$allowlist[] = 'flavor-agent/request-style-apply';
				$allowlist[] = 'flavor-agent/undo-activity';

				return $allowlist;
			}
		);

		$this->fire_registration_hook();

		$config = $this->registered_config();

		$this->assertNotContains( 'flavor-agent/request-style-apply', $config['enabled_tools'] );
		$this->assertNotContains( 'flavor-agent/undo-activity', $config['enabled_tools'] );
	}

	public function test_drops_allowlisted_abilities_that_are_not_registered(): void {
		\add_filter(
			AgentDefinition::TOOL_ALLOWLIST_FILTER,
			static function ( array $allowlist ): array {
				$allowlist[] = 'flavor-agent/not-a-real-ability';

				return $allowlist;
			}
		);

		$this->fire_registration_hook();

		$this->assertNotContains(
			'flavor-agent/not-a-real-ability',
			$this->registered_config()['enabled_tools'],
			'An unregistered name would still be declared as a tool upstream and fail only when the model called it.'
		);
	}

	public function test_bounds_the_conversation_and_discovery_budgets(): void {
		$this->fire_registration_hook();

		$config = $this->registered_config();

		$this->assertSame( AgentDefinition::MAX_TURNS, $config['max_turns'] );
		$this->assertLessThan( 12, $config['max_turns'], 'Must stay below the upstream default turn budget.' );
		$this->assertSame( 'allow', $config['tool_policy']['mode'] );
		$this->assertNotEmpty( $config['tool_call_rules'] );

		foreach ( $config['tool_call_rules'] as $rule ) {
			$this->assertSame( AgentDefinition::MAX_DISCOVERY_CALLS, $rule['max_calls'] );
			$this->assertNotEmpty( $rule['require_one_of'] );
			$this->assertFalse(
				$rule['gate_completion'],
				'A read-only answer is a legitimate run; only the read rate is bounded.'
			);
		}
	}

	public function test_instructions_state_the_mutation_boundary(): void {
		$this->fire_registration_hook();

		$instructions = $this->registered_config()['instructions'];

		$this->assertStringContainsString( 'You do not change the site.', $instructions );
		$this->assertStringContainsString( 'Settings > AI Activity', $instructions );
	}

	public function test_does_not_register_when_the_feature_gate_is_off(): void {
		WordPressTestState::$options['wpai_feature_flavor-agent_enabled'] = false;

		$this->assertFalse( FeatureBootstrap::recommendation_feature_enabled() );

		$this->fire_registration_hook();

		$this->assertSame( [], AgentsApiTestState::$registered_agents );
	}

	public function test_does_not_register_when_the_runtime_version_is_unsupported(): void {
		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
		$this->register_flavor_agent_abilities();
		\add_filter( Compatibility::VERSION_FILTER, static fn(): string => '0.6.1' );

		$this->fire_registration_hook();

		$this->assertSame( [], AgentsApiTestState::$registered_agents );
	}

	public function test_does_not_register_without_any_resolvable_ability(): void {
		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
		$this->pin_supported_version();

		// No abilities registered: the agent would have nothing to mediate.
		$this->fire_registration_hook();

		$this->assertSame( [], AgentsApiTestState::$registered_agents );
		$this->assertSame(
			1,
			WordPressTestState::$do_action_counts[ Registration::FAILED_ACTION ] ?? 0
		);
	}

	public function test_reports_a_runtime_rejection_instead_of_failing_silently(): void {
		AgentsApiTestState::$reject_registration = true;

		$this->fire_registration_hook();

		$this->assertSame( [], AgentsApiTestState::$registered_agents );
		$this->assertSame(
			1,
			WordPressTestState::$do_action_counts[ Registration::FAILED_ACTION ] ?? 0
		);
		$this->assertSame(
			0,
			WordPressTestState::$do_action_counts[ Registration::REGISTERED_ACTION ] ?? 0
		);
	}

	public function test_absorbs_a_throwing_runtime_instead_of_fataling_init(): void {
		AgentsApiTestState::$throw_on_registration = true;

		// `wp_agents_api_init` fires from `init`: an escaping exception here
		// would take the whole site down, not just the adapter.
		$this->fire_registration_hook();

		$this->assertSame( [], AgentsApiTestState::$registered_agents );
		$this->assertSame(
			1,
			WordPressTestState::$do_action_counts[ Registration::FAILED_ACTION ] ?? 0
		);
		$this->assertSame(
			0,
			WordPressTestState::$do_action_counts[ Registration::REGISTERED_ACTION ] ?? 0
		);
	}

	private function fire_registration_hook(): void {
		\add_filter( Registration::FAILURE_LOGGING_FILTER, static fn(): bool => false );
		\add_action( Compatibility::REGISTRATION_HOOK, [ Registration::class, 'register' ] );
		\do_action( Compatibility::REGISTRATION_HOOK );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function registered_config(): array {
		$this->assertArrayHasKey( AgentDefinition::SLUG, AgentsApiTestState::$registered_agents );

		return AgentsApiTestState::$registered_agents[ AgentDefinition::SLUG ]['default_config'];
	}

	private function pin_supported_version(): void {
		\add_filter(
			Compatibility::VERSION_FILTER,
			static fn(): string => Compatibility::MINIMUM_VERSION
		);
	}

	private function register_flavor_agent_abilities(): void {
		AbilityRegistration::register_abilities();
		AbilityRegistration::register_recommendation_abilities();
		AbilityRegistration::register_external_apply_abilities();
	}
}
