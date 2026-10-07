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

	test( 'extracted release preserves coherent production Composer metadata and every classmap file', () => {
		const fixtureRoot = path.join( temporaryRoot, 'source' );
		const releaseParent = path.join( temporaryRoot, 'release' );
		const releaseRoot = path.join( releaseParent, 'flavor-agent' );
		const extractedParent = path.join( temporaryRoot, 'extracted' );
		fs.mkdirSync( path.join( fixtureRoot, 'scripts' ), {
			recursive: true,
		} );
		for ( const sourceFile of [
			'.distignore',
			'composer.json',
			'composer.lock',
			'flavor-agent.php',
			'scripts/prepare-release.sh',
		] ) {
			fs.copyFileSync(
				path.join( rootDir, sourceFile ),
				path.join( fixtureRoot, sourceFile )
			);
		}
		fs.cpSync(
			path.join( rootDir, 'inc' ),
			path.join( fixtureRoot, 'inc' ),
			{
				recursive: true,
			}
		);
		const staged = spawnSync(
			resolveBashExecutable(),
			[
				path.join( fixtureRoot, 'scripts/prepare-release.sh' ),
				releaseRoot,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					COMPOSER_DISABLE_NETWORK: '1',
					COMPOSER_ROOT_VERSION: '0.1.1',
				},
			}
		);
		expect( staged.error ).toBeUndefined();
		expect( staged.status ).toBe( 0 );
		const archive = spawnSync(
			'php',
			[
				'-r',
				`$archive = new PharData($argv[2], 0, null, Phar::ZIP);
$archive->buildFromDirectory($argv[1]);
$archive->extractTo($argv[3]);`,
				releaseParent,
				path.join( temporaryRoot, 'flavor-agent.zip' ),
				extractedParent,
			],
			{ encoding: 'utf8' }
		);
		expect( archive.error ).toBeUndefined();
		expect( archive.stderr ).toBe( '' );
		expect( archive.status ).toBe( 0 );
		const extractedRoot = path.join( extractedParent, 'flavor-agent' );
		const probe = spawnSync(
			'php',
			[
				'-r',
				`set_error_handler(static function ($severity, $message) {
	throw new RuntimeException($message);
});
$plugin = $argv[1];
$loader = require $plugin . '/vendor/autoload.php';
if (!class_exists('Composer\\\\InstalledVersions')) {
	throw new RuntimeException('Composer installed-package metadata cannot autoload.');
}
$classmap = require $plugin . '/vendor/composer/autoload_classmap.php';
foreach ($classmap as $class => $file) {
	if (!is_file($file)) {
		throw new RuntimeException('Missing classmap target: ' . $class);
	}
}
$runtimeClasses = array_filter(array_keys($classmap), static fn ($class) => str_starts_with($class, 'FlavorAgent\\\\'));
$root = Composer\\InstalledVersions::getRootPackage();
echo json_encode([
	'authoritative' => $loader->isClassMapAuthoritative(),
	'installedPackages' => Composer\\InstalledVersions::getInstalledPackages(),
	'rootName' => $root['name'],
	'rootVersion' => $root['pretty_version'],
	'rootDev' => $root['dev'],
	'rootPathMatches' => realpath($root['install_path']) === realpath($plugin),
	'runtimeClassCount' => count($runtimeClasses),
	'observerAutoloads' => class_exists('FlavorAgent\\\\Activity\\\\PersistenceSaveObserver'),
	'exporterAutoloads' => class_exists('FlavorAgent\\\\Activity\\\\RecommendationFixtureExport'),
], JSON_THROW_ON_ERROR);`,
				extractedRoot,
			],
			{ encoding: 'utf8' }
		);
		expect( probe.error ).toBeUndefined();
		expect( {
			status: probe.status,
			diagnostics:
				probe.stderr || ( probe.status !== 0 ? probe.stdout : '' ),
		} ).toEqual( { status: 0, diagnostics: '' } );
		expect( JSON.parse( probe.stdout ) ).toEqual( {
			authoritative: true,
			installedPackages: [ 'flavor-agent/flavor-agent' ],
			rootName: 'flavor-agent/flavor-agent',
			rootVersion: '0.1.1',
			rootDev: false,
			rootPathMatches: true,
			runtimeClassCount: fs
				.readdirSync( path.join( rootDir, 'inc' ), { recursive: true } )
				.filter( ( file ) => file.endsWith( '.php' ) ).length,
			observerAutoloads: true,
			exporterAutoloads: true,
		} );
		expect(
			fs.existsSync( path.join( extractedRoot, 'composer.lock' ) )
		).toBe( false );
		expect(
			fs.existsSync( path.join( extractedRoot, 'vendor/bin' ) )
		).toBe( false );
	} );
} );
