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
import {
	PanelBody,
	Button,
	Notice,
	TextareaControl,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import { starFilled as icon } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { collectBlockContext } from '../context/collector';
import AIActivitySection from '../components/AIActivitySection';
import SettingsRecommendations from './SettingsRecommendations';
import StylesRecommendations from './StylesRecommendations';
import SuggestionChips from './SuggestionChips';

function findBlockPath( blocks, clientId, path = [] ) {
	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];
		const nextPath = [ ...path, index ];

		if ( block?.clientId === clientId ) {
			return nextPath;
		}

		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			const nestedPath = findBlockPath(
				block.innerBlocks,
				clientId,
				nextPath
			);

			if ( nestedPath ) {
				return nestedPath;
			}
		}
	}

	return null;
}

function blockPathMatches( left, right ) {
	return JSON.stringify( left || [] ) === JSON.stringify( right || [] );
}

const withAIRecommendations = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { clientId, isSelected } = props;
		const canRecommendBlocks =
			typeof window === 'undefined'
				? true
				: window.flavorAgentData?.canRecommendBlocks ?? true;

		const {
			recommendations,
			isLoading,
			error,
			blockActivityEntries,
			latestBlockActivity,
			latestUndoableActivityId,
			undoError,
			undoStatus,
			lastUndoneActivityId,
		} = useSelect(
			( sel ) => {
				const s = sel( STORE_NAME );
				const blockEditor = sel( blockEditorStore );
				const currentBlockPath = findBlockPath(
					blockEditor.getBlocks?.() || [],
					clientId
				);
				const activityLog = s.getActivityLog() || [];
				const blockEntries = activityLog.filter(
					( entry ) =>
						entry?.surface === 'block' &&
						( entry?.target?.clientId === clientId ||
							blockPathMatches(
								entry?.target?.blockPath,
								currentBlockPath
							) )
				);

				return {
					recommendations: s.getBlockRecommendations( clientId ),
					isLoading: s.isBlockLoading( clientId ),
					error: s.getBlockError( clientId ),
					blockActivityEntries: [ ...blockEntries ]
						.slice( -3 )
						.reverse(),
					latestBlockActivity:
						blockEntries[ blockEntries.length - 1 ] || null,
					latestUndoableActivityId:
						s.getLatestUndoableActivity()?.id || null,
					undoError: s.getUndoError(),
					undoStatus: s.getUndoStatus(),
					lastUndoneActivityId: s.getLastUndoneActivityId(),
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

		const {
			fetchBlockRecommendations,
			clearBlockError,
			clearUndoError,
			undoActivity,
		} = useDispatch( STORE_NAME );
		const [ prompt, setPrompt ] = useState( '' );
		const isDisabled = editingMode === 'disabled';
		const isContentRestricted =
			editingMode === 'contentOnly' || isInsideContentOnly;

		const handleFetch = useCallback( () => {
			if ( ! canRecommendBlocks ) {
				return;
			}

			const ctx = collectBlockContext( clientId );
			if ( ctx ) {
				fetchBlockRecommendations( clientId, ctx, prompt );
			}
		}, [
			canRecommendBlocks,
			clientId,
			prompt,
			fetchBlockRecommendations,
		] );
		const handleUndo = useCallback(
			( activityId ) => {
				undoActivity( activityId );
			},
			[ undoActivity ]
		);

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
						<div className="flavor-agent-panel">
							{ ! canRecommendBlocks && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									Configure a text-generation provider in
									Settings &gt; Connectors to enable block
									recommendations.
								</Notice>
							) }

							{ isContentRestricted && (
								<Notice
									status="info"
									isDismissible={ false }
									className="flavor-agent-content-notice"
								>
									This block is content-restricted. Only
									content edits are available.
								</Notice>
							) }

							<div className="flavor-agent-panel__intro">
								<p className="flavor-agent-panel__eyebrow">
									Selected Block
								</p>
								<p className="flavor-agent-panel__intro-copy">
									Ask for a specific outcome or fetch
									recommendations based on the current block
									context.
								</p>
							</div>

							<div className="flavor-agent-panel__composer">
								<TextareaControl
									__nextHasNoMarginBottom
									disabled={ ! canRecommendBlocks }
									label="What are you trying to achieve?"
									hideLabelFromVision
									placeholder="What are you trying to achieve?"
									value={ prompt }
									onChange={ setPrompt }
									rows={ 2 }
									className="flavor-agent-prompt"
								/>

								<Button
									variant="primary"
									onClick={ handleFetch }
									disabled={
										isLoading || ! canRecommendBlocks
									}
									icon={ icon }
									className="flavor-agent-fetch-button"
								>
									{ isLoading
										? 'Getting Suggestions\u2026'
										: 'Get Suggestions' }
								</Button>
							</div>

							{ error && (
								<Notice
									status="error"
									isDismissible
									onDismiss={ () =>
										clearBlockError( clientId )
									}
								>
									{ error }
								</Notice>
							) }

							{ undoStatus === 'error' && undoError && (
								<Notice
									status="error"
									isDismissible
									onDismiss={ clearUndoError }
								>
									{ undoError }
								</Notice>
							) }

							{ latestBlockActivity &&
								latestBlockActivity.id ===
									latestUndoableActivityId && (
									<Notice
										status="success"
										isDismissible={ false }
									>
										Applied{ ' ' }
										<strong>
											{ latestBlockActivity.suggestion }
										</strong>
										.{ ' ' }
										<Button
											variant="link"
											onClick={ () =>
												handleUndo(
													latestBlockActivity.id
												)
											}
											disabled={
												undoStatus === 'undoing'
											}
										>
											{ undoStatus === 'undoing'
												? 'Undoing…'
												: 'Undo' }
										</Button>
									</Notice>
								) }

							{ latestBlockActivity &&
								undoStatus === 'success' &&
								lastUndoneActivityId ===
									latestBlockActivity.id && (
									<Notice
										status="success"
										isDismissible={ false }
									>
										Undid{ ' ' }
										<strong>
											{ latestBlockActivity.suggestion }
										</strong>
										.
									</Notice>
								) }

							{ recommendations?.explanation && (
								<p className="flavor-agent-explanation flavor-agent-panel__note">
									{ recommendations.explanation }
								</p>
							) }

							<AIActivitySection
								entries={ blockActivityEntries }
								latestUndoableActivityId={
									latestUndoableActivityId
								}
								isUndoing={ undoStatus === 'undoing' }
								onUndo={ handleUndo }
							/>

							{ recommendations?.block?.length > 0 && (
								<div className="flavor-agent-panel__group">
									<div className="flavor-agent-panel__group-header">
										<div className="flavor-agent-panel__group-title">
											Block suggestions
										</div>
										<span className="flavor-agent-pill">
											{ recommendations.block.length }{ ' ' }
											{ recommendations.block.length === 1
												? 'idea'
												: 'ideas' }
										</span>
									</div>
									<SuggestionChips
										clientId={ clientId }
										suggestions={ recommendations.block }
										label="AI block suggestions"
									/>
								</div>
							) }
						</div>
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
							group="position"
							panel="position"
							clientId={ clientId }
							suggestions={ recommendations.settings }
							label="AI position suggestions"
						/>
						<SubPanelSuggestions
							group="advanced"
							panel="advanced"
							clientId={ clientId }
							suggestions={ recommendations.settings }
							label="AI advanced suggestions"
						/>
						<SubPanelSuggestions
							group="bindings"
							panel="bindings"
							clientId={ clientId }
							suggestions={ recommendations.settings }
							label="AI bindings suggestions"
						/>
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
						<SubPanelSuggestions
							group="filter"
							panel="filter"
							clientId={ clientId }
							suggestions={ recommendations.styles }
							label="AI filter suggestions"
						/>
						<SubPanelSuggestions
							group="background"
							panel="background"
							clientId={ clientId }
							suggestions={ recommendations.styles }
							label="AI background suggestions"
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
