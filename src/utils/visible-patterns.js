import { extractPatternNames } from './pattern-names';
import { getAllowedPatterns } from '../patterns/compat';

/**
 * Return the current editor-visible pattern names.
 *
 * Uses the compatibility adapter (patterns/compat.js) which handles
 * the stable → experimental → settings fallback chain internally.
 *
 * @param {?string} rootClientId Inserter root client ID.
 * @return {string[]} Visible pattern names for the current inserter context.
 */
export function getVisiblePatternNames( rootClientId = null ) {
	return extractPatternNames( getAllowedPatterns( rootClientId ) );
}
