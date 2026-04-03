jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import AIStatusNotice from '../AIStatusNotice';

const { getContainer, getRoot } = setupReactTest();


describe( 'AIStatusNotice', () => {
	test( 'renders nothing without a notice payload', () => {
		act( () => {
			getRoot().render( <AIStatusNotice notice={ null } /> );
		} );

		expect( getContainer().textContent ).toBe( '' );
	} );

	test( 'renders message, action, and dismiss affordances from one shared notice shape', () => {
		const onAction = jest.fn();
		const onDismiss = jest.fn();

		act( () => {
			getRoot().render(
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

		expect( getContainer().textContent ).toContain( 'Applied Refresh hero.' );
		expect( getContainer().textContent ).toContain( 'Undo' );

		const buttons = getContainer().querySelectorAll( 'button' );
		buttons[ 0 ].click();
		buttons[ 1 ].click();

		expect( onAction ).toHaveBeenCalledTimes( 1 );
		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );
} );
