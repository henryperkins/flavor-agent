const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockClearTemplateRecommendations = jest.fn();
const mockFetchTemplateRecommendations = jest.fn();
const mockSetTemplateSelectedSuggestion = jest.fn();
const mockUndoActivity = jest.fn();
const mockGetAllowedPatterns = jest.fn();
const mockGetBlockPatterns = jest.fn();
const mockGetTemplateActivityUndoState = jest.fn(
	( activity ) => activity?.undo || {}
);
const mockOpenInserterForPattern = jest.fn();
const mockSelectBlockByArea = jest.fn();
const mockSelectBlockBySlugOrArea = jest.fn();

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/components', () => {
	const { Fragment, createElement } = require( '@wordpress/element' );

	return {
		Button: ( { children, className, disabled, onClick } ) =>
			createElement(
				'button',
				{
					type: 'button',
					className,
					disabled,
					onClick,
				},
				children
			),
		Notice: ( { children } ) =>
			createElement( 'div', { role: 'alert' }, children ),
		TextareaControl: ( {
			className,
			label,
			onChange,
			placeholder,
			rows,
			value,
		} ) =>
			createElement(
				'label',
				null,
				createElement( 'span', null, label ),
				createElement( 'textarea', {
					'aria-label': label,
					className,
					rows,
					placeholder,
					value,
					onChange: ( event ) => onChange( event.target.value ),
				} )
			),
		Tooltip: ( { children } ) => createElement( Fragment, null, children ),
	};
} );

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

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../../patterns/compat', () => ( {
	getAllowedPatterns: ( ...args ) => mockGetAllowedPatterns( ...args ),
	getBlockPatterns: ( ...args ) => mockGetBlockPatterns( ...args ),
} ) );

jest.mock( '../../utils/template-actions', () => ( {
	getTemplateActivityUndoState: ( ...args ) =>
		mockGetTemplateActivityUndoState( ...args ),
	openInserterForPattern: ( ...args ) =>
		mockOpenInserterForPattern( ...args ),
	selectBlockByArea: ( ...args ) => mockSelectBlockByArea( ...args ),
	selectBlockBySlugOrArea: ( ...args ) =>
		mockSelectBlockBySlugOrArea( ...args ),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import TemplateRecommender from '../TemplateRecommender';
import {
	getSuggestionCardKey,
	TEMPLATE_OPERATION_INSERT_PATTERN,
} from '../template-recommender-helpers';

const TEMPLATE_REF = 'theme//home';
const NEXT_TEMPLATE_REF = 'theme//single';
const SUGGESTION = {
	label: 'Add hero intro',
	description: 'Insert a hero pattern near the current insertion point.',
	operations: [
		{
			type: TEMPLATE_OPERATION_INSERT_PATTERN,
			patternName: 'theme/hero',
		},
	],
};
const SUGGESTION_KEY = getSuggestionCardKey( SUGGESTION, 0 );

let currentState = null;
let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

function getState() {
	return currentState;
}

function getBlockRecord( clientId ) {
	if ( ! clientId ) {
		return null;
	}

	const state = getState();

	return (
		state.blockEditor.blockLookup[ clientId ] || {
			clientId,
			name: 'core/group',
			attributes: {},
			innerBlocks: [],
		}
	);
}

function createSelectors() {
	return {
		editor: {
			getCurrentPostId: jest.fn( () => getState().editor.postId ),
			getCurrentPostType: jest.fn( () => getState().editor.postType ),
		},
		blockEditor: {
			getAllowedPatterns: jest.fn( ( rootClientId ) => {
				const state = getState();
				const key =
					rootClientId === null ? 'null' : String( rootClientId );

				return state.blockEditor.allowedPatternsByRoot[ key ] || [];
			} ),
			getBlock: jest.fn( ( clientId ) => getBlockRecord( clientId ) ),
			getBlockInsertionPoint: jest.fn(
				() => getState().blockEditor.insertionPoint
			),
			getBlocks: jest.fn( () => getState().blockEditor.blocks ),
			getSelectedBlockClientId: jest.fn(
				() => getState().blockEditor.selectedBlockClientId
			),
		},
		editSite: {
			getEditedPostId: jest.fn( () => getState().editSite.postId ),
			getEditedPostType: jest.fn( () => getState().editSite.postType ),
		},
		store: {
			getActivityLog: jest.fn( () => getState().store.activityLog ),
			getLastUndoneActivityId: jest.fn(
				() => getState().store.lastUndoneActivityId
			),
			getTemplateApplyError: jest.fn(
				() => getState().store.templateApplyError
			),
			getTemplateApplyStatus: jest.fn(
				() => getState().store.templateApplyStatus
			),
			getTemplateError: jest.fn( () => getState().store.templateError ),
			getTemplateExplanation: jest.fn(
				() => getState().store.templateExplanation
			),
			getTemplateLastAppliedOperations: jest.fn(
				() => getState().store.templateLastAppliedOperations
			),
			getTemplateLastAppliedSuggestionKey: jest.fn(
				() => getState().store.templateLastAppliedSuggestionKey
			),
			getTemplateRecommendations: jest.fn(
				() => getState().store.templateRecommendations
			),
			getTemplateResultRef: jest.fn(
				() => getState().store.templateResultRef
			),
			getTemplateResultToken: jest.fn(
				() => getState().store.templateResultToken
			),
			getTemplateSelectedSuggestionKey: jest.fn(
				() => getState().store.templateSelectedSuggestionKey
			),
			getUndoError: jest.fn( () => getState().store.undoError ),
			getUndoStatus: jest.fn( () => getState().store.undoStatus ),
			isTemplateLoading: jest.fn(
				() => getState().store.templateStatus === 'loading'
			),
		},
	};
}

const selectors = createSelectors();

function createDispatchers() {
	return {
		applyTemplateSuggestion: jest.fn(),
		clearTemplateRecommendations: mockClearTemplateRecommendations,
		clearUndoError: jest.fn(),
		fetchTemplateRecommendations: mockFetchTemplateRecommendations,
		setTemplateSelectedSuggestion: mockSetTemplateSelectedSuggestion,
		undoActivity: mockUndoActivity,
	};
}

const dispatchers = createDispatchers();

function createState( overrides = {} ) {
	return {
		editor: {
			postId: TEMPLATE_REF,
			postType: 'wp_template',
			...overrides.editor,
		},
		editSite: {
			postId: TEMPLATE_REF,
			postType: 'wp_template',
			...overrides.editSite,
		},
		blockEditor: {
			allowedPatternsByRoot: {
				'root-a': [ { name: 'theme/hero' }, { name: 'theme/footer' } ],
				'root-b': [ { name: 'theme/footer' } ],
			},
			blockLookup: {
				'root-a': {
					clientId: 'root-a',
					name: 'core/group',
					attributes: {},
					innerBlocks: [],
				},
				'root-b': {
					clientId: 'root-b',
					name: 'core/group',
					attributes: {},
					innerBlocks: [],
				},
			},
			blocks: [
				{
					clientId: 'group-1',
					name: 'core/group',
					attributes: {},
					innerBlocks: [
						{
							clientId: 'part-header',
							name: 'core/template-part',
							attributes: {
								slug: 'site-header',
								area: 'header',
							},
							innerBlocks: [],
						},
					],
				},
				{
					clientId: 'part-footer',
					name: 'core/template-part',
					attributes: {
						area: 'footer',
					},
					innerBlocks: [],
				},
			],
			insertionPoint: {
				rootClientId: 'root-a',
				index: 0,
			},
			selectedBlockClientId: null,
			...overrides.blockEditor,
		},
		store: {
			activityLog: [],
			lastUndoneActivityId: null,
			templateApplyError: 'The previous apply state should be cleared.',
			templateApplyStatus: 'error',
			templateError: null,
			templateExplanation:
				'A focused hero pattern would strengthen the intro.',
			templateLastAppliedOperations: [],
			templateLastAppliedSuggestionKey: null,
			templateRecommendations: [ SUGGESTION ],
			templateResultRef: TEMPLATE_REF,
			templateResultToken: 1,
			templateSelectedSuggestionKey: SUGGESTION_KEY,
			templateStatus: 'ready',
			undoError: null,
			undoStatus: 'idle',
			...overrides.store,
		},
	};
}

function selectStore( storeName ) {
	if ( storeName === 'core/editor' ) {
		return selectors.editor;
	}

	if ( storeName === 'core/block-editor' ) {
		return selectors.blockEditor;
	}

	if ( storeName === 'core/edit-site' ) {
		return selectors.editSite;
	}

	if ( storeName === 'flavor-agent' ) {
		return selectors.store;
	}

	return {};
}

function hasText( value ) {
	return container.textContent.includes( value );
}

function getTextarea() {
	return container.querySelector( 'textarea' );
}

async function setPromptValue( value ) {
	const textarea = getTextarea();

	await act( async () => {
		const descriptor = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		);

		descriptor.set.call( textarea, value );
		textarea.dispatchEvent(
			new window.Event( 'input', { bubbles: true } )
		);
	} );
}

async function renderPanel() {
	await act( async () => {
		root.render( <TemplateRecommender /> );
	} );
}

beforeEach( async () => {
	jest.clearAllMocks();
	currentState = createState();
	mockGetTemplateActivityUndoState.mockImplementation(
		( activity ) => activity?.undo || {}
	);
	window.flavorAgentData = {
		canRecommendTemplates: true,
	};
	mockGetBlockPatterns.mockReturnValue( [
		{
			name: 'theme/hero',
			title: 'Hero',
		},
		{
			name: 'theme/footer',
			title: 'Footer',
		},
	] );
	mockGetAllowedPatterns.mockImplementation(
		( rootClientId, blockEditor ) =>
			blockEditor?.getAllowedPatterns?.( rootClientId ) || []
	);
	mockUseDispatch.mockImplementation( () => dispatchers );
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( selectStore )
	);
	mockSetTemplateSelectedSuggestion.mockImplementation( ( suggestionKey ) => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
				templateSelectedSuggestionKey: suggestionKey ?? null,
			},
		};
	} );
	mockClearTemplateRecommendations.mockImplementation( () => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
				templateRecommendations: [],
				templateExplanation: '',
				templateError: null,
				templateStatus: 'idle',
				templateResultRef: null,
				templateResultToken: getState().store.templateResultToken + 1,
				templateSelectedSuggestionKey: null,
				templateApplyStatus: 'idle',
				templateApplyError: null,
				templateLastAppliedSuggestionKey: null,
				templateLastAppliedOperations: [],
			},
		};
	} );
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

describe( 'TemplateRecommender', () => {
	test( 'clears stale recommendations on insertion-root drift without resetting the prompt', async () => {
		expect( hasText( 'Add hero intro' ) ).toBe( true );
		expect( hasText( 'Confirm Apply' ) ).toBe( true );
		expect( hasText( 'The previous apply state should be cleared.' ) ).toBe(
			true
		);

		await setPromptValue( 'Keep this prompt.' );
		expect( getTextarea().value ).toBe( 'Keep this prompt.' );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				insertionPoint: {
					rootClientId: 'root-b',
					index: 0,
				},
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Add hero intro' ) ).toBe( false );
		expect( hasText( 'Confirm Apply' ) ).toBe( false );
		expect( hasText( 'The previous apply state should be cleared.' ) ).toBe(
			false
		);
		expect( getTextarea().value ).toBe( 'Keep this prompt.' );
	} );

	test( 'does not clear when visible patterns only reorder', async () => {
		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				allowedPatternsByRoot: {
					...getState().blockEditor.allowedPatternsByRoot,
					'root-b': [
						{ name: 'theme/footer' },
						{ name: 'theme/hero' },
						{ name: 'theme/footer' },
					],
				},
				insertionPoint: {
					rootClientId: 'root-b',
					index: 0,
				},
			},
		};

		await renderPanel();

		expect( mockClearTemplateRecommendations ).not.toHaveBeenCalled();
		expect( hasText( 'Add hero intro' ) ).toBe( true );
	} );

	test( 'clears recommendations and resets the prompt when the template changes', async () => {
		await setPromptValue( 'Reset this prompt on template switch.' );
		expect( getTextarea().value ).toBe(
			'Reset this prompt on template switch.'
		);

		currentState = {
			...getState(),
			editor: {
				postId: NEXT_TEMPLATE_REF,
				postType: 'wp_template',
			},
			editSite: {
				postId: NEXT_TEMPLATE_REF,
				postType: 'wp_template',
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Add hero intro' ) ).toBe( false );
		expect( getTextarea().value ).toBe( '' );
	} );

	test( 'clears an in-flight request when the context changes while loading', async () => {
		currentState = createState( {
			store: {
				templateApplyError: null,
				templateApplyStatus: 'idle',
				templateExplanation: '',
				templateRecommendations: [],
				templateResultRef: null,
				templateSelectedSuggestionKey: null,
				templateStatus: 'loading',
			},
		} );

		await renderPanel();
		expect( hasText( 'Analyzing template structure…' ) ).toBe( true );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				insertionPoint: {
					rootClientId: 'root-b',
					index: 0,
				},
			},
		};

		await renderPanel();
		await renderPanel();

		expect( mockClearTemplateRecommendations ).toHaveBeenCalledTimes( 1 );
		expect( hasText( 'Analyzing template structure…' ) ).toBe( false );
	} );

	test( 'shows the undo success notice even when the last template activity is now undone', async () => {
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_suggestion',
						surface: 'template',
						suggestion: 'Clarify hierarchy',
						target: {
							templateRef: TEMPLATE_REF,
						},
						undo: {
							canUndo: false,
							status: 'undone',
							error: null,
						},
					},
				],
				lastUndoneActivityId: 'activity-1',
				undoStatus: 'success',
			},
		} );

		await renderPanel();

		expect( hasText( 'Undid Clarify hierarchy.' ) ).toBe( true );
	} );

	test( 'does not render while editing a page even if edit-site still exposes a template ref', async () => {
		currentState = createState( {
			editor: {
				postId: 42,
				postType: 'page',
			},
			editSite: {
				postId: TEMPLATE_REF,
				postType: 'wp_template',
			},
		} );

		await renderPanel();

		expect(
			container.querySelector(
				'[data-panel-title="AI Template Recommendations"]'
			)
		).toBeNull();
	} );

	test( 'recomputes template undo availability when the block tree changes', async () => {
		mockGetTemplateActivityUndoState.mockImplementation(
			( activity, blockEditorSelect ) =>
				( blockEditorSelect?.getBlocks?.() || [] ).length > 0
					? {
							canUndo: true,
							status: 'available',
							error: null,
					  }
					: {
							canUndo: false,
							status: 'failed',
							error: 'Inserted pattern content changed after apply and cannot be undone automatically.',
					  }
		);
		currentState = createState( {
			store: {
				activityLog: [
					{
						id: 'activity-1',
						type: 'apply_template_suggestion',
						surface: 'template',
						suggestion: 'Clarify hierarchy',
						target: {
							templateRef: TEMPLATE_REF,
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
						},
					},
				],
			},
		} );

		await renderPanel();

		expect( hasText( 'Undo available' ) ).toBe( true );

		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				blocks: [],
			},
		};

		await renderPanel();

		expect( hasText( 'Undo unavailable' ) ).toBe( true );
		expect(
			hasText(
				'Inserted pattern content changed after apply and cannot be undone automatically.'
			)
		).toBe( true );
	} );
} );
