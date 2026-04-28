const mockBlocksStore = {};
const mockBlockEditorStore = {};

jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	store: mockBlocksStore,
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: mockBlockEditorStore,
} ) );

const { select } = require( '@wordpress/data' );
const {
	introspectBlockInstance,
	introspectBlockType,
	resolveInspectorPanels,
} = require( '../block-inspector' );

describe( 'resolveInspectorPanels', () => {
	let blocksSelectors;
	let blockEditorSelectors;

	beforeEach( () => {
		blocksSelectors = {
			getBlockType: jest.fn(),
			getBlockStyles: jest.fn().mockReturnValue( [] ),
			getBlockVariations: jest.fn().mockReturnValue( [] ),
		};
		blockEditorSelectors = {
			getSettings: jest.fn().mockReturnValue( {} ),
		};

		select.mockImplementation( ( store ) => {
			if ( store === mockBlocksStore ) {
				return blocksSelectors;
			}

			if ( store === mockBlockEditorStore ) {
				return blockEditorSelectors;
			}

			return {};
		} );
	} );

	test( 'maps current Gutenberg support keys to the same panels as the server collector', () => {
		expect(
			resolveInspectorPanels( {
				customCSS: true,
				listView: true,
				typography: {
					fontFamily: true,
					__experimentalFontFamily: true,
					fitText: true,
					fontStyle: true,
					fontWeight: true,
					letterSpacing: true,
					textIndent: true,
					textDecoration: true,
					textTransform: true,
				},
			} )
		).toEqual( {
			advanced: [ 'customCSS' ],
			list: [ 'listView' ],
			typography: [
				'typography.fontFamily',
				'typography.__experimentalFontFamily',
				'typography.fitText',
				'typography.fontStyle',
				'typography.fontWeight',
				'typography.letterSpacing',
				'typography.textIndent',
				'typography.textDecoration',
				'typography.textTransform',
			],
		} );
	} );

	test( 'adds the bindings panel when Gutenberg exposes bindable attributes for the block', () => {
		blocksSelectors.getBlockType.mockReturnValue( {
			title: 'Paragraph',
			category: 'text',
			description: 'Paragraph block',
			supports: {},
			attributes: {
				content: {
					type: 'string',
					role: 'content',
				},
			},
		} );
		blockEditorSelectors.getSettings.mockReturnValue( {
			canUpdateBlockBindings: true,
			__experimentalBlockBindingsSupportedAttributes: {
				'core/paragraph': [ 'content' ],
			},
		} );

		const manifest = introspectBlockType( 'core/paragraph' );

		expect( manifest.bindableAttributes ).toEqual( [ 'content' ] );
		expect( manifest.inspectorPanels.bindings ).toEqual( [ 'content' ] );
	} );

	test( 'matches active block styles by complete class token', () => {
		blocksSelectors.getBlockType.mockReturnValue( {
			title: 'Button',
			category: 'design',
			description: 'Button block',
			supports: {},
			attributes: {
				className: {
					type: 'string',
				},
			},
		} );
		blocksSelectors.getBlockStyles.mockReturnValue( [
			{
				name: 'outline',
				label: 'Outline',
			},
			{
				name: 'outline-large',
				label: 'Outline Large',
			},
		] );
		blockEditorSelectors.getBlockName = jest
			.fn()
			.mockReturnValue( 'core/button' );
		blockEditorSelectors.getBlockAttributes = jest.fn().mockReturnValue( {
			className:
				'wp-block-button has-text-align-center is-style-outline-large',
		} );
		blockEditorSelectors.getBlockEditingMode = jest
			.fn()
			.mockReturnValue( 'default' );
		blockEditorSelectors.getBlockParents = jest.fn().mockReturnValue( [] );
		blockEditorSelectors.getBlockCount = jest.fn().mockReturnValue( 0 );

		const manifest = introspectBlockInstance( 'button-1' );

		expect( manifest.activeStyle ).toBe( 'outline-large' );
	} );

	test( 'adds a general panel for meaningful config attributes when no mapped supports exist', () => {
		blocksSelectors.getBlockType.mockReturnValue( {
			title: 'Spacer',
			category: 'design',
			description: 'Spacer block',
			supports: {},
			attributes: {
				height: {
					type: 'string',
				},
				metadata: {
					type: 'object',
				},
				className: {
					type: 'string',
				},
				style: {
					type: 'object',
				},
			},
		} );

		const manifest = introspectBlockType( 'core/spacer' );

		expect( manifest.inspectorPanels.general ).toEqual( [ 'height' ] );
	} );
} );
