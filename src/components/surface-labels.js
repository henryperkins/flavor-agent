/**
 * Flavor Agent — shared surface tone vocabulary.
 *
 * `tone` is a stable, never-rendered token. Both the pill CSS class and the
 * display label derive from it, so translating a label can never silently
 * change — or drop — the state styling. Never key styling off rendered text:
 * that couples presentation to the active locale.
 *
 * Components accept `tone` (token) and an optional `toneLabel` override for
 * the rare surface that needs custom pill text; when `toneLabel` is omitted it
 * resolves from the token via `getToneLabel()`.
 */
import { __ } from '@wordpress/i18n';

export const SURFACE_TONES = Object.freeze( {
	APPLY: 'apply',
	REVIEW: 'review',
	MANUAL: 'manual',
	ADVISORY: 'advisory',
	EXECUTABLE: 'executable',
	FRESH: 'fresh',
	STALE: 'stale',
} );

const TONE_PILL_CLASS_NAMES = Object.freeze( {
	[ SURFACE_TONES.APPLY ]: 'flavor-agent-pill--apply',
	[ SURFACE_TONES.REVIEW ]: 'flavor-agent-pill--review',
	[ SURFACE_TONES.MANUAL ]: 'flavor-agent-pill--manual',
	[ SURFACE_TONES.ADVISORY ]: 'flavor-agent-pill--manual',
	[ SURFACE_TONES.FRESH ]: 'flavor-agent-pill--fresh',
	[ SURFACE_TONES.STALE ]: 'flavor-agent-pill--stale',
} );

/**
 * Resolve the pill modifier class for a tone token.
 *
 * @param {string} [tone] Tone token from {@link SURFACE_TONES}.
 * @return {string} Modifier class name, or an empty string for an unknown tone.
 */
export function getTonePillClassName( tone = '' ) {
	return TONE_PILL_CLASS_NAMES[ tone ] || '';
}

export const APPLY_NOW_LABEL = __( 'Apply now', 'flavor-agent' );
export const MANUAL_IDEAS_LABEL = __( 'Manual ideas', 'flavor-agent' );
export const REVIEW_LANE_LABEL = __( 'Review first', 'flavor-agent' );
export const REVIEW_SECTION_TITLE = __( 'Review Before Apply', 'flavor-agent' );
export const ADVISORY_ONLY_LABEL = __( 'Advisory only', 'flavor-agent' );
export const EXECUTABLE_LABEL = __( 'Executable', 'flavor-agent' );
export const CURRENT_STATUS_LABEL = __( 'Current', 'flavor-agent' );
export const STALE_STATUS_LABEL = __( 'Stale', 'flavor-agent' );
export const REFRESH_ACTION_LABEL = __( 'Refresh', 'flavor-agent' );

const TONE_LABELS = Object.freeze( {
	[ SURFACE_TONES.APPLY ]: APPLY_NOW_LABEL,
	[ SURFACE_TONES.REVIEW ]: REVIEW_LANE_LABEL,
	[ SURFACE_TONES.MANUAL ]: MANUAL_IDEAS_LABEL,
	[ SURFACE_TONES.ADVISORY ]: ADVISORY_ONLY_LABEL,
	[ SURFACE_TONES.EXECUTABLE ]: EXECUTABLE_LABEL,
	[ SURFACE_TONES.FRESH ]: CURRENT_STATUS_LABEL,
	[ SURFACE_TONES.STALE ]: STALE_STATUS_LABEL,
} );

/**
 * Resolve the translated display label for a tone token.
 *
 * @param {string} [tone] Tone token from {@link SURFACE_TONES}.
 * @return {string} Display label, or an empty string for an unknown tone.
 */
export function getToneLabel( tone = '' ) {
	return TONE_LABELS[ tone ] || '';
}
