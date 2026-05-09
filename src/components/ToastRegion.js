/**
 * Flavor Agent — Toast region.
 *
 * Mounts a single portal at `document.body` and renders the toast queue from
 * the `flavor-agent` store. There is exactly one mount per editor session.
 *
 * Iframe accessibility: the post editor canvas is iframed in WP 6.x+, and the
 * Site Editor / Style Book canvases live in iframes as well. Tab focus does
 * not naturally cross from iframe content into a host-body overlay, so we
 * register a `mod+alt+shift+u` keydown listener on the host document that
 * focuses the newest visible toast's Undo button. The custom chord avoids
 * conventional undo/redo bindings such as `mod+z` and `mod+shift+z`.
 */

import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';

import UndoToast from './UndoToast';

function getRegionRoot() {
	if ( typeof document === 'undefined' ) {
		return null;
	}

	let root = document.querySelector( '.flavor-agent-toast-region' );

	if ( ! root ) {
		root = document.createElement( 'div' );
		root.className = 'flavor-agent-toast-region';
		root.setAttribute( 'aria-label', 'Flavor Agent recent changes' );
		document.body.appendChild( root );
	}

	return root;
}

function isToastUndoFocusShortcut( event ) {
	if ( event.key !== 'u' && event.key !== 'U' ) {
		return false;
	}

	if ( ! event.shiftKey || ! event.altKey ) {
		return false;
	}

	const isMacLike =
		typeof navigator !== 'undefined' &&
		/Mac|iPhone|iPad|iPod/.test( navigator.platform || '' );

	return isMacLike ? event.metaKey : event.ctrlKey;
}

function getSameOriginIframeDocument( iframe ) {
	try {
		const frameDocument =
			iframe?.contentDocument || iframe?.contentWindow?.document || null;

		return frameDocument?.addEventListener ? frameDocument : null;
	} catch {
		return null;
	}
}

export default function ToastRegion() {
	const toasts = useSelect( ( select ) => {
		const flavorAgent = select( 'flavor-agent' );

		return flavorAgent?.getToasts ? flavorAgent.getToasts() : [];
	}, [] );

	const { dismissToast, markToastInteracted, undoToastAction } =
		useDispatch( 'flavor-agent' );

	const [ root, setRoot ] = useState( () => getRegionRoot() );
	const regionRef = useRef( null );

	useEffect( () => {
		// Lazily attach the root once after first render so SSR / Jest envs
		// without a DOM don't crash on import.
		if ( ! root ) {
			setRoot( getRegionRoot() );
		}
	}, [ root ] );

	const handleFocusNewestUndo = useCallback( () => {
		if ( ! regionRef.current ) {
			return false;
		}

		const undoButtons = regionRef.current.querySelectorAll(
			'.flavor-agent-toast__action'
		);

		if ( undoButtons.length === 0 ) {
			return false;
		}

		// Newest is the last child (column flow, append-at-end).
		const newest = undoButtons[ undoButtons.length - 1 ];

		if ( newest && typeof newest.focus === 'function' ) {
			newest.focus();
			return true;
		}

		return false;
	}, [] );

	useEffect( () => {
		if ( typeof document === 'undefined' ) {
			return undefined;
		}

		const listeningDocuments = new Set();
		const observedFrames = new WeakSet();
		const frameCleanups = [];

		const handleKeyDown = ( event ) => {
			if ( ! isToastUndoFocusShortcut( event ) ) {
				return;
			}

			if ( handleFocusNewestUndo() ) {
				event.preventDefault?.();
				event.stopPropagation?.();
			}
		};

		const addDocumentListener = ( targetDocument ) => {
			if (
				! targetDocument?.addEventListener ||
				listeningDocuments.has( targetDocument )
			) {
				return;
			}

			targetDocument.addEventListener( 'keydown', handleKeyDown, true );
			listeningDocuments.add( targetDocument );
		};

		const observeFrame = ( iframe ) => {
			if ( ! iframe || observedFrames.has( iframe ) ) {
				return;
			}

			observedFrames.add( iframe );

			const attachFrameDocument = () => {
				addDocumentListener( getSameOriginIframeDocument( iframe ) );
			};

			attachFrameDocument();
			iframe.addEventListener?.( 'load', attachFrameDocument );
			frameCleanups.push( () => {
				iframe.removeEventListener?.( 'load', attachFrameDocument );
			} );
		};

		const scanFrames = ( rootNode = document ) => {
			if ( rootNode?.tagName === 'IFRAME' ) {
				observeFrame( rootNode );
			}

			if ( ! rootNode?.querySelectorAll ) {
				return;
			}

			rootNode.querySelectorAll( 'iframe' ).forEach( observeFrame );
		};

		addDocumentListener( document );
		scanFrames();

		const observerTarget = document.body || document.documentElement;
		const MutationObserverConstructor =
			typeof window !== 'undefined' ? window.MutationObserver : null;
		const observer =
			observerTarget && MutationObserverConstructor
				? new MutationObserverConstructor( ( mutations ) => {
						mutations.forEach( ( mutation ) => {
							mutation.addedNodes.forEach( scanFrames );
						} );
				  } )
				: null;

		observer?.observe( observerTarget, {
			childList: true,
			subtree: true,
		} );

		return () => {
			observer?.disconnect();
			frameCleanups.forEach( ( cleanup ) => cleanup() );
			listeningDocuments.forEach( ( targetDocument ) => {
				targetDocument.removeEventListener(
					'keydown',
					handleKeyDown,
					true
				);
			} );
		};
	}, [ handleFocusNewestUndo ] );

	const handleUndo = useCallback(
		( toastId ) => {
			const toast = toasts.find( ( entry ) => entry.id === toastId );

			if ( typeof undoToastAction === 'function' ) {
				undoToastAction( toastId, toast?.activityId || null );
			}
		},
		[ toasts, undoToastAction ]
	);

	const handleDismiss = useCallback(
		( toastId ) => {
			if ( typeof dismissToast === 'function' ) {
				dismissToast( toastId );
			}
		},
		[ dismissToast ]
	);

	const handleInteractionChange = useCallback(
		( toastId, interacted ) => {
			if ( typeof markToastInteracted === 'function' ) {
				markToastInteracted( toastId, interacted );
			}
		},
		[ markToastInteracted ]
	);

	const renderedToasts = useMemo( () => toasts.slice( 0, 3 ), [ toasts ] );

	if ( ! root ) {
		return null;
	}

	return createPortal(
		<div ref={ regionRef } className="flavor-agent-toast-region__inner">
			{ renderedToasts.map( ( toast ) => (
				<UndoToast
					key={ toast.id }
					id={ toast.id }
					variant={ toast.variant }
					title={ toast.title }
					detail={ toast.detail }
					errorHint={ toast.errorHint }
					undoLabel={ toast.undoLabel }
					autoDismissMs={ toast.autoDismissMs }
					undoDisabled={ ! toast.activityId }
					onUndo={ handleUndo }
					onDismiss={ handleDismiss }
					onInteractionChange={ handleInteractionChange }
				/>
			) ) }
		</div>,
		root
	);
}

export { isToastUndoFocusShortcut };
