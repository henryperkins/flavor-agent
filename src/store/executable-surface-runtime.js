import apiFetch from '@wordpress/api-fetch';

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

export function createExecutableSurfaceFetchConfig( {
	abortKey,
	dispatchRecommendations,
	endpoint,
	getRequestToken,
	requestErrorMessage,
	setStatusAction,
} ) {
	return {
		abortKey,
		dispatchRecommendations,
		endpoint,
		getRequestToken,
		requestErrorMessage,
		setErrorState: ( message, requestToken ) =>
			setStatusAction( 'error', message, requestToken ),
		setLoadingState: ( requestToken ) =>
			setStatusAction( 'loading', null, requestToken ),
	};
}

export function createExecutableSurfaceReviewFreshnessConfig( {
	endpoint,
	getReviewRequestToken,
	getStoredRequestSignature,
	getStoredReviewContextSignature,
	setReviewStateAction,
	surface,
} ) {
	return {
		endpoint,
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
	buildActivityEntry,
	endpoint,
	executeSuggestion,
	getStoredRequestSignature,
	getStoredResolvedContextSignature,
	setApplyStateAction,
	surface,
	unexpectedErrorMessage,
} ) {
	return {
		applyFailureMessage,
		buildActivityEntry,
		endpoint,
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
	attachRequestMetaToRecommendationPayload,
	dispatchRecommendations,
	endpoint,
	getReviewContextSignatureFromResponse,
	getResolvedContextSignatureFromResponse,
	getRequestToken,
	requestErrorMessage,
	runAbortableRecommendationRequest,
	setErrorState,
	setLoadingState,
} ) {
	return function fetchExecutableSurfaceRecommendations( input ) {
		return ( { dispatch, select } ) =>
			runAbortableRecommendationRequest( {
				abortKey,
				buildRequest: ( {
					input: requestInput,
					select: registrySelect,
				} ) => {
					const normalizedInput =
						normalizeRequestInput( requestInput );
					const { contextSignature = null, ...requestData } =
						normalizedInput;

					return {
						contextSignature,
						requestData,
						requestToken: getRequestToken( registrySelect ),
					};
				},
				dispatch,
				endpoint,
				input,
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
	endpoint,
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
				localDispatch(
					setReviewState( 'idle', {
						requestToken,
					} )
				);

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
				const result = await apiFetch( {
					path: endpoint,
					method: 'POST',
					data: {
						...requestData,
						resolveSignatureOnly: true,
					},
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
	buildActivityEntry,
	endpoint,
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
					endpoint,
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

			if ( typeof buildActivityEntry === 'function' ) {
				await recordActivityEntry(
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
		getCurrentActivityScope,
		guardSurfaceApplyFreshness,
		guardSurfaceApplyResolvedFreshness,
		recordActivityEntry,
		syncActivitySession,
	}
) {
	return createExecutableSurfaceApplyAction( {
		...config,
		getCurrentActivityScope,
		guardSurfaceApplyFreshness,
		guardSurfaceApplyResolvedFreshness,
		recordActivityEntry,
		syncActivitySession,
	} )( suggestion, currentRequestSignature, liveRequestInput );
}
