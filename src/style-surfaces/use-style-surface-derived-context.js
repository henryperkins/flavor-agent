import { useMemo } from '@wordpress/element';

import { getSuggestionKey } from '../inspector/suggestion-keys';
import {
	buildTemplateStructureSnapshot,
	collectViewportVisibilitySummary,
} from '../utils/editor-context-metadata';

const EMPTY_BLOCKS = [];
const EMPTY_RECOMMENDATIONS = [];

/**
 * Derives the referentially-stable block-/suggestion-derived context shared by
 * the Global Styles and Style Book surfaces.
 *
 * These derivations (template structure/visibility snapshots, design
 * semantics, suggestion-key tagging) are pure functions of the edited blocks
 * and the raw recommendation list. Computing them inside `useSelect` rebuilt a
 * new object/array on every store tick — tripping the editor's "useSelect
 * returns different values" warning and re-walking the whole block tree each
 * render. Hoisting them into `useMemo` keyed on the stable `getBlocks()` /
 * recommendation references collapses that churn.
 *
 * The select-coupled `activityEntries` (live undo validity) and `buildNotice`
 * stay on their owning components for now; see the scoped follow-up.
 *
 * @param {Object}   input                      Stable inputs from `useSelect`.
 * @param {Array}    input.editedBlocks         Edited block tree (stable `getBlocks()` ref).
 * @param {Array}    input.rawRecommendations   Raw surface recommendations (stable store ref).
 * @param {Function} input.buildDesignSemantics Stable `(blocks) => semantics` builder (own via `useCallback`).
 * @return {{ templateStructure: Object, templateVisibility: Object, designSemantics: Object, rawSuggestions: Array }} Memoized derived context.
 */
export function useStyleSurfaceDerivedContext( {
	editedBlocks,
	rawRecommendations,
	buildDesignSemantics,
} ) {
	const blocks = editedBlocks || EMPTY_BLOCKS;
	const recommendations = rawRecommendations || EMPTY_RECOMMENDATIONS;

	const templateStructure = useMemo(
		() => buildTemplateStructureSnapshot( blocks ),
		[ blocks ]
	);
	const templateVisibility = useMemo(
		() => collectViewportVisibilitySummary( blocks ),
		[ blocks ]
	);
	const designSemantics = useMemo(
		() => buildDesignSemantics( blocks ),
		[ blocks, buildDesignSemantics ]
	);
	const rawSuggestions = useMemo(
		() =>
			recommendations.map( ( suggestion, index ) => ( {
				...suggestion,
				suggestionKey: getSuggestionKey( suggestion, index ),
			} ) ),
		[ recommendations ]
	);

	return {
		templateStructure,
		templateVisibility,
		designSemantics,
		rawSuggestions,
	};
}
