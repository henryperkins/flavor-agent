'use strict';

const {
	discoverSourceUrls,
	evaluateSettlement,
	htmlToMarkdown,
	normalizeTrustedUrl,
	readSitemap,
	sitemapUrlWithinOrigins,
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
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const direct = await readSitemap(
			'https://developer.wordpress.org/sitemap-index.xml',
			new Set()
		);
		expect( direct.sitemaps ).toEqual( [
			'https://developer.wordpress.org/block-editor-sitemap.xml',
		] );

		const urls = await discoverSourceUrls(
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
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const urls = await discoverSourceUrls(
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
} );
