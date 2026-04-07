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
import { useMemo } from '@wordpress/element';

import { getLiveBlockContextSignature } from '../context/collector';
import { STORE_NAME } from '../store';
import { BlockRecommendationsPanel } from './BlockRecommendationsPanel';
import SettingsRecommendations from './SettingsRecommendations';
import StylesRecommendations from './StylesRecommendations';
import SuggestionChips from './SuggestionChips';
import {
	SETTINGS_PANEL_DELEGATIONS,
	STYLE_PANEL_DELEGATIONS,
} from './panel-delegation';

const withAIRecommendations = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { clientId, isSelected } = props;
		const {
			recommendations,
			editingMode,
			isInsideContentOnly,
			status,
			storedContextSignature,
		} = useSelect(
			( sel ) => {
				const editor = sel( blockEditorStore );
				const store = sel( STORE_NAME );
				const parentIds = editor.getBlockParents?.( clientId ) || [];

				return {
					recommendations: store.getBlockRecommendations( clientId ),
					status: store.getBlockStatus( clientId ),
					storedContextSignature:
						store.getBlockRecommendationContextSignature(
							clientId
						),
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
		const liveContextSignature = useSelect(
			( select ) => getLiveBlockContextSignature( select, clientId ),
			[ clientId ]
		);
		const isDisabled = editingMode === 'disabled';
		const hasStoredResult =
			status === 'ready' && Boolean( recommendations );
		const hasMatchingResult =
			hasStoredResult &&
			( ! storedContextSignature ||
				storedContextSignature === liveContextSignature );
		const isStaleResult =
			hasStoredResult &&
			Boolean( storedContextSignature ) &&
			storedContextSignature !== liveContextSignature;
		const isContentRestricted =
			editingMode === 'contentOnly' || isInsideContentOnly;
		const visibleRecommendations = hasMatchingResult
			? recommendations
			: null;
		const visibleStyleRecommendations =
			! isContentRestricted && ( hasMatchingResult || isStaleResult )
				? recommendations?.styles || []
				: [];
		const visibleDelegatedStyleRecommendations =
			! isContentRestricted && hasMatchingResult
				? recommendations?.styles || []
				: [];

		if ( ! isSelected || isDisabled ) {
			return <BlockEdit { ...props } />;
		}

		const hasInlineRecs =
			visibleRecommendations &&
			( visibleRecommendations.settings?.length > 0 ||
				visibleDelegatedStyleRecommendations.length > 0 ||
				visibleRecommendations.block?.length > 0 );
		const hasStyleRecs = visibleStyleRecommendations.length > 0;

		return (
			<>
				<BlockEdit { ...props } />

				<InspectorControls>
					<BlockRecommendationsPanel clientId={ clientId } />

					{ hasInlineRecs &&
						visibleRecommendations.settings?.length > 0 && (
							<SettingsRecommendations
								clientId={ clientId }
								suggestions={ visibleRecommendations.settings }
							/>
						) }
				</InspectorControls>

				{ hasStyleRecs && (
					<InspectorControls group="styles">
						<StylesRecommendations
							clientId={ clientId }
							suggestions={ visibleStyleRecommendations }
							isStale={ isStaleResult }
						/>
					</InspectorControls>
				) }

				{ hasInlineRecs && (
					<>
						{ SETTINGS_PANEL_DELEGATIONS.map( ( config ) => (
							<SubPanelSuggestions
								key={ `settings-${ config.group }` }
								{ ...config }
								clientId={ clientId }
								suggestions={ visibleRecommendations.settings }
							/>
						) ) }
						{ STYLE_PANEL_DELEGATIONS.map( ( config ) => (
							<SubPanelSuggestions
								key={ `styles-${ config.group }` }
								{ ...config }
								clientId={ clientId }
								suggestions={
									visibleDelegatedStyleRecommendations
								}
							/>
						) ) }
					</>
				) }
			</>
		);
	};
}, 'withAIRecommendations' );

function SubPanelSuggestions( {
	group,
	panel,
	clientId,
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
