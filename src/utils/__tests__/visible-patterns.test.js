jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

const mockRegistrySelect = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockRegistrySelect( ...args ),
	dispatch: ( ...args ) => mockRegistrySelect( ...args ),
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

	test( 'getVisiblePatternNames uses stable getAllowedPatterns selector when available', () => {
		const blockEditor = {
			getAllowedPatterns: jest
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
		expect( blockEditor.getAllowedPatterns ).toHaveBeenCalledWith(
			'root-123'
		);
	} );

	test( 'getVisiblePatternNames falls back to __experimentalGetAllowedPatterns', () => {
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

	test( 'getVisiblePatternNames falls back to settings patterns when no selector exists', () => {
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
	} );

	test( 'getVisiblePatternNames prefers stable blockPatterns setting', () => {
		const blockEditor = {
			getSettings: jest.fn().mockReturnValue( {
				blockPatterns: [ { name: 'theme/stable-hero' } ],
				__experimentalBlockPatterns: [
					{ name: 'theme/experimental-hero' },
				],
			} ),
		};

		mockRegistrySelect.mockReturnValue( blockEditor );

		expect( getVisiblePatternNames() ).toEqual( [ 'theme/stable-hero' ] );
	} );
} );
