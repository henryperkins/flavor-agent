const mockBlockPreview = jest.fn( () => null );

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/block-editor', () => ( {
	__experimentalBlockPreview: ( props ) => mockBlockPreview( props ),
	BlockPreview: ( props ) => mockBlockPreview( props ),
} ) );

jest.mock( '@wordpress/i18n', () =>
	require( '../../test-utils/i18n-mock' ).createI18nMock()
);

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
	test( 'shows one original preview and insert action when no changes are needed', () => {
		render( {
			status: 'unchanged',
			reason: 'no_changes_needed',
			changes: [],
		} );
		expect( getContainer().textContent ).toContain( 'No changes needed' );
		expect( getContainer().textContent ).toContain( 'Original pattern' );
		expect( getContainer().textContent ).not.toContain( 'Adapted result' );
		expect( getContainer().textContent ).not.toContain( 'Insert adapted' );
		expect( mockBlockPreview ).toHaveBeenCalledTimes( 1 );
		const insert = [ ...getContainer().querySelectorAll( 'button' ) ].find(
			( node ) => node.textContent === 'Insert original'
		);
		expect( insert.disabled ).toBe( false );
	} );

	test.each( [
		[ 'unsupported_synced_reference', 'Synced patterns' ],
		[ 'adapted_blocks_not_insertable', 'could not be loaded' ],
		[ 'missing_theme_tokens', 'theme presets' ],
		[ 'unsupported_block_support', 'does not support' ],
	] )( 'explains blocked reason %s', ( reason, message ) => {
		render( { status: 'blocked', reason, adaptedBlocks: [], changes: [] } );
		expect(
			getContainer().querySelector( '[role="status"]' ).textContent
		).toContain( message );
		expect( getContainer().textContent ).not.toContain(
			'could not build a safe adaptation'
		);
	} );

	test.each( [
		[ 'font-size', 'The font size preset “gigantic” is not available' ],
		[ 'color', 'The color preset “gigantic” is not available' ],
		[ 'border-radius', 'The preset “gigantic” is not available' ],
	] )( 'explains an unresolved %s theme preset', ( presetType, message ) => {
		render( {
			status: 'blocked',
			reason: 'unresolved_theme_preset',
			diagnostics: [
				{
					code: 'unresolved_theme_preset',
					presetType,
					value: 'gigantic',
				},
			],
			adaptedBlocks: [],
			changes: [],
		} );
		expect( getContainer().textContent ).toContain( message );
		expect( getContainer().textContent ).not.toContain(
			'No changes needed'
		);
	} );

	test( 'explains preserved unmapped colors even when other adaptations are ready', () => {
		render( {
			diagnostics: [
				{ code: 'unmapped_color_preset', value: 'unknown-brand' },
			],
		} );
		expect( getContainer().textContent ).toContain( 'unknown-brand' );
		expect( getContainer().textContent ).toContain( 'kept unchanged' );
	} );

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
