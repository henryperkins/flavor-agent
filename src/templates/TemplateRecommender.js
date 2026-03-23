/**
 * Template Recommender
 *
 * Advisory AI template composition suggestions in the Site Editor
 * sidebar. Every block-editor entity the LLM mentions is wired to the
 * correct review surface:
 *
 *   Template-part slugs / areas  →  selectBlock (highlights in canvas,
 *       block inspector shows the template-part controls)
 *   Pattern names                →  opens the block Inserter on the
 *       Patterns tab, pre-filtered to that pattern so the user sees
 *       a live preview and chooses where to insert it
 *
 * Free-form text (explanation, description, reason) is scanned for
 * entity mentions and linked inline with the same type-aware actions.
 */
import {
	Button,
	Notice,
	TextareaControl,
	Tooltip,
} from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import AIActivitySection from '../components/AIActivitySection';
import { STORE_NAME } from '../store';
import { normalizeTemplateType } from '../utils/template-types';
import { getBlockPatterns as getCompatBlockPatterns } from '../patterns/compat';
import {
	getTemplateActivityUndoState,
	openInserterForPattern,
	selectBlockByArea,
	selectBlockBySlugOrArea,
} from '../utils/template-actions';
import {
	buildEntityMap,
	buildEditorTemplateSlotSnapshot,
	buildTemplateFetchInput,
	buildTemplateSuggestionViewModel,
	ENTITY_ACTION_BROWSE_PATTERN,
	ENTITY_ACTION_SELECT_AREA,
	ENTITY_ACTION_SELECT_PART,
	formatCount,
	formatTemplateTypeLabel,
	getSuggestionCardKey,
	TEMPLATE_OPERATION_ASSIGN,
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_OPERATION_REPLACE,
} from './template-recommender-helpers';

/**
 * Case-insensitive word-boundary search.
 *
 * A "boundary" is the string edge or any character that is NOT
 * alphanumeric and NOT a hyphen, so slugs like "header-large-title"
 * stay atomic and "header" won't match inside them.
 *
 * @param {string} haystack Source text to search.
 * @param {string} needle   Target text to find.
 * @return {number} Match index or -1 when no boundary-safe match exists.
 */
function findWordMatch( haystack, needle ) {
	const lower = haystack.toLowerCase();
	const target = needle.toLowerCase();
	let start = 0;

	while ( start <= lower.length - target.length ) {
		const idx = lower.indexOf( target, start );
		if ( idx === -1 ) {
			return -1;
		}

		const before = idx === 0 ? '' : haystack[ idx - 1 ];
		const after =
			idx + target.length >= haystack.length
				? ''
				: haystack[ idx + target.length ];

		const okBefore = before === '' || /[^a-zA-Z0-9\-]/.test( before );
		const okAfter = after === '' || /[^a-zA-Z0-9\-]/.test( after );

		if ( okBefore && okAfter ) {
			return idx;
		}

		start = idx + 1;
	}

	return -1;
}

/**
 * Render a text string with recognized entities replaced by clickable
 * action links, each styled for its entity type.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.text          Text to render.
 * @param {Array}    props.entities      Linkable entity definitions.
 * @param {Function} props.onEntityClick Entity click handler.
 * @return {*} Rendered linked text.
 */
function LinkedText( { text, entities, onEntityClick } ) {
	if ( ! text || ! entities || entities.length === 0 ) {
		return text || null;
	}

	const segments = [];
	let remaining = text;
	let key = 0;

	while ( remaining.length > 0 ) {
		let bestEntity = null;
		let bestIndex = remaining.length;

		for ( const entity of entities ) {
			const idx = findWordMatch( remaining, entity.text );
			if ( idx !== -1 && idx < bestIndex ) {
				bestIndex = idx;
				bestEntity = entity;
			}
		}

		if ( ! bestEntity ) {
			segments.push( remaining );
			break;
		}

		if ( bestIndex > 0 ) {
			segments.push( remaining.slice( 0, bestIndex ) );
		}

		const matched = remaining.slice(
			bestIndex,
			bestIndex + bestEntity.text.length
		);
		segments.push(
			<Tooltip key={ ++key } text={ bestEntity.tooltip }>
				<Button
					size="small"
					variant="link"
					onClick={ () => onEntityClick( bestEntity ) }
					className={ `flavor-agent-inline-link flavor-agent-inline-link--${ bestEntity.type }` }
				>
					{ matched }
				</Button>
			</Tooltip>
		);

		remaining = remaining.slice( bestIndex + bestEntity.text.length );
	}

	return <>{ segments }</>;
}

function formatBlockLabel( block ) {
	if ( ! block ) {
		return 'the template';
	}

	if ( block.name === 'core/template-part' ) {
		return (
			block.attributes?.slug ||
			block.attributes?.area ||
			'current template part'
		);
	}

	return block.name
		? block.name.replace( 'core/', '' ).replaceAll( '-', ' ' )
		: 'the current block';
}

function describeInsertionPoint( {
	selectedBlock,
	rootBlock,
	insertionPoint,
} ) {
	if ( selectedBlock ) {
		return `after ${ formatBlockLabel( selectedBlock ) }`;
	}

	if ( rootBlock ) {
		return `inside ${ formatBlockLabel( rootBlock ) }`;
	}

	if (
		Number.isFinite( insertionPoint?.index ) &&
		insertionPoint.index === 0
	) {
		return 'at the start of the template';
	}

	return 'at the end of the template';
}

export default function TemplateRecommender() {
	const canRecommend = window.flavorAgentData?.canRecommendTemplates;
	const templateBlocks = useSelect(
		( select ) =>
			select( blockEditorStore )?.getBlocks?.() || [],
		[]
	);
	const templateRef = useSelect( ( select ) => {
		const editSite = select( 'core/edit-site' );

		if ( ! editSite?.getEditedPostType || ! editSite?.getEditedPostId ) {
			return null;
		}

		if ( editSite.getEditedPostType() !== 'wp_template' ) {
			return null;
		}

		const editedPostId = editSite.getEditedPostId();

		return typeof editedPostId === 'string' && editedPostId !== ''
			? editedPostId
			: null;
	}, [] );
	const templateType = normalizeTemplateType( templateRef );
	const {
		recommendations,
		explanation,
		error,
		resultRef,
		resultToken,
		isLoading,
		selectedSuggestionKey,
		applyStatus,
		applyError,
		lastAppliedSuggestionKey,
		lastAppliedOperations,
		templateActivityEntries,
		latestTemplateActivity,
		latestUndoableActivityId,
		undoError,
		undoStatus,
		lastUndoneActivityId,
	} = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const blockEditor = select( blockEditorStore );
			const activityLog = store.getActivityLog() || [];
			const templateEntries = activityLog
				.filter(
					( entry ) =>
						entry?.surface === 'template' &&
						entry?.target?.templateRef === templateRef
				)
				.map( ( entry ) => ( {
					...entry,
					undo: getTemplateActivityUndoState(
						entry,
						blockEditor
					),
				} ) );
			const latestTemplateActivity =
				templateEntries[ templateEntries.length - 1 ] || null;
			const latestUndoableActivityId =
				latestTemplateActivity?.undo?.canUndo === true &&
				latestTemplateActivity?.undo?.status === 'available'
					? latestTemplateActivity.id
					: null;

			return {
				recommendations: store.getTemplateRecommendations(),
				explanation: store.getTemplateExplanation(),
				error: store.getTemplateError(),
				resultRef: store.getTemplateResultRef(),
				resultToken: store.getTemplateResultToken(),
				isLoading: store.isTemplateLoading(),
				selectedSuggestionKey: store.getTemplateSelectedSuggestionKey(),
				applyStatus: store.getTemplateApplyStatus(),
				applyError: store.getTemplateApplyError(),
				lastAppliedSuggestionKey:
					store.getTemplateLastAppliedSuggestionKey(),
				lastAppliedOperations: store.getTemplateLastAppliedOperations(),
				templateActivityEntries: [ ...templateEntries ]
					.slice( -3 )
					.reverse(),
				latestTemplateActivity,
				latestUndoableActivityId,
				undoError: store.getUndoError(),
				undoStatus: store.getUndoStatus(),
				lastUndoneActivityId: store.getLastUndoneActivityId(),
			};
		},
		[ templateRef ]
	);
	const patternTitleMap = useSelect( () => {
		const patterns = getCompatBlockPatterns();

		return patterns.reduce( ( acc, pattern ) => {
			if ( pattern?.name ) {
				acc[ pattern.name ] = pattern.title || pattern.name;
			}

			return acc;
		}, {} );
	}, [] );
	const insertionPointLabel = useSelect( ( select ) => {
		const blockEditor = select( 'core/block-editor' );
		const selectedBlockClientId =
			blockEditor?.getSelectedBlockClientId?.() || null;
		const selectedBlock = selectedBlockClientId
			? blockEditor?.getBlock?.( selectedBlockClientId )
			: null;
		const insertionPoint = blockEditor?.getBlockInsertionPoint?.() || null;
		const rootClientId = insertionPoint?.rootClientId || null;
		const rootBlock = rootClientId
			? blockEditor?.getBlock?.( rootClientId )
			: null;

		return describeInsertionPoint( {
			selectedBlock,
			rootBlock,
			insertionPoint,
		} );
	}, [] );
	const {
		applyTemplateSuggestion,
		clearUndoError,
		clearTemplateRecommendations,
		fetchTemplateRecommendations,
		setTemplateSelectedSuggestion,
		undoActivity,
	} = useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const previousTemplateRef = useRef( templateRef );
	const editorSlots = useMemo(
		() =>
			Array.isArray( templateBlocks ) && templateBlocks.length > 0
				? buildEditorTemplateSlotSnapshot( templateBlocks )
				: null,
		[ templateBlocks ]
	);
	const editorSlotsSignature = useMemo(
		() => JSON.stringify( editorSlots || null ),
		[ editorSlots ]
	);
	const previousEditorSlotsSignature = useRef( editorSlotsSignature );
	const hasMatchingResult = resultRef === templateRef;
	const hasSuggestions = hasMatchingResult && recommendations.length > 0;

	useEffect( () => {
		const templateChanged = previousTemplateRef.current !== templateRef;
		const slotsChanged =
			previousEditorSlotsSignature.current !== editorSlotsSignature;

		if ( ! templateChanged && ! slotsChanged ) {
			return;
		}

		const shouldClearRecommendations =
			templateChanged || ( slotsChanged && ( hasMatchingResult || isLoading ) );

		previousTemplateRef.current = templateRef;
		previousEditorSlotsSignature.current = editorSlotsSignature;

		if ( ! shouldClearRecommendations ) {
			return;
		}

		clearTemplateRecommendations();

		if ( templateChanged ) {
			setPrompt( '' );
		}
	}, [
		clearTemplateRecommendations,
		editorSlotsSignature,
		hasMatchingResult,
		isLoading,
		templateRef,
	] );

	const entityMap = useMemo(
		() => buildEntityMap( recommendations, patternTitleMap ),
		[ recommendations, patternTitleMap ]
	);
	const suggestionCards = useMemo(
		() =>
			recommendations.map( ( suggestion, index ) =>
				buildTemplateSuggestionViewModel(
					{
						...suggestion,
						suggestionKey: getSuggestionCardKey(
							suggestion,
							index
						),
					},
					patternTitleMap
				)
			),
		[ recommendations, patternTitleMap ]
	);

	const handleFetch = useCallback( () => {
		fetchTemplateRecommendations(
			buildTemplateFetchInput( {
				templateRef,
				templateType,
				prompt,
				editorSlots,
			} )
		);
	}, [
		editorSlots,
		fetchTemplateRecommendations,
		prompt,
		templateRef,
		templateType,
	] );

	const handleEntityAction = useCallback( ( entity ) => {
		switch ( entity?.actionType ) {
			case ENTITY_ACTION_SELECT_PART:
				selectBlockBySlugOrArea( entity.slug, entity.area );
				break;
			case ENTITY_ACTION_SELECT_AREA:
				selectBlockByArea( entity.area );
				break;
			case ENTITY_ACTION_BROWSE_PATTERN:
				openInserterForPattern( entity.filterValue || entity.name );
				break;
		}
	}, [] );
	const handlePreviewSuggestion = useCallback(
		( suggestionKey ) => {
			setTemplateSelectedSuggestion( suggestionKey );
		},
		[ setTemplateSelectedSuggestion ]
	);
	const handleCancelPreview = useCallback( () => {
		setTemplateSelectedSuggestion( null );
	}, [ setTemplateSelectedSuggestion ] );
	const handleApplySuggestion = useCallback(
		( suggestion ) => {
			applyTemplateSuggestion( suggestion );
		},
		[ applyTemplateSuggestion ]
	);
	const handleUndo = useCallback(
		( activityId ) => {
			undoActivity( activityId );
		},
		[ undoActivity ]
	);

	if ( ! canRecommend || ! templateRef ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-template-recommendations"
			title="AI Template Recommendations"
		>
			<div className="flavor-agent-panel">
				<div className="flavor-agent-panel__intro">
					<p className="flavor-agent-panel__eyebrow">
						{ formatTemplateTypeLabel( templateType ) }
					</p>
					<p className="flavor-agent-panel__intro-copy">
						Describe the structure or layout you want. Review each
						suggested template-part change or pattern insertion,
						then confirm before Flavor Agent mutates the template.
					</p>
				</div>

				<div className="flavor-agent-panel__composer">
					<TextareaControl
						__nextHasNoMarginBottom
						label="What are you trying to achieve with this template?"
						hideLabelFromVision
						placeholder="Describe the structure or layout you want."
						value={ prompt }
						onChange={ setPrompt }
						rows={ 3 }
						className="flavor-agent-prompt"
					/>

					<Button
						variant="primary"
						onClick={ handleFetch }
						disabled={ isLoading }
						className="flavor-agent-fetch-button"
					>
						{ isLoading
							? 'Getting suggestions…'
							: 'Get Suggestions' }
					</Button>
				</div>

				{ isLoading && (
					<Notice status="info" isDismissible={ false }>
						Analyzing template structure…
					</Notice>
				) }

				{ error && (
					<Notice status="error" isDismissible={ false }>
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

				{ applyStatus === 'error' && applyError && (
					<Notice status="error" isDismissible={ false }>
						{ applyError }
					</Notice>
				) }

				{ applyStatus === 'success' &&
					lastAppliedSuggestionKey &&
					lastAppliedOperations.length > 0 &&
					latestTemplateActivity &&
					latestTemplateActivity.id === latestUndoableActivityId && (
						<Notice status="success" isDismissible={ false }>
							Applied{ ' ' }
							{ formatCount(
								lastAppliedOperations.length,
								'template operation'
							) }
							.{ ' ' }
							<Button
								variant="link"
								onClick={ () =>
									handleUndo( latestTemplateActivity.id )
								}
								disabled={ undoStatus === 'undoing' }
							>
								{ undoStatus === 'undoing'
									? 'Undoing…'
									: 'Undo' }
							</Button>
						</Notice>
					) }

				{ latestTemplateActivity &&
					undoStatus === 'success' &&
					lastUndoneActivityId === latestTemplateActivity.id && (
						<Notice status="success" isDismissible={ false }>
							Undid{ ' ' }
							<strong>
								{ latestTemplateActivity.suggestion }
							</strong>
							.
						</Notice>
					) }

				{ hasMatchingResult && explanation && (
					<p className="flavor-agent-explanation flavor-agent-panel__note">
						<LinkedText
							text={ explanation }
							entities={ entityMap }
							onEntityClick={ handleEntityAction }
						/>
					</p>
				) }

				<AIActivitySection
					entries={ templateActivityEntries }
					latestUndoableActivityId={ latestUndoableActivityId }
					isUndoing={ undoStatus === 'undoing' }
					onUndo={ handleUndo }
				/>

				{ hasSuggestions && (
					<div className="flavor-agent-panel__group">
						<div className="flavor-agent-panel__group-header">
							<div className="flavor-agent-panel__group-title">
								Suggested Composition
							</div>
							<span className="flavor-agent-pill">
								{ formatCount(
									suggestionCards.length,
									'suggestion'
								) }
							</span>
						</div>
						<div className="flavor-agent-panel__group-body">
							{ suggestionCards.map( ( suggestion, index ) => (
								<TemplateSuggestionCard
									key={ `${ resultToken }-${ getSuggestionCardKey(
										suggestion,
										index
									) }` }
									suggestion={ suggestion }
									entityMap={ entityMap }
									insertionPointLabel={ insertionPointLabel }
									isApplied={
										lastAppliedSuggestionKey ===
										suggestion.suggestionKey
									}
									isApplying={ applyStatus === 'applying' }
									isSelected={
										selectedSuggestionKey ===
										suggestion.suggestionKey
									}
									onEntityClick={ handleEntityAction }
									onApplySuggestion={ handleApplySuggestion }
									onCancelPreview={ handleCancelPreview }
									onPreviewSuggestion={
										handlePreviewSuggestion
									}
								/>
							) ) }
						</div>
					</div>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

function TemplateSuggestionCard( {
	suggestion,
	entityMap = [],
	insertionPointLabel,
	isApplied = false,
	isApplying = false,
	isSelected = false,
	onApplySuggestion,
	onCancelPreview,
	onEntityClick,
	onPreviewSuggestion,
} ) {
	const hasParts = suggestion.templateParts?.length > 0;
	const hasPatterns = suggestion.patternSuggestions?.length > 0;
	const summaryParts = [];

	if ( hasParts ) {
		summaryParts.push(
			formatCount( suggestion.templateParts.length, 'part' )
		);
	}

	if ( hasPatterns ) {
		summaryParts.push(
			formatCount( suggestion.patternSuggestions.length, 'pattern' )
		);
	}

	return (
		<div
			className={ `flavor-agent-card flavor-agent-card--template${
				isApplied ? ' is-applied' : ''
			}` }
		>
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<div className="flavor-agent-card__label">
						{ suggestion.label }
					</div>
					{ summaryParts.length > 0 && (
						<div className="flavor-agent-card__meta">
							<span className="flavor-agent-pill">
								{ summaryParts.join( ' • ' ) }
							</span>
							{ isApplied && (
								<span className="flavor-agent-done-badge">
									Applied
								</span>
							) }
						</div>
					) }
				</div>

				{ suggestion.canApply && (
					<Button
						size="small"
						variant={ isSelected ? 'secondary' : 'primary' }
						onClick={ () =>
							isSelected
								? onCancelPreview()
								: onPreviewSuggestion(
										suggestion.suggestionKey
								  )
						}
						className="flavor-agent-card__apply"
						disabled={ isApplying && ! isSelected }
					>
						{ isSelected ? 'Cancel Preview' : 'Preview Apply' }
					</Button>
				) }
			</div>

			{ suggestion.description && (
				<p className="flavor-agent-card__description">
					<LinkedText
						text={ suggestion.description }
						entities={ entityMap }
						onEntityClick={ onEntityClick }
					/>
				</p>
			) }

			{ suggestion.executionError && (
				<p className="flavor-agent-card__description">
					{ suggestion.executionError }
				</p>
			) }

			{ hasParts && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">
							Template Parts
						</div>
						<span className="flavor-agent-pill">
							{ formatCount(
								suggestion.templateParts.length,
								'part'
							) }
						</span>
					</div>
					{ suggestion.templateParts.map( ( part ) => (
						<div key={ part.key } className="flavor-agent-tpl-row">
							<span className="flavor-agent-tpl-row__mapping">
								<Tooltip
									text={ `Select “${ part.slug }” block in editor` }
								>
									<Button
										size="small"
										variant="link"
										onClick={ () =>
											selectBlockBySlugOrArea(
												part.slug,
												part.area
											)
										}
										className="flavor-agent-action-link flavor-agent-action-link--part"
									>
										{ part.slug }
									</Button>
								</Tooltip>

								<span className="flavor-agent-tpl-row__arrow">
									→
								</span>

								<Tooltip
									text={ `Select “${ part.area }” area in editor` }
								>
									<Button
										size="small"
										variant="link"
										onClick={ () =>
											selectBlockByArea( part.area )
										}
										className="flavor-agent-action-link flavor-agent-action-link--area"
									>
										{ part.area }
									</Button>
								</Tooltip>
							</span>

							<span className="flavor-agent-pill">
								{ part.ctaLabel }
							</span>

							{ part.reason && (
								<div className="flavor-agent-tpl-row__reason">
									<LinkedText
										text={ part.reason }
										entities={ entityMap }
										onEntityClick={ onEntityClick }
									/>
								</div>
							) }
						</div>
					) ) }
				</div>
			) }

			{ hasPatterns && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">
							Suggested Patterns
						</div>
						<span className="flavor-agent-pill">
							{ formatCount(
								suggestion.patternSuggestions.length,
								'pattern'
							) }
						</span>
					</div>
					{ suggestion.patternSuggestions.map( ( pattern ) => (
						<div
							key={ pattern.name }
							className="flavor-agent-tpl-row"
						>
							<Tooltip
								text={ `Browse “${ pattern.title }” in pattern inserter` }
							>
								<Button
									size="small"
									variant="link"
									onClick={ () =>
										openInserterForPattern( pattern.title )
									}
									className="flavor-agent-action-link flavor-agent-action-link--pattern"
								>
									{ pattern.title }
								</Button>
							</Tooltip>

							<Button
								size="small"
								variant="tertiary"
								onClick={ () =>
									openInserterForPattern( pattern.title )
								}
								className="flavor-agent-assign-btn"
							>
								{ pattern.ctaLabel }
							</Button>
						</div>
					) ) }
				</div>
			) }

			{ isSelected && suggestion.operations?.length > 0 && (
				<div className="flavor-agent-template-preview">
					<div className="flavor-agent-template-list">
						<div className="flavor-agent-template-list__header">
							<div className="flavor-agent-section-label">
								Review Before Apply
							</div>
							<span className="flavor-agent-pill">
								{ formatCount(
									suggestion.operations.length,
									'operation'
								) }
							</span>
						</div>
						{ suggestion.operations.map( ( operation ) => (
							<TemplateOperationPreviewRow
								key={ operation.key }
								insertionPointLabel={ insertionPointLabel }
								operation={ operation }
							/>
						) ) }
					</div>
					<p className="flavor-agent-subpanel-hint">
						Pattern insertions use the current insertion point. To
						change where a pattern lands, select a different block
						in the canvas before confirming.
					</p>
					<div className="flavor-agent-template-preview__actions">
						<Button
							variant="primary"
							onClick={ () => onApplySuggestion( suggestion ) }
							disabled={ isApplying }
							className="flavor-agent-card__apply"
						>
							{ isApplying ? 'Applying…' : 'Confirm Apply' }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}

function TemplateOperationPreviewRow( { operation, insertionPointLabel } ) {
	switch ( operation.type ) {
		case TEMPLATE_OPERATION_ASSIGN:
			return (
				<div className="flavor-agent-tpl-row">
					<span className="flavor-agent-tpl-row__mapping">
						<span className="flavor-agent-action-link flavor-agent-action-link--part">
							{ operation.slug }
						</span>
						<span className="flavor-agent-tpl-row__arrow">→</span>
						<span className="flavor-agent-action-link flavor-agent-action-link--area">
							{ operation.area }
						</span>
					</span>
					<span className="flavor-agent-pill">
						{ operation.badgeLabel }
					</span>
					<div className="flavor-agent-tpl-row__reason">
						Assign <code>{ operation.slug }</code> to the{ ' ' }
						<code>{ operation.area }</code> area.
					</div>
				</div>
			);

		case TEMPLATE_OPERATION_REPLACE:
			return (
				<div className="flavor-agent-tpl-row">
					<span className="flavor-agent-tpl-row__mapping">
						<span className="flavor-agent-action-link flavor-agent-action-link--part">
							{ operation.currentSlug }
						</span>
						<span className="flavor-agent-tpl-row__arrow">→</span>
						<span className="flavor-agent-action-link flavor-agent-action-link--part">
							{ operation.slug }
						</span>
					</span>
					<span className="flavor-agent-pill">
						{ operation.badgeLabel }
					</span>
					<div className="flavor-agent-tpl-row__reason">
						Replace the current{ ' ' }
						<code>{ operation.currentSlug }</code> template part in
						the <code>{ operation.area }</code> area with{ ' ' }
						<code>{ operation.slug }</code>.
					</div>
				</div>
			);

		case TEMPLATE_OPERATION_INSERT_PATTERN:
			return (
				<div className="flavor-agent-tpl-row">
					<span className="flavor-agent-tpl-row__mapping">
						<span className="flavor-agent-action-link flavor-agent-action-link--pattern">
							{ operation.patternTitle }
						</span>
					</span>
					<span className="flavor-agent-pill">
						{ operation.badgeLabel }
					</span>
					<div className="flavor-agent-tpl-row__reason">
						Insert <code>{ operation.patternTitle }</code>{ ' ' }
						{ insertionPointLabel }.
					</div>
				</div>
			);
	}

	return null;
}
