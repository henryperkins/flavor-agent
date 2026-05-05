const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

const rootDir = __dirname;
const port = Number( process.env.PLAYWRIGHT_PORT || 9402 );
const pluginDir = rootDir;
const muPluginDir = path.join( rootDir, 'tests/e2e/playground-mu-plugin' );

// Ensure the SHELL environment variable is set to the detected bash path
// to avoid ENOENT errors on Windows when Playwright tries to spawn a shell
if ( process.platform === 'win32' ) {
	const bashPath = 'C:\\Windows\\System32\\bash.exe';
	if ( require( 'fs' ).existsSync( bashPath ) ) {
		process.env.SHELL = bashPath;
	}
}

module.exports = defineConfig( {
	testDir: path.join( rootDir, 'tests/e2e' ),
	testIgnore: /.*\.wp70\.setup\.js/,
	// Playground can take close to a minute to finish a cold WordPress boot on
	// this host before the first admin request becomes usable.
	timeout: 120_000,
	workers: 1,
	retries: 0,
	grepInvert: /@wp70-site-editor/,
	outputDir: path.join( rootDir, 'output/playwright' ),
	use: {
		baseURL: `http://127.0.0.1:${ port }`,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	webServer: {
		// Playground's current 7.0 beta editor runtime breaks before plugin bootstrap,
		// so smoke coverage stays on stable 6.9.4 via the MU-plugin loader harness.
		command: [
			// Pinned to the last-known-good CLI from the 2026-03-27 green run.
			// Bumping this requires rerunning the full playground smoke suite.
			'npx @wp-playground/cli@3.1.13 server',
			`--port ${ port }`,
			'--wp=6.9.4',
			'--login',
			`--mount-dir ${ pluginDir } /wordpress/wp-content/plugins/flavor-agent`,
			`--mount-dir ${ muPluginDir } /wordpress/wp-content/mu-plugins`,
			'--verbosity=quiet',
		].join( ' ' ),
		port,
		reuseExistingServer: true,
		timeout: 120_000,
	},
} );
