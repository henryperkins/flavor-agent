const mockApplySuggestion = jest.fn();
const mockUseDispatch = jest.fn();
const mockUseSelect = jest.fn();
const mockCollectBlockContext = jest.fn();

jest.mock('@wordpress/data', () => ({
	useDispatch: (...args) => mockUseDispatch(...args),
	useSelect: (...args) => mockUseSelect(...args),
}));

jest.mock('@wordpress/components', () =>
	require('../../test-utils/wp-components').mockWpComponents()
);

jest.mock('@wordpress/icons', () => ({
	arrowRight: 'arrow-right',
	check: 'check',
	styles: 'styles-icon',
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

import { createElement } from '@wordpress/element';
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require('react');
const { setupReactTest } = require('../../test-utils/setup-react-test');

import StylesRecommendations from '../StylesRecommendations';
import { buildBlockRecommendationRequestSignature } from '../../utils/recommendation-request-signature';

const { getContainer, getRoot } = setupReactTest();

beforeEach(() => {
	jest.clearAllMocks();
	mockApplySuggestion.mockResolvedValue(true);
	mockCollectBlockContext.mockReturnValue({
		block: { name: 'core/paragraph' },
	});
	mockUseDispatch.mockReturnValue({
		applySuggestion: mockApplySuggestion,
		clearBlockError: jest.fn(),
	});
	mockUseSelect.mockImplementation((callback) =>
		callback(() => ({
			getBlockApplyError: jest.fn(() => null),
			getSurfaceStatusNotice: jest.fn(() => null),
			getBlockRecommendations: jest.fn(() => ({ prompt: 'Warm up the palette' })),
		}))
	);
});

function renderComponent(suggestions, extraProps = {}) {
	act(() => {
		getRoot().render(
			createElement(StylesRecommendations, {
				clientId: 'block-1',
				suggestions,
				...extraProps,
			})
		);
	});
}

function makeSuggestion(panel, label = `Suggestion for ${panel}`) {
	return {
		label,
		description: `${panel} description`,
		panel,
		type: 'attribute_change',
		attributeUpdates: {},
		confidence: 0.8,
	};
}

describe('StylesRecommendations', () => {
	test('does not render suggestions for delegated style panels', () => {
		const delegated = [
			makeSuggestion('color'),
			makeSuggestion('typography'),
			makeSuggestion('dimensions'),
			makeSuggestion('border'),
			makeSuggestion('filter'),
			makeSuggestion('background'),
		];
		const kept = [makeSuggestion('shadow')];

		renderComponent([...delegated, ...kept]);

		const text = getContainer().textContent;

		expect(text).not.toContain('Suggestion for color');
		expect(text).not.toContain('Suggestion for typography');
		expect(text).not.toContain('Suggestion for dimensions');
		expect(text).not.toContain('Suggestion for border');
		expect(text).not.toContain('Suggestion for filter');
		expect(text).not.toContain('Suggestion for background');
		expect(text).toContain('Suggestion for shadow');
	});

	test('renders non-delegated panels in the panel body', () => {
		renderComponent([makeSuggestion('shadow'), makeSuggestion('general')]);

		const text = getContainer().textContent;
		expect(text).toContain('Suggestion for shadow');
		expect(text).toContain('Suggestion for general');
	});

	test('shows hint when delegated style panels have suggestions', () => {
		renderComponent([makeSuggestion('filter'), makeSuggestion('shadow')]);

		expect(getContainer().textContent).toContain('Native Style Panels');
		expect(getContainer().textContent).toContain('Filter');
	});

	test('does not show hint when no delegated panels have suggestions', () => {
		renderComponent([makeSuggestion('shadow')]);

		expect(getContainer().textContent).not.toContain('Native Style Panels');
	});

	test('renders style variations separately', () => {
		const variation = {
			label: 'Outline',
			description: 'Outline style',
			panel: 'general',
			type: 'style_variation',
			attributeUpdates: { className: 'is-style-outline' },
			isCurrentStyle: false,
			isRecommended: true,
		};

		renderComponent([variation]);

		expect(getContainer().textContent).toContain('Outline');
		expect(getContainer().textContent).toContain('Style Variations');
	});

	test('disables the current style variation', () => {
		const variation = {
			label: 'Outline',
			description: 'Outline style',
			panel: 'general',
			type: 'style_variation',
			attributeUpdates: { className: 'is-style-outline' },
			isCurrentStyle: true,
		};

		renderComponent([variation]);

		const button = Array.from(getContainer().querySelectorAll('button')).find(
			(candidate) => candidate.textContent === 'Outline'
		);

		expect(button?.disabled).toBe(true);
		expect(mockApplySuggestion).not.toHaveBeenCalled();
	});

	test('returns null for empty suggestions', () => {
		renderComponent([]);
		expect(getContainer().innerHTML).toBe('');
	});

	test('shows inline apply feedback after a style row is applied', async () => {
		const suggestion = makeSuggestion('shadow', 'Use softer shadow');

		renderComponent([suggestion]);

		const applyButton = Array.from(
			getContainer().querySelectorAll('button')
		).find((button) => button.textContent === 'Apply');

		await act(async () => {
			applyButton.click();
			await Promise.resolve();
		});

		expect(mockApplySuggestion).toHaveBeenCalledWith(
			'block-1',
			suggestion,
			buildBlockRecommendationRequestSignature({
				clientId: 'block-1',
				prompt: 'Warm up the palette',
				contextSignature: 'live-context:block-1',
			}),
			{
				clientId: 'block-1',
				editorContext: {
					block: { name: 'core/paragraph' },
				},
				contextSignature: 'live-context:block-1',
				prompt: 'Warm up the palette',
			}
		);
		expect(
			getContainer().querySelector('.flavor-agent-inline-feedback')?.textContent
		).toBe('AppliedUse softer shadow.');
	});

	test('prefers the live block request metadata passed from the main panel when applying', async () => {
		const suggestion = makeSuggestion('shadow', 'Use softer shadow');
		const currentRequestInput = {
			clientId: 'block-1',
			editorContext: {
				block: { name: 'core/heading' },
			},
			contextSignature: 'live-context:block-1:prompt-drift',
			prompt: 'Make the block feel more editorial.',
		};

		renderComponent([suggestion], {
			currentRequestSignature: 'live-signature:block-1',
			currentRequestInput,
		});

		const applyButton = Array.from(
			getContainer().querySelectorAll('button')
		).find((button) => button.textContent === 'Apply');

		await act(async () => {
			applyButton.click();
			await Promise.resolve();
		});

		expect(mockApplySuggestion).toHaveBeenCalledWith(
			'block-1',
			suggestion,
			'live-signature:block-1',
			currentRequestInput
		);
	});

	test('renders block apply errors from the shared store notice path', () => {
		mockUseSelect.mockImplementation((callback) =>
			callback(() => ({
				getBlockApplyError: jest
					.fn()
					.mockReturnValue(
						'This result is stale. Refresh recommendations before applying it.'
					),
				getSurfaceStatusNotice: jest.fn((surface, options = {}) => {
					void surface;
					return options.applyError
						? {
								source: 'apply',
								tone: 'error',
								message: options.applyError,
								isDismissible: true,
						  }
						: null;
				}),
			}))
		);

		renderComponent([makeSuggestion('shadow', 'Use softer shadow')]);

		expect(getContainer().textContent).toContain(
			'This result is stale. Refresh recommendations before applying it.'
		);
	});

	test('shows stale projection guidance that points back to the main AI Recommendations panel', () => {
		renderComponent([makeSuggestion('shadow', 'Use softer shadow')], {
			isStale: true,
		});

		expect(getContainer().textContent).toContain(
			'These style suggestions reflect the last block request.'
		);
		expect(getContainer().textContent).toContain(
			'Refresh the main AI Recommendations panel before applying anything from Styles.'
		);
		expect(
			getContainer().querySelector(
				'.flavor-agent-scope-bar[role="status"][aria-live="polite"]'
			)
		).not.toBeNull();
		expect(
			Array.from(getContainer().querySelectorAll('button')).find(
				(button) => button.textContent === 'Refresh'
			)
		).toBeUndefined();
	});

	test('disables inline apply controls when stale suggestions are shown', () => {
		const variation = {
			label: 'Outline',
			description: 'Outline style',
			panel: 'general',
			type: 'style_variation',
			attributeUpdates: { className: 'is-style-outline' },
		};

		renderComponent([variation, makeSuggestion('shadow')], {
			isStale: true,
		});

		const buttons = Array.from(getContainer().querySelectorAll('button'));
		const outlineButton = buttons.find(
			(button) => button.textContent === 'Outline'
		);
		const applyButton = buttons.find(
			(button) => button.textContent === 'Apply'
		);

		expect(outlineButton?.disabled).toBe(true);
		expect(applyButton).toBeUndefined();
		expect(getContainer().textContent).not.toContain('Apply now');
		expect(getContainer().textContent).toContain(
			'Refresh in AI Recommendations'
		);
		expect(getContainer().textContent).toContain('Stale');
		expect(mockApplySuggestion).not.toHaveBeenCalled();
	});

	test('keeps row feedback visible across rerenders with cloned suggestions', async () => {
		const suggestion = makeSuggestion('shadow', 'Use softer shadow');

		renderComponent([suggestion]);

		const applyButton = Array.from(
			getContainer().querySelectorAll('button')
		).find((button) => button.textContent === 'Apply');

		await act(async () => {
			applyButton.click();
			await Promise.resolve();
		});

		renderComponent([{ ...suggestion }]);

		expect(
			getContainer().querySelector('.flavor-agent-inline-feedback')?.textContent
		).toBe('AppliedUse softer shadow.');
		const rerenderedApplyButton = getContainer().querySelector(
			'.flavor-agent-style-row__apply'
		);
		expect(rerenderedApplyButton?.disabled).toBe(true);
	});
});

describe('color preview swatch', () => {
	test('renders swatch for oklch preview value', () => {
		const suggestion = {
			label: 'Accent color',
			description: 'Use accent',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: 'oklch(0.7 0.15 240)',
		};

		renderComponent([suggestion]);

		const swatch = getContainer().querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect(swatch).not.toBeNull();
	});

	test('renders swatch for var() preview value', () => {
		const suggestion = {
			label: 'Accent var',
			description: 'Use var',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: 'var(--wp--preset--color--accent)',
		};

		renderComponent([suggestion]);

		const swatch = getContainer().querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect(swatch).not.toBeNull();
	});

	test('does not render swatch for non-color preview value', () => {
		const suggestion = {
			label: 'Font size',
			description: 'Bigger text',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: '1.5rem',
		};

		renderComponent([suggestion]);

		const swatch = getContainer().querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect(swatch).toBeNull();
	});
});
