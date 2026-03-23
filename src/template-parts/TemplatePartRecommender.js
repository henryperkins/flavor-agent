import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, TextareaControl, Tooltip } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';

import { getBlockPatterns as getCompatBlockPatterns } from '../patterns/compat';
import {
	openInserterForPattern,
	selectBlockByPath,
} from '../utils/template-actions';
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
	const patternTitleMap = useSelect( () => {
		const patterns = getCompatBlockPatterns();

		return patterns.reduce( ( acc, pattern ) => {
			if ( pattern?.name ) {
				acc[ pattern.name ] = pattern.title || pattern.name;
			}

			return acc;
		}, {} );
	}, [] );
	const [ prompt, setPrompt ] = useState( '' );
	const [ recommendations, setRecommendations ] = useState( [] );
	const [ explanation, setExplanation ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ resultRef, setResultRef ] = useState( null );
	const requestTokenRef = useRef( 0 );
	const abortRef = useRef( null );
	const previousTemplatePartRef = useRef( templatePartRef );

	const slug = useMemo(
		() => normalizeTemplatePartSlug( templatePartRef ),
		[ templatePartRef ]
	);
	const area = useMemo(
		() => deriveTemplatePartArea( slug ),
		[ slug ]
	);

	useEffect( () => {
		return () => {
			if ( abortRef.current ) {
				abortRef.current.abort();
				abortRef.current = null;
			}
		};
	}, [] );

	useEffect( () => {
		if ( previousTemplatePartRef.current === templatePartRef ) {
			return;
		}

		if ( abortRef.current ) {
			abortRef.current.abort();
			abortRef.current = null;
		}

		setPrompt( '' );
		setRecommendations( [] );
		setExplanation( '' );
		setError( '' );
		setIsLoading( false );
		setResultRef( null );
		previousTemplatePartRef.current = templatePartRef;
	}, [ templatePartRef ] );

	const handleFetch = useCallback( async () => {
		if ( ! templatePartRef ) {
			return;
		}

		if ( abortRef.current ) {
			abortRef.current.abort();
		}

		const controller = new AbortController();
		const requestToken = requestTokenRef.current + 1;

		requestTokenRef.current = requestToken;
		abortRef.current = controller;
		setIsLoading( true );
		setError( '' );

		try {
			const data = {
				templatePartRef,
			};
			const trimmedPrompt = prompt.trim();

			if ( trimmedPrompt ) {
				data.prompt = trimmedPrompt;
			}

			const result = await apiFetch( {
				path: '/flavor-agent/v1/recommend-template-part',
				method: 'POST',
				data,
				signal: controller.signal,
			} );

			if ( requestTokenRef.current !== requestToken ) {
				return;
			}

			setRecommendations( result?.suggestions || [] );
			setExplanation( result?.explanation || '' );
			setResultRef( templatePartRef );
		} catch ( requestError ) {
			if ( requestError?.name === 'AbortError' ) {
				return;
			}

			if ( requestTokenRef.current !== requestToken ) {
				return;
			}

			setRecommendations( [] );
			setExplanation( '' );
			setResultRef( templatePartRef );
			setError(
				requestError?.message ||
					'Template-part recommendation request failed.'
			);
		} finally {
			if ( abortRef.current === controller ) {
				abortRef.current = null;
			}

			if ( requestTokenRef.current === requestToken ) {
				setIsLoading( false );
			}
		}
	}, [ prompt, templatePartRef ] );

	if ( ! canRecommend || ! templatePartRef ) {
		return null;
	}

	const hasMatchingResult = resultRef === templatePartRef;
	const hasSuggestions =
		hasMatchingResult && recommendations.length > 0;

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
						template part. Review the suggested focus blocks and
						patterns, then decide what to edit in the canvas.
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

				{ hasMatchingResult && explanation && (
					<p className="flavor-agent-explanation flavor-agent-panel__note">
						{ explanation }
					</p>
				) }

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
									recommendations.length,
									'suggestion'
								) }
							</span>
						</div>
						<div className="flavor-agent-panel__group-body">
							{ recommendations.map(
								( suggestion, index ) => (
									<TemplatePartSuggestionCard
										key={ `${ resultRef || 'template-part' }-${ index }` }
										patternTitleMap={ patternTitleMap }
										suggestion={ suggestion }
									/>
								)
							) }
						</div>
					</div>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

function TemplatePartSuggestionCard( {
	suggestion,
	patternTitleMap = {},
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
					{ suggestion.description }
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
					{ patternSuggestions.map( ( patternName ) => {
						const patternTitle =
							patternTitleMap[ patternName ] || patternName;

						return (
							<div
								key={ patternName }
								className="flavor-agent-tpl-row"
							>
								<Tooltip
									text={ `Browse “${ patternTitle }” in pattern inserter` }
								>
									<Button
										size="small"
										variant="link"
										onClick={ () =>
											openInserterForPattern(
												patternTitle
											)
										}
										className="flavor-agent-action-link flavor-agent-action-link--pattern"
									>
										{ patternTitle }
									</Button>
								</Tooltip>

								<Button
									size="small"
									variant="tertiary"
									onClick={ () =>
										openInserterForPattern(
											patternTitle
										)
									}
									className="flavor-agent-assign-btn"
								>
									Browse pattern
								</Button>
							</div>
						);
					} ) }
				</div>
			) }
		</div>
	);
}
