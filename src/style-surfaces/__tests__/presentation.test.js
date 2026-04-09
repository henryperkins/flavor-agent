import {
	formatStyleBadgeLabel,
	formatStyleOperation,
	getStyleSuggestionToneLabel,
	isInlineStyleNotice,
} from '../presentation';

describe( 'style-surface presentation helpers', () => {
	test( 'formats theme-backed preset operations consistently across style surfaces', () => {
		expect(
			formatStyleOperation( {
				type: 'set_styles',
				path: [ 'color', 'background' ],
				value: 'var:preset|color|accent',
			} )
		).toBe( 'color.background → accent' );

		expect(
			formatStyleOperation( {
				type: 'set_block_styles',
				path: [ 'spacing', 'blockGap' ],
				presetSlug: '40',
			} )
		).toBe( 'spacing.blockGap → 40' );
	} );

	test( 'formats theme variation operations and fallback review copy', () => {
		expect(
			formatStyleOperation( {
				type: 'set_theme_variation',
				variationTitle: 'Midnight',
			} )
		).toBe( 'Switch to variation: Midnight' );

		expect(
			formatStyleOperation( {
				type: 'unknown_operation',
			} )
		).toBe( 'Review this change before applying it.' );
	} );

	test( 'normalizes shared style presentation metadata', () => {
		expect( isInlineStyleNotice( { source: 'apply' } ) ).toBe( true );
		expect( isInlineStyleNotice( { source: 'undo' } ) ).toBe( true );
		expect( isInlineStyleNotice( { source: 'request' } ) ).toBe( false );
		expect( formatStyleBadgeLabel( 'theme_variation' ) ).toBe(
			'Theme Variation'
		);
		expect(
			getStyleSuggestionToneLabel( {
				tone: 'executable',
			} )
		).toBe( 'Review first' );
		expect(
			getStyleSuggestionToneLabel( {
				tone: 'manual',
			} )
		).toBe( 'Manual ideas' );
	} );
} );
