jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const fs = require( 'fs' );
const path = require( 'path' );

jest.mock( '@wordpress/i18n', () => ( {
	__: jest.fn( ( value ) => value ),
	_n: jest.fn( ( single, plural, count ) =>
		count === 1 ? single : plural
	),
	sprintf: jest.fn( ( template, ...values ) =>
		values.reduce( ( result, value, index ) => {
			return result
				.replaceAll( `%${ index + 1 }$s`, String( value ) )
				.replaceAll( `%${ index + 1 }$d`, String( value ) )
				.replace( '%s', String( value ) )
				.replace( '%d', String( value ) );
		}, template )
	),
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
		const titleField = Array.isArray( props.fields )
			? props.fields.find( ( field ) => field.id === 'title' )
			: null;

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
						'aria-label': item.title,
						onClick: () => props.onClickItem?.( item ),
						type: 'button',
					},
					titleField?.render
						? titleField.render( { item } )
						: item.title
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
import * as i18n from '@wordpress/i18n';

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
	themeColorPresets: [
		{
			name: 'Accent',
			slug: 'accent',
			color: '#0b7b80',
		},
		{
			name: 'Contrast',
			slug: 'contrast',
			color: '#17232a',
		},
	],
	timeZone: 'UTC',
	canApproveStyleApplies: true,
	currentUserId: 7,
};
const ACTIVITY_LOG_CSS = fs.readFileSync(
	path.join( __dirname, '../activity-log.css' ),
	'utf8'
);
const ACTIVITY_LOG_JS = fs.readFileSync(
	path.join( __dirname, '../activity-log.js' ),
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

function createExternalApplyEntry( overrides = {} ) {
	return createEntry( {
		id: 'activity-external-apply',
		type: 'apply_global_styles_suggestion',
		suggestion: 'External: use the accent text preset',
		status: 'pending',
		statusLabel: 'Pending approval',
		surface: 'global-styles',
		target: {
			globalStylesId: '17',
		},
		document: {
			scopeKey: 'global_styles:17',
			postType: 'global_styles',
			entityId: '17',
		},
		undo: {
			status: 'not_applicable',
			canUndo: false,
		},
		apply: {
			status: 'pending',
			requestedBy: 7,
			requestedAt: '2026-06-10T01:00:00+00:00',
			expiresAt: '2026-06-11T01:00:00+00:00',
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'text' ],
					value: 'var:preset|color|accent',
					presetSlug: 'accent',
				},
			],
			signatures: {
				resolvedContextSignature: 'r'.repeat( 64 ),
				reviewContextSignature: 'v'.repeat( 64 ),
				baselineConfigHash: 'b'.repeat( 64 ),
			},
			requestReference: 'agent-req-1',
		},
		...overrides,
	} );
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
		learningReport: overrides.learningReport || null,
	};
}

function createDeferred() {
	let resolvePromise;
	let rejectPromise;

	const promise = new Promise( ( resolve, reject ) => {
		resolvePromise = resolve;
		rejectPromise = reject;
	} );

	return {
		promise,
		resolve: resolvePromise,
		reject: rejectPromise,
	};
}

async function flushEffects() {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
}

async function renderApp( response, { bootData } = {} ) {
	if ( response !== undefined ) {
		apiFetch.mockResolvedValue(
			Array.isArray( response ) ? buildResponse( response ) : response
		);
	}

	await act( async () => {
		getRoot().render(
			<ActivityLogApp bootData={ { ...BOOT_DATA, ...bootData } } />
		);
	} );

	await flushEffects();
}

function getVisibleTitles() {
	return Array.from(
		getContainer().querySelectorAll( '.mock-dataviews-layout button' )
	).map( ( element ) => element.getAttribute( 'aria-label' ) );
}

function getRowButtonByTitle( title ) {
	return Array.from(
		getContainer().querySelectorAll( '.mock-dataviews-layout button' )
	).find( ( button ) => button.getAttribute( 'aria-label' ) === title );
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

function getDetailSectionByLabel( label ) {
	return Array.from(
		getContainer().querySelectorAll(
			'.flavor-agent-activity-log__detail-section'
		)
	).find( ( section ) => {
		return (
			section.querySelector(
				'.flavor-agent-activity-log__detail-summary-label'
			)?.textContent === label
		);
	} );
}

function getDefinitionValue( scope, label ) {
	const term = Array.from( scope.querySelectorAll( 'dt' ) ).find(
		( node ) => node.textContent === label
	);

	return term?.nextElementSibling?.textContent || '';
}

function getVisualDiffViewer() {
	return getContainer().querySelector(
		'.flavor-agent-activity-log__visual-diff'
	);
}

function getVisualDiffRow( label ) {
	return Array.from(
		getContainer().querySelectorAll(
			'.flavor-agent-activity-log__visual-diff-row'
		)
	).find(
		( row ) =>
			row.querySelector(
				'.flavor-agent-activity-log__visual-diff-row-title'
			)?.textContent === label
	);
}

beforeEach( () => {
	getDataViewsMockState().latestProps = null;
	apiFetch.mockReset();
	i18n.__.mockClear();
	i18n.sprintf.mockClear();
	window.localStorage.clear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/options-general.php?page=flavor-agent-activity'
	);
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
				url: `${ BOOT_DATA.restUrl }flavor-agent/v1/activity?global=1&includeReports=1&page=1&perPage=${ BOOT_DATA.defaultPerPage }&sortField=timestamp&sortDirection=desc`,
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

	test( 'selects the linked activity entry from the URL', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-2'
		);

		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'First activity entry',
			} ),
			createEntry( {
				id: 'activity-2',
				suggestion: 'Linked activity entry',
			} ),
		] );

		expect( getSidebarTitle().textContent ).toBe( 'Linked activity entry' );
	} );

	test( 'renders a linked-row banner and selected-row action strip', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-2'
		);

		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'First activity entry',
			} ),
			createEntry( {
				id: 'activity-2',
				suggestion: 'Linked activity entry',
				userId: 11,
				target: {
					blockName: 'core/paragraph',
					blockPath: [ 0 ],
				},
				document: {
					scopeKey: 'post:42',
					postType: 'post',
					entityId: '42',
				},
			} ),
		] );

		const banner = getContainer().querySelector(
			'.flavor-agent-activity-log__linked-row-banner'
		);
		const actionStrip = getContainer().querySelector(
			'.flavor-agent-activity-log__action-strip'
		);
		const targetLink = Array.from(
			actionStrip.querySelectorAll( 'a' )
		).find( ( link ) => link.textContent.includes( 'Open target' ) );
		const focusedLink = Array.from(
			actionStrip.querySelectorAll( 'a' )
		).find( ( link ) => link.textContent === 'Open focused view' );
		const actionButtons = Array.from(
			actionStrip.querySelectorAll( 'button' )
		).map( ( button ) => button.textContent );

		expect( banner ).not.toBeNull();
		expect( banner.getAttribute( 'role' ) ).toBe( 'status' );
		expect( banner.textContent ).toContain( 'Focused row' );
		expect( banner.textContent ).toContain( 'Linked activity entry' );
		expect( banner.textContent ).toContain( 'Clear focused row' );
		expect( actionStrip ).not.toBeNull();
		expect( actionStrip.getAttribute( 'aria-label' ) ).toBe(
			'Selected row actions'
		);
		expect( targetLink.getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-admin/post.php?post=42&action=edit'
		);
		expect( targetLink.textContent ).toContain( 'Open post' );
		expect( focusedLink.getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-2'
		);
		expect( actionButtons ).toEqual(
			expect.arrayContaining( [
				'Same surface',
				'Same user',
				'Same entity',
				'Same block path',
			] )
		);
	} );

	test( 'clears focused-row mode before loading a selected-row pivot', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-2'
		);

		await renderApp( [
			createEntry( {
				id: 'activity-2',
				suggestion: 'Linked activity entry',
				userId: 11,
				target: {
					blockName: 'core/paragraph',
					blockPath: [ 0 ],
				},
				document: {
					scopeKey: 'post:42',
					postType: 'post',
					entityId: '42',
				},
			} ),
		] );

		const sameEntityButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Same entity' );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toContain(
			'activity=activity-2'
		);
		expect( sameEntityButton ).toBeDefined();

		await act( async () => {
			sameEntityButton.click();
		} );
		await flushEffects();

		const nextUrl = apiFetch.mock.calls[ 1 ][ 0 ].url;
		expect( nextUrl ).toContain( 'entityId=42' );
		expect( nextUrl ).toContain( 'entityIdOperator=contains' );
		expect( nextUrl ).not.toContain( 'activity=activity-2' );
		expect( nextUrl ).toContain( 'page=1' );
		expect( window.location.search ).toBe( '?page=flavor-agent-activity' );
	} );

	test( 'selected-row pivots preserve unrelated filters and replace their own filter', async () => {
		window.localStorage.setItem(
			VIEW_STORAGE_KEY,
			JSON.stringify( {
				...DEFAULT_ACTIVITY_VIEW,
				page: 1,
				filters: [
					{
						field: 'provider',
						operator: 'is',
						value: 'WordPress AI Client',
					},
					{
						field: 'entityId',
						operator: 'contains',
						value: '99',
					},
				],
			} )
		);

		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'Filter pivot row',
				document: {
					scopeKey: 'post:42',
					postType: 'post',
					entityId: '42',
				},
			} ),
		] );

		const sameEntityButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Same entity' );

		expect( sameEntityButton ).toBeDefined();

		await act( async () => {
			sameEntityButton.click();
		} );
		await flushEffects();

		const nextUrl = apiFetch.mock.calls[ 1 ][ 0 ].url;
		expect( nextUrl ).toContain( 'provider=WordPress+AI+Client' );
		expect( nextUrl ).toContain( 'providerOperator=is' );
		expect( nextUrl ).toContain( 'entityId=42' );
		expect( nextUrl ).toContain( 'entityIdOperator=contains' );
		expect( nextUrl ).not.toContain( 'entityId=99' );
		expect( nextUrl ).toContain( 'page=1' );
	} );

	test( 'clears the linked-row banner without resetting the selected row', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-2'
		);

		await renderApp( [
			createEntry( {
				id: 'activity-2',
				suggestion: 'Linked activity entry',
			} ),
		] );

		const clearButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Clear focused row' );

		expect( clearButton ).toBeDefined();
		expect(
			getContainer().querySelector(
				'.flavor-agent-activity-log__linked-row-banner'
			)
		).not.toBeNull();

		await act( async () => {
			clearButton.click();
		} );
		await flushEffects();

		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).not.toContain(
			'activity=activity-2'
		);
		expect(
			getContainer().querySelector(
				'.flavor-agent-activity-log__linked-row-banner'
			)
		).toBeNull();
		expect( getSidebarTitle().textContent ).toBe( 'Linked activity entry' );
		expect( window.location.search ).toBe( '?page=flavor-agent-activity' );
	} );

	test( 'drops a stale focused-row query param after the response no longer contains that row', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=missing-activity'
		);

		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'First activity entry',
			} ),
		] );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toContain(
			'activity=missing-activity'
		);
		expect(
			getContainer().querySelector(
				'.flavor-agent-activity-log__linked-row-banner'
			)
		).toBeNull();
		expect( getSidebarTitle().textContent ).toBe( 'First activity entry' );
		expect( window.location.search ).toBe( '?page=flavor-agent-activity' );
	} );

	test( 'bypasses the persisted saved view when loading a linked activity from the URL', async () => {
		window.localStorage.setItem(
			VIEW_STORAGE_KEY,
			JSON.stringify( {
				...DEFAULT_ACTIVITY_VIEW,
				page: 3,
				search: 'Alpha only',
				filters: [
					{
						field: 'status',
						operator: 'is',
						value: 'pending',
					},
				],
			} )
		);
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-2'
		);

		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'Filtered entry',
			} ),
			createEntry( {
				id: 'activity-2',
				suggestion: 'Linked activity entry',
			} ),
		] );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe(
			`${ BOOT_DATA.restUrl }flavor-agent/v1/activity?global=1&includeReports=1&page=1&perPage=${ BOOT_DATA.defaultPerPage }&sortField=timestamp&sortDirection=desc&activity=activity-2`
		);
		expect( getSidebarTitle().textContent ).toBe( 'Linked activity entry' );
		expect(
			readPersistedActivityView( window.localStorage )
		).toMatchObject( {
			page: 3,
			search: 'Alpha only',
			filters: [
				{
					field: 'status',
					operator: 'is',
					value: 'pending',
				},
			],
		} );
	} );

	test( 'exposes selected activity state and labels the details region', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-2'
		);

		await renderApp( [
			createEntry( {
				id: 'activity-1',
				suggestion: 'First activity entry',
			} ),
			createEntry( {
				id: 'activity-2',
				suggestion: 'Linked activity entry',
			} ),
		] );

		const titleField = getDataViewsMockState().latestProps.fields.find(
			( field ) => field.id === 'title'
		);
		const selectedTitle = titleField.render( {
			item: {
				id: 'activity-2',
				title: 'Linked activity entry',
			},
		} );
		const heading = getSidebarTitle();
		const detailsRegion = getContainer().querySelector(
			'.flavor-agent-activity-log__details-region'
		);

		expect( selectedTitle.props[ 'aria-current' ] ).toBe( 'true' );
		expect( selectedTitle.props[ 'aria-controls' ] ).toBe(
			'flavor-agent-activity-log-details'
		);
		expect( heading.id ).toBe( 'flavor-agent-activity-log-details-title' );
		expect( detailsRegion.getAttribute( 'role' ) ).toBe( 'region' );
		expect( detailsRegion.getAttribute( 'aria-labelledby' ) ).toBe(
			'flavor-agent-activity-log-details-title'
		);
	} );

	test( 'renders passive discovery badges in the feed title without click behavior', async () => {
		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-pending',
				suggestion: 'Pending governance row',
			} ),
			createEntry( {
				id: 'activity-request-log',
				suggestion: 'AI request row',
				request: {
					ai: {
						requestLogId: 'request-log-1',
						requestToken: 'request-token-1',
					},
				},
			} ),
			createEntry( {
				id: 'activity-attestation',
				suggestion: 'Attested row',
				attestation: {
					id: 'att_abc123',
				},
			} ),
			createEntry( {
				id: 'activity-ordinary',
				suggestion: 'Ordinary row',
			} ),
		] );

		const badgeTexts = Array.from(
			getContainer().querySelectorAll(
				'.flavor-agent-activity-log__entry-badge'
			)
		).map( ( badge ) => badge.textContent );
		const ordinaryRow = getRowButtonByTitle( 'Ordinary row' );

		expect( badgeTexts ).toEqual(
			expect.arrayContaining( [
				expect.stringContaining( 'Pending approval' ),
				'AI request',
				'Attestation',
			] )
		);
		expect(
			getContainer().querySelectorAll(
				'.flavor-agent-activity-log__entry-badge a, .flavor-agent-activity-log__entry-badge button'
			)
		).toHaveLength( 0 );
		expect( ordinaryRow ).toBeDefined();
		expect(
			ordinaryRow.querySelector(
				'.flavor-agent-activity-log__entry-badge'
			)
		).toBeNull();
	} );

	test( 'summarizes the selected row story before deeper technical sections', async () => {
		await renderApp( [
			createEntry( {
				id: 'activity-story',
				suggestion: 'Rewrite the intro copy',
				userId: 11,
				admin: {
					operationTypeLabel: 'Rewrite copy',
					statusLabel: 'Applied',
					surfaceLabel: 'Editor canvas',
					userLabel: 'Editor Haley',
				},
				request: {
					ai: {
						requestLogId: 'story-log',
						requestToken: 'story-token',
					},
				},
			} ),
		] );

		const story = getContainer().querySelector(
			'.flavor-agent-activity-log__entry-story'
		);
		const detailLabels = Array.from(
			getContainer().querySelectorAll(
				'.flavor-agent-activity-log__detail-summary-label'
			)
		).map( ( node ) => node.textContent );

		expect( story ).not.toBeNull();
		expect( story.textContent ).toContain( 'At a glance' );
		expect( getDefinitionValue( story, 'Current status' ) ).toBe(
			'Applied'
		);
		expect(
			story.querySelector(
				'.flavor-agent-activity-log__status.is-applied'
			)?.textContent
		).toBe( 'Applied' );
		expect( getDefinitionValue( story, 'Action' ) ).toContain(
			'Rewrite copy'
		);
		expect( getDefinitionValue( story, 'Surface / entity' ) ).toContain(
			'Editor canvas'
		);
		expect( getDefinitionValue( story, 'Surface / entity' ) ).toContain(
			'Paragraph block'
		);
		expect( getDefinitionValue( story, 'Requested by' ) ).toBe(
			'Editor Haley'
		);
		expect( getDefinitionValue( story, 'Recorded' ) ).not.toBe( '' );
		expect( getDefinitionValue( story, 'Technical review' ) ).toContain(
			'AI request log available'
		);
		expect( detailLabels.slice( 0, 4 ) ).toEqual( [
			'Overview',
			'Undo state',
			'Provider diagnostics',
			'Request context',
		] );
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
						pending: 5,
						blocked: 1,
						failed: 2,
						rejected: 6,
						expired: 7,
					},
				}
			)
		);

		expect( getSummaryCardValue( 'Recorded actions' ) ).toBe( '9' );
		expect( getSummaryCardValue( 'Still applied' ) ).toBe( '3' );
		expect( getSummaryCardValue( 'Undone' ) ).toBe( '4' );
		expect( getSummaryCardValue( 'Review-only' ) ).toBe( '2' );
		expect( getSummaryCardValue( 'Pending approval' ) ).toBe( '5' );
		expect( getSummaryCardValue( 'Undo blocked' ) ).toBe( '1' );
		expect( getSummaryCardValue( 'Failed or unavailable' ) ).toBe( '2' );
		expect( getSummaryCardValue( 'Rejected' ) ).toBe( '6' );
		expect( getSummaryCardValue( 'Expired' ) ).toBe( '7' );
	} );

	test( 'requests and renders the governance learning report', async () => {
		await renderApp(
			buildResponse(
				[
					createEntry( {
						id: 'activity-1',
						suggestion: 'Representative activity entry',
					} ),
				],
				{
					learningReport: {
						version: 'governance-learning-report-v1',
						generatedAt: '2026-06-20T00:00:00+00:00',
						sampleSize: 125,
						rowLimit: 500,
						truncated: true,
						summary: {
							shownCount: 12,
							reviewSelectionRate: 0.25,
							applyConversionRate: 0.1667,
							undoRate: 0.08,
							validationBlockedRate: 0.1,
							insertFailedRate: 0.03,
						},
						groups: {
							surfaces: [
								{
									key: 'block',
									label: 'Block',
									sampleSize: 8,
									shownCount: 5,
									selectedForReviewCount: 2,
									appliedCount: 1,
									undoneCount: 0,
									validationBlockedCount: 1,
									insertFailedCount: 0,
									reviewSelectionRate: 0.4,
									applyConversionRate: 0.2,
									undoRate: 0,
									validationBlockedRate: 0.2,
									insertFailedRate: 0,
									representativeActivityId: 'activity-1',
								},
							],
							operationTypes: [],
							providerModels: [
								{
									key: 'openai/gpt-5-mini',
									label: 'Openai Gpt 5 Mini',
									sampleSize: 6,
									shownCount: 3,
									selectedForReviewCount: 1,
									appliedCount: 1,
									undoneCount: 0,
									validationBlockedCount: 0,
									insertFailedCount: 0,
									reviewSelectionRate: 0.3333,
									applyConversionRate: 0.1667,
									undoRate: 0,
									validationBlockedRate: 0,
									insertFailedRate: 0,
									representativeActivityId: 'activity-1',
								},
							],
							validationReasons: [],
							guidelineVersions: [],
							rankingSignals: [],
							patternTraits: [],
						},
					},
				}
			)
		);

		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toContain(
			'includeReports=1'
		);

		const report = getContainer().querySelector(
			'.flavor-agent-activity-log__learning-report'
		);
		expect( report ).not.toBeNull();
		expect( report.textContent ).toContain( 'Governance learning report' );
		expect( report.textContent ).toContain(
			'Recent sample: 125 of 500 rows'
		);
		expect( report.textContent ).toContain(
			'Truncated to newest matching rows'
		);
		expect( report.textContent ).toContain( 'Review selection25%' );
		expect( report.textContent ).toContain( 'Apply conversion16.7%' );
		expect( report.textContent ).toContain( 'Undo rate8%' );
		expect( report.textContent ).toContain( 'Validation blocked10%' );
		expect( report.textContent ).toContain( 'Insert failed3%' );
		expect( report.textContent ).toContain( 'Surfaces' );
		expect( report.textContent ).toContain( 'Block' );
		expect( report.textContent ).toContain( 'Provider and model' );
		expect( report.textContent ).toContain( 'Openai Gpt 5 Mini' );

		expect(
			report.querySelector(
				'a[href="https://example.test/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-1"]'
			)
		).not.toBeNull();
	} );

	test( 'renders shared normalized governance learning report sections without local regrouping drift', async () => {
		await renderApp(
			buildResponse(
				[
					createEntry( {
						id: 'activity-1',
						suggestion: 'Representative activity entry',
					} ),
				],
				{
					learningReport: {
						version: 'governance-learning-report-v1',
						generatedAt: '2026-06-20T00:00:00+00:00',
						sampleSize: 125,
						rowLimit: 500,
						truncated: false,
						summary: {
							shownCount: 12,
							reviewSelectionRate: 0.25,
							applyConversionRate: 0.1667,
							undoRate: 0.08,
							validationBlockedRate: 0.1,
							insertFailedRate: 0.03,
						},
						groups: {
							surfaces: [],
							operationTypes: Array.from(
								{ length: 7 },
								( _, index ) => ( {
									key: `operation-${ index + 1 }`,
									label: `Operation ${ index + 1 }`,
									sampleSize: index + 1,
									shownCount: index + 1,
									selectedForReviewCount: index,
									appliedCount: index,
									undoneCount: 0,
									validationBlockedCount: 0,
									insertFailedCount: 0,
									reviewSelectionRate: 0.2,
									applyConversionRate: 0.1,
									undoRate: 0,
									validationBlockedRate: 0,
									insertFailedRate: 0,
									representativeActivityId: 'activity-1',
								} )
							),
							providerModels: [],
							validationReasons: [],
							guidelineVersions: [],
							rankingSignals: [],
							patternTraits: [],
						},
					},
				}
			)
		);

		const report = getContainer().querySelector(
			'.flavor-agent-activity-log__learning-report'
		);
		expect( report ).not.toBeNull();
		expect( report.textContent ).toContain( 'Action types' );
		expect( report.textContent ).not.toContain( 'Operation types' );
		expect( report.textContent ).toContain( 'Operation 6' );
		expect( report.textContent ).not.toContain( 'Operation 7' );
		expect(
			report.querySelectorAll(
				'.flavor-agent-activity-log__learning-report-row'
			)
		).toHaveLength( 6 );
	} );

	test( 'styles summary metrics as an auto-fit frosted card grid', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/\.flavor-agent-activity-log__summary\s*\{[^}]*grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(\s*\d+px\s*,\s*1fr\)\)/s
		);
	} );

	test( 'renders app-owned labels for DataViews toolbar controls', async () => {
		await renderApp( [ createEntry( { id: 'activity-1' } ) ] );
		expect( getContainer().textContent ).toContain( 'Filter' );
		expect( getContainer().textContent ).toContain( 'View options' );
		expect( ACTIVITY_LOG_CSS ).toContain(
			'.flavor-agent-activity-log__toolbar-control-label'
		);
		expect( ACTIVITY_LOG_CSS ).not.toMatch(
			/content:\s*attr\(aria-label\)/
		);
	} );

	test( 'keeps sidebar detail styles scoped to app-owned markup', () => {
		expect( ACTIVITY_LOG_CSS ).toContain(
			'.flavor-agent-activity-log__detail-section'
		);
		expect( ACTIVITY_LOG_CSS ).not.toContain(
			'dataviews-settings-section'
		);
	} );

	test( 'constrains code detail blobs inside the sidebar panel', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/\.flavor-agent-activity-log__detail-value--code\s+\.flavor-agent-activity-log__code\s*\{[^}]*max-width:\s*100%;[^}]*overflow:\s*auto;[^}]*white-space:\s*pre-wrap;[^}]*overflow-wrap:\s*anywhere;/s
		);
	} );

	test( 'caps cryptographic record details so approval controls stay reachable', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/\.flavor-agent-activity-log__record-body\s*\{[^}]*max-height:\s*min\(40vh,\s*22rem\);[^}]*overflow:\s*auto;/s
		);
	} );

	test( 'marks the selected table row with a non-color current state', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/\.flavor-agent-activity-log\s+\.dataviews-view-table\s+tr:has\(\s*\.flavor-agent-activity-log__entry-title\.is-current\s*\)\s+td\s*\{[^}]*background:\s*var\(--flavor-agent-activity-log-accent-soft\);[^}]*box-shadow:\s*inset\s+0\s+0\s+0\s+1px\s+var\(--flavor-agent-activity-log-border-strong\);/s
		);
	} );

	test( 'uses the strong accent for selected activity titles on tinted rows', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/\.flavor-agent-activity-log__entry-title\.is-current\s*\{[^}]*color:\s*var\(--flavor-agent-activity-log-accent-strong\);/s
		);
	} );

	test( 'keeps activity log primary button foreground on the WPDS brand token', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/\.flavor-agent-activity-log\s+\.components-button\.is-primary\s*\{[^}]*color:\s*var\(--wpds-color-fg-content-on-brand,\s*#fff\);/s
		);
	} );

	test( 'does not retain the unused error-state class on the error card', () => {
		expect( ACTIVITY_LOG_JS ).not.toContain(
			'flavor-agent-activity-log__error-state'
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

	test( 'provides forced-colors focus outlines for activity log controls', () => {
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/@media\s*\(forced-colors:\s*active\)\s*\{[\s\S]*\.flavor-agent-activity-log\s+\.dataviews-filters__summary-chip-container\s+button:focus-visible[\s\S]*box-shadow:\s*none;[\s\S]*outline:\s*2px\s+solid\s+Highlight;[\s\S]*outline-offset:\s*2px;/s
		);
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/@media\s*\(forced-colors:\s*active\)\s*\{[\s\S]*\.flavor-agent-activity-log__detail-summary:focus-visible[\s\S]*box-shadow:\s*none;[\s\S]*outline:\s*2px\s+solid\s+Highlight;[\s\S]*outline-offset:\s*2px;/s
		);
		expect( ACTIVITY_LOG_CSS ).toMatch(
			/@media\s*\(forced-colors:\s*active\)\s*\{[\s\S]*\.flavor-agent-activity-log\s+\.components-button:focus-visible:not\(:disabled\)[\s\S]*box-shadow:\s*none;[\s\S]*outline:\s*2px\s+solid\s+Highlight;[\s\S]*outline-offset:\s*2px;/s
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
		expect( getContainer().textContent ).toContain( 'Reset date filter' );
		expect( getContainer().textContent ).not.toContain(
			'Retry loading activity'
		);
		expect( getContainer().textContent ).toContain( 'Reset view' );
	} );

	test( 'clears invalid date filters from the error action before fetching activity', async () => {
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
		apiFetch.mockResolvedValue(
			buildResponse( [
				createEntry( {
					id: 'activity-recovered',
					suggestion: 'Recovered from date filter',
				} ),
			] )
		);

		await renderApp();

		const resetButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Reset date filter' );

		expect( resetButton ).toBeDefined();
		expect( apiFetch ).not.toHaveBeenCalled();

		await act( async () => {
			resetButton.click();
		} );
		await flushEffects();

		expect( getVisibleTitles() ).toEqual( [
			'Recovered from date filter',
		] );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).not.toContain(
			'dayOperator'
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
			`${ BOOT_DATA.restUrl }flavor-agent/v1/activity?global=1&includeReports=1&page=1&perPage=${ BOOT_DATA.defaultPerPage }&search=Alpha&sortField=timestamp&sortDirection=desc`
		);
	} );

	test( 'renders the detail sidebar as semantic <dl> > <dt>/<dd> fields', async () => {
		await renderApp( [
			createEntry( { suggestion: 'Audit details row' } ),
		] );

		const detailGrid = getContainer().querySelector(
			'.flavor-agent-activity-log__detail-grid'
		);
		const topLevelDetails = detailGrid
			? Array.from( detailGrid.children )
			: [];

		expect( detailGrid ).not.toBeNull();
		expect( topLevelDetails.length ).toBeGreaterThan( 0 );
		expect(
			topLevelDetails.every(
				( node ) => node.tagName === 'DT' || node.tagName === 'DD'
			)
		).toBe( true );
	} );

	test( 'preserves user-toggled detail sections across rerenders', async () => {
		await renderApp( [
			createEntry( { suggestion: 'Audit details row' } ),
		] );

		const overviewSection = getDetailSectionByLabel( 'Overview' );
		const diagnosticsSection = getDetailSectionByLabel(
			'Provider diagnostics'
		);

		expect( overviewSection.open ).toBe( true );
		expect( diagnosticsSection.open ).toBe( false );

		act( () => {
			overviewSection.querySelector( 'summary' ).click();
			diagnosticsSection.querySelector( 'summary' ).click();
		} );

		expect( overviewSection.open ).toBe( false );
		expect( diagnosticsSection.open ).toBe( true );

		await act( async () => {
			getRoot().render( <ActivityLogApp bootData={ BOOT_DATA } /> );
		} );

		expect( getDetailSectionByLabel( 'Overview' ).open ).toBe( false );
		expect( getDetailSectionByLabel( 'Provider diagnostics' ).open ).toBe(
			true
		);
	} );

	test( 'hides empty code rows and shows populated code rows as pre blocks', async () => {
		await renderApp( [
			createEntry( {
				id: 'activity-code-empty',
				suggestion: 'Code detail check',
				surfaceLabel: 'block',
				statusLabel: 'Applied',
				operationTypeLabel: 'Insert',
				timestampDisplay: '2026-03-27T10:00:00Z',
				activityTypeLabel: 'Pattern',
				entity: 'core/paragraph',
				postType: 'post',
				entityId: '42',
				documentLabel: 'Post',
				documentScopeKey: 'post:42',
				blockPath: '0',
				user: 'admin',
				request: {
					ability: 'Insert',
					route: '/wp/v2/posts',
					reference: 'rest::insert',
				},
				transportError: '',
			} ),
			createEntry( {
				id: 'activity-code-populated',
				suggestion: 'Code detail populated check',
				surfaceLabel: 'block',
				statusLabel: 'Applied',
				operationTypeLabel: 'Insert',
				timestampDisplay: '2026-03-27T10:00:00Z',
				activityTypeLabel: 'Pattern',
				entity: 'core/paragraph',
				postType: 'post',
				entityId: '42',
				documentLabel: 'Post',
				documentScopeKey: 'post:42',
				blockPath: '0',
				user: 'admin',
				request: {
					ability: 'Insert',
					route: '/wp/v2/posts',
					reference: 'rest::insert',
					prompt: '{\n  "prompt": "Use concise copy"\n}',
				},
			} ),
		] );

		const requestSection = getDetailSectionByLabel( 'Request context' );
		expect( requestSection ).not.toBeNull();
		const requestLabels = requestSection
			? Array.from(
					requestSection.querySelectorAll(
						'.flavor-agent-activity-log__detail-grid dt'
					)
			  ).map( ( node ) => node.textContent )
			: [];
		expect( requestLabels ).toEqual(
			expect.arrayContaining( [ 'Ability', 'Route', 'Reference' ] )
		);
		expect( requestLabels ).not.toContain( 'Prompt' );
		expect(
			requestSection.querySelector(
				'.flavor-agent-activity-log__detail-value--code'
			)
		).toBeNull();
		expect( getDetailSectionByLabel( 'State snapshots' ) ).toBeUndefined();

		const populatedButton = getRowButtonByTitle(
			'Code detail populated check'
		);
		expect( populatedButton ).toBeTruthy();

		await act( async () => {
			populatedButton.click();
		} );

		const populatedRequestSection =
			getDetailSectionByLabel( 'Request context' );
		expect( populatedRequestSection ).not.toBeNull();
		const populatedRequestLabels = populatedRequestSection
			? Array.from(
					populatedRequestSection.querySelectorAll(
						'.flavor-agent-activity-log__detail-grid dt'
					)
			  ).map( ( node ) => node.textContent )
			: [];
		const requestCode = populatedRequestSection
			? populatedRequestSection.querySelector(
					'.flavor-agent-activity-log__detail-grid .flavor-agent-activity-log__code'
			  )
			: null;

		expect( populatedRequestLabels ).toContain( 'Prompt' );
		expect( requestCode ).not.toBeNull();
		expect( requestCode?.textContent ).toContain(
			'"prompt": "Use concise copy"'
		);
	} );

	test( 'loads and renders core AI request log details from the selected entry', async () => {
		apiFetch
			.mockResolvedValueOnce(
				buildResponse( [
					createEntry( {
						id: 'activity-with-core-log',
						suggestion: 'Recommendation with core log',
						request: {
							ai: {
								requestLogId:
									'c85ee60d-700b-48a7-b831-5784d5ad32b1',
								requestToken:
									'7a85fe6b-ad73-4c0f-931b-0b0a70bc09c0',
							},
						},
					} ),
				] )
			)
			.mockResolvedValueOnce( {
				id: 'c85ee60d-700b-48a7-b831-5784d5ad32b1',
				provider: 'openai',
				model: 'gpt-5.4-mini',
				duration_ms: 312,
				tokens_input: 40,
				tokens_output: 56,
				tokens_total: 96,
				request_preview: 'Suggest a stronger intro.',
				response_preview: 'Use a clearer opening sentence.',
			} );

		await renderApp();

		const requestLogPanel = getContainer().querySelector(
			'.flavor-agent-activity-log__request-log'
		);
		const viewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'View AI request' );
		expect( requestLogPanel?.textContent ).toContain( 'AI request log' );
		expect( requestLogPanel?.textContent ).toContain(
			'Open the WordPress AI request trace'
		);
		expect( viewButton ).toBeDefined();

		await act( async () => {
			viewButton.click();
		} );
		await flushEffects();

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				headers: {
					'X-WP-Nonce': BOOT_DATA.nonce,
				},
				url: `${ BOOT_DATA.restUrl }ai/v1/logs/c85ee60d-700b-48a7-b831-5784d5ad32b1`,
			} )
		);
		expect( getContainer().textContent ).toContain( 'openai' );
		expect( getContainer().textContent ).toContain( 'gpt-5.4-mini' );
		expect( getContainer().textContent ).toContain( '312 ms' );
		expect( getContainer().textContent ).toContain( '96 total tokens' );
		expect( getContainer().textContent ).toContain(
			'Loaded request details'
		);
		expect( getContainer().textContent ).toContain(
			'Suggest a stronger intro.'
		);
		expect( getContainer().textContent ).toContain(
			'Use a clearer opening sentence.'
		);

		const requestLogsLink = Array.from(
			getContainer().querySelectorAll( 'a' )
		).find( ( link ) => link.textContent === 'Open in AI Request Logs' );
		expect( requestLogsLink ).toBeDefined();
		expect( requestLogsLink.getAttribute( 'href' ) ).toBe(
			`${ BOOT_DATA.adminUrl }tools.php?page=ai-request-logs`
		);
	} );

	test( 'announces AI request log loading while details are being fetched', async () => {
		const requestLogResponse = createDeferred();

		apiFetch.mockImplementation( ( request ) => {
			const url = String( request?.url || '' );

			if ( url.includes( 'flavor-agent/v1/activity?' ) ) {
				return Promise.resolve(
					buildResponse( [
						createEntry( {
							id: 'activity-with-loading-core-log',
							suggestion: 'Recommendation with loading core log',
							request: {
								ai: {
									requestLogId: 'loading-log',
									requestToken: 'loading-token',
								},
							},
						} ),
					] )
				);
			}

			if ( url.endsWith( '/ai/v1/logs/loading-log' ) ) {
				return requestLogResponse.promise;
			}

			return Promise.reject(
				new Error( `Unexpected request: ${ url }` )
			);
		} );

		await renderApp();

		const viewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'View AI request' );

		expect( viewButton ).toBeDefined();

		await act( async () => {
			viewButton.click();
		} );
		await flushEffects();

		const loadingStatus = getContainer().querySelector(
			'.screen-reader-text[role="status"][aria-live="polite"]'
		);

		expect( viewButton?.disabled ).toBe( true );
		expect( viewButton?.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( viewButton?.getAttribute( 'aria-label' ) ).toBe(
			'Loading AI request log.'
		);
		expect( loadingStatus ).not.toBeNull();
		expect( loadingStatus?.textContent ).toBe( 'Loading AI request log.' );

		await act( async () => {
			requestLogResponse.resolve( {
				provider: 'openai',
				model: 'gpt-5.4-mini',
			} );
			await Promise.resolve();
		} );
		await flushEffects();

		expect(
			Array.from(
				getContainer().querySelectorAll(
					'.screen-reader-text[role="status"][aria-live="polite"]'
				)
			).some(
				( element ) => element.textContent === 'Loading AI request log.'
			)
		).toBe( false );
	} );

	test( 'ignores stale core AI request log responses after selecting another row', async () => {
		const staleRequestLog = createDeferred();

		apiFetch.mockImplementation( ( request ) => {
			const url = String( request?.url || '' );

			if ( url.includes( 'flavor-agent/v1/activity?' ) ) {
				return Promise.resolve(
					buildResponse( [
						createEntry( {
							id: 'activity-request-1',
							suggestion: 'First request row',
							request: {
								ai: {
									requestLogId: 'log-1',
									requestToken: 'token-1',
								},
							},
						} ),
						createEntry( {
							id: 'activity-request-2',
							suggestion: 'Second request row',
							request: {
								ai: {
									requestLogId: 'log-2',
									requestToken: 'token-2',
								},
							},
						} ),
					] )
				);
			}

			if ( url.endsWith( '/ai/v1/logs/log-1' ) ) {
				return staleRequestLog.promise;
			}

			if ( url.endsWith( '/ai/v1/logs/log-2' ) ) {
				return Promise.resolve( {
					provider: 'fresh-provider',
					model: 'fresh-model',
				} );
			}

			return Promise.reject(
				new Error( `Unexpected request: ${ url }` )
			);
		} );

		await renderApp();

		const viewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'View AI request' );
		const secondRowButton = getRowButtonByTitle( 'Second request row' );

		expect( viewButton ).toBeDefined();
		expect( secondRowButton ).toBeDefined();

		await act( async () => {
			viewButton.click();
		} );
		await act( async () => {
			secondRowButton.click();
		} );

		await act( async () => {
			staleRequestLog.resolve( {
				provider: 'stale-provider',
				model: 'stale-model',
			} );
			await Promise.resolve();
		} );
		await flushEffects();

		expect( getSidebarTitle().textContent ).toBe( 'Second request row' );
		expect( getContainer().textContent ).not.toContain( 'stale-provider' );
		expect( getContainer().textContent ).not.toContain( 'stale-model' );
	} );

	test( 'announces AI request log failures with an alert notice', async () => {
		apiFetch
			.mockResolvedValueOnce(
				buildResponse( [
					createEntry( {
						id: 'activity-with-failing-core-log',
						suggestion: 'Recommendation with failing core log',
						request: {
							ai: {
								requestLogId: 'broken-log',
								requestToken: 'broken-token',
							},
						},
					} ),
				] )
			)
			.mockRejectedValueOnce(
				new Error( 'Flavor Agent could not load this AI request log.' )
			);

		await renderApp();

		const viewButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'View AI request' );

		expect( viewButton ).toBeDefined();

		await act( async () => {
			viewButton.click();
		} );
		await flushEffects();

		const alert = getContainer().querySelector(
			'.flavor-agent-activity-log__request-log-error[role="alert"]'
		);

		expect( alert ).not.toBeNull();
		expect( alert?.textContent ).toContain(
			'Flavor Agent could not load this AI request log.'
		);
		expect( alert?.getAttribute( 'data-status' ) ).toBe( 'error' );
	} );

	test( 'renders unavailable AI request log copy when a token has no log id', async () => {
		await renderApp( [
			createEntry( {
				id: 'activity-with-token-only',
				suggestion: 'Recommendation without captured core log',
				request: {
					ai: {
						requestToken: 'test-request-token',
						requestLogId: '',
					},
				},
			} ),
		] );

		expect( getContainer().textContent ).toContain(
			'AI request log unavailable'
		);
		expect( getContainer().textContent ).toContain( 'AI request log' );
		expect(
			Array.from( getContainer().querySelectorAll( 'button' ) ).some(
				( button ) => button.textContent === 'View AI request'
			)
		).toBe( false );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'renders no-model copy before unavailable request-log copy', async () => {
		await renderApp( [
			createEntry( {
				id: 'activity-no-model',
				suggestion: 'No rankable patterns',
				after: {
					modelRequest: {
						attempted: false,
						reason: 'no_rankable_candidates',
					},
				},
				request: {
					ai: {
						requestToken: 'test-request-token',
						requestLogId: '',
					},
				},
			} ),
		] );

		expect( getContainer().textContent ).toContain(
			'No model request was attempted for this diagnostic.'
		);
		expect( getContainer().textContent ).toContain( 'AI request log' );
		expect( getContainer().textContent ).not.toContain(
			'AI request log unavailable'
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
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

		expect( i18n.__ ).toHaveBeenCalledWith(
			'AI Activity Log',
			'flavor-agent'
		);
		expect( i18n.__ ).toHaveBeenCalledWith(
			'Search AI activity',
			'flavor-agent'
		);
		expect( i18n.__ ).toHaveBeenCalledWith( 'Refresh', 'flavor-agent' );
		expect( i18n.__ ).toHaveBeenCalledWith( 'Status', 'flavor-agent' );
		expect( i18n.__ ).toHaveBeenCalledWith(
			'Failed or unavailable',
			'flavor-agent'
		);
		expect( i18n.__ ).toHaveBeenCalledWith( 'Block', 'flavor-agent' );
		expect( i18n.__ ).toHaveBeenCalledWith( 'Open post', 'flavor-agent' );
	} );

	test( 'uses row selection instead of per-entry feed actions', async () => {
		await renderApp( [ createEntry() ] );

		expect( getDataViewsMockState().latestProps.actions ).toBeUndefined();

		const targetLink = Array.from(
			getContainer().querySelectorAll( 'a' )
		).find( ( element ) => element.textContent.includes( 'Open target' ) );

		expect( targetLink ).toBeDefined();
		expect( targetLink.textContent ).toContain( 'Open post' );
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
		expect( i18n.__ ).toHaveBeenCalledWith(
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
		expect(
			getContainer().querySelector( '[role="alert"]' )
		).not.toBeNull();
	} );

	test( 'announces activity loading state to assistive technologies', async () => {
		let resolveFetch;
		apiFetch.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveFetch = resolve;
			} )
		);

		await act( async () => {
			getRoot().render( <ActivityLogApp bootData={ BOOT_DATA } /> );
		} );

		const loadingStatus = getContainer().querySelector(
			'[role="status"][aria-live="polite"]'
		);

		expect( loadingStatus ).not.toBeNull();
		expect( loadingStatus.textContent ).toBe( 'Loading activity…' );

		await act( async () => {
			resolveFetch( buildResponse( [ createEntry() ] ) );
		} );
		await flushEffects();
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

	test( 'renders a pending-approval summary card', async () => {
		await renderApp(
			buildResponse( [ createEntry( { id: 'activity-1' } ) ], {
				summary: { pending: 1 },
			} )
		);

		expect(
			getContainer().textContent.includes( 'Pending approval' )
		).toBe( true );
	} );

	test( 'shows approve and reject actions for pending external applies and posts the decision', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-9'
		);

		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-9',
			} ),
		] );

		const approveButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) =>
			button.textContent.includes( 'Approve and apply' )
		);
		expect( approveButton ).toBeTruthy();

		apiFetch.mockResolvedValueOnce( { entry: { id: 'activity-9' } } );

		await act( async () => {
			approveButton.click();
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				url: expect.stringContaining(
					'flavor-agent/v1/activity/activity-9/decision'
				),
				data: expect.objectContaining( { decision: 'approve' } ),
			} )
		);
	} );

	test( 'pins the terminal entry so a decided row survives leaving the pending-only feed', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
		);

		const pending = createExternalApplyEntry();
		const {
			status: ignoredTerminalStatus,
			statusLabel: ignoredTerminalStatusLabel,
			...decided
		} = {
			...pending,
			executionResult: 'rejected',
			apply: { ...pending.apply, status: 'rejected', decidedBy: 7 },
		};
		void ignoredTerminalStatus;
		void ignoredTerminalStatusLabel;

		let feedLoads = 0;

		apiFetch.mockImplementation( ( request ) => {
			if ( request?.url?.includes( '/decision' ) ) {
				return Promise.resolve( { entry: decided } );
			}
			if ( request?.url?.includes( '/claim' ) ) {
				// Defensive: Task 9's auto-claim fires on mount once it lands.
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			feedLoads += 1;
			// 1st feed load has the pending row; after the decision the pending-only
			// feed no longer includes it.
			return Promise.resolve(
				buildResponse( feedLoads <= 1 ? [ pending ] : [] )
			);
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.trim() === 'Reject' );

		await act( async () => {
			rejectButton.click();
		} );
		await flushEffects();

		// The pinned terminal entry keeps the details panel populated even though the
		// feed refetch returned zero entries. Scope to the details region: a
		// "Rejected" summary card always renders, so asserting on the whole
		// container would pass even when the selection (and panel) clears.
		const detailsRegion = getContainer().querySelector(
			'.flavor-agent-activity-log__details-region'
		);
		const story = getContainer().querySelector(
			'.flavor-agent-activity-log__entry-story'
		);

		expect( getDefinitionValue( story, 'Current status' ) ).toBe(
			'Rejected'
		);
		expect( detailsRegion?.textContent ).toMatch( /Rejected/i );
	} );

	test( 'a race-lost invalid_transition decision pins the terminal entry instead of a generic error', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
		);

		const pending = createExternalApplyEntry();
		const {
			status: ignoredRaceStatus,
			statusLabel: ignoredRaceStatusLabel,
			...terminal
		} = {
			...pending,
			executionResult: 'rejected',
			apply: { ...pending.apply, status: 'rejected', decidedBy: 9 },
		};
		void ignoredRaceStatus;
		void ignoredRaceStatusLabel;
		const raceError = Object.assign(
			new Error(
				'Flavor Agent external applies only transition out of the pending state once.'
			),
			{ code: 'flavor_agent_apply_invalid_transition' }
		);
		let feedLoads = 0;
		let claimPosts = 0;

		apiFetch.mockImplementation( ( request ) => {
			if ( request?.url?.includes( '/decision' ) ) {
				return Promise.reject( raceError );
			}
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'POST'
			) {
				claimPosts += 1;
				// First POST is Task 9's mount auto-claim: the row is still
				// pending, so the decision controls stay open. The second POST is
				// the terminal fetch from the race-lost decision below.
				return Promise.resolve(
					claimPosts <= 1
						? { claim: { userId: 7 }, entry: pending }
						: { claim: null, entry: terminal }
				);
			}
			if ( request?.url?.includes( '/claim' ) ) {
				return Promise.resolve( { claim: null, entry: terminal } );
			}
			feedLoads += 1;
			// The pending-only feed drops the row once it is decided, so the
			// pinned terminal entry (set via onDecided) keeps "Rejected" visible.
			return Promise.resolve(
				buildResponse( feedLoads <= 1 ? [ pending ] : [] )
			);
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.trim() === 'Reject' );

		await act( async () => {
			rejectButton.click();
		} );
		await flushEffects();

		expect( getContainer().textContent ).not.toMatch(
			/The decision could not be recorded/i
		);
		// A "Rejected" summary card always renders, so scope the pinned-terminal
		// assertion to the details region (mirrors the sibling "pins the terminal
		// entry" test) — otherwise this passes even when the panel never pins.
		const detailsRegion = getContainer().querySelector(
			'.flavor-agent-activity-log__details-region'
		);
		expect( detailsRegion?.textContent ).toMatch( /Rejected/i );
	} );

	test( 'a retryable 500 decision keeps the claim and shows the inline retry error', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
		);

		const pending = createExternalApplyEntry();
		const retryError = Object.assign(
			new Error( 'Flavor Agent could not update the activity entry.' ),
			{
				code: 'flavor_agent_activity_update_failed',
				data: { status: 500 },
			}
		);
		const releaseSpy = jest.fn();

		apiFetch.mockImplementation( ( request ) => {
			if ( request?.url?.includes( '/decision' ) ) {
				return Promise.reject( retryError );
			}
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'DELETE'
			) {
				releaseSpy();
				return Promise.resolve( { claim: null, entry: pending } );
			}
			if ( request?.url?.includes( '/claim' ) ) {
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.trim() === 'Reject' );

		await act( async () => {
			rejectButton.click();
		} );
		await flushEffects();

		expect( getContainer().textContent ).toMatch(
			/could not update the activity entry/i
		);
		// Row stays mounted (no abandon) and the decision was submitted, so the claim
		// is never released on a retryable failure.
		expect( releaseSpy ).not.toHaveBeenCalled();
	} );

	const PENDING_DEEP_LINK =
		'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply';

	test( 'auto-claims on opening a pending decision and shows the "you’re reviewing" badge', async () => {
		window.history.replaceState( null, '', PENDING_DEEP_LINK );

		const pending = createExternalApplyEntry();
		// The server /claim response is an ActivityRepository::find()-shaped row: status
		// lives ONLY at apply.status, with no top-level entry.status. createExternalApplyEntry
		// models the *admin feed* row (which DOES carry a top-level status), so strip it
		// here to exercise the real claim-response shape and catch any regression that
		// reads entry.status instead of apply.status.
		const claimEntry = { ...pending };
		delete claimEntry.status;
		delete claimEntry.statusLabel;
		const claimSpy = jest.fn();

		apiFetch.mockImplementation( ( request ) => {
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'POST'
			) {
				claimSpy();
				return Promise.resolve( {
					claim: {
						userId: 7,
						claimedAt: '2026-06-25T00:00:00+00:00',
					},
					entry: claimEntry,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		// The row is auto-selected by the deep link → GovernanceEvidenceSection mounts → auto-claim.
		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		expect( claimSpy ).toHaveBeenCalled();
		expect( getContainer().textContent ).toMatch( /reviewing this/i );
		// The find()-shaped claim entry has no top-level status; applyClaimResponse must
		// read apply.status (still 'pending'), NOT entry.status — otherwise it pins the
		// row as decided and hides the controls. The Reject control must still be present.
		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.trim() === 'Reject' );
		expect( rejectButton ).toBeTruthy();
	} );

	test( 'shows another reviewer’s claim as a passive note without disabling the buttons', async () => {
		window.history.replaceState( null, '', PENDING_DEEP_LINK );

		const pending = createExternalApplyEntry( {
			apply: {
				...createExternalApplyEntry().apply,
				claim: { userId: 5, claimedAt: '2026-06-25T00:00:00+00:00' },
			},
		} );

		apiFetch.mockImplementation( ( request ) => {
			if ( request?.url?.includes( '/claim' ) ) {
				return Promise.resolve( {
					claim: { userId: 5 },
					entry: pending,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		expect( getContainer().textContent ).toContain( 'User #5' );
		// The Approve button remains enabled (claims never gate the decision).
		const approveButton = [
			...getContainer().querySelectorAll( 'button' ),
		].find( ( button ) => /approve and apply/i.test( button.textContent ) );
		expect( approveButton?.disabled ).toBeFalsy();
	} );

	test( 'releases the claim on abandon (unmount) and not after a decision submit', async () => {
		window.history.replaceState( null, '', PENDING_DEEP_LINK );

		const pending = createExternalApplyEntry();
		const releaseSpy = jest.fn();

		apiFetch.mockImplementation( ( request ) => {
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'DELETE'
			) {
				releaseSpy();
				return Promise.resolve( { claim: null, entry: pending } );
			}
			if ( request?.url?.includes( '/claim' ) ) {
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		// Unmount = abandon: the auto-claim effect cleanup releases because no decision
		// was submitted. (getRoot().unmount runs the same cleanup as deselect/navigate.)
		await act( async () => {
			getRoot().unmount();
		} );

		expect( releaseSpy ).toHaveBeenCalled();
	} );

	test( 'releases an abandoned claim after the initial claim request settles late', async () => {
		window.history.replaceState( null, '', PENDING_DEEP_LINK );

		const pending = createExternalApplyEntry();
		const deferredClaim = createDeferred();
		const releaseSpy = jest.fn();

		apiFetch.mockImplementation( ( request ) => {
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'DELETE'
			) {
				releaseSpy();
				return Promise.resolve( { claim: null, entry: pending } );
			}

			if ( request?.url?.includes( '/claim' ) ) {
				return deferredClaim.promise;
			}

			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		await act( async () => {
			getRoot().unmount();
		} );

		expect( releaseSpy ).not.toHaveBeenCalled();

		await act( async () => {
			deferredClaim.resolve( {
				claim: { userId: 7 },
				entry: pending,
			} );
			await deferredClaim.promise;
		} );
		await flushEffects();

		expect( releaseSpy ).toHaveBeenCalled();
	} );

	test( 'a retryable failure then abandon releases the claim (not leaked to TTL)', async () => {
		window.history.replaceState( null, '', PENDING_DEEP_LINK );

		const pending = createExternalApplyEntry();
		const retryError = Object.assign(
			new Error( 'Flavor Agent could not update the activity entry.' ),
			{
				code: 'flavor_agent_activity_update_failed',
				data: { status: 500 },
			}
		);
		const releaseSpy = jest.fn();

		apiFetch.mockImplementation( ( request ) => {
			if ( request?.url?.includes( '/decision' ) ) {
				return Promise.reject( retryError );
			}
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'DELETE'
			) {
				releaseSpy();
				return Promise.resolve( { claim: null, entry: pending } );
			}
			if ( request?.url?.includes( '/claim' ) ) {
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.trim() === 'Reject' );

		// Submit a decision that fails with a retryable 500 — the claim must survive...
		await act( async () => {
			rejectButton.click();
		} );
		await flushEffects();
		expect( releaseSpy ).not.toHaveBeenCalled();

		// ...then abandon the row. The decision never committed (claim still live), so the
		// auto-claim cleanup MUST release it (decisionSubmittedRef stays false on a
		// retryable failure). Without the fix the ref latched true and the claim leaked.
		await act( async () => {
			getRoot().unmount();
		} );

		expect( releaseSpy ).toHaveBeenCalled();
	} );

	test( 'renders the advisory claim badge in the feed keyed off the viewer (finding 1)', async () => {
		// Self case so the test gates the call-site wiring: with currentUserId threaded
		// into normalizeActivityDiscoveryBadges, the viewer's own claim reads
		// "You're reviewing this"; without it, it would read "User #7 is reviewing".
		const pending = createExternalApplyEntry( {
			id: 'activity-pending',
			suggestion: 'Pending governance row',
			apply: {
				...createExternalApplyEntry().apply,
				claim: { userId: 7, claimedAt: '2026-06-25T00:00:00+00:00' },
			},
		} );

		// mockImplementation (not renderApp([…])) so the auto-claim-on-select returns the
		// claim shape rather than a feed object, leaving apply.claim intact.
		apiFetch.mockImplementation( ( request ) => {
			if ( request?.url?.includes( '/claim' ) ) {
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		const badgeTexts = Array.from(
			getContainer().querySelectorAll(
				'.flavor-agent-activity-log__entry-badge'
			)
		).map( ( badge ) => badge.textContent );

		expect(
			badgeTexts.some( ( text ) => /reviewing this/i.test( text ) )
		).toBe( true );
	} );

	test( 'renders a Release control for the claim holder and releases it on click (spec :90)', async () => {
		window.history.replaceState( null, '', PENDING_DEEP_LINK );

		const pending = createExternalApplyEntry( {
			apply: {
				...createExternalApplyEntry().apply,
				claim: { userId: 7, claimedAt: '2026-06-25T00:00:00+00:00' },
			},
		} );
		const releaseSpy = jest.fn();

		apiFetch.mockImplementation( ( request ) => {
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'DELETE'
			) {
				releaseSpy();
				return Promise.resolve( { claim: null, entry: pending } );
			}
			if ( request?.url?.includes( '/claim' ) ) {
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		await renderApp( undefined, { bootData: { currentUserId: 7 } } );

		const releaseButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) =>
			/release review claim/i.test( button.textContent )
		);
		expect( releaseButton ).toBeTruthy();

		await act( async () => {
			releaseButton.click();
		} );
		await flushEffects();

		expect( releaseSpy ).toHaveBeenCalled();
	} );

	test( 'renders governance evidence for pending, rejected, failed, and executed external applies', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-pending'
		);

		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-pending',
				before: {
					userConfig: {
						styles: {
							color: {
								text: 'var:preset|color|contrast',
							},
						},
					},
				},
				after: {
					operations: [],
				},
				apply: {
					status: 'pending',
					requestedBy: 7,
					requestedAt: '2026-06-10T01:00:00+00:00',
					expiresAt: '2026-06-11T01:00:00+00:00',
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'var:preset|color|accent',
							presetSlug: 'accent',
						},
						{
							type: 'custom_unknown',
							value: 'raw',
						},
					],
					signatures: {
						resolvedContextSignature: 'r'.repeat( 64 ),
						reviewContextSignature: 'v'.repeat( 64 ),
						baselineConfigHash: 'b'.repeat( 64 ),
					},
					requestReference: 'agent-req-1',
				},
			} ),
			createExternalApplyEntry( {
				id: 'activity-rejected',
				status: 'rejected',
				apply: {
					status: 'rejected',
					decidedBy: 4,
					decidedAt: '2026-06-10T03:00:00+00:00',
					decisionNote: 'Rejected from governance review.',
					operations: [],
				},
			} ),
			createExternalApplyEntry( {
				id: 'activity-failed',
				status: 'failed',
				apply: {
					status: 'failed',
					failureCode: 'flavor_agent_apply_stale',
					failureMessage: 'The style baseline changed.',
					operations: [],
				},
			} ),
			createExternalApplyEntry( {
				id: 'activity-executed',
				status: 'applied',
				undo: {
					status: 'available',
					canUndo: true,
				},
				before: {
					userConfig: {
						styles: {
							color: {
								text: 'var:preset|color|contrast',
							},
						},
					},
				},
				after: {
					userConfig: {
						styles: {
							color: {
								text: 'var:preset|color|accent',
							},
						},
					},
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'var:preset|color|accent',
							presetSlug: 'accent',
						},
					],
				},
				apply: {
					status: 'available',
					executedAt: '2026-06-10T03:05:00+00:00',
					operations: [],
				},
			} ),
		] );

		expect( getContainer().textContent ).toContain( 'Governance evidence' );
		expect( getContainer().textContent ).toContain( 'Approval required' );
		expect( getContainer().textContent ).not.toContain(
			'Style comparison'
		);
		expect( getContainer().textContent ).toContain( 'agent-req-1' );
		expect( getContainer().textContent ).not.toContain(
			'Baseline unavailable'
		);
		expect( getVisualDiffViewer() ).not.toBeNull();
		expect( getVisualDiffRow( 'color.text' ) ).not.toBeNull();
		expect( getVisualDiffRow( 'color.text' )?.textContent ).toContain(
			'Proposed only'
		);
		expect(
			getVisualDiffRow( 'color.text' )?.querySelectorAll(
				'.flavor-agent-activity-log__visual-diff-swatch'
			)
		).toHaveLength( 2 );
		expect(
			getVisualDiffRow( 'color.text' )?.querySelectorAll(
				'.flavor-agent-activity-log__visual-diff-live'
			)
		).toHaveLength( 2 );
		expect( getVisualDiffRow( 'color.text' )?.textContent ).toContain(
			'resolved from the current theme palette'
		);
		expect( getVisualDiffRow( 'color.text' )?.textContent ).toContain(
			'Not applied'
		);
		expect( getVisualDiffRow( 'Custom Unknown' )?.className ).toContain(
			'is-unsupported'
		);
		expect( getVisualDiffRow( 'Custom Unknown' )?.textContent ).toContain(
			'{"type":"custom_unknown","value":"raw"}'
		);
		expect(
			getVisualDiffViewer().compareDocumentPosition(
				getDetailSectionByLabel( 'State snapshots' )
			)
		).toBe( 4 );

		await act( async () => {
			getDataViewsMockState().latestProps.onClickItem( {
				id: 'activity-rejected',
			} );
		} );
		expect( getContainer().textContent ).toContain(
			'Rejected from governance review.'
		);
		expect( getContainer().textContent ).not.toContain(
			'Approve and apply'
		);

		await act( async () => {
			getDataViewsMockState().latestProps.onClickItem( {
				id: 'activity-failed',
			} );
		} );
		expect( getContainer().textContent ).toContain( 'Apply failed' );
		expect( getContainer().textContent ).toContain(
			'The style baseline changed.'
		);

		await act( async () => {
			getDataViewsMockState().latestProps.onClickItem( {
				id: 'activity-executed',
			} );
		} );
		expect( getContainer().textContent ).toContain( 'Applied' );
		expect( getVisualDiffRow( 'color.text' )?.className ).toContain(
			'is-applied'
		);
		expect(
			getVisualDiffRow( 'color.text' )?.querySelectorAll(
				'.flavor-agent-activity-log__visual-diff-swatch'
			)
		).toHaveLength( 3 );
		expect(
			getVisualDiffRow( 'color.text' )?.querySelectorAll(
				'.flavor-agent-activity-log__visual-diff-live'
			)
		).toHaveLength( 3 );
	} );

	test( 'renders honest proposed-only variation rows and distinct undone and blocked viewer states', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-variation'
		);

		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-variation',
				status: 'applied',
				after: {
					operations: [
						{
							type: 'set_theme_variation',
							variationIndex: 1,
							variationTitle: 'High Contrast',
						},
					],
				},
				apply: {
					status: 'available',
					requestedBy: 7,
					requestedAt: '2026-06-10T01:00:00+00:00',
					expiresAt: '2026-06-11T01:00:00+00:00',
					operations: [],
					requestReference: 'agent-variation-1',
				},
			} ),
			createExternalApplyEntry( {
				id: 'activity-undone',
				status: 'undone',
				undo: {
					status: 'undone',
					canUndo: false,
				},
				before: {
					userConfig: {
						styles: {
							color: {
								text: 'old',
							},
						},
					},
				},
				after: {
					userConfig: {
						styles: {
							color: {
								text: 'new',
							},
						},
					},
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'new',
						},
					],
				},
				apply: {
					status: 'available',
					requestedBy: 7,
					requestedAt: '2026-06-10T01:00:00+00:00',
					expiresAt: '2026-06-11T01:00:00+00:00',
					operations: [],
					requestReference: 'agent-undone-1',
				},
			} ),
			createExternalApplyEntry( {
				id: 'activity-blocked',
				status: 'blocked',
				undo: {
					status: 'blocked',
					canUndo: false,
					error: 'Undo blocked by newer AI actions.',
				},
				before: {
					userConfig: {
						styles: {
							color: {
								text: 'old',
							},
						},
					},
				},
				after: {
					userConfig: {
						styles: {
							color: {
								text: 'new',
							},
						},
					},
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'new',
						},
					],
				},
				apply: {
					status: 'available',
					requestedBy: 7,
					requestedAt: '2026-06-10T01:00:00+00:00',
					expiresAt: '2026-06-11T01:00:00+00:00',
					operations: [],
					requestReference: 'agent-blocked-1',
				},
			} ),
		] );

		const variationRow = getVisualDiffRow( 'Theme variation' );
		expect( variationRow ).not.toBeNull();
		expect( variationRow.className ).toContain( 'is-variation' );
		expect( variationRow.textContent ).toContain( 'High Contrast' );
		expect(
			variationRow.querySelectorAll(
				'.flavor-agent-activity-log__visual-diff-stage'
			)
		).toHaveLength( 1 );

		await act( async () => {
			getDataViewsMockState().latestProps.onClickItem( {
				id: 'activity-undone',
			} );
		} );
		expect( getVisualDiffRow( 'color.text' )?.className ).toContain(
			'is-undone'
		);

		await act( async () => {
			getDataViewsMockState().latestProps.onClickItem( {
				id: 'activity-blocked',
			} );
		} );
		expect( getVisualDiffRow( 'color.text' )?.className ).toContain(
			'is-blocked'
		);
	} );

	test( 'renders attestation verification affordances for executed external applies', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-executed'
		);

		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-executed',
				status: 'applied',
				undo: {
					status: 'available',
					canUndo: true,
				},
				apply: {
					status: 'available',
					executedAt: '2026-06-10T03:05:00+00:00',
					operations: [],
				},
				attestation: {
					id: 'att_abc123',
					verificationUrl:
						'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/verification',
					verifyUrl:
						'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123',
					subjectStateUrl:
						'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/subject-state',
					keyId: 'site-key',
					governanceClaim: 'governed-change',
					governanceLane: 'external-style-apply-v1',
					subjectName: 'wp_global_styles:17',
					subjectScope: 'global-styles',
					createdAt: '2026-06-10T03:05:01+00:00',
					revertedByAttestationId: 'att_revert456',
					supersededByAttestationId: 'att_successor789',
					supersededByVerifyUrl:
						'https://example.test/wp-json/flavor-agent/v1/attestations/att_successor789',
				},
			} ),
		] );

		expect( getContainer().textContent ).toContain( 'Attestation' );
		expect( getContainer().textContent ).toContain( 'att_abc123' );
		expect( getContainer().textContent ).toContain( 'Governed change' );
		expect( getContainer().textContent ).toContain(
			'External style apply (external-style-apply-v1)'
		);
		expect( getContainer().textContent ).toContain( 'site-key' );
		expect( getContainer().textContent ).toContain( 'Reverted by' );
		expect( getContainer().textContent ).toContain( 'att_revert456' );
		expect( getContainer().textContent ).toContain( 'Superseded by' );
		expect( getContainer().textContent ).toContain( 'att_successor789' );

		const verifyButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Run verification' );
		const envelopeButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Load envelope' );
		const subjectStateButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Load live subject' );
		const envelopeLink = getContainer().querySelector(
			'a[href="https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123"]'
		);
		const subjectStateLink = getContainer().querySelector(
			'a[href="https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/subject-state"]'
		);

		expect( verifyButton ).toBeTruthy();
		expect( envelopeButton ).toBeTruthy();
		expect( subjectStateButton ).toBeTruthy();
		expect( envelopeLink?.textContent ).toBe( 'Open envelope JSON' );
		expect( subjectStateLink?.textContent ).toBe(
			'Open live subject JSON'
		);

		apiFetch.mockResolvedValueOnce( {
			attestationId: 'att_abc123',
			outcomes: [ 'signature_valid', 'live_matches_subject' ],
			subjectError: null,
		} );

		await act( async () => {
			verifyButton.click();
		} );

		expect(
			apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ].url
		).toBe(
			'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/verification'
		);
		expect( getContainer().textContent ).toContain( 'Signature valid' );
		expect( getContainer().textContent ).toContain(
			'Live subject matches'
		);

		apiFetch.mockResolvedValueOnce( {
			subject_digest: 'sha256:abc123',
			scope: 'global-styles',
		} );

		await act( async () => {
			subjectStateButton.click();
		} );

		expect(
			apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ].url
		).toBe(
			'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/subject-state'
		);
		expect( getContainer().textContent ).toContain(
			'Live subject state loaded from the public endpoint.'
		);
		expect( getContainer().textContent ).toContain(
			'Digest: sha256:abc123'
		);
	} );

	test( 'ignores stale attestation verification results after selecting another row', async () => {
		const staleVerification = createDeferred();

		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-attestation-1'
		);
		apiFetch.mockImplementation( ( request ) => {
			const url = String( request?.url || '' );

			if ( url.includes( 'flavor-agent/v1/activity?' ) ) {
				return Promise.resolve(
					buildResponse( [
						createExternalApplyEntry( {
							id: 'activity-attestation-1',
							suggestion: 'First attestation row',
							status: 'applied',
							undo: { status: 'available', canUndo: true },
							apply: {
								status: 'available',
								executedAt: '2026-06-10T03:05:00+00:00',
								operations: [],
							},
							attestation: {
								id: 'att_first',
								verificationUrl:
									'https://example.test/wp-json/flavor-agent/v1/attestations/att_first/verification',
								verifyUrl:
									'https://example.test/wp-json/flavor-agent/v1/attestations/att_first',
								subjectStateUrl:
									'https://example.test/wp-json/flavor-agent/v1/attestations/att_first/subject-state',
							},
						} ),
						createExternalApplyEntry( {
							id: 'activity-attestation-2',
							suggestion: 'Second attestation row',
							status: 'applied',
							undo: { status: 'available', canUndo: true },
							apply: {
								status: 'available',
								executedAt: '2026-06-10T03:06:00+00:00',
								operations: [],
							},
							attestation: {
								id: 'att_second',
								verificationUrl:
									'https://example.test/wp-json/flavor-agent/v1/attestations/att_second/verification',
								verifyUrl:
									'https://example.test/wp-json/flavor-agent/v1/attestations/att_second',
								subjectStateUrl:
									'https://example.test/wp-json/flavor-agent/v1/attestations/att_second/subject-state',
							},
						} ),
					] )
				);
			}

			if (
				url ===
				'https://example.test/wp-json/flavor-agent/v1/attestations/att_first/verification'
			) {
				return staleVerification.promise;
			}

			return Promise.reject(
				new Error( `Unexpected request: ${ url }` )
			);
		} );

		await renderApp();

		const verifyButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Run verification' );
		const secondRowButton = getRowButtonByTitle( 'Second attestation row' );

		expect( verifyButton ).toBeDefined();
		expect( secondRowButton ).toBeDefined();

		await act( async () => {
			verifyButton.click();
		} );
		await act( async () => {
			secondRowButton.click();
		} );

		await act( async () => {
			staleVerification.resolve( {
				outcomes: [ 'signature_valid', 'live_matches_subject' ],
			} );
			await Promise.resolve();
		} );
		await flushEffects();

		expect( getSidebarTitle().textContent ).toBe(
			'Second attestation row'
		);
		expect( getContainer().textContent ).not.toContain( 'Signature valid' );
		expect( getContainer().textContent ).not.toContain(
			'Live subject matches'
		);
	} );

	function findCryptographicRecord() {
		return Array.from( getContainer().querySelectorAll( 'details' ) ).find(
			( node ) =>
				node.querySelector(
					'.flavor-agent-activity-log__record-summary-label'
				)?.textContent === 'Cryptographic record'
		);
	}

	test( 'leads with a plain-language summary and tiers cryptographic evidence into a collapsed record', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-executed'
		);

		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-executed',
				status: 'applied',
				undo: { status: 'available', canUndo: true },
				after: {
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'new',
						},
					],
				},
				apply: {
					status: 'available',
					requestedBy: 7,
					requestedAt: '2026-06-10T01:00:00+00:00',
					expiresAt: '2026-06-11T01:00:00+00:00',
					executedAt: '2026-06-10T03:05:00+00:00',
					operations: [
						{
							type: 'set_styles',
							path: [ 'color', 'text' ],
							value: 'var:preset|color|accent',
							presetSlug: 'accent',
						},
					],
					signatures: {
						resolvedContextSignature: 'r'.repeat( 64 ),
						reviewContextSignature: 'v'.repeat( 64 ),
						baselineConfigHash: 'b'.repeat( 64 ),
					},
					requestReference: 'agent-req-1',
				},
				attestation: {
					id: 'att_abc123',
					verificationUrl:
						'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/verification',
					verifyUrl:
						'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123',
					subjectStateUrl:
						'https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/subject-state',
					keyId: 'site-key',
					governanceClaim: 'governed-change',
					governanceLane: 'external-style-apply-v1',
					subjectName: 'wp_global_styles:17',
					subjectScope: 'global-styles',
					createdAt: '2026-06-10T03:05:01+00:00',
					revertedByAttestationId: 'att_revert456',
					supersededByAttestationId: '',
					supersededByVerifyUrl: '',
				},
			} ),
		] );

		// Plain-language tier leads the pane.
		expect( getContainer().textContent ).toContain( 'What happened' );
		expect( getContainer().textContent ).toContain( 'What changed' );
		expect( getContainer().textContent ).toContain(
			'Current when applied'
		);
		expect( getContainer().textContent ).toContain( 'Reversible' );

		// One labeled, collapsed, ARIA-labeled disclosure.
		const record = findCryptographicRecord();
		expect( record ).toBeTruthy();
		expect( record.tagName ).toBe( 'DETAILS' );
		expect( record.open ).toBe( false );
		expect( record.getAttribute( 'aria-label' ) ).toBeTruthy();
		expect(
			record.querySelector(
				'.flavor-agent-activity-log__record-summary-text'
			)?.textContent
		).toContain( 'Freshness signatures' );

		// Cryptographic evidence is inside the record (reachable, not removed).
		expect( record.textContent ).toContain( 'att_abc123' );
		expect( record.textContent ).toContain( 'Owned lane' );
		expect( record.textContent ).toContain(
			'External style apply (external-style-apply-v1)'
		);
		expect( record.textContent ).toContain( 'site-key' );
		expect( record.textContent ).toContain( 'Reverted by' );
		expect( record.textContent ).toContain( 'att_revert456' );
		expect( record.textContent ).toContain( 'Resolved signature' );
		expect( record.textContent ).toContain( 'Baseline hash' );

		// Verification actions stay top-level, outside the collapsed record.
		const verifyButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Run verification' );
		const verifyRawLink = getContainer().querySelector(
			'a[href="https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123"]'
		);
		const subjectStateLink = getContainer().querySelector(
			'a[href="https://example.test/wp-json/flavor-agent/v1/attestations/att_abc123/subject-state"]'
		);
		expect( verifyButton?.textContent ).toBe( 'Run verification' );
		expect( verifyRawLink?.textContent ).toBe( 'Open envelope JSON' );
		expect( subjectStateLink?.textContent ).toBe(
			'Open live subject JSON'
		);
		expect( record.contains( verifyButton ) ).toBe( false );
		expect( record.contains( verifyRawLink ) ).toBe( false );
		expect( record.contains( subjectStateLink ) ).toBe( false );
	} );

	test( 'tiers freshness signatures into the collapsed record for a pending row', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-pending'
		);

		await renderApp( [
			createExternalApplyEntry( { id: 'activity-pending' } ),
		] );

		const record = findCryptographicRecord();
		const approveButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Approve and apply' );
		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Reject' );

		expect( record ).toBeTruthy();
		expect( record.open ).toBe( false );
		expect( getContainer().textContent ).toContain(
			'AI proposes; WordPress approves'
		);
		expect( approveButton ).toBeTruthy();
		expect( rejectButton ).toBeTruthy();
		expect( record.contains( approveButton ) ).toBe( false );
		expect( record.contains( rejectButton ) ).toBe( false );
		// Signatures recorded at request time stay reachable inside the record.
		expect( record.textContent ).toContain( 'Resolved signature' );
		expect( record.textContent ).toContain( 'Baseline hash' );

		// They are not surfaced in the always-visible plain-language summary.
		const summary = getContainer().querySelector(
			'.flavor-agent-activity-log__governance-summary'
		);
		expect( summary ).toBeTruthy();
		expect( summary.textContent ).toContain( 'What changed' );
		expect( summary.textContent ).not.toContain( 'Resolved signature' );
	} );

	test( 'disables repeated decisions while pending and preserves failed notes', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-9'
		);

		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-9',
			} ),
		] );
		apiFetch.mockRejectedValueOnce( new Error( 'Decision route failed.' ) );

		const noteField = getContainer().querySelector( 'textarea' );
		expect( noteField ).not.toBeNull();

		await act( async () => {
			noteField.value = 'Needs another look';
			noteField.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		const approveButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) =>
			button.textContent.includes( 'Approve and apply' )
		);

		await act( async () => {
			approveButton.click();
			approveButton.click();
		} );
		await flushEffects();

		const decisionCalls = apiFetch.mock.calls.filter( ( [ request ] ) =>
			String( request?.url || '' ).includes( '/decision' )
		);
		expect( decisionCalls ).toHaveLength( 1 );
		expect( getContainer().textContent ).toContain(
			'Decision route failed.'
		);
		expect( getContainer().querySelector( 'textarea' ).value ).toBe(
			'Needs another look'
		);
	} );

	test( 'removes decision controls immediately after a successful decision while refresh is pending', async () => {
		const refresh = createDeferred();
		const pendingEntry = createExternalApplyEntry( {
			id: 'activity-9',
		} );
		const rejectedEntry = createExternalApplyEntry( {
			id: 'activity-9',
			status: 'rejected',
			statusLabel: 'Rejected',
			apply: {
				status: 'rejected',
				decidedBy: 1,
				decidedAt: '2026-06-10T03:00:00+00:00',
				decisionNote: 'Not this release.',
				operations: [],
			},
		} );

		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-9'
		);
		await renderApp( [ pendingEntry ] );

		apiFetch
			.mockResolvedValueOnce( { entry: rejectedEntry } )
			.mockReturnValueOnce( refresh.promise );

		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.includes( 'Reject' ) );

		expect( rejectButton ).toBeDefined();

		await act( async () => {
			rejectButton.click();
			await Promise.resolve();
		} );
		await flushEffects();

		expect(
			Array.from( getContainer().querySelectorAll( 'button' ) ).some(
				( button ) => button.textContent.includes( 'Approve and apply' )
			)
		).toBe( false );
		expect(
			Array.from( getContainer().querySelectorAll( 'button' ) ).some(
				( button ) => button.textContent.includes( 'Reject' )
			)
		).toBe( false );
		expect(
			apiFetch.mock.calls.filter( ( [ request ] ) =>
				String( request?.url || '' ).includes( '/decision' )
			)
		).toHaveLength( 1 );

		await act( async () => {
			refresh.resolve( buildResponse( [ rejectedEntry ] ) );
			await Promise.resolve();
		} );
		await flushEffects();

		expect( getContainer().textContent ).toContain( 'Rejected' );
		expect( getContainer().textContent ).toContain( 'Not this release.' );
	} );

	test( 'ignores stale decision failures after selecting another row', async () => {
		const staleDecision = createDeferred();

		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-decision-1'
		);
		apiFetch.mockImplementation( ( request ) => {
			const url = String( request?.url || '' );

			if ( url.includes( 'flavor-agent/v1/activity?' ) ) {
				return Promise.resolve(
					buildResponse( [
						createExternalApplyEntry( {
							id: 'activity-decision-1',
							suggestion: 'First decision row',
						} ),
						createExternalApplyEntry( {
							id: 'activity-decision-2',
							suggestion: 'Second decision row',
						} ),
					] )
				);
			}

			if ( url.includes( '/decision' ) ) {
				return staleDecision.promise;
			}

			return Promise.reject(
				new Error( `Unexpected request: ${ url }` )
			);
		} );

		await renderApp();

		const approveButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) =>
			button.textContent.includes( 'Approve and apply' )
		);
		const secondRowButton = getRowButtonByTitle( 'Second decision row' );

		expect( approveButton ).toBeDefined();
		expect( secondRowButton ).toBeDefined();

		await act( async () => {
			approveButton.click();
		} );
		await act( async () => {
			secondRowButton.click();
		} );

		await act( async () => {
			staleDecision.reject( new Error( 'Stale decision failure.' ) );
			await Promise.resolve();
		} );
		await flushEffects();

		expect( getSidebarTitle().textContent ).toBe( 'Second decision row' );
		expect( getContainer().textContent ).not.toContain(
			'Stale decision failure.'
		);
	} );

	test( 'keeps the selected row open after a successful reject refresh', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-9'
		);

		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-9',
			} ),
		] );

		apiFetch
			.mockResolvedValueOnce( { entry: { id: 'activity-9' } } )
			.mockResolvedValueOnce(
				buildResponse( [
					createExternalApplyEntry( {
						id: 'activity-9',
						status: 'rejected',
						apply: {
							status: 'rejected',
							decidedBy: 1,
							decidedAt: '2026-06-10T03:00:00+00:00',
							decisionNote: 'Not this release.',
							operations: [],
						},
					} ),
				] )
			);

		const rejectButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.includes( 'Reject' ) );

		await act( async () => {
			rejectButton.click();
		} );
		await flushEffects();

		expect( getSidebarTitle().textContent ).toBe(
			'External: use the accent text preset'
		);
		expect( getContainer().textContent ).toContain( 'Rejected' );
		expect( getContainer().textContent ).toContain( 'Not this release.' );
		expect( apiFetch.mock.calls.length ).toBeGreaterThanOrEqual( 3 );
	} );

	test( 'adds an approvals quick filter that maps to pending status without changing all activity', async () => {
		await renderApp( [
			createExternalApplyEntry( {
				id: 'activity-pending',
			} ),
			createEntry( {
				id: 'activity-applied',
				suggestion: 'Already applied',
			} ),
		] );

		expect( getVisibleTitles() ).toEqual( [
			'External: use the accent text preset',
			'Already applied',
		] );

		const approvalsButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Approvals' );

		expect( approvalsButton ).toBeDefined();

		await act( async () => {
			approvalsButton.click();
		} );
		await flushEffects();

		// The auto-selected first row is a pending external apply, so Task 9's
		// mount auto-claim adds a POST /claim call; assert on the approvals feed
		// request by URL rather than a positional mock.calls index.
		const approvalsFeedCall = apiFetch.mock.calls.find(
			( [ request ] ) =>
				request?.url?.includes( 'status=pending' ) &&
				request?.url?.includes( 'statusOperator=is' )
		);
		expect( approvalsFeedCall ).toBeDefined();
	} );

	test( 'hides decision actions when the user cannot approve style applies', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-9'
		);

		await renderApp(
			[
				createExternalApplyEntry( {
					id: 'activity-9',
				} ),
			],
			{ bootData: { canApproveStyleApplies: false } }
		);

		const approveButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) =>
			button.textContent.includes( 'Approve and apply' )
		);
		expect( approveButton ).toBeFalsy();
	} );

	test( 'a window focus refreshes the feed and re-claims the selected pending row', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-external-apply'
		);

		const pending = createExternalApplyEntry();
		const claimSpy = jest.fn();

		apiFetch.mockImplementation( ( request ) => {
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'POST'
			) {
				claimSpy();
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		// Row auto-selected by the deep link; the leading-edge debounce fires the
		// re-claim synchronously on the first focus event (no fake timers needed).
		await renderApp( undefined, { bootData: { currentUserId: 7 } } );
		claimSpy.mockClear();
		const callsBefore = apiFetch.mock.calls.length;

		await act( async () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );
		await flushEffects();

		expect( apiFetch.mock.calls.length ).toBeGreaterThan( callsBefore );
		expect( claimSpy ).toHaveBeenCalled();
	} );

	test( 'an explicit release suppresses the focus re-claim for that row (fix 1)', async () => {
		window.history.replaceState( null, '', PENDING_DEEP_LINK );

		// The viewer (id 7) holds the claim, so the Release control renders and the
		// mount auto-claim is a self-claim.
		const pending = createExternalApplyEntry( {
			apply: {
				...createExternalApplyEntry().apply,
				claim: { userId: 7, claimedAt: '2026-06-25T00:00:00+00:00' },
			},
		} );
		let claimPosts = 0;

		apiFetch.mockImplementation( ( request ) => {
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'POST'
			) {
				claimPosts += 1;
				return Promise.resolve( {
					claim: { userId: 7 },
					entry: pending,
				} );
			}
			if (
				request?.url?.includes( '/claim' ) &&
				request?.method === 'DELETE'
			) {
				return Promise.resolve( { claim: null, entry: pending } );
			}
			return Promise.resolve( buildResponse( [ pending ] ) );
		} );

		// Mount auto-claim fires exactly one POST /claim.
		await renderApp( undefined, { bootData: { currentUserId: 7 } } );
		expect( claimPosts ).toBe( 1 );

		// Explicitly release the claim (DELETE /claim).
		const releaseButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) =>
			/release review claim/i.test( button.textContent )
		);
		expect( releaseButton ).toBeTruthy();

		await act( async () => {
			releaseButton.click();
		} );
		await flushEffects();

		const postsAfterRelease = claimPosts;

		// A focus event must NOT silently re-acquire the just-released claim, or the
		// explicit Release would feel like a no-op. The reloadToken bump still fires;
		// only the re-claim is suppressed for the released row until the selection
		// changes.
		await act( async () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );
		await flushEffects();

		expect( claimPosts ).toBe( postsAfterRelease );
	} );
} );
