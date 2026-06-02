import { useMemo } from '@wordpress/element';

import { getResolvedActivityEntries } from '../store/activity-history';
import { getGlobalStylesActivityUndoState } from '../utils/style-operations';

const EMPTY_ACTIVITY_LOG = [];

function isStyleSurfaceActivityInScope(
	entry,
	{ surface, globalStylesId, blockName = '' }
) {
	if ( entry?.surface !== surface ) {
		return false;
	}

	if (
		String( entry?.target?.globalStylesId || '' ) !==
		String( globalStylesId || '' )
	) {
		return false;
	}

	if ( surface !== 'style-book' ) {
		return true;
	}

	return (
		blockName !== '' &&
		String( entry?.target?.blockName || '' ) === String( blockName )
	);
}

/**
 * Resolve live undo state for Global Styles and Style Book activity without
 * allocating filtered/resolved activity arrays inside a `useSelect` callback.
 *
 * @param {Object}   input                         Hook input.
 * @param {string}   input.surface                 Style surface key.
 * @param {Array}    input.activityLog             Full activity log from the store.
 * @param {string}   input.globalStylesId          Active Global Styles entity ID.
 * @param {string}   input.blockName               Active Style Book block name.
 * @param {Object}   input.registry                WordPress data registry.
 * @param {string}   input.lastUndoneActivityId    Last undone activity ID.
 * @param {*}        input.runtimeDependency       Live style runtime dependency.
 * @param {Function} input.resolveRuntimeUndoState Runtime undo resolver.
 * @return {{ activityEntries: Array, hasUndoSuccess: boolean }} Resolved scoped activity context.
 */
export function useStyleSurfaceActivityContext( {
	surface,
	activityLog,
	globalStylesId,
	blockName = '',
	registry,
	lastUndoneActivityId = null,
	runtimeDependency = null,
	resolveRuntimeUndoState = getGlobalStylesActivityUndoState,
} ) {
	const entries = activityLog || EMPTY_ACTIVITY_LOG;

	const activityEntries = useMemo( () => {
		void runtimeDependency;

		const scopedEntries = entries.filter( ( entry ) =>
			isStyleSurfaceActivityInScope( entry, {
				surface,
				globalStylesId,
				blockName,
			} )
		);

		return getResolvedActivityEntries( scopedEntries, ( entry ) =>
			resolveRuntimeUndoState( entry, registry )
		);
	}, [
		blockName,
		entries,
		globalStylesId,
		registry,
		resolveRuntimeUndoState,
		runtimeDependency,
		surface,
	] );

	const hasUndoSuccess = useMemo( () => {
		if ( typeof lastUndoneActivityId !== 'string' ) {
			return false;
		}

		return activityEntries.some(
			( entry ) =>
				entry?.id === lastUndoneActivityId &&
				entry?.undo?.status === 'undone'
		);
	}, [ activityEntries, lastUndoneActivityId ] );

	return {
		activityEntries,
		hasUndoSuccess,
	};
}
