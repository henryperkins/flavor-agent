import { createActivityEntry } from './activity-history';

export const RECOMMENDATION_OUTCOME_TYPE = 'recommendation_outcome';
export const OUTCOME_VISIBILITY = 'diagnostic';

export const OUTCOME_EVENTS = Object.freeze( [
	'shown',
	'selected_for_review',
	'stale_blocked',
	'validation_blocked',
	'pattern_inserted_from_shelf',
] );

const OUTCOME_EVENT_SET = new Set( OUTCOME_EVENTS );
const TOP_SUGGESTION_CAP = 3;
const MAX_STRING_LENGTH = 191;
const MAX_LABEL_LENGTH = 96;
const UINT32_MODULO = 4294967296;
const recordedOutcomeKeys = new Set();
const pendingOutcomeKeys = new Set();

const OUTCOME_LABELS = Object.freeze( {
	shown: 'Recommendations shown',
	selected_for_review: 'Recommendation selected for review',
	stale_blocked: 'Recommendation blocked by stale context',
	validation_blocked: 'Recommendation blocked by validation',
	pattern_inserted_from_shelf: 'Pattern inserted from recommendation shelf',
} );

function cleanString( value, maxLength = MAX_STRING_LENGTH ) {
	if ( value === null || value === undefined ) {
		return '';
	}

	if (
		typeof value !== 'string' &&
		typeof value !== 'number' &&
		typeof value !== 'boolean'
	) {
		return '';
	}

	return String( value ).trim().slice( 0, maxLength );
}

function cleanCode( value ) {
	return cleanString( value )
		.toLowerCase()
		.replace( /[^a-z0-9_-]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.slice( 0, 64 );
}

function stableStringify( value ) {
	if ( value === null || value === undefined ) {
		return '';
	}

	if ( Array.isArray( value ) ) {
		return `[${ value.map( stableStringify ).join( ',' ) }]`;
	}

	if ( typeof value === 'object' ) {
		return `{${ Object.keys( value )
			.sort()
			.map( ( key ) => `${ key }:${ stableStringify( value[ key ] ) }` )
			.join( ',' ) }}`;
	}

	return String( value );
}

export function hashOutcomeValue( value ) {
	const text = stableStringify( value );
	let hash = 5381;

	for ( let index = 0; index < text.length; index++ ) {
		hash =
			( Math.imul( hash, 33 ) + text.charCodeAt( index ) ) %
			UINT32_MODULO;
		hash = hash < 0 ? hash + UINT32_MODULO : hash;
	}

	return `hash_${ hash.toString( 36 ) }`;
}

export function normalizeSourceRequestSignature( value ) {
	if ( value && typeof value === 'object' ) {
		return hashOutcomeValue( value );
	}

	const normalized = cleanString( value );

	if ( ! normalized ) {
		return '';
	}

	return normalized.startsWith( 'hash_' )
		? normalized
		: hashOutcomeValue( normalized );
}

export function buildRecommendationSetId( {
	surface,
	requestToken = null,
	sourceRequestSignature = '',
	resultRef = '',
} = {} ) {
	return [
		cleanCode( surface ) || 'recommendation',
		cleanString( requestToken ?? '' ) || '0',
		hashOutcomeValue( {
			resultRef: cleanString( resultRef ),
			sourceRequestSignature: normalizeSourceRequestSignature(
				sourceRequestSignature
			),
		} ),
	].join( ':' );
}

export function getSuggestionOutcomeKey( suggestion = {}, fallback = '' ) {
	return (
		cleanString( suggestion?.suggestionKey ) ||
		cleanString( suggestion?.key ) ||
		cleanString( suggestion?.name ) ||
		cleanString( fallback ) ||
		hashOutcomeValue( {
			label: cleanString( suggestion?.label ),
			type: cleanString( suggestion?.type ),
			panel: cleanString( suggestion?.panel ),
		} )
	);
}

function normalizeTopSuggestionKeys( keys = [] ) {
	if ( ! Array.isArray( keys ) ) {
		return [];
	}

	return Array.from(
		new Set( keys.map( ( key ) => cleanString( key ) ).filter( Boolean ) )
	).slice( 0, TOP_SUGGESTION_CAP );
}

export function decorateRecommendationPayload(
	payload = {},
	{
		surface,
		recommendationSetId,
		sourceRequestSignature = '',
		resultCount = null,
	} = {}
) {
	if ( ! payload || typeof payload !== 'object' ) {
		return payload;
	}

	const keys = [ 'settings', 'styles', 'block', 'suggestions' ];
	const sourceSignature = normalizeSourceRequestSignature(
		sourceRequestSignature
	);
	const setId =
		cleanString( recommendationSetId ) ||
		buildRecommendationSetId( { surface, sourceRequestSignature } );
	const decorateList = ( list = [], listKey = 'suggestions' ) =>
		Array.isArray( list )
			? list.map( ( suggestion, index ) => {
					if ( ! suggestion || typeof suggestion !== 'object' ) {
						return suggestion;
					}

					const fallbackKey = `${
						surface || 'suggestion'
					}:${ listKey }:${ index + 1 }`;
					const suggestionKey = getSuggestionOutcomeKey(
						suggestion,
						fallbackKey
					);

					return {
						...suggestion,
						suggestionKey:
							cleanString( suggestion.suggestionKey ) ||
							suggestionKey,
						recommendationOutcome: {
							recommendationSetId: setId,
							suggestionKey,
							sourceRequestSignature: sourceSignature,
							rank: index + 1,
							resultCount:
								Number.isInteger( resultCount ) &&
								resultCount >= 0
									? resultCount
									: list.length,
							topSuggestionKeys: normalizeTopSuggestionKeys(
								list.map( ( item, itemIndex ) =>
									getSuggestionOutcomeKey(
										item,
										`${
											surface || 'suggestion'
										}:${ listKey }:${ itemIndex + 1 }`
									)
								)
							),
						},
					};
			  } )
			: [];

	return keys.reduce(
		( nextPayload, key ) =>
			Array.isArray( nextPayload[ key ] )
				? {
						...nextPayload,
						[ key ]: decorateList( nextPayload[ key ], key ),
				  }
				: nextPayload,
		{
			...payload,
			recommendationOutcome: {
				recommendationSetId: setId,
				sourceRequestSignature: sourceSignature,
			},
		}
	);
}

function getRecommendationListsFromPayload( payload = {} ) {
	return [ 'settings', 'styles', 'block', 'suggestions' ].flatMap( ( key ) =>
		Array.isArray( payload?.[ key ] ) ? payload[ key ] : []
	);
}

export function getRecommendationOutcomeSummaryFromPayload( payload = {} ) {
	if ( ! payload || typeof payload !== 'object' ) {
		return null;
	}

	const suggestions = getRecommendationListsFromPayload( payload ).filter(
		( suggestion ) => suggestion && typeof suggestion === 'object'
	);
	const rootOutcome = payload.recommendationOutcome || {};
	const firstOutcome =
		suggestions.find( ( suggestion ) => suggestion?.recommendationOutcome )
			?.recommendationOutcome || {};
	const recommendationSetId = cleanString(
		rootOutcome.recommendationSetId || firstOutcome.recommendationSetId
	);

	if ( ! recommendationSetId ) {
		return null;
	}

	return {
		recommendationSetId,
		sourceRequestSignature: normalizeSourceRequestSignature(
			rootOutcome.sourceRequestSignature ||
				firstOutcome.sourceRequestSignature
		),
		topSuggestionKeys: normalizeTopSuggestionKeys(
			suggestions.map( ( suggestion, index ) =>
				getSuggestionOutcomeKey(
					suggestion,
					`suggestion:${ index + 1 }`
				)
			)
		),
		resultCount: suggestions.length,
	};
}

export function buildRecommendationOutcomeDedupeKey( {
	surface,
	event,
	recommendationSetId,
	suggestionKey = '',
	reason = '',
	patternKey = '',
} = {} ) {
	const safeSurface = cleanCode( surface );
	const safeEvent = cleanCode( event );
	const safeSetId = cleanString( recommendationSetId );

	if ( safeEvent === 'shown' ) {
		return [ safeSurface, safeEvent, safeSetId ].join( ':' );
	}

	return [
		safeSurface,
		safeEvent,
		safeSetId,
		cleanString( suggestionKey || patternKey ),
		cleanCode( reason ),
	].join( ':' );
}

export function hasRecordedRecommendationOutcome( dedupeKey ) {
	return recordedOutcomeKeys.has( cleanString( dedupeKey ) );
}

export function markRecommendationOutcomeRecorded( dedupeKey ) {
	const normalized = cleanString( dedupeKey );

	if ( normalized ) {
		recordedOutcomeKeys.add( normalized );
	}
}

export function hasPendingRecommendationOutcome( dedupeKey ) {
	return pendingOutcomeKeys.has( cleanString( dedupeKey ) );
}

export function markRecommendationOutcomePending( dedupeKey ) {
	const normalized = cleanString( dedupeKey );

	if ( normalized ) {
		pendingOutcomeKeys.add( normalized );
	}
}

export function clearRecommendationOutcomePending( dedupeKey ) {
	const normalized = cleanString( dedupeKey );

	if ( normalized ) {
		pendingOutcomeKeys.delete( normalized );
	}
}

export function resetRecommendationOutcomeDedupeForTests() {
	recordedOutcomeKeys.clear();
	pendingOutcomeKeys.clear();
}

export function buildRecommendationIdentityFromSuggestion(
	suggestion = {},
	overrides = {}
) {
	const outcome = suggestion?.recommendationOutcome || {};
	let rank = null;
	let resultCount = 0;
	let topSuggestionKeys = [];

	if ( Number.isInteger( overrides.rank ) ) {
		rank = overrides.rank;
	} else if ( Number.isInteger( outcome.rank ) ) {
		rank = outcome.rank;
	}

	if ( Array.isArray( overrides.topSuggestionKeys ) ) {
		topSuggestionKeys = overrides.topSuggestionKeys;
	} else if ( Array.isArray( outcome.topSuggestionKeys ) ) {
		topSuggestionKeys = outcome.topSuggestionKeys;
	}

	if ( Number.isInteger( overrides.resultCount ) ) {
		resultCount = overrides.resultCount;
	} else if ( Number.isInteger( outcome.resultCount ) ) {
		resultCount = outcome.resultCount;
	}

	return {
		recommendationSetId: cleanString(
			overrides.recommendationSetId || outcome.recommendationSetId
		),
		suggestionKey: cleanString(
			overrides.suggestionKey ||
				outcome.suggestionKey ||
				suggestion?.suggestionKey
		),
		sourceRequestSignature: normalizeSourceRequestSignature(
			overrides.sourceRequestSignature || outcome.sourceRequestSignature
		),
		rank,
		topSuggestionKeys,
		resultCount,
	};
}

export function buildRecommendationOutcomeEntry( {
	document = null,
	event,
	surface,
	suggestion = null,
	recommendationSetId = '',
	suggestionKey = '',
	sourceRequestSignature = '',
	reason = '',
	topSuggestionKeys = [],
	resultCount = 0,
	target = {},
	patternKey = '',
	rank = null,
} = {} ) {
	const safeEvent = cleanCode( event );
	const safeSurface = cleanCode( surface );
	const safeDocument =
		document && typeof document === 'object' && document.scopeKey
			? document
			: null;

	if (
		! safeDocument ||
		! OUTCOME_EVENT_SET.has( safeEvent ) ||
		! safeSurface
	) {
		return null;
	}

	const identity = buildRecommendationIdentityFromSuggestion( suggestion, {
		recommendationSetId,
		suggestionKey,
		sourceRequestSignature,
		topSuggestionKeys,
		resultCount,
		rank,
	} );
	const setId =
		identity.recommendationSetId ||
		buildRecommendationSetId( {
			surface: safeSurface,
			sourceRequestSignature: identity.sourceRequestSignature,
		} );
	const finalSuggestionKey =
		identity.suggestionKey ||
		cleanString( target.suggestionKey ) ||
		cleanString( patternKey );
	const safeReason = cleanCode( reason );
	const safePatternKey = cleanString( patternKey || target.patternKey );
	let targetRank = null;

	if ( Number.isInteger( identity.rank ) ) {
		targetRank = identity.rank;
	} else if ( Number.isInteger( target.rank ) ) {
		targetRank = target.rank;
	}

	const targetPayload = {
		recommendationSetId: setId,
		...( finalSuggestionKey ? { suggestionKey: finalSuggestionKey } : {} ),
		...( safePatternKey ? { patternKey: safePatternKey } : {} ),
		...( cleanString( target.blockName )
			? { blockName: cleanString( target.blockName ) }
			: {} ),
		...( cleanString( target.clientId )
			? { clientId: cleanString( target.clientId ) }
			: {} ),
		...( Number.isInteger( targetRank ) ? { rank: targetRank } : {} ),
	};
	const entry = createActivityEntry( {
		type: RECOMMENDATION_OUTCOME_TYPE,
		surface: safeSurface,
		target: targetPayload,
		suggestion: cleanString(
			OUTCOME_LABELS[ safeEvent ] || 'Recommendation outcome',
			MAX_LABEL_LENGTH
		),
		suggestionKey: finalSuggestionKey || null,
		before: {},
		after: {
			outcome: {
				event: safeEvent,
				visibility: OUTCOME_VISIBILITY,
				recommendationSetId: setId,
				sourceRequestSignature: identity.sourceRequestSignature,
				reason: safeReason,
				topSuggestionKeys: normalizeTopSuggestionKeys(
					identity.topSuggestionKeys
				),
				resultCount: Number.isInteger( identity.resultCount )
					? Math.max( 0, identity.resultCount )
					: 0,
			},
		},
		document: safeDocument,
		requestRef: `outcome:${ safeSurface }:${ safeEvent }:${ setId }`,
	} );

	return {
		...entry,
		diagnostic: true,
		executionResult: OUTCOME_VISIBILITY,
		undo: {
			canUndo: false,
			status: 'not_applicable',
			error: null,
			updatedAt: entry.timestamp,
			undoneAt: null,
		},
		request: {
			reference: `outcome:${ safeSurface }:${ safeEvent }:${ setId }`,
			recommendation: {
				recommendationSetId: setId,
				suggestionKey: finalSuggestionKey,
				sourceRequestSignature: identity.sourceRequestSignature,
				rank: targetPayload.rank ?? null,
			},
		},
	};
}

export function getRecommendationIdentityForApply( suggestion = {} ) {
	const identity = buildRecommendationIdentityFromSuggestion( suggestion );

	if ( ! identity.recommendationSetId && ! identity.suggestionKey ) {
		return null;
	}

	return {
		recommendationSetId: identity.recommendationSetId,
		suggestionKey:
			identity.suggestionKey || getSuggestionOutcomeKey( suggestion, '' ),
		sourceRequestSignature: identity.sourceRequestSignature,
		rank: identity.rank,
	};
}
