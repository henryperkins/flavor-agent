<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration as AbilityRegistration;
use FlavorAgent\AgentsAPI\AgentDefinition;
use FlavorAgent\AgentsAPI\Compatibility;
use FlavorAgent\AgentsAPI\DispatchGuard;
use FlavorAgent\AgentsAPI\Registration;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests against the **real** Automattic Agents API source.
 *
 * The rest of the Agents API suite runs against stubs in
 * `tests/phpunit/support/agents-api-stubs.php`. Those stubs were written from
 * a reading of the upstream source, so they prove the adapter's own logic but
 * cannot prove that reading was correct — a misread would be encoded
 * identically on both sides and the suite would stay green. This file closes
 * that circularity by loading upstream's actual registry, definition object,
 * and tool-policy resolver and driving them with the definition the plugin
 * really ships.
 *
 * Point it at a checkout of https://github.com/Automattic/agents-api at the
 * pinned tag and run:
 *
 *     git clone --branch v0.7.0 --depth 1 \
 *         https://github.com/Automattic/agents-api.git /tmp/agents-api
 *     FLAVOR_AGENT_AGENTS_API_PATH=/tmp/agents-api \
 *         vendor/bin/phpunit --filter AgentsApiUpstreamContractTest
 *
 * Without the environment variable every test skips, so the suite stays
 * hermetic and offline by default. A skip is not a pass: treat this file as
 * required evidence when raising {@see Compatibility::MINIMUM_VERSION}.
 *
 * Every test is process-isolated because it loads real upstream classes whose
 * names collide with the stubs the other Agents API suites define.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentsApiUpstreamContractTest extends TestCase {

	/**
	 * Upstream files the adapter's contract actually depends on.
	 *
	 * @var array<int, string>
	 */
	private const UPSTREAM_FILES = [
		'src/Registry/class-wp-agent-runtime-overrides.php',
		'src/Registry/class-wp-agent.php',
		'src/Registry/class-wp-agents-registry.php',
		'src/Registry/register-agents.php',
		'src/Tools/class-wp-agent-tool-policy-filter.php',
		'src/Tools/class-wp-agent-tool-access-policy.php',
		'src/Tools/class-wp-agent-tool-policy.php',
	];

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upstream_registry_accepts_the_shipped_agent_definition(): void {
		$this->boot_upstream();

		$agent = \wp_get_agent( AgentDefinition::SLUG );

		$this->assertInstanceOf(
			'WP_Agent',
			$agent,
			'Upstream refused the definition. Re-read src/Registry/class-wp-agent.php against AgentDefinition::registration_args().'
		);
		$this->assertSame( AgentDefinition::SLUG, $agent->get_slug() );
		$this->assertSame( AgentDefinition::provenance_meta(), $agent->get_meta() );
	}

	/**
	 * Upstream reads the agent's tool list, prompt, and budgets out of
	 * `default_config`. A silently renamed key would leave the agent
	 * registered but tool-less, which no stub-backed test can detect.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_default_config_round_trips_through_the_upstream_definition_object(): void {
		$this->boot_upstream();

		$config = \wp_get_agent( AgentDefinition::SLUG )->get_default_config();

		$this->assertNotEmpty( $config['enabled_tools'] );
		$this->assertContains( 'flavor-agent/recommend-block', $config['enabled_tools'] );
		$this->assertSame( AgentDefinition::MAX_TURNS, $config['max_turns'] );
		$this->assertSame( 'allow', $config['tool_policy']['mode'] );
		$this->assertNotEmpty( $config['tool_call_rules'] );
		$this->assertStringContainsString( 'You do not change the site.', $config['instructions'] );
	}

	/**
	 * The load-bearing assertion of the whole integration.
	 *
	 * Declarations are handed to upstream's real resolver with the mutation
	 * abilities deliberately injected, standing in for any path that could put
	 * them in front of the model. Upstream's own policy must strip all five.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upstream_tool_policy_denies_every_mutation_ability(): void {
		$this->boot_upstream();

		$config    = \wp_get_agent( AgentDefinition::SLUG )->get_default_config();
		$forbidden = AgentDefinition::forbidden_abilities();

		$declarations = [];
		foreach ( \array_merge( $config['enabled_tools'], $forbidden ) as $name ) {
			$declarations[ $name ] = [
				'name'        => $name,
				'description' => $name,
				'parameters'  => [],
				'ability'     => $name,
			];
		}

		$visible = ( new \WP_Agent_Tool_Policy() )->resolve( $declarations, [ 'agent_config' => $config ] );

		foreach ( $forbidden as $ability ) {
			$this->assertArrayNotHasKey(
				$ability,
				$visible,
				\sprintf( 'Upstream tool policy left %s visible to the model.', $ability )
			);
		}

		$this->assertSame(
			$config['enabled_tools'],
			\array_keys( $visible ),
			'The visible tool set must be exactly the declared allowlist.'
		);
	}

	/**
	 * The deny list is the backstop, so test it where it is the only thing left.
	 *
	 * In the shipped configuration a forbidden ability never reaches the
	 * allowlist — {@see Registration::resolve_tools()} strips it — so the
	 * allow-mode filter alone would hide it and a deleted `deny` list would go
	 * unnoticed. This simulates that first layer failing: a contaminated tool
	 * list, where only `deny` (applied last, unconditionally, by upstream) can
	 * still keep the ability away from the model.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deny_list_survives_a_contaminated_allowlist(): void {
		$this->boot_upstream();

		$shipped   = \wp_get_agent( AgentDefinition::SLUG )->get_default_config();
		$forbidden = AgentDefinition::forbidden_abilities();

		foreach ( $forbidden as $leaked ) {
			$contaminated = \array_merge( $shipped['enabled_tools'], [ $leaked ] );

			$config                         = $shipped;
			$config['enabled_tools']        = $contaminated;
			$config['tool_policy']['tools'] = $contaminated;

			$declarations = [];
			foreach ( $contaminated as $name ) {
				$declarations[ $name ] = [
					'name'        => $name,
					'description' => $name,
					'parameters'  => [],
					'ability'     => $name,
				];
			}

			$visible = ( new \WP_Agent_Tool_Policy() )->resolve( $declarations, [ 'agent_config' => $config ] );

			$this->assertArrayNotHasKey(
				$leaked,
				$visible,
				\sprintf(
					'%s reached the model through a contaminated allowlist: tool_policy.deny is no longer a backstop.',
					$leaked
				)
			);
		}
	}

	/**
	 * `agents/ability-call` invokes any registered ability by name with no
	 * target allowlist, so a run that can see it reaches every ability on the
	 * site regardless of this agent's `enabled_tools`.
	 *
	 * Allow-mode does not stop it: a tool marked `mandatory` by any policy
	 * provider is preserved through the allow filter. Only `deny`, applied last
	 * and unconditionally, does. This drives the real resolver with a
	 * `mandatory` dispatch tool injected to prove that.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mandatory_dispatch_meta_ability_cannot_survive_the_deny_list(): void {
		$this->boot_upstream();

		$config = \wp_get_agent( AgentDefinition::SLUG )->get_default_config();

		// Hardcoded, NOT read from AgentDefinition::denied_runtime_abilities().
		// Iterating the constant would make this test delete its own coverage:
		// removing an entry from the deny list would also remove it from the
		// loop, and the test would still pass.
		foreach ( [ 'agents/ability-call', 'agents/ability-search' ] as $dispatch_tool ) {
			$declarations = [
				'flavor-agent/recommend-block' => [
					'name'        => 'flavor-agent/recommend-block',
					'description' => 'x',
					'parameters'  => [],
				],
				$dispatch_tool                 => [
					'name'        => $dispatch_tool,
					'description' => 'x',
					'parameters'  => [],
					// Any policy provider can set this; it survives allow-mode.
					'mandatory'   => true,
				],
			];

			$visible = ( new \WP_Agent_Tool_Policy() )->resolve( $declarations, [ 'agent_config' => $config ] );

			$this->assertArrayNotHasKey(
				$dispatch_tool,
				$visible,
				\sprintf(
					'%s survived as a mandatory tool. The agent can now reach every ability on the site, including the apply and undo abilities Phase 1 excludes.',
					$dispatch_tool
				)
			);
		}
	}

	/**
	 * The deny list names upstream abilities by string literal. Pin those
	 * literals to upstream's own constants so a rename is caught when the
	 * pinned version moves rather than silently disarming the deny at runtime.
	 */
	public function test_denied_dispatch_ability_names_still_match_upstream(): void {
		$path = $this->upstream_path();
		$file = $path . '/src/Tools/register-agent-ability-meta-abilities.php';

		$this->assertFileExists(
			$file,
			'Upstream moved the dispatch meta-abilities; re-derive AgentDefinition::DENIED_RUNTIME_ABILITIES.'
		);

		$contents = (string) \file_get_contents( $file );

		foreach (
			[
				'AGENTS_ABILITY_CALL_ABILITY'   => 'agents/ability-call',
				'AGENTS_ABILITY_SEARCH_ABILITY' => 'agents/ability-search',
			] as $constant => $expected
		) {
			$this->assertSame(
				1,
				\preg_match( "/const\s+{$constant}\s*=\s*'([^']+)'/", $contents, $match ),
				\sprintf( 'Upstream no longer defines %s.', $constant )
			);
			$this->assertSame(
				$expected,
				$match[1],
				\sprintf( 'Upstream renamed %s; the deny list literal no longer matches and is silently disarmed.', $constant )
			);
		}

		$this->assertSame(
			[ 'agents/ability-call', 'agents/ability-search' ],
			AgentDefinition::denied_runtime_abilities()
		);
	}

	/**
	 * {@see DispatchGuard} hangs entirely on one upstream filter name and one
	 * input key. Neither is a documented API, so a rename would disarm the
	 * guard in total silence — the filter would simply never fire and every
	 * unit test would stay green. Pin both to the real source.
	 */
	public function test_the_dispatch_permission_seam_still_exists_upstream(): void {
		$contents = $this->meta_abilities_source();

		$this->assertSame(
			1,
			\preg_match(
				'/function\s+agents_ability_call_permission\(\s*array\s+\$input\s*\)[^{]*\{\s*return\s*\(bool\)\s*apply_filters\(\s*\'([^\']+)\'/',
				$contents,
				$match
			),
			'Upstream restructured the ability-call permission gate; DispatchGuard has no hook left.'
		);

		$this->assertSame(
			DispatchGuard::PERMISSION_FILTER,
			$match[1],
			'Upstream renamed the ability-call permission filter; the guard is attached to a hook that never fires.'
		);

		$this->assertSame(
			1,
			\preg_match( '/\$name\s*=\s*is_string\(\s*\$input\[\'name\'\]/', $contents ),
			'The target ability no longer arrives as $input[\'name\']; the guard reads the wrong key and guards nothing.'
		);
	}

	/**
	 * The guard exists because generic dispatch has no target allowlist. If
	 * upstream grows one, this fails and the guard should be re-argued rather
	 * than kept out of habit.
	 */
	public function test_generic_dispatch_still_has_no_target_allowlist(): void {
		$contents = $this->meta_abilities_source();

		$this->assertSame(
			1,
			\preg_match( '/function\s+agents_ability_call\(\s*array\s+\$input\s*\)[^}]*?\{(.*?)\n\}/s', $contents, $match ),
			'Could not isolate agents_ability_call() to re-check its guards.'
		);

		$body = $match[1];

		\preg_match_all( '/return new \\\\WP_Error\(\s*\'([^\']+)\'/', $body, $codes );

		// Exactly three rejections, and only one of them looks at the target:
		// `missing_name` is input validation, `not_found` is post-dispatch, and
		// `recursion` refuses one single name — this ability. Nothing consults
		// an allowlist, which is why the guard exists.
		$this->assertSame(
			[
				'agents_ability_call_missing_name',
				'agents_ability_call_recursion',
				'agents_ability_call_not_found',
			],
			$codes[1],
			'agents_ability_call() changed its rejection set. If upstream added a target allowlist, '
				. 'DispatchGuard should be re-argued rather than kept out of habit.'
		);

		$this->assertStringContainsString(
			'WP_Agent_Ability_Dispatcher::dispatch( $name, $parameters )',
			$body,
			'The target name still reaches the dispatcher unfiltered.'
		);
	}

	/**
	 * Scope check for the guard's deliberate exclusion of workflow steps. The
	 * workflow runner dispatches an ability named in a spec, which is an
	 * authored grant rather than a model's mid-conversation choice — so it is
	 * left alone. Assert the shape that reasoning depends on.
	 */
	public function test_workflow_ability_steps_dispatch_without_a_model_in_the_loop(): void {
		$path = $this->upstream_path();
		$file = $path . '/src/Workflows/class-wp-agent-workflow-runner.php';

		$this->assertFileExists( $file );

		$contents = (string) \file_get_contents( $file );

		$this->assertSame(
			1,
			\preg_match(
				'/function\s+default_ability_handler\([^)]*\)[^{]*\{(.*?)\n\t\}/s',
				$contents,
				$match
			),
			'Upstream restructured the workflow ability step handler; re-check the guard scope note.'
		);

		$this->assertStringContainsString(
			"\$step['ability']",
			$match[1],
			'The workflow ability name no longer comes from the authored step spec, which is the reason this path is unguarded.'
		);
	}

	/**
	 * `RunContext` binds captured run identifiers to the ability the runtime is
	 * about to dispatch, replicating upstream's own resolution order. If that
	 * order drifts, attribution binds to a name that never executes and is
	 * silently dropped — a correlation that quietly stops correlating.
	 */
	public function test_tool_call_ability_resolution_order_still_matches_upstream(): void {
		$file = $this->upstream_path() . '/src/Tools/class-wp-agent-ability-tool-executor.php';

		$this->assertFileExists( $file );

		$contents = (string) \file_get_contents( $file );

		$this->assertSame(
			1,
			\preg_match(
				'/private function ability_name\([^)]*\)[^{]*\{.*?\$candidates\s*=\s*array\((.*?)\);/s',
				$contents,
				$match
			),
			'Upstream restructured tool-call ability resolution; RunContext::target_ability() must be re-derived.'
		);

		\preg_match_all( '/\$(?:tool_definition|metadata|tool_call)\[\'([a-z_]+)\'\]/', $match[1], $keys );

		$this->assertSame(
			[ 'ability', 'ability_name', 'ability_name', 'tool_name' ],
			$keys[1],
			'The candidate order changed. RunContext resolves ability -> ability_name -> metadata.ability_name -> tool_name.'
		);
	}

	/**
	 * The keys `RunContext` reads out of the pre-tool-call mediation context.
	 */
	public function test_mediation_context_still_carries_the_keys_run_context_reads(): void {
		$file = $this->upstream_path() . '/src/Runtime/class-wp-agent-conversation-loop.php';

		$this->assertFileExists( $file );

		$contents = (string) \file_get_contents( $file );

		$this->assertSame(
			1,
			\preg_match( '/\$mediation_context\s*=\s*array\((.*?)\n\t\t\t\);/s', $contents, $match ),
			'Could not isolate the pre-tool-call mediation context.'
		);

		foreach ( [ 'tool_name', 'tool_declaration', 'prepared_tool_call', 'raw_tool_call', 'turn_context' ] as $key ) {
			$this->assertStringContainsString(
				"'" . $key . "'",
				$match[1],
				\sprintf( 'RunContext reads %s out of the mediation context and upstream stopped providing it.', $key )
			);
		}
	}

	private function meta_abilities_source(): string {
		$file = $this->upstream_path() . '/src/Tools/register-agent-ability-meta-abilities.php';

		$this->assertFileExists( $file, 'Upstream moved the dispatch meta-abilities.' );

		return (string) \file_get_contents( $file );
	}

	/**
	 * `Compatibility::detected_version()` reads the upstream plugin header
	 * because upstream defines no version constant. Assert that assumption
	 * against the real file rather than through the test bootstrap's
	 * `get_file_data()` stub.
	 */
	public function test_upstream_declares_a_parseable_version_header(): void {
		$path = $this->upstream_path();

		$bootstrap = $path . '/agents-api.php';
		$this->assertFileExists( $bootstrap );

		$contents = (string) \file_get_contents( $bootstrap );
		$this->assertSame(
			1,
			\preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $contents, $match ),
			'Upstream stopped declaring a Version header; Compatibility::detected_version() has no source left.'
		);

		$version = \trim( $match[1] );
		$this->assertNotSame( '', $version );
		$this->assertTrue(
			\version_compare( $version, Compatibility::MINIMUM_VERSION, '>=' ),
			\sprintf(
				'Checkout is %s but the adapter pins %s. Point FLAVOR_AGENT_AGENTS_API_PATH at the pinned tag.',
				$version,
				Compatibility::MINIMUM_VERSION
			)
		);
	}

	/**
	 * Load the real upstream runtime and drive the plugin's own registration
	 * path through it, exactly as `flavor-agent.php` wires it.
	 */
	private function boot_upstream(): void {
		$path = $this->upstream_path();

		foreach ( self::UPSTREAM_FILES as $file ) {
			$this->assertFileExists(
				$path . '/' . $file,
				'Upstream layout moved; the adapter is pinned to a release that had this file.'
			);

			require_once $path . '/' . $file;
		}

		if ( ! \defined( 'AGENTS_API_LOADED' ) ) {
			\define( 'AGENTS_API_LOADED', true );
		}

		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		// The checkout is asserted to satisfy the minimum by its own test; pin
		// the value here so this suite fails on a real contract break rather
		// than on header parsing.
		\add_filter( Compatibility::VERSION_FILTER, static fn(): string => Compatibility::MINIMUM_VERSION );

		$this->assertTrue(
			Compatibility::contracts_available(),
			\sprintf( 'Missing upstream contract: %s', Compatibility::missing_contract() )
		);

		AbilityRegistration::register_abilities();
		AbilityRegistration::register_recommendation_abilities();
		AbilityRegistration::register_external_apply_abilities();

		\add_action( Compatibility::REGISTRATION_HOOK, [ Registration::class, 'register' ] );

		// Upstream's registry refuses to initialize before `init` has fired.
		\do_action( 'init' );
		\WP_Agents_Registry::init();
	}

	private function upstream_path(): string {
		$path = (string) \getenv( 'FLAVOR_AGENT_AGENTS_API_PATH' );

		if ( '' === $path || ! \is_dir( $path ) ) {
			$this->markTestSkipped(
				'Set FLAVOR_AGENT_AGENTS_API_PATH to an Automattic/agents-api checkout at tag v'
				. Compatibility::MINIMUM_VERSION . ' to run the upstream contract tests.'
			);
		}

		return \rtrim( $path, '/' );
	}
}
