import {
	createActivityEntry,
	getCurrentActivityScope,
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	readPersistedActivityLog,
	writePersistedActivityLog,
} from '../activity-history';

describe( 'activity history helpers', () => {
	beforeEach( () => {
		window.sessionStorage.clear();
	} );

	test( 'getCurrentActivityScope resolves the current edited entity from registry selectors', () => {
		expect(
			getCurrentActivityScope( {
				select: ( storeName ) =>
					storeName === 'core/editor'
						? {
								getCurrentPostType: () => 'post',
								getCurrentPostId: () => 42,
						  }
						: {},
			} )
		).toEqual( {
			key: 'post:42',
			postType: 'post',
			entityId: '42',
		} );
	} );

	test( 'persisted activity history is scoped per document', () => {
		const entry = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			suggestion: 'Refresh content',
			target: {
				clientId: 'block-1',
			},
		} );

		writePersistedActivityLog( 'post:42', [ entry ] );

		expect( readPersistedActivityLog( 'post:42' ) ).toEqual( [ entry ] );
		expect( readPersistedActivityLog( 'post:99' ) ).toEqual( [] );
	} );

	test( 'legacy persisted template activity entries load as non-undoable', () => {
		window.sessionStorage.setItem(
			'flavor-agent:activity:wp_template:home',
			JSON.stringify( {
				version: 1,
				updatedAt: '2026-03-23T00:00:00Z',
				entries: [
					{
						id: 'activity-1',
						surface: 'template',
						type: 'apply_template_suggestion',
						timestamp: '2026-03-23T00:00:00Z',
						after: {
							operations: [
								{
									type: 'insert_pattern',
									patternName: 'theme/hero',
									insertedClientIds: [ 'legacy-1' ],
								},
							],
						},
						undo: {
							canUndo: true,
							status: 'available',
							error: null,
							updatedAt: '2026-03-23T00:00:00Z',
							undoneAt: null,
						},
					},
				],
			} )
		);

		expect( readPersistedActivityLog( 'wp_template:home' ) ).toEqual( [
			expect.objectContaining( {
				id: 'activity-1',
				undo: expect.objectContaining( {
					canUndo: false,
					status: 'failed',
					error: expect.stringContaining(
						'before refresh-safe undo support'
					),
				} ),
			} ),
		] );
	} );

	test( 'latest activity selectors keep strict stack order for undo', () => {
		const applied = createActivityEntry( {
			type: 'apply_suggestion',
			surface: 'block',
			suggestion: 'Refresh content',
			target: {
				clientId: 'block-1',
			},
		} );
		const undone = {
			...createActivityEntry( {
				type: 'apply_template_suggestion',
				surface: 'template',
				suggestion: 'Clarify hierarchy',
				target: {
					templateRef: 'theme//home',
				},
			} ),
			undo: {
				canUndo: true,
				status: 'undone',
				error: null,
				updatedAt: '2026-03-23T00:00:00Z',
				undoneAt: '2026-03-23T00:00:00Z',
			},
		};

		expect( getLatestAppliedActivity( [ applied, undone ] ) ).toEqual(
			applied
		);
		expect( getLatestUndoableActivity( [ applied, undone ] ) ).toEqual(
			applied
		);
	} );
} );
