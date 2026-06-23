import { __ } from '@wordpress/i18n';

/**
 * Docs grounding is best-effort prompt context: the only signal worth
 * surfacing is "this result ran without grounding." Trust and currency of
 * the corpus are owned by scripts/update-docs-ai-search.js at ingestion.
 *
 * @param {Object|null} docsGrounding `docsGrounding` summary from an ability response.
 * @return {Object|null} A single soft notice descriptor, or null when grounded.
 */
export function deriveDocsGroundingWarning( docsGrounding ) {
	if ( ! docsGrounding || docsGrounding.available !== false ) {
		return null;
	}

	const reason =
		typeof docsGrounding.reason === 'string' ? docsGrounding.reason : '';

	return {
		tone: 'info',
		reason,
		errorCode:
			typeof docsGrounding.errorCode === 'string'
				? docsGrounding.errorCode
				: '',
		message: getDocsGroundingUnavailableMessage( reason ),
	};
}

/**
 * @param {Object|null} warning Result of deriveDocsGroundingWarning.
 * @return {string} User-facing message, or '' when there is nothing to show.
 */
export function getDocsGroundingWarningMessage( warning ) {
	if ( ! warning || typeof warning !== 'object' ) {
		return '';
	}

	return typeof warning.message === 'string' ? warning.message : '';
}

function getDocsGroundingUnavailableMessage( reason ) {
	switch ( reason ) {
		case 'backend_unreachable':
			return __(
				'Suggestions are running without developer-docs grounding right now because the docs search request failed. They are still usable; grounding will return when the search backend is reachable.',
				'flavor-agent'
			);
		case 'live_no_results':
			return __(
				'Suggestions are running without developer-docs grounding right now because this request did not return any trusted Developer Docs matches. They are still usable.',
				'flavor-agent'
			);
		case 'cached_no_results':
			return __(
				'Suggestions are running without developer-docs grounding right now because this request reused a cached no-match Developer Docs result. They are still usable.',
				'flavor-agent'
			);
		case 'signature_cache_miss':
			return __(
				'Suggestions are running without developer-docs grounding right now because this review check is cache-only and no docs result was cached for it. They are still usable.',
				'flavor-agent'
			);
		case 'query_empty':
			return __(
				'Suggestions are running without developer-docs grounding right now because this request did not produce a Developer Docs query. They are still usable.',
				'flavor-agent'
			);
		case 'unconfigured':
			return __(
				'Suggestions are running without developer-docs grounding right now because the built-in Developer Docs endpoint is not configured. They are still usable.',
				'flavor-agent'
			);
		default:
			return __(
				'Suggestions are running without developer-docs grounding right now. They are still usable; grounding will return when the search backend is reachable.',
				'flavor-agent'
			);
	}
}
