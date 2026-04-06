// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import RecommendationLane from '../RecommendationLane';

const { getContainer, getRoot } = setupReactTest();

describe( 'RecommendationLane', () => {
	test( 'renders shared lane framing with tone and count badges', () => {
		act( () => {
			getRoot().render(
				<RecommendationLane
					title="Apply now"
					tone="Apply now"
					count={ 2 }
					countNoun="suggestion"
					description="These changes can run safely in place."
				>
					<div>First suggestion</div>
					<div>Second suggestion</div>
				</RecommendationLane>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Apply now' );
		expect( getContainer().textContent ).toContain( '2 suggestions' );
		expect( getContainer().textContent ).toContain(
			'These changes can run safely in place.'
		);
		expect( getContainer().textContent ).toContain( 'First suggestion' );
		expect( getContainer().textContent ).toContain( 'Second suggestion' );
	} );
} );
