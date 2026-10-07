import validationVocabulary from '../../shared/validation-reasons.json';

export const FIXTURE_SCHEMA_VERSION = 'recommendation-fixture-export-v1';
export const FIXTURE_SURFACES = [
	'block',
	'template',
	'template-part',
	'global-styles',
	'style-book',
	'pattern',
	'navigation',
	'content',
	'post-blocks',
];
const METRICS = [
	'reviewSelectionRate',
	'applyConversionRate',
	'patternInsertionRate',
	'undoRate',
	'validationBlockedRate',
	'insertFailedRate',
	'savePersistedRate',
	'saveDiscardedRate',
	'saveUnverifiableRate',
	'verificationCoverageRate',
];
const EVENTS = [
	'shown',
	'dismissed',
	'selected_for_review',
	'stale_blocked',
	'validation_blocked',
	'pattern_inserted_from_shelf',
	'insert_failed',
	'adapted_preview_shown',
	'adapted_inserted_from_preview',
	'adaptation_blocked',
	'adapted_insert_failed',
	'save_attempted',
	'save_failed',
	'save_confirmed',
	'save_discarded',
	'save_unverifiable',
	'apply_suggestion',
	'apply_block_structural_suggestion',
	'apply_template_suggestion',
	'apply_template_part_suggestion',
	'apply_post_blocks_suggestion',
	'apply_global_styles_suggestion',
	'apply_style_book_suggestion',
];
const REASONS = [
	'user_dismissed',
	'review_opened',
	'recommendation_set_visible',
	'client',
	'advisory_only',
	'insert_blocks_success',
	'insert_blocks_wrong_target',
	'insert_blocks_noop',
	'insert_blocks_exception',
	'insertion_target_changed',
	'disallowed_block_types',
	'missing_resolved_context',
	'resolved_context_changed',
	'revalidation_failed',
	'empty_pattern_blocks',
	'adapted_preview_stale',
	'operation_validation_failed',
	'not_visible_in_inserter',
	'pattern_not_found',
	'adaptation_unavailable',
	...Object.keys( validationVocabulary.reasons ),
];
const ALIASES = [
	'row',
	'set',
	'suggestion',
	'generation',
	'sourceSignature',
	'guideline',
	'docsContent',
	'docsRuntime',
	'linkedApply',
	'saveOccurrence',
];
const COUNTS = [
	'rowLimit',
	'eligibleRowCount',
	'sampleSize',
	'exportedRowCount',
	'excludedRowCount',
	'shownSetCount',
	'shownSuggestionCount',
	'unlinkedApplyCount',
	'missingIdentityCount',
];
const STATE_ENUMS = {
	executionResult: [
		'applied',
		'blocked',
		'diagnostic',
		'failed',
		'review',
		'undone',
		'pending',
		'rejected',
		'expired',
	],
	undoStatus: [ 'available', 'failed', 'not_applicable', 'review', 'undone' ],
	persistenceVerdict: [
		'unknown',
		'save_confirmed',
		'save_discarded',
		'save_unverifiable',
	],
	verificationCoverage: [ 'compared', 'not_verified', 'not_eligible' ],
};
const EVIDENCE = [
	'prompt_match',
	'operation_fit',
	'supports_fit',
	'section_role_match',
	'docs_freshness',
	'pattern_readiness',
	'visible_scope_match',
	'native_preset_fit',
	'accessibility_fit',
	'design_semantics_fit',
];
const PENALTIES = [
	'possible_no_op',
	'weak_prompt_match',
	'unsupported_control',
	'stale_docs',
	'validation_risk',
];
const TRAITS = [
	'hero-banner',
	'multi-column',
	'gallery',
	'call-to-action',
	'query-loop',
	'media-text',
	'navigation',
	'search',
	'branding',
	'social',
	'simple',
	'moderate-complexity',
	'complex',
	'media-rich',
	'text-focused',
	'mixed-content',
	'site-chrome',
	'testimonial',
	'team-or-about',
	'showcase',
	'pricing',
	'contact',
];
const has = ( value, key ) =>
	Object.prototype.hasOwnProperty.call( value, key );
const object = ( value, allowed, required = [] ) =>
	value !== null &&
	typeof value === 'object' &&
	! Array.isArray( value ) &&
	Object.keys( value ).every( ( key ) => allowed.includes( key ) ) &&
	required.every( ( key ) => has( value, key ) );
const list = ( value, max ) => Array.isArray( value ) && value.length <= max;
const count = ( value ) =>
	Number.isInteger( value ) && value >= 0 && value <= 2147483647;
const number = ( value, max = 1 ) =>
	typeof value === 'number' &&
	Number.isFinite( value ) &&
	value >= 0 &&
	value <= max;
const surfaces = ( value ) =>
	list( value, FIXTURE_SURFACES.length ) &&
	value.every( ( item ) => FIXTURE_SURFACES.includes( item ) ) &&
	new Set( value ).size === value.length;
const numericMap = ( value, allowed ) =>
	object( value, allowed ) &&
	Object.values( value ).every( ( item ) => number( item ) );

export function validateFixtureSelection( selection ) {
	const keys = [ 'dateFrom', 'dateTo', 'surfaces', 'rowLimit' ];
	if (
		! object( selection, keys, keys ) ||
		! Number.isInteger( selection.rowLimit ) ||
		selection.rowLimit < 1 ||
		selection.rowLimit > 1000 ||
		! surfaces( selection.surfaces ) ||
		! selection.surfaces.length
	) {
		return false;
	}
	const dates = [ selection.dateFrom, selection.dateTo ].map(
		( day, index ) => {
			if (
				typeof day !== 'string' ||
				! /^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}Z)?$/.test( day )
			) {
				return NaN;
			}
			const timestamp =
				day.length === 10
					? `${ day }T${ index === 0 ? '00:00:00' : '23:59:59' }Z`
					: day;
			const date = new Date( timestamp );
			return Number.isFinite( date.getTime() ) &&
				date.toISOString() === timestamp.replace( 'Z', '.000Z' )
				? date.getTime()
				: NaN;
		}
	);
	return (
		dates.every( Number.isFinite ) &&
		dates[ 0 ] <= dates[ 1 ] &&
		dates[ 1 ] - dates[ 0 ] < 31 * 86400000
	);
}

function validProvenance( value ) {
	if (
		! object(
			value,
			[ 'kind', 'implementationSha', 'publicVersions', 'config' ],
			[ 'kind', 'implementationSha', 'publicVersions', 'config' ]
		) ||
		! [ 'synthetic_fixture', 'local_runtime', 'real_site' ].includes(
			value.kind
		) ||
		typeof value.implementationSha !== 'string' ||
		! /^[a-f0-9]{40}$/.test( value.implementationSha )
	) {
		return false;
	}
	const versions = value.publicVersions;
	return (
		object(
			versions,
			[
				'plugin',
				'wordpress',
				'gutenberg',
				'ranking',
				'validationVocabulary',
				'report',
			],
			[
				'plugin',
				'wordpress',
				'ranking',
				'validationVocabulary',
				'report',
			]
		) &&
		[ 'plugin', 'wordpress', 'gutenberg' ].every(
			( key ) =>
				! has( versions, key ) ||
				( typeof versions[ key ] === 'string' &&
					versions[ key ].length <= 32 &&
					/^\d+\.\d+(?:\.\d+)?$/.test( versions[ key ] ) )
		) &&
		versions.ranking === 'contextual-ranking-v1' &&
		versions.validationVocabulary === validationVocabulary.version &&
		versions.report === 'governance-learning-report-v1' &&
		object(
			value.config,
			[ 'surfaces', 'rankingMode' ],
			[ 'surfaces', 'rankingMode' ]
		) &&
		surfaces( value.config.surfaces ) &&
		value.config.rankingMode === 'static'
	);
}

function validRow( row ) {
	if (
		! object(
			row,
			[
				'aliases',
				'surface',
				'event',
				'reason',
				'state',
				'ranking',
				'validationReason',
				'patternTraits',
			],
			[ 'aliases', 'surface', 'event' ]
		) ||
		! FIXTURE_SURFACES.includes( row.surface ) ||
		! EVENTS.includes( row.event ) ||
		! object( row.aliases, ALIASES, [ 'row' ] )
	) {
		return false;
	}
	if (
		! Object.entries( row.aliases ).every(
			( [ key, value ] ) =>
				typeof value === 'string' &&
				new RegExp( `^${ key }-[a-f0-9]{16}-[1-9][0-9]{0,5}$` ).test(
					value
				)
		)
	) {
		return false;
	}
	if ( has( row, 'reason' ) && ! REASONS.includes( row.reason ) ) {
		return false;
	}
	if (
		row.event === 'dismissed' &&
		( row.reason !== 'user_dismissed' ||
			! row.aliases.set ||
			! row.aliases.suggestion )
	) {
		return false;
	}
	if (
		has( row, 'state' ) &&
		( ! object( row.state, Object.keys( STATE_ENUMS ) ) ||
			! Object.entries( row.state ).every( ( [ key, value ] ) =>
				STATE_ENUMS[ key ].includes( value )
			) )
	) {
		return false;
	}
	if ( has( row, 'ranking' ) ) {
		const ranking = row.ranking;
		if (
			! object( ranking, [
				'rank',
				'score',
				'contextScore',
				'contextEvidence',
				'contextPenalties',
			] ) ||
			[ 'score', 'contextScore' ].some(
				( key ) => has( ranking, key ) && ! number( ranking[ key ] )
			) ||
			( has( ranking, 'rank' ) &&
				( ! Number.isInteger( ranking.rank ) ||
					ranking.rank < 1 ||
					ranking.rank > 1000 ) ) ||
			( has( ranking, 'contextEvidence' ) &&
				! numericMap( ranking.contextEvidence, EVIDENCE ) ) ||
			( has( ranking, 'contextPenalties' ) &&
				! numericMap( ranking.contextPenalties, PENALTIES ) )
		) {
			return false;
		}
	}
	return (
		( ! has( row, 'validationReason' ) ||
			( typeof row.validationReason === 'string' &&
				has( validationVocabulary.reasons, row.validationReason ) ) ) &&
		( ! has( row, 'patternTraits' ) ||
			( list( row.patternTraits, 8 ) &&
				row.patternTraits.every( ( value ) =>
					TRAITS.includes( value )
				) ) )
	);
}

/**
 * A second closed-schema check at the download boundary, with no raw response copy.
 *
 * @param {Object} candidate Privacy-projected v1 candidate.
 */
export function validateFixtureCandidate( candidate ) {
	if (
		! object(
			candidate,
			[
				'schemaVersion',
				'provenance',
				'sample',
				'rows',
				'coverage',
				'metrics',
			],
			[
				'schemaVersion',
				'provenance',
				'sample',
				'rows',
				'coverage',
				'metrics',
			]
		) ||
		candidate.schemaVersion !== FIXTURE_SCHEMA_VERSION ||
		! validProvenance( candidate.provenance )
	) {
		return false;
	}
	const sample = candidate.sample;
	if (
		! object(
			sample,
			[ ...COUNTS, 'truncated' ],
			[
				...COUNTS.filter( ( key ) => key !== 'eligibleRowCount' ),
				'truncated',
			]
		) ||
		typeof sample.truncated !== 'boolean' ||
		COUNTS.some(
			( key ) => has( sample, key ) && ! count( sample[ key ] )
		) ||
		sample.rowLimit < 1 ||
		sample.rowLimit > 1000 ||
		sample.sampleSize > sample.rowLimit ||
		sample.exportedRowCount + sample.excludedRowCount !==
			sample.sampleSize ||
		( has( sample, 'eligibleRowCount' ) &&
			sample.eligibleRowCount < sample.sampleSize )
	) {
		return false;
	}
	if (
		! list( candidate.rows, 1000 ) ||
		candidate.rows.length !== sample.exportedRowCount ||
		! candidate.rows.every( validRow ) ||
		new Set( candidate.rows.map( ( row ) => row.aliases.row ) ).size !==
			candidate.rows.length
	) {
		return false;
	}
	const coverage = candidate.coverage;
	if (
		! object(
			coverage,
			[
				'surfaces',
				'missingShownLinks',
				'missingApplyLinks',
				'unverifiedCoverageCount',
			],
			[
				'surfaces',
				'missingShownLinks',
				'missingApplyLinks',
				'unverifiedCoverageCount',
			]
		) ||
		! surfaces( coverage.surfaces ) ||
		[
			'missingShownLinks',
			'missingApplyLinks',
			'unverifiedCoverageCount',
		].some( ( key ) => ! count( coverage[ key ] ) ) ||
		! list( candidate.metrics, METRICS.length )
	) {
		return false;
	}
	const valid = candidate.metrics.every( ( metric ) => {
		if (
			! object(
				metric,
				[ 'name', 'numerator', 'denominator', 'value', 'coverage' ],
				[ 'name', 'numerator', 'denominator', 'coverage' ]
			) ||
			! METRICS.includes( metric.name ) ||
			! count( metric.numerator ) ||
			! count( metric.denominator ) ||
			! [ 'complete_sample', 'missing_links', 'no_denominator' ].includes(
				metric.coverage
			)
		) {
			return false;
		}
		return metric.denominator === 0
			? ! has( metric, 'value' ) && metric.coverage === 'no_denominator'
			: number( metric.value, 1000 ) &&
					metric.coverage !== 'no_denominator' &&
					Math.abs(
						metric.value -
							Math.round(
								( metric.numerator / metric.denominator ) *
									10000
							) /
								10000
					) <= 0.00001;
	} );
	return (
		valid &&
		new Set( candidate.metrics.map( ( metric ) => metric.name ) ).size ===
			candidate.metrics.length
	);
}

async function digestCandidate( candidate ) {
	if ( ! globalThis.crypto?.subtle ) {
		throw new Error( 'A secure local digest is unavailable.' );
	}
	const bytes = new TextEncoder().encode( JSON.stringify( candidate ) );
	const digest = await globalThis.crypto.subtle.digest( 'SHA-256', bytes );
	return Array.from( new Uint8Array( digest ), ( value ) =>
		value.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}

export async function buildFixtureReviewBundle(
	candidate,
	localReview,
	digest = digestCandidate
) {
	if (
		! validateFixtureCandidate( candidate ) ||
		! validateFixtureSelection( localReview?.selection ) ||
		[ 'custodian', 'site', 'collectionScope' ].some(
			( key ) =>
				typeof localReview[ key ] !== 'string' ||
				! localReview[ key ].trim() ||
				localReview[ key ].length > 512
		)
	) {
		throw new Error(
			'The candidate or local review record is incomplete.'
		);
	}
	const candidateDigest = await digest( candidate );
	if (
		typeof candidateDigest !== 'string' ||
		! /^[a-f0-9]{64}$/.test( candidateDigest )
	) {
		throw new Error( 'The candidate digest is unavailable.' );
	}
	return {
		candidate,
		reviewRecord: {
			custodian: localReview.custodian.trim(),
			site: localReview.site.trim(),
			collectionScope: localReview.collectionScope.trim(),
			collectionPeriod: {
				dateFrom: localReview.selection.dateFrom,
				dateTo: localReview.selection.dateTo,
			},
			selectedSurfaces: localReview.selection.surfaces,
			implementationSha: candidate.provenance.implementationSha,
			publicVersions: candidate.provenance.publicVersions,
			nonSecretConfiguration: candidate.provenance.config,
			ordering: 'newest-first: created_at DESC, id DESC',
			population:
				'selected inclusive UTC bounds and surfaces; recommendation/apply rows; save lifecycle excluded',
			provenanceBoundary:
				'implementationSha identifies the verified running exporter/runtime. Historical row generation versions remain unknown unless independently verified. A post-cutover timestamp does not prove that cached recommendations originated under this commit.',
			rowLimit: localReview.selection.rowLimit,
			sample: candidate.sample,
			coverage: candidate.coverage,
			exclusions:
				'Unsupported schema/event rows excluded; absent or unsafe optional fields omitted; capped rankings never supply shown identities.',
			metricDefinitions: {
				reviewSelectionRate:
					'distinct reviewed surface/set identities / sampled shown sets',
				applyConversionRate:
					'linked applied sets intersecting sampled shown sets / sampled shown sets',
				patternInsertionRate:
					'distinct shelf-inserted sets / sampled pattern shown sets',
				undoRate: 'undone apply rows / sampled apply rows',
				validationBlockedRate:
					'distinct validation-blocked events / distinct attempted events',
				insertFailedRate:
					'recorded insertion failures / pattern success and failure attempts',
				savePersistedRate:
					'server-resolved confirmed / conclusive applies',
				saveDiscardedRate:
					'server-resolved discarded / conclusive applies',
				saveUnverifiableRate:
					'server-resolved unverifiable / compared applies',
				verificationCoverageRate:
					'server-resolved compared / eligible applies',
			},
			reviewerDecision: 'pending',
			candidateDigest,
			digestAlgorithm: 'SHA-256',
			warning:
				'Restricted local custody record. Review candidate provenance/privacy and approve recipient/use before sharing. This bundle contains identifying custody fields.',
		},
	};
}

export function downloadFixtureReviewBundle( bundle ) {
	const url = URL.createObjectURL(
		new Blob( [ JSON.stringify( bundle, null, 2 ) ], {
			type: 'application/json',
		} )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = 'flavor-agent-local-review-bundle.json';
	document.body.appendChild( link );
	try {
		link.click();
	} finally {
		link.remove();
		URL.revokeObjectURL( url );
	}
}
