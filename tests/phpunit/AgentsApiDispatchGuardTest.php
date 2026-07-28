<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration as AbilityRegistration;
use FlavorAgent\AgentsAPI\DispatchGuard;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * The site-wide half of the dispatch boundary.
 *
 * {@see AgentsApiRegistrationTest} proves the `flavor-agent` agent cannot see
 * `agents/ability-call`. That deny binds one agent definition. This binds the
 * ability, so a run driven by any other agent on the site is covered too.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentsApiDispatchGuardTest extends TestCase {

	private const UNDO = 'flavor-agent/undo-activity';

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		DispatchGuard::reset();

		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		AbilityRegistration::register_abilities();
		AbilityRegistration::register_recommendation_abilities();
		AbilityRegistration::register_external_apply_abilities();
	}

	protected function tearDown(): void {
		DispatchGuard::reset();

		parent::tearDown();
	}

	public function test_refuses_generic_dispatch_of_the_destructive_undo_ability(): void {
		$this->assertFalse(
			DispatchGuard::filter_dispatch_permission( true, [ 'name' => self::UNDO ] ),
			'undo-activity executes on the live site with no second approval; a model must not reach it by name.'
		);
	}

	/**
	 * The four request-apply abilities are deliberately NOT guarded. Queuing a
	 * pending row is the governed loop working as designed — an administrator
	 * still decides it in Settings > AI Activity — and they are the shipped
	 * external-apply surface. Guarding them would break a released feature to
	 * prevent something that is not a mutation.
	 */
	public function test_leaves_the_review_gated_apply_requests_reachable(): void {
		foreach (
			[
				'flavor-agent/request-style-apply',
				'flavor-agent/request-template-apply',
				'flavor-agent/request-template-part-apply',
				'flavor-agent/request-post-blocks-apply',
			] as $ability
		) {
			$this->assertTrue(
				DispatchGuard::filter_dispatch_permission( true, [ 'name' => $ability ] ),
				$ability . ' queues a reviewable row; denying it would break the external-apply lane.'
			);
		}
	}

	public function test_leaves_reads_reachable(): void {
		foreach ( [ 'flavor-agent/list-templates', 'flavor-agent/get-activity', 'flavor-agent/check-status' ] as $ability ) {
			$this->assertTrue( DispatchGuard::filter_dispatch_permission( true, [ 'name' => $ability ] ) );
		}
	}

	public function test_does_not_reach_beyond_this_plugins_abilities(): void {
		\wp_register_ability(
			'some-other-plugin/delete-everything',
			[
				'label' => 'Not ours',
				'meta'  => [ 'annotations' => [ 'destructive' => true ] ],
			]
		);

		foreach ( [ 'core/edit-post', 'some-other-plugin/delete-everything', 'agents/ability-search' ] as $ability ) {
			$this->assertTrue(
				DispatchGuard::filter_dispatch_permission( true, [ 'name' => $ability ] ),
				'Flavor Agent has no standing to deny another plugin its own dispatch, destructive or not.'
			);
		}
	}

	/**
	 * The guard narrows; it never grants. An upstream `manage_options` failure,
	 * or a third-party filter that already said no, has to survive this callback.
	 */
	public function test_never_widens_an_existing_denial(): void {
		foreach ( [ false, null, 0, '' ] as $denied ) {
			$this->assertFalse(
				DispatchGuard::filter_dispatch_permission( $denied, [ 'name' => 'flavor-agent/list-templates' ] ),
				'A prior denial of a harmless read must stand.'
			);
		}
	}

	/**
	 * The static derivation only sees abilities registered through an
	 * enumerable class map. The plain abilities are registered inline, so a
	 * future destructive one would be invisible to it — live annotation
	 * introspection is what covers that case.
	 */
	public function test_guards_a_destructive_ability_the_static_derivation_cannot_see(): void {
		$name = 'flavor-agent/hypothetical-destroyer';

		$this->assertNotContains( $name, DispatchGuard::guarded_abilities() );

		\wp_register_ability(
			$name,
			[
				'label' => 'Hypothetical',
				'meta'  => [ 'annotations' => [ 'destructive' => true ] ],
			]
		);

		$this->assertTrue( DispatchGuard::is_guarded( $name ) );
		$this->assertFalse( DispatchGuard::filter_dispatch_permission( true, [ 'name' => $name ] ) );
	}

	public function test_an_unregistered_name_is_not_guarded(): void {
		$this->assertTrue(
			DispatchGuard::filter_dispatch_permission( true, [ 'name' => 'flavor-agent/not-a-real-ability' ] ),
			'Dispatch already fails an unknown name with ability_not_found; there is nothing to refuse.'
		);
	}

	public function test_missing_or_malformed_input_is_not_guarded(): void {
		$this->assertTrue( DispatchGuard::filter_dispatch_permission( true, [] ) );
		$this->assertTrue( DispatchGuard::filter_dispatch_permission( true, null ) );
		$this->assertTrue( DispatchGuard::filter_dispatch_permission( true, [ 'name' => 123 ] ) );
		$this->assertTrue( DispatchGuard::filter_dispatch_permission( true, [ 'name' => '   ' ] ) );
	}

	public function test_the_escape_hatch_requires_a_strict_true(): void {
		\add_filter( DispatchGuard::DECISION_FILTER, static fn(): mixed => 1 );

		$this->assertFalse(
			DispatchGuard::filter_dispatch_permission( true, [ 'name' => self::UNDO ] ),
			'A truthy-but-not-true return must not re-open an unreviewed mutation.'
		);
	}

	public function test_the_escape_hatch_can_deliberately_re_open_one_ability(): void {
		\add_filter(
			DispatchGuard::DECISION_FILTER,
			static fn( bool $allowed, string $name ): bool => self::UNDO === $name ? true : $allowed,
			10,
			2
		);

		$this->assertTrue( DispatchGuard::filter_dispatch_permission( true, [ 'name' => self::UNDO ] ) );
	}

	public function test_a_refusal_is_observable(): void {
		DispatchGuard::filter_dispatch_permission( true, [ 'name' => self::UNDO ] );

		$this->assertSame( 1, WordPressTestState::$do_action_counts[ DispatchGuard::DENIED_ACTION ] ?? 0 );
	}

	public function test_register_attaches_to_the_upstream_permission_filter(): void {
		DispatchGuard::register();

		$this->assertFalse(
			(bool) \apply_filters( DispatchGuard::PERMISSION_FILTER, true, [ 'name' => self::UNDO ] ),
			'The guard has to be reachable through the hook upstream actually fires.'
		);
	}

	/**
	 * Registration is deliberately unconditional: a site running an Agents API
	 * version this plugin does not otherwise integrate with still has
	 * `agents/ability-call`. Hardening that declines to apply itself on an
	 * unsupported runtime protects the wrong thing.
	 */
	public function test_registers_without_a_supported_agents_api_runtime(): void {
		$this->assertFalse(
			\FlavorAgent\AgentsAPI\Compatibility::supported(),
			'Precondition: no Agents API runtime is present in this process.'
		);

		DispatchGuard::register();

		$this->assertFalse( (bool) \apply_filters( DispatchGuard::PERMISSION_FILTER, true, [ 'name' => self::UNDO ] ) );
	}

	/**
	 * Closure guard. Every Flavor Agent ability that declares itself destructive
	 * must be covered, whichever meta source declares it. A new destructive
	 * ability should fail this test rather than ship reachable by reflection.
	 */
	public function test_every_destructive_ability_is_guarded(): void {
		$unguarded = [];

		foreach ( $this->declared_annotations() as $ability => $annotations ) {
			if ( empty( $annotations['destructive'] ) ) {
				continue;
			}

			if ( ! DispatchGuard::is_guarded( $ability ) ) {
				$unguarded[] = $ability;
			}
		}

		$this->assertSame(
			[],
			$unguarded,
			'A Flavor Agent ability declares itself destructive but stays reachable through agents/ability-call.'
		);
	}

	public function test_the_guarded_set_has_not_silently_grown_or_shrunk(): void {
		$this->assertSame( [ self::UNDO ], DispatchGuard::guarded_abilities() );
	}

	/**
	 * Declared annotations for every Flavor Agent ability, from all four meta
	 * sources: registration args for the inline abilities, and the three static
	 * meta helpers for the class-backed ones.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function declared_annotations(): array {
		$declared = [];

		foreach ( WordPressTestState::$registered_abilities as $ability => $args ) {
			$annotations = $args['meta']['annotations'] ?? null;

			if ( \is_array( $annotations ) ) {
				$declared[ (string) $ability ] = $annotations;
			}
		}

		foreach ( \array_keys( AbilityRegistration::external_apply_ability_classes() ) as $ability ) {
			$declared[ (string) $ability ] = AbilityRegistration::external_apply_meta( (string) $ability )['annotations'] ?? [];
		}

		foreach ( \array_keys( AbilityRegistration::recommendation_ability_classes() ) as $ability ) {
			$declared[ (string) $ability ] = AbilityRegistration::recommendation_meta()['annotations'] ?? [];
		}

		foreach ( \array_keys( AbilityRegistration::preview_recommendation_ability_classes() ) as $ability ) {
			$declared[ (string) $ability ] = AbilityRegistration::preview_recommendation_meta()['annotations'] ?? [];
		}

		return $declared;
	}
}
