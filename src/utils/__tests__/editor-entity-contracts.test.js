jest.mock( '@wordpress/fields', () => ( {
	authorField: { id: 'author', label: 'Author' },
	excerptField: { id: 'excerpt', label: 'Excerpt' },
	pageTitleField: { id: 'title', label: 'Title' },
	patternTitleField: { id: 'title', label: 'Title' },
	slugField: { id: 'slug', label: 'Slug' },
	statusField: { id: 'status', label: 'Status' },
	stickyField: { id: 'sticky', label: 'Sticky' },
	templateField: { id: 'template', label: 'Template' },
	templateTitleField: { id: 'title', label: 'Template' },
	titleField: { id: 'title', label: 'Title' },
} ) );

jest.mock( '@wordpress/views', () => ( {
	useViewConfig: jest.fn( () => ( {} ) ),
} ) );

import {
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
