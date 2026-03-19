export const ENTITY_PART = 'part';
export const ENTITY_AREA = 'area';
export const ENTITY_PATTERN = 'pattern';

export const ENTITY_ACTION_SELECT_PART = 'select-part';
export const ENTITY_ACTION_SELECT_AREA = 'select-area';
export const ENTITY_ACTION_BROWSE_PATTERN = 'browse-pattern';

export const TEMPLATE_PART_REVIEW_ACTION = 'review-template-part';
export const PATTERN_BROWSE_ACTION = 'browse-pattern';

export function getSuggestionCardKey( suggestion = {}, index ) {
	return `${ suggestion.label || 'suggestion' }-${ index }`;
}

export function getTemplatePartKey( slug = '', area = '' ) {
	return `${ slug }|${ area }`;
}

export function formatCount( count, noun ) {
	return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}

export function formatTemplateTypeLabel( templateType ) {
	if ( ! templateType ) {
		return 'Current Template';
	}

	return `${ templateType
		.split( '-' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' ) } Template`;
}

export function buildTemplateFetchInput( {
	templateRef,
	templateType,
	prompt,
} ) {
	const input = { templateRef };
	const trimmedPrompt = prompt.trim();

	if ( templateType ) {
		input.templateType = templateType;
	}

	if ( trimmedPrompt ) {
		input.prompt = trimmedPrompt;
	}

	return input;
}

export function buildEntityMap( recommendations = [], patternTitleMap = {} ) {
	const map = new Map();

	for ( const suggestion of recommendations ) {
		const templateParts = Array.isArray( suggestion?.templateParts )
			? suggestion.templateParts
			: [];
		const patternSuggestions = Array.isArray(
			suggestion?.patternSuggestions
		)
			? suggestion.patternSuggestions
			: [];

		for ( const part of templateParts ) {
			if ( part?.slug && ! map.has( part.slug ) ) {
				map.set( part.slug, {
					text: part.slug,
					type: ENTITY_PART,
					actionType: ENTITY_ACTION_SELECT_PART,
					slug: part.slug,
					area: part.area || '',
					tooltip: `Select “${ part.slug }” block in editor`,
				} );
			}

			if ( part?.area && ! map.has( part.area ) ) {
				map.set( part.area, {
					text: part.area,
					type: ENTITY_AREA,
					actionType: ENTITY_ACTION_SELECT_AREA,
					area: part.area,
					tooltip: `Select “${ part.area }” area in editor`,
				} );
			}
		}

		for ( const name of patternSuggestions ) {
			if ( ! name ) {
				continue;
			}

			const title = patternTitleMap[ name ] || name;

			if ( ! map.has( name ) ) {
				map.set( name, {
					text: name,
					type: ENTITY_PATTERN,
					actionType: ENTITY_ACTION_BROWSE_PATTERN,
					name,
					filterValue: title,
					tooltip: `Browse “${ title }” in pattern inserter`,
				} );
			}

			if ( title !== name && ! map.has( title ) ) {
				map.set( title, {
					text: title,
					type: ENTITY_PATTERN,
					actionType: ENTITY_ACTION_BROWSE_PATTERN,
					name,
					filterValue: title,
					tooltip: `Browse “${ title }” in pattern inserter`,
				} );
			}
		}
	}

	return Array.from( map.values() ).sort(
		( a, b ) => b.text.length - a.text.length
	);
}

export function buildTemplateSuggestionViewModel(
	suggestion = {},
	patternTitleMap = {}
) {
	const templateParts = Array.isArray( suggestion?.templateParts )
		? suggestion.templateParts
		: [];
	const patternSuggestions = Array.isArray( suggestion?.patternSuggestions )
		? suggestion.patternSuggestions
		: [];

	return {
		label: suggestion?.label || '',
		description: suggestion?.description || '',
		templateParts: templateParts.map( ( part ) => ( {
			key: getTemplatePartKey( part?.slug || '', part?.area || '' ),
			slug: part?.slug || '',
			area: part?.area || '',
			reason: part?.reason || '',
			actionType: TEMPLATE_PART_REVIEW_ACTION,
			ctaLabel: 'Review in editor',
		} ) ),
		patternSuggestions: patternSuggestions
			.filter( Boolean )
			.map( ( name ) => ( {
				name,
				title: patternTitleMap[ name ] || name,
				actionType: PATTERN_BROWSE_ACTION,
				ctaLabel: 'Browse pattern',
			} ) ),
	};
}
