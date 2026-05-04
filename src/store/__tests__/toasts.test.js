import {
	buildToastForActivity,
	reduceToastsState,
	toastsActionCreators,
	toastsDefaultState,
	toastsSelectors,
	TOAST_DEFAULTS,
} from '../toasts';

function makeToast( overrides = {} ) {
	return {
		id: `toast-${ Math.random().toString( 36 ).slice( 2 ) }`,
		variant: 'success',
		surface: 'block',
		title: 'Block updated',
		detail: 'core/heading · attr=fontWeight',
		activityId: 'activity-1',
		undoLabel: 'Undo',
		autoDismissMs: 6000,
		interacted: false,
		...overrides,
	};
}

describe( 'toasts slice — reducer', () => {
	it( 'enqueueToast appends to the queue', () => {
		const state = { ...toastsDefaultState };
		const toast = makeToast();
		const next = reduceToastsState(
			state,
			toastsActionCreators.enqueueToast( toast )
		);

		expect( next.toasts ).toEqual( [ toast ] );
	} );

	it( 'updateToast shallow-merges patch into the matching entry', () => {
		const toast = makeToast();
		const state = { toasts: [ toast ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.updateToast( toast.id, {
				variant: 'error',
				title: 'Undo failed',
			} )
		);

		expect( next.toasts[ 0 ] ).toEqual( {
			...toast,
			variant: 'error',
			title: 'Undo failed',
		} );
	} );

	it( 'updateToast is a no-op when id is unknown', () => {
		const toast = makeToast();
		const state = { toasts: [ toast ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.updateToast( 'unknown', { variant: 'error' } )
		);

		expect( next ).toBe( state );
	} );

	it( 'dismissToast removes by id', () => {
		const a = makeToast( { id: 'a' } );
		const b = makeToast( { id: 'b' } );
		const state = { toasts: [ a, b ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.dismissToast( 'a' )
		);

		expect( next.toasts ).toEqual( [ b ] );
	} );

	it( 'dismissAllToasts clears the queue', () => {
		const state = { toasts: [ makeToast(), makeToast() ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.dismissAllToasts()
		);

		expect( next.toasts ).toEqual( [] );
	} );

	it( 'markToastInteracted toggles the interacted flag', () => {
		const toast = makeToast( { id: 't1', interacted: false } );
		const state = { toasts: [ toast ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.markToastInteracted( 't1', true )
		);

		expect( next.toasts[ 0 ].interacted ).toBe( true );

		const back = reduceToastsState(
			next,
			toastsActionCreators.markToastInteracted( 't1', false )
		);

		expect( back.toasts[ 0 ].interacted ).toBe( false );
	} );

	it( 'cap-at-3 evicts the oldest non-interacted toast', () => {
		const a = makeToast( { id: 'a', interacted: false } );
		const b = makeToast( { id: 'b', interacted: false } );
		const c = makeToast( { id: 'c', interacted: false } );
		const d = makeToast( { id: 'd' } );
		const state = { toasts: [ a, b, c ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.enqueueToast( d )
		);

		expect( next.toasts.map( ( t ) => t.id ) ).toEqual( [ 'b', 'c', 'd' ] );
	} );

	it( 'cap-at-3 skips interacted toasts when evicting', () => {
		const a = makeToast( { id: 'a', interacted: true } );
		const b = makeToast( { id: 'b', interacted: false } );
		const c = makeToast( { id: 'c', interacted: false } );
		const d = makeToast( { id: 'd' } );
		const state = { toasts: [ a, b, c ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.enqueueToast( d )
		);

		// Oldest non-interacted is `b`, so `b` is evicted, not `a`.
		expect( next.toasts.map( ( t ) => t.id ) ).toEqual( [ 'a', 'c', 'd' ] );
	} );

	it( 'cap-at-3 drops incoming when every visible toast is interacted', () => {
		const a = makeToast( { id: 'a', interacted: true } );
		const b = makeToast( { id: 'b', interacted: true } );
		const c = makeToast( { id: 'c', interacted: true } );
		const d = makeToast( { id: 'd' } );
		const state = { toasts: [ a, b, c ] };
		const next = reduceToastsState(
			state,
			toastsActionCreators.enqueueToast( d )
		);

		expect( next ).toBe( state );
	} );
} );

describe( 'toasts slice — selector', () => {
	it( 'getToasts returns the queue', () => {
		const a = makeToast();
		const state = { toasts: [ a ] };

		expect( toastsSelectors.getToasts( state ) ).toEqual( [ a ] );
	} );

	it( 'getToasts returns [] when state.toasts is missing', () => {
		expect( toastsSelectors.getToasts( {} ) ).toEqual( [] );
	} );
} );

describe( 'buildToastForActivity', () => {
	it( 'block surface produces a success toast with the persisted entry id', () => {
		const result = buildToastForActivity( {
			surface: 'block',
			persistedEntry: { id: 'activity-42' },
			suggestion: {
				blockName: 'core/heading',
				attributeName: 'fontWeight',
			},
			extras: { blockContext: { name: 'core/heading' } },
		} );

		expect( result ).toMatchObject( {
			variant: 'success',
			surface: 'block',
			title: 'Block updated',
			detail: 'core/heading · attr=fontWeight',
			activityId: 'activity-42',
			autoDismissMs: TOAST_DEFAULTS.successMs,
			interacted: false,
		} );
		expect( typeof result.id ).toBe( 'string' );
	} );

	it( 'returns activityId: null when persistedEntry is missing', () => {
		const result = buildToastForActivity( {
			surface: 'block',
			persistedEntry: null,
			suggestion: { blockName: 'core/paragraph' },
		} );

		expect( result.activityId ).toBeNull();
	} );

	it( 'maps each surface key to its title', () => {
		const cases = [
			[ 'block', 'Block updated' ],
			[ 'template', 'Template applied' ],
			[ 'templatePart', 'Template part applied' ],
			[ 'template-part', 'Template part applied' ],
			[ 'globalStyles', 'Global styles updated' ],
			[ 'global-styles', 'Global styles updated' ],
			[ 'styleBook', 'Style Book updated' ],
			[ 'style-book', 'Style Book updated' ],
		];

		for ( const [ surface, expectedTitle ] of cases ) {
			const result = buildToastForActivity( {
				surface,
				persistedEntry: { id: 'x' },
				suggestion: {},
			} );

			expect( result.title ).toBe( expectedTitle );
		}
	} );

	it( 'falls back to a generic title for unknown surfaces', () => {
		const result = buildToastForActivity( {
			surface: 'unknown',
			persistedEntry: null,
			suggestion: {},
		} );

		expect( result.title ).toBe( 'Update applied' );
	} );

	it( 'template detail includes slug and operation count', () => {
		const result = buildToastForActivity( {
			surface: 'template',
			persistedEntry: { id: 'a' },
			suggestion: { templateSlug: 'page-template-1' },
			extras: { operations: [ {}, {}, {} ] },
		} );

		expect( result.detail ).toBe( 'page-template-1 · 3 ops' );
	} );

	it( 'template-part detail includes area and op count', () => {
		const result = buildToastForActivity( {
			surface: 'template-part',
			persistedEntry: { id: 'a' },
			suggestion: { area: 'header' },
			extras: { operations: [ {}, {} ] },
		} );

		expect( result.detail ).toBe( 'header · 2 ops' );
	} );

	it( 'global-styles detail includes path and value', () => {
		const result = buildToastForActivity( {
			surface: 'global-styles',
			persistedEntry: { id: 'a' },
			suggestion: {
				stylePath: 'typography.fontSize',
				value: '−0.04em',
			},
		} );

		expect( result.detail ).toBe( 'typography.fontSize · −0.04em' );
	} );
} );
