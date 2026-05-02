function getDocumentRoot( root = document ) {
	return root && typeof root.querySelector === 'function' ? root : null;
}

export function findStylesSidebarMountNode( root = document ) {
	const documentRoot = getDocumentRoot( root );

	if ( ! documentRoot ) {
		return null;
	}

	return (
		documentRoot.querySelector( '.editor-global-styles-sidebar__panel' ) ||
		documentRoot.querySelector( '.editor-global-styles-sidebar' ) ||
		documentRoot.querySelector( '[role="region"][aria-label="Styles"]' )
	);
}

export function findStyleBookIframe( root = document ) {
	const documentRoot = getDocumentRoot( root );

	if ( ! documentRoot ) {
		return null;
	}

	return documentRoot.querySelector( '.editor-style-book__iframe' );
}

export function getSelectedStyleBookTarget( root = document ) {
	const iframe = findStyleBookIframe( root );
	const iframeDocument = iframe?.contentDocument || null;

	if ( ! iframeDocument ) {
		return null;
	}

	const selectedExample = iframeDocument.querySelector(
		'.editor-style-book__example.is-selected'
	);

	if ( ! selectedExample ) {
		return null;
	}

	const exampleId = selectedExample.getAttribute( 'id' ) || '';

	if ( ! exampleId.startsWith( 'example-' ) ) {
		return null;
	}

	const rawBlockName = exampleId.slice( 'example-'.length );
	const blockName = rawBlockName.includes( '%' )
		? decodeURIComponent( rawBlockName )
		: rawBlockName;
	const blockTitle =
		selectedExample
			.querySelector( '.editor-style-book__example-title' )
			?.textContent?.trim() || '';

	return blockName
		? {
				blockName,
				blockTitle,
		  }
		: null;
}

export function getStyleBookUiState( root = document ) {
	return {
		isActive: Boolean( findStyleBookIframe( root ) ),
		target: getSelectedStyleBookTarget( root ),
	};
}

// Module-level shared observer state. The Site Editor mounts both
// GlobalStylesRecommender and StyleBookRecommender concurrently while in the
// Styles complementary area, so a per-call observer doubled the MutationObserver
// load on document.body. One observer set serves all subscribers; teardown
// happens when the last subscriber unsubscribes.
let sharedSubscribers = null;
let sharedRoot = null;
let sharedDocumentObserver = null;
let sharedIframe = null;
let sharedIframeObserver = null;
let sharedIframeLoadHandler = null;

function notifyAllSubscribers() {
	if ( ! sharedSubscribers || ! sharedRoot ) {
		return;
	}

	const state = getStyleBookUiState( sharedRoot );

	// Snapshot before iterating so a subscriber that unsubscribes during its
	// own callback doesn't perturb the loop.
	for ( const subscriber of [ ...sharedSubscribers ] ) {
		subscriber( state );
	}
}

function detachSharedIframeObserver() {
	if ( sharedIframeObserver ) {
		sharedIframeObserver.disconnect();
		sharedIframeObserver = null;
	}

	if ( sharedIframe && sharedIframeLoadHandler ) {
		sharedIframe.removeEventListener( 'load', sharedIframeLoadHandler );
	}

	sharedIframeLoadHandler = null;
	sharedIframe = null;
}

function reconcileSharedIframeObserver() {
	const nextIframe = findStyleBookIframe( sharedRoot );

	if ( nextIframe === sharedIframe ) {
		return;
	}

	detachSharedIframeObserver();
	sharedIframe = nextIframe;

	if ( ! sharedIframe ) {
		notifyAllSubscribers();
		return;
	}

	const attachIframeObserver = () => {
		if ( ! sharedIframe?.contentDocument?.body ) {
			notifyAllSubscribers();
			return;
		}

		sharedIframeObserver = new window.MutationObserver( () => {
			notifyAllSubscribers();
		} );
		sharedIframeObserver.observe( sharedIframe.contentDocument.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'class', 'id', 'aria-label' ],
		} );
		notifyAllSubscribers();
	};

	sharedIframeLoadHandler = () => {
		detachSharedIframeObserver();
		sharedIframe = nextIframe;
		attachIframeObserver();
	};
	sharedIframe.addEventListener( 'load', sharedIframeLoadHandler );
	attachIframeObserver();
}

function attachSharedObservers( root ) {
	sharedRoot = root;
	sharedDocumentObserver = new window.MutationObserver( () => {
		reconcileSharedIframeObserver();
		notifyAllSubscribers();
	} );
	sharedDocumentObserver.observe( root.body || root.documentElement, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'class', 'aria-expanded', 'aria-pressed' ],
	} );
	reconcileSharedIframeObserver();
}

function detachSharedObservers() {
	if ( sharedDocumentObserver ) {
		sharedDocumentObserver.disconnect();
		sharedDocumentObserver = null;
	}

	detachSharedIframeObserver();
	sharedRoot = null;
}

export function subscribeToStyleBookUi( root = document, onChange ) {
	const documentRoot = getDocumentRoot( root );

	if (
		! documentRoot ||
		typeof onChange !== 'function' ||
		typeof window === 'undefined' ||
		typeof window.MutationObserver !== 'function'
	) {
		return () => {};
	}

	if ( ! sharedSubscribers ) {
		sharedSubscribers = new Set();
	}

	sharedSubscribers.add( onChange );

	if ( sharedSubscribers.size === 1 ) {
		attachSharedObservers( documentRoot );
	}

	// Push initial state to the new subscriber. Other subscribers already have
	// their last known state and don't need a redundant notification.
	onChange( getStyleBookUiState( documentRoot ) );

	return () => {
		if ( ! sharedSubscribers ) {
			return;
		}

		sharedSubscribers.delete( onChange );

		if ( sharedSubscribers.size === 0 ) {
			detachSharedObservers();
			sharedSubscribers = null;
		}
	};
}
