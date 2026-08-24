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
	sitemapPathPrefixesForRoots,
	manifestEntryFromExisting,
	normalizeTrustedUrl,
	parseArgs,
	pollUntilSettled,
	pollWindow,
	processEntries,
	readSitemap,
	resolveSameSourceDeletion,
	resolveStaleDeletion,
	resolveSummaryStatus,
	findSupersededSourceItems,
	sitemapUrlWithinOrigins,
	sourcePartIdentityForUrl,
	sourceRootsForRelease,
	truncateUtf8ToBytes,
	urlMatchesRoots,
	validatePublicEndpoint,
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

function pollOptions( overrides = {} ) {
	return {
		dryRun: false,
		instance: 'wp-dev',
		pollSeconds: 10,
		pollIntervalSeconds: 1,
		pollMaxSeconds: 60,
		pollProgressGraceSeconds: 5,
		...overrides,
	};
}

// Drives pollUntilSettled by scripting the two endpoints it reads: the instance /stats
// active count and the per-item status in the listing.
function settlementFetchMock( { active, itemStatus } ) {
	return jest.fn( ( url ) => {
		const href = String( url );
		if ( href.endsWith( '/stats' ) ) {
			return mockJsonResponse(
				{ result: { queued: active(), running: 0, outdated: 0, error: 0 } },
				href
			);
		}
		if ( href.includes( '/items?' ) ) {
			return mockJsonResponse(
				{
					result: [ { id: 'item-1', key: 'desired-key', status: itemStatus() } ],
					result_info: { count: 1, per_page: 50, total_count: 1 },
				},
				href
			);
		}
		throw new Error( `Unexpected fetch: ${ href }` );
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

	test( 'validates the public endpoint with current docs and release-cycle evidence', async () => {
		global.fetch = jest.fn( () =>
			mockJsonResponse( {
				result: {
					chunks: [
						{
							item: {
								metadata: {
									source_url:
										'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/',
									retrieved_at: '2026-08-20T00:00:00Z',
								},
							},
						},
						{
							item: {
								metadata: {
									source_url:
										'https://make.wordpress.org/core/2026/08/10/wordpress-7-0-dev-notes/',
									published_at: '2026-08-10T00:00:00Z',
								},
							},
						},
					],
				},
			} )
		);

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
		} );
		const request = JSON.parse( global.fetch.mock.calls[ 0 ][ 1 ].body );

		expect( request.messages[ 0 ].content ).toBe(
			'WordPress developer documentation block.json metadata reference for WordPress 7.0'
		);
		expect( validation ).toMatchObject( {
			status: 200,
			chunkCount: 2,
			sourceTypes: [ 'developer-docs', 'make-core' ],
			currentSourceTypes: [ 'developer-docs', 'make-core' ],
			freshness: {
				developerDocs: true,
				releaseCycle: true,
			},
			ok: true,
			attempts: 1,
		} );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'rejects expected corpus sources when their freshness metadata is stale', async () => {
		global.fetch = jest.fn( () =>
			mockJsonResponse( {
				result: {
					chunks: [
						{
							item: {
								metadata: {
									source_url:
										'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/',
									retrieved_at: '2026-05-01T00:00:00Z',
								},
							},
						},
						{
							item: {
								metadata: {
									source_url:
										'https://make.wordpress.org/core/2026/03/15/wordpress-7-0-field-guide/',
									published_at: '2026-03-15T00:00:00Z',
								},
							},
						},
					],
				},
			} )
		);

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: false,
			currentSourceTypes: [],
			freshness: {
				checkedAt: '2026-08-24T00:00:00.000Z',
				developerDocs: false,
				releaseCycle: false,
			},
			evidence: [
				{
					sourceType: 'developer-docs',
					retrievedAt: '2026-05-01T00:00:00.000Z',
					publishedAt: '',
					current: false,
					basis: 'stale-retrieved-at',
				},
				{
					sourceType: 'make-core',
					retrievedAt: '',
					publishedAt: '2026-03-15T00:00:00.000Z',
					current: false,
					basis: 'stale-published-at',
				},
			],
		} );
	} );

	test( 'accepts WordPress 7.0 release-cycle evidence published on the release floor', async () => {
		global.fetch = jest.fn( () =>
			mockJsonResponse( {
				result: {
					chunks: [
						{
							item: {
								metadata: {
									source_url:
										'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/',
									retrieved_at: '2026-08-20T00:00:00Z',
								},
							},
						},
						{
							item: {
								metadata: {
									source_url:
										'https://make.wordpress.org/core/2026/05/20/wordpress-7-0/',
									published_at: '2026-05-20T00:00:00Z',
								},
							},
						},
					],
				},
			} )
		);

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: true,
			currentSourceTypes: [ 'developer-docs', 'make-core' ],
			freshness: {
				developerDocs: true,
				releaseCycle: true,
			},
			evidence: [
				{ sourceType: 'developer-docs', current: true, basis: 'retrieved-at' },
				{ sourceType: 'make-core', current: true, basis: 'release-floor' },
			],
		} );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'rejects WordPress 7.0 release-cycle evidence immediately before the release floor', async () => {
		global.fetch = jest.fn( () =>
			mockJsonResponse( {
				result: {
					chunks: [
						{
							item: {
								metadata: {
									source_url:
										'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/',
									retrieved_at: '2026-08-20T00:00:00Z',
								},
							},
						},
						{
							item: {
								metadata: {
									source_url:
										'https://make.wordpress.org/core/2026/05/19/wordpress-7-0/',
									published_at: '2026-05-19T23:59:59.999Z',
								},
							},
						},
					],
				},
			} )
		);

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: false,
			currentSourceTypes: [ 'developer-docs' ],
			freshness: {
				developerDocs: true,
				releaseCycle: false,
			},
			evidence: [
				{ sourceType: 'developer-docs', current: true, basis: 'retrieved-at' },
				{ sourceType: 'make-core', current: false, basis: 'stale-published-at' },
			],
		} );
	} );

	test( 'rejects corpus sources with missing freshness timestamps', async () => {
		global.fetch = jest.fn( () =>
			mockJsonResponse( {
				result: {
					chunks: [
						{
							item: {
								metadata: {
									source_url:
										'https://developer.wordpress.org/reference/functions/register-block-type/',
								},
							},
						},
						{
							item: {
								metadata: {
									source_url:
										'https://developer.wordpress.org/news/2026/08/12/wordpress-7-0-block-metadata/',
								},
							},
						},
					],
				},
			} )
		);

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: false,
			currentSourceTypes: [],
			freshness: { developerDocs: false, releaseCycle: false },
			evidence: [
				{ sourceType: 'developer-docs', current: false, basis: 'missing-retrieved-at' },
				{ sourceType: 'developer-blog', current: false, basis: 'missing-published-at' },
			],
		} );
	} );

	test( 'rejects invalid and archive URLs from freshness evidence', async () => {
		const chunks = [
			{
				item: {
					metadata: {
						source_url: 'https://developer.wordpress.org/reference/functions/register-block-type/',
						retrieved_at: '2026-08-20T00:00:00Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'https://developer.wordpress.org/news/all-posts/',
						published_at: '2026-08-20T00:00:00Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'http://make.wordpress.org/core/2026/08/20/dev-note/',
						published_at: '2026-08-20T00:00:00Z',
					},
				},
			},
		];
		global.fetch = jest.fn( () => mockJsonResponse( { result: { chunks } } ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-1',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: false,
			currentSourceTypes: [ 'developer-docs' ],
			freshness: { developerDocs: true, releaseCycle: false },
			evidence: [
				{ sourceType: 'developer-docs', current: true, basis: 'retrieved-at' },
				{
					url: 'https://developer.wordpress.org/news/all-posts/',
					sourceType: 'developer-blog',
					current: false,
					basis: 'ineligible-source-url',
				},
				{ url: '', sourceType: '', current: false, basis: 'invalid-source-url' },
			],
		} );
	} );

	test( 'accepts freshness provenance from legacy chunk frontmatter', async () => {
		const chunks = [
			{
				text: [
					'---',
					'source_url: "https://developer.wordpress.org/reference/functions/register-block-type/"',
					'retrieved_at: "2026-08-20T00:00:00Z"',
					'---',
					'# register_block_type',
				].join( '\n' ),
			},
			{
				text: [
					'---',
					'source_url: "https://developer.wordpress.org/news/2026/08/12/wordpress-7-0-block-metadata/"',
					'published_at: "2026-08-12T00:00:00Z"',
					'---',
					'# WordPress 7.0 block metadata',
				].join( '\n' ),
			},
		];
		global.fetch = jest.fn( () => mockJsonResponse( { result: { chunks } } ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: true,
			currentSourceTypes: [ 'developer-docs', 'developer-blog' ],
			evidence: [
				{
					retrievedAt: '2026-08-20T00:00:00.000Z',
					current: true,
					basis: 'retrieved-at',
				},
				{
					publishedAt: '2026-08-12T00:00:00.000Z',
					current: true,
					basis: 'published-at',
				},
			],
		} );
	} );

	test( 'prefers structured item metadata over legacy frontmatter', async () => {
		const chunks = [
			{
				item: {
					metadata: {
						source_url: 'https://developer.wordpress.org/reference/functions/register-block-type/',
						retrieved_at: '2026-08-20T00:00:00Z',
					},
				},
				text: [
					'---',
					'source_url: "http://developer.wordpress.org/reference/functions/register-block-type/"',
					'retrieved_at: "2099-01-01T00:00:00Z"',
					'---',
				].join( '\n' ),
			},
			{
				item: {
					metadata: {
						source_url: 'https://make.wordpress.org/core/2026/08/12/wordpress-7-0-dev-note/',
						published_at: '2026-08-12T00:00:00Z',
					},
				},
				text: [
					'---',
					'source_url: "https://make.wordpress.org/core/2026/03/15/old-note/"',
					'published_at: "2026-03-15T00:00:00Z"',
					'---',
				].join( '\n' ),
			},
		];
		global.fetch = jest.fn( () => mockJsonResponse( { result: { chunks } } ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-1',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: true,
			currentSourceTypes: [ 'developer-docs', 'make-core' ],
			evidence: [
				{ current: true, basis: 'retrieved-at' },
				{ current: true, basis: 'published-at' },
			],
		} );
	} );

	test( 'prefers structured camelCase metadata over snake_case frontmatter aliases', async () => {
		const chunks = [
			{
				item: {
					metadata: {
						sourceUrl: 'https://developer.wordpress.org/reference/functions/register-block-type/',
						retrievedAt: '2026-08-20T00:00:00Z',
					},
				},
				text: [
					'---',
					'source_url: "http://developer.wordpress.org/reference/functions/register-block-type/"',
					'retrieved_at: "2099-01-01T00:00:00Z"',
					'---',
				].join( '\n' ),
			},
			{
				item: {
					metadata: {
						sourceUrl: 'https://make.wordpress.org/core/2026/08/12/wordpress-7-0-dev-note/',
						publishedAt: '2026-08-12T00:00:00Z',
					},
				},
				text: [
					'---',
					'source_url: "https://make.wordpress.org/core/2026/03/15/old-note/"',
					'published_at: "2026-03-15T00:00:00Z"',
					'---',
				].join( '\n' ),
			},
		];
		global.fetch = jest.fn( () => mockJsonResponse( { result: { chunks } } ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-1',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: true,
			currentSourceTypes: [ 'developer-docs', 'make-core' ],
			evidence: [
				{
					url: 'https://developer.wordpress.org/reference/functions/register-block-type/',
					retrievedAt: '2026-08-20T00:00:00.000Z',
					current: true,
				},
				{
					url: 'https://make.wordpress.org/core/2026/08/12/wordpress-7-0-dev-note/',
					publishedAt: '2026-08-12T00:00:00.000Z',
					current: true,
				},
			],
		} );
	} );

	test( 'rejects freshness timestamps that are later than validation time', async () => {
		const chunks = [
			{
				item: {
					metadata: {
						source_url: 'https://developer.wordpress.org/reference/functions/register-block-type/',
						retrieved_at: '2099-01-01T00:00:00Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url:
							'https://developer.wordpress.org/news/2026/08/12/wordpress-7-0-block-metadata/',
						published_at: '2099-01-01T00:00:00Z',
					},
				},
			},
		];
		global.fetch = jest.fn( () => mockJsonResponse( { result: { chunks } } ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-1',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: false,
			currentSourceTypes: [],
			freshness: { developerDocs: false, releaseCycle: false },
			evidence: [
				{ sourceType: 'developer-docs', current: false, basis: 'future-retrieved-at' },
				{ sourceType: 'developer-blog', current: false, basis: 'future-published-at' },
			],
		} );
	} );

	test( 'accepts every source at its rolling freshness boundary and records optional sources', async () => {
		const chunks = [
			{
				item: {
					metadata: {
						source_url: 'https://developer.wordpress.org/reference/functions/register-block-type/',
						retrieved_at: '2026-05-26T00:00:00Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url:
							'https://developer.wordpress.org/news/2026/07/10/wordpress-7-0-block-metadata/',
						published_at: '2026-07-10T00:00:00Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'https://make.wordpress.org/core/2026/08/03/wordpress-7-0-dev-notes/',
						published_at: '2026-08-03T00:00:00Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'https://make.wordpress.org/ai/2026/08/03/ai-client-update/',
						published_at: '2026-08-03T00:00:00Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'https://wordpress.org/news/2026/08/wordpress-7-0-release/',
						published_at: '2026-08-03T00:00:00Z',
					},
				},
			},
		];
		global.fetch = jest.fn( () => mockJsonResponse( { result: { chunks } } ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: true,
			currentSourceTypes: [
				'developer-docs',
				'developer-blog',
				'make-core',
				'make-ai',
				'wordpress-news',
			],
			freshness: { developerDocs: true, releaseCycle: true },
		} );
		expect( validation.evidence.map( ( item ) => item.basis ) ).toEqual( [
			'retrieved-at',
			'published-at',
			'published-at',
			'published-at',
			'published-at',
		] );
	} );

	test( 'rejects every source immediately before its rolling freshness boundary', async () => {
		const chunks = [
			{
				item: {
					metadata: {
						source_url: 'https://developer.wordpress.org/reference/functions/register-block-type/',
						retrieved_at: '2026-05-25T23:59:59.999Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url:
							'https://developer.wordpress.org/news/2026/07/09/wordpress-7-1-block-metadata/',
						published_at: '2026-07-09T23:59:59.999Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'https://make.wordpress.org/core/2026/08/02/wordpress-7-1-dev-notes/',
						published_at: '2026-08-02T23:59:59.999Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'https://make.wordpress.org/ai/2026/08/02/ai-client-update/',
						published_at: '2026-08-02T23:59:59.999Z',
					},
				},
			},
			{
				item: {
					metadata: {
						source_url: 'https://wordpress.org/news/2026/08/wordpress-7-1-release/',
						published_at: '2026-08-02T23:59:59.999Z',
					},
				},
			},
		];
		global.fetch = jest.fn( () => mockJsonResponse( { result: { chunks } } ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-1',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( { ok: false, currentSourceTypes: [] } );
		expect( validation.evidence.map( ( item ) => item.basis ) ).toEqual( [
			'stale-retrieved-at',
			'stale-published-at',
			'stale-published-at',
			'stale-published-at',
			'stale-published-at',
		] );
	} );

	test( 'retries public endpoint validation past an empty post-ingest index resync', async () => {
		// Cloudflare answers a failed retrieval with HTTP 200 and no chunks while the index
		// resyncs after ingest, so the first probes look like an empty corpus.
		const usableResult = {
			result: {
				chunks: [
					{
						item: {
							metadata: {
								source_url:
									'https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/',
								retrieved_at: '2026-08-20T00:00:00Z',
							},
						},
					},
					{
						item: {
							metadata: {
								source_url:
									'https://developer.wordpress.org/news/2026/08/12/wordpress-7-0-block-metadata/',
								published_at: '2026-08-12T00:00:00Z',
							},
						},
					},
				],
			},
		};
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const responses = [
			{ result: { chunks: [] } },
			// Partially rebuilt index: results exist but none are developer docs.
			{
				result: {
					chunks: [
						{ item: { metadata: { source_url: 'https://make.wordpress.org/ai/2026/04/01/hello/' } } },
					],
				},
			},
			usableResult,
		];
		global.fetch = jest.fn( () => mockJsonResponse( responses.shift(), 'https://example.com/search' ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 5,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( { ok: true, chunkCount: 2, attempts: 3 } );
		expect( global.fetch ).toHaveBeenCalledTimes( 3 );
	} );

	test( 'reports validation failure with a return_on_failure-free diagnostic probe', async () => {
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		global.fetch = jest.fn( () =>
			mockJsonResponse( { result: { chunks: [] } }, 'https://example.com/search' )
		);

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			validationAttempts: 3,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( { ok: false, chunkCount: 0, attempts: 3 } );
		// Three validation attempts, then one diagnostic probe.
		expect( global.fetch ).toHaveBeenCalledTimes( 4 );

		const attemptBody = JSON.parse( global.fetch.mock.calls[ 0 ][ 1 ].body );
		expect( attemptBody.ai_search_options.retrieval.return_on_failure ).toBe( true );

		const diagnosticBody = JSON.parse( global.fetch.mock.calls[ 3 ][ 1 ].body );
		expect( diagnosticBody.ai_search_options.retrieval ).not.toHaveProperty( 'return_on_failure' );
		expect( validation.diagnostic ).toMatchObject( { status: 200, chunkCount: 0 } );
	} );

	test( 'records empty freshness evidence when public validation cannot connect', async () => {
		global.fetch = jest.fn( () => Promise.reject( new Error( 'network unavailable' ) ) );

		const validation = await validatePublicEndpoint( {
			publicUrl: 'https://example.com/search',
			release: '7-0',
			now: Date.parse( '2026-08-24T00:00:00Z' ),
			validationAttempts: 1,
			validationRetryDelayMs: 0,
		} );

		expect( validation ).toMatchObject( {
			ok: false,
			error: 'network unavailable',
			currentSourceTypes: [],
			evidence: [],
			freshness: {
				checkedAt: '2026-08-24T00:00:00.000Z',
				developerDocs: false,
				releaseCycle: false,
			},
			diagnostic: { error: 'network unavailable' },
		} );
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

	test( 'sitemapUrlWithinOrigins scopes wordpress.org sitemaps to trusted path prefixes', () => {
		const roots = [ 'https://wordpress.org/news/', 'https://developer.wordpress.org/block-editor/' ];
		const allowedOrigins = new Set( roots.map( ( root ) => new URL( root ).origin ) );
		const pathPrefixes = sitemapPathPrefixesForRoots( roots );

		expect( pathPrefixes.get( 'https://wordpress.org' ) ).toEqual( [ '/news/' ] );
		expect( pathPrefixes.has( 'https://developer.wordpress.org' ) ).toBe( false );

		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/news/sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( 'https://wordpress.org/news/sitemap.xml' );
		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( '' );
		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/news-sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( '' );
		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/plugins/sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( '' );
		expect(
			sitemapUrlWithinOrigins( 'https://developer.wordpress.org/wp-sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( 'https://developer.wordpress.org/wp-sitemap.xml' );
	} );

	test( 'discoverSourceUrls never crawls wordpress.org sitemaps outside /news/', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://wordpress.org/robots.txt' ) {
				return mockTextResponse(
					[
						'Sitemap: https://wordpress.org/sitemap.xml',
						'Sitemap: https://wordpress.org/news-sitemap.xml',
						'Sitemap: https://wordpress.org/plugins/sitemap.xml',
						'Sitemap: https://wordpress.org/news/sitemap.xml',
					].join( '\n' ),
					href,
					'text/plain'
				);
			}
			if ( href === 'https://wordpress.org/news/sitemap.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://wordpress.org/news/wp-sitemap.xml' ) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://wordpress.org/news/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				recentPostMaxAgeDays: 180,
				now: Date.parse( '2026-07-12T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/',
		] );
		const fetched = global.fetch.mock.calls.map( ( call ) => String( call[ 0 ] ) );
		expect( fetched ).not.toContain( 'https://wordpress.org/sitemap.xml' );
		expect( fetched ).not.toContain( 'https://wordpress.org/news-sitemap.xml' );
		expect( fetched ).not.toContain( 'https://wordpress.org/plugins/sitemap.xml' );
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

	test( 'discoverSourceUrls keeps recent make/ai posts and drops xposts and handbook pages', async () => {
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
						'<url><loc>https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/</loc></url>',
						'<url><loc>https://make.wordpress.org/ai/2026/06/29/xpost-wordpress-credits-updates/</loc></url>',
						'<url><loc>https://make.wordpress.org/ai/handbook/</loc></url>',
						'<url><loc>https://make.wordpress.org/ai/2025/01/05/old-ai-post/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if (
				href === 'https://make.wordpress.org/ai/wp-sitemap.xml' ||
				href === 'https://make.wordpress.org/ai/sitemap.xml'
			) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://make.wordpress.org/ai/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				recentPostMaxAgeDays: 180,
				now: Date.parse( '2026-07-12T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/',
		] );
	} );

	test( 'discoverSourceUrls keeps recent month-dated News posts and drops archives', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://wordpress.org/robots.txt' ) {
				return mockTextResponse(
					'Sitemap: https://wordpress.org/news/sitemap.xml',
					href,
					'text/plain'
				);
			}
			if ( href === 'https://wordpress.org/news/sitemap.xml' ) {
				return mockTextResponse(
					[
						'<sitemapindex>',
						'<sitemap><loc>https://wordpress.org/news/sitemap-2.xml</loc></sitemap>',
						'</sitemapindex>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://wordpress.org/news/sitemap-2.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/</loc></url>',
						'<url><loc>https://wordpress.org/news/2020/08/older-post/</loc></url>',
						'<url><loc>https://wordpress.org/news/category/releases/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://wordpress.org/news/wp-sitemap.xml' ) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://wordpress.org/news/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				recentPostMaxAgeDays: 180,
				now: Date.parse( '2026-07-12T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/',
		] );
	} );

	test( 'isCorpusDocumentUrl drops release-cycle index, archive, and xpost pages', () => {
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/2026/05/01/post/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/block-editor/' ) ).toBe( true );

		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/all-posts/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/tag/block-editor/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/tag/dev-notes-7-0/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/handbook/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/2026/06/29/xpost-wordpress-credits-updates/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/2026/06/01/xpost-editor-updates/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/2026/07/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/category/releases/' ) ).toBe( false );
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
			'https://make.wordpress.org/ai/',
			'https://wordpress.org/news/',
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
		// Validation that returned results but not the expected ones is noisy, not blind.
		expect(
			resolveStaleDeletion( { ...healthy, validationOk: false, validationChunkCount: 8 } )
		).toEqual( {
			delete: true,
			reason: 'validation-warning',
		} );
		// Zero chunks means the retrieval index told us nothing → never prune on that.
		expect(
			resolveStaleDeletion( { ...healthy, validationOk: false, validationChunkCount: 0 } )
		).toEqual( {
			delete: false,
			reason: 'validation-unavailable',
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
			validationChunkCount: 8,
			previousManifestCount: 13000,
		};

		expect( resolveSummaryStatus( run ) ).toBe( 'needs-attention' );
		expect( resolveStaleDeletion( run ) ).toEqual( {
			delete: true,
			reason: 'validation-warning',
		} );
	} );

	test( 'resolveSameSourceDeletion gates superseded generations on settlement and retrieval', () => {
		const settled = {
			dryRun: false,
			pollSkipped: false,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationChunkCount: 8,
		};

		expect( resolveSameSourceDeletion( settled ) ).toEqual( {
			delete: true,
			reason: 'settled-current-sources',
		} );

		// Item-level settlement problems keep the prior behaviour.
		expect( resolveSameSourceDeletion( { ...settled, dryRun: true } ).delete ).toBe( false );
		expect( resolveSameSourceDeletion( { ...settled, pollSkipped: true } ).reason ).toBe(
			'replacement-not-settled'
		);
		expect( resolveSameSourceDeletion( { ...settled, uploadErrors: 1 } ).reason ).toBe(
			'replacement-not-settled'
		);
		expect( resolveSameSourceDeletion( { ...settled, pollPending: 1 } ).reason ).toBe(
			'replacement-not-settled'
		);
		expect( resolveSameSourceDeletion( { ...settled, pollErrors: 1 } ).reason ).toBe(
			'replacement-not-settled'
		);

		// A settled replacement is still not safe to swap in while retrieval is dark:
		// deleting the old generation could leave the source with no searchable copy.
		expect( resolveSameSourceDeletion( { ...settled, validationChunkCount: 0 } ) ).toEqual( {
			delete: false,
			reason: 'validation-unavailable',
		} );
		// Degraded-but-present retrieval is only a warning, exactly as for stale deletion.
		expect(
			resolveSameSourceDeletion( { ...settled, validationChunkCount: 1 } ).delete
		).toBe( true );
	} );

	test( 'both destructive paths refuse to delete on the same zero-chunk evidence', () => {
		// Regression guard for the real gap: same-source generations are deleted earlier in
		// main() than resolveStaleDeletion() runs, so a zero-chunk run could skip bulk stale
		// deletion while still pruning superseded generations. The two resolvers must agree.
		const zeroChunk = {
			dryRun: false,
			deleteStale: true,
			explicitSources: false,
			limit: 0,
			discoveryErrors: 0,
			pollSkipped: false,
			discovered: 13317,
			prepared: 13316,
			buildErrors: 0,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: false,
			validationChunkCount: 0,
			previousManifestCount: 13000,
		};

		expect( resolveStaleDeletion( zeroChunk ).delete ).toBe( false );
		expect( resolveSameSourceDeletion( zeroChunk ).delete ).toBe( false );
		expect( resolveStaleDeletion( zeroChunk ).reason ).toBe( 'validation-unavailable' );
		expect( resolveSameSourceDeletion( zeroChunk ).reason ).toBe( 'validation-unavailable' );

		// ...and both allow deletion once retrieval proves the corpus is answerable.
		const retrievable = { ...zeroChunk, validationOk: true, validationChunkCount: 8 };
		expect( resolveStaleDeletion( retrievable ).delete ).toBe( true );
		expect( resolveSameSourceDeletion( retrievable ).delete ).toBe( true );
	} );

	test( 'resolveStaleDeletion refuses to prune when the retrieval index returned no chunks', () => {
		// Regression guard for the 2026-08-10 scheduled run, which pruned 8 managed items
		// under `validation-warning` even though the public endpoint had answered HTTP 200
		// with zero chunks — i.e. the run had no evidence the corpus was retrievable.
		const run = {
			dryRun: false,
			deleteStale: true,
			explicitSources: false,
			limit: 0,
			discoveryErrors: 0,
			pollSkipped: false,
			discovered: 13317,
			prepared: 13316,
			buildErrors: 0,
			uploadErrors: 0,
			pollPending: 0,
			pollErrors: 0,
			validationOk: false,
			validationChunkCount: 0,
			previousManifestCount: 13000,
		};

		expect( resolveSummaryStatus( run ) ).toBe( 'needs-attention' );
		expect( resolveStaleDeletion( run ) ).toEqual( {
			delete: false,
			reason: 'validation-unavailable',
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

	test( 'workflow enables stale deletion only for the Monday scheduled run', () => {
		const workflow = fs.readFileSync(
			path.resolve( __dirname, '../../.github/workflows/update-docs-ai-search.yml' ),
			'utf8'
		);

		expect( workflow ).toContain(
			"INPUT_DELETE_STALE: ${{ github.event_name == 'schedule' && github.event.schedule == '17 5 * * 1' && 'true' || github.event.inputs.delete_stale || 'false' }}"
		);
		expect( workflow ).toContain( "- cron: '17 5 * * 0,2-6'" );
		expect( workflow ).toContain( "- cron: '17 5 * * 1'" );
		expect( workflow ).toMatch( /concurrency:\s*\n\s*group: update-docs-ai-search\s*\n\s*cancel-in-progress: false/ );
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
		expect( runbook ).toContain( 'https://wordpress.org/news/' );
		expect( runbook ).toContain( 'https://make.wordpress.org/ai/' );
		expect( runbook ).toContain( '--recent-post-max-age-days' );
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

		expect( result ).toMatchObject( {
			skipped: false,
			pending: 0,
			errors: [],
			polls: 1,
			initialActive: 0,
			bestActive: 0,
			finalActive: 0,
			extended: false,
		} );
		expect( global.fetch.mock.calls[ 0 ][ 0 ] ).toContain( '/stats' );
		expect(
			global.fetch.mock.calls.filter( ( [ url ] ) => String( url ).includes( '/items?' ) )
		).toHaveLength( 1 );
	} );

	test( 'pollWindow never lets the hard cap shorten an explicitly requested base window', () => {
		expect( pollWindow( { pollSeconds: 600 } ) ).toEqual( {
			baseMs: 600_000,
			hardMs: 1_800_000,
			graceMs: 120_000,
			intervalMs: 5000,
		} );
		// An operator asking for a 3600s base must not be cut back to the 1800s default cap.
		expect( pollWindow( { pollSeconds: 3600 } ).hardMs ).toBe( 3_600_000 );
		expect(
			pollWindow( { pollSeconds: 60, pollMaxSeconds: 90, pollProgressGraceSeconds: 10, pollIntervalSeconds: 2 } )
		).toEqual( { baseMs: 60_000, hardMs: 90_000, graceMs: 10_000, intervalMs: 2000 } );
	} );

	test( 'pollUntilSettled retries the item sweep when /stats clears before the listing agrees', async () => {
		// Regression guard for run 31462270794: /stats read zero while one desired key was
		// still queued in the item listing. The old single-shot sweep failed the whole run on
		// that momentary disagreement instead of re-checking inside the remaining window.
		let itemsCall = 0;
		global.fetch = settlementFetchMock( {
			active: () => 0,
			itemStatus: () => ( itemsCall++ === 0 ? 'queued' : 'completed' ),
		} );

		jest.useFakeTimers();
		try {
			const promise = pollUntilSettled(
				new Set( [ 'desired-key' ] ),
				pollOptions( { pollSeconds: 10 } ),
				{ accountId: 'account', apiToken: 'token' }
			);
			await jest.advanceTimersByTimeAsync( 30_000 );
			const result = await promise;

			expect( result ).toMatchObject( { skipped: false, pending: 0, errors: [], polls: 2 } );
		} finally {
			jest.useRealTimers();
		}
	} );

	test( 'pollUntilSettled extends past the base window while the active count keeps falling', async () => {
		let poll = 0;
		global.fetch = settlementFetchMock( {
			// 20 → 0 over twenty polls at one second each, so success lands well past the
			// ten-second base window.
			active: () => Math.max( 0, 20 - poll++ ),
			itemStatus: () => 'completed',
		} );

		jest.useFakeTimers();
		try {
			const promise = pollUntilSettled(
				new Set( [ 'desired-key' ] ),
				pollOptions( { pollSeconds: 10, pollMaxSeconds: 120, pollProgressGraceSeconds: 5 } ),
				{ accountId: 'account', apiToken: 'token' }
			);
			await jest.advanceTimersByTimeAsync( 120_000 );
			const result = await promise;

			expect( result ).toMatchObject( { pending: 0, errors: [], initialActive: 20, bestActive: 0 } );
			expect( result.extended ).toBe( true );
			expect( result.elapsedMs ).toBeGreaterThan( 10_000 );
			expect( result.elapsedMs ).toBeLessThan( 120_000 );
		} finally {
			jest.useRealTimers();
		}
	} );

	test( 'pollUntilSettled stops a plateau after the progress grace instead of waiting out the cap', async () => {
		global.fetch = settlementFetchMock( { active: () => 3, itemStatus: () => 'queued' } );

		jest.useFakeTimers();
		try {
			const promise = pollUntilSettled(
				new Set( [ 'desired-key' ] ),
				// A ten-minute cap the plateau must NOT consume.
				pollOptions( { pollSeconds: 10, pollMaxSeconds: 600, pollProgressGraceSeconds: 5 } ),
				{ accountId: 'account', apiToken: 'token' }
			);
			await jest.advanceTimersByTimeAsync( 600_000 );
			const result = await promise;

			// A stuck item stays pending — extension never reclassifies it as settled.
			expect( result.pending ).toBe( 1 );
			expect( result.bestActive ).toBe( 3 );
			expect( result.elapsedMs ).toBeLessThan( 30_000 );
			expect( result.lastProgressAgeMs ).toBeGreaterThanOrEqual( 5000 );
		} finally {
			jest.useRealTimers();
		}
	} );

	test( 'pollUntilSettled enforces the hard cap even while still making progress', async () => {
		let poll = 0;
		global.fetch = settlementFetchMock( {
			// Always a new low, so only the hard cap can stop this.
			active: () => 1000 - poll++,
			itemStatus: () => 'queued',
		} );

		jest.useFakeTimers();
		try {
			const promise = pollUntilSettled(
				new Set( [ 'desired-key' ] ),
				pollOptions( { pollSeconds: 10, pollMaxSeconds: 20, pollProgressGraceSeconds: 100_000 } ),
				{ accountId: 'account', apiToken: 'token' }
			);
			await jest.advanceTimersByTimeAsync( 300_000 );
			const result = await promise;

			expect( result.pending ).toBe( 1 );
			expect( result.elapsedMs ).toBeGreaterThanOrEqual( 20_000 );
			expect( result.elapsedMs ).toBeLessThan( 30_000 );
			expect( result.extended ).toBe( true );
		} finally {
			jest.useRealTimers();
		}
	} );

	test( 'pollUntilSettled reports residual pending from a final item-level sweep', async () => {
		// The loop's last observation is not authoritative: the returned pending count comes
		// from a fresh sweep after the window closes.
		let itemsCall = 0;
		global.fetch = settlementFetchMock( {
			active: () => 2,
			// Every in-loop sweep is skipped (stats never clear); the final sweep is the only
			// listing call, and it is what decides the reported pending count.
			itemStatus: () => {
				itemsCall += 1;
				return 'completed';
			},
		} );

		jest.useFakeTimers();
		try {
			const promise = pollUntilSettled(
				new Set( [ 'desired-key' ] ),
				pollOptions( { pollSeconds: 10, pollMaxSeconds: 20, pollProgressGraceSeconds: 5 } ),
				{ accountId: 'account', apiToken: 'token' }
			);
			await jest.advanceTimersByTimeAsync( 60_000 );
			const result = await promise;

			expect( itemsCall ).toBe( 1 );
			expect( result.pending ).toBe( 0 );
			expect( result.errors ).toEqual( [] );
		} finally {
			jest.useRealTimers();
		}
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
