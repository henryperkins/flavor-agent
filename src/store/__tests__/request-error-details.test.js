import {
	CONNECTOR_NOT_APPROVED_CODE,
	normalizeRequestErrorDetails,
} from '../request-error-details';

describe( 'normalizeRequestErrorDetails', () => {
	test( 'normalizes canonical connector approval REST errors', () => {
		const details = normalizeRequestErrorDetails( {
			code: CONNECTOR_NOT_APPROVED_CODE,
			message:
				'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".',
			data: {
				status: 403,
				connector_id: 'openai',
				caller: {
					basename: 'flavor-agent/flavor-agent.php',
					name: 'Flavor Agent',
				},
				connectorApproval: {
					connectorId: 'openai',
					callerBasename: 'flavor-agent/flavor-agent.php',
					callerName: 'Flavor Agent',
					adminUrl:
						'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
				},
			},
		} );

		expect( details ).toMatchObject( {
			code: CONNECTOR_NOT_APPROVED_CODE,
			message:
				'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".',
			connectorApproval: {
				connectorId: 'openai',
				callerBasename: 'flavor-agent/flavor-agent.php',
				callerName: 'Flavor Agent',
				adminUrl:
					'https://example.test/wp-admin/tools.php?page=ai-connector-approval',
			},
		} );
	} );

	test( 'falls back to parsing the upstream message when structured details are missing', () => {
		const details = normalizeRequestErrorDetails( {
			code: 'wp_ai_client_request_failed',
			message:
				'The "openai" AI connector has not been approved for use by "flavor-agent/flavor-agent.php".',
			data: { status: 500 },
		} );

		expect( details.connectorApproval ).toMatchObject( {
			connectorId: 'openai',
			callerBasename: 'flavor-agent/flavor-agent.php',
		} );
	} );

	test( 'ignores null REST error data and preserves the request message', () => {
		const details = normalizeRequestErrorDetails( {
			code: 'wp_ai_client_request_failed',
			message: 'Request failed.',
			data: null,
		} );

		expect( details ).toMatchObject( {
			code: 'wp_ai_client_request_failed',
			message: 'Request failed.',
			data: {},
			connectorApproval: null,
		} );
	} );
} );
