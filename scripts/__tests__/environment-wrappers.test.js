'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const packageJson = require( '../../package.json' );

describe( 'environment wrapper configuration', () => {
	test( 'routes docs freshness through the cross-platform bash wrapper', () => {
		expect( packageJson.scripts[ 'check:docs' ] ).toBe(
			'node scripts/run-bash.js scripts/check-doc-freshness.sh'
		);
	} );

	test( 'quotes Playground host mount paths for workspaces with spaces', () => {
		const rootDir = path.resolve( __dirname, '../..' );
		const configSource = fs.readFileSync(
			path.join( rootDir, 'playwright.config.js' ),
			'utf8'
		);

		expect( configSource ).toContain(
			'`--mount-dir ${ quoteShellArg( pluginDir ) } /wordpress/wp-content/plugins/flavor-agent`'
		);
		expect( configSource ).toContain(
			'`--mount-dir ${ quoteShellArg( muPluginDir ) } /wordpress/wp-content/mu-plugins`'
		);
	} );
} );
