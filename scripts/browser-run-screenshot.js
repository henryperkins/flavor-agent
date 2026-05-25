#!/usr/bin/env node
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const DEFAULT_BASE_URL_ENV = 'BROWSER_RUN_DEFAULT_BASE_URL';
const DEFAULT_BASE_URL = defaultBaseUrlFromEnv();
const DEFAULT_OUTPUT_ROOT = path.join( 'output', 'browser-run' );
const DEFAULT_VIEWPORT = {
	width: 1440,
	height: 1200,
	deviceScaleFactor: 1,
};
const DEFAULT_GOTO_OPTIONS = {
	waitUntil: 'networkidle0',
	timeout: 45000,
};
const SCREENSHOT_ENDPOINT =
	'https://api.cloudflare.com/client/v4/accounts/{accountId}/browser-rendering/screenshot';

const PRESETS = {
	settings: {
		id: 'settings',
		path: '/wp-admin/options-general.php?page=flavor-agent',
		requiresAuth: true,
	},
	admin: {
		id: 'admin',
		path: '/wp-admin/',
		requiresAuth: true,
	},
	'block-editor': {
		id: 'block-editor',
		path: null,
		requiresAuth: true,
		requireUrl: true,
	},
	'site-editor': {
		id: 'site-editor',
		path: '/wp-admin/site-editor.php',
		requiresAuth: true,
	},
	workflow: {
		id: 'workflow',
		path: null,
		requireManifest: true,
	},
};

const SAFE_RESPONSE_HEADERS = new Set( [
	'cache-control',
	'cf-cache-status',
	'cf-ray',
	'content-length',
	'content-type',
	'date',
	'expires',
	'last-modified',
	'server',
	'x-browser-ms-used',
] );

const HELP = `Flavor Agent Browser Run screenshot audit

Usage:
  node scripts/browser-run-screenshot.js --preset=settings [options]
  node scripts/browser-run-screenshot.js --preset=block-editor --url="/wp-admin/post.php?post=123&action=edit" [options]
  node scripts/browser-run-screenshot.js --manifest=docs/audits/admin-ui-flow.json [options]

Options:
  --preset=<name>              settings, admin, block-editor, site-editor, workflow
  --manifest=<path>            Workflow manifest JSON with URL-only capture steps
  --base-url=<url>             Remote audit host for relative URLs
  --url=<path-or-url>          Override preset URL
  --viewport=<WxH[@scale]>     Override viewport, for example 1440x1200 or 390x844@2
  --selector=<css-selector>    Capture a specific selector
  --full-page                  Enable full-page screenshots
  --no-full-page               Disable full-page screenshots
  --cookies-file=<path>        JSON array of Puppeteer-compatible cookies
  --extra-headers-file=<path>  JSON object of extra HTTP headers
  --output=<path>              Output root (default: ${ DEFAULT_OUTPUT_ROOT })
  --run-name=<name>            Override output run name
  --user-agent=<value>         Pass an explicit user agent
  --add-style-tag-file=<path>  Inject CSS content through addStyleTag
  --add-script-tag-file=<path> Inject JS content through addScriptTag
  --wait-until=<event>         Puppeteer goto waitUntil value (default: networkidle0)
  --timeout=<ms>               Puppeteer goto timeout (default: 45000)
  --help, -h                   Show this message

Environment:
  CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN are required.
  BROWSER_RUN_DEFAULT_BASE_URL can provide the default remote audit host.
  Admin/editor pages also require --cookies-file, BROWSER_RUN_COOKIES_JSON, or --extra-headers-file.
`;

function parseArgs( argv, env = process.env ) {
	const opts = {
		preset: null,
		manifest: null,
		baseUrl: defaultBaseUrlFromEnv( env ),
		baseUrlProvided: false,
		url: null,
		viewport: null,
		selector: null,
		fullPage: null,
		cookiesFile: null,
		extraHeadersFile: null,
		outputRoot: DEFAULT_OUTPUT_ROOT,
		runName: null,
		userAgent: null,
		addStyleTagFiles: [],
		addScriptTagFiles: [],
		waitUntil: DEFAULT_GOTO_OPTIONS.waitUntil,
		timeout: DEFAULT_GOTO_OPTIONS.timeout,
		cacheTTL: null,
		help: false,
	};

	const args = argv.slice( 2 );
	for ( let i = 0; i < args.length; i++ ) {
		const arg = args[ i ];

		if ( arg === '--help' || arg === '-h' ) {
			opts.help = true;
			continue;
		}
		if ( arg === '--full-page' ) {
			opts.fullPage = true;
			continue;
		}
		if ( arg === '--no-full-page' ) {
			opts.fullPage = false;
			continue;
		}

		const { key, value } = parseOption( arg, args, i );
		if ( value.consumedNext ) {
			i++;
		}

		switch ( key ) {
			case 'preset':
				opts.preset = value.value;
				break;
			case 'manifest':
				opts.manifest = value.value;
				break;
			case 'base-url':
				opts.baseUrl = value.value;
				opts.baseUrlProvided = true;
				break;
			case 'url':
				opts.url = value.value;
				break;
			case 'viewport':
				opts.viewport = parseViewport( value.value );
				break;
			case 'selector':
				opts.selector = value.value;
				break;
			case 'cookies-file':
				opts.cookiesFile = value.value;
				break;
			case 'extra-headers-file':
				opts.extraHeadersFile = value.value;
				break;
			case 'output':
				opts.outputRoot = value.value;
				break;
			case 'run-name':
				opts.runName = value.value;
				break;
			case 'user-agent':
				opts.userAgent = value.value;
				break;
			case 'add-style-tag-file':
				opts.addStyleTagFiles.push( value.value );
				break;
			case 'add-script-tag-file':
				opts.addScriptTagFiles.push( value.value );
				break;
			case 'wait-until':
				opts.waitUntil = value.value;
				break;
			case 'timeout':
				opts.timeout = parsePositiveInteger( value.value, '--timeout' );
				break;
			case 'cache-ttl':
				opts.cacheTTL = parsePositiveInteger(
					value.value,
					'--cache-ttl'
				);
				break;
			default:
				throw new Error( `Unknown argument: --${ key }` );
		}
	}

	if ( opts.preset && ! PRESETS[ opts.preset ] ) {
		throw new Error( `Unknown preset: ${ opts.preset }` );
	}

	if ( ! opts.help && ! opts.preset && ! opts.manifest ) {
		throw new Error( 'Provide --preset or --manifest' );
	}

	return opts;
}

function parseOption( arg, args, index ) {
	if ( ! arg.startsWith( '--' ) ) {
		throw new Error( `Unexpected argument: ${ arg }` );
	}

	const stripped = arg.slice( 2 );
	const equalsIndex = stripped.indexOf( '=' );
	if ( equalsIndex !== -1 ) {
		return {
			key: stripped.slice( 0, equalsIndex ),
			value: {
				value: stripped.slice( equalsIndex + 1 ),
				consumedNext: false,
			},
		};
	}

	const next = args[ index + 1 ];
	if ( ! next || next.startsWith( '--' ) ) {
		throw new Error( `Missing value for ${ arg }` );
	}

	return {
		key: stripped,
		value: {
			value: next,
			consumedNext: true,
		},
	};
}

function parseViewport( value ) {
	const match = String( value ).match(
		/^([1-9][0-9]*)x([1-9][0-9]*)(?:@([1-9][0-9]*(?:\.[0-9]+)?))?$/
	);

	if ( ! match ) {
		throw new Error(
			`Invalid --viewport value: ${ value }. Expected WIDTHxHEIGHT or WIDTHxHEIGHT@SCALE.`
		);
	}

	const viewport = {
		width: Number.parseInt( match[ 1 ], 10 ),
		height: Number.parseInt( match[ 2 ], 10 ),
	};

	if ( match[ 3 ] ) {
		viewport.deviceScaleFactor = Number.parseFloat( match[ 3 ] );
	}

	return viewport;
}

function parsePositiveInteger( value, label ) {
	const parsed = Number.parseInt( value, 10 );

	if ( ! Number.isSafeInteger( parsed ) || parsed < 0 ) {
		throw new Error( `Invalid ${ label } value: ${ value }` );
	}

	return parsed;
}

function createAuditPlan( options, deps = {} ) {
	const now = deps.now instanceof Date ? deps.now : new Date();
	const timestamp = formatTimestampForPath( now );

	if ( options.manifest ) {
		return createManifestPlan( options, timestamp );
	}

	const preset = PRESETS[ options.preset ];
	if ( ! preset ) {
		throw new Error( 'Provide --preset or --manifest' );
	}
	if ( preset.requireManifest ) {
		throw new Error( `Preset "${ options.preset }" requires --manifest` );
	}
	if ( preset.requireUrl && ! options.url ) {
		throw new Error( `Preset "${ options.preset }" requires --url` );
	}

	const finalUrl = resolveUrl( options.url || preset.path, options.baseUrl );
	const runName = sanitizeFilePart( options.runName || options.preset );
	const outputDir = path.join(
		options.outputRoot,
		`${ timestamp }-${ runName }`
	);
	const step = applyCliStepOverrides(
		{
			id: preset.id,
			preset: options.preset,
			url: finalUrl,
			viewport: { ...DEFAULT_VIEWPORT },
			fullPage: true,
			requiresAuth: Boolean( preset.requiresAuth ),
		},
		options
	);

	step.requiresAuth = step.requiresAuth || isAdminUrl( step.url );

	return {
		name: options.runName || options.preset,
		preset: options.preset,
		outputDir,
		steps: [ step ],
	};
}

function createManifestPlan( options, timestamp ) {
	const manifestPath = path.resolve( options.manifest );
	const manifest = readJsonFile( manifestPath, 'manifest' );

	validateManifest( manifest, manifestPath );

	const baseUrl = options.baseUrlProvided
		? options.baseUrl
		: manifest.baseUrl || options.baseUrl;
	const defaults = {
		viewport: {
			...DEFAULT_VIEWPORT,
			...( manifest.defaults && manifest.defaults.viewport
				? manifest.defaults.viewport
				: {} ),
		},
		fullPage:
			manifest.defaults && typeof manifest.defaults.fullPage === 'boolean'
				? manifest.defaults.fullPage
				: true,
		selector: manifest.defaults ? manifest.defaults.selector : undefined,
		userAgent: manifest.defaults ? manifest.defaults.userAgent : undefined,
		addStyleTag: manifest.defaults
			? manifest.defaults.addStyleTag
			: undefined,
		addScriptTag: manifest.defaults
			? manifest.defaults.addScriptTag
			: undefined,
	};
	const name = options.runName || manifest.name || 'workflow';
	const outputDir = path.join(
		options.outputRoot,
		`${ timestamp }-${ sanitizeFilePart( name ) }`
	);

	const steps = manifest.steps.map( ( manifestStep ) => {
		const step = applyCliStepOverrides(
			{
				id: sanitizeFilePart( manifestStep.id ),
				manifest: manifestPath,
				workflow: manifest.name || 'workflow',
				url: resolveUrl( manifestStep.url, baseUrl ),
				viewport: {
					...defaults.viewport,
					...( manifestStep.viewport || {} ),
				},
				fullPage:
					typeof manifestStep.fullPage === 'boolean'
						? manifestStep.fullPage
						: defaults.fullPage,
				selector: manifestStep.selector || defaults.selector,
				userAgent: manifestStep.userAgent || defaults.userAgent,
				addStyleTag: manifestStep.addStyleTag || defaults.addStyleTag,
				addScriptTag:
					manifestStep.addScriptTag || defaults.addScriptTag,
				requiresAuth: Boolean( manifestStep.requiresAuth ),
			},
			options
		);

		step.requiresAuth = step.requiresAuth || isAdminUrl( step.url );

		return step;
	} );

	return {
		name,
		manifest: manifestPath,
		outputDir,
		steps,
	};
}

function validateManifest( manifest, manifestPath ) {
	if (
		! manifest ||
		typeof manifest !== 'object' ||
		Array.isArray( manifest )
	) {
		throw new Error(
			`Invalid manifest ${ manifestPath }: expected JSON object`
		);
	}
	if ( ! Array.isArray( manifest.steps ) || manifest.steps.length === 0 ) {
		throw new Error(
			`Invalid manifest ${ manifestPath }: expected non-empty steps array`
		);
	}

	for ( const [ index, step ] of manifest.steps.entries() ) {
		if ( ! step || typeof step !== 'object' || Array.isArray( step ) ) {
			throw new Error(
				`Invalid manifest ${ manifestPath }: step ${ index } must be an object`
			);
		}
		if ( ! step.id || typeof step.id !== 'string' ) {
			throw new Error(
				`Invalid manifest ${ manifestPath }: step ${ index } requires string id`
			);
		}
		if ( ! step.url || typeof step.url !== 'string' ) {
			throw new Error(
				`Invalid manifest ${ manifestPath }: step ${ step.id } requires string url`
			);
		}
		if ( step.actions || step.clicks || step.script ) {
			throw new Error(
				`Invalid manifest ${ manifestPath }: step ${ step.id } must describe URL capture only`
			);
		}
	}
}

function applyCliStepOverrides( step, options ) {
	return pruneUndefined( {
		...step,
		viewport: options.viewport || step.viewport,
		selector:
			options.selector !== null && options.selector !== undefined
				? options.selector
				: step.selector,
		fullPage:
			typeof options.fullPage === 'boolean'
				? options.fullPage
				: step.fullPage,
		userAgent: options.userAgent || step.userAgent,
		addStyleTag:
			options.addStyleTagFiles.length > 0
				? readTagFiles( options.addStyleTagFiles )
				: step.addStyleTag,
		addScriptTag:
			options.addScriptTagFiles.length > 0
				? readTagFiles( options.addScriptTagFiles )
				: step.addScriptTag,
		gotoOptions: {
			waitUntil: options.waitUntil,
			timeout: options.timeout,
		},
	} );
}

function readTagFiles( files ) {
	return files.map( ( file ) => ( {
		content: fs.readFileSync( path.resolve( file ), 'utf8' ),
	} ) );
}

function resolveUrl( url, baseUrl ) {
	try {
		const absoluteUrl = new URL( url );
		if (
			absoluteUrl.protocol !== 'http:' &&
			absoluteUrl.protocol !== 'https:'
		) {
			throw new Error( 'URL must use http or https' );
		}
		return absoluteUrl.toString();
	} catch ( error ) {
		if ( 'URL must use http or https' === error.message ) {
			throw new Error( `Invalid URL "${ url }": ${ error.message }` );
		}
	}

	if ( ! baseUrl ) {
		throw new Error(
			`Invalid URL "${ url }": Provide --base-url or set ${ DEFAULT_BASE_URL_ENV } for relative URLs.`
		);
	}

	try {
		return new URL( url, normalizeBaseUrl( baseUrl ) ).toString();
	} catch ( error ) {
		throw new Error( `Invalid URL "${ url }": ${ error.message }` );
	}
}

function normalizeBaseUrl( baseUrl ) {
	try {
		const url = new URL( baseUrl );
		if ( url.protocol !== 'http:' && url.protocol !== 'https:' ) {
			throw new Error( 'base URL must use http or https' );
		}
		return url.toString();
	} catch ( error ) {
		throw new Error(
			`Invalid --base-url "${ baseUrl }": ${ error.message }`
		);
	}
}

function defaultBaseUrlFromEnv( env = process.env ) {
	return typeof env[ DEFAULT_BASE_URL_ENV ] === 'string'
		? env[ DEFAULT_BASE_URL_ENV ].trim()
		: '';
}

function isAdminUrl( url ) {
	try {
		const parsed = new URL( url );
		return (
			parsed.pathname.startsWith( '/wp-admin/' ) ||
			parsed.pathname === '/wp-admin' ||
			parsed.pathname.startsWith( '/wp-login.php' )
		);
	} catch ( error ) {
		throw new Error( `Invalid URL "${ url }": ${ error.message }` );
	}
}

function validateRunConfig( plan, options, deps = {} ) {
	const env = deps.env || process.env;

	if ( ! env.CLOUDFLARE_ACCOUNT_ID ) {
		throw new Error(
			'CLOUDFLARE_ACCOUNT_ID is required for Browser Run screenshots.'
		);
	}
	if ( ! env.CLOUDFLARE_API_TOKEN ) {
		throw new Error(
			'CLOUDFLARE_API_TOKEN is required for Browser Run screenshots.'
		);
	}

	const requiresAuth = plan.steps.some( ( step ) => step.requiresAuth );
	const hasAuthInput = Boolean(
		options.cookiesFile ||
			env.BROWSER_RUN_COOKIES_JSON ||
			options.extraHeadersFile
	);

	if ( requiresAuth && ! hasAuthInput ) {
		throw new Error(
			'This audit target requires WordPress authentication. Provide --cookies-file, BROWSER_RUN_COOKIES_JSON, or --extra-headers-file.'
		);
	}

	return true;
}

function loadAuthInputs( options, env = process.env ) {
	const auth = {};

	if ( options.cookiesFile ) {
		auth.cookies = readJsonFile(
			path.resolve( options.cookiesFile ),
			'cookies'
		);
	} else if ( env.BROWSER_RUN_COOKIES_JSON ) {
		auth.cookies = parseJsonString(
			env.BROWSER_RUN_COOKIES_JSON,
			'BROWSER_RUN_COOKIES_JSON'
		);
	}

	if ( options.extraHeadersFile ) {
		auth.extraHeaders = readJsonFile(
			path.resolve( options.extraHeadersFile ),
			'extra headers'
		);
	}

	if ( auth.cookies && ! Array.isArray( auth.cookies ) ) {
		throw new Error( 'Cookies input must be a JSON array.' );
	}
	if (
		auth.extraHeaders &&
		( typeof auth.extraHeaders !== 'object' ||
			Array.isArray( auth.extraHeaders ) )
	) {
		throw new Error( 'Extra headers input must be a JSON object.' );
	}

	return auth;
}

function readJsonFile( filePath, label ) {
	try {
		return JSON.parse( fs.readFileSync( filePath, 'utf8' ) );
	} catch ( error ) {
		throw new Error(
			`Invalid ${ label } JSON at ${ filePath }: ${ error.message }`
		);
	}
}

function parseJsonString( value, label ) {
	try {
		return JSON.parse( value );
	} catch ( error ) {
		throw new Error( `Invalid ${ label }: ${ error.message }` );
	}
}

function buildScreenshotRequest( step, auth = {} ) {
	const request = pruneUndefined( {
		url: step.url,
		viewport: step.viewport,
		screenshotOptions: {
			fullPage: step.fullPage,
		},
		gotoOptions: step.gotoOptions || DEFAULT_GOTO_OPTIONS,
		cookies: auth.cookies,
		setExtraHTTPHeaders: auth.extraHeaders,
		selector: step.selector,
		userAgent: step.userAgent,
		addStyleTag: step.addStyleTag,
		addScriptTag: step.addScriptTag,
	} );

	return request;
}

function redactMetadata( {
	accountId,
	httpStatus,
	responseHeaders,
	step,
	outputFile,
	timestamp,
	error,
} ) {
	const metadata = pruneUndefined( {
		stepId: step.id,
		preset: step.preset,
		manifest: step.manifest,
		workflow: step.workflow,
		finalUrl: step.url,
		timestamp,
		viewport: step.viewport,
		selector: step.selector,
		fullPage: step.fullPage,
		cloudflareAccountIdSuffix: accountId
			? accountId.slice( -4 )
			: undefined,
		httpStatus,
		responseHeaders: pickSafeHeaders( responseHeaders ),
		outputFile,
		error,
	} );

	return metadata;
}

function pickSafeHeaders( headers ) {
	const normalized = {};
	const source = headersToObject( headers );

	for ( const [ key, value ] of Object.entries( source ) ) {
		const lowerKey = key.toLowerCase();

		if ( SAFE_RESPONSE_HEADERS.has( lowerKey ) ) {
			normalized[ lowerKey ] = value;
		}
	}

	return normalized;
}

function headersToObject( headers ) {
	const out = {};

	if ( ! headers ) {
		return out;
	}

	if ( typeof headers.forEach === 'function' ) {
		headers.forEach( ( value, key ) => {
			out[ key ] = value;
		} );
		return out;
	}

	return { ...headers };
}

async function runCli( argv = process.argv, deps = {} ) {
	const logger = deps.logger || console;

	try {
		const options = parseArgs( argv, deps.env || process.env );
		if ( options.help ) {
			logger.log( HELP );
			return { exitCode: 0 };
		}

		const plan = createAuditPlan( options, {
			now: deps.now ? deps.now() : new Date(),
		} );
		validateRunConfig( plan, options, {
			env: deps.env || process.env,
		} );

		const auth = loadAuthInputs( options, deps.env || process.env );
		const fetchImpl = deps.fetch || globalThis.fetch;
		if ( typeof fetchImpl !== 'function' ) {
			throw new Error(
				'Global fetch is unavailable. Use Node 20 or newer.'
			);
		}

		fs.mkdirSync( plan.outputDir, { recursive: true } );

		let hadFailure = false;
		for ( const step of plan.steps ) {
			const result = await captureStep( step, {
				accountId: ( deps.env || process.env ).CLOUDFLARE_ACCOUNT_ID,
				apiToken: ( deps.env || process.env ).CLOUDFLARE_API_TOKEN,
				auth,
				fetchImpl,
				outputDir: plan.outputDir,
				timestamp: ( deps.now ? deps.now() : new Date() ).toISOString(),
				cacheTTL: options.cacheTTL,
			} );

			if ( result.ok ) {
				logger.log( `Wrote ${ result.imagePath }` );
			} else {
				hadFailure = true;
				logger.error( result.error );
			}
		}

		return { exitCode: hadFailure ? 1 : 0, outputDir: plan.outputDir };
	} catch ( error ) {
		logger.error( error.message );
		return { exitCode: 2, error };
	}
}

async function captureStep( step, context ) {
	const endpoint = new URL(
		SCREENSHOT_ENDPOINT.replace(
			'{accountId}',
			encodeURIComponent( context.accountId )
		)
	);
	if ( context.cacheTTL !== null && context.cacheTTL !== undefined ) {
		endpoint.searchParams.set( 'cacheTTL', String( context.cacheTTL ) );
	}

	const outputFile = `${ sanitizeFilePart( step.id ) }.png`;
	const metadataFile = `${ sanitizeFilePart( step.id ) }.json`;
	const metadataPath = path.join( context.outputDir, metadataFile );
	const request = buildScreenshotRequest( step, context.auth );
	const response = await context.fetchImpl( endpoint.toString(), {
		method: 'POST',
		headers: {
			Authorization: `Bearer ${ context.apiToken }`,
			'Content-Type': 'application/json',
		},
		body: JSON.stringify( request ),
	} );

	if ( ! response.ok ) {
		const metadata = redactMetadata( {
			accountId: context.accountId,
			httpStatus: response.status,
			responseHeaders: response.headers,
			step,
			outputFile,
			timestamp: context.timestamp,
			error: `Cloudflare Browser Run request failed with HTTP ${ response.status }`,
		} );
		await discardResponseBody( response );
		fs.writeFileSync(
			metadataPath,
			`${ JSON.stringify( metadata, null, 2 ) }\n`
		);
		return {
			ok: false,
			error: metadata.error,
			metadataPath,
		};
	}

	const buffer = Buffer.from( await response.arrayBuffer() );
	if ( buffer.length === 0 ) {
		const metadata = redactMetadata( {
			accountId: context.accountId,
			httpStatus: response.status,
			responseHeaders: response.headers,
			step,
			outputFile,
			timestamp: context.timestamp,
			error: 'Cloudflare Browser Run returned an empty response body',
		} );
		fs.writeFileSync(
			metadataPath,
			`${ JSON.stringify( metadata, null, 2 ) }\n`
		);
		return {
			ok: false,
			error: metadata.error,
			metadataPath,
		};
	}

	const imagePath = path.join( context.outputDir, outputFile );
	fs.writeFileSync( imagePath, buffer );
	fs.writeFileSync(
		metadataPath,
		`${ JSON.stringify(
			redactMetadata( {
				accountId: context.accountId,
				httpStatus: response.status,
				responseHeaders: response.headers,
				step,
				outputFile,
				timestamp: context.timestamp,
			} ),
			null,
			2
		) }\n`
	);

	return {
		ok: true,
		imagePath,
		metadataPath,
	};
}

async function discardResponseBody( response ) {
	if ( typeof response.text === 'function' ) {
		try {
			await response.text();
		} catch {
			// The body is intentionally ignored; metadata must not persist secrets.
		}
	}
}

function pruneUndefined( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( pruneUndefined );
	}

	if ( value && typeof value === 'object' ) {
		const out = {};
		for ( const [ key, item ] of Object.entries( value ) ) {
			if ( item === undefined ) {
				continue;
			}
			out[ key ] = pruneUndefined( item );
		}
		return out;
	}

	return value;
}

function sanitizeFilePart( value ) {
	const sanitized = String( value || 'run' )
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9._-]+/g, '-' )
		.replace( /^-+|-+$/g, '' );

	return sanitized || 'run';
}

function formatTimestampForPath( date ) {
	return date.toISOString().replace( /[:.]/g, '-' );
}

if ( require.main === module ) {
	runCli().then( ( result ) => {
		process.exitCode = result.exitCode;
	} );
}

module.exports = {
	DEFAULT_BASE_URL,
	DEFAULT_BASE_URL_ENV,
	DEFAULT_GOTO_OPTIONS,
	DEFAULT_OUTPUT_ROOT,
	DEFAULT_VIEWPORT,
	PRESETS,
	SCREENSHOT_ENDPOINT,
	buildScreenshotRequest,
	createAuditPlan,
	loadAuthInputs,
	parseArgs,
	parseViewport,
	redactMetadata,
	runCli,
	validateRunConfig,
};
