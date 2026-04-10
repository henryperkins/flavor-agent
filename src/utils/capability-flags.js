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

// ── Shared message fragments ────────────────────────────────
const CHAT_PROVIDER_MESSAGE =
	'use any compatible chat provider already configured in Settings > Flavor Agent or Settings > Connectors. Configure either path to enable this surface.';
const THEME_CAPABILITY_MESSAGE = 'require the edit_theme_options capability.';

// ── Surface-specific message configuration ──────────────────
const SURFACE_MESSAGES = Object.freeze( {
	block: {
		default:
			'Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent, or configure a text-generation provider in Settings > Connectors and select it here, to enable block recommendations.',
	},
	content: {
		default: `Content recommendations ${ CHAT_PROVIDER_MESSAGE }`,
	},
	template: {
		default: `Template recommendations ${ CHAT_PROVIDER_MESSAGE }`,
	},
	'template-part': {
		default: `Template-part recommendations ${ CHAT_PROVIDER_MESSAGE }`,
	},
	navigation: {
		missing_theme_capability: `Navigation recommendations ${ THEME_CAPABILITY_MESSAGE }`,
		default: `Navigation recommendations ${ CHAT_PROVIDER_MESSAGE }`,
	},
	'global-styles': {
		missing_theme_capability: `Global Styles recommendations ${ THEME_CAPABILITY_MESSAGE }`,
		default: `Global Styles recommendations ${ CHAT_PROVIDER_MESSAGE }`,
	},
	'style-book': {
		surface_not_implemented:
			'Style Book recommendations are not available in this plugin build yet.',
		missing_theme_capability: `Style Book recommendations ${ THEME_CAPABILITY_MESSAGE }`,
		default: `Style Book recommendations ${ CHAT_PROVIDER_MESSAGE }`,
	},
	pattern: {
		default:
			'Pattern recommendations need a compatible embedding backend and Qdrant in Settings > Flavor Agent, plus a usable chat provider from Settings > Flavor Agent or Settings > Connectors.',
	},
} );

const FALLBACK_MESSAGE =
	'This Flavor Agent surface is not available right now.';

function getDefaultMessage( surface, reason ) {
	const surfaceConfig = SURFACE_MESSAGES[ surface ];
	if ( surfaceConfig ) {
		return surfaceConfig[ reason ] || surfaceConfig.default || FALLBACK_MESSAGE;
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
