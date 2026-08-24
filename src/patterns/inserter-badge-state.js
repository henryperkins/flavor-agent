import { _n, __, sprintf } from '@wordpress/i18n';

const BASE_CLASS = 'flavor-agent-inserter-badge';
const ERROR_TOOLTIP_FALLBACK = __(
	'Pattern recommendation request failed.',
	'flavor-agent'
);

function getRecommendationCountLabel( count ) {
	return sprintf(
		/* translators: %d: number of pattern recommendations. */
		_n(
			'%d pattern recommendation',
			'%d pattern recommendations',
			count,
			'flavor-agent'
		),
		count
	);
}

/**
 * Build the inserter badge's render state.
 *
 * The badge is `pointer-events: none` so a native `title` tooltip could never
 * be shown; every signal it used to carry now lives in the accessible name,
 * which assistive tech does reach.
 *
 * @param {Object}   options
 * @param {string}   options.status            Pattern request status.
 * @param {Object[]} [options.recommendations] Renderable recommendations.
 * @param {string}   [options.badge]           Why the top recommendations matched.
 * @param {string}   [options.error]           Request error message.
 * @return {Object} Badge render state.
 */
export function getInserterBadgeState( {
	status,
	recommendations = [],
	badge = null,
	error = null,
} ) {
	const count = recommendations.length;

	if ( status === 'loading' ) {
		return {
			status: 'loading',
			count,
			content: null,
			ariaLabel: __( 'Finding pattern recommendations', 'flavor-agent' ),
			className: `${ BASE_CLASS } ${ BASE_CLASS }--loading`,
		};
	}

	if ( status === 'error' ) {
		return {
			status: 'error',
			count,
			content: '!',
			ariaLabel: sprintf(
				/* translators: %s: reason the pattern recommendation request failed. */
				__( 'Pattern recommendation error: %s', 'flavor-agent' ),
				error || ERROR_TOOLTIP_FALLBACK
			),
			className: `${ BASE_CLASS } ${ BASE_CLASS }--error`,
		};
	}

	if ( status === 'ready' && count > 0 ) {
		const countLabel = getRecommendationCountLabel( count );
		const availableLabel = sprintf(
			/* translators: %s: localized "<n> pattern recommendations" phrase. */
			__( '%s available', 'flavor-agent' ),
			countLabel
		);

		return {
			status: 'ready',
			count,
			content: String( count ),
			ariaLabel: badge
				? sprintf(
						/* translators: 1: "<n> pattern recommendations available". 2: why the top recommendations matched. */
						__( '%1$s. %2$s', 'flavor-agent' ),
						availableLabel,
						badge
				  )
				: availableLabel,
			className: `${ BASE_CLASS } ${ BASE_CLASS }--ready`,
		};
	}

	return {
		status: 'hidden',
		count,
		content: null,
		ariaLabel: null,
		className: null,
	};
}
