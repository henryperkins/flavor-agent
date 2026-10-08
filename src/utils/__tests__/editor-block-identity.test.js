import { create } from '@wordpress/rich-text';
import {
	getEditorBlockIdentity,
	matchesEditorBlockIdentity,
} from '../editor-block-identity';

function paragraph( clientId, content ) {
	return {
		clientId,
		name: 'core/paragraph',
		attributes: { content },
		innerBlocks: [],
	};
}

describe( 'editor block identity', () => {
	test( 'retains plain empty-text objects inside nested attributes and arrays', () => {
		const block = paragraph( 'paragraph', 'Reviewed content' );
		block.attributes.custom = {
			text: '',
			nested: [ { text: '', formats: [], label: 'Keep this object' } ],
		};

		const snapshot = JSON.parse(
			getEditorBlockIdentity( block ).subtreeSignature
		);

		expect( snapshot.attributes.custom ).toEqual( block.attributes.custom );
	} );

	test( 'normalizes RichText content and keeps the complete descendant subtree', () => {
		const block = {
			clientId: 'group',
			name: 'core/group',
			attributes: { layout: { type: 'constrained' } },
			innerBlocks: [
				paragraph(
					'child',
					create( { html: '<strong>Legal notice</strong>' } )
				),
			],
		};
		const identity = getEditorBlockIdentity( block );

		expect(
			JSON.parse( identity.subtreeSignature ).innerBlocks[ 0 ].attributes
				.content
		).toBe( '<strong>Legal notice</strong>' );
		expect( matchesEditorBlockIdentity( block, identity ) ).toBe( true );
		block.innerBlocks[ 0 ].attributes.content = create( {
			html: '<strong>Changed legal notice</strong>',
		} );
		expect( matchesEditorBlockIdentity( block, identity ) ).toBe( false );
	} );

	test( 'rejects same-content replacement identities and malformed proof', () => {
		const identity = getEditorBlockIdentity(
			paragraph( 'original', 'Same' )
		);

		expect(
			matchesEditorBlockIdentity(
				paragraph( 'replacement', 'Same' ),
				identity
			)
		).toBe( false );
		expect(
			matchesEditorBlockIdentity( paragraph( 'original', 'Same' ), {} )
		).toBe( false );
		expect(
			matchesEditorBlockIdentity( paragraph( 'original', 'Same' ), null )
		).toBe( false );
	} );
} );
