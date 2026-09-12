'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );
const { resolveBashExecutable } = require( '../run-bash' );

const rootDir = path.resolve( __dirname, '../..' );
const developmentPaths = [
	'.dockerignore',
	'.nvmrc',
	'.npmrc',
	'.node-version',
	'.mcp.json',
	'.env.example',
	'.env',
	'.gitattributes',
	'.gitignore',
	'.distignore',
	'.deployignore',
	'.claude/skills/example/SKILL.md',
	'.codex/config.toml',
	'.github/workflows/wpcom.yml',
	'.superpowers/sdd/state.json',
	'docker/wordpress/build-setup.sh',
	'docker/wordpress/entrypoint.sh',
	'phpcs.xml.dist',
	'phpunit.xml.dist',
	'scripts/prepare-release.sh',
	'scripts/plugin-check.sh',
	'tools/code-search/install-hook.sh',
	'tests/phpunit/Support/theme-json-stub.php',
	'tests/phpunit/fixtures/plugin-autoload.php',
	'tests/e2e/playground-mu-plugin/flavor-agent-loader.php',
	'src/index.js',
	'node_modules/example/index.js',
	'docs/reference/example.md',
	'output/report.json',
	'dist/flavor-agent.zip',
	'package.json',
	'package-lock.json',
	'composer.lock',
	'AGENTS.md',
	'STATUS.md',
];
const runtimePaths = [
	'flavor-agent.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'inc/Activity/PersistenceSaveObserver.php',
	'build/index.js',
	'build/index.asset.php',
	'build/admin.js',
	'build/activity-log.js',
	'assets/abilities-bridge.js',
	'languages/flavor-agent.pot',
	'shared/support-to-panel.json',
	'vendor/autoload.php',
	'vendor/composer/autoload_classmap.php',
];

describe( 'production file exclusions', () => {
	let temporaryRoot;

	beforeEach( () => {
		temporaryRoot = fs.mkdtempSync(
			path.join( os.tmpdir(), 'flavor-agent-release-test-' )
		);
	} );

	afterEach( () => {
		if (
			path.dirname( temporaryRoot ) !== path.resolve( os.tmpdir() ) ||
			! path
				.basename( temporaryRoot )
				.startsWith( 'flavor-agent-release-test-' )
		) {
			throw new Error(
				'Refusing cleanup outside the release test directory.'
			);
		}
		fs.rmSync( temporaryRoot, { recursive: true, force: true } );
	} );

	test( 'WordPress.com deployment skips development files and keeps runtime files', () => {
		const init = spawnSync( 'git', [ 'init', '--quiet', temporaryRoot ], {
			encoding: 'utf8',
		} );
		expect( init.status ).toBe( 0 );
		const deployIgnore = path.join( rootDir, '.deployignore' );
		fs.writeFileSync(
			path.join( temporaryRoot, '.gitignore' ),
			fs.existsSync( deployIgnore )
				? fs.readFileSync( deployIgnore, 'utf8' )
				: ''
		);
		const globalIgnore = path.join( temporaryRoot, 'empty-global-ignore' );
		fs.writeFileSync( globalIgnore, '' );
		const result = spawnSync(
			'git',
			[
				'-c',
				`core.excludesFile=${ globalIgnore }`,
				'-c',
				'core.ignoreCase=false',
				'check-ignore',
				'--no-index',
				'--stdin',
				'-z',
			],
			{
				cwd: temporaryRoot,
				encoding: 'utf8',
				input:
					[ ...developmentPaths, ...runtimePaths ].join( '\0' ) +
					'\0',
			}
		);
		expect( result.error ).toBeUndefined();
		expect( result.stderr ).toBe( '' );
		expect( result.stdout.split( '\0' ).filter( Boolean ).sort() ).toEqual(
			[ ...developmentPaths ].sort()
		);
	} );

	test( 'release staging excludes developer directories, including empty agent state', () => {
		const fixtureRoot = path.join( temporaryRoot, 'source' );
		const releaseRoot = path.join( temporaryRoot, 'release' );
		for ( const fixturePath of [ ...developmentPaths, ...runtimePaths ] ) {
			const destination = path.join( fixtureRoot, fixturePath );
			fs.mkdirSync( path.dirname( destination ), { recursive: true } );
			fs.writeFileSync( destination, 'fixture' );
		}
		fs.mkdirSync( path.join( fixtureRoot, '.superpowers/sdd/empty' ), {
			recursive: true,
		} );
		fs.copyFileSync(
			path.join( rootDir, '.distignore' ),
			path.join( fixtureRoot, '.distignore' )
		);
		fs.copyFileSync(
			path.join( rootDir, 'scripts/prepare-release.sh' ),
			path.join( fixtureRoot, 'scripts/prepare-release.sh' )
		);
		const result = spawnSync(
			resolveBashExecutable(),
			[
				path.join( fixtureRoot, 'scripts/prepare-release.sh' ),
				releaseRoot,
			],
			{ encoding: 'utf8' }
		);
		expect( result.error ).toBeUndefined();
		expect( result.stderr ).toBe( '' );
		expect( result.status ).toBe( 0 );
		for ( const developmentPath of developmentPaths ) {
			expect( {
				path: developmentPath,
				included: fs.existsSync(
					path.join( releaseRoot, developmentPath )
				),
			} ).toEqual( { path: developmentPath, included: false } );
		}
		for ( const runtimePath of runtimePaths ) {
			expect(
				fs.readFileSync( path.join( releaseRoot, runtimePath ), 'utf8' )
			).toBe( 'fixture' );
		}
		expect(
			fs.existsSync( path.join( releaseRoot, '.superpowers' ) )
		).toBe( false );
	} );
} );
