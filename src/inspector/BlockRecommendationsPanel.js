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
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
} from '../store/activity-history';
import { collectBlockContext } from '../context/collector';
import AIActivitySection from '../components/AIActivitySection';
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
		() => getResolvedActivityEntries( blockActivityLog ),
		[ blockActivityLog ]
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

			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => clearBlockError( clientId ) }
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
				latestBlockActivity.id === latestUndoableActivityId && (
					<Notice status="success" isDismissible={ false }>
						Applied{ ' ' }
						<strong>{ latestBlockActivity.suggestion }</strong>.{ ' ' }
						<Button
							variant="link"
							onClick={ () =>
								handleUndo( latestBlockActivity.id )
							}
							disabled={ undoStatus === 'undoing' }
						>
							{ undoStatus === 'undoing' ? 'Undoing…' : 'Undo' }
						</Button>
					</Notice>
				) }

			{ undoStatus === 'success' && lastUndoneBlockActivity && (
				<Notice status="success" isDismissible={ false }>
					Undid{ ' ' }
					<strong>{ lastUndoneBlockActivity.suggestion }</strong>.
				</Notice>
			) }

			{ recommendations?.explanation && (
				<p className="flavor-agent-explanation flavor-agent-panel__note">
					{ recommendations.explanation }
				</p>
			) }

			<AIActivitySection
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
