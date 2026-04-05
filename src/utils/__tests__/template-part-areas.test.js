import {
	inferTemplatePartArea,
	isTemplatePartSlugRegisteredForArea,
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

	test( 'isTemplatePartSlugRegisteredForArea uses the same canonical slug resolver as inference', () => {
		expect(
			isTemplatePartSlugRegisteredForArea( 'header', 'header', {} )
		).toBe( true );
		expect(
			isTemplatePartSlugRegisteredForArea(
				'footer-newsletter',
				'footer',
				{
					'footer-newsletter': 'footer',
				}
			)
		).toBe( true );
		expect(
			isTemplatePartSlugRegisteredForArea( 'footer', 'header', {} )
		).toBe( false );
	} );
} );
