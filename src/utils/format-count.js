export function formatCount( count, noun ) {
	if ( ! Number.isFinite( count ) || count < 0 || ! noun ) {
		return '';
	}

	return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}

export function humanizeString( value ) {
	return String( value || '' )
		.split( /[-_/|\s]+/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

export function joinClassNames( ...values ) {
	return values.filter( Boolean ).join( ' ' );
}
