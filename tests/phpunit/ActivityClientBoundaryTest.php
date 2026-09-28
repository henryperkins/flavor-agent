<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Permissions;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Abilities\ApplyAbilities;
use FlavorAgent\Apply\ApplyClaim;
use FlavorAgent\Apply\PendingApplyDecision;
use FlavorAgent\Apply\StyleApplyExecutor;
use FlavorAgent\Attestation\Repository as AttestationRepository;
use FlavorAgent\REST\Agent_Controller;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ActivityClientBoundaryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_theme_options' => true,
			'edit_post'          => true,
			'manage_options'     => true,
		];
		Repository::install();
		AttestationRepository::install();
	}

	/** @dataProvider reserved_execution_results */
	public function test_client_cannot_create_governance_execution_results( string $result ): void {
		$entry                    = $this->pending_entry();
		$entry['executionResult'] = $result;
		unset( $entry['applyLane'] );

		$response = $this->create_from_client( $entry );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 400, $response->get_error_data()['status'] ?? null );
		$this->assertNull( Repository::find( $entry['id'] ) );
	}

	public static function reserved_execution_results(): array {
		return array_map( static fn ( string $result ): array => [ $result ], [ 'pending', 'rejected', 'expired', 'approved', 'executed', 'available', 'undone', ' PENDING ', 'claim:' . str_repeat( 'a', 24 ) ] );
	}

	public function test_client_cannot_queue_pending_rows_with_arbitrary_expiry(): void {
		for ( $index = 0; $index < 12; ++$index ) {
			$entry       = $this->pending_entry();
			$entry['id'] = 'forged-' . $index;
			unset( $entry['applyLane'] );
			$this->assertInstanceOf( \WP_Error::class, $this->create_from_client( $entry ) );
		}

		$this->assertSame( 0, Repository::count_active_pending_external_applies( 7 ) );
		$this->assertSame(
			[
				'count'  => 0,
				'latest' => null,
			],
			Repository::get_pending_external_apply_notification_snapshot()
		);
	}

	/** @dataProvider terminal_undo_states */
	public function test_client_terminal_undo_is_a_report_without_governance_or_attestation( string $status, ?string $verification ): void {
		$entry                                      = $this->pending_entry();
		$entry['executionResult']                   = 'applied';
		$entry['undo']                              = [
			'status'               => $status,
			'verification'         => $verification,
			'attestationStatus'    => 'recorded',
			'attestationErrorCode' => 'forged-error',
		];
		$entry['request']['apply']['decidedBy']     = 1;
		$entry['request']['apply']['decidedByName'] = 'Site Admin';
		$entry['request']['apply']['executedAt']    = '2020-01-01T00:00:00Z';
		$entry['request']['apply']['attestationStatus'] = 'recorded';
		unset( $entry['applyLane'] );

		$response = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$stored = $response->get_data()['entry'];
		$this->assertArrayNotHasKey( 'apply', $stored['request'] );
		$this->assertArrayNotHasKey( 'apply', $stored );
		$this->assertSame( 'client-reported', $stored['undo']['verification'] ?? null );
		$this->assertArrayNotHasKey( 'attestationStatus', $stored['undo'] );
		$this->assertArrayNotHasKey( 'attestationErrorCode', $stored['undo'] );
		$this->assertNull( AttestationRepository::find_by_related_activity( $entry['id'] ) );
		$this->assertSame( 'darker', $stored['request']['prompt'] );
	}

	public static function terminal_undo_states(): array {
		return [ [ 'undone', null ], [ 'undone', 'server' ], [ 'failed', null ], [ 'failed', 'server' ] ];
	}

	public function test_client_apply_metadata_is_stripped_even_when_the_execution_result_is_applied(): void {
		$entry                    = $this->pending_entry();
		$entry['executionResult'] = 'applied';
		unset( $entry['applyLane'] );
		$response = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertArrayNotHasKey( 'apply', $response->get_data()['entry'] );
		$this->assertInstanceOf( \WP_Error::class, PendingApplyDecision::decide( $entry['id'], 'approve' ) );
		$this->assertSame( 0, Repository::get_pending_external_apply_notification_snapshot()['count'] );
	}

	/** @dataProvider retry_undo_states */
	public function test_duplicate_id_never_discloses_or_rewrites_a_row_outside_the_callers_scope( string $status ): void {
		$this->seed_live_styles();
		$this->assertIsArray( Repository::create( $this->pending_entry() ) );
		$this->assertIsArray( PendingApplyDecision::decide( 'victim', 'approve' ) );
		$before = Repository::find( 'victim' );
		$live   = WordPressTestState::$posts[17]->post_content;

		WordPressTestState::$current_user_id = 55;
		WordPressTestState::$capabilities    = [
			'edit_posts'  => true,
			'edit_post:5' => true,
		];
		$this->assertFalse( Permissions::can_access_entry( $before ) );
		$entry                              = $this->editor_entry();
		$entry['id']                        = 'victim';
		$entry['undo']['status']            = $status;
		$entry['undo']['attestationStatus'] = 'recorded';
		$this->assertTrue( Permissions::can_access_entry( $entry ) );

		$response = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
		$this->assertSame( $before, Repository::find( 'victim' ) );
		$this->assertSame( $live, WordPressTestState::$posts[17]->post_content );
	}

	public static function retry_undo_states(): array {
		return [ [ 'available' ], [ 'undone' ], [ 'failed' ] ];
	}

	/** @dataProvider mismatched_retry_contexts */
	public function test_duplicate_id_requires_the_same_author_scope_surface_and_type( string $mismatch ): void {
		$entry  = $this->editor_entry();
		$before = Repository::create( $entry );
		$this->assertIsArray( $before );
		$entry['undo']['status'] = 'undone';
		if ( 'author' === $mismatch ) {
			WordPressTestState::$current_user_id = 8;
		} elseif ( 'scope' === $mismatch ) {
			$entry['document'] = [
				'scopeKey' => 'post:6',
				'postType' => 'post',
				'entityId' => '6',
			];
		} elseif ( 'surface' === $mismatch ) {
			$entry['surface'] = 'content';
		} else {
			$entry['type'] = 'apply_block_structural_suggestion';
		}

		$response = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $before, Repository::find( 'editor-row' ) );
	}

	public static function mismatched_retry_contexts(): array {
		return [ [ 'author' ], [ 'scope' ], [ 'surface' ], [ 'type' ] ];
	}

	/** @dataProvider protected_rows */
	public function test_same_author_cannot_merge_a_server_lane_or_apply_row( bool $server_lane, bool $apply ): void {
		$entry                    = $this->pending_entry();
		$entry['executionResult'] = 'applied';
		$entry['undo']            = [ 'status' => 'available' ];
		if ( ! $server_lane ) {
			unset( $entry['applyLane'] );
		}
		if ( ! $apply ) {
			unset( $entry['request']['apply'] );
		}
		$before = Repository::create( $entry );
		$this->assertIsArray( $before );
		unset( $entry['applyLane'], $entry['request']['apply'] );
		$entry['undo']['status'] = 'undone';
		$this->assertInstanceOf( \WP_Error::class, $this->create_from_client( $entry ) );
		$this->assertSame( $before, Repository::find( 'victim' ) );
	}

	public static function protected_rows(): array {
		return [ [ true, true ], [ true, false ], [ false, true ] ];
	}

	public function test_editor_retry_accepts_a_scalar_request_without_disclosing_other_rows(): void {
		$entry = $this->editor_entry();
		$this->assertIsArray( Repository::create( $entry ) );
		$entry['request']        = 'unstructured';
		$entry['undo']['status'] = 'undone';
		$response                = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 'client-reported', $response->get_data()['entry']['undo']['verification'] );
	}

	public function test_editor_retry_cannot_overwrite_a_concurrent_terminal_undo(): void {
		$entry = $this->editor_entry();
		$this->assertIsArray( Repository::create( $entry ) );
		$table      = Repository::table_name();
		$concurrent = (string) wp_json_encode(
			[
				'status' => 'failed',
				'error'  => 'Live state changed.',
			]
		);
		WordPressTestState::$before_activity_table_update = static function () use ( $table, $concurrent ): void {
			WordPressTestState::$db_tables[ $table ][0]['undo_state'] = $concurrent;
		};
		$entry['undo']['status']                          = 'undone';
		$response = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertSame( $concurrent, WordPressTestState::$db_tables[ $table ][0]['undo_state'] );
	}

	public function test_review_claim_release_rejects_a_legacy_client_row(): void {
		$entry = $this->pending_entry();
		unset( $entry['applyLane'] );
		$this->assertIsArray( Repository::create( $entry ) );
		$this->assertInstanceOf( \WP_Error::class, ApplyClaim::release( 'victim', 7 ) );
	}

	/** @dataProvider protected_rows */
	public function test_client_undo_route_cannot_terminalize_a_server_or_apply_row( bool $server_lane, bool $apply ): void {
		$entry                    = $this->pending_entry();
		$entry['executionResult'] = 'applied';
		$entry['undo']            = [ 'status' => 'available' ];
		if ( ! $server_lane ) {
			unset( $entry['applyLane'] );
		}
		if ( ! $apply ) {
			unset( $entry['request']['apply'] );
		}
		$before = Repository::create( $entry );
		$this->assertIsArray( $before );
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/victim/undo' );
		$request->set_param( 'id', 'victim' );
		$request->set_param( 'status', 'undone' );
		$response = Agent_Controller::handle_update_activity_undo( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertSame( $before, Repository::find( 'victim' ) );
	}

	public function test_client_undo_route_labels_editor_terminal_states_as_reports(): void {
		$this->assertIsArray( Repository::create( $this->editor_entry() ) );
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/editor-row/undo' );
		$request->set_param( 'id', 'editor-row' );
		$request->set_param( 'status', 'undone' );
		$request->set_param( 'verification', 'server' );
		$request->set_param( 'attestationStatus', 'recorded' );
		$response = Agent_Controller::handle_update_activity_undo( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 'client-reported', $response->get_data()['entry']['undo']['verification'] ?? null );
		$this->assertArrayNotHasKey( 'attestationStatus', $response->get_data()['entry']['undo'] );
	}

	public function test_top_level_id_cannot_substitute_for_the_incoming_entry_permission(): void {
		WordPressTestState::$current_user_id = 55;
		WordPressTestState::$capabilities    = [
			'edit_posts'  => true,
			'edit_post:5' => true,
		];
		$this->assertIsArray( Repository::create( $this->editor_entry() ) );
		$entry                    = $this->pending_entry();
		$entry['executionResult'] = 'applied';
		unset( $entry['applyLane'], $entry['request']['apply'] );
		$this->assertFalse( Permissions::can_access_entry( $entry ) );
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity' );
		$request->set_param( 'id', 'editor-row' );
		$request->set_param( 'entry', $entry );
		$response = Agent_Controller::handle_create_activity( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertNull( Repository::find( 'victim' ) );
	}

	/** @dataProvider misleading_read_parameters */
	public function test_collection_reads_authorize_the_requested_scope_instead_of_an_id_or_entry( array $params ): void {
		$this->assertIsArray( Repository::create( $this->pending_entry() ) );
		WordPressTestState::$current_user_id = 55;
		WordPressTestState::$capabilities    = [
			'edit_posts'  => true,
			'edit_post:5' => true,
		];
		$this->assertIsArray( Repository::create( $this->editor_entry() ) );
		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_param( 'id', 'editor-row' );
		$request->set_param( 'entry', $this->editor_entry() );
		$response = Agent_Controller::handle_get_activity( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	public static function misleading_read_parameters(): array {
		return [ [ [] ], [ [ 'global' => true ] ], [ [ 'scopeKey' => 'global_styles:17' ] ], [ [ 'scopeKey' => 'post:6' ] ] ];
	}

	public function test_scoped_reads_do_not_include_rows_requiring_a_stronger_capability(): void {
		$entry            = $this->editor_entry();
		$entry['id']      = 'navigation-row';
		$entry['surface'] = 'navigation';
		$this->assertIsArray( Repository::create( $entry ) );
		WordPressTestState::$current_user_id = 55;
		WordPressTestState::$capabilities    = [
			'edit_posts'  => true,
			'edit_post:5' => true,
		];
		$this->assertFalse( Permissions::can_access_entry( Repository::find( 'navigation-row' ) ) );
		$this->assertIsArray( Repository::create( $this->editor_entry() ) );
		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:5' );
		foreach ( [ false, true ] as $grouped ) {
			$request->set_param( 'groupBySurface', $grouped );
			$response = Agent_Controller::handle_get_activity( $request );
			$this->assertInstanceOf( \WP_REST_Response::class, $response );
			$this->assertSame( [ 'editor-row' ], array_column( $response->get_data()['entries'], 'id' ) );
		}
		$this->assertSame( [ 'editor-row' ], array_column( ApplyAbilities::list_activity( [ 'scopeKey' => 'post:5' ] )['entries'], 'id' ) );
	}

	/** @dataProvider retry_undo_states */
	public function test_external_undo_never_executes_or_confirms_client_supplied_snapshots( string $status ): void {
		$this->seed_live_styles();
		$entry                    = $this->pending_entry();
		$entry['executionResult'] = 'applied';
		$entry['applyLane']       = 'editor-state';
		$entry['undo']            = [ 'status' => $status ];
		$entry['after']           = [
			'userConfig' => [
				'settings' => [],
				'styles'   => [],
			],
		];
		$entry['before']          = [
			'userConfig' => [
				'settings' => [],
				'styles'   => [ 'color' => [ 'text' => '#123456' ] ],
			],
		];
		$response                 = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$live   = WordPressTestState::$posts[17]->post_content;
		$result = ApplyAbilities::undo_activity( [ 'activityId' => 'victim' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_undoable', $result->get_error_code() );
		$this->assertSame( $live, WordPressTestState::$posts[17]->post_content );
		$this->assertSame( $response->get_data()['entry'], Repository::find( 'victim' ) );
		$this->assertNull( AttestationRepository::find_by_related_activity( 'victim' ) );
	}

	public function test_legitimate_editor_create_retry_merges_only_a_client_report(): void {
		$entry = $this->editor_entry();
		$this->assertInstanceOf( \WP_REST_Response::class, $this->create_from_client( $entry ) );
		$entry['executionResult'] = 'review';
		$entry['undo']            = [
			'status'               => 'undone',
			'verification'         => 'server',
			'attestationStatus'    => 'failed',
			'attestationErrorCode' => 'forged-error',
			'updatedAt'            => '2026-09-28T10:05:00Z',
		];
		$response                 = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$stored = $response->get_data()['entry'];
		$this->assertSame( 'undone', $stored['undo']['status'] );
		$this->assertSame( 'client-reported', $stored['undo']['verification'] ?? null );
		$this->assertSame( '2026-09-28T10:05:00+00:00', $stored['undo']['updatedAt'] );
		$this->assertSame( 'applied', $stored['executionResult'] );
		$this->assertArrayNotHasKey( 'attestationStatus', $stored['undo'] );
		$this->assertArrayNotHasKey( 'attestationErrorCode', $stored['undo'] );
	}

	/** @dataProvider invalid_governance_rows */
	public function test_legacy_rows_without_a_server_apply_identity_cannot_enter_governance( ?string $lane, string $type ): void {
		$this->seed_live_styles();
		$this->assertIsArray( Repository::create( $this->pending_entry() ) );
		$table = Repository::table_name();
		WordPressTestState::$db_tables[ $table ][0]['apply_lane']    = $lane;
		WordPressTestState::$db_tables[ $table ][0]['activity_type'] = $type;
		$before = Repository::find( 'victim' );
		$live   = WordPressTestState::$posts[17]->post_content;

		$this->assertSame( 0, Repository::get_pending_external_apply_notification_snapshot()['count'] );
		$this->assertSame( 0, Repository::count_active_pending_external_applies( 7 ) );
		$this->assertInstanceOf( \WP_Error::class, ApplyClaim::claim( 'victim', 7 ) );
		$this->assertNull( ApplyClaim::get( 'victim' ) );
		$this->assertInstanceOf( \WP_Error::class, Repository::claim_external_apply_decision( 'victim' ) );
		$this->assertInstanceOf( \WP_Error::class, PendingApplyDecision::decide( 'victim', 'approve' ) );
		$this->assertInstanceOf( \WP_Error::class, PendingApplyDecision::decide( 'victim', 'reject' ) );
		$this->assertInstanceOf( \WP_Error::class, Repository::transition_external_apply( 'victim', [ 'applyStatus' => 'rejected' ] ) );
		$this->assertSame( $before, Repository::find( 'victim' ) );
		$this->assertSame( $live, WordPressTestState::$posts[17]->post_content );
		$this->assertNull( AttestationRepository::find_by_related_activity( 'victim' ) );
	}

	public static function invalid_governance_rows(): array {
		return [
			[ null, 'apply_global_styles_suggestion' ],
			[ 'editor-state', 'apply_global_styles_suggestion' ],
			[ 'server-executed', 'request_diagnostic' ],
			[ 'server-executed', 'apply_post_blocks_suggestion' ],
		];
	}

	public function test_server_authored_request_remains_approvable_and_attested(): void {
		$this->seed_live_styles();
		$this->assertIsArray( Repository::create( $this->pending_entry() ) );
		$this->assertSame( 1, Repository::get_pending_external_apply_notification_snapshot()['count'] );
		$this->assertIsArray( ApplyClaim::claim( 'victim', 7 ) );
		$result = PendingApplyDecision::decide( 'victim', 'approve' );
		$this->assertIsArray( $result );
		$this->assertSame( 'available', $result['apply']['status'] );
		$this->assertSame( 'recorded', $result['apply']['attestationStatus'] );
		$this->assertIsArray( AttestationRepository::find_by_related_activity( 'victim' ) );
	}

	public function test_client_cannot_reuse_a_pruned_activity_id_to_inherit_its_attestation(): void {
		$this->seed_live_styles();
		$this->assertIsArray( Repository::create( $this->pending_entry() ) );
		$this->assertIsArray( PendingApplyDecision::decide( 'victim', 'approve' ) );
		$this->assertSame( 1, Repository::delete_before( '2100-01-01T00:00:00Z' ) );
		$this->assertIsArray( AttestationRepository::find_by_related_activity( 'victim' ) );
		WordPressTestState::$current_user_id = 55;
		WordPressTestState::$capabilities    = [
			'edit_posts'  => true,
			'edit_post:5' => true,
		];
		$entry                               = $this->editor_entry();
		$entry['id']                         = 'victim';
		$response                            = $this->create_from_client( $entry );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertNull( Repository::find( 'victim' ) );
	}

	private function create_from_client( array $entry ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity' );
		$request->set_param( 'entry', $entry );
		return Agent_Controller::handle_create_activity( $request );
	}

	private function editor_entry(): array {
		return [
			'id'              => 'editor-row',
			'type'            => 'apply_suggestion',
			'surface'         => 'block',
			'target'          => [ 'clientId' => 'paragraph-1' ],
			'document'        => [
				'scopeKey' => 'post:5',
				'postType' => 'post',
				'entityId' => '5',
			],
			'executionResult' => 'applied',
			'undo'            => [ 'status' => 'available' ],
		];
	}

	private function pending_entry(): array {
		return [
			'id'              => 'victim',
			'type'            => 'apply_global_styles_suggestion',
			'surface'         => 'global-styles',
			'target'          => [ 'globalStylesId' => '17' ],
			'document'        => [
				'scopeKey' => 'global_styles:17',
				'postType' => 'global_styles',
				'entityId' => '17',
			],
			'executionResult' => 'pending',
			'applyLane'       => 'server-executed',
			'undo'            => [ 'status' => 'not_applicable' ],
			'request'         => [
				'prompt' => 'darker',
				'apply'  => [
					'status'      => 'pending',
					'requestedBy' => 1,
					'requestedAt' => '2020-01-01T00:00:00Z',
					'expiresAt'   => '2099-01-01T00:00:00Z',
					'operations'  => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'text' ],
							'value'      => 'var:preset|color|accent',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'accent',
							'cssVar'     => 'var(--wp--preset--color--accent)',
						],
					],
					'signatures'  => [
						'baselineConfigHash' => StyleApplyExecutor::comparable_config_hash(
							[
								'settings' => [],
								'styles'   => [],
							]
						),
					],
				],
			],
		];
	}

	private function seed_live_styles(): void {
		$secret_key = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn (): string => $secret_key );
		WordPressTestState::$posts[17]       = new \WP_Post(
			[
				'ID'           => 17,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					[
						'version'                     => 3,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => [],
						'styles'                      => [],
					]
				),
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
		WordPressTestState::$global_styles   = [ 'color' => [ 'background' => '#fefefe' ] ];
	}
}
