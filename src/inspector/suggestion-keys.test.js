import { getSuggestionKey, getSuggestionPanel } from './suggestion-keys';

describe( 'suggestion key helpers', () => {
	test( 'falls back missing panels to the general bucket', () => {
		const suggestion = {
			label: 'Keep aspect ratio',
		};

		expect( getSuggestionPanel( suggestion ) ).toBe( 'general' );
		expect( getSuggestionKey( suggestion ) ).toMatch(
			/^general-keep-aspect-ratio-[a-z0-9]+$/
		);
	} );

	test( 'preserves explicit panel names in keys', () => {
		expect(
			getSuggestionKey( {
				panel: 'layout',
				label: 'Match content width',
			} )
		).toMatch( /^layout-match-content-width-[a-z0-9]+$/ );
	} );

	test( 'reuses an explicit suggestion key when one is provided', () => {
		expect(
			getSuggestionKey( {
				suggestionKey: 'style-book-1',
				panel: 'color',
				label: 'Use accent canvas',
			} )
		).toBe( 'style-book-1' );
	} );

	test( 'avoids collisions when labels match but payloads differ', () => {
		const first = getSuggestionKey( {
			panel: 'color',
			label: 'Use accent',
			attributeUpdates: {
				style: {
					color: {
						text: 'var:preset|color|accent',
					},
				},
			},
		} );
		const second = getSuggestionKey( {
			panel: 'color',
			label: 'Use accent',
			attributeUpdates: {
				style: {
					color: {
						background: 'var:preset|color|accent',
					},
				},
			},
		} );

		expect( first ).not.toBe( second );
	} );
} );
