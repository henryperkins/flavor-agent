import { store as blockEditorStore } from '@wordpress/block-editor';
import { select as registrySelect } from '@wordpress/data';
import { extractPatternNames } from './pattern-names';

/**
 * Return the current editor-visible pattern names.
 *
 * @return {string[]} Visible pattern names for the current inserter context.
 */
export function getVisiblePatternNames() {
	const blockEditor = registrySelect( blockEditorStore );

	if ( ! blockEditor ) {
		return [];
	}

	if ( typeof blockEditor.__experimentalGetAllowedPatterns === 'function' ) {
		return extractPatternNames(
			blockEditor.__experimentalGetAllowedPatterns( null )
		);
	}

	const settings = blockEditor.getSettings?.() || {};
	const patterns = Array.isArray( settings.__experimentalBlockPatterns )
		? settings.__experimentalBlockPatterns
		: [];

	return extractPatternNames( patterns );
}
