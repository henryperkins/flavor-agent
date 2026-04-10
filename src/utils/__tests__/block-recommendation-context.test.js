import {
	BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS,
	capBlockStructuralAncestorItems,
	capBlockStructuralBranchItems,
	buildBlockRecommendationContextSignature,
} from '../block-recommendation-context';

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

	test( 'ignores structural ancestor changes beyond the server-visible cap', () => {
		const visibleAncestors = Array.from(
			{ length: BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS },
			( _unused, index ) => ( {
				block: `core/group-${ index + 2 }`,
			} )
		);

		const signatureA = buildBlockRecommendationContextSignature( {
			structuralAncestors: [
				{ block: 'core/group-1a' },
				...visibleAncestors,
			],
		} );
		const signatureB = buildBlockRecommendationContextSignature( {
			structuralAncestors: [
				{ block: 'core/group-1b' },
				...visibleAncestors,
			],
		} );

		expect( signatureA ).toBe( signatureB );
	} );

	test( 'keeps the nearest structural ancestors while trimming older wrappers', () => {
		const ancestors = Array.from(
			{ length: BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS + 2 },
			( _unused, index ) => ( {
				block: `core/group-${ index + 1 }`,
			} )
		);

		expect( capBlockStructuralAncestorItems( ancestors ) ).toEqual(
			ancestors.slice( -BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS )
		);
		expect( capBlockStructuralBranchItems( ancestors ) ).toEqual(
			ancestors.slice( 0, BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS )
		);
	} );
} );
