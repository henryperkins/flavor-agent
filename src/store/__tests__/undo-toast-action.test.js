jest.mock( '@wordpress/i18n', () => ( {
	__: jest.fn( ( text ) => text ),
} ) );

import * as i18n from '@wordpress/i18n';
import { createUndoToastAction } from '../undo-toast-action';
import { TOAST_DEFAULTS } from '../toasts';

function buildStubActions() {
	return {
		dismissToast: jest.fn( ( id ) => ( { type: 'DISMISS', id } ) ),
		updateToast: jest.fn( ( id, patch ) => ( {
			type: 'UPDATE',
			id,
			patch,
		} ) ),
		undoActivity: jest.fn( ( activityId, options ) => ( {
			type: 'UNDO',
			activityId,
			options,
		} ) ),
	};
}

function buildDispatchMock( undoResult ) {
	return jest.fn( ( action ) => {
		if ( action?.type === 'UNDO' ) {
			return Promise.resolve( undoResult );
		}

		return undefined;
	} );
}

describe( 'undoToastAction — result handling', () => {
	it( 'dismisses the toast when undoActivity reports ok', async () => {
		const actions = buildStubActions();
		const undoToastAction = createUndoToastAction( actions );
		const dispatch = buildDispatchMock( { ok: true } );

		const result = await undoToastAction(
			'toast-1',
			'activity-1'
		)( { dispatch } );

		expect( result ).toBe( true );
		expect( actions.dismissToast ).toHaveBeenCalledWith( 'toast-1' );
		expect( actions.updateToast ).not.toHaveBeenCalled();
	} );

	it( 'passes the toast activity scope through to undoActivity', async () => {
		const actions = buildStubActions();
		const undoToastAction = createUndoToastAction( actions );
		const dispatch = buildDispatchMock( { ok: true } );
		const activityDocument = {
			scopeKey: 'global_styles:17',
			postType: 'global_styles',
			entityId: '17',
		};

		await undoToastAction( 'toast-1', 'activity-1', {
			activityDocument,
			activityScopeKey: 'global_styles:17',
		} )( { dispatch } );

		expect( actions.undoActivity ).toHaveBeenCalledWith( 'activity-1', {
			document: activityDocument,
			scopeKey: 'global_styles:17',
		} );
	} );

	it( 'keeps the toast and surfaces the error variant when undoActivity returns ok=false', async () => {
		const actions = buildStubActions();
		const undoToastAction = createUndoToastAction( actions );
		const dispatch = buildDispatchMock( {
			ok: false,
			error: 'Server returned 500.',
		} );

		const result = await undoToastAction(
			'toast-1',
			'activity-1'
		)( { dispatch } );

		expect( result ).toBe( false );
		expect( actions.dismissToast ).not.toHaveBeenCalled();
		expect( actions.updateToast ).toHaveBeenCalledWith( 'toast-1', {
			variant: 'error',
			title: 'Undo failed',
			errorHint: 'Server returned 500.',
			autoDismissMs: TOAST_DEFAULTS.errorMs,
			interacted: false,
		} );
	} );

	it( 'falls back to a generic message when ok=false has no error string', async () => {
		const actions = buildStubActions();
		const undoToastAction = createUndoToastAction( actions );
		const dispatch = buildDispatchMock( { ok: false } );

		await undoToastAction( 'toast-1', 'activity-1' )( { dispatch } );

		expect( actions.updateToast ).toHaveBeenCalledWith(
			'toast-1',
			expect.objectContaining( {
				variant: 'error',
				errorHint: 'The change could not be reverted.',
			} )
		);
		expect( i18n.__ ).toHaveBeenCalledWith( 'Undo failed', 'flavor-agent' );
		expect( i18n.__ ).toHaveBeenCalledWith(
			'The change could not be reverted.',
			'flavor-agent'
		);
	} );

	it( 'converts a thrown error to the error toast variant', async () => {
		const actions = buildStubActions();
		const undoToastAction = createUndoToastAction( actions );
		const dispatch = jest.fn( ( action ) => {
			if ( action?.type === 'UNDO' ) {
				return Promise.reject( new Error( 'Network down.' ) );
			}

			return undefined;
		} );

		const result = await undoToastAction(
			'toast-1',
			'activity-1'
		)( { dispatch } );

		expect( result ).toBe( false );
		expect( actions.dismissToast ).not.toHaveBeenCalled();
		expect( actions.updateToast ).toHaveBeenCalledWith(
			'toast-1',
			expect.objectContaining( {
				variant: 'error',
				errorHint: 'Network down.',
			} )
		);
	} );

	it( 'dismisses without dispatching undo when no activityId is provided', async () => {
		const actions = buildStubActions();
		const undoToastAction = createUndoToastAction( actions );
		const dispatch = jest.fn();

		const result = await undoToastAction( 'toast-1', null )( { dispatch } );

		expect( result ).toBe( false );
		expect( actions.dismissToast ).toHaveBeenCalledWith( 'toast-1' );
		expect( actions.undoActivity ).not.toHaveBeenCalled();
	} );
} );
