describe( 'abilities bridge asset', () => {
	beforeEach( () => {
		jest.resetModules();
		delete window.flavorAgentAbilities;
		delete window.wp;
	} );

	afterEach( () => {
		delete window.flavorAgentAbilities;
		delete window.wp;
		jest.dontMock( '@wordpress/core-abilities' );
		jest.dontMock( '@wordpress/abilities' );
	} );

	function mockModules( { ready, executeAbility } ) {
		jest.doMock(
			'@wordpress/core-abilities',
			() => ( ready === undefined ? {} : { ready } ),
			{ virtual: true }
		);
		jest.doMock( '@wordpress/abilities', () => ( { executeAbility } ), {
			virtual: true,
		} );
	}

	test( 'exposes core abilities readiness with a frozen executeAbility', async () => {
		const ready = Promise.resolve();
		const executeAbility = jest.fn();
		mockModules( { ready, executeAbility } );

		await import( '../../../assets/abilities-bridge' );

		expect( window.flavorAgentAbilities.ready ).toBe( ready );
		expect( typeof window.flavorAgentAbilities.executeAbility ).toBe(
			'function'
		);
		expect( Object.isFrozen( window.flavorAgentAbilities ) ).toBe( true );
	} );

	test( 'delegates to the native executeAbility on success', async () => {
		const executeAbility = jest.fn().mockResolvedValue( { ok: true } );
		mockModules( { ready: Promise.resolve(), executeAbility } );

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

	test( 'uses an already-resolved readiness when core does not export ready', async () => {
		const executeAbility = jest.fn();
		mockModules( { ready: undefined, executeAbility } );

		await import( '../../../assets/abilities-bridge' );

		await expect(
			window.flavorAgentAbilities.ready
		).resolves.toBeUndefined();
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
		mockModules( { ready: Promise.resolve(), executeAbility } );

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
		mockModules( { ready: Promise.resolve(), executeAbility } );

		await import( '../../../assets/abilities-bridge' );

		await expect(
			window.flavorAgentAbilities.executeAbility( 'x/y', {} )
		).rejects.toBe( thrown );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
