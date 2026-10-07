const UINT32_MODULO = 4294967296;
const MAX_STRING_LENGTH = 191;

function cleanString( value, maxLength = MAX_STRING_LENGTH ) {
	if ( value === null || value === undefined ) {
		return '';
	}

	if (
		typeof value !== 'string' &&
		typeof value !== 'number' &&
		typeof value !== 'boolean'
	) {
		return '';
	}

	return String( value ).trim().slice( 0, maxLength );
}

function stableStringify( value ) {
	if ( value === null || value === undefined ) {
		return '';
	}

	if ( Array.isArray( value ) ) {
		return `[${ value.map( stableStringify ).join( ',' ) }]`;
	}

	if ( typeof value === 'object' ) {
		return `{${ Object.keys( value )
			.sort()
			.map( ( key ) => `${ key }:${ stableStringify( value[ key ] ) }` )
			.join( ',' ) }}`;
	}

	return String( value );
}

export function hashOutcomeValue( value ) {
	const text = stableStringify( value );
	let hash = 5381;

	for ( let index = 0; index < text.length; index++ ) {
		hash =
			( Math.imul( hash, 33 ) + text.charCodeAt( index ) ) %
			UINT32_MODULO;
		hash = hash < 0 ? hash + UINT32_MODULO : hash;
	}

	return `hash_${ hash.toString( 36 ) }`;
}

export function normalizeSourceRequestSignature( value ) {
	if ( value && typeof value === 'object' ) {
		return hashOutcomeValue( value );
	}

	const normalized = cleanString( value );

	if ( ! normalized ) {
		return '';
	}

	return normalized.startsWith( 'hash_' )
		? normalized
		: hashOutcomeValue( normalized );
}
