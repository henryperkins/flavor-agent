jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import InlineActionFeedback from '../InlineActionFeedback';

const { getContainer, getRoot } = setupReactTest();

describe( 'InlineActionFeedback', () => {
	test( 'renders the feedback copy without an action control when no handler is provided', () => {
		act( () => {
			getRoot().render(
				<InlineActionFeedback
					message="Applied the updated hero copy."
					actionLabel="Undo"
				/>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Applied' );
		expect( getContainer().textContent ).toContain(
			'Applied the updated hero copy.'
		);
		expect( getContainer().querySelector( 'button' ) ).toBeNull();
	} );

	test( 'renders and runs the inline action when a handler is provided', () => {
		const onAction = jest.fn();

		act( () => {
			getRoot().render(
				<InlineActionFeedback
					message="Applied the updated hero copy."
					actionLabel="Undo"
					onAction={ onAction }
					compact
				/>
			);
		} );

		const status = getContainer().querySelector( '[role="status"]' );
		const actionButton = getContainer().querySelector( 'button' );

		expect( status?.className ).toContain(
			'flavor-agent-inline-feedback--compact'
		);
		expect( actionButton?.textContent ).toBe( 'Undo' );

		act( () => {
			actionButton.click();
		} );

		expect( onAction ).toHaveBeenCalledTimes( 1 );
	} );
} );
