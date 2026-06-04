import { __, sprintf } from '@wordpress/i18n';

const LEGACY_FLAG_KEYS = Object.freeze( {
	block: 'canRecommendBlocks',
	pattern: 'canRecommendPatterns',
	content: 'canRecommendContent',
	template: 'canRecommendTemplates',
	'template-part': 'canRecommendTemplateParts',
	navigation: 'canRecommendNavigation',
	'global-styles': 'canRecommendGlobalStyles',
	'style-book': 'canRecommendStyleBook',
} );

const STRUCTURED_SURFACE_KEYS = Object.freeze( {
	block: 'block',
	pattern: 'pattern',
	content: 'content',
	template: 'template',
	'template-part': 'templatePart',
	navigation: 'navigation',
	'global-styles': 'globalStyles',
	'style-book': 'styleBook',
} );

function getFlavorAgentData( input = null ) {
	if ( input && typeof input === 'object' ) {
		return input;
	}

	if ( typeof window === 'undefined' ) {
		return {};
	}

	return window.flavorAgentData || {};
}

function normalizeAction( action ) {
	if ( ! action || typeof action !== 'object' ) {
		return null;
	}

	const label = typeof action.label === 'string' ? action.label.trim() : '';
	let href = '';

	if ( typeof action.href === 'string' ) {
		href = action.href.trim();
	} else if ( typeof action.url === 'string' ) {
		href = action.url.trim();
	}

	if ( ! label || ! href ) {
		return null;
	}

	return {
		label,
		href,
	};
}

function normalizeString( value ) {
	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function normalizeActions( actions ) {
	if ( ! Array.isArray( actions ) ) {
		return [];
	}

	return actions.map( normalizeAction ).filter( Boolean );
}

function getDefaultActions( surface, data, reason ) {
	if ( data?.canManageFlavorAgentSettings === false ) {
		return [];
	}

	if ( surface === 'navigation' && reason === 'missing_theme_capability' ) {
		return [];
	}

	if ( surface === 'pattern' ) {
		return [
			data?.settingsUrl
				? {
						label: __(
							'Open Flavor Agent settings',
							'flavor-agent'
						),
						href: data.settingsUrl,
				  }
				: null,
			data?.connectorsUrl
				? {
						label: __( 'Open Connectors', 'flavor-agent' ),
						href: data.connectorsUrl,
				  }
				: null,
		].filter( Boolean );
	}

	if (
		[
			'block',
			'content',
			'template',
			'template-part',
			'navigation',
			'global-styles',
			'style-book',
		].includes( surface )
	) {
		return [
			data?.connectorsUrl
				? {
						label: __( 'Open Connectors', 'flavor-agent' ),
						href: data.connectorsUrl,
				  }
				: null,
			data?.settingsUrl && ! data?.connectorsUrl
				? {
						label: __(
							'Open Flavor Agent settings',
							'flavor-agent'
						),
						href: data.settingsUrl,
				  }
				: null,
		].filter( Boolean );
	}

	return [
		data?.settingsUrl
			? {
					label: __( 'Open Flavor Agent settings', 'flavor-agent' ),
					href: data.settingsUrl,
			  }
			: null,
	].filter( Boolean );
}

// ── Surface-specific message configuration ──────────────────
const SURFACE_MESSAGES = Object.freeze( {
	block: {
		default: __(
			'Configure a text-generation provider in Settings > Connectors to enable block recommendations.',
			'flavor-agent'
		),
	},
	content: {
		default: __(
			'Content recommendations need a text-generation provider configured in Settings > Connectors.',
			'flavor-agent'
		),
	},
	template: {
		default: __(
			'Template recommendations need a text-generation provider configured in Settings > Connectors.',
			'flavor-agent'
		),
	},
	'template-part': {
		default: __(
			'Template-part recommendations need a text-generation provider configured in Settings > Connectors.',
			'flavor-agent'
		),
	},
	navigation: {
		missing_theme_capability: __(
			'Navigation recommendations require the edit_theme_options capability.',
			'flavor-agent'
		),
		default: __(
			'Navigation recommendations need a text-generation provider configured in Settings > Connectors.',
			'flavor-agent'
		),
	},
	'global-styles': {
		missing_theme_capability: __(
			'Global Styles recommendations require the edit_theme_options capability.',
			'flavor-agent'
		),
		default: __(
			'Global Styles recommendations need a text-generation provider configured in Settings > Connectors.',
			'flavor-agent'
		),
	},
	'style-book': {
		surface_not_implemented: __(
			'Style Book recommendations are not available in this plugin build yet.',
			'flavor-agent'
		),
		missing_theme_capability: __(
			'Style Book recommendations require the edit_theme_options capability.',
			'flavor-agent'
		),
		default: __(
			'Style Book recommendations need a text-generation provider configured in Settings > Connectors.',
			'flavor-agent'
		),
	},
	pattern: {
		default: __(
			'Pattern recommendations need the Embedding Model and Qdrant Pattern Storage in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.',
			'flavor-agent'
		),
		cloudflare_ai_search_unconfigured: __(
			'Pattern recommendations need Cloudflare AI Search Pattern Storage in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.',
			'flavor-agent'
		),
	},
} );

const FALLBACK_MESSAGE = __(
	'This Flavor Agent surface is not available right now.',
	'flavor-agent'
);

function getDefaultMessage( surface, reason ) {
	const surfaceConfig = SURFACE_MESSAGES[ surface ];
	if ( surfaceConfig ) {
		return (
			surfaceConfig[ reason ] || surfaceConfig.default || FALLBACK_MESSAGE
		);
	}

	return FALLBACK_MESSAGE;
}

export function getSurfaceCapability( surface, input = null ) {
	const data = getFlavorAgentData( input );
	const structuredKey = STRUCTURED_SURFACE_KEYS[ surface ] || surface;
	const hasStructuredCapability =
		Boolean( data?.capabilities?.surfaces ) &&
		Object.prototype.hasOwnProperty.call(
			data.capabilities.surfaces,
			structuredKey
		);
	const structuredCapability = hasStructuredCapability
		? data.capabilities.surfaces[ structuredKey ]
		: null;
	const legacyFlagKey = LEGACY_FLAG_KEYS[ surface ];
	const hasLegacyAvailability = typeof data?.[ legacyFlagKey ] === 'boolean';
	const legacyAvailable = hasLegacyAvailability
		? data[ legacyFlagKey ]
		: false;
	const available =
		typeof structuredCapability?.available === 'boolean'
			? structuredCapability.available
			: legacyAvailable;
	let reason = 'plugin_provider_unconfigured';

	if (
		typeof structuredCapability?.reason === 'string' &&
		structuredCapability.reason
	) {
		reason = structuredCapability.reason;
	} else if ( available ) {
		reason = 'ready';
	} else if (
		surface === 'block' &&
		( hasStructuredCapability || hasLegacyAvailability )
	) {
		reason = 'block_backend_unconfigured';
	}

	const structuredActions = normalizeActions( structuredCapability?.actions );
	const structuredSingleAction =
		reason === 'missing_theme_capability'
			? null
			: normalizeAction( {
					label: structuredCapability?.configurationLabel,
					href: structuredCapability?.configurationUrl,
			  } );
	let actions = structuredActions;

	if ( actions.length === 0 ) {
		if ( structuredSingleAction ) {
			actions = [ structuredSingleAction ];
		} else if ( ! hasStructuredCapability ) {
			actions = getDefaultActions( surface, data, reason );
		}
	}

	const primaryAction = actions[ 0 ] || null;

	return {
		surface,
		available,
		reason,
		owner: structuredCapability?.owner || '',
		advisoryOnly: Boolean( structuredCapability?.advisoryOnly ),
		actionLabel: primaryAction?.label || '',
		actionHref: primaryAction?.href || '',
		actions,
		message:
			structuredCapability?.message ||
			getDefaultMessage( surface, reason ),
		patternRuntimeSignature:
			surface === 'pattern'
				? normalizeString(
						structuredCapability?.patternRuntimeSignature
				  )
				: '',
	};
}

export function getCapabilityNotice( surface, input = null ) {
	const capability = getSurfaceCapability( surface, input );

	if ( capability.available ) {
		return null;
	}

	return {
		...capability,
		status:
			capability.reason === 'missing_theme_capability'
				? 'info'
				: 'warning',
	};
}

export function getConnectorApprovalNotice(
	surface,
	errorDetails = null,
	input = null
) {
	const approval = errorDetails?.connectorApproval || null;

	if ( ! approval?.connectorId || ! approval?.callerBasename ) {
		return null;
	}

	const data = getFlavorAgentData( input );
	const canManageApprovals = data.canManageFlavorAgentSettings !== false;
	const href = canManageApprovals
		? normalizeString( approval.adminUrl || data.connectorApprovalUrl )
		: '';
	const message = canManageApprovals
		? sprintf(
				/* translators: 1: AI connector ID. 2: caller plugin basename. */
				__(
					'Flavor Agent needs administrator approval to use the %1$s connector. An approval request for %2$s has been submitted.',
					'flavor-agent'
				),
				approval.connectorId,
				approval.callerBasename
		  )
		: sprintf(
				/* translators: 1: AI connector ID. 2: caller plugin basename. */
				__(
					'Flavor Agent needs administrator approval to use the %1$s connector. An approval request for %2$s has been submitted. Ask an administrator to review it in Connector Approvals.',
					'flavor-agent'
				),
				approval.connectorId,
				approval.callerBasename
		  );
	const actionLabel = href ? __( 'Open approvals page', 'flavor-agent' ) : '';

	return {
		surface,
		available: false,
		reason: 'connector_not_approved',
		status: 'warning',
		message,
		actionLabel,
		actionHref: href,
		actions: href
			? [
					{
						label: actionLabel,
						href,
					},
			  ]
			: [],
	};
}
