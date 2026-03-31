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
import {
	DataForm,
	DataViews,
	filterSortAndPaginate,
} from '@wordpress/dataviews/wp';
import {
	check,
	page,
	plugins,
	settings,
	symbol,
	undo,
	warning,
} from '@wordpress/icons';
import '@wordpress/dataviews/build-style/style.css';
import './activity-log.css';
import {
	DEFAULT_ACTIVITY_VIEW,
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
	const blockedOrFailedCount = entries.filter( ( entry ) =>
		[ 'blocked', 'failed' ].includes( entry.status )
	).length;

	return [
		{
			id: 'total',
			label: 'Recorded actions',
			value: entries.length,
			description:
				'Recent server-backed AI activity across Flavor Agent surfaces.',
		},
		{
			id: 'applied',
			label: 'Still applied',
			value: entries.filter( ( entry ) => entry.status === 'applied' )
				.length,
			description:
				'Entries that still appear undo-eligible from the current activity tail.',
		},
		{
			id: 'undone',
			label: 'Undone',
			value: entries.filter( ( entry ) => entry.status === 'undone' )
				.length,
			description:
				'Entries already reversed and synced to the audit store.',
		},
		{
			id: 'review',
			label: 'Needs review',
			value: blockedOrFailedCount,
			description:
				'Entries with blocked or failed undo paths that need operator context.',
		},
	];
}

function buildSelectElements( entries, key ) {
	const values = Array.from(
		new Set(
			entries
				.map( ( entry ) => entry?.[ key ] )
				.filter( ( value ) => typeof value === 'string' && value )
		)
	).sort( ( left, right ) => left.localeCompare( right ) );

	return values.map( ( value ) => ( {
		value,
		label: value,
	} ) );
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

function DetailLink( { href, label } ) {
	if ( ! href ) {
		return <span>Not available</span>;
	}

	return <a href={ href }>{ label }</a>;
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
			render: ( { item } ) => (
				<span>
					{ item.provider } · { item.model }
				</span>
			),
		},
		{
			id: 'requestSummary',
			label: 'Request summary',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => <span>{ item.requestReference }</span>,
		},
		{
			id: 'undoSummary',
			label: 'Undo summary',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => <span>{ item.undoStatusLabel }</span>,
		},
		{
			id: 'linksSummary',
			label: 'Links summary',
			type: 'text',
			readOnly: true,
			render: () => <span>Open target and settings</span>,
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
		{
			id: 'targetLink',
			label: 'Affected target',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<DetailLink
					href={ item.targetUrl }
					label="Open affected entity"
				/>
			),
		},
		{
			id: 'settingsLink',
			label: 'Flavor Agent settings',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<DetailLink href={ item.settingsUrl } label="Open settings" />
			),
		},
		{
			id: 'connectorsLink',
			label: 'Connectors',
			type: 'text',
			readOnly: true,
			render: ( { item } ) => (
				<DetailLink
					href={ item.connectorsUrl }
					label="Open connectors"
				/>
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
				children: [ 'provider', 'model', 'tokenUsage', 'latency' ],
				layout: {
					type: 'details',
					summary: 'diagnosticsSummary',
				},
			},
			{
				id: 'request',
				label: 'Request',
				children: [ 'requestReference', 'requestPrompt' ],
				layout: {
					type: 'details',
					summary: 'requestSummary',
				},
			},
			{
				id: 'undo',
				label: 'Undo',
				children: [ 'undoStatusLabel', 'undoError' ],
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
			{
				id: 'links',
				label: 'Links',
				children: [ 'targetLink', 'settingsLink', 'connectorsLink' ],
				layout: {
					type: 'details',
					summary: 'linksSummary',
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
						undo state, and navigation links.
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
					<div className="flavor-agent-activity-log__sidebar-actions">
						{ entry.targetUrl && (
							<Button
								href={ entry.targetUrl }
								variant="secondary"
							>
								Open target
							</Button>
						) }
						<Button
							aria-label="Open Flavor Agent settings"
							href={ entry.settingsUrl }
							variant="tertiary"
						>
							<Icon aria-hidden="true" icon={ settings } />
						</Button>
					</div>
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
	const [ view, setView ] = useState( () => readPersistedActivityView() );
	const [ entries, setEntries ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ reloadToken, setReloadToken ] = useState( 0 );
	const [ selectedEntryId, setSelectedEntryId ] = useState( '' );

	useEffect( () => {
		let isCurrent = true;

		async function loadEntries() {
			setIsLoading( true );
			setError( '' );

			try {
				const response = await apiFetch( {
					url: `${ bootData.restUrl }flavor-agent/v1/activity?global=1&limit=${ bootData.defaultLimit }`,
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
					}
				);

				if ( ! isCurrent ) {
					return;
				}

				setEntries( normalizedEntries );
			} catch ( fetchError ) {
				if ( ! isCurrent ) {
					return;
				}

				setEntries( [] );
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
	}, [ bootData, reloadToken ] );

	const fields = useMemo( () => {
		const surfaceElements = buildSelectElements( entries, 'surface' );
		const operationTypeElements = buildSelectElements(
			entries,
			'operationType'
		);
		const statusElements = [
			{ value: 'applied', label: 'Applied' },
			{ value: 'undone', label: 'Undone' },
			{ value: 'blocked', label: 'Undo blocked' },
			{ value: 'failed', label: 'Undo unavailable' },
		];
		const postTypeElements = buildSelectElements( entries, 'postType' );
		const userElements = buildSelectElements( entries, 'user' );
		const providerElements = buildSelectElements( entries, 'provider' );

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
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'description',
				label: 'Description',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
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
				elements: statusElements,
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
				id: 'user',
				label: 'User',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				elements: userElements,
				filterBy: {
					operators: [ 'is', 'isNot' ],
				},
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
				enableGlobalSearch: true,
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
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'blockPath',
				label: 'Block path',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'provider',
				label: 'Provider',
				type: 'text',
				enableSorting: false,
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
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'activityTypeLabel',
				label: 'Recorded activity type',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
			{
				id: 'requestPrompt',
				label: 'Prompt',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				filterBy: {
					operators: [ 'contains', 'notContains', 'startsWith' ],
				},
			},
		];
	}, [ entries ] );

	const processedViewData = useMemo( () => {
		const normalizedView = normalizeStoredActivityView( view );
		const initialProcessedData = filterSortAndPaginate(
			entries,
			normalizedView,
			fields
		);
		const effectiveView = clampActivityViewPage(
			normalizedView,
			initialProcessedData?.paginationInfo
		);

		if ( areActivityViewsEqual( effectiveView, normalizedView ) ) {
			return {
				effectiveView,
				processedData: initialProcessedData,
			};
		}

		return {
			effectiveView,
			processedData: filterSortAndPaginate(
				entries,
				effectiveView,
				fields
			),
		};
	}, [ entries, fields, view ] );
	const { effectiveView, processedData } = processedViewData;
	const visibleEntries = useMemo(
		() => processedData?.data || [],
		[ processedData?.data ]
	);
	const selectedEntry =
		visibleEntries.find( ( entry ) => entry.id === selectedEntryId ) ||
		null;

	useEffect( () => {
		writePersistedActivityView( effectiveView );
	}, [ effectiveView ] );

	useEffect( () => {
		if ( ! areActivityViewsEqual( view, effectiveView ) ) {
			setView( effectiveView );
		}
	}, [ effectiveView, view ] );

	useEffect( () => {
		if ( visibleEntries.length === 0 ) {
			if ( selectedEntryId ) {
				setSelectedEntryId( '' );
			}
			return;
		}

		if (
			! visibleEntries.some( ( entry ) => entry.id === selectedEntryId )
		) {
			setSelectedEntryId( visibleEntries[ 0 ].id );
		}
	}, [ selectedEntryId, visibleEntries ] );

	const summaryCards = getSummaryCards( entries );
	const isViewModified = ! areActivityViewsEqual(
		effectiveView,
		DEFAULT_ACTIVITY_VIEW
	);

	return (
		<div className="flavor-agent-activity-log">
			<div className="flavor-agent-activity-log__intro">
				<div>
					<h1 className="flavor-agent-activity-log__page-title">
						AI Activity Log
					</h1>
					<p className="flavor-agent-activity-log__copy">
						Review recent server-backed AI actions across the
						editor, Site Editor, and admin-side Flavor Agent
						workflows.
					</p>
				</div>
				<div className="flavor-agent-activity-log__intro-actions">
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
						<Icon icon={ plugins } />
						<span>Connectors</span>
					</Button>
				</div>
			</div>

			<div className="flavor-agent-activity-log__summary">
				{ summaryCards.map( ( card ) => (
					<Card
						key={ card.id }
						className="flavor-agent-activity-log__summary-card"
					>
						<CardBody>
							<div className="flavor-agent-activity-log__summary-label">
								{ card.label }
							</div>
							<div className="flavor-agent-activity-log__summary-value">
								{ card.value }
							</div>
							<p className="flavor-agent-activity-log__copy">
								{ card.description }
							</p>
						</CardBody>
					</Card>
				) ) }
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<DataViews
				data={ visibleEntries }
				fields={ fields }
				view={ effectiveView }
				onChangeView={ setView }
				getItemId={ ( item ) => item.id }
				paginationInfo={ processedData.paginationInfo }
				defaultLayouts={ {
					activity: {
						layout: {
							density: 'comfortable',
						},
					},
				} }
				isItemClickable={ () => true }
				onClickItem={ ( item ) => setSelectedEntryId( item.id ) }
				actions={ [
					{
						id: 'inspect',
						label: 'Inspect entry',
						isPrimary: true,
						callback: ( items ) => {
							if ( items[ 0 ] ) {
								setSelectedEntryId( items[ 0 ].id );
							}
						},
					},
				] }
				config={ {
					perPageSizes: [ 10, 20, 50, 100 ],
				} }
				onReset={
					isViewModified
						? () => setView( DEFAULT_ACTIVITY_VIEW )
						: false
				}
				isLoading={ isLoading }
				empty={ <EmptyState view={ view } /> }
			>
				<div className="flavor-agent-activity-log__controls">
					<div className="flavor-agent-activity-log__controls-main">
						<DataViews.Search label="Search AI activity" />
						<DataViews.FiltersToggle />
						{ isLoading && <Spinner /> }
					</div>
					<div className="flavor-agent-activity-log__controls-actions">
						<DataViews.ViewConfig />
						{ isViewModified && (
							<Button
								variant="secondary"
								onClick={ () =>
									setView( DEFAULT_ACTIVITY_VIEW )
								}
							>
								Reset view
							</Button>
						) }
					</div>
				</div>
				<DataViews.FiltersToggled />
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
