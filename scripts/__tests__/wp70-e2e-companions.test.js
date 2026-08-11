const fs = require( 'fs' );
const path = require( 'path' );

const {
	DEFAULT_GUTENBERG_VERSION,
	parseCompanionPlugins,
	parseGutenbergVersion,
	readGutenbergFlag,
} = require( '../wp70-e2e' );

const PACKAGE_JSON = require( '../../package.json' );
const WORKFLOW_PATH = path.join(
	__dirname,
	'../../.github/workflows/verify.yml'
);

describe( 'WP 7.0 harness companion plugins', () => {
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
		expect( parseCompanionPlugins( 'ai, gutenberg@23.7.1' ) ).toEqual( [
			{ slug: 'ai', version: null },
			{ slug: 'gutenberg', version: '23.7.1' },
		] );
	} );

	test( 'appends the requested Gutenberg version', () => {
		expect( parseCompanionPlugins( undefined, '23.7.1' ) ).toEqual( [
			{ slug: 'ai', version: null },
			{ slug: 'gutenberg', version: '23.7.1' },
		] );
	} );

	test( 'lets an explicit gutenberg entry win over the requested version', () => {
		expect(
			parseCompanionPlugins( 'ai, gutenberg@23.6.2', '23.7.1' )
		).toEqual( [
			{ slug: 'ai', version: null },
			{ slug: 'gutenberg', version: '23.6.2' },
		] );
	} );

	test( 'leaves Gutenberg out unless it is asked for', () => {
		expect( parseCompanionPlugins( undefined, null ) ).not.toContainEqual(
			expect.objectContaining( { slug: 'gutenberg' } )
		);
	} );
} );

describe( 'WP 7.0 harness Gutenberg opt-in', () => {
	test( 'treats a bare opt-in as the pinned default', () => {
		expect( parseGutenbergVersion( '1' ) ).toBe( DEFAULT_GUTENBERG_VERSION );
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

	test( 'pins a patched release rather than the superseded 23.7.0', () => {
		expect( DEFAULT_GUTENBERG_VERSION ).toBe( '23.7.1' );
	} );
} );

// A silently-ignored flag is the dangerous failure here: the CI leg would run
// without Gutenberg and still report green, which reads as evidence it is not.
describe( 'WP 7.0 harness --with-gutenberg flag', () => {
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

	test( 'the npm script CI runs actually passes the flag', () => {
		// The Gutenberg CI leg invokes this script by name; if it lost the flag
		// the leg would quietly verify the bundled editor twice.
		expect(
			PACKAGE_JSON.scripts[ 'wp:e2e:wp70:bootstrap:gutenberg' ]
		).toContain( '--with-gutenberg' );
	} );

	test( 'the e2e-wp70 workflow runs both editor legs', () => {
		const workflow = fs.readFileSync( WORKFLOW_PATH, 'utf8' );

		expect( workflow ).toContain(
			'bootstrap: npm run wp:e2e:wp70:bootstrap\n'
		);
		expect( workflow ).toContain(
			'bootstrap: npm run wp:e2e:wp70:bootstrap:gutenberg\n'
		);
		// Distinct artifact names, or the second leg's upload collides.
		expect( workflow ).toContain(
			'name: playwright-wp70-${{ matrix.editor.label }}'
		);
	} );
} );
