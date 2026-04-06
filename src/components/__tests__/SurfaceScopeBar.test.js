// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import SurfaceScopeBar from '../SurfaceScopeBar';

const { getContainer, getRoot } = setupReactTest();

describe( 'SurfaceScopeBar', () => {
	test( 'renders nothing when no result and no scope details', () => {
		act( () => {
			getRoot().render(
				<SurfaceScopeBar scopeLabel="Test" hasResult={ false } />
			);
		} );

		expect( getContainer().textContent ).toContain( 'Test' );
		expect( getContainer().textContent ).not.toContain( 'Current' );
		expect( getContainer().textContent ).not.toContain( 'Stale' );
	} );

	test( 'renders scope details even without a result', () => {
		act( () => {
			getRoot().render(
				<SurfaceScopeBar
					scopeLabel="Template Part"
					scopeDetails={ [ 'Area: Header', 'Slug: header' ] }
					hasResult={ false }
				/>
			);
		} );

		const text = getContainer().textContent;
		expect( text ).toContain( 'Template Part' );
		expect( text ).toContain( 'Area: Header' );
		expect( text ).toContain( 'Slug: header' );
	} );

	test( 'shows "Current" pill when fresh', () => {
		act( () => {
			getRoot().render(
				<SurfaceScopeBar scopeLabel="Global Styles" isFresh hasResult />
			);
		} );

		expect( getContainer().textContent ).toContain( 'Current' );
		expect( getContainer().textContent ).not.toContain( 'Stale' );
	} );

	test( 'shows "Stale" pill and message when not fresh', () => {
		act( () => {
			getRoot().render(
				<SurfaceScopeBar
					scopeLabel="Global Styles"
					isFresh={ false }
					hasResult
					staleMessage="Context has changed."
				/>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Stale' );
		expect( getContainer().textContent ).toContain(
			'Context has changed.'
		);
		expect(
			getContainer().querySelector( '.flavor-agent-scope-bar--stale' )
		).not.toBeNull();
	} );

	test( 'does not show freshness indicator without a result', () => {
		act( () => {
			getRoot().render(
				<SurfaceScopeBar
					scopeLabel="Test"
					scopeDetails={ [ 'detail' ] }
					isFresh={ false }
					hasResult={ false }
				/>
			);
		} );

		expect( getContainer().textContent ).not.toContain( 'Current' );
		expect( getContainer().textContent ).not.toContain( 'Stale' );
	} );
} );
