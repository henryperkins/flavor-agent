const mockOnRefresh = jest.fn();

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

import { createElement } from '@wordpress/element';
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import StaleResultBanner from '../StaleResultBanner';

const { getContainer, getRoot } = setupReactTest();

beforeEach( () => {
	jest.clearAllMocks();
} );

function renderBanner( props = {} ) {
	act( () => {
		getRoot().render( createElement( StaleResultBanner, props ) );
	} );
}

describe( 'StaleResultBanner', () => {
	test( 'renders a stale message', () => {
		renderBanner( { message: 'Context has changed.' } );

		const text = getContainer().textContent;
		expect( text ).toContain( 'Context has changed.' );
	} );

	test( 'renders default message when no message prop is provided', () => {
		renderBanner();

		const text = getContainer().textContent;
		expect( text ).toContain(
			'Context has changed since the last request'
		);
	} );

	test( 'renders a refresh button when onRefresh is provided', () => {
		renderBanner( {
			message: 'Stale results.',
			onRefresh: mockOnRefresh,
		} );

		const button = getContainer().querySelector( 'button' );
		expect( button ).not.toBeNull();
		expect( button.textContent ).toContain( 'Refresh' );
	} );

	test( 'does not render a refresh button when onRefresh is omitted', () => {
		renderBanner( { message: 'Stale results.' } );

		const button = getContainer().querySelector( 'button' );
		expect( button ).toBeNull();
	} );

	test( 'calls onRefresh when the refresh button is clicked', () => {
		renderBanner( {
			message: 'Stale results.',
			onRefresh: mockOnRefresh,
		} );

		const button = getContainer().querySelector( 'button' );
		act( () => {
			button.click();
		} );

		expect( mockOnRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'disables the refresh button when isRefreshing is true', () => {
		renderBanner( {
			message: 'Stale results.',
			onRefresh: mockOnRefresh,
			isRefreshing: true,
		} );

		const button = getContainer().querySelector( 'button' );
		expect( button.disabled ).toBe( true );
		expect( button.textContent ).toContain( '…' );
	} );

	test( 'renders nothing when message is empty', () => {
		renderBanner( { message: '' } );

		expect( getContainer().innerHTML ).toBe( '' );
	} );

	test( 'applies the embedded variant class', () => {
		renderBanner( {
			message: 'Stale.',
			variant: 'embedded',
		} );

		const banner = getContainer().querySelector(
			'.flavor-agent-stale-banner--embedded'
		);
		expect( banner ).not.toBeNull();
	} );

	test( 'has an accessible live region role', () => {
		renderBanner( { message: 'Stale.' } );

		const banner = getContainer().querySelector(
			'[role="status"][aria-live="polite"]'
		);
		expect( banner ).not.toBeNull();
	} );

	test( 'uses a custom refresh label when provided', () => {
		renderBanner( {
			message: 'Stale.',
			onRefresh: mockOnRefresh,
			refreshLabel: 'Re-fetch',
		} );

		const button = getContainer().querySelector( 'button' );
		expect( button.textContent ).toBe( 'Re-fetch' );
	} );
} );
