import {
	buildPostTypeEntityContract,
	getEditedPostTypeEntity,
	getLockedViewOptions,
	getRecommendedPatternCategorySlug,
	normalizeViewConfigContract,
} from '../editor-entity-contracts';

describe( 'editor-entity-contracts', () => {
	test( 'normalizes empty form arrays into a DataForm-compatible object', () => {
		expect(
			normalizeViewConfigContract( {
				default_view: { titleField: 'title' },
				form: [],
			} )
		).toEqual( {
			defaultView: { titleField: 'title' },
			defaultLayouts: {},
			viewList: [],
			form: { fields: [] },
		} );
	} );

	test( 'extracts locked view options for server-defined filters', () => {
		expect(
			getLockedViewOptions(
				[
					{
						title: 'Header',
						slug: 'header',
						view: {
							filters: [
								{
									field: 'area',
									value: 'header',
									isLocked: true,
								},
							],
						},
					},
				],
				'area'
			)
		).toEqual( [
			{
				label: 'Header',
				slug: 'header',
				value: 'header',
			},
		] );
	} );

	test( 'prefers the configured recommended view slug', () => {
		expect(
			getRecommendedPatternCategorySlug( [
				{ title: 'All patterns', slug: 'all-patterns' },
				{ title: 'Recommended', slug: 'editor-picks' },
			] )
		).toBe( 'editor-picks' );
	} );

	test( 'builds a post-type contract from live view config data', () => {
		expect(
			buildPostTypeEntityContract( 'wp_template_part', {
				default_view: { titleField: 'slug' },
				view_list: [
					{
						title: 'Header',
						slug: 'header',
						view: {
							filters: [
								{
									field: 'area',
									value: 'header',
									isLocked: true,
								},
							],
						},
					},
				],
				form: {
					fields: [ { id: 'slug', label: 'Slug' } ],
				},
			} )
		).toMatchObject( {
			postType: 'wp_template_part',
			titleField: {
				id: 'slug',
				label: 'Slug',
			},
			defaultView: {
				titleField: 'slug',
			},
			viewList: [
				expect.objectContaining( {
					slug: 'header',
				} ),
			],
			form: {
				fields: [ { id: 'slug', label: 'Slug' } ],
			},
			templatePartAreaOptions: [
				{
					label: 'Header',
					slug: 'header',
					value: 'header',
				},
			],
		} );
	} );

	test( 'resolves the active entity from core/editor before falling back to core/edit-site', () => {
		const select = ( storeName ) => {
			if ( storeName === 'core/editor' ) {
				return {
					getCurrentPostType: () => 'wp_template',
					getCurrentPostId: () => 'theme//home',
				};
			}

			if ( storeName === 'core/edit-site' ) {
				return {
					getEditedPostType: () => 'wp_template',
					getEditedPostId: () => 'theme//fallback',
				};
			}

			return {};
		};

		expect( getEditedPostTypeEntity( select, 'wp_template' ) ).toEqual( {
			postType: 'wp_template',
			entityId: 'theme//home',
			source: 'core/editor',
		} );
	} );
} );
