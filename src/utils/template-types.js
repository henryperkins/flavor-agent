/**
 * Known pattern template types that match the vocabulary used in
 * registered patterns' templateTypes arrays.
 */
export const KNOWN_TEMPLATE_TYPES = new Set( [
	'index',
	'home',
	'front-page',
	'singular',
	'single',
	'page',
	'archive',
	'author',
	'category',
	'tag',
	'taxonomy',
	'date',
	'search',
	'404',
] );

/**
 * Normalize a template slug or canonical template ref to the vocabulary used
 * by pattern template types.
 *
 * @param {string|undefined|null} slug Template slug from the editor.
 * @return {string|undefined} Normalized template type.
 */
export function normalizeTemplateType( slug ) {
	if ( typeof slug !== 'string' ) {
		return undefined;
	}

	const normalizedSlug = slug.includes( '//' )
		? slug.split( '//' ).pop()
		: slug;
	const safeSlug = normalizedSlug?.trim();

	if ( ! safeSlug ) {
		return undefined;
	}

	if ( KNOWN_TEMPLATE_TYPES.has( safeSlug ) ) {
		return safeSlug;
	}

	const base = safeSlug.split( '-' )[ 0 ];

	if ( KNOWN_TEMPLATE_TYPES.has( base ) ) {
		return base;
	}

	return undefined;
}
