jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '../../utils/capability-flags', () => ( {
	getCapabilityNotice: jest.fn(),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );
const { getCapabilityNotice } = require( '../../utils/capability-flags' );

import CapabilityNotice from '../CapabilityNotice';

const { getContainer, getRoot } = setupReactTest();

describe( 'CapabilityNotice', () => {
	test( 'renders nothing when no notice is available', () => {
		getCapabilityNotice.mockReturnValue( null );

		act( () => {
			getRoot().render( <CapabilityNotice surface="block" /> );
		} );

		expect( getContainer().textContent ).toBe( '' );
	} );

	test( 'renders a fallback action link from a single notice action shape', () => {
		getCapabilityNotice.mockReturnValue( {
			status: 'warning',
			message: 'Connect a provider first.',
			actionLabel: 'Open settings',
			actionHref: '/wp-admin/options-general.php?page=flavor-agent',
			actions: [],
		} );

		act( () => {
			getRoot().render( <CapabilityNotice surface="block" /> );
		} );

		expect( getContainer().textContent ).toContain(
			'Connect a provider first.'
		);

		const notice = getContainer().querySelector( '[role="alert"]' );
		const actionLink = getContainer().querySelector( 'a' );

		expect( notice?.getAttribute( 'data-status' ) ).toBe( 'warning' );
		expect( actionLink?.textContent ).toBe( 'Open settings' );
		expect( actionLink?.getAttribute( 'href' ) ).toBe(
			'/wp-admin/options-general.php?page=flavor-agent'
		);
	} );
} );
