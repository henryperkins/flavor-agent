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
	<div>{ `Settings ${ props.suggestions.length }` }</div>
) );

jest.mock( '../StylesRecommendations', () => ( props ) => (
	<div>{ `Styles ${ props.suggestions.length }` }</div>
) );

jest.mock( '../SuggestionChips', () => ( props ) => (
	<div>{ props.label }</div>
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
					getBlockEditingMode: () =>
						getState().blockEditor.editingMode,
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

	test( 'hides stale block settings and style suggestions when context changes', () => {
		currentState = {
			...getState(),
			store: {
				...getState().store,
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
		expect( getContainer().textContent ).not.toContain( 'Settings 1' );
		expect( getContainer().textContent ).not.toContain( 'Styles 1' );
	} );

	test( 'drops previously fresh injected suggestions after a same-clientId block edit changes context', () => {
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
		expect( getContainer().textContent ).not.toContain( 'Settings 1' );
		expect( getContainer().textContent ).not.toContain( 'Styles 1' );
	} );
} );
