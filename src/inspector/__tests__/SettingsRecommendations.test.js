const mockApplySuggestion = jest.fn();
const mockUseDispatch = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
} ) );

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		PanelBody: ( { children, title } ) =>
			createElement( 'div', { 'data-panel': title }, children ),
		Button: ( { children, className, disabled, onClick } ) =>
			createElement(
				'button',
				{ type: 'button', className, disabled, onClick },
				children
			),
	};
} );

jest.mock( '@wordpress/icons', () => ( {
	Icon: () => null,
	check: 'check',
	arrowRight: 'arrow-right',
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

import { createElement } from '@wordpress/element';
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import SettingsRecommendations from '../SettingsRecommendations';

let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	jest.clearAllMocks();
	mockApplySuggestion.mockResolvedValue( true );
	mockUseDispatch.mockReturnValue( {
		applySuggestion: mockApplySuggestion,
	} );
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
	container = null;
	root = null;
} );

function renderComponent( suggestions ) {
	act( () => {
		root.render(
			createElement( SettingsRecommendations, {
				clientId: 'block-1',
				suggestions,
			} )
		);
	} );
}

function makeSuggestion( panel, label = `Suggestion for ${ panel }` ) {
	return {
		label,
		description: `${ panel } description`,
		panel,
		type: 'attribute_change',
		attributeUpdates: {},
		confidence: 0.8,
		currentValue: 'old',
		suggestedValue: 'new',
	};
}

describe( 'SettingsRecommendations', () => {
	test( 'does not render suggestions for delegated settings panels', () => {
		const delegated = [
			makeSuggestion( 'position' ),
			makeSuggestion( 'advanced' ),
			makeSuggestion( 'bindings' ),
		];
		const kept = [ makeSuggestion( 'general' ) ];

		renderComponent( [ ...delegated, ...kept ] );

		const text = container.textContent;

		expect( text ).not.toContain( 'Suggestion for position' );
		expect( text ).not.toContain( 'Suggestion for advanced' );
		expect( text ).not.toContain( 'Suggestion for bindings' );
		expect( text ).toContain( 'Suggestion for general' );
	} );

	test( 'renders non-delegated panels normally', () => {
		renderComponent( [
			makeSuggestion( 'general' ),
			makeSuggestion( 'layout' ),
			makeSuggestion( 'alignment' ),
		] );

		const text = container.textContent;
		expect( text ).toContain( 'Suggestion for general' );
		expect( text ).toContain( 'Suggestion for layout' );
		expect( text ).toContain( 'Suggestion for alignment' );
	} );

	test( 'returns null when all suggestions are delegated', () => {
		renderComponent( [
			makeSuggestion( 'position' ),
			makeSuggestion( 'advanced' ),
		] );

		expect( container.innerHTML ).toBe( '' );
	} );

	test( 'returns null for empty suggestions', () => {
		renderComponent( [] );
		expect( container.innerHTML ).toBe( '' );
	} );
} );
