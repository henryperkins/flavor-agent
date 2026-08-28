const fs = require( 'fs' );
const path = require( 'path' );

const BRIDGE_PATH = path.join(
	__dirname,
	'../../../assets/abilities-bridge.js'
);
const PLUGIN_ENTRY_PATH = path.join( __dirname, '../../../flavor-agent.php' );

describe( 'abilities bridge asset', () => {
	beforeEach( () => {
		jest.resetModules();
		delete window.flavorAgentAbilities;
		delete window.wp;
	} );

	afterEach( () => {
		delete window.flavorAgentAbilities;
		delete window.wp;
		jest.dontMock( '@wordpress/abilities' );
	} );

	function mockModules( { executeAbility } ) {
		jest.doMock( '@wordpress/abilities', () => ( { executeAbility } ), {
			virtual: true,
		} );
	}

	test( 'exposes a resolved readiness with a frozen executeAbility', async () => {
		const executeAbility = jest.fn();
		mockModules( { executeAbility } );

		await import( '../../../assets/abilities-bridge' );

		await expect(
			window.flavorAgentAbilities.ready
		).resolves.toBeUndefined();
		expect( typeof window.flavorAgentAbilities.executeAbility ).toBe(
			'function'
		);
		expect( Object.isFrozen( window.flavorAgentAbilities ) ).toBe( true );
	} );

	test( 'delegates to the native executeAbility on success', async () => {
		const executeAbility = jest.fn().mockResolvedValue( { ok: true } );
		mockModules( { executeAbility } );

		await import( '../../../assets/abilities-bridge' );

		const result = await window.flavorAgentAbilities.executeAbility(
			'flavor-agent/recommend-block',
			{ surface: 'block' }
		);

		expect( executeAbility ).toHaveBeenCalledWith(
			'flavor-agent/recommend-block',
			{ surface: 'block' }
		);
		expect( result ).toEqual( { ok: true } );
	} );

	// Gutenberg 23.6 (gutenberg#79155) made `@wordpress/core-abilities`
	// auto-initialize on import, fetching categories and abilities with
	// `per_page: -1` — values the REST controller rejects. Loading it from the
	// bridge would fire both rejected requests on every editor load, so neither
	// the asset nor the script-module dependency list may reference it.
	test( 'never pulls in @wordpress/core-abilities', () => {
		expect( fs.readFileSync( BRIDGE_PATH, 'utf8' ) ).not.toMatch(
			/from\s+'@wordpress\/core-abilities'/
		);

		const scriptModuleCall = fs
			.readFileSync( PLUGIN_ENTRY_PATH, 'utf8' )
			.match(
				/wp_enqueue_script_module\(\s*'@flavor-agent\/abilities-bridge'[\s\S]*?\);/
			)?.[ 0 ];

		expect( scriptModuleCall ).toBeDefined();
		expect( scriptModuleCall ).toContain( "'@wordpress/abilities'" );
		expect( scriptModuleCall ).not.toContain(
			"'@wordpress/core-abilities'"
		);
	} );

	test( 'falls back to the REST run endpoint when the store reports Ability not found', async () => {
		const executeAbility = jest
			.fn()
			.mockRejectedValue(
				new Error( 'Ability not found: flavor-agent/recommend-block' )
			);
		const apiFetch = jest
			.fn()
			.mockResolvedValue( { payload: { suggestions: [] } } );
		window.wp = { apiFetch };
		mockModules( { executeAbility } );

		await import( '../../../assets/abilities-bridge' );

		const result = await window.flavorAgentAbilities.executeAbility(
			'flavor-agent/recommend-block',
			{ surface: 'block' }
		);

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/flavor-agent/recommend-block/run',
			method: 'POST',
			data: { input: { surface: 'block' } },
		} );
		expect( result ).toEqual( { suggestions: [] } );
	} );

	test( 'rethrows non-not-found errors without attempting REST', async () => {
		const thrown = new Error( 'boom' );
		const executeAbility = jest.fn().mockRejectedValue( thrown );
		const apiFetch = jest.fn();
		window.wp = { apiFetch };
		mockModules( { executeAbility } );

		await import( '../../../assets/abilities-bridge' );

		await expect(
			window.flavorAgentAbilities.executeAbility( 'x/y', {} )
		).rejects.toBe( thrown );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
