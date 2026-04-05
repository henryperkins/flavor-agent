import { describeEditorBlockLabel } from '../editor-context-metadata';

describe( 'editor-context-metadata', () => {
	test( 'humanizes custom namespaced block names without leaking the namespace', () => {
		expect( describeEditorBlockLabel( 'flavor/hero-banner' ) ).toBe(
			'Hero Banner'
		);
	} );
} );
