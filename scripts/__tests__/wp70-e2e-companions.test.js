const {
	DEFAULT_GUTENBERG_VERSION,
	parseCompanionPlugins,
	parseGutenbergVersion,
} = require( '../wp70-e2e' );

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
