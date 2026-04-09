jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import AIReviewSection from '../AIReviewSection';

const { getContainer, getRoot } = setupReactTest();

describe( 'AIReviewSection', () => {
	test( 'renders the shared preview frame and confirm/cancel actions', () => {
		const onConfirm = jest.fn();
		const onCancel = jest.fn();

		act( () => {
			getRoot().render(
				<AIReviewSection
					count={ 2 }
					countNoun="operation"
					summary="Review the validated structural changes before mutating content."
					hint="Only the operations shown here will run."
					onConfirm={ onConfirm }
					onCancel={ onCancel }
				>
					<div>Replace header</div>
					<div>Insert hero pattern</div>
				</AIReviewSection>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Review Before Apply' );
		expect( getContainer().textContent ).toContain( 'Executable' );
		expect( getContainer().textContent ).toContain( '2 operations' );
		expect( getContainer().textContent ).toContain(
			'Review the validated structural changes before mutating content.'
		);
		expect( getContainer().textContent ).toContain(
			'Only the operations shown here will run.'
		);

		const actionBar = getContainer().querySelector(
			'.flavor-agent-template-preview__actions'
		);
		const buttons = getContainer().querySelectorAll( 'button' );

		expect( actionBar ).toBeTruthy();
		expect( buttons[ 0 ].className ).toContain(
			'flavor-agent-template-preview__action'
		);
		expect( buttons[ 0 ].className ).toContain(
			'flavor-agent-template-preview__action--secondary'
		);
		expect( buttons[ 1 ].className ).toContain(
			'flavor-agent-template-preview__action'
		);
		expect( buttons[ 1 ].className ).toContain(
			'flavor-agent-template-preview__action--primary'
		);

		buttons[ 0 ].click();
		buttons[ 1 ].click();

		expect( onCancel ).toHaveBeenCalledTimes( 1 );
		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'disables the confirm action when no confirm handler is provided', () => {
		act( () => {
			getRoot().render(
				<AIReviewSection summary="Review the validated structural changes before mutating content." />
			);
		} );

		const confirmButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Confirm Apply' );

		expect( confirmButton ).toBeTruthy();
		expect( confirmButton.disabled ).toBe( true );
	} );
} );
