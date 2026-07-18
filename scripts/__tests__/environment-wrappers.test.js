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

	test( 'pulls the mutable WordPress base image before rebuilding the local stack', () => {
		expect( packageJson.scripts[ 'wp:start' ] ).toBe(
			'node scripts/ensure-local-env.js && node scripts/docker-compose.js up -d'
		);
		expect( packageJson.scripts[ 'wp:rebuild' ] ).toBe(
			'node scripts/ensure-local-env.js && node scripts/docker-compose.js build --pull && node scripts/docker-compose.js up -d'
		);
	} );

	test( 'probes the configured loopback listener in the WordPress healthcheck', () => {
		const rootDir = path.resolve( __dirname, '../..' );
		const composeSource = fs.readFileSync(
			path.join( rootDir, 'docker-compose.yml' ),
			'utf8'
		);

		expect( composeSource ).toContain(
			'curl -fsS "http://127.0.0.1:$${WORDPRESS_LOOPBACK_PORT:-8888}/wp-login.php" >/dev/null'
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
