import {
	buildPostTypeEntityContract,
	getEditedPostTypeEntity,
	getLockedViewOptions,
	getRecommendedPatternCategorySlug,
	normalizeEditedEntityRef,
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

	test( 'trims edited entity refs before accepting them', () => {
		expect( normalizeEditedEntityRef( ' theme//home ' ) ).toBe(
			'theme//home'
		);
		expect( normalizeEditedEntityRef( '   ' ) ).toBeNull();
	} );

	test( 'does not report fallback-only unknown post types as configured', () => {
		expect(
			buildPostTypeEntityContract( 'unknown_type', {} )
		).toMatchObject( {
			postType: 'unknown_type',
			hasConfig: false,
			titleField: {
				id: 'title',
				label: 'Title',
			},
		} );
	} );

	test( 'treats unknown post types with live view config as configured', () => {
		expect(
			buildPostTypeEntityContract( 'unknown_type', {
				default_view: { titleField: 'headline' },
				form: {
					fields: [ { id: 'headline', label: 'Headline' } ],
				},
			} )
		).toMatchObject( {
			postType: 'unknown_type',
			hasConfig: true,
			defaultView: {
				titleField: 'headline',
			},
		} );
	} );

	test( 'resolves the active non-site-editor entity from core/editor before falling back to core/edit-site', () => {
		const select = ( storeName ) => {
			if ( storeName === 'core/editor' ) {
				return {
					getCurrentPostType: () => 'post',
					getCurrentPostId: () => 42,
				};
			}

			if ( storeName === 'core/edit-site' ) {
				return {
					getEditedPostType: () => 'page',
					getEditedPostId: () => 7,
				};
			}

			return {};
		};

		expect( getEditedPostTypeEntity( select, 'post' ) ).toEqual( {
			postType: 'post',
			entityId: '42',
			source: 'core/editor',
		} );
	} );

	test( 'prefers the canonical Site Editor template ref when both stores expose the same template entity', () => {
		const select = ( storeName ) => {
			if ( storeName === 'core/editor' ) {
				return {
					getCurrentPostType: () => 'wp_template',
					getCurrentPostId: () => 42,
				};
			}

			if ( storeName === 'core/edit-site' ) {
				return {
					getEditedPostType: () => 'wp_template',
					getEditedPostId: () => 'theme//home',
				};
			}

			return {};
		};

		expect( getEditedPostTypeEntity( select, 'wp_template' ) ).toEqual( {
			postType: 'wp_template',
			entityId: 'theme//home',
			source: 'core/edit-site',
		} );
	} );

	test( 'does not use a stale Site Editor template ref when the active editor entity is a different post type', () => {
		const select = ( storeName ) => {
			if ( storeName === 'core/editor' ) {
				return {
					getCurrentPostType: () => 'page',
					getCurrentPostId: () => 42,
				};
			}

			if ( storeName === 'core/edit-site' ) {
				return {
					getEditedPostType: () => 'wp_template',
					getEditedPostId: () => 'theme//home',
				};
			}

			return {};
		};

		expect( getEditedPostTypeEntity( select, 'wp_template' ) ).toBeNull();
	} );
} );
