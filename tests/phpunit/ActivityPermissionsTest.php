<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Permissions as ActivityPermissions;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\REST\Agent_Controller;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ActivityPermissionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		ActivityRepository::install();
	}

	public function test_handle_get_activity_requires_edit_post_access_for_post_scopes(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_handle_get_activity_allows_direct_edit_post_access_for_post_scopes(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => true,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['entries'] ?? [] );
	}

	public function test_handle_get_activity_requires_manage_options_for_global_queries(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'global', true );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_can_access_activity_request_rejects_entity_id_spoofing_for_scoped_reads(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => false,
			'edit_post:99' => true,
			'edit_posts'   => false,
		];

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'entityType', 'block' );
		$request->set_param( 'entityId', '99' );

		$this->assertFalse(
			ActivityPermissions::can_access_activity_request( $request )
		);
	}

	public function test_handle_get_activity_rejects_entity_id_spoofing_for_scoped_reads(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => false,
			'edit_post:99' => true,
			'edit_posts'   => false,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'entityType', 'block' );
		$request->set_param( 'entityId', '99' );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_handle_get_activity_uses_scope_key_before_spoofed_surface(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42'       => false,
			'edit_theme_options' => true,
			'edit_posts'         => false,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );
		$request->set_param( 'surface', 'template' );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_can_access_activity_request_requires_theme_caps_for_navigation_surface_even_with_post_scope(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => true,
			'edit_posts'   => true,
		];

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );
		$request->set_param( 'surface', 'navigation' );

		$this->assertFalse(
			ActivityPermissions::can_access_activity_request( $request )
		);
	}

	public function test_handle_get_activity_requires_theme_caps_for_template_part_entity_type_even_with_post_scope(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => true,
			'edit_posts'   => true,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );
		$request->set_param( 'entityType', 'template-part' );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_is_global_request_treats_sanitized_boolean_true_as_global(): void {
		WordPressTestState::$capabilities = [
			'manage_options' => true,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-global-1', '42' ) );

		$request = new \WP_REST_Request( 'GET', '/flavor-agent/v1/activity' );
		$request->set_param( 'global', true );

		$response = Agent_Controller::handle_get_activity( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['entries'] ?? [] );
	}

	public function test_handle_update_activity_undo_requires_direct_edit_post_access_for_entry_scope(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/activity-1/undo' );
		$request->set_param( 'id', 'activity-1' );
		$request->set_param( 'status', 'undone' );

		$response = Agent_Controller::handle_update_activity_undo( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_handle_update_activity_undo_uses_stored_entry_before_spoofed_request_context(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => false,
			'edit_post:99' => true,
			'edit_posts'   => false,
		];

		ActivityRepository::create( $this->build_block_activity_entry( 'activity-1', '42' ) );

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/activity-1/undo' );
		$request->set_param( 'id', 'activity-1' );
		$request->set_param( 'status', 'undone' );
		$request->set_param( 'scopeKey', 'post:99' );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'entityType', 'block' );
		$request->set_param( 'entityId', '99' );

		$response = Agent_Controller::handle_update_activity_undo( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_handle_create_activity_rejects_spoofed_request_context_when_entry_requires_theme_access(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => true,
		];

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity' );
		$request->set_param( 'scopeKey', 'post:42' );
		$request->set_param( 'surface', 'block' );
		$request->set_param( 'entityType', 'block' );
		$request->set_param( 'entry', $this->build_template_activity_entry( 'activity-template-1' ) );

		$response = Agent_Controller::handle_create_activity( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	public function test_handle_create_activity_rejects_entry_document_entity_id_mismatch(): void {
		WordPressTestState::$capabilities = [
			'edit_post:42' => false,
			'edit_post:99' => true,
			'edit_posts'   => false,
		];

		$entry                         = $this->build_block_activity_entry( 'activity-1', '42' );
		$entry['document']['postType'] = 'post';
		$entry['document']['entityId'] = '99';

		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity' );
		$request->set_param( 'entry', $entry );

		$response = Agent_Controller::handle_create_activity( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] ?? null );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_block_activity_entry( string $id, string $entity_id ): array {
		return [
			'id'         => $id,
			'type'       => 'apply_suggestion',
			'surface'    => 'block',
			'target'     => [
				'clientId'  => 'block-1',
				'blockName' => 'core/paragraph',
				'blockPath' => [ 0 ],
			],
			'suggestion' => 'Refresh content',
			'before'     => [
				'attributes' => [
					'content' => 'Before',
				],
			],
			'after'      => [
				'attributes' => [
					'content' => 'After',
				],
			],
			'request'    => [
				'prompt'    => 'Tighten the copy.',
				'reference' => 'block:block-1:1',
			],
			'document'   => [
				'scopeKey' => "post:{$entity_id}",
				'postType' => 'post',
				'entityId' => $entity_id,
			],
			'timestamp'  => '2026-03-24T10:00:00Z',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_template_activity_entry( string $id ): array {
		return [
			'id'         => $id,
			'type'       => 'apply_template_suggestion',
			'surface'    => 'template',
			'target'     => [
				'templateRef' => 'theme//home',
			],
			'suggestion' => 'Clarify hierarchy',
			'before'     => [
				'operations' => [],
			],
			'after'      => [
				'operations' => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'theme/hero',
					],
				],
			],
			'request'    => [
				'prompt'    => 'Make the home template feel more editorial.',
				'reference' => 'template:theme//home:3',
			],
			'document'   => [
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
				'entityId' => 'theme//home',
			],
			'timestamp'  => '2026-03-24T10:00:00Z',
		];
	}
}
