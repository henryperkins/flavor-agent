const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockApplySuggestion = jest.fn();
const mockCollectBlockContext = jest.fn();

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
	collectBlockContext: (...args) => mockCollectBlockContext(...args),
	getLiveBlockContextSignature: jest.fn(
		(_select, clientId) => `live-context:${clientId}`
	),
}));

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require('react');
const { setupReactTest } = require('../../test-utils/setup-react-test');

import SuggestionChips from '../SuggestionChips';
import { buildBlockRecommendationRequestSignature } from '../../utils/recommendation-request-signature';

const { getContainer, getRoot } = setupReactTest();

beforeEach(() => {
	mockApplySuggestion.mockReset();
	mockApplySuggestion.mockResolvedValue(true);
	mockCollectBlockContext.mockReset();
	mockCollectBlockContext.mockReturnValue({
		block: { name: 'core/paragraph' },
	});
	mockUseSelect.mockReset();
	mockUseDispatch.mockImplementation(() => ({
		applySuggestion: mockApplySuggestion,
	}));
	mockUseSelect.mockImplementation((callback) =>
		callback((storeName) => {
			if (storeName === 'flavor-agent') {
				return {
					getBlockRecommendations: () => ({
						prompt: 'Keep the current direction.',
					}),
				};
			}

			return {};
		})
	);
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
				buildBlockRecommendationRequestSignature({
					clientId: 'block-1',
					prompt: 'Keep the current direction.',
					contextSignature: 'live-context:block-1',
				}),
				{
					clientId: 'block-1',
					editorContext: {
						block: { name: 'core/paragraph' },
					},
					contextSignature: 'live-context:block-1',
					prompt: 'Keep the current direction.',
				}
			);
		expect(
			getContainer().querySelector('.flavor-agent-inline-feedback')?.textContent
		).toBe('AppliedUse accent color');
	});

	test('prefers the live block request metadata passed from the main panel when applying', async () => {
		const currentRequestInput = {
			clientId: 'block-1',
			editorContext: {
				block: { name: 'core/heading' },
			},
			contextSignature: 'live-context:block-1:prompt-drift',
			prompt: 'Make the block feel more editorial.',
		};

		act(() => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					currentRequestSignature="live-signature:block-1"
					currentRequestInput={currentRequestInput}
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
			'live-signature:block-1',
			currentRequestInput
		);
	});

	test('shows stale guidance that points back to the main AI Recommendations panel', () => {
		act(() => {
			getRoot().render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					isStale
					suggestions={[
						{
							label: 'Use accent color',
							panel: 'color',
						},
					]}
				/>
			);
		});

		expect(getContainer().textContent).toContain(
			'These suggestions reflect the last AI Recommendations request.'
		);
		expect(getContainer().textContent).toContain(
			'Refresh that main panel to update them for the current block.'
		);
		const staleNotice = getContainer().querySelector(
			'.flavor-agent-chip-surface__stale[role="status"][aria-live="polite"]'
		);

		expect(staleNotice).not.toBeNull();
		expect(
			Array.from(getContainer().querySelectorAll('button')).find(
				(element) => element.textContent === 'Refresh'
			)
		).toBeUndefined();
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
