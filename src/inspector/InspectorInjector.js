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
		const { recommendations, editingMode, status, storedContextSignature } =
			useSelect(
				( sel ) => {
					const editor = sel( blockEditorStore );
					const store = sel( STORE_NAME );

					return {
						recommendations:
							store.getBlockRecommendations( clientId ),
						status: store.getBlockStatus( clientId ),
						storedContextSignature:
							store.getBlockRecommendationContextSignature(
								clientId
							),
						editingMode: editor.getBlockEditingMode( clientId ),
					};
				},
				[ clientId ]
			);
		const liveContextSignature = useSelect(
			( select ) => getLiveBlockContextSignature( select, clientId ),
			[ clientId ]
		);
		const isDisabled = editingMode === 'disabled';
		const hasMatchingResult =
			status === 'ready' &&
			Boolean( recommendations ) &&
			( ! storedContextSignature ||
				storedContextSignature === liveContextSignature );
		const visibleRecommendations = hasMatchingResult
			? recommendations
			: null;

		if ( ! isSelected || isDisabled ) {
			return <BlockEdit { ...props } />;
		}

		const hasRecs =
			visibleRecommendations &&
			( visibleRecommendations.settings?.length > 0 ||
				visibleRecommendations.styles?.length > 0 ||
				visibleRecommendations.block?.length > 0 );

		return (
			<>
				<BlockEdit { ...props } />

				<InspectorControls>
					<BlockRecommendationsPanel clientId={ clientId } />

					{ hasRecs &&
						visibleRecommendations.settings?.length > 0 && (
							<SettingsRecommendations
								clientId={ clientId }
								suggestions={ visibleRecommendations.settings }
							/>
						) }
				</InspectorControls>

				{ hasRecs && visibleRecommendations.styles?.length > 0 && (
					<InspectorControls group="styles">
						<StylesRecommendations
							clientId={ clientId }
							suggestions={ visibleRecommendations.styles }
						/>
					</InspectorControls>
				) }

				{ hasRecs && (
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
								suggestions={ visibleRecommendations.styles }
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
