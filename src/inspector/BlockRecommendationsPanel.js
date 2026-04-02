import {
	PanelBody,
	Button,
	Notice,
	TextareaControl,
} from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useState, useCallback, useMemo, useEffect } from '@wordpress/element';
import { starFilled as icon } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import {
	getBlockActivityUndoState,
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
} from '../store/activity-history';
import { collectBlockContext } from '../context/collector';
import AIActivitySection from '../components/AIActivitySection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import NavigationRecommendations from './NavigationRecommendations';
import SuggestionChips from './SuggestionChips';
import { getSurfaceCapability } from '../utils/capability-flags';

export function findBlockPath( blocks, clientId, path = [] ) {
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

export function blockPathMatches( left, right ) {
	return JSON.stringify( left || [] ) === JSON.stringify( right || [] );
}

function getCanRecommendBlocks() {
	return getSurfaceCapability( 'block' ).available;
}

function useBlockRecommendationState( clientId ) {
	const canRecommendBlocks = getCanRecommendBlocks();
	const blockEditorSelection = useSelect( ( select ) => {
		const blockEditor = select( blockEditorStore );

		return {
			getBlock: ( targetClientId ) =>
				blockEditor.getBlock?.( targetClientId ) || null,
			getBlockAttributes: ( targetClientId ) =>
				blockEditor.getBlockAttributes?.( targetClientId ) || null,
			getBlocks: () => blockEditor.getBlocks?.() || [],
		};
	}, [] );

	const {
		recommendations,
		isLoading,
		error,
		blockActivityLog,
		undoError,
		undoStatus,
		lastUndoneActivityId,
		editingMode,
		isInsideContentOnly,
		block,
	} = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const blockEditor = select( blockEditorStore );
			const blocks = blockEditor.getBlocks?.() || [];
			const currentBlockPath = findBlockPath( blocks, clientId );
			const activityLog = store.getActivityLog() || [];
			const blockEntries = activityLog.filter(
				( entry ) =>
					entry?.surface === 'block' &&
					( entry?.target?.clientId === clientId ||
						blockPathMatches(
							entry?.target?.blockPath,
							currentBlockPath
						) )
			);
			const resolvedBlock = blockEditor.getBlock?.( clientId ) || null;
			const parentIds = blockEditor.getBlockParents?.( clientId ) || [];

			return {
				recommendations: store.getBlockRecommendations( clientId ),
				isLoading: store.isBlockLoading( clientId ),
				error: store.getBlockError( clientId ),
				blockActivityLog: blockEntries,
				undoError: store.getUndoError(),
				undoStatus: store.getUndoStatus(),
				lastUndoneActivityId: store.getLastUndoneActivityId(),
				editingMode: blockEditor.getBlockEditingMode?.( clientId ),
				isInsideContentOnly: parentIds.some(
					( parentId ) =>
						blockEditor.getBlockEditingMode?.( parentId ) ===
						'contentOnly'
				),
				block: resolvedBlock,
			};
		},
		[ clientId ]
	);
	const resolvedBlockActivities = useMemo(
		() =>
			getResolvedActivityEntries( blockActivityLog, ( entry ) =>
				getBlockActivityUndoState( entry, blockEditorSelection )
			),
		[ blockActivityLog, blockEditorSelection ]
	);
	const blockActivityEntries = useMemo(
		() => [ ...resolvedBlockActivities ].slice( -3 ).reverse(),
		[ resolvedBlockActivities ]
	);
	const latestBlockActivity = useMemo(
		() => getLatestAppliedActivity( resolvedBlockActivities ),
		[ resolvedBlockActivities ]
	);
	const latestUndoableActivityId = useMemo(
		() => getLatestUndoableActivity( resolvedBlockActivities )?.id || null,
		[ resolvedBlockActivities ]
	);
	const lastUndoneBlockActivity = useMemo(
		() =>
			resolvedBlockActivities.find(
				( entry ) => entry?.id === lastUndoneActivityId
			) || null,
		[ resolvedBlockActivities, lastUndoneActivityId ]
	);

	return {
		canRecommendBlocks,
		recommendations,
		isLoading,
		error,
		blockActivityEntries,
		latestBlockActivity,
		latestUndoableActivityId,
		lastUndoneBlockActivity,
		undoError,
		undoStatus,
		isDisabled: editingMode === 'disabled',
		isContentRestricted:
			editingMode === 'contentOnly' || isInsideContentOnly,
		block,
	};
}

export function BlockRecommendationsContent( {
	clientId,
	eyebrow = 'Selected Block',
	introCopy = 'Ask for a specific outcome or fetch recommendations based on the current block context.',
} ) {
	const {
		canRecommendBlocks,
		recommendations,
		isLoading,
		error,
		blockActivityEntries,
		latestBlockActivity,
		latestUndoableActivityId,
		lastUndoneBlockActivity,
		undoError,
		undoStatus,
		isDisabled,
		isContentRestricted,
		block,
	} = useBlockRecommendationState( clientId );
	const {
		fetchBlockRecommendations,
		clearBlockError,
		clearUndoError,
		undoActivity,
	} = useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const hasApplySuccess =
		Boolean( latestBlockActivity ) &&
		latestBlockActivity?.id === latestUndoableActivityId;
	const hasUndoSuccess =
		undoStatus === 'success' &&
		lastUndoneBlockActivity?.undo?.status === 'undone';
	const { interactionState, statusNotice } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const blockSuggestions = recommendations?.block || [];

			return {
				interactionState: store.getBlockInteractionState( clientId, {
					undoError,
					hasSuccess: hasApplySuccess,
					hasUndoSuccess,
				} ),
				statusNotice: store.getSurfaceStatusNotice( 'block', {
					requestError: error,
					undoError,
					undoStatus,
					hasResult: blockSuggestions.length > 0,
					hasSuggestions: blockSuggestions.length > 0,
					hasSuccess: hasApplySuccess,
					hasUndoSuccess,
					applySuccessMessage: hasApplySuccess
						? `Applied ${
								latestBlockActivity?.suggestion || 'suggestion'
						  }.`
						: '',
					undoSuccessMessage: hasUndoSuccess
						? `Undid ${
								lastUndoneBlockActivity?.suggestion ||
								'suggestion'
						  }.`
						: '',
					onDismissAction: Boolean( error ),
					onUndoDismissAction: Boolean( undoError ),
				} ),
			};
		},
		[
			clientId,
			error,
			hasApplySuccess,
			hasUndoSuccess,
			lastUndoneBlockActivity,
			latestBlockActivity,
			recommendations,
			undoError,
			undoStatus,
		]
	);

	useEffect( () => {
		setPrompt( '' );
	}, [ clientId ] );

	const handleFetch = useCallback( () => {
		if ( ! canRecommendBlocks ) {
			return;
		}

		const context = collectBlockContext( clientId );

		if ( context ) {
			fetchBlockRecommendations( clientId, context, prompt );
		}
	}, [ canRecommendBlocks, clientId, fetchBlockRecommendations, prompt ] );
	const handleUndo = useCallback(
		( activityId ) => {
			undoActivity( activityId );
		},
		[ undoActivity ]
	);
	let dismissStatusNotice;

	if ( statusNotice?.source === 'request' ) {
		dismissStatusNotice = () => clearBlockError( clientId );
	} else if ( statusNotice?.source === 'undo' ) {
		dismissStatusNotice = clearUndoError;
	}

	if ( ! clientId || ! block || isDisabled ) {
		return null;
	}

	return (
		<div className="flavor-agent-panel">
			{ ! canRecommendBlocks && <CapabilityNotice surface="block" /> }

			{ isContentRestricted && (
				<Notice
					status="info"
					isDismissible={ false }
					className="flavor-agent-content-notice"
				>
					This block is content-restricted. Only content edits are
					available.
				</Notice>
			) }

			<div className="flavor-agent-panel__intro">
				<p className="flavor-agent-panel__eyebrow">{ eyebrow }</p>
				<p className="flavor-agent-panel__intro-copy">{ introCopy }</p>
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
					disabled={ isLoading || ! canRecommendBlocks }
					icon={ icon }
					className="flavor-agent-fetch-button"
				>
					{ isLoading ? 'Getting Suggestions…' : 'Get Suggestions' }
				</Button>
			</div>

			<AIStatusNotice
				notice={ statusNotice }
				onAction={
					statusNotice?.actionType === 'undo' && latestBlockActivity
						? () => handleUndo( latestBlockActivity.id )
						: undefined
				}
				onDismiss={ dismissStatusNotice }
			/>

			{ recommendations?.explanation && (
				<p className="flavor-agent-explanation flavor-agent-panel__note">
					{ recommendations.explanation }
				</p>
			) }

			<AIActivitySection
				description={
					interactionState === 'success' ||
					blockActivityEntries.length > 0
						? 'Undo follows the same latest-valid-action rule used across every executable Flavor Agent surface.'
						: ''
				}
				entries={ blockActivityEntries }
				isUndoing={ undoStatus === 'undoing' }
				onUndo={ handleUndo }
			/>

			<NavigationRecommendations clientId={ clientId } />

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
					<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
						Block suggestions can apply inline when Flavor Agent is
						only updating local attributes on this block. Structural
						surfaces keep the same status and history model, but
						require preview before mutation.
					</p>
					<SuggestionChips
						clientId={ clientId }
						suggestions={ recommendations.block }
						label="AI block suggestions"
					/>
				</div>
			) }
		</div>
	);
}

export function BlockRecommendationsPanel( props ) {
	return (
		<PanelBody
			title="AI Recommendations"
			initialOpen={ false }
			icon={ icon }
		>
			<BlockRecommendationsContent { ...props } />
		</PanelBody>
	);
}

export function BlockRecommendationsDocumentPanel() {
	const [ rememberedClientId, setRememberedClientId ] = useState( null );
	const { selectedBlockClientId, selectedBlock } = useSelect( ( select ) => {
		const blockEditor = select( blockEditorStore );
		const clientId = blockEditor.getSelectedBlockClientId?.() || null;

		return {
			selectedBlockClientId: clientId,
			selectedBlock: clientId ? blockEditor.getBlock?.( clientId ) : null,
		};
	}, [] );
	const rememberedBlock = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore );

			return rememberedClientId
				? blockEditor.getBlock?.( rememberedClientId ) || null
				: null;
		},
		[ rememberedClientId ]
	);

	useEffect( () => {
		if ( selectedBlockClientId && selectedBlock ) {
			setRememberedClientId( selectedBlockClientId );
		}
	}, [ selectedBlockClientId, selectedBlock ] );

	if ( selectedBlockClientId || ! rememberedClientId || ! rememberedBlock ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-block-recommendations"
			title="AI Recommendations"
		>
			<BlockRecommendationsContent
				clientId={ rememberedClientId }
				eyebrow="Last Selected Block"
				introCopy="Saving cleared block selection. Flavor Agent stays scoped to the last block you selected until you choose another block."
			/>
		</PluginDocumentSettingPanel>
	);
}
