const mockUseSelect = jest.fn();
const mockFindInserterToggle = jest.fn();
const mockGetAllowedPatterns = jest.fn();
const mockCanInsertBlockType = jest.fn();
const mockCreateBlock = jest.fn();
const mockRawHandler = jest.fn();

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/blocks', () => ( {
	createBlock: ( ...args ) => mockCreateBlock( ...args ),
	rawHandler: ( ...args ) => mockRawHandler( ...args ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '../inserter-dom', () => ( {
	findInserterToggle: ( ...args ) => mockFindInserterToggle( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../pattern-settings', () => ( {
	getAllowedPatterns: ( ...args ) => mockGetAllowedPatterns( ...args ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import InserterBadge from '../InserterBadge';

const { getContainer, getRoot } = setupReactTest();

function renderComponent() {
	act( () => {
		getRoot().render( <InserterBadge /> );
	} );
}

function setSelectState( {
	status = 'ready',
	recommendations = [],
	error = null,
	rootClientId = 'root-a',
	allowedPatterns = [],
} = {} ) {
	mockGetAllowedPatterns.mockReturnValue( allowedPatterns );
	mockUseSelect.mockImplementation( ( callback ) =>
		callback( ( storeName ) => {
			if ( storeName === 'flavor-agent' ) {
				return {
					getPatternStatus: jest.fn( () => status ),
					getPatternRecommendations: jest.fn( () => recommendations ),
					getPatternError: jest.fn( () => error ),
				};
			}

			if ( storeName === 'core/block-editor' ) {
				return {
					getBlockInsertionPoint: jest.fn( () => ( {
						rootClientId,
					} ) ),
					canInsertBlockType: ( ...args ) =>
						mockCanInsertBlockType( ...args ),
				};
			}

			return {};
		} )
	);
}

describe( 'InserterBadge', () => {
	beforeEach( () => {
		mockUseSelect.mockReset();
		mockFindInserterToggle.mockReset();
		mockGetAllowedPatterns.mockReset();
		mockCanInsertBlockType.mockReset();
		mockCanInsertBlockType.mockReturnValue( true );
		mockCreateBlock.mockReset();
		mockCreateBlock.mockImplementation( ( name, attributes ) => ( {
			name,
			attributes,
		} ) );
		mockRawHandler.mockReset();
		mockRawHandler.mockReturnValue( [] );
		setSelectState( {
			recommendations: [
				{ name: 'theme/hero', score: 0.92, reason: 'Hero match.' },
			],
			allowedPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					blocks: [ { name: 'core/paragraph', attributes: {} } ],
				},
			],
		} );
		document.body.innerHTML = '';
		document.body.appendChild( getContainer() );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'stays hidden cleanly when no toggle anchor is available', () => {
		mockFindInserterToggle.mockReturnValue( null );

		renderComponent();

		expect(
			document.querySelector( '.flavor-agent-inserter-badge--ready' )
		).toBeNull();
		expect(
			document.querySelector( '.flavor-agent-inserter-badge-anchor' )
		).toBeNull();
	} );

	test( 'adds and removes the anchor class around the resolved toggle parent', () => {
		const toolbar = document.createElement( 'div' );
		const anchor = document.createElement( 'div' );
		const button = document.createElement( 'button' );

		toolbar.className = 'edit-post-header-toolbar';
		anchor.appendChild( button );
		toolbar.appendChild( anchor );
		document.body.appendChild( toolbar );
		mockFindInserterToggle.mockReturnValue( button );

		renderComponent();

		expect(
			anchor.classList.contains( 'flavor-agent-inserter-badge-anchor' )
		).toBe( true );
		expect(
			anchor.querySelector( '.flavor-agent-inserter-badge--ready' )
		).not.toBeNull();
		expect(
			anchor
				.querySelector( '.flavor-agent-inserter-badge--ready' )
				?.getAttribute( 'title' )
		).toBe( 'Hero match.' );

		act( () => {
			getRoot().unmount();
		} );

		expect(
			anchor.classList.contains( 'flavor-agent-inserter-badge-anchor' )
		).toBe( false );
	} );

	test( 'hides ready badge when raw recommendations have no allowed matches', () => {
		const anchor = document.createElement( 'div' );
		const button = document.createElement( 'button' );

		anchor.appendChild( button );
		document.body.appendChild( anchor );
		mockFindInserterToggle.mockReturnValue( button );
		setSelectState( {
			recommendations: [
				{ name: 'theme/private', score: 0.95, reason: 'Private.' },
			],
			allowedPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					blocks: [ { name: 'core/paragraph', attributes: {} } ],
				},
			],
		} );

		renderComponent();

		expect(
			document.querySelector( '.flavor-agent-inserter-badge--ready' )
		).toBeNull();
		expect( mockFindInserterToggle ).not.toHaveBeenCalled();
	} );

	test( 'shows ready count for renderable recommendations only', () => {
		const anchor = document.createElement( 'div' );
		const button = document.createElement( 'button' );

		anchor.appendChild( button );
		document.body.appendChild( anchor );
		mockFindInserterToggle.mockReturnValue( button );
		setSelectState( {
			recommendations: [
				{ name: 'theme/hidden', score: 0.97, reason: 'Hidden.' },
				{ name: 'theme/hero', score: 0.91, reason: 'Hero.' },
			],
			allowedPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					blocks: [ { name: 'core/paragraph', attributes: {} } ],
				},
			],
		} );

		renderComponent();

		expect(
			anchor.querySelector( '.flavor-agent-inserter-badge--ready' )
				?.textContent
		).toBe( '1' );
		expect(
			anchor
				.querySelector( '.flavor-agent-inserter-badge--ready' )
				?.getAttribute( 'aria-label' )
		).toBe( '1 pattern recommendation available' );
	} );

	test( 'hides ready badge when allowed matches are not insertable at the active root', () => {
		const anchor = document.createElement( 'div' );
		const button = document.createElement( 'button' );

		anchor.appendChild( button );
		document.body.appendChild( anchor );
		mockFindInserterToggle.mockReturnValue( button );
		mockCanInsertBlockType.mockImplementation(
			( blockName ) => blockName !== 'core/template-part'
		);
		setSelectState( {
			recommendations: [
				{
					name: 'theme/template-with-parts',
					score: 0.95,
					reason: 'Template match.',
				},
			],
			allowedPatterns: [
				{
					name: 'theme/template-with-parts',
					title: 'Template with parts',
					blocks: [
						{
							name: 'core/template-part',
							attributes: { slug: 'header' },
						},
					],
				},
			],
		} );

		renderComponent();

		expect(
			document.querySelector( '.flavor-agent-inserter-badge--ready' )
		).toBeNull();
		expect( mockFindInserterToggle ).not.toHaveBeenCalled();
		expect( mockCanInsertBlockType ).toHaveBeenCalledWith(
			'core/template-part',
			'root-a'
		);
	} );

	test( 'uses renderable high-confidence reason instead of filtered raw reason', () => {
		const anchor = document.createElement( 'div' );
		const button = document.createElement( 'button' );

		anchor.appendChild( button );
		document.body.appendChild( anchor );
		mockFindInserterToggle.mockReturnValue( button );
		setSelectState( {
			recommendations: [
				{ name: 'theme/hidden', score: 0.99, reason: 'Hidden reason.' },
				{
					name: 'theme/hero',
					score: 0.93,
					reason: 'Renderable reason.',
				},
			],
			allowedPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					blocks: [ { name: 'core/paragraph', attributes: {} } ],
				},
			],
		} );

		renderComponent();

		expect(
			anchor
				.querySelector( '.flavor-agent-inserter-badge--ready' )
				?.getAttribute( 'aria-label' )
		).toBe( '1 pattern recommendation available' );
		expect(
			anchor
				.querySelector( '.flavor-agent-inserter-badge--ready' )
				?.getAttribute( 'title' )
		).toBe( 'Renderable reason.' );
		expect( document.body.textContent ).not.toContain( 'Hidden reason.' );
	} );

	test( 'loading state still renders when allowed matches are zero', () => {
		const anchor = document.createElement( 'div' );
		const button = document.createElement( 'button' );

		anchor.appendChild( button );
		document.body.appendChild( anchor );
		mockFindInserterToggle.mockReturnValue( button );
		setSelectState( {
			status: 'loading',
			recommendations: [],
			allowedPatterns: [],
		} );

		renderComponent();

		expect(
			anchor.querySelector( '.flavor-agent-inserter-badge--loading' )
		).not.toBeNull();
	} );

	test( 'error state still renders when allowed matches are zero', () => {
		const anchor = document.createElement( 'div' );
		const button = document.createElement( 'button' );

		anchor.appendChild( button );
		document.body.appendChild( anchor );
		mockFindInserterToggle.mockReturnValue( button );
		setSelectState( {
			status: 'error',
			error: 'Pattern request failed.',
			recommendations: [],
			allowedPatterns: [],
		} );

		renderComponent();

		expect(
			anchor.querySelector( '.flavor-agent-inserter-badge--error' )
		).not.toBeNull();
	} );
} );
