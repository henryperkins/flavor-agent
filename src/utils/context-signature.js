import { stableSerialize } from './structural-equality';

export function buildContextSignature( scopeOrInputs = {}, maybeInputs ) {
	if ( maybeInputs !== undefined ) {
		const scope =
			typeof scopeOrInputs === 'string' ? scopeOrInputs.trim() : '';

		return stableSerialize( {
			...( scope ? { scope } : {} ),
			...( maybeInputs && typeof maybeInputs === 'object' ? maybeInputs : {} ),
		} );
	}

	return stableSerialize(
		scopeOrInputs && typeof scopeOrInputs === 'object' ? scopeOrInputs : {}
	);
}
