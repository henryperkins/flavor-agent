'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const {
	STEPS,
	collectDiscoveredArtifacts,
	computeCounts,
	computeStatus,
	getLintPluginAvailability,
	loadDotEnvFile,
	parseArgs,
	parseDotEnvLine,
	resolvePluginCheckContext,
	resolveStepAvailability,
	snapshotArtifacts,
} = require( '../verify.js' );

describe( 'verify script helpers', () => {
	test( 'includes the plugin-check step in the aggregate pipeline', () => {
		expect( STEPS.map( ( step ) => step.name ) ).toContain( 'lint-plugin' );
	} );

	test( 'rejects an empty --only option before planning a zero-step run', () => {
		expect( () =>
			parseArgs( [ 'node', 'scripts/verify.js', '--only=' ] )
		).toThrow( 'No steps specified in --only' );
	} );

	test( 'treats an all-skipped run as incomplete instead of passed', () => {
		const results = STEPS.map( ( step ) => ( {
			name: step.name,
			command: step.command,
			status: 'skipped',
			reason: 'excluded via --skip',
			durationMs: 0,
		} ) );

		expect( computeStatus( results ) ).toBe( 'incomplete' );
	} );

	test( 'npm-backed steps require npm to be available', () => {
		const buildStep = STEPS.find( ( step ) => step.name === 'build' );
		const availability = resolveStepAvailability( buildStep, {
			commandExists: ( command ) => command !== 'npm',
		} );

		expect( availability ).toEqual( {
			available: false,
			reason: 'required command not found: npm',
		} );
	} );

	test( 'bails after an incomplete prerequisite skip when requested', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'verify-bail-incomplete-' )
		);

		try {
			const result = spawnSync(
				process.execPath,
				[
					path.resolve( __dirname, '../verify.js' ),
					'--only=lint-plugin,unit',
					'--bail',
					'--json',
					`--output=${ tempRoot }`,
				],
				{
					cwd: path.resolve( __dirname, '../..' ),
					encoding: 'utf8',
					env: { ...process.env, PATH: '' },
				}
			);
			const summary = JSON.parse(
				fs.readFileSync( path.join( tempRoot, 'summary.json' ), 'utf8' )
			);
			const unit = summary.steps.find( ( step ) => step.name === 'unit' );

			expect( result.status ).toBe( 1 );
			expect( summary.status ).toBe( 'incomplete' );
			expect( unit.status ).toBe( 'skipped' );
			expect( unit.reason ).toBe( 'bailed after prior non-pass' );
			expect( unit.incomplete ).toBeUndefined();
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'marks plugin-check unavailable when wp-cli is missing', () => {
		const availability = getLintPluginAvailability( {
			commandExists: ( command ) => command !== 'wp',
		} );

		expect( availability ).toEqual( {
			available: false,
			reason: 'required command not found: wp',
		} );
	} );

	test( 'marks plugin-check unavailable when the WordPress root is absent', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'verify-missing-root-' )
		);

		try {
			const availability = getLintPluginAvailability( {
				env: { WP_PLUGIN_CHECK_PATH: tempRoot },
				commandExists: () => true,
			} );

			expect( availability.available ).toBe( false );
			expect( availability.reason ).toContain(
				'plugin-check WordPress root not found'
			);
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'loads repo .env defaults without overriding existing process env values', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'verify-env-' )
		);
		const envPath = path.join( tempRoot, '.env' );
		const env = {
			WORDPRESS_DB_USER: 'shell-user',
		};

		try {
			fs.writeFileSync(
				envPath,
				[
					'# Local defaults',
					'WP_PLUGIN_CHECK_PATH=/tmp/wp',
					'WORDPRESS_DB_HOST=127.0.0.1:3306',
					'WORDPRESS_DB_USER=env-file-user',
					'WORDPRESS_TITLE="Flavor Agent Local"',
				].join( '\n' )
			);

			const loaded = loadDotEnvFile( envPath, env );

			expect( loaded ).toEqual( {
				WP_PLUGIN_CHECK_PATH: '/tmp/wp',
				WORDPRESS_DB_HOST: '127.0.0.1:3306',
				WORDPRESS_TITLE: 'Flavor Agent Local',
			} );
			expect( env.WORDPRESS_DB_USER ).toBe( 'shell-user' );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'parses shell-style env lines used by local defaults', () => {
		expect(
			parseDotEnvLine( 'export WP_PLUGIN_CHECK_PATH=/tmp/wp' )
		).toEqual( {
			key: 'WP_PLUGIN_CHECK_PATH',
			value: '/tmp/wp',
		} );
		expect(
			parseDotEnvLine( 'WORDPRESS_TITLE="Flavor Agent Local"' )
		).toEqual( {
			key: 'WORDPRESS_TITLE',
			value: 'Flavor Agent Local',
		} );
		expect( parseDotEnvLine( '# comment' ) ).toBeNull();
	} );

	test( 'reports only artifacts changed during the current run', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'verify-artifacts-' )
		);
		const repoRoot = path.resolve( __dirname, '../..' );

		try {
			const changedDir = path.join( tempRoot, 'changed' );
			const unchangedDir = path.join( tempRoot, 'unchanged' );

			fs.mkdirSync( changedDir );
			fs.mkdirSync( unchangedDir );
			fs.writeFileSync(
				path.join( unchangedDir, 'before.txt' ),
				'before'
			);

			const candidates = [ changedDir, unchangedDir ];
			const beforeSnapshot = snapshotArtifacts( candidates );

			const now = new Date();
			fs.writeFileSync( path.join( changedDir, 'after.txt' ), 'after' );
			fs.utimesSync( path.join( changedDir, 'after.txt' ), now, now );

			const discovered = collectDiscoveredArtifacts(
				beforeSnapshot,
				snapshotArtifacts( candidates )
			);

			expect( discovered ).toContain(
				path.relative( repoRoot, changedDir )
			);
			expect( discovered ).not.toContain(
				path.relative( repoRoot, unchangedDir )
			);
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	describe( 'parseArgs', () => {
		test( 'rejects unknown CLI arguments with a useful message', () => {
			expect( () =>
				parseArgs( [ 'node', 'verify.js', '--unknown' ] )
			).toThrow( 'Unknown argument: --unknown' );
		} );

		test( 'rejects unknown step names in --only', () => {
			expect( () =>
				parseArgs( [ 'node', 'verify.js', '--only=does-not-exist' ] )
			).toThrow( 'Unknown step in --only: does-not-exist' );
		} );

		test( 'rejects unknown step names in --skip', () => {
			expect( () =>
				parseArgs( [ 'node', 'verify.js', '--skip=mystery' ] )
			).toThrow( 'Unknown step in --skip: mystery' );
		} );

		test( 'parses combined flags into a normalized options object', () => {
			const opts = parseArgs( [
				'node',
				'verify.js',
				'--bail',
				'--dry-run',
				'--json',
				'--strict',
				'--skip-e2e',
				'--only=build,unit',
				'--skip=lint-plugin',
				'--output=tmp/verify',
			] );

			expect( opts.bail ).toBe( true );
			expect( opts.dryRun ).toBe( true );
			expect( opts.json ).toBe( true );
			expect( opts.strict ).toBe( true );
			expect( opts.skipE2E ).toBe( true );
			expect( opts.only ).toEqual( [ 'build', 'unit' ] );
			expect( opts.skip ).toEqual( [ 'lint-plugin' ] );
			expect( opts.outputDir ).toBe( 'tmp/verify' );
		} );

		test( 'recognizes --help and -h', () => {
			expect( parseArgs( [ 'node', 'verify.js', '--help' ] ).help ).toBe(
				true
			);
			expect( parseArgs( [ 'node', 'verify.js', '-h' ] ).help ).toBe(
				true
			);
		} );
	} );

	describe( 'computeStatus / computeCounts', () => {
		test( 'reports pass when every step passed', () => {
			const results = [
				{ name: 'a', status: 'pass' },
				{ name: 'b', status: 'pass' },
			];
			expect( computeStatus( results ) ).toBe( 'pass' );
			expect( computeCounts( results ) ).toEqual( {
				total: 2,
				passed: 2,
				failed: 0,
				skipped: 0,
			} );
		} );

		test( 'reports fail when any step failed, even alongside incomplete', () => {
			const results = [
				{ name: 'a', status: 'pass' },
				{ name: 'b', status: 'fail' },
				{
					name: 'c',
					status: 'skipped',
					incomplete: true,
					reason: 'missing tool',
				},
			];
			expect( computeStatus( results ) ).toBe( 'fail' );
			expect( computeCounts( results ) ).toEqual( {
				total: 3,
				passed: 1,
				failed: 1,
				skipped: 1,
			} );
		} );

		test( 'reports incomplete when a prerequisite was missing but no failure occurred', () => {
			const results = [
				{ name: 'a', status: 'pass' },
				{
					name: 'b',
					status: 'skipped',
					incomplete: true,
					reason: 'required command not found: wp',
				},
			];
			expect( computeStatus( results ) ).toBe( 'incomplete' );
		} );

		test( 'reports incomplete when no step actually completed', () => {
			const results = [
				{
					name: 'a',
					status: 'skipped',
					reason: 'excluded via --skip',
				},
				{
					name: 'b',
					status: 'skipped',
					reason: 'not in --only',
				},
			];
			expect( computeStatus( results ) ).toBe( 'incomplete' );
			expect( computeCounts( results ) ).toEqual( {
				total: 2,
				passed: 0,
				failed: 0,
				skipped: 2,
			} );
		} );
	} );

	describe( 'parseDotEnvLine edge cases', () => {
		test( 'returns null for blank, whitespace-only, and comment lines', () => {
			expect( parseDotEnvLine( '' ) ).toBeNull();
			expect( parseDotEnvLine( '   \t  ' ) ).toBeNull();
			expect( parseDotEnvLine( '   # leading comment' ) ).toBeNull();
		} );

		test( 'strips matching single-quote wrappers from values', () => {
			expect( parseDotEnvLine( "FOO='bar'" ) ).toEqual( {
				key: 'FOO',
				value: 'bar',
			} );
		} );

		test( 'preserves mismatched quote characters', () => {
			expect( parseDotEnvLine( 'FOO="bar' ) ).toEqual( {
				key: 'FOO',
				value: '"bar',
			} );
		} );

		test( 'returns null for lines without a key/value separator', () => {
			expect( parseDotEnvLine( 'NOT_AN_ASSIGNMENT' ) ).toBeNull();
		} );

		test( 'strips trailing CR for Windows-style line endings', () => {
			expect( parseDotEnvLine( 'FOO=bar\r' ) ).toEqual( {
				key: 'FOO',
				value: 'bar',
			} );
		} );
	} );

	describe( 'loadDotEnvFile', () => {
		test( 'returns an empty object when the env file does not exist', () => {
			const tempRoot = fs.mkdtempSync(
				path.join( os.tmpdir(), 'verify-no-env-' )
			);
			try {
				const env = {};
				expect(
					loadDotEnvFile( path.join( tempRoot, '.env' ), env )
				).toEqual( {} );
				expect( env ).toEqual( {} );
			} finally {
				fs.rmSync( tempRoot, { recursive: true, force: true } );
			}
		} );

		test( 'skips comment lines and unparsable entries', () => {
			const tempRoot = fs.mkdtempSync(
				path.join( os.tmpdir(), 'verify-noisy-env-' )
			);
			const envPath = path.join( tempRoot, '.env' );

			try {
				fs.writeFileSync(
					envPath,
					[ '# comment', '', 'NOT_AN_ASSIGNMENT', 'GOOD=value' ].join(
						'\n'
					)
				);
				const env = {};
				expect( loadDotEnvFile( envPath, env ) ).toEqual( {
					GOOD: 'value',
				} );
				expect( env.GOOD ).toBe( 'value' );
			} finally {
				fs.rmSync( tempRoot, { recursive: true, force: true } );
			}
		} );
	} );

	describe( 'resolvePluginCheckContext', () => {
		test( 'derives wp-config and plugins paths from WP_PLUGIN_CHECK_PATH', () => {
			const tempRoot = fs.mkdtempSync(
				path.join( os.tmpdir(), 'verify-pc-context-' )
			);
			try {
				const context = resolvePluginCheckContext( {
					WP_PLUGIN_CHECK_PATH: tempRoot,
				} );
				expect( context.wpRoot ).toBe( tempRoot );
				expect( context.wpConfigPath ).toBe(
					path.join( tempRoot, 'wp-config.php' )
				);
				expect( context.pluginsDir ).toBe(
					path.join( tempRoot, 'wp-content', 'plugins' )
				);
			} finally {
				fs.rmSync( tempRoot, { recursive: true, force: true } );
			}
		} );

		test( 'falls back to repo-relative ancestor when WP_PLUGIN_CHECK_PATH is unset', () => {
			const context = resolvePluginCheckContext( {} );
			// The fallback resolves three levels up from REPO_ROOT, so it must
			// at least produce an absolute path with the expected suffix.
			expect( path.isAbsolute( context.wpRoot ) ).toBe( true );
			expect(
				context.pluginsDir.endsWith(
					path.join( 'wp-content', 'plugins' )
				)
			).toBe( true );
		} );
	} );

	describe( 'getLintPluginAvailability prerequisite handling', () => {
		test( 'reports missing bash before any other check', () => {
			expect(
				getLintPluginAvailability( {
					commandExists: ( command ) => command !== 'bash',
				} )
			).toEqual( {
				available: false,
				reason: 'required command not found: bash',
			} );
		} );

		test( 'reports a missing plugins directory inside an otherwise-valid WP root', () => {
			const tempRoot = fs.mkdtempSync(
				path.join( os.tmpdir(), 'verify-no-plugins-' )
			);
			try {
				fs.writeFileSync(
					path.join( tempRoot, 'wp-config.php' ),
					'<?php\n'
				);
				const availability = getLintPluginAvailability( {
					env: { WP_PLUGIN_CHECK_PATH: tempRoot },
					commandExists: () => true,
				} );

				expect( availability.available ).toBe( false );
				expect( availability.reason ).toContain(
					'plugin-check plugins directory not found'
				);
			} finally {
				fs.rmSync( tempRoot, { recursive: true, force: true } );
			}
		} );

		test( 'returns the resolved context when every prerequisite is satisfied', () => {
			const tempRoot = fs.mkdtempSync(
				path.join( os.tmpdir(), 'verify-full-pc-' )
			);
			try {
				fs.writeFileSync(
					path.join( tempRoot, 'wp-config.php' ),
					'<?php\n'
				);
				fs.mkdirSync( path.join( tempRoot, 'wp-content', 'plugins' ), {
					recursive: true,
				} );
				const availability = getLintPluginAvailability( {
					env: { WP_PLUGIN_CHECK_PATH: tempRoot },
					commandExists: () => true,
				} );

				expect( availability.available ).toBe( true );
				expect( availability.context.wpRoot ).toBe( tempRoot );
			} finally {
				fs.rmSync( tempRoot, { recursive: true, force: true } );
			}
		} );
	} );

	describe( 'resolveStepAvailability', () => {
		test( 'delegates to checkAvailability after required commands pass', () => {
			const checkAvailability = jest.fn( () => ( {
				available: false,
				reason: 'custom-reason',
			} ) );
			const result = resolveStepAvailability(
				{ requires: 'bash', checkAvailability },
				{ commandExists: () => true }
			);

			expect( checkAvailability ).toHaveBeenCalled();
			expect( result ).toEqual( {
				available: false,
				reason: 'custom-reason',
			} );
		} );

		test( 'short-circuits when a single required command is missing', () => {
			const result = resolveStepAvailability(
				{ requires: 'composer' },
				{ commandExists: ( cmd ) => cmd !== 'composer' }
			);
			expect( result ).toEqual( {
				available: false,
				reason: 'required command not found: composer',
			} );
		} );

		test( 'reports available when no requirements are declared', () => {
			expect(
				resolveStepAvailability( {}, { commandExists: () => false } )
			).toEqual( { available: true } );
		} );
	} );
} );

describe( 'plugin-check.sh prerequisite handling', () => {
	const scriptPath = path.resolve( __dirname, '../plugin-check.sh' );

	test( 'fails fast with a clear message when WP_PLUGIN_CHECK_PATH is not a WordPress root', () => {
		// Skip on environments without bash to keep the suite portable.
		const probe = spawnSync( 'bash', [ '--version' ] );
		if ( probe.status !== 0 ) {
			return;
		}

		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'verify-pc-sh-' )
		);

		try {
			const result = spawnSync( 'bash', [ scriptPath ], {
				encoding: 'utf8',
				env: {
					...process.env,
					WP_PLUGIN_CHECK_PATH: tempRoot,
				},
			} );

			expect( result.status ).toBe( 1 );
			expect( result.stderr ).toContain(
				`Expected a WordPress root at ${ tempRoot }`
			);
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'fails when wp-config.php exists but the plugins directory is missing', () => {
		const probe = spawnSync( 'bash', [ '--version' ] );
		if ( probe.status !== 0 ) {
			return;
		}

		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'verify-pc-sh-no-plugins-' )
		);

		try {
			fs.writeFileSync(
				path.join( tempRoot, 'wp-config.php' ),
				'<?php\n'
			);
			const result = spawnSync( 'bash', [ scriptPath ], {
				encoding: 'utf8',
				env: {
					...process.env,
					WP_PLUGIN_CHECK_PATH: tempRoot,
				},
			} );

			expect( result.status ).toBe( 1 );
			expect( result.stderr ).toContain( 'Expected a plugins directory' );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'stages the plugin outside the WordPress plugins directory', () => {
		const probe = spawnSync( 'bash', [ '--version' ] );
		if ( probe.status !== 0 ) {
			return;
		}

		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'verify-pc-sh-stage-' )
		);
		const wpRoot = path.join( tempRoot, 'wp' );
		const pluginsDir = path.join( wpRoot, 'wp-content', 'plugins' );
		const stageDir = path.join( tempRoot, 'stage' );
		const binDir = path.join( tempRoot, 'bin' );
		const argsFile = path.join( tempRoot, 'wp-args.txt' );

		try {
			fs.mkdirSync( pluginsDir, { recursive: true } );
			fs.mkdirSync( stageDir, { recursive: true } );
			fs.mkdirSync( binDir, { recursive: true } );
			fs.writeFileSync( path.join( wpRoot, 'wp-config.php' ), '<?php\n' );
			fs.writeFileSync(
				path.join( binDir, 'composer' ),
				'#!/usr/bin/env bash\nexit 0\n'
			);
			fs.writeFileSync(
				path.join( binDir, 'wp' ),
				'#!/usr/bin/env bash\nprintf "%s\\n" "$@" > "$WP_ARGS_FILE"\n'
			);
			fs.chmodSync( path.join( binDir, 'composer' ), 0o755 );
			fs.chmodSync( path.join( binDir, 'wp' ), 0o755 );

			const result = spawnSync( 'bash', [ scriptPath, '--format=json' ], {
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${ binDir }${ path.delimiter }${ process.env.PATH }`,
					PLUGIN_CHECK_KEEP_STAGE: '1',
					PLUGIN_CHECK_STAGE_DIR: stageDir,
					WP_ARGS_FILE: argsFile,
					WP_PLUGIN_CHECK_PATH: wpRoot,
				},
			} );

			expect( result.status ).toBe( 0 );

			const wpArgs = fs.readFileSync( argsFile, 'utf8' ).trim().split( '\n' );
			const stagedPluginDir = wpArgs[ 2 ];
			expect( wpArgs[ 0 ] ).toBe( 'plugin' );
			expect( wpArgs[ 1 ] ).toBe( 'check' );
			expect(
				stagedPluginDir.startsWith(
					`${ stageDir }/flavor-agent-plugin-check-`
				)
			).toBe( true );
			expect( stagedPluginDir.startsWith( pluginsDir ) ).toBe( false );
			expect(
				fs.existsSync(
					path.join(
						stagedPluginDir,
						'2026-05-04-231958-local-command-caveatcaveat-the-messages-below.txt'
					)
				)
			).toBe( false );
			expect( wpArgs ).toContain( `--path=${ wpRoot }` );
			expect( wpArgs ).toContain( '--format=json' );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );
} );
