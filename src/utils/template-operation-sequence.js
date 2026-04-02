const TEMPLATE_OPERATION_ASSIGN = 'assign_template_part';
const TEMPLATE_OPERATION_REPLACE = 'replace_template_part';
const TEMPLATE_OPERATION_INSERT_PATTERN = 'insert_pattern';
const TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN =
	'replace_block_with_pattern';
const TEMPLATE_OPERATION_REMOVE_BLOCK = 'remove_block';
const TEMPLATE_PART_PLACEMENT_START = 'start';
const TEMPLATE_PART_PLACEMENT_END = 'end';
const TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH = 'before_block_path';
const TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH = 'after_block_path';

function toNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function normalizeBlockPath( value ) {
	if ( ! Array.isArray( value ) || value.length === 0 ) {
		return null;
	}

	const path = [];

	for ( const segment of value ) {
		const normalizedSegment = Number( segment );

		if (
			! Number.isInteger( normalizedSegment ) ||
			normalizedSegment < 0
		) {
			return null;
		}

		path.push( normalizedSegment );
	}

	return path;
}

function normalizeExpectedTarget( value ) {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return null;
	}

	const expectedTarget = {
		name:
			typeof value.name === 'string' && value.name.trim() !== ''
				? value.name.trim()
				: '',
		label:
			typeof value.label === 'string' && value.label.trim() !== ''
				? value.label.trim()
				: '',
		childCount: Number.isInteger( Number( value.childCount ) )
			? Number( value.childCount )
			: 0,
	};

	if ( value.attributes && typeof value.attributes === 'object' ) {
		expectedTarget.attributes = Object.fromEntries(
			Object.entries( value.attributes ).filter(
				( [ , attributeValue ] ) =>
					typeof attributeValue === 'string' ||
					typeof attributeValue === 'number' ||
					typeof attributeValue === 'boolean'
			)
		);
	}

	if (
		value.slot &&
		typeof value.slot === 'object' &&
		! Array.isArray( value.slot )
	) {
		expectedTarget.slot = {
			slug:
				typeof value.slot.slug === 'string'
					? value.slot.slug.trim()
					: '',
			area:
				typeof value.slot.area === 'string'
					? value.slot.area.trim()
					: '',
			isEmpty: Boolean( value.slot.isEmpty ),
		};
	}

	if ( ! expectedTarget.name ) {
		return null;
	}

	return expectedTarget;
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
	const anchoredPlacements = new Set( [
		TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH,
		TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH,
	] );
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
				const hasTargetPath = Object.prototype.hasOwnProperty.call(
					rawOperation ?? {},
					'targetPath'
				);
				const patternName = toNonEmptyString(
					rawOperation?.patternName ?? rawOperation?.name
				);
				const placement = toNonEmptyString( rawOperation?.placement );
				const targetPath = normalizeBlockPath(
					rawOperation?.targetPath
				);
				const expectedTarget = normalizeExpectedTarget(
					rawOperation?.expectedTarget
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
				if ( hasTargetPath && ! targetPath ) {
					return {
						ok: false,
						error: 'Template pattern insertions that include a targetPath must provide a non-empty array of non-negative indexes.',
					};
				}

				if ( ! placement ) {
					return {
						ok: false,
						error: 'Template pattern insertions must include a placement.',
					};
				}

				if (
					placement &&
					placement !== TEMPLATE_PART_PLACEMENT_START &&
					placement !== TEMPLATE_PART_PLACEMENT_END &&
					placement !== TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH &&
					placement !== TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
				) {
					return {
						ok: false,
						error: 'Template pattern insertions must use start, end, before_block_path, or after_block_path.',
					};
				}

				if ( anchoredPlacements.has( placement ) && ! targetPath ) {
					return {
						ok: false,
						error: 'Anchored template pattern insertions must include a targetPath.',
					};
				}

				const normalizedOperation = {
					type,
					patternName,
				};

				if ( placement ) {
					normalizedOperation.placement = placement;
				}

				if ( targetPath && anchoredPlacements.has( placement ) ) {
					normalizedOperation.targetPath = targetPath;
				}

				if ( expectedTarget && anchoredPlacements.has( placement ) ) {
					normalizedOperation.expectedTarget = expectedTarget;
				}

				normalizedOperations.push( normalizedOperation );
				break;
			}

			default:
				return {
					ok: false,
					error: `Unsupported template operation “${
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

export function validateTemplatePartOperationSequence( operations = [] ) {
	if ( ! Array.isArray( operations ) || operations.length === 0 ) {
		return {
			ok: false,
			error: 'This suggestion does not include any executable template-part operations.',
		};
	}

	if ( operations.length > 3 ) {
		return {
			ok: false,
			error: 'Template-part suggestions can apply at most 3 operations automatically.',
		};
	}

	const normalizedOperations = [];
	const anchoredPlacements = new Set( [
		TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH,
		TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH,
	] );

	for ( const rawOperation of operations ) {
		const type = toNonEmptyString( rawOperation?.type );

		switch ( type ) {
			case TEMPLATE_OPERATION_INSERT_PATTERN: {
				const patternName = toNonEmptyString(
					rawOperation?.patternName ?? rawOperation?.name
				);
				const placement = toNonEmptyString( rawOperation?.placement );
				const targetPath = normalizeBlockPath(
					rawOperation?.targetPath
				);

				if ( ! patternName || ! placement ) {
					return {
						ok: false,
						error: 'Template-part pattern insertions must include both a pattern name and placement.',
					};
				}

				if (
					placement !== TEMPLATE_PART_PLACEMENT_START &&
					placement !== TEMPLATE_PART_PLACEMENT_END &&
					placement !== TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH &&
					placement !== TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
				) {
					return {
						ok: false,
						error: 'Template-part pattern insertions must use an explicit start, end, before_block_path, or after_block_path placement.',
					};
				}

				if ( anchoredPlacements.has( placement ) && ! targetPath ) {
					return {
						ok: false,
						error: 'Anchored template-part pattern insertions must include a targetPath.',
					};
				}

				const normalizedOperation = {
					type,
					patternName,
					placement,
				};

				if ( targetPath ) {
					normalizedOperation.targetPath = targetPath;
				}

				normalizedOperations.push( normalizedOperation );
				break;
			}

			case TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN: {
				const patternName = toNonEmptyString(
					rawOperation?.patternName ?? rawOperation?.name
				);
				const expectedBlockName = toNonEmptyString(
					rawOperation?.expectedBlockName
				);
				const targetPath = normalizeBlockPath(
					rawOperation?.targetPath
				);

				if ( ! patternName || ! expectedBlockName || ! targetPath ) {
					return {
						ok: false,
						error: 'Template-part block replacements must include patternName, expectedBlockName, and targetPath.',
					};
				}

				normalizedOperations.push( {
					type,
					patternName,
					expectedBlockName,
					targetPath,
				} );
				break;
			}

			case TEMPLATE_OPERATION_REMOVE_BLOCK: {
				const expectedBlockName = toNonEmptyString(
					rawOperation?.expectedBlockName
				);
				const targetPath = normalizeBlockPath(
					rawOperation?.targetPath
				);

				if ( ! expectedBlockName || ! targetPath ) {
					return {
						ok: false,
						error: 'Template-part block removals must include expectedBlockName and targetPath.',
					};
				}

				normalizedOperations.push( {
					type,
					expectedBlockName,
					targetPath,
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
	TEMPLATE_OPERATION_REMOVE_BLOCK,
	TEMPLATE_OPERATION_REPLACE,
	TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
	TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH,
	TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH,
	TEMPLATE_PART_PLACEMENT_END,
	TEMPLATE_PART_PLACEMENT_START,
};
