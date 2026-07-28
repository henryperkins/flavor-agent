<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
use FlavorAgent\REST\Agent_Controller;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for POST /flavor-agent/v1/activity/{id}/undo.
 *
 * The route used to be pure bookkeeping: it wrote the caller's requested undo
 * status without ever reading the subject, so an external-agent apply could be
 * recorded as `undone` while the change stayed live on the site (validation
 * record 2026-07-28, Finding 2). Every assertion here checks the live Global
 * Styles entity, not only the row transition -- the row transition is exactly
 * what stayed green while the site kept the change.
 */
final class ActivityUndoRouteTest extends TestCase {

	private const GLOBAL_STYLES_ID = 17;

	/** The value the apply wrote, and the one undo has to remove. */
	private const APPLIED_STYLES = [ 'color' => [ 'text' => 'var:preset|color|accent' ] ];

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id                    = 7;
		WordPressTestState::$capabilities['edit_theme_options'] = true;
		Repository::install();
		$this->seed_live_styles( [] );
	}

	/**
	 * The live user Global Styles post the executor reads and writes.
	 *
	 * @param array<string, mixed> $styles
	 */
	private function seed_live_styles( array $styles ): void {
		WordPressTestState::$posts[ self::GLOBAL_STYLES_ID ] = new \WP_Post(
			[
				'ID'           => self::GLOBAL_STYLES_ID,
				'post_type'    => 'wp_global_styles',
				'post_content' => (string) wp_json_encode(
					[
						'version'                     => 3,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => [],
						'styles'                      => $styles,
					]
				),
			]
		);
	}

	/**
	 * @return array<string, mixed> The live `styles` branch, read back from the post.
	 */
	private function live_styles(): array {
		$decoded = json_decode(
			(string) WordPressTestState::$posts[ self::GLOBAL_STYLES_ID ]->post_content,
			true
		);

		return is_array( $decoded['styles'] ?? null ) ? $decoded['styles'] : [];
	}

	/**
	 * A pending external-apply row on the governed style lane.
	 *
	 * @return array<string, mixed>
	 */
	private function create_pending_row(): array {
		$created = Repository::create(
			[
				'type'            => 'apply_global_styles_suggestion',
				'surface'         => 'global-styles',
				'target'          => [ 'globalStylesId' => (string) self::GLOBAL_STYLES_ID ],
				'suggestion'      => 'Darken the body text',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'prompt' => 'darker',
					'apply'  => [
						'status'      => 'pending',
						'requestedBy' => 7,
						'requestedAt' => gmdate( 'c' ),
						'expiresAt'   => gmdate( 'c', time() + 3600 ),
					],
				],
				'document'        => [
					'scopeKey' => 'global_styles:' . self::GLOBAL_STYLES_ID,
					'postType' => 'global_styles',
					'entityId' => (string) self::GLOBAL_STYLES_ID,
				],
			]
		);

		$this->assertIsArray( $created );

		return $created;
	}

	/**
	 * Approve and execute the pending row, exactly as the decision route does:
	 * the terminal row carries `apply.executedAt` plus the before/after
	 * snapshots a server-side revert compares live state against.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after
	 * @return array<string, mixed>
	 */
	private function create_executed_row( ?array $before = null, ?array $after = null ): array {
		$created  = $this->create_pending_row();
		$executed = Repository::transition_external_apply(
			(string) $created['id'],
			[
				'applyStatus'  => 'available',
				'decidedBy'    => 7,
				'decidedAt'    => '2026-07-28T04:00:00+00:00',
				'decisionNote' => 'Looks safe',
				'executedAt'   => '2026-07-28T04:00:01+00:00',
				'target'       => [ 'globalStylesId' => (string) self::GLOBAL_STYLES_ID ],
				'before'       => $before ?? [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'        => $after ?? [
					'userConfig' => [
						'settings' => [],
						'styles'   => self::APPLIED_STYLES,
					],
				],
			]
		);

		$this->assertIsArray( $executed );
		$this->assertSame( 'applied', $executed['executionResult'] );
		$this->assertSame( 'available', $executed['undo']['status'] );

		return $executed;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function undo( string $activity_id, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/' . $activity_id . '/undo' );
		$request->set_param( 'id', $activity_id );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return Agent_Controller::handle_update_activity_undo( $request );
	}

	/**
	 * The Finding 2 regression. The applied value is live; the route must strip
	 * it from the entity before it is allowed to write `undone`.
	 */
	public function test_undo_reverts_the_live_entity_before_recording_undone(): void {
		$row = $this->create_executed_row();
		$this->seed_live_styles( self::APPLIED_STYLES );

		$response = $this->undo( (string) $row['id'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		// Asserted first, and against the entity rather than the row: the row
		// transition is what stayed green on the live site while the change
		// remained applied.
		$this->assertSame(
			[],
			$this->live_styles(),
			'The live Global Styles entity must hold the before snapshot once the row says undone.'
		);

		$data = $response->get_data();
		$this->assertSame( 'undone', $data['result'] ?? null );
		$this->assertSame( 'undone', $data['entry']['undo']['status'] );
		$this->assertSame( 'server', $data['entry']['undo']['verification'] ?? null );
		$this->assertNotSame( '', (string) ( $data['entry']['undo']['undoneAt'] ?? '' ) );
		$this->assertSame(
			'undone',
			Repository::find( (string) $row['id'] )['undo']['status'],
			'The stored row must agree with the response.'
		);
	}

	/**
	 * A revert the server cannot make must be reported as a failure, not as a
	 * silent success. Silent success is the defect.
	 */
	public function test_undo_reports_drift_as_failure_and_leaves_the_site_untouched(): void {
		$row     = $this->create_executed_row();
		$drifted = [ 'color' => [ 'text' => '#333333' ] ];
		$this->seed_live_styles( $drifted );

		$response = $this->undo( (string) $row['id'] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'flavor_agent_undo_drift', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] ?? null );

		$stored = Repository::find( (string) $row['id'] );
		$this->assertSame( 'failed', $stored['undo']['status'] );
		$this->assertSame( 'server', $stored['undo']['verification'] ?? null );
		$this->assertNotSame( '', (string) ( $stored['undo']['error'] ?? '' ) );
		$this->assertNull( $stored['undo']['undoneAt'] );

		$this->assertSame(
			$drifted,
			$this->live_styles(),
			'A failed undo must not touch the drifted entity.'
		);
	}

	/**
	 * The editor reverts client-side before it reports to this route, so the
	 * executor frequently finds the revert already done. That must resolve as
	 * `already_undone` without a second write, not as a double revert.
	 */
	public function test_undo_reports_already_undone_without_writing_again(): void {
		$row = $this->create_executed_row();
		$this->seed_live_styles( [] );
		WordPressTestState::$updated_posts = [];

		$response = $this->undo( (string) $row['id'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'already_undone', $data['result'] ?? null );
		$this->assertSame( 'undone', $data['entry']['undo']['status'] );
		$this->assertSame( 'server', $data['entry']['undo']['verification'] ?? null );

		$this->assertSame( [], $this->live_styles() );
		$this->assertSame(
			[],
			WordPressTestState::$updated_posts,
			'An already-undone subject must not be rewritten.'
		);
	}

	/**
	 * A row that executed on the server but carries no comparable snapshot has
	 * no honest terminal state available: fail closed rather than record an
	 * undo nothing verified.
	 */
	public function test_undo_refuses_an_unverifiable_server_executed_row(): void {
		$row = $this->create_executed_row(
			[ 'operations' => [] ],
			[ 'operations' => [ [ 'type' => 'set_styles' ] ] ]
		);
		$this->seed_live_styles( self::APPLIED_STYLES );

		$response = $this->undo( (string) $row['id'] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'flavor_agent_undo_unverifiable', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] ?? null );

		$stored = Repository::find( (string) $row['id'] );
		$this->assertSame(
			'available',
			$stored['undo']['status'],
			'An unverifiable row stays undoable rather than being closed out as undone.'
		);
		$this->assertSame( self::APPLIED_STYLES, $this->live_styles() );
	}

	/**
	 * Editor-local surfaces have no server-side subject, so their transition is
	 * the editor's report. It is still recorded -- and labelled as a report.
	 */
	public function test_undo_records_an_editor_local_row_as_client_reported(): void {
		$created = Repository::create(
			[
				'type'       => 'apply_suggestion',
				'surface'    => 'block',
				'target'     => [
					'clientId'  => 'block-1',
					'blockName' => 'core/paragraph',
				],
				'suggestion' => 'Rewrite the introduction.',
				'before'     => [ 'attributes' => [ 'content' => 'Before' ] ],
				'after'      => [ 'attributes' => [ 'content' => 'After' ] ],
				'document'   => [
					'scopeKey' => 'post:42',
					'postType' => 'post',
					'entityId' => '42',
				],
			]
		);
		$this->assertIsArray( $created );
		WordPressTestState::$capabilities['edit_posts']   = true;
		WordPressTestState::$capabilities['edit_post:42'] = true;

		$response = $this->undo( (string) $created['id'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'client_reported', $data['result'] ?? null );
		$this->assertSame( 'undone', $data['entry']['undo']['status'] );
		$this->assertSame(
			'client-reported',
			$data['entry']['undo']['verification'] ?? null,
			'An unverified transition must never be recorded as a server-verified revert.'
		);
	}

	/**
	 * A caller may report that its own undo failed -- failure never claims a
	 * revert happened -- and that report must not trigger a server-side write.
	 */
	public function test_undo_honors_a_client_failure_report_without_touching_live_state(): void {
		$row = $this->create_executed_row();
		$this->seed_live_styles( self::APPLIED_STYLES );
		WordPressTestState::$updated_posts = [];

		$response = $this->undo(
			(string) $row['id'],
			[
				'status' => 'failed',
				'error'  => 'The editor could not restore the previous styles.',
			]
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'failed', $data['result'] ?? null );
		$this->assertSame( 'failed', $data['entry']['undo']['status'] );
		$this->assertSame( 'client-reported', $data['entry']['undo']['verification'] ?? null );
		$this->assertSame(
			'The editor could not restore the previous styles.',
			$data['entry']['undo']['error']
		);

		$this->assertSame( self::APPLIED_STYLES, $this->live_styles() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}

	/**
	 * The ordered-undo gate runs before the executor, but update_undo_status()
	 * re-checks it at the write boundary. If a newer activity on the same entity
	 * lands in between, the subject has already been reverted -- refusing to
	 * record that cannot un-revert it, it only hides it. The coordinator must
	 * still write the terminal status for a revert it actually performed.
	 */
	public function test_verified_undo_records_a_revert_it_performed_even_if_ordering_lapsed(): void {
		$row = $this->create_executed_row();
		$this->seed_live_styles( self::APPLIED_STYLES );

		// A newer, still-undoable activity on the same entity: exactly what a
		// concurrent insert between the gate and the terminal write produces.
		$newer = Repository::create(
			[
				'type'       => 'apply_global_styles_suggestion',
				'surface'    => 'global-styles',
				'target'     => [ 'globalStylesId' => (string) self::GLOBAL_STYLES_ID ],
				'suggestion' => 'A newer change on the same entity',
				'before'     => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'after'      => [
					'userConfig' => [
						'settings' => [],
						'styles'   => [],
					],
				],
				'document'   => [
					'scopeKey' => 'global_styles:' . self::GLOBAL_STYLES_ID,
					'postType' => 'global_styles',
					'entityId' => (string) self::GLOBAL_STYLES_ID,
				],
			]
		);
		$this->assertIsArray( $newer );
		$this->assertFalse(
			Repository::can_perform_ordered_undo( (string) $row['id'] ),
			'The newer row must make the target ordered-undo ineligible.'
		);

		$result = \FlavorAgent\Apply\UndoCoordinator::run_verified_undo(
			(string) $row['id'],
			$row,
			\FlavorAgent\Apply\StyleApplyExecutor::class
		);

		$this->assertIsArray( $result, 'A performed revert must not be reported as a plain error.' );
		$this->assertSame( 'undone', $result['result'] );
		$this->assertSame(
			[],
			$this->live_styles(),
			'The executor reverted the subject.'
		);
		$this->assertSame(
			'undone',
			Repository::find( (string) $row['id'] )['undo']['status'],
			'A reverted subject must never be left with an available row -- that is the record/reality split this whole change exists to close.'
		);
	}

	/**
	 * A pending row's snapshots describe a change the site never received.
	 * Undoing one would be an unreviewed mutation, so the gate runs before any
	 * executor dispatch.
	 */
	public function test_undo_rejects_a_pending_apply_that_never_executed(): void {
		$row = $this->create_pending_row();
		$this->seed_live_styles( self::APPLIED_STYLES );
		WordPressTestState::$updated_posts = [];

		$response = $this->undo( (string) $row['id'] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'flavor_agent_activity_not_undoable', $response->get_error_code() );
		$this->assertSame( self::APPLIED_STYLES, $this->live_styles() );
		$this->assertSame( [], WordPressTestState::$updated_posts );
	}
}
