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
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import { STORE_NAME } from '../store';
import { normalizeTemplateType } from '../utils/template-types';
import {
	openInserterForPattern,
	selectBlockByArea,
	selectBlockBySlugOrArea,
} from '../utils/template-actions';
import {
	buildEntityMap,
	buildTemplateFetchInput,
	buildTemplateSuggestionViewModel,
	ENTITY_ACTION_BROWSE_PATTERN,
	ENTITY_ACTION_SELECT_AREA,
	ENTITY_ACTION_SELECT_PART,
	formatCount,
	formatTemplateTypeLabel,
	getSuggestionCardKey,
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
	const {
		recommendations,
		explanation,
		error,
		resultRef,
		resultToken,
		isLoading,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );

		return {
			recommendations: store.getTemplateRecommendations(),
			explanation: store.getTemplateExplanation(),
			error: store.getTemplateError(),
			resultRef: store.getTemplateResultRef(),
			resultToken: store.getTemplateResultToken(),
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
	const suggestionCards = useMemo(
		() =>
			recommendations.map( ( suggestion ) =>
				buildTemplateSuggestionViewModel( suggestion, patternTitleMap )
			),
		[ recommendations, patternTitleMap ]
	);

	const handleFetch = useCallback( () => {
		fetchTemplateRecommendations(
			buildTemplateFetchInput( {
				templateRef,
				templateType,
				prompt,
			} )
		);
	}, [ fetchTemplateRecommendations, prompt, templateRef, templateType ] );

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
						Describe the structure or layout you want. Suggestions
						stay advisory so you can review template parts and
						browse candidate patterns in the editor before changing
						the template.
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

				{ hasMatchingResult && explanation && (
					<p className="flavor-agent-explanation flavor-agent-panel__note">
						<LinkedText
							text={ explanation }
							entities={ entityMap }
							onEntityClick={ handleEntityAction }
						/>
					</p>
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
								<TemplateSuggestionCard
									key={ `${ resultToken }-${ getSuggestionCardKey(
										suggestion,
										index
									) }` }
									suggestion={ suggestion }
									entityMap={ entityMap }
									onEntityClick={ handleEntityAction }
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
	onEntityClick,
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
		<div className="flavor-agent-card flavor-agent-card--template">
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
						</div>
					) }
				</div>
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
		</div>
	);
}
