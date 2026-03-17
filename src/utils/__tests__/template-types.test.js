import { KNOWN_TEMPLATE_TYPES, normalizeTemplateType } from '../template-types';

describe( 'template-types', () => {
	test( 'KNOWN_TEMPLATE_TYPES contains expected entries', () => {
		expect( KNOWN_TEMPLATE_TYPES.has( 'single' ) ).toBe( true );
		expect( KNOWN_TEMPLATE_TYPES.has( '404' ) ).toBe( true );
		expect( KNOWN_TEMPLATE_TYPES.has( 'front-page' ) ).toBe( true );
		expect( KNOWN_TEMPLATE_TYPES.has( 'nonexistent' ) ).toBe( false );
	} );

	test( 'normalizeTemplateType returns exact match', () => {
		expect( normalizeTemplateType( 'single' ) ).toBe( 'single' );
		expect( normalizeTemplateType( 'page' ) ).toBe( 'page' );
		expect( normalizeTemplateType( '404' ) ).toBe( '404' );
	} );

	test( 'normalizeTemplateType normalizes compound slugs and canonical refs', () => {
		expect( normalizeTemplateType( 'single-post' ) ).toBe( 'single' );
		expect( normalizeTemplateType( 'archive-product' ) ).toBe( 'archive' );
		expect( normalizeTemplateType( 'twentytwentyfive//single-post' ) ).toBe(
			'single'
		);
		expect( normalizeTemplateType( 'theme//front-page' ) ).toBe(
			'front-page'
		);
	} );

	test( 'normalizeTemplateType returns undefined for unknown values', () => {
		expect( normalizeTemplateType( 'custom-layout' ) ).toBeUndefined();
		expect( normalizeTemplateType( '' ) ).toBeUndefined();
		expect( normalizeTemplateType( undefined ) ).toBeUndefined();
	} );
} );
