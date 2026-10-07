'use strict';

const childProcess = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const { parseArgs } = require( '../update-docs-ai-search' );
const {
	buildUpdaterArgs,
	run,
} = require( '../update-docs-ai-search-scheduled' );

// Local-time dates: the weekly stale-deletion pass follows the operator's
// calendar, the same way the scheduled task fires.
const MONDAY = new Date( 2026, 9, 5, 5, 17 );
const TUESDAY = new Date( 2026, 9, 6, 5, 17 );

const CREDENTIALS =
	'CLOUDFLARE_ACCOUNT_ID=acct-123\nCLOUDFLARE_AI_SEARCH_API_TOKEN=secret-token-456\n';

// Stands in for the real updater at the network boundary: it records what it
// was launched with, prints a line, and exits with a chosen code.
const FAKE_UPDATER = `
const fs = require( 'node:fs' );
fs.writeFileSync(
	process.env.FAKE_UPDATER_RECORD,
	JSON.stringify( {
		argv: process.argv.slice( 2 ),
		accountId: process.env.CLOUDFLARE_ACCOUNT_ID || null,
		token: process.env.CLOUDFLARE_AI_SEARCH_API_TOKEN || null,
	} )
);
console.log( 'fake updater ran' );
process.exit( Number( process.env.FAKE_UPDATER_EXIT || 0 ) );
`;

describe( 'scheduled docs corpus arguments', () => {
	test( 'requests the weekly stale-deletion pass only on Mondays', () => {
		expect(
			parseArgs( buildUpdaterArgs( { now: MONDAY } ) )
		).toMatchObject( {
			deleteStale: true,
			pollSeconds: 600,
		} );
		expect(
			parseArgs( buildUpdaterArgs( { now: TUESDAY } ) )
		).toMatchObject( {
			deleteStale: false,
			pollSeconds: 600,
		} );
	} );

	test( 'lets operator flags override the scheduled defaults', () => {
		const options = parseArgs(
			buildUpdaterArgs( {
				now: MONDAY,
				extraArgs: [ '--no-delete', '--dry-run', '--limit=5' ],
			} )
		);

		expect( options ).toMatchObject( {
			deleteStale: false,
			dryRun: true,
			limit: 5,
		} );
	} );
} );

describe( 'scheduled docs corpus run', () => {
	let tmpDir;
	let updaterPath;
	let logDir;
	let recordPath;

	beforeEach( () => {
		tmpDir = fs.mkdtempSync(
			path.join( os.tmpdir(), 'fa-docs-scheduled-' )
		);
		updaterPath = path.join( tmpDir, 'fake-updater.js' );
		logDir = path.join( tmpDir, 'logs' );
		recordPath = path.join( tmpDir, 'record.json' );
		fs.writeFileSync( updaterPath, FAKE_UPDATER );
	} );

	afterEach( () => {
		fs.rmSync( tmpDir, { recursive: true, force: true } );
	} );

	// The runner's own environment must not leak real credentials into a test.
	function baseEnv() {
		const env = { ...process.env, FAKE_UPDATER_RECORD: recordPath };
		for ( const key of Object.keys( env ) ) {
			if (
				key.startsWith( 'CLOUDFLARE_' ) ||
				key === 'FLAVOR_AGENT_DOCS_AI_SEARCH_ENV_FILE'
			) {
				delete env[ key ];
			}
		}
		return env;
	}

	function writeEnvFile( contents ) {
		const envFile = path.join( tmpDir, 'docs-ai-search.env' );
		fs.writeFileSync( envFile, contents );
		return envFile;
	}

	function readRecord() {
		return JSON.parse( fs.readFileSync( recordPath, 'utf8' ) );
	}

	function readOnlyLog() {
		const logs = fs.readdirSync( logDir );
		expect( logs ).toHaveLength( 1 );
		return fs.readFileSync( path.join( logDir, logs[ 0 ] ), 'utf8' );
	}

	function runScheduled( { argv = [], env = {}, now = TUESDAY } = {} ) {
		return run( {
			argv,
			env: { ...baseEnv(), ...env },
			now,
			homedir: tmpDir,
			updaterPath,
			logDir,
			echo: false,
		} );
	}

	test( 'stops before the updater runs when credentials are missing', async () => {
		const code = await runScheduled();

		expect( code ).toBe( 2 );
		expect( fs.existsSync( recordPath ) ).toBe( false );
		expect( readOnlyLog() ).toContain(
			path.join( tmpDir, '.config', 'flavor-agent', 'docs-ai-search.env' )
		);
	} );

	test( 'runs a dry run without credentials', async () => {
		const code = await runScheduled( { argv: [ '--dry-run' ] } );

		expect( code ).toBe( 0 );
		expect( readRecord().argv ).toContain( '--dry-run' );
	} );

	test( 'loads credentials from the env file and passes the scheduled arguments', async () => {
		const envFile = writeEnvFile( CREDENTIALS );

		const code = await runScheduled( {
			env: { FLAVOR_AGENT_DOCS_AI_SEARCH_ENV_FILE: envFile },
		} );

		expect( code ).toBe( 0 );
		expect( readRecord() ).toEqual( {
			argv: [ '--poll-seconds=600' ],
			accountId: 'acct-123',
			token: 'secret-token-456',
		} );
	} );

	test( 'keeps values already in the environment over the env file', async () => {
		const envFile = writeEnvFile( CREDENTIALS );

		await runScheduled( {
			env: {
				FLAVOR_AGENT_DOCS_AI_SEARCH_ENV_FILE: envFile,
				CLOUDFLARE_ACCOUNT_ID: 'acct-from-shell',
			},
		} );

		expect( readRecord().accountId ).toBe( 'acct-from-shell' );
	} );

	test( 'records which commit the run used', async () => {
		const head = childProcess
			.execFileSync( 'git', [ 'rev-parse', 'HEAD' ], {
				cwd: path.resolve( __dirname, '../..' ),
				encoding: 'utf8',
			} )
			.trim();

		await runScheduled( { argv: [ '--dry-run' ] } );

		expect( readOnlyLog() ).toContain( head );
	} );

	test( 'logs updater output without secrets and returns its exit code', async () => {
		const envFile = writeEnvFile( CREDENTIALS );

		const code = await runScheduled( {
			env: {
				FLAVOR_AGENT_DOCS_AI_SEARCH_ENV_FILE: envFile,
				FAKE_UPDATER_EXIT: '3',
			},
		} );

		const log = readOnlyLog();
		expect( code ).toBe( 3 );
		expect( log ).toContain( 'fake updater ran' );
		expect( log ).not.toContain( 'secret-token-456' );
	} );
} );
