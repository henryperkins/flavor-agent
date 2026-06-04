const EXAMPLE_ID_PREFIX = 'example-';
const STYLE_BOOK_BLOCK_NAME_PATTERN = /^[a-z0-9-]+\/[a-z0-9-]+$/i;

const STYLES_SIDEBAR_PANEL_SELECTOR = [
	'.editor-global-styles-sidebar__panel',
	'.edit-site-global-styles-sidebar .editor-global-styles-sidebar__panel',
	'.interface-complementary-area.edit-site-global-styles-sidebar .editor-global-styles-sidebar__panel',
	'[data-wp-component="GlobalStylesSidebar"] .editor-global-styles-sidebar__panel',
].join( ', ' );

const STYLES_SIDEBAR_MOUNT_SELECTOR = [
	'.editor-global-styles-sidebar',
	'.edit-site-global-styles-sidebar',
	'.interface-complementary-area.edit-site-global-styles-sidebar',
	'.interface-complementary-area[data-slug="edit-site/global-styles"]',
	'[data-wp-component="GlobalStylesSidebar"]',
].join( ', ' );

const STYLES_SIDEBAR_SCOPE_SELECTOR = [
	STYLES_SIDEBAR_MOUNT_SELECTOR,
	'.edit-site-layout__sidebar',
	'.edit-site-sidebar',
].join( ', ' );

const STYLE_BOOK_CONTAINER_SELECTOR = [
	'.editor-style-book',
	'.edit-site-style-book',
	'.edit-site-global-styles-screen-style-book',
	STYLES_SIDEBAR_MOUNT_SELECTOR,
	'[data-wp-component="StyleBook"]',
	'[data-wp-component="GlobalStylesSidebar"]',
].join( ', ' );

const STYLE_BOOK_PREVIEW_MOUNT_SELECTOR = [
	'.editor-style-book',
	'.edit-site-style-book',
	'.edit-site-global-styles-screen-style-book',
	'[data-wp-component="StyleBook"]',
].join( ', ' );

const STYLE_BOOK_IFRAME_SELECTOR = [
	'.editor-style-book__iframe',
	'iframe[title="Style Book"]',
	'iframe[title*="Style Book"]',
	'iframe[aria-label="Style Book"]',
	'iframe[aria-label*="Style Book"]',
	'iframe[data-wp-style-book]',
	'iframe[data-style-book]',
].join( ', ' );

const STYLE_BOOK_NAVIGATION_CANDIDATE_SELECTOR = [
	'[data-style-book-block-name]',
	'[data-wp-style-book-block-name]',
	'[data-wp-style-book-example]',
	'[data-style-book-example]',
	'[data-block-name]',
	'a[href*="example-"]',
	'[aria-controls^="example-"]',
	'[data-target*="example-"]',
].join( ', ' );

const STYLE_BOOK_EXAMPLE_CANDIDATE_SELECTOR = [
	'.editor-style-book__example',
	'[id^="example-"]',
	'[data-wp-style-book-example]',
	'[data-style-book-example]',
	'[data-style-book-block-name]',
	'[data-wp-style-book-block-name]',
	'[data-block-name]',
	'[data-type]',
].join( ', ' );

const STYLE_BOOK_BLOCK_NAME_ATTRIBUTES = [
	'data-block-name',
	'data-wp-block-name',
	'data-style-book-block-name',
	'data-wp-style-book-block-name',
	'data-style-book-block',
	'data-wp-style-book-block',
	'data-style-book-example',
	'data-wp-style-book-example',
	'data-type',
	'aria-controls',
	'href',
	'data-target',
];

const STYLE_BOOK_BLOCK_TITLE_SELECTORS = [
	'.editor-style-book__example-title',
	'[data-style-book-example-title]',
	'[data-wp-style-book-example-title]',
];

const STYLE_BOOK_BLOCK_TITLE_ATTRIBUTES = [
	'data-block-title',
	'data-style-book-block-title',
	'data-wp-style-book-block-title',
	'aria-label',
	'title',
];

const STYLE_BOOK_IFRAME_OBSERVED_ATTRIBUTES = [
	'aria-current',
	'aria-label',
	'aria-selected',
	'class',
	'data-block-name',
	'data-style-book-block-name',
	'data-style-book-example',
	'data-target',
	'data-wp-block-name',
	'data-wp-style-book-block-name',
	'data-wp-style-book-example',
	'href',
	'id',
	'title',
];

const STYLE_BOOK_DOCUMENT_OBSERVED_ATTRIBUTES = [
	'aria-controls',
	'aria-current',
	'aria-expanded',
	'aria-label',
	'aria-pressed',
	'aria-selected',
	'class',
	'data-block-name',
	'data-slug',
	'data-style-book-block-name',
	'data-style-book-example',
	'data-target',
	'data-wp-block-name',
	'data-wp-component',
	'data-wp-style-book-block-name',
	'data-wp-style-book-example',
	'href',
	'title',
];

function getDocumentRoot( root = document ) {
	return root && typeof root.querySelector === 'function' ? root : null;
}

function queryFirst( root, selector ) {
	try {
		return root?.querySelector?.( selector ) || null;
	} catch {
		// Ignore selectors unsupported by the current DOM implementation.
	}

	return null;
}

function queryAll( root, selector ) {
	try {
		return [ ...root.querySelectorAll( selector ) ];
	} catch {
		// Ignore selectors unsupported by the current DOM implementation.
	}

	return [];
}

function closestMatching( element, selector ) {
	try {
		return element?.closest?.( selector ) || null;
	} catch {
		// Ignore selectors unsupported by the current DOM implementation.
	}

	return null;
}

function isSelectedStyleBookElement( element ) {
	return Boolean(
		element?.classList?.contains( 'is-selected' ) ||
			( element?.getAttribute?.( 'aria-current' ) || '' ).trim() ||
			element?.getAttribute?.( 'aria-selected' ) === 'true'
	);
}

function safeDecodeURIComponent( value ) {
	try {
		return decodeURIComponent( value );
	} catch {
		return value;
	}
}

function normalizeBlockNameCandidate( value ) {
	let candidate = String( value || '' ).trim();

	if ( ! candidate ) {
		return '';
	}

	if ( candidate.includes( '#' ) ) {
		candidate = candidate.slice( candidate.lastIndexOf( '#' ) + 1 );
	}

	if ( candidate.startsWith( EXAMPLE_ID_PREFIX ) ) {
		candidate = candidate.slice( EXAMPLE_ID_PREFIX.length );
	}

	candidate = safeDecodeURIComponent( candidate );

	return STYLE_BOOK_BLOCK_NAME_PATTERN.test( candidate ) ? candidate : '';
}

function getBlockNameFromElement( element ) {
	if ( ! element || typeof element.getAttribute !== 'function' ) {
		return '';
	}

	for ( const attribute of STYLE_BOOK_BLOCK_NAME_ATTRIBUTES ) {
		const blockName = normalizeBlockNameCandidate(
			element.getAttribute( attribute )
		);

		if ( blockName ) {
			return blockName;
		}
	}

	return normalizeBlockNameCandidate( element.getAttribute( 'id' ) );
}

function getBlockTitleFromElement(
	element,
	{ allowTextContent = false } = {}
) {
	if ( ! element || typeof element.querySelector !== 'function' ) {
		return '';
	}

	const titleNode = queryFirst( element, STYLE_BOOK_BLOCK_TITLE_SELECTORS );
	const titleText = titleNode?.textContent?.trim() || '';

	if ( titleText ) {
		return titleText;
	}

	for ( const attribute of STYLE_BOOK_BLOCK_TITLE_ATTRIBUTES ) {
		const value = element.getAttribute( attribute )?.trim() || '';

		if ( value ) {
			return value;
		}
	}

	return allowTextContent ? element.textContent?.trim() || '' : '';
}

function getStyleBookContainers( documentRoot ) {
	const containers = queryAll( documentRoot, STYLE_BOOK_CONTAINER_SELECTOR );
	const sidebarMountNode = findStylesSidebarMountNode( documentRoot );

	if ( sidebarMountNode ) {
		containers.push( sidebarMountNode );
	}

	return containers.filter(
		( element, index ) => containers.indexOf( element ) === index
	);
}

function getScopedStylesRegion( stylesRegions ) {
	return stylesRegions.find( ( region ) =>
		closestMatching( region, STYLES_SIDEBAR_SCOPE_SELECTOR )
	);
}

function findStyleBookPreviewMountNode( documentRoot ) {
	const iframe =
		queryFirst( documentRoot, '.editor-style-book__iframe' ) ||
		queryFirst( documentRoot, STYLE_BOOK_IFRAME_SELECTOR );

	return closestMatching( iframe, STYLE_BOOK_PREVIEW_MOUNT_SELECTOR );
}

function findStyleBookExampleForBlockName( iframeDocument, blockName ) {
	if ( ! iframeDocument || ! blockName ) {
		return null;
	}

	return (
		queryAll( iframeDocument, STYLE_BOOK_EXAMPLE_CANDIDATE_SELECTOR ).find(
			( element ) => getBlockNameFromElement( element ) === blockName
		) || null
	);
}

function resolveStyleBookTargetFromElement(
	element,
	iframeDocument,
	{ allowTextContentTitle = false } = {}
) {
	const blockName = getBlockNameFromElement( element );

	if ( ! blockName ) {
		return null;
	}

	const matchingExample = findStyleBookExampleForBlockName(
		iframeDocument,
		blockName
	);
	const blockTitle =
		getBlockTitleFromElement( element, {
			allowTextContent: allowTextContentTitle,
		} ) || getBlockTitleFromElement( matchingExample );

	return {
		blockName,
		blockTitle,
	};
}

function findSelectedStyleBookExample( iframeDocument ) {
	return (
		queryAll( iframeDocument, STYLE_BOOK_EXAMPLE_CANDIDATE_SELECTOR ).find(
			isSelectedStyleBookElement
		) || null
	);
}

function findSelectedStyleBookNavigationTarget( documentRoot, iframeDocument ) {
	for ( const container of getStyleBookContainers( documentRoot ) ) {
		const selectedNavigationElement = queryAll(
			container,
			STYLE_BOOK_NAVIGATION_CANDIDATE_SELECTOR
		).find( isSelectedStyleBookElement );
		const target = resolveStyleBookTargetFromElement(
			selectedNavigationElement,
			iframeDocument,
			{ allowTextContentTitle: true }
		);

		if ( target ) {
			return target;
		}
	}

	return null;
}

export function findStylesSidebarMountNode( root = document ) {
	const documentRoot = getDocumentRoot( root );

	if ( ! documentRoot ) {
		return null;
	}

	const directMountNode =
		queryFirst( documentRoot, STYLES_SIDEBAR_PANEL_SELECTOR ) ||
		queryFirst( documentRoot, STYLES_SIDEBAR_MOUNT_SELECTOR );

	if ( directMountNode ) {
		return directMountNode;
	}

	const stylesRegions = queryAll(
		documentRoot,
		'[role="region"][aria-label="Styles"]'
	);
	const scopedStylesRegion = getScopedStylesRegion( stylesRegions );

	if ( scopedStylesRegion ) {
		return scopedStylesRegion;
	}

	if ( stylesRegions.length === 1 ) {
		return stylesRegions[ 0 ];
	}

	return findStyleBookPreviewMountNode( documentRoot );
}

export function findStyleBookIframe( root = document ) {
	const documentRoot = getDocumentRoot( root );

	if ( ! documentRoot ) {
		return null;
	}

	const classMatch = queryFirst( documentRoot, '.editor-style-book__iframe' );

	if ( classMatch ) {
		return classMatch;
	}

	for ( const container of getStyleBookContainers( documentRoot ) ) {
		const iframe = queryFirst( container, STYLE_BOOK_IFRAME_SELECTOR );

		if ( iframe ) {
			return iframe;
		}
	}

	return queryFirst( documentRoot, STYLE_BOOK_IFRAME_SELECTOR );
}

export function getSelectedStyleBookTarget( root = document ) {
	const documentRoot = getDocumentRoot( root );

	if ( ! documentRoot ) {
		return null;
	}

	const iframe = findStyleBookIframe( root );
	const iframeDocument = iframe?.contentDocument || null;

	if ( ! iframeDocument ) {
		return null;
	}

	const selectedExample = findSelectedStyleBookExample( iframeDocument );
	const selectedExampleTarget = resolveStyleBookTargetFromElement(
		selectedExample,
		iframeDocument
	);

	if ( selectedExampleTarget ) {
		return selectedExampleTarget;
	}

	return findSelectedStyleBookNavigationTarget(
		documentRoot,
		iframeDocument
	);
}

export function getStyleBookUiState( root = document ) {
	return {
		isActive: Boolean( findStyleBookIframe( root ) ),
		sidebarMountNode: findStylesSidebarMountNode( root ),
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
			attributeFilter: STYLE_BOOK_IFRAME_OBSERVED_ATTRIBUTES,
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
	const observerTarget = root.body || root.documentElement;

	if ( ! observerTarget ) {
		reconcileSharedIframeObserver();
		return;
	}

	sharedDocumentObserver = new window.MutationObserver( () => {
		reconcileSharedIframeObserver();
		notifyAllSubscribers();
	} );
	sharedDocumentObserver.observe( observerTarget, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: STYLE_BOOK_DOCUMENT_OBSERVED_ATTRIBUTES,
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
