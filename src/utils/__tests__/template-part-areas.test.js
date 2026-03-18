import {
	inferTemplatePartArea,
	matchesTemplatePartArea,
} from '../template-part-areas';

describe( 'template-part-areas', () => {
	test( 'inferTemplatePartArea prefers the explicit block area', () => {
		expect(
			inferTemplatePartArea(
				{
					area: 'footer',
					slug: 'footer',
				},
				{
					footer: 'sidebar',
				}
			)
		).toBe( 'footer' );
	} );

	test( 'inferTemplatePartArea falls back to localized slug lookups', () => {
		expect(
			inferTemplatePartArea(
				{
					slug: 'footer',
					tagName: 'footer',
				},
				{
					footer: 'footer',
					'footer-newsletter': 'footer',
				}
			)
		).toBe( 'footer' );
	} );

	test( 'inferTemplatePartArea falls back to structural hints when needed', () => {
		expect(
			inferTemplatePartArea( {
				slug: 'header',
			} )
		).toBe( 'header' );

		expect(
			inferTemplatePartArea( {
				tagName: 'aside',
			} )
		).toBe( 'sidebar' );
	} );

	test( 'matchesTemplatePartArea checks inferred areas on assigned blocks', () => {
		expect(
			matchesTemplatePartArea(
				{
					attributes: {
						slug: 'footer',
						tagName: 'footer',
					},
				},
				'footer',
				{
					footer: 'footer',
				}
			)
		).toBe( true );

		expect(
			matchesTemplatePartArea(
				{
					attributes: {
						slug: 'footer',
					},
				},
				'header',
				{
					footer: 'footer',
				}
			)
		).toBe( false );
	} );
} );
