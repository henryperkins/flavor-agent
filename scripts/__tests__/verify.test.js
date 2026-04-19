'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const {
	STEPS,
	collectDiscoveredArtifacts,
	getLintPluginAvailability,
	snapshotArtifacts,
} = require( '../verify.js' );

describe( 'verify script helpers', () => {
	test( 'includes the plugin-check step in the aggregate pipeline', () => {
		expect( STEPS.map( ( step ) => step.name ) ).toContain( 'lint-plugin' );
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
