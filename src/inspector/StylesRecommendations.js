/**
 * Styles Recommendations
 *
 * Renders AI-suggested style changes in the Appearance tab.
 */
import { PanelBody, Button, ButtonGroup } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { arrowRight, check, styles as stylesIcon } from '@wordpress/icons';

import AIStatusNotice from '../components/AIStatusNotice';
import { STORE_NAME } from '../store';
import { formatCount } from '../utils/format-count';
import { getLiveBlockContextSignature } from '../context/collector';
import InlineActionFeedback from '../components/InlineActionFeedback';
import RecommendationLane from '../components/RecommendationLane';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import groupByPanel from './group-by-panel';
import {
	DELEGATED_STYLE_PANELS,
	STYLE_PANEL_DELEGATIONS,
} from './panel-delegation';
import { getSuggestionKey, getSuggestionPanel } from './suggestion-keys';
import useSuggestionApplyFeedback from './use-suggestion-apply-feedback';

function buildStyleFeedback(suggestion, key) {
	return {
		key,
		panel: getSuggestionPanel(suggestion),
		label: suggestion?.label || 'Suggestion',
		type: suggestion?.type === 'style_variation' ? 'variation' : 'attribute',
	};
}

export default function StylesRecommendations({ clientId, suggestions }) {
	const { applySuggestion, clearBlockError } = useDispatch(STORE_NAME);
	const liveContextSignature = useSelect(
		(select) => getLiveBlockContextSignature(select, clientId),
		[clientId]
	);
	const applyNotice = useSelect(
		(select) => {
			const store = select(STORE_NAME);
			const applyError = store.getBlockApplyError?.(clientId) || null;

			return store.getSurfaceStatusNotice('block', {
				applyError,
				onApplyDismissAction: true,
			});
		},
		[clientId]
	);
	const { appliedKey, feedback, handleApply } = useSuggestionApplyFeedback({
		applySuggestion: (targetClientId, suggestion) =>
			applySuggestion(targetClientId, suggestion, liveContextSignature),
		buildFeedback: buildStyleFeedback,
		clientId,
		getKey: getSuggestionKey,
		suggestions,
	});

	if (!suggestions.length) {
		return null;
	}

	const variationSuggestions = suggestions.filter(
		(s) => s.type === 'style_variation'
	);
	const attributeSuggestions = suggestions.filter(
		(s) => s.type !== 'style_variation'
	);
	const delegatedSuggestions = attributeSuggestions.filter((s) =>
		DELEGATED_STYLE_PANELS.has(s.panel)
	);
	const delegatedPanelTitles = STYLE_PANEL_DELEGATIONS.filter((config) =>
		delegatedSuggestions.some((suggestion) => suggestion.panel === config.panel)
	).map((config) => config.title);
	const byPanel = groupByPanel(attributeSuggestions, DELEGATED_STYLE_PANELS);

	return (
		<PanelBody title="AI Style Suggestions" initialOpen icon={stylesIcon}>
			<div className="flavor-agent-panel">
				<SurfacePanelIntro
					eyebrow="Block Styles"
					introCopy="One-click apply stays available for safe block-level style changes. Suggestions stay grouped beside the native controls they map to."
					className="flavor-agent-style-surface__intro"
				>
					<div className="flavor-agent-style-surface__meta">
						<span className="flavor-agent-pill">
							{formatCount(suggestions.length, 'suggestion')}
						</span>
						{variationSuggestions.length > 0 && (
							<span className="flavor-agent-pill">
								{formatCount(variationSuggestions.length, 'variation')}
							</span>
						)}
						{delegatedSuggestions.length > 0 && (
							<span className="flavor-agent-pill">
								{formatCount(
									delegatedSuggestions.length,
									'native sub-panel item'
								)}
							</span>
						)}
					</div>
				</SurfacePanelIntro>

				<AIStatusNotice
					notice={applyNotice}
					onDismiss={() => clearBlockError(clientId)}
				/>

				{variationSuggestions.length > 0 && (
					<RecommendationLane
						title="Style Variations"
						tone="Apply now"
						count={variationSuggestions.length}
						countNoun="variation"
						description="Apply a registered style variation directly from the Styles tab when the current block exposes one."
					>
						<ButtonGroup className="flavor-agent-style-variations">
							{variationSuggestions.map((s) => {
								const key = getSuggestionKey(s);
								const applied = appliedKey === key;
								const isCurrentStyle = Boolean(s.isCurrentStyle);
								const isDisabled = isCurrentStyle || applied;

								return (
									<Button
										key={key}
										variant={
											isCurrentStyle || applied ? 'primary' : 'secondary'
										}
										size="compact"
										onClick={() => void handleApply(s, key)}
										title={
											isCurrentStyle ? 'Current style variation' : s.description
										}
										icon={isCurrentStyle || applied ? check : undefined}
										disabled={isDisabled}
										className="flavor-agent-style-variation"
									>
										{s.label}
										{s.isRecommended && (
											<span className="flavor-agent-style-variation__star">
												★
											</span>
										)}
									</Button>
								);
							})}
						</ButtonGroup>

						{feedback?.type === 'variation' && (
							<InlineActionFeedback message={`${feedback.label}.`} />
						)}
					</RecommendationLane>
				)}

				{Object.entries(byPanel).map(([panel, items]) => (
					<RecommendationLane
						key={panel}
						title={panelLabel(panel)}
						tone="Apply now"
						count={items.length}
						countNoun="suggestion"
						description="Flavor Agent keeps these style updates beside the native controls they map to."
					>
						{items.map((s) => {
							const key = getSuggestionKey(s);
							return (
								<StyleSuggestionRow
									key={key}
									suggestion={s}
									onApply={() => void handleApply(s, key)}
									applied={appliedKey === key}
								/>
							);
						})}
					</RecommendationLane>
				))}

				{delegatedSuggestions.length > 0 && (
					<RecommendationLane
						title="Native Style Panels"
						tone="Apply now"
						count={delegatedPanelTitles.length}
						countNoun="panel"
						description={`More style suggestions appear directly inside the native ${delegatedPanelTitles.join(
							', '
						)} panels above so the action stays next to the matching control.`}
					>
						<div className="flavor-agent-style-surface__meta">
							{delegatedPanelTitles.map((title) => (
								<span key={title} className="flavor-agent-pill">
									{title}
								</span>
							))}
						</div>
					</RecommendationLane>
				)}
			</div>
		</PanelBody>
	);
}

function StyleSuggestionRow({ suggestion, onApply, applied }) {
	const { label, description, preview, cssVar, confidence } = suggestion;
	const previewLabel = preview && !isColor(preview) ? preview : '';
	const confidenceLabel =
		confidence !== null && confidence !== undefined
			? `${Math.max(
					0,
					Math.min(100, Math.round(confidence * 100))
			  )}% confidence`
			: '';

	return (
		<div
			className={`flavor-agent-card flavor-agent-style-card${
				applied ? ' flavor-agent-style-card--active' : ''
			}`}
		>
			<div className="flavor-agent-style-row">
				{preview && isColor(preview) && (
					<span
						className="flavor-agent-style-row__preview"
						style={{
							'--flavor-agent-style-preview': preview,
						}}
					/>
				)}

				<div className="flavor-agent-style-row__info">
					<div className="flavor-agent-style-row__header">
						<div className="flavor-agent-style-row__label">{label}</div>
						<div className="flavor-agent-style-card__badges">
							<span className="flavor-agent-pill">Apply now</span>
							{confidenceLabel && (
								<span className="flavor-agent-pill">{confidenceLabel}</span>
							)}
							{previewLabel && (
								<code className="flavor-agent-pill flavor-agent-pill--code">
									{previewLabel}
								</code>
							)}
							{cssVar && (
								<code className="flavor-agent-pill flavor-agent-pill--code">
									{cssVar}
								</code>
							)}
						</div>
					</div>
					{description && (
						<p className="flavor-agent-style-row__description">{description}</p>
					)}
				</div>

				<Button
					variant="tertiary"
					size="small"
					onClick={onApply}
					icon={applied ? check : arrowRight}
					label={applied ? 'Applied' : 'Apply'}
					className={`flavor-agent-card__apply${
						applied ? ' flavor-agent-card__apply--applied' : ''
					} flavor-agent-style-row__apply`}
					disabled={applied}
				/>
			</div>

			{applied && <InlineActionFeedback message={`${label}.`} />}
		</div>
	);
}

function isColor(str) {
	return /^(#|rgb|hsl|oklch|lab|lch|var\()/.test(str);
}

function panelLabel(panel) {
	const labels = {
		general: 'General',
		layout: 'Layout',
		position: 'Position',
		advanced: 'Advanced',
		effects: 'Effects',
		shadow: 'Shadow',
	};

	return labels[panel] || panel;
}
