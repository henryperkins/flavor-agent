'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const {
	createVisualAuditPlan,
	isAuditSuccessful,
	mintWordPressAuthCookies,
	parseArgs,
	runCli,
	runWorkflowCaptures,
} = require( '../browser-run-visual-audit.js' );

describe( 'browser-run-visual-audit helpers', () => {
	test( 'requires an explicit target or base URL', () => {
		expect( () =>
			createVisualAuditPlan(
				parseArgs( [ 'node', 'scripts/browser-run-visual-audit.js' ] ),
				{ now: new Date( '2026-05-25T12:00:00.000Z' ) }
			)
		).toThrow( 'Provide --target or --base-url' );
	} );

	test( 'builds the core visual audit plan for the wp-hperkins target', () => {
		const plan = createVisualAuditPlan(
			parseArgs( [
				'node',
				'scripts/browser-run-visual-audit.js',
				'--target=wp-hperkins',
			] ),
			{ now: new Date( '2026-05-25T12:00:00.000Z' ) }
		);

		expect( plan ).toMatchObject( {
			target: 'wp-hperkins',
			baseUrl: 'https://wp.hperkins.com',
			wpPath: '/home/dev/wp-hperkins-com',
			suite: 'core',
			outputDir: path.join(
				'output',
				'browser-run',
				'2026-05-25T12-00-00-000Z-visual-core-wp-hperkins'
			),
		} );
		expect( plan.staticSteps.map( ( step ) => step.id ) ).toEqual( [
			'admin-dashboard',
			'flavor-agent-settings',
			'ai-activity',
		] );
		expect( plan.workflows.map( ( workflow ) => workflow.id ) ).toEqual( [
			'admin-to-settings',
			'settings-to-activity',
			'post-editor-entry',
			'site-editor-entry',
		] );
	} );

	test( 'mints short-lived WordPress cookies and registers cleanup', () => {
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'visual-audit-auth-test-' )
		);
		const execFileSync = jest.fn( ( command, args, options ) => {
			const cookiePath = options.env.BROWSER_RUN_COOKIE_PATH;
			const tokenPath = options.env.BROWSER_RUN_TOKEN_PATH;

			if (
				args.includes( 'eval' ) &&
				cookiePath &&
				! fs.existsSync( cookiePath )
			) {
				fs.writeFileSync(
					cookiePath,
					JSON.stringify( [
						{
							name: 'wordpress_sec_example',
							value: 'secret-cookie',
							domain: 'wp.hperkins.com',
							path: '/wp-admin',
							secure: true,
							httpOnly: true,
						},
					] )
				);
				fs.writeFileSync( tokenPath, 'session-token' );
			}

			return Buffer.from( '' );
		} );

		try {
			const auth = mintWordPressAuthCookies( {
				baseUrl: 'https://wp.hperkins.com',
				wpPath: '/home/dev/wp-hperkins-com',
				tempRoot,
				execFileSync,
			} );

			expect( fs.existsSync( auth.cookiesFile ) ).toBe( true );
			expect(
				JSON.parse( fs.readFileSync( auth.cookiesFile, 'utf8' ) )
			).toHaveLength( 1 );

			auth.cleanup();

			expect( execFileSync ).toHaveBeenCalledWith(
				'wp',
				expect.arrayContaining( [
					'--path=/home/dev/wp-hperkins-com',
					'eval',
				] ),
				expect.objectContaining( {
					env: expect.objectContaining( {
						BROWSER_RUN_SESSION_TOKEN: 'session-token',
					} ),
				} )
			);
			expect( fs.existsSync( auth.tempDir ) ).toBe( false );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'runs scripted workflow captures through a Playwright-compatible browser', async () => {
		const calls = [];
		const fakePage = {
			goto: jest.fn( async ( url, options ) =>
				calls.push( [ 'goto', url, options.waitUntil ] )
			),
			locator: jest.fn( ( selector ) => ( {
				click: jest.fn( async () =>
					calls.push( [ 'click', selector ] )
				),
				fill: jest.fn( async ( value ) =>
					calls.push( [ 'fill', selector, value ] )
				),
			} ) ),
			waitForSelector: jest.fn( async ( selector ) =>
				calls.push( [ 'waitForSelector', selector ] )
			),
			waitForTimeout: jest.fn( async ( timeout ) =>
				calls.push( [ 'waitForTimeout', timeout ] )
			),
			screenshot: jest.fn( async ( options ) =>
				calls.push( [ 'screenshot', path.basename( options.path ) ] )
			),
			setViewportSize: jest.fn(),
		};
		const fakeContext = {
			addCookies: jest.fn(),
			pages: jest.fn( () => [ fakePage ] ),
		};
		const fakeBrowser = {
			contexts: jest.fn( () => [ fakeContext ] ),
			close: jest.fn(),
		};
		const playwright = {
			chromium: {
				connectOverCDP: jest.fn( async () => fakeBrowser ),
			},
		};
		const tempRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'visual-audit-workflow-test-' )
		);
		const cookiesFile = path.join( tempRoot, 'cookies.json' );
		fs.writeFileSync(
			cookiesFile,
			JSON.stringify( [
				{
					name: 'wordpress_sec_example',
					value: 'secret-cookie',
					domain: 'wp.hperkins.com',
					path: '/wp-admin',
				},
			] )
		);

		try {
			const result = await runWorkflowCaptures(
				{
					baseUrl: 'https://wp.hperkins.com',
					outputDir: tempRoot,
					workflows: [
						{
							id: 'settings-flow',
							viewport: { width: 1440, height: 1200 },
							steps: [
								{ goto: '/wp-admin/' },
								{ click: '#menu-settings a' },
								{
									fill: '#sample',
									value: 'example value',
								},
								{ waitForSelector: '#wpbody-content' },
								{ waitForTimeout: 2000 },
								{ screenshot: 'settings-open' },
							],
						},
					],
				},
				{
					accountId: 'account-id',
					apiToken: 'token',
					cookiesFile,
					playwright,
				}
			);

			expect( playwright.chromium.connectOverCDP ).toHaveBeenCalledWith(
				'wss://api.cloudflare.com/client/v4/accounts/account-id/browser-rendering/devtools/browser?keep_alive=600000',
				expect.objectContaining( {
					headers: {
						Authorization: 'Bearer token',
					},
				} )
			);
			expect( fakeContext.addCookies ).toHaveBeenCalledWith(
				expect.arrayContaining( [
					expect.objectContaining( {
						name: 'wordpress_sec_example',
					} ),
				] )
			);
			expect( calls ).toEqual( [
				[
					'goto',
					'https://wp.hperkins.com/wp-admin/',
					'domcontentloaded',
				],
				[ 'click', '#menu-settings a' ],
				[ 'fill', '#sample', 'example value' ],
				[ 'waitForSelector', '#wpbody-content' ],
				[ 'waitForTimeout', 2000 ],
				[ 'screenshot', 'settings-open.png' ],
			] );
			expect( result ).toMatchObject( {
				ok: true,
				results: [
					{
						id: 'settings-flow',
						status: 'pass',
					},
				],
			} );
		} finally {
			fs.rmSync( tempRoot, { recursive: true, force: true } );
		}
	} );

	test( 'does not let WordPress cleanup failures override a successful audit', async () => {
		const outputRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'visual-audit-runcli-test-' )
		);
		const execFileSync = jest.fn( ( command, args, options ) => {
			const cookiePath = options.env.BROWSER_RUN_COOKIE_PATH;
			const tokenPath = options.env.BROWSER_RUN_TOKEN_PATH;

			if ( cookiePath && tokenPath ) {
				fs.writeFileSync(
					cookiePath,
					JSON.stringify( [
						{
							name: 'wordpress_sec_example',
							value: 'secret-cookie',
							domain: 'wp.hperkins.com',
							path: '/wp-admin',
							secure: true,
							httpOnly: true,
						},
					] )
				);
				fs.writeFileSync( tokenPath, 'session-token' );
				return Buffer.from( '' );
			}

			throw new Error( 'cleanup failed' );
		} );
		const logger = {
			error: jest.fn(),
			log: jest.fn(),
		};
		const fetchImpl = jest.fn( async () => ( {
			status: 200,
			headers: {
				get: jest.fn( () => '' ),
			},
		} ) );

		try {
			const result = await runCli(
				[
					'node',
					'scripts/browser-run-visual-audit.js',
					'--base-url=https://wp.hperkins.com',
					'--wp-path=/home/dev/wp-hperkins-com',
					'--skip-static',
					'--skip-workflows',
					`--output=${ outputRoot }`,
					'--run-name=cleanup-test',
				],
				{
					fetch: fetchImpl,
					execFileSync,
					logger,
					now: () => new Date( '2026-05-25T12:00:00.000Z' ),
					tempRoot: outputRoot,
				}
			);

			expect( result ).toMatchObject( {
				exitCode: 0,
				cleanupWarning: expect.stringContaining( 'cleanup failed' ),
			} );
			expect(
				JSON.parse(
					fs.readFileSync(
						path.join(
							outputRoot,
							'2026-05-25T12-00-00-000Z-cleanup-test',
							'summary.json'
						),
						'utf8'
					)
				).auth.cleanupWarning
			).toContain( 'cleanup failed' );
			expect( logger.error ).toHaveBeenCalledWith(
				expect.stringContaining( 'WordPress auth cleanup failed' )
			);
		} finally {
			fs.rmSync( outputRoot, { recursive: true, force: true } );
		}
	} );

	test( 'treats skipped static captures as successful when workflows pass', () => {
		expect(
			isAuditSuccessful(
				{ skipped: true },
				{
					ok: true,
				}
			)
		).toBe( true );
		expect(
			isAuditSuccessful(
				{ exitCode: 1 },
				{
					ok: true,
				}
			)
		).toBe( false );
	} );
} );
