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

	public function test_add_menu_registers_notice_and_known_page_load_hooks(): void {
		ActivityPage::add_menu();

		$this->assertSame(
			10,
			has_action( 'admin_notices', [ ActivityPage::class, 'render_pending_external_apply_notice' ] )
		);
		$this->assertSame(
			10,
			has_action( 'load-settings_page_flavor-agent-activity', [ ActivityPage::class, 'handle_page_load' ] )
		);
		$this->assertSame(
			10,
			has_action( 'load-admin_page_flavor-agent-activity', [ ActivityPage::class, 'handle_page_load' ] )
		);
	}

	public function test_render_pending_external_apply_notice_includes_context_and_link_for_eligible_admins(): void {
		$this->create_pending_entry(
			[
				'id'       => 'pending-notice',
				'type'     => 'apply_style_book_suggestion',
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

		$this->assertStringContainsString( 'Pending external apply awaiting approval', $output );
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

	public function test_render_pending_external_apply_notice_skips_activity_page_get_requests(): void {
		$this->create_pending_entry();
		WordPressTestState::$capabilities['manage_options']     = true;
		WordPressTestState::$capabilities['edit_theme_options'] = true;
		$_GET['page'] = 'flavor-agent-activity';

		ob_start();
		ActivityPage::render_pending_external_apply_notice();
		$output = (string) ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	public function test_render_pending_external_apply_notice_skips_activity_screen_requests(): void {
		$this->create_pending_entry();
		WordPressTestState::$capabilities['manage_options']     = true;
		WordPressTestState::$capabilities['edit_theme_options'] = true;
		WordPressTestState::$current_screen                     = (object) [
			'id' => 'settings_page_flavor-agent-activity',
		];

		ob_start();
		ActivityPage::render_pending_external_apply_notice();
		$output = (string) ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	public function test_theme_color_presets_are_resolved_for_admin_boot_data(): void {
		WordPressTestState::$global_settings = [
			'color' => [
				'palette' => [
					[
						'name'  => 'Accent',
						'slug'  => 'accent',
						'color' => '#0b7b80',
					],
					[
						'name'  => 'Contrast',
						'slug'  => 'contrast',
						'color' => '#17232a',
					],
					[
						'name'  => 'Empty',
						'slug'  => 'empty',
						'color' => '',
					],
				],
			],
		];
		$method                              = new \ReflectionMethod( ActivityPage::class, 'get_theme_color_presets' );
		$method->setAccessible( true );

		$this->assertSame(
			[
				[
					'name'  => 'Accent',
					'slug'  => 'accent',
					'color' => '#0b7b80',
				],
				[
					'name'  => 'Contrast',
					'slug'  => 'contrast',
					'color' => '#17232a',
				],
			],
			$method->invoke( null )
		);
	}

	public function test_build_activity_log_boot_data_includes_current_user_id(): void {
		WordPressTestState::$current_user_id = 42;

		$method = new \ReflectionMethod( ActivityPage::class, 'build_activity_log_boot_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null );

		$this->assertArrayHasKey( 'currentUserId', $data );
		$this->assertSame( 42, $data['currentUserId'] );
	}

	public function test_boot_exposes_only_signing_availability_and_eligible_surfaces_when_enabled(): void {
		$secret = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		add_filter( 'flavor_agent_attest_private_key', static fn () => $secret );

		$data = $this->activity_boot_data();

		$this->assertSame(
			[
				'signingAvailable' => true,
				'eligibleSurfaces' => [ 'global-styles', 'style-book', 'template', 'template-part' ],
			],
			$data['attestation'] ?? null
		);
		$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $data ) );
	}

	public function test_boot_reports_unavailable_signing_without_key_configuration(): void {
		add_filter( 'flavor_agent_attest_private_key', static fn () => '' );

		$data = $this->activity_boot_data();

		$this->assertSame( false, $data['attestation']['signingAvailable'] ?? null );
		$this->assertSame(
			[ 'signingAvailable', 'eligibleSurfaces' ],
			array_keys( $data['attestation'] ?? [] )
		);
	}

	public function test_boot_survives_throwing_signing_configuration_without_exposing_exception_details(): void {
		add_filter(
			'flavor_agent_attest_private_key',
			static function () {
				throw new \RuntimeException( 'private-key-secret request-identity-private' );
			}
		);

		$data = $this->activity_boot_data();

		$this->assertSame( false, $data['attestation']['signingAvailable'] ?? null );
		$this->assertSame(
			[ 'signingAvailable', 'eligibleSurfaces' ],
			array_keys( $data['attestation'] ?? [] )
		);
		$this->assertStringNotContainsString( 'private-key-secret', (string) wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'request-identity-private', (string) wp_json_encode( $data ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function activity_boot_data(): array {
		$method = new \ReflectionMethod( ActivityPage::class, 'build_activity_log_boot_data' );
		$method->setAccessible( true );

		return $method->invoke( null );
	}

	public function test_render_pending_external_apply_notice_includes_post_title_and_id_for_post_blocks(): void {
		$this->create_pending_entry(
			[
				'id'       => 'pending-post-blocks-notice',
				'type'     => 'apply_post_blocks_suggestion',
				'surface'  => 'post-blocks',
				'target'   => [
					'postId'   => 9400,
					'postType' => 'post',
					'title'    => 'Sample document',
				],
				'document' => [
					'scopeKey' => 'post:9400',
					'postType' => 'post',
					'entityId' => '9400',
				],
			]
		);
		WordPressTestState::$capabilities['manage_options']     = true;
		WordPressTestState::$capabilities['edit_theme_options'] = true;

		ob_start();
		ActivityPage::render_pending_external_apply_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Post: Sample document (#9400)', $output );
		$this->assertStringContainsString( 'Open AI Activity', $output );
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
				'applyLane'       => 'server-executed',
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

		$created = Repository::create( $entry );
		$this->assertIsArray( $created );
	}
}
