<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ApplyAbilitiesTest extends TestCase {

	private const GLOBAL_STYLES_ID = '17';

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_theme_options' => true,
			'edit_posts'         => true,
		];
		Repository::install();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [],
			]
		);
		WordPressTestState::$global_settings = [
			'color' => [
				'palette'    => [
					'theme' => [
						[
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#111111',
						],
						[
							'slug'  => 'base',
							'name'  => 'Base',
							'color' => '#fefefe',
						],
					],
				],
				'background' => true,
				'text'       => true,
			],
		];
		// A resolvable merged background complement so a solo text-color
		// operation can pass the executor's contrast check at approval time.
		WordPressTestState::$global_styles = [
			'color' => [ 'background' => '#fefefe' ],
		];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function seed_global_styles_post( array $config ): void {
		WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ] = new \WP_Post(
			[
				'ID'           => (int) self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					array_merge(
						[
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
						],
						$config
					)
				),
			]
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function agent_request_input( array $overrides = [] ): array {
		$scope         = [
			'surface'        => 'global-styles',
			'globalStylesId' => self::GLOBAL_STYLES_ID,
		];
		$style_context = [
			'currentConfig' => [
				'settings' => [],
				'styles'   => [],
			],
			'mergedConfig'  => [
				'settings' => [],
				'styles'   => [],
			],
		];
		$signatures    = StyleAbilities::recommend_style(
			[
				'scope'                => $scope,
				'styleContext'         => $style_context,
				'prompt'               => 'darker',
				'resolveSignatureOnly' => true,
			]
		);
		$this->assertIsArray( $signatures );

		return array_replace_recursive(
			[
				'scope'            => $scope,
				'styleContext'     => $style_context,
				'prompt'           => 'darker',
				'operations'       => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|accent',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'accent',
					],
				],
				'signatures'       => [
					'resolvedContextSignature' => (string) $signatures['resolvedContextSignature'],
					'reviewContextSignature'   => (string) $signatures['reviewContextSignature'],
				],
				'suggestion'       => [ 'label' => 'Use the accent text preset' ],
				'requestReference' => 'agent-req-1',
			],
			$overrides
		);
	}

	public function test_request_style_apply_creates_a_pending_row_with_lifecycle_payload(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotSame( '', (string) $result['activityId'] );
		$this->assertNotSame( '', (string) $result['expiresAt'] );

		$entry = Repository::find( (string) $result['activityId'] );
		$this->assertIsArray( $entry );
		$this->assertSame( 'apply_global_styles_suggestion', $entry['type'] );
		$this->assertSame( 'global-styles', $entry['surface'] );
		$this->assertSame( 'pending', $entry['executionResult'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
		$this->assertSame( [], $entry['before'] );
		$this->assertSame( 7, $entry['apply']['requestedBy'] );
		$this->assertSame( 'agent-req-1', $entry['apply']['requestReference'] );
		$this->assertSame( 'global_styles:17', $entry['document']['scopeKey'] );
		$this->assertCount( 1, $entry['apply']['operations'] );
		$this->assertSame( 64, strlen( (string) $entry['apply']['signatures']['baselineConfigHash'] ) );
	}

	public function test_request_style_apply_rejects_mismatched_signatures_and_records_stale_blocked(): void {
		$input = $this->agent_request_input();
		$input['signatures']['resolvedContextSignature'] = str_repeat( 'f', 64 );

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );

		$diagnostics = Repository::query(
			[
				'scopeKey'           => 'global_styles:17',
				'includeDiagnostics' => true,
			]
		);
		$outcomes    = array_values(
			array_filter(
				$diagnostics,
				static fn ( array $entry ): bool => 'recommendation_outcome' === (string) ( $entry['type'] ?? '' )
			)
		);
		$this->assertCount( 1, $outcomes );
		$this->assertSame( 'stale_blocked', $outcomes[0]['after']['outcome']['event'] );
	}

	public function test_request_style_apply_rejects_when_the_live_entity_drifted_from_the_claimed_config(): void {
		$input = $this->agent_request_input();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#444444' ] ],
			]
		);

		$result = ApplyAbilities::request_style_apply( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_stale', $result->get_error_code() );
	}

	public function test_request_style_apply_enforces_the_per_user_pending_cap(): void {
		add_filter( 'flavor_agent_external_apply_pending_cap', static fn(): int => 1 );

		$first = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $first );

		$second = ApplyAbilities::request_style_apply( $this->agent_request_input() );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'flavor_agent_apply_queue_full', $second->get_error_code() );
	}

	public function test_request_style_apply_rejects_operations_failing_the_live_contract_without_creating_a_row(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input(
				[
					'operations' => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'text' ],
							'value'      => 'var:preset|color|nope',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'nope',
						],
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_operations_invalid', $result->get_error_code() );
		$this->assertSame(
			[],
			Repository::query( [ 'scopeKey' => 'global_styles:17' ] ),
			'Invalid operations must not enqueue a pending row.'
		);
	}

	public function test_request_style_apply_requires_a_supported_scope(): void {
		$result = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'scope' => [ 'surface' => 'navigation' ] ] )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_style_scope', $result->get_error_code() );
	}

	public function test_get_activity_returns_the_entry_and_lazily_expires_overdue_pending_rows(): void {
		$result = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $result );

		$fetched = ApplyAbilities::get_activity( [ 'activityId' => (string) $result['activityId'] ] );
		$this->assertIsArray( $fetched );
		$this->assertSame( 'pending', $fetched['entry']['apply']['status'] );

		// An overdue pending row (seeded directly, past its expiresAt) must
		// lazily expire on read — the agent observes 'expired', persisted.
		$overdue = Repository::create(
			[
				'type'            => 'apply_global_styles_suggestion',
				'surface'         => 'global-styles',
				'target'          => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'suggestion'      => 'Overdue request',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'apply' => [
						'status'    => 'pending',
						'expiresAt' => gmdate( 'c', time() - 60 ),
					],
				],
				'document'        => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$this->assertIsArray( $overdue );

		$expired = ApplyAbilities::get_activity( [ 'activityId' => (string) $overdue['id'] ] );
		$this->assertSame( 'expired', $expired['entry']['apply']['status'] );
		$this->assertSame(
			'expired',
			Repository::find( (string) $overdue['id'] )['apply']['status'],
			'Lazy expiry must persist.'
		);
	}

	public function test_get_activity_returns_not_found_for_unknown_ids(): void {
		$result = ApplyAbilities::get_activity( [ 'activityId' => 'missing' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_found', $result->get_error_code() );
	}

	public function test_list_activity_requires_a_scope_key(): void {
		$result = ApplyAbilities::list_activity( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_invalid_entry', $result->get_error_code() );
	}

	public function test_list_activity_filters_by_scope_and_status(): void {
		$first = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $first );
		$second = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'requestReference' => 'agent-req-2' ] )
		);
		$this->assertIsArray( $second );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $second['activityId'],
			[ 'applyStatus' => 'rejected' ]
		);

		$pending = ApplyAbilities::list_activity(
			[
				'scopeKey' => 'global_styles:17',
				'status'   => 'pending',
			]
		);
		$this->assertIsArray( $pending );
		$this->assertCount( 1, $pending['entries'] );
		$this->assertSame( (string) $first['activityId'], $pending['entries'][0]['id'] );

		$rejected = ApplyAbilities::list_activity(
			[
				'scopeKey' => 'global_styles:17',
				'status'   => 'rejected',
			]
		);
		$this->assertCount( 1, $rejected['entries'] );

		$all = ApplyAbilities::list_activity( [ 'scopeKey' => 'global_styles:17' ] );
		$this->assertCount( 2, $all['entries'] );
	}

	/**
	 * Persist an executed editor-shaped Global Styles row whose snapshots
	 * match the seeded entity state.
	 *
	 * @return array<string, mixed>
	 */
	private function create_executed_style_row(): array {
		$created = \FlavorAgent\Activity\Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
				'suggestion' => 'Accent text',
				'before'     => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => [],
				],
				'undo'       => [ 'status' => 'available' ],
				'document'   => [ 'scopeKey' => 'global_styles:17' ],
			]
		);
		$this->assertIsArray( $created );

		return $created;
	}

	public function test_undo_activity_restores_the_entity_and_persists_undone(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame( 'undone', $result['entry']['undo']['status'] );

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( [], $written['styles'] );
	}

	public function test_undo_activity_persists_failed_on_drift(): void {
		$row = $this->create_executed_style_row();
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#999999' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['result'] );
		$this->assertSame( 'failed', $result['entry']['undo']['status'] );
		$this->assertNotSame( '', (string) $result['entry']['undo']['error'] );
	}

	public function test_undo_activity_reports_persisted_undone_rows_idempotently_without_rewrite(): void {
		$row = $this->create_executed_style_row();
		\FlavorAgent\Activity\Repository::update_undo_status( (string) $row['id'], 'undone' );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $row['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'already_undone', $result['result'] );
		$this->assertSame( 'undone', $result['entry']['undo']['status'] );
	}

	public function test_undo_activity_rejects_non_executed_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $pending['activityId'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_undoable', $result->get_error_code() );
	}

	public function test_undo_activity_enforces_the_ordered_undo_rule(): void {
		$older = $this->create_executed_style_row();
		$newer = $this->create_executed_style_row();
		$this->assertNotSame( $older['id'], $newer['id'] );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $older['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_undo_blocked', $result->get_error_code() );
	}

	public function test_undo_activity_rejects_unsupported_surfaces(): void {
		$created = \FlavorAgent\Activity\Repository::create(
			[
				'type'       => 'apply_suggestion',
				'surface'    => 'block',
				'target'     => [ 'blockName' => 'core/paragraph' ],
				'suggestion' => 'Block apply',
				'before'     => [ 'attributes' => [] ],
				'after'      => [ 'attributes' => [] ],
				'undo'       => [ 'status' => 'available' ],
				'document'   => [ 'scopeKey' => 'post:5' ],
			]
		);
		$this->assertIsArray( $created );

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $created['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_undo_surface_unsupported', $result->get_error_code() );
	}

	public function test_undo_activity_works_for_approved_external_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $pending['activityId'],
			[
				'applyStatus' => 'available',
				'executedAt'  => gmdate( 'c' ),
				'before'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'       => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
					],
					'operations' => [],
				],
				'target'      => [ 'globalStylesId' => self::GLOBAL_STYLES_ID ],
			]
		);
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ],
			]
		);

		$result = ApplyAbilities::undo_activity( [ 'activityId' => (string) $pending['activityId'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'undone', $result['result'] );
	}

	public function test_decision_approve_executes_and_transitions_to_available(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve',
			'Reviewed and safe'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'available', $entry['apply']['status'] );
		$this->assertSame( 'applied', $entry['executionResult'] );
		$this->assertSame( 'available', $entry['undo']['status'] );
		$this->assertSame( 'Reviewed and safe', $entry['apply']['decisionNote'] );
		$this->assertSame( 7, $entry['apply']['decidedBy'] );
		$this->assertNotSame( '', (string) $entry['apply']['executedAt'] );
		$this->assertSame(
			'var:preset|color|accent',
			$entry['after']['userConfig']['styles']['color']['text']
		);

		$written = json_decode(
			(string) WordPressTestState::$posts[ (int) self::GLOBAL_STYLES_ID ]->post_content,
			true
		);
		$this->assertSame( 'var:preset|color|accent', $written['styles']['color']['text'] );
	}

	public function test_decision_approve_fails_closed_when_the_entity_drifted_after_the_request(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		// Simulate a Site Editor session changing Global Styles before approval.
		$this->seed_global_styles_post(
			[
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#abcdef' ] ],
			]
		);

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'approve'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'failed', $entry['apply']['status'] );
		$this->assertSame( 'failed', $entry['executionResult'] );
		$this->assertSame( 'flavor_agent_apply_stale', $entry['apply']['failureCode'] );
		$this->assertSame( 'not_applicable', $entry['undo']['status'] );
	}

	public function test_decision_reject_records_provenance_without_executing(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$entry = \FlavorAgent\Apply\PendingApplyDecision::decide(
			(string) $pending['activityId'],
			'reject',
			'Not aligned with brand'
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'rejected', $entry['apply']['status'] );
		$this->assertSame( 'Not aligned with brand', $entry['apply']['decisionNote'] );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Reject must not write the entity.' );
	}

	public function test_decision_rejects_non_pending_rows_and_expired_rows(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );
		\FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'reject' );

		$again = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'approve' );
		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 'flavor_agent_apply_not_pending', $again->get_error_code() );

		$expired = ApplyAbilities::request_style_apply(
			$this->agent_request_input( [ 'requestReference' => 'agent-req-3' ] )
		);
		$this->assertIsArray( $expired );
		\FlavorAgent\Activity\Repository::transition_external_apply(
			(string) $expired['activityId'],
			[ 'applyStatus' => 'expired' ]
		);
		$late = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $expired['activityId'], 'approve' );
		$this->assertInstanceOf( \WP_Error::class, $late );
		$this->assertSame( 'flavor_agent_apply_expired', $late->get_error_code() );
	}

	public function test_decision_validates_the_decision_value(): void {
		$pending = ApplyAbilities::request_style_apply( $this->agent_request_input() );
		$this->assertIsArray( $pending );

		$result = \FlavorAgent\Apply\PendingApplyDecision::decide( (string) $pending['activityId'], 'maybe' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_invalid_decision', $result->get_error_code() );
	}

	public function test_request_style_apply_ability_requires_edit_theme_options(): void {
		$ability = new \FlavorAgent\AI\Abilities\RequestStyleApplyAbility(
			\FlavorAgent\AI\Abilities\RequestStyleApplyAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse( $ability->permission_callback( [] ) );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( $ability->permission_callback( [] ) );
	}

	public function test_undo_activity_ability_enforces_the_row_capability_contextually(): void {
		$row     = $this->create_executed_style_row();
		$ability = new \FlavorAgent\AI\Abilities\UndoActivityAbility(
			\FlavorAgent\AI\Abilities\UndoActivityAbility::ABILITY_NAME,
			[]
		);
		$input   = [ 'activityId' => (string) $row['id'] ];

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse(
			$ability->permission_callback( $input ),
			'Style rows resolve to edit_theme_options through the contextual check.'
		);

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue( $ability->permission_callback( $input ) );
	}

	public function test_list_activity_ability_gates_on_the_scope_context(): void {
		$ability = new \FlavorAgent\AI\Abilities\ListActivityAbility(
			\FlavorAgent\AI\Abilities\ListActivityAbility::ABILITY_NAME,
			[]
		);

		WordPressTestState::$capabilities = [ 'edit_posts' => true ];
		$this->assertFalse(
			$ability->permission_callback( [ 'scopeKey' => 'global_styles:17' ] )
		);
		$this->assertFalse( $ability->permission_callback( [] ), 'A scopeKey is required.' );

		WordPressTestState::$capabilities = [ 'edit_theme_options' => true ];
		$this->assertTrue(
			$ability->permission_callback( [ 'scopeKey' => 'global_styles:17' ] )
		);
	}
}
