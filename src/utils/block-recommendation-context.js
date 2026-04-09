import { buildContextSignature } from './context-signature';

export function buildBlockRecommendationContextSignature( context = null ) {
	if ( ! context || typeof context !== 'object' ) {
		return '';
	}

	return buildContextSignature( {
		blockContext: context?.block || {},
		siblingsBefore: context?.siblingsBefore || [],
		siblingsAfter: context?.siblingsAfter || [],
		structuralAncestors: context?.structuralAncestors || [],
		structuralBranch: context?.structuralBranch || [],
		themeTokens: context?.themeTokens || {},
	} );
}
