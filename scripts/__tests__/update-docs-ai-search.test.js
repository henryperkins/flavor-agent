'use strict';

const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const {
	configureInstance,
	contentHashForEntry,
	discoverSourceUrls,
	evaluateSettlement,
	existingItemsByUrl,
	fetchJson,
	buildMarkdownDocument,
	isCorpusDocumentUrl,
	isFreshByLastmod,
	fetchText,
	htmlToMarkdown,
	isSettlementComplete,
	listBuiltinItems,
	makeCorePostDate,
	managedCompletedSourceUrlCount,
	manifestEntryFromExisting,
	normalizeTrustedUrl,
	parseArgs,
	pollUntilSettled,
	processEntries,
	readSitemap,
	resolveStaleDeletion,
	resolveSummaryStatus,
	findSupersededSourceItems,
	sitemapUrlWithinOrigins,
	sourcePartIdentityForUrl,
	sourceRootsForRelease,
	truncateUtf8ToBytes,
	urlMatchesRoots,
	withinRecentPostWindow,
	wordPressNewsPostDate,
} = require( '../update-docs-ai-search.js' );

function mockTextResponse( text, url, contentType = 'application/xml' ) {
	return Promise.resolve( {
		ok: true,
		status: 200,
		url,
		text: () => Promise.resolve( text ),
		headers: {
			get: ( key ) => ( key.toLowerCase() === 'content-type' ? contentType : '' ),
		},
	} );
}

function mockJsonResponse( data, url, status = 200, headers = {} ) {
	return Promise.resolve( {
		ok: status >= 200 && status < 300,
		status,
		url,
		text: () => Promise.resolve( JSON.stringify( data ) ),
		headers: {
			get: ( key ) => headers[ key.toLowerCase() ] || '',
		},
	} );
}

function docsHtml( { canonical, title = 'WordPress Docs Page', body = '' } ) {
	const content = body || 'This page contains enough developer documentation text for the updater to build a Markdown document. '.repeat( 4 );
	return [
		'<!doctype html><html><head>',
		`<title>${ title }</title>`,
		`<link rel="canonical" href="${ canonical }">`,
		'</head><body><main>',
		`<h1>${ title }</h1>`,
		`<p>${ content }</p>`,
		'</main></body></html>',
	].join( '' );
}

describe( 'update-docs-ai-search helpers', () => {
	let originalFetch;

	beforeEach( () => {
		originalFetch = global.fetch;
	} );

	afterEach( () => {
		global.fetch = originalFetch;
		jest.restoreAllMocks();
	} );

	test( 'resolves trusted relative canonical URLs against the response URL', () => {
		expect(
			normalizeTrustedUrl(
				'/block-editor/reference-guides/block-api/block-metadata/?utm=1#usage',
				'https://developer.wordpress.org/block-editor/reference-guides/'
			)
		).toBe(
			'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/'
		);

		expect( normalizeTrustedUrl( '/block-editor/reference-guides/' ) ).toBe( '' );
	} );

	test( 'dedupes sitemap loc entries before discovery queues nested sitemaps', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://developer.wordpress.org/robots.txt' ) {
				return mockTextResponse(
					'Sitemap: https://developer.wordpress.org/sitemap-index.xml',
					href,
					'text/plain'
				);
			}
			if ( href === 'https://developer.wordpress.org/sitemap-index.xml' ) {
				return mockTextResponse(
					[
						'<sitemapindex>',
						'<sitemap><loc>https://developer.wordpress.org/block-editor-sitemap.xml</loc></sitemap>',
						'<sitemap><loc>https://developer.wordpress.org/block-editor-sitemap.xml</loc></sitemap>',
						'</sitemapindex>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://developer.wordpress.org/block-editor-sitemap.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://developer.wordpress.org/block-editor/reference-guides/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if (
				href === 'https://developer.wordpress.org/block-editor/wp-sitemap.xml' ||
				href === 'https://developer.wordpress.org/block-editor/sitemap.xml'
			) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const direct = await readSitemap(
			'https://developer.wordpress.org/sitemap-index.xml',
			new Set()
		);
		expect( direct.sitemaps ).toEqual( [
			'https://developer.wordpress.org/block-editor-sitemap.xml',
		] );

		const { urls } = await discoverSourceUrls(
			[ 'https://developer.wordpress.org/block-editor/' ],
			{ sourceUrls: [], sourceFile: '', limit: 0 }
		);

		expect( urls ).toEqual( [
			'https://developer.wordpress.org/block-editor/',
			'https://developer.wordpress.org/block-editor/reference-guides/',
		] );
		expect(
			global.fetch.mock.calls.filter(
				( [ url ] ) => String( url ) === 'https://developer.wordpress.org/block-editor-sitemap.xml'
			)
		).toHaveLength( 1 );
	} );

	test( 'preserves fenced code block formatting when converting HTML to Markdown', () => {
		const markdown = htmlToMarkdown(
			[
				'<main>',
				'<h2>Example</h2>',
				'<pre><code>const block = {\n\tname: &quot;core/group&quot;,\n\tattributes: { layout: { type: &quot;constrained&quot; } },\n};</code></pre>',
				'<p>Use the attributes above.</p>',
				'</main>',
			].join( '' )
		);

		expect( markdown ).toContain( '```' );
		expect( markdown ).toContain(
			'const block = {\n\tname: "core/group",\n\tattributes: { layout: { type: "constrained" } },\n};'
		);
		expect( markdown ).toContain( 'Use the attributes above.' );
		expect( markdown ).not.toContain( 'const block = { name:' );
	} );

	test( 'preserves escaped HTML inside fenced code blocks', () => {
		const markdown = htmlToMarkdown(
			[
				'<main>',
				'<pre><code>export default function Edit() {\n\treturn &lt;InnerBlocks /&gt;;\n}</code></pre>',
				'</main>',
			].join( '' )
		);

		expect( markdown ).toContain(
			'export default function Edit() {\n\treturn <InnerBlocks />;\n}'
		);
	} );

	test( 'sitemapUrlWithinOrigins only accepts trusted https origins', () => {
		const allowed = new Set( [ 'https://developer.wordpress.org' ] );

		expect(
			sitemapUrlWithinOrigins(
				'https://developer.wordpress.org/sitemap-index.xml',
				allowed
			)
		).toBe( 'https://developer.wordpress.org/sitemap-index.xml' );

		// SSRF surface: off-origin and non-https references are rejected.
		expect( sitemapUrlWithinOrigins( 'https://attacker.example/x.xml', allowed ) ).toBe( '' );
		expect( sitemapUrlWithinOrigins( 'http://developer.wordpress.org/x.xml', allowed ) ).toBe( '' );
		expect( sitemapUrlWithinOrigins( 'not a url', allowed ) ).toBe( '' );
		expect( sitemapUrlWithinOrigins( '', allowed ) ).toBe( '' );
	} );

	test( 'discoverSourceUrls does not fetch sitemaps from untrusted origins', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://developer.wordpress.org/robots.txt' ) {
				return mockTextResponse(
					[
						'Sitemap: https://developer.wordpress.org/sitemap-index.xml',
						'Sitemap: https://attacker.example/evil-sitemap.xml',
					].join( '\n' ),
					href,
					'text/plain'
				);
			}
			if ( href === 'https://developer.wordpress.org/sitemap-index.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://developer.wordpress.org/block-editor/reference-guides/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if (
				href === 'https://developer.wordpress.org/block-editor/wp-sitemap.xml' ||
				href === 'https://developer.wordpress.org/block-editor/sitemap.xml'
			) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://developer.wordpress.org/block-editor/' ],
			{ sourceUrls: [], sourceFile: '', limit: 0 }
		);

		expect( urls ).toEqual( [
			'https://developer.wordpress.org/block-editor/',
			'https://developer.wordpress.org/block-editor/reference-guides/',
		] );
		// The untrusted sitemap was never requested.
		expect(
			global.fetch.mock.calls.some(
				( [ url ] ) => String( url ) === 'https://attacker.example/evil-sitemap.xml'
			)
		).toBe( false );
	} );

	test( 'evaluateSettlement treats never-seen desired keys as missing', () => {
		const desiredKeys = new Set( [ 'key-a', 'key-b' ] );

		// key-b never appears in the listing → must not settle as complete.
		const partial = evaluateSettlement(
			[ { key: 'key-a', status: 'completed' } ],
			desiredKeys
		);
		expect( partial.missing ).toEqual( [ 'key-b' ] );
		expect( partial.pending ).toHaveLength( 0 );

		// All present and completed → nothing missing or pending.
		const settled = evaluateSettlement(
			[
				{ key: 'key-a', status: 'completed' },
				{ key: 'key-b', status: 'completed' },
			],
			desiredKeys
		);
		expect( settled.missing ).toEqual( [] );
		expect( settled.pending ).toHaveLength( 0 );
	} );

	test( 'makeCorePostDate parses dated Make subsite post URLs and ignores the rest', () => {
		expect(
			makeCorePostDate( 'https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/' )
		).toBe( Date.parse( '2026-05-14T00:00:00Z' ) );
		expect(
			makeCorePostDate( 'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/' )
		).toBe( Date.parse( '2026-07-08T00:00:00Z' ) );
		expect( makeCorePostDate( 'https://make.wordpress.org/core/7-0/' ) ).toBeNull();
		expect( makeCorePostDate( 'https://make.wordpress.org/core/handbook/about/' ) ).toBeNull();
		expect( makeCorePostDate( 'https://make.wordpress.org/ai/handbook/' ) ).toBeNull();
		expect( makeCorePostDate( 'https://developer.wordpress.org/news/2026/05/01/post/' ) ).toBeNull();
	} );

	test( 'wordPressNewsPostDate dates month-dated News posts at month start and ignores the rest', () => {
		expect(
			wordPressNewsPostDate( 'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/' )
		).toBe( Date.parse( '2026-07-01T00:00:00Z' ) );
		expect( wordPressNewsPostDate( 'https://wordpress.org/news/' ) ).toBeNull();
		expect( wordPressNewsPostDate( 'https://wordpress.org/news/category/releases/' ) ).toBeNull();
		expect( wordPressNewsPostDate( 'https://make.wordpress.org/core/2026/05/14/post/' ) ).toBeNull();
	} );

	test( 'withinRecentPostWindow gates dated make and news posts and passes undated docs', () => {
		const cutoff = Date.parse( '2026-01-01T00:00:00Z' );
		expect( withinRecentPostWindow( 'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/', cutoff ) ).toBe( true );
		expect( withinRecentPostWindow( 'https://make.wordpress.org/core/2025/01/15/old-dev-note/', cutoff ) ).toBe( false );
		expect( withinRecentPostWindow( 'https://make.wordpress.org/ai/handbook/', cutoff ) ).toBe( false );
		expect( withinRecentPostWindow( 'https://wordpress.org/news/2026/06/open-web-merch/', cutoff ) ).toBe( true );
		expect( withinRecentPostWindow( 'https://wordpress.org/news/2025/11/old-post/', cutoff ) ).toBe( false );
		expect( withinRecentPostWindow( 'https://developer.wordpress.org/reference/functions/register_block_type/', cutoff ) ).toBe( true );
		expect( withinRecentPostWindow( 'https://wordpress.org/news/2025/11/old-post/', null ) ).toBe( true );
	} );

	test( 'parseArgs accepts the recent-post window flag and its make-core alias', () => {
		expect( parseArgs( [] ).recentPostMaxAgeDays ).toBe( 180 );
		expect( parseArgs( [ '--recent-post-max-age-days=45' ] ).recentPostMaxAgeDays ).toBe( 45 );
		expect( parseArgs( [ '--make-core-max-age-days=90' ] ).recentPostMaxAgeDays ).toBe( 90 );
		expect( () => parseArgs( [ '--recent-post-max-age-days=x' ] ) ).toThrow(
			'recent-post-max-age-days must be a non-negative integer'
		);
	} );

	test( 'discoverSourceUrls keeps recent Make/Core posts and drops stale or undated ones', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://make.wordpress.org/robots.txt' ) {
				return mockTextResponse(
					'Sitemap: https://make.wordpress.org/wp-sitemap.xml',
					href,
					'text/plain'
				);
			}
			if ( href === 'https://make.wordpress.org/wp-sitemap.xml' ) {
				return mockTextResponse(
					[
						'<sitemapindex>',
						'<sitemap><loc>https://make.wordpress.org/wp-sitemap-posts-post-1.xml</loc></sitemap>',
						'</sitemapindex>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://make.wordpress.org/wp-sitemap-posts-post-1.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://make.wordpress.org/core/2026/06/03/dev-chat-agenda-june-03-2026/</loc></url>',
						'<url><loc>https://make.wordpress.org/core/2025/01/15/old-dev-note/</loc></url>',
						'<url><loc>https://make.wordpress.org/core/handbook/about/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if (
				href === 'https://make.wordpress.org/core/wp-sitemap.xml' ||
				href === 'https://make.wordpress.org/core/sitemap.xml'
			) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://make.wordpress.org/core/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				makeCoreMaxAgeDays: 180,
				now: Date.parse( '2026-06-08T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://make.wordpress.org/core/2026/06/03/dev-chat-agenda-june-03-2026/',
		] );
	} );

	test( 'isCorpusDocumentUrl drops release-cycle index and archive pages', () => {
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/2026/05/01/post/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/block-editor/' ) ).toBe( true );

		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/all-posts/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/tag/block-editor/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/tag/dev-notes-7-0/' ) ).toBe( false );
	} );

	test( 'discoverSourceUrls keeps explicitly supplied Make/Core URLs regardless of age', async () => {
		global.fetch = jest.fn( ( url ) => {
			throw new Error( `Unexpected fetch: ${ String( url ) }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://make.wordpress.org/core/' ],
			{
				sourceUrls: [ 'https://make.wordpress.org/core/2020/01/01/ancient-note/' ],
				sourceFile: '',
				limit: 0,
				makeCoreMaxAgeDays: 180,
				now: Date.parse( '2026-06-08T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://make.wordpress.org/core/2020/01/01/ancient-note/',
		] );
	} );

	test( 'discoverSourceUrls discovers Make/Core posts via the /core/ subsite sitemap', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://make.wordpress.org/robots.txt' ) {
				// Network-root robots.txt does not advertise the /core/ subsite sitemap.
				return mockTextResponse(
					'Sitemap: https://make.wordpress.org/wp-sitemap.xml',
					href,
					'text/plain'
				);
			}
			if ( href === 'https://make.wordpress.org/wp-sitemap.xml' ) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			if ( href === 'https://make.wordpress.org/core/wp-sitemap.xml' ) {
				return mockTextResponse(
					[
						'<sitemapindex>',
						'<sitemap><loc>https://make.wordpress.org/core/wp-sitemap-posts-post-1.xml</loc></sitemap>',
						'</sitemapindex>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://make.wordpress.org/core/wp-sitemap-posts-post-1.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://make.wordpress.org/core/2026/06/03/whats-new-in-gutenberg-23-3-03-jun/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://make.wordpress.org/core/sitemap.xml' ) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://make.wordpress.org/core/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				makeCoreMaxAgeDays: 180,
				now: Date.parse( '2026-06-08T00:00:00Z' ),
			}
		);

		expect( urls ).toContain(
			'https://make.wordpress.org/core/2026/06/03/whats-new-in-gutenberg-23-3-03-jun/'
		);
	} );

	test( 'discoverSourceUrls counts non-404 sitemap failures as discovery errors but ignores 404s', async () => {
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const httpError = ( status, href ) =>
			Promise.resolve( {
				ok: false,
				status,
				url: href,
				text: () => Promise.resolve( 'error body' ),
				headers: { get: () => '' },
			} );
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://make.wordpress.org/robots.txt' ) {
				return mockTextResponse( 'Sitemap: https://make.wordpress.org/wp-sitemap.xml', href, 'text/plain' );
			}
			if ( href === 'https://make.wordpress.org/wp-sitemap.xml' ) {
				return mockTextResponse(
					[
						'<sitemapindex>',
						'<sitemap><loc>https://make.wordpress.org/core/wp-sitemap-posts-post-1.xml</loc></sitemap>',
						'<sitemap><loc>https://make.wordpress.org/core/wp-sitemap-posts-post-2.xml</loc></sitemap>',
						'</sitemapindex>',
					].join( '' ),
					href
				);
			}
			// One advertised child is absent (404 → benign); one is a 500 outage (counts).
			if ( href === 'https://make.wordpress.org/core/wp-sitemap-posts-post-1.xml' ) {
				return httpError( 404, href );
			}
			if ( href === 'https://make.wordpress.org/core/wp-sitemap-posts-post-2.xml' ) {
				return httpError( 500, href );
			}
			if (
				href === 'https://make.wordpress.org/core/wp-sitemap.xml' ||
				href === 'https://make.wordpress.org/core/sitemap.xml'
			) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { errors } = await discoverSourceUrls(
			[ 'https://make.wordpress.org/core/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				makeCoreMaxAgeDays: 180,
				now: Date.parse( '2026-06-08T00:00:00Z' ),
			}
		);

		expect( errors ).toHaveLength( 1 );
	} );

	test( 'sourceRootsForRelease returns every trusted root, not just the first', () => {
		expect( sourceRootsForRelease() ).toEqual( [
			'https://developer.wordpress.org/block-editor/',
			'https://developer.wordpress.org/rest-api/',
			'https://developer.wordpress.org/themes/',
			'https://developer.wordpress.org/reference/',
			'https://developer.wordpress.org/news/',
			'https://make.wordpress.org/core/',
		] );
	} );

	test( 'discoverSourceUrls keeps every explicitly supplied URL, not just the first', async () => {
		global.fetch = jest.fn( ( url ) => {
			throw new Error( `Unexpected fetch: ${ String( url ) }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://make.wordpress.org/core/' ],
			{
				sourceUrls: [
					'https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/',
					'https://make.wordpress.org/core/2026/06/03/whats-new-in-gutenberg-23-3-03-jun/',
				],
				sourceFile: '',
				limit: 0,
				makeCoreMaxAgeDays: 180,
				now: Date.parse( '2026-06-08T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/',
			'https://make.wordpress.org/core/2026/06/03/whats-new-in-gutenberg-23-3-03-jun/',
		] );
	} );

	test( 'isSettlementComplete requires nothing missing, pending, or errored', () => {
		expect( isSettlementComplete( { missing: [], pending: [], errors: [] } ) ).toBe( true );
		expect( isSettlementComplete( { missing: [ 'k' ], pending: [], errors: [] } ) ).toBe( false );
		expect( isSettlementComplete( { missing: [], pending: [ {} ], errors: [] } ) ).toBe( false );
		// Item-level errors must block settlement (previously a run settled as complete).
		expect( isSettlementComplete( { missing: [], pending: [], errors: [ {} ] } ) ).toBe( false );
	} );

	test( 'resolveStaleDeletion only deletes after destructive full-corpus safety checks pass', () => {
		const healthy = {
			dryRun: false,
			deleteStale: true,
			explicitSources: false,
			prepared: 10,
			buildErrors: 0,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: true,
		};
		expect( resolveStaleDeletion( healthy ).delete ).toBe( true );

		expect( resolveStaleDeletion( { ...healthy, deleteStale: false } ).delete ).toBe( false );
		// Targeted --source-url/--source-file runs never prune the full corpus.
		expect( resolveStaleDeletion( { ...healthy, explicitSources: true } ).delete ).toBe( false );
		// A broadly failed discovery/build must not be allowed to wipe the corpus.
		expect( resolveStaleDeletion( { ...healthy, prepared: 0 } ).delete ).toBe( false );
		expect( resolveStaleDeletion( { ...healthy, buildErrors: 3 } ).delete ).toBe( false );
		expect( resolveStaleDeletion( { ...healthy, uploadErrors: 1 } ).delete ).toBe( false );
		expect( resolveStaleDeletion( { ...healthy, pollPending: 2 } ).delete ).toBe( false );
		expect( resolveStaleDeletion( { ...healthy, pollErrors: 1 } ).delete ).toBe( false );
		expect( resolveStaleDeletion( { ...healthy, validationOk: false } ) ).toEqual( {
			delete: true,
			reason: 'validation-warning',
		} );
	} );

	test( 'resolveSummaryStatus flags poll, upload, validation, and total-build failures', () => {
		const clean = {
			dryRun: false,
			discovered: 100,
			prepared: 100,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: true,
		};
		expect( resolveSummaryStatus( clean ) ).toBe( 'ok' );
		expect( resolveSummaryStatus( { ...clean, validationOk: false } ) ).toBe( 'needs-attention' );
		expect( resolveSummaryStatus( { ...clean, uploadErrors: 1 } ) ).toBe( 'needs-attention' );
		expect( resolveSummaryStatus( { ...clean, pollPending: 1 } ) ).toBe( 'needs-attention' );
		expect( resolveSummaryStatus( { ...clean, pollErrors: 1 } ) ).toBe( 'needs-attention' );
		// Discovered URLs but built nothing → systemic failure.
		expect( resolveSummaryStatus( { ...clean, prepared: 0 } ) ).toBe( 'needs-attention' );
		// Dry runs never fail on these signals.
		expect(
			resolveSummaryStatus( { ...clean, dryRun: true, validationOk: false, prepared: 0 } )
		).toBe( 'ok' );
	} );

	test( 'resolveStaleDeletion blocks limited, discovery-error, poll-skipped, and regressed runs', () => {
		const healthy = {
			dryRun: false,
			deleteStale: true,
			explicitSources: false,
			limit: 0,
			discoveryErrors: 0,
			pollSkipped: false,
			prepared: 100,
			buildErrors: 0,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: true,
			previousManifestCount: 100,
		};
		expect( resolveStaleDeletion( healthy ).delete ).toBe( true );
		expect( resolveStaleDeletion( { ...healthy, limit: 10 } ).reason ).toBe( 'limited-run' );
		expect( resolveStaleDeletion( { ...healthy, discoveryErrors: 1 } ).reason ).toBe( 'discovery-errors' );
		expect( resolveStaleDeletion( { ...healthy, pollSkipped: true } ).reason ).toBe( 'poll-skipped' );
		// A run preparing 100 docs against a prior 1000-doc corpus is an 80%+ collapse → refuse.
		expect( resolveStaleDeletion( { ...healthy, previousManifestCount: 1000 } ).reason ).toBe( 'prepared-count-regression' );
	} );

	test( 'resolveStaleDeletion tolerates build errors within the attention ratio of discovered URLs', () => {
		const healthy = {
			dryRun: false,
			deleteStale: true,
			explicitSources: false,
			limit: 0,
			discoveryErrors: 0,
			pollSkipped: false,
			discovered: 13314,
			prepared: 13212,
			buildErrors: 102,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: true,
			previousManifestCount: 13000,
		};

		// ~0.8% of discovered pages persistently fail to build (binary attachment
		// pages in the sitemaps); that noise must not leave stale generations
		// unprunable forever.
		expect( resolveStaleDeletion( healthy ) ).toEqual( { delete: true, reason: 'healthy' } );
		// Above the 2% attention ratio it is a systemic build problem → refuse.
		expect( resolveStaleDeletion( { ...healthy, buildErrors: 300 } ).reason ).toBe( 'build-errors' );
		// Build errors without discovery context stay blocking (conservative).
		expect( resolveStaleDeletion( { ...healthy, discovered: 0, buildErrors: 3 } ).reason ).toBe( 'build-errors' );
	} );

	test( 'resolveStaleDeletion allows pruning when only public endpoint validation fails', () => {
		const run = {
			dryRun: false,
			deleteStale: true,
			explicitSources: false,
			limit: 0,
			discoveryErrors: 0,
			pollSkipped: false,
			discovered: 13314,
			prepared: 13212,
			buildErrors: 102,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: false,
			previousManifestCount: 13000,
		};

		expect( resolveSummaryStatus( run ) ).toBe( 'needs-attention' );
		expect( resolveStaleDeletion( run ) ).toEqual( {
			delete: true,
			reason: 'validation-warning',
		} );
	} );

	test( 'resolveSummaryStatus flags discovery errors and a high build-error ratio', () => {
		const clean = {
			dryRun: false,
			discovered: 100,
			prepared: 100,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: true,
			buildErrors: 0,
			discoveryErrors: 0,
		};
		expect( resolveSummaryStatus( clean ) ).toBe( 'ok' );
		expect( resolveSummaryStatus( { ...clean, discoveryErrors: 1 } ) ).toBe( 'needs-attention' );
		// A couple of flaky pages are tolerated; a large fraction is not.
		expect( resolveSummaryStatus( { ...clean, buildErrors: 1 } ) ).toBe( 'ok' );
		expect( resolveSummaryStatus( { ...clean, buildErrors: 5 } ) ).toBe( 'needs-attention' );
	} );

	test( 'parseArgs rejects partial numeric values', () => {
		expect( () => parseArgs( [ '--limit=10abc' ] ) ).toThrow( 'limit must be a non-negative integer' );
		expect( () => parseArgs( [ '--poll-seconds=' ] ) ).toThrow( 'poll-seconds must be a non-negative integer' );
		expect( parseArgs( [ '--limit=10' ] ).limit ).toBe( 10 );
	} );

	test( 'parseArgs makes stale deletion opt-in via --delete-stale', () => {
		expect( parseArgs( [] ).deleteStale ).toBe( false );
		expect( parseArgs( [ '--delete-stale' ] ).deleteStale ).toBe( true );
		expect( parseArgs( [ '--delete-stale', '--no-delete' ] ).deleteStale ).toBe( false );
	} );

	test( 'parseArgs skips Cloudflare instance configuration unless explicitly requested', () => {
		expect( parseArgs( [] ).configureInstance ).toBe( false );
		expect( parseArgs( [ '--configure' ] ).configureInstance ).toBe( true );
		expect( parseArgs( [ '--configure', '--skip-configure' ] ).configureInstance ).toBe( false );
	} );

	test( 'workflow enables stale deletion for scheduled runs while manual dispatch stays opt-in', () => {
		const workflow = fs.readFileSync(
			path.resolve( __dirname, '../../.github/workflows/update-docs-ai-search.yml' ),
			'utf8'
		);

		expect( workflow ).toContain(
			"INPUT_DELETE_STALE: ${{ github.event_name == 'schedule' && 'true' || github.event.inputs.delete_stale || 'false' }}"
		);
		expect( workflow ).toMatch( /delete_stale:[\s\S]*?default: false/ );
	} );

	test( 'workflow fallback corpus matches updater defaults', () => {
		const workflow = fs.readFileSync(
			path.resolve( __dirname, '../../.github/workflows/update-docs-ai-search.yml' ),
			'utf8'
		);
		const defaults = parseArgs( [] );

		expect( workflow ).toContain(
			`CLOUDFLARE_AI_SEARCH_INSTANCE: \${{ vars.CLOUDFLARE_AI_SEARCH_INSTANCE || '${ defaults.instance }' }}`
		);
		expect( workflow ).toContain(
			`CLOUDFLARE_AI_SEARCH_PUBLIC_URL: \${{ vars.CLOUDFLARE_AI_SEARCH_PUBLIC_URL || '${ defaults.publicUrl }' }}`
		);
		expect( workflow ).toContain( `name: Update ${ defaults.instance } corpus` );
	} );

	test( 'corpus runbook documents the updater defaults', () => {
		const runbook = fs.readFileSync(
			path.resolve( __dirname, '../../docs/reference/developer-docs-public-corpus-runbook.md' ),
			'utf8'
		);
		const defaults = parseArgs( [] );

		expect( runbook ).toContain( `Endpoint: \`${ defaults.publicUrl }\`` );
		expect( runbook ).toContain(
			`CLOUDFLARE_AI_SEARCH_INSTANCE\` (default \`${ defaults.instance }\`)`
		);
		expect( runbook ).toContain(
			`public Cloudflare AI Search corpus on \`${ defaults.instance }\``
		);
		expect( runbook ).toContain(
			`instance \`${ defaults.instance }\` / \`${ defaults.publicUrl.replace(
				'/search',
				'/mcp'
			) }\``
		);
	} );

	test( 'workflow requires explicit opt-in before updating Cloudflare instance config', () => {
		const workflow = fs.readFileSync(
			path.resolve( __dirname, '../../.github/workflows/update-docs-ai-search.yml' ),
			'utf8'
		);

		expect( workflow ).toMatch( /configure_instance:[\s\S]*?default: false/ );
		expect( workflow ).toContain(
			"INPUT_CONFIGURE_INSTANCE: ${{ github.event.inputs.configure_instance || 'false' }}"
		);
		expect( workflow ).toContain( 'args+=( "--configure" )' );
		expect( workflow ).not.toContain( 'args+=( "--skip-configure" )' );
	} );

	test( 'configureInstance enforces the exact-symbol search baseline', async () => {
		global.fetch = jest.fn( ( url, init ) =>
			mockJsonResponse( { result: { id: 'wp-dev' } }, String( url ) ).then( ( response ) => {
				response.requestBody = init.body;
				return response;
			} )
		);

		await configureInstance(
			{ dryRun: false, configureInstance: true, instance: 'wp-dev' },
			{ accountId: 'account', apiToken: 'token' }
		);

		const body = JSON.parse( global.fetch.mock.calls[ 0 ][ 1 ].body );
		expect( body.rewrite_query ).toBe( false );
		expect( body.reranking ).toBe( true );
		expect( body.reranking_model ).toBe( '@cf/baai/bge-reranker-base' );
		expect( body.cache_threshold ).toBe( 'super_strict_match' );
		expect( body.cache_ttl ).toBe( 3600 );
	} );

	test( 'fetchJson retries transient Cloudflare API failures', async () => {
		global.fetch = jest
			.fn()
			.mockImplementationOnce( ( url ) =>
				mockJsonResponse(
					{ errors: [ { message: 'rate limited' } ] },
					String( url ),
					429,
					{ 'retry-after': '0' }
				)
			)
			.mockImplementationOnce( ( url ) =>
				mockJsonResponse( { result: { ok: true } }, String( url ) )
			);

		await expect(
			fetchJson( 'https://api.cloudflare.com/client/v4/accounts/a/ai-search/instances/wp-dev', {}, {
				retries: 1,
				retryDelayMs: 0,
			} )
		).resolves.toEqual( { result: { ok: true } } );
		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'listBuiltinItems continues page scans when result_info is absent on a full page', async () => {
		const firstPage = Array.from( { length: 50 }, ( _, index ) => ( {
			id: `item-${ index }`,
			key: `key-${ index }`,
		} ) );
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			const page = new URL( href ).searchParams.get( 'page' );
			if ( page === '1' ) {
				return mockJsonResponse( { result: firstPage }, href );
			}
			if ( page === '2' ) {
				return mockJsonResponse( { result: [ { id: 'item-50', key: 'key-50' } ] }, href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const items = await listBuiltinItems(
			{ instance: 'wp-dev' },
			{ accountId: 'account', apiToken: 'token' }
		);

		expect( items ).toHaveLength( 51 );
	} );

	test( 'pollUntilSettled polls instance stats before doing one final key-level sweep', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href.endsWith( '/stats' ) ) {
				return mockJsonResponse(
					{ result: { queued: 0, running: 0, outdated: 0, error: 0 } },
					href
				);
			}
			if ( href.includes( '/items?' ) ) {
				return mockJsonResponse(
					{
						result: [ { id: 'item-1', key: 'desired-key', status: 'completed' } ],
						result_info: { count: 1, per_page: 50, total_count: 1 },
					},
					href
				);
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const result = await pollUntilSettled(
			new Set( [ 'desired-key' ] ),
			{ dryRun: false, pollSeconds: 30, instance: 'wp-dev' },
			{ accountId: 'account', apiToken: 'token' }
		);

		expect( result ).toEqual( { skipped: false, pending: 0, errors: [] } );
		expect( global.fetch.mock.calls[ 0 ][ 0 ] ).toContain( '/stats' );
		expect(
			global.fetch.mock.calls.filter( ( [ url ] ) => String( url ).includes( '/items?' ) )
		).toHaveLength( 1 );
	} );

	test( 'processEntries dedupes canonical source identities before upload', async () => {
		const canonical = 'https://developer.wordpress.org/reference/functions/wp_insert_post/';
		const sourceUrls = [
			'https://developer.wordpress.org/reference/functions/wp_insert_post/?utm_source=one',
			'https://developer.wordpress.org/reference/functions/wp_insert_post/?utm_source=two',
		];

		global.fetch = jest.fn( ( url, init = {} ) => {
			const href = String( url );
			if ( href.startsWith( 'https://developer.wordpress.org/' ) ) {
				return mockTextResponse(
					docsHtml( { canonical, title: 'wp_insert_post()' } ),
					href,
					'text/html'
				);
			}
			if ( href.includes( '/ai-search/instances/wp-dev/items' ) && init.method === 'POST' ) {
				return mockJsonResponse(
					{ result: { id: 'uploaded-item', key: 'uploaded-key', status: 'queued' } },
					href
				);
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const processed = await processEntries(
			sourceUrls,
			[ 'https://developer.wordpress.org/reference/' ],
			{ dryRun: false, fullRefetch: true, instance: 'wp-dev' },
			{ accountId: 'account', apiToken: 'token' },
			new Map(),
			{},
			new Map()
		);

		expect( processed.prepared ).toBe( 1 );
		expect( processed.desiredKeys.size ).toBe( 1 );
		expect( processed.desiredSourceIdentities ).toEqual(
			new Set( [ sourcePartIdentityForUrl( canonical ) ] )
		);
		expect( processed.duplicateSources ).toEqual( [
			expect.objectContaining( {
				url: sourceUrls[ 1 ],
				canonical,
				identity: sourcePartIdentityForUrl( canonical ),
				reason: 'duplicate-source-url',
			} ),
		] );
		expect( processed.skipped ).toEqual( [
			expect.objectContaining( {
				url: sourceUrls[ 1 ],
				reason: 'duplicate-source-url',
			} ),
		] );
		expect( processed.uploaded ).toHaveLength( 1 );
		expect(
			global.fetch.mock.calls.filter(
				( [ url, init ] ) =>
					String( url ).includes( '/ai-search/instances/wp-dev/items' ) &&
					init?.method === 'POST'
			)
		).toHaveLength( 1 );
	} );

	test( 'findSupersededSourceItems selects older same-source generations only for desired identities', () => {
		const sourceUrl = 'https://developer.wordpress.org/reference/functions/wp_register_ability/';
		const desiredKey = 'ai-search/wp-dev/developer.wordpress.org/reference-functions-wp-register-ability/newhash/part-0001.md';
		const oldKey = 'ai-search/wp-dev/developer.wordpress.org/reference-functions-wp-register-ability/oldhash/part-0001.md';
		const unrelatedKey = 'ai-search/wp-dev/developer.wordpress.org/reference-functions-wp_has_ability/oldhash/part-0001.md';

		const superseded = findSupersededSourceItems(
			[
				{
					id: 'desired',
					key: desiredKey,
					status: 'completed',
					metadata: { source_url: sourceUrl },
				},
				{
					id: 'old',
					key: oldKey,
					status: 'completed',
					metadata: { source_url: sourceUrl },
				},
				{
					id: 'unrelated',
					key: unrelatedKey,
					status: 'completed',
					metadata: { source_url: 'https://developer.wordpress.org/reference/functions/wp_has_ability/' },
				},
				{
					id: 'foreign',
					key: 'manual-upload.md',
					status: 'completed',
					metadata: { source_url: sourceUrl },
				},
			],
			new Set( [ desiredKey ] ),
			new Set( [ sourcePartIdentityForUrl( sourceUrl ) ] ),
			'wp-dev'
		);

		expect( superseded ).toEqual( [
			expect.objectContaining( { id: 'old', key: oldKey } ),
		] );
	} );

	test( 'urlMatchesRoots matches roots regardless of trailing slash', () => {
		const roots = [ 'https://developer.wordpress.org/reference/' ];
		expect( urlMatchesRoots( 'https://developer.wordpress.org/reference', roots ) ).toBe( true );
		expect( urlMatchesRoots( 'https://developer.wordpress.org/reference/classes/wp/', roots ) ).toBe( true );
		expect( urlMatchesRoots( 'https://developer.wordpress.org/reference-guides/', roots ) ).toBe( false );
		expect( urlMatchesRoots( 'https://make.wordpress.org/reference/', roots ) ).toBe( false );
	} );

	test( 'truncateUtf8ToBytes respects the byte budget without splitting multibyte characters', () => {
		const multibyte = '★'.repeat( 100 ); // each ★ is 3 UTF-8 bytes
		const out = truncateUtf8ToBytes( multibyte, 10 );
		expect( Buffer.byteLength( out, 'utf8' ) ).toBeLessThanOrEqual( 10 );
		expect( out.includes( '�' ) ).toBe( false );
		expect( out ).toBe( '★★★' ); // 9 bytes — the largest whole-character fit
	} );

	test( 'fetchText rejects redirects that leave the trusted origins', async () => {
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				status: 200,
				url: 'https://evil.example/landing',
				text: () => Promise.resolve( 'redirected body' ),
				headers: { get: () => '' },
			} )
		);
		await expect(
			fetchText( 'https://developer.wordpress.org/block-editor/', {
				allowedOrigins: new Set( [ 'https://developer.wordpress.org' ] ),
			} )
		).rejects.toThrow( 'Refusing redirect outside trusted origins' );
	} );

	test( 'isFreshByLastmod reuses only completed items crawled at/after the sitemap lastmod', () => {
		const completed = ( retrievedAt ) => ( {
			status: 'completed',
			metadata: { retrieved_at: retrievedAt },
		} );

		// Crawled after the page's last modification -> safe to reuse.
		expect( isFreshByLastmod( '2026-05-01T00:00:00Z', completed( '2026-06-06T00:00:00Z' ) ) ).toBe( true );
		// Page modified after our crawl -> must re-fetch.
		expect( isFreshByLastmod( '2026-06-07T00:00:00Z', completed( '2026-06-06T00:00:00Z' ) ) ).toBe( false );
		// Missing lastmod, missing item, non-completed status, missing crawl time, or
		// unparseable dates all fall through to a fresh fetch.
		expect( isFreshByLastmod( '', completed( '2026-06-06T00:00:00Z' ) ) ).toBe( false );
		expect( isFreshByLastmod( '2026-05-01T00:00:00Z', null ) ).toBe( false );
		expect(
			isFreshByLastmod( '2026-05-01T00:00:00Z', { status: 'queued', metadata: { retrieved_at: '2026-06-06T00:00:00Z' } } )
		).toBe( false );
		expect( isFreshByLastmod( '2026-05-01T00:00:00Z', { status: 'completed', metadata: {} } ) ).toBe( false );
		expect( isFreshByLastmod( 'not-a-date', completed( '2026-06-06T00:00:00Z' ) ) ).toBe( false );
	} );

	test( 'isFreshByLastmod accepts epoch-ms retrieved_at as returned by the Cloudflare items API', () => {
		const completedAtMs = ( retrievedAtMs ) => ( {
			status: 'completed',
			metadata: { retrieved_at: retrievedAtMs },
		} );

		// The items API normalizes datetime metadata to epoch milliseconds, not the ISO
		// strings the updater uploads, so a numeric crawl time must still allow reuse.
		expect(
			isFreshByLastmod( '2026-05-01T00:00:00Z', completedAtMs( Date.parse( '2026-06-06T00:00:00Z' ) ) )
		).toBe( true );
		expect(
			isFreshByLastmod( '2026-06-07T00:00:00Z', completedAtMs( Date.parse( '2026-06-06T00:00:00Z' ) ) )
		).toBe( false );
	} );

	test( 'existingItemsByUrl indexes items by normalized source URL', () => {
		const base = 'https://developer.wordpress.org/block-editor/reference-guides/';
		const map = existingItemsByUrl( [
			{ key: 'k1', status: 'completed', metadata: { source_url: base } },
			{ key: 'k2', status: 'completed', metadata: {} }, // no source_url -> skipped
			{ key: 'k3', status: 'completed', metadata: { source_url: `${ base }?utm=1#frag` } }, // normalizes to k1's URL -> first wins
			'not-an-object',
		] );

		expect( map.size ).toBe( 1 );
		expect( map.get( base ).key ).toBe( 'k1' );
	} );

	test( 'existingItemsByUrl keeps the newest retrieved item for duplicate source URLs', () => {
		const base = 'https://developer.wordpress.org/block-editor/reference-guides/';
		const map = existingItemsByUrl( [
			{
				key: 'old-generation',
				status: 'completed',
				metadata: {
					source_url: base,
					retrieved_at: '2026-06-06T00:00:00Z',
				},
			},
			{
				key: 'new-generation',
				status: 'completed',
				metadata: {
					source_url: `${ base }?utm=1#frag`,
					retrieved_at: '2026-06-08T00:00:00Z',
				},
			},
			{
				key: 'undated-generation',
				status: 'completed',
				metadata: {
					source_url: base,
				},
			},
		] );

		expect( map.size ).toBe( 1 );
		expect( map.get( base ).key ).toBe( 'new-generation' );
	} );

	test( 'existingItemsByUrl keeps the newest generation when retrieved_at is epoch milliseconds', () => {
		const base = 'https://developer.wordpress.org/block-editor/reference-guides/';
		// Older generation listed first: the buggy path treats numeric timestamps as
		// unparseable and keeps the first-listed item, so ordering matters here.
		const map = existingItemsByUrl( [
			{
				key: 'old-generation',
				status: 'completed',
				metadata: { source_url: base, retrieved_at: Date.parse( '2026-06-06T00:00:00Z' ) },
			},
			{
				key: 'new-generation',
				status: 'completed',
				metadata: { source_url: base, retrieved_at: Date.parse( '2026-06-08T00:00:00Z' ) },
			},
		] );

		expect( map.size ).toBe( 1 );
		expect( map.get( base ).key ).toBe( 'new-generation' );
	} );

	test( 'managedCompletedSourceUrlCount uses distinct completed managed source URLs as a regression baseline', () => {
		const base = 'https://developer.wordpress.org/reference/functions/current_user_can/';
		const second = 'https://developer.wordpress.org/reference/functions/wp_register_ability/';
		const items = [
			{
				key: 'ai-search/wp-dev/developer.wordpress.org/current-user-can/old/part-0001.md',
				status: 'completed',
				metadata: { source_url: base },
			},
			{
				key: 'ai-search/wp-dev/developer.wordpress.org/current-user-can/new/part-0001.md',
				status: 'completed',
				metadata: { source_url: `${ base }?utm=1#frag` },
			},
			{
				key: 'ai-search/wp-dev/developer.wordpress.org/wp-register-ability/new/part-0001.md',
				status: 'queued',
				metadata: { source_url: second },
			},
			{
				key: 'unmanaged/key.md',
				status: 'completed',
				metadata: { source_url: second },
			},
		];

		expect( managedCompletedSourceUrlCount( items, 'wp-dev' ) ).toBe( 1 );
	} );

	test( 'manifestEntryFromExisting normalizes Cloudflare epoch-ms timestamps to ISO strings', () => {
		const entry = manifestEntryFromExisting(
			{
				key: 'new-generation',
				metadata: {
					source_url: 'https://developer.wordpress.org/reference/functions/current_user_can/',
					title: 'current_user_can()',
					retrieved_at: Date.parse( '2026-06-09T22:10:00Z' ),
					published_at: Date.parse( '2026-05-20T00:00:00Z' ),
					content_hash: 'abc123',
				},
			},
			'https://developer.wordpress.org/reference/functions/current_user_can/'
		);

		expect( entry.retrievedAt ).toBe( '2026-06-09T22:10:00.000Z' );
		expect( entry.publishedAt ).toBe( '2026-05-20T00:00:00.000Z' );
	} );

	test( 'contentHashForEntry folds the document layout version into the hash', () => {
		const canonical = 'https://developer.wordpress.org/block-editor/';
		const markdown = '# Block Editor\n\nBody.';
		const legacyHash = crypto
			.createHash( 'sha256' )
			.update( canonical + '\n' + markdown )
			.digest( 'hex' );

		const hash = contentHashForEntry( canonical, markdown );

		expect( hash ).toMatch( /^[0-9a-f]{64}$/ );
		// Deterministic for identical inputs, sensitive to content changes.
		expect( contentHashForEntry( canonical, markdown ) ).toBe( hash );
		expect( contentHashForEntry( canonical, `${ markdown }!` ) ).not.toBe( hash );
		// Diverges from the pre-layout-version formula so stored items built under an
		// older document layout mint new keys and re-upload instead of being skipped
		// as unchanged (shouldUpload matches on content_hash; --full only re-fetches).
		expect( hash ).not.toBe( legacyHash );
	} );

	test( 'buildMarkdownDocument keeps titles in metadata without prepending a standalone H1', () => {
		const body = buildMarkdownDocument( {
			canonical: 'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/',
			title: 'Block Metadata',
			retrievedAt: '2026-06-08T12:00:00.000Z',
			publishedAt: '',
			contentHash: 'abc123',
			markdown: '# Block Metadata\n\nThe block metadata file defines block behavior.',
		} );

		expect( body ).toBe(
			[
				'---',
				'source_url: "https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/"',
				'retrieved_at: "2026-06-08T12:00:00.000Z"',
				'content_hash: "abc123"',
				'title: "Block Metadata"',
				'---',
				'',
				'# Block Metadata',
				'',
				'The block metadata file defines block behavior.',
				'',
			].join( '\n' )
		);
		expect( body ).not.toContain( '# Block Metadata\n\n# Block Metadata' );
	} );

	test( 'discoverSourceUrls captures sitemap lastmod for content URLs', async () => {
		const contentUrl = 'https://developer.wordpress.org/block-editor/reference-guides/';
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://developer.wordpress.org/robots.txt' ) {
				return mockTextResponse( 'Sitemap: https://developer.wordpress.org/sitemap.xml', href, 'text/plain' );
			}
			if ( href === 'https://developer.wordpress.org/sitemap.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						`<url><loc>${ contentUrl }</loc><lastmod>2026-05-31T11:45:20Z</lastmod></url>`,
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if (
				href === 'https://developer.wordpress.org/block-editor/wp-sitemap.xml' ||
				href === 'https://developer.wordpress.org/block-editor/sitemap.xml'
			) {
				return mockTextResponse( '<urlset></urlset>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const result = await discoverSourceUrls(
			[ 'https://developer.wordpress.org/block-editor/' ],
			{ sourceUrls: [], sourceFile: '', limit: 0 }
		);

		expect( result.urls ).toContain( contentUrl );
		expect( result.lastmods[ contentUrl ] ).toBe( '2026-05-31T11:45:20Z' );
	} );
} );
