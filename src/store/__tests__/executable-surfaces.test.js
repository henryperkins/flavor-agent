jest.mock( '../executable-surface-runtime', () => {
	const actual = jest.requireActual( '../executable-surface-runtime' );

	return {
		...actual,
		buildExecutableSurfaceFetchThunk: jest.fn(
			( config, input, deps ) => ( {
				kind: 'fetch',
				config,
				input,
				deps,
			} )
		),
		buildExecutableSurfaceApplyThunk: jest.fn(
			(
				config,
				suggestion,
				currentRequestSignature,
				liveRequestInput,
				deps
			) => ( {
				kind: 'apply',
				config,
				suggestion,
				currentRequestSignature,
				liveRequestInput,
				deps,
			} )
		),
		buildExecutableSurfaceReviewFreshnessThunk: jest.fn(
			( config, currentRequestSignature, liveRequestInput, deps ) => ( {
				kind: 'review',
				config,
				currentRequestSignature,
				liveRequestInput,
				deps,
			} )
		),
	};
} );

jest.mock( '../../utils/recommendation-request-signature', () => ( {
	buildGlobalStylesRecommendationRequestSignature: jest.fn(),
	buildStyleBookRecommendationRequestSignature: jest.fn(),
	buildTemplatePartRecommendationRequestSignature: jest.fn(),
	buildTemplateRecommendationRequestSignature: jest.fn(),
} ) );

jest.mock( '../../utils/style-operations', () => ( {
	applyGlobalStyleSuggestionOperations: jest.fn(),
} ) );

jest.mock( '../../utils/template-actions', () => ( {
	applyTemplatePartSuggestionOperations: jest.fn(),
	applyTemplateSuggestionOperations: jest.fn(),
} ) );

jest.mock( '../activity-session', () => ( {
	getRequestDocumentFromScope: jest.fn( ( scope = null ) => {
		let scopeKey = '';

		if ( typeof scope?.scopeKey === 'string' && scope.scopeKey.trim() ) {
			scopeKey = scope.scopeKey.trim();
		} else if ( typeof scope?.key === 'string' && scope.key.trim() ) {
			scopeKey = scope.key.trim();
		}

		if ( ! scopeKey ) {
			return null;
		}

		return {
			scopeKey,
			postType: scope.postType,
			entityId: scope.entityId,
			entityKind: scope.entityKind || '',
			entityName: scope.entityName || '',
			stylesheet: scope.stylesheet || '',
		};
	} ),
	getScopeKey: jest.fn( ( scope = null ) => {
		if ( typeof scope?.scopeKey === 'string' && scope.scopeKey.trim() ) {
			return scope.scopeKey.trim();
		}

		if ( typeof scope?.key === 'string' && scope.key.trim() ) {
			return scope.key.trim();
		}

		return null;
	} ),
} ) );

jest.mock( '../activity-undo', () => ( {
	buildGlobalStylesActivityEntryFromStore: jest.fn(),
	buildStyleBookActivityEntryFromStore: jest.fn(),
	buildTemplateActivityEntryFromStore: jest.fn(),
	buildTemplatePartActivityEntryFromStore: jest.fn(),
} ) );

import {
	EXECUTABLE_SURFACE_DEFS,
	createExecutableSurfaceDefaultState,
	createExecutableSurfaceRuntimeActionCreators,
	createExecutableSurfaceSelectors,
	createExecutableSurfaceStateActionCreators,
	reduceExecutableSurfaceState,
} from '../executable-surfaces';
import {
	buildExecutableSurfaceApplyThunk,
	buildExecutableSurfaceFetchThunk,
	buildExecutableSurfaceReviewFreshnessThunk,
	createExecutableSurfaceApplyConfig,
	createExecutableSurfaceFetchConfig,
	createExecutableSurfaceReviewFreshnessConfig,
} from '../executable-surface-runtime';

function buildSurfaceFixture( def ) {
	switch ( def.key ) {
		case 'template':
			return {
				input: {
					templateRef: 'template-17',
					prompt: 'Draft template',
					contextSignature: 'template-context',
				},
				recommendationValue: 'template-17',
				expectedDocument: {
					scopeKey: 'wp_template:template-17',
					postType: 'wp_template',
					entityId: 'template-17',
					entityKind: '',
					entityName: '',
					stylesheet: '',
				},
				payload: {
					suggestions: [
						{
							suggestionKey: 'template-suggestion-1',
						},
					],
					explanation: 'Template explanation',
				},
				resultState: {
					templateRef: 'template-17',
				},
				reviewContextSignature: 'template-review-context',
				resolvedContextSignature: 'template-resolved-context',
			};
		case 'templatePart':
			return {
				input: {
					templatePartRef: 'template-part-17',
					prompt: 'Draft template part',
					contextSignature: 'template-part-context',
				},
				recommendationValue: 'template-part-17',
				expectedDocument: {
					scopeKey: 'wp_template_part:template-part-17',
					postType: 'wp_template_part',
					entityId: 'template-part-17',
					entityKind: '',
					entityName: '',
					stylesheet: '',
				},
				payload: {
					suggestions: [
						{
							suggestionKey: 'template-part-suggestion-1',
						},
					],
					explanation: 'Template part explanation',
				},
				resultState: {
					templatePartRef: 'template-part-17',
				},
				reviewContextSignature: 'template-part-review-context',
				resolvedContextSignature: 'template-part-resolved-context',
			};
		case 'globalStyles':
			return {
				input: {
					scope: {
						scopeKey: 'global-styles:17',
						globalStylesId: '17',
						surface: 'global-styles',
					},
					prompt: 'Refine global styles',
					contextSignature: 'global-styles-context',
				},
				recommendationValue: {
					scopeKey: 'global-styles:17',
					globalStylesId: '17',
					surface: 'global-styles',
				},
				expectedDocument: {
					scopeKey: 'global-styles:17',
					postType: 'global_styles',
					entityId: '17',
					entityKind: 'root',
					entityName: 'globalStyles',
					stylesheet: '',
				},
				payload: {
					suggestions: [
						{
							suggestionKey: 'global-styles-suggestion-1',
						},
					],
					explanation: 'Global styles explanation',
				},
				resultState: {
					globalStylesEntityId: '17',
				},
				selectorOverrides: {
					globalStylesScopeKey: 'global-styles:17',
				},
				reviewContextSignature: 'global-styles-review-context',
				resolvedContextSignature: 'global-styles-resolved-context',
			};
		case 'styleBook':
			return {
				input: {
					scope: {
						scopeKey: 'style-book:17',
						globalStylesId: '17',
						blockName: 'core/paragraph',
						blockTitle: 'Paragraph',
						surface: 'style-book',
					},
					prompt: 'Refine style book',
					contextSignature: 'style-book-context',
				},
				recommendationValue: {
					scopeKey: 'style-book:17',
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
					surface: 'style-book',
				},
				expectedDocument: {
					scopeKey: 'style-book:17',
					postType: 'global_styles',
					entityId: '17',
					entityKind: 'block',
					entityName: 'styleBook',
					stylesheet: '',
				},
				payload: {
					suggestions: [
						{
							suggestionKey: 'style-book-suggestion-1',
						},
					],
					explanation: 'Style book explanation',
				},
				resultState: {
					styleBookScopeKey: 'style-book:17',
					styleBookGlobalStylesId: '17',
					styleBookBlockName: 'core/paragraph',
					styleBookBlockTitle: 'Paragraph',
				},
				selectorOverrides: {
					styleBookScopeKey: 'style-book:17',
					styleBookGlobalStylesId: '17',
					styleBookBlockName: 'core/paragraph',
					styleBookBlockTitle: 'Paragraph',
				},
				reviewContextSignature: 'style-book-review-context',
				resolvedContextSignature: 'style-book-resolved-context',
			};
		default:
			throw new Error( `Unsupported surface fixture: ${ def.key }` );
	}
}

function buildPopulatedSurfaceState( def, fixture ) {
	return {
		...createExecutableSurfaceDefaultState(),
		...fixture.resultState,
		...fixture.selectorOverrides,
		[ def.collectionKey ]: fixture.payload.suggestions,
		[ def.explanationKey ]: fixture.payload.explanation,
		[ def.statusKey ]: 'loading',
		[ def.errorKey ]: ' request failed ',
		[ def.requestPromptKey ]: fixture.input.prompt,
		[ def.contextSignatureKey ]: ' request-context ',
		[ def.reviewContextSignatureKey ]: fixture.reviewContextSignature,
		[ def.resolvedContextSignatureKey ]: fixture.resolvedContextSignature,
		[ def.requestTokenKey ]: 7,
		[ def.resultTokenKey ]: 2,
		[ def.reviewRequestTokenKey ]: 3,
		[ def.reviewFreshnessStatusKey ]: 'fresh',
		[ def.selectedSuggestionKey ]: 'selected-suggestion',
		[ def.applyStatusKey ]: 'applying',
		[ def.applyErrorKey ]: ' apply failed ',
		[ def.lastAppliedSuggestionKey ]: 'last-applied-suggestion',
		[ def.lastAppliedOperationsKey ]: [
			{
				op: 'old-operation',
			},
		],
		[ def.reviewStaleReasonKey ]: 'server-review',
		[ def.staleReasonKey ]: 'apply-stale',
		undoStatus: 'undoing',
	};
}

describe( 'executable-surface runtime config factories', () => {
	it( 'wires fetch config callbacks through the status action', () => {
		const setStatusAction = jest.fn( ( status, error, requestToken ) => ( {
			status,
			error,
			requestToken,
		} ) );
		const buildRequestDocument = jest.fn();
		const dispatchRecommendations = jest.fn();
		const getRequestToken = jest.fn();
		const config = createExecutableSurfaceFetchConfig( {
			abortKey: '_surfaceAbort',
			buildRequestDocument,
			dispatchRecommendations,
			abilityName: 'test/fetch',
			getRequestToken,
			requestErrorMessage: 'Fetch failed.',
			setStatusAction,
		} );

		expect( config ).toMatchObject( {
			abortKey: '_surfaceAbort',
			buildRequestDocument,
			dispatchRecommendations,
			abilityName: 'test/fetch',
			getRequestToken,
			requestErrorMessage: 'Fetch failed.',
			setErrorState: expect.any( Function ),
			setLoadingState: expect.any( Function ),
		} );
		expect( config.setErrorState( 'request failed', 7 ) ).toEqual( {
			status: 'error',
			error: 'request failed',
			requestToken: 7,
		} );
		expect( setStatusAction ).toHaveBeenCalledWith(
			'error',
			'request failed',
			7
		);
		expect( config.setLoadingState( 8 ) ).toEqual( {
			status: 'loading',
			error: null,
			requestToken: 8,
		} );
		expect( setStatusAction ).toHaveBeenCalledWith( 'loading', null, 8 );
	} );

	it( 'wires review freshness config callbacks through the review action', () => {
		const getReviewRequestToken = jest.fn();
		const getStoredRequestSignature = jest.fn();
		const getStoredReviewContextSignature = jest.fn();
		const setReviewStateAction = jest.fn(
			( status, requestToken, staleReason ) => ( {
				status,
				requestToken,
				staleReason,
			} )
		);
		const config = createExecutableSurfaceReviewFreshnessConfig( {
			abilityName: 'test/review',
			getReviewRequestToken,
			getStoredRequestSignature,
			getStoredReviewContextSignature,
			setReviewStateAction,
			surface: 'surface',
		} );

		expect( config ).toMatchObject( {
			abilityName: 'test/review',
			getReviewRequestToken,
			getStoredRequestSignature,
			getStoredReviewContextSignature,
			setReviewState: expect.any( Function ),
			surface: 'surface',
		} );
		expect(
			config.setReviewState( 'stale', {
				requestToken: 9,
				staleReason: 'server-review',
			} )
		).toEqual( {
			status: 'stale',
			requestToken: 9,
			staleReason: 'server-review',
		} );
		expect( setReviewStateAction ).toHaveBeenCalledWith(
			'stale',
			9,
			'server-review'
		);
		expect( config.setReviewState( 'fresh' ) ).toEqual( {
			status: 'fresh',
			requestToken: null,
			staleReason: null,
		} );
		expect( setReviewStateAction ).toHaveBeenCalledWith(
			'fresh',
			null,
			null
		);
	} );

	it( 'wires apply config callbacks through the apply action', () => {
		const buildActivityEntry = jest.fn();
		const executeSuggestion = jest.fn();
		const getStoredRequestSignature = jest.fn();
		const getStoredResolvedContextSignature = jest.fn();
		const setApplyStateAction = jest.fn(
			( status, error, suggestionKey, operations, staleReason ) => ( {
				status,
				error,
				suggestionKey,
				operations,
				staleReason,
			} )
		);
		const config = createExecutableSurfaceApplyConfig( {
			applyFailureMessage: 'Apply failed.',
			buildActivityEntry,
			abilityName: 'test/apply',
			executeSuggestion,
			getStoredRequestSignature,
			getStoredResolvedContextSignature,
			setApplyStateAction,
			surface: 'surface',
			unexpectedErrorMessage: 'Unexpected apply failure.',
		} );

		expect( config ).toMatchObject( {
			applyFailureMessage: 'Apply failed.',
			buildActivityEntry,
			abilityName: 'test/apply',
			executeSuggestion,
			getStoredRequestSignature,
			getStoredResolvedContextSignature: expect.any( Function ),
			setApplyState: expect.any( Function ),
			surface: 'surface',
			unexpectedErrorMessage: 'Unexpected apply failure.',
		} );
		expect(
			config.setApplyState( 'success', {
				error: null,
				suggestionKey: 'suggestion-1',
				operations: [
					{
						op: 'replace',
					},
				],
				staleReason: 'server-review',
			} )
		).toEqual( {
			status: 'success',
			error: null,
			suggestionKey: 'suggestion-1',
			operations: [
				{
					op: 'replace',
				},
			],
			staleReason: 'server-review',
		} );
		expect( setApplyStateAction ).toHaveBeenCalledWith(
			'success',
			null,
			'suggestion-1',
			[
				{
					op: 'replace',
				},
			],
			'server-review'
		);
		expect( config.setApplyState( 'idle' ) ).toEqual( {
			status: 'idle',
			error: null,
			suggestionKey: null,
			operations: [],
			staleReason: null,
		} );
		expect( setApplyStateAction ).toHaveBeenCalledWith(
			'idle',
			null,
			null,
			[],
			null
		);
	} );
} );

for ( const def of EXECUTABLE_SURFACE_DEFS ) {
	describe( `${ def.key } executable-surface contract`, () => {
		let fixture;
		let actions;
		let runtime;
		let selectors;
		let normalizeStringMessage;
		let normalizedInteractionState;
		let runtimeActions;
		let fetchDeps;
		let applyDeps;
		let reviewDeps;

		beforeEach( () => {
			fixture = buildSurfaceFixture( def );
			runtime = {
				[ def.abortKey ]: {
					abort: jest.fn(),
				},
			};
			actions = createExecutableSurfaceStateActionCreators( runtime );
			normalizeStringMessage = jest.fn( ( value ) =>
				typeof value === 'string' ? value.trim() : ''
			);
			normalizedInteractionState = jest.fn( ( surface, payload ) => ( {
				surface,
				payload,
			} ) );
			selectors = createExecutableSurfaceSelectors( {
				getNormalizedInteractionState: normalizedInteractionState,
				normalizeStringMessage,
			} );
			fetchDeps = {
				attachRequestMetaToRecommendationPayload: jest.fn(),
				getReviewContextSignatureFromResponse: jest.fn(),
				getResolvedContextSignatureFromResponse: jest.fn(),
				runAbortableRecommendationRequest: jest.fn(),
			};
			applyDeps = {
				dispatchToastForActivity: jest.fn(),
				getCurrentActivityScope: jest.fn(),
				guardSurfaceApplyFreshness: jest.fn(),
				guardSurfaceApplyResolvedFreshness: jest.fn(),
				recordActivityEntry: jest.fn(),
				syncActivitySession: jest.fn(),
			};
			reviewDeps = {
				getReviewContextSignatureFromResponse: jest.fn(),
			};
			runtimeActions = createExecutableSurfaceRuntimeActionCreators(
				actions,
				{
					fetchDeps,
					applyDeps,
					reviewDeps,
				}
			);
			buildExecutableSurfaceFetchThunk.mockClear();
			buildExecutableSurfaceApplyThunk.mockClear();
			buildExecutableSurfaceReviewFreshnessThunk.mockClear();
		} );

		it( 'exposes the expected default slice state', () => {
			const defaults = createExecutableSurfaceDefaultState();

			expect( defaults ).toMatchObject( {
				[ def.collectionKey ]: [],
				[ def.explanationKey ]: '',
				[ def.statusKey ]: 'idle',
				[ def.errorKey ]: null,
				[ def.requestPromptKey ]: '',
				[ def.resultRefKey ]: null,
				[ def.contextSignatureKey ]: null,
				[ def.reviewContextSignatureKey ]: null,
				[ def.resolvedContextSignatureKey ]: null,
				[ def.requestTokenKey ]: 0,
				[ def.resultTokenKey ]: 0,
				[ def.reviewRequestTokenKey ]: 0,
				[ def.reviewFreshnessStatusKey ]: 'idle',
				[ def.selectedSuggestionKey ]: null,
				[ def.applyStatusKey ]: 'idle',
				[ def.applyErrorKey ]: null,
				[ def.lastAppliedSuggestionKey ]: null,
				[ def.lastAppliedOperationsKey ]: [],
				[ def.reviewStaleReasonKey ]: null,
				[ def.staleReasonKey ]: null,
				...def.extraStateDefaults,
			} );
		} );

		it( 'creates surface-specific state action shapes', () => {
			expect(
				actions[ def.methodNames.setStatus ](
					'ready',
					'request failed',
					9
				)
			).toEqual( {
				type: def.types.setStatus,
				status: 'ready',
				error: 'request failed',
				requestToken: 9,
			} );

			expect(
				actions[ def.methodNames.setRecommendations ](
					fixture.recommendationValue,
					fixture.payload,
					fixture.input.prompt,
					11,
					'request-context',
					fixture.reviewContextSignature,
					fixture.resolvedContextSignature
				)
			).toEqual( {
				type: def.types.setRecommendations,
				[ def.inputKey ]: fixture.recommendationValue,
				payload: fixture.payload,
				prompt: fixture.input.prompt,
				requestToken: 11,
				contextSignature: 'request-context',
				reviewContextSignature: fixture.reviewContextSignature,
				resolvedContextSignature: fixture.resolvedContextSignature,
			} );

			expect(
				actions[ def.methodNames.setReviewFreshnessState ](
					'stale',
					12,
					'server-review'
				)
			).toEqual( {
				type: def.types.setReviewFreshnessState,
				status: 'stale',
				requestToken: 12,
				staleReason: 'server-review',
			} );

			expect(
				actions[ def.methodNames.setSelectedSuggestion ](
					'suggestion-1'
				)
			).toEqual( {
				type: def.types.setSelectedSuggestion,
				suggestionKey: 'suggestion-1',
			} );

			expect(
				actions[ def.methodNames.setApplyState ](
					'success',
					'apply failed',
					'suggestion-1',
					[
						{
							op: 'replace',
						},
					],
					'stale'
				)
			).toEqual( {
				type: def.types.setApplyState,
				status: 'success',
				error: 'apply failed',
				suggestionKey: 'suggestion-1',
				operations: [
					{
						op: 'replace',
					},
				],
				staleReason: 'stale',
			} );
		} );

		it( 'clears in-flight work when recommendations are dismissed', () => {
			const abortController = runtime[ def.abortKey ];
			const dispatch = jest.fn();

			actions[ def.methodNames.clearRecommendations ]()( {
				dispatch,
			} );

			expect( abortController.abort ).toHaveBeenCalledTimes( 1 );
			expect( runtime[ def.abortKey ] ).toBeNull();
			expect( dispatch ).toHaveBeenCalledWith( {
				type: def.types.clearRecommendations,
			} );
		} );

		it( 'selector helpers expose the expected slice values', () => {
			const state = buildPopulatedSurfaceState( def, fixture );

			expect(
				selectors[ def.methodNames.getRecommendations ]( state )
			).toBe( fixture.payload.suggestions );
			expect( selectors[ def.methodNames.getExplanation ]( state ) ).toBe(
				fixture.payload.explanation
			);
			expect( selectors[ def.methodNames.getError ]( state ) ).toBe(
				' request failed '
			);
			expect(
				selectors[ def.methodNames.getRequestPrompt ]( state )
			).toBe( fixture.input.prompt );
			expect( selectors[ def.methodNames.getResultRef ]( state ) ).toBe(
				fixture.resultState[ def.resultRefKey ]
			);
			expect(
				selectors[ def.methodNames.getContextSignature ]( state )
			).toBe( ' request-context ' );
			expect(
				selectors[ def.methodNames.getReviewContextSignature ]( state )
			).toBe( fixture.reviewContextSignature );
			expect(
				selectors[ def.methodNames.getResolvedContextSignature ](
					state
				)
			).toBe( fixture.resolvedContextSignature );
			expect(
				selectors[ def.methodNames.getRequestToken ]( state )
			).toBe( 7 );
			expect( selectors[ def.methodNames.getResultToken ]( state ) ).toBe(
				2
			);
			expect(
				selectors[ def.methodNames.getReviewRequestToken ]( state )
			).toBe( 3 );
			expect(
				selectors[ def.methodNames.getReviewFreshnessStatus ]( state )
			).toBe( 'fresh' );
			expect( selectors[ def.methodNames.isLoading ]( state ) ).toBe(
				true
			);
			expect( selectors[ def.methodNames.getStatus ]( state ) ).toBe(
				'loading'
			);
			expect(
				selectors[ def.methodNames.getSelectedSuggestionKey ]( state )
			).toBe( 'selected-suggestion' );
			expect( selectors[ def.methodNames.getApplyStatus ]( state ) ).toBe(
				'applying'
			);
			expect( selectors[ def.methodNames.getApplyError ]( state ) ).toBe(
				' apply failed '
			);
			expect( selectors[ def.methodNames.isApplying ]( state ) ).toBe(
				true
			);
			expect(
				selectors[ def.methodNames.getLastAppliedSuggestionKey ](
					state
				)
			).toBe( 'last-applied-suggestion' );
			expect(
				selectors[ def.methodNames.getLastAppliedOperations ]( state )
			).toEqual( [
				{
					op: 'old-operation',
				},
			] );
			expect(
				selectors[ def.methodNames.getReviewStaleReason ]( state )
			).toBe( 'server-review' );
			expect( selectors[ def.methodNames.getStaleReason ]( state ) ).toBe(
				'apply-stale'
			);

			for ( const { name, key } of def.extraSelectors ) {
				expect( selectors[ name ]( state ) ).toBe( state[ key ] );
			}

			const interactionState = selectors[
				def.methodNames.getInteractionState
			]( state, {
				hasPreview: false,
				hasSuccess: true,
				hasUndoSuccess: true,
				marker: 'preserved',
			} );

			expect( normalizeStringMessage ).toHaveBeenCalledWith(
				' request failed '
			);
			expect( normalizeStringMessage ).toHaveBeenCalledWith(
				' apply failed '
			);
			expect( normalizedInteractionState ).toHaveBeenCalledWith(
				def.surface,
				expect.objectContaining( {
					requestStatus: 'loading',
					requestError: 'request failed',
					applyStatus: 'applying',
					applyError: 'apply failed',
					undoStatus: 'undoing',
					hasResult: true,
					hasPreview: false,
					hasSuccess: true,
					hasUndoSuccess: true,
					marker: 'preserved',
				} )
			);
			expect( interactionState ).toEqual( {
				surface: def.surface,
				payload: expect.any( Object ),
			} );
		} );

		it( 'runtime action creators hand coherent configs to the thunk builders', () => {
			const fetchRequestTokenSelector = jest.fn( () => 4 );
			const requestDocument = runtimeActions[
				def.methodNames.fetchRecommendations
			]( fixture.input );

			expect( buildExecutableSurfaceFetchThunk ).toHaveBeenCalledTimes(
				1
			);
			const [ fetchConfig, fetchInput, fetchThunkDeps ] =
				buildExecutableSurfaceFetchThunk.mock.calls[ 0 ];

			expect( fetchInput ).toBe( fixture.input );
			expect( fetchThunkDeps ).toBe( fetchDeps );
			expect( fetchConfig ).toMatchObject( {
				abortKey: def.abortKey,
				buildRequestDocument: expect.any( Function ),
				dispatchRecommendations: expect.any( Function ),
				abilityName: def.abilityName,
				getRequestToken: expect.any( Function ),
				requestErrorMessage: def.requestErrorMessage,
				setErrorState: expect.any( Function ),
				setLoadingState: expect.any( Function ),
			} );
			expect(
				fetchConfig.getRequestToken( {
					[ def.methodNames.getRequestToken ]:
						fetchRequestTokenSelector,
				} )
			).toBe( 5 );
			expect( fetchRequestTokenSelector ).toHaveBeenCalledTimes( 1 );
			expect(
				fetchConfig.buildRequestDocument( {
					input: fixture.input,
					registry: {
						select: {},
					},
				} )
			).toEqual( fixture.expectedDocument );

			const fetchDispatch = jest.fn();
			fetchConfig.dispatchRecommendations( {
				dispatch: fetchDispatch,
				input: fixture.input,
				payload: fixture.payload,
				requestToken: 13,
				contextSignature: fixture.input.contextSignature,
				reviewContextSignature: fixture.reviewContextSignature,
				resolvedContextSignature: fixture.resolvedContextSignature,
			} );
			expect( fetchDispatch ).toHaveBeenCalledWith(
				actions[ def.methodNames.setRecommendations ](
					fixture.recommendationValue,
					fixture.payload,
					fixture.input.prompt,
					13,
					fixture.input.contextSignature,
					fixture.reviewContextSignature,
					fixture.resolvedContextSignature
				)
			);
			expect( fetchConfig.setLoadingState( 21 ) ).toEqual(
				actions[ def.methodNames.setStatus ]( 'loading', null, 21 )
			);
			expect( fetchConfig.setErrorState( 'request failed', 22 ) ).toEqual(
				actions[ def.methodNames.setStatus ](
					'error',
					'request failed',
					22
				)
			);
			expect( requestDocument.kind ).toBe( 'fetch' );

			const applySuggestion = {
				suggestionKey: `${ def.key }-suggestion-1`,
			};
			const applyResult = runtimeActions[
				def.methodNames.applySuggestion
			]( applySuggestion, 'request-signature', fixture.input );

			expect( buildExecutableSurfaceApplyThunk ).toHaveBeenCalledTimes(
				1
			);
			const [
				applyConfig,
				applyThunkSuggestion,
				applyRequestSignature,
				applyLiveRequestInput,
				applyThunkDeps,
			] = buildExecutableSurfaceApplyThunk.mock.calls[ 0 ];

			expect( applyThunkSuggestion ).toBe( applySuggestion );
			expect( applyRequestSignature ).toBe( 'request-signature' );
			expect( applyLiveRequestInput ).toBe( fixture.input );
			expect( applyThunkDeps ).toBe( applyDeps );
			expect( applyConfig ).toMatchObject( {
				applyFailureMessage: def.applyFailureMessage,
				buildActivityEntry: def.buildActivityEntry,
				abilityName: def.abilityName,
				executeSuggestion: def.executeSuggestion,
				getStoredRequestSignature: def.buildStoredRequestSignature,
				getStoredResolvedContextSignature: expect.any( Function ),
				setApplyState: expect.any( Function ),
				surface: def.surface,
				unexpectedErrorMessage: def.unexpectedErrorMessage,
			} );
			expect(
				applyConfig.getStoredResolvedContextSignature( {
					[ def.methodNames.getResolvedContextSignature ]: jest.fn(
						() => fixture.resolvedContextSignature
					),
				} )
			).toBe( fixture.resolvedContextSignature );
			expect(
				applyConfig.setApplyState( 'success', {
					error: null,
					suggestionKey: applySuggestion.suggestionKey,
					operations: [
						{
							op: 'replace',
						},
					],
					staleReason: 'server-review',
				} )
			).toEqual(
				actions[ def.methodNames.setApplyState ](
					'success',
					null,
					applySuggestion.suggestionKey,
					[
						{
							op: 'replace',
						},
					],
					'server-review'
				)
			);
			expect( applyConfig.executeSuggestion ).toBe(
				def.executeSuggestion
			);
			expect( applyConfig.buildActivityEntry ).toBe(
				def.buildActivityEntry
			);
			expect( applyResult.kind ).toBe( 'apply' );

			const reviewRequestTokenSelector = jest.fn( () => 6 );
			const reviewResult = runtimeActions[
				def.methodNames.revalidateReviewFreshness
			]( 'current-request-signature', fixture.input );

			expect(
				buildExecutableSurfaceReviewFreshnessThunk
			).toHaveBeenCalledTimes( 1 );
			const [
				reviewConfig,
				reviewRequestSignature,
				reviewLiveRequestInput,
				reviewThunkDeps,
			] = buildExecutableSurfaceReviewFreshnessThunk.mock.calls[ 0 ];

			expect( reviewRequestSignature ).toBe(
				'current-request-signature'
			);
			expect( reviewLiveRequestInput ).toBe( fixture.input );
			expect( reviewThunkDeps ).toBe( reviewDeps );
			expect( reviewConfig ).toMatchObject( {
				abilityName: def.abilityName,
				getReviewRequestToken: expect.any( Function ),
				getStoredRequestSignature: def.buildStoredRequestSignature,
				getStoredReviewContextSignature: expect.any( Function ),
				setReviewState: expect.any( Function ),
				surface: def.surface,
			} );
			expect(
				reviewConfig.getReviewRequestToken( {
					[ def.methodNames.getReviewRequestToken ]:
						reviewRequestTokenSelector,
				} )
			).toBe( 6 );
			expect( reviewRequestTokenSelector ).toHaveBeenCalledTimes( 1 );
			expect(
				reviewConfig.getStoredReviewContextSignature( {
					[ def.methodNames.getReviewContextSignature ]: jest.fn(
						() => fixture.reviewContextSignature
					),
				} )
			).toBe( fixture.reviewContextSignature );
			expect(
				reviewConfig.setReviewState( 'stale', {
					requestToken: 9,
					staleReason: 'server-review',
				} )
			).toEqual(
				actions[ def.methodNames.setReviewFreshnessState ](
					'stale',
					9,
					'server-review'
				)
			);
			expect( reviewResult.kind ).toBe( 'review' );
		} );

		it( 'reduces loading, recommendation, freshness, apply, and clear transitions', () => {
			const loadingState = {
				...createExecutableSurfaceDefaultState(),
				[ def.applyStatusKey ]: 'error',
				[ def.applyErrorKey ]: 'apply failed',
				[ def.lastAppliedSuggestionKey ]: 'previous-suggestion',
				[ def.lastAppliedOperationsKey ]: [
					{
						op: 'old-operation',
					},
				],
				[ def.reviewFreshnessStatusKey ]: 'stale',
				[ def.reviewStaleReasonKey ]: 'server-review',
				[ def.staleReasonKey ]: 'apply-stale',
				[ def.requestTokenKey ]: 6,
				[ def.reviewRequestTokenKey ]: 2,
			};
			const loadingAction = actions[ def.methodNames.setStatus ](
				'loading',
				null,
				7
			);
			const afterLoading = reduceExecutableSurfaceState(
				loadingState,
				loadingAction
			);

			expect( afterLoading[ def.statusKey ] ).toBe( 'loading' );
			expect( afterLoading[ def.errorKey ] ).toBeNull();
			expect( afterLoading[ def.requestTokenKey ] ).toBe( 7 );
			expect( afterLoading[ def.applyStatusKey ] ).toBe( 'idle' );
			expect( afterLoading[ def.applyErrorKey ] ).toBeNull();
			expect( afterLoading[ def.lastAppliedSuggestionKey ] ).toBeNull();
			expect( afterLoading[ def.lastAppliedOperationsKey ] ).toEqual(
				[]
			);
			expect( afterLoading[ def.reviewRequestTokenKey ] ).toBe( 3 );
			expect( afterLoading[ def.reviewFreshnessStatusKey ] ).toBe(
				'idle'
			);
			expect( afterLoading[ def.reviewStaleReasonKey ] ).toBeNull();
			expect( afterLoading[ def.staleReasonKey ] ).toBeNull();

			const staleStatusState = {
				...createExecutableSurfaceDefaultState(),
				[ def.requestTokenKey ]: 10,
			};
			expect(
				reduceExecutableSurfaceState(
					staleStatusState,
					actions[ def.methodNames.setStatus ]( 'ready', null, 9 )
				)
			).toBe( staleStatusState );
			expect(
				reduceExecutableSurfaceState(
					staleStatusState,
					actions[ def.methodNames.setRecommendations ](
						fixture.recommendationValue,
						fixture.payload,
						fixture.input.prompt,
						9,
						fixture.input.contextSignature,
						fixture.reviewContextSignature,
						fixture.resolvedContextSignature
					)
				)
			).toBe( staleStatusState );

			const recommendationState = {
				...afterLoading,
				[ def.applyStatusKey ]: 'error',
				[ def.applyErrorKey ]: 'apply failed',
				[ def.lastAppliedSuggestionKey ]: 'previous-suggestion',
				[ def.lastAppliedOperationsKey ]: [
					{
						op: 'old-operation',
					},
				],
				[ def.reviewFreshnessStatusKey ]: 'stale',
				[ def.reviewStaleReasonKey ]: 'server-review',
				[ def.staleReasonKey ]: 'apply-stale',
			};
			const recommendationAction = actions[
				def.methodNames.setRecommendations
			](
				fixture.recommendationValue,
				fixture.payload,
				fixture.input.prompt,
				8,
				fixture.input.contextSignature,
				fixture.reviewContextSignature,
				fixture.resolvedContextSignature
			);
			const afterRecommendations = reduceExecutableSurfaceState(
				recommendationState,
				recommendationAction
			);

			expect( afterRecommendations ).toMatchObject( fixture.resultState );
			expect( afterRecommendations[ def.collectionKey ] ).toBe(
				fixture.payload.suggestions
			);
			expect( afterRecommendations[ def.explanationKey ] ).toBe(
				fixture.payload.explanation
			);
			expect( afterRecommendations[ def.requestPromptKey ] ).toBe(
				fixture.input.prompt
			);
			expect( afterRecommendations[ def.contextSignatureKey ] ).toBe(
				fixture.input.contextSignature
			);
			expect(
				afterRecommendations[ def.reviewContextSignatureKey ]
			).toBe( fixture.reviewContextSignature );
			expect(
				afterRecommendations[ def.resolvedContextSignatureKey ]
			).toBe( fixture.resolvedContextSignature );
			expect( afterRecommendations[ def.requestTokenKey ] ).toBe( 8 );
			expect( afterRecommendations[ def.resultTokenKey ] ).toBe( 1 );
			expect( afterRecommendations[ def.reviewRequestTokenKey ] ).toBe(
				4
			);
			expect( afterRecommendations[ def.reviewFreshnessStatusKey ] ).toBe(
				'fresh'
			);
			expect( afterRecommendations[ def.statusKey ] ).toBe( 'ready' );
			expect( afterRecommendations[ def.errorKey ] ).toBeNull();
			expect(
				afterRecommendations[ def.selectedSuggestionKey ]
			).toBeNull();
			expect( afterRecommendations[ def.applyStatusKey ] ).toBe( 'idle' );
			expect( afterRecommendations[ def.applyErrorKey ] ).toBeNull();
			expect(
				afterRecommendations[ def.lastAppliedSuggestionKey ]
			).toBeNull();
			expect(
				afterRecommendations[ def.lastAppliedOperationsKey ]
			).toEqual( [] );
			expect(
				afterRecommendations[ def.reviewStaleReasonKey ]
			).toBeNull();
			expect( afterRecommendations[ def.staleReasonKey ] ).toBeNull();

			const staleReviewState = {
				...createExecutableSurfaceDefaultState(),
				[ def.reviewRequestTokenKey ]: 4,
			};
			expect(
				reduceExecutableSurfaceState(
					staleReviewState,
					actions[ def.methodNames.setReviewFreshnessState ](
						'stale',
						3,
						'server-review'
					)
				)
			).toBe( staleReviewState );

			const afterStaleReview = reduceExecutableSurfaceState(
				afterRecommendations,
				actions[ def.methodNames.setReviewFreshnessState ](
					'stale',
					5,
					'server-review'
				)
			);
			expect( afterStaleReview[ def.reviewFreshnessStatusKey ] ).toBe(
				'stale'
			);
			expect( afterStaleReview[ def.reviewStaleReasonKey ] ).toBe(
				'server-review'
			);
			expect( afterStaleReview[ def.reviewRequestTokenKey ] ).toBe( 5 );

			const afterFreshReview = reduceExecutableSurfaceState(
				afterStaleReview,
				actions[ def.methodNames.setReviewFreshnessState ]( 'fresh', 6 )
			);
			expect( afterFreshReview[ def.reviewFreshnessStatusKey ] ).toBe(
				'fresh'
			);
			expect( afterFreshReview[ def.reviewStaleReasonKey ] ).toBeNull();
			expect( afterFreshReview[ def.reviewRequestTokenKey ] ).toBe( 6 );

			const selectedSuggestionState = {
				...createExecutableSurfaceDefaultState(),
				[ def.applyStatusKey ]: 'error',
				[ def.applyErrorKey ]: 'apply failed',
			};
			const afterSelectedSuggestion = reduceExecutableSurfaceState(
				selectedSuggestionState,
				actions[ def.methodNames.setSelectedSuggestion ](
					'suggestion-1'
				)
			);
			expect( afterSelectedSuggestion[ def.selectedSuggestionKey ] ).toBe(
				'suggestion-1'
			);
			expect( afterSelectedSuggestion[ def.applyStatusKey ] ).toBe(
				'idle'
			);
			expect( afterSelectedSuggestion[ def.applyErrorKey ] ).toBeNull();

			const applyState = {
				...createExecutableSurfaceDefaultState(),
				[ def.lastAppliedSuggestionKey ]: 'previous-suggestion',
				[ def.lastAppliedOperationsKey ]: [
					{
						op: 'old-operation',
					},
				],
			};
			const successfulApply = reduceExecutableSurfaceState(
				applyState,
				actions[ def.methodNames.setApplyState ](
					'success',
					null,
					'suggestion-2',
					[
						{
							op: 'replace',
						},
					],
					null
				)
			);
			expect( successfulApply[ def.applyStatusKey ] ).toBe( 'success' );
			expect( successfulApply[ def.applyErrorKey ] ).toBeNull();
			expect( successfulApply[ def.lastAppliedSuggestionKey ] ).toBe(
				'suggestion-2'
			);
			expect( successfulApply[ def.lastAppliedOperationsKey ] ).toEqual( [
				{
					op: 'replace',
				},
			] );
			expect( successfulApply[ def.staleReasonKey ] ).toBeNull();

			const failedApply = reduceExecutableSurfaceState(
				successfulApply,
				actions[ def.methodNames.setApplyState ](
					'error',
					'apply failed',
					'suggestion-3',
					[
						{
							op: 'noop',
						},
					],
					'apply-stale'
				)
			);
			expect( failedApply[ def.applyStatusKey ] ).toBe( 'error' );
			expect( failedApply[ def.applyErrorKey ] ).toBe( 'apply failed' );
			expect( failedApply[ def.lastAppliedSuggestionKey ] ).toBe(
				'suggestion-2'
			);
			expect( failedApply[ def.lastAppliedOperationsKey ] ).toEqual( [
				{
					op: 'replace',
				},
			] );
			expect( failedApply[ def.staleReasonKey ] ).toBe( 'apply-stale' );

			const cleared = reduceExecutableSurfaceState( failedApply, {
				type: def.types.clearRecommendations,
			} );
			expect( cleared ).toMatchObject( {
				[ def.collectionKey ]: [],
				[ def.explanationKey ]: '',
				[ def.statusKey ]: 'idle',
				[ def.errorKey ]: null,
				[ def.requestPromptKey ]: '',
				[ def.resultRefKey ]: null,
				[ def.contextSignatureKey ]: null,
				[ def.reviewContextSignatureKey ]: null,
				[ def.resolvedContextSignatureKey ]: null,
				[ def.reviewFreshnessStatusKey ]: 'idle',
				[ def.selectedSuggestionKey ]: null,
				[ def.applyStatusKey ]: 'idle',
				[ def.applyErrorKey ]: null,
				[ def.lastAppliedSuggestionKey ]: null,
				[ def.lastAppliedOperationsKey ]: [],
				[ def.reviewStaleReasonKey ]: null,
				[ def.staleReasonKey ]: null,
			} );
			expect( cleared[ def.requestTokenKey ] ).toBe(
				failedApply[ def.requestTokenKey ] + 1
			);
			expect( cleared[ def.resultTokenKey ] ).toBe(
				failedApply[ def.resultTokenKey ] + 1
			);
			expect( cleared[ def.reviewRequestTokenKey ] ).toBe(
				failedApply[ def.reviewRequestTokenKey ] + 1
			);
		} );
	} );
}
