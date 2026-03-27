const mockBlockEditorStore = {};

jest.mock( '@wordpress/block-editor', () => ( {
	store: mockBlockEditorStore,
} ) );

const mockSelect = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockSelect( ...args ),
} ) );

const mockIntrospectBlockInstance = jest.fn();
const mockIntrospectBlockTree = jest.fn();
const mockSummarizeTree = jest.fn();

jest.mock( '../block-inspector', () => ( {
	introspectBlockInstance: ( ...args ) =>
		mockIntrospectBlockInstance( ...args ),
	introspectBlockTree: ( ...args ) => mockIntrospectBlockTree( ...args ),
	summarizeTree: ( ...args ) => mockSummarizeTree( ...args ),
} ) );

const mockCollectThemeTokens = jest.fn();
const mockSummarizeTokens = jest.fn();

jest.mock( '../theme-tokens', () => ( {
	collectThemeTokens: ( ...args ) => mockCollectThemeTokens( ...args ),
	summarizeTokens: ( ...args ) => mockSummarizeTokens( ...args ),
} ) );

const mockBuildStructuralContext = jest.fn();

jest.mock( '../../utils/structural-identity', () => ( {
	buildStructuralContext: ( ...args ) =>
		mockBuildStructuralContext( ...args ),
} ) );

const { collectBlockContext } = require( '../collector' );

describe( 'collectBlockContext', () => {
	beforeEach( () => {
		mockSelect.mockReset();
		mockIntrospectBlockInstance.mockReset();
		mockIntrospectBlockTree.mockReset();
		mockSummarizeTree.mockReset();
		mockCollectThemeTokens.mockReset();
		mockSummarizeTokens.mockReset();
		mockBuildStructuralContext.mockReset();
	} );

	test( 'includes bindableAttributes from block introspection in the request context', () => {
		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/button',
			title: 'Button',
			currentAttributes: { text: 'Read more' },
			inspectorPanels: { bindings: [ 'url', 'text' ] },
			bindableAttributes: [ 'url', 'text' ],
			styles: [],
			activeStyle: null,
			variations: [],
			supportsContentRole: false,
			contentAttributes: {},
			configAttributes: {},
			editingMode: 'default',
			isInsideContentOnly: false,
			blockVisibility: null,
			childCount: 0,
		} );
		mockIntrospectBlockTree.mockReturnValue( [] );
		mockBuildStructuralContext.mockReturnValue( {
			blockIdentity: { role: 'cta-button' },
			structuralAncestors: [],
			branchRoot: null,
		} );
		mockCollectThemeTokens.mockReturnValue( { color: {} } );
		mockSummarizeTokens.mockReturnValue( { colors: [ 'accent: #f00' ] } );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( null ),
			getBlockOrder: jest.fn().mockReturnValue( [] ),
			getBlockName: jest.fn(),
		} );

		expect( collectBlockContext( 'client-1' ) ).toEqual( {
			block: {
				name: 'core/button',
				title: 'Button',
				currentAttributes: { text: 'Read more' },
				inspectorPanels: { bindings: [ 'url', 'text' ] },
				bindableAttributes: [ 'url', 'text' ],
				styles: [],
				activeStyle: null,
				variations: [],
				supportsContentRole: false,
				contentAttributes: {},
				configAttributes: {},
				editingMode: 'default',
				isInsideContentOnly: false,
				blockVisibility: null,
				childCount: 0,
				structuralIdentity: { role: 'cta-button' },
			},
			siblingsBefore: [],
			siblingsAfter: [],
			structuralAncestors: [],
			structuralBranch: [],
			themeTokens: { colors: [ 'accent: #f00' ] },
		} );
	} );
} );
