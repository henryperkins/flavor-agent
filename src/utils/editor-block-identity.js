import { toHTMLString } from '@wordpress/rich-text';
import { stableSerialize } from './structural-equality';

function normalizeSnapshotValue( value ) {
	if ( value && typeof value === 'object' ) {
		try {
			if ( typeof value.toHTMLString === 'function' ) {
				return value.toHTMLString();
			}
			if (
				typeof value.text === 'string' &&
				Array.isArray( value.formats ) &&
				Array.isArray( value.replacements )
			) {
				return toHTMLString( { value } );
			}
		} catch {}

		if ( Array.isArray( value ) ) {
			return value.map( ( item ) =>
				normalizeSnapshotValue( item === undefined ? null : item )
			);
		}

		return Object.fromEntries(
			Object.entries( value )
				.filter( ( [ , entry ] ) => entry !== undefined )
				.map( ( [ key, entry ] ) => [
					key,
					normalizeSnapshotValue( entry ),
				] )
		);
	}

	return value;
}

function snapshotEditorBlock( block ) {
	return {
		clientId: block?.clientId || '',
		name: block?.name || '',
		attributes: normalizeSnapshotValue( block?.attributes || {} ),
		innerBlocks: Array.isArray( block?.innerBlocks )
			? block.innerBlocks.filter( Boolean ).map( snapshotEditorBlock )
			: [],
	};
}

/**
 * Local editor target proof. Include descendant identities and complete content:
 * equal block names/counts do not identify a reviewed block after a reorder.
 * The opaque signature is compared exactly, never displayed or used as authority.
 *
 * @param {Object} block Live editor block.
 * @return {Object|null} The client identity and normalized subtree signature.
 */
export function getEditorBlockIdentity( block ) {
	if ( typeof block?.clientId !== 'string' || ! block.clientId ) {
		return null;
	}

	return {
		clientId: block.clientId,
		subtreeSignature: stableSerialize( snapshotEditorBlock( block ) ),
	};
}

export function matchesEditorBlockIdentity( block, expectedIdentity ) {
	const identity = getEditorBlockIdentity( block );

	return Boolean(
		identity &&
			typeof expectedIdentity?.clientId === 'string' &&
			typeof expectedIdentity?.subtreeSignature === 'string' &&
			identity.clientId === expectedIdentity.clientId &&
			identity.subtreeSignature === expectedIdentity.subtreeSignature
	);
}
