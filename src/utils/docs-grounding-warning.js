const COVERAGE_WARNING_STATUSES = new Set( [
	'missing-current-release-cycle',
] );

function getString( value ) {
	return typeof value === 'string' ? value : '';
}

export function normalizeDocsGroundingWarning( docsGrounding ) {
	if (
		! docsGrounding ||
		typeof docsGrounding !== 'object' ||
		docsGrounding.status === 'unavailable'
	) {
		return null;
	}

	const status = getString( docsGrounding.status );
	const coverageStatus = getString( docsGrounding.coverage?.status );
	const hasStatusWarning = status === 'stale' || status === 'degraded';
	const hasCoverageWarning =
		Boolean( coverageStatus ) &&
		! [ 'current', 'unknown' ].includes( coverageStatus );

	if ( ! hasStatusWarning && ! hasCoverageWarning ) {
		return null;
	}

	return {
		status,
		message: getString( docsGrounding.message ),
		coverageStatus,
		coverageMessage:
			getString( docsGrounding.coverage?.message ) ||
			getString( docsGrounding.coverage?.errorMessage ),
		source: getString( docsGrounding.source ),
		checkedAt:
			getString( docsGrounding.checkedAt ) ||
			getString( docsGrounding.coverage?.checkedAt ),
	};
}

export function getDocsGroundingWarningMessage( warning ) {
	if ( ! warning || typeof warning !== 'object' ) {
		return '';
	}

	if ( COVERAGE_WARNING_STATUSES.has( warning.coverageStatus ) ) {
		return 'Developer Docs grounding is trusted, but current release-cycle sources have not been confirmed. Review current WordPress docs before applying.';
	}

	if ( warning.status === 'stale' ) {
		return 'Developer Docs grounding is stale. Review current WordPress docs before applying.';
	}

	if ( warning.status === 'degraded' ) {
		return 'Developer Docs grounding is incomplete. Review current WordPress docs before applying.';
	}

	return 'Developer Docs grounding is incomplete. Review current WordPress docs before applying.';
}
