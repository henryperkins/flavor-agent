import {
	buildGlobalStylesRecommendationRequestSignature,
	buildStyleBookRecommendationRequestSignature,
	buildTemplatePartRecommendationRequestSignature,
	buildTemplateRecommendationRequestSignature,
} from '../utils/recommendation-request-signature';
import { applyGlobalStyleSuggestionOperations } from '../utils/style-operations';
import {
	applyTemplatePartSuggestionOperations,
	applyTemplateSuggestionOperations,
} from '../utils/template-actions';
import { getScopeKey } from './activity-session';
import {
	buildGlobalStylesActivityEntryFromStore,
	buildStyleBookActivityEntryFromStore,
	buildTemplateActivityEntryFromStore,
	buildTemplatePartActivityEntryFromStore,
} from './activity-undo';
import {
	buildExecutableSurfaceApplyThunk,
	buildExecutableSurfaceFetchThunk,
	buildExecutableSurfaceReviewFreshnessThunk,
	createExecutableSurfaceApplyConfig,
	createExecutableSurfaceFetchConfig,
	createExecutableSurfaceReviewFreshnessConfig,
} from './executable-surface-runtime';

function buildMethodNames( baseName ) {
	return {
		setStatus: `set${ baseName }Status`,
		setRecommendations: `set${ baseName }Recommendations`,
		setReviewFreshnessState: `set${ baseName }ReviewFreshnessState`,
		setSelectedSuggestion: `set${ baseName }SelectedSuggestion`,
		setApplyState: `set${ baseName }ApplyState`,
		clearRecommendations: `clear${ baseName }Recommendations`,
		fetchRecommendations: `fetch${ baseName }Recommendations`,
		applySuggestion: `apply${ baseName }Suggestion`,
		revalidateReviewFreshness: `revalidate${ baseName }ReviewFreshness`,
		getRecommendations: `get${ baseName }Recommendations`,
		getExplanation: `get${ baseName }Explanation`,
		getError: `get${ baseName }Error`,
		getRequestPrompt: `get${ baseName }RequestPrompt`,
		getResultRef: `get${ baseName }ResultRef`,
		getContextSignature: `get${ baseName }ContextSignature`,
		getReviewContextSignature: `get${ baseName }ReviewContextSignature`,
		getResolvedContextSignature: `get${ baseName }ResolvedContextSignature`,
		getRequestToken: `get${ baseName }RequestToken`,
		getResultToken: `get${ baseName }ResultToken`,
		getReviewRequestToken: `get${ baseName }ReviewRequestToken`,
		getReviewFreshnessStatus: `get${ baseName }ReviewFreshnessStatus`,
		isLoading: `is${ baseName }Loading`,
		getStatus: `get${ baseName }Status`,
		getSelectedSuggestionKey: `get${ baseName }SelectedSuggestionKey`,
		getApplyStatus: `get${ baseName }ApplyStatus`,
		getApplyError: `get${ baseName }ApplyError`,
		isApplying: `is${ baseName }Applying`,
		getLastAppliedSuggestionKey: `get${ baseName }LastAppliedSuggestionKey`,
		getLastAppliedOperations: `get${ baseName }LastAppliedOperations`,
		getReviewStaleReason: `get${ baseName }ReviewStaleReason`,
		getStaleReason: `get${ baseName }StaleReason`,
		getInteractionState: `get${ baseName }InteractionState`,
	};
}

function buildActionTypes( prefix ) {
	return {
		setStatus: `SET_${ prefix }_STATUS`,
		setRecommendations: `SET_${ prefix }_RECS`,
		setReviewFreshnessState: `SET_${ prefix }_REVIEW_FRESHNESS_STATE`,
		setSelectedSuggestion: `SET_${ prefix }_SELECTED_SUGGESTION`,
		setApplyState: `SET_${ prefix }_APPLY_STATE`,
		clearRecommendations: `CLEAR_${ prefix }_RECS`,
	};
}

function createSurfaceDefinition( config ) {
	return {
		...config,
		methodNames: buildMethodNames( config.baseName ),
		types: buildActionTypes( config.actionPrefix ),
	};
}

export const EXECUTABLE_SURFACE_DEFS = Object.freeze( [
	createSurfaceDefinition( {
		key: 'template',
		baseName: 'Template',
		actionPrefix: 'TEMPLATE',
		surface: 'template',
		abortKey: '_templateAbort',
		endpoint: '/flavor-agent/v1/recommend-template',
		requestErrorMessage: 'Template recommendation request failed.',
		applyFailureMessage: 'Template apply failed.',
		unexpectedErrorMessage: 'Template apply failed unexpectedly.',
		inputKey: 'templateRef',
		collectionKey: 'templateRecommendations',
		explanationKey: 'templateExplanation',
		statusKey: 'templateStatus',
		errorKey: 'templateError',
		requestPromptKey: 'templateRequestPrompt',
		resultRefKey: 'templateRef',
		contextSignatureKey: 'templateContextSignature',
		reviewContextSignatureKey: 'templateReviewContextSignature',
		resolvedContextSignatureKey: 'templateResolvedContextSignature',
		requestTokenKey: 'templateRequestToken',
		resultTokenKey: 'templateResultToken',
		reviewRequestTokenKey: 'templateReviewRequestToken',
		reviewFreshnessStatusKey: 'templateReviewFreshnessStatus',
		selectedSuggestionKey: 'templateSelectedSuggestionKey',
		applyStatusKey: 'templateApplyStatus',
		applyErrorKey: 'templateApplyError',
		lastAppliedSuggestionKey: 'templateLastAppliedSuggestionKey',
		lastAppliedOperationsKey: 'templateLastAppliedOperations',
		reviewStaleReasonKey: 'templateReviewStaleReason',
		staleReasonKey: 'templateStaleReason',
		extraStateDefaults: {},
		mapResultState( templateRef ) {
			return {
				templateRef,
			};
		},
		hasResult( state ) {
			return Boolean( state.templateRef );
		},
		buildStoredRequestSignature( select ) {
			return buildTemplateRecommendationRequestSignature( {
				templateRef: select.getTemplateResultRef?.() || '',
				prompt: select.getTemplateRequestPrompt?.() || '',
				contextSignature:
					select.getTemplateContextSignature?.() || null,
			} );
		},
		executeSuggestion( { suggestion } ) {
			return applyTemplateSuggestionOperations( suggestion );
		},
		buildActivityEntry: buildTemplateActivityEntryFromStore,
		extraSelectors: [],
	} ),
	createSurfaceDefinition( {
		key: 'templatePart',
		baseName: 'TemplatePart',
		actionPrefix: 'TEMPLATE_PART',
		surface: 'template-part',
		abortKey: '_templatePartAbort',
		endpoint: '/flavor-agent/v1/recommend-template-part',
		requestErrorMessage: 'Template-part recommendation request failed.',
		applyFailureMessage: 'Template-part apply failed.',
		unexpectedErrorMessage: 'Template-part apply failed unexpectedly.',
		inputKey: 'templatePartRef',
		collectionKey: 'templatePartRecommendations',
		explanationKey: 'templatePartExplanation',
		statusKey: 'templatePartStatus',
		errorKey: 'templatePartError',
		requestPromptKey: 'templatePartRequestPrompt',
		resultRefKey: 'templatePartRef',
		contextSignatureKey: 'templatePartContextSignature',
		reviewContextSignatureKey: 'templatePartReviewContextSignature',
		resolvedContextSignatureKey: 'templatePartResolvedContextSignature',
		requestTokenKey: 'templatePartRequestToken',
		resultTokenKey: 'templatePartResultToken',
		reviewRequestTokenKey: 'templatePartReviewRequestToken',
		reviewFreshnessStatusKey: 'templatePartReviewFreshnessStatus',
		selectedSuggestionKey: 'templatePartSelectedSuggestionKey',
		applyStatusKey: 'templatePartApplyStatus',
		applyErrorKey: 'templatePartApplyError',
		lastAppliedSuggestionKey: 'templatePartLastAppliedSuggestionKey',
		lastAppliedOperationsKey: 'templatePartLastAppliedOperations',
		reviewStaleReasonKey: 'templatePartReviewStaleReason',
		staleReasonKey: 'templatePartStaleReason',
		extraStateDefaults: {},
		mapResultState( templatePartRef ) {
			return {
				templatePartRef,
			};
		},
		hasResult( state ) {
			return Boolean( state.templatePartRef );
		},
		buildStoredRequestSignature( select ) {
			return buildTemplatePartRecommendationRequestSignature( {
				templatePartRef: select.getTemplatePartResultRef?.() || '',
				prompt: select.getTemplatePartRequestPrompt?.() || '',
				contextSignature:
					select.getTemplatePartContextSignature?.() || null,
			} );
		},
		executeSuggestion( { suggestion } ) {
			return applyTemplatePartSuggestionOperations( suggestion );
		},
		buildActivityEntry: buildTemplatePartActivityEntryFromStore,
		extraSelectors: [],
	} ),
	createSurfaceDefinition( {
		key: 'globalStyles',
		baseName: 'GlobalStyles',
		actionPrefix: 'GLOBAL_STYLES',
		surface: 'global-styles',
		abortKey: '_globalStylesAbort',
		endpoint: '/flavor-agent/v1/recommend-style',
		requestErrorMessage: 'Global Styles recommendation request failed.',
		applyFailureMessage: 'Global Styles apply failed.',
		unexpectedErrorMessage: 'Global Styles apply failed unexpectedly.',
		inputKey: 'scope',
		collectionKey: 'globalStylesSuggestions',
		explanationKey: 'globalStylesExplanation',
		statusKey: 'globalStylesStatus',
		errorKey: 'globalStylesError',
		requestPromptKey: 'globalStylesRequestPrompt',
		resultRefKey: 'globalStylesEntityId',
		contextSignatureKey: 'globalStylesContextSignature',
		reviewContextSignatureKey: 'globalStylesReviewContextSignature',
		resolvedContextSignatureKey: 'globalStylesResolvedContextSignature',
		requestTokenKey: 'globalStylesRequestToken',
		resultTokenKey: 'globalStylesResultToken',
		reviewRequestTokenKey: 'globalStylesReviewRequestToken',
		reviewFreshnessStatusKey: 'globalStylesReviewFreshnessStatus',
		selectedSuggestionKey: 'globalStylesSelectedSuggestionKey',
		applyStatusKey: 'globalStylesApplyStatus',
		applyErrorKey: 'globalStylesApplyError',
		lastAppliedSuggestionKey: 'globalStylesLastAppliedSuggestionKey',
		lastAppliedOperationsKey: 'globalStylesLastAppliedOperations',
		reviewStaleReasonKey: 'globalStylesReviewStaleReason',
		staleReasonKey: 'globalStylesStaleReason',
		extraStateDefaults: {
			globalStylesScopeKey: null,
		},
		mapResultState( scope ) {
			return {
				globalStylesScopeKey: getScopeKey( scope ),
				globalStylesEntityId:
					scope?.globalStylesId || scope?.entityId || null,
			};
		},
		hasResult( state ) {
			return Boolean( state.globalStylesEntityId );
		},
		buildStoredRequestSignature( select ) {
			return buildGlobalStylesRecommendationRequestSignature( {
				scope: {
					scopeKey: select.getGlobalStylesScopeKey?.() || '',
					globalStylesId: select.getGlobalStylesResultRef?.() || '',
					entityId: select.getGlobalStylesResultRef?.() || '',
				},
				prompt: select.getGlobalStylesRequestPrompt?.() || '',
				contextSignature:
					select.getGlobalStylesContextSignature?.() || null,
			} );
		},
		executeSuggestion( { registry, suggestion } ) {
			return applyGlobalStyleSuggestionOperations( suggestion, registry, {
				surface: 'global-styles',
			} );
		},
		buildActivityEntry: buildGlobalStylesActivityEntryFromStore,
		extraSelectors: [
			{
				name: 'getGlobalStylesScopeKey',
				key: 'globalStylesScopeKey',
			},
		],
	} ),
	createSurfaceDefinition( {
		key: 'styleBook',
		baseName: 'StyleBook',
		actionPrefix: 'STYLE_BOOK',
		surface: 'style-book',
		abortKey: '_styleBookAbort',
		endpoint: '/flavor-agent/v1/recommend-style',
		requestErrorMessage: 'Style Book recommendation request failed.',
		applyFailureMessage: 'Style Book apply failed.',
		unexpectedErrorMessage: 'Style Book apply failed unexpectedly.',
		inputKey: 'scope',
		collectionKey: 'styleBookSuggestions',
		explanationKey: 'styleBookExplanation',
		statusKey: 'styleBookStatus',
		errorKey: 'styleBookError',
		requestPromptKey: 'styleBookRequestPrompt',
		resultRefKey: 'styleBookScopeKey',
		contextSignatureKey: 'styleBookContextSignature',
		reviewContextSignatureKey: 'styleBookReviewContextSignature',
		resolvedContextSignatureKey: 'styleBookResolvedContextSignature',
		requestTokenKey: 'styleBookRequestToken',
		resultTokenKey: 'styleBookResultToken',
		reviewRequestTokenKey: 'styleBookReviewRequestToken',
		reviewFreshnessStatusKey: 'styleBookReviewFreshnessStatus',
		selectedSuggestionKey: 'styleBookSelectedSuggestionKey',
		applyStatusKey: 'styleBookApplyStatus',
		applyErrorKey: 'styleBookApplyError',
		lastAppliedSuggestionKey: 'styleBookLastAppliedSuggestionKey',
		lastAppliedOperationsKey: 'styleBookLastAppliedOperations',
		reviewStaleReasonKey: 'styleBookReviewStaleReason',
		staleReasonKey: 'styleBookStaleReason',
		extraStateDefaults: {
			styleBookGlobalStylesId: null,
			styleBookBlockName: null,
			styleBookBlockTitle: '',
		},
		mapResultState( scope ) {
			return {
				styleBookScopeKey: getScopeKey( scope ),
				styleBookGlobalStylesId: scope?.globalStylesId || null,
				styleBookBlockName: scope?.blockName || null,
				styleBookBlockTitle: scope?.blockTitle || '',
			};
		},
		hasResult( state ) {
			return Boolean( state.styleBookScopeKey );
		},
		buildStoredRequestSignature( select ) {
			return buildStyleBookRecommendationRequestSignature( {
				scope: {
					scopeKey: select.getStyleBookScopeKey?.() || '',
					globalStylesId:
						select.getStyleBookGlobalStylesId?.() || '',
					entityId: select.getStyleBookGlobalStylesId?.() || '',
					blockName: select.getStyleBookBlockName?.() || '',
				},
				prompt: select.getStyleBookRequestPrompt?.() || '',
				contextSignature:
					select.getStyleBookContextSignature?.() || null,
			} );
		},
		executeSuggestion( { registry, suggestion } ) {
			return applyGlobalStyleSuggestionOperations( suggestion, registry, {
				surface: 'style-book',
			} );
		},
		buildActivityEntry: buildStyleBookActivityEntryFromStore,
		extraSelectors: [
			{
				name: 'getStyleBookScopeKey',
				key: 'styleBookScopeKey',
			},
			{
				name: 'getStyleBookGlobalStylesId',
				key: 'styleBookGlobalStylesId',
			},
			{
				name: 'getStyleBookBlockName',
				key: 'styleBookBlockName',
			},
			{
				name: 'getStyleBookBlockTitle',
				key: 'styleBookBlockTitle',
			},
		],
	} ),
] );

function buildSurfaceDefaultState( def ) {
	return {
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
	};
}

export function createExecutableSurfaceDefaultState() {
	return EXECUTABLE_SURFACE_DEFS.reduce(
		( state, def ) => ( {
			...state,
			...buildSurfaceDefaultState( def ),
		} ),
		{}
	);
}

export function createExecutableSurfaceStateActionCreators( runtime ) {
	return EXECUTABLE_SURFACE_DEFS.reduce( ( actionCreators, def ) => {
		actionCreators[ def.methodNames.setStatus ] = function setStatus(
			status,
			error = null,
			requestToken = null
		) {
			return {
				type: def.types.setStatus,
				status,
				error,
				requestToken,
			};
		};

		actionCreators[ def.methodNames.setRecommendations ] =
			function setRecommendations(
				value,
				payload,
				prompt = '',
				requestToken = null,
				contextSignature = null,
				reviewContextSignature = null,
				resolvedContextSignature = null
			) {
				return {
					type: def.types.setRecommendations,
					[ def.inputKey ]: value,
					payload,
					prompt,
					requestToken,
					contextSignature,
					reviewContextSignature,
					resolvedContextSignature,
				};
			};

		actionCreators[ def.methodNames.setReviewFreshnessState ] =
			function setReviewFreshnessState(
				status,
				requestToken = null,
				staleReason = null
			) {
				return {
					type: def.types.setReviewFreshnessState,
					status,
					requestToken,
					staleReason,
				};
			};

		actionCreators[ def.methodNames.setSelectedSuggestion ] =
			function setSelectedSuggestion( suggestionKey = null ) {
				return {
					type: def.types.setSelectedSuggestion,
					suggestionKey,
				};
			};

		actionCreators[ def.methodNames.setApplyState ] = function setApplyState(
			status,
			error = null,
			suggestionKey = null,
			operations = [],
			staleReason = null
		) {
			return {
				type: def.types.setApplyState,
				status,
				error,
				suggestionKey,
				operations,
				staleReason,
			};
		};

		actionCreators[ def.methodNames.clearRecommendations ] =
			function clearRecommendations() {
				return ( { dispatch } ) => {
					if ( runtime[ def.abortKey ] ) {
						runtime[ def.abortKey ].abort();
						runtime[ def.abortKey ] = null;
					}

					dispatch( {
						type: def.types.clearRecommendations,
					} );
				};
			};

		return actionCreators;
	}, {} );
}

function dispatchRecommendationsForSurface( def, actions, params ) {
	params.dispatch(
		actions[ def.methodNames.setRecommendations ](
			params.input?.[ def.inputKey ],
			params.payload,
			params.input?.prompt || '',
			params.requestToken,
			params.contextSignature,
			params.reviewContextSignature,
			params.resolvedContextSignature
		)
	);
}

function createFetchConfig( def, actions ) {
	return createExecutableSurfaceFetchConfig( {
		abortKey: def.abortKey,
		dispatchRecommendations: ( params ) =>
			dispatchRecommendationsForSurface( def, actions, params ),
		endpoint: def.endpoint,
		getRequestToken: ( select ) =>
			( select[ def.methodNames.getRequestToken ]?.() || 0 ) + 1,
		requestErrorMessage: def.requestErrorMessage,
		setStatusAction: actions[ def.methodNames.setStatus ],
	} );
}

function createApplyConfig( def, actions ) {
	return createExecutableSurfaceApplyConfig( {
		applyFailureMessage: def.applyFailureMessage,
		buildActivityEntry: def.buildActivityEntry,
		endpoint: def.endpoint,
		executeSuggestion: def.executeSuggestion,
		getStoredRequestSignature: def.buildStoredRequestSignature,
		getStoredResolvedContextSignature: ( select ) =>
			select[ def.methodNames.getResolvedContextSignature ]?.() || null,
		setApplyStateAction: actions[ def.methodNames.setApplyState ],
		surface: def.surface,
		unexpectedErrorMessage: def.unexpectedErrorMessage,
	} );
}

function createReviewConfig( def, actions ) {
	return createExecutableSurfaceReviewFreshnessConfig( {
		endpoint: def.endpoint,
		getReviewRequestToken: ( select ) =>
			select[ def.methodNames.getReviewRequestToken ]?.() || 0,
		getStoredRequestSignature: def.buildStoredRequestSignature,
		getStoredReviewContextSignature: ( select ) =>
			select[ def.methodNames.getReviewContextSignature ]?.() || null,
		setReviewStateAction: actions[ def.methodNames.setReviewFreshnessState ],
		surface: def.surface,
	} );
}

export function createExecutableSurfaceRuntimeActionCreators(
	actions,
	{ fetchDeps, applyDeps, reviewDeps }
) {
	return EXECUTABLE_SURFACE_DEFS.reduce( ( actionCreators, def ) => {
		actionCreators[ def.methodNames.fetchRecommendations ] =
			function fetchRecommendations( input ) {
				return buildExecutableSurfaceFetchThunk(
					createFetchConfig( def, actions ),
					input,
					fetchDeps
				);
			};

		actionCreators[ def.methodNames.applySuggestion ] =
			function applySuggestion(
				suggestion,
				currentRequestSignature = null,
				liveRequestInput = null
			) {
				return buildExecutableSurfaceApplyThunk(
					createApplyConfig( def, actions ),
					suggestion,
					currentRequestSignature,
					liveRequestInput,
					applyDeps
				);
			};

		actionCreators[ def.methodNames.revalidateReviewFreshness ] =
			function revalidateReviewFreshness(
				currentRequestSignature = null,
				liveRequestInput = null
			) {
				return buildExecutableSurfaceReviewFreshnessThunk(
					createReviewConfig( def, actions ),
					currentRequestSignature,
					liveRequestInput,
					reviewDeps
				);
			};

		return actionCreators;
	}, {} );
}

function isStaleRequest( state, def, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state[ def.requestTokenKey ] || 0 );
}

function isStaleReviewRequest( state, def, requestToken ) {
	if ( requestToken === null || requestToken === undefined ) {
		return false;
	}

	return requestToken < ( state[ def.reviewRequestTokenKey ] || 0 );
}

function reduceExecutableSurface( state, action, def ) {
	if ( action.type === def.types.setStatus ) {
		if ( isStaleRequest( state, def, action.requestToken ) ) {
			return state;
		}

		return {
			...state,
			[ def.statusKey ]: action.status,
			[ def.errorKey ]: action.error ?? null,
			[ def.requestTokenKey ]:
				action.requestToken ?? state[ def.requestTokenKey ],
			[ def.applyStatusKey ]:
				action.status === 'loading'
					? 'idle'
					: state[ def.applyStatusKey ],
			[ def.applyErrorKey ]:
				action.status === 'loading'
					? null
					: state[ def.applyErrorKey ],
			[ def.lastAppliedSuggestionKey ]:
				action.status === 'loading'
					? null
					: state[ def.lastAppliedSuggestionKey ],
			[ def.lastAppliedOperationsKey ]:
				action.status === 'loading'
					? []
					: state[ def.lastAppliedOperationsKey ],
			[ def.reviewRequestTokenKey ]:
				action.status === 'loading'
					? state[ def.reviewRequestTokenKey ] + 1
					: state[ def.reviewRequestTokenKey ],
			[ def.reviewFreshnessStatusKey ]:
				action.status === 'loading'
					? 'idle'
					: state[ def.reviewFreshnessStatusKey ],
			[ def.reviewStaleReasonKey ]:
				action.status === 'loading'
					? null
					: state[ def.reviewStaleReasonKey ],
			[ def.staleReasonKey ]:
				action.status === 'loading'
					? null
					: state[ def.staleReasonKey ],
		};
	}

	if ( action.type === def.types.setRecommendations ) {
		if ( isStaleRequest( state, def, action.requestToken ) ) {
			return state;
		}

		return {
			...state,
			[ def.collectionKey ]: action.payload?.suggestions ?? [],
			[ def.explanationKey ]: action.payload?.explanation ?? '',
			[ def.requestPromptKey ]: action.prompt ?? '',
			[ def.contextSignatureKey ]: action.contextSignature ?? null,
			[ def.reviewContextSignatureKey ]:
				action.reviewContextSignature ?? null,
			[ def.resolvedContextSignatureKey ]:
				action.resolvedContextSignature ?? null,
			[ def.requestTokenKey ]:
				action.requestToken ?? state[ def.requestTokenKey ],
			[ def.resultTokenKey ]: state[ def.resultTokenKey ] + 1,
			[ def.reviewRequestTokenKey ]:
				state[ def.reviewRequestTokenKey ] + 1,
			[ def.reviewFreshnessStatusKey ]: action.reviewContextSignature
				? 'fresh'
				: 'idle',
			[ def.statusKey ]: 'ready',
			[ def.errorKey ]: null,
			[ def.selectedSuggestionKey ]: null,
			[ def.applyStatusKey ]: 'idle',
			[ def.applyErrorKey ]: null,
			[ def.lastAppliedSuggestionKey ]: null,
			[ def.lastAppliedOperationsKey ]: [],
			[ def.reviewStaleReasonKey ]: null,
			[ def.staleReasonKey ]: null,
			...def.mapResultState( action[ def.inputKey ] ),
		};
	}

	if ( action.type === def.types.setReviewFreshnessState ) {
		if ( isStaleReviewRequest( state, def, action.requestToken ) ) {
			return state;
		}

		return {
			...state,
			[ def.reviewRequestTokenKey ]:
				action.requestToken ?? state[ def.reviewRequestTokenKey ],
			[ def.reviewFreshnessStatusKey ]:
				action.status ?? state[ def.reviewFreshnessStatusKey ],
			[ def.reviewStaleReasonKey ]:
				action.status === 'stale'
					? action.staleReason ?? null
					: null,
		};
	}

	if ( action.type === def.types.setSelectedSuggestion ) {
		return {
			...state,
			[ def.selectedSuggestionKey ]: action.suggestionKey ?? null,
			[ def.applyStatusKey ]:
				state[ def.applyStatusKey ] === 'error'
					? 'idle'
					: state[ def.applyStatusKey ],
			[ def.applyErrorKey ]:
				state[ def.applyStatusKey ] === 'error'
					? null
					: state[ def.applyErrorKey ],
		};
	}

	if ( action.type === def.types.setApplyState ) {
		return {
			...state,
			[ def.applyStatusKey ]: action.status,
			[ def.applyErrorKey ]: action.error ?? null,
			[ def.lastAppliedSuggestionKey ]:
				action.status === 'success'
					? action.suggestionKey ?? null
					: state[ def.lastAppliedSuggestionKey ],
			[ def.lastAppliedOperationsKey ]:
				action.status === 'success'
					? action.operations ?? []
					: state[ def.lastAppliedOperationsKey ],
			[ def.staleReasonKey ]:
				action.status === 'error'
					? action.staleReason ?? null
					: null,
		};
	}

	if ( action.type === def.types.clearRecommendations ) {
		return {
			...state,
			...buildSurfaceDefaultState( def ),
			[ def.requestTokenKey ]: state[ def.requestTokenKey ] + 1,
			[ def.resultTokenKey ]: state[ def.resultTokenKey ] + 1,
			[ def.reviewRequestTokenKey ]: state[ def.reviewRequestTokenKey ] + 1,
		};
	}

	return null;
}

export function reduceExecutableSurfaceState( state, action ) {
	for ( const def of EXECUTABLE_SURFACE_DEFS ) {
		const nextState = reduceExecutableSurface( state, action, def );

		if ( nextState ) {
			return nextState;
		}
	}

	return null;
}

export function createExecutableSurfaceSelectors( {
	getNormalizedInteractionState,
	normalizeStringMessage,
} ) {
	return EXECUTABLE_SURFACE_DEFS.reduce( ( selectors, def ) => {
		selectors[ def.methodNames.getRecommendations ] = ( state ) =>
			state[ def.collectionKey ];
		selectors[ def.methodNames.getExplanation ] = ( state ) =>
			state[ def.explanationKey ];
		selectors[ def.methodNames.getError ] = ( state ) =>
			state[ def.errorKey ];
		selectors[ def.methodNames.getRequestPrompt ] = ( state ) =>
			state[ def.requestPromptKey ];
		selectors[ def.methodNames.getResultRef ] = ( state ) =>
			state[ def.resultRefKey ];
		selectors[ def.methodNames.getContextSignature ] = ( state ) =>
			state[ def.contextSignatureKey ];
		selectors[ def.methodNames.getReviewContextSignature ] = ( state ) =>
			state[ def.reviewContextSignatureKey ];
		selectors[ def.methodNames.getResolvedContextSignature ] = ( state ) =>
			state[ def.resolvedContextSignatureKey ];
		selectors[ def.methodNames.getRequestToken ] = ( state ) =>
			state[ def.requestTokenKey ];
		selectors[ def.methodNames.getResultToken ] = ( state ) =>
			state[ def.resultTokenKey ];
		selectors[ def.methodNames.getReviewRequestToken ] = ( state ) =>
			state[ def.reviewRequestTokenKey ];
		selectors[ def.methodNames.getReviewFreshnessStatus ] = ( state ) =>
			state[ def.reviewFreshnessStatusKey ];
		selectors[ def.methodNames.isLoading ] = ( state ) =>
			state[ def.statusKey ] === 'loading';
		selectors[ def.methodNames.getStatus ] = ( state ) =>
			state[ def.statusKey ];
		selectors[ def.methodNames.getSelectedSuggestionKey ] = ( state ) =>
			state[ def.selectedSuggestionKey ];
		selectors[ def.methodNames.getApplyStatus ] = ( state ) =>
			state[ def.applyStatusKey ];
		selectors[ def.methodNames.getApplyError ] = ( state ) =>
			state[ def.applyErrorKey ];
		selectors[ def.methodNames.isApplying ] = ( state ) =>
			state[ def.applyStatusKey ] === 'applying';
		selectors[ def.methodNames.getLastAppliedSuggestionKey ] = ( state ) =>
			state[ def.lastAppliedSuggestionKey ];
		selectors[ def.methodNames.getLastAppliedOperations ] = ( state ) =>
			state[ def.lastAppliedOperationsKey ];
		selectors[ def.methodNames.getReviewStaleReason ] = ( state ) =>
			state[ def.reviewStaleReasonKey ];
		selectors[ def.methodNames.getStaleReason ] = ( state ) =>
			state[ def.staleReasonKey ];
		selectors[ def.methodNames.getInteractionState ] = (
			state,
			options = {}
		) =>
			getNormalizedInteractionState( def.surface, {
				requestStatus: state[ def.statusKey ],
				requestError: normalizeStringMessage( state[ def.errorKey ] ),
				applyStatus: state[ def.applyStatusKey ],
				applyError: normalizeStringMessage( state[ def.applyErrorKey ] ),
				undoStatus: state.undoStatus,
				undoError: normalizeStringMessage( options.undoError ),
				hasResult: def.hasResult( state ),
				hasPreview: Boolean(
					options.hasPreview ?? state[ def.selectedSuggestionKey ]
				),
				hasSuccess: Boolean( options.hasSuccess ),
				hasUndoSuccess: Boolean( options.hasUndoSuccess ),
				...options,
			} );

		for ( const extraSelector of def.extraSelectors ) {
			selectors[ extraSelector.name ] = ( state ) =>
				state[ extraSelector.key ];
		}

		return selectors;
	}, {} );
}
