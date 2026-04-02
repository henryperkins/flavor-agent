jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import AIReviewSection from '../AIReviewSection';

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

describe( 'AIReviewSection', () => {
	test( 'renders the shared preview frame and confirm/cancel actions', () => {
		const onConfirm = jest.fn();
		const onCancel = jest.fn();

		act( () => {
			root.render(
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

		expect( container.textContent ).toContain( 'Review Before Apply' );
		expect( container.textContent ).toContain( 'Executable' );
		expect( container.textContent ).toContain( '2 operations' );
		expect( container.textContent ).toContain(
			'Review the validated structural changes before mutating content.'
		);
		expect( container.textContent ).toContain(
			'Only the operations shown here will run.'
		);

		const buttons = container.querySelectorAll( 'button' );
		buttons[ 0 ].click();
		buttons[ 1 ].click();

		expect( onCancel ).toHaveBeenCalledTimes( 1 );
		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
	} );
} );
