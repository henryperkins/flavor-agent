import { executeFlavorAgentAbility } from './abilities-client';
import { buildClientRequestIdentity } from './client-request-identity';
import { deriveDocsGroundingWarning } from '../utils/docs-grounding-warning';
import { normalizeRequestErrorDetails } from './request-error-details';
import { buildContextSignature } from '../utils/context-signature';
import { getEditorBlockIdentity } from '../utils/editor-block-identity';
import {
	getCurrentGlobalStylesId,
	getCurrentThemeBaseGlobalStyles,
	getCurrentThemeGlobalStylesVariations,
} from '../global-styles/selectors';

// Read the registry synchronously at both sides of the server preflight. A
// render-time request snapshot alone can miss edits awaiting a React render.
function captureLiveApplyContext( registry, surface, scope ) {
	try {
		const blockEditor = registry?.select?.( 'core/block-editor' ) || {};
		const settings = blockEditor.getSettings?.() || {};
		const context = {
			scope,
			blocks: ( blockEditor.getBlocks?.() || [] ).map(
				getEditorBlockIdentity
			),
			settings: {
				allowedBlockTypes: settings.allowedBlockTypes,
				templateLock: settings.templateLock,
				canLockBlocks: settings.canLockBlocks,
				supportsLayout: settings.supportsLayout,
				features: settings.__experimentalFeatures,
				resolvedFeatures: settings.features,
				layout: settings.layout,
				colors: settings.colors,
				gradients: settings.gradients,
				fontSizes: settings.fontSizes,
			},
		};

		if ( surface === 'global-styles' || surface === 'style-book' ) {
			const core = registry?.select?.( 'core' ) || {};
			const globalStylesId = getCurrentGlobalStylesId( core );
			const record = globalStylesId
				? core.getEditedEntityRecord?.(
						'root',
						'globalStyles',
						globalStylesId
				  ) ||
				  core.getEntityRecord?.(
						'root',
						'globalStyles',
						globalStylesId
				  )
				: null;
			context.globalStyles = {
				globalStylesId,
				settings: record?.settings,
				styles: record?.styles,
				base: getCurrentThemeBaseGlobalStyles( core ),
				variations: getCurrentThemeGlobalStylesVariations( core ),
			};
		}

		return buildContextSignature( context );
	} catch {
		return null;
	}
}

function hasCurrentLiveRequest(
	getLiveRequestState,
	requestSignature,
	requestInputSignature
) {
	if ( typeof getLiveRequestState !== 'function' ) {
		return true;
	}

	try {
		const live = getLiveRequestState();
		return Boolean(
			live &&
				live.requestSignature === requestSignature &&
				live.requestInput &&
				buildContextSignature( live.requestInput ) ===
					requestInputSignature
		);
	} catch {
		return false;
	}
}

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
		setErrorState: ( message, requestToken, errorDetails = null ) =>
			setStatusAction( 'error', message, requestToken, errorDetails ),
		setLoadingState: ( requestToken ) =>
			setStatusAction( 'loading', null, requestToken, null ),
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
			{
				requestToken = null,
				staleReason = null,
				docsGroundingWarning = null,
			} = {}
		) =>
			setReviewStateAction(
				status,
				requestToken,
				staleReason,
				docsGroundingWarning
			),
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
	getStoredRequestToken = null,
	getStoredResultToken = null,
	recordOutcomeAction = null,
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
		getStoredRequestToken,
		getStoredResultToken,
		recordOutcomeAction,
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

function createExecutableSurfaceFetchAction( {
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
					const errorDetails = normalizeRequestErrorDetails( err );

					localDispatch(
						setErrorState(
							errorDetails.message || requestErrorMessage,
							requestToken,
							errorDetails
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

function createExecutableSurfaceReviewFreshnessAction( {
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
				// Server review freshness includes a compact docs-grounding fingerprint,
				// not the full grounded prompt text.
				const result = await executeFlavorAgentAbility(
					abilityName,
					{
						...requestData,
						resolveSignatureOnly: true,
						clientRequest: buildClientRequestIdentity( {
							requestData,
							requestToken,
						} ),
					},
					{ forceRest: true }
				);
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

				const docsGroundingWarning = deriveDocsGroundingWarning(
					result?.docsGrounding
				);

				localDispatch(
					setReviewState( 'fresh', {
						requestToken,
						docsGroundingWarning,
					} )
				);

				return {
					ok: true,
					reviewContextSignature,
					surface,
					...( docsGroundingWarning ? { docsGroundingWarning } : {} ),
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

function createExecutableSurfaceApplyAction( {
	applyFailureMessage,
	abilityName,
	buildActivityEntry,
	dispatchToastForActivity = null,
	executeSuggestion,
	getCurrentActivityScope,
	getStoredRequestSignature,
	getStoredResolvedContextSignature,
	getStoredRequestToken,
	getStoredResultToken,
	guardSurfaceApplyFreshness,
	guardSurfaceApplyResolvedFreshness,
	recordOutcomeAction,
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
		liveRequestInput = null,
		getLiveRequestState = null
	) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );
			const recordBlockedOutcome = ( event, reason ) => {
				if ( typeof recordOutcomeAction !== 'function' ) {
					return;
				}

				localDispatch(
					recordOutcomeAction( {
						event,
						surface,
						suggestion,
						reason,
					} )
				);
			};

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
				recordBlockedOutcome(
					'stale_blocked',
					staleApplyResult.staleReason || 'client'
				);
				return staleApplyResult;
			}

			// Capture before awaiting preflight, even when preflight later fails.
			// eslint-disable-next-line @wordpress/no-unused-vars-before-return
			const liveContextSignature = captureLiveApplyContext(
				registry,
				surface,
				scope
			);
			// eslint-disable-next-line @wordpress/no-unused-vars-before-return
			const requestInputSignature = buildContextSignature(
				normalizeRequestInput( liveRequestInput )
			);

			localDispatch( setApplyState( 'applying' ) );

			const storedResolvedContextSignature =
				getStoredResolvedContextSignature( select );
			const storedRequestToken =
				typeof getStoredRequestToken === 'function'
					? getStoredRequestToken( select )
					: null;
			const storedResultToken =
				typeof getStoredResultToken === 'function'
					? getStoredResultToken( select )
					: null;
			const isCurrentResult = ( storedSignature ) => {
				const currentResolvedContextSignature = normalizeStringMessage(
					getStoredResolvedContextSignature( select )
				);
				const currentRequestToken =
					typeof getStoredRequestToken === 'function'
						? getStoredRequestToken( select )
						: null;
				const currentResultToken =
					typeof getStoredResultToken === 'function'
						? getStoredResultToken( select )
						: null;

				return (
					currentResolvedContextSignature === storedSignature &&
					( storedRequestToken === null ||
						currentRequestToken === storedRequestToken ) &&
					( storedResultToken === null ||
						currentResultToken === storedResultToken )
				);
			};
			const resolvedFreshness = await guardSurfaceApplyResolvedFreshness(
				{
					surface,
					abilityName,
					liveRequestInput,
					storedResolvedContextSignature,
					abortId: surface,
					isCurrent: isCurrentResult,
					localDispatch,
					setApplyState: buildErrorApplyStateAction,
				}
			);

			if ( ! resolvedFreshness.ok ) {
				if ( ! resolvedFreshness.skipped ) {
					recordBlockedOutcome(
						'stale_blocked',
						resolvedFreshness.staleReason || 'revalidation_failed'
					);
				}
				return resolvedFreshness;
			}

			if (
				! isCurrentResult(
					normalizeStringMessage( storedResolvedContextSignature )
				)
			) {
				return { ok: false, skipped: true, surface };
			}

			if (
				liveContextSignature === null ||
				captureLiveApplyContext(
					registry,
					surface,
					getCurrentActivityScope( registry )
				) !== liveContextSignature ||
				! hasCurrentLiveRequest(
					getLiveRequestState,
					currentRequestSignature,
					requestInputSignature
				)
			) {
				const error =
					'This editor context changed while the suggestion was being checked. Refresh the recommendations before applying.';
				localDispatch(
					buildErrorApplyStateAction( 'error', error, 'client' )
				);
				recordBlockedOutcome( 'stale_blocked', 'client' );
				return { ok: false, error, staleReason: 'client', surface };
			}

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
				recordBlockedOutcome(
					'validation_blocked',
					normalizeStringMessage( result?.code ) ||
						'operation_validation_failed'
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
	},
	getLiveRequestState = null
) {
	return createExecutableSurfaceApplyAction( {
		...config,
		dispatchToastForActivity,
		getCurrentActivityScope,
		guardSurfaceApplyFreshness,
		guardSurfaceApplyResolvedFreshness,
		recordActivityEntry,
		syncActivitySession,
	} )(
		suggestion,
		currentRequestSignature,
		liveRequestInput,
		getLiveRequestState
	);
}
