const mockUseDispatch = jest.fn();
const mockUseRegistry = jest.fn();
const mockUseSelect = jest.fn();
const mockCloneBlock = jest.fn();
const mockCreateBlock = jest.fn();
const mockParse = jest.fn();
const mockFetchPatternRecommendations = jest.fn();
const mockResolvePatternRecommendationSignature = jest.fn();
const mockRecordRecommendationOutcome = jest.fn();
const mockInsertBlocks = jest.fn();
const mockRemoveBlocks = jest.fn();
const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();
const mockCanInsertBlockType = jest.fn();
const mockGetBlockAttributes = jest.fn();
const mockGetAllowedPatterns = jest.fn();
const mockFindInserterContainer = jest.fn();
const mockFindInserterSearchInput = jest.fn();
const mockGetVisiblePatternNames = jest.fn();

const fs = require( 'fs' );
const path = require( 'path' );

const EDITOR_CSS = fs.readFileSync(
	path.join( __dirname, '../../editor.css' ),
	'utf8'
);

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	cloneBlock: ( ...args ) => mockCloneBlock( ...args ),
	createBlock: ( ...args ) => mockCreateBlock( ...args ),
	parse: ( ...args ) => mockParse( ...args ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useRegistry: ( ...args ) => mockUseRegistry( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '@wordpress/editor', () => ( {
	store: 'core/editor',
} ) );

jest.mock( '@wordpress/i18n', () => {
	const translate = jest.fn( ( value ) => value );
	const sprintf = jest.fn( ( template, ...values ) => {
		let i = 0;
		return template
			.replace(
				/%(\d+)\$s/g,
				( _, n ) => values[ Number( n ) - 1 ] ?? ''
			)
			.replace( /%s/g, () => values[ i++ ] ?? '' );
	} );

	return {
		__: translate,
		sprintf,
	};
} );

jest.mock( '@wordpress/notices', () => ( {
	store: 'core/notices',
} ) );

jest.mock( '../pattern-settings', () => ( {
	getAllowedPatterns: ( ...args ) => mockGetAllowedPatterns( ...args ),
} ) );

jest.mock( '../inserter-dom', () => ( {
	findInserterContainer: ( ...args ) => mockFindInserterContainer( ...args ),
	findInserterSearchInput: ( ...args ) =>
		mockFindInserterSearchInput( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	getResolvedContextSignatureFromResponse: ( result = null ) => {
		const fromPayload =
			typeof result?.payload?.resolvedContextSignature === 'string'
				? result.payload.resolvedContextSignature.trim()
				: '';

		if ( fromPayload ) {
			return fromPayload;
		}

		const direct =
			typeof result?.resolvedContextSignature === 'string'
				? result.resolvedContextSignature.trim()
				: '';

		return direct || null;
	},
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../utils/visible-patterns', () => ( {
	getVisiblePatternNames: ( ...args ) =>
		mockGetVisiblePatternNames( ...args ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );
const {
	__: mockTranslate,
	sprintf: mockSprintf,
} = require( '@wordpress/i18n' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import PatternRecommender from '../PatternRecommender';

const { getRoot } = setupReactTest();

let state = null;
let originalMutationObserver = null;
const DOCS_WARNING_TEXT =
	'Developer Docs grounding is trusted, but current release-cycle sources have not been confirmed. Review current WordPress docs before applying.';
const DOCS_GROUNDING_WARNING = {
	status: 'grounded',
	coverageStatus: 'missing-current-release-cycle',
};

function createSelectMap() {
	return {
		'core/editor': {
			getCurrentPostType: jest.fn( () => state.postType ),
			isInserterOpened: jest.fn( () => state.isInserterOpen ),
		},
		'core/edit-site': {
			getEditedPostType: jest.fn( () => state.editSite.postType ),
			getEditedPostId: jest.fn( () => state.editSite.postId ),
		},
		'core/block-editor': {
			getSelectedBlockClientId: jest.fn(
				() => state.blockEditor.selectedBlockClientId
			),
			getBlockName: jest.fn( ( clientId ) => {
				if ( clientId === state.blockEditor.selectedBlockClientId ) {
					return state.blockEditor.selectedBlockName;
				}
				return (
					( state.blockEditor.blockNames || {} )[ clientId ] ?? null
				);
			} ),
			getBlockInsertionPoint: jest.fn(
				() => state.blockEditor.insertionPoint
			),
			getBlockOrder: jest.fn(
				( rootClientId ) =>
					( state.blockEditor.blockOrder || {} )[ rootClientId ] ?? []
			),
			getBlockRootClientId: jest.fn(
				( clientId ) =>
					( state.blockEditor.blockRoots || {} )[ clientId ] ?? null
			),
			getBlockAttributes: mockGetBlockAttributes,
			getBlocks: jest.fn(
				( rootClientId ) =>
					( state.blockEditor.blocks || {} )[ rootClientId ?? '' ] ??
					[]
			),
			getBlock: jest.fn( ( clientId ) => {
				for ( const blocks of Object.values(
					state.blockEditor.blocks || {}
				) ) {
					const block = blocks.find(
						( candidate ) => candidate.clientId === clientId
					);

					if ( block ) {
						return block;
					}
				}

				return null;
			} ),
			canInsertBlockType: ( ...args ) =>
				mockCanInsertBlockType( ...args ),
		},
		'flavor-agent': {
			getPatternError: jest.fn( () => state.store.patternError ),
			getPatternErrorDetails: jest.fn(
				() => state.store.patternErrorDetails
			),
			getPatternStatus: jest.fn( () => state.store.patternStatus ),
			getPatternRecommendations: jest.fn(
				() => state.store.patternRecommendations
			),
			getPatternDiagnostics: jest.fn(
				() => state.store.patternDiagnostics
			),
			getPatternRequestSignature: jest.fn(
				() => state.store.patternRequestSignature
			),
			getPatternInsertionTargetSignature: jest.fn(
				() => state.store.patternInsertionTargetSignature
			),
			getPatternResolvedContextSignature: jest.fn(
				() => state.store.patternResolvedContextSignature
			),
			getPatternDocsGroundingWarning: jest.fn(
				() => state.store.patternDocsGroundingWarning
			),
		},
	};
}

function renderComponent() {
	act( () => {
		getRoot().render( <PatternRecommender /> );
	} );
}

describe( 'PatternRecommender', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		state = {
			postType: 'page',
			isInserterOpen: true,
			visiblePatternNames: [ 'theme/hero' ],
			allowedPatterns: [],
			editSite: {
				postType: 'page',
				postId: null,
			},
			blockEditor: {
				selectedBlockClientId: null,
				selectedBlockName: null,
				insertionPoint: {
					rootClientId: 'root-a',
					index: 0,
				},
				blockNames: { 'root-a': 'core/group' },
				blockOrder: { 'root-a': [] },
				blockRoots: { 'root-a': null },
				blockAttributes: {},
				blocks: { 'root-a': [] },
			},
			store: {
				patternError: '',
				patternErrorDetails: null,
				patternStatus: 'idle',
				patternRecommendations: [],
				patternDiagnostics: null,
				patternRequestSignature: '',
				patternInsertionTargetSignature: '',
				patternResolvedContextSignature: 'resolved-pattern-context',
				patternDocsGroundingWarning: null,
			},
		};
		mockUseDispatch.mockReset();
		mockUseRegistry.mockReset();
		mockUseSelect.mockReset();
		mockCloneBlock.mockReset();
		mockCreateBlock.mockReset();
		mockParse.mockReset();
		mockFetchPatternRecommendations.mockReset();
		mockResolvePatternRecommendationSignature.mockReset();
		mockResolvePatternRecommendationSignature.mockResolvedValue( {
			resolvedContextSignature: 'resolved-pattern-context',
		} );
		mockRecordRecommendationOutcome.mockReset();
		mockInsertBlocks.mockReset();
		mockInsertBlocks.mockImplementation(
			( blocks, index = null, rootClientId = '' ) => {
				const key = rootClientId ?? '';
				const current = [
					...( ( state.blockEditor.blocks || {} )[ key ] || [] ),
				];
				const insertionIndex =
					Number.isInteger( index ) && index >= 0
						? Math.min( index, current.length )
						: current.length;

				state.blockEditor.blocks = {
					...( state.blockEditor.blocks || {} ),
					[ key ]: [
						...current.slice( 0, insertionIndex ),
						...blocks,
						...current.slice( insertionIndex ),
					],
				};
			}
		);
		mockRemoveBlocks.mockReset();
		mockRemoveBlocks.mockImplementation( ( clientIds ) => {
			const ids = new Set( clientIds );

			state.blockEditor.blocks = Object.fromEntries(
				Object.entries( state.blockEditor.blocks || {} ).map(
					( [ key, blocks ] ) => [
						key,
						blocks.filter(
							( block ) => ! ids.has( block.clientId )
						),
					]
				)
			);
		} );
		mockCreateSuccessNotice.mockReset();
		mockCreateErrorNotice.mockReset();
		mockCanInsertBlockType.mockReset();
		mockCanInsertBlockType.mockReturnValue( true );
		mockGetBlockAttributes.mockReset();
		mockGetAllowedPatterns.mockReset();
		mockFindInserterContainer.mockReset();
		mockFindInserterSearchInput.mockReset();
		mockGetVisiblePatternNames.mockReset();
		mockTranslate.mockClear();
		mockTranslate.mockImplementation( ( value ) => value );
		mockSprintf.mockClear();
		mockSprintf.mockImplementation( ( template, ...values ) => {
			let i = 0;
			return template
				.replace(
					/%(\d+)\$s/g,
					( _, n ) => values[ Number( n ) - 1 ] ?? ''
				)
				.replace( /%s/g, () => values[ i++ ] ?? '' );
		} );
		mockUseDispatch.mockImplementation( ( storeName ) => {
			if ( storeName === 'flavor-agent' ) {
				return {
					fetchPatternRecommendations: ( input ) =>
						mockFetchPatternRecommendations( input ),
					resolvePatternRecommendationSignature: ( input ) =>
						mockResolvePatternRecommendationSignature( input ),
					recordRecommendationOutcome: ( outcome ) =>
						mockRecordRecommendationOutcome( outcome ),
				};
			}

			if ( storeName === 'core/block-editor' ) {
				return {
					insertBlocks: mockInsertBlocks,
					removeBlocks: mockRemoveBlocks,
				};
			}

			if ( storeName === 'core/notices' ) {
				return {
					createSuccessNotice: mockCreateSuccessNotice,
					createErrorNotice: mockCreateErrorNotice,
				};
			}

			return {};
		} );
		mockUseRegistry.mockImplementation( () => ( {
			select: ( storeName ) => createSelectMap()[ storeName ] || {},
		} ) );
		mockUseSelect.mockImplementation( ( callback ) =>
			callback( ( storeName ) => createSelectMap()[ storeName ] )
		);
		mockCloneBlock.mockImplementation( ( block ) => ( {
			...block,
			cloned: true,
		} ) );
		mockCreateBlock.mockImplementation( ( name, attributes ) => ( {
			name,
			attributes,
		} ) );
		mockParse.mockReturnValue( [] );
		mockGetBlockAttributes.mockImplementation(
			( clientId ) =>
				( state.blockEditor.blockAttributes || {} )[ clientId ] || {}
		);
		mockGetAllowedPatterns.mockImplementation(
			() => state.allowedPatterns
		);
		mockGetVisiblePatternNames.mockImplementation(
			() => state.visiblePatternNames
		);
		window.flavorAgentData = { canRecommendPatterns: true };
		originalMutationObserver = window.MutationObserver;
	} );

	afterEach( () => {
		try {
			act( () => {
				getRoot().unmount();
			} );
		} catch {
			// Ignore unmount errors from already-unmounted roots in test cleanup.
		}
		document.body.innerHTML = '';
		state = null;
		delete window.flavorAgentData;
		window.MutationObserver = originalMutationObserver;
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
	} );

	test( 'uses the defined editor text token for pattern shelf titles', () => {
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-pattern-shelf__title\s*\{[^}]*color:\s*var\(--flavor-agent-editor-text\)/s
		);
		expect( EDITOR_CSS ).not.toContain( '--flavor-agent-editor-ink' );
	} );

	test( 'uses semantic z-index tokens for editor overlays', () => {
		expect( EDITOR_CSS ).toContain(
			'--flavor-agent-editor-z-inserter-badge: 1;'
		);
		expect( EDITOR_CSS ).toContain(
			'--flavor-agent-editor-z-toast-region: 100000;'
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-inserter-badge-anchor\s*\{[^}]*isolation:\s*isolate;/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-inserter-badge\s*\{[^}]*z-index:\s*var\(--flavor-agent-editor-z-inserter-badge\)/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-toast-region\s*\{[^}]*z-index:\s*var\(--flavor-agent-editor-z-toast-region\)/s
		);
		expect( EDITOR_CSS ).not.toMatch(
			/\.flavor-agent-inserter-badge\s*\{[^}]*z-index:\s*100\b/s
		);
		expect( EDITOR_CSS ).not.toMatch(
			/\.flavor-agent-toast-region\s*\{[^}]*z-index:\s*1000\b/s
		);
	} );

	test( 'uses semantic editor tokens for recommendation hero spacing and lane pills', () => {
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-recommendation-hero\s*\{[^}]*gap:\s*var\(--flavor-agent-editor-space-8,\s*8px\);[^}]*padding:\s*var\(--flavor-agent-editor-space-12,\s*12px\);/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-recommendation-hero__header\s*\{[^}]*gap:\s*var\(--flavor-agent-editor-space-12,\s*12px\);/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-recommendation-hero__actions\s*\{[^}]*gap:\s*var\(--flavor-agent-editor-space-8,\s*8px\);/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-pill--lane\s*\{[^}]*color:\s*var\(--flavor-agent-editor-accent-strong\);/s
		);
	} );

	test( 'uses semantic toast severity tokens instead of raw variant literals', () => {
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-toast__icon--success\s*\{[^}]*var\(--flavor-agent-color-success\)/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-toast--error\s+\.flavor-agent-toast__progress\s*\{[^}]*var\(--flavor-agent-color-error\)/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-toast--warning\s+\.flavor-agent-toast__progress\s*\{[^}]*var\(--flavor-agent-color-warning\)/s
		);
		expect( EDITOR_CSS ).not.toMatch( /#(?:6cd394|ff8b8b|f0c267)\b/i );
	} );

	test( 'dims stale passive chips and removes their live preview color', () => {
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-chip--passive\.is-stale\s*\{[^}]*opacity:\s*0\.6;/s
		);
		expect( EDITOR_CSS ).toMatch(
			/\.flavor-agent-chip--passive\.is-stale\s+\.flavor-agent-chip__preview\s*\{[^}]*background:\s*transparent;/s
		);
	} );

	test( 'provides forced-colors focus outlines for chip and panel buttons', () => {
		expect( EDITOR_CSS ).toMatch(
			/@media\s*\(forced-colors:\s*active\)\s*\{[\s\S]*\.flavor-agent-chip\.components-button:focus-visible[\s\S]*box-shadow:\s*none;[\s\S]*outline:\s*2px\s+solid\s+Highlight;[\s\S]*outline-offset:\s*2px;/s
		);
		expect( EDITOR_CSS ).toMatch(
			/@media\s*\(forced-colors:\s*active\)\s*\{[\s\S]*\.flavor-agent-panel\s+\.components-button\.is-primary:focus-visible:not\(:disabled\)[\s\S]*box-shadow:\s*none;[\s\S]*outline:\s*2px\s+solid\s+Highlight;[\s\S]*outline-offset:\s*2px;/s
		);
		expect( EDITOR_CSS ).toMatch(
			/@media\s*\(forced-colors:\s*active\)\s*\{[\s\S]*\.flavor-agent-panel\s+\.components-button\.is-secondary:focus-visible:not\(:disabled\)[\s\S]*box-shadow:\s*none;[\s\S]*outline:\s*2px\s+solid\s+Highlight;[\s\S]*outline-offset:\s*2px;/s
		);
	} );

	test( 'renders docs grounding warnings inside the inserter shelf', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
			},
		];
		state.store.patternDocsGroundingWarning = DOCS_GROUNDING_WARNING;
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/group' } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain( DOCS_WARNING_TEXT );
	} );

	test( 'disconnects the observers cleanly when the inserter search input never appears', () => {
		const observerInstances = [];

		mockFindInserterSearchInput.mockReturnValue( null );
		window.MutationObserver = class MockMutationObserver {
			constructor() {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerInstances.push( this );
			}
		};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
		expect( observerInstances ).toHaveLength( 2 );
		expect( observerInstances[ 0 ].observe ).toHaveBeenCalledWith(
			document.body,
			{
				childList: true,
				subtree: true,
			}
		);
		expect( observerInstances[ 1 ].observe ).toHaveBeenCalledWith(
			document.body,
			{
				childList: true,
				subtree: true,
			}
		);

		act( () => {
			getRoot().unmount();
		} );

		expect( observerInstances[ 0 ].disconnect ).toHaveBeenCalled();
		expect( observerInstances[ 1 ].disconnect ).toHaveBeenCalled();
	} );

	test( 'derives template-part metadata from ancestor attributes and lookup data', () => {
		state.blockEditor.insertionPoint = {
			rootClientId: 'group-a',
			index: 1,
		};
		state.blockEditor.blockNames = {
			'tpl-a': 'core/template-part',
			'group-a': 'core/group',
			'sibling-a': 'core/paragraph',
			'sibling-b': 'core/image',
			'sibling-c': 'core/buttons',
		};
		state.blockEditor.blockRoots = {
			'group-a': 'tpl-a',
			'tpl-a': null,
		};
		state.blockEditor.blockOrder = {
			'group-a': [ 'sibling-a', 'sibling-b', 'sibling-c' ],
		};
		state.blockEditor.blockAttributes = {
			'tpl-a': {
				slug: 'site-header',
			},
			'group-a': {
				layout: {
					type: 'flex',
				},
			},
		};
		window.flavorAgentData = {
			canRecommendPatterns: true,
			templatePartAreas: {
				'site-header': 'header',
			},
		};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/template-part', 'core/group' ],
				nearbySiblings: [
					'core/paragraph',
					'core/image',
					'core/buttons',
				],
				templatePartArea: 'header',
				templatePartSlug: 'site-header',
				containerLayout: 'flex',
			},
		} );
		expect( mockGetBlockAttributes ).toHaveBeenCalledWith( 'group-a' );
		expect( mockGetBlockAttributes ).toHaveBeenCalledWith( 'tpl-a' );
	} );

	test( 'omits rootBlock from root-level insertion context requests', () => {
		state.blockEditor.insertionPoint = {
			rootClientId: null,
			index: 0,
		};
		state.blockEditor.blockNames = {};
		state.blockEditor.blockRoots = {};
		state.blockEditor.blockOrder = {};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				ancestors: [],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'removes the input listener on unmount when a search field is found immediately', () => {
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};

		mockFindInserterSearchInput.mockReturnValue( searchInput );

		renderComponent();

		expect( searchInput.addEventListener ).toHaveBeenCalledWith(
			'input',
			expect.any( Function )
		);

		act( () => {
			getRoot().unmount();
		} );

		expect( searchInput.removeEventListener ).toHaveBeenCalledWith(
			'input',
			searchInput.addEventListener.mock.calls[ 0 ][ 1 ]
		);
	} );

	test( 'shows a shared capability notice inside the inserter when pattern recommendations are unavailable', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		window.flavorAgentData = {
			canRecommendPatterns: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
		expect( document.body.textContent ).toContain(
			'Pattern recommendations need the Embedding Model and Qdrant Pattern Storage'
		);
		expect( document.body.textContent ).toContain(
			'Settings > Flavor Agent'
		);
		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
	} );

	test( 'renders a loading notice inside the inserter while ranking patterns', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'loading';
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Ranking patterns for this insertion point.'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Ranking patterns for this insertion point.',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Ranking…',
			'flavor-agent'
		);
	} );

	test( 'renders an idle notice instead of loading when no recommendation context is available', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.postType = '';
		state.editSite = {
			postType: '',
			postId: null,
		};
		state.store.patternStatus = 'idle';
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
		expect( document.body.textContent ).toContain(
			'Preparing pattern recommendations for this insertion point.'
		);
		expect( document.body.textContent ).not.toContain(
			'Ranking patterns for this insertion point.'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Preparing pattern recommendations for this insertion point.',
			'flavor-agent'
		);
	} );

	test( 'renders an empty-state notice inside the inserter when no pattern matches are returned', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Flavor Agent did not find a strong pattern match for this insertion point yet.'
		);
		expect( document.body.textContent ).toContain( 'No matches yet' );
	} );

	test( 'uses unreadable synced-pattern diagnostics for the empty state message', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [];
		state.store.patternDiagnostics = {
			filteredCandidates: {
				unreadableSyncedPatterns: 1,
			},
		};
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'1 synced pattern was skipped because current WordPress permissions do not allow read access.'
		);
		expect( document.body.textContent ).not.toContain( 'Private' );
	} );

	test( 'shows an unavailable-native-pattern message until the allowed pattern list hydrates', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Flavor Agent found ranked patterns, but Gutenberg is not currently exposing those patterns for this insertion point.'
		);
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );

		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];

		renderComponent();

		const shelfText = inserterContainer.textContent;

		expect( shelfText ).toContain( 'Hero' );
		expect( shelfText ).toContain( 'Flavor Agent' );
		expect( shelfText ).toContain( '1 recommendation' );
		expect( shelfText ).not.toContain( 'native pattern registry' );
		expect( shelfText ).not.toContain( 'Gutenberg' );
	} );

	test( 'does not record pattern shown outcomes when the inserter shelf is not visible', () => {
		state.isInserterOpen = false;
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/group' } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( null );

		renderComponent();

		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'shown',
				surface: 'pattern',
			} )
		);
	} );

	test( 'does not record pattern shown outcomes when the inserter slot is detached', () => {
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/group' } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( null );

		renderComponent();

		expect( document.body.textContent ).not.toContain( 'Hero' );
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'shown',
				surface: 'pattern',
			} )
		);
	} );

	test( 'records pattern shown outcomes when the shelf renders in the inserter', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				reason: 'Matches this insertion point.',
				ranking: {
					contextScore: 0.91,
					blendedScore: 0.88,
					rankingVersion: 'contextual-ranking-v1',
				},
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/group' } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain( 'Hero' );
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'shown',
				surface: 'pattern',
				reason: 'recommendation_set_visible',
				resultCount: 1,
				patternKey: expect.any( String ),
				rankingSet: [
					expect.objectContaining( {
						suggestionKey: 'theme/hero',
						ranking: expect.objectContaining( {
							contextScore: 0.91,
							blendedScore: 0.88,
						} ),
					} ),
				],
			} )
		);
	} );

	test( 'records pattern shown once when recommendations hydrate after the inserter opens', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		// Phase 1: inserter open but recommendations still loading, so the shelf is
		// hidden. The notice slot attaches, but no "shown" outcome fires yet.
		state.store.patternStatus = 'loading';
		state.store.patternRecommendations = [];
		state.allowedPatterns = [];

		renderComponent();

		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( { event: 'shown', surface: 'pattern' } )
		);

		// Phase 2: recommendations hydrate and the shelf becomes visible WITHOUT a
		// fresh DOM attach (the portal stays mounted across loading -> ready). "shown"
		// must still fire exactly once. This guards the onAttached-identity-change ->
		// portal effect re-run -> re-attach chain that replaced the old dedicated
		// shouldShowPatternShelf effect; if that chain breaks, no "shown" is recorded.
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];

		renderComponent();

		const shownCalls = mockRecordRecommendationOutcome.mock.calls.filter(
			( [ payload ] ) =>
				payload?.event === 'shown' && payload?.surface === 'pattern'
		);

		expect( shownCalls ).toHaveLength( 1 );

		inserterContainer.remove();
	} );

	test( 'renders an error notice with retry inside the inserter when ranking fails', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'error';
		state.store.patternError = 'Pattern recommendation request failed.';
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Pattern recommendation request failed.'
		);
		expect( document.body.textContent ).toContain( 'Retry' );
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Ranking failed',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith( 'Retry', 'flavor-agent' );
		expect(
			inserterContainer
				.querySelector( '[role="status"]' )
				?.querySelector( 'button' )
		).toBeNull();

		act( () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Retry' )
				.click();
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'renders connector approval notice instead of generic ranking error', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		window.flavorAgentData = {
			...( window.flavorAgentData || {} ),
			canManageFlavorAgentSettings: true,
			connectorApprovalUrl:
				'https://example.test/wp-admin/options-general.php?page=connector-approvals',
		};
		state.store.patternStatus = 'error';
		state.store.patternError = 'Pattern recommendation request failed.';
		state.store.patternErrorDetails = {
			code: 'wpai_connector_not_approved',
			connectorApproval: {
				connectorId: 'openai',
				callerBasename: 'flavor-agent/flavor-agent.php',
			},
		};
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( inserterContainer.textContent ).toContain(
			'Flavor Agent needs administrator approval to use the openai connector.'
		);
		expect( inserterContainer.textContent ).toContain(
			'Open approvals page'
		);
		expect( inserterContainer.textContent ).not.toContain(
			'Pattern recommendation request failed.'
		);
		expect( inserterContainer.textContent ).not.toContain( 'Retry' );
	} );

	test( 'renders a local inserter shelf and inserts matched allowed patterns', async () => {
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		const shelfText = inserterContainer.textContent;

		expect( shelfText ).toContain( 'Hero' );
		expect( shelfText ).toContain( 'Recommended hero pattern.' );
		expect( shelfText ).toContain( 'Flavor Agent' );
		expect( shelfText ).toContain( '1 recommendation' );
		expect( shelfText ).not.toContain( 'native pattern registry' );
		expect( shelfText ).not.toContain( 'Gutenberg' );
		expect(
			inserterContainer
				.querySelector( '[role="status"]' )
				?.querySelector( 'button' )
		).toBeNull();
		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		expect( insertButton?.getAttribute( 'aria-label' ) ).toBe(
			'Insert Hero'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Insert',
			'flavor-agent'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Insert %s',
			'flavor-agent'
		);
		expect( mockSprintf ).toHaveBeenCalledWith( 'Insert %s', 'Hero' );

		await act( async () => {
			insertButton.click();
		} );

		expect( mockCloneBlock ).toHaveBeenCalledWith(
			allowedPattern.blocks[ 0 ]
		);
		expect( mockInsertBlocks ).toHaveBeenCalledWith(
			[
				{
					...allowedPattern.blocks[ 0 ],
					cloned: true,
				},
			],
			0,
			'root-a',
			true
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Block pattern "Hero" inserted.',
			{
				type: 'snackbar',
				id: 'inserter-notice',
			}
		);
	} );

	test( 'renders translated insert button text and accessible label', () => {
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [ { name: 'core/paragraph', attributes: {} } ],
		};

		mockTranslate.mockImplementation( ( value ) => {
			if ( value === 'Insert' ) {
				return 'Translated Insert';
			}
			if ( value === 'Insert %s' ) {
				return 'Translated Insert %s';
			}
			return value;
		} );
		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
			},
		];
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Translated Insert' );

		expect( insertButton ).toBeTruthy();
		expect( insertButton?.getAttribute( 'aria-label' ) ).toBe(
			'Translated Insert Hero'
		);
	} );

	test( 'blocks insert and refetches when the live insertion context drifts from the ranked context', () => {
		const {
			buildPatternInsertionTargetSignature,
		} = require( '../../utils/recommendation-request-signature' );
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		// Stale signature captured for a previous insertion target. Live signature
		// computed by the component will differ because the component includes
		// the live inserter root/index in the insertion-target signature.
		state.store.patternInsertionTargetSignature =
			buildPatternInsertionTargetSignature( {
				postType: 'page',
				inserterRootClientId: 'previous-root',
				insertionIndex: 0,
				insertionContext: {
					rootBlock: 'core/group',
					ancestors: [ 'core/group' ],
					nearbySiblings: [],
				},
			} );
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		// Drop the initial fetch from useEffect so we can observe the
		// drift-triggered refetch in isolation.
		mockFetchPatternRecommendations.mockClear();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		act( () => {
			insertButton.click();
		} );

		expect( mockInsertBlocks ).not.toHaveBeenCalled();
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice ).toHaveBeenCalledTimes( 1 );
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			expect.stringMatching(
				/insertion point has changed|inserter has moved/i
			),
			expect.objectContaining( {
				type: 'snackbar',
				id: 'inserter-notice',
			} )
		);
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				postType: 'page',
				visiblePatternNames: [ 'theme/hero' ],
				insertionContext: expect.objectContaining( {
					rootBlock: 'core/group',
				} ),
			} )
		);
	} );

	test( 'blocks insert when root and index match but insertion context changes', () => {
		const {
			buildPatternInsertionTargetSignature,
		} = require( '../../utils/recommendation-request-signature' );
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.blockEditor.blockOrder = {
			'root-a': [ 'sibling-a' ],
		};
		state.blockEditor.blockNames = {
			'root-a': 'core/group',
			'sibling-a': 'core/heading',
		};
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.store.patternInsertionTargetSignature =
			buildPatternInsertionTargetSignature( {
				postType: 'page',
				inserterRootClientId: 'root-a',
				insertionIndex: 0,
				insertionContext: {
					rootBlock: 'core/group',
					ancestors: [ 'core/group' ],
					nearbySiblings: [ 'core/paragraph' ],
				},
			} );
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();
		mockFetchPatternRecommendations.mockClear();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		act( () => {
			insertButton.click();
		} );

		expect( mockInsertBlocks ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice ).toHaveBeenCalledTimes( 1 );
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				insertionContext: expect.objectContaining( {
					nearbySiblings: [ 'core/heading' ],
				} ),
			} )
		);
	} );

	test( 'inserts when the live insertion context matches the ranked context', async () => {
		const {
			buildPatternInsertionTargetSignature,
			buildPatternRecommendationRequestSignature,
		} = require( '../../utils/recommendation-request-signature' );
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
				ranking: {
					contextScore: 0.91,
					blendedScore: 0.88,
					rankingVersion: 'contextual-ranking-v1',
				},
			},
		];
		state.store.patternRequestSignature =
			buildPatternRecommendationRequestSignature( {
				postType: 'page',
				visiblePatternNames: [ 'theme/hero' ],
				insertionContext: {
					rootBlock: 'core/group',
					ancestors: [ 'core/group' ],
					nearbySiblings: [],
				},
			} );
		state.store.patternInsertionTargetSignature =
			buildPatternInsertionTargetSignature( {
				postType: 'page',
				inserterRootClientId: 'root-a',
				insertionIndex: 0,
				insertionContext: {
					rootBlock: 'core/group',
					ancestors: [ 'core/group' ],
					nearbySiblings: [],
				},
			} );
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();
		mockFetchPatternRecommendations.mockClear();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		await act( async () => {
			insertButton.click();
		} );

		expect( mockInsertBlocks ).toHaveBeenCalledTimes( 1 );
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
		expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();

		const insertedOutcomeIndex =
			mockRecordRecommendationOutcome.mock.calls.findIndex(
				( [ outcome ] ) =>
					outcome?.event === 'pattern_inserted_from_shelf'
			);

		expect( insertedOutcomeIndex ).toBeGreaterThanOrEqual( 0 );
		expect(
			mockRecordRecommendationOutcome.mock.calls[
				insertedOutcomeIndex
			][ 0 ]
		).toEqual(
			expect.objectContaining( {
				event: 'pattern_inserted_from_shelf',
				surface: 'pattern',
				reason: 'insert_blocks_success',
				patternKey: 'theme/hero',
				suggestion: expect.objectContaining( {
					name: 'theme/hero',
					ranking: expect.objectContaining( {
						contextScore: 0.91,
					} ),
				} ),
			} )
		);
		expect(
			mockRecordRecommendationOutcome.mock.invocationCallOrder[
				insertedOutcomeIndex
			]
		).toBeGreaterThan( mockInsertBlocks.mock.invocationCallOrder[ 0 ] );
	} );

	test( 'records renderability and insertability drops before hiding the shelf', () => {
		const inserterContainer = document.createElement( 'div' );
		const blockedPattern = {
			name: 'theme/template-with-parts',
			title: 'Template with parts',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hidden',
				score: 0.95,
				reason: 'Recommended but not visible to Gutenberg.',
			},
			{
				name: blockedPattern.name,
				score: 0.94,
				reason: 'Recommended but not insertable here.',
			},
		];
		state.allowedPatterns = [ blockedPattern ];
		mockCanInsertBlockType.mockReturnValue( false );
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'validation_blocked',
				surface: 'pattern',
				reason: 'not_visible_in_inserter',
				patternKey: 'theme/hidden',
			} )
		);
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'validation_blocked',
				surface: 'pattern',
				reason: 'disallowed_block_types',
				patternKey: 'theme/template-with-parts',
			} )
		);
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'pattern_inserted_from_shelf',
			} )
		);
	} );

	test( 'records insert failure when Gutenberg rejects insertBlocks', async () => {
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );
		mockInsertBlocks.mockImplementationOnce( () => {
			throw new Error( 'Insert rejected' );
		} );

		renderComponent();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		await act( async () => {
			insertButton.click();
		} );

		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Cannot insert pattern "Hero" because Gutenberg rejected the insertion request.',
			{
				type: 'snackbar',
				id: 'inserter-notice',
			}
		);
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'insert_failed',
				surface: 'pattern',
				reason: 'insert_blocks_exception',
				patternKey: 'theme/hero',
			} )
		);
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'pattern_inserted_from_shelf',
			} )
		);
	} );

	test( 'records insert failure when post-insert verification cannot find inserted blocks', async () => {
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );
		mockInsertBlocks.mockImplementationOnce( () => {} );

		renderComponent();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		await act( async () => {
			insertButton.click();
		} );

		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		expect( mockRemoveBlocks ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Cannot confirm pattern "Hero" was inserted. Gutenberg did not report the inserted blocks at the target location.',
			{
				type: 'snackbar',
				id: 'inserter-notice',
			}
		);
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'insert_failed',
				surface: 'pattern',
				reason: 'insert_blocks_noop',
				patternKey: 'theme/hero',
			} )
		);
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'pattern_inserted_from_shelf',
			} )
		);
	} );

	test( 'records insert failure when Gutenberg inserts the blocks outside the requested target index', async () => {
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					clientId: 'inserted-client',
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.blockEditor.blocks = {
			'root-a': [
				{ clientId: 'existing-heading', name: 'core/heading' },
				{ clientId: 'existing-image', name: 'core/image' },
			],
		};
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );
		mockInsertBlocks.mockImplementationOnce( ( blocks ) => {
			state.blockEditor.blocks = {
				'root-a': [
					...state.blockEditor.blocks[ 'root-a' ],
					...blocks,
				],
			};
		} );

		renderComponent();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		await act( async () => {
			insertButton.click();
		} );

		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		expect( mockRemoveBlocks ).toHaveBeenCalledWith(
			[ 'inserted-client' ],
			false
		);
		expect( state.blockEditor.blocks[ 'root-a' ] ).toEqual( [
			{ clientId: 'existing-heading', name: 'core/heading' },
			{ clientId: 'existing-image', name: 'core/image' },
		] );
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Cannot insert pattern "Hero" at the requested location. Gutenberg inserted it somewhere else, so Flavor Agent removed those blocks.',
			{
				type: 'snackbar',
				id: 'inserter-notice',
			}
		);
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'insert_failed',
				surface: 'pattern',
				reason: 'insert_blocks_wrong_target',
				patternKey: 'theme/hero',
			} )
		);
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'pattern_inserted_from_shelf',
			} )
		);
	} );

	test( 'removes cloned blocks when Gutenberg inserts them into a different root', async () => {
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					clientId: 'inserted-client',
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.blockEditor.blocks = {
			'root-a': [
				{ clientId: 'existing-heading', name: 'core/heading' },
			],
			'root-b': [ { clientId: 'existing-image', name: 'core/image' } ],
		};
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );
		mockInsertBlocks.mockImplementationOnce( ( blocks ) => {
			state.blockEditor.blocks = {
				...state.blockEditor.blocks,
				'root-b': [
					...state.blockEditor.blocks[ 'root-b' ],
					...blocks,
				],
			};
		} );

		renderComponent();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		await act( async () => {
			insertButton.click();
		} );

		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		expect( mockRemoveBlocks ).toHaveBeenCalledWith(
			[ 'inserted-client' ],
			false
		);
		expect( state.blockEditor.blocks[ 'root-a' ] ).toEqual( [
			{ clientId: 'existing-heading', name: 'core/heading' },
		] );
		expect( state.blockEditor.blocks[ 'root-b' ] ).toEqual( [
			{ clientId: 'existing-image', name: 'core/image' },
		] );
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'insert_failed',
				surface: 'pattern',
				reason: 'insert_blocks_wrong_target',
				patternKey: 'theme/hero',
			} )
		);
	} );

	test( 'blocks insert when the server-resolved apply context drifts', async () => {
		const {
			buildPatternInsertionTargetSignature,
		} = require( '../../utils/recommendation-request-signature' );
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.store.patternInsertionTargetSignature =
			buildPatternInsertionTargetSignature( {
				postType: 'page',
				inserterRootClientId: 'root-a',
				insertionIndex: 0,
				insertionContext: {
					rootBlock: 'core/group',
					ancestors: [ 'core/group' ],
					nearbySiblings: [],
				},
			} );
		state.store.patternResolvedContextSignature =
			'resolved-pattern-context-old';
		state.allowedPatterns = [ allowedPattern ];
		mockResolvePatternRecommendationSignature.mockResolvedValue( {
			resolvedContextSignature: 'resolved-pattern-context-new',
		} );
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();
		mockFetchPatternRecommendations.mockClear();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		await act( async () => {
			insertButton.click();
		} );

		expect(
			mockResolvePatternRecommendationSignature
		).toHaveBeenCalledWith(
			expect.objectContaining( {
				postType: 'page',
				visiblePatternNames: [ 'theme/hero' ],
				insertionContext: expect.objectContaining( {
					rootBlock: 'core/group',
				} ),
			} )
		);
		expect( mockInsertBlocks ).not.toHaveBeenCalled();
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Cannot insert pattern "Hero" because the server-resolved apply context has changed since these recommendations were ranked. Refreshing now — try again in a moment.',
			{
				type: 'snackbar',
				id: 'inserter-notice',
			}
		);
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'stale_blocked',
				surface: 'pattern',
				reason: 'resolved_context_changed',
				patternKey: 'theme/hero',
			} )
		);
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'pattern_inserted_from_shelf',
			} )
		);
	} );

	test( 'inserts when live visible patterns drift but the insertion target still matches', async () => {
		const {
			buildPatternInsertionTargetSignature,
			buildPatternRecommendationRequestSignature,
		} = require( '../../utils/recommendation-request-signature' );
		const inserterContainer = document.createElement( 'div' );
		const allowedPattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [
				{
					name: 'core/paragraph',
					attributes: {
						content: 'Hello world',
					},
				},
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.visiblePatternNames = [ 'theme/hero', 'theme/cards' ];
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.store.patternRequestSignature =
			buildPatternRecommendationRequestSignature( {
				postType: 'page',
				visiblePatternNames: [ 'theme/hero' ],
				insertionContext: {
					rootBlock: 'core/group',
					ancestors: [ 'core/group' ],
					nearbySiblings: [],
				},
			} );
		state.store.patternInsertionTargetSignature =
			buildPatternInsertionTargetSignature( {
				postType: 'page',
				inserterRootClientId: 'root-a',
				insertionIndex: 0,
				insertionContext: {
					rootBlock: 'core/group',
					ancestors: [ 'core/group' ],
					nearbySiblings: [],
				},
			} );
		state.allowedPatterns = [ allowedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();
		mockFetchPatternRecommendations.mockClear();

		const insertButton = Array.from(
			inserterContainer.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Insert' );

		await act( async () => {
			insertButton.click();
		} );

		expect( mockInsertBlocks ).toHaveBeenCalledTimes( 1 );
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
		expect( mockFetchPatternRecommendations ).not.toHaveBeenCalled();
	} );

	test( 'shows a safe unreadable synced-pattern notice when renderable recommendations remain', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternDiagnostics = {
			filteredCandidates: {
				unreadableSyncedPatterns: 2,
			},
		};
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
				categories: [ 'hero' ],
				ranking: {
					sourceSignals: [ 'qdrant_semantic', 'llm_ranker' ],
					rankingHint: {
						matchesNearbyBlock: true,
					},
				},
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				categories: [ 'featured' ],
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'2 synced patterns were skipped because current WordPress permissions do not allow read access.'
		);
		expect( document.body.textContent ).toContain( 'Semantic match' );
		expect( document.body.textContent ).toContain( 'Model ranked' );
		expect( document.body.textContent ).toContain( 'Category: hero' );
		expect( document.body.textContent ).toContain( 'Allowed here' );
		expect( document.body.textContent ).toContain( 'Nearby block fit' );
	} );

	test( 'inserts synced user patterns via a core/block reference', async () => {
		const inserterContainer = document.createElement( 'div' );
		const syncedPattern = {
			name: 'core/block-flavor-agent-sync',
			title: 'Synced Hero',
			type: 'user',
			syncStatus: 'fully',
			id: 77,
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: syncedPattern.name,
				score: 0.98,
				reason: 'Best reusable match.',
			},
		];
		state.allowedPatterns = [ syncedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		await act( async () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Insert' )
				.click();
		} );

		expect( mockCreateBlock ).toHaveBeenCalledWith( 'core/block', {
			ref: 77,
		} );
		expect( mockInsertBlocks ).toHaveBeenCalledWith(
			[
				{
					name: 'core/block',
					attributes: {
						ref: 77,
					},
					cloned: true,
				},
			],
			0,
			'root-a',
			true
		);
	} );

	test( 'filters out recommendations whose top-level blocks cannot be inserted at the current root', async () => {
		const inserterContainer = document.createElement( 'div' );
		const insertablePattern = {
			name: 'theme/hero',
			title: 'Hero',
			blocks: [ { name: 'core/paragraph', attributes: {} } ],
		};
		const templateOnlyPattern = {
			name: 'twentytwentyfive/template-page-photo-blog',
			title: 'Photo blog page',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
				{ name: 'core/group', attributes: {}, innerBlocks: [] },
				{ name: 'core/template-part', attributes: { slug: 'footer' } },
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: templateOnlyPattern.name,
				score: 0.97,
				reason: 'Recommended template page.',
			},
			{
				name: insertablePattern.name,
				score: 0.92,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [ templateOnlyPattern, insertablePattern ];
		mockCanInsertBlockType.mockImplementation(
			( blockName ) => blockName !== 'core/template-part'
		);
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain( 'Hero' );
		expect( document.body.textContent ).not.toContain( 'Photo blog page' );

		await act( async () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Insert' )
				.click();
		} );

		expect( mockInsertBlocks ).toHaveBeenCalledTimes( 1 );
		expect( mockInsertBlocks ).toHaveBeenCalledWith(
			[
				{
					...insertablePattern.blocks[ 0 ],
					cloned: true,
				},
			],
			0,
			'root-a',
			true
		);
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
	} );

	test( 'explains when allowed recommendations are rejected by insertability checks', () => {
		const inserterContainer = document.createElement( 'div' );
		const blockedPattern = {
			name: 'theme/template-with-parts',
			title: 'Template with parts',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: blockedPattern.name,
				score: 0.96,
				reason: 'Strong template match.',
			},
		];
		state.allowedPatterns = [ blockedPattern ];
		mockCanInsertBlockType.mockReturnValue( false );
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();

		expect( document.body.textContent ).toContain(
			'Flavor Agent found ranked patterns, but the matched pattern blocks are not allowed at this insertion point.'
		);
		expect( document.body.textContent ).not.toContain(
			'Gutenberg is not currently exposing those patterns'
		);
		expect(
			inserterContainer.querySelector(
				'.flavor-agent-pattern-shelf__item'
			)
		).toBeNull();
	} );

	test( 'shows an error notice and skips dispatch when the resolved blocks are not allowed at the insertion point', () => {
		// Defense in depth: if pre-filter is bypassed (e.g., a click races a
		// settings change), the click handler must surface a clear error
		// rather than silently dispatch a no-op.
		const inserterContainer = document.createElement( 'div' );
		const blockedPattern = {
			name: 'twentytwentyfive/template-page-photo-blog',
			title: 'Photo blog page',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
				{ name: 'core/group', attributes: {}, innerBlocks: [] },
				{ name: 'core/template-part', attributes: { slug: 'footer' } },
			],
		};

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: blockedPattern.name,
				score: 0.97,
				reason: 'Recommended template page.',
			},
		];
		state.allowedPatterns = [ blockedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		// Pre-filter pass-through (true), but a fresh active-registry select at
		// click time rejects the template-part blocks.
		mockUseRegistry.mockImplementation( () => ( {
			select: ( storeName ) => {
				if ( storeName === 'core/block-editor' ) {
					return {
						canInsertBlockType: ( blockName ) =>
							blockName !== 'core/template-part',
					};
				}

				return createSelectMap()[ storeName ] || {};
			},
		} ) );

		renderComponent();

		expect( document.body.textContent ).toContain( 'Photo blog page' );

		act( () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Insert' )
				.click();
		} );

		expect( mockInsertBlocks ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice ).toHaveBeenCalledTimes( 1 );
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Cannot insert pattern "Photo blog page" here. The following blocks are not allowed at this insertion point: core/template-part, core/template-part.',
			{
				type: 'snackbar',
				id: 'inserter-notice',
			}
		);
		expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'validation_blocked',
				surface: 'pattern',
				reason: 'disallowed_block_types',
				patternKey: 'twentytwentyfive/template-page-photo-blog',
			} )
		);
		expect( mockRecordRecommendationOutcome ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				event: 'pattern_inserted_from_shelf',
			} )
		);
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
	} );

	test( 'validates pattern insertion against the active registry instead of the global data store', () => {
		const inserterContainer = document.createElement( 'div' );
		const blockedPattern = {
			name: 'theme/template-with-parts',
			title: 'Template with parts',
			blocks: [
				{ name: 'core/template-part', attributes: { slug: 'header' } },
			],
		};
		const globalSelect = jest.fn().mockReturnValue( {
			canInsertBlockType: () => true,
		} );
		const previousWp = window.wp;

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: blockedPattern.name,
				score: 0.97,
				reason: 'Recommended template page.',
			},
		];
		state.allowedPatterns = [ blockedPattern ];
		mockFindInserterContainer.mockReturnValue( inserterContainer );
		mockUseRegistry.mockImplementation( () => ( {
			select: ( storeName ) => {
				if ( storeName === 'core/block-editor' ) {
					return {
						canInsertBlockType: ( blockName ) =>
							blockName !== 'core/template-part',
					};
				}

				return createSelectMap()[ storeName ] || {};
			},
		} ) );
		window.wp = { data: { select: globalSelect } };

		try {
			renderComponent();

			act( () => {
				Array.from( inserterContainer.querySelectorAll( 'button' ) )
					.find( ( button ) => button.textContent === 'Insert' )
					.click();
			} );

			expect( globalSelect ).not.toHaveBeenCalled();
			expect( mockInsertBlocks ).not.toHaveBeenCalled();
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Cannot insert pattern "Template with parts" here. The following blocks are not allowed at this insertion point: core/template-part.',
				{
					type: 'snackbar',
					id: 'inserter-notice',
				}
			);
		} finally {
			window.wp = previousWp;
		}
	} );

	test( 'refetches when visible pattern names hydrate after the initial empty load', () => {
		state.visiblePatternNames = [];

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );

		state.visiblePatternNames = [ 'theme/hero' ];

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 2 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'does not create the inserter notice slot while the affordance is hidden', () => {
		const originalCreateElement = document.createElement.bind( document );
		const createdDivs = [];
		const createElementSpy = jest
			.spyOn( document, 'createElement' )
			.mockImplementation( ( tagName, ...args ) => {
				const element = originalCreateElement( tagName, ...args );

				if ( tagName === 'div' ) {
					createdDivs.push( element );
				}

				return element;
			} );

		state.isInserterOpen = false;

		renderComponent();

		expect(
			createdDivs.filter(
				( element ) =>
					element.className === 'flavor-agent-pattern-inserter-slot'
			)
		).toHaveLength( 0 );

		createElementSpy.mockRestore();
	} );

	test( 'reattaches the inserter shelf when Gutenberg replaces the container', () => {
		const firstContainer = document.createElement( 'div' );
		const secondContainer = document.createElement( 'div' );
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		const observerInstances = [];
		const observerCallbacks = [];
		let currentContainer = firstContainer;

		firstContainer.className = 'block-editor-inserter__panel-content';
		secondContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( firstContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];
		mockFindInserterContainer.mockImplementation( () => currentContainer );
		mockFindInserterSearchInput.mockReturnValue( searchInput );
		window.MutationObserver = class MockMutationObserver {
			constructor( callback ) {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerInstances.push( this );
				observerCallbacks.push( callback );
			}
		};

		renderComponent();

		expect(
			firstContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
		expect( observerInstances ).toHaveLength( 2 );

		firstContainer.remove();
		document.body.appendChild( secondContainer );
		currentContainer = secondContainer;

		act( () => {
			observerCallbacks.forEach( ( callback ) => callback( [] ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 50 );
		} );

		expect(
			secondContainer.querySelector(
				'.flavor-agent-pattern-inserter-slot'
			)
		).not.toBeNull();
		expect( observerInstances[ 0 ].disconnect ).not.toHaveBeenCalled();
		expect( observerInstances[ 1 ].disconnect ).not.toHaveBeenCalled();

		act( () => {
			getRoot().unmount();
		} );

		observerInstances.forEach( ( observer ) => {
			expect( observer.disconnect ).toHaveBeenCalled();
		} );
		secondContainer.remove();
	} );

	test( 'coalesces inserter notice resyncs during mutation bursts', () => {
		const inserterContainer = document.createElement( 'div' );
		const observerCallbacks = [];

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];
		mockFindInserterContainer.mockReturnValue( inserterContainer );
		mockFindInserterSearchInput.mockReturnValue( null );
		window.MutationObserver = class MockMutationObserver {
			constructor( callback ) {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerCallbacks.push( callback );
			}
		};

		renderComponent();
		mockFindInserterContainer.mockClear();

		act( () => {
			observerCallbacks[ 0 ]( [] );
			observerCallbacks[ 0 ]( [] );
			observerCallbacks[ 0 ]( [] );
		} );

		expect( mockFindInserterContainer ).not.toHaveBeenCalled();

		act( () => {
			jest.advanceTimersByTime( 50 );
		} );

		expect( mockFindInserterContainer ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'keeps search-triggered fetches debounced and includes the selected block context', () => {
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		let inputListener = null;

		state.blockEditor.selectedBlockClientId = 'block-1';
		state.blockEditor.selectedBlockName = 'core/heading';
		mockFindInserterSearchInput.mockReturnValue( searchInput );
		searchInput.addEventListener.mockImplementation(
			( event, listener ) => {
				if ( event === 'input' ) {
					inputListener = listener;
				}
			}
		);

		renderComponent();

		expect( inputListener ).toEqual( expect.any( Function ) );
		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );

		act( () => {
			inputListener( {
				target: {
					value: 'hero',
				},
			} );
			jest.advanceTimersByTime( 399 );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 1 );

		act( () => {
			jest.advanceTimersByTime( 1 );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 2 );
		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
			prompt: 'hero',
			blockContext: {
				blockName: 'core/heading',
			},
		} );
	} );

	test( 'reattaches the inserter search listener when Gutenberg replaces the input', () => {
		const firstSearchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		const secondSearchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		const observerCallbacks = [];
		let currentSearchInput = firstSearchInput;
		let secondInputListener = null;

		state.blockEditor.selectedBlockClientId = 'block-1';
		state.blockEditor.selectedBlockName = 'core/heading';
		mockFindInserterSearchInput.mockImplementation(
			() => currentSearchInput
		);
		secondSearchInput.addEventListener.mockImplementation(
			( event, listener ) => {
				if ( event === 'input' ) {
					secondInputListener = listener;
				}
			}
		);
		window.MutationObserver = class MockMutationObserver {
			constructor( callback ) {
				this.observe = jest.fn();
				this.disconnect = jest.fn();
				observerCallbacks.push( callback );
			}
		};

		renderComponent();

		expect( firstSearchInput.addEventListener ).toHaveBeenCalledWith(
			'input',
			expect.any( Function )
		);

		currentSearchInput = secondSearchInput;
		act( () => {
			observerCallbacks.forEach( ( callback ) => callback( [] ) );
		} );

		expect( firstSearchInput.removeEventListener ).toHaveBeenCalledWith(
			'input',
			firstSearchInput.addEventListener.mock.calls[ 0 ][ 1 ]
		);
		expect( secondSearchInput.addEventListener ).toHaveBeenCalledWith(
			'input',
			expect.any( Function )
		);

		act( () => {
			secondInputListener( {
				target: {
					value: 'gallery',
				},
			} );
			jest.advanceTimersByTime( 400 );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenLastCalledWith( {
			postType: 'page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
			prompt: 'gallery',
			blockContext: {
				blockName: 'core/heading',
			},
		} );
	} );

	test( 'renders template recommendations with normalized template type when editing a site template', () => {
		state.editSite = {
			postType: 'wp_template',
			postId: 'custom//front-page',
		};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'page',
			templateType: 'front-page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'uses the Site Editor template post type when the editor post type is empty', () => {
		state.postType = '';
		state.editSite = {
			postType: 'wp_template',
			postId: 'custom//front-page',
		};

		renderComponent();

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'wp_template',
			templateType: 'front-page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'uses the Site Editor template post type for retry requests', () => {
		const inserterContainer = document.createElement( 'div' );

		inserterContainer.className = 'block-editor-inserter__panel-content';
		document.body.appendChild( inserterContainer );
		state.postType = '';
		state.editSite = {
			postType: 'wp_template',
			postId: 'custom//front-page',
		};
		state.store.patternStatus = 'error';
		state.store.patternError = 'Pattern recommendation request failed.';
		mockFindInserterContainer.mockReturnValue( inserterContainer );

		renderComponent();
		mockFetchPatternRecommendations.mockClear();

		act( () => {
			Array.from( inserterContainer.querySelectorAll( 'button' ) )
				.find( ( button ) => button.textContent === 'Retry' )
				.click();
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'wp_template',
			templateType: 'front-page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
		} );
	} );

	test( 'uses the Site Editor template post type for inserter search requests', () => {
		const searchInput = {
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		};
		let inputListener = null;

		state.postType = '';
		state.editSite = {
			postType: 'wp_template',
			postId: 'custom//front-page',
		};
		state.blockEditor.selectedBlockClientId = 'block-1';
		state.blockEditor.selectedBlockName = 'core/heading';
		mockFindInserterSearchInput.mockReturnValue( searchInput );
		searchInput.addEventListener.mockImplementation(
			( event, listener ) => {
				if ( event === 'input' ) {
					inputListener = listener;
				}
			}
		);

		renderComponent();
		mockFetchPatternRecommendations.mockClear();

		act( () => {
			inputListener( {
				target: {
					value: 'hero',
				},
			} );
			jest.advanceTimersByTime( 400 );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledWith( {
			postType: 'wp_template',
			templateType: 'front-page',
			visiblePatternNames: [ 'theme/hero' ],
			insertionContext: {
				rootBlock: 'core/group',
				ancestors: [ 'core/group' ],
				nearbySiblings: [],
			},
			prompt: 'hero',
			blockContext: {
				blockName: 'core/heading',
			},
		} );
	} );

	test( 'mounts cleanly in a second root after the first editor session unmounts', () => {
		let secondContainer = null;
		let secondRoot = null;

		state.store.patternStatus = 'ready';
		state.store.patternRecommendations = [
			{
				name: 'theme/hero',
				score: 0.94,
				reason: 'Recommended hero pattern.',
			},
		];
		state.allowedPatterns = [
			{
				name: 'theme/hero',
				title: 'Hero',
				blocks: [ { name: 'core/paragraph', attributes: {} } ],
			},
		];

		renderComponent();

		act( () => {
			getRoot().unmount();
		} );

		secondContainer = document.createElement( 'div' );
		document.body.appendChild( secondContainer );
		secondRoot = createRoot( secondContainer );

		act( () => {
			secondRoot.render( <PatternRecommender /> );
		} );

		expect( mockFetchPatternRecommendations ).toHaveBeenCalledTimes( 2 );

		act( () => {
			secondRoot.unmount();
		} );
		secondContainer.remove();
	} );
} );
