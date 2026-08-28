jest.mock( '@playwright/test', () => ( {
	defineConfig: ( config ) => config,
} ) );

const {
	buildServerFaultReport,
	PHP_WASM_POOL_FAULT_BODY,
	isPlaygroundHarness,
	isPhpWasmPoolFault,
} = require( '../php-wasm-fault' );

describe( 'isPhpWasmPoolFault', () => {
	// The exact shape observed in the CI traces for runs 30322550003,
	// 31864133215 and 31906757683: Express-supplied headers only, 21-byte body.
	const EXPRESS_HEADERS = {
		connection: 'keep-alive',
		'transfer-encoding': 'chunked',
		'x-powered-by': 'Express',
	};

	test( 'matches the Playground instance-pool fault signature', () => {
		expect(
			isPhpWasmPoolFault( 500, EXPRESS_HEADERS, 'Internal Server Error' )
		).toBe( true );
	} );

	test( 'tolerates surrounding whitespace in the body', () => {
		expect(
			isPhpWasmPoolFault(
				500,
				EXPRESS_HEADERS,
				'\nInternal Server Error\n'
			)
		).toBe( true );
	} );

	test( 'treats a PHP-attributed 500 as a real WordPress error', () => {
		// A WordPress fatal reaches the client through PHP, so it is
		// attributable and must not be written off as harness noise.
		expect(
			isPhpWasmPoolFault(
				500,
				{ ...EXPRESS_HEADERS, 'x-powered-by': 'PHP/8.3.30' },
				PHP_WASM_POOL_FAULT_BODY
			)
		).toBe( false );
	} );

	test( 'treats a non-Express 500 as a real server error', () => {
		expect(
			isPhpWasmPoolFault(
				500,
				{ 'x-powered-by': 'Apache/2.4' },
				PHP_WASM_POOL_FAULT_BODY
			)
		).toBe( false );
	} );

	test( 'ignores non-500 statuses', () => {
		// 502 is the pool's own MaxPhpInstancesError branch, and 503/504 come
		// from elsewhere; only the 500 branch produces this synthetic body.
		expect(
			isPhpWasmPoolFault( 502, EXPRESS_HEADERS, PHP_WASM_POOL_FAULT_BODY )
		).toBe( false );
		expect(
			isPhpWasmPoolFault( 503, EXPRESS_HEADERS, PHP_WASM_POOL_FAULT_BODY )
		).toBe( false );
	} );

	test( 'ignores a 500 carrying a different body', () => {
		expect(
			isPhpWasmPoolFault(
				500,
				EXPRESS_HEADERS,
				JSON.stringify( { code: 'internal_server_error' } )
			)
		).toBe( false );
	} );

	test( 'survives missing headers and body', () => {
		expect( isPhpWasmPoolFault( 500, undefined, undefined ) ).toBe( false );
		expect(
			isPhpWasmPoolFault( 500, undefined, PHP_WASM_POOL_FAULT_BODY )
		).toBe( false );
	} );
} );

describe( 'isPlaygroundHarness', () => {
	test( 'only recognizes projects explicitly marked as Playground', () => {
		expect(
			isPlaygroundHarness( { flavorAgentHarness: 'playground' } )
		).toBe( true );
		expect( isPlaygroundHarness( { flavorAgentHarness: 'wp70' } ) ).toBe(
			false
		);
		expect( isPlaygroundHarness( {} ) ).toBe( false );
	} );
} );

describe( 'buildServerFaultReport', () => {
	const poolFault = {
		status: 500,
		url: 'http://127.0.0.1/wp/v2/block-patterns/patterns',
		poweredBy: 'Express',
		phpWasmPoolFault: true,
	};
	const wordpressFault = {
		status: 500,
		url: 'http://127.0.0.1/wp-json/flavor-agent/v1/patterns',
		poweredBy: 'PHP/8.3.30',
		phpWasmPoolFault: false,
	};

	test( 'keeps mixed failures product-visible and lists every response', () => {
		const report = buildServerFaultReport( [ poolFault, wordpressFault ] );

		expect( report.poolFaults ).toEqual( [ poolFault ] );
		expect( report.nonPoolFaults ).toEqual( [ wordpressFault ] );
		expect( report.annotation.type ).toBe( 'mixed-server-5xx' );
		expect( report.failureMessage ).toContain( poolFault.url );
		expect( report.failureMessage ).toContain( wordpressFault.url );
		expect( report.failureMessage ).not.toContain(
			'HARNESS FAULT, NOT NECESSARILY A PRODUCT REGRESSION.'
		);
	} );

	test( 'classifies a pure instance-pool failure as harness-specific', () => {
		const report = buildServerFaultReport( [ poolFault ] );

		expect( report.nonPoolFaults ).toHaveLength( 0 );
		expect( report.annotation.type ).toBe(
			'playground-instance-pool-fault'
		);
		expect( report.failureMessage ).toContain(
			'HARNESS FAULT, NOT NECESSARILY A PRODUCT REGRESSION.'
		);
	} );
} );

describe( 'Playwright harness metadata', () => {
	test( 'marks the Playground and WP 7.0 configurations distinctly', () => {
		const playgroundConfig = require( '../../../playwright.config' );
		const wp70Config = require( '../../../playwright.wp70.config' );

		expect( playgroundConfig.metadata ).toEqual( {
			flavorAgentHarness: 'playground',
		} );
		expect( wp70Config.metadata ).toEqual( {
			flavorAgentHarness: 'wp70',
		} );
	} );
} );
