import {
	buildRankingSetFromSuggestions,
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

	test.each( [
		[ 'adapted_preview_shown', 'Adapted pattern preview shown' ],
		[
			'adapted_inserted_from_preview',
			'Adapted pattern inserted from preview',
		],
		[ 'adaptation_blocked', 'Pattern adaptation blocked' ],
		[ 'adapted_insert_failed', 'Adapted pattern insertion failed' ],
	] )( 'accepts adapted outcome event %s', ( event, label ) => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42', postType: 'post', entityId: '42' },
			event,
			surface: 'pattern',
			recommendationSetId: 'pattern:1:set',
			suggestionKey: 'theme/hero',
			reason: 'adapted_preview_stale',
		} );

		expect( entry ).not.toBeNull();
		expect( entry.after.outcome.event ).toBe( event );
		expect( entry.suggestion ).toBe( label );
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
				requestMeta: {
					learningAttribution: {
						generationId:
							'recgen:template:11111111-1111-4111-8111-111111111111',
						guidelineVersion: 'guidelines:v8',
						docsContentFingerprint: 'docs-content:abc',
						docsRuntimeFingerprint: 'docs-runtime:def',
						provider: 'openai',
						model: 'gpt-5',
						rankingVersion: 'contextual-ranking-v1',
						validationVocabularyVersion: 'validation-reasons-v1',
						rawPrompt: 'Private launch copy',
					},
				},
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
		expect( payload.recommendationOutcome.learningAttribution ).toEqual(
			expect.objectContaining( {
				generationId:
					'recgen:template:11111111-1111-4111-8111-111111111111',
				recommendationSetId,
				sourceRequestSignature: expect.stringMatching( /^hash_/ ),
				guidelineVersion: 'guidelines:v8',
				docsContentFingerprint: 'docs-content:abc',
				docsRuntimeFingerprint: 'docs-runtime:def',
				provider: 'openai',
				model: 'gpt-5',
				rankingVersion: 'contextual-ranking-v1',
				validationVocabularyVersion: 'validation-reasons-v1',
			} )
		);
		expect(
			payload.suggestions[ 0 ].recommendationOutcome.learningAttribution
		).toEqual( payload.recommendationOutcome.learningAttribution );
		expect(
			getRecommendationIdentityForApply( payload.suggestions[ 0 ] )
				.learningAttribution
		).toEqual( payload.recommendationOutcome.learningAttribution );
		expect( JSON.stringify( payload.recommendationOutcome ) ).not.toContain(
			'Private launch copy'
		);
	} );

	test( 'carries learning attribution from summary into shown outcome rows', () => {
		const payload = decorateRecommendationPayload(
			{
				requestMeta: {
					learningAttribution: {
						generationId:
							'recgen:block:22222222-2222-4222-8222-222222222222',
						guidelineVersion: 'guidelines:v8',
						provider: 'anthropic',
						rawPrompt: 'Secret campaign notes',
					},
				},
				suggestions: [ { label: 'Use concise copy' } ],
			},
			{
				surface: 'block',
				recommendationSetId: 'block:2:set',
				sourceRequestSignature: 'signature',
			}
		);

		const summary = getRecommendationOutcomeSummaryFromPayload( payload );
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			event: 'shown',
			surface: 'block',
			recommendationSetId: summary.recommendationSetId,
			sourceRequestSignature: summary.sourceRequestSignature,
			topSuggestionKeys: summary.topSuggestionKeys,
			resultCount: summary.resultCount,
			learningAttribution: summary.learningAttribution,
		} );

		expect( summary.learningAttribution ).toEqual(
			expect.objectContaining( {
				generationId:
					'recgen:block:22222222-2222-4222-8222-222222222222',
				recommendationSetId: 'block:2:set',
				sourceRequestSignature: expect.stringMatching( /^hash_/ ),
				guidelineVersion: 'guidelines:v8',
				provider: 'anthropic',
			} )
		);
		expect( entry.after.outcome.learningAttribution ).toEqual(
			summary.learningAttribution
		);
		expect( entry.request.recommendation.learningAttribution ).toEqual(
			summary.learningAttribution
		);
		expect( JSON.stringify( entry ) ).not.toContain( 'Secret' );
		expect( JSON.stringify( entry ) ).not.toContain( 'campaign' );
	} );

	test( 'decorates pattern recommendations with learning attribution for outcome summaries', () => {
		const payload = decorateRecommendationPayload(
			{
				requestMeta: {
					learningAttribution: {
						generationId:
							'recgen:pattern:77777777-7777-4777-8777-777777777777',
						guidelineVersion: 'guidelines:v8',
						provider: 'openai',
						model: 'gpt-5',
						rawPrompt: 'Private pattern launch copy',
					},
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
			},
			{
				surface: 'pattern',
				recommendationSetId: 'pattern:2:set',
				sourceRequestSignature: 'pattern-signature',
			}
		);
		const summary = getRecommendationOutcomeSummaryFromPayload( payload );

		expect(
			payload.recommendations[ 0 ].recommendationOutcome
				.learningAttribution
		).toEqual(
			expect.objectContaining( {
				generationId:
					'recgen:pattern:77777777-7777-4777-8777-777777777777',
				recommendationSetId: 'pattern:2:set',
				sourceRequestSignature: expect.stringMatching( /^hash_/ ),
				guidelineVersion: 'guidelines:v8',
				provider: 'openai',
				model: 'gpt-5',
			} )
		);
		expect( summary.learningAttribution ).toEqual(
			payload.recommendations[ 0 ].recommendationOutcome
				.learningAttribution
		);
		expect( JSON.stringify( summary ) ).not.toContain( 'Private pattern' );
	} );

	test( 'drops learning attribution without a generation id', () => {
		const payload = decorateRecommendationPayload(
			{
				requestMeta: {
					learningAttribution: {
						provider: 'openai',
						model: 'gpt-5',
					},
				},
				suggestions: [ { label: 'Insert hero' } ],
			},
			{
				surface: 'template',
				recommendationSetId: 'template:2:set',
				sourceRequestSignature: 'signature',
			}
		);

		expect( payload.recommendationOutcome ).not.toHaveProperty(
			'learningAttribution'
		);
		expect(
			payload.suggestions[ 0 ].recommendationOutcome
		).not.toHaveProperty( 'learningAttribution' );
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

	test( 'summarizes pattern traits on shown ranking-set items without raw content', () => {
		const payload = {
			recommendationOutcome: {
				recommendationSetId: 'pattern:abc',
				sourceRequestSignature: 'source:sig',
			},
			recommendations: [
				{
					name: 'theme/hero',
					traits: [
						'hero-banner',
						'complex',
						'hero-banner',
						'media-rich',
						'Private launch copy',
					],
					content:
						'<!-- wp:paragraph --><p>Private launch copy</p><!-- /wp:paragraph -->',
					ranking: {
						contextScore: 0.91,
						blendedScore: 0.88,
						rankingVersion: 'contextual-ranking-v1',
					},
				},
			],
		};

		const summary = getRecommendationOutcomeSummaryFromPayload( payload );
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
			},
			event: 'shown',
			surface: 'pattern',
			recommendationSetId: summary.recommendationSetId,
			sourceRequestSignature: summary.sourceRequestSignature,
			topSuggestionKeys: summary.topSuggestionKeys,
			resultCount: summary.resultCount,
			rankingSet: summary.rankingSet,
		} );

		expect( summary.rankingSet[ 0 ].patternTraits ).toEqual( [
			'hero-banner',
			'complex',
			'media-rich',
		] );
		expect( entry.after.outcome.rankingSet[ 0 ].patternTraits ).toEqual( [
			'hero-banner',
			'complex',
			'media-rich',
		] );
		expect(
			entry.request.recommendation.rankingSet[ 0 ].patternTraits
		).toEqual( [ 'hero-banner', 'complex', 'media-rich' ] );
		expect( JSON.stringify( entry ) ).not.toContain( 'Private' );
		expect( JSON.stringify( entry ) ).not.toContain( 'paragraph' );
	} );

	test( 'persists capped pattern traits on engaged pattern outcomes', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
			},
			event: 'pattern_inserted_from_shelf',
			surface: 'pattern',
			recommendationSetId: 'pattern:abc',
			suggestion: {
				name: 'theme/hero',
				traits: [
					'hero-banner',
					'multi-column',
					'gallery',
					'call-to-action',
					'query-loop',
					'media-text',
					'navigation',
					'search',
					'branding',
					'Private launch copy',
					'hero-banner',
				],
				content: 'Private launch copy',
				ranking: {
					blendedScore: 0.88,
					rankingVersion: 'contextual-ranking-v1',
				},
			},
		} );

		const expectedTraits = [
			'hero-banner',
			'multi-column',
			'gallery',
			'call-to-action',
			'query-loop',
			'media-text',
			'navigation',
			'search',
		];

		expect( entry.after.outcome.patternTraits ).toEqual( expectedTraits );
		expect( entry.request.recommendation.patternTraits ).toEqual(
			expectedTraits
		);
		expect( JSON.stringify( entry ) ).not.toContain( 'Private' );
		expect( JSON.stringify( entry ) ).not.toContain( 'content' );
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

describe( 'validationReason on outcomes', () => {
	beforeEach( () => {
		resetRecommendationOutcomeDedupeForTests();
	} );

	it( 'carries the primary reason + version into rankingSet items', () => {
		const set = buildRankingSetFromSuggestions( [
			{
				suggestionKey: 'style:styles:1',
				ranking: { blendedScore: 0.4 },
				validationReasons: [
					{ code: 'failed_contrast', severity: 'downgraded' },
				],
			},
		] );
		expect( set[ 0 ].validationReason ).toBe( 'failed_contrast' );
		expect( set[ 0 ].validationVocabularyVersion ).toBe(
			'validation-reasons-v1'
		);
	} );

	it( 'selects the highest-severity reason for rankingSet items', () => {
		const set = buildRankingSetFromSuggestions( [
			{
				suggestionKey: 'style:styles:1',
				ranking: { blendedScore: 0.4 },
				validationReasons: [
					{ code: 'failed_contrast', severity: 'downgraded' },
					{ code: 'unsupported_path', severity: 'rejected' },
				],
			},
		] );
		expect( set[ 0 ].validationReason ).toBe( 'unsupported_path' );
	} );

	it( 'leaves reason-less rankingSet items identical to today', () => {
		const set = buildRankingSetFromSuggestions( [
			{
				suggestionKey: 'style:styles:1',
				ranking: { blendedScore: 0.4 },
			},
		] );
		expect( set[ 0 ] ).toEqual( {
			suggestionKey: 'style:styles:1',
			ranking: { blendedScore: 0.4 },
		} );
		expect( set[ 0 ] ).not.toHaveProperty( 'validationReason' );
		expect( set[ 0 ] ).not.toHaveProperty( 'validationVocabularyVersion' );
	} );

	it( 'carries the primary reason into the apply identity', () => {
		const identity = getRecommendationIdentityForApply( {
			recommendationOutcome: {
				recommendationSetId: 's:0:h',
				suggestionKey: 'k',
			},
			validationReasons: [ { code: 'unsupported_path' } ],
		} );
		expect( identity.validationReason ).toBe( 'unsupported_path' );
	} );

	it( 'omits validationReason from the apply identity when reason-less', () => {
		const identity = getRecommendationIdentityForApply( {
			recommendationOutcome: {
				recommendationSetId: 's:0:h',
				suggestionKey: 'k',
			},
		} );
		expect( identity ).not.toHaveProperty( 'validationReason' );
	} );

	it( 'adds a sibling validationReason on selected_for_review without touching reason', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'selected_for_review',
			surface: 'style',
			recommendationSetId: 'style:1:set',
			suggestionKey: 'style:styles:1',
			reason: 'review_opened',
			suggestion: {
				ranking: { blendedScore: 0.4 },
				validationReasons: [
					{ code: 'failed_contrast', severity: 'downgraded' },
				],
			},
		} );
		expect( entry.after.outcome.reason ).toBe( 'review_opened' );
		expect( entry.after.outcome.validationReason ).toBe(
			'failed_contrast'
		);
	} );

	it( 'never stamps a sibling validationReason on shown outcomes', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'shown',
			surface: 'style',
			recommendationSetId: 'style:1:set',
			rankingSet: [
				{
					suggestionKey: 'style:styles:1',
					ranking: { blendedScore: 0.4 },
					validationReasons: [ { code: 'failed_contrast' } ],
				},
			],
		} );
		expect( entry.after.outcome ).not.toHaveProperty( 'validationReason' );
	} );

	it( 'omits the sibling validationReason on selected_for_review when reason-less', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'selected_for_review',
			surface: 'style',
			recommendationSetId: 'style:1:set',
			suggestionKey: 'style:styles:1',
			reason: 'review_opened',
			suggestion: { ranking: { blendedScore: 0.4 } },
		} );
		expect( entry.after.outcome ).not.toHaveProperty( 'validationReason' );
	} );

	it( 'stamps the vocabulary version on validation_blocked outcomes (reason slot holds a vocab code)', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'validation_blocked',
			surface: 'template',
			recommendationSetId: 'template:1:set',
			suggestionKey: 'template:suggestions:1',
			reason: 'unsupported_path',
		} );
		expect( entry.after.outcome.reason ).toBe( 'unsupported_path' );
		expect( entry.after.outcome.validationVocabularyVersion ).toBe(
			'validation-reasons-v1'
		);
	} );

	it( 'co-locates the vocabulary version with the sibling reason on selected_for_review', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'selected_for_review',
			surface: 'style',
			recommendationSetId: 'style:1:set',
			suggestionKey: 'style:styles:1',
			reason: 'review_opened',
			suggestion: {
				ranking: { blendedScore: 0.4 },
				validationReasons: [
					{ code: 'failed_contrast', severity: 'downgraded' },
				],
			},
		} );
		expect( entry.after.outcome.validationReason ).toBe(
			'failed_contrast'
		);
		expect( entry.after.outcome.validationVocabularyVersion ).toBe(
			'validation-reasons-v1'
		);
	} );

	it( 'omits the vocabulary version on selected_for_review when reason-less', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'selected_for_review',
			surface: 'style',
			recommendationSetId: 'style:1:set',
			suggestionKey: 'style:styles:1',
			reason: 'review_opened',
			suggestion: { ranking: { blendedScore: 0.4 } },
		} );
		expect( entry.after.outcome ).not.toHaveProperty(
			'validationVocabularyVersion'
		);
	} );

	it( 'never stamps the vocabulary version on shown outcomes (rankingSet items carry it)', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'shown',
			surface: 'style',
			recommendationSetId: 'style:1:set',
			rankingSet: [
				{
					suggestionKey: 'style:styles:1',
					ranking: { blendedScore: 0.4 },
					validationReasons: [ { code: 'failed_contrast' } ],
				},
			],
		} );
		expect( entry.after.outcome ).not.toHaveProperty(
			'validationVocabularyVersion'
		);
		expect(
			entry.after.outcome.rankingSet[ 0 ].validationVocabularyVersion
		).toBe( 'validation-reasons-v1' );
	} );

	it( 'preserves pre-normalized validation metadata on shown rankingSet items', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: { scopeKey: 'post:42' },
			event: 'shown',
			surface: 'style',
			recommendationSetId: 'style:1:set',
			rankingSet: [
				{
					suggestionKey: 'style:styles:1',
					ranking: { blendedScore: 0.4 },
					validationReason: 'failed_contrast',
					validationVocabularyVersion: 'validation-reasons-v1',
				},
			],
		} );
		expect( entry.after.outcome ).not.toHaveProperty(
			'validationVocabularyVersion'
		);
		expect( entry.after.outcome.rankingSet[ 0 ] ).toEqual(
			expect.objectContaining( {
				validationReason: 'failed_contrast',
				validationVocabularyVersion: 'validation-reasons-v1',
			} )
		);
	} );

	test( 'decorateRecommendationPayload preserves recommended sets and group ids', () => {
		const decorated = decorateRecommendationPayload(
			{
				settings: [
					{
						label: 'Wide layout',
						panel: 'layout',
						type: 'attribute_change',
						attributeUpdates: { align: 'wide' },
						groupId: 'hero-polish',
					},
				],
				styles: [],
				block: [],
				recommendedSets: [
					{
						id: 'hero-polish',
						label: 'Hero polish',
						reason: 'Apply these together.',
					},
				],
			},
			{
				surface: 'block',
				recommendationSetId: 'block:1:hash_abc',
				sourceRequestSignature: 'sig-a',
			}
		);

		expect( decorated.settings[ 0 ].groupId ).toBe( 'hero-polish' );
		expect( decorated.recommendedSets ).toEqual( [
			{
				id: 'hero-polish',
				label: 'Hero polish',
				reason: 'Apply these together.',
			},
		] );
	} );

	test( 'getRecommendationIdentityForApply supports ordered batch members', () => {
		const identity = getRecommendationIdentityForApply( {
			suggestionKey: 'block-batch:block:1:hash_set:hash_members',
			recommendationOutcome: {
				recommendationSetId: 'block:1:hash_set',
				sourceRequestSignature: 'hash_source',
			},
			members: [ 'block:settings:1', 'block:styles:1' ],
		} );

		expect( identity ).toEqual(
			expect.objectContaining( {
				recommendationSetId: 'block:1:hash_set',
				suggestionKey: 'block-batch:block:1:hash_set:hash_members',
				members: [ 'block:settings:1', 'block:styles:1' ],
			} )
		);
	} );

	test( 'buildRecommendationOutcomeEntry persists selected members for a blocked batch', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			event: 'validation_blocked',
			surface: 'block',
			suggestion: {
				suggestionKey: 'block-batch:block:1:hash_set:hash_members',
				members: [ 'block:settings:1', 'block:styles:1' ],
				recommendationOutcome: {
					recommendationSetId: 'block:1:hash_set',
				},
			},
			recommendationSetId: 'block:1:hash_set',
			reason: 'operation_validation_failed',
			target: {
				clientId: 'block-1',
				members: [ 'block:settings:1', 'block:styles:1' ],
			},
		} );

		expect( entry.target.members ).toEqual( [
			'block:settings:1',
			'block:styles:1',
		] );
		expect( entry.request.recommendation.members ).toEqual( [
			'block:settings:1',
			'block:styles:1',
		] );
	} );

	test( 'buildRecommendationOutcomeEntry omits members for a single non-batch outcome', () => {
		const entry = buildRecommendationOutcomeEntry( {
			document: {
				scopeKey: 'post:42',
				postType: 'post',
				entityId: '42',
			},
			event: 'validation_blocked',
			surface: 'block',
			suggestion: {
				suggestionKey: 'block:settings:1',
				recommendationOutcome: {
					recommendationSetId: 'block:1:hash_set',
				},
			},
			recommendationSetId: 'block:1:hash_set',
			reason: 'operation_validation_failed',
			target: { clientId: 'block-1' },
		} );

		expect( entry.target ).not.toHaveProperty( 'members' );
		expect( entry.request.recommendation ).not.toHaveProperty( 'members' );
	} );
} );
