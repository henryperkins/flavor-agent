import { buildStyleRecommendationRequestInput } from '../request-input';

describe( 'buildStyleRecommendationRequestInput', () => {
	test( 'defaults to a global-styles scope with empty fields', () => {
		const result = buildStyleRecommendationRequestInput( {} );

		expect( result ).toEqual( {
			scope: {
				surface: 'global-styles',
				scopeKey: '',
				globalStylesId: '',
				postType: 'global_styles',
				entityId: '',
				entityKind: 'root',
				entityName: 'globalStyles',
				stylesheet: '',
				templateSlug: '',
				templateType: '',
			},
			styleContext: {
				currentConfig: undefined,
				mergedConfig: undefined,
				templateStructure: undefined,
				templateVisibility: undefined,
				designSemantics: undefined,
				themeTokenDiagnostics: undefined,
			},
			contextSignature: '',
		} );
	} );

	test( 'derives entityId from globalStylesId for the global-styles surface', () => {
		const result = buildStyleRecommendationRequestInput( {
			surface: 'global-styles',
			scope: {
				scopeKey: 'gs-42',
				globalStylesId: 42,
				stylesheet: 'twentytwentyfive',
			},
			contextSignature: 'sig-1',
		} );

		expect( result.scope ).toMatchObject( {
			surface: 'global-styles',
			scopeKey: 'gs-42',
			globalStylesId: 42,
			entityId: 42,
			entityKind: 'root',
			entityName: 'globalStyles',
			stylesheet: 'twentytwentyfive',
		} );
		expect( result.scope ).not.toHaveProperty( 'blockName' );
	} );

	test( 'derives entityId from blockName and exposes block metadata for style-book', () => {
		const result = buildStyleRecommendationRequestInput( {
			surface: 'style-book',
			scope: {
				blockName: 'core/heading',
				blockTitle: 'Heading',
			},
		} );

		expect( result.scope ).toMatchObject( {
			surface: 'style-book',
			entityId: 'core/heading',
			entityKind: 'block',
			entityName: 'styleBook',
			blockName: 'core/heading',
			blockTitle: 'Heading',
		} );
	} );

	test( 'lets explicit scope fields override surface-derived defaults', () => {
		const result = buildStyleRecommendationRequestInput( {
			surface: 'style-book',
			scope: {
				blockName: 'core/heading',
				entityId: 'override-id',
				entityKind: 'custom-kind',
				entityName: 'custom-name',
			},
		} );

		expect( result.scope.entityId ).toBe( 'override-id' );
		expect( result.scope.entityKind ).toBe( 'custom-kind' );
		expect( result.scope.entityName ).toBe( 'custom-name' );
	} );

	test( 'trims the prompt and omits it entirely when empty after trimming', () => {
		const withWhitespace = buildStyleRecommendationRequestInput( {
			prompt: '   \n\t ',
		} );
		expect( withWhitespace ).not.toHaveProperty( 'prompt' );

		const withContent = buildStyleRecommendationRequestInput( {
			prompt: '   make it pop  ',
		} );
		expect( withContent.prompt ).toBe( 'make it pop' );
	} );

	test( 'omits prompt when not a string', () => {
		const result = buildStyleRecommendationRequestInput( { prompt: 123 } );
		expect( result ).not.toHaveProperty( 'prompt' );
	} );

	test( 'attaches availableVariations only when an array is passed', () => {
		const variations = [ { title: 'Midnight' } ];
		const withArray = buildStyleRecommendationRequestInput( {
			availableVariations: variations,
		} );
		expect( withArray.styleContext.availableVariations ).toBe( variations );

		const withNonArray = buildStyleRecommendationRequestInput( {
			availableVariations: { title: 'Midnight' },
		} );
		expect( withNonArray.styleContext ).not.toHaveProperty(
			'availableVariations'
		);
	} );

	test( 'attaches styleBookTarget only when truthy', () => {
		const target = { blockName: 'core/heading' };
		const withTarget = buildStyleRecommendationRequestInput( {
			styleBookTarget: target,
		} );
		expect( withTarget.styleContext.styleBookTarget ).toBe( target );

		const withoutTarget = buildStyleRecommendationRequestInput( {
			styleBookTarget: null,
		} );
		expect( withoutTarget.styleContext ).not.toHaveProperty(
			'styleBookTarget'
		);
	} );

	test( 'forwards style-context payload fields verbatim', () => {
		const payload = {
			currentConfig: { a: 1 },
			mergedConfig: { b: 2 },
			templateStructure: { c: 3 },
			templateVisibility: { d: 4 },
			designSemantics: { e: 5 },
			themeTokenDiagnostics: { f: 6 },
		};

		const result = buildStyleRecommendationRequestInput( payload );

		expect( result.styleContext ).toEqual( payload );
	} );

	test( 'tolerates a null scope', () => {
		const result = buildStyleRecommendationRequestInput( {
			surface: 'global-styles',
			scope: null,
		} );
		expect( result.scope.scopeKey ).toBe( '' );
		expect( result.scope.entityKind ).toBe( 'root' );
	} );
} );
