import {
	buildTemplateStructureSnapshot,
	describeEditorBlockLabel,
} from '../editor-context-metadata';

describe( 'editor-context-metadata', () => {
	test( 'humanizes core block names for editor-facing labels', () => {
		expect( describeEditorBlockLabel( 'core/paragraph' ) ).toBe(
			'Paragraph'
		);
	} );

	test( 'humanizes custom namespaced block names without leaking the namespace', () => {
		expect( describeEditorBlockLabel( 'flavor/hero-banner' ) ).toBe(
			'Hero Banner'
		);
	} );

	test( 'builds a shallow nested template structure snapshot', () => {
		expect(
			buildTemplateStructureSnapshot( [
				{
					name: 'core/group',
					innerBlocks: [
						{
							name: 'core/heading',
							innerBlocks: [ { name: 'core/paragraph' } ],
						},
					],
				},
			] )
		).toEqual( [
			{
				name: 'core/group',
				innerBlocks: [ { name: 'core/heading' } ],
			},
		] );
	} );
} );
