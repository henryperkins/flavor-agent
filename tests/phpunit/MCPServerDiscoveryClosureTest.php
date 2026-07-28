<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration;
use FlavorAgent\MCP\ServerBootstrap;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Closure guard for the dedicated MCP server's tool set.
 *
 * The dedicated server at `/wp-json/mcp/flavor-agent` is a separate endpoint
 * from mcp-adapter's universal default server: a client configures one URL and
 * sees only that server's `tools/list`. So every required input of every tool
 * on this server has to be obtainable *from this server* — otherwise the tool
 * is advertised but uncallable, which is the defect this file exists to stop
 * from returning.
 *
 * Each required input is classified exactly once:
 *
 *   supplier  - produced by another tool on this same server
 *   client    - a value any client can supply cold (free text, a well-known slug)
 *   echo      - returned by an earlier tool on this server and passed straight back
 *   unresolved- genuinely not obtainable; must be explicitly documented below
 *
 * A newly-required input that is not classified fails {@see self::test_every_required_input_is_classified()}.
 * That is the point: adding `'required' => [ 'somethingRef' ]` to an ability
 * schema should force a decision about where a cold client gets it.
 *
 * @see docs/reference/abilities-and-routes.md
 */
final class MCPServerDiscoveryClosureTest extends TestCase {

	/**
	 * Required inputs produced by another tool on this server.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SUPPLIERS = [
		'flavor-agent/recommend-style'             => [
			'scope'        => 'flavor-agent/get-theme-styles',
			'styleContext' => 'flavor-agent/get-theme-styles',
		],
		'flavor-agent/recommend-template'          => [
			'templateRef' => 'flavor-agent/list-templates',
		],
		'flavor-agent/recommend-template-part'     => [
			'templatePartRef' => 'flavor-agent/list-template-parts',
		],
		'flavor-agent/request-style-apply'         => [
			'scope'        => 'flavor-agent/get-theme-styles',
			'styleContext' => 'flavor-agent/get-theme-styles',
		],
		'flavor-agent/request-template-apply'      => [
			'scope' => 'flavor-agent/list-templates',
		],
		'flavor-agent/request-template-part-apply' => [
			'scope' => 'flavor-agent/list-template-parts',
		],
		'flavor-agent/list-activity'               => [
			// global_styles:<id> comes from get-theme-styles scope.scopeKey;
			// the template lanes derive theirs from the list tools' ids.
			'scopeKey' => 'flavor-agent/get-theme-styles',
		],
	];

	/**
	 * Inputs any client can supply from a cold start.
	 *
	 * @var array<string, string>
	 */
	private const CLIENT_SUPPLIABLE = [
		'postType' => 'A well-known core slug ("post" / "page"); no enumeration needed.',
	];

	/**
	 * Inputs echoed back from an earlier tool response on this server.
	 *
	 * @var array<string, string>
	 */
	private const ECHOED = [
		'operations' => 'Returned by the matching recommend-* tool and passed back unchanged.',
		'signatures' => 'Freshness signatures issued by the matching recommend-* tool.',
		'activityId' => 'Returned by the request-*-apply tools.',
	];

	/**
	 * Inputs enforced by the execute callback but absent from the schema
	 * `required` array.
	 *
	 * These are the dangerous ones. A schema-declared requirement at least
	 * fails loudly; `recommend-patterns` without `visiblePatternNames` returns
	 * an empty recommendation set as a **successful** response, so a client
	 * that never learned to call `list-patterns` sees "no recommendations" and
	 * concludes the feature does not work.
	 *
	 * Keyed by tool, then by the input (or `a|b` for a disjunctive gate).
	 *
	 * @var array<string, array<string, array{supplier: string, enforcement: string}>>
	 */
	private const RUNTIME_REQUIRED = [
		'flavor-agent/recommend-patterns'   => [
			'visiblePatternNames' => [
				'supplier'    => 'flavor-agent/list-patterns',
				'enforcement' => 'PatternAbilities::recommend_patterns() returns [ recommendations => [] ] before retrieval when this is null or empty — a silent success, not an error.',
			],
		],
		'flavor-agent/recommend-navigation' => [
			'menuId|navigationMarkup' => [
				'supplier'    => 'flavor-agent/list-template-parts',
				'enforcement' => 'NavigationAbilities::recommend_navigation() returns WP_Error missing_navigation_input unless one of the two is present, before the resolveSignatureOnly early return.',
			],
		],
	];

	/**
	 * Runtime-enforced inputs a client can still satisfy cold.
	 *
	 * Block names are well-known public core identifiers (`core/paragraph`,
	 * `core/group`), unlike a site-specific `templateRef`. Enumerating them via
	 * `list-allowed-blocks` is a convenience, not a precondition, so that
	 * ability is deliberately not on the dedicated server.
	 *
	 * @var array<string, string>
	 */
	private const RUNTIME_CLIENT_SUPPLIABLE = [
		'flavor-agent/recommend-block' => 'selectedBlock.blockName — BlockAbilities returns missing_block_name without it, but core block names need no site-specific lookup.',
	];

	/**
	 * Required inputs that genuinely cannot be obtained from this server.
	 *
	 * Flavor Agent registers no post-listing or post-reading ability, so there
	 * is nothing to add that would close this one — a client has to learn a
	 * post ID from core (the REST posts route or a core ability on the
	 * universal server). Recorded here rather than silently tolerated so the
	 * set cannot grow unnoticed.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const UNRESOLVED = [
		'flavor-agent/recommend-post-blocks'     => [
			'postId' => 'No Flavor Agent ability enumerates posts; the ID comes from core.',
		],
		'flavor-agent/request-post-blocks-apply' => [
			'scope' => 'Carries the same postId as recommend-post-blocks.',
		],
	];

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		Registration::register_abilities();
		Registration::register_recommendation_abilities();
		Registration::register_external_apply_abilities();
	}

	public function test_every_required_input_is_classified(): void {
		$unclassified = [];

		foreach ( ServerBootstrap::server_ability_names() as $tool ) {
			foreach ( $this->required_inputs( $tool ) as $input ) {
				if ( $this->classification( $tool, $input ) === null ) {
					$unclassified[] = $tool . '.' . $input;
				}
			}
		}

		$this->assertSame(
			[],
			$unclassified,
			'A tool on the dedicated MCP server requires an input with no documented source. '
				. 'Either add the supplying ability to ServerBootstrap::DISCOVERY_READ_ABILITIES, or classify '
				. 'the input in this test as client-suppliable, echoed, or explicitly unresolved.'
		);
	}

	public function test_every_named_supplier_is_on_the_same_server(): void {
		$tools = ServerBootstrap::server_ability_names();

		foreach ( self::SUPPLIERS as $tool => $inputs ) {
			foreach ( $inputs as $input => $supplier ) {
				$this->assertContains(
					$supplier,
					$tools,
					\sprintf(
						'%s.%s is supplied by %s, which is not on the dedicated server — the tool is advertised but uncallable.',
						$tool,
						$input,
						$supplier
					)
				);
			}
		}
	}

	public function test_unresolved_inputs_have_not_grown(): void {
		$unresolved = [];

		foreach ( ServerBootstrap::server_ability_names() as $tool ) {
			foreach ( $this->required_inputs( $tool ) as $input ) {
				if ( 'unresolved' === $this->classification( $tool, $input ) ) {
					$unresolved[] = $tool . '.' . $input;
				}
			}
		}

		$this->assertSame(
			[
				'flavor-agent/recommend-post-blocks.postId',
				'flavor-agent/request-post-blocks-apply.scope',
			],
			$unresolved,
			'The set of uncallable-from-cold inputs changed. Growing it needs a deliberate decision.'
		);
	}

	public function test_discovery_reads_are_all_registered_and_read_only(): void {
		foreach ( ServerBootstrap::discovery_read_abilities() as $ability ) {
			$args = WordPressTestState::$registered_abilities[ $ability ] ?? null;

			$this->assertIsArray( $args, \sprintf( '%s is not a registered ability.', $ability ) );
			$this->assertTrue(
				! empty( $args['meta']['readonly'] ) || ! empty( $args['meta']['annotations']['readonly'] ),
				\sprintf( '%s must be read-only to sit on the dedicated server as a discovery aid.', $ability )
			);
			$this->assertTrue(
				! empty( $args['meta']['mcp']['public'] ),
				\sprintf(
					'%s is not meta.mcp.public. Adding a deliberately non-public ability to the dedicated server '
						. 'reverses a recorded decision and needs to be argued, not defaulted into.',
					$ability
				)
			);
		}
	}

	/**
	 * Schema `required` is not the whole contract. Several abilities enforce an
	 * input in the execute callback instead, which this guard would otherwise
	 * miss entirely — including the one case that fails silently.
	 */
	public function test_runtime_enforced_inputs_have_a_supplier_on_the_same_server(): void {
		$tools = ServerBootstrap::server_ability_names();

		foreach ( self::RUNTIME_REQUIRED as $tool => $inputs ) {
			$this->assertContains( $tool, $tools, \sprintf( '%s is not on the dedicated server.', $tool ) );

			foreach ( $inputs as $input => $spec ) {
				$this->assertContains(
					$spec['supplier'],
					$tools,
					\sprintf(
						'%s requires %s at runtime (%s) but its supplier %s is not on the dedicated server.',
						$tool,
						$input,
						$spec['enforcement'],
						$spec['supplier']
					)
				);
			}
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

		if ( isset( self::ECHOED[ $input ] ) ) {
			return 'echo';
		}

		if ( isset( self::CLIENT_SUPPLIABLE[ $input ] ) ) {
			return 'client';
		}

		return null;
	}
}
