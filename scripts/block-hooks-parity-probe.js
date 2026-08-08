function parseBlockHooksParityProbeOutput( output ) {
	let probe;

	try {
		probe = JSON.parse( output );
	} catch ( error ) {
		throw new Error(
			`Block Hooks parity probe returned non-JSON output: ${ output }`,
			{ cause: error }
		);
	}

	if ( probe?.probeError ) {
		throw new Error(
			`Block Hooks parity probe failed: ${ probe.probeError.class } - ${ probe.probeError.message }`
		);
	}

	return probe;
}

module.exports = { parseBlockHooksParityProbeOutput };
