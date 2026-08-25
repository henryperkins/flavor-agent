const path = require( 'path' );
const fs = require( 'fs' );
const { defineConfig } = require( '@playwright/test' );

const rootDir = __dirname;
const port = Number( process.env.PLAYWRIGHT_PORT || 9402 );
const pluginDir = rootDir;
const muPluginDir = path.join( rootDir, 'tests/e2e/playground-mu-plugin' );
const playgroundTmpDir = path.join( rootDir, 'output/playground-tmp' );

fs.mkdirSync( playgroundTmpDir, { recursive: true } );

function quoteShellArg( value ) {
	return `"${ String( value ).replace( /"/g, '\\"' ) }"`;
}

// Ensure the SHELL environment variable is set to the detected bash path
// to avoid ENOENT errors on Windows when Playwright tries to spawn a shell
if ( process.platform === 'win32' ) {
	const bashPath = 'C:\\Windows\\System32\\bash.exe';
	if ( require( 'fs' ).existsSync( bashPath ) ) {
		process.env.SHELL = bashPath;
	}
}

module.exports = defineConfig( {
	metadata: {
		flavorAgentHarness: 'playground',
	},
	testDir: path.join( rootDir, 'tests/e2e' ),
	// `__tests__` under tests/e2e belongs to Jest (`npm run test:unit`), which
	// matches any file there. Playwright's default testMatch would also collect
	// `*.test.js` from it and fail at load time on Jest globals.
	testIgnore: [ /.*\.wp70\.setup\.js/, /__tests__/ ],
	// Playground can take close to a minute to finish a cold WordPress boot on
	// this host before the first admin request becomes usable, and WordPress 7.1
	// is meaningfully slower to reach a usable editor than the 6.9.4 build this
	// harness used to pin — enough that individual specs were exhausting a 120s
	// budget under suite load while passing in isolation.
	timeout: 180_000,
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
		// Smoke coverage runs on stable WordPress 7.1 via the MU-plugin loader
		// harness, matching the Docker-backed Site Editor harness and the local
		// dev container. The earlier 6.9.4 pin dated from when 7.0 was still a
		// beta whose editor runtime broke before plugin bootstrap; 7.1 is stable
		// and boots cleanly, so there is no longer a reason to trail the release.
		command: [
			// CLI pinned so a Playground release cannot silently change what the
			// suite verifies. 3.1.13 predates WordPress 7.1 support; bumping this
			// requires rerunning the full playground smoke suite.
			'npx @wp-playground/cli@3.1.51 server',
			`--port ${ port }`,
			'--wp=7.1',
			'--login',
			`--mount-dir ${ quoteShellArg(
				pluginDir
			) } /wordpress/wp-content/plugins/flavor-agent`,
			`--mount-dir ${ quoteShellArg(
				muPluginDir
			) } /wordpress/wp-content/mu-plugins`,
			// Deliberately NOT --verbosity=quiet. The server's own output is the
			// only channel that can explain a boot failure, and CI discarded it
			// for every run before this. Default verbosity is ~20 lines.
		].join( ' ' ),
		env: {
			...process.env,
			TMPDIR: playgroundTmpDir,
			TMP: playgroundTmpDir,
			TEMP: playgroundTmpDir,
		},
		port,
		// Locally this keeps the ~1 minute cold boot out of every run. In CI it
		// must be off: a reused server can be serving a stale mount, which
		// would record a green against code the run never actually exercised.
		reuseExistingServer: ! process.env.CI,
		// Playwright pipes webServer stderr by default but drops stdout, so the
		// Playground CLI's boot log never reached the job log.
		stdout: 'pipe',
		stderr: 'pipe',
		timeout: 120_000,
	},
} );
