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

const withAIRecommendations = createHigherOrderComponent((BlockEdit) => {
    return (props) => {
        const { clientId, isSelected } = props;
        const { recommendations, editingMode } = useSelect(
            (sel) => {
                const editor = sel(blockEditorStore);

                return {
                    recommendations:
                        sel(STORE_NAME).getBlockRecommendations(clientId),
                    editingMode: editor.getBlockEditingMode(clientId),
                };
            },
            [clientId]
        );
        const isDisabled = editingMode === 'disabled';

        if (!isSelected || isDisabled) {
            return <BlockEdit {...props} />;
        }

        const hasRecs =
            recommendations &&
            (recommendations.settings?.length > 0 ||
                recommendations.styles?.length > 0 ||
                recommendations.block?.length > 0);

        return (
            <>
                <BlockEdit {...props} />

                <InspectorControls>
                    <BlockRecommendationsPanel clientId={clientId} />

                    {hasRecs && recommendations.settings?.length > 0 && (
                        <SettingsRecommendations
                            clientId={clientId}
                            suggestions={recommendations.settings}
                        />
                    )}
                </InspectorControls>

                {hasRecs && recommendations.styles?.length > 0 && (
                    <InspectorControls group="styles">
                        <StylesRecommendations
                            clientId={clientId}
                            suggestions={recommendations.styles}
                        />
                    </InspectorControls>
                )}

                {hasRecs && (
                    <>
                        <SubPanelSuggestions
                            group="position"
                            panel="position"
                            clientId={clientId}
                            suggestions={recommendations.settings}
                            label="AI position suggestions"
                        />
                        <SubPanelSuggestions
                            group="advanced"
                            panel="advanced"
                            clientId={clientId}
                            suggestions={recommendations.settings}
                            label="AI advanced suggestions"
                        />
                        <SubPanelSuggestions
                            group="bindings"
                            panel="bindings"
                            clientId={clientId}
                            suggestions={recommendations.settings}
                            label="AI bindings suggestions"
                        />
                        <SubPanelSuggestions
                            group="list"
                            panel="list"
                            clientId={clientId}
                            suggestions={recommendations.settings}
                            label="AI list view suggestions"
                        />
                        <SubPanelSuggestions
                            group="color"
                            panel="color"
                            clientId={clientId}
                            suggestions={recommendations.styles}
                            label="AI color suggestions"
                        />
                        <SubPanelSuggestions
                            group="typography"
                            panel="typography"
                            clientId={clientId}
                            suggestions={recommendations.styles}
                            label="AI typography suggestions"
                        />
                        <SubPanelSuggestions
                            group="dimensions"
                            panel="dimensions"
                            clientId={clientId}
                            suggestions={recommendations.styles}
                            label="AI spacing suggestions"
                        />
                        <SubPanelSuggestions
                            group="border"
                            panel="border"
                            clientId={clientId}
                            suggestions={recommendations.styles}
                            label="AI border suggestions"
                        />
                        <SubPanelSuggestions
                            group="filter"
                            panel="filter"
                            clientId={clientId}
                            suggestions={recommendations.styles}
                            label="AI filter suggestions"
                        />
                        <SubPanelSuggestions
                            group="background"
                            panel="background"
                            clientId={clientId}
                            suggestions={recommendations.styles}
                            label="AI background suggestions"
                        />
                    </>
                )}
            </>
        );
    };
}, 'withAIRecommendations');

function SubPanelSuggestions({ group, panel, clientId, suggestions, label }) {
    const filtered = (suggestions || []).filter((s) => s.panel === panel);
    if (!filtered.length) {
        return null;
    }
    return (
        <InspectorControls group={group}>
            <SuggestionChips
                clientId={clientId}
                suggestions={filtered}
                label={label}
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
