import {
	clampActivityViewPage,
	DEFAULT_ACTIVITY_VIEW,
	areActivityViewsEqual,
	buildActivityTargetLink,
	buildActivityTargetUrl,
	formatActivityTimestamp,
	normalizeActivityEntries,
	readPersistedActivityView,
	writePersistedActivityView,
} from '../activity-log-utils';

function createEntry( overrides = {} ) {
	return {
		id: 'activity-1',
		surface: 'block',
		timestamp: '2026-03-26T10:00:00Z',
		target: {
			blockName: 'core/paragraph',
			blockPath: [ 0 ],
		},
		document: {
			scopeKey: 'post:42',
			postType: 'post',
			entityId: '42',
		},
		undo: {
			status: 'available',
			canUndo: true,
			error: null,
			updatedAt: '2026-03-26T10:00:00Z',
			undoneAt: null,
		},
		persistence: {
			status: 'server',
		},
		request: {},
		before: {},
		after: {},
		...overrides,
	};
}

function createStorage() {
	const values = new Map();

	return {
		getItem( key ) {
			return values.has( key ) ? values.get( key ) : null;
		},
		setItem( key, value ) {
			values.set( key, value );
		},
	};
}

describe( 'activity log utils', () => {
	test( 'normalizeActivityEntries preserves server-resolved blocked status for admin rows', () => {
		const olderEntry = createEntry( {
			id: 'activity-older',
			timestamp: '2026-03-26T10:00:00Z',
			status: 'blocked',
		} );
		const newerEntry = createEntry( {
			id: 'activity-newer',
			timestamp: '2026-03-26T10:00:01Z',
			status: 'applied',
		} );

		const entries = normalizeActivityEntries( [ olderEntry, newerEntry ] );

		expect( entries[ 0 ].status ).toBe( 'blocked' );
		expect( entries[ 0 ].statusLabel ).toBe( 'Undo blocked' );
		expect( entries[ 1 ].status ).toBe( 'applied' );
		expect( entries[ 1 ].statusLabel ).toBe( 'Applied' );
	} );

	test( 'normalizeActivityEntries derives operation, document, path, and diff metadata', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				before: {
					attributes: {
						content: 'Before copy',
					},
				},
				after: {
					attributes: {
						content: 'After copy',
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'modify-attributes',
			operationTypeLabel: 'Modify attributes',
			postType: 'post',
			entityId: '42',
			blockPath: 'Paragraph · 1',
		} );
		expect( entries[ 0 ].stateDiff ).toContain(
			'~ content: Before copy → After copy'
		);
	} );

	test( 'normalizeActivityEntries does not misclassify unchanged structured style payloads as style edits', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				before: {
					attributes: {
						style: {
							color: {
								text: 'red',
							},
						},
						content: 'Before copy',
					},
				},
				after: {
					attributes: {
						style: {
							color: {
								text: 'red',
							},
						},
						content: 'After copy',
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'modify-attributes',
			operationTypeLabel: 'Modify attributes',
		} );
		expect( entries[ 0 ].stateDiff ).toContain(
			'~ content: Before copy → After copy'
		);
	} );

	test( 'normalizeActivityEntries still classifies nested style changes as apply style', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				before: {
					attributes: {
						style: {
							color: {
								text: 'red',
							},
						},
					},
				},
				after: {
					attributes: {
						style: {
							color: {
								text: 'blue',
							},
						},
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'apply-style',
			operationTypeLabel: 'Apply style',
		} );
		expect( entries[ 0 ].stateDiff ).toContain(
			'~ style.color.text: red → blue'
		);
	} );

	test( 'normalizeActivityEntries maps template operations to normalized action types', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				type: 'apply_template_suggestion',
				surface: 'template',
				target: {
					templateRef: 'theme//home',
				},
				document: {
					scopeKey: 'wp_template:theme//home',
					postType: 'wp_template',
					entityId: 'theme//home',
				},
				after: {
					operations: [
						{
							type: 'insert_pattern',
							patternName: 'theme/hero',
						},
					],
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'insert',
			operationTypeLabel: 'Insert pattern',
			activityTypeLabel: 'Apply template suggestion',
			postType: 'wp_template',
			entityId: 'theme//home',
		} );
	} );

	test( 'buildActivityTargetUrl returns site editor and post editor links', () => {
		const templateUrl = buildActivityTargetUrl(
			createEntry( {
				surface: 'template',
				target: {
					templateRef: 'theme//home',
				},
				document: {
					scopeKey: 'wp_template:theme//home',
					postType: 'wp_template',
					entityId: 'theme//home',
				},
			} ),
			'https://example.test/wp-admin/'
		);
		const postUrl = buildActivityTargetUrl(
			createEntry(),
			'https://example.test/wp-admin/'
		);

		expect( templateUrl ).toContain( '/wp-admin/site-editor.php?' );
		expect( templateUrl ).toContain( 'postType=wp_template' );
		expect( templateUrl ).toContain( 'postId=theme%2F%2Fhome' );
		expect( postUrl ).toBe(
			'https://example.test/wp-admin/post.php?post=42&action=edit'
		);
	} );

	test( 'formatActivityTimestamp uses the same timezone basis for grouping and display', () => {
		const formatted = formatActivityTimestamp( '2026-03-27T00:30:00Z', {
			locale: 'en-US',
			timeZone: 'America/Los_Angeles',
		} );

		expect( formatted.dayKey ).toBe( '2026-03-26' );
		expect( formatted.timestampDisplay ).toContain( 'Mar 26' );
	} );

	test( 'buildActivityTargetLink uses honest styles labels for style surfaces', () => {
		const globalStylesLink = buildActivityTargetLink(
			createEntry( {
				surface: 'global-styles',
				target: {
					globalStylesId: '17',
				},
				document: {
					scopeKey: 'global_styles:17',
					postType: 'global_styles',
					entityId: '17',
				},
			} ),
			'https://example.test/wp-admin/'
		);
		const styleBookLink = buildActivityTargetLink(
			createEntry( {
				surface: 'style-book',
				target: {
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				document: {
					scopeKey: 'style_book:17:core/paragraph',
					postType: 'global_styles',
					entityId: '17',
				},
			} ),
			'https://example.test/wp-admin/'
		);

		expect( globalStylesLink.label ).toBe( 'Open Styles' );
		expect( globalStylesLink.url ).toContain( '/wp-admin/site-editor.php?' );
		expect( styleBookLink.label ).toBe( 'Open Styles' );
		expect( styleBookLink.url ).toContain( '/wp-admin/site-editor.php?' );
	} );

	test( 'normalizeActivityEntries labels Style Book activity against the selected block target', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				surface: 'style-book',
				target: {
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				document: {
					scopeKey: 'style_book:17:core/paragraph',
					postType: 'global_styles',
					entityId: '17',
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			surfaceLabel: 'Style Book',
		} );
		expect( entries[ 0 ].description ).toContain( 'Style Book' );
	} );

	test( 'persisted views round-trip through storage', () => {
		const storage = createStorage();
		const nextView = {
			...DEFAULT_ACTIVITY_VIEW,
			search: 'template',
			perPage: 50,
			groupBy: {
				field: 'surface',
				direction: 'asc',
				showLabel: true,
			},
		};

		writePersistedActivityView( nextView, storage );

		expect(
			areActivityViewsEqual(
				readPersistedActivityView( storage ),
				nextView
			)
		).toBe( true );
	} );

	test( 'clampActivityViewPage keeps pagination in range', () => {
		expect(
			clampActivityViewPage(
				{
					...DEFAULT_ACTIVITY_VIEW,
					page: 5,
				},
				{
					totalPages: 2,
				}
			).page
		).toBe( 2 );
		expect(
			clampActivityViewPage(
				{
					...DEFAULT_ACTIVITY_VIEW,
					page: 3,
				},
				{
					totalPages: 0,
				}
			).page
		).toBe( 1 );
		expect(
			clampActivityViewPage(
				{
					...DEFAULT_ACTIVITY_VIEW,
					page: 2,
				},
				{
					totalPages: 4,
				}
			).page
		).toBe( 2 );
		expect(
			clampActivityViewPage( {
				...DEFAULT_ACTIVITY_VIEW,
				page: 4,
			} ).page
		).toBe( 1 );
	} );
} );
