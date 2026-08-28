const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );
const { getWp70HarnessConfig } = require( './scripts/wp70-e2e' );

const rootDir = __dirname;
const harness = getWp70HarnessConfig( rootDir );

module.exports = defineConfig( {
	metadata: {
		flavorAgentHarness: 'wp70',
	},
	testDir: path.join( rootDir, 'tests/e2e' ),
	timeout: 120_000,
	workers: 1,
	retries: 0,
	outputDir: path.join( rootDir, 'output/playwright-wp70' ),
	globalSetup: path.join( rootDir, 'tests/e2e/wp70.global-setup.js' ),
	use: {
		baseURL: harness.baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /.*\.wp70\.setup\.js/,
		},
		{
			name: 'wp70-site-editor',
			dependencies: [ 'setup' ],
			grep: /@wp70-site-editor/,
			// See the matching note in playwright.config.js: `__tests__` under
			// tests/e2e is Jest's, not Playwright's.
			testIgnore: [ /.*\.wp70\.setup\.js/, /__tests__/ ],
			use: {
				storageState: harness.storageStatePath,
			},
		},
	],
} );
