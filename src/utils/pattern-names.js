/**
 * Collapse a pattern collection to the distinct set of registered names.
 *
 * @param {Array} patterns Pattern-like objects from editor settings/selectors.
 * @return {string[]} Distinct pattern names.
 */
export function extractPatternNames( patterns ) {
	if ( ! Array.isArray( patterns ) ) {
		return [];
	}

	return Array.from(
		new Set(
			patterns.map( ( pattern ) => pattern?.name ).filter( Boolean )
		)
	);
}
