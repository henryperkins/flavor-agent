/* eslint-disable react-hooks/rules-of-hooks --
   Playwright fixtures receive a `use` callback. It is not a React Hook, but the
   rule matches on the bare name. */
const base = require( '@playwright/test' );
const {
	buildServerFaultReport,
	PHP_WASM_POOL_FAULT_BODY,
	isPlaygroundHarness,
	isPhpWasmPoolFault,
} = require( './php-wasm-fault' );

/**
 * Extends the base `page` fixture with a 5xx recorder.
 *
 * Deliberately does NOT fail a test that otherwise passed: Playground emits
 * these faults in bursts, and most bursts land on requests no assertion depends
 * on. Failing on any 5xx would raise the suite's red rate instead of explaining
 * it. Instead every fault is attached to the report. When the test has already
 * failed, an extra error distinguishes pure Playground pool faults from mixed
 * or non-matching 5xx responses that still require product-side investigation.
 */
const test = base.test.extend( {
	page: async ( { page }, use, testInfo ) => {
		const faults = [];
		const pendingReads = [];
		const playgroundHarness = isPlaygroundHarness(
			testInfo.project.metadata
		);

		const onResponse = ( response ) => {
			if ( response.status() < 500 ) {
				return;
			}

			pendingReads.push(
				( async () => {
					const headers = await response
						.allHeaders()
						.catch( () => ( {} ) );
					const body = await response.text().catch( () => '' );
					const status = response.status();

					faults.push( {
						url: response.url(),
						status,
						poweredBy: headers[ 'x-powered-by' ] || '',
						body: body.slice( 0, 200 ),
						phpWasmPoolFault:
							playgroundHarness &&
							isPhpWasmPoolFault( status, headers, body ),
					} );
				} )()
			);
		};

		page.on( 'response', onResponse );

		await use( page );

		page.off( 'response', onResponse );
		await Promise.all( pendingReads );

		if ( faults.length === 0 ) {
			return;
		}

		const report = buildServerFaultReport( faults );

		await testInfo.attach( 'server-5xx-responses.json', {
			body: JSON.stringify( faults, null, 2 ),
			contentType: 'application/json',
		} );

		testInfo.annotations.push( report.annotation );

		// The test passed despite the fault. Recorded, not escalated.
		if ( testInfo.status === testInfo.expectedStatus ) {
			return;
		}

		throw new Error( report.failureMessage );
	},
} );

module.exports = {
	test,
	expect: base.expect,
	buildServerFaultReport,
	PHP_WASM_POOL_FAULT_BODY,
	isPlaygroundHarness,
	isPhpWasmPoolFault,
};
