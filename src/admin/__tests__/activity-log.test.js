jest.mock( '@wordpress/api-fetch', () => jest.fn() );

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
					},
				}
			)
		);

		expect( getSummaryCardValue( 'Recorded actions' ) ).toBe( '9' );
		expect( getSummaryCardValue( 'Still applied' ) ).toBe( '3' );
		expect( getSummaryCardValue( 'Undone' ) ).toBe( '4' );
		expect( getSummaryCardValue( 'Needs review' ) ).toBe( '2' );
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
						{ value: 'insert', label: 'Insert pattern' },
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
			{ value: 'insert', label: 'Insert pattern' },
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

	test( 'registers action, post type, entity id, block path, and date filters for the feed', async () => {
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

		expect( getDataViewsMockState().latestProps.view.fields ).toEqual( [
			'timestampDisplay',
			'status',
			'surface',
		] );
		expect( actionTypeField.filterBy.operators ).toEqual( [
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
} );
