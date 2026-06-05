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

export function shouldFallbackToAbilityRest( error ) {
	const message = typeof error?.message === 'string' ? error.message : '';

	return (
		error?.code === 'ability_not_found' ||
		message.includes( 'Ability not found' )
	);
}
