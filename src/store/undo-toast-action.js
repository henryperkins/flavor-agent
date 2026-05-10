import { __ } from '@wordpress/i18n';

import { TOAST_DEFAULTS } from './toasts';

const FALLBACK_ERROR_HINT = __(
	'The change could not be reverted.',
	'flavor-agent'
);
const UNDO_FAILED_TITLE = __( 'Undo failed', 'flavor-agent' );

function buildErrorPatch( errorHint ) {
	return {
		variant: 'error',
		title: UNDO_FAILED_TITLE,
		errorHint: errorHint || FALLBACK_ERROR_HINT,
		autoDismissMs: TOAST_DEFAULTS.errorMs,
		interacted: false,
	};
}

export function createUndoToastAction( actions ) {
	return ( toastId, activityId, options = {} ) =>
		async ( { dispatch } ) => {
			if ( ! activityId ) {
				dispatch( actions.dismissToast( toastId ) );
				return false;
			}

			let result;

			try {
				result = await dispatch(
					actions.undoActivity( activityId, {
						document: options?.activityDocument || null,
						scopeKey: options?.activityScopeKey || null,
					} )
				);
			} catch ( error ) {
				dispatch(
					actions.updateToast(
						toastId,
						buildErrorPatch( error?.message )
					)
				);
				return false;
			}

			if ( result?.ok ) {
				dispatch( actions.dismissToast( toastId ) );
				return true;
			}

			dispatch(
				actions.updateToast( toastId, buildErrorPatch( result?.error ) )
			);
			return false;
		};
}
