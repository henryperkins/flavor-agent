const mockUseSelect = jest.fn();
const mockCollectBlockContext = jest.fn();
const mockRenderBlockRecommendationsPanel = jest.fn();
const mockRenderSuggestionChips = jest.fn();

jest.mock( '@wordpress/hooks', () => ( {
	addFilter: jest.fn(),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/compose', () => ( {
	createHigherOrderComponent: ( factory ) => factory,
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: ( ...args ) => mockUseSelect( ...args ),
} ) );

jest.mock( '../../context/collector', () => ( {
	collectBlockContext: ( ...args ) => mockCollectBlockContext( ...args ),
	getLiveBlockContextSignature: ( _select, clientId ) => {
		const context = mockCollectBlockContext( clientId );

		return context ? JSON.stringify( context ) : '';
	},
} ) );

jest.mock( '../../utils/block-recommendation-context', () => ( {
	buildBlockRecommendationContextSignature: ( context = {} ) =>
		JSON.stringify( context || {} ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

jest.mock( '../BlockRecommendationsPanel', () => ( {
	BlockRecommendationsPanel: ( props ) => {
		mockRenderBlockRecommendationsPanel( props );

		return (
			<div>
				<div>Block Panel</div>
				<button
					type="button"
					onClick={ () =>
						props.onPromptChange?.(
							'Make the block feel more editorial.'
						)
					}
				>
					Change block prompt
				</button>
			</div>
		);
	},
} ) );

jest.mock( '../SuggestionChips', () => ( {
	__esModule: true,
	default: ( props ) => {
		mockRenderSuggestionChips( props );

		return (
			<div>
				{ `${ props.label }${ props.isStale ? ' stale' : '' }${
					props.interactive === false ? ' passive' : ''
				}` }
			</div>
		);
	},
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import withAIRecommendations from '../InspectorInjector';
import { buildBlockRecommendationRequestSignature } from '../../utils/recommendation-request-signature';

const { getContainer, getRoot } = setupReactTest();

let currentState = null;

function getState() {
	return currentState;
}

function renderComponent( props = {} ) {
	const BlockEdit = () => <div>Block Edit</div>;
	const Wrapped = withAIRecommendations( BlockEdit );

	act( () => {
		getRoot().render(
			<Wrapped clientId="block-1" isSelected { ...props } />
		);
	} );
}

function getLatestChipProps( label ) {
	const matchingCalls = mockRenderSuggestionChips.mock.calls
		.map( ( [ props ] ) => props )
		.filter( ( props ) => props.label === label );

	return matchingCalls[ matchingCalls.length - 1 ] || null;
}

beforeEach( () => {
	jest.clearAllMocks();
	currentState = {
		blockEditor: {
			editingMode: 'default',
			parentIds: [],
			editingModes: {},
		},
		store: {
			blockRecommendations: {
				prompt: 'Keep the current direction.',
				settings: [
					{ label: 'Use larger heading', panel: 'advanced' },
				],
				styles: [
					{ label: 'Use accent color', panel: 'color' },
					{ label: 'Use soft shadow', panel: 'shadow' },
				],
				block: [ { label: 'Hide on mobile' } ],
			},
			blockStatus: 'ready',
			blockContextSignature: JSON.stringify( {
				block: { name: 'core/paragraph' },
			} ),
		},
	};
	mockCollectBlockContext.mockReturnValue( {
		block: {
			name: 'core/paragraph',
		},
	} );
	mockUseSelect.mockImplementation( ( mapSelect ) =>
		mapSelect( ( storeName ) => {
			if ( storeName === 'core/block-editor' ) {
				return {
					getBlockEditingMode: ( clientId ) =>
						getState().blockEditor.editingModes?.[ clientId ] ??
						getState().blockEditor.editingMode,
					getBlockParents: () => getState().blockEditor.parentIds,
				};
			}

			if ( storeName === 'flavor-agent' ) {
				return {
					getBlockRecommendations: () =>
						getState().store.blockRecommendations,
					getBlockStatus: () => getState().store.blockStatus,
					getBlockRecommendationContextSignature: () =>
						getState().store.blockContextSignature,
				};
			}

			return {};
		} )
	);
} );

describe( 'InspectorInjector', () => {
	test( 'renders block-scoped passive delegated chip groups for fresh results', () => {
		renderComponent();

		expect( getContainer().textContent ).toContain( 'Block Panel' );
		expect( getContainer().textContent ).toContain(
			'AI color suggestions passive'
		);
		expect( getContainer().textContent ).toContain(
			'AI shadow suggestions passive'
		);
		const colorChipProps = getLatestChipProps( 'AI color suggestions' );

		expect( colorChipProps?.interactive ).toBe( false );
	} );

	test( 'keeps stale projected settings and style suggestions visible when context changes', () => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
				blockRecommendations: {
					prompt: 'Keep the current direction.',
					settings: [
						{ label: 'Use larger heading' },
						{ label: 'Pin block', panel: 'position' },
					],
					styles: [ { label: 'Use accent color', panel: 'color' } ],
					block: [ { label: 'Hide on mobile' } ],
				},
				blockContextSignature: JSON.stringify( {
					block: { name: 'core/heading' },
				} ),
			},
		};
		mockCollectBlockContext.mockReturnValue( {
			block: {
				name: 'core/paragraph',
			},
		} );

		renderComponent();

		expect( getContainer().textContent ).toContain( 'Block Panel' );
		expect( getContainer().textContent ).toContain(
			'AI position suggestions stale passive'
		);
		expect( getContainer().textContent ).toContain(
			'AI color suggestions stale passive'
		);
	} );

	test( 'keeps stale projected settings visible after a same-clientId block edit changes context', () => {
		renderComponent();

		expect( getContainer().textContent ).toContain(
			'AI color suggestions passive'
		);

		mockCollectBlockContext.mockReturnValue( {
			block: {
				name: 'core/heading',
			},
		} );

		renderComponent();

		expect( getContainer().textContent ).toContain( 'Block Panel' );
		expect( getContainer().textContent ).toContain(
			'AI color suggestions stale passive'
		);
	} );

	test( 'marks projected settings, styles, and delegated chips stale when only the shared block prompt changes', () => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
				blockRecommendations: {
					prompt: 'Keep the current direction.',
					settings: [
						{ label: 'Use larger heading' },
						{ label: 'Pin block', panel: 'position' },
					],
					styles: [ { label: 'Use accent color', panel: 'color' } ],
					block: [ { label: 'Hide on mobile' } ],
				},
			},
		};

		renderComponent();

		expect( getContainer().textContent ).not.toContain( ' stale' );

		act( () => {
			Array.from( getContainer().querySelectorAll( 'button' ) )
				.find(
					( button ) => button.textContent === 'Change block prompt'
				)
				?.click();
		} );

		const expectedRequestInput = {
			clientId: 'block-1',
			editorContext: {
				block: {
					name: 'core/paragraph',
				},
			},
			contextSignature: JSON.stringify( {
				block: { name: 'core/paragraph' },
			} ),
			prompt: 'Make the block feel more editorial.',
		};
		const expectedRequestSignature =
			buildBlockRecommendationRequestSignature( {
				clientId: 'block-1',
				prompt: 'Make the block feel more editorial.',
				contextSignature: JSON.stringify( {
					block: { name: 'core/paragraph' },
				} ),
			} );
		const positionChipProps = getLatestChipProps(
			'AI position suggestions'
		);
		const colorChipProps = getLatestChipProps( 'AI color suggestions' );

		expect( getContainer().textContent ).toContain(
			'AI position suggestions stale passive'
		);
		expect( getContainer().textContent ).toContain(
			'AI color suggestions stale passive'
		);
		expect( positionChipProps?.currentRequestSignature ).toBe(
			expectedRequestSignature
		);
		expect( positionChipProps?.currentRequestInput ).toEqual(
			expectedRequestInput
		);
		expect( colorChipProps?.currentRequestSignature ).toBe(
			expectedRequestSignature
		);
		expect( colorChipProps?.currentRequestInput ).toEqual(
			expectedRequestInput
		);
	} );

	test( 'does not mount styles recommendations when the block is inside a contentOnly parent', () => {
		currentState = {
			...getState(),
			blockEditor: {
				...getState().blockEditor,
				parentIds: [ 'parent-1' ],
				editingModes: {
					'parent-1': 'contentOnly',
				},
			},
		};

		renderComponent();

		expect( getContainer().textContent ).toContain( 'Block Panel' );
		expect( getContainer().textContent ).not.toContain(
			'AI color suggestions'
		);
	} );
} );
