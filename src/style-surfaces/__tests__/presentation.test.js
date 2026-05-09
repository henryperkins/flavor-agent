jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

import {
	formatStyleBadgeLabel,
	formatStyleOperation,
	getStyleSuggestionToneLabel,
	isInlineStyleNotice,
	StyleSuggestionCard,
} from '../presentation';

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

const { getContainer, getRoot } = setupReactTest();

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

	test( 'labels style review controls with the suggestion name', async () => {
		await act( async () => {
			getRoot().render(
				<div>
					<StyleSuggestionCard
						suggestion={ {
							label: 'Tune the button contrast',
							suggestionKey: 'tune-button-contrast',
							tone: 'executable',
						} }
						onReview={ jest.fn() }
					/>
					<StyleSuggestionCard
						suggestion={ {
							label: 'Switch to Midnight',
							suggestionKey: 'switch-midnight',
							tone: 'executable',
						} }
						isSelected
						onReview={ jest.fn() }
					/>
				</div>
			);
		} );

		const reviewButtons = Array.from(
			getContainer().querySelectorAll( '.flavor-agent-card__apply' )
		);
		const reviewButton = reviewButtons.find(
			( button ) => button.textContent === 'Review'
		);
		const selectedButton = reviewButtons.find(
			( button ) => button.textContent === 'Reviewing'
		);

		expect( reviewButton?.getAttribute( 'aria-label' ) ).toBe(
			'Review Tune the button contrast'
		);
		expect( selectedButton?.getAttribute( 'aria-label' ) ).toBe(
			'Reviewing Switch to Midnight'
		);
	} );
} );
