import {
	DEFAULT_ACTIVITY_VIEW,
	areActivityViewsEqual,
	buildActivityTargetUrl,
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
	test( 'normalizeActivityEntries marks older entries as blocked when newer actions remain applied', () => {
		const olderEntry = createEntry( {
			id: 'activity-older',
			timestamp: '2026-03-26T10:00:00Z',
		} );
		const newerEntry = createEntry( {
			id: 'activity-newer',
			timestamp: '2026-03-26T10:00:01Z',
		} );

		const entries = normalizeActivityEntries( [ olderEntry, newerEntry ] );

		expect( entries[ 0 ].status ).toBe( 'blocked' );
		expect( entries[ 0 ].statusLabel ).toBe( 'Undo blocked' );
		expect( entries[ 1 ].status ).toBe( 'applied' );
		expect( entries[ 1 ].statusLabel ).toBe( 'Applied' );
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
} );
