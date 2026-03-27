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

	test( 'getVisiblePatternNames supports a future stable getAllowedPatterns selector when available', () => {
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

	test( 'getVisiblePatternNames prefers an injected block editor selector', () => {
		const injectedBlockEditor = {
			getAllowedPatterns: jest
				.fn()
				.mockReturnValue( [
					{ name: 'theme/nested-hero' },
					{ name: 'theme/nested-hero' },
					{ name: 'theme/nested-footer' },
				] ),
		};

		expect(
			getVisiblePatternNames( 'root-789', injectedBlockEditor )
		).toEqual( [ 'theme/nested-hero', 'theme/nested-footer' ] );
		expect( injectedBlockEditor.getAllowedPatterns ).toHaveBeenCalledWith(
			'root-789'
		);
		expect( mockRegistrySelect ).not.toHaveBeenCalled();
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

	test( 'getVisiblePatternNames with null root returns document-root scoped patterns', () => {
		const blockEditor = {
			getAllowedPatterns: jest.fn( ( rootClientId ) => {
				if ( rootClientId === null ) {
					return [
						{ name: 'theme/hero' },
						{ name: 'theme/footer' },
						{ name: 'theme/sidebar' },
					];
				}

				return [ { name: 'theme/hero' } ];
			} ),
		};

		// null root should return all document-level patterns
		expect( getVisiblePatternNames( null, blockEditor ) ).toEqual( [
			'theme/hero',
			'theme/footer',
			'theme/sidebar',
		] );
		expect( blockEditor.getAllowedPatterns ).toHaveBeenCalledWith( null );

		// nested root returns a narrower set
		expect(
			getVisiblePatternNames( 'nested-group-123', blockEditor )
		).toEqual( [ 'theme/hero' ] );
		expect( blockEditor.getAllowedPatterns ).toHaveBeenCalledWith(
			'nested-group-123'
		);
	} );

	test( 'getVisiblePatternNames fails closed when no contextual selector exists even if settings contain patterns', () => {
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

		expect( getVisiblePatternNames( 'root-456' ) ).toEqual( [] );
	} );

	test( 'getVisiblePatternNames remains selector-driven even if future stable settings exist without a contextual selector', () => {
		const blockEditor = {
			getSettings: jest.fn().mockReturnValue( {
				blockPatterns: [ { name: 'theme/stable-hero' } ],
				__experimentalBlockPatterns: [
					{ name: 'theme/experimental-hero' },
				],
			} ),
		};

		mockRegistrySelect.mockReturnValue( blockEditor );

		expect( getVisiblePatternNames() ).toEqual( [] );
	} );
} );
