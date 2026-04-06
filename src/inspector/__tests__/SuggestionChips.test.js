const mockUseDispatch = jest.fn();
const mockApplySuggestion = jest.fn();

jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import SuggestionChips from '../SuggestionChips';

const { getContainer, getRoot } = setupReactTest();

beforeEach( () => {
	mockApplySuggestion.mockReset();
	mockApplySuggestion.mockResolvedValue( true );
	mockUseDispatch.mockImplementation( () => ( {
		applySuggestion: mockApplySuggestion,
	} ) );
} );

describe( 'SuggestionChips', () => {
	test( 'renders named chip controls as an ARIA group', () => {
		act( () => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={ [
						{
							label: 'Use accent color',
							panel: 'color',
						},
					] }
				/>
			);
		} );

		expect(
			getContainer().querySelector(
				'[role="group"][aria-label="AI color suggestions"]'
			)
		).not.toBeNull();
	} );

	test( 'renders inline feedback near the chip group after apply', async () => {
		act( () => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={ [
						{
							label: 'Use accent color',
							panel: 'color',
						},
					] }
				/>
			);
		} );

		await act( async () => {
			getContainer().querySelector( 'button' ).click();
			await Promise.resolve();
		} );

		expect( mockApplySuggestion ).toHaveBeenCalledWith( 'block-1', {
			label: 'Use accent color',
			panel: 'color',
		} );
		expect(
			getContainer().querySelector( '.flavor-agent-inline-feedback' )
				?.textContent
		).toBe( 'AppliedUse accent color' );
	} );

	test( 'disables an applied chip while inline feedback is visible', async () => {
		act( () => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={ [
						{
							label: 'Use accent color',
							panel: 'color',
						},
					] }
				/>
			);
		} );

		const chip = getContainer().querySelector( 'button' );

		await act( async () => {
			chip.click();
			await Promise.resolve();
		} );

		expect( chip.disabled ).toBe( true );
		expect( chip.textContent ).toBe( 'Applied' );

		chip.click();

		expect( mockApplySuggestion ).toHaveBeenCalledTimes( 1 );
	} );
} );
