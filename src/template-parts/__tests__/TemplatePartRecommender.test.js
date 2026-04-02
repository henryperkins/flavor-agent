const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockGetBlockPatterns = jest.fn();
const mockGetTemplatePartActivityUndoState = jest.fn(
	( activity ) => activity?.undo || {}
);
const mockGetTemplatePartAreaLookup = jest.fn( () => ( {} ) );
const mockUndoActivity = jest.fn();

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
	openInserterForPattern: jest.fn(),
	selectBlockByPath: jest.fn(),
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
const { createRoot } = require( '@wordpress/element' );

import TemplatePartRecommender from '../TemplatePartRecommender';

let currentState = null;
let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

function getState() {
	return currentState;
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
			templatePartLastAppliedOperations: [],
			templatePartLastAppliedSuggestionKey: null,
			templatePartRecommendations: [],
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
			getTemplatePartLastAppliedOperations: jest.fn(
				() => getState().store.templatePartLastAppliedOperations
			),
			getTemplatePartLastAppliedSuggestionKey: jest.fn(
				() => getState().store.templatePartLastAppliedSuggestionKey
			),
			getTemplatePartRecommendations: jest.fn(
				() => getState().store.templatePartRecommendations
			),
			getTemplatePartResultRef: jest.fn(
				() => getState().store.templatePartResultRef
			),
			getTemplatePartResultToken: jest.fn(
				() => getState().store.templatePartResultToken
			),
			getTemplatePartSelectedSuggestionKey: jest.fn(
				() => getState().store.templatePartSelectedSuggestionKey
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
	return container.textContent.includes( value );
}

function getTextarea() {
	return container.querySelector( 'textarea' );
}

async function renderPanel() {
	await act( async () => {
		root.render( <TemplatePartRecommender /> );
	} );
}

beforeEach( async () => {
	jest.clearAllMocks();
	currentState = createState();
	window.flavorAgentData = {
		canRecommendTemplateParts: true,
	};
	mockGetBlockPatterns.mockReturnValue( [] );
	mockUseDispatch.mockImplementation( () => ( {
		applyTemplatePartSuggestion: jest.fn(),
		clearTemplatePartRecommendations: jest.fn(),
		clearUndoError: jest.fn(),
		fetchTemplatePartRecommendations: jest.fn(),
		setTemplatePartSelectedSuggestion: jest.fn(),
		undoActivity: mockUndoActivity,
	} ) );
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( selectStore )
	);
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await renderPanel();
} );

afterEach( async () => {
	delete window.flavorAgentData;

	if ( root ) {
		await act( async () => {
			root.unmount();
		} );
	}

	if ( container ) {
		container.remove();
	}

	root = null;
	container = null;
	currentState = null;
} );

describe( 'TemplatePartRecommender', () => {
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
			container.querySelector(
				'.flavor-agent-template-preview .flavor-agent-preview-token--pattern'
			)
		).not.toBeNull();
		expect(
			container.querySelector(
				'.flavor-agent-template-preview .flavor-agent-action-link'
			)
		).toBeNull();
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
			container.querySelectorAll( 'button' )
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
			container.querySelector(
				'[data-panel-title="AI Template Part Recommendations"]'
			)
		).not.toBeNull();
		expect( hasText( 'Settings > Flavor Agent' ) ).toBe( true );
		expect( hasText( 'Recent AI Actions' ) ).toBe( true );
		expect( hasText( 'Add utility links' ) ).toBe( true );
		expect( hasText( 'Undo available' ) ).toBe( true );
		expect( hasText( 'Suggested Composition' ) ).toBe( false );
		expect( getTextarea() ).toBeNull();
	} );
} );
