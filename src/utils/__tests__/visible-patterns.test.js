jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

const mockRegistrySelect = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockRegistrySelect( ...args ),
} ) );

import { extractPatternNames } from '../pattern-names';
import { getVisiblePatternNames } from '../visible-patterns';

describe( 'visible-patterns', () => {
	beforeEach( () => {
		mockRegistrySelect.mockReset();
	} );

	test( 'extractPatternNames returns unique pattern names in order', () => {
		expect(
			extractPatternNames( [
				{ name: 'theme/header' },
				{ name: 'theme/footer' },
				{ name: 'theme/header' },
			] )
		).toEqual( [ 'theme/header', 'theme/footer' ] );
	} );

	test( 'extractPatternNames ignores missing names and non-arrays', () => {
		expect(
			extractPatternNames( [
				{ name: 'theme/hero' },
				{},
				null,
				{ name: '' },
			] )
		).toEqual( [ 'theme/hero' ] );
		expect( extractPatternNames( null ) ).toEqual( [] );
	} );

	test( 'getVisiblePatternNames passes the provided rootClientId to allowed-pattern lookup', () => {
		const blockEditor = {
			__experimentalGetAllowedPatterns: jest
				.fn()
				.mockReturnValue( [
					{ name: 'theme/hero' },
					{ name: 'theme/hero' },
					{ name: 'theme/footer' },
				] ),
		};

		mockRegistrySelect.mockReturnValue( blockEditor );

		expect( getVisiblePatternNames( 'root-123' ) ).toEqual( [
			'theme/hero',
			'theme/footer',
		] );
		expect(
			blockEditor.__experimentalGetAllowedPatterns
		).toHaveBeenCalledWith( 'root-123' );
	} );

	test( 'getVisiblePatternNames keeps null root as the top-level fallback', () => {
		const blockEditor = {
			__experimentalGetAllowedPatterns: jest
				.fn()
				.mockReturnValue( [ { name: 'theme/index' } ] ),
		};

		mockRegistrySelect.mockReturnValue( blockEditor );

		expect( getVisiblePatternNames() ).toEqual( [ 'theme/index' ] );
		expect(
			blockEditor.__experimentalGetAllowedPatterns
		).toHaveBeenCalledWith( null );
	} );

	test( 'getVisiblePatternNames falls back to settings when selector is unavailable', () => {
		const blockEditor = {
			getSettings: jest.fn().mockReturnValue( {
				__experimentalBlockPatterns: [
					{ name: 'theme/hero' },
					{},
					{ name: 'theme/hero' },
					{ name: 'theme/footer' },
				],
			} ),
		};

		mockRegistrySelect.mockReturnValue( blockEditor );

		expect( getVisiblePatternNames( 'root-456' ) ).toEqual( [
			'theme/hero',
			'theme/footer',
		] );
		expect( blockEditor.getSettings ).toHaveBeenCalledTimes( 1 );
	} );
} );
