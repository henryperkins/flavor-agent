import { PanelBody, Notice } from '@wordpress/components';
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
import { getBlockSuggestionExecutionInfo } from '../store/update-helpers';
import { collectBlockContext } from '../context/collector';
import AIActivitySection from '../components/AIActivitySection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import { buildBlockRecommendationContextSignature } from '../utils/block-recommendation-context';
import NavigationRecommendations from './NavigationRecommendations';
import SuggestionChips from './SuggestionChips';
import { getSuggestionKey } from './suggestion-keys';
import { getSurfaceCapability } from '../utils/capability-flags';

const EMPTY_BLOCK_SUGGESTIONS = [];

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
		status,
		storedContextSignature,
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
				status: store.getBlockStatus( clientId ),
				storedContextSignature:
					store.getBlockRecommendationContextSignature( clientId ),
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
		status,
		storedContextSignature,
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
		status,
		storedContextSignature,
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
	const liveContext = useMemo(
		() => collectBlockContext( clientId ),
		[ clientId ]
	);
	const liveContextSignature = useMemo(
		() =>
			liveContext
				? buildBlockRecommendationContextSignature( liveContext )
				: '',
		[ liveContext ]
	);
	const hasApplySuccess =
		Boolean( latestBlockActivity ) &&
		latestBlockActivity?.id === latestUndoableActivityId;
	const hasUndoSuccess =
		undoStatus === 'success' &&
		lastUndoneBlockActivity?.undo?.status === 'undone';
	const hasMatchingResult =
		status === 'ready' &&
		Boolean( recommendations ) &&
		( ! storedContextSignature ||
			storedContextSignature === liveContextSignature );
	const hasResult = status === 'ready' && Boolean( recommendations );
	const blockSuggestions = hasMatchingResult
		? recommendations?.block ?? EMPTY_BLOCK_SUGGESTIONS
		: EMPTY_BLOCK_SUGGESTIONS;
	const hasBlockSuggestions = blockSuggestions.length > 0;
	const { interactionState, statusNotice } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );

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
					hasResult,
					hasSuggestions: hasBlockSuggestions,
					hasSuccess: hasApplySuccess,
					hasUndoSuccess,
					emptyMessage:
						hasMatchingResult && ! hasBlockSuggestions
							? 'No block suggestions were returned for the current prompt.'
							: '',
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
			hasResult,
			hasMatchingResult,
			hasBlockSuggestions,
			hasApplySuccess,
			hasUndoSuccess,
			lastUndoneBlockActivity,
			latestBlockActivity,
			undoError,
			undoStatus,
		]
	);
	const { executableBlockSuggestions, advisoryBlockSuggestions } =
		useMemo( () => {
			const blockContext = recommendations?.blockContext || {};
			const executable = [];
			const advisory = [];

			for ( const suggestion of blockSuggestions ) {
				const execution = getBlockSuggestionExecutionInfo(
					suggestion,
					blockContext
				);

				if ( execution.isExecutable ) {
					executable.push( suggestion );
				} else {
					advisory.push( suggestion );
				}
			}

			return {
				executableBlockSuggestions: executable,
				advisoryBlockSuggestions: advisory,
			};
		}, [ blockSuggestions, recommendations?.blockContext ] );

	useEffect( () => {
		setPrompt( '' );
	}, [ clientId ] );

	const handleFetch = useCallback( () => {
		if ( ! canRecommendBlocks ) {
			return;
		}

		if ( liveContext ) {
			fetchBlockRecommendations( clientId, liveContext, prompt );
		}
	}, [
		canRecommendBlocks,
		clientId,
		fetchBlockRecommendations,
		liveContext,
		prompt,
	] );
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

	const showSecondaryGuidance =
		blockSuggestions.length === 0 &&
		blockActivityEntries.length === 0 &&
		interactionState !== 'success' &&
		statusNotice?.source !== 'empty';

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

			<SurfacePanelIntro eyebrow={ eyebrow } introCopy={ introCopy } />

			<SurfaceScopeBar
				scopeLabel={ block?.name || '' }
				isFresh={ hasMatchingResult }
				hasResult={ hasResult }
			/>

			<SurfaceComposer
				prompt={ prompt }
				onPromptChange={ setPrompt }
				onFetch={ handleFetch }
				placeholder="What are you trying to achieve?"
				rows={ 2 }
				fetchIcon={ icon }
				isLoading={ isLoading }
				disabled={ ! canRecommendBlocks }
			/>

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
				description="Undo follows the same latest-valid-action rule used across every executable Flavor Agent surface."
				entries={ blockActivityEntries }
				isUndoing={ undoStatus === 'undoing' }
				onUndo={ handleUndo }
			/>

			<NavigationRecommendations clientId={ clientId } />

			{ executableBlockSuggestions.length > 0 && (
				<div className="flavor-agent-panel__group">
					<div className="flavor-agent-panel__group-header">
						<div className="flavor-agent-panel__group-title">
							Block suggestions
						</div>
						<span className="flavor-agent-pill">
							{ executableBlockSuggestions.length }{ ' ' }
							{ executableBlockSuggestions.length === 1
								? 'idea'
								: 'ideas' }
						</span>
					</div>
					{ showSecondaryGuidance && (
						<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
							One-click apply stays available when Flavor Agent
							can safely change this block&apos;s local
							attributes. Broader structural and replacement ideas
							stay advisory.
						</p>
					) }
					<SuggestionChips
						clientId={ clientId }
						suggestions={ executableBlockSuggestions }
						label="AI block suggestions"
					/>
				</div>
			) }

			{ advisoryBlockSuggestions.length > 0 && (
				<div className="flavor-agent-panel__group">
					<div className="flavor-agent-panel__group-header">
						<div className="flavor-agent-panel__group-title">
							Advisory suggestions
						</div>
						<span className="flavor-agent-pill">
							{ advisoryBlockSuggestions.length }{ ' ' }
							{ advisoryBlockSuggestions.length === 1
								? 'idea'
								: 'ideas' }
						</span>
					</div>
					{ showSecondaryGuidance && (
						<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
							These ideas need manual follow-through or a broader
							preview/apply flow, so Flavor Agent keeps them
							advisory instead of pretending they are one-click
							safe.
						</p>
					) }
					<div className="flavor-agent-panel__group-body">
						{ advisoryBlockSuggestions.map( ( suggestion ) => (
							<AdvisorySuggestionCard
								key={ getSuggestionKey( suggestion ) }
								suggestion={ suggestion }
							/>
						) ) }
					</div>
				</div>
			) }
		</div>
	);
}

function AdvisorySuggestionCard( { suggestion } ) {
	const typeLabel = getAdvisorySuggestionTypeLabel( suggestion );

	return (
		<div className="flavor-agent-card">
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<span className="flavor-agent-card__label">
						{ suggestion?.label || 'Suggestion' }
					</span>
					{ typeLabel && (
						<div className="flavor-agent-card__meta">
							<span className="flavor-agent-pill">
								{ typeLabel }
							</span>
						</div>
					) }
				</div>
			</div>

			{ suggestion?.description && (
				<p className="flavor-agent-card__description">
					{ suggestion.description }
				</p>
			) }
		</div>
	);
}

function getAdvisorySuggestionTypeLabel( suggestion ) {
	switch ( suggestion?.type ) {
		case 'structural_recommendation':
			return 'Structure';
		case 'pattern_replacement':
			return 'Pattern';
		case 'style_variation':
			return 'Style variation';
		default:
			return 'Advisory';
	}
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
