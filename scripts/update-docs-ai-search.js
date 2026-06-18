#!/usr/bin/env node
'use strict';

const crypto = require( 'node:crypto' );
const fsp = require( 'node:fs/promises' );
const path = require( 'node:path' );

const DEFAULT_INSTANCE = 'wp-dev-docs';
const DEFAULT_PUBLIC_SEARCH_URL = 'https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search';
const DEFAULT_RELEASE = '7-0';
const DEFAULT_OUTPUT_DIR = path.resolve( __dirname, '..', 'output', 'docs-ai-search' );
const LEGACY_ITEM_KEY_PREFIX = 'wp-dev-docs-';
const USER_AGENT = 'Flavor Agent Developer Docs AI Search Updater (+https://github.com/henryperkins/flavor-agent)';
const MAX_UPLOAD_BYTES = 3_900_000;
const KEY_MAX_BYTES = 128;
const SITEMAP_CONCURRENCY = 4;
const PAGE_CONCURRENCY = 5;
const FETCH_RETRIES = 2;
const FETCH_RETRY_BASE_MS = 500;
const RETRYABLE_STATUS = new Set( [ 429, 500, 502, 503, 504 ] );
const REQUEST_TIMEOUT_MS = 30_000;
const MAKE_CORE_DEFAULT_MAX_AGE_DAYS = 180;
const DAY_MS = 86_400_000;
const MAX_FETCH_BYTES = 12_000_000;
const PREPARED_REGRESSION_RATIO = 0.8;
const BUILD_ERROR_ATTENTION_RATIO = 0.02;
// Bump whenever buildMarkdownDocument's stored layout changes (v2: dropped the
// standalone `# title` H1 that produced title-only first chunks). The version is
// folded into the content hash, so every changed item mints a new key and
// re-uploads on the next `--full` run. Superseded same-source generations are
// pruned after the replacement key settles.
const DOC_LAYOUT_VERSION = 2;
const VALIDATION_QUERY = 'WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes';

const METADATA_SCHEMA = [
	{ field_name: 'source_url', data_type: 'text' },
	{ field_name: 'retrieved_at', data_type: 'datetime' },
	{ field_name: 'published_at', data_type: 'datetime' },
	{ field_name: 'content_hash', data_type: 'text' },
	{ field_name: 'title', data_type: 'text' },
];

function usage() {
	return `Update the wp-dev-docs Cloudflare AI Search Developer Docs corpus.

Usage:
  node scripts/update-docs-ai-search.js [options]

Options:
  --release=<slug>       Active WordPress major release slug, e.g. 7-0.
  --instance=<id>        AI Search instance ID. Defaults to env or wp-dev-docs.
  --public-url=<url>     Public /search endpoint used for validation.
  --source-url=<url>     Restrict the run to specific trusted URLs (replaces sitemap
                         discovery). May be repeated. Targeted runs never delete stale items.
  --source-file=<path>   JSON array or newline-delimited trusted URL list (replaces sitemap
                         discovery; targeted runs never delete stale items).
  --limit=<n>            Limit discovered URLs, useful for smoke tests.
  --make-core-max-age-days=<n>  Only ingest make.wordpress.org/core posts published
                         within this many days (by /core/YYYY/MM/DD/ permalink date).
                         0 ingests every matched post. Default: 180.
  --dry-run              Discover and build payloads without Cloudflare writes.
  --delete-stale         Opt in to deleting stale managed docs items. Off by default and
                         always skipped for --limit, --source-url/--source-file, and any
                         run with discovery/build/upload/poll/validation problems.
  --no-delete            Force stale deletion off (overrides --delete-stale).
  --full                 Re-fetch every discovered URL even if unchanged since the
                         last ingest (bypasses the sitemap-lastmod incremental skip).
  --configure            Update the instance metadata/search configuration. This can
                         trigger an instance-wide Cloudflare resync; leave off for
                         normal corpus updates.
  --skip-configure       Compatibility no-op; configuration is skipped by default.
  --poll-seconds=<n>     Poll uploaded items before validation. Default: 180.
  --output=<dir>         Write summary artifacts here.
  --help, -h             Show this message.

Required environment for non-dry-run writes:
  CLOUDFLARE_ACCOUNT_ID
  CLOUDFLARE_AI_SEARCH_API_TOKEN
`;
}

function parseArgs( argv ) {
	const options = {
		release: process.env.WP_DOCS_RELEASE || DEFAULT_RELEASE,
		instance: process.env.CLOUDFLARE_AI_SEARCH_INSTANCE || DEFAULT_INSTANCE,
		publicUrl: process.env.CLOUDFLARE_AI_SEARCH_PUBLIC_URL || DEFAULT_PUBLIC_SEARCH_URL,
		sourceUrls: [],
		sourceFile: '',
		limit: 0,
		makeCoreMaxAgeDays: MAKE_CORE_DEFAULT_MAX_AGE_DAYS,
		dryRun: false,
		deleteStale: false,
		configureInstance: false,
		pollSeconds: 180,
		fullRefetch: false,
		outputDir: DEFAULT_OUTPUT_DIR,
	};

	for ( const arg of argv ) {
		if ( arg === '--help' || arg === '-h' ) {
			console.log( usage() );
			process.exit( 0 );
		}
		if ( arg === '--dry-run' ) {
			options.dryRun = true;
			continue;
		}
		if ( arg === '--delete-stale' ) {
			options.deleteStale = true;
			continue;
		}
		if ( arg === '--no-delete' ) {
			options.deleteStale = false;
			continue;
		}
		if ( arg === '--full' || arg === '--force-refetch' ) {
			options.fullRefetch = true;
			continue;
		}
		if ( arg === '--skip-configure' ) {
			options.configureInstance = false;
			continue;
		}
		if ( arg === '--configure' ) {
			options.configureInstance = true;
			continue;
		}

		const match = arg.match( /^--([^=]+)=(.*)$/ );
		if ( ! match ) {
			throw new Error( `Unknown argument: ${ arg }` );
		}

		const key = match[ 1 ];
		const value = match[ 2 ];
		switch ( key ) {
			case 'release':
				options.release = value;
				break;
			case 'instance':
				options.instance = value;
				break;
			case 'public-url':
				options.publicUrl = value;
				break;
			case 'source-url':
				options.sourceUrls.push( value );
				break;
			case 'source-file':
				options.sourceFile = value;
				break;
			case 'limit':
				options.limit = normalizeNonNegativeInteger( value, 'limit' );
				break;
			case 'make-core-max-age-days':
				options.makeCoreMaxAgeDays = normalizeNonNegativeInteger( value, 'make-core-max-age-days' );
				break;
			case 'poll-seconds':
				options.pollSeconds = normalizeNonNegativeInteger( value, 'poll-seconds' );
				break;
			case 'output':
				options.outputDir = path.resolve( value );
				break;
			default:
				throw new Error( `Unknown argument: ${ arg }` );
		}
	}

	options.release = normalizeRelease( options.release );
	options.instance = normalizeInstanceId( options.instance );
	options.publicUrl = normalizePublicSearchUrl( options.publicUrl );

	return options;
}

function normalizeNonNegativeInteger( value, label ) {
	const raw = String( value ?? '' ).trim();
	if ( ! /^\d+$/.test( raw ) ) {
		throw new Error( `--${ label } must be a non-negative integer.` );
	}
	return Number( raw );
}

function normalizeRelease( value ) {
	const release = String( value || '' ).trim().toLowerCase();
	if ( ! /^[0-9]+-[0-9]+$/.test( release ) ) {
		throw new Error( 'Release slug must look like 7-0.' );
	}
	return release;
}

function normalizeInstanceId( value ) {
	const instance = String( value || '' ).trim();
	if ( ! /^[a-z0-9_-]+$/.test( instance ) ) {
		throw new Error( 'AI Search instance ID must contain only lowercase letters, numbers, hyphens, and underscores.' );
	}
	return instance;
}

function normalizePublicSearchUrl( value ) {
	const url = new URL( String( value || '' ).trim() );
	if ( url.protocol !== 'https:' || ! url.hostname.endsWith( '.search.ai.cloudflare.com' ) ) {
		throw new Error( 'Public AI Search URL must be an https://*.search.ai.cloudflare.com/search endpoint.' );
	}
	url.pathname = url.pathname.replace( /\/+$/, '' ) || '/search';
	if ( url.pathname === '/mcp' ) {
		url.pathname = '/search';
	}
	if ( url.pathname !== '/search' || url.search || url.hash ) {
		throw new Error( 'Public AI Search URL must not include query/fragment and must end in /search.' );
	}
	return url.toString();
}

function sourceRootsForRelease() {
	// developer.wordpress.org posts live directly under these handbook/news roots, so a
	// urlMatchesRoots() startsWith match discovers them from the sitemaps. Make/Core
	// release-cycle posts live under dated /core/YYYY/MM/DD/ permalinks rather than under
	// the release hub or tag archives, so the trusted root is the broad /core/ scope and
	// discoverSourceUrls() then bounds them to recent posts via withinMakeCoreWindow() to
	// avoid pulling the decade-long Make/Core archive.
	return [
		'https://developer.wordpress.org/block-editor/',
		'https://developer.wordpress.org/rest-api/',
		'https://developer.wordpress.org/themes/',
		'https://developer.wordpress.org/reference/',
		'https://developer.wordpress.org/news/',
		'https://make.wordpress.org/core/',
	].map( ( value ) => normalizeTrustedUrl( value ) ).filter( Boolean );
}

function normalizeTrustedUrl( value, base = '' ) {
	let url;
	const raw = String( value || '' ).trim();
	if ( ! raw ) {
		return '';
	}
	try {
		url = base ? new URL( raw, base ) : new URL( raw );
	} catch {
		return '';
	}

	if (
		url.protocol !== 'https:' ||
		url.username ||
		url.password ||
		( url.port && url.port !== '443' )
	) {
		return '';
	}

	url.hash = '';
	url.search = '';
	url.pathname = url.pathname.replace( /\/{2,}/g, '/' );

	if ( ! isTrustedPath( url ) ) {
		return '';
	}

	return url.toString();
}

function isTrustedPath( url ) {
	const host = url.hostname.toLowerCase();
	const pathName = url.pathname;

	if ( host === 'developer.wordpress.org' ) {
		return [
			'/block-editor/',
			'/rest-api/',
			'/themes/',
			'/reference/',
			'/news/',
		].some( ( prefix ) => pathName === prefix.slice( 0, -1 ) || pathName.startsWith( prefix ) );
	}

	if ( host === 'make.wordpress.org' ) {
		return pathName === '/core' || pathName.startsWith( '/core/' );
	}

	return false;
}

function urlMatchesRoots( value, roots ) {
	let url;
	try {
		url = new URL( value );
	} catch {
		return false;
	}
	const urlPath = url.pathname.replace( /\/+$/, '' );
	return roots.some( ( rootValue ) => {
		let root;
		try {
			root = new URL( rootValue );
		} catch {
			return false;
		}
		if ( url.origin !== root.origin ) {
			return false;
		}
		const rootPath = root.pathname.replace( /\/+$/, '' );
		return urlPath === rootPath || urlPath.startsWith( `${ rootPath }/` );
	} );
}

// Make/Core posts use dated permalinks (/core/YYYY/MM/DD/slug/). Parse that date so
// discovery can keep the current release cycle and drop the long-tail archive. Returns
// the UTC publish-day timestamp in ms, or null for non-make-core or undated URLs.
function makeCorePostDate( url ) {
	let parsed;
	try {
		parsed = new URL( url );
	} catch {
		return null;
	}
	if ( parsed.hostname.toLowerCase() !== 'make.wordpress.org' ) {
		return null;
	}
	const match = parsed.pathname.match( /^\/core\/(\d{4})\/(\d{2})\/(\d{2})\// );
	if ( ! match ) {
		return null;
	}
	const timestamp = Date.parse( `${ match[ 1 ] }-${ match[ 2 ] }-${ match[ 3 ] }T00:00:00Z` );
	return Number.isFinite( timestamp ) ? timestamp : null;
}

function makeCoreRecencyCutoff( options ) {
	const now = Number.isFinite( options.now ) ? options.now : Date.now();
	const maxAgeDays = Number.isFinite( options.makeCoreMaxAgeDays )
		? options.makeCoreMaxAgeDays
		: MAKE_CORE_DEFAULT_MAX_AGE_DAYS;
	return maxAgeDays > 0 ? now - maxAgeDays * DAY_MS : null;
}

// developer.wordpress.org reference/handbook URLs are undated and always pass; only
// bulk-discovered make.wordpress.org/core posts are gated to the recency window. A null
// cutoff (max-age-days=0) disables the gate. Explicit --source-url entries bypass this
// gate entirely (see discoverSourceUrls).
function withinMakeCoreWindow( url, cutoffMs ) {
	let host;
	try {
		host = new URL( url ).hostname.toLowerCase();
	} catch {
		return false;
	}
	if ( host !== 'make.wordpress.org' || cutoffMs === null ) {
		return true;
	}
	const published = makeCorePostDate( url );
	return published !== null && published >= cutoffMs;
}

function isCorpusDocumentUrl( value ) {
	let url;
	try {
		url = new URL( value );
	} catch {
		return false;
	}

	const host = url.hostname.toLowerCase();
	const pathName = url.pathname.replace( /\/+$/, '' ) || '/';
	if ( host === 'developer.wordpress.org' && ( pathName === '/news' || pathName.startsWith( '/news/' ) ) ) {
		return /^\/news\/\d{4}\/\d{2}\/(?:\d{2}\/)?[a-z0-9][^/]*$/.test( pathName );
	}

	if ( host === 'make.wordpress.org' && ( pathName === '/core' || pathName.startsWith( '/core/' ) ) ) {
		return /^\/core\/\d{4}\/\d{2}\/\d{2}\/[a-z0-9][^/]*$/.test( pathName );
	}

	return true;
}

// Only fetch sitemaps that live on one of the trusted roots' origins. Sitemap
// references come from robots.txt and nested <loc> entries, i.e. remote-controlled
// input fetched by a secret-bearing workflow runner — constraining them to the
// allowed https origins removes that SSRF surface.
function sitemapUrlWithinOrigins( value, allowedOrigins ) {
	const raw = String( value || '' ).trim();
	if ( ! raw ) {
		return '';
	}
	let url;
	try {
		url = new URL( raw );
	} catch {
		return '';
	}
	if ( url.protocol !== 'https:' ) {
		return '';
	}
	return allowedOrigins.has( url.origin ) ? url.toString() : '';
}

async function readLimitedText( response, maxBytes ) {
	const declared = Number( response.headers.get( 'content-length' ) || 0 );
	if ( Number.isFinite( declared ) && declared > maxBytes ) {
		throw new Error( `Response exceeds ${ maxBytes } bytes (declared ${ declared }).` );
	}

	// Real fetch bodies are capped incrementally; responses without a readable stream
	// (e.g. test mocks) fall back to text() with a post-hoc byte check.
	if ( ! response.body || typeof response.body.getReader !== 'function' ) {
		const text = await response.text();
		if ( Buffer.byteLength( text, 'utf8' ) > maxBytes ) {
			throw new Error( `Response exceeds ${ maxBytes } bytes.` );
		}
		return text;
	}

	const reader = response.body.getReader();
	const chunks = [];
	let total = 0;
	while ( true ) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}
		total += value.byteLength;
		if ( total > maxBytes ) {
			await reader.cancel();
			throw new Error( `Response exceeds ${ maxBytes } bytes.` );
		}
		chunks.push( Buffer.from( value ) );
	}
	return Buffer.concat( chunks ).toString( 'utf8' );
}

async function fetchText( url, options = {} ) {
	// Retries are opt-in (default 0) so discovery/validation keep their fail-fast
	// behavior; page fetches pass retries to ride out transient upstream 5xx/429
	// and network blips instead of turning every blip into a build error.
	const retries = Number.isInteger( options.retries ) && options.retries > 0 ? options.retries : 0;
	const maxAttempts = retries + 1;
	let lastError;
	for ( let attempt = 1; attempt <= maxAttempts; attempt += 1 ) {
		try {
			return await fetchTextOnce( url, options );
		} catch ( error ) {
			lastError = error;
			if ( ! error || error.retryable !== true || attempt === maxAttempts ) {
				throw error;
			}
			await delay( FETCH_RETRY_BASE_MS * 2 ** ( attempt - 1 ) );
		}
	}
	throw lastError;
}

async function fetchTextOnce( url, options = {} ) {
	const controller = new AbortController();
	const timeout = setTimeout( () => controller.abort(), options.timeoutMs || REQUEST_TIMEOUT_MS );
	try {
		let response;
		try {
			response = await fetch( url, {
				redirect: 'follow',
				headers: {
					'Accept': options.accept || 'text/html,application/xhtml+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
					'User-Agent': USER_AGENT,
					...( options.headers || {} ),
				},
				signal: controller.signal,
			} );
		} catch ( error ) {
			// Network failure or timeout abort: transient, so allow a retry.
			if ( error && typeof error === 'object' ) {
				error.retryable = true;
			}
			throw error;
		}

		// fetch() follows redirects; a trusted sitemap/page that 30x-es off-origin must not
		// be read by this secret-bearing runner. Validate the final origin before the body.
		const finalUrl = response.url || url;
		if ( options.allowedOrigins ) {
			let finalOrigin = '';
			try {
				finalOrigin = new URL( finalUrl ).origin;
			} catch {
				finalOrigin = '';
			}
			if ( ! options.allowedOrigins.has( finalOrigin ) ) {
				// Off-origin redirect is a hard security stop, never retried.
				throw new Error( `Refusing redirect outside trusted origins: ${ finalUrl }` );
			}
		}

		const text = await readLimitedText( response, options.maxBytes || MAX_FETCH_BYTES );
		if ( ! response.ok ) {
			const error = new Error( `HTTP ${ response.status } fetching ${ url }: ${ text.slice( 0, 160 ) }` );
			error.status = response.status;
			error.retryable = RETRYABLE_STATUS.has( response.status );
			throw error;
		}
		return {
			url: finalUrl,
			text,
			contentType: response.headers.get( 'content-type' ) || '',
		};
	} finally {
		clearTimeout( timeout );
	}
}

async function fetchWithTimeout( url, init = {}, timeoutMs = REQUEST_TIMEOUT_MS ) {
	const controller = new AbortController();
	const timeout = setTimeout( () => controller.abort(), timeoutMs );
	try {
		return await fetch( url, { ...init, signal: controller.signal } );
	} finally {
		clearTimeout( timeout );
	}
}

async function fetchJson( url, requestOptions = {}, retryOptions = {} ) {
	const configuredRetries = Number.isInteger( retryOptions.retries )
		? retryOptions.retries
		: requestOptions.retries;
	const maxAttempts = Math.max(
		1,
		( Number.isInteger( configuredRetries ) && configuredRetries >= 0
			? configuredRetries
			: FETCH_RETRIES ) + 1
	);
	const configuredRetryDelayMs = Number.isFinite( retryOptions.retryDelayMs )
		? retryOptions.retryDelayMs
		: requestOptions.retryDelayMs;
	const retryDelayMs = Number.isFinite( configuredRetryDelayMs )
		? Math.max( 0, configuredRetryDelayMs )
		: null;
	const fetchOptions = { ...requestOptions };
	delete fetchOptions.retries;
	delete fetchOptions.retryDelayMs;

	let lastError;
	for ( let attempt = 1; attempt <= maxAttempts; attempt += 1 ) {
		try {
			return await fetchJsonOnce( url, fetchOptions );
		} catch ( error ) {
			lastError = error;
			if ( ! error || error.retryable !== true || attempt === maxAttempts ) {
				throw error;
			}
			await delay(
				retryDelayMs !== null
					? retryDelayMs
					: error.retryAfterMs || FETCH_RETRY_BASE_MS * 2 ** ( attempt - 1 )
			);
		}
	}
	throw lastError;
}

async function fetchJsonOnce( url, requestOptions = {} ) {
	let response;
	try {
		response = await fetchWithTimeout( url, requestOptions );
	} catch ( error ) {
		if ( error && typeof error === 'object' ) {
			error.retryable = true;
		}
		throw error;
	}
	const text = await response.text();
	let data;
	try {
		data = text ? JSON.parse( text ) : {};
	} catch ( error ) {
		throw new Error( `Could not parse JSON from ${ url }: ${ error.message }` );
	}
	if ( ! response.ok ) {
		const error = new Error( `Cloudflare API returned HTTP ${ response.status } for ${ url }: ${ extractRemoteMessage( data ) }` );
		error.status = response.status;
		error.retryable = RETRYABLE_STATUS.has( response.status );
		error.retryAfterMs = retryAfterMs( response.headers?.get?.( 'retry-after' ) || '' );
		throw error;
	}
	return data;
}

function retryAfterMs( value ) {
	const raw = String( value || '' ).trim();
	if ( ! raw ) {
		return 0;
	}
	if ( /^\d+(?:\.\d+)?$/.test( raw ) ) {
		return Math.max( 0, Math.ceil( Number( raw ) * 1000 ) );
	}
	const timestamp = Date.parse( raw );
	if ( Number.isNaN( timestamp ) ) {
		return 0;
	}
	return Math.max( 0, timestamp - Date.now() );
}

function extractRemoteMessage( data ) {
	if ( data && typeof data === 'object' ) {
		if ( typeof data.message === 'string' ) {
			return data.message;
		}
		if ( data.error && typeof data.error.message === 'string' ) {
			return data.error.message;
		}
		if ( Array.isArray( data.errors ) && data.errors.length > 0 ) {
			return data.errors.map( ( error ) => {
				if ( typeof error === 'string' ) {
					return error;
				}
				return error && typeof error.message === 'string' ? error.message : '';
			} ).filter( Boolean ).join( '; ' );
		}
	}
	return 'Unknown error';
}

async function discoverSourceUrls( roots, options ) {
	const explicit = await readExplicitSourceUrls( options );
	if ( explicit.length > 0 ) {
		return {
			urls: dedupeUrls( explicit.filter( ( url ) => urlMatchesRoots( url, roots ) || roots.includes( url ) ) ),
			errors: [],
			lastmods: {},
		};
	}

	const allowedOrigins = new Set( roots.map( ( root ) => new URL( root ).origin ) );
	const sitemapUrls = new Set();

	for ( const origin of allowedOrigins ) {
		for ( const sitemapUrl of await discoverRobotsSitemaps( origin ) ) {
			const normalized = sitemapUrlWithinOrigins( sitemapUrl, allowedOrigins );
			if ( normalized ) {
				sitemapUrls.add( normalized );
			}
		}
	}

	// WordPress exposes wp-sitemap.xml at each site root. On subdirectory multisites
	// (e.g. make.wordpress.org/core/) the network-root robots.txt does not advertise the
	// subsite sitemap, so also seed each trusted root's own sitemap. The origin SSRF
	// guard still applies, and unknown roots simply 404 and are skipped.
	for ( const root of roots ) {
		for ( const candidate of [ 'wp-sitemap.xml', 'sitemap.xml' ] ) {
			const normalized = sitemapUrlWithinOrigins(
				new URL( candidate, root ).toString(),
				allowedOrigins
			);
			if ( normalized ) {
				sitemapUrls.add( normalized );
			}
		}
	}

	const discovered = new Set( roots.filter( isCorpusDocumentUrl ) );
	const lastmods = new Map();
	const makeCoreCutoff = makeCoreRecencyCutoff( options );
	const discoveryErrors = [];
	const visitedSitemaps = new Set();
	const queue = [ ...sitemapUrls ];
	const queuedSitemaps = new Set( queue );

	while ( queue.length > 0 ) {
		const batch = queue.splice( 0, SITEMAP_CONCURRENCY );
		const batchResults = await Promise.allSettled(
			batch.map( ( sitemapUrl ) => readSitemap( sitemapUrl, visitedSitemaps, allowedOrigins ) )
		);

		for ( const result of batchResults ) {
			if ( result.status !== 'fulfilled' ) {
				const reason = result.reason || {};
				console.warn( reason.message );
				// A 404 means the sitemap is legitimately absent (speculative root-relative
				// seeds, cross-subsite robots.txt entries). Under-discovery from genuine 404s
				// is caught by the prepared-count regression guard; only non-404 failures
				// (5xx, network, parse) are discovery errors that block stale deletion.
				if ( reason.status !== 404 ) {
					discoveryErrors.push( reason.message );
				}
				continue;
			}
			for ( const sitemapUrl of result.value.sitemaps ) {
				const normalized = sitemapUrlWithinOrigins( sitemapUrl, allowedOrigins );
				if ( normalized && ! visitedSitemaps.has( normalized ) && ! queuedSitemaps.has( normalized ) ) {
					queue.push( normalized );
					queuedSitemaps.add( normalized );
				}
			}
			for ( const loc of result.value.urls ) {
				const normalized = normalizeTrustedUrl( loc );
				if (
					normalized &&
					urlMatchesRoots( normalized, roots ) &&
					isCorpusDocumentUrl( normalized ) &&
					withinMakeCoreWindow( normalized, makeCoreCutoff )
				) {
					discovered.add( normalized );
					const lastmod = result.value.lastmods && result.value.lastmods.get( loc );
					if ( lastmod && ! lastmods.has( normalized ) ) {
						lastmods.set( normalized, lastmod );
					}
				}
			}
		}
	}

	const urls = [ ...discovered ].sort();
	return {
		urls: options.limit > 0 ? urls.slice( 0, options.limit ) : urls,
		errors: discoveryErrors,
		lastmods: Object.fromEntries( lastmods ),
	};
}

async function readExplicitSourceUrls( options ) {
	const urls = options.sourceUrls.map( ( value ) => normalizeTrustedUrl( value ) ).filter( Boolean );

	if ( ! options.sourceFile ) {
		return urls;
	}

	const sourcePath = path.resolve( options.sourceFile );
	const content = await fsp.readFile( sourcePath, 'utf8' );
	let parsed;
	try {
		parsed = JSON.parse( content );
	} catch {
		parsed = content.split( /\r?\n/ ).map( ( line ) => line.trim() ).filter( Boolean );
	}

	if ( ! Array.isArray( parsed ) ) {
		throw new Error( '--source-file must be a JSON array or newline-delimited URL list.' );
	}

	for ( const entry of parsed ) {
		const normalized = normalizeTrustedUrl( entry );
		if ( normalized ) {
			urls.push( normalized );
		}
	}

	return dedupeUrls( urls );
}

function dedupeUrls( urls ) {
	return [ ...new Set( urls.map( ( value ) => normalizeTrustedUrl( value ) ).filter( Boolean ) ) ].sort();
}

async function discoverRobotsSitemaps( origin ) {
	const robotsUrl = new URL( '/robots.txt', origin ).toString();
	try {
		const response = await fetchText( robotsUrl, { accept: 'text/plain,*/*;q=0.8', allowedOrigins: new Set( [ origin ] ) } );
		const sitemaps = response.text.split( /\r?\n/ )
			.map( ( line ) => line.match( /^\s*sitemap:\s*(\S+)/i ) )
			.filter( Boolean )
			.map( ( match ) => match[ 1 ] );

		if ( sitemaps.length > 0 ) {
			return sitemaps;
		}
	} catch ( error ) {
		console.warn( `Could not read ${ robotsUrl }: ${ error.message }` );
	}

	return [ new URL( '/sitemap.xml', origin ).toString() ];
}

async function readSitemap( sitemapUrl, visited, allowedOrigins = null ) {
	if ( visited.has( sitemapUrl ) ) {
		return { urls: [], sitemaps: [] };
	}
	visited.add( sitemapUrl );

	const response = await fetchText( sitemapUrl, { accept: 'application/xml,text/xml,*/*;q=0.8', allowedOrigins } );
	const locs = [ ...response.text.matchAll( /<loc>\s*<!\[CDATA\[(.*?)\]\]>\s*<\/loc>|<loc>\s*([^<]+)\s*<\/loc>/gi ) ]
		.map( ( match ) => decodeXml( match[ 1 ] || match[ 2 ] || '' ).trim() )
		.filter( Boolean );

	const sitemaps = [];
	const urls = [];
	const seenSitemaps = new Set();
	const seenUrls = new Set();
	for ( const loc of locs ) {
		if ( /\.xml(?:\.gz)?(?:$|\?)/i.test( loc ) || /sitemap/i.test( loc ) ) {
			if ( ! seenSitemaps.has( loc ) ) {
				seenSitemaps.add( loc );
				sitemaps.push( loc );
			}
		} else {
			if ( ! seenUrls.has( loc ) ) {
				seenUrls.add( loc );
				urls.push( loc );
			}
		}
	}

	// Pair each content <loc> with its <lastmod> (URL-set sitemaps only) so the
	// updater can skip re-fetching pages unchanged since the last crawl.
	const lastmods = new Map();
	for ( const block of response.text.matchAll( /<url\b[^>]*>([\s\S]*?)<\/url>/gi ) ) {
		const inner = block[ 1 ];
		const locMatch = inner.match( /<loc>\s*(?:<!\[CDATA\[([\s\S]*?)\]\]>|([^<]+))\s*<\/loc>/i );
		const loc = decodeXml( ( locMatch && ( locMatch[ 1 ] || locMatch[ 2 ] ) ) || '' ).trim();
		if ( ! loc ) {
			continue;
		}
		const lastmodMatch = inner.match( /<lastmod>\s*([^<]+?)\s*<\/lastmod>/i );
		if ( lastmodMatch ) {
			lastmods.set( loc, lastmodMatch[ 1 ].trim() );
		}
	}

	return { urls, sitemaps, lastmods };
}

function decodeXml( value ) {
	return String( value )
		.replace( /&amp;/g, '&' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&quot;/g, '"' )
		.replace( /&#039;|&apos;/g, "'" );
}

async function buildEntryForUrl( url, roots, options ) {
	const allowedOrigins = new Set(
		roots
			.map( ( root ) => {
				try {
					return new URL( root ).origin;
				} catch {
					return '';
				}
			} )
			.filter( Boolean )
	);
	const response = await fetchText( url, { allowedOrigins, retries: FETCH_RETRIES } );
	if ( response.contentType && ! /html|xml|text/i.test( response.contentType ) ) {
		throw new Error( `Unsupported content type ${ response.contentType }` );
	}

	const html = response.text;
	const canonical = normalizeTrustedUrl( extractCanonicalUrl( html, response.url ) || response.url, response.url );
	if ( ! canonical || ! urlMatchesRoots( canonical, roots ) ) {
		throw new Error( `Canonical URL is outside trusted roots: ${ canonical || response.url }` );
	}

	const title = extractTitle( html ) || canonical;
	const markdown = htmlToMarkdown( extractMainHtml( html ) );
	if ( markdown.length < 120 ) {
		throw new Error( 'Extracted content is too short.' );
	}

	const retrievedAt = new Date().toISOString();
	const publishedAt = extractPublishedAt( html );
	const contentHash = contentHashForEntry( canonical, markdown );
	const key = buildItemKey( canonical, contentHash, options.instance );
	const body = buildMarkdownDocument( {
		canonical,
		title,
		retrievedAt,
		publishedAt,
		contentHash,
		markdown,
	} );

	return {
		key,
		url: canonical,
		title,
		retrievedAt,
		publishedAt,
		contentHash,
		body,
		bodyBytes: Buffer.byteLength( body, 'utf8' ),
		metadata: buildUploadMetadata( {
			canonical,
			title,
			retrievedAt,
			publishedAt,
			contentHash,
		} ),
	};
}

function contentHashForEntry( canonical, markdown ) {
	return sha256( canonical + '\n' + DOC_LAYOUT_VERSION + '\n' + markdown );
}

function buildItemKey( canonical, contentHash, instance ) {
	const url = new URL( canonical );
	const head = [ 'ai-search', instance, url.hostname.toLowerCase() ].join( '/' );
	const tail = [ String( contentHash ).slice( 0, 16 ), 'part-0001.md' ].join( '/' );

	// Cloudflare AI Search rejects item filenames over its maximum length
	// (filename_exceeds_maximum_length); the plugin uploader caps at 128 too
	// (PatternSearchClient::filename_for_item_id). Keep only a bounded,
	// human-readable path slug here — uniqueness and per-content stability come
	// from the (truncated) content hash, and the full URL stays in metadata.
	const slugBudget = KEY_MAX_BYTES - head.length - tail.length - 2;
	const rawSlug = url.pathname.split( '/' ).map( ( segment ) => segment.trim() ).filter( Boolean ).join( '-' );
	const slug = boundedSlug( rawSlug, slugBudget );

	return ( slug ? [ head, slug, tail ] : [ head, tail ] ).join( '/' );
}

function boundedSlug( value, maxBytes ) {
	if ( maxBytes <= 0 ) {
		return '';
	}
	const ascii = String( value )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
	if ( ascii.length <= maxBytes ) {
		return ascii;
	}
	return ascii.slice( 0, maxBytes ).replace( /-+$/, '' );
}

function extractCanonicalUrl( html, fallback ) {
	const canonical = matchAttributeTag( html, /<link\b[^>]*rel=["'][^"']*\bcanonical\b[^"']*["'][^>]*>/i, 'href' );
	if ( canonical ) {
		return canonical;
	}

	const ogUrl = matchMetaContent( html, 'property', 'og:url' );
	if ( ogUrl ) {
		return ogUrl;
	}

	return fallback;
}

function extractTitle( html ) {
	return flattenString( cleanText(
		matchMetaContent( html, 'property', 'og:title' ) ||
		matchMetaContent( html, 'name', 'title' ) ||
		( html.match( /<title\b[^>]*>([\s\S]*?)<\/title>/i ) || [] )[ 1 ] ||
		''
	) );
}

function extractPublishedAt( html ) {
	const candidates = [
		matchMetaContent( html, 'property', 'article:published_time' ),
		matchMetaContent( html, 'name', 'date' ),
		matchMetaContent( html, 'name', 'dc.date' ),
		matchMetaContent( html, 'name', 'pubdate' ),
		matchAttributeTag( html, /<time\b[^>]*datetime=["'][^"']+["'][^>]*>/i, 'datetime' ),
		...extractJsonLdDates( html ),
	].filter( Boolean );

	for ( const candidate of candidates ) {
		const timestamp = Date.parse( candidate );
		if ( Number.isFinite( timestamp ) ) {
			return new Date( timestamp ).toISOString();
		}
	}

	return '';
}

function extractJsonLdDates( html ) {
	const dates = [];
	for ( const match of html.matchAll( /<script\b[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi ) ) {
		const raw = decodeHtml( match[ 1 ] || '' ).trim();
		try {
			const parsed = JSON.parse( raw );
			collectJsonLdDates( parsed, dates );
		} catch {
			continue;
		}
	}
	return dates;
}

function collectJsonLdDates( value, dates ) {
	if ( Array.isArray( value ) ) {
		value.forEach( ( entry ) => collectJsonLdDates( entry, dates ) );
		return;
	}
	if ( ! value || typeof value !== 'object' ) {
		return;
	}
	for ( const key of [ 'datePublished', 'dateModified' ] ) {
		if ( typeof value[ key ] === 'string' ) {
			dates.push( value[ key ] );
		}
	}
	for ( const entry of Object.values( value ) ) {
		if ( entry && typeof entry === 'object' ) {
			collectJsonLdDates( entry, dates );
		}
	}
}

function matchMetaContent( html, attrName, attrValue ) {
	const regex = new RegExp( `<meta\\b[^>]*${ attrName }=["']${ escapeRegExp( attrValue ) }["'][^>]*>`, 'i' );
	return matchAttributeTag( html, regex, 'content' );
}

function matchAttributeTag( html, regex, attrName ) {
	const tag = ( html.match( regex ) || [] )[ 0 ] || '';
	if ( ! tag ) {
		return '';
	}
	const attrRegex = new RegExp( `${ attrName }\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i' );
	const match = tag.match( attrRegex );
	return decodeHtml( match?.[ 1 ] || match?.[ 2 ] || match?.[ 3 ] || '' ).trim();
}

function extractMainHtml( html ) {
	for ( const pattern of [
		/<main\b[^>]*>([\s\S]*?)<\/main>/i,
		/<article\b[^>]*>([\s\S]*?)<\/article>/i,
		/<body\b[^>]*>([\s\S]*?)<\/body>/i,
	] ) {
		const match = html.match( pattern );
		if ( match && match[ 1 ] ) {
			return match[ 1 ];
		}
	}
	return html;
}

function htmlToMarkdown( html ) {
	const codeBlocks = [];
	let text = String( html || '' )
		.replace( /<script\b[\s\S]*?<\/script>/gi, ' ' )
		.replace( /<style\b[\s\S]*?<\/style>/gi, ' ' )
		.replace( /<svg\b[\s\S]*?<\/svg>/gi, ' ' )
		.replace( /<(nav|header|footer|aside|form)\b[\s\S]*?<\/\1>/gi, ' ' )
		.replace( /<!--[\s\S]*?-->/g, ' ' )
		.replace( /<pre\b[^>]*>\s*<code\b[^>]*>([\s\S]*?)<\/code>\s*<\/pre>/gi, ( _, code ) => stashCodeBlock( code, codeBlocks ) )
		.replace( /<pre\b[^>]*>([\s\S]*?)<\/pre>/gi, ( _, code ) => stashCodeBlock( code, codeBlocks ) )
		.replace( /<code\b[^>]*>([\s\S]*?)<\/code>/gi, '`$1`' )
		.replace( /<h1\b[^>]*>([\s\S]*?)<\/h1>/gi, '\n# $1\n' )
		.replace( /<h2\b[^>]*>([\s\S]*?)<\/h2>/gi, '\n## $1\n' )
		.replace( /<h3\b[^>]*>([\s\S]*?)<\/h3>/gi, '\n### $1\n' )
		.replace( /<h4\b[^>]*>([\s\S]*?)<\/h4>/gi, '\n#### $1\n' )
		.replace( /<li\b[^>]*>([\s\S]*?)<\/li>/gi, '\n- $1' )
		.replace( /<\/(p|div|section|article|ul|ol|table|tr)>/gi, '\n' )
		.replace( /<br\s*\/?>/gi, '\n' )
		.replace( /<[^>]+>/g, ' ' );

	text = decodeHtml( text );
	text = text.replace( /\[(?:Copy|Edit|View source)\]/gi, ' ' );
	text = text.replace( /[ \t]+/g, ' ' );
	text = text.replace( /\n[ \t]+/g, '\n' );
	text = text.replace( /\n{3,}/g, '\n\n' );
	text = cleanText( text );
	text = restoreCodeBlocks( text, codeBlocks );
	text = text.replace( /\n{3,}/g, '\n\n' );

	return text.trim();
}

function stashCodeBlock( rawCode, codeBlocks ) {
	const code = decodeHtml(
		String( rawCode || '' )
			.replace( /<br\s*\/?>/gi, '\n' )
			.replace( /<[^>]+>/g, '' )
	)
		.replace( /^\n+|\n+$/g, '' );
	const index = codeBlocks.push( code ) - 1;

	return `\n\n@@FLAVOR_AGENT_CODE_BLOCK_${ index }@@\n\n`;
}

function restoreCodeBlocks( text, codeBlocks ) {
	return String( text || '' ).replace(
		/@@FLAVOR_AGENT_CODE_BLOCK_(\d+)@@/g,
		( _, index ) => {
			const code = codeBlocks[ Number.parseInt( index, 10 ) ] || '';
			return `\n\n\`\`\`\n${ code }\n\`\`\`\n\n`;
		}
	);
}

function decodeHtml( value ) {
	return String( value || '' )
		.replace( /&#(\d+);/g, ( _, code ) => String.fromCodePoint( Number.parseInt( code, 10 ) ) )
		.replace( /&#x([0-9a-f]+);/gi, ( _, code ) => String.fromCodePoint( Number.parseInt( code, 16 ) ) )
		.replace( /&amp;/g, '&' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&quot;/g, '"' )
		.replace( /&#039;|&apos;/g, "'" )
		.replace( /&nbsp;/g, ' ' );
}

function cleanText( value ) {
	return String( value || '' ).replace( /\s+\n/g, '\n' ).replace( /\n\s+/g, '\n' ).trim();
}

function flattenString( value ) {
	// Force a standalone, sequential copy. Strings produced by RegExp capture
	// groups / String.match() are V8 SlicedStrings that retain a reference to
	// the entire parent HTML; storing one in a long-lived structure (e.g. the
	// manifest's title) pins the whole page in memory. Round-tripping through
	// UTF-8 bytes severs that link.
	return Buffer.from( String( value || '' ), 'utf8' ).toString( 'utf8' );
}

function truncateUtf8ToBytes( value, maxBytes ) {
	let output = String( value || '' );
	if ( maxBytes <= 0 ) {
		return '';
	}
	// Trim ~5% of characters per pass and re-measure bytes, so we never cut on a partial
	// multibyte sequence the way a raw byte subarray would.
	while ( Buffer.byteLength( output, 'utf8' ) > maxBytes ) {
		const next = Math.floor( output.length * 0.95 );
		output = next < output.length ? output.slice( 0, next ) : output.slice( 0, -1 );
	}
	// Never end on a lone high surrogate (a split astral character).
	const last = output.charCodeAt( output.length - 1 );
	if ( last >= 0xd800 && last <= 0xdbff ) {
		output = output.slice( 0, -1 );
	}
	return output;
}

function buildMarkdownDocument( entry ) {
	const frontmatter = [
		'---',
		`source_url: "${ yamlQuote( entry.canonical ) }"`,
		`retrieved_at: "${ yamlQuote( entry.retrievedAt ) }"`,
		entry.publishedAt ? `published_at: "${ yamlQuote( entry.publishedAt ) }"` : '',
		`content_hash: "${ yamlQuote( entry.contentHash ) }"`,
		`title: "${ yamlQuote( entry.title ) }"`,
		'---',
		'',
	].filter( ( line ) => line !== '' ).join( '\n' );

	const prefix = `${ frontmatter }\n\n`;
	const suffix = '\n\n[Content truncated before upload to fit Cloudflare AI Search item limits.]\n';

	const full = `${ prefix }${ entry.markdown }\n`;
	if ( Buffer.byteLength( full, 'utf8' ) <= MAX_UPLOAD_BYTES ) {
		return full;
	}

	// Budget against the actual prefix + suffix bytes (not just frontmatter), so an unusually
	// large title/frontmatter can never let the final body exceed the cap.
	const markdownBudget =
		MAX_UPLOAD_BYTES -
		Buffer.byteLength( prefix, 'utf8' ) -
		Buffer.byteLength( suffix, 'utf8' );
	if ( markdownBudget <= 0 ) {
		throw new Error( 'Frontmatter exceeds the Cloudflare AI Search item size limit.' );
	}

	return `${ prefix }${ truncateUtf8ToBytes( entry.markdown, markdownBudget ) }${ suffix }`;
}

function yamlQuote( value ) {
	return String( value || '' ).replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );
}

function buildUploadMetadata( entry ) {
	const metadata = {
		retrieved_at: entry.retrievedAt,
		content_hash: entry.contentHash,
	};
	if ( entry.publishedAt ) {
		metadata.published_at = entry.publishedAt;
	}
	if ( Buffer.byteLength( entry.canonical, 'utf8' ) <= 500 ) {
		metadata.source_url = entry.canonical;
	}
	if ( entry.title && Buffer.byteLength( entry.title, 'utf8' ) <= 500 ) {
		metadata.title = entry.title;
	}
	return metadata;
}

function sha256( value ) {
	return crypto.createHash( 'sha256' ).update( value ).digest( 'hex' );
}

function escapeRegExp( value ) {
	return String( value ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

async function configureInstance( options, auth ) {
	if ( options.dryRun || ! options.configureInstance ) {
		return { skipped: true };
	}

	const url = cloudflareUrl( auth.accountId, `instances/${ encodeURIComponent( options.instance ) }` );
	const data = await fetchJson( url, {
		method: 'PUT',
		headers: {
			'Authorization': `Bearer ${ auth.apiToken }`,
			'Content-Type': 'application/json',
			'User-Agent': USER_AGENT,
		},
		body: JSON.stringify( {
			custom_metadata: METADATA_SCHEMA,
			index_method: {
				keyword: true,
				vector: true,
			},
			fusion_method: 'rrf',
			retrieval_options: {
				keyword_match_mode: 'or',
			},
			rewrite_query: false,
			reranking: true,
			reranking_model: '@cf/baai/bge-reranker-base',
			cache_threshold: 'super_strict_match',
			cache_ttl: 3600,
		} ),
	} );

	return { skipped: false, id: data?.result?.id || options.instance };
}

async function listBuiltinItems( options, auth ) {
	const items = [];
	let page = 1;

	while ( true ) {
		const url = new URL( cloudflareUrl( auth.accountId, `instances/${ encodeURIComponent( options.instance ) }/items` ) );
		url.searchParams.set( 'page', String( page ) );
		url.searchParams.set( 'per_page', '50' );
		url.searchParams.set( 'source', 'builtin' );

		const data = await fetchJson( url.toString(), {
			headers: {
				'Authorization': `Bearer ${ auth.apiToken }`,
				'User-Agent': USER_AGENT,
			},
		} );

		const result = Array.isArray( data.result ) ? data.result : [];
		items.push( ...result.filter( ( item ) => item && typeof item === 'object' ) );

		const info = data.result_info && typeof data.result_info === 'object' ? data.result_info : null;
		if ( ! info ) {
			if ( result.length === 0 || result.length < 50 ) {
				break;
			}
			page += 1;
			continue;
		}
		const count = Number( info.count ?? result.length );
		const perPage = Number( info.per_page ?? 50 );
		const total = Number( info.total_count ?? items.length );
		if ( count === 0 || page * perPage >= total ) {
			break;
		}
		page += 1;
	}

	return items;
}

async function getInstanceStats( options, auth ) {
	const data = await fetchJson(
		cloudflareUrl( auth.accountId, `instances/${ encodeURIComponent( options.instance ) }/stats` ),
		{
			headers: {
				'Authorization': `Bearer ${ auth.apiToken }`,
				'User-Agent': USER_AGENT,
			},
		}
	);

	const result = data?.result && typeof data.result === 'object' ? data.result : data;
	return {
		completed: normalizeCount( result.completed ),
		queued: normalizeCount( result.queued ),
		running: normalizeCount( result.running ),
		outdated: normalizeCount( result.outdated ),
		error: normalizeCount( result.error ),
		skipped: normalizeCount( result.skipped ),
	};
}

function normalizeCount( value ) {
	const count = Number( value );
	return Number.isFinite( count ) && count > 0 ? count : 0;
}

async function uploadEntry( entry, options, auth ) {
	if ( options.dryRun ) {
		return { key: entry.key, dryRun: true };
	}

	const form = new FormData();
	form.append( 'metadata', JSON.stringify( entry.metadata ) );
	form.append( 'file', new Blob( [ entry.body ], { type: 'text/markdown; charset=UTF-8' } ), entry.key );

	const data = await fetchJson(
		cloudflareUrl( auth.accountId, `instances/${ encodeURIComponent( options.instance ) }/items` ),
		{
			method: 'POST',
			headers: {
				'Authorization': `Bearer ${ auth.apiToken }`,
				'User-Agent': USER_AGENT,
			},
			body: form,
		}
	);

	return data.result || { key: entry.key };
}

async function deleteItem( item, options, auth ) {
	if ( options.dryRun ) {
		return { id: item.id, key: item.key, dryRun: true };
	}

	const itemId = encodeURIComponent( String( item.id || '' ) );
	if ( ! itemId ) {
		throw new Error( `Cannot delete item without an id: ${ item.key || '<unknown>' }` );
	}

	const data = await fetchJson(
		cloudflareUrl( auth.accountId, `instances/${ encodeURIComponent( options.instance ) }/items/${ itemId }` ),
		{
			method: 'DELETE',
			headers: {
				'Authorization': `Bearer ${ auth.apiToken }`,
				'User-Agent': USER_AGENT,
			},
		}
	);

	return data.result || { id: item.id, key: item.key };
}

function cloudflareUrl( accountId, pathPart ) {
	return `https://api.cloudflare.com/client/v4/accounts/${ encodeURIComponent( accountId ) }/ai-search/${ pathPart }`;
}

function getAuth( options ) {
	if ( options.dryRun ) {
		return null;
	}

	const accountId = process.env.CLOUDFLARE_ACCOUNT_ID || '';
	const apiToken = process.env.CLOUDFLARE_AI_SEARCH_API_TOKEN || process.env.CLOUDFLARE_API_TOKEN || '';
	if ( ! accountId || ! apiToken ) {
		throw new Error( 'CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_AI_SEARCH_API_TOKEN are required unless --dry-run is used.' );
	}

	return { accountId, apiToken };
}

function shouldUpload( entry, existingItem ) {
	if ( ! existingItem ) {
		return true;
	}

	const metadata = existingItem.metadata && typeof existingItem.metadata === 'object' ? existingItem.metadata : {};
	const remoteHash = String( metadata.content_hash || metadata.contentHash || '' );
	if ( remoteHash && remoteHash === entry.contentHash && existingItem.status === 'completed' ) {
		return false;
	}

	return true;
}

/**
 * Parse a metadata timestamp into epoch milliseconds. The updater uploads ISO
 * strings, but Cloudflare AI Search normalizes datetime metadata (see
 * METADATA_SCHEMA) and returns it as epoch milliseconds from the items and
 * search APIs — accept both shapes, returning NaN for anything else.
 */
function parseTimestampMs( value ) {
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? value : NaN;
	}
	return Date.parse( String( value || '' ) );
}

/**
 * Decide whether a page can be reused without re-fetching, comparing the sitemap
 * <lastmod> against when we last crawled it. Conservative: only true with a
 * parseable lastmod, a completed existing item, and a crawl at/after the last
 * modification. Any uncertainty falls through to a fresh fetch.
 */
function isFreshByLastmod( lastmod, existingItem ) {
	if ( ! lastmod || ! existingItem || typeof existingItem !== 'object' ) {
		return false;
	}
	if ( String( existingItem.status || '' ) !== 'completed' ) {
		return false;
	}
	const metadata = existingItem.metadata && typeof existingItem.metadata === 'object' ? existingItem.metadata : {};
	const retrievedAt = metadata.retrieved_at || metadata.retrievedAt || '';
	const lastModifiedMs = Date.parse( lastmod );
	const retrievedMs = parseTimestampMs( retrievedAt );
	if ( Number.isNaN( lastModifiedMs ) || Number.isNaN( retrievedMs ) ) {
		return false;
	}
	return retrievedMs >= lastModifiedMs;
}

/**
 * Index existing built-in items by normalized source URL so a discovered URL can
 * be matched to the item already in the corpus.
 *
 * @param {Array<object>} items
 * @return {Map<string, object>}
 */
function existingItemsByUrl( items ) {
	const map = new Map();
	for ( const item of Array.isArray( items ) ? items : [] ) {
		if ( ! item || typeof item !== 'object' ) {
			continue;
		}
		const metadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : {};
		const normalized = normalizeTrustedUrl( metadata.source_url || metadata.sourceUrl || '' );
		if (
			normalized &&
			( ! map.has( normalized ) || itemRetrievedAtMs( item ) > itemRetrievedAtMs( map.get( normalized ) ) )
		) {
			map.set( normalized, item );
		}
	}
	return map;
}

function itemRetrievedAtMs( item ) {
	const metadata = item && typeof item === 'object' && item.metadata && typeof item.metadata === 'object'
		? item.metadata
		: {};
	const retrievedAt = metadata.retrieved_at || metadata.retrievedAt || '';
	const timestamp = parseTimestampMs( retrievedAt );
	return Number.isNaN( timestamp ) ? -Infinity : timestamp;
}

function evaluateSettlement( latest, desiredKeys ) {
	const scoped = latest.filter( ( item ) => desiredKeys.has( String( item.key || '' ) ) );
	const seen = new Set( scoped.map( ( item ) => String( item.key || '' ) ) );
	const missing = [ ...desiredKeys ].filter( ( key ) => ! seen.has( key ) );
	const pending = scoped.filter( ( item ) => [ 'queued', 'running', 'outdated' ].includes( String( item.status || '' ) ) );
	const errors = scoped.filter( ( item ) => [ 'error', 'skipped' ].includes( String( item.status || '' ) ) );

	return { missing, pending, errors };
}

function isSettlementComplete( settlement ) {
	const missing = Array.isArray( settlement?.missing ) ? settlement.missing : [];
	const pending = Array.isArray( settlement?.pending ) ? settlement.pending : [];
	const errors = Array.isArray( settlement?.errors ) ? settlement.errors : [];
	return missing.length === 0 && pending.length === 0 && errors.length === 0;
}

function explicitSourcesRequested( options ) {
	const urls = Array.isArray( options?.sourceUrls ) ? options.sourceUrls : [];
	const file = typeof options?.sourceFile === 'string' ? options.sourceFile.trim() : '';
	return urls.length > 0 || file !== '';
}

function isManagedDocsKey( key, instance ) {
	return key.startsWith( `ai-search/${ instance }/` ) ||
		key.startsWith( 'ai-search/wp-dev-docs/' ) ||
		key.startsWith( LEGACY_ITEM_KEY_PREFIX );
}

function sourcePartIdentityForUrl( url, partIndex = 1 ) {
	const normalized = normalizeTrustedUrl( url );
	if ( ! normalized ) {
		return '';
	}
	const numericPart = Number( partIndex );
	const safePart = Number.isFinite( numericPart ) && numericPart > 0 ? Math.floor( numericPart ) : 1;
	return `${ normalized }#part-${ String( safePart ).padStart( 4, '0' ) }`;
}

function sourcePartIdentityForItem( item ) {
	if ( ! item || typeof item !== 'object' ) {
		return '';
	}
	const metadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : {};
	const sourceUrl = metadata.source_url || metadata.sourceUrl || '';
	return sourcePartIdentityForUrl( sourceUrl, partIndexFromItemKey( item.key ) );
}

function partIndexFromItemKey( key ) {
	const match = String( key || '' ).match( /\/part-(\d+)\.md$/ );
	if ( ! match ) {
		return 1;
	}
	const value = Number( match[ 1 ] );
	return Number.isFinite( value ) && value > 0 ? value : 1;
}

function managedCompletedSourceUrlCount( items, instance ) {
	const urls = new Set();
	for ( const item of Array.isArray( items ) ? items : [] ) {
		if ( ! item || typeof item !== 'object' ) {
			continue;
		}
		const key = String( item.key || '' );
		if ( ! isManagedDocsKey( key, instance ) || String( item.status || '' ) !== 'completed' ) {
			continue;
		}
		const metadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : {};
		const normalized = normalizeTrustedUrl( metadata.source_url || metadata.sourceUrl || '' );
		if ( normalized ) {
			urls.add( normalized );
		}
	}
	return urls.size;
}

function assertUniquePreparedIdentities( preparedItems ) {
	const seen = new Map();
	for ( const item of Array.isArray( preparedItems ) ? preparedItems : [] ) {
		const identity = String( item.identity || '' );
		if ( ! identity ) {
			throw new Error( `Prepared docs item is missing source identity: ${ item.url || item.key || '<unknown>' }` );
		}
		if ( seen.has( identity ) ) {
			const previous = seen.get( identity );
			throw new Error(
				`Duplicate prepared docs source identity ${ identity }: ${ previous.url || previous.key || '<unknown>' } and ${ item.url || item.key || '<unknown>' }`
			);
		}
		seen.set( identity, item );
	}
}

function dedupePreparedItems( preparedItems ) {
	const groups = new Map();
	for ( const item of Array.isArray( preparedItems ) ? preparedItems : [] ) {
		const identity = String( item.identity || '' );
		if ( ! identity ) {
			throw new Error( `Prepared docs item is missing source identity: ${ item.url || item.key || '<unknown>' }` );
		}
		if ( ! groups.has( identity ) ) {
			groups.set( identity, [] );
		}
		groups.get( identity ).push( item );
	}

	const deduped = [];
	const duplicates = [];
	for ( const [ identity, items ] of groups ) {
		const winner = items.find( ( item ) => item.kind === 'entry' ) || items[ 0 ];
		deduped.push( winner );
		for ( const item of items ) {
			if ( item === winner ) {
				continue;
			}
			duplicates.push( {
				url: item.url || '',
				canonical: item.canonical || '',
				identity,
				key: item.key || '',
				keptKey: winner.key || '',
				keptUrl: winner.url || '',
				reason: 'duplicate-source-url',
			} );
		}
	}

	deduped.sort( ( a, b ) => a.index - b.index );
	duplicates.sort( ( a, b ) => String( a.url ).localeCompare( String( b.url ) ) );
	assertUniquePreparedIdentities( deduped );
	return { items: deduped, duplicates };
}

function findSupersededSourceItems( items, desiredKeys, desiredSourceIdentities, instance ) {
	const desiredKeySet = desiredKeys instanceof Set ? desiredKeys : new Set();
	const desiredIdentitySet = desiredSourceIdentities instanceof Set ? desiredSourceIdentities : new Set();
	return ( Array.isArray( items ) ? items : [] ).filter( ( item ) => {
		if ( ! item || typeof item !== 'object' ) {
			return false;
		}
		const key = String( item.key || '' );
		if ( ! isManagedDocsKey( key, instance ) || desiredKeySet.has( key ) ) {
			return false;
		}
		if ( String( item.status || '' ) !== 'completed' ) {
			return false;
		}
		const identity = sourcePartIdentityForItem( item );
		return identity && desiredIdentitySet.has( identity );
	} );
}

// Stale deletion is the one destructive step, so it runs only for a full, demonstrably
// healthy run. Targeted runs (explicit --source-url/--source-file), an out-of-ratio build
// failure, and any upload or poll problem disable it so a degraded discovery cannot
// wipe the corpus. Public endpoint validation still marks the run as needing attention,
// but it must not block stale deletion because stale generations can be the reason
// validation is noisy in the first place.
function resolveStaleDeletion( run ) {
	if ( run.dryRun ) {
		return { delete: false, reason: 'dry-run' };
	}
	if ( ! run.deleteStale ) {
		return { delete: false, reason: 'disabled' };
	}
	if ( run.explicitSources ) {
		return { delete: false, reason: 'targeted-source-run' };
	}
	if ( run.limit > 0 ) {
		return { delete: false, reason: 'limited-run' };
	}
	if ( run.discoveryErrors > 0 ) {
		return { delete: false, reason: 'discovery-errors' };
	}
	if ( run.pollSkipped ) {
		return { delete: false, reason: 'poll-skipped' };
	}
	if ( ! ( run.prepared > 0 ) ) {
		return { delete: false, reason: 'no-prepared-documents' };
	}
	// Persistent low-level build noise (binary attachment pages discovered from the
	// sitemaps) must not leave stale generations unprunable forever; only a build-error
	// ratio above the same attention threshold used by resolveSummaryStatus() is a
	// systemic failure. Without a discovered count, any build error stays blocking.
	const discoveredCount = run.discovered || 0;
	const buildErrorCount = run.buildErrors || 0;
	const buildErrorRatio = discoveredCount > 0
		? buildErrorCount / discoveredCount
		: ( buildErrorCount > 0 ? 1 : 0 );
	if ( buildErrorRatio > BUILD_ERROR_ATTENTION_RATIO ) {
		return { delete: false, reason: 'build-errors' };
	}
	if ( run.uploadErrors > 0 ) {
		return { delete: false, reason: 'upload-errors' };
	}
	if ( run.pollPending > 0 ) {
		return { delete: false, reason: 'pending-items' };
	}
	if ( run.pollErrors > 0 ) {
		return { delete: false, reason: 'item-errors' };
	}
	// Guard against a discovery that quietly under-counted: if a prior manifest existed and
	// this run prepared far fewer docs, refuse to prune rather than gut the corpus.
	if (
		run.previousManifestCount > 0 &&
		run.prepared < run.previousManifestCount * PREPARED_REGRESSION_RATIO
	) {
		return { delete: false, reason: 'prepared-count-regression' };
	}
	if ( run.validationOk !== true ) {
		return { delete: true, reason: 'validation-warning' };
	}
	return { delete: true, reason: 'healthy' };
}

function resolveSummaryStatus( run ) {
	if ( run.dryRun ) {
		return 'ok';
	}
	const discovered = run.discovered || 0;
	const buildErrors = run.buildErrors || 0;
	const builtNothing = run.prepared === 0 && discovered > 0;
	const buildErrorRatio = discovered > 0 ? buildErrors / discovered : 0;
	if (
		run.validationOk === false ||
		run.uploadErrors > 0 ||
		run.pollPending > 0 ||
		run.pollErrors > 0 ||
		( run.discoveryErrors || 0 ) > 0 ||
		builtNothing ||
		buildErrorRatio > BUILD_ERROR_ATTENTION_RATIO
	) {
		return 'needs-attention';
	}
	return 'ok';
}

async function pollUntilSettled( desiredKeys, options, auth ) {
	if ( options.dryRun || options.pollSeconds <= 0 || desiredKeys.size === 0 ) {
		return { skipped: true, pending: 0, errors: [] };
	}

	const deadline = Date.now() + options.pollSeconds * 1000;
	let lastStats = null;
	while ( Date.now() < deadline ) {
		lastStats = await getInstanceStats( options, auth );
		if ( statsActiveCount( lastStats ) !== 0 ) {
			await delay( 5000 );
			continue;
		}

		const latest = await listBuiltinItems( options, auth );
		const settlement = evaluateSettlement( latest, desiredKeys );

		// Keys that never appear (dropped write / eventual-consistency lag) are not
		// "pending" in the listing, so success requires zero missing keys; item-level
		// error/skip statuses must also clear, or a degraded index would settle as "ok".
		if ( isSettlementComplete( settlement ) ) {
			return { skipped: false, pending: 0, errors: settlement.errors };
		}

		return {
			skipped: false,
			pending: settlement.missing.length + settlement.pending.length,
			errors: settlement.errors,
			stats: lastStats,
		};
	}

	const latest = await listBuiltinItems( options, auth );
	const { missing, pending, errors } = evaluateSettlement( latest, desiredKeys );
	return {
		skipped: false,
		pending: missing.length + pending.length,
		errors,
		stats: lastStats,
	};
}

function statsActiveCount( stats ) {
	return normalizeCount( stats?.queued ) +
		normalizeCount( stats?.running ) +
		normalizeCount( stats?.outdated );
}

function delay( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

async function validatePublicEndpoint( options ) {
	const response = await fetchWithTimeout( options.publicUrl, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'User-Agent': USER_AGENT,
		},
		body: JSON.stringify( {
			messages: [
				{
					role: 'user',
					content: VALIDATION_QUERY.replace( '7.0', options.release.replace( '-', '.' ) ),
				},
			],
			ai_search_options: {
				retrieval: {
					retrieval_type: 'hybrid',
					max_num_results: 8,
					match_threshold: 0.2,
					context_expansion: 1,
					fusion_method: 'rrf',
					return_on_failure: true,
				},
			},
		} ),
	} );
	const text = await response.text();
	let data;
	try {
		data = JSON.parse( text );
	} catch {
		data = {};
	}
	const result = data?.result && typeof data.result === 'object' ? data.result : data;
	const chunks = Array.isArray( result?.chunks ) ? result.chunks : [];
	const urls = chunks.map( extractChunkUrl ).filter( Boolean );
	const sourceTypes = [ ...new Set( urls.map( classifySourceUrl ).filter( Boolean ) ) ];

	return {
		status: response.status,
		chunkCount: chunks.length,
		sourceTypes,
		urls: urls.slice( 0, 8 ),
		ok: response.ok && chunks.length > 0 && sourceTypes.includes( 'developer-docs' ),
	};
}

function extractChunkUrl( chunk ) {
	if ( ! chunk || typeof chunk !== 'object' ) {
		return '';
	}
	const item = chunk.item && typeof chunk.item === 'object' ? chunk.item : {};
	const metadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : {};
	for ( const key of [ 'source_url', 'sourceUrl', 'url', 'original_url', 'originalUrl', 'permalink' ] ) {
		if ( typeof metadata[ key ] === 'string' && metadata[ key ].trim() ) {
			return metadata[ key ].trim();
		}
	}
	const text = typeof chunk.text === 'string' ? chunk.text : '';
	const match = text.match( /(?:source_url|original_url):\s*(?:"([^"]+)"|([^\n]+))/i );
	return ( match?.[ 1 ] || match?.[ 2 ] || '' ).trim();
}

function classifySourceUrl( value ) {
	let url;
	try {
		url = new URL( value );
	} catch {
		return '';
	}
	if ( url.hostname === 'developer.wordpress.org' && [ '/block-editor/', '/rest-api/', '/themes/', '/reference/' ].some( ( prefix ) => url.pathname.startsWith( prefix ) ) ) {
		return 'developer-docs';
	}
	if ( url.hostname === 'developer.wordpress.org' && url.pathname.startsWith( '/news/' ) ) {
		return 'developer-blog';
	}
	if ( url.hostname === 'make.wordpress.org' && url.pathname.startsWith( '/core/' ) ) {
		return 'make-core';
	}
	return '';
}

async function processEntries( urls, roots, options, auth, existingByKey, lastmods = {}, existingByUrl = new Map() ) {
	const desiredKeys = new Set();
	const desiredSourceIdentities = new Set();
	const manifest = [];
	const uploaded = [];
	const skipped = [];
	const duplicateSources = [];
	const uploadErrors = [];
	const buildErrors = [];
	let prepared = 0;
	let reused = 0;
	const preparedItems = new Array( urls.length );

	let index = 0;
	async function worker() {
		while ( index < urls.length ) {
			const current = index++;
			const url = urls[ current ];

			// Incremental skip: if the sitemap reports this page unchanged since we last
			// crawled it, reuse the existing item instead of re-fetching. This is what
			// keeps the weekly run inside the job timeout and gentle on the upstream.
			if ( ! options.fullRefetch ) {
				const existingByUrlItem = existingByUrl.get( url );
				if ( isFreshByLastmod( lastmods[ url ], existingByUrlItem ) ) {
					const reusedKey = String( existingByUrlItem.key || '' );
					if ( reusedKey ) {
						const metadata = existingByUrlItem.metadata && typeof existingByUrlItem.metadata === 'object' ? existingByUrlItem.metadata : {};
						const canonical = normalizeTrustedUrl( metadata.source_url || metadata.sourceUrl || url );
						preparedItems[ current ] = {
							kind: 'reuse',
							index: current,
							url,
							canonical,
							identity: sourcePartIdentityForItem( existingByUrlItem ),
							key: reusedKey,
							item: existingByUrlItem,
						};
						continue;
					}
				}
			}

			let entry;
			try {
				entry = await buildEntryForUrl( url, roots, options );
			} catch ( error ) {
				buildErrors.push( { url, message: error.message } );
				console.warn( `Skipping ${ url }: ${ error.message }` );
				continue;
			}

			preparedItems[ current ] = {
				kind: 'entry',
				index: current,
				url,
				canonical: entry.url,
				identity: sourcePartIdentityForUrl( entry.url ),
				key: entry.key,
				entry,
			};
		}
	}

	await Promise.all( Array.from( { length: Math.min( PAGE_CONCURRENCY, urls.length ) }, worker ) );

	const { items: uploadPlan, duplicates } = dedupePreparedItems( preparedItems.filter( Boolean ) );
	duplicateSources.push( ...duplicates );
	for ( const duplicate of duplicates ) {
		skipped.push( duplicate );
	}

	const entriesToUpload = [];
	for ( const item of uploadPlan ) {
		++prepared;
		desiredKeys.add( item.key );
		if ( item.identity ) {
			desiredSourceIdentities.add( item.identity );
		}

		if ( item.kind === 'reuse' ) {
			++reused;
			manifest.push( manifestEntryFromExisting( item.item, item.url ) );
			skipped.push( { key: item.key, url: item.url, reason: 'fresh-lastmod' } );
			continue;
		}

		const entry = item.entry;
		manifest.push( manifestEntry( entry ) );

		const existing = existingByKey.get( entry.key );
		if ( ! shouldUpload( entry, existing ) ) {
			skipped.push( { key: entry.key, url: entry.url, reason: 'unchanged' } );
			continue;
		}

		entriesToUpload.push( entry );
	}

	let uploadIndex = 0;
	async function uploadWorker() {
		while ( uploadIndex < entriesToUpload.length ) {
			const current = uploadIndex++;
			const entry = entriesToUpload[ current ];

			try {
				uploaded.push( await uploadEntry( entry, options, auth ) );
			} catch ( error ) {
				console.warn( `Upload failed for ${ entry.url }: ${ error.message }` );
				uploadErrors.push( { key: entry.key, url: entry.url, message: error.message } );
			}
		}
	}

	await Promise.all( Array.from( { length: Math.min( PAGE_CONCURRENCY, entriesToUpload.length ) }, uploadWorker ) );

	return {
		desiredKeys,
		desiredSourceIdentities,
		manifest,
		uploaded,
		skipped,
		duplicateSources,
		uploadErrors,
		buildErrors,
		prepared,
		reused,
	};
}

function manifestEntry( entry ) {
	return {
		key: entry.key,
		url: entry.url,
		title: entry.title,
		retrievedAt: entry.retrievedAt,
		publishedAt: entry.publishedAt,
		contentHash: entry.contentHash,
		bodyBytes: entry.bodyBytes,
	};
}

function manifestEntryFromExisting( item, url ) {
	const metadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : {};
	return {
		key: String( item.key || '' ),
		url: metadata.source_url || url,
		title: metadata.title || '',
		retrievedAt: manifestTimestamp( metadata.retrieved_at || metadata.retrievedAt || '' ),
		publishedAt: manifestTimestamp( metadata.published_at || metadata.publishedAt || '' ),
		contentHash: metadata.content_hash || '',
		bodyBytes: 0,
	};
}

function manifestTimestamp( value ) {
	if ( value === '' || value === null || typeof value === 'undefined' ) {
		return '';
	}
	const timestamp = parseTimestampMs( value );
	if ( ! Number.isNaN( timestamp ) ) {
		return new Date( timestamp ).toISOString();
	}
	return typeof value === 'string' ? value : '';
}

async function writeSummary( options, summary, manifest ) {
	await fsp.mkdir( options.outputDir, { recursive: true } );
	await fsp.writeFile( path.join( options.outputDir, 'summary.json' ), JSON.stringify( summary, null, 2 ) + '\n' );
	await fsp.writeFile(
		path.join( options.outputDir, 'manifest.json' ),
		JSON.stringify( manifest, null, 2 ) + '\n'
	);
}

async function readPreviousManifestCount( options ) {
	try {
		const raw = await fsp.readFile( path.join( options.outputDir, 'manifest.json' ), 'utf8' );
		const parsed = JSON.parse( raw );
		return Array.isArray( parsed ) ? parsed.length : 0;
	} catch {
		return 0;
	}
}

async function main() {
	const options = parseArgs( process.argv.slice( 2 ) );
	const auth = getAuth( options );
	const roots = sourceRootsForRelease();
	const startedAt = new Date().toISOString();

	console.log( `Discovering trusted WordPress docs sources for ${ options.release }...` );
	const discovery = await discoverSourceUrls( roots, options );
	const urls = discovery.urls;
	const discoveryErrorCount = discovery.errors.length;
	console.log( `Discovered ${ urls.length } source URLs.` );
	if ( discoveryErrorCount > 0 ) {
		console.warn( `Discovery hit ${ discoveryErrorCount } sitemap error(s); stale deletion will be skipped.` );
	}

	let configureResult = { skipped: true };
	let existingItems = [];
	let deleted = [];
	let sameSourceDeleted = [];
	let poll = { skipped: true, pending: 0, errors: [] };
	let validation = { skipped: options.dryRun };

	if ( auth ) {
		configureResult = await configureInstance( options, auth );
		existingItems = await listBuiltinItems( options, auth );
	}

	const existingByKey = new Map();
	for ( const item of existingItems ) {
		const key = String( item.key || '' );
		if ( key ) {
			existingByKey.set( key, item );
		}
	}
	const existingByUrl = existingItemsByUrl( existingItems );

	const processed = await processEntries( urls, roots, options, auth, existingByKey, discovery.lastmods, existingByUrl );
	console.log(
		`Prepared ${ processed.prepared } Markdown documents (${ processed.reused } reused unchanged via sitemap lastmod).`
	);

	// Uploads happen inside processEntries; settle and validate BEFORE any destructive
	// deletion so a degraded discovery/build cannot prune the corpus (resolveStaleDeletion).
	poll = await pollUntilSettled( processed.desiredKeys, options, auth );
	if ( ! options.dryRun ) {
		validation = await validatePublicEndpoint( options );
	}

	const uploadErrorCount = processed.uploadErrors.length;
	const buildErrorCount = processed.buildErrors.length;
	const pollPending = poll.pending || 0;
	const pollErrorCount = Array.isArray( poll.errors ) ? poll.errors.length : 0;
	const settledReplacementKeys = ! options.dryRun &&
		poll.skipped !== true &&
		uploadErrorCount === 0 &&
		pollPending === 0 &&
		pollErrorCount === 0;
	const deletedItemIds = new Set();

	if ( auth && settledReplacementKeys ) {
		const superseded = findSupersededSourceItems(
			existingItems,
			processed.desiredKeys,
			processed.desiredSourceIdentities,
			options.instance
		);
		for ( const item of superseded ) {
			const result = await deleteItem( item, options, auth );
			sameSourceDeleted.push( result );
			const id = String( item.id || result.id || '' );
			if ( id ) {
				deletedItemIds.add( id );
			}
		}
	}

	const cachedManifestCount = await readPreviousManifestCount( options );
	const remoteBaselineCount = managedCompletedSourceUrlCount( existingItems, options.instance );
	const previousManifestCount = Math.max( cachedManifestCount, remoteBaselineCount );
	const deletion = resolveStaleDeletion( {
		dryRun: options.dryRun,
		deleteStale: options.deleteStale,
		explicitSources: explicitSourcesRequested( options ),
		limit: options.limit,
		discoveryErrors: discoveryErrorCount,
		pollSkipped: poll.skipped === true,
		discovered: urls.length,
		prepared: processed.prepared,
		buildErrors: buildErrorCount,
		uploadErrors: uploadErrorCount,
		pollPending,
		pollErrors: pollErrorCount,
		validationOk: validation.ok,
		previousManifestCount,
	} );

	if ( deletion.delete ) {
		const stale = existingItems.filter( ( item ) => {
			const key = String( item.key || '' );
			const id = String( item.id || '' );
			return isManagedDocsKey( key, options.instance ) &&
				! processed.desiredKeys.has( key ) &&
				! deletedItemIds.has( id );
		} );
		for ( const item of stale ) {
			deleted.push( await deleteItem( item, options, auth ) );
		}
	} else if ( options.deleteStale && ! options.dryRun ) {
		console.warn( `Skipping stale deletion: ${ deletion.reason }.` );
	}

	const summary = {
		status: resolveSummaryStatus( {
			dryRun: options.dryRun,
			discovered: urls.length,
			prepared: processed.prepared,
			uploadErrors: uploadErrorCount,
			pollPending,
			pollErrors: pollErrorCount,
			validationOk: validation.ok,
			buildErrors: buildErrorCount,
			discoveryErrors: discoveryErrorCount,
		} ),
		startedAt,
		finishedAt: new Date().toISOString(),
		dryRun: options.dryRun,
		release: options.release,
		instance: options.instance,
		publicUrl: options.publicUrl,
		sourceUrls: urls.length,
		preparedDocuments: processed.prepared,
		regressionBaseline: {
			cachedManifestCount,
			remoteCompletedSourceUrls: remoteBaselineCount,
			appliedCount: previousManifestCount,
		},
		configureResult,
		sameSourceDeletion: {
			performed: sameSourceDeleted.length > 0,
			deleted: sameSourceDeleted.length,
			reason: settledReplacementKeys ? 'settled-current-sources' : 'replacement-not-settled',
		},
		staleDeletion: { performed: deletion.delete, reason: deletion.reason },
		counts: {
			uploaded: processed.uploaded.length,
			skipped: processed.skipped.length,
			deleted: deleted.length + sameSourceDeleted.length,
			sameSourceDeleted: sameSourceDeleted.length,
			duplicateSources: processed.duplicateSources.length,
			discoveryErrors: discoveryErrorCount,
			buildErrors: buildErrorCount,
			uploadErrors: uploadErrorCount,
			pending: pollPending,
			errors: pollErrorCount,
		},
		discoveryErrors: discovery.errors.slice( 0, 10 ),
		duplicateSources: processed.duplicateSources.slice( 0, 10 ),
		buildErrors: processed.buildErrors.slice( 0, 10 ),
		uploadErrors: processed.uploadErrors.slice( 0, 10 ),
		validation,
	};

	await writeSummary( options, summary, processed.manifest );
	console.log( `DOCS_AI_SEARCH_RESULT=${ JSON.stringify( summary ) }` );

	if ( summary.status !== 'ok' ) {
		process.exit( 1 );
	}
}

if ( require.main === module ) {
	main().catch( ( error ) => {
		console.error( error.stack || error.message );
		process.exit( 1 );
	} );
}

module.exports = {
	contentHashForEntry,
	configureInstance,
	discoverSourceUrls,
	findSupersededSourceItems,
	existingItemsByUrl,
	fetchJson,
	isFreshByLastmod,
	isCorpusDocumentUrl,
	evaluateSettlement,
	isSettlementComplete,
	listBuiltinItems,
	resolveStaleDeletion,
	resolveSummaryStatus,
	extractTitle,
	fetchText,
	htmlToMarkdown,
	makeCorePostDate,
	managedCompletedSourceUrlCount,
	manifestEntry,
	manifestEntryFromExisting,
	processEntries,
	cleanText,
	flattenString,
	pollUntilSettled,
	buildMarkdownDocument,
	buildItemKey,
	boundedSlug,
	normalizeTrustedUrl,
	sourcePartIdentityForUrl,
	parseArgs,
	readSitemap,
	sitemapUrlWithinOrigins,
	sourceRootsForRelease,
	truncateUtf8ToBytes,
	urlMatchesRoots,
};
