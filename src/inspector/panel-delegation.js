/**
 * Panel delegation constants.
 *
 * Panels listed here are rendered as SuggestionChips inside their
 * dedicated InspectorControls groups (SubPanelSuggestions in
 * InspectorInjector.js). The main StylesRecommendations and
 * SettingsRecommendations panels must exclude these to prevent
 * duplicate rendering.
 *
 * Keep in sync with the SubPanelSuggestions list in InspectorInjector.js.
 */

/**
 * Style panels delegated to sub-panel chip groups.
 *
 * Maps to: color, typography, dimensions, border, filter, background
 * groups in InspectorInjector.js SubPanelSuggestions.
 */
export const DELEGATED_STYLE_PANELS = new Set( [
	'color',
	'typography',
	'dimensions',
	'border',
	'filter',
	'background',
] );

/**
 * Settings panels delegated to sub-panel chip groups.
 *
 * Maps to: position, advanced, bindings groups in
 * InspectorInjector.js SubPanelSuggestions.
 */
export const DELEGATED_SETTINGS_PANELS = new Set( [
	'position',
	'advanced',
	'bindings',
] );

/**
 * @param {string} panel Panel key.
 * @return {boolean} Whether this panel is rendered as sub-panel style chips.
 */
export function isDelegatedStylePanel( panel ) {
	return DELEGATED_STYLE_PANELS.has( panel );
}

/**
 * @param {string} panel Panel key.
 * @return {boolean} Whether this panel is rendered as sub-panel settings chips.
 */
export function isDelegatedSettingsPanel( panel ) {
	return DELEGATED_SETTINGS_PANELS.has( panel );
}
