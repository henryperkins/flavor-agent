const mockBlockEditorStore = {};

jest.mock( '@wordpress/block-editor', () => ( {
	store: mockBlockEditorStore,
} ) );

const mockSelect = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockSelect( ...args ),
} ) );

const mockBlocksStore = {};

jest.mock( '@wordpress/blocks', () => ( {
	store: mockBlocksStore,
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

const mockAnnotateStructuralIdentity = jest.fn();
const mockFindBranchRoot = jest.fn();
const mockFindNodePath = jest.fn();
const mockToStructuralSummary = jest.fn();

jest.mock( '../../utils/structural-identity', () => ( {
	annotateStructuralIdentity: ( ...args ) =>
		mockAnnotateStructuralIdentity( ...args ),
	findBranchRoot: ( ...args ) => mockFindBranchRoot( ...args ),
	findNodePath: ( ...args ) => mockFindNodePath( ...args ),
	toStructuralSummary: ( ...args ) => mockToStructuralSummary( ...args ),
} ) );

const {
	collectBlockContext,
	getAnnotatedBlockTree,
	invalidateAnnotatedTreeCache,
} = require( '../collector' );

describe( 'collectBlockContext', () => {
	beforeEach( () => {
		mockSelect.mockReset();
		mockIntrospectBlockInstance.mockReset();
		mockIntrospectBlockTree.mockReset();
		mockSummarizeTree.mockReset();
		mockCollectThemeTokens.mockReset();
		mockSummarizeTokens.mockReset();
		mockAnnotateStructuralIdentity.mockReset();
		mockFindBranchRoot.mockReset();
		mockFindNodePath.mockReset();
		mockToStructuralSummary.mockReset();
		invalidateAnnotatedTreeCache();
	} );

	test( 'returns null when clientId is falsy', () => {
		expect( collectBlockContext( '' ) ).toBeNull();
		expect( collectBlockContext( null ) ).toBeNull();
	} );

	test( 'includes bindableAttributes and structural identity when block is found in tree', () => {
		const selectedNode = {
			clientId: 'client-1',
			name: 'core/button',
			innerBlocks: [],
			structuralIdentity: { role: 'cta-button' },
		};

		const parentNode = {
			clientId: 'parent-1',
			name: 'core/group',
			innerBlocks: [ selectedNode ],
			structuralIdentity: { role: 'content-area' },
		};

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

		mockIntrospectBlockTree.mockReturnValue( [
			{
				clientId: 'parent-1',
				innerBlocks: [ { clientId: 'client-1', innerBlocks: [] } ],
			},
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [ parentNode ] );
		mockFindNodePath.mockReturnValue( [ parentNode, selectedNode ] );
		mockFindBranchRoot.mockReturnValue( parentNode );
		mockToStructuralSummary.mockReturnValue( {
			block: 'core/group',
			title: 'Group',
			role: 'content-area',
		} );
		mockSummarizeTree.mockReturnValue( [ { block: 'core/group' } ] );

		mockCollectThemeTokens.mockReturnValue( {
			color: {},
			diagnostics: {
				source: 'stable',
				settingsKey: 'features',
				reason: 'stable-parity',
			},
		} );
		mockSummarizeTokens.mockReturnValue( {
			colors: [ 'accent: #f00' ],
			diagnostics: {
				source: 'stable',
				settingsKey: 'features',
				reason: 'stable-parity',
			},
		} );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( null ),
			getBlockOrder: jest.fn().mockReturnValue( [] ),
			getBlockName: jest.fn(),
		} );

		const result = collectBlockContext( 'client-1' );

		expect( result.block.structuralIdentity ).toEqual( {
			role: 'cta-button',
		} );
		expect( result.structuralAncestors ).toEqual( [
			{ block: 'core/group', title: 'Group', role: 'content-area' },
		] );
		expect( result.structuralBranch ).toEqual( [
			{ block: 'core/group' },
		] );
		expect( mockFindBranchRoot ).toHaveBeenCalledWith( [
			parentNode,
			selectedNode,
		] );
		expect( result.block.bindableAttributes ).toEqual( [ 'url', 'text' ] );
	} );

	test( 'returns empty structural context when block is not found in tree', () => {
		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/button',
			title: 'Button',
			currentAttributes: { text: 'Read more' },
			inspectorPanels: {},
			bindableAttributes: [],
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

		mockIntrospectBlockTree.mockReturnValue( [
			{ clientId: 'other', innerBlocks: [] },
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [] );
		mockFindNodePath.mockReturnValue( null );

		mockCollectThemeTokens.mockReturnValue( { color: {} } );
		mockSummarizeTokens.mockReturnValue( { colors: [] } );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( null ),
			getBlockOrder: jest.fn().mockReturnValue( [] ),
			getBlockName: jest.fn(),
		} );

		const result = collectBlockContext( 'missing-1' );

		expect( result.block.structuralIdentity ).toEqual( {} );
		expect( result.structuralAncestors ).toEqual( [] );
		expect( result.structuralBranch ).toEqual( [] );
		expect( mockFindBranchRoot ).not.toHaveBeenCalled();
	} );

	test( 'includes parent context and sibling summaries with visual hints', () => {
		const selectedNode = {
			clientId: 'client-1',
			name: 'core/button',
			innerBlocks: [],
			structuralIdentity: { role: 'cta-button' },
		};
		const parentNode = {
			clientId: 'parent-1',
			name: 'core/group',
			innerBlocks: [ selectedNode ],
			structuralIdentity: {
				role: 'header-cover',
				job: 'Header container',
			},
			childCount: 2,
		};
		const beforeNode = {
			clientId: 'sibling-before',
			name: 'core/paragraph',
			innerBlocks: [],
			structuralIdentity: { role: 'lede' },
		};
		const afterNode = {
			clientId: 'sibling-after',
			name: 'core/image',
			innerBlocks: [],
			structuralIdentity: { role: 'content-image' },
		};

		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/button',
			title: 'Button',
			currentAttributes: {},
			inspectorPanels: {},
			bindableAttributes: [],
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

		mockIntrospectBlockTree.mockReturnValue( [
			{
				clientId: 'parent-1',
				innerBlocks: [
					{ clientId: 'sibling-before', innerBlocks: [] },
					{ clientId: 'client-1', innerBlocks: [] },
					{ clientId: 'sibling-after', innerBlocks: [] },
				],
			},
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [
			{
				...parentNode,
				innerBlocks: [ beforeNode, selectedNode, afterNode ],
			},
		] );
		mockFindNodePath.mockImplementation( ( tree, predicate ) => {
			const ordered = [ parentNode, beforeNode, selectedNode, afterNode ];
			const target = ordered.find( predicate );

			if ( ! target ) {
				return null;
			}

			if ( target.clientId === 'client-1' ) {
				return [ parentNode, selectedNode ];
			}

			if ( target.clientId === 'sibling-before' ) {
				return [ parentNode, beforeNode ];
			}

			if ( target.clientId === 'sibling-after' ) {
				return [ parentNode, afterNode ];
			}

			return [ target ];
		} );
		mockFindBranchRoot.mockReturnValue( parentNode );
		mockSummarizeTree.mockReturnValue( [
			{
				block: 'core/group',
				children: [ { block: 'core/button', isSelected: true } ],
			},
		] );
		mockCollectThemeTokens.mockReturnValue( {} );
		mockSummarizeTokens.mockReturnValue( {} );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( 'parent-1' ),
			getBlockOrder: jest
				.fn()
				.mockReturnValue( [
					'sibling-before',
					'client-1',
					'sibling-after',
				] ),
			getBlockName: jest.fn().mockImplementation( ( id ) => {
				const map = {
					'parent-1': 'core/cover',
					'sibling-before': 'core/paragraph',
					'sibling-after': 'core/image',
				};
				return map[ id ];
			} ),
			getBlockAttributes: jest.fn().mockImplementation( ( id ) => {
				if ( id === 'parent-1' ) {
					return {
						backgroundColor: 'contrast',
						dimRatio: 80,
						layout: { type: 'constrained' },
					};
				}
				if ( id === 'sibling-before' ) {
					return { textAlign: 'center' };
				}
				if ( id === 'sibling-after' ) {
					return {
						align: 'wide',
						style: {
							color: {
								text: 'var(--wp--preset--color--contrast)',
							},
						},
					};
				}
				return {};
			} ),
			getBlockCount: jest.fn().mockReturnValue( 3 ),
		} );

		const result = collectBlockContext( 'client-1' );

		expect( result.parentContext ).toEqual(
			expect.objectContaining( {
				block: 'core/cover',
				role: 'header-cover',
				job: 'Header container',
				childCount: 3,
			} )
		);
		expect( result.parentContext.visualHints ).toMatchObject( {
			backgroundColor: 'contrast',
			dimRatio: 80,
			layout: { type: 'constrained' },
		} );
		expect( result.siblingSummariesBefore ).toEqual( [
			{
				block: 'core/paragraph',
				role: 'lede',
				visualHints: { textAlign: 'center' },
			},
		] );
		expect( result.siblingSummariesAfter ).toEqual( [
			{
				block: 'core/image',
				role: 'content-image',
				visualHints: {
					align: 'wide',
					style: {
						color: { text: 'var(--wp--preset--color--contrast)' },
					},
				},
			},
		] );
	} );
} );

describe( 'getAnnotatedBlockTree', () => {
	beforeEach( () => {
		mockIntrospectBlockTree.mockReset();
		mockAnnotateStructuralIdentity.mockReset();
		invalidateAnnotatedTreeCache();
	} );

	test( 'calls introspectBlockTree and annotateStructuralIdentity on first call', () => {
		const mockTree = [
			{ clientId: 'a', name: 'core/group', innerBlocks: [] },
		];
		const mockAnnotated = [
			{
				clientId: 'a',
				name: 'core/group',
				innerBlocks: [],
				structuralIdentity: {},
			},
		];

		mockIntrospectBlockTree.mockReturnValue( mockTree );
		mockAnnotateStructuralIdentity.mockReturnValue( mockAnnotated );

		const result = getAnnotatedBlockTree( 10 );

		expect( mockIntrospectBlockTree ).toHaveBeenCalledWith( null, 10 );
		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledWith(
			mockTree
		);
		expect( result ).toBe( mockAnnotated );
	} );

	test( 'returns cached result when tree fingerprint is unchanged', () => {
		const mockTree = [
			{ clientId: 'a', name: 'core/group', innerBlocks: [] },
		];
		const mockAnnotated = [
			{
				clientId: 'a',
				name: 'core/group',
				innerBlocks: [],
				structuralIdentity: {},
			},
		];

		mockIntrospectBlockTree.mockReturnValue( mockTree );
		mockAnnotateStructuralIdentity.mockReturnValue( mockAnnotated );

		// First call — builds and caches.
		getAnnotatedBlockTree( 10 );
		// Second call — same tree, same fingerprint.
		getAnnotatedBlockTree( 10 );

		// introspectBlockTree is still called (it IS the source of the
		// fingerprint), but annotateStructuralIdentity should only run once.
		expect( mockIntrospectBlockTree ).toHaveBeenCalledTimes( 2 );
		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'rebuilds when tree fingerprint changes', () => {
		mockIntrospectBlockTree
			.mockReturnValueOnce( [
				{ clientId: 'a', name: 'core/group', innerBlocks: [] },
			] )
			.mockReturnValueOnce( [
				{ clientId: 'b', name: 'core/paragraph', innerBlocks: [] },
			] );
		mockAnnotateStructuralIdentity
			.mockReturnValueOnce( [
				{
					clientId: 'a',
					name: 'core/group',
					innerBlocks: [],
					structuralIdentity: {},
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'b',
					name: 'core/paragraph',
					innerBlocks: [],
					structuralIdentity: {},
				},
			] );

		getAnnotatedBlockTree( 10 );
		getAnnotatedBlockTree( 10 );

		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'rebuilds when structural-identity attributes change without tree shape changes', () => {
		mockIntrospectBlockTree
			.mockReturnValueOnce( [
				{
					clientId: 'query-1',
					name: 'core/query',
					currentAttributes: { query: { inherit: false } },
					innerBlocks: [],
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'query-1',
					name: 'core/query',
					currentAttributes: { query: { inherit: true } },
					innerBlocks: [],
				},
			] );
		mockAnnotateStructuralIdentity
			.mockReturnValueOnce( [
				{
					clientId: 'query-1',
					name: 'core/query',
					innerBlocks: [],
					structuralIdentity: { role: 'supplemental-query' },
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'query-1',
					name: 'core/query',
					innerBlocks: [],
					structuralIdentity: { role: 'main-query' },
				},
			] );

		getAnnotatedBlockTree( 10 );
		getAnnotatedBlockTree( 10 );

		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'rebuilds when a block type changes without a clientId change', () => {
		mockIntrospectBlockTree
			.mockReturnValueOnce( [
				{
					clientId: 'node-1',
					name: 'core/group',
					currentAttributes: {},
					innerBlocks: [],
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'node-1',
					name: 'core/navigation',
					currentAttributes: {},
					innerBlocks: [],
				},
			] );
		mockAnnotateStructuralIdentity
			.mockReturnValueOnce( [
				{
					clientId: 'node-1',
					name: 'core/group',
					innerBlocks: [],
					structuralIdentity: {},
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'node-1',
					name: 'core/navigation',
					innerBlocks: [],
					structuralIdentity: {},
				},
			] );

		getAnnotatedBlockTree( 10 );
		getAnnotatedBlockTree( 10 );

		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'invalidateAnnotatedTreeCache', () => {
	beforeEach( () => {
		mockIntrospectBlockTree.mockReset();
		mockAnnotateStructuralIdentity.mockReset();
		invalidateAnnotatedTreeCache();
	} );

	test( 'forces rebuild on next call after invalidation', () => {
		const mockTree = [
			{ clientId: 'a', name: 'core/group', innerBlocks: [] },
		];
		mockIntrospectBlockTree.mockReturnValue( mockTree );
		mockAnnotateStructuralIdentity.mockReturnValue( [
			{
				clientId: 'a',
				name: 'core/group',
				innerBlocks: [],
				structuralIdentity: {},
			},
		] );

		getAnnotatedBlockTree( 10 );
		invalidateAnnotatedTreeCache();
		getAnnotatedBlockTree( 10 );

		// Both called twice: cache was invalidated between calls.
		expect( mockIntrospectBlockTree ).toHaveBeenCalledTimes( 2 );
		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledTimes( 2 );
	} );
} );
