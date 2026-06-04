'use strict';

const path = require( 'path' );
const { execFileSync: defaultExecFileSync } = require( 'child_process' );

const DEFAULT_WORDPRESS_PORT = '8888';
const EXPLICIT_BROWSER_URL_ENV_KEYS = [
	'FLAVOR_AGENT_BROWSER_BASE_URL',
	'FLAVOR_AGENT_BASE_URL',
	'WORDPRESS_BROWSER_URL',
];

function normalizeWordPressBrowserBaseUrl( value ) {
	const rawValue = String( value || '' ).trim();

	if ( ! rawValue ) {
		return '';
	}

	const url = new URL( rawValue );
	const pathname = url.pathname.replace( /\/+$/, '' );

	return `${ url.origin }${ pathname && pathname !== '/' ? pathname : '' }`;
}

function readDockerWordPressHomeUrl( {
	rootDir = path.resolve( __dirname, '..' ),
	execFileSync = defaultExecFileSync,
} = {} ) {
	try {
		const output = execFileSync(
			process.execPath,
			[
				path.join( rootDir, 'scripts', 'docker-compose.js' ),
				'exec',
				'-T',
				'wordpress',
				'wp',
				'option',
				'get',
				'home',
				'--allow-root',
			],
			{
				cwd: rootDir,
				encoding: 'utf8',
				stdio: [ 'ignore', 'pipe', 'ignore' ],
			}
		);

		return normalizeWordPressBrowserBaseUrl( output );
	} catch {
		return '';
	}
}

function resolveExplicitBrowserBaseUrl( env ) {
	for ( const key of EXPLICIT_BROWSER_URL_ENV_KEYS ) {
		const value = env?.[ key ];

		if ( value ) {
			return normalizeWordPressBrowserBaseUrl( value );
		}
	}

	return '';
}

function resolveWordPressBrowserBaseUrl( {
	env = process.env,
	rootDir = path.resolve( __dirname, '..' ),
	execFileSync = defaultExecFileSync,
} = {} ) {
	const explicitUrl = resolveExplicitBrowserBaseUrl( env );

	if ( explicitUrl ) {
		return explicitUrl;
	}

	const dockerHomeUrl = readDockerWordPressHomeUrl( {
		rootDir,
		execFileSync,
	} );

	if ( dockerHomeUrl ) {
		return dockerHomeUrl;
	}

	const wordpressUrl = env?.WORDPRESS_URL
		? normalizeWordPressBrowserBaseUrl( env.WORDPRESS_URL )
		: '';

	if ( wordpressUrl ) {
		return wordpressUrl;
	}

	return normalizeWordPressBrowserBaseUrl(
		`http://localhost:${ env?.WORDPRESS_PORT || DEFAULT_WORDPRESS_PORT }`
	);
}

function assertSameBrowserOrigin(
	expectedBaseUrl,
	currentUrl,
	{ label = 'WordPress browser session' } = {}
) {
	const expected = new URL(
		normalizeWordPressBrowserBaseUrl( expectedBaseUrl )
	);
	const actual = new URL( currentUrl );

	if ( expected.origin === actual.origin ) {
		return;
	}

	throw new Error(
		`${ label } origin mismatch: expected ${ expected.origin } but browser is at ${ actual.origin }. Use the WordPress canonical browser URL so auth cookies and WordPress redirects stay on the same host.`
	);
}

async function assertPageOriginMatchesBaseUrl( page, baseUrl, options = {} ) {
	if ( ! page || typeof page.url !== 'function' ) {
		throw new Error( 'A Playwright page with a url() method is required.' );
	}

	assertSameBrowserOrigin( baseUrl, page.url(), options );
}

if ( require.main === module ) {
	try {
		process.stdout.write( `${ resolveWordPressBrowserBaseUrl() }\n` );
	} catch ( error ) {
		process.stderr.write( `${ error?.message || error }\n` );
		process.exitCode = 1;
	}
}

module.exports = {
	assertPageOriginMatchesBaseUrl,
	assertSameBrowserOrigin,
	normalizeWordPressBrowserBaseUrl,
	readDockerWordPressHomeUrl,
	resolveWordPressBrowserBaseUrl,
};
