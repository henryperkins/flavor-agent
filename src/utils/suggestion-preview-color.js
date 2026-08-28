const HEX_PREVIEW_COLOR = /^#(?:[\da-f]{3}|[\da-f]{4}|[\da-f]{6}|[\da-f]{8})$/i;

export function normalizeSuggestionPreviewColor( value ) {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const color = value.trim();

	return HEX_PREVIEW_COLOR.test( color ) ? color.toLowerCase() : null;
}
