const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockGetBlockPatterns = jest.fn();
const mockFetchTemplatePartRecommendations = jest.fn();
const mockRevalidateTemplatePartReviewFreshness = jest.fn();
const mockGetTemplatePartActivityUndoState = jest.fn(
	( activity ) => activity?.undo || {}
);
const mockGetTemplatePartAreaLookup = jest.fn( () => ( {} ) );
const mockOpenInserterForPattern = jest.fn();
const mockSelectBlockByPath = jest.fn();
const mockUndoActivity = jest.fn();
const DOCUMENT_POSITION_FOLLOWING = 4;

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '@wordpress/editor', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		PluginDocumentSettingPanel: ( { children, title } ) =>
			createElement( 'section', { 'data-panel-title': title }, children ),
	};
} );

jest.mock( '../../patterns/compat', () => ( {
	getBlockPatterns: ( ...args ) => mockGetBlockPatterns( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../utils/template-actions', () => ( {
	getTemplatePartActivityUndoState: ( ...args ) =>
		mockGetTemplatePartActivityUndoState( ...args ),
	openInserterForPattern: ( ...args ) =>
		mockOpenInserterForPattern( ...args ),
	selectBlockByPath: ( ...args ) => mockSelectBlockByPath( ...args ),
} ) );

jest.mock( '../../utils/visible-patterns', () => ( {
	getVisiblePatternNames: jest.fn( () => [] ),
} ) );

jest.mock( '../../utils/template-part-areas', () => ( {
	getTemplatePartAreaLookup: ( ...args ) =>
		mockGetTemplatePartAreaLookup( ...args ),
} ) );

jest.mock( '../../utils/template-operation-sequence', () => ( {
	TEMPLATE_OPERATION_INSERT_PATTERN: 'insert_pattern',
	TEMPLATE_OPERATION_REMOVE_BLOCK: 'remove_block',
	TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN: 'replace_block_with_pattern',
	TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH: 'after_block_path',
	TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH: 'before_block_path',
	validateTemplatePartOperationSequence: jest.fn( ( operations ) => ( {
		ok: true,
		operations,
	} ) ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import TemplatePartRecommender from '../TemplatePartRecommender';
import {
	buildEditorTemplatePartStructureSnapshot,
	buildTemplatePartRecommendationContextSignature,
} from '../template-part-recommender-helpers';

const { getContainer, getRoot } = setupReactTest();

let currentState = null;
function getState() {
	return currentState;
}

function buildTemplatePartContextSignature( state = getState() ) {
	return buildTemplatePartRecommendationContextSignature( {
		visiblePatternNames: [],
		editorStructure: buildEditorTemplatePartStructureSnapshot(
			state.blockEditor.blocks,
			mockGetTemplatePartAreaLookup()
		),
	} );
}

function createState( overrides = {} ) {
	return {
		editSite: {
			postId: 'theme//header',
			postType: 'wp_template_part',
			...overrides.editSite,
		},
		blockEditor: {
			blocks: [],
			...overrides.blockEditor,
		},
		store: {
			activityLog: [],
			lastUndoneActivityId: null,
			templatePartApplyError: null,
			templatePartApplyStatus: 'idle',
			templatePartError: null,
			templatePartExplanation: '',
			templatePartRequestPrompt: '',
			templatePartLastAppliedOperations: [],
			templatePartLastAppliedSuggestionKey: null,
			templatePartRecommendations: [],
			templatePartContextSignature: null,
			templatePartReviewContextSignature: null,
			templatePartReviewStaleReason: null,
			templatePartResultRef: null,
			templatePartResultToken: 1,
			templatePartSelectedSuggestionKey: null,
			templatePartStatus: 'idle',
			undoError: null,
			undoStatus: 'idle',
			...overrides.store,
		},
	};
}

function selectStore( storeName ) {
	if ( storeName === 'core/block-editor' ) {
		return {
			getBlocks: jest.fn( () => getState().blockEditor.blocks ),
		};
	}

	if ( storeName === 'core/edit-site' ) {
		return {
			getEditedPostId: jest.fn( () => getState().editSite.postId ),
			getEditedPostType: jest.fn( () => getState().editSite.postType ),
		};
	}

	if ( storeName === 'flavor-agent' ) {
		return {
			getActivityLog: jest.fn( () => getState().store.activityLog ),
			getLastUndoneActivityId: jest.fn(
				() => getState().store.lastUndoneActivityId
			),
			getSurfaceStatusNotice: jest.fn( ( surface, options = {} ) => {
				void surface;

				if ( options.requestError ) {
					return {
						source: 'request',
						tone: 'error',
						message: options.requestError,
					};
				}

				if ( options.undoError ) {
					return {
						source: 'undo',
						tone: 'error',
						message: options.undoError,
						isDismissible: true,
					};
				}

				if ( options.undoSuccessMessage ) {
					return {
						source: 'undo',
						tone: 'success',
						message: options.undoSuccessMessage,
					};
				}

				if ( options.applyError ) {
					return {
						source: 'apply',
						tone: 'error',
						message: options.applyError,
					};
				}

				if ( options.applySuccessMessage ) {
					return {
						source: 'apply',
						tone: 'success',
						message: options.applySuccessMessage,
						actionType: 'undo',
						actionLabel: 'Undo',
					};
				}

				if ( options.emptyMessage ) {
					return {
						source: 'empty',
						tone: 'info',
						message:
							options.requestStatus === 'loading'
								? ''
								: options.emptyMessage,
					};
				}

				return null;
			} ),
			getTemplatePartApplyError: jest.fn(
				() => getState().store.templatePartApplyError
			),
			getTemplatePartInteractionState: jest.fn( () => 'idle' ),
			getTemplatePartApplyStatus: jest.fn(
				() => getState().store.templatePartApplyStatus
			),
			getTemplatePartError: jest.fn(
				() => getState().store.templatePartError
			),
			getTemplatePartExplanation: jest.fn(
				() => getState().store.templatePartExplanation
			),
			getTemplatePartRequestPrompt: jest.fn(
				() => getState().store.templatePartRequestPrompt
			),
			getTemplatePartLastAppliedOperations: jest.fn(
				() => getState().store.templatePartLastAppliedOperations
			),
			getTemplatePartLastAppliedSuggestionKey: jest.fn(
				() => getState().store.templatePartLastAppliedSuggestionKey
			),
			getTemplatePartRecommendations: jest.fn(
				() => getState().store.templatePartRecommendations
			),
			getTemplatePartContextSignature: jest.fn(
				() => getState().store.templatePartContextSignature
			),
			getTemplatePartReviewContextSignature: jest.fn(
				() => getState().store.templatePartReviewContextSignature
			),
			getTemplatePartResultRef: jest.fn(
				() => getState().store.templatePartResultRef
			),
			getTemplatePartResultToken: jest.fn(
				() => getState().store.templatePartResultToken
			),
			getTemplatePartStatus: jest.fn(
				() => getState().store.templatePartStatus
			),
			getTemplatePartSelectedSuggestionKey: jest.fn(
				() => getState().store.templatePartSelectedSuggestionKey
			),
			getTemplatePartReviewStaleReason: jest.fn(
				() => getState().store.templatePartReviewStaleReason
			),
			getUndoError: jest.fn( () => getState().store.undoError ),
			getUndoStatus: jest.fn( () => getState().store.undoStatus ),
			isTemplatePartLoading: jest.fn(
				() => getState().store.templatePartStatus === 'loading'
			),
		};
	}

	return {};
}

function hasText( value ) {
	return getContainer().textContent.includes( value );
}

function getTextarea() {
	return getContainer().querySelector( 'textarea' );
}

function getButton( label ) {
	return Array.from( getContainer().querySelectorAll( 'button' ) ).find(
		( element ) => element.textContent === label
	);
}

function getReviewSections() {
	return Array.from(
		getContainer().querySelectorAll( '.flavor-agent-review-section' )
	);
}

async function renderPanel() {
	await act( async () => {
		getRoot().render( <TemplatePartRecommender /> );
	} );
}

beforeEach( async () => {
	jest.clearAllMocks();
	currentState = createState();
	window.flavorAgentData = {
		canRecommendTemplateParts: true,
	};
	mockGetBlockPatterns.mockReturnValue( [] );
	mockOpenInserterForPattern.mockReset();
	mockSelectBlockByPath.mockReset();
	mockUseDispatch.mockImplementation( () => ( {
		applyTemplatePartSuggestion: jest.fn(),
		clearTemplatePartRecommendations: jest.fn(),
		clearUndoError: jest.fn(),
		fetchTemplatePartRecommendations: mockFetchTemplatePartRecommendations,
		revalidateTemplatePartReviewFreshness:
			mockRevalidateTemplatePartReviewFreshness,
		setTemplatePartSelectedSuggestion: jest.fn(),
		undoActivity: mockUndoActivity,
	} ) );
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( selectStore )
	);
	await renderPanel();
} );

afterEach( async () => {
	delete window.flavorAgentData;
	currentState = null;
} );

describe( 'TemplatePartRecommender', () => {
	test( 'renders inline entity links in explanation and card descriptions', async () => {
		currentState = createState( {
			store: {
				templatePartExplanation:
					'Focus Navigation block first, then browse Utility Links.',
				templatePartRecommendations: [
					{
						label: 'Tighten the header utility area',
						description:
							'Focus Navigation block first, then browse Utility Links.',
						blockHints: [
							{
								path: [ 0 ],
								label: 'Navigation block',
								reason: 'Keep the utility actions near the main nav.',
							},
						],
						patternSuggestions: [ 'theme/utility-links' ],
					},
				],
				templatePartResultRef: 'theme//header',
				templatePartStatus: 'ready',
			},
		} );
		mockGetBlockPatterns.mockReturnValue( [
			{
				name: 'theme/utility-links',
				title: 'Utility Links',
			},
		] );

		await renderPanel();

		const navigationButtons = Array.from(
			getContainer().querySelectorAll( 'button' )
		).filter( ( element ) => element.textContent === 'Navigation block' );
		const patternButtons = Array.from(
			getContainer().querySelectorAll( 'button' )
		).filter( ( element ) => element.textContent === 'Utility Links' );

		expect( navigationButtons.length ).toBeGreaterThan( 1 );
		expect( patternButtons.length ).toBeGreaterThan( 1 );

		act( () => {
			navigationButtons[ 0 ].click();
			patternButtons[ 0 ].click();
		} );

		expect( mockSelectBlockByPath ).toHaveBeenCalledWith( [ 0 ] );
		expect( mockOpenInserterForPattern ).toHaveBeenCalledWith(
			'Utility Links'
		);
	} );

	test( 'renders one shared review panel below the lanes instead of nesting it in the selected card', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [
					{
						label: 'Replace navigation block',
						description:
							'Swap the existing navigation block for a utility-links pattern.',
						operations: [
							{
								type: 'replace_block_with_pattern',
								patternName: 'theme/utility-links',
								expectedBlockName: 'core/navigation',
								targetPath: [ 0 ],
							},
						],
					},
				],
				templatePartResultRef: 'theme//header',
				templatePartSelectedSuggestionKey: 'Replace navigation block-0',
				templatePartStatus: 'ready',
			},
		} );

		await renderPanel();

		const selectedCard = getContainer().querySelector(
			'.flavor-agent-card--template.is-review-selected'
		);
		const reviewSections = getReviewSections();
		const reviewSection = reviewSections[ 0 ] || null;

		expect( selectedCard ).not.toBeNull();
		expect( reviewSections.length ).toBe( 1 );
		expect( selectedCard?.textContent ).toContain( 'Review open' );
		expect( selectedCard?.textContent ).toContain( 'Reviewing' );
		expect( selectedCard?.contains( reviewSection ) ).toBe( false );
		expect( selectedCard?.compareDocumentPosition( reviewSection ) ).toBe(
			DOCUMENT_POSITION_FOLLOWING
		);
	} );

	test( 'keeps stale template-part results visible but disables confirm apply when the stored context signature mismatches', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [
					{
						label: 'Replace navigation block',
						description:
							'Swap the existing navigation block for a utility-links pattern.',
						operations: [
							{
								type: 'replace_block_with_pattern',
								patternName: 'theme/utility-links',
								expectedBlockName: 'core/navigation',
								targetPath: [ 0 ],
							},
						],
					},
				],
				templatePartContextSignature: 'stale-signature',
				templatePartResultRef: 'theme//header',
				templatePartSelectedSuggestionKey: 'Replace navigation block-0',
				templatePartStatus: 'ready',
			},
		} );

		await renderPanel();

		expect( hasText( 'Replace navigation block' ) ).toBe( true );
		expect( getButton( 'Confirm Apply' )?.disabled ).toBe( true );
	} );

	test( 'shows a stale scope badge when the stored template-part result context mismatches', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [
					{
						label: 'Replace navigation block',
						operations: [
							{
								type: 'replace_block_with_pattern',
								patternName: 'theme/utility-links',
								expectedBlockName: 'core/navigation',
								targetPath: [ 0 ],
							},
						],
					},
				],
				templatePartContextSignature: 'stale-signature',
				templatePartResultRef: 'theme//header',
				templatePartSelectedSuggestionKey: 'Replace navigation block-0',
				templatePartStatus: 'ready',
			},
		} );

		await renderPanel();

		expect( hasText( 'Header Template Part' ) ).toBe( true );
		expect( hasText( 'Slug: header' ) ).toBe( true );
		expect( hasText( 'Stale' ) ).toBe( true );
		expect(
			hasText(
				'This template-part result no longer matches the current live structure or prompt. Refresh before reviewing or applying anything from the previous result.'
			)
		).toBe( true );
		expect(
			getContainer()
				.querySelector( '.flavor-agent-scope-bar' )
				?.getAttribute( 'role' )
		).toBe( 'status' );
	} );

	test( 'shows server-review stale copy when the template-part review signature drifts', async () => {
		currentState = createState( {
			store: {
				templatePartReviewStaleReason: 'server-review',
			},
		} );

		await renderPanel();

		expect( hasText( 'Replace navigation block' ) ).toBe( true );
		expect( hasText( 'Stale' ) ).toBe( true );
		expect(
			hasText(
				'This template-part result no longer matches the current server review context. Refresh before reviewing or applying anything from the previous result.'
			)
		).toBe( true );
		expect( getButton( 'Confirm Apply' )?.disabled ).toBe( true );
	} );

	test( 'does not show the current scope badge when the latest template-part request failed', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [],
				templatePartExplanation: '',
				templatePartError: 'Template-part request failed.',
				templatePartStatus: 'error',
				templatePartResultRef: 'theme//header',
			},
		} );

		await renderPanel();

		expect( hasText( 'Template-part request failed.' ) ).toBe( true );
		expect( hasText( 'Current' ) ).toBe( false );
	} );

	test( 'treats an empty successful template-part response as a current result', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [],
				templatePartExplanation: '',
				templatePartResultRef: 'theme//header',
				templatePartContextSignature: buildTemplatePartContextSignature(
					createState()
				),
				templatePartStatus: 'ready',
			},
		} );

		await renderPanel();

		expect( hasText( 'Current' ) ).toBe( true );
		expect(
			hasText(
				'No template-part suggestions were returned for this request.'
			)
		).toBe( true );
	} );

	test( 'keeps advisory template-part suggestions expanded when they are returned', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [
					{
						label: 'Introduce utility links',
						description:
							'Add a compact utility-links pattern near the navigation block.',
						patternSuggestions: [ 'theme/utility-links' ],
						operations: [],
					},
				],
				templatePartExplanation: 'One advisory idea is available.',
				templatePartResultRef: 'theme//header',
				templatePartStatus: 'ready',
			},
		} );
		mockGetBlockPatterns.mockReturnValue( [
			{
				name: 'theme/utility-links',
				title: 'Utility Links',
			},
		] );

		await renderPanel();

		expect( hasText( 'Manual ideas' ) ).toBe( true );
		expect( hasText( 'Advisory only' ) ).toBe( true );
		expect( hasText( 'Introduce utility links' ) ).toBe( true );
		expect( hasText( 'Browse pattern' ) ).toBe( true );
		expect(
			getContainer()
				.querySelector( '.flavor-agent-recommendation-hero' )
				?.compareDocumentPosition(
					getContainer().querySelector( '.flavor-agent-explanation' )
				)
		).toBe( DOCUMENT_POSITION_FOLLOWING );
	} );

	test( 'submits live pattern override metadata with template-part requests', async () => {
		currentState = createState( {
			blockEditor: {
				blocks: [
					{
						name: 'core/group',
						attributes: {},
						innerBlocks: [
							{
								name: 'core/navigation',
								attributes: {
									metadata: {
										bindings: {
											overlayMenu: {
												source: 'core/pattern-overrides',
											},
										},
									},
								},
								innerBlocks: [],
							},
						],
					},
				],
			},
		} );

		await renderPanel();

		await act( async () => {
			Array.from( getContainer().querySelectorAll( 'button' ) )
				.find(
					( element ) => element.textContent === 'Get Suggestions'
				)
				.click();
		} );

		expect( mockFetchTemplatePartRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				templatePartRef: 'theme//header',
				editorStructure: expect.objectContaining( {
					blockTree: [
						{
							path: [ 0 ],
							name: 'core/group',
							label: 'Group',
							attributes: {},
							childCount: 1,
							children: [
								{
									path: [ 0, 0 ],
									name: 'core/navigation',
									label: 'Navigation',
									attributes: {},
									childCount: 0,
									children: [],
								},
							],
						},
					],
					allBlockPaths: [
						{
							path: [ 0 ],
							name: 'core/group',
							label: 'Group',
							attributes: {},
							childCount: 1,
						},
						{
							path: [ 0, 0 ],
							name: 'core/navigation',
							label: 'Navigation',
							attributes: {},
							childCount: 0,
						},
					],
					topLevelBlocks: [ 'core/group' ],
					blockCounts: {
						'core/group': 1,
						'core/navigation': 1,
					},
					structureStats: {
						blockCount: 2,
						maxDepth: 2,
						hasNavigation: true,
						containsLogo: false,
						containsSiteTitle: false,
						containsSearch: false,
						containsSocialLinks: false,
						containsQuery: false,
						containsColumns: false,
						containsButtons: false,
						containsSpacer: false,
						containsSeparator: false,
						firstTopLevelBlock: 'core/group',
						lastTopLevelBlock: 'core/group',
						hasSingleWrapperGroup: true,
						isNearlyEmpty: false,
					},
					currentPatternOverrides: {
						hasOverrides: true,
						blockCount: 1,
						blockNames: [ 'core/navigation' ],
						blocks: [
							{
								path: [ 0, 0 ],
								name: 'core/navigation',
								label: 'Navigation',
								overrideAttributes: [ 'overlayMenu' ],
								usesDefaultBinding: false,
							},
						],
					},
					operationTargets: [
						{
							path: [ 0 ],
							name: 'core/group',
							label: 'Group',
							allowedOperations: [
								'replace_block_with_pattern',
								'remove_block',
							],
							allowedInsertions: [
								'before_block_path',
								'after_block_path',
							],
						},
						{
							path: [ 0, 0 ],
							name: 'core/navigation',
							label: 'Navigation',
							allowedOperations: [
								'replace_block_with_pattern',
								'remove_block',
							],
							allowedInsertions: [
								'before_block_path',
								'after_block_path',
							],
						},
					],
					insertionAnchors: [
						{
							placement: 'start',
							label: 'Start of template part',
						},
						{
							placement: 'end',
							label: 'End of template part',
						},
						{
							placement: 'before_block_path',
							targetPath: [ 0 ],
							blockName: 'core/group',
							label: 'Before Group',
						},
						{
							placement: 'after_block_path',
							targetPath: [ 0 ],
							blockName: 'core/group',
							label: 'After Group',
						},
						{
							placement: 'before_block_path',
							targetPath: [ 0, 0 ],
							blockName: 'core/navigation',
							label: 'Before Navigation',
						},
						{
							placement: 'after_block_path',
							targetPath: [ 0, 0 ],
							blockName: 'core/navigation',
							label: 'After Navigation',
						},
					],
					structuralConstraints: {
						contentOnlyPaths: [],
						lockedPaths: [],
						hasContentOnly: false,
						hasLockedBlocks: false,
					},
				} ),
			} )
		);
	} );

	test( 'handles template-part requests without live override metadata and keeps empty structure explicit', async () => {
		currentState = createState( {
			blockEditor: {
				blocks: [],
			},
		} );

		await renderPanel();

		await act( async () => {
			Array.from( getContainer().querySelectorAll( 'button' ) )
				.find(
					( element ) => element.textContent === 'Get Suggestions'
				)
				.click();
		} );

		expect( mockFetchTemplatePartRecommendations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				templatePartRef: 'theme//header',
				editorStructure: {
					blockTree: [],
					allBlockPaths: [],
					topLevelBlocks: [],
					blockCounts: {},
					structureStats: {
						blockCount: 0,
						maxDepth: 0,
						hasNavigation: false,
						containsLogo: false,
						containsSiteTitle: false,
						containsSearch: false,
						containsSocialLinks: false,
						containsQuery: false,
						containsColumns: false,
						containsButtons: false,
						containsSpacer: false,
						containsSeparator: false,
						firstTopLevelBlock: '',
						lastTopLevelBlock: '',
						hasSingleWrapperGroup: false,
						isNearlyEmpty: true,
					},
					currentPatternOverrides: {
						hasOverrides: false,
						blockCount: 0,
						blockNames: [],
						blocks: [],
					},
					operationTargets: [],
					insertionAnchors: [
						{
							placement: 'start',
							label: 'Start of template part',
						},
						{
							placement: 'end',
							label: 'End of template part',
						},
					],
					structuralConstraints: {
						contentOnlyPaths: [],
						lockedPaths: [],
						hasContentOnly: false,
						hasLockedBlocks: false,
					},
				},
			} )
		);
	} );

	test( 'renders non-interactive preview tokens in the review overlay', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [
					{
						label: 'Replace navigation block',
						description:
							'Swap the existing navigation block for a utility-links pattern.',
						operations: [
							{
								type: 'replace_block_with_pattern',
								patternName: 'theme/utility-links',
								expectedBlockName: 'core/navigation',
								targetPath: [ 0 ],
							},
						],
					},
				],
				templatePartResultRef: 'theme//header',
				templatePartSelectedSuggestionKey: 'Replace navigation block-0',
				templatePartStatus: 'ready',
			},
		} );
		mockGetBlockPatterns.mockReturnValue( [
			{
				name: 'theme/utility-links',
				title: 'Utility Links',
			},
		] );

		await renderPanel();

		expect(
			getContainer().querySelector(
				'.flavor-agent-template-preview .flavor-agent-preview-token--pattern'
			)
		).not.toBeNull();
		expect(
			getContainer().querySelector(
				'.flavor-agent-template-preview .flavor-agent-action-link'
			)
		).toBeNull();
	} );

	test( 'formats template-part preview paths with one-based labels', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [
					{
						label: 'Add utility links',
						description:
							'Insert a utility-links pattern after the navigation group.',
						operations: [
							{
								type: 'insert_pattern',
								patternName: 'theme/utility-links',
								placement: 'after_block_path',
								targetPath: [ 0, 1 ],
							},
						],
					},
				],
				templatePartResultRef: 'theme//header',
				templatePartSelectedSuggestionKey: 'Add utility links-0',
				templatePartStatus: 'ready',
			},
		} );
		mockGetBlockPatterns.mockReturnValue( [
			{
				name: 'theme/utility-links',
				title: 'Utility Links',
			},
		] );

		await renderPanel();

		expect( hasText( 'After target block (Path 1 > 2)' ) ).toBe( true );
		expect( getContainer().textContent.replace( /\s+/g, ' ' ) ).toContain(
			'relative to Path 1 > 2.'
		);
		expect( hasText( 'Path 0 > 1' ) ).toBe( false );
	} );

	test( 'formats template-part start placements without raw tokens', async () => {
		currentState = createState( {
			store: {
				templatePartRecommendations: [
					{
						label: 'Add hero pattern',
						description:
							'Insert a hero pattern at the start of the template part.',
						operations: [
							{
								type: 'insert_pattern',
								patternName: 'theme/hero',
								placement: 'start',
							},
						],
					},
				],
				templatePartResultRef: 'theme//header',
				templatePartSelectedSuggestionKey: 'Add hero pattern-0',
				templatePartStatus: 'ready',
			},
		} );
		mockGetBlockPatterns.mockReturnValue( [
			{
				name: 'theme/hero',
				title: 'Hero',
			},
		] );

		await renderPanel();

		expect( hasText( 'Start of this template part' ) ).toBe( true );
		expect(
			Array.from( getContainer().querySelectorAll( 'code' ) ).some(
				( element ) => element.textContent === 'start'
			)
		).toBe( false );
	} );

	test( 'shows an undo action on apply success notices and dispatches undo for the latest template-part activity', async () => {
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_part_suggestion',
						surface: 'template-part',
						suggestion: 'Add utility links',
						target: {
							templatePartRef: 'theme//header',
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
					},
				],
				templatePartApplyError: null,
				templatePartApplyStatus: 'success',
				templatePartLastAppliedSuggestionKey: 'utility-links-0',
				templatePartLastAppliedOperations: [
					{
						type: 'replace_block_with_pattern',
						patternName: 'theme/utility-links',
					},
				],
				templatePartSelectedSuggestionKey: null,
			},
		} );

		await renderPanel();

		expect( hasText( 'Applied 1 template-part operation.' ) ).toBe( true );

		const undoButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( element ) => element.textContent === 'Undo' );

		expect( undoButton ).toBeDefined();

		await act( async () => {
			undoButton.click();
		} );

		expect( mockUndoActivity ).toHaveBeenCalledWith( 'activity-1' );
	} );

	test( 'does not show an empty-state notice while reloading the same template part', async () => {
		currentState = createState( {
			store: {
				templatePartExplanation: '',
				templatePartRecommendations: [],
				templatePartResultRef: 'theme//header',
				templatePartStatus: 'loading',
			},
		} );

		await renderPanel();

		expect( hasText( 'Analyzing template-part structure…' ) ).toBe( true );
		expect(
			hasText(
				'No template-part suggestions were returned for this request.'
			)
		).toBe( false );
	} );

	test( 'keeps undo history visible when template-part recommendations are unavailable', async () => {
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_part_suggestion',
						surface: 'template-part',
						suggestion: 'Add utility links',
						target: {
							templatePartRef: 'theme//header',
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
						persistence: {
							status: 'server',
						},
					},
				],
			},
		} );
		window.flavorAgentData = {
			canRecommendTemplateParts: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};

		await renderPanel();

		expect(
			getContainer().querySelector(
				'[data-panel-title="AI Template Part Recommendations"]'
			)
		).not.toBeNull();
		expect( hasText( 'Settings > Flavor Agent' ) ).toBe( true );
		expect( hasText( 'Recent AI Actions' ) ).toBe( true );
		expect(
			hasText(
				'Template-part actions share the same history and latest-valid undo behavior as the other executable review surfaces.'
			)
		).toBe( true );
		expect( hasText( 'Add utility links' ) ).toBe( true );
		expect( hasText( 'Undo available' ) ).toBe( true );
		expect( hasText( 'Suggested Composition' ) ).toBe( false );
		expect( getTextarea() ).toBeNull();
	} );
} );
