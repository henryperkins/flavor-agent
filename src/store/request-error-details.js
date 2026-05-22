export const CONNECTOR_NOT_APPROVED_CODE = 'wpai_connector_not_approved';

function normalizeString( value ) {
	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function normalizeObject( value ) {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? value
		: {};
}

function parseConnectorApprovalMessage( message ) {
	const match = normalizeString( message ).match(
		/^The "([^"]+)" AI connector has not been approved for use by "([^"]+)".$/
	);

	return match
		? { connectorId: match[ 1 ], callerBasename: match[ 2 ] }
		: null;
}

export function normalizeRequestErrorDetails( error = null ) {
	const data = normalizeObject( error?.data );
	const direct = normalizeObject( data.connectorApproval );
	const parsed = parseConnectorApprovalMessage( error?.message ) || {};
	const connectorId = normalizeString(
		direct.connectorId || data.connector_id || parsed.connectorId
	);
	const caller = normalizeObject( data.caller );
	const callerBasename = normalizeString(
		direct.callerBasename || caller.basename || parsed.callerBasename
	);
	const adminUrl = normalizeString(
		direct.adminUrl ||
			( typeof window !== 'undefined'
				? window.flavorAgentData?.connectorApprovalUrl
				: '' )
	);
	const code = normalizeString( error?.code || data.code );
	const message = normalizeString( error?.message );
	const connectorApproval =
		connectorId && callerBasename
			? {
					code: CONNECTOR_NOT_APPROVED_CODE,
					connectorId,
					callerBasename,
					callerName: normalizeString(
						direct.callerName || caller.name || 'Flavor Agent'
					),
					adminUrl,
			  }
			: null;

	return {
		code: connectorApproval ? CONNECTOR_NOT_APPROVED_CODE : code,
		message,
		data,
		connectorApproval,
	};
}
