// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import AIAdvisorySection from '../AIAdvisorySection';

const { getContainer, getRoot } = setupReactTest();

describe( 'AIAdvisorySection', () => {
	test( 'renders the shared advisory framing with description and count', () => {
		act( () => {
			getRoot().render(
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

		expect( getContainer().textContent ).toContain( 'Navigation ideas' );
		expect( getContainer().textContent ).toContain( 'Advisory only' );
		expect( getContainer().textContent ).toContain( '2 ideas' );
		expect( getContainer().textContent ).toContain(
			'These suggestions stay advisory-only.'
		);
		expect( getContainer().textContent ).toContain( 'First advisory idea' );
		expect( getContainer().textContent ).toContain(
			'Second advisory idea'
		);
	} );
} );
