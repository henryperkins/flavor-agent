import { getSuggestionKey, getSuggestionPanel } from './suggestion-keys';

describe( 'suggestion key helpers', () => {
	test( 'falls back missing panels to the general bucket', () => {
		const suggestion = {
			label: 'Keep aspect ratio',
		};

		expect( getSuggestionPanel( suggestion ) ).toBe( 'general' );
		expect( getSuggestionKey( suggestion ) ).toBe(
			'general-Keep aspect ratio'
		);
	} );

	test( 'preserves explicit panel names in keys', () => {
		expect(
			getSuggestionKey( {
				panel: 'layout',
				label: 'Match content width',
			} )
		).toBe( 'layout-Match content width' );
	} );
} );
