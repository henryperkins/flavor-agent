import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

import { getLiveBlockContextData } from '../context/collector';
import { buildBlockRecommendationRequestData } from './block-recommendation-request';

const EMPTY_LIVE_CONTEXT_DATA = Object.freeze( {
	context: null,
	signature: '',
} );

const EMPTY_REQUEST_DATA = Object.freeze( {
	liveContext: null,
	liveContextSignature: '',
	currentRequestSignature: null,
	currentRequestInput: null,
} );

function hasProvidedRequestData( requestData ) {
	return Boolean(
		requestData &&
			( requestData.liveContext ||
				requestData.liveContextSignature ||
				requestData.currentRequestSignature ||
				requestData.currentRequestInput )
	);
}

export default function useBlockRecommendationRequestData( {
	clientId,
	enabled = true,
	prompt = '',
	requestData = null,
} ) {
	const hasProvidedData = hasProvidedRequestData( requestData );
	const liveData = useSelect(
		( select ) => {
			if ( ! enabled || hasProvidedData ) {
				return EMPTY_LIVE_CONTEXT_DATA;
			}

			return getLiveBlockContextData( select, clientId );
		},
		[ clientId, enabled, hasProvidedData ]
	);
	const liveContext = hasProvidedData
		? requestData.liveContext ||
		  requestData.currentRequestInput?.editorContext ||
		  null
		: liveData.context;
	const liveContextSignature = hasProvidedData
		? requestData.liveContextSignature ||
		  requestData.currentRequestInput?.contextSignature ||
		  ''
		: liveData.signature;

	return useMemo( () => {
		if ( ! enabled ) {
			return EMPTY_REQUEST_DATA;
		}

		if (
			hasProvidedData &&
			requestData.currentRequestSignature !== undefined &&
			requestData.currentRequestInput !== undefined
		) {
			return {
				liveContext,
				liveContextSignature,
				currentRequestSignature: requestData.currentRequestSignature,
				currentRequestInput: requestData.currentRequestInput,
			};
		}

		const { requestSignature, requestInput } =
			buildBlockRecommendationRequestData( {
				clientId,
				liveContext,
				liveContextSignature,
				prompt,
			} );

		return {
			liveContext,
			liveContextSignature,
			currentRequestSignature: requestSignature,
			currentRequestInput: requestInput,
		};
	}, [
		clientId,
		enabled,
		hasProvidedData,
		liveContext,
		liveContextSignature,
		prompt,
		requestData,
	] );
}
