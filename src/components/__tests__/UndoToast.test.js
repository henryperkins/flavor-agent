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

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import UndoToast from '../UndoToast';

const { getContainer, getRoot } = setupReactTest();

function setReducedMotion( matches ) {
	window.matchMedia = jest.fn().mockImplementation( ( query ) => ( {
		matches: query === '(prefers-reduced-motion: reduce)' && matches,
		media: query,
		onchange: null,
		addEventListener: () => {},
		removeEventListener: () => {},
		addListener: () => {},
		removeListener: () => {},
		dispatchEvent: () => false,
	} ) );
}

function renderToast( props = {} ) {
	const merged = {
		id: 'toast-1',
		variant: 'success',
		title: 'Block updated',
		detail: 'core/heading · attr=fontWeight',
		undoLabel: 'Undo',
		autoDismissMs: 6000,
		onUndo: jest.fn(),
		onDismiss: jest.fn(),
		undoDisabled: false,
		onInteractionChange: jest.fn(),
		...props,
	};

	act( () => {
		getRoot().render( <UndoToast { ...merged } /> );
	} );

	return merged;
}

function getToast() {
	return getContainer().querySelector( '.flavor-agent-toast' );
}

function getProgress() {
	return getContainer().querySelector( '.flavor-agent-toast__progress' );
}

function getUndoButton() {
	return getContainer().querySelector( '.flavor-agent-toast__action' );
}

function getCloseButton() {
	return getContainer().querySelector( '.flavor-agent-toast__close' );
}

describe( 'UndoToast — rendering', () => {
	beforeEach( () => {
		setReducedMotion( false );
	} );

	test( 'renders title, detail, and the success variant icon', () => {
		renderToast( {
			variant: 'success',
			title: 'Block updated',
			detail: 'core/heading · attr=fontWeight',
		} );

		expect( getContainer().textContent ).toContain( 'Block updated' );
		expect( getContainer().textContent ).toContain(
			'core/heading · attr=fontWeight'
		);
		expect(
			getContainer().querySelector( '.flavor-agent-toast__icon--success' )
		).toBeTruthy();
	} );

	test( 'renders the error variant with the error hint', () => {
		renderToast( {
			variant: 'error',
			title: 'Undo failed',
			detail: 'core/heading',
			errorHint: 'Server returned 500.',
		} );

		expect(
			getContainer().querySelector( '.flavor-agent-toast__icon--error' )
		).toBeTruthy();
		expect( getContainer().textContent ).toContain(
			'Server returned 500.'
		);
	} );

	test( 'Undo control is tabbable and aria-disabled when undoDisabled', () => {
		renderToast( { undoDisabled: true } );

		const undoBtn = getUndoButton();

		expect( undoBtn.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( undoBtn.getAttribute( 'tabindex' ) ).toBe( '0' );
		expect( undoBtn.getAttribute( 'title' ) ).toBe(
			'Undo unavailable for this change'
		);
	} );

	test( 'clicking Undo invokes onUndo with the toast id when enabled', () => {
		const props = renderToast();

		act( () => {
			getUndoButton().click();
		} );

		expect( props.onUndo ).toHaveBeenCalledWith( 'toast-1' );
	} );

	test( 'clicking Undo when disabled does not invoke onUndo', () => {
		const props = renderToast( { undoDisabled: true } );

		act( () => {
			getUndoButton().click();
		} );

		expect( props.onUndo ).not.toHaveBeenCalled();
	} );

	test( 'clicking Close invokes onDismiss', () => {
		const props = renderToast();

		act( () => {
			getCloseButton().click();
		} );

		expect( props.onDismiss ).toHaveBeenCalledWith( 'toast-1' );
	} );

	test( 'Escape key invokes onDismiss', () => {
		const props = renderToast();

		act( () => {
			const event = new window.KeyboardEvent( 'keydown', {
				key: 'Escape',
				bubbles: true,
			} );
			getToast().dispatchEvent( event );
		} );

		expect( props.onDismiss ).toHaveBeenCalledWith( 'toast-1' );
	} );
} );

describe( 'UndoToast — interaction reporting', () => {
	beforeEach( () => {
		setReducedMotion( false );
	} );

	test( 'mouseenter / mouseleave toggle the is-paused class on the progress bar', () => {
		renderToast();

		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseover', { bubbles: true } )
			);
		} );

		expect( getProgress().className ).toContain( 'is-paused' );

		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseout', { bubbles: true } )
			);
		} );

		expect( getProgress().className ).not.toContain( 'is-paused' );
	} );

	test( 'mouseenter / mouseleave call onInteractionChange', () => {
		const props = renderToast();

		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseover', { bubbles: true } )
			);
		} );

		expect( props.onInteractionChange ).toHaveBeenCalledWith(
			'toast-1',
			true
		);

		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseout', { bubbles: true } )
			);
		} );

		expect( props.onInteractionChange ).toHaveBeenLastCalledWith(
			'toast-1',
			false
		);
	} );
} );

describe( 'UndoToast — auto-dismiss timer', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		setReducedMotion( false );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	test( 'dismisses after autoDismissMs of unpaused time', () => {
		const props = renderToast( { autoDismissMs: 6000 } );

		act( () => {
			jest.advanceTimersByTime( 6000 );
		} );

		expect( props.onDismiss ).toHaveBeenCalledWith( 'toast-1' );
	} );

	test( 'pause-on-hover halts the timer; remaining time resumes after mouseleave', () => {
		const props = renderToast( { autoDismissMs: 6000 } );

		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );

		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseover', { bubbles: true } )
			);
		} );

		// While paused the timer must not fire even after a long advance.
		act( () => {
			jest.advanceTimersByTime( 10000 );
		} );

		expect( props.onDismiss ).not.toHaveBeenCalled();

		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseout', { bubbles: true } )
			);
		} );

		// Remaining 3s of the original 6s.
		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );

		expect( props.onDismiss ).toHaveBeenCalledWith( 'toast-1' );
	} );

	test( 'multi-interaction cycle fires only after cumulative non-paused duration', () => {
		const props = renderToast( { autoDismissMs: 6000 } );

		// 2s elapsed, then hover.
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseover', { bubbles: true } )
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 5000 );
		} );

		// Unhover, then 1s elapsed.
		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseout', { bubbles: true } )
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );

		// Hover again, more time, unhover.
		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseover', { bubbles: true } )
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 30000 );
		} );

		expect( props.onDismiss ).not.toHaveBeenCalled();

		act( () => {
			getToast().dispatchEvent(
				new window.MouseEvent( 'mouseout', { bubbles: true } )
			);
		} );

		// 6 - 2 - 1 = 3s remaining.
		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );

		expect( props.onDismiss ).toHaveBeenCalledWith( 'toast-1' );
	} );

	test( 'reduced-motion disables auto-dismiss entirely', () => {
		setReducedMotion( true );

		const props = renderToast( { autoDismissMs: 6000 } );

		act( () => {
			jest.advanceTimersByTime( 60000 );
		} );

		expect( props.onDismiss ).not.toHaveBeenCalled();
	} );
} );
