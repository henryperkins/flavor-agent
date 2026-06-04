import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Icon,
	Spinner,
} from '@wordpress/components';
import {
	createRoot,
	Fragment,
	useCallback,
	useEffect,
	useMemo,
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
	clampActivityViewPage,
	normalizeActivityEntries,
	normalizeStoredActivityView,
	readPersistedActivityView,
	writePersistedActivityView,
} from './activity-log-utils';

const ROOT_ID = 'flavor-agent-activity-log-root';
const NOT_RECORDED = 'Not recorded';
const ERROR_KIND_INVALID_DAY_FILTER = 'invalid-day-filter';
const ERROR_KIND_FETCH = 'fetch';
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

function ActivityEntryDetails( { entry, bootData } ) {
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
			blocked: 0,
			failed: 0,
		},
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
					blocked: 0,
					failed: 0,
				},
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
						blocked: response?.summary?.blocked || 0,
						failed: response?.summary?.failed || 0,
					},
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
						blocked: 0,
						failed: 0,
					},
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
							'Review recent AI actions across editor, Site Editor, and admin surfaces.',
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
