#!/usr/bin/env node
'use strict';

/*
 * Runs the repository's full verification pipeline (build, lint, unit, PHP, E2E)
 * and emits a machine-readable JSON summary plus per-step log artifacts.
 *
 * Usage:
 *   node scripts/verify.js                      # run every step
 *   node scripts/verify.js --skip-e2e           # skip both Playwright suites
 *   node scripts/verify.js --only=build,unit    # run a subset
 *   node scripts/verify.js --skip=lint-plugin   # skip a specific step
 *   node scripts/verify.js --bail               # stop at first failure
 *   node scripts/verify.js --strict             # include the strict/full profile
 *   node scripts/verify.js --dry-run            # print planned steps and exit
 *   node scripts/verify.js --json               # suppress per-step streaming
 *   node scripts/verify.js --output=path/dir    # override output directory
 *
 * Exit codes: 0 pass, 1 fail/incomplete, 2 usage error.
 */

const fs = require( 'fs' );
const fsp = require( 'fs/promises' );
const path = require( 'path' );
const { spawn, spawnSync } = require( 'child_process' );

const REPO_ROOT = path.resolve( __dirname, '..' );
const DEFAULT_OUTPUT_REL = path.join( 'output', 'verify' );
const ARTIFACT_CANDIDATES = [
	path.join( REPO_ROOT, 'build' ),
	path.join( REPO_ROOT, 'output', 'playwright' ),
	path.join( REPO_ROOT, 'playwright-report' ),
	path.join( REPO_ROOT, 'test-results' ),
	path.join( REPO_ROOT, '.phpunit.result.cache' ),
];

const STEPS = [
	{
		name: 'build',
		command: 'npm run build',
		requires: [ 'node', 'npm' ],
	},
	{
		name: 'lint-js',
		command: 'npm run lint:js',
		requires: [ 'node', 'npm' ],
	},
	{
		name: 'lint-plugin',
		command: 'npm run lint:plugin',
		requires: [ 'node', 'npm' ],
		checkAvailability: getLintPluginAvailability,
	},
	{
		name: 'unit',
		command: 'npm run test:unit -- --runInBand',
		requires: [ 'node', 'npm' ],
	},
	{
		name: 'lint-php',
		command: 'composer lint:php',
		requires: 'composer',
	},
	{
		name: 'check-docs',
		command: 'npm run check:docs',
		requires: [ 'node', 'npm' ],
		optional: true,
	},
	{
		name: 'test-php',
		command: 'composer test:php',
		requires: 'composer',
	},
	{
		name: 'e2e-playground',
		command: 'npm run test:e2e:playground',
		requires: [ 'node', 'npm' ],
		tag: 'e2e',
	},
	{
		name: 'e2e-wp70',
		command: 'npm run test:e2e:wp70',
		requires: [ 'node', 'npm' ],
		tag: 'e2e',
	},
];

const HELP = `flavor-agent verify — run build, lint, unit, PHP, and E2E checks.

Usage:
  node scripts/verify.js [options]

Options:
  --only=<steps>     Comma-separated list of step names to run.
  --skip=<steps>     Comma-separated list of step names to skip.
  --skip-e2e         Skip both Playwright suites (shorthand).
  --bail             Stop at the first failing step.
  --dry-run          Print planned steps as JSON and exit.
  --json             Suppress streaming; print final one-line JSON only.
  --output=<dir>     Directory for logs and summary (default: output/verify).
  --help, -h         Show this message.
  --strict           Include optional verification steps (for example docs checks).

Steps (in execution order):
  ${ STEPS.map( ( s ) => s.name ).join( ', ' ) }

Artifacts:
  <output>/summary.json            full structured run report
  <output>/<step>.stdout.log       per-step stdout
  <output>/<step>.stderr.log       per-step stderr

Exit codes:
  0  all requested steps passed
  1  one or more steps failed or were implicitly skipped (missing tool)
  2  usage / argument error
`;

function parseArgs( argv ) {
	const opts = {
		only: null,
		skip: [],
		skipE2E: false,
		bail: false,
		dryRun: false,
		json: false,
		help: false,
		strict: false,
		outputDir: DEFAULT_OUTPUT_REL,
	};
	for ( const arg of argv.slice( 2 ) ) {
		if ( arg === '--help' || arg === '-h' ) {
			opts.help = true;
		} else if ( arg === '--skip-e2e' ) {
			opts.skipE2E = true;
		} else if ( arg === '--bail' ) {
			opts.bail = true;
		} else if ( arg === '--dry-run' ) {
			opts.dryRun = true;
		} else if ( arg === '--json' ) {
			opts.json = true;
		} else if ( arg === '--strict' ) {
			opts.strict = true;
		} else if ( arg.startsWith( '--only=' ) ) {
			opts.only = arg
				.slice( '--only='.length )
				.split( ',' )
				.map( ( s ) => s.trim() )
				.filter( Boolean );
		} else if ( arg.startsWith( '--skip=' ) ) {
			opts.skip = arg
				.slice( '--skip='.length )
				.split( ',' )
				.map( ( s ) => s.trim() )
				.filter( Boolean );
		} else if ( arg.startsWith( '--output=' ) ) {
			opts.outputDir = arg.slice( '--output='.length );
		} else {
			throw new Error( `Unknown argument: ${ arg }` );
		}
	}
	if ( opts.only && opts.only.length === 0 ) {
		throw new Error( 'No steps specified in --only' );
	}
	const known = new Set( STEPS.map( ( s ) => s.name ) );
	for ( const name of opts.only || [] ) {
		if ( ! known.has( name ) ) {
			throw new Error( `Unknown step in --only: ${ name }` );
		}
	}
	for ( const name of opts.skip ) {
		if ( ! known.has( name ) ) {
			throw new Error( `Unknown step in --skip: ${ name }` );
		}
	}
	return opts;
}

function hasCommand( cmd ) {
	const probeCmd = process.platform === 'win32' ? 'where' : 'which';
	const probe = spawnSync( probeCmd, [ cmd ], { stdio: 'ignore' } );
	return probe.status === 0;
}

function parseDotEnvLine( line ) {
	const normalizedLine = line.replace( /\r$/, '' );

	if (
		! normalizedLine.trim() ||
		normalizedLine.trimStart().startsWith( '#' )
	) {
		return null;
	}

	const match = normalizedLine.match(
		/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/
	);

	if ( ! match ) {
		return null;
	}

	let value = match[ 2 ].trim();

	if (
		( value.startsWith( '"' ) && value.endsWith( '"' ) ) ||
		( value.startsWith( "'" ) && value.endsWith( "'" ) )
	) {
		value = value.slice( 1, -1 );
	}

	return {
		key: match[ 1 ],
		value,
	};
}

function loadDotEnvFile(
	envPath = path.join( REPO_ROOT, '.env' ),
	env = process.env
) {
	if ( ! fs.existsSync( envPath ) ) {
		return {};
	}

	const loaded = {};
	const contents = fs.readFileSync( envPath, 'utf8' );

	for ( const line of contents.split( '\n' ) ) {
		const parsed = parseDotEnvLine( line );

		if (
			! parsed ||
			Object.prototype.hasOwnProperty.call( env, parsed.key )
		) {
			continue;
		}

		env[ parsed.key ] = parsed.value;
		loaded[ parsed.key ] = parsed.value;
	}

	return loaded;
}

function resolvePluginCheckContext( env = process.env ) {
	const configuredRoot = env.WP_PLUGIN_CHECK_PATH
		? path.resolve( REPO_ROOT, env.WP_PLUGIN_CHECK_PATH )
		: path.resolve( REPO_ROOT, '..', '..', '..' );

	return {
		wpRoot: configuredRoot,
		wpConfigPath: path.join( configuredRoot, 'wp-config.php' ),
		pluginsDir: path.join( configuredRoot, 'wp-content', 'plugins' ),
	};
}

function getLintPluginAvailability( {
	env = process.env,
	commandExists = hasCommand,
} = {} ) {
	if ( ! commandExists( 'bash' ) ) {
		return { available: false, reason: 'required command not found: bash' };
	}
	if ( ! commandExists( 'wp' ) ) {
		return { available: false, reason: 'required command not found: wp' };
	}

	const context = resolvePluginCheckContext( env );
	if ( ! fs.existsSync( context.wpConfigPath ) ) {
		return {
			available: false,
			reason: `plugin-check WordPress root not found: ${ context.wpRoot } (set WP_PLUGIN_CHECK_PATH)`,
		};
	}
	if ( ! fs.existsSync( context.pluginsDir ) ) {
		return {
			available: false,
			reason: `plugin-check plugins directory not found: ${ context.pluginsDir }`,
		};
	}

	return {
		available: true,
		context,
	};
}

function resolveStepAvailability(
	step,
	{ env = process.env, commandExists = hasCommand } = {}
) {
	let requiredCommands = [];

	if ( Array.isArray( step.requires ) ) {
		requiredCommands = step.requires;
	} else if ( step.requires ) {
		requiredCommands = [ step.requires ];
	}

	for ( const command of requiredCommands ) {
		if ( ! commandExists( command ) ) {
			return {
				available: false,
				reason: `required command not found: ${ command }`,
			};
		}
	}

	if ( typeof step.checkAvailability === 'function' ) {
		return step.checkAvailability( { env, commandExists } );
	}

	return { available: true };
}

function closeStream( stream ) {
	return new Promise( ( resolve ) => {
		stream.end( () => resolve() );
	} );
}

function buildShellInvocation( command ) {
	if ( process.platform === 'win32' ) {
		return {
			file: process.env.ComSpec || 'cmd.exe',
			args: [ '/d', '/s', '/c', command ],
			windowsVerbatimArguments: true,
		};
	}
	return {
		file: '/bin/sh',
		args: [ '-c', command ],
		windowsVerbatimArguments: false,
	};
}

function runStep( step, { outputDir, streaming } ) {
	return new Promise( ( resolve ) => {
		const startedAt = new Date();
		const startMs = Date.now();
		const stdoutPath = path.join( outputDir, `${ step.name }.stdout.log` );
		const stderrPath = path.join( outputDir, `${ step.name }.stderr.log` );
		const outStream = fs.createWriteStream( stdoutPath );
		const errStream = fs.createWriteStream( stderrPath );

		const invocation = buildShellInvocation( step.command );
		const child = spawn( invocation.file, invocation.args, {
			cwd: REPO_ROOT,
			env: process.env,
			windowsVerbatimArguments: invocation.windowsVerbatimArguments,
		} );

		child.stdout.on( 'data', ( chunk ) => {
			outStream.write( chunk );
			if ( streaming ) {
				process.stdout.write( chunk );
			}
		} );
		child.stderr.on( 'data', ( chunk ) => {
			errStream.write( chunk );
			if ( streaming ) {
				process.stderr.write( chunk );
			}
		} );

		const finalize = async ( partial ) => {
			await Promise.all( [
				closeStream( outStream ),
				closeStream( errStream ),
			] );
			resolve( {
				name: step.name,
				command: step.command,
				durationMs: Date.now() - startMs,
				startedAt: startedAt.toISOString(),
				finishedAt: new Date().toISOString(),
				stdoutPath: path.relative( REPO_ROOT, stdoutPath ),
				stderrPath: path.relative( REPO_ROOT, stderrPath ),
				...partial,
			} );
		};

		child.on( 'close', ( code, signal ) => {
			finalize( {
				status: code === 0 ? 'pass' : 'fail',
				exitCode: code,
				signal: signal || null,
			} );
		} );
		child.on( 'error', ( err ) => {
			finalize( {
				status: 'fail',
				exitCode: null,
				signal: null,
				error: err.message,
			} );
		} );
	} );
}

function collectEnvironment() {
	const env = {
		node: process.version,
		platform: process.platform,
		arch: process.arch,
	};
	const stripAnsi = ( str ) => str.replace( /\u001b\[[0-9;]*m/g, '' );
	const capture = ( bin, args ) => {
		const invocation = buildShellInvocation( [ bin, ...args ].join( ' ' ) );
		const res = spawnSync( invocation.file, invocation.args, {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			windowsVerbatimArguments: invocation.windowsVerbatimArguments,
		} );
		if ( res.status === 0 && res.stdout ) {
			return stripAnsi( res.stdout.trim().split( /\r?\n/ )[ 0 ] );
		}
		return null;
	};
	const npmVersion = capture( 'npm', [ '--version' ] );
	if ( npmVersion ) {
		env.npm = npmVersion;
	}
	const composerVersion = capture( 'composer', [ '--version' ] );
	if ( composerVersion ) {
		env.composer = composerVersion;
	}
	const phpVersion = capture( 'php', [ '--version' ] );
	if ( phpVersion ) {
		env.php = phpVersion;
	}
	const dockerVersion = capture( 'docker', [ '--version' ] );
	if ( dockerVersion ) {
		env.docker = dockerVersion;
	}
	return env;
}

function getArtifactFingerprint( candidatePath ) {
	if ( ! fs.existsSync( candidatePath ) ) {
		return null;
	}

	const stat = fs.statSync( candidatePath );
	if ( ! stat.isDirectory() ) {
		return {
			type: 'file',
			size: stat.size,
			mtimeMs: stat.mtimeMs,
		};
	}

	let latestMtimeMs = stat.mtimeMs;
	let fileCount = 0;
	let dirCount = 1;
	let totalSize = 0;
	const queue = [ candidatePath ];

	while ( queue.length ) {
		const currentDir = queue.pop();
		const entries = fs.readdirSync( currentDir, { withFileTypes: true } );
		for ( const entry of entries ) {
			const fullPath = path.join( currentDir, entry.name );
			const entryStat = fs.statSync( fullPath );
			latestMtimeMs = Math.max( latestMtimeMs, entryStat.mtimeMs );

			if ( entry.isDirectory() ) {
				dirCount++;
				queue.push( fullPath );
				continue;
			}

			fileCount++;
			totalSize += entryStat.size;
		}
	}

	return {
		type: 'directory',
		latestMtimeMs,
		fileCount,
		dirCount,
		totalSize,
	};
}

function snapshotArtifacts( candidates = ARTIFACT_CANDIDATES ) {
	const snapshot = {};
	for ( const candidatePath of candidates ) {
		snapshot[ path.relative( REPO_ROOT, candidatePath ) ] =
			getArtifactFingerprint( candidatePath );
	}
	return snapshot;
}

function artifactFingerprintChanged( before, after ) {
	return JSON.stringify( before ) !== JSON.stringify( after );
}

function collectDiscoveredArtifacts(
	beforeSnapshot = {},
	afterSnapshot = snapshotArtifacts()
) {
	return Object.entries( afterSnapshot )
		.filter( ( [ relativePath, afterFingerprint ] ) => {
			if ( ! afterFingerprint ) {
				return false;
			}
			return artifactFingerprintChanged(
				beforeSnapshot[ relativePath ] || null,
				afterFingerprint
			);
		} )
		.map( ( [ relativePath ] ) => relativePath );
}

function computeStatus( results ) {
	let failed = 0;
	let incomplete = false;
	let completed = 0;
	for ( const r of results ) {
		if ( r.status === 'fail' ) {
			failed++;
			completed++;
		} else if ( r.incomplete ) {
			incomplete = true;
		} else if ( r.status === 'pass' ) {
			completed++;
		}
	}
	if ( failed > 0 ) {
		return 'fail';
	}
	if ( incomplete ) {
		return 'incomplete';
	}
	if ( completed === 0 ) {
		return 'incomplete';
	}
	return 'pass';
}

function computeCounts( results ) {
	const counts = { total: results.length, passed: 0, failed: 0, skipped: 0 };
	for ( const r of results ) {
		if ( r.status === 'pass' ) {
			counts.passed++;
		} else if ( r.status === 'fail' ) {
			counts.failed++;
		} else {
			counts.skipped++;
		}
	}
	return counts;
}

async function main() {
	let opts;
	try {
		opts = parseArgs( process.argv );
	} catch ( err ) {
		process.stderr.write( `${ err.message }\nUse --help for usage.\n` );
		process.exit( 2 );
	}
	if ( opts.help ) {
		process.stdout.write( HELP );
		process.exit( 0 );
	}

	loadDotEnvFile();

	const outputDirAbs = path.isAbsolute( opts.outputDir )
		? opts.outputDir
		: path.join( REPO_ROOT, opts.outputDir );
	await fsp.mkdir( outputDirAbs, { recursive: true } );
	const artifactSnapshotBefore = snapshotArtifacts();

	const plan = [];
	for ( const step of STEPS ) {
		let included = true;
		let reason = null;
		if ( opts.only && ! opts.only.includes( step.name ) ) {
			included = false;
			reason = 'not in --only';
		} else if ( ! opts.strict && step.optional ) {
			included = false;
			reason = 'excluded unless --strict';
		} else if ( opts.skip.includes( step.name ) ) {
			included = false;
			reason = 'excluded via --skip';
		} else if ( opts.skipE2E && step.tag === 'e2e' ) {
			included = false;
			reason = 'excluded via --skip-e2e';
		}
		plan.push( { step, included, excludeReason: reason } );
	}

	if ( opts.dryRun ) {
		const planned = plan
			.filter( ( p ) => p.included )
			.map( ( p ) => ( { name: p.step.name, command: p.step.command } ) );
		const skipped = plan
			.filter( ( p ) => ! p.included )
			.map( ( p ) => ( {
				name: p.step.name,
				reason: p.excludeReason,
			} ) );
		process.stdout.write(
			JSON.stringify(
				{
					dryRun: true,
					outputDir: path.relative( REPO_ROOT, outputDirAbs ),
					steps: planned,
					skipped,
				},
				null,
				2
			) + '\n'
		);
		process.exit( 0 );
	}

	const overallStart = Date.now();
	const overallStartedAt = new Date();
	const results = [];
	let bailed = false;

	for ( const entry of plan ) {
		const { step, included, excludeReason } = entry;
		if ( ! included ) {
			results.push( {
				name: step.name,
				command: step.command,
				status: 'skipped',
				reason: excludeReason,
				durationMs: 0,
			} );
			continue;
		}
		if ( bailed ) {
			results.push( {
				name: step.name,
				command: step.command,
				status: 'skipped',
				reason: 'bailed after prior non-pass',
				durationMs: 0,
			} );
			continue;
		}
		const availability = resolveStepAvailability( step );
		if ( ! availability.available ) {
			results.push( {
				name: step.name,
				command: step.command,
				status: 'skipped',
				reason: availability.reason,
				incomplete: true,
				durationMs: 0,
			} );
			if ( opts.bail ) {
				bailed = true;
			}
			continue;
		}
		if ( ! opts.json ) {
			process.stdout.write(
				`\n[RUN] ${ step.name } — ${ step.command }\n`
			);
		}
		const result = await runStep( step, {
			outputDir: outputDirAbs,
			streaming: ! opts.json,
		} );
		results.push( result );
		if ( ! opts.json ) {
			const tag = result.status === 'pass' ? '[PASS]' : '[FAIL]';
			process.stdout.write(
				`${ tag } ${ step.name } (${ result.durationMs }ms, exit=${ result.exitCode })\n`
			);
		}
		if ( opts.bail && result.status === 'fail' ) {
			bailed = true;
		}
	}

	const counts = computeCounts( results );
	const status = computeStatus( results );
	const summaryPath = path.join( outputDirAbs, 'summary.json' );

	const summary = {
		schemaVersion: 1,
		generatedAt: new Date().toISOString(),
		startedAt: overallStartedAt.toISOString(),
		durationMs: Date.now() - overallStart,
		status,
		counts,
		options: {
			only: opts.only,
			skip: opts.skip,
			skipE2E: opts.skipE2E,
			bail: opts.bail,
			strict: opts.strict,
		},
		steps: results,
		artifacts: {
			summaryPath: path.relative( REPO_ROOT, summaryPath ),
			directory: path.relative( REPO_ROOT, outputDirAbs ),
			discovered: collectDiscoveredArtifacts( artifactSnapshotBefore ),
		},
		environment: collectEnvironment(),
	};

	await fsp.writeFile(
		summaryPath,
		JSON.stringify( summary, null, 2 ) + '\n',
		'utf8'
	);

	const oneLine = {
		status,
		summaryPath: path.relative( REPO_ROOT, summaryPath ),
		counts,
	};
	process.stdout.write( `VERIFY_RESULT=${ JSON.stringify( oneLine ) }\n` );

	process.exit( status === 'pass' ? 0 : 1 );
}

if ( require.main === module ) {
	main().catch( ( err ) => {
		process.stderr.write(
			`verify crashed: ${ err.stack || err.message }\n`
		);
		process.exit( 2 );
	} );
}

module.exports = {
	ARTIFACT_CANDIDATES,
	STEPS,
	collectDiscoveredArtifacts,
	computeCounts,
	computeStatus,
	getArtifactFingerprint,
	getLintPluginAvailability,
	loadDotEnvFile,
	parseArgs,
	parseDotEnvLine,
	resolvePluginCheckContext,
	resolveStepAvailability,
	snapshotArtifacts,
};
