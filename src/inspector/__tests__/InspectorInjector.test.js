const mockUseSelect = jest.fn();
const mockCollectBlockContext = jest.fn();

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
	BlockRecommendationsPanel: () => <div>Block Panel</div>,
} ) );

jest.mock( '../SettingsRecommendations', () => ( props ) => (
	<div>{ `Settings ${ props.suggestions.length }${
		props.isStale ? ' stale' : ''
	}` }</div>
) );

jest.mock( '../StylesRecommendations', () => ( props ) => (
	<div>{ `Styles ${ props.suggestions.length }${
		props.isStale ? ' stale' : ''
	}` }</div>
) );

jest.mock( '../SuggestionChips', () => ( props ) => (
	<div>{ `${ props.label }${ props.isStale ? ' stale' : '' }` }</div>
) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import withAIRecommendations from '../InspectorInjector';

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
				settings: [ { label: 'Use larger heading' } ],
				styles: [ { label: 'Use accent color' } ],
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
	test( 'renders block-scoped settings and style inspector surfaces for fresh results', () => {
		renderComponent();

		expect( getContainer().textContent ).toContain( 'Block Panel' );
		expect( getContainer().textContent ).toContain( 'Settings 1' );
		expect( getContainer().textContent ).toContain( 'Styles 1' );
	} );

	test( 'keeps stale projected settings and style suggestions visible when context changes', () => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
				blockRecommendations: {
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
		expect( getContainer().textContent ).toContain( 'Settings 2 stale' );
		expect( getContainer().textContent ).toContain( 'Styles 1 stale' );
		expect( getContainer().textContent ).toContain(
			'AI position suggestions stale'
		);
		expect( getContainer().textContent ).toContain(
			'AI color suggestions stale'
		);
	} );

	test( 'keeps stale projected settings visible after a same-clientId block edit changes context', () => {
		renderComponent();

		expect( getContainer().textContent ).toContain( 'Settings 1' );
		expect( getContainer().textContent ).toContain( 'Styles 1' );

		mockCollectBlockContext.mockReturnValue( {
			block: {
				name: 'core/heading',
			},
		} );

		renderComponent();

		expect( getContainer().textContent ).toContain( 'Block Panel' );
		expect( getContainer().textContent ).toContain( 'Settings 1 stale' );
		expect( getContainer().textContent ).toContain( 'Styles 1 stale' );
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
		expect( getContainer().textContent ).toContain( 'Settings 1' );
		expect( getContainer().textContent ).not.toContain( 'Styles 1' );
	} );
} );
