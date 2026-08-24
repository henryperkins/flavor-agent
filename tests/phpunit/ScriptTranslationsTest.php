<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the JS translation wiring.
 *
 * `__()`/`_n()` inside a bundle only resolve when the handle has had
 * `wp_set_script_translations()` called on it — without it every string in the
 * editor, settings, and AI Activity bundles renders its English source
 * regardless of the site locale. That failure is invisible in an English-only
 * environment, so pin it here rather than relying on manual QA.
 */
final class ScriptTranslationsTest extends TestCase {

	private const TEXT_DOMAIN = 'flavor-agent';

	/**
	 * Every enqueued (non-module) script handle, and the file that enqueues it.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function enqueued_script_handles(): array {
		return [
			'editor'       => [ 'flavor-agent-editor', 'flavor-agent.php' ],
			'settings'     => [ 'flavor-agent-admin', 'inc/Admin/Settings/Assets.php' ],
			'activity log' => [ 'flavor-agent-activity-log', 'inc/Admin/ActivityPage.php' ],
		];
	}

	/**
	 * @dataProvider enqueued_script_handles
	 */
	public function test_handle_registers_script_translations( string $handle, string $relative_path ): void {
		$source = self::read_plugin_file( $relative_path );

		$this->assertMatchesRegularExpression(
			'/wp_set_script_translations\(\s*[\'"]' . preg_quote( $handle, '/' ) . '[\'"]/',
			$source,
			sprintf(
				'%s enqueues "%s" but never calls wp_set_script_translations() for it, so its bundled __() strings stay untranslated.',
				$relative_path,
				$handle
			)
		);
	}

	/**
	 * @dataProvider enqueued_script_handles
	 */
	public function test_script_translations_use_the_plugin_text_domain( string $handle, string $relative_path ): void {
		$source  = self::read_plugin_file( $relative_path );
		$matched = preg_match(
			'/wp_set_script_translations\(\s*[\'"]' . preg_quote( $handle, '/' ) . '[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
			$source,
			$matches
		);

		$this->assertSame( 1, $matched, sprintf( 'No wp_set_script_translations() call found for "%s".', $handle ) );
		$this->assertSame( self::TEXT_DOMAIN, $matches[1] );
	}

	/**
	 * @dataProvider enqueued_script_handles
	 */
	public function test_script_translations_point_at_the_bundled_languages_directory( string $handle, string $relative_path ): void {
		$source = self::read_plugin_file( $relative_path );

		$this->assertMatchesRegularExpression(
			'/wp_set_script_translations\(\s*[\'"]' . preg_quote( $handle, '/' ) . '[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*,\s*FLAVOR_AGENT_DIR\s*\.\s*[\'"]languages[\'"]/',
			$source,
			sprintf( 'wp_set_script_translations() for "%s" should resolve JSON translations from the plugin languages directory.', $handle )
		);
	}

	public function test_plugin_header_declares_a_domain_path(): void {
		$source = self::read_plugin_file( 'flavor-agent.php' );

		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Domain Path:\s*\/languages\s*$/m',
			$source,
			'Without a Domain Path header WordPress cannot locate the bundled translation files.'
		);
	}

	public function test_languages_directory_ships(): void {
		$this->assertDirectoryExists( dirname( __DIR__, 2 ) . '/languages' );
	}

	public function test_languages_directory_is_not_excluded_from_the_release_build(): void {
		$distignore = self::read_plugin_file( '.distignore' );
		$entries    = array_map( 'trim', explode( "\n", $distignore ) );

		$this->assertNotContains( 'languages', $entries );
		$this->assertNotContains( 'languages/', $entries );
	}

	private static function read_plugin_file( string $relative_path ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $relative_path;

		self::assertFileExists( $path );

		return (string) file_get_contents( $path );
	}
}
