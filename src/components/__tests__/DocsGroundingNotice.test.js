jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import DocsGroundingNotice from '../DocsGroundingNotice';

const { getContainer, getRoot } = setupReactTest();

describe( 'DocsGroundingNotice', () => {
	test( 'renders nothing without warning metadata', () => {
		act( () => {
			getRoot().render( <DocsGroundingNotice warning={ null } /> );
		} );

		expect( getContainer().textContent ).toBe( '' );
	} );

	test( 'renders a non-dismissible info notice from the derived warning', () => {
		act( () => {
			getRoot().render(
				<DocsGroundingNotice
					warning={ {
						tone: 'info',
						message:
							'Suggestions are running without developer-docs grounding right now. They are still usable; grounding will return when the search backend is reachable.',
					} }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain(
			'running without developer-docs grounding'
		);
		expect(
			getContainer().querySelector( '[data-status="info"]' )
		).not.toBeNull();
		expect(
			getContainer().querySelector( '[data-dismiss="true"]' )
		).toBeNull();
	} );
} );
