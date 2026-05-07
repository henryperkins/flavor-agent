export const ACTIVITY_TITLE_MAX_LENGTH = 96;

export function truncateActivityTitle(
	value = '',
	maxLength = ACTIVITY_TITLE_MAX_LENGTH
) {
	const normalized = String( value || '' )
		.trim()
		.replace( /\s+/g, ' ' );

	if ( normalized.length <= maxLength ) {
		return normalized;
	}

	const suffix = '...';
	const limit = Math.max( 1, maxLength - suffix.length );
	const hardCut = normalized.slice( 0, limit ).trimEnd();
	const wordBoundary = hardCut.lastIndexOf( ' ' );
	const boundaryFloor = Math.floor( limit * 0.65 );
	const prefix =
		wordBoundary >= boundaryFloor
			? hardCut.slice( 0, wordBoundary )
			: hardCut;

	return `${ prefix.replace( /[.,;:!?-]+$/, '' ) }${ suffix }`;
}
