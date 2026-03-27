import { validateTemplateOperationSequence } from '../utils/template-operation-sequence';
import { inferTemplatePartArea } from '../utils/template-part-areas';

export const ENTITY_PART = 'part';
export const ENTITY_AREA = 'area';
export const ENTITY_PATTERN = 'pattern';

export const ENTITY_ACTION_SELECT_PART = 'select-part';
export const ENTITY_ACTION_SELECT_AREA = 'select-area';
export const ENTITY_ACTION_BROWSE_PATTERN = 'browse-pattern';

export const TEMPLATE_PART_REVIEW_ACTION = 'review-template-part';
export const PATTERN_BROWSE_ACTION = 'browse-pattern';
export const TEMPLATE_OPERATION_ASSIGN = 'assign_template_part';
export const TEMPLATE_OPERATION_REPLACE = 'replace_template_part';
export const TEMPLATE_OPERATION_INSERT_PATTERN = 'insert_pattern';

export function getSuggestionCardKey( suggestion = {}, index ) {
	return `${ suggestion.label || 'suggestion' }-${ index }`;
}

export function getTemplatePartKey( slug = '', area = '' ) {
	return `${ slug }|${ area }`;
}

export function getTemplateOperationKey( operation = {} ) {
	switch ( operation?.type ) {
		case TEMPLATE_OPERATION_ASSIGN:
			return `${ operation.type }|${ operation.slug || '' }|${
				operation.area || ''
			}`;
		case TEMPLATE_OPERATION_REPLACE:
			return `${ operation.type }|${ operation.currentSlug || '' }|${
				operation.slug || ''
			}|${ operation.area || '' }`;
		case TEMPLATE_OPERATION_INSERT_PATTERN:
			return `${ operation.type }|${ operation.patternName || '' }`;
		default:
			return `${ operation?.type || 'operation' }`;
	}
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

export function normalizeVisiblePatternNames( visiblePatternNames ) {
	if ( ! Array.isArray( visiblePatternNames ) ) {
		return null;
	}

	return Array.from( new Set( visiblePatternNames.filter( Boolean ) ) );
}

export function buildTemplateRecommendationContextSignature( {
	editorSlots,
	visiblePatternNames,
} = {} ) {
	const normalizedVisiblePatternNames =
		normalizeVisiblePatternNames( visiblePatternNames );

	return JSON.stringify( {
		editorSlots: editorSlots || null,
		visiblePatternNames: Array.isArray( normalizedVisiblePatternNames )
			? [ ...normalizedVisiblePatternNames ].sort()
			: null,
	} );
}

export function buildTemplateFetchInput( {
	templateRef,
	templateType,
	prompt,
	editorSlots,
	visiblePatternNames,
} ) {
	const input = { templateRef };
	const trimmedPrompt = prompt.trim();
	const normalizedVisiblePatternNames =
		normalizeVisiblePatternNames( visiblePatternNames );

	if ( templateType ) {
		input.templateType = templateType;
	}

	if ( trimmedPrompt ) {
		input.prompt = trimmedPrompt;
	}

	if ( editorSlots ) {
		input.editorSlots = editorSlots;
	}

	if ( Array.isArray( normalizedVisiblePatternNames ) ) {
		input.visiblePatternNames = normalizedVisiblePatternNames;
	}

	return input;
}

export function buildEditorTemplateSlotSnapshot( blocks = [], areaLookup ) {
	const assignedParts = [];
	const emptyAreas = new Set();
	const allowedAreas = new Set();

	const visitBlocks = ( branch = [] ) => {
		for ( const block of branch ) {
			if ( block?.name === 'core/template-part' ) {
				const attributes =
					block && typeof block.attributes === 'object'
						? block.attributes
						: {};
				const slug =
					typeof attributes?.slug === 'string'
						? attributes.slug.trim()
						: '';
				const area = inferTemplatePartArea( attributes, areaLookup );

				if ( area ) {
					allowedAreas.add( area );
				}

				if ( slug ) {
					assignedParts.push( {
						slug,
						area,
					} );
				} else if ( area ) {
					emptyAreas.add( area );
				}
			}

			if (
				Array.isArray( block?.innerBlocks ) &&
				block.innerBlocks.length
			) {
				visitBlocks( block.innerBlocks );
			}
		}
	};

	visitBlocks( blocks );

	assignedParts.sort( ( left, right ) => {
		const leftKey = `${ left.area || '' }|${ left.slug || '' }`;
		const rightKey = `${ right.area || '' }|${ right.slug || '' }`;

		return leftKey.localeCompare( rightKey );
	} );

	return {
		assignedParts,
		emptyAreas: [ ...emptyAreas ].sort(),
		allowedAreas: [ ...allowedAreas ].sort(),
	};
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
		const operations = Array.isArray( suggestion?.operations )
			? suggestion.operations
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

		for ( const operation of operations ) {
			if (
				operation?.currentSlug &&
				! map.has( operation.currentSlug )
			) {
				map.set( operation.currentSlug, {
					text: operation.currentSlug,
					type: ENTITY_PART,
					actionType: ENTITY_ACTION_SELECT_PART,
					slug: operation.currentSlug,
					area: operation.area || '',
					tooltip: `Select “${ operation.currentSlug }” block in editor`,
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

export function buildTemplateOperationViewModel(
	operation = {},
	patternTitleMap = {}
) {
	switch ( operation?.type ) {
		case TEMPLATE_OPERATION_ASSIGN:
			return {
				key: getTemplateOperationKey( operation ),
				type: TEMPLATE_OPERATION_ASSIGN,
				slug: operation?.slug || '',
				area: operation?.area || '',
				currentSlug: '',
				patternName: '',
				patternTitle: '',
				badgeLabel: 'Assign',
			};
		case TEMPLATE_OPERATION_REPLACE:
			return {
				key: getTemplateOperationKey( operation ),
				type: TEMPLATE_OPERATION_REPLACE,
				slug: operation?.slug || '',
				area: operation?.area || '',
				currentSlug: operation?.currentSlug || '',
				patternName: '',
				patternTitle: '',
				badgeLabel: 'Replace',
			};
		case TEMPLATE_OPERATION_INSERT_PATTERN:
			return {
				key: getTemplateOperationKey( operation ),
				type: TEMPLATE_OPERATION_INSERT_PATTERN,
				slug: '',
				area: '',
				currentSlug: '',
				patternName: operation?.patternName || '',
				patternTitle:
					patternTitleMap[ operation?.patternName ] ||
					operation?.patternName ||
					'',
				badgeLabel: 'Insert',
			};
		default:
			return null;
	}
}

export function buildTemplateSuggestionViewModel(
	suggestion = {},
	patternTitleMap = {}
) {
	const templateParts = Array.isArray( suggestion?.templateParts )
		? suggestion.templateParts
		: [];
	const executableOperations = validateTemplateOperationSequence(
		suggestion?.operations
	);
	const partReasonLookup = templateParts.reduce( ( acc, part ) => {
		const key = getTemplatePartKey( part?.slug || '', part?.area || '' );
		acc[ key ] = part?.reason || '';
		return acc;
	}, {} );
	const operations = executableOperations.ok
		? executableOperations.operations
				.map( ( operation ) =>
					buildTemplateOperationViewModel(
						operation,
						patternTitleMap
					)
				)
				.filter( Boolean )
		: [];
	const templateOperations = operations.filter(
		( operation ) =>
			operation.type === TEMPLATE_OPERATION_ASSIGN ||
			operation.type === TEMPLATE_OPERATION_REPLACE
	);
	const patternOperations = operations.filter(
		( operation ) => operation.type === TEMPLATE_OPERATION_INSERT_PATTERN
	);

	return {
		suggestionKey: suggestion?.suggestionKey || '',
		label: suggestion?.label || '',
		description: suggestion?.description || '',
		executionError: executableOperations.ok
			? ''
			: executableOperations.error || '',
		operations,
		templateParts: templateOperations.map( ( operation ) => ( {
			key: getTemplatePartKey( operation.slug, operation.area ),
			slug: operation.slug,
			area: operation.area,
			reason:
				partReasonLookup[
					getTemplatePartKey( operation.slug, operation.area )
				] || '',
			actionType: TEMPLATE_PART_REVIEW_ACTION,
			ctaLabel: 'Review in editor',
		} ) ),
		patternSuggestions: patternOperations.map( ( operation ) => ( {
			name: operation.patternName,
			title: operation.patternTitle,
			actionType: PATTERN_BROWSE_ACTION,
			ctaLabel: 'Browse pattern',
		} ) ),
		canApply: executableOperations.ok && operations.length > 0,
	};
}
