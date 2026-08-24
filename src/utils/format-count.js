/**
 * Flavor Agent — shared count/label formatting helpers.
 *
 * `formatCount` takes a countable-noun *token* rather than a display noun so
 * every plural form goes through a literal `_n()` call that `wp i18n make-pot`
 * can extract. Appending an "s" to a runtime string is only correct in English
 * and produces wrong output in every language with non-suffix plural rules.
 */
import { _n, sprintf } from '@wordpress/i18n';

/**
 * Countable nouns rendered in count pills and status text.
 *
 * Keys are stable tokens passed as `countNoun`; values build the localized
 * "<n> <noun>" string. Each entry keeps its `_n()` arguments literal so the
 * strings stay extractable.
 *
 * @type {Object<string, (count: number) => string>}
 */
const COUNT_FORMATTERS = Object.freeze( {
	action: ( count ) =>
		sprintf(
			/* translators: %d: number of AI actions. */
			_n( '%d action', '%d actions', count, 'flavor-agent' ),
			count
		),
	block: ( count ) =>
		sprintf(
			/* translators: %d: number of blocks. */
			_n( '%d block', '%d blocks', count, 'flavor-agent' ),
			count
		),
	change: ( count ) =>
		sprintf(
			/* translators: %d: number of changes. */
			_n( '%d change', '%d changes', count, 'flavor-agent' ),
			count
		),
	idea: ( count ) =>
		sprintf(
			/* translators: %d: number of ideas. */
			_n( '%d idea', '%d ideas', count, 'flavor-agent' ),
			count
		),
	item: ( count ) =>
		sprintf(
			/* translators: %d: number of items. */
			_n( '%d item', '%d items', count, 'flavor-agent' ),
			count
		),
	note: ( count ) =>
		sprintf(
			/* translators: %d: number of notes. */
			_n( '%d note', '%d notes', count, 'flavor-agent' ),
			count
		),
	operation: ( count ) =>
		sprintf(
			/* translators: %d: number of operations. */
			_n( '%d operation', '%d operations', count, 'flavor-agent' ),
			count
		),
	'override-ready block': ( count ) =>
		sprintf(
			/* translators: %d: number of blocks that support pattern overrides. */
			_n(
				'%d override-ready block',
				'%d override-ready blocks',
				count,
				'flavor-agent'
			),
			count
		),
	part: ( count ) =>
		sprintf(
			/* translators: %d: number of template parts. */
			_n( '%d part', '%d parts', count, 'flavor-agent' ),
			count
		),
	pattern: ( count ) =>
		sprintf(
			/* translators: %d: number of patterns. */
			_n( '%d pattern', '%d patterns', count, 'flavor-agent' ),
			count
		),
	'ranked pattern': ( count ) =>
		sprintf(
			/* translators: %d: number of ranked patterns. */
			_n(
				'%d ranked pattern',
				'%d ranked patterns',
				count,
				'flavor-agent'
			),
			count
		),
	recommendation: ( count ) =>
		sprintf(
			/* translators: %d: number of recommendations. */
			_n(
				'%d recommendation',
				'%d recommendations',
				count,
				'flavor-agent'
			),
			count
		),
	suggestion: ( count ) =>
		sprintf(
			/* translators: %d: number of suggestions. */
			_n( '%d suggestion', '%d suggestions', count, 'flavor-agent' ),
			count
		),
	'synced pattern': ( count ) =>
		sprintf(
			/* translators: %d: number of synced patterns. */
			_n(
				'%d synced pattern',
				'%d synced patterns',
				count,
				'flavor-agent'
			),
			count
		),
	'template operation': ( count ) =>
		sprintf(
			/* translators: %d: number of template operations. */
			_n(
				'%d template operation',
				'%d template operations',
				count,
				'flavor-agent'
			),
			count
		),
	'template-part operation': ( count ) =>
		sprintf(
			/* translators: %d: number of template-part operations. */
			_n(
				'%d template-part operation',
				'%d template-part operations',
				count,
				'flavor-agent'
			),
			count
		),
	'viewport constraint': ( count ) =>
		sprintf(
			/* translators: %d: number of viewport constraints. */
			_n(
				'%d viewport constraint',
				'%d viewport constraints',
				count,
				'flavor-agent'
			),
			count
		),
} );

export const COUNT_NOUNS = Object.freeze( Object.keys( COUNT_FORMATTERS ) );

/**
 * Format a localized "<n> <noun>" label.
 *
 * @param {number} count Non-negative, finite count.
 * @param {string} noun  Countable-noun token; see {@link COUNT_NOUNS}.
 * @return {string} Localized label, or an empty string for an unusable input.
 */
export function formatCount( count, noun ) {
	if ( ! Number.isFinite( count ) || count < 0 || ! noun ) {
		return '';
	}

	const formatter = COUNT_FORMATTERS[ noun ];

	if ( formatter ) {
		return formatter( count );
	}

	// Unregistered noun: emit the count and the raw token rather than dropping
	// the label. Deliberately untranslated — a bare "%d %s" is meaningless to a
	// translator, and this path only fires on a developer mistake. Register the
	// token in COUNT_FORMATTERS to make it translatable.
	return `${ count } ${ noun }`;
}

export function humanizeString( value ) {
	return String( value ?? '' )
		.split( /[-_/|\s]+/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

export function joinClassNames( ...values ) {
	return values.filter( Boolean ).join( ' ' );
}
