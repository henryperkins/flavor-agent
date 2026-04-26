import { validateTemplatePartOperationSequence } from '../template-operation-sequence';

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
		} );
	} );
} );
