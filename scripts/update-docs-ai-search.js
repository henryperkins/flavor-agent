#!/usr/bin/env node
'use strict';

const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const fsp = require( 'node:fs/promises' );
const path = require( 'node:path' );

const DEFAULT_INSTANCE = 'wp-dev';
const DEFAULT_PUBLIC_SEARCH_URL = 'https://ba566764-a507-4cd0-8cc8-cffbbde72ac3.search.ai.cloudflare.com/search';
const DEFAULT_RELEASE = '7-0';
const DEFAULT_OUTPUT_DIR = path.resolve( __dirname, '..', 'output', 'docs-ai-search' );
const LEGACY_ITEM_KEY_PREFIX = 'wp-dev-docs-';
const USER_AGENT = 'Flavor Agent Developer Docs AI Search Updater (+https://github.com/henryperkins/flavor-agent)';
const MAX_UPLOAD_BYTES = 3_900_000;
const KEY_MAX_BYTES = 128;
const SITEMAP_CONCURRENCY = 4;
const PAGE_CONCURRENCY = 5;
const REQUEST_TIMEOUT_MS = 30_000;
const VALIDATION_QUERY = 'WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes';

const METADATA_SCHEMA = [
	{ field_name: 'source_url', data_type: 'text' },
	{ field_name: 'retrieved_at', data_type: 'datetime' },
	{ field_name: 'published_at', data_type: 'datetime' },
	{ field_name: 'content_hash', data_type: 'text' },
	{ field_name: 'title', data_type: 'text' },
];

function usage() {
	return `Update the wp-dev Cloudflare AI Search Developer Docs corpus.

Usage:
  node scripts/update-docs-ai-search.js [options]

Options:
  --release=<slug>       Active WordPress major release slug, e.g. 7-0.
  --instance=<id>        AI Search instance ID. Defaults to env or wp-dev.
  --public-url=<url>     Public /search endpoint used for validation.
  --source-url=<url>     Add a specific trusted URL. May be repeated.
  --source-file=<path>   JSON array or newline-delimited list of trusted URLs.
  --limit=<n>            Limit discovered URLs, useful for smoke tests.
  --dry-run              Discover and build payloads without Cloudflare writes.
  --no-delete            Do not delete stale managed wp-dev-docs-* items.
  --skip-configure       Do not update the instance metadata schema.
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
		dryRun: false,
		deleteStale: true,
		configureInstance: true,
		pollSeconds: 180,
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
		if ( arg === '--no-delete' ) {
			options.deleteStale = false;
			continue;
		}
		if ( arg === '--skip-configure' ) {
			options.configureInstance = false;
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
	const number = Number.parseInt( value, 10 );
	if ( ! Number.isFinite( number ) || number < 0 ) {
		throw new Error( `--${ label } must be a non-negative integer.` );
	}
	return number;
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

function sourceRootsForRelease( release ) {
	return [
		'https://developer.wordpress.org/block-editor/',
		'https://developer.wordpress.org/rest-api/',
		'https://developer.wordpress.org/themes/',
		'https://developer.wordpress.org/reference/',
		'https://developer.wordpress.org/news/',
		`https://make.wordpress.org/core/${ release }/`,
		`https://make.wordpress.org/core/tag/dev-notes-${ release }/`,
		'https://make.wordpress.org/core/tag/gutenberg-new/',
	].map( normalizeTrustedUrl ).filter( Boolean );
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

function urlMatchesRoots( url, roots ) {
	return roots.some( ( root ) => url === root || url.startsWith( root ) );
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

async function fetchText( url, options = {} ) {
	const controller = new AbortController();
	const timeout = setTimeout( () => controller.abort(), options.timeoutMs || REQUEST_TIMEOUT_MS );
	try {
		const response = await fetch( url, {
			headers: {
				'Accept': options.accept || 'text/html,application/xhtml+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
				'User-Agent': USER_AGENT,
				...( options.headers || {} ),
			},
			signal: controller.signal,
		} );
		const text = await response.text();
		if ( ! response.ok ) {
			throw new Error( `HTTP ${ response.status } fetching ${ url }: ${ text.slice( 0, 160 ) }` );
		}
		return {
			url: response.url || url,
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

async function fetchJson( url, requestOptions = {} ) {
	const response = await fetchWithTimeout( url, requestOptions );
	const text = await response.text();
	let data;
	try {
		data = text ? JSON.parse( text ) : {};
	} catch ( error ) {
		throw new Error( `Could not parse JSON from ${ url }: ${ error.message }` );
	}
	if ( ! response.ok ) {
		throw new Error( `Cloudflare API returned HTTP ${ response.status } for ${ url }: ${ extractRemoteMessage( data ) }` );
	}
	return data;
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
		return dedupeUrls( explicit.filter( ( url ) => urlMatchesRoots( url, roots ) || roots.includes( url ) ) );
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

	const discovered = new Set( roots );
	const visitedSitemaps = new Set();
	const queue = [ ...sitemapUrls ];
	const queuedSitemaps = new Set( queue );

	while ( queue.length > 0 ) {
		const batch = queue.splice( 0, SITEMAP_CONCURRENCY );
		const batchResults = await Promise.allSettled(
			batch.map( ( sitemapUrl ) => readSitemap( sitemapUrl, visitedSitemaps ) )
		);

		for ( const result of batchResults ) {
			if ( result.status !== 'fulfilled' ) {
				console.warn( result.reason.message );
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
				if ( normalized && urlMatchesRoots( normalized, roots ) ) {
					discovered.add( normalized );
				}
			}
		}
	}

	const urls = [ ...discovered ].sort();
	return options.limit > 0 ? urls.slice( 0, options.limit ) : urls;
}

async function readExplicitSourceUrls( options ) {
	const urls = options.sourceUrls.map( normalizeTrustedUrl ).filter( Boolean );

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
	return [ ...new Set( urls.map( normalizeTrustedUrl ).filter( Boolean ) ) ].sort();
}

async function discoverRobotsSitemaps( origin ) {
	const robotsUrl = new URL( '/robots.txt', origin ).toString();
	try {
		const response = await fetchText( robotsUrl, { accept: 'text/plain,*/*;q=0.8' } );
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

async function readSitemap( sitemapUrl, visited ) {
	if ( visited.has( sitemapUrl ) ) {
		return { urls: [], sitemaps: [] };
	}
	visited.add( sitemapUrl );

	const response = await fetchText( sitemapUrl, { accept: 'application/xml,text/xml,*/*;q=0.8' } );
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

	return { urls, sitemaps };
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
	const response = await fetchText( url );
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
	const contentHash = sha256( canonical + '\n' + markdown );
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

	let body = `${ frontmatter }# ${ entry.title }\n\n${ entry.markdown }\n`;
	if ( Buffer.byteLength( body, 'utf8' ) > MAX_UPLOAD_BYTES ) {
		const budget = MAX_UPLOAD_BYTES - Buffer.byteLength( frontmatter, 'utf8' ) - 256;
		body = `${ frontmatter }# ${ entry.title }\n\n${ Buffer.from( entry.markdown ).subarray( 0, budget ).toString( 'utf8' ) }\n\n[Content truncated before upload to fit Cloudflare AI Search item limits.]\n`;
	}

	return body;
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

		const info = data.result_info || {};
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

function evaluateSettlement( latest, desiredKeys ) {
	const scoped = latest.filter( ( item ) => desiredKeys.has( String( item.key || '' ) ) );
	const seen = new Set( scoped.map( ( item ) => String( item.key || '' ) ) );
	const missing = [ ...desiredKeys ].filter( ( key ) => ! seen.has( key ) );
	const pending = scoped.filter( ( item ) => [ 'queued', 'running', 'outdated' ].includes( String( item.status || '' ) ) );
	const errors = scoped.filter( ( item ) => [ 'error', 'skipped' ].includes( String( item.status || '' ) ) );

	return { missing, pending, errors };
}

async function pollUntilSettled( desiredKeys, options, auth ) {
	if ( options.dryRun || options.pollSeconds <= 0 || desiredKeys.size === 0 ) {
		return { skipped: true, pending: 0, errors: [] };
	}

	const deadline = Date.now() + options.pollSeconds * 1000;
	let latest = [];
	while ( Date.now() < deadline ) {
		latest = await listBuiltinItems( options, auth );
		const { missing, pending, errors } = evaluateSettlement( latest, desiredKeys );

		// Keys that never appear (dropped write / eventual-consistency lag) are not
		// "pending" in the listing, so success must also require zero missing keys —
		// otherwise an incomplete corpus would settle as "ok".
		if ( pending.length === 0 && missing.length === 0 ) {
			return { skipped: false, pending: 0, errors };
		}

		await delay( 5000 );
	}

	const { missing, pending, errors } = evaluateSettlement( latest, desiredKeys );
	return {
		skipped: false,
		pending: missing.length + pending.length,
		errors,
	};
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

async function mapLimit( items, limit, callback ) {
	const results = new Array( items.length );
	let index = 0;

	async function worker() {
		while ( index < items.length ) {
			const current = index++;
			results[ current ] = await callback( items[ current ], current );
		}
	}

	await Promise.all( Array.from( { length: Math.min( limit, items.length ) }, worker ) );
	return results;
}

async function processEntries( urls, roots, options, auth, existingByKey ) {
	const desiredKeys = new Set();
	const manifest = [];
	const uploaded = [];
	const skipped = [];
	const uploadErrors = [];
	let prepared = 0;

	let index = 0;
	async function worker() {
		while ( index < urls.length ) {
			const current = index++;
			const url = urls[ current ];
			let entry;
			try {
				entry = await buildEntryForUrl( url, roots, options );
			} catch ( error ) {
				console.warn( `Skipping ${ url }: ${ error.message }` );
				continue;
			}

			++prepared;
			desiredKeys.add( entry.key );
			manifest.push( manifestEntry( entry ) );

			const existing = existingByKey.get( entry.key );
			if ( ! shouldUpload( entry, existing ) ) {
				skipped.push( { key: entry.key, reason: 'unchanged' } );
				continue;
			}

			try {
				uploaded.push( await uploadEntry( entry, options, auth ) );
			} catch ( error ) {
				console.warn( `Upload failed for ${ entry.url }: ${ error.message }` );
				uploadErrors.push( { key: entry.key, url: entry.url, message: error.message } );
			}
		}
	}

	await Promise.all( Array.from( { length: Math.min( PAGE_CONCURRENCY, urls.length ) }, worker ) );

	return {
		desiredKeys,
		manifest,
		uploaded,
		skipped,
		uploadErrors,
		prepared,
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

async function writeSummary( options, summary, manifest ) {
	await fsp.mkdir( options.outputDir, { recursive: true } );
	await fsp.writeFile( path.join( options.outputDir, 'summary.json' ), JSON.stringify( summary, null, 2 ) + '\n' );
	await fsp.writeFile(
		path.join( options.outputDir, 'manifest.json' ),
		JSON.stringify( manifest, null, 2 ) + '\n'
	);
}

async function main() {
	const options = parseArgs( process.argv.slice( 2 ) );
	const auth = getAuth( options );
	const roots = sourceRootsForRelease( options.release );
	const startedAt = new Date().toISOString();

	console.log( `Discovering trusted WordPress docs sources for ${ options.release }...` );
	const urls = await discoverSourceUrls( roots, options );
	console.log( `Discovered ${ urls.length } source URLs.` );

	let configureResult = { skipped: true };
	let existingItems = [];
	let deleted = [];
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

	const processed = await processEntries( urls, roots, options, auth, existingByKey );
	console.log( `Prepared ${ processed.prepared } uploadable Markdown documents.` );

	if ( options.deleteStale ) {
		const stale = existingItems.filter( ( item ) => {
			const key = String( item.key || '' );
			return isManagedDocsKey( key, options.instance ) && ! processed.desiredKeys.has( key );
		} );
		for ( const item of stale ) {
			deleted.push( await deleteItem( item, options, auth ) );
		}

		function isManagedDocsKey( key, instance ) {
			return key.startsWith( `ai-search/${ instance }/` ) ||
				key.startsWith( 'ai-search/wp-dev-docs/' ) ||
				key.startsWith( LEGACY_ITEM_KEY_PREFIX );
		}
	}

	poll = await pollUntilSettled( processed.desiredKeys, options, auth );
	if ( ! options.dryRun ) {
		validation = await validatePublicEndpoint( options );
	}

	const uploadErrorCount = processed.uploadErrors.length;
	const summary = {
		status: ! options.dryRun && ( validation.ok === false || uploadErrorCount > 0 ) ? 'needs-attention' : 'ok',
		startedAt,
		finishedAt: new Date().toISOString(),
		dryRun: options.dryRun,
		release: options.release,
		instance: options.instance,
		publicUrl: options.publicUrl,
		sourceUrls: urls.length,
		preparedDocuments: processed.prepared,
		configureResult,
		counts: {
			uploaded: processed.uploaded.length,
			skipped: processed.skipped.length,
			deleted: deleted.length,
			uploadErrors: uploadErrorCount,
			pending: poll.pending || 0,
			errors: Array.isArray( poll.errors ) ? poll.errors.length : 0,
		},
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
	discoverSourceUrls,
	evaluateSettlement,
	extractTitle,
	htmlToMarkdown,
	manifestEntry,
	cleanText,
	flattenString,
	buildItemKey,
	boundedSlug,
	normalizeTrustedUrl,
	readSitemap,
	sitemapUrlWithinOrigins,
};
