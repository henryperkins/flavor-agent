import apiFetch from '@wordpress/api-fetch';
import {
	buildAbilityRunPath,
	normalizeAbilityExecutionResult,
	shouldFallbackToAbilityRest,
} from '../../assets/ability-execution-utils';

export { buildAbilityRunPath, normalizeAbilityExecutionResult };

async function isBridgeReady( bridgeApi ) {
	const ready = bridgeApi?.ready;

	if ( ! ready || typeof ready.then !== 'function' ) {
		return true;
	}

	try {
		await ready;
		return true;
	} catch {
		return false;
	}
}

async function executeAbilityViaRest( abilityName, data, { signal } = {} ) {
	const request = {
		path: buildAbilityRunPath( abilityName ),
		method: 'POST',
		data: {
			input: data,
		},
	};

	if ( signal ) {
		request.signal = signal;
	}

	const result = await apiFetch( request );

	return normalizeAbilityExecutionResult( result );
}

export async function executeFlavorAgentAbility(
	abilityName,
	data,
	{ signal } = {}
) {
	if ( ! abilityName ) {
		const error = new Error(
			'Flavor Agent ability execution is unavailable because no ability name was provided.'
		);
		error.code = 'flavor_agent_missing_ability_name';
		throw error;
	}

	const bridgeApi =
		typeof window !== 'undefined' ? window.flavorAgentAbilities : null;
	const bridge = bridgeApi?.executeAbility;

	if ( typeof bridge === 'function' && ! signal ) {
		if ( await isBridgeReady( bridgeApi ) ) {
			try {
				return await bridge( abilityName, data );
			} catch ( error ) {
				if ( ! shouldFallbackToAbilityRest( error ) ) {
					throw error;
				}
			}
		}
	}

	return executeAbilityViaRest( abilityName, data, { signal } );
}
