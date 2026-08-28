import { __ } from '@wordpress/i18n';

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
		label: __( 'AI typography suggestions', 'flavor-agent' ),
		title: __( 'Typography', 'flavor-agent' ),
	},
	{
		group: 'dimensions',
		panel: 'dimensions',
		label: __( 'AI spacing suggestions', 'flavor-agent' ),
		title: __( 'Dimensions', 'flavor-agent' ),
	},
	{
		group: 'border',
		panel: 'border',
		label: __( 'AI border suggestions', 'flavor-agent' ),
		title: __( 'Border', 'flavor-agent' ),
	},
	{
		group: 'border',
		panel: 'shadow',
		label: __( 'AI shadow suggestions', 'flavor-agent' ),
		title: __( 'Shadow', 'flavor-agent' ),
	},
	{
		group: 'filter',
		panel: 'filter',
		label: __( 'AI filter suggestions', 'flavor-agent' ),
		title: __( 'Filter', 'flavor-agent' ),
	},
	{
		group: 'background',
		panel: 'background',
		label: __( 'AI background suggestions', 'flavor-agent' ),
		title: __( 'Background', 'flavor-agent' ),
	},
];

/**
 * Settings panels delegated to sub-panel chip groups.
 */
export const SETTINGS_PANEL_DELEGATIONS = [
	{
		group: 'position',
		panel: 'position',
		label: __( 'AI position suggestions', 'flavor-agent' ),
		title: __( 'Position', 'flavor-agent' ),
	},
	{
		group: 'advanced',
		panel: 'advanced',
		label: __( 'AI advanced suggestions', 'flavor-agent' ),
		title: __( 'Advanced', 'flavor-agent' ),
	},
	{
		group: 'bindings',
		panel: 'bindings',
		label: __( 'AI bindings suggestions', 'flavor-agent' ),
		title: __( 'Bindings', 'flavor-agent' ),
	},
	{
		group: 'list',
		panel: 'list',
		label: __( 'AI list view suggestions', 'flavor-agent' ),
		title: __( 'List View', 'flavor-agent' ),
	},
];
