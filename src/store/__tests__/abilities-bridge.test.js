describe( 'abilities bridge asset', () => {
	beforeEach( () => {
		jest.resetModules();
		delete window.flavorAgentAbilities;
	} );

	afterEach( () => {
		delete window.flavorAgentAbilities;
		jest.dontMock( '@wordpress/core-abilities' );
		jest.dontMock( '@wordpress/abilities' );
	} );

	test( 'exposes core abilities readiness with executeAbility', async () => {
		const ready = Promise.resolve();
		const executeAbility = jest.fn();

		jest.doMock(
			'@wordpress/core-abilities',
			() => ( {
				ready,
			} ),
			{ virtual: true }
		);
		jest.doMock(
			'@wordpress/abilities',
			() => ( {
				executeAbility,
			} ),
			{ virtual: true }
		);

		await import( '../../../assets/abilities-bridge' );

		expect( window.flavorAgentAbilities ).toEqual( {
			ready,
			executeAbility,
		} );
		expect( Object.isFrozen( window.flavorAgentAbilities ) ).toBe( true );
	} );

	test( 'uses an already-resolved readiness promise when core does not export ready', async () => {
		const executeAbility = jest.fn();

		jest.doMock( '@wordpress/core-abilities', () => ( {} ), {
			virtual: true,
		} );
		jest.doMock(
			'@wordpress/abilities',
			() => ( {
				executeAbility,
			} ),
			{ virtual: true }
		);

		await import( '../../../assets/abilities-bridge' );

		await expect(
			window.flavorAgentAbilities.ready
		).resolves.toBeUndefined();
		expect( window.flavorAgentAbilities.executeAbility ).toBe(
			executeAbility
		);
	} );
} );
