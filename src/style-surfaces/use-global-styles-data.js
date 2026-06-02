import { useMemo } from '@wordpress/element';

import {
	getCurrentGlobalStylesId,
	getCurrentThemeBaseGlobalStyles,
} from '../global-styles/selectors';
import { getGlobalStylesUserConfig } from '../utils/style-operations';

// Shared read-only fallbacks: stable identities keep `useGlobalStylesData`
// referentially stable when no config is available, and freezing guards the
// shared instances against accidental mutation by downstream consumers.
export const EMPTY_STYLE_CONFIG = Object.freeze( {
	settings: Object.freeze( {} ),
	styles: Object.freeze( {} ),
	_links: Object.freeze( {} ),
} );
export const EMPTY_STYLE_VARIATIONS = Object.freeze( [] );

function safelyCallSelector( coreSelect, selectorName ) {
	try {
		return coreSelect?.[ selectorName ]?.();
	} catch {
		return undefined;
	}
}

function getCurrentThemeGlobalStylesVariationRecords( coreSelect ) {
	const stableVariations = safelyCallSelector(
		coreSelect,
		'getCurrentThemeGlobalStylesVariations'
	);

	if ( Array.isArray( stableVariations ) ) {
		return stableVariations;
	}

	const experimentalVariations = safelyCallSelector(
		coreSelect,
		'__experimentalGetCurrentThemeGlobalStylesVariations'
	);

	return Array.isArray( experimentalVariations )
		? experimentalVariations
		: null;
}

export function selectGlobalStylesDataDependencies( select ) {
	const coreSelect = select( 'core' ) || {};
	const globalStylesId = getCurrentGlobalStylesId( coreSelect );
	const userConfigRecord = globalStylesId
		? coreSelect?.getEditedEntityRecord?.(
				'root',
				'globalStyles',
				globalStylesId
		  ) ||
		  coreSelect?.getEntityRecord?.(
				'root',
				'globalStyles',
				globalStylesId
		  ) ||
		  null
		: null;

	return {
		globalStylesId,
		userConfigRecord,
		baseConfigRecord: getCurrentThemeBaseGlobalStyles( coreSelect ),
		variationRecords:
			getCurrentThemeGlobalStylesVariationRecords( coreSelect ),
	};
}

export function useGlobalStylesData( registry, dependencies ) {
	const {
		baseConfigRecord,
		globalStylesId,
		userConfigRecord,
		variationRecords,
	} = dependencies || {};

	return useMemo( () => {
		void baseConfigRecord;
		void globalStylesId;
		void userConfigRecord;
		void variationRecords;

		const globalStylesData = getGlobalStylesUserConfig( registry );

		return {
			globalStylesId: globalStylesData?.globalStylesId || '',
			currentConfig: globalStylesData?.userConfig || EMPTY_STYLE_CONFIG,
			mergedConfig: globalStylesData?.mergedConfig || EMPTY_STYLE_CONFIG,
			availableVariations: Array.isArray( globalStylesData?.variations )
				? globalStylesData.variations
				: EMPTY_STYLE_VARIATIONS,
		};
	}, [
		baseConfigRecord,
		globalStylesId,
		registry,
		userConfigRecord,
		variationRecords,
	] );
}
