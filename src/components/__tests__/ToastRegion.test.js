jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/icons', () => ( {
	check: 'svg-check',
	closeSmall: 'svg-close',
	undo: 'svg-undo',
} ) );

jest.mock( '@wordpress/i18n', () =>
	require( '../../test-utils/i18n-mock' ).createI18nMock()
);

import * as i18n from '@wordpress/i18n';

const fs = require( 'fs' );
const path = require( 'path' );

let mockToasts = [];
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

import ToastRegion, { isToastUndoFocusShortcut } from '../ToastRegion';

const { getRoot } = setupReactTest();

function setToasts( next ) {
	mockToasts = [ ...next ];
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
		expect( root.getAttribute( 'role' ) ).toBe( 'region' );
		expect( root.getAttribute( 'aria-label' ) ).toBe(
			'Flavor Agent recent changes'
		);
		expect( i18n.__ ).toHaveBeenCalledWith(
			'Flavor Agent recent changes',
			'flavor-agent'
		);
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
			'activity-42',
			{
				activityDocument: null,
				activityScopeKey: null,
			}
		);
	} );

	test( 'clicking Undo includes the toast activity scope', () => {
		setToasts( [
			{
				id: 't1',
				variant: 'success',
				title: 'Global styles updated',
				activityId: 'activity-42',
				activityScopeKey: 'global_styles:17',
				activityDocument: {
					scopeKey: 'global_styles:17',
					postType: 'global_styles',
					entityId: '17',
				},
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
			'activity-42',
			{
				activityDocument: {
					scopeKey: 'global_styles:17',
					postType: 'global_styles',
					entityId: '17',
				},
				activityScopeKey: 'global_styles:17',
			}
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

describe( 'ToastRegion — queue cap contract', () => {
	test( 'uses the toast store maxVisible constant instead of a local cap literal', () => {
		const source = fs.readFileSync(
			path.join( __dirname, '../ToastRegion.js' ),
			'utf8'
		);

		expect( source ).toContain( 'TOAST_DEFAULTS.maxVisible' );
		expect( source ).not.toContain( 'slice( 0, 3 )' );
	} );
} );

describe( 'ToastRegion — focus return', () => {
	let editorControl;
	let iframe;
	const toast = ( id ) => ( {
		id,
		title: 'Block updated',
		activityId: `activity-${ id }`,
		autoDismissMs: 6000,
	} );
	const renderQueue = () => getRoot().render( <ToastRegion /> );
	const removeToast = ( id ) => {
		setToasts( mockToasts.filter( ( entry ) => entry.id !== id ) );
		renderQueue();
	};
	const focusShortcut = ( targetDocument = document ) => {
		targetDocument.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'u',
				altKey: true,
				ctrlKey: true,
				metaKey: true,
				shiftKey: true,
				bubbles: true,
			} )
		);
	};

	beforeEach( () => {
		editorControl = document.createElement( 'button' );
		editorControl.textContent = 'Editor control';
		document.body.appendChild( editorControl );
		setToasts( [ toast( 't1' ) ] );
		mockDispatch.dismissToast.mockImplementation( removeToast );
		mockDispatch.undoToastAction.mockImplementation( removeToast );
	} );

	afterEach( () => {
		editorControl.remove();
		iframe?.remove();
		iframe = null;
	} );

	test.each( [ 'Escape', 'Dismiss', 'Undo' ] )(
		'returns shortcut focus to the editor after %s removes the toast',
		( action ) => {
			act( renderQueue );
			editorControl.focus();
			act( () => focusShortcut() );
			const undoButton = getRegionRoot().querySelector(
				'.flavor-agent-toast__action'
			);
			expect( document.activeElement ).toBe( undoButton );

			act( () => {
				if ( action === 'Escape' ) {
					undoButton.dispatchEvent(
						new window.KeyboardEvent( 'keydown', {
							key: 'Escape',
							bubbles: true,
						} )
					);
				} else if ( action === 'Dismiss' ) {
					const closeButton = getRegionRoot().querySelector(
						'.flavor-agent-toast__close'
					);
					closeButton.focus();
					closeButton.click();
				} else {
					undoButton.click();
				}
			} );

			expect(
				getRegionRoot().querySelector( '.flavor-agent-toast' )
			).toBeNull();
			expect( document.activeElement ).toBe( editorControl );
		}
	);

	test( 'returns focus through nested same-origin editor iframes', () => {
		iframe = document.createElement( 'iframe' );
		document.body.appendChild( iframe );
		const nested = iframe.contentDocument.createElement( 'iframe' );
		iframe.contentDocument.body.appendChild( nested );
		const canvasControl = nested.contentDocument.createElement( 'button' );
		canvasControl.textContent = 'Canvas control';
		nested.contentDocument.body.appendChild( canvasControl );
		act( renderQueue );
		iframe.focus();
		nested.focus();
		canvasControl.focus();
		act( () => focusShortcut( nested.contentDocument ) );
		act( () => removeToast( 't1' ) );

		expect( document.activeElement ).toBe( iframe );
		expect( iframe.contentDocument.activeElement ).toBe( nested );
		expect( nested.contentDocument.activeElement ).toBe( canvasControl );
	} );

	test( 'keeps the original return target when the shortcut is repeated inside a toast', () => {
		act( renderQueue );
		editorControl.focus();
		act( () => focusShortcut() );
		act( () => focusShortcut() );
		act( () => removeToast( 't1' ) );
		expect( document.activeElement ).toBe( editorControl );
	} );

	test( 'returns focus after entering the toast directly from an editor control', () => {
		act( renderQueue );
		editorControl.focus();
		act( () =>
			getRegionRoot()
				.querySelector( '.flavor-agent-toast__action' )
				.focus()
		);
		act( () => removeToast( 't1' ) );
		expect( document.activeElement ).toBe( editorControl );
	} );

	test( 'does not steal focus after the reviewer moves out of the toast before Undo finishes', () => {
		act( renderQueue );
		editorControl.focus();
		act( () => focusShortcut() );
		act( () => editorControl.focus() );
		act( () => removeToast( 't1' ) );
		expect( document.activeElement ).toBe( editorControl );
	} );

	test( 'does not move focus when another toast disappears', () => {
		setToasts( [ toast( 't1' ), toast( 't2' ) ] );
		act( renderQueue );
		editorControl.focus();
		act( () => focusShortcut() );
		const focused = document.activeElement;
		act( () => removeToast( 't1' ) );
		expect( document.activeElement ).toBe( focused );
	} );

	test( 'focuses a surviving toast when the editor return target has been removed', () => {
		setToasts( [ toast( 't1' ), toast( 't2' ) ] );
		act( renderQueue );
		editorControl.focus();
		act( () => focusShortcut() );
		editorControl.remove();
		act( () => removeToast( 't2' ) );
		expect( document.activeElement ).toBe(
			getRegionRoot().querySelector( '.flavor-agent-toast__action' )
		);
	} );

	test( 'does not return focus into an editor iframe that has been removed', () => {
		setToasts( [ toast( 't1' ), toast( 't2' ) ] );
		iframe = document.createElement( 'iframe' );
		document.body.appendChild( iframe );
		const canvasControl = iframe.contentDocument.createElement( 'button' );
		iframe.contentDocument.body.appendChild( canvasControl );
		act( renderQueue );
		iframe.focus();
		canvasControl.focus();
		act( () => focusShortcut( iframe.contentDocument ) );
		iframe.remove();
		act( () => removeToast( 't2' ) );
		expect( document.activeElement ).toBe(
			getRegionRoot().querySelector( '.flavor-agent-toast__action' )
		);
	} );

	test( 'uses the labelled region as a fallback when no return target or toast survives', () => {
		act( renderQueue );
		editorControl.focus();
		act( () => focusShortcut() );
		editorControl.remove();
		act( () => removeToast( 't1' ) );
		expect( document.activeElement ).toBe( getRegionRoot() );
	} );
} );

describe( 'ToastRegion — keyboard shortcut', () => {
	test( 'toast undo focus shortcut does not claim the conventional redo chord', () => {
		expect(
			isToastUndoFocusShortcut( {
				key: 'z',
				shiftKey: true,
				altKey: false,
				ctrlKey: true,
				metaKey: false,
			} )
		).toBe( false );
	} );

	test( 'toast undo focus shortcut matches shift + alt + ctrl + u on non-mac platforms', () => {
		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'Linux x86_64',
			configurable: true,
		} );

		expect(
			isToastUndoFocusShortcut( {
				key: 'u',
				shiftKey: true,
				altKey: true,
				ctrlKey: true,
				metaKey: false,
			} )
		).toBe( true );
		expect(
			isToastUndoFocusShortcut( {
				key: 'u',
				shiftKey: true,
				altKey: true,
				ctrlKey: false,
				metaKey: true,
			} )
		).toBe( false );
		expect(
			isToastUndoFocusShortcut( {
				key: 'u',
				shiftKey: false,
				altKey: true,
				ctrlKey: true,
			} )
		).toBe( false );
		expect(
			isToastUndoFocusShortcut( {
				key: 'a',
				shiftKey: true,
				altKey: true,
				ctrlKey: true,
			} )
		).toBe( false );

		Object.defineProperty( navigator, 'platform', {
			value: original,
			configurable: true,
		} );
	} );

	test( 'toast undo focus shortcut matches shift + option + cmd + u on mac platforms', () => {
		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'MacIntel',
			configurable: true,
		} );

		expect(
			isToastUndoFocusShortcut( {
				key: 'u',
				shiftKey: true,
				altKey: true,
				ctrlKey: false,
				metaKey: true,
			} )
		).toBe( true );
		expect(
			isToastUndoFocusShortcut( {
				key: 'u',
				shiftKey: true,
				altKey: true,
				ctrlKey: true,
				metaKey: false,
			} )
		).toBe( false );

		Object.defineProperty( navigator, 'platform', {
			value: original,
			configurable: true,
		} );
	} );

	test( 'toast undo focus shortcut uses userAgentData platform before navigator.platform', () => {
		const originalPlatform = navigator.platform;
		const originalUserAgentData = navigator.userAgentData;

		Object.defineProperty( navigator, 'platform', {
			value: '',
			configurable: true,
		} );
		Object.defineProperty( navigator, 'userAgentData', {
			value: { platform: 'macOS' },
			configurable: true,
		} );

		expect(
			isToastUndoFocusShortcut( {
				key: 'u',
				shiftKey: true,
				altKey: true,
				ctrlKey: false,
				metaKey: true,
			} )
		).toBe( true );
		expect(
			isToastUndoFocusShortcut( {
				key: 'u',
				shiftKey: true,
				altKey: true,
				ctrlKey: true,
				metaKey: false,
			} )
		).toBe( false );

		Object.defineProperty( navigator, 'platform', {
			value: originalPlatform,
			configurable: true,
		} );
		Object.defineProperty( navigator, 'userAgentData', {
			value: originalUserAgentData,
			configurable: true,
		} );
	} );

	test( 'mod+shift+z keydown does not move focus away from editor redo', () => {
		setToasts( [
			{
				id: 'newest',
				variant: 'success',
				title: 'Second',
				activityId: 'a2',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		const editorButton = document.createElement( 'button' );
		editorButton.textContent = 'Editor control';
		document.body.appendChild( editorButton );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		editorButton.focus();

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

		expect( document.activeElement ).toBe( editorButton );
	} );

	test( 'toast undo focus shortcut keydown focuses the newest toast Undo button', () => {
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
					key: 'u',
					altKey: true,
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

	test( 'toast undo focus shortcut keydown from a same-origin iframe focuses the newest toast Undo button', () => {
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

		const iframe = document.createElement( 'iframe' );
		document.body.appendChild( iframe );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'Linux x86_64',
			configurable: true,
		} );

		act( () => {
			iframe.contentDocument.dispatchEvent(
				new iframe.contentWindow.KeyboardEvent( 'keydown', {
					key: 'u',
					altKey: true,
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

	test( 'observes same-origin iframes added after mount for the undo focus shortcut', async () => {
		setToasts( [
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

		const iframe = document.createElement( 'iframe' );
		document.body.appendChild( iframe );

		await act( async () => {
			await Promise.resolve();
		} );

		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'Linux x86_64',
			configurable: true,
		} );

		act( () => {
			iframe.contentDocument.dispatchEvent(
				new iframe.contentWindow.KeyboardEvent( 'keydown', {
					key: 'u',
					altKey: true,
					ctrlKey: true,
					shiftKey: true,
					bubbles: true,
				} )
			);
		} );

		expect( document.activeElement ).toBe(
			getRegionRoot().querySelector( '.flavor-agent-toast__action' )
		);

		Object.defineProperty( navigator, 'platform', {
			value: original,
			configurable: true,
		} );
	} );

	test( 'observes nested same-origin iframes added after mount for the undo focus shortcut', async () => {
		setToasts( [
			{
				id: 'newest',
				variant: 'success',
				title: 'Second',
				activityId: 'a2',
				autoDismissMs: 6000,
				interacted: false,
			},
		] );

		const iframe = document.createElement( 'iframe' );
		document.body.appendChild( iframe );

		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		const nestedIframe = iframe.contentDocument.createElement( 'iframe' );
		iframe.contentDocument.body.appendChild( nestedIframe );

		await act( async () => {
			await Promise.resolve();
		} );

		const original = navigator.platform;

		Object.defineProperty( navigator, 'platform', {
			value: 'Linux x86_64',
			configurable: true,
		} );

		act( () => {
			nestedIframe.contentDocument.dispatchEvent(
				new nestedIframe.contentWindow.KeyboardEvent( 'keydown', {
					key: 'u',
					altKey: true,
					ctrlKey: true,
					shiftKey: true,
					bubbles: true,
				} )
			);
		} );

		expect( document.activeElement ).toBe(
			getRegionRoot().querySelector( '.flavor-agent-toast__action' )
		);

		Object.defineProperty( navigator, 'platform', {
			value: original,
			configurable: true,
		} );
	} );

	test( 'toast undo focus shortcut keydown is a no-op when the queue is empty', () => {
		act( () => {
			getRoot().render( <ToastRegion /> );
		} );

		expect( () => {
			act( () => {
				document.dispatchEvent(
					new window.KeyboardEvent( 'keydown', {
						key: 'u',
						altKey: true,
						ctrlKey: true,
						shiftKey: true,
						bubbles: true,
					} )
				);
			} );
		} ).not.toThrow();
	} );
} );
