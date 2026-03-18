const BASE_CLASS = 'flavor-agent-inserter-badge';
const ERROR_TOOLTIP_FALLBACK = 'Pattern recommendation request failed.';

function getRecommendationLabel( count ) {
	return `pattern recommendation${ count === 1 ? '' : 's' }`;
}

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
			tooltip: 'Finding patterns...',
			ariaLabel: 'Finding pattern recommendations',
			className: `${ BASE_CLASS } ${ BASE_CLASS }--loading`,
		};
	}

	if ( status === 'error' ) {
		return {
			status: 'error',
			count,
			content: '!',
			tooltip: error || ERROR_TOOLTIP_FALLBACK,
			ariaLabel: 'Pattern recommendation error',
			className: `${ BASE_CLASS } ${ BASE_CLASS }--error`,
		};
	}

	if ( status === 'ready' && count > 0 ) {
		const label = getRecommendationLabel( count );

		return {
			status: 'ready',
			count,
			content: String( count ),
			tooltip: badge || `${ count } ${ label }`,
			ariaLabel: `${ count } ${ label } available`,
			className: `${ BASE_CLASS } ${ BASE_CLASS }--ready`,
		};
	}

	return {
		status: 'hidden',
		count,
		content: null,
		tooltip: null,
		ariaLabel: null,
		className: null,
	};
}
