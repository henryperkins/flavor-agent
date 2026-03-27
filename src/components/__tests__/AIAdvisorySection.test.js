// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import AIAdvisorySection from '../AIAdvisorySection';

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

describe( 'AIAdvisorySection', () => {
	test( 'renders the shared advisory framing with description and count', () => {
		act( () => {
			root.render(
				<AIAdvisorySection
					title="Navigation ideas"
					count={ 2 }
					countNoun="idea"
					description="These suggestions stay advisory-only."
				>
					<div>First advisory idea</div>
					<div>Second advisory idea</div>
				</AIAdvisorySection>
			);
		} );

		expect( container.textContent ).toContain( 'Navigation ideas' );
		expect( container.textContent ).toContain( 'Advisory only' );
		expect( container.textContent ).toContain( '2 ideas' );
		expect( container.textContent ).toContain(
			'These suggestions stay advisory-only.'
		);
		expect( container.textContent ).toContain( 'First advisory idea' );
		expect( container.textContent ).toContain( 'Second advisory idea' );
	} );
} );
