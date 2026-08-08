const {
	parseBlockHooksParityProbeOutput,
} = require( '../block-hooks-parity-probe' );

describe( 'parseBlockHooksParityProbeOutput', () => {
	it( 'surfaces structured PHP failures with their original diagnostic', () => {
		expect( () =>
			parseBlockHooksParityProbeOutput(
				JSON.stringify( {
					probeError: {
						class: 'RuntimeException',
						message:
							'Template execute: failure_code - write failed',
					},
				} )
			)
		).toThrow(
			'Block Hooks parity probe failed: RuntimeException - Template execute: failure_code - write failed'
		);
	} );

	it( 'identifies non-JSON WP-CLI output', () => {
		expect( () =>
			parseBlockHooksParityProbeOutput(
				'PHP Fatal error: uncaught failure'
			)
		).toThrow(
			'Block Hooks parity probe returned non-JSON output: PHP Fatal error: uncaught failure'
		);
	} );
} );
