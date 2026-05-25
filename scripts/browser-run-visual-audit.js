#!/usr/bin/env node
'use strict';

const childProcess = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const { runCli: runScreenshotCli } = require( './browser-run-screenshot.js' );

const DEFAULT_OUTPUT_ROOT = path.join( 'output', 'browser-run' );
const DEFAULT_SUITE = 'core';
const DEFAULT_AUTH_TTL_SECONDS = 900;
const DEFAULT_CDP_KEEP_ALIVE_MS = 600000;

const TARGETS = {
	'wp-hperkins': {
		baseUrl: 'https://wp.hperkins.com',
		wpPath: '/home/dev/wp-hperkins-com',
		adminUserId: 1,
	},
};

const SUITES = {
	core: {
		staticSteps: [
			{
				id: 'admin-dashboard',
				url: '/wp-admin/',
			},
			{
				id: 'flavor-agent-settings',
				url: '/wp-admin/options-general.php?page=flavor-agent',
			},
			{
				id: 'ai-activity',
				url: '/wp-admin/options-general.php?page=flavor-agent-activity',
			},
		],
		workflows: [
			{
				id: 'admin-to-settings',
				title: 'Dashboard to Flavor Agent settings',
				viewport: {
					width: 1440,
					height: 1200,
				},
				steps: [
					{
						goto: '/wp-admin/',
					},
					{
						screenshot: 'dashboard',
					},
					{
						goto: '/wp-admin/options-general.php?page=flavor-agent',
					},
					{
						waitForSelector: '#wpbody-content',
					},
					{
						screenshot: 'flavor-agent-settings',
					},
				],
			},
			{
				id: 'settings-to-activity',
				title: 'Settings to AI Activity audit screen',
				viewport: {
					width: 1440,
					height: 1200,
				},
				steps: [
					{
						goto: '/wp-admin/options-general.php?page=flavor-agent',
					},
					{
						screenshot: 'settings-overview',
					},
					{
						goto: '/wp-admin/options-general.php?page=flavor-agent-activity',
					},
					{
						waitForSelector: '#wpbody-content',
						optional: true,
					},
					{
						screenshot: 'ai-activity-overview',
					},
				],
			},
			{
				id: 'post-editor-entry',
				title: 'Post editor entry state',
				viewport: {
					width: 1440,
					height: 1200,
				},
				steps: [
					{
						goto: '/wp-admin/post-new.php?post_type=post',
						waitUntil: 'domcontentloaded',
					},
					{
						waitForSelector:
							'.edit-post-layout, .editor-styles-wrapper, body.block-editor-page',
						optional: true,
						timeout: 20000,
					},
					{
						screenshot: 'post-editor-entry',
					},
				],
			},
			{
				id: 'site-editor-entry',
				title: 'Site Editor entry state',
				viewport: {
					width: 1440,
					height: 1200,
				},
				steps: [
					{
						goto: '/wp-admin/site-editor.php',
						waitUntil: 'domcontentloaded',
					},
					{
						waitForSelector:
							'.interface-interface-skeleton, .edit-site-sidebar-navigation-screen__content, .edit-site-layout',
						optional: true,
						timeout: 30000,
					},
					{
						waitForTimeout: 2000,
					},
					{
						screenshot: 'site-editor-entry',
					},
				],
			},
		],
	},
};

const HELP = `Flavor Agent Browser Run visual audit

Usage:
  node scripts/browser-run-visual-audit.js --target=wp-hperkins [options]
  node scripts/browser-run-visual-audit.js --base-url=https://example.test --cookies-file=/tmp/wp-cookies.json [options]

Options:
  --target=<name>              Known target. Supported: ${ Object.keys(
		TARGETS
  ).join( ', ' ) }
  --base-url=<url>             Public WordPress target when no known target is used
  --wp-path=<path>             Local WordPress root used to mint short-lived auth cookies
  --admin-user-id=<id>         Admin user ID for auto-minted cookies (default: target value or 1)
  --suite=<name>               Visual audit suite (default: ${ DEFAULT_SUITE })
  --cookies-file=<path>        Reuse an explicit cookie JSON file instead of auto-minting
  --output=<path>              Output root (default: ${ DEFAULT_OUTPUT_ROOT })
  --run-name=<name>            Override output run name
  --skip-static                Do not run Quick Actions URL checkpoints
  --skip-workflows             Do not run scripted Browser Run CDP workflow captures
  --dry-run                    Print the resolved audit plan without contacting Cloudflare
  --help, -h                   Show this message

Environment:
  CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN are required unless --dry-run is used.
`;

function parseArgs( argv ) {
	const opts = {
		target: null,
		baseUrl: null,
		wpPath: null,
		adminUserId: null,
		suite: DEFAULT_SUITE,
		cookiesFile: null,
		outputRoot: DEFAULT_OUTPUT_ROOT,
		runName: null,
		skipStatic: false,
		skipWorkflows: false,
		dryRun: false,
		help: false,
	};

	const args = argv.slice( 2 );
	for ( let i = 0; i < args.length; i++ ) {
		const arg = args[ i ];

		if ( arg === '--help' || arg === '-h' ) {
			opts.help = true;
			continue;
		}
		if ( arg === '--skip-static' ) {
			opts.skipStatic = true;
			continue;
		}
		if ( arg === '--skip-workflows' ) {
			opts.skipWorkflows = true;
			continue;
		}
		if ( arg === '--dry-run' ) {
			opts.dryRun = true;
			continue;
		}

		const { key, value } = parseOption( arg, args, i );
		if ( value.consumedNext ) {
			i++;
		}

		switch ( key ) {
			case 'target':
				opts.target = value.value;
				break;
			case 'base-url':
				opts.baseUrl = value.value;
				break;
			case 'wp-path':
				opts.wpPath = value.value;
				break;
			case 'admin-user-id':
				opts.adminUserId = parsePositiveInteger(
					value.value,
					'--admin-user-id'
				);
				break;
			case 'suite':
				opts.suite = value.value;
				break;
			case 'cookies-file':
				opts.cookiesFile = value.value;
				break;
			case 'output':
				opts.outputRoot = value.value;
				break;
			case 'run-name':
				opts.runName = value.value;
				break;
			default:
				throw new Error( `Unknown argument: --${ key }` );
		}
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

function parsePositiveInteger( value, label ) {
	const parsed = Number.parseInt( value, 10 );

	if ( ! Number.isSafeInteger( parsed ) || parsed < 1 ) {
		throw new Error( `Invalid ${ label } value: ${ value }` );
	}

	return parsed;
}

function createVisualAuditPlan( options, deps = {} ) {
	const now = deps.now instanceof Date ? deps.now : new Date();
	const targetConfig = options.target ? TARGETS[ options.target ] : null;
	const suite = SUITES[ options.suite ];

	if ( options.target && ! targetConfig ) {
		throw new Error( `Unknown target: ${ options.target }` );
	}
	if ( ! suite ) {
		throw new Error( `Unknown suite: ${ options.suite }` );
	}

	const baseUrl = options.baseUrl || ( targetConfig && targetConfig.baseUrl );
	if ( ! baseUrl ) {
		throw new Error( 'Provide --target or --base-url' );
	}

	const normalizedBaseUrl = normalizeBaseUrl( baseUrl );
	const runName =
		options.runName ||
		`visual-${ options.suite }-${
			options.target || hostSlug( normalizedBaseUrl )
		}`;
	const outputDir = path.join(
		options.outputRoot,
		`${ formatTimestampForPath( now ) }-${ sanitizeFilePart( runName ) }`
	);

	return {
		target: options.target,
		baseUrl: normalizedBaseUrl.replace( /\/$/, '' ),
		wpPath: options.wpPath || ( targetConfig && targetConfig.wpPath ),
		adminUserId:
			options.adminUserId ||
			( targetConfig && targetConfig.adminUserId ) ||
			1,
		suite: options.suite,
		outputDir,
		staticSteps: options.skipStatic ? [] : suite.staticSteps,
		workflows: options.skipWorkflows ? [] : suite.workflows,
	};
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

function hostSlug( baseUrl ) {
	return new URL( baseUrl ).hostname.replace( /[^a-z0-9]+/gi, '-' );
}

function mintWordPressAuthCookies( {
	baseUrl,
	wpPath,
	adminUserId = 1,
	ttlSeconds = DEFAULT_AUTH_TTL_SECONDS,
	tempRoot = os.tmpdir(),
	execFileSync = childProcess.execFileSync,
	env = process.env,
} ) {
	if ( ! wpPath ) {
		throw new Error(
			'WordPress auth requires --cookies-file or a local --wp-path.'
		);
	}

	const tempDir = fs.mkdtempSync(
		path.join( tempRoot, 'browser-run-visual-auth-' )
	);
	const cookiesFile = path.join( tempDir, 'cookies.json' );
	const tokenPath = path.join( tempDir, 'session-token.txt' );
	const cookieDomain = new URL( baseUrl ).hostname;

	execFileSync( 'wp', [ `--path=${ wpPath }`, 'eval', buildMintAuthPhp() ], {
		env: {
			...env,
			BROWSER_RUN_ADMIN_USER_ID: String( adminUserId ),
			BROWSER_RUN_AUTH_TTL_SECONDS: String( ttlSeconds ),
			BROWSER_RUN_COOKIE_DOMAIN: cookieDomain,
			BROWSER_RUN_COOKIE_PATH: cookiesFile,
			BROWSER_RUN_TOKEN_PATH: tokenPath,
		},
	} );

	if ( ! fs.existsSync( cookiesFile ) ) {
		throw new Error(
			'WordPress auth cookie minting did not write cookies.'
		);
	}

	return {
		cookiesFile,
		tempDir,
		cleanup: () => {
			cleanupWordPressAuthSession( {
				wpPath,
				adminUserId,
				cookiesFile,
				tokenPath,
				tempDir,
				execFileSync,
				env,
			} );
		},
	};
}

function cleanupWordPressAuthSession( {
	wpPath,
	adminUserId,
	cookiesFile,
	tokenPath,
	tempDir,
	execFileSync = childProcess.execFileSync,
	env = process.env,
} ) {
	let cleanupError = null;

	try {
		const token = fs.existsSync( tokenPath )
			? fs.readFileSync( tokenPath, 'utf8' ).trim()
			: '';
		if ( token ) {
			execFileSync(
				'wp',
				[ `--path=${ wpPath }`, 'eval', buildDestroyAuthPhp() ],
				{
					env: {
						...env,
						BROWSER_RUN_ADMIN_USER_ID: String( adminUserId ),
						BROWSER_RUN_SESSION_TOKEN: token,
					},
				}
			);
		}
	} catch ( error ) {
		cleanupError = error;
	} finally {
		if ( cleanupError ) {
			if ( cookiesFile ) {
				fs.rmSync( cookiesFile, { force: true } );
			}

			const wrappedError = new Error(
				`WordPress auth cleanup failed: ${ cleanupError.message }. Temporary session token left at ${ tokenPath }; it expires automatically, but can be destroyed manually with WP-CLI if needed.`
			);
			wrappedError.cause = cleanupError;
			wrappedError.tempDir = tempDir;
			wrappedError.tokenPath = tokenPath;
			throw wrappedError;
		}

		fs.rmSync( tempDir, { recursive: true, force: true } );
	}
}

function buildMintAuthPhp() {
	return `
$user_id = (int) getenv( 'BROWSER_RUN_ADMIN_USER_ID' );
$ttl = (int) getenv( 'BROWSER_RUN_AUTH_TTL_SECONDS' );
$expiration = time() + max( 60, $ttl );
$token = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
$domain = (string) getenv( 'BROWSER_RUN_COOKIE_DOMAIN' );
$secure_auth = wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token );
$logged_in = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
$cookies = array(
	array(
		'name' => SECURE_AUTH_COOKIE,
		'value' => $secure_auth,
		'domain' => $domain,
		'path' => ADMIN_COOKIE_PATH,
		'secure' => true,
		'httpOnly' => true,
	),
	array(
		'name' => SECURE_AUTH_COOKIE,
		'value' => $secure_auth,
		'domain' => $domain,
		'path' => PLUGINS_COOKIE_PATH,
		'secure' => true,
		'httpOnly' => true,
	),
	array(
		'name' => LOGGED_IN_COOKIE,
		'value' => $logged_in,
		'domain' => $domain,
		'path' => COOKIEPATH ? COOKIEPATH : '/',
		'secure' => true,
		'httpOnly' => true,
	),
);
if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH && SITECOOKIEPATH !== COOKIEPATH ) {
	$cookies[] = array(
		'name' => LOGGED_IN_COOKIE,
		'value' => $logged_in,
		'domain' => $domain,
		'path' => SITECOOKIEPATH,
		'secure' => true,
		'httpOnly' => true,
	);
}
file_put_contents( getenv( 'BROWSER_RUN_COOKIE_PATH' ), wp_json_encode( $cookies, JSON_PRETTY_PRINT ) );
file_put_contents( getenv( 'BROWSER_RUN_TOKEN_PATH' ), $token );
`;
}

function buildDestroyAuthPhp() {
	return `
$user_id = (int) getenv( 'BROWSER_RUN_ADMIN_USER_ID' );
$token = (string) getenv( 'BROWSER_RUN_SESSION_TOKEN' );
if ( $user_id > 0 && '' !== $token ) {
	WP_Session_Tokens::get_instance( $user_id )->destroy( $token );
}
`;
}

async function validateWordPressAuth( {
	baseUrl,
	cookiesFile,
	fetchImpl = fetch,
} ) {
	const cookies = readJsonFile( cookiesFile, 'cookies' );
	const cookieHeader = cookies
		.map( ( cookie ) => `${ cookie.name }=${ cookie.value }` )
		.join( '; ' );
	const response = await fetchImpl( resolveUrl( '/wp-admin/', baseUrl ), {
		headers: {
			Cookie: cookieHeader,
		},
		redirect: 'manual',
	} );
	const location = response.headers.get( 'location' ) || '';

	if (
		response.status >= 300 &&
		response.status < 400 &&
		location.includes( 'wp-login.php' )
	) {
		throw new Error(
			'WordPress auth validation redirected to wp-login.php.'
		);
	}

	if ( response.status < 200 || response.status >= 300 ) {
		throw new Error(
			`WordPress auth validation failed with HTTP ${ response.status }.`
		);
	}

	return true;
}

async function runStaticCaptures( plan, { cookiesFile, env = process.env } ) {
	if ( plan.staticSteps.length === 0 ) {
		return {
			skipped: true,
			reason: 'No static steps in plan.',
		};
	}

	const manifestDir = fs.mkdtempSync(
		path.join( os.tmpdir(), 'browser-run-visual-static-' )
	);
	const manifestPath = path.join( manifestDir, 'manifest.json' );
	fs.writeFileSync(
		manifestPath,
		`${ JSON.stringify(
			{
				name: `${ plan.suite }-static`,
				baseUrl: plan.baseUrl,
				steps: plan.staticSteps.map( ( step ) => ( {
					id: step.id,
					url: step.url,
				} ) ),
			},
			null,
			2
		) }\n`
	);

	try {
		const argv = [
			'node',
			'scripts/browser-run-screenshot.js',
			`--manifest=${ manifestPath }`,
			`--output=${ plan.outputDir }`,
			`--run-name=${ plan.suite }-static`,
		];

		if ( cookiesFile ) {
			argv.push( `--cookies-file=${ cookiesFile }` );
		}

		return await runScreenshotCli( argv, { env } );
	} finally {
		fs.rmSync( manifestDir, { recursive: true, force: true } );
	}
}

async function runWorkflowCaptures( plan, deps = {} ) {
	if ( plan.workflows.length === 0 ) {
		return {
			ok: true,
			results: [],
		};
	}

	const accountId = deps.accountId || process.env.CLOUDFLARE_ACCOUNT_ID;
	const apiToken = deps.apiToken || process.env.CLOUDFLARE_API_TOKEN;
	if ( ! accountId ) {
		throw new Error(
			'CLOUDFLARE_ACCOUNT_ID is required for workflow captures.'
		);
	}
	if ( ! apiToken ) {
		throw new Error(
			'CLOUDFLARE_API_TOKEN is required for workflow captures.'
		);
	}

	const playwright = deps.playwright || require( 'playwright-core' );
	const endpoint = `wss://api.cloudflare.com/client/v4/accounts/${ encodeURIComponent(
		accountId
	) }/browser-rendering/devtools/browser?keep_alive=${ DEFAULT_CDP_KEEP_ALIVE_MS }`;
	const browser = await playwright.chromium.connectOverCDP( endpoint, {
		headers: {
			Authorization: `Bearer ${ apiToken }`,
		},
	} );
	const context = browser.contexts()[ 0 ];
	if ( ! context ) {
		throw new Error( 'Browser Run CDP did not provide a browser context.' );
	}

	if ( deps.cookiesFile ) {
		await context.addCookies( readJsonFile( deps.cookiesFile, 'cookies' ) );
	}

	const results = [];
	try {
		for ( const workflow of plan.workflows ) {
			results.push(
				await runSingleWorkflow( {
					baseUrl: plan.baseUrl,
					outputDir: plan.outputDir,
					workflow,
					context,
				} )
			);
		}
	} finally {
		await browser.close();
	}

	return {
		ok: results.every( ( result ) => result.status === 'pass' ),
		results,
	};
}

async function runSingleWorkflow( { baseUrl, outputDir, workflow, context } ) {
	const workflowDir = path.join(
		outputDir,
		'workflows',
		sanitizeFilePart( workflow.id )
	);
	fs.mkdirSync( workflowDir, { recursive: true } );

	const page = context.pages()[ 0 ] || ( await context.newPage() );
	if ( workflow.viewport ) {
		await page.setViewportSize( {
			width: workflow.viewport.width,
			height: workflow.viewport.height,
		} );
	}

	const screenshots = [];
	try {
		for ( const step of workflow.steps ) {
			const screenshot = await runWorkflowStep( {
				baseUrl,
				workflowDir,
				page,
				step,
			} );
			if ( screenshot ) {
				screenshots.push( screenshot );
			}
		}
		return {
			id: workflow.id,
			title: workflow.title,
			status: 'pass',
			screenshots,
		};
	} catch ( error ) {
		return {
			id: workflow.id,
			title: workflow.title,
			status: 'fail',
			error: error.message,
			screenshots,
		};
	}
}

async function runWorkflowStep( { baseUrl, workflowDir, page, step } ) {
	if ( step.goto ) {
		await page.goto( resolveUrl( step.goto, baseUrl ), {
			waitUntil: step.waitUntil || 'domcontentloaded',
			timeout: step.timeout || 45000,
		} );
		return null;
	}

	if ( step.click ) {
		await page.locator( step.click ).click( {
			timeout: step.timeout || 10000,
		} );
		return null;
	}

	if ( step.fill ) {
		await page.locator( step.fill ).fill( step.value || '', {
			timeout: step.timeout || 10000,
		} );
		return null;
	}

	if ( step.waitForSelector ) {
		try {
			await page.waitForSelector( step.waitForSelector, {
				state: step.state || 'visible',
				timeout: step.timeout || 10000,
			} );
		} catch ( error ) {
			if ( ! step.optional ) {
				throw error;
			}
		}
		return null;
	}

	if ( step.waitForTimeout ) {
		await page.waitForTimeout( step.waitForTimeout );
		return null;
	}

	if ( step.screenshot ) {
		const fileName = `${ sanitizeFilePart( step.screenshot ) }.png`;
		const screenshotPath = path.join( workflowDir, fileName );
		await page.screenshot( {
			path: screenshotPath,
			fullPage: step.fullPage !== false,
		} );
		return screenshotPath;
	}

	throw new Error( `Unsupported workflow step: ${ JSON.stringify( step ) }` );
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

function resolveUrl( url, baseUrl ) {
	return new URL( url, `${ baseUrl.replace( /\/$/, '' ) }/` ).toString();
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

async function runCli( argv = process.argv, deps = {} ) {
	const logger = deps.logger || console;
	let auth = null;
	let result = null;

	try {
		const options = parseArgs( argv );
		if ( options.help ) {
			logger.log( HELP );
			result = { exitCode: 0 };
			return result;
		}

		const plan = createVisualAuditPlan( options, {
			now: deps.now ? deps.now() : new Date(),
		} );

		if ( options.dryRun ) {
			logger.log( JSON.stringify( plan, null, 2 ) );
			result = {
				exitCode: 0,
				plan,
			};
			return result;
		}

		const env = deps.env || process.env;
		const cookiesFile =
			options.cookiesFile ||
			( auth = mintWordPressAuthCookies( {
				baseUrl: plan.baseUrl,
				wpPath: plan.wpPath,
				adminUserId: plan.adminUserId,
				tempRoot: deps.tempRoot,
				execFileSync: deps.execFileSync,
				env,
			} ) ).cookiesFile;

		await validateWordPressAuth( {
			baseUrl: plan.baseUrl,
			cookiesFile,
			fetchImpl: deps.fetch || globalThis.fetch,
		} );

		fs.mkdirSync( plan.outputDir, { recursive: true } );
		const staticResult = await runStaticCaptures( plan, {
			cookiesFile,
			env,
		} );
		const workflowResult = await runWorkflowCaptures( plan, {
			accountId: env.CLOUDFLARE_ACCOUNT_ID,
			apiToken: env.CLOUDFLARE_API_TOKEN,
			cookiesFile,
			playwright: deps.playwright,
		} );
		const summary = {
			target: plan.target,
			baseUrl: plan.baseUrl,
			suite: plan.suite,
			outputDir: plan.outputDir,
			auth: {
				source: options.cookiesFile
					? 'cookies-file'
					: 'wp-cli-short-lived',
			},
			static: staticResult,
			workflows: workflowResult,
			note: 'Browser Run screenshots are supporting visual evidence and do not replace Playwright assertions.',
		};
		fs.writeFileSync(
			path.join( plan.outputDir, 'summary.json' ),
			`${ JSON.stringify( summary, null, 2 ) }\n`
		);

		logger.log( `Wrote ${ path.join( plan.outputDir, 'summary.json' ) }` );

		result = {
			exitCode: isAuditSuccessful( staticResult, workflowResult ) ? 0 : 1,
			plan,
			summary,
		};
		return result;
	} catch ( error ) {
		logger.error( error.message );
		result = {
			exitCode: 2,
			error,
		};
		return result;
	} finally {
		if ( auth ) {
			try {
				auth.cleanup();
			} catch ( error ) {
				recordCleanupWarning( result, error, logger );
			}
		}
	}
}

function recordCleanupWarning( result, error, logger ) {
	const warning = error.message || String( error );
	logger.error( `Warning: ${ warning }` );

	if ( ! result ) {
		return;
	}

	result.cleanupWarning = warning;

	if ( ! result.summary || ! result.summary.outputDir ) {
		return;
	}

	result.summary.auth = {
		...( result.summary.auth || {} ),
		cleanupWarning: warning,
	};

	try {
		fs.writeFileSync(
			path.join( result.summary.outputDir, 'summary.json' ),
			`${ JSON.stringify( result.summary, null, 2 ) }\n`
		);
	} catch ( writeError ) {
		logger.error(
			`Warning: Could not persist WordPress auth cleanup warning: ${ writeError.message }`
		);
	}
}

function isAuditSuccessful( staticResult, workflowResult ) {
	const staticOk =
		staticResult && ( staticResult.skipped || staticResult.exitCode === 0 );
	return Boolean( staticOk && workflowResult && workflowResult.ok );
}

if ( require.main === module ) {
	runCli().then( ( result ) => {
		process.exitCode = result.exitCode;
	} );
}

module.exports = {
	DEFAULT_AUTH_TTL_SECONDS,
	DEFAULT_OUTPUT_ROOT,
	SUITES,
	TARGETS,
	createVisualAuditPlan,
	isAuditSuccessful,
	mintWordPressAuthCookies,
	parseArgs,
	runCli,
	runWorkflowCaptures,
	validateWordPressAuth,
};
