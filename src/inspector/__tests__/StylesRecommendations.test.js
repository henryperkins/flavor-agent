const mockApplySuggestion = jest.fn();
const mockUseDispatch = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
} ) );

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/icons', () => ( {
	arrowRight: 'arrow-right',
	check: 'check',
	styles: 'styles-icon',
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

import { createElement } from '@wordpress/element';
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import StylesRecommendations from '../StylesRecommendations';

const { getContainer, getRoot } = setupReactTest();

beforeEach( () => {
	jest.clearAllMocks();
	mockApplySuggestion.mockResolvedValue( true );
	mockUseDispatch.mockReturnValue( {
		applySuggestion: mockApplySuggestion,
	} );
} );

function renderComponent( suggestions ) {
	act( () => {
		getRoot().render(
			createElement( StylesRecommendations, {
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
	};
}

describe( 'StylesRecommendations', () => {
	test( 'does not render suggestions for delegated style panels', () => {
		const delegated = [
			makeSuggestion( 'color' ),
			makeSuggestion( 'typography' ),
			makeSuggestion( 'dimensions' ),
			makeSuggestion( 'border' ),
			makeSuggestion( 'filter' ),
			makeSuggestion( 'background' ),
		];
		const kept = [ makeSuggestion( 'shadow' ) ];

		renderComponent( [ ...delegated, ...kept ] );

		const text = getContainer().textContent;

		expect( text ).not.toContain( 'Suggestion for color' );
		expect( text ).not.toContain( 'Suggestion for typography' );
		expect( text ).not.toContain( 'Suggestion for dimensions' );
		expect( text ).not.toContain( 'Suggestion for border' );
		expect( text ).not.toContain( 'Suggestion for filter' );
		expect( text ).not.toContain( 'Suggestion for background' );
		expect( text ).toContain( 'Suggestion for shadow' );
	} );

	test( 'renders non-delegated panels in the panel body', () => {
		renderComponent( [
			makeSuggestion( 'shadow' ),
			makeSuggestion( 'general' ),
		] );

		const text = getContainer().textContent;
		expect( text ).toContain( 'Suggestion for shadow' );
		expect( text ).toContain( 'Suggestion for general' );
	} );

	test( 'shows hint when delegated style panels have suggestions', () => {
		renderComponent( [
			makeSuggestion( 'filter' ),
			makeSuggestion( 'shadow' ),
		] );

		expect( getContainer().textContent ).toContain( 'Native Style Panels' );
		expect( getContainer().textContent ).toContain( 'Filter' );
	} );

	test( 'does not show hint when no delegated panels have suggestions', () => {
		renderComponent( [ makeSuggestion( 'shadow' ) ] );

		expect( getContainer().textContent ).not.toContain(
			'Native Style Panels'
		);
	} );

	test( 'renders style variations separately', () => {
		const variation = {
			label: 'Outline',
			description: 'Outline style',
			panel: 'general',
			type: 'style_variation',
			attributeUpdates: { className: 'is-style-outline' },
			isCurrentStyle: false,
			isRecommended: true,
		};

		renderComponent( [ variation ] );

		expect( getContainer().textContent ).toContain( 'Outline' );
		expect( getContainer().textContent ).toContain( 'Style Variations' );
	} );

	test( 'disables the current style variation', () => {
		const variation = {
			label: 'Outline',
			description: 'Outline style',
			panel: 'general',
			type: 'style_variation',
			attributeUpdates: { className: 'is-style-outline' },
			isCurrentStyle: true,
		};

		renderComponent( [ variation ] );

		const button = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( candidate ) => candidate.textContent === 'Outline' );

		expect( button?.disabled ).toBe( true );
		expect( mockApplySuggestion ).not.toHaveBeenCalled();
	} );

	test( 'returns null for empty suggestions', () => {
		renderComponent( [] );
		expect( getContainer().innerHTML ).toBe( '' );
	} );

	test( 'shows inline apply feedback after a style row is applied', async () => {
		const suggestion = makeSuggestion( 'shadow', 'Use softer shadow' );

		renderComponent( [ suggestion ] );

		const applyButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Apply' );

		await act( async () => {
			applyButton.click();
			await Promise.resolve();
		} );

		expect( mockApplySuggestion ).toHaveBeenCalledWith(
			'block-1',
			suggestion
		);
		expect(
			getContainer().querySelector( '.flavor-agent-inline-feedback' )
				?.textContent
		).toBe( 'AppliedUse softer shadow.' );
	} );

	test( 'keeps row feedback visible across rerenders with cloned suggestions', async () => {
		const suggestion = makeSuggestion( 'shadow', 'Use softer shadow' );

		renderComponent( [ suggestion ] );

		const applyButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Apply' );

		await act( async () => {
			applyButton.click();
			await Promise.resolve();
		} );

		renderComponent( [ { ...suggestion } ] );

		expect(
			getContainer().querySelector( '.flavor-agent-inline-feedback' )
				?.textContent
		).toBe( 'AppliedUse softer shadow.' );
		const rerenderedApplyButton = getContainer().querySelector(
			'.flavor-agent-style-row__apply'
		);
		expect( rerenderedApplyButton?.disabled ).toBe( true );
	} );
} );

describe( 'color preview swatch', () => {
	test( 'renders swatch for oklch preview value', () => {
		const suggestion = {
			label: 'Accent color',
			description: 'Use accent',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: 'oklch(0.7 0.15 240)',
		};

		renderComponent( [ suggestion ] );

		const swatch = getContainer().querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect( swatch ).not.toBeNull();
	} );

	test( 'renders swatch for var() preview value', () => {
		const suggestion = {
			label: 'Accent var',
			description: 'Use var',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: 'var(--wp--preset--color--accent)',
		};

		renderComponent( [ suggestion ] );

		const swatch = getContainer().querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect( swatch ).not.toBeNull();
	} );

	test( 'does not render swatch for non-color preview value', () => {
		const suggestion = {
			label: 'Font size',
			description: 'Bigger text',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: '1.5rem',
		};

		renderComponent( [ suggestion ] );

		const swatch = getContainer().querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect( swatch ).toBeNull();
	} );
} );
