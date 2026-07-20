<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/support/theme-json-stub.php';

use FlavorAgent\Support\ThemeJsonCapabilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use WP_Theme_JSON_Gutenberg;

final class ThemeJsonCapabilitiesTest extends TestCase {

	private const TEXT_SHADOW = [ 'typography', 'textShadow' ];

	protected function setUp(): void {
		parent::setUp();

		WP_Theme_JSON_Gutenberg::reset();
		ThemeJsonCapabilities::flush();
		WordPressTestState::$capabilities = [];
	}

	protected function tearDown(): void {
		WP_Theme_JSON_Gutenberg::reset();
		ThemeJsonCapabilities::flush();
		WordPressTestState::$capabilities = [];

		parent::tearDown();
	}

	public function test_path_absent_from_the_schema_is_unsupported(): void {
		$this->assertFalse(
			ThemeJsonCapabilities::supports_style_path( self::TEXT_SHADOW ),
			'A path the running theme.json class strips must not be reported as supported.'
		);
	}

	public function test_path_present_in_the_schema_is_supported(): void {
		WP_Theme_JSON_Gutenberg::$valid_style_paths = [ 'typography.textShadow' ];
		ThemeJsonCapabilities::flush();

		$this->assertTrue(
			ThemeJsonCapabilities::supports_style_path( self::TEXT_SHADOW )
		);
	}

	public function test_unsupported_path_can_never_be_persisted(): void {
		WordPressTestState::$capabilities = [ 'unfiltered_html' => true ];

		$this->assertFalse(
			ThemeJsonCapabilities::current_user_can_persist_style_path( self::TEXT_SHADOW ),
			'unfiltered_html must not rescue a path the schema itself drops.'
		);
	}

	public function test_unfiltered_html_user_persists_a_path_the_css_allowlist_rejects(): void {
		// The real situation for text-shadow: rendered by theme.json, but absent
		// from safe_style_css. Users with unfiltered_html never have the
		// global-styles kses filter installed, so the value survives.
		WP_Theme_JSON_Gutenberg::$valid_style_paths = [ 'typography.textShadow' ];
		WP_Theme_JSON_Gutenberg::$safe_style_paths  = [];
		WordPressTestState::$capabilities           = [ 'unfiltered_html' => true ];
		ThemeJsonCapabilities::flush();

		$this->assertTrue(
			ThemeJsonCapabilities::current_user_can_persist_style_path( self::TEXT_SHADOW )
		);
	}

	public function test_restricted_user_cannot_persist_a_path_the_css_allowlist_rejects(): void {
		WP_Theme_JSON_Gutenberg::$valid_style_paths = [ 'typography.textShadow' ];
		WP_Theme_JSON_Gutenberg::$safe_style_paths  = [];
		WordPressTestState::$capabilities           = [ 'unfiltered_html' => false ];
		ThemeJsonCapabilities::flush();

		$this->assertFalse(
			ThemeJsonCapabilities::current_user_can_persist_style_path( self::TEXT_SHADOW ),
			'Without unfiltered_html the save-time kses pass strips the value, so the apply must be refused.'
		);
	}

	public function test_restricted_user_persists_a_path_the_css_allowlist_accepts(): void {
		WP_Theme_JSON_Gutenberg::$valid_style_paths = [ 'typography.textShadow' ];
		WP_Theme_JSON_Gutenberg::$safe_style_paths  = [ 'typography.textShadow' ];
		WordPressTestState::$capabilities           = [ 'unfiltered_html' => false ];
		ThemeJsonCapabilities::flush();

		$this->assertTrue(
			ThemeJsonCapabilities::current_user_can_persist_style_path( self::TEXT_SHADOW )
		);
	}

	public function test_empty_path_is_rejected(): void {
		WP_Theme_JSON_Gutenberg::$valid_style_paths = [ 'typography.textShadow' ];
		ThemeJsonCapabilities::flush();

		$this->assertFalse( ThemeJsonCapabilities::supports_style_path( [] ) );
		$this->assertFalse( ThemeJsonCapabilities::supports_style_path( [ '' ] ) );
	}

	public function test_probe_result_is_cached_until_flushed(): void {
		WP_Theme_JSON_Gutenberg::$valid_style_paths = [ 'typography.textShadow' ];
		ThemeJsonCapabilities::flush();

		$this->assertTrue( ThemeJsonCapabilities::supports_style_path( self::TEXT_SHADOW ) );

		// Changing the runtime without flushing must not change the answer:
		// proves the result is cached rather than re-probed on every call.
		WP_Theme_JSON_Gutenberg::$valid_style_paths = [];

		$this->assertTrue( ThemeJsonCapabilities::supports_style_path( self::TEXT_SHADOW ) );

		ThemeJsonCapabilities::flush();

		$this->assertFalse( ThemeJsonCapabilities::supports_style_path( self::TEXT_SHADOW ) );
	}
}
