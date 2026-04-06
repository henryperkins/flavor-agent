import { inferTemplatePartArea } from './template-part-areas';

function getBlockAttributes( block = {} ) {
	return block && typeof block.attributes === 'object'
		? block.attributes
		: {};
}

function getInnerBlocks( block = {} ) {
	return Array.isArray( block?.innerBlocks ) ? block.innerBlocks : [];
}

export function buildTemplateStructureSnapshot( blocks = [], depth = 0 ) {
	if ( ! Array.isArray( blocks ) ) {
		return [];
	}

	return blocks
		.map( ( block ) => {
			if ( ! block || typeof block !== 'object' ) {
				return null;
			}

			const name =
				typeof block?.name === 'string' ? block.name.trim() : '';

			if ( ! name ) {
				return null;
			}

			const snapshot = { name };

			if ( depth < 1 ) {
				const innerBlocks = buildTemplateStructureSnapshot(
					getInnerBlocks( block ),
					depth + 1
				);

				if ( innerBlocks.length > 0 ) {
					snapshot.innerBlocks = innerBlocks;
				}
			}

			return snapshot;
		} )
		.filter( Boolean );
}

function humanizeBlockName( blockName = '' ) {
	if ( ! blockName ) {
		return 'Block';
	}

	const normalizedName = blockName.includes( '/' )
		? blockName.split( '/' )[ 1 ]
		: blockName;

	return normalizedName
		.split( /[-_]/ )
		.filter( Boolean )
		.map(
			( segment ) =>
				segment.charAt( 0 ).toUpperCase() + segment.slice( 1 )
		)
		.join( ' ' );
}

export function describeEditorBlockLabel(
	blockName = '',
	attributes = {},
	areaLookup
) {
	if ( blockName === 'core/template-part' ) {
		const slug =
			typeof attributes?.slug === 'string' ? attributes.slug.trim() : '';
		const area = inferTemplatePartArea( attributes, areaLookup );

		if ( slug && area ) {
			return `${ slug } template part (${ area })`;
		}

		if ( slug ) {
			return `${ slug } template part`;
		}

		if ( area ) {
			return `Empty ${ area } template-part slot`;
		}

		return 'Template-part slot';
	}

	return humanizeBlockName( blockName );
}

function getPatternOverrideEntry( attributes = {} ) {
	const bindings =
		attributes?.metadata &&
		typeof attributes.metadata === 'object' &&
		! Array.isArray( attributes.metadata ) &&
		attributes.metadata.bindings &&
		typeof attributes.metadata.bindings === 'object' &&
		! Array.isArray( attributes.metadata.bindings )
			? attributes.metadata.bindings
			: null;

	if ( ! bindings ) {
		return null;
	}

	const overrideAttributes = [];
	let usesDefaultBinding = false;

	for ( const [ attributeName, binding ] of Object.entries( bindings ) ) {
		if (
			! binding ||
			typeof binding !== 'object' ||
			Array.isArray( binding ) ||
			binding.source !== 'core/pattern-overrides'
		) {
			continue;
		}

		if ( attributeName === '__default' ) {
			usesDefaultBinding = true;
			continue;
		}

		overrideAttributes.push( attributeName );
	}

	if ( overrideAttributes.length === 0 && ! usesDefaultBinding ) {
		return null;
	}

	return {
		overrideAttributes: Array.from( new Set( overrideAttributes ) ).sort(),
		usesDefaultBinding,
	};
}

function getViewportVisibilityEntry( attributes = {} ) {
	const blockVisibility =
		attributes?.metadata &&
		typeof attributes.metadata === 'object' &&
		! Array.isArray( attributes.metadata )
			? attributes.metadata.blockVisibility
			: null;

	if ( blockVisibility === false ) {
		return {
			hiddenViewports: [ 'all' ],
			visibleViewports: [],
		};
	}

	if (
		! blockVisibility ||
		typeof blockVisibility !== 'object' ||
		Array.isArray( blockVisibility )
	) {
		return null;
	}

	const viewport =
		blockVisibility.viewport &&
		typeof blockVisibility.viewport === 'object' &&
		! Array.isArray( blockVisibility.viewport )
			? blockVisibility.viewport
			: null;

	if ( ! viewport ) {
		return null;
	}

	const hiddenViewports = [];
	const visibleViewports = [];

	for ( const [ viewportName, value ] of Object.entries( viewport ) ) {
		if ( typeof value !== 'boolean' ) {
			continue;
		}

		if ( value ) {
			visibleViewports.push( viewportName );
		} else {
			hiddenViewports.push( viewportName );
		}
	}

	if ( hiddenViewports.length === 0 && visibleViewports.length === 0 ) {
		return null;
	}

	return {
		hiddenViewports: Array.from( new Set( hiddenViewports ) ).sort(),
		visibleViewports: Array.from( new Set( visibleViewports ) ).sort(),
	};
}

export function collectPatternOverrideSummary( blocks = [], areaLookup ) {
	const summary = {
		hasOverrides: false,
		blockCount: 0,
		blockNames: [],
		blocks: [],
	};
	const blockNames = new Set();

	const visit = ( branch = [], path = [] ) => {
		if ( ! Array.isArray( branch ) ) {
			return;
		}

		branch.forEach( ( block, index ) => {
			if ( ! block || typeof block !== 'object' || ! block.name ) {
				return;
			}

			const nextPath = [ ...path, index ];
			const attributes = getBlockAttributes( block );
			const overrideEntry = getPatternOverrideEntry( attributes );

			if ( overrideEntry ) {
				summary.hasOverrides = true;
				summary.blockCount += 1;
				blockNames.add( block.name );
				summary.blocks.push( {
					path: nextPath,
					name: block.name,
					label: describeEditorBlockLabel(
						block.name,
						attributes,
						areaLookup
					),
					overrideAttributes: overrideEntry.overrideAttributes,
					usesDefaultBinding: overrideEntry.usesDefaultBinding,
				} );
			}

			visit( getInnerBlocks( block ), nextPath );
		} );
	};

	visit( blocks );

	summary.blockNames = Array.from( blockNames ).sort();

	return summary;
}

export function collectViewportVisibilitySummary( blocks = [], areaLookup ) {
	const summary = {
		hasVisibilityRules: false,
		blockCount: 0,
		blocks: [],
	};

	const visit = ( branch = [], path = [] ) => {
		if ( ! Array.isArray( branch ) ) {
			return;
		}

		branch.forEach( ( block, index ) => {
			if ( ! block || typeof block !== 'object' || ! block.name ) {
				return;
			}

			const nextPath = [ ...path, index ];
			const attributes = getBlockAttributes( block );
			const visibilityEntry = getViewportVisibilityEntry( attributes );

			if ( visibilityEntry ) {
				summary.hasVisibilityRules = true;
				summary.blockCount += 1;
				summary.blocks.push( {
					path: nextPath,
					name: block.name,
					label: describeEditorBlockLabel(
						block.name,
						attributes,
						areaLookup
					),
					hiddenViewports: visibilityEntry.hiddenViewports,
					visibleViewports: visibilityEntry.visibleViewports,
				} );
			}

			visit( getInnerBlocks( block ), nextPath );
		} );
	};

	visit( blocks );

	return summary;
}
