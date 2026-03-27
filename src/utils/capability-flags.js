const LEGACY_FLAG_KEYS = Object.freeze( {
	block: 'canRecommendBlocks',
	pattern: 'canRecommendPatterns',
	template: 'canRecommendTemplates',
	'template-part': 'canRecommendTemplateParts',
	navigation: 'canRecommendNavigation',
} );

const STRUCTURED_SURFACE_KEYS = Object.freeze( {
	block: 'block',
	pattern: 'pattern',
	template: 'template',
	'template-part': 'templatePart',
	navigation: 'navigation',
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

	if ( surface === 'block' ) {
		return [
			data?.settingsUrl
				? {
						label: 'Open Flavor Agent settings',
						href: data.settingsUrl,
				  }
				: null,
			data?.connectorsUrl
				? {
						label: 'Open Connectors',
						href: data.connectorsUrl,
				  }
				: null,
		].filter( Boolean );
	}

	if ( surface === 'navigation' && reason === 'missing_theme_capability' ) {
		return [];
	}

	return [
		data?.settingsUrl
			? {
					label: 'Open Flavor Agent settings',
					href: data.settingsUrl,
			  }
			: null,
	].filter( Boolean );
}

function getDefaultMessage( surface, reason ) {
	switch ( surface ) {
		case 'block':
			return 'Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent, or configure a text-generation provider in Settings > Connectors, to enable block recommendations.';
		case 'template':
			return "Template recommendations rely on Flavor Agent's configured chat provider. Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent.";
		case 'template-part':
			return "Template-part recommendations rely on Flavor Agent's configured chat provider. Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent.";
		case 'navigation':
			if ( reason === 'missing_theme_capability' ) {
				return 'Navigation recommendations require the edit_theme_options capability.';
			}

			return "Navigation recommendations rely on Flavor Agent's configured chat provider. Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent.";
		case 'pattern':
			return "Pattern recommendations rely on Flavor Agent's chat and embedding backends plus Qdrant in Settings > Flavor Agent.";
		default:
			return 'This Flavor Agent surface is not available right now.';
	}
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
	const legacyAvailable =
		typeof data?.[ legacyFlagKey ] === 'boolean'
			? data[ legacyFlagKey ]
			: true;
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
	} else if ( surface === 'block' ) {
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
