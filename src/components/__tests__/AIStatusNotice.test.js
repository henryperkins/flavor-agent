jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import AIStatusNotice from '../AIStatusNotice';

let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => {
		root.unmount();
	} );
	container.remove();
} );

describe( 'AIStatusNotice', () => {
	test( 'renders nothing without a notice payload', () => {
		act( () => {
			root.render( <AIStatusNotice notice={ null } /> );
		} );

		expect( container.textContent ).toBe( '' );
	} );

	test( 'renders message, action, and dismiss affordances from one shared notice shape', () => {
		const onAction = jest.fn();
		const onDismiss = jest.fn();

		act( () => {
			root.render(
				<AIStatusNotice
					notice={ {
						tone: 'success',
						message: 'Applied Refresh hero.',
						actionLabel: 'Undo',
						isDismissible: true,
					} }
					onAction={ onAction }
					onDismiss={ onDismiss }
				/>
			);
		} );

		expect( container.textContent ).toContain( 'Applied Refresh hero.' );
		expect( container.textContent ).toContain( 'Undo' );

		const buttons = container.querySelectorAll( 'button' );
		buttons[ 0 ].click();
		buttons[ 1 ].click();

		expect( onAction ).toHaveBeenCalledTimes( 1 );
		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );
} );
