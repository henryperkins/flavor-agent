import { Button, Notice, TextareaControl, Tooltip } from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';

import AIActivitySection from '../components/AIActivitySection';
import { getBlockPatterns as getCompatBlockPatterns } from '../patterns/compat';
import { STORE_NAME } from '../store';
import {
	getTemplatePartActivityUndoState,
	openInserterForPattern,
	selectBlockByPath,
} from '../utils/template-actions';
import { getVisiblePatternNames } from '../utils/visible-patterns';
import {
	TEMPLATE_OPERATION_INSERT_PATTERN,
	validateTemplatePartOperationSequence,
} from '../utils/template-operation-sequence';
import { getTemplatePartAreaLookup } from '../utils/template-part-areas';

function formatCount( count, noun ) {
	return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}

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

function deriveTemplatePartArea( slug, areaLookup = getTemplatePartAreaLookup() ) {
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

	if (
		Array.isArray( normalizedVisiblePatternNames ) &&
		normalizedVisiblePatternNames.length > 0
	) {
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
	}|${ operation?.placement || '' }`;
}

function formatPlacementLabel( placement ) {
	return placement === 'start'
		? 'Start of this template part'
		: 'End of this template part';
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
					if ( operation?.type !== TEMPLATE_OPERATION_INSERT_PATTERN ) {
						return null;
					}

					return {
						key: getOperationKey( operation ),
						type: TEMPLATE_OPERATION_INSERT_PATTERN,
						patternName: operation.patternName,
						patternTitle:
							patternTitleMap[ operation.patternName ] ||
							operation.patternName,
						placement: operation.placement,
						badgeLabel: 'Insert',
					};
				} )
				.filter( Boolean )
		: [];
	const mergedPatternSuggestions = Array.from(
		new Set( [
			...rawPatternSuggestions,
			...operations.map( ( operation ) => operation.patternName ),
		].filter( Boolean ) )
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
	const canRecommend = window.flavorAgentData?.canRecommendTemplateParts;
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
		templatePartActivityEntries,
		latestTemplatePartActivity,
		latestUndoableActivityId,
		undoError,
		undoStatus,
		lastUndoneActivityId,
	} = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const blockEditor = select( blockEditorStore );
			const activityLog = store.getActivityLog() || [];
			const templatePartEntries = activityLog
				.filter(
					( entry ) =>
						entry?.surface === 'template-part' &&
						entry?.target?.templatePartRef === templatePartRef
				)
				.map( ( entry ) => ( {
					...entry,
					undo: getTemplatePartActivityUndoState(
						entry,
						blockEditor
					),
				} ) );
			const latestTemplatePartEntry =
				templatePartEntries[ templatePartEntries.length - 1 ] || null;
			const latestUndoableTemplatePartActivityId =
				latestTemplatePartEntry?.undo?.canUndo === true &&
				latestTemplatePartEntry?.undo?.status === 'available'
					? latestTemplatePartEntry.id
					: null;

			return {
				recommendations: store.getTemplatePartRecommendations(),
				explanation: store.getTemplatePartExplanation(),
				error: store.getTemplatePartError(),
				resultRef: store.getTemplatePartResultRef(),
				resultToken: store.getTemplatePartResultToken(),
				isLoading: store.isTemplatePartLoading(),
				selectedSuggestionKey:
					store.getTemplatePartSelectedSuggestionKey(),
				applyStatus: store.getTemplatePartApplyStatus(),
				applyError: store.getTemplatePartApplyError(),
				lastAppliedSuggestionKey:
					store.getTemplatePartLastAppliedSuggestionKey(),
				lastAppliedOperations:
					store.getTemplatePartLastAppliedOperations(),
				templatePartActivityEntries: [ ...templatePartEntries ]
					.slice( -3 )
					.reverse(),
				latestTemplatePartActivity: latestTemplatePartEntry,
				latestUndoableActivityId:
					latestUndoableTemplatePartActivityId,
				undoError: store.getUndoError(),
				undoStatus: store.getUndoStatus(),
				lastUndoneActivityId: store.getLastUndoneActivityId(),
			};
		},
		[ templatePartRef ]
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
		const blockEditor = select( blockEditorStore );

		return getVisiblePatternNames( null, blockEditor );
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
	const area = useMemo(
		() => deriveTemplatePartArea( slug ),
		[ slug ]
	);
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

	const handleFetch = useCallback( () => {
		fetchTemplatePartRecommendations(
			buildTemplatePartFetchInput( {
				templatePartRef,
				prompt,
				visiblePatternNames,
			} )
		);
	}, [
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

	if ( ! canRecommend || ! templatePartRef ) {
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
						insertions Flavor Agent can place deterministically.
					</p>
				</div>

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

				{ isLoading && (
					<Notice status="info" isDismissible={ false }>
						Analyzing template-part structure…
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
					latestTemplatePartActivity &&
					latestTemplatePartActivity.id === latestUndoableActivityId && (
						<Notice status="success" isDismissible={ false }>
							Applied{ ' ' }
							{ formatCount(
								lastAppliedOperations.length,
								'template-part operation'
							) }
							.{ ' ' }
							<Button
								variant="link"
								onClick={ () =>
									handleUndo(
										latestTemplatePartActivity.id
									)
								}
								disabled={ undoStatus === 'undoing' }
							>
								{ undoStatus === 'undoing'
									? 'Undoing…'
									: 'Undo' }
							</Button>
						</Notice>
					) }

				{ latestTemplatePartActivity &&
					undoStatus === 'success' &&
					lastUndoneActivityId ===
						latestTemplatePartActivity.id && (
						<Notice status="success" isDismissible={ false }>
							Undid{ ' ' }
							<strong>
								{ latestTemplatePartActivity.suggestion }
							</strong>
							.
						</Notice>
					) }

				{ hasMatchingResult && explanation && (
					<p className="flavor-agent-explanation flavor-agent-panel__note">
						{ explanation }
					</p>
				) }

				<AIActivitySection
					entries={ templatePartActivityEntries }
					latestUndoableActivityId={ latestUndoableActivityId }
					isUndoing={ undoStatus === 'undoing' }
					onUndo={ handleUndo }
				/>

				{ hasMatchingResult &&
					! isLoading &&
					! error &&
					! hasSuggestions && (
						<Notice status="warning" isDismissible={ false }>
							No template-part suggestions were returned for
							this request.
						</Notice>
					) }

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
							) ) }
						</div>
					</div>
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
	const patternSuggestions = Array.isArray(
		suggestion?.patternSuggestions
	)
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
										openInserterForPattern(
											pattern.title
										)
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
							<TemplatePartOperationPreviewRow
								key={ operation.key }
								operation={ operation }
							/>
						) ) }
					</div>
					<p className="flavor-agent-subpanel-hint">
						Flavor Agent will insert this pattern at the exact
						placement shown below inside the current template
						part.
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

function TemplatePartOperationPreviewRow( { operation } ) {
	return (
		<div className="flavor-agent-tpl-row">
			<span className="flavor-agent-tpl-row__mapping">
				<span className="flavor-agent-action-link flavor-agent-action-link--pattern">
					{ operation.patternTitle }
				</span>
				<span className="flavor-agent-tpl-row__arrow">→</span>
				<span className="flavor-agent-action-link flavor-agent-action-link--area">
					{ formatPlacementLabel( operation.placement ) }
				</span>
			</span>
			<span className="flavor-agent-pill">{ operation.badgeLabel }</span>
			<div className="flavor-agent-tpl-row__reason">
				Insert <code>{ operation.patternTitle }</code> at the{ ' ' }
				<code>{ operation.placement }</code> of this template part.
			</div>
		</div>
	);
}
