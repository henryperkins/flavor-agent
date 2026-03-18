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

export function resolveTemplatePartAreaEvidence(
	attributes,
	areaLookup = getTemplatePartAreaLookup()
) {
	if ( ! attributes || typeof attributes !== 'object' ) {
		return { area: '', source: '' };
	}

	if ( typeof attributes.area === 'string' && attributes.area !== '' ) {
		return { area: attributes.area, source: 'explicit' };
	}

	if ( typeof attributes.slug === 'string' && attributes.slug !== '' ) {
		if ( typeof areaLookup?.[ attributes.slug ] === 'string' ) {
			return { area: areaLookup[ attributes.slug ], source: 'registry' };
		}

		if (
			attributes.slug === 'header' ||
			attributes.slug === 'footer' ||
			attributes.slug === 'sidebar'
		) {
			return { area: attributes.slug, source: 'slug' };
		}
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
