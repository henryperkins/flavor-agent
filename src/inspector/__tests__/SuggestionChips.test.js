const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockApplySuggestion = jest.fn();

jest.mock('@wordpress/components', () =>
	require('../../test-utils/wp-components').mockWpComponents()
);

jest.mock('@wordpress/data', () => ({
	useDispatch: (...args) => mockUseDispatch(...args),
	useSelect: (...args) => mockUseSelect(...args),
}));

jest.mock('../../store', () => ({
	STORE_NAME: 'flavor-agent',
}));

jest.mock('../../context/collector', () => ({
	getLiveBlockContextSignature: jest.fn(
		(_select, clientId) => `live-context:${clientId}`
	),
}));

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require('react');
const { setupReactTest } = require('../../test-utils/setup-react-test');

import SuggestionChips from '../SuggestionChips';

const { getContainer, getRoot } = setupReactTest();

beforeEach(() => {
	mockApplySuggestion.mockReset();
	mockApplySuggestion.mockResolvedValue(true);
	mockUseSelect.mockReset();
	mockUseDispatch.mockImplementation(() => ({
		applySuggestion: mockApplySuggestion,
	}));
	mockUseSelect.mockImplementation((callback) => callback(jest.fn()));
});

describe('SuggestionChips', () => {
	test('renders named chip controls as an ARIA group', () => {
		act(() => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={[
						{
							label: 'Use accent color',
							panel: 'color',
						},
					]}
				/>
			);
		});

		expect(
			getContainer().querySelector(
				'[role="group"][aria-label="AI color suggestions"]'
			)
		).not.toBeNull();
	});

	test('renders inline feedback near the chip group after apply', async () => {
		act(() => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={[
						{
							label: 'Use accent color',
							panel: 'color',
						},
					]}
				/>
			);
		});

		await act(async () => {
			getContainer().querySelector('button').click();
			await Promise.resolve();
		});

		expect(mockApplySuggestion).toHaveBeenCalledWith(
			'block-1',
			{
				label: 'Use accent color',
				panel: 'color',
			},
			'live-context:block-1'
		);
		expect(
			getContainer().querySelector('.flavor-agent-inline-feedback')?.textContent
		).toBe('AppliedUse accent color');
	});

	test('disables an applied chip while inline feedback is visible', async () => {
		act(() => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={[
						{
							label: 'Use accent color',
							panel: 'color',
						},
					]}
				/>
			);
		});

		const chip = getContainer().querySelector('button');

		await act(async () => {
			chip.click();
			await Promise.resolve();
		});

		expect(chip.disabled).toBe(true);
		expect(chip.textContent).toBe('Use accent color');

		chip.click();

		expect(mockApplySuggestion).toHaveBeenCalledTimes(1);
	});

	test('keeps apply feedback visible across rerenders with a cloned suggestion array', async () => {
		const initialSuggestions = [
			{
				label: 'Use accent color',
				panel: 'color',
			},
		];

		act(() => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={initialSuggestions}
				/>
			);
		});

		await act(async () => {
			getContainer().querySelector('button').click();
			await Promise.resolve();
		});

		act(() => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={[
						{
							label: 'Use accent color',
							panel: 'color',
						},
					]}
				/>
			);
		});

		expect(
			getContainer().querySelector('.flavor-agent-inline-feedback')?.textContent
		).toBe('AppliedUse accent color');
		expect(getContainer().querySelector('button')?.disabled).toBe(true);
	});
});
