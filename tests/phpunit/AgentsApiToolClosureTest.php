<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration as AbilityRegistration;
use FlavorAgent\AgentsAPI\AgentDefinition;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Closure guard for the agent's declared tool profile.
 *
 * The agent runtime hands the model a closed allowlist. That list is not just a
 * privilege boundary — it is the model's entire world. If a recommendation tool
 * is declared but the ability that supplies its required input is not, the tool
 * stays advertised and fails at call time on an input the model has no way to
 * obtain. Nothing fails loudly: registration succeeds, the tool appears, and the
 * lane is quietly dead.
 *
 * That is exactly how `recommend-style` shipped in the first cut of this
 * adapter — `get-theme-styles` was missing from the allowlist, so the style lane
 * could only fabricate a `scope` / `styleContext` or fail with
 * `missing_style_scope`. This file exists so the next one is caught here.
 *
 * Sibling of {@see MCPServerDiscoveryClosureTest}, which asks the same question
 * of the dedicated MCP server's tool set. Two surfaces, one property: a tool you
 * can see is a tool you can call.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentsApiToolClosureTest extends TestCase {

	/**
	 * Required inputs supplied by another ability in the profile.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SUPPLIERS = [
		'flavor-agent/recommend-style'                 => [
			'scope'        => 'flavor-agent/get-theme-styles',
			'styleContext' => 'flavor-agent/get-theme-styles',
		],
		'flavor-agent/recommend-template'              => [
			'templateRef' => 'flavor-agent/list-templates',
		],
		'flavor-agent/recommend-template-part'         => [
			'templatePartRef' => 'flavor-agent/list-template-parts',
		],
		// The read-only preview siblings take the same inputs as the write-side
		// tools they mirror, so they inherit the same suppliers.
		'flavor-agent/preview-recommend-style'         => [
			'scope'        => 'flavor-agent/get-theme-styles',
			'styleContext' => 'flavor-agent/get-theme-styles',
		],
		'flavor-agent/preview-recommend-template'      => [
			'templateRef' => 'flavor-agent/list-templates',
		],
		'flavor-agent/preview-recommend-template-part' => [
			'templatePartRef' => 'flavor-agent/list-template-parts',
		],
		// Activity reads chain: list first to learn an id, then read one row.
		'flavor-agent/get-activity'                    => [
			'activityId' => 'flavor-agent/list-activity',
		],
		'flavor-agent/list-activity'                   => [
			'scopeKey' => 'flavor-agent/get-theme-styles',
		],
	];

	/**
	 * Requirements enforced in the execute callback rather than the schema.
	 *
	 * Schema `required` is not the whole contract, and the silent one is the
	 * dangerous one: `recommend-patterns` without `visiblePatternNames` returns
	 * an empty recommendation set as a *successful* response, so a model that
	 * never learned to call `list-patterns` concludes the surface has nothing
	 * to offer.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const RUNTIME_SUPPLIERS = [
		'flavor-agent/recommend-patterns' => [
			'visiblePatternNames' => 'flavor-agent/list-patterns',
		],
	];

	/**
	 * Runtime-enforced inputs with no supplier anywhere in the profile.
	 *
	 * `recommend-navigation` needs a `wp_navigation` post id or navigation
	 * block markup. `list-template-parts` returns `wp_template_part` metadata —
	 * id, slug, title, area, content — which is a different entity entirely; an
	 * earlier version of this file named it as the supplier and was simply
	 * wrong, which let a presence-only check certify a lane the agent cannot
	 * actually start. Flavor Agent registers nothing that enumerates navigation
	 * menus, so the input has to arrive from the conversation or from core.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const RUNTIME_UNRESOLVED = [
		'flavor-agent/recommend-navigation' => [
			'menuId|navigationMarkup' => 'No Flavor Agent ability enumerates wp_navigation posts or returns navigation block markup.',
		],
	];

	/**
	 * Inputs a model can supply without enumerating anything site-specific.
	 *
	 * @var array<string, string>
	 */
	private const MODEL_SUPPLIABLE = [
		'postType'  => 'A well-known core slug ("post" / "page").',
		'blockName' => 'Core block names are public identifiers, not site data.',
	];

	/**
	 * Inputs no ability in this profile can produce.
	 *
	 * Flavor Agent registers no post-listing ability, so a post ID has to come
	 * from the conversation or from core. Recorded rather than tolerated so the
	 * set cannot grow unnoticed.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const UNRESOLVED = [
		'flavor-agent/recommend-post-blocks'         => [
			'postId' => 'No Flavor Agent ability enumerates posts.',
		],
		'flavor-agent/preview-recommend-post-blocks' => [
			'postId' => 'Same gap as its write-side sibling.',
		],
	];

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		AbilityRegistration::register_abilities();
		AbilityRegistration::register_recommendation_abilities();
		AbilityRegistration::register_preview_recommendation_abilities();
		AbilityRegistration::register_external_apply_abilities();
	}

	public function test_every_required_input_of_every_declared_tool_is_classified(): void {
		$tools        = AgentDefinition::tool_allowlist();
		$unclassified = [];

		foreach ( $tools as $tool ) {
			foreach ( $this->required_inputs( $tool ) as $input ) {
				if ( null === $this->classification( $tool, $input ) ) {
					$unclassified[] = $tool . '.' . $input;
				}
			}
		}

		$this->assertSame(
			[],
			$unclassified,
			'A tool in the agent profile requires an input with no documented source. Either add the '
				. 'supplying ability to AgentDefinition::READ_ABILITIES, or classify the input here as '
				. 'model-suppliable or explicitly unresolved.'
		);
	}

	public function test_every_named_supplier_is_in_the_agent_profile(): void {
		$tools = AgentDefinition::tool_allowlist();

		foreach ( self::SUPPLIERS as $tool => $inputs ) {
			if ( ! \in_array( $tool, $tools, true ) ) {
				continue;
			}

			foreach ( $inputs as $input => $supplier ) {
				$this->assertContains(
					$supplier,
					$tools,
					\sprintf(
						'%s declares %s required and %s supplies it, but %s is not in the agent profile — '
							. 'the tool is advertised and uncallable.',
						$tool,
						$input,
						$supplier,
						$supplier
					)
				);
			}
		}
	}

	/**
	 * The regression this file was written for.
	 */
	public function test_the_style_lane_can_obtain_its_own_required_context(): void {
		$tools = AgentDefinition::tool_allowlist();

		$this->assertContains( 'flavor-agent/recommend-style', $tools );
		$this->assertContains(
			'flavor-agent/get-theme-styles',
			$tools,
			'recommend-style requires scope + styleContext; without get-theme-styles the model can only '
				. 'fabricate them or fail with missing_style_scope.'
		);
	}

	public function test_runtime_enforced_inputs_have_a_supplier_in_the_profile(): void {
		$tools = AgentDefinition::tool_allowlist();

		foreach ( self::RUNTIME_SUPPLIERS as $tool => $inputs ) {
			if ( ! \in_array( $tool, $tools, true ) ) {
				continue;
			}

			foreach ( $inputs as $input => $supplier ) {
				$this->assertContains(
					$supplier,
					$tools,
					\sprintf( '%s enforces %s at runtime but %s is not in the profile.', $tool, $input, $supplier )
				);
			}
		}
	}

	/**
	 * A named supplier has to actually produce the input. Asserting only that
	 * some ability is present in the profile certifies nothing — that is how
	 * `list-template-parts` came to be recorded as the source of a navigation
	 * menu id.
	 */
	public function test_runtime_unresolved_inputs_have_not_grown(): void {
		$this->assertSame(
			[ 'flavor-agent/recommend-navigation.menuId|navigationMarkup' ],
			\array_merge(
				...\array_map(
					static fn( string $tool, array $inputs ): array => \array_map(
						static fn( string $input ): string => $tool . '.' . $input,
						\array_keys( $inputs )
					),
					\array_keys( self::RUNTIME_UNRESOLVED ),
					\array_values( self::RUNTIME_UNRESOLVED )
				)
			),
			'The set of runtime-enforced inputs the agent cannot obtain changed.'
		);

		// It stays in the profile deliberately: a caller that already holds
		// markup (the editor, or a conversation that pasted it) can still use
		// the lane. What it cannot do is bootstrap one from a cold start.
		$this->assertContains( 'flavor-agent/recommend-navigation', AgentDefinition::tool_allowlist() );
	}

	public function test_unresolved_inputs_have_not_grown(): void {
		$unresolved = [];

		foreach ( AgentDefinition::tool_allowlist() as $tool ) {
			foreach ( $this->required_inputs( $tool ) as $input ) {
				if ( 'unresolved' === $this->classification( $tool, $input ) ) {
					$unresolved[] = $tool . '.' . $input;
				}
			}
		}

		$this->assertSame(
			[
				'flavor-agent/preview-recommend-post-blocks.postId',
				'flavor-agent/recommend-post-blocks.postId',
			],
			$unresolved,
			'The set of inputs the agent cannot obtain changed. Growing it needs a deliberate decision.'
		);
	}

	/**
	 * Every supplier pulled in for closure must still be a read. Closing a gap
	 * by widening the profile with something that writes would trade a dead
	 * lane for a broken boundary.
	 */
	public function test_suppliers_are_read_only(): void {
		$suppliers = \array_unique(
			\array_merge(
				\array_merge( ...\array_values( \array_map( '\array_values', self::SUPPLIERS ) ) ),
				\array_merge( ...\array_values( \array_map( '\array_values', self::RUNTIME_SUPPLIERS ) ) )
			)
		);

		foreach ( $suppliers as $supplier ) {
			$args = WordPressTestState::$registered_abilities[ $supplier ] ?? null;

			$this->assertIsArray( $args, \sprintf( '%s is not registered.', $supplier ) );
			$this->assertTrue(
				! empty( $args['meta']['readonly'] ) || ! empty( $args['meta']['annotations']['readonly'] ),
				\sprintf( '%s is pulled into the profile to close a gap, so it has to be read-only.', $supplier )
			);
		}
	}

	/**
	 * @return array<int, string>
	 */
	private function required_inputs( string $tool ): array {
		$args     = WordPressTestState::$registered_abilities[ $tool ] ?? [];
		$schema   = \is_array( $args['input_schema'] ?? null ) ? $args['input_schema'] : [];
		$required = $schema['required'] ?? [];

		return \is_array( $required ) ? \array_values( \array_filter( $required, 'is_string' ) ) : [];
	}

	private function classification( string $tool, string $input ): ?string {
		if ( isset( self::SUPPLIERS[ $tool ][ $input ] ) ) {
			return 'supplier';
		}

		if ( isset( self::UNRESOLVED[ $tool ][ $input ] ) ) {
			return 'unresolved';
		}

		if ( isset( self::MODEL_SUPPLIABLE[ $input ] ) ) {
			return 'model';
		}

		return null;
	}
}
