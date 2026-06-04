import {
	buildPatternInsertionTargetSignature,
	buildPatternRecommendationRequestSignature,
} from '../recommendation-request-signature';

describe( 'buildPatternRecommendationRequestSignature', () => {
	const baseInput = {
		postType: 'page',
		templateType: 'front-page',
		visiblePatternNames: [ 'theme/hero', 'theme/cards' ],
		prompt: 'hero',
		insertionContext: {
			rootBlock: 'core/group',
			ancestors: [ 'core/group' ],
			nearbySiblings: [ 'core/paragraph' ],
		},
		blockContext: {
			blockName: 'core/heading',
		},
	};

	test( 'is independent of the activity-scope `document` metadata field', () => {
		// `document` is activity metadata: the PHP execution layer strips it
		// before the LLM is called (RecommendationAbilityExecution::build_execution_input).
		// The store fetch path adds `document` to the signed input but the
		// PatternRecommender insert path does not, so including it in the
		// signature produced a false-positive freshness mismatch on every
		// Insert click in a real edit session.
		const withoutDocument =
			buildPatternRecommendationRequestSignature( baseInput );
		const withDocument = buildPatternRecommendationRequestSignature( {
			...baseInput,
			document: {
				scopeKey: 'page:42',
				postType: 'page',
				entityId: '42',
				entityKind: '',
				entityName: '',
				stylesheet: '',
			},
		} );

		expect( withDocument ).toBe( withoutDocument );
	} );

	test( 'changes when a ranking-relevant field changes', () => {
		const baseline =
			buildPatternRecommendationRequestSignature( baseInput );

		expect(
			buildPatternRecommendationRequestSignature( {
				...baseInput,
				prompt: 'cards',
			} )
		).not.toBe( baseline );

		expect(
			buildPatternRecommendationRequestSignature( {
				...baseInput,
				visiblePatternNames: [ 'theme/cards' ],
			} )
		).not.toBe( baseline );

		expect(
			buildPatternRecommendationRequestSignature( {
				...baseInput,
				insertionContext: {
					...baseInput.insertionContext,
					rootBlock: 'core/cover',
				},
			} )
		).not.toBe( baseline );

		expect(
			buildPatternRecommendationRequestSignature( {
				...baseInput,
				patternRuntimeSignature: 'runtime-a',
			} )
		).not.toBe( baseline );
	} );
} );

describe( 'buildPatternInsertionTargetSignature', () => {
	const baseInput = {
		postType: 'page',
		templateType: 'front-page',
		inserterRootClientId: 'root-a',
		insertionIndex: 2,
		insertionContext: {
			rootBlock: 'core/group',
			ancestors: [ 'core/group' ],
			nearbySiblings: [ 'core/paragraph' ],
		},
	};

	test( 'changes when insertion context changes', () => {
		const baseline = buildPatternInsertionTargetSignature( baseInput );

		expect(
			buildPatternInsertionTargetSignature( {
				...baseInput,
				insertionContext: {
					...baseInput.insertionContext,
					rootBlock: 'core/cover',
				},
			} )
		).not.toBe( baseline );

		expect(
			buildPatternInsertionTargetSignature( {
				...baseInput,
				insertionContext: {
					...baseInput.insertionContext,
					ancestors: [ 'core/group', 'core/columns' ],
				},
			} )
		).not.toBe( baseline );

		expect(
			buildPatternInsertionTargetSignature( {
				...baseInput,
				insertionContext: {
					...baseInput.insertionContext,
					nearbySiblings: [ 'core/heading' ],
				},
			} )
		).not.toBe( baseline );
	} );

	test( 'ignores ranking-only and activity metadata fields', () => {
		const baseline = buildPatternInsertionTargetSignature( baseInput );

		expect(
			buildPatternInsertionTargetSignature( {
				...baseInput,
				prompt: 'hero',
				visiblePatternNames: [ 'theme/hero' ],
				blockContext: { blockName: 'core/heading' },
				document: { scopeKey: 'page:42' },
			} )
		).toBe( baseline );
	} );
} );
