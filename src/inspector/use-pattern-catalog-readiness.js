import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

const PATTERN_CATALOG_WAIT_MS = 20_000;

/**
 * Wait for core's completion signal before collecting structural request input.
 * Finished includes a failed or genuinely empty resolution; neither should leave
 * the composer waiting forever. Older runtimes have a bounded fallback, while
 * subsequent catalog changes still participate in the normal freshness checks.
 *
 * @param {Object}  options
 * @param {boolean} options.enabled Whether this request needs the catalog.
 * @param {string}  options.scope   Selected block whose wait budget is active.
 * @return {Object} Catalog waiting and timeout state.
 */
export default function usePatternCatalogReadiness( { enabled, scope } ) {
	const hasResolved = useSelect(
		( select ) => {
			if ( ! enabled ) {
				return true;
			}

			const coreData = select( 'core' );
			// Calling the selector also starts resolution if the editor has not.
			coreData?.getBlockPatterns?.();
			return Boolean(
				coreData?.hasFinishedResolution?.( 'getBlockPatterns' )
			);
		},
		[ enabled ]
	);
	const [ timedOutScope, setTimedOutScope ] = useState( null );

	useEffect( () => {
		setTimedOutScope( null );
		if ( ! enabled || hasResolved ) {
			return undefined;
		}

		const timer = setTimeout( () => {
			setTimedOutScope( scope );
		}, PATTERN_CATALOG_WAIT_MS );
		return () => clearTimeout( timer );
	}, [ enabled, hasResolved, scope ] );

	const hasTimedOut = enabled && ! hasResolved && timedOutScope === scope;
	return {
		isWaiting: enabled && ! hasResolved && ! hasTimedOut,
		hasTimedOut,
	};
}
