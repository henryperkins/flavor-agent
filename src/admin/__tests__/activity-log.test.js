jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const fs = require( 'fs' );
const path = require( 'path' );

const mockTranslate = jest.fn( ( value ) => value );
const mockSprintf = jest.fn( ( template, ...values ) => {
	return values.reduce( ( result, value, index ) => {
		return result
			.replaceAll( `%${ index + 1 }$s`, String( value ) )
			.replace( '%s', String( value ) );
	}, template );
} );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( ...args ) => mockTranslate( ...args ),
	sprintf: ( ...args ) => mockSprintf( ...args ),
} ) );

function getDataViewsMockState() {
	return global.__flavorAgentActivityLogDataViewsState;
}

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/dataviews/wp', () => {
	global.__flavorAgentActivityLogDataViewsState =
		global.__flavorAgentActivityLogDataViewsState || {
			latestProps: null,
		};

	const {
		createContext,
		createElement,
		useContext,
	} = require( '@wordpress/element' );
	const DataViewsContext = createContext( null );
	const dataViewsMockState = global.__flavorAgentActivityLogDataViewsState;

	function filterSortAndPaginate( data, view ) {
		const search = String( view?.search || '' )
			.trim()
			.toLowerCase();
		const perPage =
			Number.isInteger( view?.perPage ) && view.perPage > 0
				? view.perPage
				: 20;
		const page =
			Number.isInteger( view?.page ) && view.page > 0 ? view.page : 1;
		let nextData = Array.isArray( data ) ? [ ...data ] : [];

		if ( search ) {
			nextData = nextData.filter( ( item ) =>
				[
					item?.title,
					item?.description,
					item?.entity,
					item?.entityId,
					item?.operationTypeLabel,
					item?.postType,
					item?.blockPath,
					item?.provider,
					item?.requestPrompt,
					item?.statusLabel,
					item?.surfaceLabel,
					item?.user,
				].some( ( value ) =>
					String( value || '' )
						.toLowerCase()
						.includes( search )
				)
			);
		}

		if ( view?.sort?.field ) {
			nextData.sort( ( left, right ) => {
				if ( view.sort.field === 'timestamp' ) {
					return (
						Date.parse( left?.timestamp || '' ) -
						Date.parse( right?.timestamp || '' )
					);
				}

				return String( left?.[ view.sort.field ] || '' ).localeCompare(
					String( right?.[ view.sort.field ] || '' )
				);
			} );

			if ( view.sort.direction !== 'asc' ) {
				nextData.reverse();
			}
		}

		const totalItems = nextData.length;
		const totalPages =
			totalItems > 0 ? Math.ceil( totalItems / perPage ) : 0;
		const startIndex = ( page - 1 ) * perPage;

		return {
			data: nextData.slice( startIndex, startIndex + perPage ),
			paginationInfo: {
				totalItems,
				totalPages,
			},
		};
	}

	function DataViews( { children, ...props } ) {
		dataViewsMockState.latestProps = props;

		return createElement(
			DataViewsContext.Provider,
			{ value: props },
			createElement( 'div', { className: 'mock-dataviews' }, children )
		);
	}

	function SearchControl( { label } ) {
		const props = useContext( DataViewsContext );

		return createElement( 'input', {
			'aria-label': label,
			onChange: ( event ) =>
				props.onChangeView( {
					...props.view,
					page: 1,
					search: event.target.value,
				} ),
			value: props.view.search || '',
		} );
	}

	DataViews.FiltersToggle = () =>
		createElement( 'button', { type: 'button' }, 'Filters' );
	DataViews.ViewConfig = () =>
		createElement( 'button', { type: 'button' }, 'View config' );
	DataViews.FiltersToggled = () => null;

	function LayoutView() {
		const props = useContext( DataViewsContext );

		if ( ! props.data.length ) {
			return props.empty || null;
		}

		return createElement(
			'div',
			{ className: 'mock-dataviews-layout' },
			props.data.map( ( item ) =>
				createElement(
					'button',
					{
						key: item.id,
						onClick: () => props.onClickItem?.( item ),
						type: 'button',
					},
					item.title
				)
			)
		);
	}

	function PaginationView() {
		const props = useContext( DataViewsContext );

		return createElement(
			'div',
			{
				'data-total-pages': props.paginationInfo?.totalPages || 0,
			},
			`Page ${ props.view.page }`
		);
	}

	DataViews.Search = SearchControl;
	DataViews.Layout = LayoutView;
	DataViews.Pagination = PaginationView;

	return {
		DataForm: () => createElement( 'div', { className: 'mock-data-form' } ),
		DataViews,
		filterSortAndPaginate,
		__mockState: dataViewsMockState,
	};
} );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import apiFetch from '@wordpress/api-fetch';

import {
	DEFAULT_ACTIVITY_VIEW,
	VIEW_STORAGE_KEY,
	readPersistedActivityView,
} from '../activity-log-utils';
import { ActivityLogApp } from '../activity-log';

const { getContainer, getRoot } = setupReactTest();

const BOOT_DATA = {
	adminUrl: 'https://example.test/wp-admin/',
	connectorsUrl: 'https://example.test/wp-admin/options-connectors.php',
	defaultPerPage: 20,
	locale: 'en-US',
	maxPerPage: 100,
	nonce: 'test-nonce',
	restUrl: 'https://example.test/wp-json/',
	settingsUrl:
		'https://example.test/wp-admin/options-general.php?page=flavor-agent',
	timeZone: 'UTC',
};
const ACTIVITY_LOG_CSS = fs.readFileSync(
	path.join( __dirname, '../activity-log.css' ),
	'utf8'
);

function createEntry( overrides = {} ) {
	return {
		id: 'activity-1',
		suggestion: 'Refresh intro copy',
		status: 'applied',
		surface: 'block',
		target: {
			blockName: 'core/paragraph',
		},
		document: {
			scopeKey: 'post:42',
			postType: 'post',
			entityId: '42',
		},
		undo: {
			canUndo: true,
			status: 'available',
		},
		persistence: {
			status: 'server',
		},
		request: {},
		before: {},
		after: {},
		timestamp: '2026-03-27T10:00:00Z',
		...overrides,
	};
}

function buildResponse( entries, overrides = {} ) {
	return {
		entries,
		filterOptions: overrides.filterOptions || null,
		paginationInfo: {
			page: 1,
			perPage: BOOT_DATA.defaultPerPage,
			totalItems: entries.length,
			totalPages: entries.length > 0 ? 1 : 0,
			...( overrides.paginationInfo || {} ),
		},
		summary: {
			total: entries.length,
			applied: entries.length,
			undone: 0,
			review: 0,
			blocked: 0,
			failed: 0,
			...( overrides.summary || {} ),
		},
	};
}

async function flushEffects() {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
}

async function renderApp( response ) {
	if ( response !== undefined ) {
		apiFetch.mockResolvedValue(
			Array.isArray( response ) ? buildResponse( response ) : response
		);
	}

	await act( async () => {
		getRoot().render( <ActivityLogApp bootData={ BOOT_DATA } /> );
	} );

	await flushEffects();
}

function getVisibleTitles() {
	return Array.from(
		getContainer().querySelectorAll( '.mock-dataviews-layout button' )
	).map( ( element ) => element.textContent );
}

function getSummaryCardValue( label ) {
	const summaryCard = Array.from(
		getContainer().querySelectorAll(
			'.flavor-agent-activity-log__summary-item'
		)
	).find( ( element ) => element.textContent.includes( label ) );

	return summaryCard?.querySelector(
		'.flavor-agent-activity-log__summary-value'
	)?.textContent;
}

function getSidebarTitle() {
	return getContainer().querySelector(
		'.flavor-agent-activity-log__sidebar-card .flavor-agent-activity-log__section-title'
	);
}

beforeEach( () => {
	getDataViewsMockState().latestProps = null;
	apiFetch.mockReset();
	mockTranslate.mockClear();
	mockSprintf.mockClear();
	window.localStorage.clear();
} );

describe( 'ActivityLogApp', () => {
	test( 'renders fetched activity entries', async () => {
		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'First activity entry',
			} ),
		] );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				headers: {
					'X-WP-Nonce': BOOT_DATA.nonce,
				},
				url: `${ BOOT_DATA.restUrl }flavor-agent/v1/activity?global=1&page=1&perPage=${ BOOT_DATA.defaultPerPage }&sortField=timestamp&sortDirection=desc`,
			} )
		);
		expect( getVisibleTitles() ).toEqual( [ 'First activity entry' ] );
		expect( getDataViewsMockState().latestProps.view.layout.density ).toBe(
			'comfortable'
		);
		expect( getContainer().textContent ).not.toContain(
			'No matching activity'
		);
	} );

	test( 'renders summary cards from the server response instead of the visible page size', async () => {
		await renderApp(
			buildResponse(
				[
					createEntry( {
						id: 'activity-1',
						suggestion: 'Visible page entry',
					} ),
				],
				{
					summary: {
						total: 9,
						applied: 3,
						undone: 4,
						review: 2,
						blocked: 1,
						failed: 2,
					},
				}
			)
		);

		expect( getSummaryCardValue( 'Recorded actions' ) ).toBe( '9' );
		expect( getSummaryCardValue( 'Still applied' ) ).toBe( '3' );
		expect( getSummaryCardValue( 'Undone' ) ).toBe( '4' );
		expect( getSummaryCardValue( 'Review-only' ) ).toBe( '2' );
		expect( getSummaryCardValue( 'Undo blocked' ) ).toBe( '1' );
		expect( getSummaryCardValue( 'Failed or unavailable' ) ).toBe( '2' );
	} );

	test( 'styles the six summary metrics as one desktop grid row', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/grid-template-columns:\s*repeat\(6,\s*minmax\(0,\s*1fr\)\)/
		);
	} );

	test( 'declares distinct review and blocked activity status styles', () => {
		expect( ACTIVITY_LOG_CSS ).toContain(
			'.flavor-agent-activity-log__status.is-review'
		);
		expect( ACTIVITY_LOG_CSS ).toContain(
			'.flavor-agent-activity-log__icon.is-review'
		);
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/\.flavor-agent-activity-log__icon\.is-blocked\s*\{[^}]*warning/s
		);
	} );

	test( 'clamps stale saved pages back into range before rendering the feed', async () => {
		window.localStorage.setItem(
			VIEW_STORAGE_KEY,
			JSON.stringify( {
				...DEFAULT_ACTIVITY_VIEW,
				page: 5,
			} )
		);

		await renderApp( [
			createEntry( {
				id: 'activity-2',
				suggestion: 'Beta entry',
				timestamp: '2026-03-27T10:00:01Z',
			} ),
			createEntry( {
				id: 'activity-1',
				suggestion: 'Alpha entry',
				timestamp: '2026-03-27T10:00:00Z',
			} ),
		] );
		await flushEffects();

		expect( getVisibleTitles() ).toEqual( [ 'Beta entry', 'Alpha entry' ] );
		expect( getContainer().textContent ).not.toContain(
			'No matching activity'
		);
		expect( readPersistedActivityView( window.localStorage ).page ).toBe(
			1
		);
	} );

	test( 'blocks persisted malformed date filters instead of fetching unfiltered activity', async () => {
		window.localStorage.setItem(
			VIEW_STORAGE_KEY,
			JSON.stringify( {
				...DEFAULT_ACTIVITY_VIEW,
				filters: [
					{
						field: 'day',
						operator: 'between',
						value: [ '2026-03-31', '2026-03-01' ],
					},
				],
			} )
		);

		await renderApp( [ createEntry() ] );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( getContainer().textContent ).toContain(
			'Complete or reset the date filter to load activity.'
		);
		expect( getContainer().textContent ).toContain( 'Reset view' );
	} );

	test( 'keeps the detail sidebar synced to the server-backed visible feed', async () => {
		apiFetch
			.mockResolvedValueOnce(
				buildResponse( [
					createEntry( {
						id: 'activity-2',
						suggestion: 'Beta entry',
						timestamp: '2026-03-27T10:00:01Z',
					} ),
					createEntry( {
						id: 'activity-1',
						suggestion: 'Alpha entry',
						timestamp: '2026-03-27T10:00:00Z',
					} ),
				] )
			)
			.mockResolvedValueOnce(
				buildResponse(
					[
						createEntry( {
							id: 'activity-1',
							suggestion: 'Alpha entry',
							timestamp: '2026-03-27T10:00:00Z',
						} ),
					],
					{
						summary: {
							total: 1,
							applied: 1,
							undone: 0,
							review: 0,
						},
					}
				)
			);

		await renderApp();

		expect( getSidebarTitle().textContent ).toBe( 'Beta entry' );

		await act( async () => {
			getDataViewsMockState().latestProps.onChangeView( {
				...getDataViewsMockState().latestProps.view,
				page: 1,
				search: 'Alpha',
			} );
		} );
		await flushEffects();

		expect( getVisibleTitles() ).toEqual( [ 'Alpha entry' ] );
		expect( getSidebarTitle().textContent ).toBe( 'Alpha entry' );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toBe(
			`${ BOOT_DATA.restUrl }flavor-agent/v1/activity?global=1&page=1&perPage=${ BOOT_DATA.defaultPerPage }&search=Alpha&sortField=timestamp&sortDirection=desc`
		);
	} );

	test( 'uses server-backed filter options instead of only the visible page entries', async () => {
		await renderApp(
			buildResponse( [ createEntry() ], {
				filterOptions: {
					surface: [
						{ value: 'block', label: 'Block' },
						{ value: 'template', label: 'Template' },
					],
					operationType: [
						{ value: 'insert', label: 'Insert' },
						{
							value: 'modify-attributes',
							label: 'Modify attributes',
						},
					],
					postType: [
						{ value: 'post', label: 'post' },
						{ value: 'wp_template', label: 'wp_template' },
					],
					userId: [
						{ value: '11', label: 'User #11' },
						{ value: '7', label: 'User #7' },
					],
					provider: [
						{
							value: 'Azure OpenAI responses',
							label: 'Azure OpenAI responses',
						},
					],
					providerPath: [
						{
							value: 'Azure OpenAI via Settings > Flavor Agent',
							label: 'Azure OpenAI via Settings > Flavor Agent',
						},
					],
					configurationOwner: [
						{
							value: 'Settings > Flavor Agent',
							label: 'Settings > Flavor Agent',
						},
					],
					credentialSource: [
						{
							value: 'Settings > Flavor Agent',
							label: 'Settings > Flavor Agent',
						},
					],
					selectedProvider: [
						{ value: 'Azure OpenAI', label: 'Azure OpenAI' },
					],
				},
			} )
		);

		const fields = getDataViewsMockState().latestProps.fields;

		expect(
			fields.find( ( field ) => field.id === 'surface' ).elements
		).toEqual( [
			{ value: 'block', label: 'Block' },
			{ value: 'template', label: 'Template' },
		] );
		expect(
			fields.find( ( field ) => field.id === 'operationType' ).elements
		).toEqual( [
			{ value: 'insert', label: 'Insert' },
			{ value: 'modify-attributes', label: 'Modify attributes' },
		] );
		expect(
			fields.find( ( field ) => field.id === 'postType' ).elements
		).toEqual( [
			{ value: 'post', label: 'post' },
			{ value: 'wp_template', label: 'wp_template' },
		] );
		expect(
			fields.find( ( field ) => field.id === 'userId' ).elements
		).toEqual( [
			{ value: '11', label: 'User #11' },
			{ value: '7', label: 'User #7' },
		] );
		expect(
			fields.find( ( field ) => field.id === 'provider' ).elements
		).toEqual( [
			{
				value: 'Azure OpenAI responses',
				label: 'Azure OpenAI responses',
			},
		] );
		expect(
			fields.find( ( field ) => field.id === 'configurationOwner' )
				.elements
		).toEqual( [
			{
				value: 'Settings > Flavor Agent',
				label: 'Settings > Flavor Agent',
			},
		] );
	} );

	test( 'deduplicates duplicate server operation filter options by value', async () => {
		await renderApp(
			buildResponse( [ createEntry() ], {
				filterOptions: {
					operationType: [
						{ value: 'insert', label: 'Insert' },
						{ value: 'insert', label: 'Insert pattern' },
						{ value: 'replace', label: 'Replace' },
						{ value: 'replace', label: 'Assign template part' },
					],
				},
			} )
		);

		const fields = getDataViewsMockState().latestProps.fields;

		expect(
			fields.find( ( field ) => field.id === 'operationType' ).elements
		).toEqual( [
			{ value: 'insert', label: 'Insert' },
			{ value: 'replace', label: 'Replace' },
		] );
	} );

	test( 'renders the global masthead actions', async () => {
		await renderApp( [ createEntry() ] );

		const settingsLink = Array.from(
			getContainer().querySelectorAll( 'a' )
		).find(
			( element ) => element.textContent === 'Flavor Agent settings'
		);
		const connectorsLink = Array.from(
			getContainer().querySelectorAll( 'a' )
		).find( ( element ) => element.textContent === 'Connectors' );

		expect( settingsLink ).not.toBeNull();
		expect( settingsLink.getAttribute( 'href' ) ).toBe(
			BOOT_DATA.settingsUrl
		);
		expect( connectorsLink ).not.toBeNull();
		expect( connectorsLink.getAttribute( 'href' ) ).toBe(
			BOOT_DATA.connectorsUrl
		);
	} );

	test( 'localizes the interactive page chrome and feed labels', async () => {
		await renderApp( [ createEntry() ] );

		expect( mockTranslate ).toHaveBeenCalledWith(
			'AI Activity Log',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Search AI activity',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Refresh',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Status',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Failed or unavailable',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith( 'Block', 'flavor-agent' );
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Open post',
			'flavor-agent'
		);
	} );

	test( 'uses row selection instead of per-entry feed actions', async () => {
		await renderApp( [ createEntry() ] );

		expect( getDataViewsMockState().latestProps.actions ).toBeUndefined();

		const targetLink = Array.from(
			getContainer().querySelectorAll( 'a' )
		).find( ( element ) => element.textContent === 'Open post' );

		expect( targetLink ).toBeDefined();
		expect( targetLink.getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-admin/post.php?post=42&action=edit'
		);
	} );

	test( 'registers provenance, action, post type, entity id, block path, and date filters for the feed', async () => {
		await renderApp( [ createEntry() ] );

		const fields = getDataViewsMockState().latestProps.fields;
		const actionTypeField = fields.find(
			( field ) => field.id === 'operationType'
		);
		const dateField = fields.find( ( field ) => field.id === 'day' );
		const postTypeField = fields.find(
			( field ) => field.id === 'postType'
		);
		const entityIdField = fields.find(
			( field ) => field.id === 'entityId'
		);
		const blockPathField = fields.find(
			( field ) => field.id === 'blockPath'
		);
		const providerField = fields.find(
			( field ) => field.id === 'provider'
		);
		const providerPathField = fields.find(
			( field ) => field.id === 'providerPath'
		);
		const configurationOwnerField = fields.find(
			( field ) => field.id === 'configurationOwner'
		);
		const credentialSourceField = fields.find(
			( field ) => field.id === 'credentialSource'
		);
		const selectedProviderField = fields.find(
			( field ) => field.id === 'selectedProvider'
		);

		expect( getDataViewsMockState().latestProps.view.fields ).toEqual( [
			'timestampDisplay',
			'status',
			'surface',
		] );
		expect( actionTypeField.filterBy.operators ).toEqual( [
			'is',
			'isNot',
		] );
		expect( providerField.enableSorting ).toBe( true );
		expect( providerField.filterBy.operators ).toEqual( [ 'is', 'isNot' ] );
		expect( providerPathField.enableSorting ).toBe( true );
		expect( providerPathField.filterBy.operators ).toEqual( [
			'is',
			'isNot',
		] );
		expect( configurationOwnerField.enableSorting ).toBe( true );
		expect( configurationOwnerField.filterBy.operators ).toEqual( [
			'is',
			'isNot',
		] );
		expect( credentialSourceField.enableSorting ).toBe( true );
		expect( credentialSourceField.filterBy.operators ).toEqual( [
			'is',
			'isNot',
		] );
		expect( selectedProviderField.enableSorting ).toBe( true );
		expect( selectedProviderField.filterBy.operators ).toEqual( [
			'is',
			'isNot',
		] );
		expect( postTypeField.filterBy.operators ).toEqual( [ 'is', 'isNot' ] );
		expect( entityIdField.filterBy.operators ).toEqual( [
			'contains',
			'notContains',
			'startsWith',
		] );
		expect( blockPathField.filterBy.operators ).toEqual( [
			'contains',
			'notContains',
			'startsWith',
		] );
		expect( dateField.filterBy.operators ).toEqual( [
			'on',
			'before',
			'after',
			'between',
			'inThePast',
			'over',
		] );
	} );

	test( 'labels the failed status filter for both request and undo failures', async () => {
		await renderApp( [ createEntry() ] );

		const statusField = getDataViewsMockState().latestProps.fields.find(
			( field ) => field.id === 'status'
		);

		expect(
			statusField.elements.find(
				( element ) => element.value === 'failed'
			).label
		).toBe( 'Failed or unavailable' );
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Failed or unavailable',
			'flavor-agent'
		);
	} );

	test( 'adds provenance filters to the activity request URL', async () => {
		await renderApp( [ createEntry() ] );

		await act( async () => {
			getDataViewsMockState().latestProps.onChangeView( {
				...getDataViewsMockState().latestProps.view,
				filters: [
					{
						field: 'provider',
						operator: 'is',
						value: 'WordPress AI Client',
					},
					{
						field: 'configurationOwner',
						operator: 'is',
						value: 'Settings > Connectors',
					},
				],
			} );
		} );
		await flushEffects();

		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toContain(
			'provider=WordPress+AI+Client'
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toContain(
			'providerOperator=is'
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toContain(
			'configurationOwner=Settings+%3E+Connectors'
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toContain(
			'configurationOwnerOperator=is'
		);
	} );

	test( 'adds day filters to the activity request URL', async () => {
		await renderApp( [ createEntry() ] );

		await act( async () => {
			getDataViewsMockState().latestProps.onChangeView( {
				...getDataViewsMockState().latestProps.view,
				filters: [
					{
						field: 'day',
						operator: 'between',
						value: [ '2026-03-01', '2026-03-31' ],
					},
				],
			} );
		} );
		await flushEffects();

		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toContain(
			'dayOperator=between'
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toContain(
			'day=2026-03-01'
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toContain(
			'dayEnd=2026-03-31'
		);

		await act( async () => {
			getDataViewsMockState().latestProps.onChangeView( {
				...getDataViewsMockState().latestProps.view,
				filters: [
					{
						field: 'day',
						operator: 'inThePast',
						value: {
							value: 7,
							unit: 'days',
						},
					},
				],
			} );
		} );
		await flushEffects();

		expect( apiFetch.mock.calls[ 2 ][ 0 ].url ).toContain(
			'dayOperator=inThePast'
		);
		expect( apiFetch.mock.calls[ 2 ][ 0 ].url ).toContain(
			'dayRelativeValue=7'
		);
		expect( apiFetch.mock.calls[ 2 ][ 0 ].url ).toContain(
			'dayRelativeUnit=days'
		);

		await act( async () => {
			getDataViewsMockState().latestProps.onChangeView( {
				...getDataViewsMockState().latestProps.view,
				filters: [
					{
						field: 'day',
						operator: 'between',
						value: [ '2026-03-01' ],
					},
				],
			} );
		} );
		await flushEffects();

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( getContainer().textContent ).toContain(
			'Complete or reset the date filter to load activity.'
		);

		await act( async () => {
			getDataViewsMockState().latestProps.onChangeView( {
				...getDataViewsMockState().latestProps.view,
				filters: [
					{
						field: 'day',
						operator: 'between',
						value: [ '2026-03-31', '2026-03-01' ],
					},
				],
			} );
		} );
		await flushEffects();

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( getContainer().textContent ).toContain(
			'Complete or reset the date filter to load activity.'
		);
	} );

	test( 'renders an inline error state instead of the empty activity copy when loading fails', async () => {
		apiFetch.mockRejectedValueOnce(
			new Error( 'Activity fetch failed for this admin session.' )
		);

		await renderApp();

		expect( getContainer().textContent ).toContain(
			'Activity log unavailable'
		);
		expect( getContainer().textContent ).toContain(
			'Activity fetch failed for this admin session.'
		);
		expect( getContainer().textContent ).not.toContain(
			'No AI activity has been recorded yet.'
		);
		expect( getContainer().querySelector( '[role="alert"]' ) ).toBeNull();
	} );

	test( 'retries loading activity from the inline error state', async () => {
		apiFetch
			.mockRejectedValueOnce( new Error( 'Activity fetch failed.' ) )
			.mockResolvedValueOnce(
				buildResponse( [
					createEntry( {
						id: 'activity-2',
						suggestion: 'Recovered activity entry',
					} ),
				] )
			);

		await renderApp();

		const retryButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find(
			( element ) => element.textContent === 'Retry loading activity'
		);

		expect( retryButton ).toBeDefined();

		await act( async () => {
			retryButton.click();
		} );
		await flushEffects();

		expect( getVisibleTitles() ).toEqual( [ 'Recovered activity entry' ] );
		expect( getContainer().textContent ).not.toContain(
			'Activity log unavailable'
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );
} );
