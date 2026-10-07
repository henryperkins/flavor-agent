#!/usr/bin/env node
'use strict';

/**
 * Scheduled entry point for the Developer Docs corpus updater.
 *
 * GitHub Actions cannot run for this repository's account, so the daily refresh
 * that `.github/workflows/update-docs-ai-search.yml` used to perform runs from a
 * local scheduler instead (see scripts/register-docs-ai-search-task.ps1 and the
 * public corpus runbook). This wrapper keeps the workflow's behavior: a 600-second
 * settlement budget, the weekly stale-deletion pass on Mondays, and credentials
 * supplied from outside the repository. A scheduled run has no console to read,
 * so every run is logged under output/docs-ai-search/logs/.
 */

const childProcess = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const util = require( 'node:util' );

const REPO_ROOT = path.resolve( __dirname, '..' );
const UPDATER_PATH = path.join( __dirname, 'update-docs-ai-search.js' );
const LOG_DIR = path.join( REPO_ROOT, 'output', 'docs-ai-search', 'logs' );
const ENV_FILE_VARIABLE = 'FLAVOR_AGENT_DOCS_AI_SEARCH_ENV_FILE';
const MONDAY = 1;
// Distinguishes "never reached the updater" from the updater's own failure (1).
const EXIT_MISSING_CREDENTIALS = 2;

function buildUpdaterArgs( { now = new Date(), extraArgs = [] } = {} ) {
	const args = [ '--poll-seconds=600' ];
	if ( now.getDay() === MONDAY ) {
		args.push( '--delete-stale' );
	}
	// The updater applies flags in order, so operator flags go last and win:
	// an explicit --no-delete suppresses a Monday pass.
	return [ ...args, ...extraArgs ];
}

// Values already in the environment win over the file, as with node --env-file.
function loadEnvFile( envFile, env ) {
	if ( ! fs.existsSync( envFile ) ) {
		return { env, loaded: false };
	}
	const fileValues = util.parseEnv( fs.readFileSync( envFile, 'utf8' ) );
	return { env: { ...fileValues, ...env }, loaded: true };
}

function missingCredentials( env ) {
	return [ 'CLOUDFLARE_ACCOUNT_ID', 'CLOUDFLARE_AI_SEARCH_API_TOKEN' ].filter(
		( name ) => ! env[ name ]
	);
}

function describeCheckout( cwd ) {
	const git = ( args ) => {
		const result = childProcess.spawnSync( 'git', args, {
			cwd,
			encoding: 'utf8',
		} );
		return result.status === 0 ? result.stdout.trim() : '';
	};
	const sha = git( [ 'rev-parse', 'HEAD' ] );
	if ( ! sha ) {
		return 'unknown (not a git checkout)';
	}
	return `${ sha } (${ git( [ 'rev-parse', '--abbrev-ref', 'HEAD' ] ) })`;
}

function runUpdater( { updaterPath, args, cwd, env, write } ) {
	return new Promise( ( resolve ) => {
		const child = childProcess.spawn(
			process.execPath,
			[ updaterPath, ...args ],
			{
				cwd,
				env,
				stdio: [ 'ignore', 'pipe', 'pipe' ],
			}
		);
		child.stdout.on( 'data', ( chunk ) => write( chunk, process.stdout ) );
		child.stderr.on( 'data', ( chunk ) => write( chunk, process.stderr ) );
		child.on( 'error', ( error ) => {
			write(
				`Could not start the updater: ${ error.message }\n`,
				process.stderr
			);
			resolve( 1 );
		} );
		child.on( 'close', ( code ) => resolve( code ?? 1 ) );
	} );
}

async function run( {
	argv = process.argv.slice( 2 ),
	env = process.env,
	now = new Date(),
	homedir = os.homedir(),
	updaterPath = UPDATER_PATH,
	logDir = LOG_DIR,
	cwd = REPO_ROOT,
	echo = true,
} = {} ) {
	fs.mkdirSync( logDir, { recursive: true } );
	const logPath = path.join(
		logDir,
		`scheduled-${ now.toISOString().replace( /[:.]/g, '-' ) }.log`
	);
	const log = fs.createWriteStream( logPath );
	const write = ( text, stream = process.stdout ) => {
		log.write( text );
		if ( echo ) {
			stream.write( text );
		}
	};

	const envFile =
		env[ ENV_FILE_VARIABLE ] ||
		path.join( homedir, '.config', 'flavor-agent', 'docs-ai-search.env' );
	const { env: childEnv, loaded } = loadEnvFile( envFile, env );
	const args = buildUpdaterArgs( { now, extraArgs: argv } );

	write(
		[
			`Scheduled docs corpus update started ${ now.toISOString() }`,
			`Checkout: ${ describeCheckout( cwd ) }`,
			`Env file: ${ envFile } (${ loaded ? 'loaded' : 'not found' })`,
			`Updater args: ${ args.join( ' ' ) }`,
			'',
		].join( '\n' )
	);

	const missing = args.includes( '--dry-run' )
		? []
		: missingCredentials( childEnv );
	let exitCode;
	if ( missing.length > 0 ) {
		write(
			`Missing ${ missing.join( ', ' ) }. Add them to ${ envFile } ` +
				'(see docs/reference/developer-docs-public-corpus-runbook.md).\n',
			process.stderr
		);
		exitCode = EXIT_MISSING_CREDENTIALS;
	} else {
		exitCode = await runUpdater( {
			updaterPath,
			args,
			cwd,
			env: childEnv,
			write,
		} );
	}

	write(
		`\nFinished ${ new Date().toISOString() } with exit code ${ exitCode }\n`
	);
	await new Promise( ( resolve ) => log.end( resolve ) );
	return exitCode;
}

if ( require.main === module ) {
	run().then( ( code ) => process.exit( code ) );
}

module.exports = {
	buildUpdaterArgs,
	run,
};
