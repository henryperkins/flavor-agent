import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Icon,
	Notice,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import {
	createRoot,
	Fragment,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import { __, sprintf } from '@wordpress/i18n';
import { caution, check, page, plugins, symbol, undo } from '@wordpress/icons';
import './wpds-runtime.css';
import './dataviews-runtime.css';
import '../tokens.css';
import './brand.css';
import './activity-log.css';
import {
	areActivityViewsEqual,
	buildActivityPermalink,
	buildClaimReleaseRequest,
	buildClaimRequest,
	buildDecisionRequest,
	clampActivityViewPage,
	formatActivityTimestamp,
	formatApplyClaimNotice,
	getGovernanceDetails,
	getGovernancePlainSummary,
	isPendingExternalApply,
	normalizeActivityEntries,
	normalizeActivityDiscoveryBadges,
	normalizeGovernanceLearningReport,
	normalizeSelectedActivityActions,
	normalizeStoredActivityView,
	readPersistedActivityView,
	TERMINAL_DECISION_ERROR_CODES,
	writePersistedActivityView,
} from './activity-log-utils';

const ROOT_ID = 'flavor-agent-activity-log-root';
const NOT_RECORDED = 'Not recorded';
const ERROR_KIND_INVALID_DAY_FILTER = 'invalid-day-filter';
const ERROR_KIND_FETCH = 'fetch';
const LEARNING_REPORT_VERSION = 'governance-learning-report-v1';
// Leading-edge debounce window for the focus/visibility refresh: the first event
// fires synchronously, then further focus/visibility churn is suppressed for this
// long so rapid tab-switching can't hammer the feed or the /claim endpoint.
const FOCUS_REFRESH_DEBOUNCE_MS = 1000;
const RELATIVE_DAY_UNITS = new Set( [
	'hours',
	'days',
	'weeks',
	'months',
	'years',
] );

function getBootData() {
	return window.flavorAgentActivityLog || null;
}

function getLinkedActivityEntryId() {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	const searchParams = new URLSearchParams( window.location?.search || '' );
	const activityId = searchParams.get( 'activity' ) || '';

	return typeof activityId === 'string' ? activityId.trim() : '';
}

function setLinkedActivityEntryIdInUrl( activityId = '' ) {
	if (
		typeof window === 'undefined' ||
		typeof window.history?.replaceState !== 'function' ||
		typeof window.location?.href !== 'string'
	) {
		return;
	}

	const normalizedId =
		typeof activityId === 'string' && activityId.trim()
			? activityId.trim()
			: '';
	const nextUrl = new URL( window.location.href );

	if ( normalizedId ) {
		nextUrl.searchParams.set( 'activity', normalizedId );
	} else {
		nextUrl.searchParams.delete( 'activity' );
	}

	const nextLocation = `${ nextUrl.pathname }${ nextUrl.search }${ nextUrl.hash }`;
	const currentLocation = `${ window.location.pathname }${ window.location.search }${ window.location.hash }`;

	if ( nextLocation !== currentLocation ) {
		window.history.replaceState( window.history.state, '', nextLocation );
	}
}

function getInvalidDayFilterError() {
	return __(
		'Complete or reset the date filter to load activity.',
		'flavor-agent'
	);
}

function getIconForEntry( entry ) {
	switch ( entry?.status ) {
		case 'blocked':
		case 'failed':
			return caution;
		case 'undone':
			return undo;
		default:
			switch ( entry?.surface ) {
				case 'template':
				case 'template-part':
					return page;
				case 'global-styles':
					return plugins;
				case 'block':
					return symbol;
				default:
					return check;
			}
	}
}

function getSummaryCards( entries ) {
	const summary = entries || {};

	return [
		{
			id: 'total',
			label: __( 'Recorded actions', 'flavor-agent' ),
			value: summary?.total || 0,
			description: '',
		},
		{
			id: 'applied',
			label: __( 'Still applied', 'flavor-agent' ),
			value: summary?.applied || 0,
			description: '',
		},
		{
			id: 'undone',
			label: __( 'Undone', 'flavor-agent' ),
			value: summary?.undone || 0,
			description: '',
		},
		{
			id: 'review',
			label: __( 'Review-only', 'flavor-agent' ),
			value: summary?.review || 0,
			description: '',
		},
		{
			id: 'pending',
			label: __( 'Pending approval', 'flavor-agent' ),
			value: summary?.pending || 0,
			description: '',
		},
		{
			id: 'rejected',
			label: __( 'Rejected', 'flavor-agent' ),
			value: summary?.rejected || 0,
			description: '',
		},
		{
			id: 'expired',
			label: __( 'Expired', 'flavor-agent' ),
			value: summary?.expired || 0,
			description: '',
		},
		{
			id: 'blocked',
			label: __( 'Undo blocked', 'flavor-agent' ),
			value: summary?.blocked || 0,
			description: '',
		},
		{
			id: 'failed',
			label: __( 'Failed or unavailable', 'flavor-agent' ),
			value: summary?.failed || 0,
			description: '',
		},
	];
}

function isPlainRecord( value ) {
	return !! value && typeof value === 'object' && ! Array.isArray( value );
}

function getLearningReportInteger( value ) {
	const number = Number( value );

	return Number.isFinite( number ) && number > 0 ? Math.trunc( number ) : 0;
}

function getLearningReportRate( value ) {
	const number = Number( value );

	if ( ! Number.isFinite( number ) || number <= 0 ) {
		return 0;
	}

	return Math.min( number, 1 );
}

function formatLearningReportInteger( value, locale ) {
	const integer = getLearningReportInteger( value );

	try {
		return new Intl.NumberFormat( locale || undefined ).format( integer );
	} catch {
		return String( integer );
	}
}

function formatLearningReportRate( value ) {
	const percentage = getLearningReportRate( value ) * 100;
	const rounded = Math.round( percentage * 10 ) / 10;

	return `${ Number.isInteger( rounded ) ? rounded : rounded.toFixed( 1 ) }%`;
}

function getLearningReportGroups( report ) {
	const groups = Array.isArray( report?.groupSections )
		? report.groupSections
		: [];

	return groups.filter(
		( group ) =>
			isPlainRecord( group ) &&
			typeof group.key === 'string' &&
			!! group.key.trim() &&
			typeof group.label === 'string' &&
			!! group.label.trim() &&
			Array.isArray( group.rows ) &&
			group.rows.length > 0
	);
}

function getLearningReportSummaryMetrics( report, locale ) {
	const summary = isPlainRecord( report?.summary ) ? report.summary : {};

	return [
		{
			id: 'shown',
			label: __( 'Shown recommendations', 'flavor-agent' ),
			value: formatLearningReportInteger( summary.shownCount, locale ),
		},
		{
			id: 'review-selection',
			label: __( 'Review selection', 'flavor-agent' ),
			value: formatLearningReportRate( summary.reviewSelectionRate ),
		},
		{
			id: 'apply-conversion',
			label: __( 'Apply conversion', 'flavor-agent' ),
			value: formatLearningReportRate( summary.applyConversionRate ),
		},
		{
			id: 'undo',
			label: __( 'Undo rate', 'flavor-agent' ),
			value: formatLearningReportRate( summary.undoRate ),
		},
		{
			id: 'validation-blocked',
			label: __( 'Validation blocked', 'flavor-agent' ),
			value: formatLearningReportRate( summary.validationBlockedRate ),
		},
		{
			id: 'insert-failed',
			label: __( 'Insert failed', 'flavor-agent' ),
			value: formatLearningReportRate( summary.insertFailedRate ),
		},
	];
}

function LearningReportRow( { adminUrl, locale, row } ) {
	const activityUrl = buildActivityPermalink(
		adminUrl,
		row.representativeActivityId
	);

	return (
		<div className="flavor-agent-activity-log__learning-report-row">
			<div className="flavor-agent-activity-log__learning-report-row-main">
				{ activityUrl ? (
					<a
						className="flavor-agent-activity-log__learning-report-link"
						href={ activityUrl }
					>
						{ row.label }
					</a>
				) : (
					<span className="flavor-agent-activity-log__learning-report-label">
						{ row.label }
					</span>
				) }
				<span className="flavor-agent-activity-log__learning-report-row-size">
					{ sprintf(
						/* translators: %s: count of activity rows in the report bucket. */
						__( '%s rows', 'flavor-agent' ),
						formatLearningReportInteger( row.sampleSize, locale )
					) }
				</span>
			</div>
			<div className="flavor-agent-activity-log__learning-report-row-metrics">
				<span>
					{ sprintf(
						/* translators: %s: review-selection percentage. */
						__( 'Review %s', 'flavor-agent' ),
						formatLearningReportRate( row.reviewSelectionRate )
					) }
				</span>
				<span>
					{ sprintf(
						/* translators: %s: apply-conversion percentage. */
						__( 'Apply %s', 'flavor-agent' ),
						formatLearningReportRate( row.applyConversionRate )
					) }
				</span>
				<span>
					{ sprintf(
						/* translators: %s: validation-blocked percentage. */
						__( 'Blocked %s', 'flavor-agent' ),
						formatLearningReportRate( row.validationBlockedRate )
					) }
				</span>
			</div>
		</div>
	);
}

function LearningReportSection( { bootData, report } ) {
	if (
		! isPlainRecord( report ) ||
		report.version !== LEARNING_REPORT_VERSION
	) {
		return null;
	}

	const locale = bootData?.locale || undefined;
	const metrics = getLearningReportSummaryMetrics( report, locale );
	const groups = getLearningReportGroups( report );

	return (
		<section
			className="flavor-agent-activity-log__learning-report"
			aria-labelledby="flavor-agent-activity-log-learning-report-title"
		>
			<div className="flavor-agent-activity-log__learning-report-header">
				<div>
					<h2
						id="flavor-agent-activity-log-learning-report-title"
						className="flavor-agent-activity-log__section-title"
					>
						{ __( 'Governance learning report', 'flavor-agent' ) }
					</h2>
					<p className="flavor-agent-activity-log__learning-report-meta">
						<span>
							{ sprintf(
								/* translators: 1: sampled activity rows, 2: report row limit. */
								__(
									'Recent sample: %1$s of %2$s rows',
									'flavor-agent'
								),
								formatLearningReportInteger(
									report.sampleSize,
									locale
								),
								formatLearningReportInteger(
									report.rowLimit,
									locale
								)
							) }
						</span>
						{ !! report.truncated && (
							<span>
								{ __(
									'Truncated to newest matching rows',
									'flavor-agent'
								) }
							</span>
						) }
					</p>
				</div>
			</div>
			<dl className="flavor-agent-activity-log__learning-report-metrics">
				{ metrics.map( ( metric ) => (
					<div
						key={ metric.id }
						className="flavor-agent-activity-log__learning-report-metric"
					>
						<dt className="flavor-agent-activity-log__summary-label">
							{ metric.label }
						</dt>
						<dd className="flavor-agent-activity-log__summary-value">
							{ metric.value }
						</dd>
					</div>
				) ) }
			</dl>
			{ groups.length > 0 && (
				<div className="flavor-agent-activity-log__learning-report-groups">
					{ groups.map( ( group ) => (
						<section
							key={ group.key }
							className="flavor-agent-activity-log__learning-report-group"
							aria-labelledby={ `flavor-agent-activity-log-learning-report-${ group.key }` }
						>
							<h3
								id={ `flavor-agent-activity-log-learning-report-${ group.key }` }
								className="flavor-agent-activity-log__learning-report-group-title"
							>
								{ group.label }
							</h3>
							<div className="flavor-agent-activity-log__learning-report-rows">
								{ group.rows.map( ( row ) => (
									<LearningReportRow
										key={ row.key }
										adminUrl={ bootData?.adminUrl }
										locale={ locale }
										row={ row }
									/>
								) ) }
							</div>
						</section>
					) ) }
				</div>
			) }
		</section>
	);
}

function buildSelectElements( entries, key, { labelKey = key } = {} ) {
	const options = new Map();

	entries.forEach( ( entry ) => {
		const rawValue = entry?.[ key ];

		if (
			rawValue === undefined ||
			rawValue === null ||
			rawValue === '' ||
			rawValue === NOT_RECORDED
		) {
			return;
		}

		const value = String( rawValue );
		const label =
			typeof labelKey === 'function'
				? labelKey( entry )
				: String( entry?.[ labelKey ] || value );

		if ( ! options.has( value ) ) {
			options.set( value, label );
		}
	} );

	return Array.from( options.entries() )
		.sort( ( [ , leftLabel ], [ , rightLabel ] ) =>
			leftLabel.localeCompare( rightLabel )
		)
		.map( ( [ value, label ] ) => ( {
			value,
			label,
		} ) );
}

function getServerFilterOptions( responseData, key ) {
	if (
		! responseData?.filterOptions ||
		! Object.hasOwn( responseData.filterOptions, key )
	) {
		return null;
	}

	const options = responseData.filterOptions[ key ];

	if ( ! Array.isArray( options ) ) {
		return [];
	}

	const deduped = new Map();

	options.forEach( ( option ) => {
		if (
			option &&
			option.value !== undefined &&
			option.value !== null &&
			option.value !== '' &&
			option.label !== undefined &&
			option.label !== null &&
			option.label !== ''
		) {
			const value = String( option.value );

			if ( ! deduped.has( value ) ) {
				deduped.set( value, {
					value,
					label: String( option.label ),
				} );
			}
		}
	} );

	return Array.from( deduped.values() );
}

function getViewFilter( view, field ) {
	return Array.isArray( view?.filters )
		? view.filters.find( ( filter ) => filter?.field === field ) || null
		: null;
}

function isPendingApprovalView( view ) {
	const statusFilter = getViewFilter( view, 'status' );
	const value = Array.isArray( statusFilter?.value )
		? statusFilter.value[ 0 ]
		: statusFilter?.value;

	return statusFilter?.operator === 'is' && value === 'pending';
}

function withPendingApprovalFilter( view, options ) {
	const normalizedView = normalizeStoredActivityView( view, options );
	const filters = normalizedView.filters.filter(
		( filter ) => filter?.field !== 'status'
	);

	if ( isPendingApprovalView( normalizedView ) ) {
		return {
			...normalizedView,
			page: 1,
			filters,
		};
	}

	return {
		...normalizedView,
		page: 1,
		filters: [
			...filters,
			{ field: 'status', operator: 'is', value: 'pending' },
		],
	};
}

function appendExplicitFilter( params, fieldName, filter ) {
	if ( ! filter ) {
		return;
	}

	const value = Array.isArray( filter.value )
		? filter.value[ 0 ]
		: filter.value;

	if ( value === undefined || value === null || value === '' ) {
		return;
	}

	params.set( fieldName, String( value ) );

	if ( filter.operator ) {
		params.set( `${ fieldName }Operator`, String( filter.operator ) );
	}
}

function getDayFilterState( filter ) {
	if ( ! filter || ! filter.operator ) {
		return 'inactive';
	}

	if ( filter.value === undefined || filter.value === null ) {
		return 'invalid';
	}

	if ( filter.operator === 'between' && Array.isArray( filter.value ) ) {
		const dayStart = String( filter.value[ 0 ] || '' );
		const dayEnd = String( filter.value[ 1 ] || '' );

		if ( ! dayStart && ! dayEnd ) {
			return 'invalid';
		}

		if (
			isValidActivityDay( dayStart ) &&
			isValidActivityDay( dayEnd ) &&
			dayStart <= dayEnd
		) {
			return 'valid';
		}

		return 'invalid';
	}

	if (
		[ 'inThePast', 'over' ].includes( filter.operator ) &&
		filter.value &&
		typeof filter.value === 'object'
	) {
		if (
			Number.isInteger( filter.value.value ) &&
			filter.value.value > 0 &&
			RELATIVE_DAY_UNITS.has( filter.value.unit )
		) {
			return 'valid';
		}

		return 'invalid';
	}

	const day = String( filter.value || '' );

	if ( ! day ) {
		return [ 'on', 'before', 'after' ].includes( filter.operator )
			? 'invalid'
			: 'inactive';
	}

	if (
		[ 'on', 'before', 'after' ].includes( filter.operator ) &&
		isValidActivityDay( day )
	) {
		return 'valid';
	}

	return 'invalid';
}

function hasInvalidDayFilter( view ) {
	return getDayFilterState( getViewFilter( view, 'day' ) ) === 'invalid';
}

function appendDayFilter( params, filter ) {
	if ( getDayFilterState( filter ) !== 'valid' ) {
		return;
	}

	params.set( 'dayOperator', String( filter.operator ) );

	if ( filter.operator === 'between' ) {
		params.set( 'day', String( filter.value[ 0 ] ) );
		params.set( 'dayEnd', String( filter.value[ 1 ] ) );
		return;
	}

	if ( [ 'inThePast', 'over' ].includes( filter.operator ) ) {
		params.set( 'dayRelativeValue', String( filter.value.value ) );
		params.set( 'dayRelativeUnit', String( filter.value.unit ) );
		return;
	}

	params.set( 'day', String( filter.value ) );
}

function isValidActivityDay( value ) {
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
		return false;
	}

	const [ year, month, day ] = value.split( '-' ).map( Number );
	const date = new Date( Date.UTC( year, month - 1, day ) );

	return (
		date.getUTCFullYear() === year &&
		date.getUTCMonth() === month - 1 &&
		date.getUTCDate() === day
	);
}

function getActivityRequestUrl( bootData, view, linkedActivityId ) {
	const params = new URLSearchParams( {
		global: '1',
		includeReports: '1',
		page: String( view.page || 1 ),
		perPage: String( view.perPage || bootData.defaultPerPage ),
	} );

	if ( view.search ) {
		params.set( 'search', view.search );
	}

	if ( view.sort?.field ) {
		params.set( 'sortField', view.sort.field );
		params.set( 'sortDirection', view.sort.direction || 'desc' );
	}

	if (
		typeof linkedActivityId === 'string' &&
		'' !== linkedActivityId.trim()
	) {
		params.set( 'activity', linkedActivityId.trim() );
	}

	appendExplicitFilter( params, 'surface', getViewFilter( view, 'surface' ) );
	appendExplicitFilter( params, 'status', getViewFilter( view, 'status' ) );
	appendExplicitFilter(
		params,
		'postType',
		getViewFilter( view, 'postType' )
	);
	appendExplicitFilter(
		params,
		'provider',
		getViewFilter( view, 'provider' )
	);
	appendExplicitFilter(
		params,
		'providerPath',
		getViewFilter( view, 'providerPath' )
	);
	appendExplicitFilter(
		params,
		'configurationOwner',
		getViewFilter( view, 'configurationOwner' )
	);
	appendExplicitFilter(
		params,
		'credentialSource',
		getViewFilter( view, 'credentialSource' )
	);
	appendExplicitFilter(
		params,
		'selectedProvider',
		getViewFilter( view, 'selectedProvider' )
	);
	appendExplicitFilter( params, 'userId', getViewFilter( view, 'userId' ) );
	appendExplicitFilter(
		params,
		'operationType',
		getViewFilter( view, 'operationType' )
	);
	appendExplicitFilter(
		params,
		'entityId',
		getViewFilter( view, 'entityId' )
	);
	appendExplicitFilter(
		params,
		'blockPath',
		getViewFilter( view, 'blockPath' )
	);
	appendDayFilter( params, getViewFilter( view, 'day' ) );

	return `${
		bootData.restUrl
	}flavor-agent/v1/activity?${ params.toString() }`;
}

function getPerPageSizes( defaultPerPage, maxPerPage ) {
	return Array.from(
		new Set(
			[ 10, 20, 50, 100, defaultPerPage ].filter(
				( value ) =>
					Number.isInteger( value ) &&
					value > 0 &&
					value <= maxPerPage
			)
		)
	).sort( ( left, right ) => left - right );
}

function EmptyState( { view } ) {
	const message = view.search
		? sprintf(
				/* translators: %s: activity search query. */
				__( 'No AI actions matched “%s”.', 'flavor-agent' ),
				view.search
		  )
		: __( 'No AI activity has been recorded yet.', 'flavor-agent' );

	return (
		<Card className="flavor-agent-activity-log__empty" size="small">
			<CardBody>
				<h3 className="flavor-agent-activity-log__section-title">
					{ __( 'No matching activity', 'flavor-agent' ) }
				</h3>
				<p className="flavor-agent-activity-log__copy">{ message }</p>
			</CardBody>
		</Card>
	);
}

function ErrorState( { error, actionLabel, onAction } ) {
	return (
		<Card className="flavor-agent-activity-log__empty" size="small">
			<CardBody>
				<div role="alert">
					<div className="flavor-agent-activity-log__error-heading">
						<span className="flavor-agent-activity-log__error-icon">
							<Icon icon={ caution } />
						</span>
						<h3 className="flavor-agent-activity-log__section-title">
							{ __( 'Activity log unavailable', 'flavor-agent' ) }
						</h3>
					</div>
					<p className="flavor-agent-activity-log__copy">
						{ error ||
							__(
								'Flavor Agent could not load the recent AI activity log.',
								'flavor-agent'
							) }
					</p>
					<div className="flavor-agent-activity-log__empty-actions">
						<Button variant="secondary" onClick={ onAction }>
							{ actionLabel ||
								__( 'Retry loading activity', 'flavor-agent' ) }
						</Button>
					</div>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * Section spec for the entry-detail sidebar.
 *
 * Each row is `[label, fieldKey]` (text) or `[label, fieldKey, 'code']` for
 * pre-formatted blobs. `summary` produces the dimmed line shown next to the
 * section heading; rows where the value is empty or "Not recorded" are
 * either dimmed (text) or hidden (code), keeping the sidebar scannable.
 */
const DETAIL_SECTIONS = [
	{
		id: 'overview',
		label: __( 'Overview', 'flavor-agent' ),
		summary: ( entry ) =>
			[ entry.statusLabel, entry.surfaceLabel, entry.operationTypeLabel ]
				.filter( ( value ) => value && value !== NOT_RECORDED )
				.join( ' · ' ),
		rows: [
			[ __( 'Status', 'flavor-agent' ), 'statusLabel', 'status' ],
			[ __( 'Recorded', 'flavor-agent' ), 'timestampDisplay' ],
			[ __( 'Surface', 'flavor-agent' ), 'surfaceLabel' ],
			[ __( 'Action type', 'flavor-agent' ), 'operationTypeLabel' ],
			[
				__( 'Recorded activity type', 'flavor-agent' ),
				'activityTypeLabel',
			],
			[ __( 'Entity', 'flavor-agent' ), 'entity' ],
			[ __( 'Post type', 'flavor-agent' ), 'postType' ],
			[ __( 'Entity ID', 'flavor-agent' ), 'entityId' ],
			[ __( 'Document', 'flavor-agent' ), 'documentLabel' ],
			[ __( 'Document scope', 'flavor-agent' ), 'documentScopeKey' ],
			[ __( 'Block path', 'flavor-agent' ), 'blockPath' ],
			[ __( 'User', 'flavor-agent' ), 'user' ],
		],
		initialOpen: true,
	},
	{
		id: 'undo',
		label: __( 'Undo state', 'flavor-agent' ),
		summary: ( entry ) => {
			const parts = [ entry.undoStatusLabel ];

			if ( entry.undoReason && entry.undoReason !== NOT_RECORDED ) {
				parts.push( entry.undoReason );
			}

			return parts.filter( Boolean ).join( ' · ' );
		},
		rows: [
			[ __( 'Undo state', 'flavor-agent' ), 'undoStatusLabel' ],
			[ __( 'Undo reason', 'flavor-agent' ), 'undoReason' ],
			[ __( 'Undo error', 'flavor-agent' ), 'undoError' ],
		],
	},
	{
		id: 'diagnostics',
		label: __( 'Provider diagnostics', 'flavor-agent' ),
		summary: ( entry ) =>
			[ entry.provider, entry.model, entry.providerPath ]
				.filter( ( value ) => value && value !== NOT_RECORDED )
				.join( ' · ' ),
		rows: [
			[ __( 'Provider', 'flavor-agent' ), 'provider' ],
			[ __( 'Model', 'flavor-agent' ), 'model' ],
			[ __( 'Provider path', 'flavor-agent' ), 'providerPath' ],
			[ __( 'Configured in', 'flavor-agent' ), 'configurationOwner' ],
			[ __( 'Credential source', 'flavor-agent' ), 'credentialSource' ],
			[ __( 'Selected provider', 'flavor-agent' ), 'selectedProvider' ],
			[ __( 'Connector', 'flavor-agent' ), 'connector' ],
			[ __( 'Connector plugin', 'flavor-agent' ), 'connectorPlugin' ],
			[ __( 'Fallback', 'flavor-agent' ), 'requestFallback' ],
			[ __( 'Token usage', 'flavor-agent' ), 'tokenUsage' ],
			[ __( 'Latency', 'flavor-agent' ), 'latency' ],
			[ __( 'Endpoint', 'flavor-agent' ), 'transportEndpoint' ],
			[ __( 'Timeout', 'flavor-agent' ), 'timeout' ],
			[ __( 'Payload', 'flavor-agent' ), 'requestPayload' ],
			[ __( 'Response', 'flavor-agent' ), 'responseSummary' ],
			[
				__( 'Provider request ID', 'flavor-agent' ),
				'providerRequestId',
			],
			[
				__( 'Transport detail', 'flavor-agent' ),
				'transportError',
				'code',
			],
		],
	},
	{
		id: 'request',
		label: __( 'Request context', 'flavor-agent' ),
		summary: ( entry ) => {
			const parts = [
				entry.requestAbility,
				entry.requestRoute,
				entry.requestReference,
			].filter( ( value ) => value && value !== NOT_RECORDED );

			if ( ! isEmptyDetailValue( entry.requestPrompt ) ) {
				parts.push( __( 'Prompt recorded', 'flavor-agent' ) );
			}

			return parts.join( ' · ' );
		},
		rows: [
			[ __( 'Ability', 'flavor-agent' ), 'requestAbility' ],
			[ __( 'Route', 'flavor-agent' ), 'requestRoute' ],
			[ __( 'Reference', 'flavor-agent' ), 'requestReference' ],
			[ __( 'Prompt', 'flavor-agent' ), 'requestPrompt', 'code' ],
		],
	},
	{
		id: 'state',
		label: __( 'State snapshots', 'flavor-agent' ),
		summary: ( entry ) =>
			[
				[ __( 'Structured diff', 'flavor-agent' ), 'stateDiff' ],
				[ __( 'Before', 'flavor-agent' ), 'beforeSummary' ],
				[ __( 'After', 'flavor-agent' ), 'afterSummary' ],
			]
				.filter( ( [ , key ] ) => ! isEmptyDetailValue( entry[ key ] ) )
				.map( ( [ label ] ) => label )
				.filter( ( value ) => value && value !== NOT_RECORDED )
				.join( ' · ' ),
		rows: [
			[ __( 'Structured diff', 'flavor-agent' ), 'stateDiff', 'code' ],
			[ __( 'Before', 'flavor-agent' ), 'beforeSummary', 'code' ],
			[ __( 'After', 'flavor-agent' ), 'afterSummary', 'code' ],
		],
	},
];

function isEmptyDetailValue( value ) {
	return (
		value === undefined ||
		value === null ||
		value === '' ||
		value === NOT_RECORDED
	);
}

function getNonEmptyDetailValues( values ) {
	const seen = new Set();

	return values.filter( ( value ) => {
		if ( isEmptyDetailValue( value ) || seen.has( value ) ) {
			return false;
		}

		seen.add( value );
		return true;
	} );
}

function getEntryTargetSummary( entry ) {
	const governanceDetails = getGovernanceDetailsForEntry( entry );
	const values = governanceDetails
		? [
				governanceDetails.targetLabel,
				governanceDetails.surfaceLabel,
				entry.documentLabel,
		  ]
		: [ entry.surfaceLabel, entry.entity, entry.documentLabel ];

	return getNonEmptyDetailValues( values ).join( ' · ' ) || NOT_RECORDED;
}

function getEntryRequesterSummary( entry ) {
	const governanceDetails = getGovernanceDetailsForEntry( entry );

	return (
		getNonEmptyDetailValues( [
			governanceDetails?.requestedByLabel,
			entry.user,
		] )[ 0 ] || NOT_RECORDED
	);
}

function getEntryTechnicalReviewSummary( entry ) {
	if ( entry?.aiRequestLogId ) {
		return __(
			'AI request log available; diagnostics and snapshots below',
			'flavor-agent'
		);
	}

	if ( entry?.modelRequest?.attempted === false ) {
		return __(
			'No model request; diagnostics and snapshots below',
			'flavor-agent'
		);
	}

	if ( entry?.aiRequestToken ) {
		return __(
			'Request token recorded; AI request log unavailable',
			'flavor-agent'
		);
	}

	return __(
		'Provider diagnostics, request context, and state snapshots below',
		'flavor-agent'
	);
}

function getEntryStoryRows( entry ) {
	return [
		{
			label: __( 'Current status', 'flavor-agent' ),
			value: entry.statusLabel,
			kind: 'status',
		},
		{
			label: __( 'Action', 'flavor-agent' ),
			value:
				getNonEmptyDetailValues( [
					entry.operationTypeLabel,
					entry.activityTypeLabel,
				] ).join( ' · ' ) || NOT_RECORDED,
		},
		{
			label: __( 'Surface / entity', 'flavor-agent' ),
			value: getEntryTargetSummary( entry ),
		},
		{
			label: __( 'Requested by', 'flavor-agent' ),
			value: getEntryRequesterSummary( entry ),
		},
		{
			label: __( 'Recorded', 'flavor-agent' ),
			value: entry.timestampDisplay,
		},
		{
			label: __( 'Technical review', 'flavor-agent' ),
			value: getEntryTechnicalReviewSummary( entry ),
		},
	];
}

function ActivityDetailRow( { label, value, kind, status } ) {
	const empty = isEmptyDetailValue( value );

	if ( kind === 'code' ) {
		// Code blobs hide entirely when empty — there's nothing to inspect.
		if ( empty ) {
			return null;
		}

		return (
			<Fragment>
				<dt className="flavor-agent-activity-log__detail-label flavor-agent-activity-log__detail-label--code">
					{ label }
				</dt>
				<dd className="flavor-agent-activity-log__detail-value flavor-agent-activity-log__detail-value--code">
					<pre className="flavor-agent-activity-log__code">
						{ value }
					</pre>
				</dd>
			</Fragment>
		);
	}

	if ( kind === 'status' ) {
		return (
			<Fragment>
				<dt className="flavor-agent-activity-log__detail-label">
					{ label }
				</dt>
				<dd className="flavor-agent-activity-log__detail-value">
					<span
						className={ `flavor-agent-activity-log__status is-${ status }` }
					>
						{ value }
					</span>
				</dd>
			</Fragment>
		);
	}

	return (
		<Fragment>
			<dt className="flavor-agent-activity-log__detail-label">
				{ label }
			</dt>
			<dd
				className={
					empty
						? 'flavor-agent-activity-log__detail-value is-empty'
						: 'flavor-agent-activity-log__detail-value'
				}
			>
				{ value }
			</dd>
		</Fragment>
	);
}

function ActivityEntryStory( { entry } ) {
	return (
		<section className="flavor-agent-activity-log__entry-story">
			<h4 className="flavor-agent-activity-log__sidebar-subtitle">
				{ __( 'At a glance', 'flavor-agent' ) }
			</h4>
			<dl className="flavor-agent-activity-log__detail-grid flavor-agent-activity-log__detail-grid--story">
				{ getEntryStoryRows( entry ).map( ( row ) => (
					<ActivityDetailRow
						key={ row.label }
						label={ row.label }
						value={ row.value }
						kind={ row.kind }
						status={
							row.kind === 'status' ? entry.status : undefined
						}
					/>
				) ) }
			</dl>
		</section>
	);
}

function SelectedActivityActions( { actions, onFilterAction } ) {
	if ( ! actions.length ) {
		return null;
	}

	const linkActions = actions.filter( ( action ) => action.type === 'link' );
	const filterActions = actions.filter(
		( action ) => action.type === 'filter'
	);

	return (
		<div
			className="flavor-agent-activity-log__action-strip"
			aria-label={ __( 'Selected row actions', 'flavor-agent' ) }
		>
			{ linkActions.length > 0 && (
				<div className="flavor-agent-activity-log__action-group">
					<span className="flavor-agent-activity-log__action-group-label">
						{ __( 'Open', 'flavor-agent' ) }
					</span>
					<div className="flavor-agent-activity-log__action-buttons">
						{ linkActions.map( ( action ) => (
							<Button
								key={ action.id }
								href={ action.url }
								variant="secondary"
							>
								<span className="flavor-agent-activity-log__action-label">
									{ action.label }
								</span>
								{ action.detail && (
									<span className="flavor-agent-activity-log__action-detail">
										{ action.detail }
									</span>
								) }
							</Button>
						) ) }
					</div>
				</div>
			) }
			{ filterActions.length > 0 && (
				<div className="flavor-agent-activity-log__action-group">
					<span className="flavor-agent-activity-log__action-group-label">
						{ __( 'Related rows', 'flavor-agent' ) }
					</span>
					<div className="flavor-agent-activity-log__action-buttons">
						{ filterActions.map( ( action ) => (
							<Button
								key={ action.id }
								type="button"
								variant="tertiary"
								onClick={ () => onFilterAction( action ) }
							>
								{ action.label }
							</Button>
						) ) }
					</div>
				</div>
			) }
		</div>
	);
}

function LinkedActivityBanner( { activityId, entry, onClear } ) {
	if ( ! activityId ) {
		return null;
	}

	return (
		<div
			className="flavor-agent-activity-log__linked-row-banner"
			role="status"
		>
			<div className="flavor-agent-activity-log__linked-row-copy">
				<span className="flavor-agent-activity-log__linked-row-kicker">
					{ __( 'Focused row', 'flavor-agent' ) }
				</span>
				<p className="flavor-agent-activity-log__copy">
					{ entry?.title
						? sprintf(
								/* translators: %s: focused activity row title. */
								__(
									'Showing the focused activity row: %s',
									'flavor-agent'
								),
								entry.title
						  )
						: __(
								'Showing a focused activity row from the URL.',
								'flavor-agent'
						  ) }
				</p>
			</div>
			<Button type="button" variant="secondary" onClick={ onClear }>
				{ __( 'Clear focused row', 'flavor-agent' ) }
			</Button>
		</div>
	);
}

function ActivityDetailSection( { section, entry } ) {
	const [ isOpen, setIsOpen ] = useState( Boolean( section.initialOpen ) );
	const summary = section.summary( entry ) || '';
	const visibleRows = section.rows.filter( ( [ , key, kind ] ) => {
		if ( kind !== 'code' ) {
			return true;
		}

		return ! isEmptyDetailValue( entry[ key ] );
	} );

	if ( visibleRows.length === 0 ) {
		return null;
	}

	const rows = visibleRows.map( ( [ label, key, kind ] ) => (
		<ActivityDetailRow
			key={ key }
			label={ label }
			value={ entry[ key ] }
			kind={ kind }
			status={ kind === 'status' ? entry.status : undefined }
		/>
	) );

	return (
		<details
			className="flavor-agent-activity-log__detail-section"
			open={ isOpen }
			onToggle={ ( event ) => setIsOpen( event.currentTarget.open ) }
		>
			<summary className="flavor-agent-activity-log__detail-summary">
				<span className="flavor-agent-activity-log__detail-summary-label">
					{ section.label }
				</span>
				{ summary && (
					<span className="flavor-agent-activity-log__detail-summary-text">
						{ summary }
					</span>
				) }
			</summary>
			<dl className="flavor-agent-activity-log__detail-grid">{ rows }</dl>
		</details>
	);
}

function getNumberValue( value ) {
	const normalized = Number( value );

	return Number.isFinite( normalized ) ? normalized : null;
}

function formatCoreRequestLogValue( value ) {
	if ( value === undefined || value === null || value === '' ) {
		return NOT_RECORDED;
	}

	return String( value );
}

function normalizeCoreRequestLogDetails( log ) {
	const inputTokens = getNumberValue( log?.tokens_input );
	const outputTokens = getNumberValue( log?.tokens_output );
	const totalTokens = getNumberValue( log?.tokens_total );
	const durationMs = getNumberValue( log?.duration_ms );
	const tokenUsage =
		totalTokens !== null
			? `${ totalTokens } total tokens`
			: [
					inputTokens !== null ? `${ inputTokens } input` : null,
					outputTokens !== null ? `${ outputTokens } output` : null,
			  ]
					.filter( Boolean )
					.join( ' / ' );

	return {
		provider: formatCoreRequestLogValue( log?.provider ),
		model: formatCoreRequestLogValue( log?.model ),
		duration: durationMs !== null ? `${ durationMs } ms` : NOT_RECORDED,
		tokenUsage: tokenUsage || NOT_RECORDED,
		requestPreview: formatCoreRequestLogValue(
			log?.request_preview || log?.requestPreview
		),
		responsePreview: formatCoreRequestLogValue(
			log?.response_preview || log?.responsePreview
		),
	};
}

function AiRequestLogPanel( { entry, bootData } ) {
	const requestLogId = entry?.aiRequestLogId || '';
	const requestToken = entry?.aiRequestToken || '';
	const requestLogLoadTokenRef = useRef( 0 );
	const [ requestLogState, setRequestLogState ] = useState( {
		id: requestLogId,
		details: null,
		error: '',
		isLoading: false,
	} );

	useEffect( () => {
		requestLogLoadTokenRef.current += 1;
		setRequestLogState( {
			id: requestLogId,
			details: null,
			error: '',
			isLoading: false,
		} );
	}, [ requestLogId ] );

	useEffect(
		() => () => {
			requestLogLoadTokenRef.current += 1;
		},
		[]
	);

	if ( entry?.modelRequest?.attempted === false ) {
		return (
			<section className="flavor-agent-activity-log__request-log flavor-agent-activity-log__request-log--no-model">
				<h4 className="flavor-agent-activity-log__sidebar-subtitle">
					{ __( 'AI request log', 'flavor-agent' ) }
				</h4>
				<p className="flavor-agent-activity-log__copy">
					{ __(
						'No model request was attempted for this diagnostic.',
						'flavor-agent'
					) }
				</p>
			</section>
		);
	}

	if ( ! requestLogId && ! requestToken ) {
		return null;
	}

	if ( ! requestLogId ) {
		return (
			<section className="flavor-agent-activity-log__request-log flavor-agent-activity-log__request-log--unavailable">
				<h4 className="flavor-agent-activity-log__sidebar-subtitle">
					{ __( 'AI request log', 'flavor-agent' ) }
				</h4>
				<p className="flavor-agent-activity-log__copy">
					{ __(
						'AI request log unavailable (core logging may have been disabled at request time).',
						'flavor-agent'
					) }
				</p>
			</section>
		);
	}

	const loadRequestLog = async () => {
		const loadToken = ++requestLogLoadTokenRef.current;

		setRequestLogState( ( current ) => ( {
			...current,
			error: '',
			isLoading: true,
		} ) );

		try {
			const response = await apiFetch( {
				url: `${ bootData.restUrl }ai/v1/logs/${ encodeURIComponent(
					requestLogId
				) }`,
				headers: {
					'X-WP-Nonce': bootData.nonce,
				},
			} );

			if ( requestLogLoadTokenRef.current !== loadToken ) {
				return;
			}

			setRequestLogState( {
				id: requestLogId,
				details: normalizeCoreRequestLogDetails( response || {} ),
				error: '',
				isLoading: false,
			} );
		} catch ( fetchError ) {
			if ( requestLogLoadTokenRef.current !== loadToken ) {
				return;
			}

			setRequestLogState( {
				id: requestLogId,
				details: null,
				error:
					fetchError?.message ||
					__(
						'Flavor Agent could not load this AI request log.',
						'flavor-agent'
					),
				isLoading: false,
			} );
		}
	};

	const details = requestLogState.details;
	const requestLogButtonLabel = __( 'View AI request', 'flavor-agent' );
	const requestLogLoadingLabel = __(
		'Loading AI request log.',
		'flavor-agent'
	);
	const rows = details
		? [
				[ __( 'Provider', 'flavor-agent' ), details.provider ],
				[ __( 'Model', 'flavor-agent' ), details.model ],
				[ __( 'Duration', 'flavor-agent' ), details.duration ],
				[ __( 'Tokens', 'flavor-agent' ), details.tokenUsage ],
		  ]
		: [];

	return (
		<section className="flavor-agent-activity-log__request-log">
			<div>
				<h4 className="flavor-agent-activity-log__sidebar-subtitle">
					{ __( 'AI request log', 'flavor-agent' ) }
				</h4>
				<p className="flavor-agent-activity-log__copy">
					{ __(
						'Open the WordPress AI request trace for provider, model, timing, tokens, request, and response previews.',
						'flavor-agent'
					) }
				</p>
			</div>
			<div className="flavor-agent-activity-log__request-log-actions">
				<Button
					aria-busy={ requestLogState.isLoading }
					aria-label={
						requestLogState.isLoading
							? requestLogLoadingLabel
							: requestLogButtonLabel
					}
					isBusy={ requestLogState.isLoading }
					variant="secondary"
					onClick={ loadRequestLog }
					disabled={ requestLogState.isLoading }
				>
					{ requestLogButtonLabel }
				</Button>
				{ requestLogState.isLoading && (
					<span
						className="screen-reader-text"
						role="status"
						aria-live="polite"
					>
						{ requestLogLoadingLabel }
					</span>
				) }
				{ entry.aiRequestLogsUrl && (
					<Button
						href={ entry.aiRequestLogsUrl }
						target="_blank"
						rel="noreferrer"
						variant="secondary"
					>
						{ __( 'Open in AI Request Logs', 'flavor-agent' ) }
					</Button>
				) }
			</div>
			{ requestLogState.error && (
				<Notice
					className="flavor-agent-activity-log__request-log-error"
					status="error"
					isDismissible={ false }
				>
					{ requestLogState.error }
				</Notice>
			) }
			{ details && (
				<div className="flavor-agent-activity-log__request-log-details">
					<p className="flavor-agent-activity-log__request-log-summary">
						{ __( 'Loaded request details', 'flavor-agent' ) }
					</p>
					<dl className="flavor-agent-activity-log__detail-grid">
						{ rows.map( ( [ label, value ] ) => (
							<ActivityDetailRow
								key={ label }
								label={ label }
								value={ value }
							/>
						) ) }
						<ActivityDetailRow
							label={ __( 'Request preview', 'flavor-agent' ) }
							value={ details.requestPreview }
							kind="code"
						/>
						<ActivityDetailRow
							label={ __( 'Response preview', 'flavor-agent' ) }
							value={ details.responsePreview }
							kind="code"
						/>
					</dl>
				</div>
			) }
		</section>
	);
}

function getGovernanceDetailsForEntry( entry ) {
	return entry?.governanceDetails || getGovernanceDetails( entry );
}

function GovernanceOperationList( { title, operations = [] } ) {
	if ( ! operations.length ) {
		return null;
	}

	return (
		<div className="flavor-agent-activity-log__governance-subsection">
			<h4 className="flavor-agent-activity-log__governance-subtitle">
				{ title }
			</h4>
			<ul className="flavor-agent-activity-log__governance-list">
				{ operations.map( ( operation, index ) => (
					<li key={ `${ title }-${ index }` }>{ operation }</li>
				) ) }
			</ul>
		</div>
	);
}

function getVisualDiffStatusLabel( status = '' ) {
	switch ( status ) {
		case 'proposed':
			return __( 'Proposed only', 'flavor-agent' );
		case 'undone':
			return __( 'Undone', 'flavor-agent' );
		case 'blocked':
			return __( 'Undo blocked', 'flavor-agent' );
		case 'unsupported':
			return __( 'Text fallback', 'flavor-agent' );
		default:
			return __( 'Applied', 'flavor-agent' );
	}
}

function GovernanceVisualDiffValue( { value = '', visual = null } ) {
	if ( ! value ) {
		return null;
	}

	const showRawValue = ! visual || visual.label !== value;
	const livePreviewLabel = __(
		'Live preview resolved from the current theme palette, not a frozen snapshot of when this change was recorded.',
		'flavor-agent'
	);

	return (
		<div className="flavor-agent-activity-log__visual-diff-value">
			{ visual?.type === 'swatch' && (
				<div
					className={ `flavor-agent-activity-log__visual-diff-meta${
						visual.resolvedFromPalette
							? ' flavor-agent-activity-log__visual-diff-meta--live'
							: ''
					}` }
				>
					<span
						className="flavor-agent-activity-log__visual-diff-swatch"
						aria-hidden="true"
						style={ { background: visual.cssValue } }
					/>
					<span className="flavor-agent-activity-log__visual-diff-chip">
						{ visual.label }
					</span>
					{ visual.resolvedFromPalette && (
						<span
							className="flavor-agent-activity-log__visual-diff-live"
							title={ livePreviewLabel }
						>
							<span aria-hidden="true">{ '∼' }</span>
							<span className="screen-reader-text">
								{ livePreviewLabel }
							</span>
						</span>
					) }
				</div>
			) }
			{ visual?.type === 'chip' && (
				<span className="flavor-agent-activity-log__visual-diff-chip">
					{ visual.label }
				</span>
			) }
			{ showRawValue && (
				<span className="flavor-agent-activity-log__visual-diff-raw">
					{ value }
				</span>
			) }
		</div>
	);
}

function GovernanceVisualDiffStage( { label, value = '', visual = null } ) {
	if ( ! value ) {
		return null;
	}

	return (
		<div className="flavor-agent-activity-log__visual-diff-stage">
			<span className="flavor-agent-activity-log__visual-diff-stage-label">
				{ label }
			</span>
			<GovernanceVisualDiffValue value={ value } visual={ visual } />
		</div>
	);
}

function GovernanceVisualDiffViewer( { rows = [] } ) {
	if ( ! rows.length ) {
		return null;
	}

	return (
		<div className="flavor-agent-activity-log__governance-subsection">
			<h4 className="flavor-agent-activity-log__governance-subtitle">
				{ __( 'Style changes', 'flavor-agent' ) }
			</h4>
			<div className="flavor-agent-activity-log__visual-diff">
				{ rows.map( ( row, index ) => (
					<section
						key={ `${ row.label }-${ index }` }
						className={ `flavor-agent-activity-log__visual-diff-row is-${ row.status } is-${ row.kind } state-${ row.changeState }` }
					>
						<div className="flavor-agent-activity-log__visual-diff-row-header">
							<div className="flavor-agent-activity-log__visual-diff-row-copy">
								<h5 className="flavor-agent-activity-log__visual-diff-row-title">
									{ row.label }
								</h5>
								{ row.kind === 'variation' &&
									! row.hasResolvedVariationIdentity && (
										<p className="flavor-agent-activity-log__visual-diff-row-note">
											{ __(
												'Variation identity was not recorded in the snapshots. Inspect State snapshots below for raw evidence.',
												'flavor-agent'
											) }
										</p>
									) }
								{ row.kind === 'unsupported' && (
									<p className="flavor-agent-activity-log__visual-diff-row-note">
										{ __(
											'Rendered as plain text because this operation does not have a safe visual diff.',
											'flavor-agent'
										) }
									</p>
								) }
							</div>
							<span className="flavor-agent-activity-log__visual-diff-status">
								{ getVisualDiffStatusLabel( row.status ) }
							</span>
						</div>
						<div className="flavor-agent-activity-log__visual-diff-stages">
							{ row.kind === 'variation' &&
							! row.hasResolvedVariationIdentity ? (
								<GovernanceVisualDiffStage
									label={ __(
										'Proposed variation',
										'flavor-agent'
									) }
									value={ row.proposed }
									visual={ row.proposedVisual }
								/>
							) : (
								<Fragment>
									<GovernanceVisualDiffStage
										label={ __( 'Before', 'flavor-agent' ) }
										value={ row.before }
										visual={ row.beforeVisual }
									/>
									<GovernanceVisualDiffStage
										label={ __(
											'Proposed',
											'flavor-agent'
										) }
										value={ row.proposed }
										visual={ row.proposedVisual }
									/>
									<GovernanceVisualDiffStage
										label={ __( 'After', 'flavor-agent' ) }
										value={ row.after }
										visual={ row.afterVisual }
									/>
								</Fragment>
							) }
						</div>
					</section>
				) ) }
			</div>
		</div>
	);
}

function GovernanceDetailRows( { rows = [] } ) {
	const visibleRows = rows.filter(
		( [ , value ] ) =>
			value !== undefined &&
			value !== null &&
			value !== '' &&
			value !== NOT_RECORDED
	);

	if ( ! visibleRows.length ) {
		return null;
	}

	return (
		<dl className="flavor-agent-activity-log__detail-grid">
			{ visibleRows.map( ( [ label, value ] ) => (
				<ActivityDetailRow
					key={ label }
					label={ label }
					value={ value }
				/>
			) ) }
		</dl>
	);
}

function getAttestationString( artifact, key ) {
	const value = artifact?.[ key ];

	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function getAttestationArtifact( entry ) {
	const artifact = isPlainRecord( entry?.attestation )
		? entry.attestation
		: null;
	const id = getAttestationString( artifact, 'id' );

	if ( ! id ) {
		return null;
	}

	return {
		id,
		verificationUrl: getAttestationString( artifact, 'verificationUrl' ),
		verifyUrl: getAttestationString( artifact, 'verifyUrl' ),
		subjectStateUrl: getAttestationString( artifact, 'subjectStateUrl' ),
		keyId: getAttestationString( artifact, 'keyId' ),
		governanceClaim: getAttestationString( artifact, 'governanceClaim' ),
		governanceLane: getAttestationString( artifact, 'governanceLane' ),
		subjectName: getAttestationString( artifact, 'subjectName' ),
		subjectScope: getAttestationString( artifact, 'subjectScope' ),
		createdAt: getAttestationString( artifact, 'createdAt' ),
		revertedByAttestationId: getAttestationString(
			artifact,
			'revertedByAttestationId'
		),
		supersededByAttestationId: getAttestationString(
			artifact,
			'supersededByAttestationId'
		),
		supersededByVerifyUrl: getAttestationString(
			artifact,
			'supersededByVerifyUrl'
		),
	};
}

function getAttestationClaimLabel( claim ) {
	if ( claim === 'governed-change' ) {
		return __( 'Governed change', 'flavor-agent' );
	}

	return claim;
}

function getAttestationLaneLabel( lane ) {
	if ( lane === 'external-style-apply-v1' ) {
		return sprintf(
			/* translators: %s: attestation lane identifier. */
			__( 'External style apply (%s)', 'flavor-agent' ),
			lane
		);
	}

	return lane;
}

function getAttestationResultString( payload, key ) {
	const value = isPlainRecord( payload ) ? payload[ key ] : '';

	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

const ATTESTATION_OUTCOME_LABELS = {
	signature_valid: __( 'Signature valid', 'flavor-agent' ),
	record_tampered: __( 'Record tampered', 'flavor-agent' ),
	live_matches_subject: __( 'Live subject matches', 'flavor-agent' ),
	reverted_by_attestation: __( 'Reverted by attestation', 'flavor-agent' ),
	superseded_by_attestation: __(
		'Superseded by attestation',
		'flavor-agent'
	),
	live_changed_since_attestation: __(
		'Live subject changed since attestation',
		'flavor-agent'
	),
	live_subject_unavailable: __( 'Live subject unavailable', 'flavor-agent' ),
};

function getVerificationCheckDetails( payload ) {
	const outcomes = Array.isArray( payload?.outcomes ) ? payload.outcomes : [];
	const details = outcomes.map(
		( outcome ) => ATTESTATION_OUTCOME_LABELS[ outcome ] || outcome
	);
	const subjectError = getAttestationResultString( payload, 'subjectError' );

	if ( subjectError ) {
		details.push(
			sprintf(
				/* translators: %s: attestation subject-state error code. */
				__( 'Subject error: %s', 'flavor-agent' ),
				subjectError
			)
		);
	}

	return {
		message: __(
			'Verification completed using the public attestation endpoints.',
			'flavor-agent'
		),
		status: outcomes.includes( 'record_tampered' ) ? 'error' : 'success',
		details,
	};
}

function getAttestationCheckDetails( type, payload ) {
	if ( 'envelope' === type ) {
		const keyId = getAttestationResultString( payload, 'key_id' );
		const hasSignature = !! getAttestationResultString(
			payload,
			'signature_b64'
		);
		const hasStatement = !! getAttestationResultString(
			payload,
			'statement_b64'
		);
		const statement = isPlainRecord( payload?.statement_json )
			? payload.statement_json
			: null;
		const predicate = isPlainRecord( statement?.predicate )
			? statement.predicate
			: null;
		const governance = isPlainRecord( predicate?.governance )
			? predicate.governance
			: null;
		const governanceClaim = getAttestationResultString(
			governance,
			'claim'
		);
		const governanceLane = getAttestationResultString( governance, 'lane' );
		const details = [];

		if ( keyId ) {
			details.push(
				sprintf(
					/* translators: %s: attestation signing key id. */
					__( 'Key: %s', 'flavor-agent' ),
					keyId
				)
			);
		}

		details.push(
			hasSignature
				? __( 'Signature: present', 'flavor-agent' )
				: __( 'Signature: not present', 'flavor-agent' )
		);
		details.push(
			hasStatement
				? __( 'Statement: present', 'flavor-agent' )
				: __( 'Statement: not present', 'flavor-agent' )
		);

		if ( governanceClaim ) {
			details.push(
				sprintf(
					/* translators: %s: attestation governance claim. */
					__( 'Claim: %s', 'flavor-agent' ),
					getAttestationClaimLabel( governanceClaim )
				)
			);
		}

		if ( governanceLane ) {
			details.push(
				sprintf(
					/* translators: %s: attestation governance lane. */
					__( 'Owned lane: %s', 'flavor-agent' ),
					getAttestationLaneLabel( governanceLane )
				)
			);
		}

		return {
			message: __(
				'Envelope loaded from the public endpoint.',
				'flavor-agent'
			),
			status: 'success',
			details,
		};
	}

	const digest = getAttestationResultString( payload, 'subject_digest' );
	const scope = getAttestationResultString( payload, 'scope' );
	const details = [];

	if ( digest ) {
		details.push(
			sprintf(
				/* translators: %s: canonical subject digest. */
				__( 'Digest: %s', 'flavor-agent' ),
				digest
			)
		);
	}

	if ( scope ) {
		details.push(
			sprintf(
				/* translators: %s: attestation subject scope. */
				__( 'Scope: %s', 'flavor-agent' ),
				scope
			)
		);
	}

	return {
		message: __(
			'Live subject state loaded from the public endpoint.',
			'flavor-agent'
		),
		status: 'success',
		details,
	};
}

function GovernancePlainSummary( { rows = [] } ) {
	if ( ! rows.length ) {
		return null;
	}

	return (
		<section className="flavor-agent-activity-log__governance-summary">
			<h4 className="flavor-agent-activity-log__governance-subtitle">
				{ __( 'What happened', 'flavor-agent' ) }
			</h4>
			<dl className="flavor-agent-activity-log__detail-grid">
				{ rows.map( ( { label, value } ) => (
					<ActivityDetailRow
						key={ label }
						label={ label }
						value={ value }
					/>
				) ) }
			</dl>
		</section>
	);
}

function AttestationActions( { artifact } ) {
	const verificationUrl = artifact?.verificationUrl || '';
	const verifyUrl = artifact?.verifyUrl || '';
	const subjectStateUrl = artifact?.subjectStateUrl || '';
	const publicCheckTokenRef = useRef( 0 );
	const [ activeCheck, setActiveCheck ] = useState( '' );
	const [ checkResult, setCheckResult ] = useState( null );
	const [ checkError, setCheckError ] = useState( '' );

	useEffect( () => {
		publicCheckTokenRef.current += 1;
		setActiveCheck( '' );
		setCheckResult( null );
		setCheckError( '' );
	}, [ artifact?.id, verificationUrl, verifyUrl, subjectStateUrl ] );

	useEffect(
		() => () => {
			publicCheckTokenRef.current += 1;
		},
		[]
	);

	const runPublicCheck = useCallback( async ( type, url ) => {
		if ( ! url ) {
			return;
		}

		const checkToken = ++publicCheckTokenRef.current;

		setActiveCheck( type );
		setCheckResult( null );
		setCheckError( '' );

		try {
			const payload = await apiFetch( { url } );

			if ( publicCheckTokenRef.current !== checkToken ) {
				return;
			}

			setCheckResult(
				'verification' === type
					? getVerificationCheckDetails( payload )
					: getAttestationCheckDetails( type, payload )
			);
		} catch ( error ) {
			if ( publicCheckTokenRef.current !== checkToken ) {
				return;
			}

			setCheckError(
				error?.message ||
					__(
						'The attestation verification data could not be loaded.',
						'flavor-agent'
					)
			);
		} finally {
			if ( publicCheckTokenRef.current === checkToken ) {
				setActiveCheck( '' );
			}
		}
	}, [] );

	if ( ! verificationUrl && ! verifyUrl && ! subjectStateUrl ) {
		return null;
	}

	return (
		<section className="flavor-agent-activity-log__governance-subsection">
			<h4 className="flavor-agent-activity-log__governance-subtitle">
				{ __( 'External verification', 'flavor-agent' ) }
			</h4>
			<p className="flavor-agent-activity-log__copy">
				{ __(
					'Run the site-served verification summary here, or open the public envelope and live subject endpoints for independent verification.',
					'flavor-agent'
				) }
			</p>
			<div className="flavor-agent-activity-log__attestation-actions">
				{ verificationUrl && (
					<Button
						disabled={ !! activeCheck }
						isBusy={ 'verification' === activeCheck }
						onClick={ () =>
							runPublicCheck( 'verification', verificationUrl )
						}
						variant="secondary"
					>
						{ __( 'Run verification', 'flavor-agent' ) }
					</Button>
				) }
				{ verifyUrl && (
					<Button
						disabled={ !! activeCheck }
						isBusy={ 'envelope' === activeCheck }
						onClick={ () =>
							runPublicCheck( 'envelope', verifyUrl )
						}
						variant="secondary"
					>
						{ __( 'Load envelope', 'flavor-agent' ) }
					</Button>
				) }
				{ subjectStateUrl && (
					<Button
						disabled={ !! activeCheck }
						isBusy={ 'subject' === activeCheck }
						onClick={ () =>
							runPublicCheck( 'subject', subjectStateUrl )
						}
						variant="secondary"
					>
						{ __( 'Load live subject', 'flavor-agent' ) }
					</Button>
				) }
			</div>
			<div className="flavor-agent-activity-log__attestation-raw-links">
				{ verifyUrl && (
					<a href={ verifyUrl } target="_blank" rel="noreferrer">
						{ __( 'Open envelope JSON', 'flavor-agent' ) }
					</a>
				) }
				{ subjectStateUrl && (
					<a
						href={ subjectStateUrl }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Open live subject JSON', 'flavor-agent' ) }
					</a>
				) }
			</div>
			{ checkResult && (
				<Notice
					className="flavor-agent-activity-log__attestation-result"
					status={ checkResult.status }
					isDismissible={ false }
				>
					<p>{ checkResult.message }</p>
					{ checkResult.details.length > 0 && (
						<ul>
							{ checkResult.details.map( ( detail ) => (
								<li key={ detail }>{ detail }</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }
			{ checkError && (
				<Notice
					className="flavor-agent-activity-log__attestation-result"
					status="error"
					isDismissible={ false }
				>
					{ checkError }
				</Notice>
			) }
		</section>
	);
}

function CryptographicRecord( { details, artifact } ) {
	const hasSignatureEvidence =
		details.hasResolvedSignature ||
		details.hasReviewSignature ||
		details.hasBaselineHash;

	if ( ! hasSignatureEvidence && ! artifact && ! details.diagnosticText ) {
		return null;
	}

	const signatureRows = [
		[
			__( 'Resolved signature', 'flavor-agent' ),
			details.hasResolvedSignature
				? __( 'Recorded', 'flavor-agent' )
				: __( 'Not recorded', 'flavor-agent' ),
		],
		[
			__( 'Review signature', 'flavor-agent' ),
			details.hasReviewSignature
				? __( 'Recorded', 'flavor-agent' )
				: __( 'Not recorded', 'flavor-agent' ),
		],
		[
			__( 'Baseline hash', 'flavor-agent' ),
			details.hasBaselineHash
				? __( 'Recorded', 'flavor-agent' )
				: __( 'Not recorded', 'flavor-agent' ),
		],
	];
	const attestationRows = artifact
		? [
				[ __( 'Attestation ID', 'flavor-agent' ), artifact.id ],
				[
					__( 'Claim', 'flavor-agent' ),
					getAttestationClaimLabel( artifact.governanceClaim ),
				],
				[
					__( 'Owned lane', 'flavor-agent' ),
					getAttestationLaneLabel( artifact.governanceLane ),
				],
				[ __( 'Key', 'flavor-agent' ), artifact.keyId ],
				[ __( 'Subject', 'flavor-agent' ), artifact.subjectName ],
				[ __( 'Scope', 'flavor-agent' ), artifact.subjectScope ],
				[ __( 'Recorded', 'flavor-agent' ), artifact.createdAt ],
				[
					__( 'Reverted by', 'flavor-agent' ),
					artifact.revertedByAttestationId,
				],
				[
					__( 'Superseded by', 'flavor-agent' ),
					artifact.supersededByAttestationId,
				],
		  ]
		: [];

	return (
		<details
			className="flavor-agent-activity-log__record"
			aria-label={ __(
				'Cryptographic record — freshness signatures, attestation, and raw diagnostics',
				'flavor-agent'
			) }
		>
			<summary className="flavor-agent-activity-log__record-summary">
				<span className="flavor-agent-activity-log__record-summary-label">
					{ __( 'Cryptographic record', 'flavor-agent' ) }
				</span>
				<span className="flavor-agent-activity-log__record-summary-text">
					{ __(
						'Freshness signatures, attestation, raw diagnostics',
						'flavor-agent'
					) }
				</span>
			</summary>
			<div className="flavor-agent-activity-log__record-body">
				{ hasSignatureEvidence && (
					<div className="flavor-agent-activity-log__governance-subsection">
						<h4 className="flavor-agent-activity-log__governance-subtitle">
							{ __( 'Freshness signatures', 'flavor-agent' ) }
						</h4>
						<GovernanceDetailRows rows={ signatureRows } />
					</div>
				) }
				{ artifact && (
					<div className="flavor-agent-activity-log__governance-subsection">
						<h4 className="flavor-agent-activity-log__governance-subtitle">
							{ __( 'Attestation', 'flavor-agent' ) }
						</h4>
						<GovernanceDetailRows rows={ attestationRows } />
					</div>
				) }
				{ details.diagnosticText && (
					<div className="flavor-agent-activity-log__governance-subsection">
						<h4 className="flavor-agent-activity-log__governance-subtitle">
							{ __(
								'Raw governance diagnostics',
								'flavor-agent'
							) }
						</h4>
						<pre className="flavor-agent-activity-log__code">
							{ details.diagnosticText }
						</pre>
					</div>
				) }
			</div>
		</details>
	);
}

function GovernanceEvidenceSection( {
	entry,
	bootData,
	onDecided,
	onClaimResolved,
	onClaimReleased,
	currentUserId,
	isLocallyDecided = false,
} ) {
	const details = getGovernanceDetailsForEntry( entry );
	const [ note, setNote ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ decisionError, setDecisionError ] = useState( '' );
	const decisionRequestTokenRef = useRef( 0 );
	const isSubmittingRef = useRef( false );
	const decisionSubmittedRef = useRef( false );

	useEffect( () => {
		decisionRequestTokenRef.current += 1;
		setNote( '' );
		setDecisionError( '' );
		isSubmittingRef.current = false;
		setIsSubmitting( false );
	}, [ entry?.id ] );

	useEffect(
		() => () => {
			decisionRequestTokenRef.current += 1;
		},
		[]
	);

	const canDecide =
		! isLocallyDecided &&
		isPendingExternalApply( entry ) &&
		bootData?.canApproveStyleApplies;

	useEffect( () => {
		if ( ! canDecide || ! entry?.id ) {
			return undefined;
		}

		decisionSubmittedRef.current = false;
		const activityId = entry.id;
		let active = true;

		apiFetch( buildClaimRequest( bootData, activityId ) )
			.then( ( response ) => {
				if ( active ) {
					onClaimResolved?.( activityId, response );
				}
			} )
			.catch( () => {} );

		return () => {
			active = false;

			// Release on abandon/close only. A submitted decision clears the claim
			// server-side on success; a retryable failure must keep it. The 5-minute
			// TTL covers any missed release.
			if ( decisionSubmittedRef.current ) {
				return;
			}

			apiFetch( buildClaimReleaseRequest( bootData, activityId ) ).catch(
				() => {}
			);
		};
	}, [ canDecide, entry?.id, bootData, onClaimResolved ] );

	if ( ! details ) {
		return null;
	}

	const claimNotice = formatApplyClaimNotice(
		entry?.apply?.claim,
		currentUserId
	);

	// Explicit Release control (spec :90): renders only when the viewer holds the
	// claim. There is nothing for a non-holder to release, and we never steal.
	const viewerId = Number( currentUserId );
	const claimUserId = Number( entry?.apply?.claim?.userId );
	const viewerHoldsClaim =
		Number.isFinite( viewerId ) && viewerId > 0 && viewerId === claimUserId;

	const releaseClaim = async () => {
		try {
			const response = await apiFetch(
				buildClaimReleaseRequest( bootData, entry.id )
			);
			onClaimResolved?.( entry.id, response );
			onClaimReleased?.( entry.id );
		} catch {
			// Best-effort; the 5-minute TTL covers a missed release.
		}
	};

	const artifact = getAttestationArtifact( entry );
	const summaryRows = getGovernancePlainSummary(
		details,
		( value ) =>
			formatActivityTimestamp( value, {
				locale: bootData?.locale,
				timeZone: bootData?.timeZone,
			} ).timestampDisplay
	);
	const provenanceRows = [
		[ __( 'Target', 'flavor-agent' ), details.targetLabel ],
		[ __( 'Requested by', 'flavor-agent' ), details.requestedByLabel ],
		[ __( 'Requested', 'flavor-agent' ), details.requestedAt ],
		[ __( 'Expires', 'flavor-agent' ), details.expiresAt ],
		[ __( 'Request reference', 'flavor-agent' ), details.requestReference ],
		[ __( 'Decided by', 'flavor-agent' ), details.decidedByLabel ],
		[ __( 'Decided', 'flavor-agent' ), details.decidedAt ],
		[ __( 'Executed', 'flavor-agent' ), details.executedAt ],
		[ __( 'Decision note', 'flavor-agent' ), details.decisionNote ],
	];
	const outcomeRows = [
		[ __( 'Failure code', 'flavor-agent' ), details.failureCode ],
		[ __( 'Failure reason', 'flavor-agent' ), details.failureMessage ],
		[ __( 'Undo state', 'flavor-agent' ), details.undoStatus ],
		[ __( 'Undo reason', 'flavor-agent' ), details.undoReason ],
	];

	const submitDecision = async ( decision ) => {
		if ( isSubmittingRef.current ) {
			return;
		}

		const requestToken = ++decisionRequestTokenRef.current;

		isSubmittingRef.current = true;
		setIsSubmitting( true );
		setDecisionError( '' );

		try {
			const response = await apiFetch(
				buildDecisionRequest( bootData, entry.id, decision, note )
			);

			if ( decisionRequestTokenRef.current !== requestToken ) {
				return;
			}

			onDecided?.( entry.id, response?.entry );
			decisionSubmittedRef.current = true;
		} catch ( error ) {
			if ( decisionRequestTokenRef.current !== requestToken ) {
				return;
			}

			const code = typeof error?.code === 'string' ? error.code : '';

			// Terminal race-loss: the row was decided by another admin. Fetch the
			// terminal row via one claim call and pin it (legible conflict) instead
			// of showing a generic error. invalid_transition is the genuine
			// simultaneous-loss code; not_pending/expired are the re-read cases.
			if ( TERMINAL_DECISION_ERROR_CODES.includes( code ) ) {
				try {
					const claimResponse = await apiFetch(
						buildClaimRequest( bootData, entry.id )
					);

					if ( decisionRequestTokenRef.current !== requestToken ) {
						return;
					}

					onDecided?.( entry.id, claimResponse?.entry );
					decisionSubmittedRef.current = true;
					return;
				} catch {
					// Fall through to the inline error if the claim fetch also fails.
				}
			}

			// Retryable failures (500) and any unresolved terminal fetch: leave the
			// row pending, keep the claim, and show the inline retry error.
			setDecisionError(
				error?.message ||
					__( 'The decision could not be recorded.', 'flavor-agent' )
			);
		} finally {
			if ( decisionRequestTokenRef.current === requestToken ) {
				isSubmittingRef.current = false;
				setIsSubmitting( false );
			}
		}
	};

	return (
		<section className="flavor-agent-activity-log__governance">
			<div
				className={ `flavor-agent-activity-log__governance-banner is-${ details.status }` }
			>
				<span className="flavor-agent-activity-log__governance-kicker">
					{ __( 'Governance evidence', 'flavor-agent' ) }
				</span>
				<h3>{ details.lifecycleLabel }</h3>
				<p>
					{ details.status === 'pending'
						? __(
								'An external agent requested this bounded style apply. Review the operation, provenance, and freshness evidence before deciding.',
								'flavor-agent'
						  )
						: __(
								'This external style apply row is retained for approval, provenance, freshness, and undo review.',
								'flavor-agent'
						  ) }
				</p>
			</div>
			<GovernancePlainSummary rows={ summaryRows } />
			<GovernanceVisualDiffViewer rows={ details.visualDiffRows || [] } />
			<GovernanceOperationList
				title={ __( 'Requested operations', 'flavor-agent' ) }
				operations={ details.proposedOperations }
			/>
			<GovernanceOperationList
				title={ __( 'Executed operations', 'flavor-agent' ) }
				operations={ details.executedOperations }
			/>
			<div className="flavor-agent-activity-log__governance-subsection">
				<h4 className="flavor-agent-activity-log__governance-subtitle">
					{ __( 'Full provenance', 'flavor-agent' ) }
				</h4>
				<GovernanceDetailRows rows={ provenanceRows } />
			</div>
			<div className="flavor-agent-activity-log__governance-subsection">
				<h4 className="flavor-agent-activity-log__governance-subtitle">
					{ __( 'Outcome and undo', 'flavor-agent' ) }
				</h4>
				<GovernanceDetailRows rows={ outcomeRows } />
			</div>
			{ canDecide && (
				<div className="flavor-agent-activity-log__decision">
					<div>
						<h4 className="flavor-agent-activity-log__governance-subtitle">
							{ __( 'Decision', 'flavor-agent' ) }
						</h4>
						<p className="flavor-agent-activity-log__copy">
							{ __(
								'AI proposes; WordPress approves. Approving applies this bounded style change from WordPress; rejecting keeps the site unchanged.',
								'flavor-agent'
							) }
						</p>
						{ claimNotice && (
							<p className="flavor-agent-activity-log__claim-note">
								{ claimNotice.text }
							</p>
						) }
						{ viewerHoldsClaim && (
							<Button variant="tertiary" onClick={ releaseClaim }>
								{ __( 'Release review claim', 'flavor-agent' ) }
							</Button>
						) }
					</div>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Decision note (optional)',
							'flavor-agent'
						) }
						value={ note }
						onChange={ setNote }
						disabled={ isSubmitting }
					/>
					{ decisionError && (
						<Notice status="error" isDismissible={ false }>
							{ decisionError }
						</Notice>
					) }
					<Flex justify="flex-start" gap={ 2 }>
						<Button
							variant="primary"
							isBusy={ isSubmitting }
							disabled={ isSubmitting }
							onClick={ () => submitDecision( 'approve' ) }
						>
							{ __( 'Approve and apply', 'flavor-agent' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							disabled={ isSubmitting }
							onClick={ () => submitDecision( 'reject' ) }
						>
							{ __( 'Reject', 'flavor-agent' ) }
						</Button>
					</Flex>
				</div>
			) }
			<AttestationActions artifact={ artifact } />
			<CryptographicRecord details={ details } artifact={ artifact } />
		</section>
	);
}

function ActivityEntryDetails( {
	entry,
	bootData,
	onDecided,
	onFilterAction,
	onClaimResolved,
	onClaimReleased,
	currentUserId,
	isLocallyDecided = false,
} ) {
	if ( ! entry ) {
		return (
			<Card className="flavor-agent-activity-log__sidebar-card">
				<CardBody>
					<h3 className="flavor-agent-activity-log__section-title">
						{ __( 'Entry details', 'flavor-agent' ) }
					</h3>
					<p className="flavor-agent-activity-log__copy">
						{ __(
							'Select an activity item to inspect request metadata, provider ownership, undo state, and navigation links.',
							'flavor-agent'
						) }
					</p>
				</CardBody>
			</Card>
		);
	}

	const selectedActions = normalizeSelectedActivityActions( entry, {
		adminUrl: bootData?.adminUrl,
	} );

	return (
		<Card className="flavor-agent-activity-log__sidebar-card">
			<div
				id="flavor-agent-activity-log-details"
				className="flavor-agent-activity-log__details-region"
				role="region"
				aria-labelledby="flavor-agent-activity-log-details-title"
			>
				<CardHeader>
					<div className="flavor-agent-activity-log__sidebar-heading">
						<div>
							<h3
								id="flavor-agent-activity-log-details-title"
								className="flavor-agent-activity-log__section-title"
							>
								{ entry.title }
							</h3>
							<p className="flavor-agent-activity-log__copy">
								{ entry.description }
							</p>
						</div>
					</div>
				</CardHeader>
				<CardBody>
					<SelectedActivityActions
						actions={ selectedActions }
						onFilterAction={ onFilterAction }
					/>
					<ActivityEntryStory entry={ entry } />
					<AiRequestLogPanel entry={ entry } bootData={ bootData } />
					<GovernanceEvidenceSection
						entry={ entry }
						bootData={ bootData }
						onDecided={ onDecided }
						onClaimResolved={ onClaimResolved }
						onClaimReleased={ onClaimReleased }
						currentUserId={ currentUserId }
						isLocallyDecided={ isLocallyDecided }
					/>
					<div className="flavor-agent-activity-log__detail-sections">
						{ DETAIL_SECTIONS.map( ( section ) => (
							<ActivityDetailSection
								key={ section.id }
								section={ section }
								entry={ entry }
							/>
						) ) }
					</div>
				</CardBody>
			</div>
		</Card>
	);
}

export function ActivityLogApp( { bootData } ) {
	const viewOptions = useMemo(
		() => ( {
			defaultPerPage: bootData.defaultPerPage,
			maxPerPage: bootData.maxPerPage,
		} ),
		[ bootData.defaultPerPage, bootData.maxPerPage ]
	);
	const linkedActivityEntryId = useMemo( getLinkedActivityEntryId, [] );
	const defaultView = useMemo(
		() => normalizeStoredActivityView( undefined, viewOptions ),
		[ viewOptions ]
	);
	const persistedView = useMemo(
		() => readPersistedActivityView( undefined, viewOptions ),
		[ viewOptions ]
	);
	const persistActivityViewRef = useRef( ! linkedActivityEntryId );
	const [ view, setView ] = useState( () =>
		linkedActivityEntryId ? defaultView : persistedView
	);
	const [ responseData, setResponseData ] = useState( () => ( {
		entries: [],
		filterOptions: null,
		paginationInfo: {
			page: 1,
			perPage: defaultView.perPage,
			totalItems: 0,
			totalPages: 0,
		},
		summary: {
			total: 0,
			applied: 0,
			undone: 0,
			review: 0,
			pending: 0,
			rejected: 0,
			expired: 0,
			blocked: 0,
			failed: 0,
		},
		learningReport: null,
	} ) );
	const [ error, setError ] = useState( '' );
	const [ errorKind, setErrorKind ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ reloadToken, setReloadToken ] = useState( 0 );
	const [ locallyDecidedEntryIds, setLocallyDecidedEntryIds ] = useState(
		() => new Set()
	);
	const [ pinnedTerminalEntry, setPinnedTerminalEntry ] = useState( null );
	const [ requestActivityId, setRequestActivityId ] = useState(
		linkedActivityEntryId
	);
	const [ selectedEntryId, setSelectedEntryId ] = useState(
		linkedActivityEntryId
	);
	const exitLinkedActivityMode = useCallback( () => {
		if ( requestActivityId ) {
			persistActivityViewRef.current = true;
			setLinkedActivityEntryIdInUrl();
			setRequestActivityId( '' );
		}
	}, [ requestActivityId ] );

	const requestedView = useMemo(
		() => normalizeStoredActivityView( view, viewOptions ),
		[ view, viewOptions ]
	);
	const effectiveView = useMemo(
		() =>
			clampActivityViewPage(
				requestedView,
				responseData.paginationInfo,
				viewOptions
			),
		[ requestedView, responseData.paginationInfo, viewOptions ]
	);
	const requestUrl = useMemo(
		() =>
			getActivityRequestUrl( bootData, requestedView, requestActivityId ),
		[ bootData, requestedView, requestActivityId ]
	);
	const invalidDayFilter = hasInvalidDayFilter( requestedView );
	const perPageSizes = useMemo(
		() => getPerPageSizes( defaultView.perPage, bootData.maxPerPage ),
		[ bootData.maxPerPage, defaultView.perPage ]
	);
	const clearDayFilter = useCallback( () => {
		setView( ( currentView ) => {
			const normalizedView = normalizeStoredActivityView(
				currentView,
				viewOptions
			);

			return {
				...normalizedView,
				page: 1,
				filters: normalizedView.filters.filter(
					( filter ) => filter?.field !== 'day'
				),
			};
		} );
	}, [ viewOptions ] );
	const normalizeAdminEntries = useCallback(
		( entries ) =>
			normalizeActivityEntries( entries, {
				adminBaseUrl: bootData.adminUrl,
				settingsUrl: bootData.settingsUrl,
				connectorsUrl: bootData.connectorsUrl,
				locale: bootData.locale,
				themeColorPresets: bootData.themeColorPresets,
				timeZone: bootData.timeZone,
			} ),
		[
			bootData.adminUrl,
			bootData.connectorsUrl,
			bootData.locale,
			bootData.settingsUrl,
			bootData.themeColorPresets,
			bootData.timeZone,
		]
	);
	const handleEntryDecided = useCallback(
		( activityId, decidedEntry ) => {
			const decidedEntryId =
				typeof activityId === 'string' ? activityId.trim() : '';

			if ( decidedEntryId ) {
				setLocallyDecidedEntryIds( ( current ) => {
					if ( current.has( decidedEntryId ) ) {
						return current;
					}

					const next = new Set( current );
					next.add( decidedEntryId );

					return next;
				} );
			}

			if (
				isPlainRecord( decidedEntry ) &&
				( typeof decidedEntry.status === 'string' ||
					isPlainRecord( decidedEntry.apply ) )
			) {
				const normalizedEntry = normalizeAdminEntries( [
					decidedEntry,
				] )[ 0 ];

				if ( normalizedEntry?.id ) {
					setResponseData( ( current ) => ( {
						...current,
						entries: current.entries.map( ( existingEntry ) =>
							existingEntry.id === normalizedEntry.id
								? normalizedEntry
								: existingEntry
						),
					} ) );

					// A terminal (decided) entry is pinned so it stays legible after
					// it drops out of the pending-only Approvals filter on refetch.
					if ( normalizedEntry.status !== 'pending' ) {
						setPinnedTerminalEntry( normalizedEntry );
					}
				}
			}

			setReloadToken( ( value ) => value + 1 );
		},
		[ normalizeAdminEntries ]
	);

	// Forward-declared this task as a Task 7 deliverable; the decision panel
	// (onClaimResolved) and the focus/visibility refresh wire it in Tasks 9–10.
	const applyClaimResponse = useCallback(
		( activityId, response ) => {
			if ( ! isPlainRecord( response ) ) {
				return;
			}

			const responseEntry = response.entry;

			// /claim returns ActivityRepository::find()-shaped rows, whose lifecycle
			// status lives at entry.apply.status — there is NO top-level entry.status
			// (Serializer::hydrate_row synthesizes none; only the client-side
			// normalizeActivityEntry does, and this response is NOT normalized).
			// Reading entry.status here would be undefined for every successful
			// pending claim and wrongly route it through handleEntryDecided, hiding
			// the decision controls on a still-pending row.
			const responseApply = isPlainRecord( responseEntry )
				? responseEntry.apply
				: null;
			const responseApplyStatus =
				isPlainRecord( responseApply ) &&
				typeof responseApply.status === 'string'
					? responseApply.status
					: '';

			// Decided/expired elsewhere (or by us): the row came back terminal — pin it.
			if ( responseApplyStatus && responseApplyStatus !== 'pending' ) {
				handleEntryDecided( activityId, responseEntry );
				return;
			}

			// Still pending: merge the live claim onto the selected row so the
			// badge reflects the current reviewer immediately.
			const claim = isPlainRecord( response.claim )
				? response.claim
				: null;
			setResponseData( ( current ) => ( {
				...current,
				entries: current.entries.map( ( existingEntry ) =>
					existingEntry.id === activityId &&
					isPlainRecord( existingEntry.apply )
						? {
								...existingEntry,
								apply: { ...existingEntry.apply, claim },
						  }
						: existingEntry
				),
			} ) );
		},
		[ handleEntryDecided ]
	);

	useEffect( () => {
		let isCurrent = true;

		if ( invalidDayFilter ) {
			setResponseData( {
				entries: [],
				filterOptions: null,
				paginationInfo: {
					page: 1,
					perPage: requestedView.perPage,
					totalItems: 0,
					totalPages: 0,
				},
				summary: {
					total: 0,
					applied: 0,
					undone: 0,
					review: 0,
					pending: 0,
					rejected: 0,
					expired: 0,
					blocked: 0,
					failed: 0,
				},
				learningReport: null,
			} );
			setError( getInvalidDayFilterError() );
			setErrorKind( ERROR_KIND_INVALID_DAY_FILTER );
			setIsLoading( false );

			return () => {
				isCurrent = false;
			};
		}

		async function loadEntries() {
			setIsLoading( true );
			setError( '' );
			setErrorKind( '' );

			try {
				const response = await apiFetch( {
					url: requestUrl,
					headers: {
						'X-WP-Nonce': bootData.nonce,
					},
				} );
				const normalizedEntries = normalizeAdminEntries(
					response?.entries || []
				);

				if ( ! isCurrent ) {
					return;
				}

				setResponseData( {
					entries: normalizedEntries,
					filterOptions: response?.filterOptions || null,
					paginationInfo: {
						page:
							response?.paginationInfo?.page ||
							requestedView.page,
						perPage:
							response?.paginationInfo?.perPage ||
							requestedView.perPage,
						totalItems: response?.paginationInfo?.totalItems || 0,
						totalPages: response?.paginationInfo?.totalPages || 0,
					},
					summary: {
						total: response?.summary?.total || 0,
						applied: response?.summary?.applied || 0,
						undone: response?.summary?.undone || 0,
						review: response?.summary?.review || 0,
						pending: response?.summary?.pending || 0,
						rejected: response?.summary?.rejected || 0,
						expired: response?.summary?.expired || 0,
						blocked: response?.summary?.blocked || 0,
						failed: response?.summary?.failed || 0,
					},
					learningReport: normalizeGovernanceLearningReport(
						response?.learningReport
					),
				} );
			} catch ( fetchError ) {
				if ( ! isCurrent ) {
					return;
				}

				setResponseData( {
					entries: [],
					filterOptions: null,
					paginationInfo: {
						page: 1,
						perPage: requestedView.perPage,
						totalItems: 0,
						totalPages: 0,
					},
					summary: {
						total: 0,
						applied: 0,
						undone: 0,
						review: 0,
						pending: 0,
						rejected: 0,
						expired: 0,
						blocked: 0,
						failed: 0,
					},
					learningReport: null,
				} );
				setError(
					fetchError?.message ||
						__(
							'Flavor Agent could not load the recent AI activity log.',
							'flavor-agent'
						)
				);
				setErrorKind( ERROR_KIND_FETCH );
			} finally {
				if ( isCurrent ) {
					setIsLoading( false );
				}
			}
		}

		loadEntries();

		return () => {
			isCurrent = false;
		};
		// requestUrl already encodes requestedView (page, perPage, filters), so it changes when those do.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bootData, invalidDayFilter, requestUrl, reloadToken ] );

	const fields = useMemo( () => {
		const surfaceElements =
			getServerFilterOptions( responseData, 'surface' ) ||
			buildSelectElements( responseData.entries, 'surface', {
				labelKey: 'surfaceLabel',
			} );
		const operationTypeElements =
			getServerFilterOptions( responseData, 'operationType' ) ||
			buildSelectElements( responseData.entries, 'operationType', {
				labelKey: 'operationTypeLabel',
			} );
		const postTypeElements =
			getServerFilterOptions( responseData, 'postType' ) ||
			buildSelectElements( responseData.entries, 'postType' );
		const providerElements =
			getServerFilterOptions( responseData, 'provider' ) ||
			buildSelectElements( responseData.entries, 'provider' );
		const providerPathElements =
			getServerFilterOptions( responseData, 'providerPath' ) ||
			buildSelectElements( responseData.entries, 'providerPath' );
		const configurationOwnerElements =
			getServerFilterOptions( responseData, 'configurationOwner' ) ||
			buildSelectElements( responseData.entries, 'configurationOwner' );
		const credentialSourceElements =
			getServerFilterOptions( responseData, 'credentialSource' ) ||
			buildSelectElements( responseData.entries, 'credentialSource' );
		const selectedProviderElements =
			getServerFilterOptions( responseData, 'selectedProvider' ) ||
			buildSelectElements( responseData.entries, 'selectedProvider' );
		const userElements =
			getServerFilterOptions( responseData, 'userId' ) ||
			buildSelectElements( responseData.entries, 'userId', {
				labelKey: 'user',
			} );

		return [
			{
				id: 'icon',
				label: __( 'Icon', 'flavor-agent' ),
				type: 'media',
				enableSorting: false,
				render: ( { item } ) => (
					<span
						className={ `flavor-agent-activity-log__icon is-${ item.status }` }
					>
						<Icon icon={ getIconForEntry( item ) } />
					</span>
				),
			},
			{
				id: 'title',
				label: __( 'Action', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				render: ( { item } ) => {
					const badges = normalizeActivityDiscoveryBadges(
						item,
						bootData?.currentUserId
					);

					return (
						<span
							id={ `flavor-agent-activity-log-entry-title-${ item.id }` }
							className={ `flavor-agent-activity-log__entry-title${
								item.id === selectedEntryId ? ' is-current' : ''
							}${ badges.length > 0 ? ' has-badges' : '' }` }
							aria-current={
								item.id === selectedEntryId ? 'true' : undefined
							}
							aria-controls={
								item.id === selectedEntryId
									? 'flavor-agent-activity-log-details'
									: undefined
							}
						>
							<span className="flavor-agent-activity-log__entry-title-text">
								{ item.title }
							</span>
							{ badges.length > 0 && (
								<span
									className="flavor-agent-activity-log__entry-badges"
									aria-label={ __(
										'Activity evidence',
										'flavor-agent'
									) }
								>
									{ badges.map( ( badge ) => (
										<span
											key={ badge.id }
											className={ `flavor-agent-activity-log__entry-badge is-${ badge.tone }` }
										>
											{ badge.label }
											{ badge.detail
												? ` · ${ badge.detail }`
												: '' }
										</span>
									) ) }
								</span>
							) }
						</span>
					);
				},
			},
			{
				id: 'description',
				label: __( 'Description', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<span className="flavor-agent-activity-log__entry-description">
						{ item.description }
					</span>
				),
			},
			{
				id: 'day',
				label: __( 'Date', 'flavor-agent' ),
				type: 'date',
				enableSorting: false,
				filterBy: {
					operators: [
						'on',
						'before',
						'after',
						'between',
						'inThePast',
						'over',
					],
				},
			},
			{
				id: 'timestamp',
				label: __( 'Timestamp', 'flavor-agent' ),
				type: 'datetime',
				enableSorting: true,
				render: ( { item } ) => <span>{ item.timestampDisplay }</span>,
			},
			{
				id: 'timestampDisplay',
				label: __( 'Recorded', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				render: ( { item } ) => <span>{ item.timestampDisplay }</span>,
			},
			{
				id: 'operationType',
				label: __( 'Action type', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				elements: operationTypeElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
				render: ( { item } ) => (
					<span>{ item.operationTypeLabel }</span>
				),
			},
			{
				id: 'surface',
				label: __( 'Surface', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				elements: surfaceElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
				render: ( { item } ) => <span>{ item.surfaceLabel }</span>,
			},
			{
				id: 'status',
				label: __( 'Status', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				elements: [
					{
						value: 'applied',
						label: __( 'Applied', 'flavor-agent' ),
					},
					{
						value: 'pending',
						label: __( 'Pending approval', 'flavor-agent' ),
					},
					{
						value: 'rejected',
						label: __( 'Rejected', 'flavor-agent' ),
					},
					{
						value: 'expired',
						label: __( 'Expired', 'flavor-agent' ),
					},
					{ value: 'review', label: __( 'Review', 'flavor-agent' ) },
					{ value: 'undone', label: __( 'Undone', 'flavor-agent' ) },
					{
						value: 'blocked',
						label: __( 'Undo blocked', 'flavor-agent' ),
					},
					{
						value: 'failed',
						label: __( 'Failed or unavailable', 'flavor-agent' ),
					},
				],
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
				render: ( { item } ) => (
					<span
						className={ `flavor-agent-activity-log__status is-${ item.status }` }
					>
						{ item.statusLabel }
					</span>
				),
			},
			{
				id: 'userId',
				label: __( 'User', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				elements: userElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
				render: ( { item } ) => <span>{ item.user }</span>,
			},
			{
				id: 'postType',
				label: __( 'Post type', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				elements: postTypeElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
			},
			{
				id: 'entityId',
				label: __( 'Entity ID', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'entity',
				label: __( 'Entity', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'blockPath',
				label: __( 'Block path', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'provider',
				label: __( 'Provider', 'flavor-agent' ),
				type: 'text',
				enableSorting: true,
				enableGlobalSearch: true,
				elements: providerElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
			},
			{
				id: 'model',
				label: __( 'Model', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'providerPath',
				label: __( 'Provider path', 'flavor-agent' ),
				type: 'text',
				enableSorting: true,
				enableGlobalSearch: true,
				elements: providerPathElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
			},
			{
				id: 'configurationOwner',
				label: __( 'Configured in', 'flavor-agent' ),
				type: 'text',
				enableSorting: true,
				enableGlobalSearch: true,
				elements: configurationOwnerElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
			},
			{
				id: 'credentialSource',
				label: __( 'Credential source', 'flavor-agent' ),
				type: 'text',
				enableSorting: true,
				enableGlobalSearch: true,
				elements: credentialSourceElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
			},
			{
				id: 'selectedProvider',
				label: __( 'Selected provider', 'flavor-agent' ),
				type: 'text',
				enableSorting: true,
				enableGlobalSearch: true,
				elements: selectedProviderElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
			},
			{
				id: 'activityTypeLabel',
				label: __( 'Recorded activity type', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'requestPrompt',
				label: __( 'Prompt', 'flavor-agent' ),
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
		];
	}, [ responseData, selectedEntryId, bootData?.currentUserId ] );

	const selectedEntry =
		responseData.entries.find(
			( entry ) => entry.id === selectedEntryId
		) ||
		( pinnedTerminalEntry && pinnedTerminalEntry.id === selectedEntryId
			? pinnedTerminalEntry
			: null );

	// Mirror the selected entry into a ref so the focus/visibility refresh effect
	// (deps: [ bootData, applyClaimResponse ]) reads the live selection without a
	// stale closure and without re-binding listeners on every selection change.
	const selectedEntryRef = useRef( null );
	useEffect( () => {
		selectedEntryRef.current = selectedEntry;
	}, [ selectedEntry ] );

	// Tracks a row whose claim the viewer explicitly released while staying on it.
	// The focus/visibility refresh must NOT silently re-acquire that claim, or the
	// explicit Release would feel like a no-op. Cleared only when the SELECTED id
	// changes — not when the selectedEntry object changes, because applyClaimResponse
	// merges apply.claim and mints a new object on the same row, which would
	// prematurely clear the suppression.
	const releasedClaimIdRef = useRef( null );
	const handleClaimReleased = useCallback( ( activityId ) => {
		releasedClaimIdRef.current = activityId;
	}, [] );
	useEffect( () => {
		releasedClaimIdRef.current = null;
	}, [ selectedEntryId ] );

	useEffect( () => {
		if ( ! persistActivityViewRef.current ) {
			return;
		}

		writePersistedActivityView( effectiveView, undefined, viewOptions );
	}, [ effectiveView, viewOptions ] );

	useEffect( () => {
		if ( ! areActivityViewsEqual( view, effectiveView, viewOptions ) ) {
			setView( effectiveView );
		}
	}, [ effectiveView, view, viewOptions ] );

	useEffect( () => {
		// A pinned terminal row stays selected even after it leaves the filtered feed.
		if (
			pinnedTerminalEntry?.id &&
			pinnedTerminalEntry.id === selectedEntryId
		) {
			return;
		}

		if ( responseData.entries.length === 0 ) {
			if ( requestActivityId && ! isLoading ) {
				exitLinkedActivityMode();
			}

			if ( selectedEntryId && ! isLoading ) {
				setSelectedEntryId( '' );
			}
			return;
		}

		if (
			! responseData.entries.some(
				( entry ) => entry.id === selectedEntryId
			)
		) {
			setSelectedEntryId( responseData.entries[ 0 ].id );
		}

		if (
			requestActivityId &&
			! responseData.entries.some(
				( entry ) => entry.id === requestActivityId
			)
		) {
			exitLinkedActivityMode();
		}
	}, [
		exitLinkedActivityMode,
		isLoading,
		pinnedTerminalEntry,
		requestActivityId,
		responseData.entries,
		selectedEntryId,
	] );

	useEffect( () => {
		if (
			pinnedTerminalEntry &&
			pinnedTerminalEntry.id !== selectedEntryId
		) {
			setPinnedTerminalEntry( null );
		}
	}, [ pinnedTerminalEntry, selectedEntryId ] );

	// Opportunistic refresh: when the reviewer returns to the tab/window, refetch
	// the feed and (if a pending row is selected and they can approve) re-issue the
	// claim so the TTL stays warm — and so a decided-elsewhere row is detected and
	// pinned via applyClaimResponse. Event-driven only; there is no polling.
	useEffect( () => {
		if (
			typeof window === 'undefined' ||
			typeof document === 'undefined'
		) {
			return undefined;
		}

		let debounceTimer = null;

		const handleFocusOrVisible = () => {
			if ( document.visibilityState === 'hidden' ) {
				return;
			}

			if ( debounceTimer ) {
				return;
			}

			debounceTimer = setTimeout( () => {
				debounceTimer = null;
			}, FOCUS_REFRESH_DEBOUNCE_MS );

			setReloadToken( ( value ) => value + 1 );

			const selected = selectedEntryRef.current;

			if (
				selected?.id &&
				selected.status === 'pending' &&
				bootData?.canApproveStyleApplies &&
				selected.id !== releasedClaimIdRef.current
			) {
				apiFetch( buildClaimRequest( bootData, selected.id ) )
					.then( ( response ) =>
						applyClaimResponse( selected.id, response )
					)
					.catch( () => {} );
			}
		};

		window.addEventListener( 'focus', handleFocusOrVisible );
		document.addEventListener( 'visibilitychange', handleFocusOrVisible );

		return () => {
			window.removeEventListener( 'focus', handleFocusOrVisible );
			document.removeEventListener(
				'visibilitychange',
				handleFocusOrVisible
			);

			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}
		};
	}, [ bootData, applyClaimResponse ] );

	const summaryCards = getSummaryCards( responseData.summary );
	const isViewModified = ! areActivityViewsEqual(
		effectiveView,
		defaultView,
		viewOptions
	);
	const approvalsFilterActive = isPendingApprovalView( effectiveView );
	const toggleApprovalsFilter = useCallback( () => {
		exitLinkedActivityMode();

		setView( ( currentView ) =>
			withPendingApprovalFilter( currentView, viewOptions )
		);
	}, [ exitLinkedActivityMode, viewOptions ] );
	const applySelectedRowFilter = useCallback(
		( action ) => {
			if (
				! action ||
				action.type !== 'filter' ||
				! action.field ||
				! action.operator ||
				action.value === undefined ||
				action.value === null ||
				action.value === ''
			) {
				return;
			}

			exitLinkedActivityMode();

			setView( ( currentView ) => {
				const normalizedView = normalizeStoredActivityView(
					currentView,
					viewOptions
				);

				return {
					...normalizedView,
					page: 1,
					filters: [
						...normalizedView.filters.filter(
							( filter ) => filter?.field !== action.field
						),
						{
							field: action.field,
							operator: action.operator,
							value: action.value,
						},
					],
				};
			} );
		},
		[ exitLinkedActivityMode, viewOptions ]
	);
	const emptyState = error ? (
		<ErrorState
			error={ error }
			actionLabel={
				errorKind === ERROR_KIND_INVALID_DAY_FILTER
					? __( 'Reset date filter', 'flavor-agent' )
					: __( 'Retry loading activity', 'flavor-agent' )
			}
			onAction={
				errorKind === ERROR_KIND_INVALID_DAY_FILTER
					? clearDayFilter
					: () => setReloadToken( ( value ) => value + 1 )
			}
		/>
	) : (
		<EmptyState view={ effectiveView } />
	);

	return (
		<div className="flavor-agent-activity-log">
			<div className="flavor-agent-activity-log__masthead">
				<div className="flavor-agent-activity-log__masthead-copy">
					<p className="flavor-agent-activity-log__eyebrow">
						Flavor Agent
					</p>
					<div className="flavor-agent-activity-log__title-row">
						<h1 className="flavor-agent-activity-log__page-title">
							{ __( 'AI Activity Log', 'flavor-agent' ) }
						</h1>
					</div>
					<p className="flavor-agent-activity-log__copy">
						{ __(
							'Decide pending external applies and review recent AI actions across editor, Site Editor, and admin surfaces. AI proposes; WordPress approves.',
							'flavor-agent'
						) }
					</p>
				</div>
				<div className="flavor-agent-activity-log__masthead-actions">
					<Button
						variant="secondary"
						onClick={ () =>
							setReloadToken( ( value ) => value + 1 )
						}
						disabled={ isLoading }
					>
						{ __( 'Refresh', 'flavor-agent' ) }
					</Button>
					<Button href={ bootData.settingsUrl } variant="tertiary">
						{ __( 'Flavor Agent settings', 'flavor-agent' ) }
					</Button>
					<Button href={ bootData.connectorsUrl } variant="tertiary">
						{ __( 'Connectors', 'flavor-agent' ) }
					</Button>
				</div>
			</div>

			<DataViews
				data={ responseData.entries }
				fields={ fields }
				view={ effectiveView }
				onChangeView={ ( nextView ) => {
					exitLinkedActivityMode();

					setView( nextView );
				} }
				getItemId={ ( item ) => item.id }
				paginationInfo={ responseData.paginationInfo }
				defaultLayouts={ {
					activity: {
						layout: {
							density: 'comfortable',
						},
					},
				} }
				isItemClickable={ () => true }
				onClickItem={ ( item ) => {
					exitLinkedActivityMode();

					setSelectedEntryId( item.id );
				} }
				config={ {
					perPageSizes,
				} }
				onReset={
					isViewModified ? () => setView( defaultView ) : false
				}
				isLoading={ isLoading }
				empty={ emptyState }
			>
				<div className="flavor-agent-activity-log__overview">
					<div className="flavor-agent-activity-log__summary">
						{ summaryCards.map( ( card ) => (
							<div
								key={ card.id }
								className="flavor-agent-activity-log__summary-item"
							>
								<div className="flavor-agent-activity-log__summary-label">
									{ card.label }
								</div>
								<div className="flavor-agent-activity-log__summary-value">
									{ card.value }
								</div>
							</div>
						) ) }
					</div>
					<LearningReportSection
						bootData={ bootData }
						report={ responseData.learningReport }
					/>
					<div className="flavor-agent-activity-log__toolbar">
						<div className="flavor-agent-activity-log__controls">
							<div className="flavor-agent-activity-log__controls-main">
								<div className="flavor-agent-activity-log__search">
									<DataViews.Search
										label={ __(
											'Search AI activity',
											'flavor-agent'
										) }
									/>
								</div>
								{ isLoading && <Spinner /> }
								{ isLoading && (
									<span
										className="screen-reader-text"
										role="status"
										aria-live="polite"
									>
										{ __(
											'Loading activity…',
											'flavor-agent'
										) }
									</span>
								) }
							</div>
							<div className="flavor-agent-activity-log__controls-actions">
								<Button
									variant="secondary"
									isPressed={ approvalsFilterActive }
									onClick={ toggleApprovalsFilter }
								>
									{ __( 'Approvals', 'flavor-agent' ) }
								</Button>
								<span className="flavor-agent-activity-log__toolbar-control">
									<DataViews.FiltersToggle />
									<span
										className="flavor-agent-activity-log__toolbar-control-label"
										aria-hidden="true"
									>
										{ __( 'Filter', 'flavor-agent' ) }
									</span>
								</span>
								<span className="flavor-agent-activity-log__toolbar-control">
									<DataViews.ViewConfig />
									<span
										className="flavor-agent-activity-log__toolbar-control-label"
										aria-hidden="true"
									>
										{ __( 'View options', 'flavor-agent' ) }
									</span>
								</span>
								{ isViewModified && (
									<Button
										variant="secondary"
										onClick={ () => setView( defaultView ) }
									>
										{ __( 'Reset view', 'flavor-agent' ) }
									</Button>
								) }
							</div>
						</div>
						<div className="flavor-agent-activity-log__filters">
							<DataViews.FiltersToggled />
						</div>
					</div>
				</div>

				<LinkedActivityBanner
					activityId={ requestActivityId }
					entry={ selectedEntry }
					onClear={ exitLinkedActivityMode }
				/>

				<div className="flavor-agent-activity-log__content">
					<div className="flavor-agent-activity-log__feed">
						<Card className="flavor-agent-activity-log__feed-card">
							<CardBody>
								<DataViews.Layout />
							</CardBody>
						</Card>
						<div className="flavor-agent-activity-log__pagination">
							<DataViews.Pagination />
						</div>
					</div>
					<div className="flavor-agent-activity-log__sidebar">
						<ActivityEntryDetails
							entry={ selectedEntry }
							bootData={ bootData }
							onDecided={ handleEntryDecided }
							onFilterAction={ applySelectedRowFilter }
							onClaimResolved={ applyClaimResponse }
							onClaimReleased={ handleClaimReleased }
							currentUserId={ bootData?.currentUserId }
							isLocallyDecided={ locallyDecidedEntryIds.has(
								selectedEntry?.id
							) }
						/>
					</div>
				</div>
			</DataViews>
		</div>
	);
}

const rootElement = document.getElementById( ROOT_ID );
const bootData = getBootData();

if ( rootElement && bootData ) {
	createRoot( rootElement ).render(
		<ActivityLogApp bootData={ bootData } />
	);
}
