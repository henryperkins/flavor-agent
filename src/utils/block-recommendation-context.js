import { buildContextSignature } from './context-signature';

// Keep these aligned with the PHP structural-summary normalization/schema caps.
export const BLOCK_SIBLING_SUMMARY_MAX_ITEMS = 3;
export const BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS = 6;
export const BLOCK_STRUCTURAL_BRANCH_MAX_DEPTH = 3;
export const BLOCK_STRUCTURAL_BRANCH_MAX_CHILDREN = 6;

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
		themeTokens: context?.themeTokens || {},
		parentContext: context?.parentContext || null,
		siblingSummariesBefore: context?.siblingSummariesBefore || [],
		siblingSummariesAfter: context?.siblingSummariesAfter || [],
	} );
}
