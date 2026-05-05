/**
 * Flavor Agent — Undo Toast slice.
 *
 * Owns the transient toast queue surfaced by the body-portal `<ToastRegion />`.
 * Each toast represents the result of a recent executable apply and carries the
 * activity entry id so its Undo button can dispatch `undoActivity(entryId)` —
 * the same canonical undo path that `<AIActivitySection />` uses.
 *
 * Toast contract:
 *   - Variants: success (only enqueue source from apply paths) and error
 *     (only on undo failure, via `updateToast`); warning is reserved.
 *   - Cap at 3 visible. Eviction skips the oldest "interacted" entry; if
 *     every visible toast is interacted, the new toast is dropped.
 *   - The component renders from these props verbatim — variant transitions
 *     live in this slice, not in component-local state.
 *
 * Surface→title mapping lives in this file (single source of truth) so the
 * two block apply call sites in `src/store/index.js` and the executor pipeline
 * in `src/store/executable-surface-runtime.js` never drift.
 */

const ACTION_TYPES = Object.freeze( {
	ENQUEUE: 'FLAVOR_AGENT_TOAST_ENQUEUE',
	UPDATE: 'FLAVOR_AGENT_TOAST_UPDATE',
	DISMISS: 'FLAVOR_AGENT_TOAST_DISMISS',
	DISMISS_ALL: 'FLAVOR_AGENT_TOAST_DISMISS_ALL',
	MARK_INTERACTED: 'FLAVOR_AGENT_TOAST_MARK_INTERACTED',
} );

const MAX_VISIBLE = 3;
const DEFAULT_SUCCESS_MS = 6000;
const DEFAULT_ERROR_MS = 8000;

const SURFACE_TITLES = Object.freeze( {
	block: 'Block updated',
	template: 'Template applied',
	templatePart: 'Template part applied',
	'template-part': 'Template part applied',
	globalStyles: 'Global styles updated',
	'global-styles': 'Global styles updated',
	styleBook: 'Style Book updated',
	'style-book': 'Style Book updated',
} );

let nextLocalToastId = 0;

function generateToastId() {
	nextLocalToastId += 1;
	return `flavor-agent-toast-${ Date.now() }-${ nextLocalToastId }`;
}

function getSurfaceTitle( surface ) {
	if ( typeof surface === 'string' && SURFACE_TITLES[ surface ] ) {
		return SURFACE_TITLES[ surface ];
	}

	return 'Update applied';
}

function formatBlockDetail( suggestion, extras ) {
	const blockName =
		extras?.blockContext?.name ||
		extras?.blockContext?.blockName ||
		suggestion?.blockName ||
		suggestion?.blockType ||
		null;
	const attrName =
		suggestion?.attributeName ||
		suggestion?.attribute ||
		( suggestion?.attributes
			? Object.keys( suggestion.attributes )[ 0 ]
			: null );

	if ( blockName && attrName ) {
		return `${ blockName } · attr=${ attrName }`;
	}

	if ( blockName ) {
		return blockName;
	}

	return suggestion?.label || suggestion?.suggestionKey || '';
}

function formatTemplateDetail( suggestion, extras ) {
	const slug =
		suggestion?.templateSlug ||
		suggestion?.slug ||
		extras?.result?.templateSlug ||
		'';
	const opCount =
		( Array.isArray( extras?.operations ) && extras.operations.length ) ||
		( Array.isArray( extras?.result?.operations ) &&
			extras.result.operations.length ) ||
		( Array.isArray( suggestion?.operations ) &&
			suggestion.operations.length ) ||
		0;

	if ( slug && opCount ) {
		return `${ slug } · ${ opCount } ops`;
	}

	if ( slug ) {
		return slug;
	}

	if ( opCount ) {
		return `${ opCount } ops`;
	}

	return suggestion?.label || '';
}

function formatTemplatePartDetail( suggestion, extras ) {
	const area =
		suggestion?.area ||
		suggestion?.templatePartArea ||
		extras?.result?.area ||
		'';
	const opCount =
		( Array.isArray( extras?.operations ) && extras.operations.length ) ||
		( Array.isArray( suggestion?.operations ) &&
			suggestion.operations.length ) ||
		0;

	if ( area && opCount ) {
		return `${ area } · ${ opCount } ops`;
	}

	if ( area ) {
		return area;
	}

	if ( opCount ) {
		return `${ opCount } ops`;
	}

	return suggestion?.label || '';
}

function formatStyleDetail( suggestion ) {
	const path =
		suggestion?.stylePath || suggestion?.path || suggestion?.target || '';
	const value = suggestion?.value || suggestion?.newValue || '';

	if ( path && value ) {
		return `${ path } · ${ value }`;
	}

	if ( path ) {
		return path;
	}

	return suggestion?.label || '';
}

function formatStyleBookDetail( suggestion ) {
	const target =
		suggestion?.blockName ||
		suggestion?.target ||
		suggestion?.styleVariation ||
		'';
	const variation = suggestion?.styleVariation || suggestion?.variant || '';

	if ( target && variation ) {
		return `${ target } · ${ variation }`;
	}

	if ( target ) {
		return target;
	}

	return suggestion?.label || '';
}

function buildToastDetail( surface, suggestion, extras ) {
	switch ( surface ) {
		case 'block':
			return formatBlockDetail( suggestion, extras );
		case 'template':
			return formatTemplateDetail( suggestion, extras );
		case 'templatePart':
		case 'template-part':
			return formatTemplatePartDetail( suggestion, extras );
		case 'globalStyles':
		case 'global-styles':
			return formatStyleDetail( suggestion );
		case 'styleBook':
		case 'style-book':
			return formatStyleBookDetail( suggestion );
		default:
			return suggestion?.label || '';
	}
}

/**
 * Single source of truth for translating an apply outcome into a toast.
 * Both block apply actions in `src/store/index.js` and the executor pipeline
 * in `src/store/executable-surface-runtime.js` call this — the per-surface
 * title and detail mapping must not drift.
 *
 * @param {Object} options
 * @param {string} options.surface        Surface key (`block`, `template`, `templatePart`, `globalStyles`, `styleBook`).
 * @param {Object} options.persistedEntry The activity entry returned by `recordActivityEntry`. May be `null` if persistence failed.
 * @param {Object} options.suggestion     The apply suggestion.
 * @param {Object} [options.extras]       Optional render hints (e.g. `blockContext`, `operations`).
 * @return {Object} Toast payload suitable for `enqueueToast`.
 */
export function buildToastForActivity( {
	surface,
	persistedEntry = null,
	suggestion = null,
	extras = null,
} = {} ) {
	const activityId = persistedEntry?.id || null;

	return {
		id: generateToastId(),
		variant: 'success',
		surface,
		title: getSurfaceTitle( surface ),
		detail: buildToastDetail( surface, suggestion, extras ),
		activityId,
		undoLabel: 'Undo',
		autoDismissMs: DEFAULT_SUCCESS_MS,
		interacted: false,
	};
}

const toastsActions = {
	enqueueToast( toast ) {
		return {
			type: ACTION_TYPES.ENQUEUE,
			toast,
		};
	},
	updateToast( id, patch ) {
		return {
			type: ACTION_TYPES.UPDATE,
			id,
			patch,
		};
	},
	dismissToast( id ) {
		return {
			type: ACTION_TYPES.DISMISS,
			id,
		};
	},
	dismissAllToasts() {
		return {
			type: ACTION_TYPES.DISMISS_ALL,
		};
	},
	markToastInteracted( id, interacted ) {
		return {
			type: ACTION_TYPES.MARK_INTERACTED,
			id,
			interacted: Boolean( interacted ),
		};
	},
};

function reduceEnqueue( state, action ) {
	const incoming = action.toast;

	if ( ! incoming || ! incoming.id ) {
		return state;
	}

	const queue = Array.isArray( state.toasts ) ? state.toasts : [];

	if ( queue.length < MAX_VISIBLE ) {
		return { ...state, toasts: [ ...queue, incoming ] };
	}

	const evictIndex = queue.findIndex( ( entry ) => ! entry.interacted );

	if ( evictIndex === -1 ) {
		// Every visible toast is being interacted with — drop the incoming
		// rather than yank focus/hover out from under the user.
		return state;
	}

	const next = queue.slice();

	next.splice( evictIndex, 1 );
	next.push( incoming );

	return { ...state, toasts: next };
}

function reduceUpdate( state, action ) {
	const queue = Array.isArray( state.toasts ) ? state.toasts : [];
	let didChange = false;
	const next = queue.map( ( entry ) => {
		if ( entry.id !== action.id ) {
			return entry;
		}

		didChange = true;

		return { ...entry, ...action.patch };
	} );

	return didChange ? { ...state, toasts: next } : state;
}

function reduceDismiss( state, action ) {
	const queue = Array.isArray( state.toasts ) ? state.toasts : [];
	const next = queue.filter( ( entry ) => entry.id !== action.id );

	if ( next.length === queue.length ) {
		return state;
	}

	return { ...state, toasts: next };
}

function reduceMarkInteracted( state, action ) {
	const queue = Array.isArray( state.toasts ) ? state.toasts : [];
	let didChange = false;
	const next = queue.map( ( entry ) => {
		if (
			entry.id !== action.id ||
			entry.interacted === action.interacted
		) {
			return entry;
		}

		didChange = true;

		return { ...entry, interacted: action.interacted };
	} );

	return didChange ? { ...state, toasts: next } : state;
}

export const toastsDefaultState = {
	toasts: [],
};

export function reduceToastsState( state, action ) {
	switch ( action.type ) {
		case ACTION_TYPES.ENQUEUE:
			return reduceEnqueue( state, action );
		case ACTION_TYPES.UPDATE:
			return reduceUpdate( state, action );
		case ACTION_TYPES.DISMISS:
			return reduceDismiss( state, action );
		case ACTION_TYPES.DISMISS_ALL:
			if (
				! Array.isArray( state.toasts ) ||
				state.toasts.length === 0
			) {
				return null;
			}

			return { ...state, toasts: [] };
		case ACTION_TYPES.MARK_INTERACTED:
			return reduceMarkInteracted( state, action );
		default:
			return null;
	}
}

export const toastsActionCreators = toastsActions;

export const toastsSelectors = {
	getToasts: ( state ) =>
		Array.isArray( state.toasts ) ? state.toasts : [],
};

export const TOAST_DEFAULTS = Object.freeze( {
	successMs: DEFAULT_SUCCESS_MS,
	errorMs: DEFAULT_ERROR_MS,
	maxVisible: MAX_VISIBLE,
} );

export const TOAST_ACTION_TYPES = ACTION_TYPES;
