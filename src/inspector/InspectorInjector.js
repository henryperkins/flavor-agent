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
		const { recommendations, editingMode } = useSelect(
			( sel ) => {
				const editor = sel( blockEditorStore );

				return {
					recommendations:
						sel( STORE_NAME ).getBlockRecommendations( clientId ),
					editingMode: editor.getBlockEditingMode( clientId ),
				};
			},
			[ clientId ]
		);
		const isDisabled = editingMode === 'disabled';

		if ( ! isSelected || isDisabled ) {
			return <BlockEdit { ...props } />;
		}

		const hasRecs =
			recommendations &&
			( recommendations.settings?.length > 0 ||
				recommendations.styles?.length > 0 ||
				recommendations.block?.length > 0 );

		return (
			<>
				<BlockEdit { ...props } />

				<InspectorControls>
					<BlockRecommendationsPanel clientId={ clientId } />

					{ hasRecs && recommendations.settings?.length > 0 && (
						<SettingsRecommendations
							clientId={ clientId }
							suggestions={ recommendations.settings }
						/>
					) }
				</InspectorControls>

				{ hasRecs && recommendations.styles?.length > 0 && (
					<InspectorControls group="styles">
						<StylesRecommendations
							clientId={ clientId }
							suggestions={ recommendations.styles }
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
								suggestions={ recommendations.settings }
							/>
						) ) }
						{ STYLE_PANEL_DELEGATIONS.map( ( config ) => (
							<SubPanelSuggestions
								key={ `styles-${ config.group }` }
								{ ...config }
								clientId={ clientId }
								suggestions={ recommendations.styles }
							/>
						) ) }
					</>
				) }
			</>
		);
	};
}, 'withAIRecommendations' );

function SubPanelSuggestions( { group, panel, clientId, suggestions, label } ) {
	const filtered = ( suggestions || [] ).filter( ( s ) => s.panel === panel );
	if ( ! filtered.length ) {
		return null;
	}
	return (
		<InspectorControls group={ group }>
			<SuggestionChips
				clientId={ clientId }
				suggestions={ filtered }
				label={ label }
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
