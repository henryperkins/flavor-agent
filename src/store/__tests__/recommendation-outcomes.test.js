import {
	buildRecommendationOutcomeDedupeKey,
	buildRecommendationOutcomeEntry,
	buildRecommendationSetId,
	decorateRecommendationPayload,
	getRecommendationIdentityForApply,
	getRecommendationOutcomeSummaryFromPayload,
	hasRecordedRecommendationOutcome,
	markRecommendationOutcomeRecorded,
	resetRecommendationOutcomeDedupeForTests,
} from '../recommendation-outcomes';

describe( 'recommendation outcomes', () => {
	beforeEach( () => {
		resetRecommendationOutcomeDedupeForTests();
	} );

	test( 'builds a diagnostic shown entry without raw prompt or generated text', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			event: 'shown',
			surface: 'block',
			recommendationSetId: 'block:1:set',
			sourceRequestSignature: {
				prompt: 'Make my private launch copy better.',
				content: '<!-- wp:paragraph -->Secret<!-- /wp:paragraph -->',
			},
			resultCount: 3,
			topSuggestionKeys: [ 'a', 'b', 'c', 'd' ],
		} );

		expect( entry ).toEqual(
			expect.objectContaining( {
				type: 'recommendation_outcome',
				diagnostic: true,
				executionResult: 'diagnostic',
				suggestion: 'Recommendations shown',
				undo: expect.objectContaining( {
					canUndo: false,
					status: 'not_applicable',
				} ),
			} )
		);
		expect( entry.request.prompt ).toBeUndefined();
		expect( entry.request.recommendation ).toEqual(
			expect.objectContaining( {
				recommendationSetId: 'block:1:set',
				sourceRequestSignature: expect.stringMatching( /^hash_/ ),
			} )
		);
		expect( entry.after.outcome.topSuggestionKeys ).toEqual( [
			'a',
			'b',
			'c',
		] );
		expect( JSON.stringify( entry ) ).not.toContain(
			'private launch copy'
		);
		expect( JSON.stringify( entry ) ).not.toContain( 'Secret' );
	} );

	test( 'dedupes top suggestion keys before truncating', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			event: 'shown',
			surface: 'block',
			recommendationSetId: 'block:1:set',
			topSuggestionKeys: [
				'alpha',
				'alpha',
				'bravo',
				'charlie',
				'delta',
			],
		} );

		expect( entry.after.outcome.topSuggestionKeys ).toEqual( [
			'alpha',
			'bravo',
			'charlie',
		] );
	} );

	test( 'rejects unknown outcome events client-side', () => {
		expect(
			buildRecommendationOutcomeEntry( {
				document: { scopeKey: 'post:42' },
				event: 'dismissed',
				surface: 'block',
			} )
		).toBeNull();
	} );

	test( 'builds privacy-safe insert failure outcomes', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
			},
			event: 'insert_failed',
			surface: 'pattern',
			recommendationSetId: 'set-1',
			suggestionKey: 'theme/hero',
			reason: 'insert_blocks_noop',
		} );

		expect( entry ).toEqual(
			expect.objectContaining( {
				type: 'recommendation_outcome',
				suggestion: 'Pattern insertion failed',
				executionResult: 'diagnostic',
			} )
		);
		expect( entry.after.outcome ).toEqual(
			expect.objectContaining( {
				event: 'insert_failed',
				visibility: 'diagnostic',
				reason: 'insert_blocks_noop',
			} )
		);
	} );

	test( 'dedupes shown by set and per-suggestion outcomes by reason', () => {
		const shownKey = buildRecommendationOutcomeDedupeKey( {
			surface: 'block',
			event: 'shown',
			recommendationSetId: 'set-1',
			suggestionKey: 'ignored',
			reason: 'ignored',
		} );
		const blockedKey = buildRecommendationOutcomeDedupeKey( {
			surface: 'pattern',
			event: 'validation_blocked',
			recommendationSetId: 'set-1',
			suggestionKey: 'suggestion-1',
			reason: 'disallowed_block_types',
		} );

		expect( shownKey ).toBe( 'block:shown:set-1' );
		expect( blockedKey ).toBe(
			'pattern:validation_blocked:set-1:suggestion-1:disallowed_block_types'
		);
		expect( hasRecordedRecommendationOutcome( shownKey ) ).toBe( false );

		markRecommendationOutcomeRecorded( shownKey );

		expect( hasRecordedRecommendationOutcome( shownKey ) ).toBe( true );
	} );

	test( 'decorates recommendations with stable apply join identity', () => {
		const recommendationSetId = buildRecommendationSetId( {
			surface: 'template',
			requestToken: 7,
			sourceRequestSignature: 'signature',
			resultRef: 'theme//home',
		} );
		const payload = decorateRecommendationPayload(
			{
				suggestions: [
					{ label: 'Insert hero', suggestionKey: 'hero' },
					{ label: 'Tighten footer' },
				],
			},
			{
				surface: 'template',
				recommendationSetId,
				sourceRequestSignature: 'signature',
			}
		);

		expect( payload.suggestions[ 0 ].recommendationOutcome ).toEqual(
			expect.objectContaining( {
				recommendationSetId,
				suggestionKey: 'hero',
				rank: 1,
			} )
		);
		expect(
			getRecommendationIdentityForApply( payload.suggestions[ 0 ] )
		).toEqual(
			expect.objectContaining( {
				recommendationSetId,
				suggestionKey: 'hero',
				rank: 1,
			} )
		);
	} );

	test( 'summarizes decorated payloads with lane-unique fallback keys', () => {
		const payload = decorateRecommendationPayload(
			{
				settings: [ { label: 'Use preset spacing' } ],
				styles: [ { label: 'Improve contrast' } ],
				block: [ { label: 'Insert supported pattern' } ],
				suggestions: [ { label: 'Use concise copy' } ],
			},
			{
				surface: 'block',
				recommendationSetId: 'block:2:set',
				sourceRequestSignature: 'signature',
			}
		);

		expect( payload.settings[ 0 ].suggestionKey ).toBe(
			'block:settings:1'
		);
		expect( payload.styles[ 0 ].suggestionKey ).toBe( 'block:styles:1' );
		expect( payload.block[ 0 ].suggestionKey ).toBe( 'block:block:1' );
		expect( payload.suggestions[ 0 ].suggestionKey ).toBe(
			'block:suggestions:1'
		);
		expect( getRecommendationOutcomeSummaryFromPayload( payload ) ).toEqual(
			expect.objectContaining( {
				recommendationSetId: 'block:2:set',
				sourceRequestSignature: expect.stringMatching( /^hash_/ ),
				resultCount: 4,
				topSuggestionKeys: [
					'block:settings:1',
					'block:styles:1',
					'block:block:1',
				],
			} )
		);
	} );

	test( 'summarizes pattern recommendation ranking sets', () => {
		const payload = {
			recommendationOutcome: {
				recommendationSetId: 'pattern:abc',
				sourceRequestSignature: 'source:sig',
			},
			recommendations: [
				{
					name: 'theme/hero',
					ranking: {
						contextScore: 0.91,
						blendedScore: 0.88,
						rankingVersion: 'contextual-ranking-v1',
					},
				},
			],
		};

		const summary = getRecommendationOutcomeSummaryFromPayload( payload );

		expect( summary.rankingSet ).toEqual( [
			expect.objectContaining( {
				suggestionKey: 'theme/hero',
				ranking: expect.objectContaining( {
					contextScore: 0.91,
					blendedScore: 0.88,
					rankingVersion: 'contextual-ranking-v1',
				} ),
			} ),
		] );
	} );

	test( 'includes compact ranking snapshots without label-derived aggregate keys', () => {
		const payload = decorateRecommendationPayload(
			{
				suggestions: [
					{
						label: 'Use secret launch copy',
						ranking: {
							modelScore: 0.4,
							deterministicScore: 0.6,
							contextScore: 0.9,
							blendedScore: 0.7,
							contextEvidence: {
								prompt_match: 0.9,
								rawText: 'Use secret launch copy',
							},
							contextPenalties: {
								stale_docs: 0.15,
							},
							rankingVersion: 'contextual-ranking-v1',
						},
					},
				],
			},
			{
				surface: 'block',
				recommendationSetId: 'block:ranking:set',
				sourceRequestSignature: 'signature',
			}
		);
		const summary = getRecommendationOutcomeSummaryFromPayload( payload );
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
			},
			event: 'shown',
			surface: 'block',
			recommendationSetId: summary.recommendationSetId,
			sourceRequestSignature: summary.sourceRequestSignature,
			topSuggestionKeys: summary.topSuggestionKeys,
			resultCount: summary.resultCount,
			rankingSet: summary.rankingSet,
		} );
		const serialized = JSON.stringify( entry );

		expect( summary.rankingSet ).toEqual( [
			{
				suggestionKey: 'block:suggestions:1',
				ranking: expect.objectContaining( {
					contextScore: 0.9,
					rankingVersion: 'contextual-ranking-v1',
				} ),
			},
		] );
		expect( serialized ).not.toContain( 'secret' );
		expect( serialized ).not.toContain( 'launch' );
		expect( serialized ).not.toContain( 'copy' );
		expect( serialized ).not.toContain( 'rawText' );
	} );

	test( 'keeps aggregate shown ranking sets separate from per-suggestion ranking snapshots', () => {
		const ranking = {
			contextScore: 0.9,
			blendedScore: 0.8,
			rankingVersion: 'contextual-ranking-v1',
		};
		const shown = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
			},
			event: 'shown',
			surface: 'block',
			recommendationSetId: 'block:ranking:set',
			suggestion: {
				suggestionKey: 'block:suggestions:1',
				ranking,
			},
			rankingSet: [
				{
					suggestionKey: 'block:suggestions:1',
					ranking,
				},
			],
		} );
		const selected = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
			},
			event: 'selected_for_review',
			surface: 'block',
			recommendationSetId: 'block:ranking:set',
			suggestion: {
				suggestionKey: 'block:suggestions:1',
				ranking,
			},
			rankingSet: [
				{
					suggestionKey: 'block:suggestions:1',
					ranking,
				},
			],
		} );

		expect( shown.after.outcome.rankingSet ).toEqual( [
			{
				suggestionKey: 'block:suggestions:1',
				ranking,
			},
		] );
		expect( shown.after.outcome.ranking ).toBeUndefined();
		expect( selected.after.outcome.ranking ).toEqual( ranking );
		expect( selected.after.outcome.rankingSet ).toBeUndefined();
	} );

	test( 'replaces prose-like ranking-set keys with set-local fallbacks', () => {
		const ranking = {
			contextScore: 0.9,
			rankingVersion: 'contextual-ranking-v1',
		};
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
			},
			event: 'shown',
			surface: 'block',
			recommendationSetId: 'block:ranking:set',
			rankingSet: [
				{
					suggestionKey: 'use-secret-launch-copy',
					ranking,
				},
				{
					suggestionKey: 'hash_abc123',
					ranking,
				},
			],
		} );

		expect( entry.after.outcome.rankingSet ).toEqual( [
			{
				suggestionKey: 'suggestion:1',
				ranking,
			},
			{
				suggestionKey: 'hash_abc123',
				ranking,
			},
		] );
		expect( JSON.stringify( entry.after.outcome ) ).not.toContain(
			'secret'
		);
		expect( JSON.stringify( entry.after.outcome ) ).not.toContain(
			'launch'
		);
		expect( JSON.stringify( entry.after.outcome ) ).not.toContain( 'copy' );
	} );
} );
