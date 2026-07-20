/**
 * Panel delegation constants.
 *
 * Panels listed here are rendered as SuggestionChips inside their
 * dedicated InspectorControls groups (SubPanelSuggestions in
 * InspectorInjector.js). These chips are passive mirrors of the
 * main block panel's executable results.
 *
 * Keep in sync with the SubPanelSuggestions list in InspectorInjector.js.
 */

/**
 * Shared delegated Inspector panel metadata.
 *
 * The injector renders these as sub-panel chip groups while the main
 * block recommendation panel owns all executable apply actions.
 */
export const STYLE_PANEL_DELEGATIONS = [
	{
		group: 'typography',
		panel: 'typography',
		label: 'AI typography suggestions',
		title: 'Typography',
	},
	{
		group: 'dimensions',
		panel: 'dimensions',
		label: 'AI spacing suggestions',
		title: 'Dimensions',
	},
	{
		group: 'border',
		panel: 'border',
		label: 'AI border suggestions',
		title: 'Border',
	},
	{
		group: 'border',
		panel: 'shadow',
		label: 'AI shadow suggestions',
		title: 'Shadow',
	},
	{
		group: 'filter',
		panel: 'filter',
		label: 'AI filter suggestions',
		title: 'Filter',
	},
	{
		group: 'background',
		panel: 'background',
		label: 'AI background suggestions',
		title: 'Background',
	},
];

/**
 * Settings panels delegated to sub-panel chip groups.
 */
export const SETTINGS_PANEL_DELEGATIONS = [
	{
		group: 'position',
		panel: 'position',
		label: 'AI position suggestions',
		title: 'Position',
	},
	{
		group: 'advanced',
		panel: 'advanced',
		label: 'AI advanced suggestions',
		title: 'Advanced',
	},
	{
		group: 'bindings',
		panel: 'bindings',
		label: 'AI bindings suggestions',
		title: 'Bindings',
	},
	{
		group: 'list',
		panel: 'list',
		label: 'AI list view suggestions',
		title: 'List View',
	},
];
