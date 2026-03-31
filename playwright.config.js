const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

const rootDir = __dirname;
const port = Number( process.env.PLAYWRIGHT_PORT || 9402 );
const pluginDir = rootDir;
const muPluginDir = path.join( rootDir, 'tests/e2e/playground-mu-plugin' );

module.exports = defineConfig( {
	testDir: path.join( rootDir, 'tests/e2e' ),
	testIgnore: /.*\.wp70\.setup\.js/,
	timeout: 60_000,
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
			'npx @wp-playground/cli@latest server',
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
