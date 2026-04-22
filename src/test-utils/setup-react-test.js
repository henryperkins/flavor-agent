/* global afterEach, beforeEach */

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

/**
 * Sets up a React 18 DOM test harness with container/root lifecycle.
 *
 * Returns getter functions so callers always see the current beforeEach value.
 *
 * @return {{ getContainer: () => HTMLDivElement, getRoot: () => import('react-dom/client').Root }} Test DOM accessors.
 */
function setupReactTest() {
	let container = null;
	let root = null;

	// eslint-disable-next-line no-undef
	window.IS_REACT_ACT_ENVIRONMENT = true;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( async () => {
		if ( root ) {
			await act( async () => {
				root.unmount();
			} );
		}

		if ( container ) {
			container.remove();
		}

		root = null;
		container = null;
	} );

	return {
		getContainer: () => container,
		getRoot: () => root,
	};
}

module.exports = { setupReactTest };
