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
	buildDecisionRequest,
	clampActivityViewPage,
	getGovernanceDetails,
	isPendingExternalApply,
	normalizeActivityEntries,
	normalizeStoredActivityView,
	readPersistedActivityView,
	writePersistedActivityView,
} from './activity-log-utils';

const ROOT_ID = 'flavor-agent-activity-log-root';
const NOT_RECORDED = 'Not recorded';
const ERROR_KIND_INVALID_DAY_FILTER = 'invalid-day-filter';
const ERROR_KIND_FETCH = 'fetch';
const LEARNING_REPORT_VERSION = 'governance-learning-report-v1';
const LEARNING_REPORT_GROUP_ROW_LIMIT = 4;
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

function getLearningReportActivityUrl( adminUrl, activityId ) {
	const id =
		typeof activityId === 'string' && activityId.trim()
			? activityId.trim()
			: '';
	const base =
		typeof adminUrl === 'string' && adminUrl.trim() ? adminUrl.trim() : '';

	if ( ! id || ! base ) {
		return '';
	}

	const normalizedBase = base.endsWith( '/' ) ? base : `${ base }/`;
	const params = new URLSearchParams( {
		page: 'flavor-agent-activity',
		activity: id,
	} );

	return `${ normalizedBase }options-general.php?${ params.toString() }`;
}

function normalizeLearningReportRows( rows ) {
	if ( ! Array.isArray( rows ) ) {
		return [];
	}

	return rows
		.map( ( row ) => {
			if ( ! isPlainRecord( row ) ) {
				return null;
			}

			const key =
				typeof row.key === 'string' && row.key.trim()
					? row.key.trim()
					: '';
			const label =
				typeof row.label === 'string' && row.label.trim()
					? row.label.trim()
					: key;

			if ( ! label ) {
				return null;
			}

			return {
				key: key || label,
				label,
				sampleSize: getLearningReportInteger( row.sampleSize ),
				shownCount: getLearningReportInteger( row.shownCount ),
				selectedForReviewCount: getLearningReportInteger(
					row.selectedForReviewCount
				),
				appliedCount: getLearningReportInteger( row.appliedCount ),
				validationBlockedCount: getLearningReportInteger(
					row.validationBlockedCount
				),
				reviewSelectionRate: getLearningReportRate(
					row.reviewSelectionRate
				),
				applyConversionRate: getLearningReportRate(
					row.applyConversionRate
				),
				validationBlockedRate: getLearningReportRate(
					row.validationBlockedRate
				),
				representativeActivityId:
					typeof row.representativeActivityId === 'string'
						? row.representativeActivityId.trim()
						: '',
			};
		} )
		.filter( Boolean )
		.slice( 0, LEARNING_REPORT_GROUP_ROW_LIMIT );
}

function getLearningReportGroups( report ) {
	const groups = isPlainRecord( report?.groups ) ? report.groups : {};
	const definitions = [
		{
			id: 'surfaces',
			label: __( 'Surfaces', 'flavor-agent' ),
		},
		{
			id: 'operationTypes',
			label: __( 'Operation types', 'flavor-agent' ),
		},
		{
			id: 'providerModels',
			label: __( 'Provider and model', 'flavor-agent' ),
		},
		{
			id: 'validationReasons',
			label: __( 'Validation reasons', 'flavor-agent' ),
		},
		{
			id: 'guidelineVersions',
			label: __( 'Guideline versions', 'flavor-agent' ),
		},
		{
			id: 'rankingSignals',
			label: __( 'Ranking signals', 'flavor-agent' ),
		},
		{
			id: 'patternTraits',
			label: __( 'Pattern traits', 'flavor-agent' ),
		},
	];

	return definitions
		.map( ( group ) => ( {
			...group,
			rows: normalizeLearningReportRows( groups[ group.id ] ),
		} ) )
		.filter( ( group ) => group.rows.length > 0 );
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
	const activityUrl = getLearningReportActivityUrl(
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
							key={ group.id }
							className="flavor-agent-activity-log__learning-report-group"
							aria-labelledby={ `flavor-agent-activity-log-learning-report-${ group.id }` }
						>
							<h3
								id={ `flavor-agent-activity-log-learning-report-${ group.id }` }
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
			[ entry.statusLabel, entry.operationTypeLabel ]
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
		id: 'diagnostics',
		label: __( 'Diagnostics', 'flavor-agent' ),
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
		label: __( 'Request', 'flavor-agent' ),
		summary: ( entry ) =>
			[ entry.requestAbility, entry.requestReference ]
				.filter( ( value ) => value && value !== NOT_RECORDED )
				.join( ' · ' ),
		rows: [
			[ __( 'Ability', 'flavor-agent' ), 'requestAbility' ],
			[ __( 'Route', 'flavor-agent' ), 'requestRoute' ],
			[ __( 'Reference', 'flavor-agent' ), 'requestReference' ],
			[ __( 'Prompt', 'flavor-agent' ), 'requestPrompt', 'code' ],
		],
	},
	{
		id: 'undo',
		label: __( 'Undo', 'flavor-agent' ),
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
		id: 'state',
		label: __( 'State snapshots', 'flavor-agent' ),
		summary: () => '',
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
	const [ requestLogState, setRequestLogState ] = useState( {
		id: requestLogId,
		details: null,
		error: '',
		isLoading: false,
	} );

	useEffect( () => {
		setRequestLogState( {
			id: requestLogId,
			details: null,
			error: '',
			isLoading: false,
		} );
	}, [ requestLogId ] );

	if ( entry?.modelRequest?.attempted === false ) {
		return (
			<div className="flavor-agent-activity-log__request-log flavor-agent-activity-log__request-log--no-model">
				<p className="flavor-agent-activity-log__copy">
					{ __(
						'No model request was attempted for this diagnostic.',
						'flavor-agent'
					) }
				</p>
			</div>
		);
	}

	if ( ! requestLogId && ! requestToken ) {
		return null;
	}

	if ( ! requestLogId ) {
		return (
			<div className="flavor-agent-activity-log__request-log flavor-agent-activity-log__request-log--unavailable">
				<p className="flavor-agent-activity-log__copy">
					{ __(
						'AI request log unavailable (core logging may have been disabled at request time).',
						'flavor-agent'
					) }
				</p>
			</div>
		);
	}

	const loadRequestLog = async () => {
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

			setRequestLogState( {
				id: requestLogId,
				details: normalizeCoreRequestLogDetails( response || {} ),
				error: '',
				isLoading: false,
			} );
		} catch ( fetchError ) {
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
	const rows = details
		? [
				[ __( 'Provider', 'flavor-agent' ), details.provider ],
				[ __( 'Model', 'flavor-agent' ), details.model ],
				[ __( 'Duration', 'flavor-agent' ), details.duration ],
				[ __( 'Tokens', 'flavor-agent' ), details.tokenUsage ],
		  ]
		: [];

	return (
		<div className="flavor-agent-activity-log__request-log">
			<div className="flavor-agent-activity-log__request-log-actions">
				<Button
					variant="secondary"
					onClick={ loadRequestLog }
					disabled={ requestLogState.isLoading }
				>
					{ requestLogState.isLoading && <Spinner /> }
					{ __( 'View AI request', 'flavor-agent' ) }
				</Button>
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
				<p className="flavor-agent-activity-log__request-log-error">
					{ requestLogState.error }
				</p>
			) }
			{ details && (
				<div className="flavor-agent-activity-log__request-log-details">
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
		</div>
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

function GovernanceComparisonTable( { rows = [] } ) {
	if ( ! rows.length ) {
		return null;
	}

	return (
		<div className="flavor-agent-activity-log__governance-subsection">
			<h4 className="flavor-agent-activity-log__governance-subtitle">
				{ __( 'Style comparison', 'flavor-agent' ) }
			</h4>
			<div className="flavor-agent-activity-log__comparison" role="table">
				<div
					className="flavor-agent-activity-log__comparison-row flavor-agent-activity-log__comparison-row--header"
					role="row"
				>
					<span role="columnheader">
						{ __( 'Value', 'flavor-agent' ) }
					</span>
					<span role="columnheader">
						{ __( 'Before', 'flavor-agent' ) }
					</span>
					<span role="columnheader">
						{ __( 'Proposed', 'flavor-agent' ) }
					</span>
					<span role="columnheader">
						{ __( 'After', 'flavor-agent' ) }
					</span>
				</div>
				{ rows.map( ( row, index ) => (
					<div
						key={ `${ row.label }-${ index }` }
						className={ `flavor-agent-activity-log__comparison-row is-${ row.status }` }
						role="row"
					>
						<span role="cell">{ row.label }</span>
						<span role="cell">{ row.before }</span>
						<span role="cell">{ row.proposed }</span>
						<span role="cell">{ row.after }</span>
					</div>
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
		verifyUrl: getAttestationString( artifact, 'verifyUrl' ),
		subjectStateUrl: getAttestationString( artifact, 'subjectStateUrl' ),
		keyId: getAttestationString( artifact, 'keyId' ),
		subjectName: getAttestationString( artifact, 'subjectName' ),
		subjectScope: getAttestationString( artifact, 'subjectScope' ),
		createdAt: getAttestationString( artifact, 'createdAt' ),
		revertedByAttestationId: getAttestationString(
			artifact,
			'revertedByAttestationId'
		),
	};
}

function AttestationEvidenceSection( { entry } ) {
	const artifact = getAttestationArtifact( entry );

	if ( ! artifact ) {
		return null;
	}

	const rows = [
		[ __( 'Attestation ID', 'flavor-agent' ), artifact.id ],
		[ __( 'Key', 'flavor-agent' ), artifact.keyId ],
		[ __( 'Subject', 'flavor-agent' ), artifact.subjectName ],
		[ __( 'Scope', 'flavor-agent' ), artifact.subjectScope ],
		[ __( 'Recorded', 'flavor-agent' ), artifact.createdAt ],
		[
			__( 'Reverted by', 'flavor-agent' ),
			artifact.revertedByAttestationId,
		],
	];

	return (
		<section className="flavor-agent-activity-log__governance-subsection">
			<h4 className="flavor-agent-activity-log__governance-subtitle">
				{ __( 'Attestation', 'flavor-agent' ) }
			</h4>
			<GovernanceDetailRows rows={ rows } />
			<div className="flavor-agent-activity-log__attestation-actions">
				{ artifact.verifyUrl && (
					<Button
						href={ artifact.verifyUrl }
						target="_blank"
						rel="noreferrer"
						variant="secondary"
					>
						{ __( 'Verify envelope', 'flavor-agent' ) }
					</Button>
				) }
				{ artifact.subjectStateUrl && (
					<Button
						href={ artifact.subjectStateUrl }
						target="_blank"
						rel="noreferrer"
						variant="secondary"
					>
						{ __( 'Check live subject', 'flavor-agent' ) }
					</Button>
				) }
			</div>
		</section>
	);
}

function GovernanceEvidenceSection( { entry, bootData, onDecided } ) {
	const details = getGovernanceDetailsForEntry( entry );
	const [ note, setNote ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ decisionError, setDecisionError ] = useState( '' );
	const isSubmittingRef = useRef( false );

	useEffect( () => {
		setNote( '' );
		setDecisionError( '' );
		isSubmittingRef.current = false;
		setIsSubmitting( false );
	}, [ entry?.id ] );

	if ( ! details ) {
		return null;
	}

	const canDecide =
		isPendingExternalApply( entry ) && bootData?.canApproveStyleApplies;
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
	const freshnessRows = [
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
		[ __( 'Failure code', 'flavor-agent' ), details.failureCode ],
		[ __( 'Failure reason', 'flavor-agent' ), details.failureMessage ],
		[ __( 'Undo state', 'flavor-agent' ), details.undoStatus ],
		[ __( 'Undo reason', 'flavor-agent' ), details.undoReason ],
	];

	const submitDecision = async ( decision ) => {
		if ( isSubmittingRef.current ) {
			return;
		}

		isSubmittingRef.current = true;
		setIsSubmitting( true );
		setDecisionError( '' );

		try {
			await apiFetch(
				buildDecisionRequest( bootData, entry.id, decision, note )
			);
			onDecided?.();
		} catch ( error ) {
			setDecisionError(
				error?.message ||
					__( 'The decision could not be recorded.', 'flavor-agent' )
			);
		} finally {
			isSubmittingRef.current = false;
			setIsSubmitting( false );
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
			<GovernanceOperationList
				title={ __( 'Requested operations', 'flavor-agent' ) }
				operations={ details.proposedOperations }
			/>
			<GovernanceOperationList
				title={ __( 'Executed operations', 'flavor-agent' ) }
				operations={ details.executedOperations }
			/>
			<GovernanceComparisonTable rows={ details.comparisonRows } />
			<div className="flavor-agent-activity-log__governance-subsection">
				<h4 className="flavor-agent-activity-log__governance-subtitle">
					{ __( 'Target and provenance', 'flavor-agent' ) }
				</h4>
				<GovernanceDetailRows rows={ provenanceRows } />
			</div>
			<div className="flavor-agent-activity-log__governance-subsection">
				<h4 className="flavor-agent-activity-log__governance-subtitle">
					{ __( 'Freshness and undo evidence', 'flavor-agent' ) }
				</h4>
				<GovernanceDetailRows rows={ freshnessRows } />
			</div>
			<AttestationEvidenceSection entry={ entry } />
			{ details.diagnosticText && (
				<details className="flavor-agent-activity-log__governance-diagnostics">
					<summary>
						{ __( 'Raw governance diagnostics', 'flavor-agent' ) }
					</summary>
					<pre className="flavor-agent-activity-log__code">
						{ details.diagnosticText }
					</pre>
				</details>
			) }
			{ canDecide && (
				<div className="flavor-agent-activity-log__decision">
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
		</section>
	);
}

function ActivityEntryDetails( { entry, bootData, onDecided } ) {
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
						{ entry.targetUrl && (
							<div className="flavor-agent-activity-log__sidebar-actions">
								<Button
									href={ entry.targetUrl }
									variant="secondary"
								>
									{ entry.targetLinkLabel }
								</Button>
							</div>
						) }
					</div>
				</CardHeader>
				<CardBody>
					<AiRequestLogPanel entry={ entry } bootData={ bootData } />
					<GovernanceEvidenceSection
						entry={ entry }
						bootData={ bootData }
						onDecided={ onDecided }
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
	const defaultView = useMemo(
		() => normalizeStoredActivityView( undefined, viewOptions ),
		[ viewOptions ]
	);
	const [ view, setView ] = useState( () =>
		readPersistedActivityView( undefined, viewOptions )
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
			blocked: 0,
			failed: 0,
		},
		learningReport: null,
	} ) );
	const [ error, setError ] = useState( '' );
	const [ errorKind, setErrorKind ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ reloadToken, setReloadToken ] = useState( 0 );
	const linkedActivityEntryId = useMemo( getLinkedActivityEntryId, [] );
	const [ requestActivityId, setRequestActivityId ] = useState(
		linkedActivityEntryId
	);
	const [ selectedEntryId, setSelectedEntryId ] = useState(
		linkedActivityEntryId
	);

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
				const normalizedEntries = normalizeActivityEntries(
					response?.entries || [],
					{
						adminBaseUrl: bootData.adminUrl,
						settingsUrl: bootData.settingsUrl,
						connectorsUrl: bootData.connectorsUrl,
						locale: bootData.locale,
						timeZone: bootData.timeZone,
					}
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
						blocked: response?.summary?.blocked || 0,
						failed: response?.summary?.failed || 0,
					},
					learningReport: isPlainRecord( response?.learningReport )
						? response.learningReport
						: null,
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
				render: ( { item } ) => (
					<span
						id={ `flavor-agent-activity-log-entry-title-${ item.id }` }
						className={ `flavor-agent-activity-log__entry-title${
							item.id === selectedEntryId ? ' is-current' : ''
						}${
							item.governanceDetails?.status === 'pending'
								? ' is-pending-governance'
								: ''
						}` }
						aria-current={
							item.id === selectedEntryId ? 'true' : undefined
						}
						aria-controls={
							item.id === selectedEntryId
								? 'flavor-agent-activity-log-details'
								: undefined
						}
					>
						{ item.title }
						{ item.governanceDetails?.status === 'pending' && (
							<span className="flavor-agent-activity-log__entry-badge">
								{ __( 'Pending approval', 'flavor-agent' ) }
								{ item.governanceDetails.expiresAt
									? ` · ${ sprintf(
											/* translators: %s: expiry timestamp. */
											__( 'Expires %s', 'flavor-agent' ),
											item.governanceDetails.expiresAt
									  ) }`
									: '' }
							</span>
						) }
					</span>
				),
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
	}, [ responseData, selectedEntryId ] );

	const selectedEntry =
		responseData.entries.find(
			( entry ) => entry.id === selectedEntryId
		) || null;

	useEffect( () => {
		writePersistedActivityView( effectiveView, undefined, viewOptions );
	}, [ effectiveView, viewOptions ] );

	useEffect( () => {
		if ( ! areActivityViewsEqual( view, effectiveView, viewOptions ) ) {
			setView( effectiveView );
		}
	}, [ effectiveView, view, viewOptions ] );

	useEffect( () => {
		if ( responseData.entries.length === 0 ) {
			if ( requestActivityId ) {
				setRequestActivityId( '' );
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
			setRequestActivityId( '' );
		}
	}, [
		isLoading,
		requestActivityId,
		responseData.entries,
		selectedEntryId,
	] );

	const summaryCards = getSummaryCards( responseData.summary );
	const isViewModified = ! areActivityViewsEqual(
		effectiveView,
		defaultView,
		viewOptions
	);
	const approvalsFilterActive = isPendingApprovalView( effectiveView );
	const toggleApprovalsFilter = useCallback( () => {
		if ( requestActivityId ) {
			setRequestActivityId( '' );
		}

		setView( ( currentView ) =>
			withPendingApprovalFilter( currentView, viewOptions )
		);
	}, [ requestActivityId, viewOptions ] );
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
					if ( requestActivityId ) {
						setRequestActivityId( '' );
					}

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
					if ( requestActivityId ) {
						setRequestActivityId( '' );
					}

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
							onDecided={ () =>
								setReloadToken( ( value ) => value + 1 )
							}
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
