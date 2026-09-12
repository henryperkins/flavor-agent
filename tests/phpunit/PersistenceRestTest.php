<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Permissions;
use FlavorAgent\Activity\PersistenceOccurrenceRepository;
use FlavorAgent\Activity\Repository;
use FlavorAgent\REST\Agent_Controller;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PersistenceRestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		WordPressTestState::$capabilities    = [
			'edit_posts'   => true,
			'edit_post:42' => true,
		];
		Repository::install();
		PersistenceOccurrenceRepository::install();
	}

	public function test_client_server_verdict_and_external_lane_are_rejected(): void {
		$entry          = $this->apply_entry();
		$entry['type']  = 'recommendation_outcome';
		$entry['after'] = [
			'outcome' => [
				'event'          => 'save_confirmed',
				'serverAuthored' => true,
			],
		];
		$result         = Agent_Controller::handle_create_activity( $this->post( $entry ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_persistence_server_authorship_required', $result->get_error_code() );
		$entry              = $this->apply_entry();
		$entry['applyLane'] = 'server-executed';
		$this->assertInstanceOf( \WP_Error::class, Agent_Controller::handle_create_activity( $this->post( $entry ) ) );
		$this->assertCount(
			0,
			Repository::query(
				[
					'scopeKey'           => 'post:42',
					'includeDiagnostics' => true,
				]
			)
		);
	}

	public function test_attempt_is_owner_checked_and_strips_untrusted_verification_fields(): void {
		Repository::create( $this->apply_entry() );
		$entry                          = $this->apply_entry();
		$entry['id']                    = 'attempt';
		$entry['type']                  = 'recommendation_outcome';
		$entry['linkedApplyActivityId'] = 'apply-one';
		$entry['saveOccurrenceId']      = 'occurrence-one';
		$entry['after']                 = [
			'outcome' => [
				'event'              => 'save_failed',
				'saveSequence'       => 999,
				'rawError'           => 'private body',
				'contentFingerprint' => 'fake',
			],
		];
		$result                         = Agent_Controller::handle_create_activity( $this->post( $entry ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( [ 'event', 'visibility', 'applyActivityId', 'saveOccurrenceId' ], array_keys( $result->get_data()['entry']['after']['outcome'] ) );
		WordPressTestState::$current_user_id = 9;
		$this->assertInstanceOf( \WP_Error::class, Agent_Controller::handle_create_activity( $this->post( $entry ) ) );
	}

	public function test_explicit_lookup_checks_every_apply_scope_without_global_access(): void {
		Repository::create( $this->apply_entry() );
		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'applyIds', 'apply-one' );
		$request->set_param( 'saveOccurrenceId', 'occurrence-one' );
		$this->assertTrue( Permissions::can_access_activity_request( $request ) );
		$result = Agent_Controller::handle_get_activity( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( [ 'apply-one' ], array_column( $result->get_data()['entries'], 'id' ) );
		$this->assertFalse( $result->get_data()['pending'] );
		$other             = $this->apply_entry();
		$other['id']       = 'other';
		$other['document'] = [
			'scopeKey' => 'post:99',
			'postType' => 'post',
			'entityId' => '99',
		];
		Repository::create( $other );
		$request->set_param( 'applyIds', 'apply-one,other' );
		$this->assertFalse( Permissions::can_access_activity_request( $request ) );
		$this->assertInstanceOf( \WP_Error::class, Agent_Controller::handle_get_activity( $request ) );
	}

	private function post( array $entry ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity' );
		$request->set_param( 'entry', $entry );
		return $request;
	}

	private function apply_entry(): array {
		return [
			'id'        => 'apply-one',
			'type'      => 'apply_suggestion',
			'surface'   => 'block',
			'applyLane' => 'editor-state',
			'document'  => [
				'scopeKey' => 'post:42',
				'postType' => 'post',
				'entityId' => '42',
			],
			'target'    => [
				'blockName' => 'core/heading',
				'blockPath' => [ 0 ],
			],
			'before'    => [ 'attributes' => [ 'level' => 2 ] ],
			'after'     => [ 'attributes' => [ 'level' => 3 ] ],
		];
	}
}
