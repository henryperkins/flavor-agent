import {
	buildDismissalOutcomes,
	buildRecommendationOutcomeEntry,
	buildRecommendationOutcomeDedupeKey,
} from '../recommendation-outcomes';

const suggestions = [ 's1', 's2', 's3', 's4' ].map( ( suggestionKey ) => ( {
	label: 'Private generated label',
	operations: [ { secret: 'Private operation' } ],
	recommendationOutcome: {
		recommendationSetId: 'set-1',
		suggestionKey,
		sourceRequestSignature: 'hash_fresh',
	},
} ) );

describe( 'explicit recommendation dismissal', () => {
	test.each( [
		'block',
		'template',
		'template-part',
		'global-styles',
		'style-book',
		'pattern',
		'navigation',
		'content',
		'post-blocks',
	] )( 'supports explicit canonical identities on %s', ( surface ) => {
		expect(
			buildDismissalOutcomes( {
				surface,
				suggestions,
				currentRequestSignature: 'hash_fresh',
			} )
		).toHaveLength( 4 );
	} );
	test( 'rejects unknown surfaces and drops every optional payload field from dismissal', () => {
		expect(
			buildDismissalOutcomes( {
				surface: 'private',
				suggestions,
				currentRequestSignature: 'hash_fresh',
			} )
		).toEqual( [] );
		const entry = buildRecommendationOutcomeEntry( {
			event: 'dismissed',
			surface: 'block',
			recommendationSetId: 'set-1',
			suggestionKey: 's1',
			sourceRequestSignature: 'hash_fresh',
			reason: 'user_dismissed',
			patternKey: 'Private text',
			rank: 1,
			resultCount: 4,
			topSuggestionKeys: [ 'Private text' ],
			document: { scopeKey: 'post:5', prompt: 'Private prompt' },
			target: { operations: [ 'Private operations' ] },
		} );
		expect( JSON.stringify( entry ) ).not.toContain( 'Private' );
		expect( entry.after.outcome.rank ).toBeUndefined();
	} );
	test( 'builds one fixed diagnostic for every explicitly named visible suggestion', () => {
		expect( typeof buildDismissalOutcomes ).toBe( 'function' );
		const outcomes = buildDismissalOutcomes( {
			surface: 'block',
			suggestions,
			currentRequestSignature: 'hash_fresh',
		} );
		expect( outcomes.map( ( item ) => item.suggestionKey ) ).toEqual( [
			's1',
			's2',
			's3',
			's4',
		] );
		const entry = buildRecommendationOutcomeEntry( {
			...outcomes[ 0 ],
			document: { scopeKey: 'post:5' },
		} );
		expect( entry ).toMatchObject( {
			suggestion: 'Recommendation dismissed',
			executionResult: 'diagnostic',
			undo: { canUndo: false, status: 'not_applicable' },
			after: {
				outcome: { event: 'dismissed', reason: 'user_dismissed' },
			},
		} );
		expect( JSON.stringify( entry ) ).not.toContain( 'Private' );
	} );

	test.each( [
		{ isStale: true },
		{ currentRequestSignature: 'hash_stale' },
		{ currentRequestSignature: '' },
		{
			suggestions: [
				{ suggestionKey: 'guessed', label: 'No canonical identity' },
			],
		},
		{
			suggestions: [
				suggestions[ 0 ],
				{
					...suggestions[ 1 ],
					recommendationOutcome: {
						...suggestions[ 1 ].recommendationOutcome,
						recommendationSetId: 'other-set',
					},
				},
			],
		},
	] )(
		'blocks stale, missing or mixed canonical identities: %p',
		( overrides ) => {
			expect( typeof buildDismissalOutcomes ).toBe( 'function' );
			expect(
				buildDismissalOutcomes( {
					surface: 'block',
					suggestions,
					currentRequestSignature: 'hash_fresh',
					...overrides,
				} )
			).toEqual( [] );
		}
	);

	test( 'does not manufacture fallback set or suggestion identities for dismissal', () => {
		expect(
			buildRecommendationOutcomeEntry( {
				event: 'dismissed',
				surface: 'block',
				document: { scopeKey: 'post:5' },
			} )
		).toBeNull();
	} );

	test( 'keeps explicit shown identities beyond the ranking cap', () => {
		const entry = buildRecommendationOutcomeEntry( {
			event: 'shown',
			surface: 'block',
			recommendationSetId: 'set-1',
			document: { scopeKey: 'post:5' },
			shownSuggestionKeys: [ 's1', 's2', 's3', 's4' ],
			topSuggestionKeys: [ 's1', 's2', 's3', 's4' ],
		} );
		expect( entry.after.outcome.shownSuggestionKeys ).toEqual( [
			's1',
			's2',
			's3',
			's4',
		] );
		expect( entry.after.outcome.topSuggestionKeys ).toHaveLength( 3 );
	} );

	test( 'deduplication distinguishes the new set while ignoring repeated explicit actions', () => {
		const input = {
			surface: 'block',
			recommendationSetId: 'set-1',
			suggestionKey: 's1',
			event: 'dismissed',
			reason: 'user_dismissed',
		};
		expect( buildRecommendationOutcomeDedupeKey( input ) ).toBe(
			buildRecommendationOutcomeDedupeKey( { ...input } )
		);
		expect( buildRecommendationOutcomeDedupeKey( input ) ).not.toBe(
			buildRecommendationOutcomeDedupeKey( {
				...input,
				recommendationSetId: 'set-2',
			} )
		);
	} );
} );
