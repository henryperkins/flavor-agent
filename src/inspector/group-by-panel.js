import { getSuggestionPanel } from './suggestion-keys';

export default function groupByPanel( suggestions, excludedPanels = null ) {
	const grouped = {};

	for ( const suggestion of suggestions ) {
		const panel = getSuggestionPanel( suggestion );

		if ( excludedPanels?.has( panel ) ) {
			continue;
		}

		if ( ! grouped[ panel ] ) {
			grouped[ panel ] = [];
		}

		grouped[ panel ].push( suggestion );
	}

	return grouped;
}
