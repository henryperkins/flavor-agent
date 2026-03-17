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
import { PanelBody, Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import { starFilled as icon } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { collectBlockContext } from '../context/collector';
import SettingsRecommendations from './SettingsRecommendations';
import StylesRecommendations from './StylesRecommendations';
import SuggestionChips from './SuggestionChips';

const withAIRecommendations = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { clientId, isSelected } = props;

		const { recommendations, isLoading, error } = useSelect(
			( sel ) => {
				const s = sel( STORE_NAME );
				return {
					recommendations: s.getBlockRecommendations( clientId ),
					isLoading: s.isLoading(),
					error: s.getError(),
				};
			},
			[ clientId ]
		);
		const { editingMode, isInsideContentOnly } = useSelect(
			( sel ) => {
				const editor = sel( blockEditorStore );
				const mode = editor.getBlockEditingMode( clientId );
				const parentIds = editor.getBlockParents( clientId );

				return {
					editingMode: mode,
					isInsideContentOnly: parentIds.some(
						( parentId ) =>
							editor.getBlockEditingMode( parentId ) ===
							'contentOnly'
					),
				};
			},
			[ clientId ]
		);

		const { fetchBlockRecommendations, setStatus } =
			useDispatch( STORE_NAME );
		const [ prompt, setPrompt ] = useState( '' );
		const isDisabled = editingMode === 'disabled';
		const isContentRestricted =
			editingMode === 'contentOnly' || isInsideContentOnly;

		const handleFetch = useCallback( () => {
			const ctx = collectBlockContext( clientId );
			if ( ctx ) {
				fetchBlockRecommendations( clientId, ctx, prompt );
			}
		}, [ clientId, prompt, fetchBlockRecommendations ] );

		if ( ! isSelected || isDisabled ) {
			return <BlockEdit { ...props } />;
		}

		const hasRecs =
			recommendations &&
			( recommendations.settings.length > 0 ||
				recommendations.styles.length > 0 ||
				recommendations.block.length > 0 );

		return (
			<>
				<BlockEdit { ...props } />

				<InspectorControls>
					<PanelBody
						title="AI Recommendations"
						initialOpen={ false }
						icon={ icon }
					>
						{ isContentRestricted && (
							<Notice
								status="info"
								isDismissible={ false }
								style={ { margin: '0 0 8px' } }
							>
								This block is content-restricted. Only content
								edits are available.
							</Notice>
						) }
						<div style={ { marginBottom: '8px' } }>
							<textarea
								placeholder="What are you trying to achieve?"
								value={ prompt }
								onChange={ ( e ) =>
									setPrompt( e.target.value )
								}
								rows={ 2 }
								style={ { width: '100%', resize: 'vertical' } }
								aria-label="Describe what you want to achieve with this block"
							/>
						</div>
						<Button
							variant="primary"
							onClick={ handleFetch }
							disabled={ isLoading }
							icon={ icon }
							style={ {
								width: '100%',
								justifyContent: 'center',
							} }
						>
							{ isLoading ? <Spinner /> : 'Get Suggestions' }
						</Button>

						{ error && (
							<Notice
								status="error"
								isDismissible
								onDismiss={ () => setStatus( 'idle' ) }
								style={ { marginTop: '8px' } }
							>
								{ error }
							</Notice>
						) }

						{ recommendations?.explanation && (
							<p
								style={ {
									marginTop: '8px',
									fontSize: '12px',
									color: 'var(--wp-components-color-foreground-secondary, #757575)',
								} }
							>
								{ recommendations.explanation }
							</p>
						) }

						{ recommendations?.block?.length > 0 && (
							<div style={ { marginTop: '12px' } }>
								<div
									style={ {
										fontSize: '11px',
										fontWeight: 600,
										textTransform: 'uppercase',
										letterSpacing: '0.5px',
										color: 'var(--wp-components-color-foreground-secondary, #757575)',
										marginBottom: '6px',
									} }
								>
									Block suggestions
								</div>
								<SuggestionChips
									clientId={ clientId }
									suggestions={ recommendations.block }
									label="AI block suggestions"
								/>
							</div>
						) }
					</PanelBody>

					{ hasRecs && recommendations.settings.length > 0 && (
						<SettingsRecommendations
							clientId={ clientId }
							suggestions={ recommendations.settings }
						/>
					) }
				</InspectorControls>

				{ hasRecs && recommendations.styles.length > 0 && (
					<InspectorControls group="styles">
						<StylesRecommendations
							clientId={ clientId }
							suggestions={ recommendations.styles }
						/>
					</InspectorControls>
				) }

				{ hasRecs && (
					<>
						<SubPanelSuggestions
							group="color"
							panel="color"
							clientId={ clientId }
							suggestions={ recommendations.styles }
							label="AI color suggestions"
						/>
						<SubPanelSuggestions
							group="typography"
							panel="typography"
							clientId={ clientId }
							suggestions={ recommendations.styles }
							label="AI typography suggestions"
						/>
						<SubPanelSuggestions
							group="dimensions"
							panel="dimensions"
							clientId={ clientId }
							suggestions={ recommendations.styles }
							label="AI spacing suggestions"
						/>
						<SubPanelSuggestions
							group="border"
							panel="border"
							clientId={ clientId }
							suggestions={ recommendations.styles }
							label="AI border suggestions"
						/>
					</>
				) }
			</>
		);
	};
}, 'withAIRecommendations' );

function SubPanelSuggestions( { group, panel, clientId, suggestions, label } ) {
	const filtered = suggestions.filter( ( s ) => s.panel === panel );
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
