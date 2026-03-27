const mockBlocksStore = {};
const mockBlockEditorStore = {};

jest.mock('@wordpress/data', () => ({
	select: jest.fn(),
}));

jest.mock('@wordpress/blocks', () => ({
	store: mockBlocksStore,
}));

jest.mock('@wordpress/block-editor', () => ({
	store: mockBlockEditorStore,
}));

const { select } = require('@wordpress/data');
const {
	introspectBlockType,
	resolveInspectorPanels,
} = require('../block-inspector');

describe('resolveInspectorPanels', () => {
	let blocksSelectors;
	let blockEditorSelectors;

	beforeEach(() => {
		blocksSelectors = {
			getBlockType: jest.fn(),
			getBlockStyles: jest.fn().mockReturnValue([]),
			getBlockVariations: jest.fn().mockReturnValue([]),
		};
		blockEditorSelectors = {
			getSettings: jest.fn().mockReturnValue({}),
		};

		select.mockImplementation((store) => {
			if (store === mockBlocksStore) {
				return blocksSelectors;
			}

			if (store === mockBlockEditorStore) {
				return blockEditorSelectors;
			}

			return {};
		});
	});

	test('maps current Gutenberg support keys to the same panels as the server collector', () => {
		expect(
			resolveInspectorPanels({
				customCSS: true,
				listView: true,
				typography: {
					fitText: true,
					textIndent: true,
				},
			})
		).toEqual({
			advanced: ['customCSS'],
			list: ['listView'],
			typography: ['typography.fitText', 'typography.textIndent'],
		});
	});

	test('adds the bindings panel when Gutenberg exposes bindable attributes for the block', () => {
		blocksSelectors.getBlockType.mockReturnValue({
			title: 'Paragraph',
			category: 'text',
			description: 'Paragraph block',
			supports: {},
			attributes: {
				content: {
					type: 'string',
					role: 'content',
				},
			},
		});
		blockEditorSelectors.getSettings.mockReturnValue({
			canUpdateBlockBindings: true,
			__experimentalBlockBindingsSupportedAttributes: {
				'core/paragraph': ['content'],
			},
		});

		const manifest = introspectBlockType('core/paragraph');

		expect(manifest.bindableAttributes).toEqual(['content']);
		expect(manifest.inspectorPanels.bindings).toEqual(['content']);
	});
});
