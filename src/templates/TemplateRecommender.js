/**
 * Template Recommender
 *
 * Renders AI template composition suggestions in the Site Editor's
 * document sidebar via PluginDocumentSettingPanel.
 */
import { Button, Notice, TextareaControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';

import { STORE_NAME } from '../store';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';

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

	return ! canRecommend || ! templateRef ? null : (
		<PluginDocumentSettingPanel
			name="flavor-agent-template-recommendations"
			title="AI Template Recommendations"
		>
			<TextareaControl
				label="What are you trying to achieve with this template?"
				value={ prompt }
				onChange={ setPrompt }
				rows={ 3 }
				placeholder="Describe the structure or layout you want."
			/>

			<Button
				variant="primary"
				onClick={ handleFetch }
				disabled={ isLoading }
				style={ { width: '100%', justifyContent: 'center' } }
			>
				{ isLoading ? 'Getting suggestions...' : 'Get Suggestions' }
			</Button>

			{ isLoading && (
				<Notice
					status="info"
					isDismissible={ false }
					style={ { marginTop: '8px' } }
				>
					Analyzing template structure...
				</Notice>
			) }

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					style={ { marginTop: '8px' } }
				>
					{ error }
				</Notice>
			) }

			{ hasMatchingResult && explanation && (
				<p
					style={ {
						marginTop: '8px',
						fontSize: '12px',
						color: 'var(--wp-components-color-foreground-secondary, #757575)',
					} }
				>
					{ explanation }
				</p>
			) }

			{ hasSuggestions &&
				recommendations.map( ( suggestion, index ) => (
					<SuggestionCard
						key={ `${ suggestion.label }-${ index }` }
						suggestion={ suggestion }
						patternTitleMap={ patternTitleMap }
					/>
				) ) }
		</PluginDocumentSettingPanel>
	);
}

function SuggestionCard( { suggestion, patternTitleMap = {} } ) {
	return (
		<div
			style={ {
				marginTop: '12px',
				padding: '10px',
				border: '1px solid var(--wp-components-color-accent, #3858e9)',
				borderRadius: '4px',
			} }
		>
			<div style={ { fontWeight: 600, marginBottom: '4px' } }>
				{ suggestion.label }
			</div>

			{ suggestion.description && (
				<p
					style={ {
						fontSize: '12px',
						margin: '0 0 8px',
						color: 'var(--wp-components-color-foreground-secondary, #757575)',
					} }
				>
					{ suggestion.description }
				</p>
			) }

			{ suggestion.templateParts?.length > 0 && (
				<div style={ { marginBottom: '8px' } }>
					<div
						style={ {
							fontSize: '11px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							marginBottom: '4px',
						} }
					>
						Template Parts
					</div>
					{ suggestion.templateParts.map( ( templatePart ) => (
						<div
							key={ `${ templatePart.slug }-${ templatePart.area }` }
							style={ { fontSize: '12px', marginLeft: '8px' } }
						>
							<code>{ templatePart.slug }</code> { '->' }{ ' ' }
							{ templatePart.area }
							{ templatePart.reason && (
								<span
									style={ {
										color: 'var(--wp-components-color-foreground-secondary, #757575)',
									} }
								>
									{ ' ' }
									- { templatePart.reason }
								</span>
							) }
						</div>
					) ) }
				</div>
			) }

			{ suggestion.patternSuggestions?.length > 0 && (
				<div>
					<div
						style={ {
							fontSize: '11px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							marginBottom: '4px',
						} }
					>
						Suggested Patterns
					</div>
					{ suggestion.patternSuggestions.map( ( name ) => (
						<div
							key={ name }
							style={ { fontSize: '12px', marginLeft: '8px' } }
						>
							{ patternTitleMap[ name ] || <code>{ name }</code> }
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}
