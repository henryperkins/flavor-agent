import {
	createSavePersistenceController,
	getSavePersistenceStorageKey,
	matchEntitySaveRequest,
	installSavePersistenceObserver,
} from '../save-persistence';
import apiFetch from '@wordpress/api-fetch';

const SITE = 'https://example.test/wp-json/';
const configs = [
	{ kind: 'postType', name: 'post', baseURL: '/wp/v2/posts' },
	{ kind: 'postType', name: 'page', baseURL: '/wp/v2/pages' },
	{ kind: 'postType', name: 'book', baseURL: '/library/v1/books' },
	{ kind: 'postType', name: 'wp_template', baseURL: '/wp/v2/templates' },
	{
		kind: 'postType',
		name: 'wp_template_part',
		baseURL: '/wp/v2/template-parts',
	},
	{ kind: 'root', name: 'globalStyles', baseURL: '/wp/v2/global-styles' },
];
const core = {
	getEntitiesConfig: ( kind ) =>
		configs.filter( ( item ) => item.kind === kind ),
	getEntityConfig: ( kind, name ) =>
		configs.find( ( item ) => item.kind === kind && item.name === name ),
	isAutosavingEntityRecord: () => false,
};

function apply(
	id,
	surface = 'block',
	postType = 'post',
	entityId = '42',
	target = {}
) {
	return {
		id,
		type: 'apply_suggestion',
		applyLane: 'editor-state',
		surface,
		document: {
			scopeKey: `${ postType }:${ entityId }`,
			postType,
			entityId,
		},
		target,
	};
}

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} );
	return { promise, resolve, reject };
}

describe( 'physical entity save matching', () => {
	test( 'registers exactly one middleware across editor bootstrap remounts', () => {
		const use = jest
			.spyOn( apiFetch, 'use' )
			.mockImplementation( () => {} );
		const first = installSavePersistenceObserver();
		first();
		const second = installSavePersistenceObserver();
		second();
		expect( use ).toHaveBeenCalledTimes( 1 );
		use.mockRestore();
	} );
	test.each( [
		[ '/wp/v2/posts/42?context=edit', 'post', '42' ],
		[ '/library/v1/books/8', 'book', '8' ],
		[ '/wp/v2/templates/theme//home', 'wp_template', 'theme//home' ],
		[
			'/wp/v2/template-parts/theme%2F%2Fheader',
			'wp_template_part',
			'theme//header',
		],
		[ '/wp/v2/global-styles/17', 'wp_global_styles', '17' ],
	] )( 'matches configured entity route %s', ( path, postType, id ) => {
		expect(
			matchEntitySaveRequest(
				{ path, method: 'POST', data: {} },
				core,
				SITE
			)
		).toMatchObject( { postType, id } );
	} );

	test.each( [
		{ path: '/wp/v2/posts/42' },
		{ path: '/wp/v2/posts/42/autosaves', method: 'POST' },
		{ path: '/wp/v2/posts/42/revisions/9', method: 'POST' },
		{ path: '/wp/v2/posts/42?preview=true', method: 'POST' },
		{
			path: '/wp/v2/posts/42?wp_theme_preview=other-theme',
			method: 'POST',
		},
		{ path: '/wp/v2/posts/42', method: 'POST', data: { preview: true } },
		{ path: '/wp/v2/posts/42', method: 'POST', data: { status: 'trash' } },
		{ path: '/wp/v2/posts/42', method: 'DELETE' },
		{ path: '/flavor-agent/v1/activity', method: 'POST' },
		{ path: '/batch/v1', method: 'POST' },
		{ path: '/wp/v2/users/42', method: 'POST' },
		{ path: '/wp/v2/posts/42/arbitrary', method: 'POST' },
		{ url: 'https://other.test/wp-json/wp/v2/posts/42', method: 'POST' },
		{
			url: 'https://example.test/unrelated/wp/v2/posts/42',
			method: 'POST',
		},
	] )( 'ignores non-save request %j', ( options ) => {
		expect( matchEntitySaveRequest( options, core, SITE ) ).toBeNull();
	} );

	test( 'matches absolute REST URLs and excludes core-data autosaves', () => {
		const options = {
			url: `${ SITE }wp/v2/posts/42`,
			method: 'PUT',
			data: {},
		};
		expect( matchEntitySaveRequest( options, core, SITE )?.id ).toBe(
			'42'
		);
		expect(
			matchEntitySaveRequest(
				options,
				{ ...core, isAutosavingEntityRecord: () => true },
				SITE
			)
		).toBeNull();
	} );
} );

describe( 'save occurrence controller', () => {
	let controllers;
	let requests;
	let transport;
	let sequence;

	function controller( options = {} ) {
		const instance = createSavePersistenceController( {
			apiFetch: ( request ) => {
				requests.push( request );
				return transport( request );
			},
			getCoreData: () => core,
			storage: window.sessionStorage,
			restUrl: SITE,
			currentUserId: 7,
			createOccurrenceId: () => `save-${ ++sequence }`,
			...options,
		} );
		controllers.push( instance );
		return instance;
	}

	const save = ( instance, promise, path = '/wp/v2/posts/42' ) =>
		instance.middleware(
			{ path, method: 'POST', data: { content: 'Saved bytes' } },
			() => promise
		);
	const events = () =>
		requests
			.filter( ( item ) => item.method === 'POST' )
			.map( ( item ) => item.data.entry );

	beforeEach( () => {
		jest.useFakeTimers();
		window.sessionStorage.clear();
		controllers = [];
		requests = [];
		sequence = 0;
		transport = () => Promise.resolve( { entries: [], pending: false } );
	} );

	afterEach( () => {
		controllers.forEach( ( instance ) => instance.dispose() );
		jest.useRealTimers();
	} );

	test( 'adds only a fresh header and returns the exact WordPress promise without local candidates', () => {
		const instance = controller();
		const pending = deferred();
		const options = {
			path: '/wp/v2/posts/42',
			method: 'POST',
			headers: { 'X-WP-Nonce': 'nonce' },
			data: { content: 'Exact bytes' },
			parse: false,
		};
		const forwarded = [];
		const next = ( value ) => {
			forwarded.push( value );
			return pending.promise;
		};
		expect( instance.middleware( options, next ) ).toBe( pending.promise );
		expect( instance.middleware( options, next ) ).toBe( pending.promise );
		expect( forwarded[ 0 ] ).toEqual( {
			...options,
			headers: {
				'X-WP-Nonce': 'nonce',
				'X-Flavor-Agent-Save-Occurrence': 'save-1',
			},
		} );
		expect(
			forwarded[ 1 ].headers[ 'X-Flavor-Agent-Save-Occurrence' ]
		).toBe( 'save-2' );
		expect( forwarded[ 0 ].data ).toBe( options.data );
		expect( options.headers ).toEqual( { 'X-WP-Nonce': 'nonce' } );
		expect( requests ).toEqual( [] );
	} );

	test( 'still generates a correlation UUID when randomUUID is unavailable on an HTTP editor', () => {
		const descriptor = Object.getOwnPropertyDescriptor(
			globalThis.crypto,
			'randomUUID'
		);
		Object.defineProperty( globalThis.crypto, 'randomUUID', {
			configurable: true,
			value: undefined,
		} );
		try {
			const instance = controller( { createOccurrenceId: undefined } );
			let headers;
			instance.middleware(
				{ path: '/wp/v2/posts/42', method: 'POST' },
				( request ) => {
					headers = request.headers;
					return deferred().promise;
				}
			);
			expect( headers?.[ 'X-Flavor-Agent-Save-Occurrence' ] ).toMatch(
				/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
			);
		} finally {
			if ( descriptor ) {
				Object.defineProperty(
					globalThis.crypto,
					'randomUUID',
					descriptor
				);
			} else {
				delete globalThis.crypto.randomUUID;
			}
		}
	} );

	test( 'never resubmits an ignored request when its downstream handler throws synchronously', () => {
		const instance = controller();
		let submissions = 0;
		const error = new Error( 'Synchronous request failure' );
		expect( () =>
			instance.middleware(
				{ path: '/flavor-agent/v1/activity', method: 'POST' },
				() => {
					submissions += 1;
					throw error;
				}
			)
		).toThrow( error );
		expect( submissions ).toBe( 1 );
	} );

	test( 'replaces an existing occurrence header regardless of casing', () => {
		const instance = controller();
		const options = {
			path: '/wp/v2/posts/42',
			method: 'POST',
			headers: {
				'x-flavor-agent-save-occurrence': 'old',
				'X-WP-Nonce': 'nonce',
			},
		};
		let headers;
		instance.middleware( options, ( request ) => {
			headers = request.headers;
			return deferred().promise;
		} );
		expect( headers ).toEqual( {
			'X-WP-Nonce': 'nonce',
			'X-Flavor-Agent-Save-Occurrence': 'save-1',
		} );
		expect( options.headers[ 'x-flavor-agent-save-occurrence' ] ).toBe(
			'old'
		);
	} );

	test( 'finishes the WordPress promise while telemetry is still unresolved', async () => {
		const instance = controller();
		instance.registerCandidate( apply( 'original' ) );
		const telemetry = deferred();
		transport = () => telemetry.promise;
		const response = { id: 42, content: { raw: 'Stored' } };
		const promise = Promise.resolve( response );
		const observed = save( instance, promise );
		const delivery = instance.flush();
		expect( observed ).toBe( promise );
		await expect( observed ).resolves.toBe( response );
		telemetry.resolve( { entries: [], pending: false } );
		await delivery;
	} );

	test( 'assigns separate occurrences to a multi-entity save and reports only the failed entity', async () => {
		const instance = controller();
		instance.registerCandidate(
			apply( 'template', 'template', 'wp_template', 'theme//home', {
				templateRef: 'theme//home',
			} )
		);
		instance.registerCandidate(
			apply(
				'part',
				'template-part',
				'wp_template_part',
				'theme//header',
				{ templatePartRef: 'theme//header' }
			)
		);
		const template = deferred();
		const part = deferred();
		save( instance, template.promise, '/wp/v2/templates/theme//home' );
		save( instance, part.promise, '/wp/v2/template-parts/theme//header' );
		template.resolve( {} );
		part.reject( new Error( 'Request lost' ) );
		await expect( part.promise ).rejects.toThrow( 'Request lost' );
		await instance.flush();
		expect(
			events().map( ( entry ) => [
				entry.linkedApplyActivityId,
				entry.saveOccurrenceId,
				entry.after.outcome.event,
			] )
		).toEqual( [
			[ 'template', 'save-1', 'save_attempted' ],
			[ 'part', 'save-2', 'save_attempted' ],
			[ 'part', 'save-2', 'save_failed' ],
		] );
	} );

	test( 'keeps an inconclusive server comparison eligible for a later save attempt', async () => {
		const instance = controller();
		instance.registerCandidate( apply( 'original' ) );
		instance.cacheServerEntries( [
			{
				id: 'original',
				persistenceVerdict: {
					state: 'save_unverifiable',
					saveSequence: 1,
					label: 'Could not verify',
				},
			},
		] );
		save( instance, deferred().promise );
		await instance.flush();
		expect(
			events().map( ( entry ) => entry.linkedApplyActivityId )
		).toEqual( [ 'original' ] );
	} );

	test( 'refreshes missing coverage after the original apply reaches storage on a telemetry retry', async () => {
		const instance = controller();
		instance.registerCandidate( apply( 'original' ) );
		transport = ( request ) =>
			request.method === 'POST'
				? Promise.reject( { data: { status: 409 } } )
				: Promise.resolve( { entries: [], pending: false } );
		save( instance, Promise.resolve( { id: 42 } ) );
		await Promise.resolve();
		await instance.flush();
		transport = ( request ) =>
			Promise.resolve(
				request.method === 'POST'
					? {}
					: {
							entries: [
								{
									id: 'original',
									verificationCoverage: {
										state: 'not_verified',
										eligible: true,
										compared: false,
										conclusive: false,
										label: 'Not verified',
									},
								},
							],
							pending: false,
					  }
			);
		await jest.advanceTimersByTimeAsync( 1000 );
		await instance.flush();
		expect(
			instance.getAssurance( 'original' ).verificationCoverage?.state
		).toBe( 'not_verified' );
	} );

	test( 'freezes per-entity attribution, shares Global Styles/Style Book occurrences and ignores later applies', async () => {
		const instance = controller();
		const pending = deferred();
		instance.registerCandidate( apply( 'post' ) );
		instance.registerCandidate(
			apply( 'styles', 'global-styles', 'global_styles', '17', {
				globalStylesId: 17,
			} )
		);
		instance.registerCandidate(
			apply( 'book', 'style-book', 'global_styles', '17', {
				globalStylesId: 17,
			} )
		);
		instance.registerCandidate(
			apply( 'template', 'template', 'wp_template', 'theme//home', {
				templateRef: 'theme//home',
			} )
		);
		save( instance, pending.promise, '/wp/v2/global-styles/17' );
		instance.registerCandidate(
			apply( 'late', 'global-styles', 'global_styles', '17', {
				globalStylesId: 17,
			} )
		);
		pending.reject( new Error( 'Private server exception' ) );
		await expect( pending.promise ).rejects.toThrow(
			'Private server exception'
		);
		await instance.flush();
		expect(
			events().map( ( entry ) => [
				entry.linkedApplyActivityId,
				entry.saveOccurrenceId,
				entry.after.outcome.event,
			] )
		).toEqual( [
			[ 'styles', 'save-1', 'save_attempted' ],
			[ 'book', 'save-1', 'save_attempted' ],
			[ 'styles', 'save-1', 'save_failed' ],
			[ 'book', 'save-1', 'save_failed' ],
		] );
		expect( JSON.stringify( events() ) ).not.toContain(
			'Private server exception'
		);
		expect( events().every( ( entry ) => ! entry.id ) ).toBe( true );
	} );

	test( 'keeps candidates beyond activity-page limits and scope changes', async () => {
		const instance = controller();
		for ( let index = 0; index < 90; index++ ) {
			instance.registerCandidate( apply( `apply-${ index }` ) );
		}
		instance.registerCandidate(
			apply(
				'other-scope',
				'template-part',
				'wp_template_part',
				'theme//header',
				{ templatePartRef: 'theme//header' }
			)
		);
		save( instance, deferred().promise );
		await instance.flush();
		expect( events() ).toHaveLength( 90 );
		expect( events()[ 0 ].linkedApplyActivityId ).toBe( 'apply-0' );
		expect(
			events().some(
				( entry ) => entry.linkedApplyActivityId === 'other-scope'
			)
		).toBe( false );
	} );

	test.each( [ true, false ] )(
		'reconciles after reload during an in-flight save when attempt was already delivered: %s',
		async ( delivered ) => {
			const first = controller();
			first.registerCandidate( apply( 'original' ) );
			const pending = deferred();
			save( first, pending.promise );
			if ( delivered ) {
				await first.flush();
			}
			first.dispose();
			const reloaded = controller();
			await reloaded.flush();
			// The server has not captured the still-running save, so its first
			// read says pending:false and unknown. That must not finish polling.
			expect( reloaded.getAssurance( 'original' ) ).toEqual( {} );
			transport = ( request ) =>
				Promise.resolve(
					request.method === 'POST'
						? {}
						: {
								entries: [
									{
										id: 'original',
										persistenceVerdict: {
											state: 'save_confirmed',
											saveSequence: 3,
											saveOccurrenceId: 'save-1',
											label: 'Persisted',
										},
									},
								],
								pending: false,
						  }
				);
			pending.resolve( { id: 42 } );
			await pending.promise;
			await jest.advanceTimersByTimeAsync( 1000 );
			await reloaded.flush();
			expect(
				reloaded.getAssurance( 'original' ).persistenceVerdict
			).toMatchObject( {
				state: 'save_confirmed',
				saveOccurrenceId: 'save-1',
			} );
			expect(
				events().map( ( entry ) => entry.after.outcome.event )
			).toEqual( [ 'save_attempted' ] );
			const before = requests.length;
			await jest.runAllTimersAsync();
			expect( requests ).toHaveLength( before );
		}
	);

	test( 'bounds reconciliation when a lost save response never produces a server capture', async () => {
		const first = controller();
		first.registerCandidate( apply( 'original' ) );
		save( first, deferred().promise );
		await first.flush();
		first.dispose();
		const reloaded = controller();
		await jest.runAllTimersAsync();
		expect(
			requests.filter( ( request ) => request.method === 'GET' )
		).toHaveLength( 6 );
		expect( reloaded.getAssurance( 'original' ) ).toEqual( {} );
	} );

	test( 'persists deduplicated attempts for retry after reload, including a missing original apply', async () => {
		const first = controller();
		const entry = apply( 'original' );
		const observedAt = new Date().toISOString();
		first.registerCandidate( entry );
		first.registerCandidate( entry );
		transport = () =>
			Promise.reject( {
				data: { status: 409 },
				message: 'Not stored yet',
			} );
		save( first, deferred().promise );
		await first.flush();
		expect( events()[ 0 ].timestamp ).toBe( observedAt );
		expect( events()[ 0 ].after.outcome.observedAt ).toBe( observedAt );
		first.dispose();
		const second = controller();
		transport = () => Promise.resolve( {} );
		await jest.advanceTimersByTimeAsync( 1000 );
		await second.flush();
		expect( events() ).toHaveLength( 2 );
		expect( events()[ 1 ] ).toEqual( events()[ 0 ] );
		expect( events()[ 1 ].timestamp ).toBe( observedAt );
		expect( events()[ 1 ].after.outcome.observedAt ).toBe( observedAt );
		await second.flush();
		expect( events() ).toHaveLength( 2 );
	} );

	test( 'isolates stored candidates and pending attempts by site and current user', async () => {
		const first = controller();
		first.registerCandidate( apply( 'private-apply' ) );
		save( first, deferred().promise );
		first.dispose();
		const otherUser = controller( { currentUserId: 8 } );
		const otherSite = controller( {
			restUrl: 'https://example.test/other/wp-json/',
		} );
		await otherUser.flush();
		await otherSite.flush();
		expect( events() ).toEqual( [] );
		expect( getSavePersistenceStorageKey( SITE, 7 ) ).not.toBe(
			getSavePersistenceStorageKey( SITE, 8 )
		);
	} );

	test( 'drops forbidden telemetry without retry and never sends it under a changed user', async () => {
		let current = true;
		const instance = controller( { isCurrentUser: () => current } );
		instance.registerCandidate( apply( 'private-apply' ) );
		transport = () => Promise.reject( { data: { status: 403 } } );
		save( instance, deferred().promise );
		await instance.flush();
		await jest.advanceTimersByTimeAsync( 60000 );
		expect( events() ).toHaveLength( 1 );
		instance.registerCandidate( apply( 'another-apply' ) );
		save( instance, deferred().promise );
		current = false;
		await instance.flush();
		expect( events() ).toHaveLength( 1 );
	} );

	test( 'preserves the original rejection while accepting a server confirmation for the same failed response', async () => {
		const instance = controller();
		instance.registerCandidate( apply( 'original' ) );
		const error = new Error( 'Response was lost after saving' );
		const pending = deferred();
		transport = ( request ) =>
			Promise.resolve(
				request.method === 'POST'
					? {}
					: {
							entries: [
								{
									id: 'original',
									persistenceVerdict: {
										state: 'save_confirmed',
										label: 'Persisted',
										saveSequence: 2,
										saveOccurrenceId: 'save-1',
									},
									requestStatus: {
										state: 'save_failed',
										label: 'Save request failed',
									},
									verificationCoverage: {
										state: 'compared',
										eligible: true,
										compared: true,
										conclusive: true,
										label: 'Compared',
									},
									undoState: {
										state: 'undone',
										label: 'Undone in editor',
									},
								},
							],
							pending: false,
					  }
			);
		const submittedAt = new Date().toISOString();
		expect( save( instance, pending.promise ) ).toBe( pending.promise );
		jest.setSystemTime( Date.now() + 2000 );
		const failedAt = new Date().toISOString();
		pending.reject( error );
		await expect( pending.promise ).rejects.toBe( error );
		await instance.flush();
		expect( instance.getAssurance( 'original' ) ).toMatchObject( {
			persistenceVerdict: { state: 'save_confirmed' },
			requestStatus: { state: 'save_failed' },
			undoState: { state: 'undone' },
		} );
		expect(
			events().map( ( entry ) => entry.after.outcome.event )
		).toEqual( [ 'save_attempted', 'save_failed' ] );
		expect( events().map( ( entry ) => entry.timestamp ) ).toEqual( [
			submittedAt,
			failedAt,
		] );
	} );

	test( 'does not infer persistence from HTTP success or allow an older comparison to replace a newer one', async () => {
		const instance = controller();
		instance.registerCandidate( apply( 'original' ) );
		save( instance, Promise.resolve( { id: 42 } ) );
		await Promise.resolve();
		await instance.flush();
		expect( instance.getAssurance( 'original' ) ).toEqual( {} );
		instance.cacheServerEntries( [
			{
				id: 'original',
				persistenceVerdict: {
					state: 'save_discarded',
					saveSequence: 4,
					label: 'Not present',
				},
			},
		] );
		instance.cacheServerEntries( [
			{
				id: 'original',
				persistenceVerdict: {
					state: 'save_confirmed',
					saveSequence: 3,
					label: 'Persisted',
				},
			},
		] );
		expect(
			instance.getAssurance( 'original' ).persistenceVerdict.state
		).toBe( 'save_discarded' );
		requests = [];
		save( instance, Promise.resolve( { id: 42 } ) );
		await Promise.resolve();
		await instance.flush();
		expect( events() ).toEqual( [] );
		expect(
			requests.some( ( request ) =>
				request.path.includes( 'applyIds=original' )
			)
		).toBe( true );
	} );

	test( 'reconciles in batches of 50 and stops bounded pending retries without inventing a verdict', async () => {
		const instance = controller();
		for ( let index = 0; index < 51; index++ ) {
			instance.registerCandidate( apply( `apply-${ index }` ) );
		}
		transport = ( request ) =>
			Promise.resolve(
				request.method === 'POST' ? {} : { entries: [], pending: true }
			);
		save( instance, Promise.resolve( {} ) );
		await Promise.resolve();
		await instance.flush();
		const lookups = requests.filter(
			( request ) => request.method === 'GET'
		);
		expect( lookups ).toHaveLength( 2 );
		expect(
			new URL( lookups[ 0 ].path, SITE ).searchParams
				.get( 'applyIds' )
				.split( ',' )
		).toHaveLength( 50 );
		await jest.runAllTimersAsync();
		expect(
			requests.filter( ( request ) => request.method === 'GET' ).length
		).toBeLessThanOrEqual( 12 );
		expect( instance.getAssurance( 'apply-0' ) ).toEqual( {} );
	} );
} );
