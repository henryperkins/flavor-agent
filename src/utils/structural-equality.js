export function normalizeComparableValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) => normalizeComparableValue( item ) );
	}

	if ( value && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.entries( value )
				.sort( ( [ leftKey ], [ rightKey ] ) =>
					leftKey.localeCompare( rightKey )
				)
				.map( ( [ key, entryValue ] ) => [
					key,
					normalizeComparableValue( entryValue ),
				] )
		);
	}

	return value;
}

export function stableSerialize( value ) {
	return JSON.stringify( normalizeComparableValue( value ) );
}

export function shallowStructuralEqual( left, right ) {
	if ( left === right ) {
		return true;
	}

	if ( ! left || ! right || typeof left !== 'object' || typeof right !== 'object' ) {
		return false;
	}

	if ( Array.isArray( left ) || Array.isArray( right ) ) {
		if ( ! Array.isArray( left ) || ! Array.isArray( right ) ) {
			return false;
		}

		if ( left.length !== right.length ) {
			return false;
		}

		return left.every( ( value, index ) => value === right[ index ] );
	}

	const leftKeys = Object.keys( left );
	const rightKeys = Object.keys( right );

	if ( leftKeys.length !== rightKeys.length ) {
		return false;
	}

	return leftKeys.every(
		( key ) =>
			Object.prototype.hasOwnProperty.call( right, key ) &&
			left[ key ] === right[ key ]
	);
}

export function deepStructuralEqual( left, right ) {
	if ( left === right ) {
		return true;
	}

	if ( typeof left !== typeof right ) {
		return false;
	}

	if ( Array.isArray( left ) || Array.isArray( right ) ) {
		if ( ! Array.isArray( left ) || ! Array.isArray( right ) ) {
			return false;
		}

		if ( left.length !== right.length ) {
			return false;
		}

		return left.every( ( value, index ) =>
			deepStructuralEqual( value, right[ index ] )
		);
	}

	if ( typeof left !== 'object' || typeof right !== 'object' ) {
		return false;
	}

	const leftKeys = Object.keys( left );
	const rightKeys = Object.keys( right );

	if ( leftKeys.length !== rightKeys.length ) {
		return false;
	}

	return leftKeys.every(
		( key ) =>
			Object.prototype.hasOwnProperty.call( right, key ) &&
			deepStructuralEqual( left[ key ], right[ key ] )
	);
}
