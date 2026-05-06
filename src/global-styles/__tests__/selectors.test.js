import {
	getCurrentGlobalStylesId,
	getCurrentThemeBaseGlobalStyles,
	getCurrentThemeGlobalStylesVariations,
} from '../selectors';

describe( 'global styles selector adapter', () => {
	test( 'prefers stable current Global Styles selectors before experimental fallbacks', () => {
		const coreSelect = {
			getCurrentGlobalStylesId: jest.fn( () => 19 ),
			__experimentalGetCurrentGlobalStylesId: jest.fn( () => '17' ),
		};

		expect( getCurrentGlobalStylesId( coreSelect ) ).toBe( '19' );
		expect(
			coreSelect.__experimentalGetCurrentGlobalStylesId
		).not.toHaveBeenCalled();
	} );

	test( 'falls back to the current experimental Global Styles selectors', () => {
		expect(
			getCurrentGlobalStylesId( {
				__experimentalGetCurrentGlobalStylesId: () => '17',
			} )
		).toBe( '17' );
		expect(
			getCurrentThemeBaseGlobalStyles( {
				__experimentalGetCurrentThemeBaseGlobalStyles: () => ( {
					styles: { color: { text: 'black' } },
				} ),
			} )
		).toEqual( {
			styles: { color: { text: 'black' } },
		} );
		expect(
			getCurrentThemeGlobalStylesVariations( {
				__experimentalGetCurrentThemeGlobalStylesVariations: () => [
					{ title: 'Default' },
				],
			} )
		).toEqual( [ { title: 'Default' } ] );
	} );

	test( 'normalizes missing or malformed selector results to safe defaults', () => {
		expect( getCurrentGlobalStylesId( {} ) ).toBeNull();
		expect( getCurrentThemeBaseGlobalStyles( {} ) ).toBeNull();
		expect(
			getCurrentThemeGlobalStylesVariations( {
				getCurrentThemeGlobalStylesVariations: () => null,
			} )
		).toEqual( [] );
	} );
} );
