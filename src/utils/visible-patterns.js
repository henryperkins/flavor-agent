import { extractPatternNames } from './pattern-names';
import { getAllowedPatterns } from '../patterns/pattern-settings';

/**
 * Return the current editor-visible pattern names.
 *
 * Uses the pattern settings adapter which probes future stable APIs and
 * current experimental selectors internally.
 *
 * @param {?string} rootClientId  Inserter root client ID.
 * @param {Object}  [blockEditor] Optional block-editor selector object.
 * @return {string[]} Visible pattern names for the current inserter context.
 */
export function getVisiblePatternNames(rootClientId = null, blockEditor) {
	return extractPatternNames(
		getAllowedPatterns(rootClientId, blockEditor)
	);
}
