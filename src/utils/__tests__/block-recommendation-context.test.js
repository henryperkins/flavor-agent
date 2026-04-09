import { buildBlockRecommendationContextSignature } from '../block-recommendation-context';

describe( 'buildBlockRecommendationContextSignature', () => {
	test( 'includes parent and sibling summaries in signature', () => {
		const baseContext = {
			block: { name: 'core/paragraph' },
			siblingsBefore: [],
			siblingsAfter: [],
			structuralAncestors: [],
			structuralBranch: [],
			themeTokens: {},
		};

		const withoutExtras =
			buildBlockRecommendationContextSignature( baseContext );
		const withExtras = buildBlockRecommendationContextSignature( {
			...baseContext,
			parentContext: {
				block: 'core/group',
				visualHints: { align: 'wide' },
			},
			siblingSummariesBefore: [
				{ block: 'core/heading', role: 'section-heading' },
			],
			siblingSummariesAfter: [],
		} );

		expect( withExtras ).not.toBe( withoutExtras );
	} );

	test( 'treats missing new fields the same as empty defaults', () => {
		const signatureA = buildBlockRecommendationContextSignature( {} );
		const signatureB = buildBlockRecommendationContextSignature( {
			parentContext: null,
			siblingSummariesBefore: [],
			siblingSummariesAfter: [],
		} );

		expect( signatureA ).toBe( signatureB );
	} );
} );
