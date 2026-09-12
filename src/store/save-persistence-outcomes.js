export const CLIENT_SAVE_OUTCOME_EVENTS = Object.freeze( [
	'save_attempted',
	'save_failed',
] );

/**
 * Build client observations only; the server supplies IDs and verdicts.
 *
 * @param {Object} options                       Observation input.
 * @param {Object} options.document              Original apply scope.
 * @param {string} options.event                 Client save event.
 * @param {string} options.surface               Original apply surface.
 * @param {Object} options.target                Entity metadata without content.
 * @param {string} options.linkedApplyActivityId Original apply ID.
 * @param {string} options.saveOccurrenceId      Submitted save correlation ID.
 * @param {string} options.timestamp             Frozen client observation time.
 * @return {Object|null} Client telemetry, or null for unsupported observations.
 */
export function buildSavePersistenceOutcome( {
	document,
	event,
	surface,
	target = {},
	linkedApplyActivityId,
	saveOccurrenceId,
	timestamp = new Date().toISOString(),
} = {} ) {
	if (
		! CLIENT_SAVE_OUTCOME_EVENTS.includes( event ) ||
		! document?.scopeKey ||
		! surface ||
		typeof linkedApplyActivityId !== 'string' ||
		! linkedApplyActivityId ||
		typeof saveOccurrenceId !== 'string' ||
		! saveOccurrenceId
	) {
		return null;
	}

	return {
		type: 'recommendation_outcome',
		surface,
		document,
		target,
		linkedApplyActivityId,
		saveOccurrenceId,
		timestamp,
		after: { outcome: { event, observedAt: timestamp } },
	};
}
