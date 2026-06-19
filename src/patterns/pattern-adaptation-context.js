/**
 * Client-only adaptation context.
 *
 * Reads nearby heading levels and container/sibling alignment from the live
 * editor at preview time. This signal must stay out of ranking requests and
 * server freshness signatures; it only feeds deterministic adaptation and the
 * local adaptation signature.
 */

const NEARBY_RANGE = 3;

function readLevel( editor, clientId ) {
	if ( editor.getBlockName?.( clientId ) !== 'core/heading' ) {
		return null;
	}

	const level = Number( editor.getBlockAttributes?.( clientId )?.level );

	return Number.isInteger( level ) && level >= 1 && level <= 6 ? level : null;
}

function readAlign( editor, clientId ) {
	const align = editor.getBlockAttributes?.( clientId )?.align;

	return typeof align === 'string' && align.trim() ? align.trim() : '';
}

export function buildPatternAdaptationContext(
	editor,
	{ inserterRootClientId = null, insertionIndex, siblingOrder } = {}
) {
	const empty = {
		precedingHeadingLevel: null,
		nearbyHeadingLevels: [],
		rootAlign: '',
		siblingAligns: [],
	};

	if ( ! editor ) {
		return empty;
	}

	const order = Array.isArray( siblingOrder )
		? siblingOrder
		: editor.getBlockOrder?.( inserterRootClientId ) || [];
	// Clamp into [0, order.length] so out-of-range inputs (e.g. -1 or a value
	// past the end) still produce a valid scan window instead of one that skips
	// every sibling and drops all nearby context.
	const insertIndex = Number.isInteger( insertionIndex )
		? Math.min( order.length, Math.max( 0, insertionIndex ) )
		: order.length;
	const start = Math.max( 0, insertIndex - NEARBY_RANGE );
	const end = Math.min( order.length, insertIndex + NEARBY_RANGE );

	const nearbyHeadingLevels = [];
	const siblingAligns = [];
	let precedingHeadingLevel = null;

	for ( let i = start; i < end; i++ ) {
		const clientId = order[ i ];
		const level = readLevel( editor, clientId );

		if ( level !== null ) {
			nearbyHeadingLevels.push( level );

			if ( i < insertIndex ) {
				precedingHeadingLevel = level;
			}
		}

		const align = readAlign( editor, clientId );
		if ( align ) {
			siblingAligns.push( align );
		}
	}

	return {
		precedingHeadingLevel,
		nearbyHeadingLevels,
		rootAlign: inserterRootClientId
			? readAlign( editor, inserterRootClientId )
			: '',
		siblingAligns,
	};
}
