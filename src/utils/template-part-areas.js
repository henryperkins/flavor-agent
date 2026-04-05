const BUILTIN_TEMPLATE_PART_AREAS = Object.freeze( [
	'header',
	'footer',
	'sidebar',
] );

function normalizeTemplatePartArea( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

export function getTemplatePartAreaLookup() {
	const localized =
		typeof window !== 'undefined'
			? window.flavorAgentData?.templatePartAreas
			: null;

	if ( ! localized || typeof localized !== 'object' ) {
		return {};
	}

	return localized;
}

export function resolveTemplatePartAreaFromSlug(
	slug,
	areaLookup = getTemplatePartAreaLookup()
) {
	const normalizedSlug = normalizeTemplatePartArea( slug );

	if ( ! normalizedSlug ) {
		return { area: '', source: '' };
	}

	const registeredArea = normalizeTemplatePartArea(
		areaLookup?.[ normalizedSlug ]
	);

	if ( registeredArea ) {
		return { area: registeredArea, source: 'registry' };
	}

	if ( BUILTIN_TEMPLATE_PART_AREAS.includes( normalizedSlug ) ) {
		return { area: normalizedSlug, source: 'slug' };
	}

	return { area: '', source: '' };
}

export function resolveTemplatePartAreaEvidence(
	attributes,
	areaLookup = getTemplatePartAreaLookup()
) {
	if ( ! attributes || typeof attributes !== 'object' ) {
		return { area: '', source: '' };
	}

	const explicitArea = normalizeTemplatePartArea( attributes.area );

	if ( explicitArea ) {
		return { area: explicitArea, source: 'explicit' };
	}

	const slugArea = resolveTemplatePartAreaFromSlug(
		attributes.slug,
		areaLookup
	);

	if ( slugArea.area ) {
		return slugArea;
	}

	if ( attributes.tagName === 'header' || attributes.tagName === 'footer' ) {
		return { area: attributes.tagName, source: 'tag' };
	}

	if ( attributes.tagName === 'aside' ) {
		return { area: 'sidebar', source: 'tag' };
	}

	return { area: '', source: '' };
}

export function inferTemplatePartArea(
	attributes,
	areaLookup = getTemplatePartAreaLookup()
) {
	return resolveTemplatePartAreaEvidence( attributes, areaLookup ).area;
}

export function matchesTemplatePartArea(
	block,
	area,
	areaLookup = getTemplatePartAreaLookup()
) {
	return inferTemplatePartArea( block?.attributes, areaLookup ) === area;
}

export function isTemplatePartSlugRegisteredForArea(
	slug,
	area,
	areaLookup = getTemplatePartAreaLookup()
) {
	const normalizedArea = normalizeTemplatePartArea( area );

	if ( ! normalizedArea ) {
		return false;
	}

	return (
		resolveTemplatePartAreaFromSlug( slug, areaLookup ).area ===
		normalizedArea
	);
}
