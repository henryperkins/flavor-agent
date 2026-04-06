const mockOnPromptChange = jest.fn();
const mockOnFetch = jest.fn();

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import SurfaceComposer from '../SurfaceComposer';

const { getContainer, getRoot } = setupReactTest();

beforeEach( () => {
	jest.clearAllMocks();
} );

describe( 'SurfaceComposer', () => {
	test( 'renders prompt textarea and fetch button', () => {
		act( () => {
			getRoot().render(
				<SurfaceComposer
					prompt="test prompt"
					onPromptChange={ mockOnPromptChange }
					onFetch={ mockOnFetch }
				/>
			);
		} );

		const textarea = getContainer().querySelector( 'textarea' );
		expect( textarea ).not.toBeNull();
		expect( textarea.value ).toBe( 'test prompt' );

		const button = getContainer().querySelector( 'button' );
		expect( button.textContent ).toBe( 'Get Suggestions' );
	} );

	test( 'shows loading label when isLoading', () => {
		act( () => {
			getRoot().render(
				<SurfaceComposer
					prompt=""
					onPromptChange={ mockOnPromptChange }
					onFetch={ mockOnFetch }
					isLoading
				/>
			);
		} );

		const button = getContainer().querySelector( 'button' );
		expect( button.textContent ).toContain( 'Getting suggestions' );
		expect( button.disabled ).toBe( true );
	} );

	test( 'disables controls when disabled prop is true', () => {
		act( () => {
			getRoot().render(
				<SurfaceComposer
					prompt=""
					onPromptChange={ mockOnPromptChange }
					onFetch={ mockOnFetch }
					disabled
				/>
			);
		} );

		const textarea = getContainer().querySelector( 'textarea' );
		expect( textarea.disabled ).toBe( true );

		const button = getContainer().querySelector( 'button' );
		expect( button.disabled ).toBe( true );
	} );

	test( 'uses custom fetch and loading labels', () => {
		act( () => {
			getRoot().render(
				<SurfaceComposer
					prompt=""
					onPromptChange={ mockOnPromptChange }
					onFetch={ mockOnFetch }
					fetchLabel="Get Nav Suggestions"
					loadingLabel="Thinking..."
				/>
			);
		} );

		expect( getContainer().querySelector( 'button' ).textContent ).toBe(
			'Get Nav Suggestions'
		);
	} );

	test( 'calls onFetch when button is clicked', () => {
		act( () => {
			getRoot().render(
				<SurfaceComposer
					prompt="something"
					onPromptChange={ mockOnPromptChange }
					onFetch={ mockOnFetch }
				/>
			);
		} );

		act( () => {
			getContainer().querySelector( 'button' ).click();
		} );

		expect( mockOnFetch ).toHaveBeenCalledTimes( 1 );
	} );
} );
