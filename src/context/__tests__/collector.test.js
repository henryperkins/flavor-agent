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
const mockGetStructuralIdentityFingerprintAttributes = jest.fn();
const mockToStructuralSummary = jest.fn();

jest.mock( '../../utils/structural-identity', () => ( {
	annotateStructuralIdentity: ( ...args ) =>
		mockAnnotateStructuralIdentity( ...args ),
	findBranchRoot: ( ...args ) => mockFindBranchRoot( ...args ),
	findNodePath: ( ...args ) => mockFindNodePath( ...args ),
	getStructuralIdentityFingerprintAttributes: ( ...args ) =>
		mockGetStructuralIdentityFingerprintAttributes( ...args ),
	toStructuralSummary: ( ...args ) => mockToStructuralSummary( ...args ),
} ) );

const {
	collectBlockContext,
	getAnnotatedBlockTree,
	getLiveBlockContextSignature,
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
		mockGetStructuralIdentityFingerprintAttributes.mockReset();
		mockToStructuralSummary.mockReset();
		mockGetStructuralIdentityFingerprintAttributes.mockImplementation(
			( node ) => {
				const attributes = node?.currentAttributes || {};
				const fingerprint = {};

				if ( node?.name === 'core/template-part' ) {
					if ( attributes.area ) {
						fingerprint.area = attributes.area;
					}

					if ( attributes.slug ) {
						fingerprint.slug = attributes.slug;
					}

					if ( attributes.tagName ) {
						fingerprint.tagName = attributes.tagName;
					}
				}

				if (
					node?.name === 'core/query' &&
					attributes?.query &&
					typeof attributes.query === 'object' &&
					'inherit' in attributes.query
				) {
					fingerprint.query = {
						inherit: attributes.query.inherit,
					};
				}

				return fingerprint;
			}
		);
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

	test( 'caps structural ancestors to the server-visible limit', () => {
		const ancestorNodes = Array.from(
			{ length: 7 },
			( _unused, index ) => ( {
				clientId: `ancestor-${ index + 1 }`,
				name: `core/group-${ index + 1 }`,
				innerBlocks: [],
				structuralIdentity: { role: `ancestor-${ index + 1 }` },
			} )
		);
		const selectedNode = {
			clientId: 'client-1',
			name: 'core/button',
			innerBlocks: [],
			structuralIdentity: { role: 'cta-button' },
		};

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
			{
				clientId: 'ancestor-1',
				innerBlocks: [ { clientId: 'client-1', innerBlocks: [] } ],
			},
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [
			ancestorNodes[ 0 ],
		] );
		mockFindNodePath.mockReturnValue( [ ...ancestorNodes, selectedNode ] );
		mockFindBranchRoot.mockReturnValue( ancestorNodes[ 0 ] );
		mockToStructuralSummary.mockImplementation( ( node ) => ( {
			block: node.name,
			role: node.structuralIdentity?.role,
		} ) );
		mockSummarizeTree.mockReturnValue( [ { block: 'core/group-5' } ] );

		mockCollectThemeTokens.mockReturnValue( { color: {} } );
		mockSummarizeTokens.mockReturnValue( { colors: [] } );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( null ),
			getBlockOrder: jest.fn().mockReturnValue( [] ),
			getBlockName: jest.fn(),
		} );

		const result = collectBlockContext( 'client-1' );

		expect( result.structuralAncestors ).toHaveLength( 6 );
		expect( result.structuralAncestors[ 0 ] ).toEqual( {
			block: 'core/group-2',
			role: 'ancestor-2',
		} );
		expect( result.structuralAncestors[ 5 ] ).toEqual( {
			block: 'core/group-7',
			role: 'ancestor-7',
		} );
	} );

	test( 'does not rebuild the annotated tree when unrelated attributes change', () => {
		mockIntrospectBlockTree
			.mockReturnValueOnce( [
				{
					clientId: 'node-1',
					name: 'core/paragraph',
					currentAttributes: {
						content: 'Alpha',
						className: 'has-drop-cap',
						textColor: 'contrast',
					},
					innerBlocks: [],
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'node-1',
					name: 'core/paragraph',
					currentAttributes: {
						content: 'Beta',
						className: 'has-background',
						textColor: 'accent',
					},
					innerBlocks: [],
				},
			] );
		mockAnnotateStructuralIdentity.mockReturnValue( [
			{
				clientId: 'node-1',
				name: 'core/paragraph',
				innerBlocks: [],
				structuralIdentity: {},
			},
		] );

		getAnnotatedBlockTree( 10 );
		getAnnotatedBlockTree( 10 );

		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'rebuilds when template-part location evidence changes without tree shape changes', () => {
		mockIntrospectBlockTree
			.mockReturnValueOnce( [
				{
					clientId: 'part-1',
					name: 'core/template-part',
					currentAttributes: {
						slug: 'site-header',
						tagName: 'header',
					},
					innerBlocks: [],
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'part-1',
					name: 'core/template-part',
					currentAttributes: {
						slug: 'site-footer',
						tagName: 'footer',
					},
					innerBlocks: [],
				},
			] );
		mockAnnotateStructuralIdentity
			.mockReturnValueOnce( [
				{
					clientId: 'part-1',
					name: 'core/template-part',
					innerBlocks: [],
					structuralIdentity: { role: 'header-slot' },
				},
			] )
			.mockReturnValueOnce( [
				{
					clientId: 'part-1',
					name: 'core/template-part',
					innerBlocks: [],
					structuralIdentity: { role: 'footer-slot' },
				},
			] );

		getAnnotatedBlockTree( 10 );
		getAnnotatedBlockTree( 10 );

		expect( mockAnnotateStructuralIdentity ).toHaveBeenCalledTimes( 2 );
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

	test( 'sibling summaries omit non-allowlisted attributes', () => {
		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/paragraph',
			title: 'Paragraph',
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
				clientId: 'root',
				innerBlocks: [
					{ clientId: 'sib-1', innerBlocks: [] },
					{ clientId: 'target', innerBlocks: [] },
				],
			},
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [] );
		mockFindNodePath.mockReturnValue( null );
		mockCollectThemeTokens.mockReturnValue( {} );
		mockSummarizeTokens.mockReturnValue( {} );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( 'root' ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'sib-1', 'target' ] ),
			getBlockName: jest
				.fn()
				.mockImplementation( ( id ) =>
					id === 'sib-1' ? 'core/heading' : undefined
				),
			getBlockAttributes: jest.fn().mockImplementation( ( id ) => {
				if ( id === 'sib-1' ) {
					return {
						align: 'wide',
						content: 'Hello World',
						className: 'custom',
						fontSize: 'large',
						style: {
							color: { text: '#000' },
							typography: { lineHeight: '1.5' },
						},
					};
				}
				return {};
			} ),
		} );

		const result = collectBlockContext( 'target' );

		expect( result.siblingSummariesBefore ).toHaveLength( 1 );
		const hints = result.siblingSummariesBefore[ 0 ].visualHints;
		expect( hints.align ).toBe( 'wide' );
		expect( hints.style ).toEqual( { color: { text: '#000' } } );
		expect( hints ).not.toHaveProperty( 'content' );
		expect( hints ).not.toHaveProperty( 'className' );
		expect( hints ).not.toHaveProperty( 'fontSize' );
		expect( hints.style ).not.toHaveProperty( 'typography' );
	} );

	test( 'omits parentContext for root-level block', () => {
		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/group',
			title: 'Group',
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
			{ clientId: 'root-block', innerBlocks: [] },
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [] );
		mockFindNodePath.mockReturnValue( null );
		mockCollectThemeTokens.mockReturnValue( {} );
		mockSummarizeTokens.mockReturnValue( {} );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( null ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'root-block' ] ),
			getBlockName: jest.fn(),
		} );

		const result = collectBlockContext( 'root-block' );

		expect( result.parentContext ).toBeUndefined();
	} );

	test( 'omits parent role and job when structural identity lookup misses', () => {
		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/paragraph',
			title: 'Paragraph',
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
				clientId: 'parent-deep',
				innerBlocks: [ { clientId: 'child-1', innerBlocks: [] } ],
			},
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [] );
		mockFindNodePath.mockReturnValue( null );
		mockCollectThemeTokens.mockReturnValue( {} );
		mockSummarizeTokens.mockReturnValue( {} );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( 'parent-deep' ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'child-1' ] ),
			getBlockName: jest
				.fn()
				.mockImplementation( ( id ) =>
					id === 'parent-deep' ? 'core/cover' : undefined
				),
			getBlockAttributes: jest.fn().mockReturnValue( {
				backgroundColor: 'contrast',
			} ),
			getBlockCount: jest.fn().mockReturnValue( 1 ),
		} );

		const result = collectBlockContext( 'child-1' );

		expect( result.parentContext ).toBeDefined();
		expect( result.parentContext.block ).toBe( 'core/cover' );
		expect( result.parentContext.visualHints ).toEqual( {
			backgroundColor: 'contrast',
		} );
		expect( result.parentContext ).not.toHaveProperty( 'role' );
		expect( result.parentContext ).not.toHaveProperty( 'job' );
	} );

	test( 'preserves existing siblingsBefore and siblingsAfter string arrays', () => {
		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/paragraph',
			title: 'Paragraph',
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
				clientId: 'root',
				innerBlocks: [
					{ clientId: 'a', innerBlocks: [] },
					{ clientId: 'target', innerBlocks: [] },
					{ clientId: 'b', innerBlocks: [] },
				],
			},
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [] );
		mockFindNodePath.mockReturnValue( null );
		mockCollectThemeTokens.mockReturnValue( {} );
		mockSummarizeTokens.mockReturnValue( {} );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( 'root' ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'a', 'target', 'b' ] ),
			getBlockName: jest.fn().mockImplementation( ( id ) => {
				const map = { a: 'core/heading', b: 'core/image' };
				return map[ id ];
			} ),
			getBlockAttributes: jest.fn().mockReturnValue( {} ),
		} );

		const result = collectBlockContext( 'target' );

		expect( result.siblingsBefore ).toEqual( [ 'core/heading' ] );
		expect( result.siblingsAfter ).toEqual( [ 'core/image' ] );
		expect( Array.isArray( result.siblingSummariesBefore ) ).toBe( true );
		expect( Array.isArray( result.siblingSummariesAfter ) ).toBe( true );
	} );
} );

describe( 'getAnnotatedBlockTree', () => {
	beforeEach( () => {
		mockIntrospectBlockTree.mockReset();
		mockAnnotateStructuralIdentity.mockReset();
		mockGetStructuralIdentityFingerprintAttributes.mockReset();
		mockGetStructuralIdentityFingerprintAttributes.mockImplementation(
			( node ) => {
				const attributes = node?.currentAttributes || {};
				const fingerprint = {};

				if ( node?.name === 'core/template-part' ) {
					if ( attributes.area ) {
						fingerprint.area = attributes.area;
					}

					if ( attributes.slug ) {
						fingerprint.slug = attributes.slug;
					}

					if ( attributes.tagName ) {
						fingerprint.tagName = attributes.tagName;
					}
				}

				if (
					node?.name === 'core/query' &&
					attributes?.query &&
					typeof attributes.query === 'object' &&
					'inherit' in attributes.query
				) {
					fingerprint.query = {
						inherit: attributes.query.inherit,
					};
				}

				return fingerprint;
			}
		);
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

describe( 'getLiveBlockContextSignature', () => {
	/**
	 * Build a fake registrySelect that returns an editor store stub. The
	 * `subscribeToBlockContextSources` function uses registrySelect to read
	 * selectors and register data-store dependencies; it does NOT use the
	 * module-level `select` for those reads.  collectBlockContext (called
	 * internally) *does* use the module-level `select`, so we must also set
	 * up mockSelect.
	 * @param {Object} editorOverrides
	 */
	function buildRegistrySelect( editorOverrides = {} ) {
		const baseEditor = {
			getBlock: jest.fn().mockReturnValue( {
				clientId: 'test-block',
				name: 'core/paragraph',
				attributes: {},
				innerBlocks: [],
			} ),
			getBlockRootClientId: jest.fn().mockReturnValue( '' ),
			getBlockName: jest.fn().mockReturnValue( 'core/paragraph' ),
			getBlockAttributes: jest.fn().mockReturnValue( {} ),
			getBlockEditingMode: jest.fn().mockReturnValue( 'default' ),
			getBlockParents: jest.fn().mockReturnValue( [] ),
			getBlockCount: jest.fn().mockReturnValue( 0 ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'test-block' ] ),
			getBlocks: jest.fn().mockReturnValue( [] ),
			getSettings: jest.fn().mockReturnValue( {} ),
			...editorOverrides,
		};

		const baseBlocks = {
			getBlockType: jest.fn().mockReturnValue( null ),
			getBlockStyles: jest.fn().mockReturnValue( [] ),
			getBlockVariations: jest.fn().mockReturnValue( [] ),
		};

		return ( store ) =>
			store === mockBlockEditorStore ? baseEditor : baseBlocks;
	}

	function setupCollectMocks() {
		mockIntrospectBlockInstance.mockReturnValue( {
			name: 'core/paragraph',
			title: 'Paragraph',
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
			{ clientId: 'test-block', innerBlocks: [] },
		] );
		mockAnnotateStructuralIdentity.mockReturnValue( [] );
		mockFindNodePath.mockReturnValue( null );
		mockCollectThemeTokens.mockReturnValue( {} );
		mockSummarizeTokens.mockReturnValue( {} );
	}

	beforeEach( () => {
		jest.clearAllMocks();
		invalidateAnnotatedTreeCache();
	} );

	test( 'returns empty string for null clientId', () => {
		const registrySelect = buildRegistrySelect();
		const sig = getLiveBlockContextSignature( registrySelect, null );
		expect( sig ).toBe( '' );
	} );

	test( 'changes when parent visual context changes', () => {
		setupCollectMocks();

		// First pass: no parent (root block).
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( '' ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'test-block' ] ),
			getBlockName: jest.fn().mockReturnValue( 'core/paragraph' ),
			getBlockAttributes: jest.fn().mockReturnValue( {} ),
		} );

		const sig1 = getLiveBlockContextSignature(
			buildRegistrySelect(),
			'test-block'
		);

		// Second pass: place inside a parent with visual hints.
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( 'parent-group' ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'test-block' ] ),
			getBlockName: jest
				.fn()
				.mockImplementation( ( id ) =>
					id === 'parent-group' ? 'core/group' : 'core/paragraph'
				),
			getBlockAttributes: jest
				.fn()
				.mockImplementation( ( id ) =>
					id === 'parent-group' ? { backgroundColor: 'contrast' } : {}
				),
			getBlockCount: jest.fn().mockReturnValue( 1 ),
		} );

		const sig2 = getLiveBlockContextSignature(
			buildRegistrySelect( {
				getBlockRootClientId: jest
					.fn()
					.mockReturnValue( 'parent-group' ),
				getBlockName: jest
					.fn()
					.mockImplementation( ( id ) =>
						id === 'parent-group' ? 'core/group' : 'core/paragraph'
					),
				getBlockAttributes: jest
					.fn()
					.mockImplementation( ( id ) =>
						id === 'parent-group'
							? { backgroundColor: 'contrast' }
							: {}
					),
				getBlockCount: jest.fn().mockReturnValue( 1 ),
			} ),
			'test-block'
		);

		expect( sig1 ).not.toBe( '' );
		expect( sig2 ).not.toBe( '' );
		expect( sig1 ).not.toEqual( sig2 );
	} );

	test( 'changes when sibling visual context changes', () => {
		setupCollectMocks();

		// First pass: no siblings.
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( 'root' ),
			getBlockOrder: jest.fn().mockReturnValue( [ 'test-block' ] ),
			getBlockName: jest.fn().mockReturnValue( 'core/paragraph' ),
			getBlockAttributes: jest.fn().mockReturnValue( {} ),
			getBlockCount: jest.fn().mockReturnValue( 1 ),
		} );

		const sig1 = getLiveBlockContextSignature(
			buildRegistrySelect( {
				getBlockRootClientId: jest.fn().mockReturnValue( 'root' ),
				getBlockOrder: jest.fn().mockReturnValue( [ 'test-block' ] ),
				getBlockCount: jest.fn().mockReturnValue( 1 ),
			} ),
			'test-block'
		);

		// Second pass: add a sibling with visual hints.
		mockIntrospectBlockTree.mockReturnValue( [
			{
				clientId: 'root',
				innerBlocks: [
					{ clientId: 'sibling-1', innerBlocks: [] },
					{ clientId: 'test-block', innerBlocks: [] },
				],
			},
		] );
		mockSelect.mockReturnValue( {
			getBlockRootClientId: jest.fn().mockReturnValue( 'root' ),
			getBlockOrder: jest
				.fn()
				.mockReturnValue( [ 'sibling-1', 'test-block' ] ),
			getBlockName: jest
				.fn()
				.mockImplementation( ( id ) =>
					id === 'sibling-1' ? 'core/heading' : 'core/paragraph'
				),
			getBlockAttributes: jest
				.fn()
				.mockImplementation( ( id ) =>
					id === 'sibling-1'
						? { align: 'wide', textColor: 'primary' }
						: {}
				),
			getBlockCount: jest.fn().mockReturnValue( 2 ),
		} );

		invalidateAnnotatedTreeCache();

		const sig2 = getLiveBlockContextSignature(
			buildRegistrySelect( {
				getBlockRootClientId: jest.fn().mockReturnValue( 'root' ),
				getBlockOrder: jest
					.fn()
					.mockReturnValue( [ 'sibling-1', 'test-block' ] ),
				getBlockName: jest
					.fn()
					.mockImplementation( ( id ) =>
						id === 'sibling-1' ? 'core/heading' : 'core/paragraph'
					),
				getBlockAttributes: jest
					.fn()
					.mockImplementation( ( id ) =>
						id === 'sibling-1'
							? { align: 'wide', textColor: 'primary' }
							: {}
					),
				getBlockCount: jest.fn().mockReturnValue( 2 ),
			} ),
			'test-block'
		);

		expect( sig1 ).not.toBe( '' );
		expect( sig2 ).not.toBe( '' );
		expect( sig1 ).not.toEqual( sig2 );
	} );
} );
