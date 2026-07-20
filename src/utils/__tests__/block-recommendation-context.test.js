import {
	BLOCK_STRUCTURAL_SUMMARY_MAX_ITEMS,
	BLOCK_INTERIOR_MAX_ITEMS,
	capBlockStructuralAncestorItems,
	capBlockStructuralBranchItems,
	capBlockInteriorItems,
	buildBlockRecommendationContextSignature,
} from '../block-recommendation-context';

describe( 'block interior signature contract', () => {
	const baseContext = {
		block: { name: 'core/group' },
		blockInterior: [
			{
				block: 'core/heading',
				childCount: 0,
				visualHints: { textColor: 'contrast' },
			},
		],
	};

	test( 'signature changes when a descendant visual hint changes', () => {
		const before = buildBlockRecommendationContextSignature( baseContext );
		const after = buildBlockRecommendationContextSignature( {
			...baseContext,
			blockInterior: [
				{
					block: 'core/heading',
					childCount: 0,
					visualHints: { textColor: 'accent' },
				},
			],
		} );

		expect( after ).not.toBe( before );
	} );

	// Stability against descendant text edits is a property of what the collector
	// puts in blockInterior (includeBlockCapabilities: false), not of this hash
	// function, so it is asserted in collector.test.js against the real call.

	test( 'interior cap is applied inside the signature builder', () => {
		// The server hashes the capped context, so an uncapped client-side hash
		// would diverge and surface as a stale-context error at apply time.
		const many = Array.from( { length: 20 }, ( _, index ) => ( {
			block: `core/block-${ index }`,
			childCount: 0,
		} ) );

		expect(
			buildBlockRecommendationContextSignature( {
				...baseContext,
				blockInterior: many,
			} )
		).toBe(
			buildBlockRecommendationContextSignature( {
				...baseContext,
				blockInterior: many.slice( 0, BLOCK_INTERIOR_MAX_ITEMS ),
			} )
		);
	} );

	test( 'capBlockInteriorItems caps and tolerates non-arrays', () => {
		expect(
			capBlockInteriorItems( new Array( 20 ).fill( {} ) )
		).toHaveLength( BLOCK_INTERIOR_MAX_ITEMS );
		expect( capBlockInteriorItems( null ) ).toEqual( [] );
		expect( capBlockInteriorItems() ).toEqual( [] );
	} );
} );

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

	test( 'includes block operation allowed pattern context in signature', () => {
		const signatureA = buildBlockRecommendationContextSignature( {
			block: { name: 'core/group' },
			blockOperationContext: {
				targetClientId: 'block-1',
				targetBlockName: 'core/group',
				targetSignature: 'target-sig',
				allowedPatterns: [
					{
						name: 'theme/hero',
						allowedActions: [ 'insert_after' ],
					},
				],
			},
		} );
		const signatureB = buildBlockRecommendationContextSignature( {
			block: { name: 'core/group' },
			blockOperationContext: {
				targetClientId: 'block-1',
				targetBlockName: 'core/group',
				targetSignature: 'target-sig',
				allowedPatterns: [
					{
						name: 'theme/hero',
						allowedActions: [ 'insert_before' ],
					},
				],
			},
		} );

		expect( signatureA ).not.toBe( signatureB );
	} );

	test( 'includes normalized design semantic context in the block signature', () => {
		const baseContext = {
			block: { name: 'core/paragraph' },
			designSemantics: {
				surface: 'block',
				sectionRole: 'hero',
				contrastContext: 'dark-parent',
				negativeSignals: [ 'parent-already-supplies-contrast' ],
			},
		};

		expect( buildBlockRecommendationContextSignature( baseContext ) ).toBe(
			buildBlockRecommendationContextSignature( {
				block: { name: 'core/paragraph' },
				designSemantics: {
					negativeSignals: [ 'parent-already-supplies-contrast' ],
					contrastContext: 'dark-parent',
					sectionRole: 'hero',
					surface: 'block',
				},
			} )
		);

		expect(
			buildBlockRecommendationContextSignature( baseContext )
		).not.toBe(
			buildBlockRecommendationContextSignature( {
				...baseContext,
				designSemantics: {
					...baseContext.designSemantics,
					sectionRole: 'footer',
				},
			} )
		);
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
