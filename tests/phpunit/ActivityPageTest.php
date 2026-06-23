<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
use FlavorAgent\Admin\ActivityPage;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ActivityPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$_GET = [];
		Repository::install();
	}

	protected function tearDown(): void {
		$_GET = [];

		parent::tearDown();
	}

	public function test_render_page_outputs_a_server_side_fallback_shell(): void {
		ob_start();
		ActivityPage::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'flavor-agent-activity-log-root',
			$output
		);
		$this->assertStringContainsString( 'AI Activity Log', $output );
		$this->assertStringContainsString(
			'Flavor Agent could not load the interactive activity log.',
			$output
		);
		$this->assertStringContainsString(
			'options-general.php?page=flavor-agent',
			$output
		);
		$this->assertStringContainsString(
			'options-connectors.php',
			$output
		);
	}

	public function test_render_pending_external_apply_notice_includes_context_and_link_for_eligible_admins(): void {
		$this->create_pending_entry(
			[
				'id'       => 'pending-notice',
				'surface'  => 'style-book',
				'target'   => [
					'globalStylesId' => '17',
					'blockName'      => 'core/button',
				],
				'document' => [
					'scopeKey' => 'global_styles:17:block:core/button',
					'postType' => 'global_styles',
					'entityId' => '17',
				],
			]
		);
		WordPressTestState::$capabilities['manage_options']     = true;
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ob_start();
		ActivityPage::render_pending_external_apply_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Pending external style apply awaiting approval', $output );
		$this->assertStringContainsString( 'Style Book (core/button, Global Styles 17)', $output );
		$this->assertStringContainsString( 'Requested by: User #7 (reference: agent-req-1)', $output );
		$this->assertStringContainsString( 'Open AI Activity', $output );
		$this->assertStringContainsString( 'options-general.php?page=flavor-agent-activity', $output );
	}

	public function test_render_pending_external_apply_notice_skips_ineligible_users(): void {
		$this->create_pending_entry();
		WordPressTestState::$capabilities['manage_options']     = true;
		WordPressTestState::$capabilities['edit_theme_options'] = false;

		ob_start();
		ActivityPage::render_pending_external_apply_notice();
		$output = (string) ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	public function test_render_pending_external_apply_notice_skips_activity_page_to_avoid_redundancy(): void {
		$this->create_pending_entry();
		WordPressTestState::$capabilities['manage_options']     = true;
		WordPressTestState::$capabilities['edit_theme_options'] = true;
		$_GET['page'] = 'flavor-agent-activity';

		ob_start();
		ActivityPage::render_pending_external_apply_notice();
		$output = (string) ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function create_pending_entry( array $overrides = [] ): void {
		WordPressTestState::$current_user_id = 7;

		$entry = array_replace_recursive(
			[
				'id'              => 'pending-default',
				'type'            => 'apply_global_styles_suggestion',
				'surface'         => 'global-styles',
				'target'          => [ 'globalStylesId' => '17' ],
				'suggestion'      => 'Darken the palette',
				'before'          => [],
				'after'           => [],
				'executionResult' => 'pending',
				'undo'            => [ 'status' => 'not_applicable' ],
				'request'         => [
					'prompt'    => 'darker',
					'reference' => 'external-apply:global_styles:17',
					'apply'     => [
						'status'           => 'pending',
						'requestedBy'      => 7,
						'requestedAt'      => gmdate( 'c' ),
						'expiresAt'        => gmdate( 'c', time() + 3600 ),
						'operations'       => [
							[
								'type'  => 'set_styles',
								'path'  => [ 'color', 'text' ],
								'value' => 'var:preset|color|accent',
							],
						],
						'requestReference' => 'agent-req-1',
					],
				],
				'document'        => [
					'scopeKey' => 'global_styles:17',
					'postType' => 'global_styles',
					'entityId' => '17',
				],
			],
			$overrides
		);

		Repository::create( $entry );
	}
}
