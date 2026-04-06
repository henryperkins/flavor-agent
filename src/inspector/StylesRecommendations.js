/**
 * Styles Recommendations
 *
 * Renders AI-suggested style changes in the Appearance tab.
 */
import { PanelBody, Button, ButtonGroup } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { arrowRight, check, styles as stylesIcon } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { formatCount } from '../utils/format-count';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import groupByPanel from './group-by-panel';
import {
	DELEGATED_STYLE_PANELS,
	STYLE_PANEL_DELEGATIONS,
} from './panel-delegation';
import { getSuggestionKey, getSuggestionPanel } from './suggestion-keys';
import useSuggestionApplyFeedback from './use-suggestion-apply-feedback';

function buildStyleFeedback( suggestion, key ) {
	return {
		key,
		panel: getSuggestionPanel( suggestion ),
		label: suggestion?.label || 'Suggestion',
		type:
			suggestion?.type === 'style_variation' ? 'variation' : 'attribute',
	};
}

export default function StylesRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );
	const { appliedKey, feedback, handleApply } = useSuggestionApplyFeedback( {
		applySuggestion,
		buildFeedback: buildStyleFeedback,
		clientId,
		getKey: getSuggestionKey,
		suggestions,
	} );

	if ( ! suggestions.length ) {
		return null;
	}

	const variationSuggestions = suggestions.filter(
		( s ) => s.type === 'style_variation'
	);
	const attributeSuggestions = suggestions.filter(
		( s ) => s.type !== 'style_variation'
	);
	const delegatedSuggestions = attributeSuggestions.filter( ( s ) =>
		DELEGATED_STYLE_PANELS.has( s.panel )
	);
	const delegatedPanelTitles = STYLE_PANEL_DELEGATIONS.filter( ( config ) =>
		delegatedSuggestions.some(
			( suggestion ) => suggestion.panel === config.panel
		)
	).map( ( config ) => config.title );
	const byPanel = groupByPanel(
		attributeSuggestions,
		DELEGATED_STYLE_PANELS
	);

	return (
		<PanelBody title="AI Style Suggestions" initialOpen icon={ stylesIcon }>
			<div className="flavor-agent-panel">
				<SurfacePanelIntro
					eyebrow="Block Styles"
					introCopy="One-click apply stays available for safe block-level style changes. Suggestions stay grouped beside the native controls they map to."
					className="flavor-agent-style-surface__intro"
				>
					<div className="flavor-agent-style-surface__meta">
						<span className="flavor-agent-pill">
							{ formatCount( suggestions.length, 'suggestion' ) }
						</span>
						{ variationSuggestions.length > 0 && (
							<span className="flavor-agent-pill">
								{ formatCount(
									variationSuggestions.length,
									'variation'
								) }
							</span>
						) }
						{ delegatedSuggestions.length > 0 && (
							<span className="flavor-agent-pill">
								{ formatCount(
									delegatedSuggestions.length,
									'native sub-panel item'
								) }
							</span>
						) }
					</div>
				</SurfacePanelIntro>

				{ variationSuggestions.length > 0 && (
					<div className="flavor-agent-panel__group">
						<div className="flavor-agent-panel__group-header">
							<div className="flavor-agent-panel__group-title">
								Style Variations
							</div>
							<span className="flavor-agent-pill">
								{ formatCount(
									variationSuggestions.length,
									'variation'
								) }
							</span>
						</div>
						<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
							Apply a registered style variation directly from the
							Styles tab when the current block exposes one.
						</p>

						<ButtonGroup className="flavor-agent-style-variations">
							{ variationSuggestions.map( ( s ) => {
								const key = getSuggestionKey( s );
								const applied = appliedKey === key;
								const isCurrentStyle = Boolean(
									s.isCurrentStyle
								);
								const isDisabled = isCurrentStyle || applied;

								return (
									<Button
										key={ key }
										variant={
											isCurrentStyle || applied
												? 'primary'
												: 'secondary'
										}
										size="compact"
										onClick={ () =>
											void handleApply( s, key )
										}
										title={
											isCurrentStyle
												? 'Current style variation'
												: s.description
										}
										icon={
											isCurrentStyle || applied
												? check
												: undefined
										}
										disabled={ isDisabled }
										className="flavor-agent-style-variation"
									>
										{ s.label }
										{ s.isRecommended && (
											<span className="flavor-agent-style-variation__star">
												★
											</span>
										) }
									</Button>
								);
							} ) }
						</ButtonGroup>

						{ feedback?.type === 'variation' && (
							<InlineApplyFeedback
								message={ `${ feedback.label }.` }
							/>
						) }
					</div>
				) }

				{ Object.entries( byPanel ).map( ( [ panel, items ] ) => (
					<div key={ panel } className="flavor-agent-panel__group">
						<div className="flavor-agent-panel__group-header">
							<div className="flavor-agent-panel__group-title">
								{ panelLabel( panel ) }
							</div>
							<span className="flavor-agent-pill">
								{ formatCount( items.length, 'suggestion' ) }
							</span>
						</div>

						<div className="flavor-agent-panel__group-body">
							{ items.map( ( s ) => {
								const key = getSuggestionKey( s );
								return (
									<StyleSuggestionRow
										key={ key }
										suggestion={ s }
										onApply={ () =>
											void handleApply( s, key )
										}
										applied={ appliedKey === key }
									/>
								);
							} ) }
						</div>
					</div>
				) ) }

				{ delegatedSuggestions.length > 0 && (
					<div className="flavor-agent-panel__group">
						<div className="flavor-agent-panel__group-header">
							<div className="flavor-agent-panel__group-title">
								Native Style Panels
							</div>
							<span className="flavor-agent-pill">
								{ formatCount(
									delegatedPanelTitles.length,
									'panel'
								) }
							</span>
						</div>
						<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
							More style suggestions appear directly inside the
							native { delegatedPanelTitles.join( ', ' ) } panels
							above so the action stays next to the matching
							control.
						</p>
						<div className="flavor-agent-style-surface__meta">
							{ delegatedPanelTitles.map( ( title ) => (
								<span
									key={ title }
									className="flavor-agent-pill"
								>
									{ title }
								</span>
							) ) }
						</div>
					</div>
				) }
			</div>
		</PanelBody>
	);
}

function StyleSuggestionRow( { suggestion, onApply, applied } ) {
	const { label, description, preview, cssVar } = suggestion;
	const previewLabel = preview && ! isColor( preview ) ? preview : '';

	return (
		<div
			className={ `flavor-agent-card flavor-agent-style-card${
				applied ? ' flavor-agent-style-card--active' : ''
			}` }
		>
			<div className="flavor-agent-style-row">
				{ preview && isColor( preview ) && (
					<span
						className="flavor-agent-style-row__preview"
						style={ {
							'--flavor-agent-style-preview': preview,
						} }
					/>
				) }

				<div className="flavor-agent-style-row__info">
					<div className="flavor-agent-style-row__header">
						<div className="flavor-agent-style-row__label">
							{ label }
						</div>
						<div className="flavor-agent-style-card__badges">
							{ previewLabel && (
								<code className="flavor-agent-pill flavor-agent-pill--code">
									{ previewLabel }
								</code>
							) }
							{ cssVar && (
								<code className="flavor-agent-pill flavor-agent-pill--code">
									{ cssVar }
								</code>
							) }
						</div>
					</div>
					{ description && (
						<p className="flavor-agent-style-row__description">
							{ description }
						</p>
					) }
				</div>

				<Button
					variant="tertiary"
					size="small"
					onClick={ onApply }
					icon={ applied ? check : arrowRight }
					label={ applied ? 'Applied' : 'Apply' }
					className={ `flavor-agent-card__apply${
						applied ? ' flavor-agent-card__apply--applied' : ''
					} flavor-agent-style-row__apply` }
					disabled={ applied }
				/>
			</div>

			{ applied && <InlineApplyFeedback message={ `${ label }.` } /> }
		</div>
	);
}

function InlineApplyFeedback( { message } ) {
	return (
		<div
			className="flavor-agent-inline-feedback"
			role="status"
			aria-live="polite"
		>
			<span className="flavor-agent-pill flavor-agent-pill--success">
				Applied
			</span>
			<span className="flavor-agent-inline-feedback__message">
				{ message }
			</span>
		</div>
	);
}

function isColor( str ) {
	return /^(#|rgb|hsl|oklch|lab|lch|var\()/.test( str );
}

function panelLabel( panel ) {
	const labels = {
		general: 'General',
		layout: 'Layout',
		position: 'Position',
		advanced: 'Advanced',
		effects: 'Effects',
		shadow: 'Shadow',
	};

	return labels[ panel ] || panel;
}
