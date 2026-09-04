const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const YAML = require( 'yaml' );

jest.mock( '@playwright/test', () => ( {
	request: {
		newContext: jest.fn(),
	},
} ) );

const { request } = require( '@playwright/test' );
const verifyPlaygroundEditorRuntime = require( '../../tests/e2e/playground.global-setup' );

const {
	DEFAULT_GUTENBERG_VERSION,
	getWp70HarnessConfig,
	parseCompanionPlugins,
	parseGutenbergVersion,
	readGutenbergFlag,
} = require( '../wp70-e2e' );

const PACKAGE_JSON = require( '../../package.json' );
const WORKFLOW_PATH = path.join(
	__dirname,
	'../../.github/workflows/verify.yml'
);
const PLAYGROUND_CONFIG_PATH = path.join(
	__dirname,
	'../../playwright.config.js'
);
const PLAYGROUND_BLUEPRINT_PATH = path.join(
	__dirname,
	'../../tests/e2e/playground-blueprint.json'
);

function readPlaygroundConfig( environment = {} ) {
	return JSON.parse(
		execFileSync(
			process.execPath,
			[
				'-e',
				'const config = require(process.argv[1]); process.stdout.write(JSON.stringify({ webServer: config.webServer, globalSetup: config.globalSetup }))',
				PLAYGROUND_CONFIG_PATH,
			],
			{
				encoding: 'utf8',
				env: { ...process.env, ...environment },
			}
		)
	);
}

describe( 'WP 7.1 harness companion plugins', () => {
	test( 'defaults to the mandatory AI plugin, unpinned', () => {
		expect( parseCompanionPlugins( undefined ) ).toEqual( [
			{ slug: 'ai', version: null },
		] );
	} );

	test( 'keeps the AI plugin first however the override is ordered', () => {
		expect( parseCompanionPlugins( 'plugin-check, ai' ) ).toEqual( [
			{ slug: 'plugin-check', version: null },
			{ slug: 'ai', version: null },
		] );

		expect( parseCompanionPlugins( 'plugin-check' ) ).toEqual( [
			{ slug: 'ai', version: null },
			{ slug: 'plugin-check', version: null },
		] );
	} );

	test( 'reads a pinned version off a slug@version entry', () => {
		expect( parseCompanionPlugins( 'ai, gutenberg@23.9.0' ) ).toEqual( [
			{ slug: 'ai', version: null },
			{ slug: 'gutenberg', version: '23.9.0' },
		] );
	} );

	test( 'appends the requested Gutenberg version', () => {
		expect( parseCompanionPlugins( undefined, '23.9.0' ) ).toEqual( [
			{ slug: 'ai', version: null },
			{ slug: 'gutenberg', version: '23.9.0' },
		] );
	} );

	test( 'lets an explicit gutenberg entry win over the requested version', () => {
		expect(
			parseCompanionPlugins( 'ai, gutenberg@23.7.1', '23.9.0' )
		).toEqual( [
			{ slug: 'ai', version: null },
			{ slug: 'gutenberg', version: '23.7.1' },
		] );
	} );

	test( 'leaves Gutenberg out unless it is asked for', () => {
		expect( parseCompanionPlugins( undefined, null ) ).not.toContainEqual(
			expect.objectContaining( { slug: 'gutenberg' } )
		);
	} );
} );

describe( 'WP 7.1 harness Gutenberg version resolution', () => {
	test( 'treats a bare opt-in as the pinned default', () => {
		expect( parseGutenbergVersion( '1' ) ).toBe(
			DEFAULT_GUTENBERG_VERSION
		);
		expect( parseGutenbergVersion( 'true' ) ).toBe(
			DEFAULT_GUTENBERG_VERSION
		);
	} );

	test( 'passes an explicit version through', () => {
		expect( parseGutenbergVersion( '23.6.2' ) ).toBe( '23.6.2' );
	} );

	test( 'stays off when unset or explicitly disabled', () => {
		expect( parseGutenbergVersion( undefined ) ).toBeNull();
		expect( parseGutenbergVersion( '' ) ).toBeNull();
		expect( parseGutenbergVersion( '0' ) ).toBeNull();
		expect( parseGutenbergVersion( 'false' ) ).toBeNull();
	} );

	test( 'pins the latest supported Gutenberg release', () => {
		expect( DEFAULT_GUTENBERG_VERSION ).toBe( '23.9.0' );
	} );
} );

describe( 'WP 7.1 harness editor selection', () => {
	const originalGutenbergVersion = process.env.FLAVOR_AGENT_WP70_GUTENBERG;

	afterEach( () => {
		if ( originalGutenbergVersion === undefined ) {
			delete process.env.FLAVOR_AGENT_WP70_GUTENBERG;
			return;
		}

		process.env.FLAVOR_AGENT_WP70_GUTENBERG = originalGutenbergVersion;
	} );

	test( 'defaults unflagged Playwright bootstrap callers to the supported release', () => {
		delete process.env.FLAVOR_AGENT_WP70_GUTENBERG;

		const harness = getWp70HarnessConfig();

		expect( harness.gutenbergVersion ).toBe( DEFAULT_GUTENBERG_VERSION );
		expect( harness.companionPlugins ).toContainEqual( {
			slug: 'gutenberg',
			version: DEFAULT_GUTENBERG_VERSION,
		} );
	} );

	test( 'uses the last repeated CLI flag and keeps it above the environment', () => {
		process.env.FLAVOR_AGENT_WP70_GUTENBERG = '23.6.2';
		const requestedVersion = readGutenbergFlag( [
			'--with-gutenberg',
			'--with-gutenberg=23.7.1',
		] );

		const harness = getWp70HarnessConfig( undefined, {
			gutenberg: requestedVersion,
		} );

		expect( requestedVersion ).toBe( '23.7.1' );
		expect( harness.gutenbergVersion ).toBe( '23.7.1' );
	} );

	test.each( [
		[ '23.7.1', '23.7.1' ],
		[ '0', null ],
		[ 'false', null ],
	] )(
		'lets FLAVOR_AGENT_WP70_GUTENBERG=%s override the config default',
		( environmentValue, expectedVersion ) => {
			process.env.FLAVOR_AGENT_WP70_GUTENBERG = environmentValue;

			const harness = getWp70HarnessConfig( undefined, {
				gutenberg: readGutenbergFlag( [] ),
			} );

			expect( harness.gutenbergVersion ).toBe( expectedVersion );
		}
	);

	test( 'lets an explicit bare CLI flag override an environment disable', () => {
		process.env.FLAVOR_AGENT_WP70_GUTENBERG = 'false';

		const harness = getWp70HarnessConfig( undefined, {
			gutenberg: readGutenbergFlag( [ '--with-gutenberg' ] ),
		} );

		expect( harness.gutenbergVersion ).toBe( DEFAULT_GUTENBERG_VERSION );
	} );

	test.each( [ '0', 'false' ] )(
		'lets an appended --with-gutenberg=%s explicitly select bundled',
		( value ) => {
			process.env.FLAVOR_AGENT_WP70_GUTENBERG = '23.7.1';
			const requestedVersion = readGutenbergFlag( [
				'--with-gutenberg',
				`--with-gutenberg=${ value }`,
			] );

			const harness = getWp70HarnessConfig( undefined, {
				gutenberg: requestedVersion,
			} );

			expect( harness.gutenbergVersion ).toBeNull();
			expect( harness.companionPlugins ).not.toContainEqual(
				expect.objectContaining( { slug: 'gutenberg' } )
			);
		}
	);
} );

// A silently-ignored flag is the dangerous failure here: the CI leg would run
// without Gutenberg and still report green, which reads as evidence it is not.
describe( 'WP 7.1 harness --with-gutenberg flag', () => {
	test( 'reads the bare flag as an opt-in', () => {
		const flag = readGutenbergFlag( [ '--with-gutenberg' ] );

		expect( flag ).toBe( '1' );
		expect( parseGutenbergVersion( flag ) ).toBe(
			DEFAULT_GUTENBERG_VERSION
		);
	} );

	test( 'reads an explicit version off the flag', () => {
		expect( readGutenbergFlag( [ '--with-gutenberg=23.6.2' ] ) ).toBe(
			'23.6.2'
		);
	} );

	test( 'treats an empty value as the pinned default', () => {
		expect( readGutenbergFlag( [ '--with-gutenberg=' ] ) ).toBe( '1' );
	} );

	test( 'stays undefined when the flag is absent', () => {
		expect( readGutenbergFlag( [] ) ).toBeUndefined();
		expect( readGutenbergFlag( [ '--other' ] ) ).toBeUndefined();
	} );

	test( 'the default npm bootstrap installs the supported Gutenberg release', () => {
		// Config owns the default so environment and appended CLI overrides remain
		// effective instead of being shadowed by a synthetic bare flag.
		expect( PACKAGE_JSON.scripts[ 'wp:e2e:wp70:bootstrap' ] ).toBe(
			'node scripts/wp70-e2e.js bootstrap'
		);
		expect(
			PACKAGE_JSON.scripts[ 'wp:e2e:wp70:bootstrap:gutenberg' ]
		).toBe( 'node scripts/wp70-e2e.js bootstrap' );
	} );

	test( 'the bundled npm bootstrap explicitly disables Gutenberg', () => {
		expect(
			PACKAGE_JSON.scripts[ 'wp:e2e:wp70:bootstrap:bundled' ]
		).toContain( '--with-gutenberg=false' );
	} );

	test( 'the e2e-wp70 workflow hard-gates bundled and latest Gutenberg', () => {
		const workflow = YAML.parse( fs.readFileSync( WORKFLOW_PATH, 'utf8' ) );
		const job = workflow.jobs[ 'e2e-wp70' ];
		const upload = job.steps.find(
			( step ) => step.name === 'Upload Playwright artifacts'
		);

		expect( job.strategy ).toEqual( {
			'fail-fast': false,
			matrix: {
				editor: [
					{ label: 'bundled', gutenberg: 'false' },
					{ label: 'gutenberg-23.9.0', gutenberg: '23.9.0' },
				],
			},
		} );
		expect( job[ 'continue-on-error' ] ).toBeUndefined();
		expect( job.env ).toMatchObject( {
			FLAVOR_AGENT_WP70_GUTENBERG: '${{ matrix.editor.gutenberg }}',
			MARIADB_IMAGE: 'mariadb:11.4',
		} );
		expect( upload.with.name ).toBe(
			'playwright-wp70-${{ matrix.editor.label }}'
		);
	} );
} );

describe( 'Playground latest-Gutenberg boundary', () => {
	test( 'the default Playground server installs the supported Gutenberg release', () => {
		const playgroundConfig = readPlaygroundConfig();
		const playgroundServer = playgroundConfig.webServer;
		const expectedPort = process.env.PLAYWRIGHT_PORT || '9402';

		expect( playgroundServer.command ).toContain(
			'--blueprint tests/e2e/playground-blueprint.json'
		);
		expect( playgroundServer.url ).toBe(
			`http://127.0.0.1:${ expectedPort }/flavor-agent-gutenberg-${ DEFAULT_GUTENBERG_VERSION }-ready.txt`
		);
		expect( playgroundServer.port ).toBeUndefined();
		expect( playgroundConfig.globalSetup ).toBe(
			path.join( __dirname, '../../tests/e2e/playground.global-setup.js' )
		);

		const blueprint = JSON.parse(
			fs.readFileSync( PLAYGROUND_BLUEPRINT_PATH, 'utf8' )
		);
		const gutenbergInstall = blueprint.steps.find(
			( step ) => step.step === 'installPlugin'
		);

		expect( blueprint.login ).toBe( true );
		expect( blueprint.steps.at( -1 ) ).toEqual( {
			step: 'writeFile',
			path: `/wordpress/flavor-agent-gutenberg-${ DEFAULT_GUTENBERG_VERSION }-ready.txt`,
			data: 'ready',
		} );
		expect( gutenbergInstall ).toEqual( {
			step: 'installPlugin',
			pluginData: {
				resource: 'url',
				url: `https://downloads.wordpress.org/plugin/gutenberg.${ DEFAULT_GUTENBERG_VERSION }.zip`,
			},
			options: {
				activate: true,
			},
		} );
	} );

	test( 'keeps a custom port aligned across the command and readiness URL', () => {
		const playgroundServer = readPlaygroundConfig( {
			PLAYWRIGHT_PORT: '9555',
		} ).webServer;

		expect( playgroundServer.command ).toContain( '--port 9555' );
		expect( playgroundServer.url ).toBe(
			`http://127.0.0.1:9555/flavor-agent-gutenberg-${ DEFAULT_GUTENBERG_VERSION }-ready.txt`
		);
	} );
} );

describe( 'Playground runtime editor guard', () => {
	const config = {
		projects: [
			{
				use: {
					baseURL: 'http://127.0.0.1:9402',
				},
			},
		],
	};
	let get;
	let dispose;

	function mockResponse( { body, ok = true, status = 200 } ) {
		return {
			ok: () => ok,
			status: () => status,
			text: jest.fn().mockResolvedValue( JSON.stringify( body ) ),
		};
	}

	beforeEach( () => {
		get = jest.fn();
		dispose = jest.fn().mockResolvedValue();
		request.newContext.mockResolvedValue( { get, dispose } );
	} );

	afterEach( () => {
		jest.clearAllMocks();
	} );

	test( 'accepts the exact active Gutenberg version', async () => {
		get.mockResolvedValue(
			mockResponse( {
				body: {
					status: 'ready',
					gutenberg_version: DEFAULT_GUTENBERG_VERSION,
				},
			} )
		);

		await expect(
			verifyPlaygroundEditorRuntime( config )
		).resolves.toBeUndefined();
		expect( dispose ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'rejects an inactive or mismatched runtime response', async () => {
		get.mockResolvedValue(
			mockResponse( {
				body: {
					code: 'flavor_agent_e2e_editor_not_ready',
				},
				ok: false,
				status: 503,
			} )
		);

		await expect( verifyPlaygroundEditorRuntime( config ) ).rejects.toThrow(
			'returned HTTP 503'
		);
		expect( dispose ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'rejects a successful response for the wrong Gutenberg version', async () => {
		get.mockResolvedValue(
			mockResponse( {
				body: {
					status: 'ready',
					gutenberg_version: '23.8.0',
				},
			} )
		);

		await expect( verifyPlaygroundEditorRuntime( config ) ).rejects.toThrow(
			`did not confirm active Gutenberg ${ DEFAULT_GUTENBERG_VERSION }`
		);
		expect( dispose ).toHaveBeenCalledTimes( 1 );
	} );
} );
