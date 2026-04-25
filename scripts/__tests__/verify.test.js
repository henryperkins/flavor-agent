'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const {
	STEPS,
	collectDiscoveredArtifacts,
	computeStatus,
	getLintPluginAvailability,
	parseArgs,
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
} );
