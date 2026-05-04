jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/icons', () => ( {
	check: 'svg-check',
	closeSmall: 'svg-close',
	undo: 'svg-undo',
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );

const mockToasts = [];
const mockDispatch = {
	dismissToast: jest.fn(),
	markToastInteracted: jest.fn(),
	undoToastAction: jest.fn(),
};

jest.mock( '@wordpress/data', () => ( {
	useSelect: ( selector ) =>
		selector( () => ( {
			getToasts: () => mockToasts,
		} ) ),
	useDispatch: () => mockDispatch,
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import ToastRegion, { isPrimaryShiftZ } from '../ToastRegion';

const { getRoot } = setupReactTest();

function setToasts( next ) {
	mockToasts.length = 0;
	mockToasts.push( ...next );
}

function getRegionRoot() {
	return document.querySelector( '.flavor-agent-toast-region' );
}

beforeEach( () => {
	mockToasts.length = 0;
	mockDispatch.dismissToast.mockReset();
	mockDispatch.markToastInteracted.mockReset();
	mockDispatch.undoToastAction.mockReset();

	const existing = document.querySelector( '.flavor-agent-toast-region' );

	if ( existing ) {
		existing.remove();
	}
} );

describe( 'ToastRegion — portal mount', () => {
	test( 'mounts a region root on document.body and renders queue', () => {
		setToasts( [
			{
				id: 't1',
				variant: 'success',
				title: 'Block updated',
				detail: 'core/heading',
				activityId: 'a1',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		const root = getRegionRoot();

		expect( root ).toBeTruthy();
		expect( root.parentElement ).toBe( document.body );
		expect( root.textContent ).toContain( 'Block updated' );
	} );

	test( 'renders queue oldest→newest with newest visually at the bottom', () => {
		setToasts( [
			{
				id: 'oldest',
				variant: 'success',
				title: 'First',
				activityId: 'a1',
				autoDismissMs: 6000,
				interacted: false,
			},
			{
				id: 'middle',
				variant: 'success',
				title: 'Second',
				activityId: 'a2',
				autoDismissMs: 6000,
				interacted: false,
			},
			{
				id: 'newest',
				variant: 'success',
				title: 'Third',
				activityId: 'a3',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		const titles = Array.from(
			getRegionRoot().querySelectorAll( '.flavor-agent-toast__title' )
		).map( ( node ) => node.textContent );

		expect( titles ).toEqual( [ 'First', 'Second', 'Third' ] );
	} );
} );

describe( 'ToastRegion — wiring', () => {
	test( 'clicking Undo dispatches undoToastAction with toast id and activityId', () => {
		setToasts( [
			{
				id: 't1',
				variant: 'success',
				title: 'Block updated',
				activityId: 'activity-42',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		act( () => {
			getRegionRoot()
				.querySelector( '.flavor-agent-toast__action' )
				.click();
		} );

		expect( mockDispatch.undoToastAction ).toHaveBeenCalledWith(
			't1',
			'activity-42'
		);
	} );

	test( 'clicking Close dispatches dismissToast', () => {
		setToasts( [
			{
				id: 't1',
				variant: 'success',
				title: 'Block updated',
				activityId: 'a1',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		act( () => {
			getRegionRoot()
				.querySelector( '.flavor-agent-toast__close' )
				.click();
		} );

		expect( mockDispatch.dismissToast ).toHaveBeenCalledWith( 't1' );
	} );

	test( 'mouseover dispatches markToastInteracted(true)', () => {
		setToasts( [
			{
				id: 't1',
				variant: 'success',
				title: 'Block updated',
				activityId: 'a1',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		act( () => {
			getRegionRoot()
				.querySelector( '.flavor-agent-toast' )
				.dispatchEvent(
					new window.MouseEvent( 'mouseover', { bubbles: true } )
				);
		} );

		expect( mockDispatch.markToastInteracted ).toHaveBeenCalledWith(
			't1',
			true
		);
	} );

	test( 'Undo button is disabled when activityId is missing', () => {
		setToasts( [
			{
				id: 't1',
				variant: 'success',
				title: 'Block updated',
				activityId: null,
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		const undoBtn = getRegionRoot().querySelector(
			'.flavor-agent-toast__action'
		);

		expect( undoBtn.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
	} );
} );

describe( 'ToastRegion — keyboard shortcut', () => {
	test( 'isPrimaryShiftZ matches shift + ctrl + z on non-mac platforms', () => {
		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'Linux x86_64',
			configurable: true,
		} );

		expect(
			isPrimaryShiftZ( {
				key: 'z',
				shiftKey: true,
				ctrlKey: true,
				metaKey: false,
			} )
		).toBe( true );
		expect(
			isPrimaryShiftZ( {
				key: 'z',
				shiftKey: true,
				ctrlKey: false,
				metaKey: true,
			} )
		).toBe( false );
		expect(
			isPrimaryShiftZ( { key: 'z', shiftKey: false, ctrlKey: true } )
		).toBe( false );
		expect(
			isPrimaryShiftZ( { key: 'a', shiftKey: true, ctrlKey: true } )
		).toBe( false );

		Object.defineProperty( navigator, 'platform', {
			value: original,
			configurable: true,
		} );
	} );

	test( 'isPrimaryShiftZ matches shift + cmd + z on mac platforms', () => {
		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'MacIntel',
			configurable: true,
		} );

		expect(
			isPrimaryShiftZ( {
				key: 'z',
				shiftKey: true,
				ctrlKey: false,
				metaKey: true,
			} )
		).toBe( true );
		expect(
			isPrimaryShiftZ( {
				key: 'z',
				shiftKey: true,
				ctrlKey: true,
				metaKey: false,
			} )
		).toBe( false );

		Object.defineProperty( navigator, 'platform', {
			value: original,
			configurable: true,
		} );
	} );

	test( 'mod+shift+z keydown focuses the newest toast Undo button', () => {
		setToasts( [
			{
				id: 'oldest',
				variant: 'success',
				title: 'First',
				activityId: 'a1',
				autoDismissMs: 6000,
				interacted: false,
			},
			{
				id: 'newest',
				variant: 'success',
				title: 'Second',
				activityId: 'a2',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'Linux x86_64',
			configurable: true,
		} );

		act( () => {
			document.dispatchEvent(
				new window.KeyboardEvent( 'keydown', {
					key: 'z',
					ctrlKey: true,
					shiftKey: true,
					bubbles: true,
				} )
			);
		} );

		const undoButtons = getRegionRoot().querySelectorAll(
			'.flavor-agent-toast__action'
		);

		expect( document.activeElement ).toBe(
			undoButtons[ undoButtons.length - 1 ]
		);

		Object.defineProperty( navigator, 'platform', {
			value: original,
			configurable: true,
		} );
	} );

	test( 'mod+shift+z keydown is a no-op when the queue is empty', () => {
		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		expect( () => {
			act( () => {
				document.dispatchEvent(
					new window.KeyboardEvent( 'keydown', {
						key: 'z',
						ctrlKey: true,
						shiftKey: true,
						bubbles: true,
					} )
				);
			} );
		} ).not.toThrow();
	} );
} );
