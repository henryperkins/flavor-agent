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

	let iframe = null;
	let iframeObserver = null;
	let iframeLoadHandler = null;

	const disconnectIframeObserver = () => {
		if ( iframeObserver ) {
			iframeObserver.disconnect();
			iframeObserver = null;
		}

		if ( iframe && iframeLoadHandler ) {
			iframe.removeEventListener( 'load', iframeLoadHandler );
		}

		iframeLoadHandler = null;
		iframe = null;
	};

	const notify = () => {
		onChange( getStyleBookUiState( documentRoot ) );
	};

	const observeIframe = () => {
		const nextIframe = findStyleBookIframe( documentRoot );

		if ( nextIframe === iframe ) {
			return;
		}

		disconnectIframeObserver();
		iframe = nextIframe;

		if ( ! iframe ) {
			notify();
			return;
		}

		const attachIframeObserver = () => {
			if ( ! iframe?.contentDocument?.body ) {
				notify();
				return;
			}

			iframeObserver = new window.MutationObserver( () => {
				notify();
			} );
			iframeObserver.observe( iframe.contentDocument.body, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: [ 'class', 'id', 'aria-label' ],
			} );
			notify();
		};

		iframeLoadHandler = () => {
			disconnectIframeObserver();
			iframe = nextIframe;
			attachIframeObserver();
		};
		iframe.addEventListener( 'load', iframeLoadHandler );
		attachIframeObserver();
	};

	const documentObserver = new window.MutationObserver( () => {
		observeIframe();
		notify();
	} );

	documentObserver.observe(
		documentRoot.body || documentRoot.documentElement,
		{
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'class', 'aria-expanded', 'aria-pressed' ],
		}
	);

	observeIframe();
	notify();

	return () => {
		documentObserver.disconnect();
		disconnectIframeObserver();
	};
}
