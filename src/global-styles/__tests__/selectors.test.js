const fs = require( 'fs' );
const path = require( 'path' );

import {
	getCurrentGlobalStylesId,
	getCurrentThemeBaseGlobalStyles,
	getCurrentThemeGlobalStylesVariations,
} from '../selectors';

const REPO_ROOT = path.join( __dirname, '../../..' );

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

	test( 'fails closed when Global Styles selector calls throw', () => {
		const throwingCoreSelect = {
			getCurrentGlobalStylesId: () => {
				throw new Error( 'selector unavailable' );
			},
			__experimentalGetCurrentGlobalStylesId: () => {
				throw new Error( 'experimental unavailable' );
			},
			getCurrentThemeBaseGlobalStyles: () => {
				throw new Error( 'base unavailable' );
			},
			__experimentalGetCurrentThemeBaseGlobalStyles: () => {
				throw new Error( 'experimental base unavailable' );
			},
			getCurrentThemeGlobalStylesVariations: () => {
				throw new Error( 'variations unavailable' );
			},
			__experimentalGetCurrentThemeGlobalStylesVariations: () => {
				throw new Error( 'experimental variations unavailable' );
			},
		};

		expect( getCurrentGlobalStylesId( throwingCoreSelect ) ).toBeNull();
		expect(
			getCurrentThemeBaseGlobalStyles( throwingCoreSelect )
		).toBeNull();
		expect(
			getCurrentThemeGlobalStylesVariations( throwingCoreSelect )
		).toEqual( [] );
	} );

	test( 'documents the selector adapter as the allowed Global Styles experimental boundary', () => {
		const guidance = fs.readFileSync(
			path.join( REPO_ROOT, 'CLAUDE.md' ),
			'utf8'
		);

		expect( guidance ).toContain( 'src/global-styles/selectors.js' );
	} );
} );
