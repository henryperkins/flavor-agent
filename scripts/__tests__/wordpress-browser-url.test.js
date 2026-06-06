'use strict';

const path = require( 'path' );

const {
	assertSameBrowserOrigin,
	normalizeWordPressBrowserBaseUrl,
	resolveWordPressBrowserBaseUrl,
} = require( '../wordpress-browser-url.js' );

describe( 'wordpress-browser-url helpers', () => {
	test( 'normalizes base URLs without changing the canonical host', () => {
		expect(
			normalizeWordPressBrowserBaseUrl( 'http://localhost:8888/' )
		).toBe( 'http://localhost:8888' );
		expect(
			normalizeWordPressBrowserBaseUrl( 'http://127.0.0.1:9404/wp/' )
		).toBe( 'http://127.0.0.1:9404/wp' );
	} );

	test( 'prefers the Docker WordPress home option for browser auth', () => {
		const execFileSync = jest.fn( () =>
			Buffer.from( 'http://localhost:8888\n' )
		);

		expect(
			resolveWordPressBrowserBaseUrl( {
				env: {},
				execFileSync,
				rootDir: '/repo',
			} )
		).toBe( 'http://localhost:8888' );
		expect( execFileSync ).toHaveBeenCalledWith(
			process.execPath,
			expect.arrayContaining( [
				path.join( '/repo', 'scripts', 'docker-compose.js' ),
				'exec',
				'-T',
				'wordpress',
				'wp',
				'option',
				'get',
				'home',
				'--allow-root',
			] ),
			expect.objectContaining( {
				cwd: '/repo',
				encoding: 'utf8',
			} )
		);
	} );

	test( 'falls back to localhost on the configured WordPress port', () => {
		const execFileSync = jest.fn( () => {
			throw new Error( 'Docker is not running.' );
		} );

		expect(
			resolveWordPressBrowserBaseUrl( {
				env: { WORDPRESS_PORT: '8890' },
				execFileSync,
				rootDir: '/repo',
			} )
		).toBe( 'http://localhost:8890' );
	} );

	test( 'honors explicit browser URL overrides', () => {
		const execFileSync = jest.fn();

		expect(
			resolveWordPressBrowserBaseUrl( {
				env: {
					FLAVOR_AGENT_BROWSER_BASE_URL:
						'http://127.0.0.1:8888/',
				},
				execFileSync,
				rootDir: '/repo',
			} )
		).toBe( 'http://127.0.0.1:8888' );
		expect( execFileSync ).not.toHaveBeenCalled();
	} );

	test( 'throws an actionable error when browser and base origins diverge', () => {
		expect( () =>
			assertSameBrowserOrigin(
				'http://localhost:8888',
				'http://127.0.0.1:8888/wp-login.php'
			)
		).toThrow(
			'WordPress browser session origin mismatch: expected http://localhost:8888 but browser is at http://127.0.0.1:8888'
		);
	} );
} );
