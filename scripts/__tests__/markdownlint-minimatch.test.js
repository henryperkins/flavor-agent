'use strict';

/* eslint jest/expect-expect: ["error", { "assertFunctionNames": ["expect", "expectChild"] }] */

const { spawnSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const cliPath = require.resolve( 'markdownlint-cli/markdownlint.js' );
const timeout = 5000;

// Resolve from each real consumer, rather than accidentally testing the root
// Minimatch copy. Keep hostile patterns in a killable child process.
const matcherProbe = `
	const assert = require( 'node:assert/strict' );
	const { createRequire } = require( 'node:module' );
	const cliRequire = createRequire( process.argv[ 1 ] );
	const globRequire = createRequire( cliRequire.resolve( 'glob' ) );
	const pattern = process.argv[ 2 ];
	const matching = 'ab'.repeat( 20 );
	const nonmatching = 'a'.repeat( 28 ) + 'c';
	for ( const consumerRequire of [ cliRequire, globRequire ] ) {
		const minimatch = consumerRequire( 'minimatch' );
		assert.equal( typeof minimatch, 'function' );
		const matcher = new minimatch.Minimatch( pattern );
		const defaultMatcher = minimatch.defaults( { dot: true } );
		for ( const [ input, expected ] of [ [ matching, true ], [ nonmatching, false ] ] ) {
			assert.equal( minimatch( input, pattern ), expected );
			assert.equal( matcher.match( input ), expected );
			assert.equal( matcher.makeRe().test( input ), expected );
			assert.equal( defaultMatcher( input, pattern ), expected );
			assert.equal( new defaultMatcher.Minimatch( pattern ).match( input ), expected );
			assert.equal( minimatch.filter( pattern )( input ), expected );
		}
		assert.deepEqual( minimatch.match( [ matching, nonmatching ], pattern ), [ matching ] );
		assert.equal( minimatch( 'keep/good.md', '**/*.{md,markdown}' ), true );
		assert.equal( minimatch( '.hidden/good.md', '**/*.md' ), false );
		assert.equal( minimatch( '.hidden/good.md', '**/*.md', { dot: true } ), true );
	}
	assert.deepEqual(
		cliRequire( 'glob' ).sync( pattern + '.md', { cwd: process.argv[ 3 ] } ),
		[ 'a.md' ]
	);
`;

// Execute the installed CLI's actual filtering helper with Windows path
// semantics. This catches the backslash regression in a bare parser override.
const windowsProbe = String.raw`
	const assert = require( 'node:assert/strict' );
	const fs = require( 'node:fs' );
	const path = require( 'node:path' );
	const vm = require( 'node:vm' );
	const { createRequire } = require( 'node:module' );
	const cliRequire = createRequire( process.argv[ 1 ] );
	const source = fs.readFileSync( process.argv[ 1 ], 'utf8' );
	const helper = source.match( /^function prepareFileList\([\s\S]*?^\}/m );
	assert.ok( helper, 'CLI file filtering helper must be exercised' );
	const normalizer = source.match( /^function posixPath\([\s\S]*?^\}/m );
	const prepareFileList = vm.runInNewContext(
		( normalizer ? normalizer[ 0 ] : '' ) + '\n(' + helper[ 0 ] + ')',
		{
			path: path.win32,
			minimatch: cliRequire( 'minimatch' ),
			processCwd: 'C:\\repo',
			options: { dot: false },
			fs: { lstatSync( file ) {
				if ( file === 'keep' ) return { isDirectory: () => true };
				throw new Error( 'glob is not a literal directory' );
			} },
			glob: { sync: () => assert.fail( 'Filtering must use previous results' ) },
		}
	);
	const files = [
		{ absolute: 'C:\\repo\\keep\\good.md', original: 'keep\\good.md' },
		{ absolute: 'C:\\repo\\ignored\\bad.md', original: 'ignored\\bad.md' },
	];
	for ( const pattern of [ 'keep', 'keep\\**\\*.md' ] ) {
		const result = prepareFileList( [ pattern ], [ 'md', 'markdown' ], files );
		assert.deepEqual( Array.from( result, file => file.original ), [ 'keep\\good.md' ] );
	}
`;

function expectChild( result, status ) {
	expect( result.error ).toBeUndefined();
	expect( result.signal ).toBeNull();
	if ( result.status !== status ) {
		throw new Error( result.stderr || result.stdout || 'Unexpected exit' );
	}
	expect( result.status ).toBe( status );
}

describe( 'legacy Markdown CLI Minimatch compatibility', () => {
	let fixtureDir;
	let jsonConfig;
	let yamlConfig;

	beforeAll( () => {
		fixtureDir = fs.mkdtempSync(
			path.join( os.tmpdir(), 'flavor markdownlint-' )
		);
		for ( const folder of [ 'keep', 'ignored', '.hidden' ] ) {
			fs.mkdirSync( path.join( fixtureDir, folder ) );
		}
		const clean = '# Fixture\n\nClean paragraph.\n';
		fs.writeFileSync( path.join( fixtureDir, 'keep/good.md' ), clean );
		fs.writeFileSync( path.join( fixtureDir, 'a.md' ), clean );
		fs.writeFileSync(
			path.join( fixtureDir, 'a'.repeat( 28 ) + 'c.md' ),
			clean
		);
		for ( const folder of [ 'ignored', '.hidden' ] ) {
			fs.writeFileSync(
				path.join( fixtureDir, folder, 'bad.md' ),
				'# Bad\nNo blank.\n'
			);
		}
		jsonConfig = path.join( fixtureDir, 'config.json' );
		yamlConfig = path.join( fixtureDir, 'config.yaml' );
		fs.writeFileSync( jsonConfig, '{ "default": true, "MD013": false }' );
		fs.writeFileSync( yamlConfig, 'default: true\nMD013: false\n' );
	} );

	afterAll( () => {
		fs.rmSync( fixtureDir, { recursive: true, force: true } );
	} );

	test.each( [ '*(*(*(a|b)))', '+(+(+(a|b)))', '*(+(*(a|b)))' ] )(
		'matches and rejects nested extglob %s promptly through both consumers',
		( pattern ) => {
			const result = spawnSync(
				process.execPath,
				[ '-e', matcherProbe, cliPath, pattern, fixtureDir ],
				{ encoding: 'utf8', timeout }
			);
			expectChild( result, 0 );
		},
		10000
	);

	test( 'retains Windows directory and glob ignores', () => {
		const result = spawnSync(
			process.execPath,
			[ '-e', windowsProbe, cliPath ],
			{
				encoding: 'utf8',
				timeout,
				env: {
					...process.env,
					__MINIMATCH_TESTING_PLATFORM__: 'win32',
				},
			}
		);
		expectChild( result, 0 );
	} );

	test.each( [
		[ 'directory ignore', [ '.', '--ignore', 'ignored' ], false, 0 ],
		[
			'brace and dot ignore',
			[ '.', '--dot', '--ignore', '{ignored,.hidden}/**/*.md' ],
			true,
			0,
		],
		[
			'brace input glob',
			[ '{keep,ignored}/*.md', '--ignore', 'ignored/*.md' ],
			false,
			0,
		],
		[ 'invalid file control', [ '.' ], false, 1 ],
		[
			'dot file control',
			[ '.', '--dot', '--ignore', 'ignored' ],
			true,
			1,
		],
		[
			'nested extglob ignore',
			[ '*.md', '--ignore', '*(*(*(a|b))).md' ],
			false,
			0,
		],
		[ 'nested extglob input', [ '*(*(*(a|b))).md' ], false, 0 ],
	] )(
		'%s retains CLI behavior',
		( name, args, yaml, status ) => {
			const outputPath = path.join( fixtureDir, name + '.json' );
			const result = spawnSync(
				process.execPath,
				[
					cliPath,
					'--config',
					yaml ? yamlConfig : jsonConfig,
					'--json',
					'--output',
					outputPath,
					...args,
				],
				{ cwd: fixtureDir, encoding: 'utf8', timeout }
			);
			expectChild( result, status );
			const output = fs.readFileSync( outputPath, 'utf8' );
			const errors = output ? JSON.parse( output ) : [];
			expect( output.length === 0 ).toBe( status === 0 );
			expect( errors.map( ( error ) => error.ruleNames[ 0 ] ) ).toEqual(
				status === 0 ? [] : [ 'MD022' ]
			);
		},
		10000
	);
} );
