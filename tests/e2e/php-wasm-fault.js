/**
 * The WordPress Playground CLI answers a failed PHP instance acquisition with a
 * synthetic response built by `@php-wasm/universal`'s
 * `PHPResponse.forHttpCode( 500 )`: an EMPTY header map plus this exact body.
 * Express then supplies the only headers on the wire, so the response carries
 * `x-powered-by: Express` and never the `x-powered-by: PHP/<version>` that a
 * real WordPress response does.
 *
 * The acquisition catch block discards the error object, so the server logs
 * nothing about it at any `--verbosity` level. Response inspection is the only
 * way the fault is observable, which is why this classifier exists: without it,
 * a harness fault is indistinguishable from a product regression and surfaces
 * only as an assertion timeout somewhere downstream.
 *
 * @see tests/e2e/wait-for-wordpress-ready.js which matches the same body, but
 *      only for the top-level document. Every fault seen so far in CI landed on
 *      a subresource, so that guard reported "ready" on a half-booted page.
 */
const PHP_WASM_POOL_FAULT_BODY = 'Internal Server Error';
const PLAYGROUND_HARNESS = 'playground';

/**
 * Distinguishes a Playground instance-pool fault from another 500 response.
 * Deliberately conservative: the response must carry Express attribution as
 * well as the exact synthetic body, because mislabelling a genuine server
 * error as harness noise is the more costly mistake.
 *
 * @param {number}                status  HTTP status code.
 * @param {Record<string,string>} headers Lower-cased response headers.
 * @param {string}                body    Response body.
 * @return {boolean} Whether the response is a Playground instance-pool fault.
 */
function isPhpWasmPoolFault( status, headers, body ) {
	if ( status !== 500 ) {
		return false;
	}

	if ( String( body || '' ).trim() !== PHP_WASM_POOL_FAULT_BODY ) {
		return false;
	}

	return /^Express$/i.test(
		String( ( headers || {} )[ 'x-powered-by' ] || '' ).trim()
	);
}

/**
 * Checks whether the active Playwright project is the Playground harness.
 *
 * @param {Record<string,*>} metadata Playwright project metadata.
 * @return {boolean} Whether this project runs against Playground.
 */
function isPlaygroundHarness( metadata ) {
	return metadata?.flavorAgentHarness === PLAYGROUND_HARNESS;
}

function formatFault( fault ) {
	return `  ${ fault.status } ${ fault.url }${
		fault.poweredBy ? ` (x-powered-by: ${ fault.poweredBy })` : ''
	}`;
}

/**
 * Builds the annotation and failure detail for captured server faults.
 *
 * Mixed groups are always reported as server failures and include every
 * response. A Playground-only group may use the harness-specific diagnosis.
 *
 * @param {Array<Record<string,*>>} faults Captured responses with status >= 500.
 * @return {Object} Partitioned faults plus annotation and failure message.
 */
function buildServerFaultReport( faults ) {
	const allFaults = Array.isArray( faults ) ? faults : [];
	const poolFaults = allFaults.filter(
		( fault ) => fault.phpWasmPoolFault === true
	);
	const nonPoolFaults = allFaults.filter(
		( fault ) => fault.phpWasmPoolFault !== true
	);
	const isMixed = poolFaults.length > 0 && nonPoolFaults.length > 0;
	let annotationType = 'server-5xx';

	if ( isMixed ) {
		annotationType = 'mixed-server-5xx';
	} else if ( poolFaults.length > 0 ) {
		annotationType = 'playground-instance-pool-fault';
	}

	const annotation = {
		type: annotationType,
		description: `${ allFaults.length } response(s) >= 500${
			poolFaults.length > 0
				? `, ${ poolFaults.length } matching the Playground instance-pool fault signature`
				: ''
		}${
			nonPoolFaults.length > 0
				? `, ${ nonPoolFaults.length } requiring server-side investigation`
				: ''
		}`,
	};

	if ( nonPoolFaults.length > 0 ) {
		return {
			poolFaults,
			nonPoolFaults,
			annotation,
			failureMessage: [
				`This test failed while ${ allFaults.length } response(s) returned >= 500:`,
				...allFaults.map( formatFault ),
				'',
				...( isMixed
					? [
							`${ poolFaults.length } response(s) also matched the Playground instance-pool fault signature,`,
							'but the non-pool failures mean this run is not safe to classify as infrastructure-only.',
					  ]
					: [] ),
				`${ nonPoolFaults.length } response(s) did not match the exact Playground instance-pool signature.`,
				'Treat them as server-side errors until their WordPress, PHP, or web-server source is identified.',
			].join( '\n' ),
		};
	}

	return {
		poolFaults,
		nonPoolFaults,
		annotation,
		failureMessage: [
			'HARNESS FAULT, NOT NECESSARILY A PRODUCT REGRESSION.',
			'',
			`${ poolFaults.length } response(s) matched the WordPress Playground`,
			'instance-pool fault signature (HTTP 500, Express attribution, body',
			`exactly "${ PHP_WASM_POOL_FAULT_BODY }"). The Playground server failed to`,
			'hand out a PHP instance and answered with a synthetic response; it logs',
			'nothing about this at any verbosity.',
			'',
			...poolFaults.map( formatFault ),
			'',
			'A failure of core /wp/v2/block-patterns/patterns starves the inserter of',
			'patterns, which is enough on its own to fail any pattern-surface',
			'assertion downstream. Confirm against the assertion above before',
			'attributing this failure to plugin code.',
		].join( '\n' ),
	};
}

module.exports = {
	buildServerFaultReport,
	PHP_WASM_POOL_FAULT_BODY,
	isPlaygroundHarness,
	isPhpWasmPoolFault,
};
