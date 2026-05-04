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

const SOURCE_SIGNAL_LABELS = {
	qdrant_semantic: 'Semantic match',
	qdrant_structural: 'Structural fit',
	cloudflare_ai_search: 'AI Search match',
	llm_ranker: 'Model ranked',
};

function normalizeStringList( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return value
		.map( ( item ) =>
			typeof item === 'string' && item.trim() ? item.trim() : ''
		)
		.filter( Boolean );
}

function addUniqueLabel( labels, label ) {
	if ( label && ! labels.includes( label ) ) {
		labels.push( label );
	}
}

export function getPatternRecommendationInsights( pattern, recommendation ) {
	const labels = [];
	const sourceSignals = normalizeStringList(
		recommendation?.ranking?.sourceSignals
	);

	sourceSignals.forEach( ( signal ) => {
		addUniqueLabel( labels, SOURCE_SIGNAL_LABELS[ signal ] );
	} );

	const category =
		normalizeStringList( recommendation?.categories )[ 0 ] ||
		normalizeStringList( pattern?.categories )[ 0 ] ||
		'';

	if ( category ) {
		addUniqueLabel( labels, `Category: ${ category }` );
	}

	addUniqueLabel( labels, 'Allowed here' );

	const rankingHint = recommendation?.ranking?.rankingHint || {};
	if (
		rankingHint.matchesNearbyBlock ||
		rankingHint.matchesNearbyCustomBlock ||
		Number( rankingHint.siblingOverrideCount || 0 ) > 0
	) {
		addUniqueLabel( labels, 'Nearby block fit' );
	}

	return labels;
}

// Pick the first high-confidence recommendation reason for the toolbar badge.
export function getPatternBadgeReason( recommendations ) {
	const badge = recommendations.find(
		( recommendation ) => recommendation.score >= 0.9
	);

	return badge ? badge.reason : null;
}
