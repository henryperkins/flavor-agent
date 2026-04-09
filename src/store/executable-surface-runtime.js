function normalizeRequestInput( requestInput ) {
	return requestInput && typeof requestInput === 'object' ? requestInput : {};
}

// Keep the shared lifecycle generic while leaving selector/action wiring to
// each surface adapter in the main store module.
export function createExecutableSurfaceFetchAction( {
	abortKey,
	attachRequestMetaToRecommendationPayload,
	dispatchRecommendations,
	endpoint,
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
						resolvedContextSignature:
							getResolvedContextSignatureFromResponse( result ),
					} );
				},
				select,
			} );
	};
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
