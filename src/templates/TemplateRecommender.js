/**
 * Template Recommender
 *
 * Interactive AI template composition suggestions in the Site Editor
 * sidebar.  Every block-editor entity the LLM mentions is wired to the
 * correct editor element:
 *
 *   Template-part slugs / areas  →  selectBlock  (highlights in canvas,
 *       block inspector shows the template-part controls)
 *   Pattern names                →  opens the block Inserter on the
 *       Patterns tab, pre-filtered to that pattern so the user sees
 *       a live preview and can choose an insertion point
 *   "Assign" / "Insert" buttons  →  direct mutations
 *   "Apply All"                  →  batch-applies the entire suggestion
 *
 * Free-form text (explanation, description, reason) is scanned for
 * entity mentions and linked inline with the same type-aware actions.
 */
import { Button, Notice, TextareaControl, Tooltip } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { check } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { normalizeTemplateType } from '../utils/template-types';
import {
	applySuggestion,
	assignTemplatePart,
	insertPatternByName,
	openInserterForPattern,
	selectBlockByArea,
	selectBlockBySlugOrArea,
} from '../utils/template-actions';
import { getVisiblePatternNames } from '../utils/visible-patterns';

/* ------------------------------------------------------------------ */
/*  Entity types                                                       */
/* ------------------------------------------------------------------ */

const ENTITY_PART = 'part';
const ENTITY_AREA = 'area';
const ENTITY_PATTERN = 'pattern';

/* ------------------------------------------------------------------ */
/*  Inline entity linking                                              */
/* ------------------------------------------------------------------ */

/**
 * Build a de-duped, length-sorted list of linkable entities from every
 * suggestion in the response.
 *
 * Each entity carries a `type` so the renderer can apply type-specific
 * styles and the correct editor action:
 *   part    → selectBlock (canvas)
 *   area    → selectBlock (canvas)
 *   pattern → open Inserter filtered to that pattern
 */
function buildEntityMap( recommendations, patternTitleMap ) {
	const map = new Map();

	for ( const s of recommendations ) {
		/* Template parts → slug + area */
		if ( s.templateParts?.length > 0 ) {
			for ( const part of s.templateParts ) {
				if ( part.slug && ! map.has( part.slug ) ) {
					const slug = part.slug;
					const area = part.area;
					map.set( slug, {
						text: slug,
						type: ENTITY_PART,
						action: () =>
							selectBlockBySlugOrArea( slug, area ),
						tooltip: `Select \u201c${ slug }\u201d block in editor`,
					} );
				}
				if ( part.area && ! map.has( part.area ) ) {
					const area = part.area;
					map.set( area, {
						text: area,
						type: ENTITY_AREA,
						action: () => selectBlockByArea( area ),
						tooltip: `Select \u201c${ area }\u201d area in editor`,
					} );
				}
			}
		}

		/* Patterns → slug + display title */
		if ( s.patternSuggestions?.length > 0 ) {
			for ( const name of s.patternSuggestions ) {
				const title = patternTitleMap[ name ] || name;
				if ( ! map.has( name ) ) {
					map.set( name, {
						text: name,
						type: ENTITY_PATTERN,
						action: () => openInserterForPattern( title ),
						tooltip: `Browse \u201c${ title }\u201d in pattern inserter`,
					} );
				}
				if ( title !== name && ! map.has( title ) ) {
					const n = name;
					const t = title;
					map.set( title, {
						text: title,
						type: ENTITY_PATTERN,
						action: () => openInserterForPattern( t ),
						tooltip: `Browse \u201c${ t }\u201d in pattern inserter`,
					} );
				}
			}
		}
	}

	return Array.from( map.values() ).sort(
		( a, b ) => b.text.length - a.text.length
	);
}

/**
 * Case-insensitive word-boundary search.
 *
 * A "boundary" is the string edge or any character that is NOT
 * alphanumeric and NOT a hyphen, so slugs like "header-large-title"
 * stay atomic and "header" won't match inside them.
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
 * Render a text string with recognised entities replaced by clickable
 * action links, each styled for its entity type.
 */
function LinkedText( { text, entities } ) {
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
					onClick={ bestEntity.action }
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

/* ------------------------------------------------------------------ */
/*  Main component                                                     */
/* ------------------------------------------------------------------ */

export default function TemplateRecommender() {
	const canRecommend = window.flavorAgentData?.canRecommendTemplates;
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
	const { recommendations, explanation, error, resultRef, isLoading } =
		useSelect( ( select ) => {
			const store = select( STORE_NAME );

			return {
				recommendations: store.getTemplateRecommendations(),
				explanation: store.getTemplateExplanation(),
				error: store.getTemplateError(),
				resultRef: store.getTemplateResultRef(),
				isLoading: store.isTemplateLoading(),
			};
		}, [] );
	const patternTitleMap = useSelect( ( select ) => {
		const blockEditor = select( 'core/block-editor' );
		const settings = blockEditor?.getSettings?.() || {};
		const patterns = Array.isArray( settings.__experimentalBlockPatterns )
			? settings.__experimentalBlockPatterns
			: [];

		return patterns.reduce( ( acc, pattern ) => {
			if ( pattern?.name ) {
				acc[ pattern.name ] = pattern.title || pattern.name;
			}

			return acc;
		}, {} );
	}, [] );
	const { fetchTemplateRecommendations, clearTemplateRecommendations } =
		useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const previousTemplateRef = useRef( templateRef );

	useEffect( () => {
		if ( previousTemplateRef.current === templateRef ) {
			return;
		}

		clearTemplateRecommendations();
		setPrompt( '' );
		previousTemplateRef.current = templateRef;
	}, [ templateRef, clearTemplateRecommendations ] );

	const hasMatchingResult = resultRef === templateRef;
	const hasSuggestions = hasMatchingResult && recommendations.length > 0;

	const entityMap = useMemo(
		() => buildEntityMap( recommendations, patternTitleMap ),
		[ recommendations, patternTitleMap ]
	);

	const handleFetch = () => {
		const input = {
			templateRef,
			visiblePatternNames: getVisiblePatternNames(),
		};
		const trimmedPrompt = prompt.trim();

		if ( templateType ) {
			input.templateType = templateType;
		}

		if ( trimmedPrompt ) {
			input.prompt = trimmedPrompt;
		}

		fetchTemplateRecommendations( input );
	};

	if ( ! canRecommend || ! templateRef ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-template-recommendations"
			title="AI Template Recommendations"
		>
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
				{ isLoading ? 'Getting suggestions\u2026' : 'Get Suggestions' }
			</Button>

			{ isLoading && (
				<Notice status="info" isDismissible={ false }>
					Analyzing template structure\u2026
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ hasMatchingResult && explanation && (
				<p className="flavor-agent-explanation">
					<LinkedText text={ explanation } entities={ entityMap } />
				</p>
			) }

			{ hasSuggestions &&
				recommendations.map( ( suggestion, index ) => (
					<TemplateSuggestionCard
						key={ `${ suggestion.label }-${ index }` }
						suggestion={ suggestion }
						patternTitleMap={ patternTitleMap }
						entityMap={ entityMap }
					/>
				) ) }
		</PluginDocumentSettingPanel>
	);
}

/* ------------------------------------------------------------------ */
/*  Interactive suggestion card                                        */
/* ------------------------------------------------------------------ */

function TemplateSuggestionCard( {
	suggestion,
	patternTitleMap = {},
	entityMap = [],
} ) {
	const [ appliedParts, setAppliedParts ] = useState( {} );
	const [ insertedPatterns, setInsertedPatterns ] = useState( {} );
	const [ allApplied, setAllApplied ] = useState( false );

	/* ---- Navigation (non-destructive) ---- */

	const handleFocusPart = useCallback( ( slug, area ) => {
		selectBlockBySlugOrArea( slug, area );
	}, [] );

	const handleFocusArea = useCallback( ( area ) => {
		selectBlockByArea( area );
	}, [] );

	const handleBrowsePattern = useCallback( ( name ) => {
		const title = patternTitleMap[ name ] || name;
		openInserterForPattern( title );
	}, [ patternTitleMap ] );

	/* ---- Mutations ---- */

	const handleAssign = useCallback( ( slug, area ) => {
		if ( assignTemplatePart( slug, area ) ) {
			setAppliedParts( ( prev ) => ( {
				...prev,
				[ `${ slug }|${ area }` ]: true,
			} ) );
		}
	}, [] );

	const handleInsert = useCallback( ( name ) => {
		if ( insertPatternByName( name ) ) {
			setInsertedPatterns( ( prev ) => ( { ...prev, [ name ]: true } ) );
		}
	}, [] );

	const handleApplyAll = useCallback( () => {
		const results = applySuggestion( suggestion );

		const nextParts = {};
		for ( const p of results.parts ) {
			if ( p.applied ) {
				nextParts[ `${ p.slug }|${ p.area }` ] = true;
			}
		}
		setAppliedParts( nextParts );

		const nextPatterns = {};
		for ( const p of results.patterns ) {
			if ( p.inserted ) {
				nextPatterns[ p.name ] = true;
			}
		}
		setInsertedPatterns( nextPatterns );
		setAllApplied( true );
	}, [ suggestion ] );

	/* ---- Render ---- */

	const hasParts = suggestion.templateParts?.length > 0;
	const hasPatterns = suggestion.patternSuggestions?.length > 0;

	return (
		<div
			className={ `flavor-agent-card flavor-agent-card--template${
				allApplied ? ' is-applied' : ''
			}` }
		>
			{ /* ---- Header ---- */ }
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__label">
					{ suggestion.label }
				</div>
				<Button
					size="small"
					variant={ allApplied ? 'tertiary' : 'primary' }
					onClick={ handleApplyAll }
					disabled={ allApplied }
					icon={ allApplied ? check : undefined }
					className="flavor-agent-card__apply"
				>
					{ allApplied ? 'Applied' : 'Apply All' }
				</Button>
			</div>

			{ suggestion.description && (
				<p className="flavor-agent-card__description">
					<LinkedText
						text={ suggestion.description }
						entities={ entityMap }
					/>
				</p>
			) }

			{ /* ---- Template parts ---- */ }
			{ hasParts && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-section-label">
						Template Parts
					</div>
					{ suggestion.templateParts.map( ( part ) => {
						const key = `${ part.slug }|${ part.area }`;
						const isDone = !! appliedParts[ key ];

						return (
							<div
								key={ key }
								className={ `flavor-agent-tpl-row${
									isDone ? ' is-done' : ''
								}` }
							>
								<span className="flavor-agent-tpl-row__mapping">
									<Tooltip
										text={ `Select \u201c${ part.slug }\u201d block in editor` }
									>
										<Button
											size="small"
											variant="link"
											onClick={ () =>
												handleFocusPart(
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
										\u2192
									</span>

									<Tooltip
										text={ `Select \u201c${ part.area }\u201d area in editor` }
									>
										<Button
											size="small"
											variant="link"
											onClick={ () =>
												handleFocusArea( part.area )
											}
											className="flavor-agent-action-link flavor-agent-action-link--area"
										>
											{ part.area }
										</Button>
									</Tooltip>
								</span>

								{ isDone ? (
									<span className="flavor-agent-done-badge">
										\u2713 Assigned
									</span>
								) : (
									<Tooltip
										text={ `Assign \u201c${ part.slug }\u201d to the \u201c${ part.area }\u201d area` }
									>
										<Button
											size="small"
											variant="tertiary"
											onClick={ () =>
												handleAssign(
													part.slug,
													part.area
												)
											}
											className="flavor-agent-assign-btn"
										>
											Assign
										</Button>
									</Tooltip>
								) }

								{ part.reason && (
									<div className="flavor-agent-tpl-row__reason">
										<LinkedText
											text={ part.reason }
											entities={ entityMap }
										/>
									</div>
								) }
							</div>
						);
					} ) }
				</div>
			) }

			{ /* ---- Patterns ---- */ }
			{ hasPatterns && (
				<div className="flavor-agent-template-list">
					<div className="flavor-agent-section-label">
						Suggested Patterns
					</div>
					{ suggestion.patternSuggestions.map( ( name ) => {
						const isDone = !! insertedPatterns[ name ];
						const title = patternTitleMap[ name ] || name;

						return (
							<div
								key={ name }
								className={ `flavor-agent-tpl-row${
									isDone ? ' is-done' : ''
								}` }
							>
								{ /* Name link → opens Inserter filtered to this pattern */ }
								<Tooltip
									text={
										isDone
											? `\u201c${ title }\u201d inserted`
											: `Browse \u201c${ title }\u201d in pattern inserter`
									}
								>
									<Button
										size="small"
										variant="link"
										onClick={ () =>
											handleBrowsePattern( name )
										}
										className="flavor-agent-action-link flavor-agent-action-link--pattern"
									>
										{ patternTitleMap[ name ] || (
											<code>{ name }</code>
										) }
									</Button>
								</Tooltip>

								{ isDone ? (
									<span className="flavor-agent-done-badge">
										\u2713 Inserted
									</span>
								) : (
									<Tooltip
										text={ `Insert \u201c${ title }\u201d directly into template` }
									>
										<Button
											size="small"
											variant="tertiary"
											onClick={ () =>
												handleInsert( name )
											}
											className="flavor-agent-assign-btn"
										>
											Insert
										</Button>
									</Tooltip>
								) }
							</div>
						);
					} ) }
				</div>
			) }
		</div>
	);
}
