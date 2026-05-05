jest.mock( '@wordpress/api-fetch', () => jest.fn() );

import apiFetch from '@wordpress/api-fetch';

import {
	buildAbilityRunPath,
	executeFlavorAgentAbility,
	normalizeAbilityExecutionResult,
} from '../abilities-client';

describe( 'abilities client', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		delete window.flavorAgentAbilities;
	} );

	test( 'buildAbilityRunPath preserves namespaced ability path segments', () => {
		expect( buildAbilityRunPath( 'flavor-agent/recommend-block' ) ).toBe(
			'/wp-abilities/v1/abilities/flavor-agent/recommend-block/run'
		);
	} );

	test( 'buildAbilityRunPath encodes unsafe characters inside ability segments', () => {
		expect( buildAbilityRunPath( 'flavor agent/recommend block' ) ).toBe(
			'/wp-abilities/v1/abilities/flavor%20agent/recommend%20block/run'
		);
	} );

	test( 'executeFlavorAgentAbility prefers the bridge when abort is not required', async () => {
		const executeAbility = jest.fn().mockResolvedValue( {
			payload: {
				explanation: 'Bridge result',
			},
		} );

		window.flavorAgentAbilities = { executeAbility };

		await expect(
			executeFlavorAgentAbility( 'flavor-agent/recommend-block', {
				prompt: 'Tighten this copy.',
			} )
		).resolves.toEqual( {
			explanation: 'Bridge result',
		} );

		expect( executeAbility ).toHaveBeenCalledWith(
			'flavor-agent/recommend-block',
			{
				prompt: 'Tighten this copy.',
			}
		);
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'executeFlavorAgentAbility falls back to REST when the bridge is not ready', async () => {
		const executeAbility = jest.fn().mockRejectedValue( {
			code: 'ability_not_found',
			message: 'Ability was not loaded yet.',
		} );

		window.flavorAgentAbilities = { executeAbility };
		apiFetch.mockResolvedValue( {
			result: {
				explanation: 'REST fallback result',
			},
		} );

		await expect(
			executeFlavorAgentAbility( 'flavor-agent/recommend-block', {
				prompt: 'Tighten this copy.',
			} )
		).resolves.toEqual( {
			explanation: 'REST fallback result',
		} );

		expect( executeAbility ).toHaveBeenCalledWith(
			'flavor-agent/recommend-block',
			{
				prompt: 'Tighten this copy.',
			}
		);
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/flavor-agent/recommend-block/run',
			method: 'POST',
			data: {
				input: {
					prompt: 'Tighten this copy.',
				},
			},
		} );
	} );

	test.each( [
		'ability_permission_denied',
		'ability_invalid_input',
		'ability_invalid_output',
		'missing_text_generation_provider',
		'provider_rate_limited',
	] )(
		'executeFlavorAgentAbility does not fall back for bridge %s errors',
		async ( code ) => {
			const error = new Error( 'Bridge rejected the request.' );
			error.code = code;

			const executeAbility = jest.fn().mockRejectedValue( error );

			window.flavorAgentAbilities = { executeAbility };

			await expect(
				executeFlavorAgentAbility( 'flavor-agent/recommend-block', {
					prompt: 'Tighten this copy.',
				} )
			).rejects.toBe( error );

			expect( executeAbility ).toHaveBeenCalled();
			expect( apiFetch ).not.toHaveBeenCalled();
		}
	);

	test( 'executeFlavorAgentAbility wraps REST fallback input for canonical Abilities API', async () => {
		const controller = new AbortController();

		apiFetch.mockResolvedValue( {
			result: {
				explanation: 'REST fallback result',
			},
		} );

		await expect(
			executeFlavorAgentAbility(
				'flavor-agent/recommend-template',
				{
					templateRef: 'theme//home',
					resolveSignatureOnly: true,
				},
				{
					signal: controller.signal,
				}
			)
		).resolves.toEqual( {
			explanation: 'REST fallback result',
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/flavor-agent/recommend-template/run',
			method: 'POST',
			data: {
				input: {
					templateRef: 'theme//home',
					resolveSignatureOnly: true,
				},
			},
			signal: controller.signal,
		} );
	} );

	test( 'executeFlavorAgentAbility falls back to REST when bridge is unavailable without abort signal', async () => {
		apiFetch.mockResolvedValue( {
			result: {
				explanation: 'REST no-signal fallback result',
			},
		} );

		await expect(
			executeFlavorAgentAbility( 'flavor-agent/recommend-block', {
				prompt: 'Tighten this copy.',
			} )
		).resolves.toEqual( {
			explanation: 'REST no-signal fallback result',
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/flavor-agent/recommend-block/run',
			method: 'POST',
			data: {
				input: {
					prompt: 'Tighten this copy.',
				},
			},
		} );
	} );

	test( 'normalizeAbilityExecutionResult supports known wrapper shapes', () => {
		expect(
			normalizeAbilityExecutionResult( { payload: { ok: true } } )
		).toEqual( {
			ok: true,
		} );
		expect(
			normalizeAbilityExecutionResult( { result: { ok: true } } )
		).toEqual( {
			ok: true,
		} );
		expect(
			normalizeAbilityExecutionResult( { output: { ok: true } } )
		).toEqual( {
			ok: true,
		} );
	} );
} );
