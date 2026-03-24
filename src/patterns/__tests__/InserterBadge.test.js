const mockUseSelect = jest.fn();
const mockFindInserterToggle = jest.fn();
const mockGetInserterBadgeState = jest.fn();

jest.mock( '@wordpress/components', () => {
	const { Fragment, createElement } = require( '@wordpress/element' );

	return {
		Tooltip: ( { children } ) => createElement( Fragment, null, children ),
	};
} );

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
const { createRoot } = require( '@wordpress/element' );

import InserterBadge from '../InserterBadge';

window.IS_REACT_ACT_ENVIRONMENT = true;

let container = null;
let root = null;

function renderComponent() {
	act( () => {
		root.render( <InserterBadge /> );
	} );
}

describe( 'InserterBadge', () => {
	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
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
		document.body.appendChild( container );
	} );

	afterEach( () => {
		if ( root ) {
			act( () => {
				root.unmount();
			} );
		}
		if ( container?.parentNode ) {
			container.parentNode.removeChild( container );
		}
		root = null;
		container = null;
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
			root.unmount();
		} );

		expect(
			anchor.classList.contains( 'flavor-agent-inserter-badge-anchor' )
		).toBe( false );
		root = null;
	} );
} );
