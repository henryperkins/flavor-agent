import {
	validateTemplateOperationSequence,
	validateTemplatePartOperationSequence,
} from '../template-operation-sequence';

describe( 'template operation sequence validation', () => {
	test( 'rejects overlapping template-part target paths before review apply', () => {
		const result = validateTemplatePartOperationSequence( [
			{
				type: 'replace_block_with_pattern',
				patternName: 'theme/header-utility',
				expectedBlockName: 'core/group',
				targetPath: [ 0 ],
			},
			{
				type: 'remove_block',
				expectedBlockName: 'core/site-logo',
				targetPath: [ 0, 0 ],
			},
		] );

		expect( result ).toEqual( {
			ok: false,
			error: 'This suggestion targets overlapping template-part block paths and cannot be applied automatically.',
			code: 'overlapping_block_paths',
		} );
	} );

	test( 'returns too_many_operations when the template-part cap is exceeded', () => {
		const result = validateTemplatePartOperationSequence( [
			{ type: 'insert_pattern', patternName: 'a/b', placement: 'start' },
			{ type: 'insert_pattern', patternName: 'a/c', placement: 'start' },
			{ type: 'insert_pattern', patternName: 'a/d', placement: 'start' },
			{ type: 'insert_pattern', patternName: 'a/e', placement: 'start' },
		] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'too_many_operations' );
	} );

	test( 'returns invalid_placement for an unsupported template-part placement', () => {
		const result = validateTemplatePartOperationSequence( [
			{
				type: 'insert_pattern',
				patternName: 'a/b',
				placement: 'sideways',
			},
		] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'invalid_placement' );
	} );

	test( 'returns invalid_anchor for anchored template-part insert missing targetPath', () => {
		const result = validateTemplatePartOperationSequence( [
			{
				type: 'insert_pattern',
				patternName: 'a/b',
				placement: 'before_block_path',
			},
		] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'invalid_anchor' );
	} );

	test( 'returns unknown_operation_type for an unsupported template-part operation', () => {
		const result = validateTemplatePartOperationSequence( [
			{ type: 'frobnicate' },
		] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'unknown_operation_type' );
	} );

	test( 'returns no_executable_operations for an empty template operation list', () => {
		const result = validateTemplateOperationSequence( [] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'no_executable_operations' );
	} );

	test( 'returns duplicate_area_mutation when a template area is targeted twice', () => {
		const result = validateTemplateOperationSequence( [
			{ type: 'assign_template_part', slug: 'header-a', area: 'header' },
			{ type: 'assign_template_part', slug: 'header-b', area: 'header' },
		] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'duplicate_area_mutation' );
	} );

	test( 'returns unknown_pattern for a template insert missing a pattern name', () => {
		const result = validateTemplateOperationSequence( [
			{ type: 'insert_pattern', placement: 'start' },
		] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'unknown_pattern' );
	} );

	test( 'returns unknown_operation_type for an unsupported template operation', () => {
		const result = validateTemplateOperationSequence( [
			{ type: 'frobnicate' },
		] );

		expect( result.ok ).toBe( false );
		expect( result.code ).toBe( 'unknown_operation_type' );
	} );
} );
