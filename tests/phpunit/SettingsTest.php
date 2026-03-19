<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Settings;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$_POST = [];
		$_GET  = [];
		$this->reset_cloudflare_validation_state();
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		$this->reset_cloudflare_validation_state();

		parent::tearDown();
	}

	public function test_sanitize_cloudflare_settings_skips_remote_validation_when_credentials_are_unchanged(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			'flavor_agent_azure_openai_endpoint'           => 'https://example.openai.azure.com/',
		];

		$this->assertSame( 'account-123', Settings::sanitize_cloudflare_account_id( 'account-123' ) );
		$this->assertSame( 'wp-dev-docs', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs' ) );
		$this->assertSame( 'token-xyz', Settings::sanitize_cloudflare_api_token( 'token-xyz' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_cloudflare_settings_accepts_verified_values_and_validates_once(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		WordPressTestState::$remote_get_response  = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'id' => 'wp-dev-docs',
					],
				]
			),
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'probe-chunk',
								'item' => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];

		$this->assertSame( 'account-123', Settings::sanitize_cloudflare_account_id( 'account-123' ) );
		$this->assertSame( 'wp-dev-docs', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs' ) );
		$this->assertSame( 'token-xyz', Settings::sanitize_cloudflare_api_token( 'token-xyz' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/wp-dev-docs',
			WordPressTestState::$last_remote_get['url']
		);
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/wp-dev-docs/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_cloudflare_settings_reverts_invalid_values_and_reports_error_once(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-new',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-new',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-new',
		];

		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 403,
			],
			'body'     => wp_json_encode(
				[
					'errors' => [
						[
							'message' => 'Authentication error',
						],
					],
				]
			),
		];

		$this->assertSame( 'account-old', Settings::sanitize_cloudflare_account_id( 'account-new' ) );
		$this->assertSame( 'wp-dev-docs-old', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs-new' ) );
		$this->assertSame( 'token-old', Settings::sanitize_cloudflare_api_token( 'token-new' ) );
		$this->assertCount( 1, WordPressTestState::$settings_errors );
		$this->assertSame(
			'Authentication error',
			WordPressTestState::$settings_errors[0]['message']
		);
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
		$this->assertCount( 0, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_cloudflare_settings_allows_partial_credentials_without_remote_validation(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => '',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];

		$this->assertSame( '', Settings::sanitize_cloudflare_account_id( '' ) );
		$this->assertSame( 'wp-dev-docs-old', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs-old' ) );
		$this->assertSame( 'token-old', Settings::sanitize_cloudflare_api_token( 'token-old' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_cloudflare_settings_reverts_incompatible_values_and_reports_error_once(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-new',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-new',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-new',
		];

		WordPressTestState::$remote_get_response  = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'id'     => 'wp-dev-docs-new',
						'enable' => true,
						'paused' => false,
					],
				]
			),
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'private-chunk',
								'item' => [
									'key'      => 'internal/wiki/theme-roadmap',
									'metadata' => [
										'url' => 'https://intranet.example.com/wiki/theme-roadmap',
									],
								],
								'text' => 'Private roadmap content that should never reach editor prompts.',
							],
						],
					],
				]
			),
		];

		$this->assertSame( 'account-old', Settings::sanitize_cloudflare_account_id( 'account-new' ) );
		$this->assertSame( 'wp-dev-docs-old', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs-new' ) );
		$this->assertSame( 'token-old', Settings::sanitize_cloudflare_api_token( 'token-new' ) );
		$this->assertCount( 1, WordPressTestState::$settings_errors );
		$this->assertSame(
			'Cloudflare AI Search validation could not confirm trusted developer.wordpress.org content from this instance. Use the official WordPress developer docs index before saving these credentials.',
			WordPressTestState::$settings_errors[0]['message']
		);
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_render_settings_notices_displays_general_and_plugin_messages_after_redirect(): void {
		$_GET = [
			'settings-updated' => 'true',
		];

		WordPressTestState::$transients['settings_errors'] = [
			[
				'setting' => 'general',
				'code'    => 'settings_updated',
				'message' => 'Settings saved.',
				'type'    => 'success',
			],
			[
				'setting' => 'flavor_agent_settings',
				'code'    => 'flavor_agent_cloudflare_ai_search_validation',
				'message' => 'Authentication error',
				'type'    => 'error',
			],
		];

		ob_start();
		$method = new ReflectionMethod( Settings::class, 'render_settings_notices' );
		$method->setAccessible( true );
		$method->invoke( null );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Settings saved.', $output );
		$this->assertStringContainsString( 'Authentication error', $output );
		$this->assertArrayNotHasKey( 'settings_errors', WordPressTestState::$transients );
	}

	private function reset_cloudflare_validation_state(): void {
		$state = new ReflectionProperty( Settings::class, 'cloudflare_validation_state' );
		$state->setAccessible( true );
		$state->setValue( null, null );

		$reported = new ReflectionProperty( Settings::class, 'cloudflare_validation_error_reported' );
		$reported->setAccessible( true );
		$reported->setValue( null, false );
	}
}
