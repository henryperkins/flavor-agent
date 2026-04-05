jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
	dispatch: jest.fn(),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	store: {},
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: {},
} ) );

const { select, dispatch } = require( '@wordpress/data' );
const { store: blocksStore } = require( '@wordpress/blocks' );
const { store: blockEditorStore } = require( '@wordpress/block-editor' );
const {
	applyGlobalStyleSuggestionOperations,
	buildGlobalStylesRecommendationContextSignature,
	getGlobalStylesActivityUndoState,
	getGlobalStylesUserConfig,
	undoGlobalStyleSuggestionOperations,
} = require( '../style-operations' );

describe( 'style-operations', () => {
	let coreSelect;
	let coreDispatch;
	let blockEditorSelect;
	let blocksSelect;
	let blockEditorSettings;
	let currentRecord;
	let baseConfig;
	let variations;
	let registeredBlockTypes;

	beforeEach( () => {
		blockEditorSettings = {
			features: {
				color: {
					palette: {
						theme: [
							{
								name: 'Base',
								slug: 'base',
								color: '#111111',
							},
							{
								name: 'Accent',
								slug: 'accent',
								color: '#ff5500',
							},
							{
								name: 'Contrast',
								slug: 'contrast',
								color: '#f5f5f5',
							},
						],
					},
					background: true,
					text: true,
				},
				typography: {
					fontSizes: {
						theme: [
							{
								name: 'Body',
								slug: 'body',
								size: '1rem',
							},
						],
					},
					fontFamilies: {
						theme: [
							{
								name: 'Display',
								slug: 'display',
								fontFamily: 'Georgia, serif',
							},
						],
					},
					lineHeight: true,
				},
				spacing: {
					spacingSizes: {
						theme: [
							{
								name: 'Small',
								slug: 's',
								size: '0.5rem',
							},
						],
					},
					blockGap: true,
				},
				border: {
					color: true,
					radius: true,
					style: true,
					width: true,
				},
				shadow: {
					presets: {
						theme: [
							{
								name: 'Soft',
								slug: 'soft',
								shadow: '0 10px 30px rgba(0,0,0,0.1)',
							},
						],
					},
				},
			},
		};
		registeredBlockTypes = {
			'core/paragraph': {
				name: 'core/paragraph',
				supports: {
					color: {
						background: true,
						text: true,
					},
					typography: {
						fontSize: true,
						fontFamily: true,
						lineHeight: true,
					},
					spacing: {
						blockGap: true,
					},
					border: {
						color: true,
						radius: true,
						style: true,
						width: true,
					},
					shadow: true,
				},
			},
		};
		currentRecord = {
			settings: {},
			styles: {
				color: {
					background: 'var:preset|color|base',
				},
			},
			_links: {
				self: [ { href: '/wp/v2/global-styles/17' } ],
			},
		};
		baseConfig = {
			settings: {
				typography: {
					lineHeight: true,
				},
			},
			styles: {
				color: {
					text: 'var:preset|color|contrast',
				},
			},
		};
		variations = [
			{
				title: 'Default',
				settings: {},
				styles: {},
			},
			{
				title: 'Midnight',
				settings: {},
				styles: {
					color: {
						background: 'var:preset|color|accent',
					},
				},
			},
		];
		coreDispatch = {
			editEntityRecord: jest.fn( ( kind, name, id, update ) => {
				currentRecord = {
					...currentRecord,
					...update,
				};
			} ),
		};
		coreSelect = {
			__experimentalGetCurrentGlobalStylesId: jest
				.fn()
				.mockReturnValue( '17' ),
			getEditedEntityRecord: jest
				.fn()
				.mockImplementation( () => currentRecord ),
			getEntityRecord: jest
				.fn()
				.mockImplementation( () => currentRecord ),
			__experimentalGetCurrentThemeBaseGlobalStyles: jest
				.fn()
				.mockImplementation( () => baseConfig ),
			__experimentalGetCurrentThemeGlobalStylesVariations: jest
				.fn()
				.mockImplementation( () => variations ),
		};
		blockEditorSelect = {
			getSettings: jest.fn( () => blockEditorSettings ),
		};
		blocksSelect = {
			getBlockType: jest.fn(
				( blockName ) => registeredBlockTypes[ blockName ] || null
			),
		};

		select.mockImplementation( ( storeName ) => {
			if ( storeName === 'core' ) {
				return coreSelect;
			}

			if (
				storeName === 'core/block-editor' ||
				storeName === blockEditorStore
			) {
				return blockEditorSelect;
			}

			if ( storeName === blocksStore ) {
				return blocksSelect;
			}

			return {};
		} );
		dispatch.mockImplementation( ( storeName ) =>
			storeName === 'core' ? coreDispatch : {}
		);
	} );

	afterEach( () => {
		jest.resetAllMocks();
	} );

	test( 'getGlobalStylesUserConfig resolves the current entity and variations', () => {
		expect( getGlobalStylesUserConfig() ).toEqual( {
			globalStylesId: '17',
			userConfig: {
				settings: {},
				styles: {
					color: {
						background: 'var:preset|color|base',
					},
				},
				_links: {
					self: [ { href: '/wp/v2/global-styles/17' } ],
				},
			},
			mergedConfig: {
				settings: {
					typography: {
						lineHeight: true,
					},
				},
				styles: {
					color: {
						text: 'var:preset|color|contrast',
						background: 'var:preset|color|base',
					},
				},
				_links: {
					self: [ { href: '/wp/v2/global-styles/17' } ],
				},
			},
			variations,
		} );
	} );

	test( 'applyGlobalStyleSuggestionOperations updates preset-backed style paths', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'background' ],
					value: 'var:preset|color|accent',
					valueType: 'preset',
					presetSlug: 'accent',
					presetType: 'color',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.beforeConfig.styles.color.background ).toBe(
			'var:preset|color|base'
		);
		expect( result.afterConfig.styles.color.background ).toBe(
			'var:preset|color|accent'
		);
		expect( coreDispatch.editEntityRecord ).toHaveBeenCalledWith(
			'root',
			'globalStyles',
			'17',
			expect.objectContaining( {
				styles: {
					color: {
						background: 'var:preset|color|accent',
					},
				},
			} )
		);
	} );

	test( 'applyGlobalStyleSuggestionOperations applies validated freeform style values', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'typography', 'lineHeight' ],
					value: 1.6,
					valueType: 'freeform',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.afterConfig.styles.typography.lineHeight ).toBe( 1.6 );
		expect( coreDispatch.editEntityRecord ).toHaveBeenCalledWith(
			'root',
			'globalStyles',
			'17',
			expect.objectContaining( {
				styles: expect.objectContaining( {
					typography: {
						lineHeight: 1.6,
					},
				} ),
			} )
		);
	} );

	test( 'applyGlobalStyleSuggestionOperations rejects malformed freeform values', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'typography', 'lineHeight' ],
					value: {
						amount: 1.6,
					},
					valueType: 'freeform',
				},
			],
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: expect.stringContaining( 'typography.lineHeight' ),
			} )
		);
		expect( coreDispatch.editEntityRecord ).not.toHaveBeenCalled();
		expect( currentRecord.styles.typography ).toBeUndefined();
	} );

	test( 'applyGlobalStyleSuggestionOperations rejects unsupported live style paths', () => {
		blockEditorSettings = {
			...blockEditorSettings,
			features: {
				...blockEditorSettings.features,
				color: {
					...blockEditorSettings.features.color,
					background: false,
				},
			},
		};

		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'background' ],
					value: 'var:preset|color|accent',
					valueType: 'preset',
					presetSlug: 'accent',
					presetType: 'color',
				},
			],
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: expect.stringContaining( 'color.background' ),
			} )
		);
		expect( coreDispatch.editEntityRecord ).not.toHaveBeenCalled();
	} );

	test( 'applyGlobalStyleSuggestionOperations rejects missing live preset slugs', () => {
		blockEditorSettings = {
			...blockEditorSettings,
			features: {
				...blockEditorSettings.features,
				color: {
					...blockEditorSettings.features.color,
					palette: {
						theme: [
							{
								name: 'Base',
								slug: 'base',
								color: '#111111',
							},
							{
								name: 'Contrast',
								slug: 'contrast',
								color: '#f5f5f5',
							},
						],
					},
				},
			},
		};

		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'background' ],
					value: 'var:preset|color|accent',
					valueType: 'preset',
					presetSlug: 'accent',
					presetType: 'color',
				},
			],
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: expect.stringContaining( 'accent' ),
			} )
		);
		expect( coreDispatch.editEntityRecord ).not.toHaveBeenCalled();
	} );

	test( 'applyGlobalStyleSuggestionOperations rejects preset type and value mismatches', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'typography', 'fontSize' ],
					value: 'var:preset|color|accent',
					valueType: 'preset',
					presetSlug: 'accent',
					presetType: 'color',
				},
			],
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: expect.stringContaining( 'typography.fontSize' ),
			} )
		);
		expect( coreDispatch.editEntityRecord ).not.toHaveBeenCalled();
	} );

	test( 'applyGlobalStyleSuggestionOperations resolves theme variations deterministically', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_theme_variation',
					variationIndex: 1,
					variationTitle: 'Midnight',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect( result.afterConfig.styles.color.background ).toBe(
			'var:preset|color|accent'
		);
	} );

	test( 'applyGlobalStyleSuggestionOperations rejects duplicate theme variation operations', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_theme_variation',
					variationIndex: 0,
					variationTitle: 'Default',
				},
				{
					type: 'set_theme_variation',
					variationIndex: 1,
					variationTitle: 'Midnight',
				},
			],
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: 'Global Styles suggestions may include at most one set_theme_variation operation.',
			} )
		);
		expect( coreDispatch.editEntityRecord ).not.toHaveBeenCalled();
	} );

	test( 'applyGlobalStyleSuggestionOperations rejects theme variations for style-book scope', () => {
		const result = applyGlobalStyleSuggestionOperations(
			{
				operations: [
					{
						type: 'set_theme_variation',
						variationIndex: 1,
						variationTitle: 'Midnight',
					},
				],
			},
			undefined,
			{ surface: 'style-book' }
		);

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: 'Style Book suggestions cannot switch the active site theme variation.',
			} )
		);
		expect( coreDispatch.editEntityRecord ).not.toHaveBeenCalled();
	} );

	test( 'applyGlobalStyleSuggestionOperations updates block-scoped preset-backed style paths', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_block_styles',
					blockName: 'core/paragraph',
					path: [ 'color', 'text' ],
					value: 'var:preset|color|accent',
					valueType: 'preset',
					presetSlug: 'accent',
					presetType: 'color',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect(
			result.afterConfig.styles.blocks[ 'core/paragraph' ].color.text
		).toBe( 'var:preset|color|accent' );
		expect( coreDispatch.editEntityRecord ).toHaveBeenCalledWith(
			'root',
			'globalStyles',
			'17',
			expect.objectContaining( {
				styles: expect.objectContaining( {
					blocks: {
						'core/paragraph': {
							color: {
								text: 'var:preset|color|accent',
							},
						},
					},
				} ),
			} )
		);
	} );

	test( 'applyGlobalStyleSuggestionOperations rejects unsupported block-scoped style paths', () => {
		registeredBlockTypes[ 'core/paragraph' ] = {
			...registeredBlockTypes[ 'core/paragraph' ],
			supports: {
				color: {
					text: true,
				},
			},
		};

		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_block_styles',
					blockName: 'core/paragraph',
					path: [ 'border', 'radius' ],
					value: '12px',
					valueType: 'freeform',
				},
			],
		} );

		expect( result ).toEqual(
			expect.objectContaining( {
				ok: false,
				error: expect.stringContaining( 'border.radius' ),
			} )
		);
		expect( coreDispatch.editEntityRecord ).not.toHaveBeenCalled();
	} );

	test( 'applyGlobalStyleSuggestionOperations applies a theme variation before later style overrides', () => {
		const result = applyGlobalStyleSuggestionOperations( {
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'text' ],
					value: 'var:preset|color|contrast',
					valueType: 'preset',
					presetSlug: 'contrast',
					presetType: 'color',
				},
				{
					type: 'set_theme_variation',
					variationIndex: 1,
					variationTitle: 'Midnight',
				},
			],
		} );

		expect( result.ok ).toBe( true );
		expect(
			result.operations.map( ( operation ) => operation.type )
		).toEqual( [ 'set_theme_variation', 'set_styles' ] );
		expect( result.afterConfig.styles.color ).toEqual( {
			background: 'var:preset|color|accent',
			text: 'var:preset|color|contrast',
		} );
	} );

	test( 'buildGlobalStylesRecommendationContextSignature ignores variations for style-book scope', () => {
		const baseArgs = {
			scope: {
				surface: 'style-book',
				scopeKey: 'style_book:17:core/paragraph',
				globalStylesId: '17',
				stylesheet: 'theme-slug',
				templateSlug: 'theme-slug//home',
				templateType: 'home',
			},
			currentConfig: {
				settings: {},
				styles: {
					blocks: {
						'core/paragraph': {
							color: {
								text: 'var:preset|color|contrast',
							},
						},
					},
				},
			},
			mergedConfig: {
				settings: {},
				styles: {
					blocks: {
						'core/paragraph': {
							color: {
								text: 'var:preset|color|contrast',
							},
						},
					},
				},
			},
			themeTokenDiagnostics: {
				source: 'stable',
			},
			executionContract: {
				supportedStylePaths: [
					{
						path: [ 'color', 'text' ],
						valueSource: 'color',
					},
				],
				presetSlugs: {
					color: [ 'accent', 'contrast' ],
				},
			},
		};

		const firstSignature = buildGlobalStylesRecommendationContextSignature(
			{
				...baseArgs,
				availableVariations: [
					{
						title: 'Default',
						settings: {},
						styles: {},
					},
				],
			}
		);
		const secondSignature = buildGlobalStylesRecommendationContextSignature(
			{
				...baseArgs,
				availableVariations: [
					{
						title: 'Midnight',
						settings: {},
						styles: {
							color: {
								background: 'var:preset|color|accent',
							},
						},
					},
				],
			}
		);

		expect( firstSignature ).toBe( secondSignature );
	} );

	test( 'undo helpers require the current config to still match the applied state', () => {
		const activity = {
			target: {
				globalStylesId: '17',
			},
			before: {
				userConfig: {
					settings: {},
					styles: {
						color: {
							background: 'var:preset|color|base',
						},
					},
					_links: {
						self: [ { href: '/wp/v2/global-styles/17' } ],
					},
				},
			},
			after: {
				userConfig: {
					settings: {},
					styles: {
						color: {
							background: 'var:preset|color|accent',
						},
					},
					_links: {
						self: [ { href: '/wp/v2/global-styles/17' } ],
					},
				},
			},
		};

		currentRecord = activity.after.userConfig;

		expect( getGlobalStylesActivityUndoState( activity ) ).toEqual( {
			canUndo: true,
			status: 'available',
			error: null,
		} );

		expect( undoGlobalStyleSuggestionOperations( activity ) ).toEqual( {
			ok: true,
		} );
		expect( currentRecord.styles.color.background ).toBe(
			'var:preset|color|base'
		);

		currentRecord = {
			...activity.after.userConfig,
			styles: {
				color: {
					background: 'var:preset|color|brand',
				},
			},
		};

		expect( getGlobalStylesActivityUndoState( activity ) ).toEqual(
			expect.objectContaining( {
				canUndo: false,
				status: 'failed',
			} )
		);
	} );

	test( 'undo helpers ignore _links changes and object key ordering', () => {
		const activity = {
			target: {
				globalStylesId: '17',
			},
			after: {
				userConfig: {
					settings: {
						color: {
							palette: {
								default: true,
								custom: false,
							},
						},
					},
					styles: {
						color: {
							background: 'var:preset|color|accent',
							text: 'var:preset|color|contrast',
						},
					},
					_links: {
						self: [ { href: '/wp/v2/global-styles/17' } ],
					},
				},
			},
		};

		currentRecord = {
			styles: {
				color: {
					text: 'var:preset|color|contrast',
					background: 'var:preset|color|accent',
				},
			},
			_links: {
				self: [ { href: '/wp/v2/global-styles/17?context=edit' } ],
			},
			settings: {
				color: {
					palette: {
						custom: false,
						default: true,
					},
				},
			},
		};

		expect( getGlobalStylesActivityUndoState( activity ) ).toEqual( {
			canUndo: true,
			status: 'available',
			error: null,
		} );
	} );
} );
