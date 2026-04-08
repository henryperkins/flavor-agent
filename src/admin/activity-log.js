import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Icon,
	Notice,
	Spinner,
} from '@wordpress/components';
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { DataForm, DataViews } from '@wordpress/dataviews/wp';
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
			label: 'Recorded actions',
			value: summary?.total || 0,
			description: '',
		},
		{
			id: 'applied',
			label: 'Still applied',
			value: summary?.applied || 0,
			description: '',
		},
		{
			id: 'undone',
			label: 'Undone',
			value: summary?.undone || 0,
			description: '',
		},
		{
			id: 'review',
			label: 'Review-only',
			value: summary?.review || 0,
			description: '',
		},
		{
			id: 'blocked',
			label: 'Undo blocked',
			value: summary?.blocked || 0,
			description: '',
		},
		{
			id: 'failed',
			label: 'Failed or unavailable',
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
			rawValue === 'Not recorded'
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
		? `No AI actions matched “${ view.search }”.`
		: 'No AI activity has been recorded yet.';

	return (
		<Card className="flavor-agent-activity-log__empty" size="small">
			<CardBody>
				<h3 className="flavor-agent-activity-log__section-title">
					No matching activity
				</h3>
				<p className="flavor-agent-activity-log__copy">{ message }</p>
			</CardBody>
		</Card>
	);
}

function getDetailFields() {
	return [
		{
			id: 'overviewSummary',
			label: 'Overview summary',
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
			label: 'Diagnostics summary',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => {
				const parts = [
					item.provider,
					item.model,
					item.providerPath,
				].filter( ( value ) => value && value !== 'Not recorded' );

				return <span>{ parts.join( ' · ' ) || 'Not recorded' }</span>;
			},
		},
		{
			id: 'requestSummary',
			label: 'Request summary',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => {
				const parts = [
					item.requestAbility,
					item.requestReference,
				].filter( ( value ) => value && value !== 'Not recorded' );

				return <span>{ parts.join( ' · ' ) || 'Not recorded' }</span>;
			},
		},
		{
			id: 'undoSummary',
			label: 'Undo summary',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<span>
					{ item.undoStatusLabel }
					{ item.undoReason && item.undoReason !== 'Not recorded'
						? ` · ${ item.undoReason }`
						: '' }
				</span>
			),
		},
		{
			id: 'statusLabel',
			label: 'Status',
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
			label: 'Recorded',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'surfaceLabel',
			label: 'Surface',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'operationTypeLabel',
			label: 'Action type',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'activityTypeLabel',
			label: 'Recorded activity type',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'entity',
			label: 'Entity',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'postType',
			label: 'Post type',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'entityId',
			label: 'Entity ID',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'documentLabel',
			label: 'Document',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'documentScopeKey',
			label: 'Document scope',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'blockPath',
			label: 'Block path',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'user',
			label: 'User',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'provider',
			label: 'Provider',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'model',
			label: 'Model',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'providerPath',
			label: 'Provider path',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'configurationOwner',
			label: 'Configured in',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'credentialSource',
			label: 'Credential source',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'selectedProvider',
			label: 'Selected provider',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'connector',
			label: 'Connector',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'connectorPlugin',
			label: 'Connector plugin',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestFallback',
			label: 'Fallback',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'tokenUsage',
			label: 'Token usage',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'latency',
			label: 'Latency',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestReference',
			label: 'Reference',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestAbility',
			label: 'Ability',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestRoute',
			label: 'Route',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'requestPrompt',
			label: 'Prompt',
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
			label: 'Undo state',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'undoError',
			label: 'Undo error',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'undoReason',
			label: 'Undo reason',
			type: 'text',
			readOnly: true,
		},
		{
			id: 'stateDiff',
			label: 'Structured diff',
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
			label: 'Before',
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
			label: 'After',
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
				label: 'Overview',
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
				label: 'Diagnostics',
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
				],
				layout: {
					type: 'details',
					summary: 'diagnosticsSummary',
				},
			},
			{
				id: 'request',
				label: 'Request',
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
				label: 'Undo',
				children: [ 'undoStatusLabel', 'undoReason', 'undoError' ],
				layout: {
					type: 'details',
					summary: 'undoSummary',
				},
			},
			{
				id: 'state',
				label: 'State snapshots',
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
						Entry details
					</h3>
					<p className="flavor-agent-activity-log__copy">
						Select an activity item to inspect request metadata,
						provider ownership, undo state, and navigation links.
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
						'Flavor Agent could not load the recent AI activity log.'
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
				label: 'Icon',
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
				label: 'Action',
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
				label: 'Description',
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
				label: 'Date',
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
				label: 'Timestamp',
				type: 'datetime',
				enableSorting: true,
				render: ( { item } ) => <span>{ item.timestampDisplay }</span>,
			},
			{
				id: 'timestampDisplay',
				label: 'Recorded',
				type: 'text',
				enableSorting: false,
				render: ( { item } ) => <span>{ item.timestampDisplay }</span>,
			},
			{
				id: 'operationType',
				label: 'Action type',
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
				label: 'Surface',
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
				label: 'Status',
				type: 'text',
				enableSorting: false,
				elements: [
					{ value: 'applied', label: 'Applied' },
					{ value: 'review', label: 'Review' },
					{ value: 'undone', label: 'Undone' },
					{ value: 'blocked', label: 'Undo blocked' },
					{ value: 'failed', label: 'Undo unavailable' },
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
				label: 'User',
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
				label: 'Post type',
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
				label: 'Entity ID',
				type: 'text',
				enableSorting: false,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'entity',
				label: 'Entity',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'blockPath',
				label: 'Block path',
				type: 'text',
				enableSorting: false,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'provider',
				label: 'Provider',
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
				label: 'Model',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'providerPath',
				label: 'Provider path',
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
				label: 'Configured in',
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
				label: 'Credential source',
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
				label: 'Selected provider',
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
				label: 'Recorded activity type',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'requestPrompt',
				label: 'Prompt',
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

	return (
		<div className="flavor-agent-activity-log">
			<div className="flavor-agent-activity-log__masthead">
				<div className="flavor-agent-activity-log__masthead-copy">
					<p className="flavor-agent-activity-log__eyebrow">
						Flavor Agent
					</p>
					<div className="flavor-agent-activity-log__title-row">
						<h1 className="flavor-agent-activity-log__page-title">
							AI Activity Log
						</h1>
					</div>
					<p className="flavor-agent-activity-log__copy">
						Review recent AI actions across editor, Site Editor, and
						admin surfaces.
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
						Refresh
					</Button>
					<Button href={ bootData.settingsUrl } variant="tertiary">
						Flavor Agent settings
					</Button>
					<Button href={ bootData.connectorsUrl } variant="tertiary">
						Connectors
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
				empty={ <EmptyState view={ effectiveView } /> }
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
									<DataViews.Search label="Search AI activity" />
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
										Reset view
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

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
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
