/**
 * Inspector Injector
 *
 * Uses the editor.BlockEdit filter to inject AI recommendation controls
 * into the native Inspector tabs.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

import { STORE_NAME } from '../store';
import { BlockRecommendationsPanel } from './BlockRecommendationsPanel';
import { getBlockRecommendationFreshness } from './block-recommendation-request';
import SuggestionChips from './SuggestionChips';
import {
	SETTINGS_PANEL_DELEGATIONS,
	STYLE_PANEL_DELEGATIONS,
} from './panel-delegation';
import useBlockRecommendationRequestData from './use-block-recommendation-request-data';

const withAIRecommendations = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { clientId, isSelected } = props;
		const [ promptState, setPromptState ] = useState( {
			clientId: null,
			value: '',
		} );
		useEffect( () => {
			setPromptState( ( currentPromptState ) => {
				if (
					currentPromptState.clientId === null &&
					currentPromptState.value === ''
				) {
					return currentPromptState;
				}

				return {
					clientId: null,
					value: '',
				};
			} );
		}, [ clientId ] );

		if ( ! isSelected ) {
			return <BlockEdit { ...props } />;
		}

		return (
			<SelectedAIRecommendations
				BlockEdit={ BlockEdit }
				blockEditProps={ props }
				promptState={ promptState }
				setPromptState={ setPromptState }
			/>
		);
	};
}, 'withAIRecommendations' );

function SelectedAIRecommendations( {
	BlockEdit,
	blockEditProps,
	promptState,
	setPromptState,
} ) {
	const { clientId } = blockEditProps;
	const {
		recommendations,
		editingMode,
		isInsideContentOnly,
		status,
		storedContextSignature,
		storedStaleReason,
	} = useSelect(
		( sel ) => {
			const editor = sel( blockEditorStore );
			const store = sel( STORE_NAME );
			const parentIds = editor.getBlockParents?.( clientId ) || [];

			return {
				recommendations: store.getBlockRecommendations( clientId ),
				status: store.getBlockStatus( clientId ),
				storedContextSignature:
					store.getBlockRecommendationContextSignature( clientId ),
				storedStaleReason:
					store.getBlockStaleReason?.( clientId ) || null,
				editingMode: editor.getBlockEditingMode( clientId ),
				isInsideContentOnly: parentIds.some(
					( parentId ) =>
						editor.getBlockEditingMode?.( parentId ) ===
						'contentOnly'
				),
			};
		},
		[ clientId ]
	);
	const prompt =
		promptState.clientId === clientId
			? promptState.value
			: recommendations?.prompt || '';
	const handlePromptChange = useCallback(
		( nextPrompt ) => {
			setPromptState( {
				clientId,
				value: nextPrompt,
			} );
		},
		[ clientId, setPromptState ]
	);
	const isDisabled = editingMode === 'disabled';
	const requestData = useBlockRecommendationRequestData( {
		clientId,
		enabled: ! isDisabled,
		prompt,
	} );
	const { liveContextSignature } = requestData;
	const { hasFreshResult, hasStoredResult, isStaleResult } = useMemo(
		() =>
			getBlockRecommendationFreshness( {
				clientId,
				recommendations,
				status,
				storedContextSignature,
				storedStaleReason,
				liveContextSignature,
				prompt,
			} ),
		[
			clientId,
			liveContextSignature,
			prompt,
			recommendations,
			status,
			storedContextSignature,
			storedStaleReason,
		]
	);
	const hasMatchingResult = hasStoredResult && hasFreshResult;
	const isContentRestricted =
		editingMode === 'contentOnly' || isInsideContentOnly;
	const hasVisibleResult = hasMatchingResult || isStaleResult;
	const visibleRecommendations = hasVisibleResult ? recommendations : null;
	const visibleSettingsRecommendations = hasVisibleResult
		? recommendations?.settings || []
		: [];
	const visibleDelegatedStyleRecommendations =
		! isContentRestricted && hasVisibleResult
			? recommendations?.styles || []
			: [];

	if ( isDisabled ) {
		return <BlockEdit { ...blockEditProps } />;
	}

	const hasInlineRecs =
		visibleRecommendations &&
		( visibleSettingsRecommendations.length > 0 ||
			visibleDelegatedStyleRecommendations.length > 0 ||
			visibleRecommendations.block?.length > 0 );

	return (
		<>
			<BlockEdit { ...blockEditProps } />

			<InspectorControls>
				<BlockRecommendationsPanel
					clientId={ clientId }
					prompt={ prompt }
					onPromptChange={ handlePromptChange }
					requestData={ requestData }
				/>
			</InspectorControls>

			{ hasInlineRecs && (
				<>
					{ SETTINGS_PANEL_DELEGATIONS.map( ( config ) => (
						<SubPanelSuggestions
							key={ `settings-${ config.group }` }
							{ ...config }
							clientId={ clientId }
							suggestions={ visibleSettingsRecommendations }
							isStale={ isStaleResult }
						/>
					) ) }
					{ STYLE_PANEL_DELEGATIONS.map( ( config ) => (
						<SubPanelSuggestions
							key={ `styles-${ config.group }-${ config.panel }` }
							{ ...config }
							clientId={ clientId }
							suggestions={ visibleDelegatedStyleRecommendations }
							isStale={ isStaleResult }
						/>
					) ) }
				</>
			) }
		</>
	);
}

function SubPanelSuggestions( {
	group,
	panel,
	clientId,
	isStale = false,
	suggestions,
	label,
	title,
} ) {
	const filtered = useMemo(
		() => ( suggestions || [] ).filter( ( s ) => s.panel === panel ),
		[ panel, suggestions ]
	);
	if ( ! filtered.length ) {
		return null;
	}
	return (
		<InspectorControls group={ group }>
			<SuggestionChips
				clientId={ clientId }
				suggestions={ filtered }
				isStale={ isStale }
				interactive={ false }
				label={ label }
				title={ title }
				tone="Apply now"
			/>
		</InspectorControls>
	);
}

addFilter(
	'editor.BlockEdit',
	'flavor-agent/ai-recommendations',
	withAIRecommendations
);

export default withAIRecommendations;
