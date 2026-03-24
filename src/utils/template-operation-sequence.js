const TEMPLATE_OPERATION_ASSIGN = 'assign_template_part';
const TEMPLATE_OPERATION_REPLACE = 'replace_template_part';
const TEMPLATE_OPERATION_INSERT_PATTERN = 'insert_pattern';
const TEMPLATE_PART_PLACEMENT_START = 'start';
const TEMPLATE_PART_PLACEMENT_END = 'end';

function toNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== ''
		? value.trim()
		: '';
}

export function validateTemplateOperationSequence( operations = [] ) {
	if ( ! Array.isArray( operations ) || operations.length === 0 ) {
		return {
			ok: false,
			error: 'This suggestion does not include any executable template operations.',
		};
	}

	const normalizedOperations = [];
	const mutatedAreas = new Set();
	let hasPatternInsert = false;

	for ( const rawOperation of operations ) {
		const type = toNonEmptyString( rawOperation?.type );

		switch ( type ) {
			case TEMPLATE_OPERATION_ASSIGN: {
				const slug = toNonEmptyString( rawOperation?.slug );
				const area = toNonEmptyString( rawOperation?.area );

				if ( ! slug || ! area ) {
					return {
						ok: false,
						error: 'Template-part assignments must include both a slug and an area.',
					};
				}

				if ( mutatedAreas.has( area ) ) {
					return {
						ok: false,
						error: `This suggestion targets the “${ area }” area more than once and cannot be applied automatically.`,
					};
				}

				mutatedAreas.add( area );
				normalizedOperations.push( {
					type,
					slug,
					area,
				} );
				break;
			}

			case TEMPLATE_OPERATION_REPLACE: {
				const currentSlug = toNonEmptyString(
					rawOperation?.currentSlug ?? rawOperation?.fromSlug
				);
				const slug = toNonEmptyString( rawOperation?.slug );
				const area = toNonEmptyString( rawOperation?.area );

				if ( ! currentSlug || ! slug || ! area ) {
					return {
						ok: false,
						error: 'Template-part replacements must include currentSlug, slug, and area.',
					};
				}

				if ( mutatedAreas.has( area ) ) {
					return {
						ok: false,
						error: `This suggestion targets the “${ area }” area more than once and cannot be applied automatically.`,
					};
				}

				mutatedAreas.add( area );
				normalizedOperations.push( {
					type,
					currentSlug,
					slug,
					area,
				} );
				break;
			}

			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const patternName = toNonEmptyString(
					rawOperation?.patternName ?? rawOperation?.name
				);

				if ( ! patternName ) {
					return {
						ok: false,
						error: 'Pattern insertions must include a pattern name.',
					};
				}

				if ( hasPatternInsert ) {
					return {
						ok: false,
						error: 'Only one pattern insertion can be applied automatically per suggestion.',
					};
				}

				hasPatternInsert = true;
				normalizedOperations.push( {
					type,
					patternName,
				} );
				break;
			}

			default:
				return {
					ok: false,
					error: `Unsupported template operation “${ type || 'unknown' }”.`,
				};
		}
	}

	return {
		ok: true,
		operations: normalizedOperations,
	};
}

export function validateTemplatePartOperationSequence( operations = [] ) {
	if ( ! Array.isArray( operations ) || operations.length === 0 ) {
		return {
			ok: false,
			error: 'This suggestion does not include any executable template-part operations.',
		};
	}

	const normalizedOperations = [];
	let hasPatternInsert = false;

	for ( const rawOperation of operations ) {
		const type = toNonEmptyString( rawOperation?.type );

		switch ( type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const patternName = toNonEmptyString(
					rawOperation?.patternName ?? rawOperation?.name
				);
				const placement = toNonEmptyString( rawOperation?.placement );

				if ( ! patternName || ! placement ) {
					return {
						ok: false,
						error: 'Template-part pattern insertions must include both a pattern name and placement.',
					};
				}

				if (
					placement !== TEMPLATE_PART_PLACEMENT_START &&
					placement !== TEMPLATE_PART_PLACEMENT_END
				) {
					return {
						ok: false,
						error: 'Template-part pattern insertions must use an explicit start or end placement.',
					};
				}

				if ( hasPatternInsert ) {
					return {
						ok: false,
						error: 'Only one template-part pattern insertion can be applied automatically per suggestion.',
					};
				}

				hasPatternInsert = true;
				normalizedOperations.push( {
					type,
					patternName,
					placement,
				} );
				break;
			}

			default:
				return {
					ok: false,
					error: `Unsupported template-part operation “${
						type || 'unknown'
					}”.`,
				};
		}
	}

	return {
		ok: true,
		operations: normalizedOperations,
	};
}

export {
	TEMPLATE_OPERATION_ASSIGN,
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_OPERATION_REPLACE,
	TEMPLATE_PART_PLACEMENT_END,
	TEMPLATE_PART_PLACEMENT_START,
};
