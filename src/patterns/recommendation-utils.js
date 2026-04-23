// Pick the first high-confidence recommendation reason for the toolbar badge.
export function getPatternBadgeReason( recommendations ) {
	const badge = recommendations.find(
		( recommendation ) => recommendation.score >= 0.9
	);

	return badge ? badge.reason : null;
}
