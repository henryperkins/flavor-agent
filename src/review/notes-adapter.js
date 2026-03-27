const VALID_INTERACTION_STATES = new Set( [
	'idle',
	'loading',
	'advisory-ready',
	'preview-ready',
	'applying',
	'success',
	'undoing',
	'error',
] );

function normalizeText( value ) {
	return typeof value === 'string' ? value.trim() : '';
}

function normalizeOperation( operation = {}, index = 0 ) {
	return {
		id:
			normalizeText( operation.id ) ||
			normalizeText( operation.key ) ||
			`operation-${ index }`,
		type: normalizeText( operation.type ) || 'operation',
		label:
			normalizeText( operation.label ) ||
			normalizeText( operation.badgeLabel ) ||
			normalizeText( operation.patternTitle ) ||
			normalizeText( operation.slug ) ||
			'Review operation',
		summary:
			normalizeText( operation.summary ) ||
			normalizeText( operation.reason ) ||
			'',
	};
}

function normalizeReference( reference = {}, index = 0 ) {
	return {
		id:
			normalizeText( reference.id ) ||
			normalizeText( reference.slug ) ||
			normalizeText( reference.name ) ||
			`reference-${ index }`,
		label:
			normalizeText( reference.label ) ||
			normalizeText( reference.slug ) ||
			normalizeText( reference.name ) ||
			'Reference',
		type: normalizeText( reference.type ) || 'reference',
	};
}

export function normalizeNotesReviewEvidence( evidence = {} ) {
	const interactionState = normalizeText( evidence.interactionState );

	return {
		surface: normalizeText( evidence.surface ) || 'review',
		interactionState: VALID_INTERACTION_STATES.has( interactionState )
			? interactionState
			: 'idle',
		title: normalizeText( evidence.title ) || 'Flavor Agent review',
		summary: normalizeText( evidence.summary ),
		advisoryOnly: evidence.advisoryOnly === true,
		previewRequired: evidence.previewRequired === true,
		operations: Array.isArray( evidence.operations )
			? evidence.operations.map( normalizeOperation )
			: [],
		references: Array.isArray( evidence.references )
			? evidence.references.map( normalizeReference )
			: [],
	};
}

export function createNotesReviewProjection( evidence = {} ) {
	const normalized = normalizeNotesReviewEvidence( evidence );

	return {
		kind: 'flavor-agent/review-note',
		title: normalized.title,
		surface: normalized.surface,
		status: normalized.interactionState,
		body: normalized.summary,
		meta: {
			advisoryOnly: normalized.advisoryOnly,
			previewRequired: normalized.previewRequired,
			operationCount: normalized.operations.length,
			referenceCount: normalized.references.length,
		},
		operations: normalized.operations,
		references: normalized.references,
	};
}

export function serializeNotesReviewProjection( evidence = {} ) {
	return JSON.stringify( createNotesReviewProjection( evidence ) );
}
