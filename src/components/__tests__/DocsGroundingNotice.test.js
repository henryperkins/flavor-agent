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

	test( 'renders a non-dismissible warning notice from normalized metadata', () => {
		act( () => {
			getRoot().render(
				<DocsGroundingNotice
					warning={ {
						status: 'grounded',
						coverageStatus: 'missing-current-release-cycle',
					} }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain(
			'Developer Docs grounding is trusted, but current release-cycle sources have not been confirmed. Review current WordPress docs before applying.'
		);
		expect(
			getContainer().querySelector( '[data-status="warning"]' )
		).not.toBeNull();
		expect(
			getContainer().querySelector( '[data-dismiss="true"]' )
		).toBeNull();
	} );
} );
