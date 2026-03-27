jest.mock( '@wordpress/api-fetch', () => jest.fn() );

function getDataViewsMockState() {
	return global.__flavorAgentActivityLogDataViewsState;
}

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	function Button( { children, href, onClick, size, variant, ...props } ) {
		void size;
		void variant;

		if ( href ) {
			return createElement(
				'a',
				{
					href,
					onClick,
					...props,
				},
				children
			);
		}

		return createElement(
			'button',
			{
				type: 'button',
				onClick,
				...props,
			},
			children
		);
	}

	return {
		Button,
		Card: ( { children, className = '' } ) =>
			createElement( 'div', { className }, children ),
		CardBody: ( { children, className = '' } ) =>
			createElement(
				'div',
				{
					className: [ 'components-card__body', className ]
						.filter( Boolean )
						.join( ' ' ),
				},
				children
			),
		CardHeader: ( { children, className = '' } ) =>
			createElement(
				'div',
				{
					className: [ 'components-card__header', className ]
						.filter( Boolean )
						.join( ' ' ),
				},
				children
			),
		Icon: ( { icon, ...props } ) => {
			void icon;

			return createElement( 'span', {
				'aria-hidden': 'true',
				'data-icon': 'true',
				...props,
			} );
		},
		Notice: ( { children, status } ) =>
			createElement(
				'div',
				{ 'data-status': status, role: 'alert' },
				children
			),
		Spinner: () => createElement( 'div', null, 'Loading…' ),
	};
} );

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
const { createRoot } = require( '@wordpress/element' );

import apiFetch from '@wordpress/api-fetch';

import {
	DEFAULT_ACTIVITY_VIEW,
	VIEW_STORAGE_KEY,
	readPersistedActivityView,
} from '../activity-log-utils';
import { ActivityLogApp } from '../activity-log';

const BOOT_DATA = {
	adminUrl: 'https://example.test/wp-admin/',
	connectorsUrl: 'https://example.test/wp-admin/options-connectors.php',
	defaultLimit: 100,
	nonce: 'test-nonce',
	restUrl: 'https://example.test/wp-json/',
	settingsUrl:
		'https://example.test/wp-admin/options-general.php?page=flavor-agent',
};

let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

function createEntry( overrides = {} ) {
	return {
		id: 'activity-1',
		suggestion: 'Refresh intro copy',
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

async function flushEffects() {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
}

async function renderApp( entries ) {
	apiFetch.mockResolvedValue( {
		entries,
	} );

	await act( async () => {
		root.render( <ActivityLogApp bootData={ BOOT_DATA } /> );
	} );

	await flushEffects();
}

function getVisibleTitles() {
	return Array.from(
		container.querySelectorAll( '.mock-dataviews-layout button' )
	).map( ( element ) => element.textContent );
}

function getSidebarTitle() {
	return container.querySelector(
		'.flavor-agent-activity-log__sidebar-card .flavor-agent-activity-log__section-title'
	);
}

beforeEach( () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	getDataViewsMockState().latestProps = null;
	apiFetch.mockReset();
	window.localStorage.clear();
} );

afterEach( async () => {
	if ( root ) {
		await act( async () => {
			root.unmount();
		} );
	}

	if ( container ) {
		container.remove();
	}

	container = null;
	root = null;
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
				url: `${ BOOT_DATA.restUrl }flavor-agent/v1/activity?global=1&limit=${ BOOT_DATA.defaultLimit }`,
			} )
		);
		expect( getVisibleTitles() ).toEqual( [ 'First activity entry' ] );
		expect( container.textContent ).not.toContain( 'No matching activity' );
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
				id: 'activity-1',
				suggestion: 'Alpha entry',
				timestamp: '2026-03-27T10:00:00Z',
			} ),
			createEntry( {
				id: 'activity-2',
				suggestion: 'Beta entry',
				timestamp: '2026-03-27T10:00:01Z',
			} ),
		] );
		await flushEffects();

		expect( getVisibleTitles() ).toEqual( [ 'Beta entry', 'Alpha entry' ] );
		expect( container.textContent ).not.toContain( 'No matching activity' );
		expect( readPersistedActivityView( window.localStorage ).page ).toBe(
			1
		);
	} );

	test( 'keeps the detail sidebar synced to the visible feed', async () => {
		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'Alpha entry',
				timestamp: '2026-03-27T10:00:00Z',
			} ),
			createEntry( {
				id: 'activity-2',
				suggestion: 'Beta entry',
				timestamp: '2026-03-27T10:00:01Z',
			} ),
		] );

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
	} );

	test( 'adds an accessible name to the icon-only settings control', async () => {
		await renderApp( [ createEntry() ] );

		const settingsLink = container.querySelector(
			'a[aria-label="Open Flavor Agent settings"]'
		);

		expect( settingsLink ).not.toBeNull();
		expect( settingsLink.getAttribute( 'href' ) ).toBe(
			BOOT_DATA.settingsUrl
		);
	} );

	test( 'keeps target navigation on href-backed links instead of feed actions', async () => {
		await renderApp( [ createEntry() ] );

		expect(
			getDataViewsMockState().latestProps.actions.map(
				( action ) => action.id
			)
		).toEqual( [ 'inspect' ] );

		const targetLink = Array.from( container.querySelectorAll( 'a' ) ).find(
			( element ) => element.textContent === 'Open target'
		);

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

		expect( getDataViewsMockState().latestProps.view.fields ).toEqual(
			expect.arrayContaining( [ 'operationType', 'postType' ] )
		);
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
