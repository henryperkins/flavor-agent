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

	return {
		tone: 'info',
		message: __(
			'Suggestions are running without developer-docs grounding right now. They are still usable; grounding will return when the search backend is reachable.',
			'flavor-agent'
		),
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
