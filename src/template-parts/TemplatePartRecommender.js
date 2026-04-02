import { Button, TextareaControl, Tooltip } from '@wordpress/components';
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
import AIAdvisorySection from '../components/AIAdvisorySection';
import AIReviewSection from '../components/AIReviewSection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import { getBlockPatterns as getCompatBlockPatterns } from '../patterns/compat';
import { STORE_NAME } from '../store';
import {
	getLatestAppliedActivity,
	getLatestUndoableActivity,
	getResolvedActivityEntries,
} from '../store/activity-history';
import {
	getTemplatePartActivityUndoState,
	openInserterForPattern,
	selectBlockByPath,
} from '../utils/template-actions';
import { getVisiblePatternNames } from '../utils/visible-patterns';
import {
	TEMPLATE_OPERATION_INSERT_PATTERN,
	TEMPLATE_OPERATION_REMOVE_BLOCK,
	TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
	TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH,
	TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH,
	validateTemplatePartOperationSequence,
} from '../utils/template-operation-sequence';
import { getTemplatePartAreaLookup } from '../utils/template-part-areas';
import { getSurfaceCapability } from '../utils/capability-flags';
import { formatCount } from '../utils/format-count';

function normalizeTemplatePartSlug( templatePartRef ) {
	if ( typeof templatePartRef !== 'string' || templatePartRef === '' ) {
		return '';
	}

	return templatePartRef.includes( '//' )
		? templatePartRef.slice( templatePartRef.indexOf( '//' ) + 2 )
		: templatePartRef;
}

function humanizeLabel( value ) {
	if ( ! value ) {
		return '';
	}

	return value
		.split( /[-_]/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

function formatTemplatePartLabel( slug, area ) {
	return `${ humanizeLabel( area || slug || 'Current' ) } Template Part`;
}

function formatBlockPath( path = [] ) {
	return `Path ${ path.join( ' > ' ) }`;
}

function deriveTemplatePartArea(
	slug,
	areaLookup = getTemplatePartAreaLookup()
) {
	if ( typeof areaLookup?.[ slug ] === 'string' && areaLookup[ slug ] ) {
		return areaLookup[ slug ];
	}

	if (
		slug === 'header' ||
		slug === 'footer' ||
		slug === 'sidebar' ||
		slug === 'navigation-overlay'
	) {
		return slug;
	}

	return '';
}

function normalizeVisiblePatternNames( visiblePatternNames ) {
	if ( ! Array.isArray( visiblePatternNames ) ) {
		return null;
	}

	return Array.from( new Set( visiblePatternNames.filter( Boolean ) ) );
}

function buildTemplatePartRecommendationContextSignature( {
	visiblePatternNames,
} = {} ) {
	const normalizedVisiblePatternNames =
		normalizeVisiblePatternNames( visiblePatternNames );

	return JSON.stringify( {
		visiblePatternNames: Array.isArray( normalizedVisiblePatternNames )
			? [ ...normalizedVisiblePatternNames ].sort()
			: null,
	} );
}

function buildTemplatePartFetchInput( {
	templatePartRef,
	prompt,
	visiblePatternNames,
} ) {
	const input = { templatePartRef };
	const trimmedPrompt = prompt.trim();
	const normalizedVisiblePatternNames =
		normalizeVisiblePatternNames( visiblePatternNames );

	if ( trimmedPrompt ) {
		input.prompt = trimmedPrompt;
	}

	if ( Array.isArray( normalizedVisiblePatternNames ) ) {
		input.visiblePatternNames = normalizedVisiblePatternNames;
	}

	return input;
}

function getSuggestionCardKey( suggestion = {}, index ) {
	return `${ suggestion.label || 'suggestion' }-${ index }`;
}

function getOperationKey( operation = {} ) {
	return `${ operation?.type || 'operation' }|${
		operation?.patternName || ''
	}|${ operation?.placement || '' }|${
		Array.isArray( operation?.targetPath )
			? operation.targetPath.join( '.' )
			: ''
	}|${ operation?.expectedBlockName || '' }`;
}

function formatPlacementLabel( placement ) {
	if ( placement === 'start' ) {
		return 'Start of this template part';
	}

	if ( placement === 'end' ) {
		return 'End of this template part';
	}

	if ( placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH ) {
		return 'Before target block';
	}

	return 'After target block';
}

function formatBlockNameLabel( blockName = '' ) {
	if ( ! blockName ) {
		return 'block';
	}

	const normalized = blockName.includes( '/' )
		? blockName.split( '/' )[ 1 ]
		: blockName;

	return humanizeLabel( normalized ) || blockName;
}

function formatTargetPathLabel( path = [] ) {
	return Array.isArray( path ) && path.length > 0
		? `Target ${ formatBlockPath( path ) }`
		: 'Target block';
}

function buildTemplatePartSuggestionViewModel(
	suggestion = {},
	patternTitleMap = {}
) {
	const blockHints = Array.isArray( suggestion?.blockHints )
		? suggestion.blockHints
		: [];
	const rawPatternSuggestions = Array.isArray(
		suggestion?.patternSuggestions
	)
		? suggestion.patternSuggestions
		: [];
	const rawOperations = Array.isArray( suggestion?.operations )
		? suggestion.operations
		: [];
	const executableOperations =
		rawOperations.length > 0
			? validateTemplatePartOperationSequence( rawOperations )
			: { ok: true, operations: [] };
	const operations = executableOperations.ok
		? executableOperations.operations
				.map( ( operation ) => {
					switch ( operation?.type ) {
						case TEMPLATE_OPERATION_INSERT_PATTERN:
							return {
								key: getOperationKey( operation ),
								type: TEMPLATE_OPERATION_INSERT_PATTERN,
								patternName: operation.patternName,
								patternTitle:
									patternTitleMap[ operation.patternName ] ||
									operation.patternName,
								placement: operation.placement,
								targetPath: Array.isArray(
									operation.targetPath
								)
									? operation.targetPath
									: null,
								badgeLabel: 'Insert',
							};

						case TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN:
							return {
								key: getOperationKey( operation ),
								type: TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN,
								patternName: operation.patternName,
								patternTitle:
									patternTitleMap[ operation.patternName ] ||
									operation.patternName,
								expectedBlockName: operation.expectedBlockName,
								targetPath: operation.targetPath,
								badgeLabel: 'Replace',
							};

						case TEMPLATE_OPERATION_REMOVE_BLOCK:
							return {
								key: getOperationKey( operation ),
								type: TEMPLATE_OPERATION_REMOVE_BLOCK,
								expectedBlockName: operation.expectedBlockName,
								targetPath: operation.targetPath,
								badgeLabel: 'Remove',
							};

						default:
							return null;
					}
				} )
				.filter( Boolean )
		: [];
	const mergedPatternSuggestions = Array.from(
		new Set(
			[
				...rawPatternSuggestions,
				...operations
					.map( ( operation ) => operation.patternName )
					.filter( Boolean ),
			].filter( Boolean )
		)
	).map( ( patternName ) => ( {
		name: patternName,
		title: patternTitleMap[ patternName ] || patternName,
		ctaLabel: 'Browse pattern',
	} ) );

	return {
		suggestionKey: suggestion?.suggestionKey || '',
		label: suggestion?.label || '',
		description: suggestion?.description || '',
		blockHints,
		patternSuggestions: mergedPatternSuggestions,
		operations,
		executionError:
			rawOperations.length > 0 && ! executableOperations.ok
				? executableOperations.error || ''
				: '',
		canApply:
			rawOperations.length > 0 &&
			executableOperations.ok &&
			operations.length > 0,
	};
}

export default function TemplatePartRecommender() {
	const canRecommend = getSurfaceCapability( 'template-part' ).available;
	const templatePartRef = useSelect( ( select ) => {
		const editSite = select( 'core/edit-site' );

		if ( ! editSite?.getEditedPostType || ! editSite?.getEditedPostId ) {
			return null;
		}

		if ( editSite.getEditedPostType() !== 'wp_template_part' ) {
			return null;
		}

		const editedPostId = editSite.getEditedPostId();

		return typeof editedPostId === 'string' && editedPostId !== ''
			? editedPostId
			: null;
	}, [] );
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
		activityLog,
		undoError,
		undoStatus,
		lastUndoneActivityId,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );

		return {
			recommendations: store.getTemplatePartRecommendations(),
			explanation: store.getTemplatePartExplanation(),
			error: store.getTemplatePartError(),
			resultRef: store.getTemplatePartResultRef(),
			resultToken: store.getTemplatePartResultToken(),
			isLoading: store.isTemplatePartLoading(),
			selectedSuggestionKey: store.getTemplatePartSelectedSuggestionKey(),
			applyStatus: store.getTemplatePartApplyStatus(),
			applyError: store.getTemplatePartApplyError(),
			lastAppliedSuggestionKey:
				store.getTemplatePartLastAppliedSuggestionKey(),
			lastAppliedOperations: store.getTemplatePartLastAppliedOperations(),
			activityLog: store.getActivityLog() || [],
			undoError: store.getUndoError(),
			undoStatus: store.getUndoStatus(),
			lastUndoneActivityId: store.getLastUndoneActivityId(),
		};
	}, [] );
	const editorBlocks = useSelect(
		( select ) => select( blockEditorStore ).getBlocks?.() || [],
		[]
	);
	const blockEditorSelection = useMemo(
		() => ( {
			getBlocks: () => editorBlocks,
		} ),
		[ editorBlocks ]
	);
	const resolvedTemplatePartActivities = useMemo(
		() =>
			getResolvedActivityEntries(
				activityLog.filter(
					( entry ) =>
						entry?.surface === 'template-part' &&
						entry?.target?.templatePartRef === templatePartRef
				),
				( entry ) =>
					getTemplatePartActivityUndoState(
						entry,
						blockEditorSelection
					)
			),
		[ activityLog, blockEditorSelection, templatePartRef ]
	);
	const templatePartActivityEntries = useMemo(
		() => [ ...resolvedTemplatePartActivities ].slice( -3 ).reverse(),
		[ resolvedTemplatePartActivities ]
	);
	const latestTemplatePartActivity = useMemo(
		() => getLatestAppliedActivity( resolvedTemplatePartActivities ),
		[ resolvedTemplatePartActivities ]
	);
	const latestUndoableActivityId = useMemo(
		() =>
			getLatestUndoableActivity( resolvedTemplatePartActivities )?.id ||
			null,
		[ resolvedTemplatePartActivities ]
	);
	const lastUndoneTemplatePartActivity = useMemo(
		() =>
			resolvedTemplatePartActivities.find(
				( entry ) => entry?.id === lastUndoneActivityId
			) || null,
		[ resolvedTemplatePartActivities, lastUndoneActivityId ]
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
	const visiblePatternNames = useSelect( ( select ) => {
		const blockEditorStoreSelect = select( blockEditorStore );

		return getVisiblePatternNames( null, blockEditorStoreSelect );
	}, [] );
	const {
		applyTemplatePartSuggestion,
		clearTemplatePartRecommendations,
		clearUndoError,
		fetchTemplatePartRecommendations,
		setTemplatePartSelectedSuggestion,
		undoActivity,
	} = useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const previousTemplatePartRef = useRef( templatePartRef );
	const recommendationContextSignature = useMemo(
		() =>
			buildTemplatePartRecommendationContextSignature( {
				visiblePatternNames,
			} ),
		[ visiblePatternNames ]
	);
	const previousRecommendationContextSignature = useRef(
		recommendationContextSignature
	);

	const slug = useMemo(
		() => normalizeTemplatePartSlug( templatePartRef ),
		[ templatePartRef ]
	);
	const area = useMemo( () => deriveTemplatePartArea( slug ), [ slug ] );
	const hasMatchingResult = resultRef === templatePartRef;
	const hasSuggestions = hasMatchingResult && recommendations.length > 0;

	useEffect( () => {
		const templatePartChanged =
			previousTemplatePartRef.current !== templatePartRef;
		const recommendationContextChanged =
			previousRecommendationContextSignature.current !==
			recommendationContextSignature;

		if ( ! templatePartChanged && ! recommendationContextChanged ) {
			return;
		}

		const shouldClearRecommendations =
			templatePartChanged ||
			( recommendationContextChanged &&
				( hasMatchingResult || isLoading ) );

		previousTemplatePartRef.current = templatePartRef;
		previousRecommendationContextSignature.current =
			recommendationContextSignature;

		if ( ! shouldClearRecommendations ) {
			return;
		}

		clearTemplatePartRecommendations();

		if ( templatePartChanged ) {
			setPrompt( '' );
		}
	}, [
		clearTemplatePartRecommendations,
		hasMatchingResult,
		isLoading,
		recommendationContextSignature,
		templatePartRef,
	] );

	const suggestionCards = useMemo(
		() =>
			recommendations.map( ( suggestion, index ) =>
				buildTemplatePartSuggestionViewModel(
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
	const executableSuggestionCards = useMemo(
		() => suggestionCards.filter( ( suggestion ) => suggestion.canApply ),
		[ suggestionCards ]
	);
	const advisorySuggestionCards = useMemo(
		() => suggestionCards.filter( ( suggestion ) => ! suggestion.canApply ),
		[ suggestionCards ]
	);
	const hasApplySuccess =
		applyStatus === 'success' &&
		lastAppliedSuggestionKey &&
		lastAppliedOperations.length > 0 &&
		latestTemplatePartActivity &&
		latestTemplatePartActivity.id === latestUndoableActivityId;
	const hasUndoSuccess =
		undoStatus === 'success' &&
		lastUndoneTemplatePartActivity?.undo?.status === 'undone';
	const { interactionState, statusNotice } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );

			return {
				interactionState: store.getTemplatePartInteractionState( {
					undoError,
					hasPreview: Boolean( selectedSuggestionKey ),
					hasSuccess: Boolean( hasApplySuccess ),
					hasUndoSuccess,
				} ),
				statusNotice: store.getSurfaceStatusNotice( 'template-part', {
					requestStatus: isLoading ? 'loading' : 'idle',
					requestError: error,
					applyError,
					undoError,
					undoStatus,
					applyStatus,
					hasResult: hasMatchingResult,
					hasSuggestions,
					hasPreview: Boolean( selectedSuggestionKey ),
					hasSuccess: Boolean( hasApplySuccess ),
					hasUndoSuccess,
					applySuccessMessage: hasApplySuccess
						? `Applied ${ formatCount(
								lastAppliedOperations.length,
								'template-part operation'
						  ) }.`
						: '',
					undoSuccessMessage: hasUndoSuccess
						? `Undid ${
								lastUndoneTemplatePartActivity?.suggestion ||
								'suggestion'
						  }.`
						: '',
					emptyMessage: hasMatchingResult
						? 'No template-part suggestions were returned for this request.'
						: '',
					onUndoDismissAction: Boolean( undoError ),
				} ),
			};
		},
		[
			applyError,
			applyStatus,
			error,
			hasApplySuccess,
			hasMatchingResult,
			hasSuggestions,
			isLoading,
			lastAppliedOperations,
			lastUndoneTemplatePartActivity,
			selectedSuggestionKey,
			undoError,
			undoStatus,
			hasUndoSuccess,
		]
	);

	const handleFetch = useCallback( () => {
		if ( ! canRecommend ) {
			return;
		}

		fetchTemplatePartRecommendations(
			buildTemplatePartFetchInput( {
				templatePartRef,
				prompt,
				visiblePatternNames,
			} )
		);
	}, [
		canRecommend,
		fetchTemplatePartRecommendations,
		prompt,
		templatePartRef,
		visiblePatternNames,
	] );
	const handlePreviewSuggestion = useCallback(
		( suggestionKey ) => {
			setTemplatePartSelectedSuggestion( suggestionKey );
		},
		[ setTemplatePartSelectedSuggestion ]
	);
	const handleCancelPreview = useCallback( () => {
		setTemplatePartSelectedSuggestion( null );
	}, [ setTemplatePartSelectedSuggestion ] );
	const handleApplySuggestion = useCallback(
		( suggestion ) => {
			applyTemplatePartSuggestion( suggestion );
		},
		[ applyTemplatePartSuggestion ]
	);
	const handleUndo = useCallback(
		( activityId ) => {
			undoActivity( activityId );
		},
		[ undoActivity ]
	);

	if ( ! templatePartRef ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-template-part-recommendations"
			title="AI Template Part Recommendations"
		>
			<div className="flavor-agent-panel">
				<div className="flavor-agent-panel__intro">
					<p className="flavor-agent-panel__eyebrow">
						{ formatTemplatePartLabel( slug, area ) }
					</p>
					<div className="flavor-agent-card__meta">
						{ area && (
							<span className="flavor-agent-pill">
								Area: { humanizeLabel( area ) }
							</span>
						) }
						{ slug && (
							<code className="flavor-agent-pill flavor-agent-pill--code">
								Slug: { slug }
							</code>
						) }
					</div>
					<p className="flavor-agent-panel__intro-copy">
						Describe the structural change you want inside this
						template part. Review the focus blocks and pattern
						suggestions first, then confirm only the executable
						operations Flavor Agent can validate deterministically.
					</p>
				</div>

				{ ! canRecommend && (
					<CapabilityNotice surface="template-part" />
				) }

				{ canRecommend && (
					<div className="flavor-agent-panel__composer">
						<TextareaControl
							__nextHasNoMarginBottom
							label="What are you trying to achieve with this template part?"
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
				) }

				{ canRecommend && isLoading && (
					<AIStatusNotice
						notice={ {
							tone: 'info',
							message: 'Analyzing template-part structure…',
						} }
					/>
				) }

				<AIStatusNotice
					notice={ statusNotice }
					onAction={
						statusNotice?.actionType === 'undo' &&
						latestTemplatePartActivity
							? () => handleUndo( latestTemplatePartActivity.id )
							: undefined
					}
					onDismiss={
						statusNotice?.source === 'undo'
							? clearUndoError
							: undefined
					}
				/>

				{ canRecommend && hasMatchingResult && explanation && (
					<p className="flavor-agent-explanation flavor-agent-panel__note">
						{ explanation }
					</p>
				) }

				<AIActivitySection
					description={
						interactionState === 'success' ||
						templatePartActivityEntries.length > 0
							? 'Template-part actions share the same history and latest-valid undo behavior as the other executable review surfaces.'
							: ''
					}
					entries={ templatePartActivityEntries }
					isUndoing={ undoStatus === 'undoing' }
					onUndo={ handleUndo }
				/>

				{ canRecommend && executableSuggestionCards.length > 0 && (
					<div className="flavor-agent-panel__group">
						<div className="flavor-agent-panel__group-header">
							<div className="flavor-agent-panel__group-title">
								Suggested Composition
							</div>
							<span className="flavor-agent-pill">
								{ formatCount(
									executableSuggestionCards.length,
									'suggestion'
								) }
							</span>
						</div>
						<div className="flavor-agent-panel__group-body">
							{ executableSuggestionCards.map(
								( suggestion, index ) => (
									<TemplatePartSuggestionCard
										key={ `${ resultToken }-${ getSuggestionCardKey(
											suggestion,
											index
										) }` }
										suggestion={ suggestion }
										isApplied={
											lastAppliedSuggestionKey ===
											suggestion.suggestionKey
										}
										isApplying={
											applyStatus === 'applying'
										}
										isSelected={
											selectedSuggestionKey ===
											suggestion.suggestionKey
										}
										onApplySuggestion={
											handleApplySuggestion
										}
										onCancelPreview={ handleCancelPreview }
										onPreviewSuggestion={
											handlePreviewSuggestion
										}
									/>
								)
							) }
						</div>
					</div>
				) }

				{ canRecommend && advisorySuggestionCards.length > 0 && (
					<AIAdvisorySection
						title="Advisory Suggestions"
						count={ advisorySuggestionCards.length }
						countNoun="suggestion"
						description="These suggestions stay visible, but Flavor Agent could not validate an exact deterministic operation sequence for them."
					>
						{ advisorySuggestionCards.map(
							( suggestion, index ) => (
								<TemplatePartSuggestionCard
									key={ `advisory-${ resultToken }-${ getSuggestionCardKey(
										suggestion,
										index
									) }` }
									suggestion={ suggestion }
									isApplied={
										lastAppliedSuggestionKey ===
										suggestion.suggestionKey
									}
									isApplying={ applyStatus === 'applying' }
									isSelected={
										selectedSuggestionKey ===
										suggestion.suggestionKey
									}
									onApplySuggestion={ handleApplySuggestion }
									onCancelPreview={ handleCancelPreview }
									onPreviewSuggestion={
										handlePreviewSuggestion
									}
								/>
							)
						) }
					</AIAdvisorySection>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

function TemplatePartSuggestionCard( {
	suggestion,
	isApplied = false,
	isApplying = false,
	isSelected = false,
	onApplySuggestion,
	onCancelPreview,
	onPreviewSuggestion,
} ) {
	const blockHints = Array.isArray( suggestion?.blockHints )
		? suggestion.blockHints
		: [];
	const patternSuggestions = Array.isArray( suggestion?.patternSuggestions )
		? suggestion.patternSuggestions
		: [];
	const summaryParts = [];

	if ( blockHints.length > 0 ) {
		summaryParts.push( formatCount( blockHints.length, 'block' ) );
	}

	if ( patternSuggestions.length > 0 ) {
		summaryParts.push(
			formatCount( patternSuggestions.length, 'pattern' )
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
					<div className="flavor-agent-card__meta">
						<span className="flavor-agent-pill">
							{ suggestion.canApply ? 'Executable' : 'Advisory' }
						</span>
						{ summaryParts.length > 0 && (
							<span className="flavor-agent-pill">
								{ summaryParts.join( ' • ' ) }
							</span>
						) }
						{ isApplied && (
							<span className="flavor-agent-done-badge">
								Applied
							</span>
						) }
					</div>
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
					{ suggestion.description }
				</p>
			) }

			{ suggestion.executionError && (
				<p className="flavor-agent-card__description">
					{ suggestion.executionError }
				</p>
			) }

			{ blockHints.length > 0 && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">
							Focus Blocks
						</div>
						<span className="flavor-agent-pill">
							{ formatCount( blockHints.length, 'block' ) }
						</span>
					</div>
					{ blockHints.map( ( hint ) => (
						<div
							key={ formatBlockPath( hint.path ) }
							className="flavor-agent-tpl-row"
						>
							<Tooltip
								text={ `Select “${ hint.label }” in editor` }
							>
								<Button
									size="small"
									variant="link"
									onClick={ () =>
										selectBlockByPath( hint.path )
									}
									className="flavor-agent-action-link flavor-agent-action-link--part"
								>
									{ hint.label }
								</Button>
							</Tooltip>

							<span className="flavor-agent-pill">
								{ formatBlockPath( hint.path ) }
							</span>

							<div className="flavor-agent-tpl-row__reason">
								{ hint.blockName }
								{ hint.reason ? `: ${ hint.reason }` : '' }
							</div>
						</div>
					) ) }
				</div>
			) }

			{ patternSuggestions.length > 0 && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-template-list__header">
						<div className="flavor-agent-section-label">
							Suggested Patterns
						</div>
						<span className="flavor-agent-pill">
							{ formatCount(
								patternSuggestions.length,
								'pattern'
							) }
						</span>
					</div>
					{ patternSuggestions.map( ( pattern ) => (
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
				<AIReviewSection
					count={ suggestion.operations.length }
					countNoun="operation"
					summary="Review the validated operations below before Flavor Agent mutates this template part."
					hint="Flavor Agent will only apply the exact deterministic operations shown here inside the current template part."
					confirmLabel={ isApplying ? 'Applying…' : 'Confirm Apply' }
					confirmDisabled={ isApplying }
					onConfirm={ () => onApplySuggestion( suggestion ) }
					onCancel={ onCancelPreview }
				>
					{ suggestion.operations.map( ( operation ) => (
						<TemplatePartOperationPreviewRow
							key={ operation.key }
							operation={ operation }
						/>
					) ) }
				</AIReviewSection>
			) }
		</div>
	);
}

function TemplatePartOperationPreviewRow( { operation } ) {
	if ( operation.type === TEMPLATE_OPERATION_REPLACE_BLOCK_WITH_PATTERN ) {
		return (
			<div className="flavor-agent-tpl-row">
				<span className="flavor-agent-tpl-row__mapping">
					<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
						{ formatTargetPathLabel( operation.targetPath ) }
					</span>
					<span className="flavor-agent-tpl-row__arrow">→</span>
					<span className="flavor-agent-preview-token flavor-agent-preview-token--pattern">
						{ operation.patternTitle }
					</span>
				</span>
				<span className="flavor-agent-pill">
					{ operation.badgeLabel }
				</span>
				<div className="flavor-agent-tpl-row__reason">
					Replace the{ ' ' }
					<code>
						{ formatBlockNameLabel( operation.expectedBlockName ) }
					</code>{ ' ' }
					at <code>{ formatBlockPath( operation.targetPath ) }</code>{ ' ' }
					with <code>{ operation.patternTitle }</code>.
				</div>
			</div>
		);
	}

	if ( operation.type === TEMPLATE_OPERATION_REMOVE_BLOCK ) {
		return (
			<div className="flavor-agent-tpl-row">
				<span className="flavor-agent-tpl-row__mapping">
					<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
						{ formatTargetPathLabel( operation.targetPath ) }
					</span>
				</span>
				<span className="flavor-agent-pill">
					{ operation.badgeLabel }
				</span>
				<div className="flavor-agent-tpl-row__reason">
					Remove the{ ' ' }
					<code>
						{ formatBlockNameLabel( operation.expectedBlockName ) }
					</code>{ ' ' }
					at <code>{ formatBlockPath( operation.targetPath ) }</code>.
				</div>
			</div>
		);
	}

	const placementTarget =
		operation.placement === TEMPLATE_PART_PLACEMENT_BEFORE_BLOCK_PATH ||
		operation.placement === TEMPLATE_PART_PLACEMENT_AFTER_BLOCK_PATH
			? `${ formatPlacementLabel(
					operation.placement
			  ) } (${ formatBlockPath( operation.targetPath ) })`
			: formatPlacementLabel( operation.placement );

	return (
		<div className="flavor-agent-tpl-row">
			<span className="flavor-agent-tpl-row__mapping">
				<span className="flavor-agent-preview-token flavor-agent-preview-token--pattern">
					{ operation.patternTitle }
				</span>
				<span className="flavor-agent-tpl-row__arrow">→</span>
				<span className="flavor-agent-preview-token flavor-agent-preview-token--area">
					{ placementTarget }
				</span>
			</span>
			<span className="flavor-agent-pill">{ operation.badgeLabel }</span>
			<div className="flavor-agent-tpl-row__reason">
				Insert <code>{ operation.patternTitle }</code>{ ' ' }
				{ operation.targetPath ? 'relative to' : 'at' }{ ' ' }
				<code>
					{ operation.targetPath
						? formatBlockPath( operation.targetPath )
						: operation.placement }
				</code>
				.
			</div>
		</div>
	);
}
