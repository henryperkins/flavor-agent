jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import RecommendationHero from '../RecommendationHero';

const { getContainer, getRoot } = setupReactTest();

describe( 'RecommendationHero', () => {
	test( 'renders tone, rationale, and actions', () => {
		const onPrimaryAction = jest.fn();
		const onSecondaryAction = jest.fn();

		act( () => {
			getRoot().render(
				<RecommendationHero
					title="Refresh spacing and alignment"
					description="This suggestion is safe to apply on the current block."
					tone="Apply now"
					why="It updates only local attributes and preserves existing content."
					primaryActionLabel="Apply"
					onPrimaryAction={ onPrimaryAction }
					secondaryActionLabel="Review"
					onSecondaryAction={ onSecondaryAction }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain(
			'Refresh spacing and alignment'
		);
		expect( getContainer().textContent ).toContain( 'Apply now' );
		expect( getContainer().textContent ).toContain(
			'preserves existing content'
		);
		expect(
			getContainer().querySelector( '.flavor-agent-pill--apply' )
		).not.toBeNull();

		const buttons = getContainer().querySelectorAll( 'button' );
		buttons[ 0 ].click();
		buttons[ 1 ].click();

		expect( onSecondaryAction ).toHaveBeenCalledTimes( 1 );
		expect( onPrimaryAction ).toHaveBeenCalledTimes( 1 );
	} );
} );
