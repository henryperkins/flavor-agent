'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const {
	buildScreenshotRequest,
	createAuditPlan,
	loadAuthInputs,
	parseArgs,
	redactMetadata,
	runCli,
	validateRunConfig,
} = require( '../browser-run-screenshot.js' );

const AUDIT_BASE_URL = 'https://audit.example';

describe( 'browser-run-screenshot helpers', () => {
	test( 'requires a base URL for relative preset URLs without an environment default', () => {
		const options = parseArgs(
			[
				'node',
				'scripts/browser-run-screenshot.js',
				'--preset=settings',
			],
			{}
		);

		expect( () =>
			createAuditPlan( options, {
				now: new Date( '2026-05-06T12:34:56.000Z' ),
			} )
		).toThrow( 'Provide --base-url or set BROWSER_RUN_DEFAULT_BASE_URL' );
	} );

	test( 'resolves the settings preset against the environment default audit host', () => {
		const options = parseArgs(
			[
				'node',
				'scripts/browser-run-screenshot.js',
				'--preset=settings',
			],
			{
				BROWSER_RUN_DEFAULT_BASE_URL: AUDIT_BASE_URL,
			}
		);
		const plan = createAuditPlan( options, {
			now: new Date( '2026-05-06T12:34:56.000Z' ),
		} );

		expect( plan ).toMatchObject( {
			name: 'settings',
			outputDir: path.join(
				'output',
				'browser-run',
				'2026-05-06T12-34-56-000Z-settings'
			),
			steps: [
				{
					id: 'settings',
					preset: 'settings',
					url: `${ AUDIT_BASE_URL }/wp-admin/options-general.php?page=flavor-agent`,
					viewport: {
						width: 1440,
						height: 1200,
						deviceScaleFactor: 1,
					},
					fullPage: true,
					requiresAuth: true,
				},
			],
		} );
	} );

	test( 'uses explicit preset URL and viewport overrides', () => {
		const options = parseArgs( [
			'node',
			'scripts/browser-run-screenshot.js',
			'--preset=block-editor',
			'--base-url=https://staging.example',
			'--url=/wp-admin/post.php?post=123&action=edit',
			'--viewport=390x844@2',
			'--selector=.edit-post-layout',
			'--no-full-page',
		] );
		const plan = createAuditPlan( options, {
			now: new Date( '2026-05-06T12:34:56.000Z' ),
		} );

		expect( plan.steps[ 0 ] ).toMatchObject( {
			id: 'block-editor',
			url: 'https://staging.example/wp-admin/post.php?post=123&action=edit',
			viewport: {
				width: 390,
				height: 844,
				deviceScaleFactor: 2,
			},
			selector: '.edit-post-layout',
			fullPage: false,
			requiresAuth: true,
		} );
	} );

	test( 'loads workflow manifests as URL-only capture steps', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'browser-run-manifest-' )
		);
		const manifestPath = path.join( tempRoot, 'admin-ui-flow.json' );

		try {
			fs.writeFileSync(
				manifestPath,
				JSON.stringify( {
					name: 'settings-audit',
					baseUrl: 'https://manifest.example',
					defaults: {
						viewport: {
							width: 1024,
							height: 768,
							deviceScaleFactor: 1,
						},
						fullPage: false,
					},
					steps: [
						{
							id: 'settings',
							url: '/wp-admin/options-general.php?page=flavor-agent',
						},
						{
							id: 'site-editor',
							url: '/wp-admin/site-editor.php',
							fullPage: true,
						},
					],
				} )
			);

			const options = parseArgs( [
				'node',
				'scripts/browser-run-screenshot.js',
				`--manifest=${ manifestPath }`,
				'--base-url=https://cli.example',
			] );
			const plan = createAuditPlan( options, {
				now: new Date( '2026-05-06T12:34:56.000Z' ),
			} );

			expect( plan.name ).toBe( 'settings-audit' );
			expect( plan.steps ).toEqual( [
				expect.objectContaining( {
					id: 'settings',
					url: 'https://cli.example/wp-admin/options-general.php?page=flavor-agent',
					fullPage: false,
					requiresAuth: true,
				} ),
				expect.objectContaining( {
					id: 'site-editor',
					url: 'https://cli.example/wp-admin/site-editor.php',
					fullPage: true,
					requiresAuth: true,
				} ),
			] );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'uses the manifest base URL when the CLI does not override it', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'browser-run-manifest-base-' )
		);
		const manifestPath = path.join( tempRoot, 'admin-ui-flow.json' );

		try {
			fs.writeFileSync(
				manifestPath,
				JSON.stringify( {
					name: 'settings-audit',
					baseUrl: 'https://manifest.example',
					steps: [
						{
							id: 'settings',
							url: '/wp-admin/options-general.php?page=flavor-agent',
						},
					],
				} )
			);

			const options = parseArgs( [
				'node',
				'scripts/browser-run-screenshot.js',
				`--manifest=${ manifestPath }`,
			] );
			const plan = createAuditPlan( options, {
				now: new Date( '2026-05-06T12:34:56.000Z' ),
			} );

			expect( plan.steps[ 0 ].url ).toBe(
				'https://manifest.example/wp-admin/options-general.php?page=flavor-agent'
			);
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'requires Cloudflare credentials and explicit admin authentication', () => {
		const options = parseArgs(
			[
				'node',
				'scripts/browser-run-screenshot.js',
				'--preset=settings',
			],
			{
				BROWSER_RUN_DEFAULT_BASE_URL: AUDIT_BASE_URL,
			}
		);
		const plan = createAuditPlan( options );

		expect( () =>
			validateRunConfig( plan, options, {
				env: {},
			} )
		).toThrow( 'CLOUDFLARE_ACCOUNT_ID' );

		expect( () =>
			validateRunConfig( plan, options, {
				env: {
					CLOUDFLARE_ACCOUNT_ID: 'account-id',
					CLOUDFLARE_API_TOKEN: 'token',
				},
			} )
		).toThrow( 'requires WordPress authentication' );
	} );

	test( 'loads cookies and extra headers only from explicit inputs', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'browser-run-auth-' )
		);
		const cookiesPath = path.join( tempRoot, 'cookies.json' );
		const headersPath = path.join( tempRoot, 'headers.json' );

		try {
			fs.writeFileSync(
				cookiesPath,
				JSON.stringify( [
					{
						name: 'wordpress_logged_in_example',
						value: 'secret',
						domain: 'audit.example',
						path: '/',
					},
				] )
			);
			fs.writeFileSync(
				headersPath,
				JSON.stringify( {
					'X-Audit-Session': 'manual-review',
				} )
			);

			const options = parseArgs( [
				'node',
				'scripts/browser-run-screenshot.js',
				'--preset=settings',
				`--cookies-file=${ cookiesPath }`,
				`--extra-headers-file=${ headersPath }`,
			] );

			expect( loadAuthInputs( options, {} ) ).toEqual( {
				cookies: [
					{
						name: 'wordpress_logged_in_example',
						value: 'secret',
						domain: 'audit.example',
						path: '/',
					},
				],
				extraHeaders: {
					'X-Audit-Session': 'manual-review',
				},
			} );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'builds the Cloudflare screenshot request body with optional fields', () => {
		const request = buildScreenshotRequest(
			{
				url: `${ AUDIT_BASE_URL }/wp-admin/options-general.php?page=flavor-agent`,
				viewport: {
					width: 1440,
					height: 1200,
					deviceScaleFactor: 1,
				},
				fullPage: true,
				selector: '#wpbody-content',
				userAgent: 'Flavor Agent Screenshot Audit',
				addStyleTag: [
					{ content: 'body { caret-color: transparent; }' },
				],
				addScriptTag: [ { content: 'window.__audit = true;' } ],
			},
			{
				cookies: [
					{
						name: 'wordpress_logged_in_example',
						value: 'secret',
					},
				],
				extraHeaders: {
					'X-Audit-Session': 'manual-review',
				},
			}
		);

		expect( request ).toEqual( {
			url: `${ AUDIT_BASE_URL }/wp-admin/options-general.php?page=flavor-agent`,
			viewport: {
				width: 1440,
				height: 1200,
				deviceScaleFactor: 1,
			},
			screenshotOptions: {
				fullPage: true,
			},
			gotoOptions: {
				waitUntil: 'networkidle0',
				timeout: 45000,
			},
			cookies: [
				{
					name: 'wordpress_logged_in_example',
					value: 'secret',
				},
			],
			setExtraHTTPHeaders: {
				'X-Audit-Session': 'manual-review',
			},
			selector: '#wpbody-content',
			userAgent: 'Flavor Agent Screenshot Audit',
			addStyleTag: [ { content: 'body { caret-color: transparent; }' } ],
			addScriptTag: [ { content: 'window.__audit = true;' } ],
		} );
	} );

	test( 'redacts persisted metadata and keeps only safe response headers', () => {
		const metadata = redactMetadata( {
			accountId: '1234567890abcdef',
			httpStatus: 500,
			responseHeaders: {
				'content-type': 'application/json',
				'cf-ray': 'abc',
				'content-length': '42',
				'set-cookie': 'wordpress_logged_in=secret',
				authorization: 'Bearer secret',
			},
			step: {
				id: 'settings',
				preset: 'settings',
				url: `${ AUDIT_BASE_URL }/wp-admin/options-general.php?page=flavor-agent`,
				viewport: {
					width: 1440,
					height: 1200,
					deviceScaleFactor: 1,
				},
				fullPage: true,
				selector: '#wpbody-content',
			},
			outputFile: 'settings.png',
			timestamp: '2026-05-06T12:34:56.000Z',
		} );

		expect( metadata ).toEqual( {
			stepId: 'settings',
			preset: 'settings',
			finalUrl: `${ AUDIT_BASE_URL }/wp-admin/options-general.php?page=flavor-agent`,
			timestamp: '2026-05-06T12:34:56.000Z',
			viewport: {
				width: 1440,
				height: 1200,
				deviceScaleFactor: 1,
			},
			selector: '#wpbody-content',
			fullPage: true,
			cloudflareAccountIdSuffix: 'cdef',
			httpStatus: 500,
			responseHeaders: {
				'content-type': 'application/json',
				'cf-ray': 'abc',
				'content-length': '42',
			},
			outputFile: 'settings.png',
		} );
	} );

	test( 'returns nonzero and writes failure metadata for Cloudflare errors', async () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'browser-run-output-' )
		);
		const cookiesPath = path.join( tempRoot, 'cookies.json' );

		try {
			fs.writeFileSync(
				cookiesPath,
				JSON.stringify( [
					{
						name: 'wordpress_logged_in_example',
						value: 'secret',
					},
				] )
			);

			const result = await runCli(
				[
					'node',
					'scripts/browser-run-screenshot.js',
					'--preset=settings',
					`--cookies-file=${ cookiesPath }`,
					`--output=${ tempRoot }`,
				],
				{
					env: {
						BROWSER_RUN_DEFAULT_BASE_URL: AUDIT_BASE_URL,
						CLOUDFLARE_ACCOUNT_ID: '1234567890abcdef',
						CLOUDFLARE_API_TOKEN: 'token',
					},
					fetch: jest.fn().mockResolvedValue( {
						ok: false,
						status: 500,
						headers: new Map( [
							[ 'content-type', 'application/json' ],
							[ 'set-cookie', 'secret' ],
						] ),
						text: jest
							.fn()
							.mockResolvedValue(
								'{"success":false,"errors":[{"message":"Nope"}]}'
							),
					} ),
					now: () => new Date( '2026-05-06T12:34:56.000Z' ),
					logger: {
						log: jest.fn(),
						error: jest.fn(),
					},
				}
			);
			const metadataPath = path.join(
				tempRoot,
				'2026-05-06T12-34-56-000Z-settings',
				'settings.json'
			);
			const metadata = JSON.parse(
				fs.readFileSync( metadataPath, 'utf8' )
			);

			expect( result.exitCode ).toBe( 1 );
			expect( metadata ).toMatchObject( {
				stepId: 'settings',
				httpStatus: 500,
				error: 'Cloudflare Browser Run request failed with HTTP 500',
				responseHeaders: {
					'content-type': 'application/json',
				},
			} );
			expect( JSON.stringify( metadata ) ).not.toContain( 'token' );
			expect( JSON.stringify( metadata ) ).not.toContain( 'secret' );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );
} );
