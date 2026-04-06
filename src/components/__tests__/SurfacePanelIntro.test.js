// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import SurfacePanelIntro from '../SurfacePanelIntro';

const { getContainer, getRoot } = setupReactTest();

describe( 'SurfacePanelIntro', () => {
	test( 'renders eyebrow, intro copy, and meta', () => {
		act( () => {
			getRoot().render(
				<SurfacePanelIntro
					eyebrow="Block Styles"
					introCopy="Style suggestions for this block."
					meta={
						<span className="flavor-agent-pill">3 suggestions</span>
					}
				/>
			);
		} );

		const text = getContainer().textContent;
		expect( text ).toContain( 'Block Styles' );
		expect( text ).toContain( 'Style suggestions for this block.' );
		expect( text ).toContain( '3 suggestions' );
	} );

	test( 'renders children after intro copy', () => {
		act( () => {
			getRoot().render(
				<SurfacePanelIntro eyebrow="Test">
					<div data-testid="child">Extra content</div>
				</SurfacePanelIntro>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Extra content' );
	} );

	test( 'applies custom className', () => {
		act( () => {
			getRoot().render(
				<SurfacePanelIntro
					eyebrow="Custom"
					className="flavor-agent-style-surface__intro"
				/>
			);
		} );

		const intro = getContainer().querySelector(
			'.flavor-agent-panel__intro'
		);
		expect( intro.className ).toContain(
			'flavor-agent-style-surface__intro'
		);
	} );

	test( 'omits empty sections', () => {
		act( () => {
			getRoot().render( <SurfacePanelIntro /> );
		} );

		expect(
			getContainer().querySelector( '.flavor-agent-panel__eyebrow' )
		).toBeNull();
		expect(
			getContainer().querySelector( '.flavor-agent-panel__intro-copy' )
		).toBeNull();
		expect(
			getContainer().querySelector( '.flavor-agent-card__meta' )
		).toBeNull();
	} );
} );
