const mockUseSelect = jest.fn();
const mockFindInserterToggle = jest.fn();
const mockGetInserterBadgeState = jest.fn();

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/data', () => ( {
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '../inserter-dom', () => ( {
	findInserterToggle: ( ...args ) => mockFindInserterToggle( ...args ),
} ) );

jest.mock( '../inserter-badge-state', () => ( {
	getInserterBadgeState: ( ...args ) => mockGetInserterBadgeState( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
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

describe( 'InserterBadge', () => {
	beforeEach( () => {
		mockUseSelect.mockReset();
		mockFindInserterToggle.mockReset();
		mockGetInserterBadgeState.mockReset();
		mockUseSelect.mockImplementation( ( callback ) =>
			callback( () => ( {
				getPatternStatus: jest.fn( () => 'success' ),
				getPatternRecommendations: jest.fn( () => [] ),
				getPatternBadge: jest.fn( () => null ),
				getPatternError: jest.fn( () => null ),
			} ) )
		);
		mockGetInserterBadgeState.mockReturnValue( {
			status: 'ready',
			className: 'flavor-agent-badge flavor-agent-badge--test',
			ariaLabel: '2 pattern recommendations available',
			content: '2',
			tooltip: '2 recommendations',
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
			document.querySelector( '.flavor-agent-badge--test' )
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
			anchor.querySelector( '.flavor-agent-badge--test' )
		).not.toBeNull();

		act( () => {
			getRoot().unmount();
		} );

		expect(
			anchor.classList.contains( 'flavor-agent-inserter-badge-anchor' )
		).toBe( false );
		} );
} );
