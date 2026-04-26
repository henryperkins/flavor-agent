import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Icon,
	Spinner,
} from '@wordpress/components';
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { DataForm, DataViews } from '@wordpress/dataviews/wp';
import { __, sprintf } from '@wordpress/i18n';
import { check, page, plugins, symbol, undo, warning } from '@wordpress/icons';
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

function getBootData() {
	return window.flavorAgentActivityLog || null;
}

function getIconForEntry( entry ) {
	switch ( entry?.status ) {
		case 'blocked':
		case 'failed':
			return warning;
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

	return options.filter(
		( option ) =>
			option &&
			option.value !== undefined &&
			option.value !== null &&
			option.value !== '' &&
			option.label !== undefined &&
			option.label !== null &&
			option.label !== ''
	);
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

function appendDayFilter( params, filter ) {
	if ( ! filter || ! filter.operator || filter.value === undefined ) {
		return;
	}

	params.set( 'dayOperator', String( filter.operator ) );

	if ( filter.operator === 'between' && Array.isArray( filter.value ) ) {
		if ( filter.value[ 0 ] ) {
			params.set( 'day', String( filter.value[ 0 ] ) );
		}

		if ( filter.value[ 1 ] ) {
			params.set( 'dayEnd', String( filter.value[ 1 ] ) );
		}

		return;
	}

	if (
		[ 'inThePast', 'over' ].includes( filter.operator ) &&
		filter.value &&
		typeof filter.value === 'object'
	) {
		if (
			Number.isInteger( filter.value.value ) &&
			filter.value.value > 0
		) {
			params.set( 'dayRelativeValue', String( filter.value.value ) );
		}

		if ( filter.value.unit ) {
			params.set( 'dayRelativeUnit', String( filter.value.unit ) );
		}

		return;
	}

	if ( filter.value ) {
		params.set( 'day', String( filter.value ) );
	}
}

function getActivityRequestUrl( bootData, view ) {
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

function ErrorState( { error, onRetry } ) {
	return (
		<Card
			className="flavor-agent-activity-log__empty flavor-agent-activity-log__error-state"
			size="small"
		>
			<CardBody>
				<div className="flavor-agent-activity-log__error-heading">
					<span className="flavor-agent-activity-log__error-icon">
						<Icon icon={ warning } />
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
					<Button variant="secondary" onClick={ onRetry }>
						{ __( 'Retry loading activity', 'flavor-agent' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function getDetailFields() {
	return [
		{
			id: 'overviewSummary',
			label: __( 'Overview summary', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<span>
					{ item.statusLabel } · { item.operationTypeLabel }
				</span>
			),
		},
		{
			id: 'diagnosticsSummary',
			label: __( 'Diagnostics summary', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => {
				const parts = [
					item.provider,
					item.model,
					item.providerPath,
				].filter( ( value ) => value && value !== NOT_RECORDED );

				return (
					<span>
						{ parts.join( ' · ' ) ||
							__( 'Not recorded', 'flavor-agent' ) }
					</span>
				);
			},
		},
		{
			id: 'requestSummary',
			label: __( 'Request summary', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => {
				const parts = [
					item.requestAbility,
					item.requestReference,
				].filter( ( value ) => value && value !== NOT_RECORDED );

				return (
					<span>
						{ parts.join( ' · ' ) ||
							__( 'Not recorded', 'flavor-agent' ) }
					</span>
				);
			},
		},
		{
			id: 'undoSummary',
			label: __( 'Undo summary', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<span>
					{ item.undoStatusLabel }
					{ item.undoReason && item.undoReason !== NOT_RECORDED
						? ` · ${ item.undoReason }`
						: '' }
				</span>
			),
		},
		{
			id: 'statusLabel',
			label: __( 'Status', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<span
					className={ `flavor-agent-activity-log__status is-${ item.status }` }
				>
					{ item.statusLabel }
				</span>
			),
		},
		{
			id: 'timestampDisplay',
			label: __( 'Recorded', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'surfaceLabel',
			label: __( 'Surface', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'operationTypeLabel',
			label: __( 'Action type', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'activityTypeLabel',
			label: __( 'Recorded activity type', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'entity',
			label: __( 'Entity', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'postType',
			label: __( 'Post type', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'entityId',
			label: __( 'Entity ID', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'documentLabel',
			label: __( 'Document', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'documentScopeKey',
			label: __( 'Document scope', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'blockPath',
			label: __( 'Block path', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'user',
			label: __( 'User', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'provider',
			label: __( 'Provider', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'model',
			label: __( 'Model', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'providerPath',
			label: __( 'Provider path', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'configurationOwner',
			label: __( 'Configured in', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'credentialSource',
			label: __( 'Credential source', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'selectedProvider',
			label: __( 'Selected provider', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'connector',
			label: __( 'Connector', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'connectorPlugin',
			label: __( 'Connector plugin', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestFallback',
			label: __( 'Fallback', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'tokenUsage',
			label: __( 'Token usage', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'latency',
			label: __( 'Latency', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'transportEndpoint',
			label: __( 'Endpoint', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'timeout',
			label: __( 'Timeout', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestPayload',
			label: __( 'Payload', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'responseSummary',
			label: __( 'Response', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'providerRequestId',
			label: __( 'Provider request ID', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'transportError',
			label: __( 'Transport detail', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<pre className="flavor-agent-activity-log__code">
					{ item.transportError }
				</pre>
			),
		},
		{
			id: 'requestReference',
			label: __( 'Reference', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestAbility',
			label: __( 'Ability', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestRoute',
			label: __( 'Route', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestPrompt',
			label: __( 'Prompt', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<pre className="flavor-agent-activity-log__code">
					{ item.requestPrompt }
				</pre>
			),
		},
		{
			id: 'undoStatusLabel',
			label: __( 'Undo state', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'undoError',
			label: __( 'Undo error', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'undoReason',
			label: __( 'Undo reason', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
		},
		{
			id: 'stateDiff',
			label: __( 'Structured diff', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<pre className="flavor-agent-activity-log__code">
					{ item.stateDiff }
				</pre>
			),
		},
		{
			id: 'beforeSummary',
			label: __( 'Before', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<pre className="flavor-agent-activity-log__code">
					{ item.beforeSummary }
				</pre>
			),
		},
		{
			id: 'afterSummary',
			label: __( 'After', 'flavor-agent' ),
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<pre className="flavor-agent-activity-log__code">
					{ item.afterSummary }
				</pre>
			),
		},
	];
}

function getDetailForm() {
	return {
		fields: [
			{
				id: 'overview',
				label: __( 'Overview', 'flavor-agent' ),
				children: [
					'statusLabel',
					'timestampDisplay',
					'operationTypeLabel',
					'activityTypeLabel',
					'surfaceLabel',
					'entity',
					'postType',
					'entityId',
					'documentLabel',
					'documentScopeKey',
					'blockPath',
					'user',
				],
				layout: {
					type: 'details',
					summary: 'overviewSummary',
				},
			},
			{
				id: 'diagnostics',
				label: __( 'Diagnostics', 'flavor-agent' ),
				children: [
					'provider',
					'model',
					'providerPath',
					'configurationOwner',
					'credentialSource',
					'selectedProvider',
					'connector',
					'connectorPlugin',
					'requestFallback',
					'tokenUsage',
					'latency',
					'transportEndpoint',
					'timeout',
					'requestPayload',
					'responseSummary',
					'providerRequestId',
					'transportError',
				],
				layout: {
					type: 'details',
					summary: 'diagnosticsSummary',
				},
			},
			{
				id: 'request',
				label: __( 'Request', 'flavor-agent' ),
				children: [
					'requestAbility',
					'requestRoute',
					'requestReference',
					'requestPrompt',
				],
				layout: {
					type: 'details',
					summary: 'requestSummary',
				},
			},
			{
				id: 'undo',
				label: __( 'Undo', 'flavor-agent' ),
				children: [ 'undoStatusLabel', 'undoReason', 'undoError' ],
				layout: {
					type: 'details',
					summary: 'undoSummary',
				},
			},
			{
				id: 'state',
				label: __( 'State snapshots', 'flavor-agent' ),
				children: [ 'stateDiff', 'beforeSummary', 'afterSummary' ],
				layout: {
					type: 'details',
				},
			},
		],
	};
}

function ActivityEntryDetails( { entry } ) {
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
			<CardHeader>
				<div className="flavor-agent-activity-log__sidebar-heading">
					<div>
						<h3 className="flavor-agent-activity-log__section-title">
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
				<DataForm
					data={ entry }
					fields={ getDetailFields() }
					form={ getDetailForm() }
					onChange={ () => {} }
				/>
			</CardBody>
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
	const [ isLoading, setIsLoading ] = useState( true );
	const [ reloadToken, setReloadToken ] = useState( 0 );
	const [ selectedEntryId, setSelectedEntryId ] = useState( '' );

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
		() => getActivityRequestUrl( bootData, requestedView ),
		[ bootData, requestedView ]
	);
	const perPageSizes = useMemo(
		() => getPerPageSizes( defaultView.perPage, bootData.maxPerPage ),
		[ bootData.maxPerPage, defaultView.perPage ]
	);

	useEffect( () => {
		let isCurrent = true;

		async function loadEntries() {
			setIsLoading( true );
			setError( '' );

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
	}, [
		bootData,
		requestUrl,
		reloadToken,
		requestedView.page,
		requestedView.perPage,
	] );

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
						className={ `flavor-agent-activity-log__entry-title${
							item.id === selectedEntryId ? ' is-current' : ''
						}` }
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
			if ( selectedEntryId ) {
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
	}, [ responseData.entries, selectedEntryId ] );

	const summaryCards = getSummaryCards( responseData.summary );
	const isViewModified = ! areActivityViewsEqual(
		effectiveView,
		defaultView,
		viewOptions
	);
	const emptyState = error ? (
		<ErrorState
			error={ error }
			onRetry={ () => setReloadToken( ( value ) => value + 1 ) }
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
				onChangeView={ setView }
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
				onClickItem={ ( item ) => setSelectedEntryId( item.id ) }
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
							</div>
							<div className="flavor-agent-activity-log__controls-actions">
								<DataViews.FiltersToggle />
								<DataViews.ViewConfig />
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
						<ActivityEntryDetails entry={ selectedEntry } />
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
