import { buildContextSignature } from './context-signature';
import { normalizeDesignSemantics } from './recommendation-design-semantics';

// Keep these aligned with the PHP structural-summary normalization/schema caps.
export const BLOCK_SIBLING_SUMMARY_MAX_ITEMS = 3;
export const BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS = 6;
export const BLOCK_STRUCTURAL_BRANCH_MAX_DEPTH = 3;
export const BLOCK_STRUCTURAL_BRANCH_MAX_CHILDREN = 6;

// Interior caps are larger than the branch caps: core/columns and flex rows
// routinely hold 6-8 children, and truncating at 6 would fire moreChildren on
// ordinary layouts. Mirrored by BLOCK_INTERIOR_MAX_* in BlockAbilities.
export const BLOCK_INTERIOR_MAX_ITEMS = 8;
export const BLOCK_INTERIOR_MAX_CHILDREN = 8;
export const BLOCK_INTERIOR_MAX_DEPTH = 3;

export function capBlockStructuralAncestorItems( items = [] ) {
	return Array.isArray( items )
		? items.slice( -BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS )
		: [];
}

export function capBlockStructuralBranchItems( items = [] ) {
	return Array.isArray( items )
		? items.slice( 0, BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS )
		: [];
}

export function capBlockInteriorItems( items = [] ) {
	return Array.isArray( items )
		? items.slice( 0, BLOCK_INTERIOR_MAX_ITEMS )
		: [];
}

export function buildBlockRecommendationContextSignature( context = null ) {
	if ( ! context || typeof context !== 'object' ) {
		return '';
	}

	return buildContextSignature( {
		blockContext: context?.block || {},
		siblingsBefore: context?.siblingsBefore || [],
		siblingsAfter: context?.siblingsAfter || [],
		structuralAncestors: capBlockStructuralAncestorItems(
			context?.structuralAncestors
		),
		structuralBranch: capBlockStructuralBranchItems(
			context?.structuralBranch
		),
		// Capped here, not just at collection time: the server hashes the whole
		// normalized context, so hashing an uncapped value client-side would make
		// the two signatures diverge and surface as a stale-context error at apply.
		blockInterior: capBlockInteriorItems( context?.blockInterior ),
		themeTokens: context?.themeTokens || {},
		parentContext: context?.parentContext || null,
		siblingSummariesBefore: context?.siblingSummariesBefore || [],
		siblingSummariesAfter: context?.siblingSummariesAfter || [],
		blockOperationContext: context?.blockOperationContext || null,
		designSemantics: normalizeDesignSemantics(
			context?.designSemantics || {},
			'block'
		),
	} );
}
