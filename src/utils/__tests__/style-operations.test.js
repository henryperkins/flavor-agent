jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
	dispatch: jest.fn(),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: {},
} ) );

const { select, dispatch } = require( '@wordpress/data' );
const {
	applyGlobalStyleSuggestionOperations,
	getGlobalStylesActivityUndoState,
	getGlobalStylesUserConfig,
	undoGlobalStyleSuggestionOperations,
} = require( '../style-operations' );

describe( 'style-operations', () => {
	let coreSelect;
	let coreDispatch;
	let blockEditorSelect;
	let blockEditorSettings;
	let currentRecord;
	let baseConfig;
	let variations;

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
					lineHeight: true,
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

		select.mockImplementation( ( storeName ) => {
			if ( storeName === 'core' ) {
				return coreSelect;
			}

			if ( storeName === 'core/block-editor' ) {
				return blockEditorSelect;
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
