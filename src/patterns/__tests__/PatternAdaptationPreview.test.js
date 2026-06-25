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
						reason: 'nearby_heading_hierarchy',
						blockName: 'core/heading',
						attribute: 'level',
						from: 5,
						to: 3,
					},
				] }
				originalBlocks={ [
					{ name: 'core/heading', attributes: { level: 5 } },
				] }
				adaptedBlocks={ [
					{ name: 'core/heading', attributes: { level: 3 } },
				] }
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
	test( 'renders labeled original and adapted BlockPreview panels when ready', () => {
		render();
		expect( getContainer().textContent ).toContain( 'Original pattern' );
		expect( getContainer().textContent ).toContain( 'Adapted result' );
		expect( mockBlockPreview ).toHaveBeenNthCalledWith(
			1,
			expect.objectContaining( {
				blocks: [
					{
						name: 'core/heading',
						attributes: { level: 5 },
					},
				],
			} )
		);
		expect( mockBlockPreview ).toHaveBeenNthCalledWith(
			2,
			expect.objectContaining( {
				blocks: [
					{
						name: 'core/heading',
						attributes: { level: 3 },
					},
				],
			} )
		);
		expect( getContainer().textContent ).toContain( 'Insert adapted' );
		expect( getContainer().textContent ).toContain( 'Insert original' );
	} );

	test( 'renders a deterministic scalar change summary row', () => {
		render();
		expect( getContainer().textContent ).toContain(
			'Heading level matched to nearby headings - core/heading - level - 5 -> 3'
		);
	} );

	test( 'flattens nested object diffs to changed leaf paths only', () => {
		render( {
			changes: [
				{
					reason: 'theme_spacing_alignment',
					blockName: 'core/group',
					attribute: 'style',
					from: {
						spacing: {
							padding: {
								top: 'var:preset|spacing|80',
								bottom: 'var:preset|spacing|80',
							},
						},
					},
					to: {
						spacing: {
							padding: {
								top: 'var:preset|spacing|60',
								bottom: 'var:preset|spacing|80',
							},
						},
					},
				},
			],
		} );

		expect( getContainer().textContent ).toContain(
			'Spacing aligned to theme presets - core/group - style.spacing.padding.top - var:preset|spacing|80 -> var:preset|spacing|60'
		);
		expect( getContainer().textContent ).not.toContain(
			'style.spacing.padding.bottom'
		);
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
		expect( getContainer().textContent ).not.toContain(
			'Original pattern'
		);
		expect( getContainer().textContent ).not.toContain( 'Adapted result' );
	} );

	test( 'shows a blocked message and only original/close when blocked', () => {
		render( {
			status: 'blocked',
			originalBlocks: [],
			adaptedBlocks: [],
			changes: [],
		} );
		expect( getContainer().textContent ).toContain( 'Insert original' );
		expect( mockBlockPreview ).not.toHaveBeenCalled();
		expect( getContainer().textContent ).not.toContain(
			'Original pattern'
		);
		expect( getContainer().textContent ).not.toContain( 'Adapted result' );
	} );
} );
