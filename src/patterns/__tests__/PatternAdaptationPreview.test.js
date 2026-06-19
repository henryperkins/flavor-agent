const mockBlockPreview = jest.fn( () => null );

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/block-editor', () => ( {
	__experimentalBlockPreview: ( props ) => mockBlockPreview( props ),
	BlockPreview: ( props ) => mockBlockPreview( props ),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( value ) => value,
	sprintf: ( template, ...values ) => {
		let i = 0;
		return template.replace( /%s/g, () => values[ i++ ] ?? '' );
	},
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import PatternAdaptationPreview from '../PatternAdaptationPreview';

const { getContainer, getRoot } = setupReactTest();

function render( props ) {
	act( () => {
		getRoot().render(
			<PatternAdaptationPreview
				title="Hero"
				status="ready"
				changes={ [
					{
						reason: 'theme_color_alignment',
						blockName: 'core/group',
					},
				] }
				blocks={ [ { name: 'core/group' } ] }
				isStale={ false }
				onInsertAdapted={ jest.fn() }
				onInsertOriginal={ jest.fn() }
				onClose={ jest.fn() }
				{ ...props }
			/>
		);
	} );
}

beforeEach( () => {
	mockBlockPreview.mockClear();
} );

describe( 'PatternAdaptationPreview', () => {
	test( 'renders BlockPreview with the adapted blocks when ready', () => {
		render();
		expect( mockBlockPreview ).toHaveBeenCalledWith(
			expect.objectContaining( { blocks: [ { name: 'core/group' } ] } )
		);
		expect( getContainer().textContent ).toContain( 'Insert adapted' );
		expect( getContainer().textContent ).toContain( 'Insert original' );
	} );

	test( 'sets an i18n aria-label on the adapted insert button', () => {
		render();
		const adaptedButton = [
			...getContainer().querySelectorAll( 'button' ),
		].find( ( node ) => node.textContent === 'Insert adapted' );

		expect( adaptedButton.getAttribute( 'aria-label' ) ).toBe(
			'Insert adapted Hero'
		);
	} );

	test( 'invokes onInsertAdapted from the adapted button', () => {
		const onInsertAdapted = jest.fn();
		render( { onInsertAdapted } );
		const button = [ ...getContainer().querySelectorAll( 'button' ) ].find(
			( node ) => node.textContent === 'Insert adapted'
		);
		act( () => {
			button.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		} );
		expect( onInsertAdapted ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'disables Insert adapted and hides the preview when stale', () => {
		render( { isStale: true, status: 'stale' } );
		const button = [ ...getContainer().querySelectorAll( 'button' ) ].find(
			( node ) => node.textContent === 'Insert adapted'
		);
		expect( button.disabled ).toBe( true );
		expect( mockBlockPreview ).not.toHaveBeenCalled();
	} );

	test( 'shows a blocked message and only original/close when blocked', () => {
		render( { status: 'blocked', blocks: [], changes: [] } );
		expect( getContainer().textContent ).toContain( 'Insert original' );
		expect( mockBlockPreview ).not.toHaveBeenCalled();
	} );
} );
