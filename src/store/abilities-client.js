import apiFetch from '@wordpress/api-fetch';

function isPlainObject( value ) {
	return Boolean(
		value && typeof value === 'object' && ! Array.isArray( value )
	);
}

export function buildAbilityRunPath( abilityName ) {
	const encodedAbilityName = String( abilityName )
		.split( '/' )
		.map( encodeURIComponent )
		.join( '/' );

	return `/wp-abilities/v1/abilities/${ encodedAbilityName }/run`;
}

export function normalizeAbilityExecutionResult( result ) {
	if ( ! isPlainObject( result ) ) {
		return result;
	}

	if ( Object.prototype.hasOwnProperty.call( result, 'payload' ) ) {
		return result.payload;
	}

	if ( Object.prototype.hasOwnProperty.call( result, 'result' ) ) {
		return result.result;
	}

	if ( Object.prototype.hasOwnProperty.call( result, 'output' ) ) {
		return result.output;
	}

	return result;
}

function shouldFallbackToRest( error ) {
	const message = typeof error?.message === 'string' ? error.message : '';

	return (
		error?.code === 'ability_not_found' ||
		message.includes( 'Ability not found' )
	);
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

	const bridge =
		typeof window !== 'undefined'
			? window.flavorAgentAbilities?.executeAbility
			: null;

	if ( typeof bridge === 'function' && ! signal ) {
		try {
			return normalizeAbilityExecutionResult(
				await bridge( abilityName, data )
			);
		} catch ( error ) {
			if ( ! shouldFallbackToRest( error ) ) {
				throw error;
			}
		}
	}

	return executeAbilityViaRest( abilityName, data, { signal } );
}
