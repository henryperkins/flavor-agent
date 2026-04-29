export function buildRecommendedPatterns( recommendations, allowedPatterns ) {
	if (
		! Array.isArray( recommendations ) ||
		! Array.isArray( allowedPatterns )
	) {
		return [];
	}

	const allowedByName = new Map(
		allowedPatterns
			.filter(
				( pattern ) => typeof pattern?.name === 'string' && pattern.name
			)
			.map( ( pattern ) => [ pattern.name, pattern ] )
	);

	return recommendations
		.map( ( recommendation ) => {
			const pattern = allowedByName.get( recommendation?.name );

			if ( ! pattern ) {
				return null;
			}

			return {
				pattern,
				recommendation,
			};
		} )
		.filter( Boolean );
}

// Pick the first high-confidence recommendation reason for the toolbar badge.
export function getPatternBadgeReason( recommendations ) {
	const badge = recommendations.find(
		( recommendation ) => recommendation.score >= 0.9
	);

	return badge ? badge.reason : null;
}
