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

	if (
		[
			'block',
			'pattern',
			'content',
			'template',
			'template-part',
			'navigation',
			'global-styles',
			'style-book',
		].includes( surface )
	) {
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
			return 'Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent, or configure a text-generation provider in Settings > Connectors and select it here, to enable block recommendations.';
		case 'content':
			return 'Content recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.';
		case 'template':
			return 'Template recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.';
		case 'template-part':
			return 'Template-part recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.';
		case 'navigation':
			if ( reason === 'missing_theme_capability' ) {
				return 'Navigation recommendations require the edit_theme_options capability.';
			}

			return 'Navigation recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.';
		case 'global-styles':
			if ( reason === 'missing_theme_capability' ) {
				return 'Global Styles recommendations require the edit_theme_options capability.';
			}

			return 'Global Styles recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.';
		case 'style-book':
			if ( reason === 'surface_not_implemented' ) {
				return 'Style Book recommendations are not available in this plugin build yet.';
			}

			if ( reason === 'missing_theme_capability' ) {
				return 'Style Book recommendations require the edit_theme_options capability.';
			}

			return 'Style Book recommendations use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.';
		case 'pattern':
			return 'Pattern recommendations need a compatible embedding backend and Qdrant in Settings > Flavor Agent, plus a usable chat provider from Settings > Flavor Agent or Settings > Connectors.';
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
