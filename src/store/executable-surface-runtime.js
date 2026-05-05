import { executeFlavorAgentAbility } from './abilities-client';

const CLIENT_REQUEST_SESSION_ID = `flavor-agent-${ Date.now() }-${ Math.random()
	.toString( 36 )
	.slice( 2 ) }`;

function normalizeRequestInput( requestInput ) {
	return requestInput && typeof requestInput === 'object' ? requestInput : {};
}

function normalizeStringMessage( value ) {
	return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function stripContextSignatureFromRequestInput( requestInput = null ) {
	const normalizedInput = normalizeRequestInput( requestInput );
	const { contextSignature, ...requestData } = normalizedInput;

	void contextSignature;

	return requestData;
}

function buildClientRequestIdentity( {
	abortId = null,
	requestData = {},
	requestToken = null,
} = {} ) {
	return {
		sessionId: CLIENT_REQUEST_SESSION_ID,
		requestToken: Number.isFinite( requestToken ) ? requestToken : null,
		abortId:
			abortId === null || abortId === undefined ? '' : String( abortId ),
		scopeKey: requestData?.document?.scopeKey || '',
	};
}

export function createExecutableSurfaceFetchConfig( {
	abortKey,
	abilityName,
	buildRequestDocument,
	dispatchRecommendations,
	getRequestToken,
	requestErrorMessage,
	setStatusAction,
} ) {
	return {
		abortKey,
		abilityName,
		buildRequestDocument,
		dispatchRecommendations,
		getRequestToken,
		requestErrorMessage,
		setErrorState: ( message, requestToken ) =>
			setStatusAction( 'error', message, requestToken ),
		setLoadingState: ( requestToken ) =>
			setStatusAction( 'loading', null, requestToken ),
	};
}

export function createExecutableSurfaceReviewFreshnessConfig( {
	abilityName,
	getReviewRequestToken,
	getStoredRequestSignature,
	getStoredReviewContextSignature,
	setReviewStateAction,
	surface,
} ) {
	return {
		abilityName,
		getReviewRequestToken,
		getStoredRequestSignature,
		getStoredReviewContextSignature,
		setReviewState: (
			status,
			{ requestToken = null, staleReason = null } = {}
		) => setReviewStateAction( status, requestToken, staleReason ),
		surface,
	};
}

export function createExecutableSurfaceApplyConfig( {
	applyFailureMessage,
	abilityName,
	buildActivityEntry,
	executeSuggestion,
	getStoredRequestSignature,
	getStoredResolvedContextSignature,
	setApplyStateAction,
	surface,
	unexpectedErrorMessage,
} ) {
	return {
		applyFailureMessage,
		abilityName,
		buildActivityEntry,
		executeSuggestion,
		getStoredRequestSignature,
		getStoredResolvedContextSignature,
		setApplyState: (
			status,
			{
				error = null,
				suggestionKey = null,
				operations = [],
				staleReason = null,
			} = {}
		) =>
			setApplyStateAction(
				status,
				error,
				suggestionKey,
				operations,
				staleReason
			),
		surface,
		unexpectedErrorMessage,
	};
}

export function createExecutableSurfaceFetchAction( {
	abortKey,
	abilityName,
	attachRequestMetaToRecommendationPayload,
	buildRequestDocument,
	dispatchRecommendations,
	getReviewContextSignatureFromResponse,
	getResolvedContextSignatureFromResponse,
	getRequestToken,
	requestErrorMessage,
	runAbortableRecommendationRequest,
	setErrorState,
	setLoadingState,
} ) {
	return function fetchExecutableSurfaceRecommendations( input ) {
		return ( { dispatch, registry, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey,
				buildRequest: ( {
					input: requestInput,
					registry: requestRegistry,
					select: registrySelect,
				} ) => {
					const normalizedInput =
						normalizeRequestInput( requestInput );
					const { contextSignature = null, ...requestData } =
						normalizedInput;
					const document =
						typeof buildRequestDocument === 'function'
							? buildRequestDocument( {
									input: normalizedInput,
									registry: requestRegistry,
									requestData,
							  } )
							: null;

					const requestToken = getRequestToken( registrySelect );
					const finalRequestData = document
						? {
								...requestData,
								document,
						  }
						: requestData;

					return {
						contextSignature,
						requestData: {
							...finalRequestData,
							clientRequest: buildClientRequestIdentity( {
								abortId:
									normalizedInput?.templateRef ||
									normalizedInput?.templatePartRef ||
									finalRequestData?.scope?.scopeKey ||
									null,
								requestData: finalRequestData,
								requestToken,
							} ),
						},
						requestToken,
					};
				},
				dispatch,
				abilityName,
				input,
				registry,
				onError: ( { dispatch: localDispatch, err, requestToken } ) => {
					localDispatch(
						setErrorState(
							err?.message || requestErrorMessage,
							requestToken
						)
					);
				},
				onLoading: ( { dispatch: localDispatch, requestToken } ) => {
					localDispatch( setLoadingState( requestToken ) );
				},
				onSuccess: ( {
					contextSignature,
					dispatch: localDispatch,
					input: requestInput,
					requestToken,
					result,
				} ) => {
					dispatchRecommendations( {
						contextSignature,
						dispatch: localDispatch,
						input: normalizeRequestInput( requestInput ),
						payload:
							attachRequestMetaToRecommendationPayload( result ),
						requestToken,
						reviewContextSignature:
							getReviewContextSignatureFromResponse( result ),
						resolvedContextSignature:
							getResolvedContextSignatureFromResponse( result ),
					} );
				},
				select,
			} );
	};
}

export function buildExecutableSurfaceFetchThunk(
	config,
	input,
	{
		attachRequestMetaToRecommendationPayload,
		getReviewContextSignatureFromResponse,
		getResolvedContextSignatureFromResponse,
		runAbortableRecommendationRequest,
	}
) {
	return createExecutableSurfaceFetchAction( {
		...config,
		attachRequestMetaToRecommendationPayload,
		getReviewContextSignatureFromResponse,
		getResolvedContextSignatureFromResponse,
		runAbortableRecommendationRequest,
	} )( input );
}

export function createExecutableSurfaceReviewFreshnessAction( {
	abilityName,
	getReviewContextSignatureFromResponse,
	getReviewRequestToken,
	getStoredRequestSignature,
	getStoredReviewContextSignature,
	setReviewState,
	surface,
} ) {
	return function revalidateExecutableSurfaceReviewFreshness(
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return async ( { dispatch: localDispatch, select } ) => {
			const requestToken = ( getReviewRequestToken( select ) || 0 ) + 1;
			const storedRequestSignature =
				getStoredRequestSignature( select ) || null;
			const storedReviewContextSignature = normalizeStringMessage(
				getStoredReviewContextSignature( select )
			);
			const requestData =
				stripContextSignatureFromRequestInput( liveRequestInput );

			if (
				! storedRequestSignature ||
				! currentRequestSignature ||
				storedRequestSignature !== currentRequestSignature ||
				! storedReviewContextSignature ||
				Object.keys( requestData ).length === 0
			) {
				return {
					ok: false,
					skipped: true,
				};
			}

			localDispatch(
				setReviewState( 'checking', {
					requestToken,
				} )
			);

			try {
				// Server review freshness is based on docs-free server context, not grounded prompt churn.
				const result = await executeFlavorAgentAbility( abilityName, {
					...requestData,
					resolveSignatureOnly: true,
					clientRequest: buildClientRequestIdentity( {
						requestData,
						requestToken,
					} ),
				} );
				const reviewContextSignature = normalizeStringMessage(
					getReviewContextSignatureFromResponse( result )
				);

				if (
					! reviewContextSignature ||
					reviewContextSignature !== storedReviewContextSignature
				) {
					localDispatch(
						setReviewState( 'stale', {
							requestToken,
							staleReason: 'server-review',
						} )
					);

					return {
						ok: false,
						staleReason: 'server-review',
						surface,
					};
				}

				localDispatch(
					setReviewState( 'fresh', {
						requestToken,
					} )
				);

				return {
					ok: true,
					reviewContextSignature,
					surface,
				};
			} catch {
				localDispatch(
					setReviewState( 'idle', {
						requestToken,
					} )
				);

				return {
					ok: false,
					error: 'review-revalidation-failed',
					surface,
				};
			}
		};
	};
}

export function buildExecutableSurfaceReviewFreshnessThunk(
	config,
	currentRequestSignature = null,
	liveRequestInput = null,
	{ getReviewContextSignatureFromResponse }
) {
	return createExecutableSurfaceReviewFreshnessAction( {
		...config,
		getReviewContextSignatureFromResponse,
	} )( currentRequestSignature, liveRequestInput );
}

export function createExecutableSurfaceApplyAction( {
	applyFailureMessage,
	abilityName,
	buildActivityEntry,
	dispatchToastForActivity = null,
	executeSuggestion,
	getCurrentActivityScope,
	getStoredRequestSignature,
	getStoredResolvedContextSignature,
	guardSurfaceApplyFreshness,
	guardSurfaceApplyResolvedFreshness,
	recordActivityEntry,
	setApplyState,
	surface,
	syncActivitySession,
	unexpectedErrorMessage,
} ) {
	const buildErrorApplyStateAction = (
		status,
		error = null,
		staleReason = null
	) =>
		setApplyState( status, {
			error,
			staleReason,
		} );

	return function applyExecutableSurfaceSuggestion(
		suggestion,
		currentRequestSignature = null,
		liveRequestInput = null
	) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession( localDispatch, select, scope );

			const staleApplyResult = guardSurfaceApplyFreshness( {
				surface,
				currentRequestSignature,
				getStoredRequestSignature: () =>
					getStoredRequestSignature( select ),
				localDispatch,
				setApplyState: buildErrorApplyStateAction,
			} );

			if ( staleApplyResult ) {
				return staleApplyResult;
			}

			const resolvedFreshness = await guardSurfaceApplyResolvedFreshness(
				{
					surface,
					abilityName,
					liveRequestInput,
					storedResolvedContextSignature:
						getStoredResolvedContextSignature( select ),
					localDispatch,
					setApplyState: buildErrorApplyStateAction,
				}
			);

			if ( ! resolvedFreshness.ok ) {
				return resolvedFreshness;
			}

			localDispatch( setApplyState( 'applying' ) );

			let result;

			try {
				result = await executeSuggestion( {
					suggestion,
					registry,
					select,
					scope,
				} );
			} catch ( err ) {
				const message = err?.message || unexpectedErrorMessage;

				localDispatch(
					setApplyState( 'error', {
						error: message,
					} )
				);

				return {
					ok: false,
					error: message,
				};
			}

			if ( ! result.ok ) {
				localDispatch(
					setApplyState( 'error', {
						error: result.error || applyFailureMessage,
					} )
				);

				return result;
			}

			let persistedEntry = null;

			if ( typeof buildActivityEntry === 'function' ) {
				persistedEntry = await recordActivityEntry(
					localDispatch,
					select,
					buildActivityEntry( {
						result,
						scope,
						select,
						suggestion,
					} )
				);
			}

			localDispatch(
				setApplyState( 'success', {
					suggestionKey: suggestion?.suggestionKey || null,
					operations: result.operations,
				} )
			);

			if ( typeof dispatchToastForActivity === 'function' ) {
				dispatchToastForActivity( {
					localDispatch,
					persistedEntry,
					surface,
					suggestion,
					extras: { operations: result.operations, result },
				} );
			}

			return result;
		};
	};
}

export function buildExecutableSurfaceApplyThunk(
	config,
	suggestion,
	currentRequestSignature = null,
	liveRequestInput = null,
	{
		dispatchToastForActivity = null,
		getCurrentActivityScope,
		guardSurfaceApplyFreshness,
		guardSurfaceApplyResolvedFreshness,
		recordActivityEntry,
		syncActivitySession,
	}
) {
	return createExecutableSurfaceApplyAction( {
		...config,
		dispatchToastForActivity,
		getCurrentActivityScope,
		guardSurfaceApplyFreshness,
		guardSurfaceApplyResolvedFreshness,
		recordActivityEntry,
		syncActivitySession,
	} )( suggestion, currentRequestSignature, liveRequestInput );
}
