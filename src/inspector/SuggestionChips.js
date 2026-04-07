/**
 * Suggestion chips for Inspector sub-panels.
 * Renders compact apply buttons inside ToolsPanel grids.
 */
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { check } from '@wordpress/icons';

import InlineActionFeedback from '../components/InlineActionFeedback';
import { STORE_NAME } from '../store';
import { getLiveBlockContextSignature } from '../context/collector';
import { getSuggestionKey } from './suggestion-keys';
import useSuggestionApplyFeedback from './use-suggestion-apply-feedback';

function buildChipFeedback(suggestion, key) {
	return {
		key,
		label: suggestion?.label || 'Suggestion',
	};
}

export default function SuggestionChips({
	clientId,
	suggestions,
	label,
	disabled = false,
	title = '',
	tone = '',
}) {
	const { applySuggestion } = useDispatch(STORE_NAME);
	const liveContextSignature = useSelect(
		(select) => getLiveBlockContextSignature(select, clientId),
		[clientId]
	);
	const { appliedKey, feedback, handleApply } = useSuggestionApplyFeedback({
		applySuggestion: (targetClientId, suggestion) =>
			applySuggestion(targetClientId, suggestion, liveContextSignature),
		buildFeedback: buildChipFeedback,
		clientId,
		getKey: getSuggestionKey,
		suggestions,
	});

	return (
		<div className="flavor-agent-chip-surface">
			{(title || tone) && (
				<div className="flavor-agent-chip-surface__header">
					{title && <div className="flavor-agent-section-label">{title}</div>}
					{tone && <span className="flavor-agent-pill">{tone}</span>}
				</div>
			)}

			<div className="flavor-agent-chips" role="group" aria-label={label}>
				{suggestions.map((s) => {
					const key = getSuggestionKey(s);
					const wasApplied = appliedKey === key;

					return (
						<Button
							key={key}
							variant={wasApplied ? 'primary' : 'secondary'}
							size="small"
							onClick={() => void handleApply(s)}
							disabled={wasApplied || disabled}
							title={s.description || s.label}
							icon={wasApplied ? check : undefined}
							className={`flavor-agent-chip${wasApplied ? ' is-applied' : ''}`}
							style={
								s.preview
									? {
											'--flavor-agent-chip-preview': s.preview,
									  }
									: undefined
							}
						>
							<span className="flavor-agent-chip__label">{s.label}</span>
							{!wasApplied && !disabled && s.preview && (
								<span
									className="flavor-agent-chip__preview"
									aria-hidden="true"
								/>
							)}
						</Button>
					);
				})}
			</div>

			{feedback && (
				<InlineActionFeedback
					compact
					message={feedback.label}
					className="flavor-agent-chip-surface__feedback"
				/>
			)}
		</div>
	);
}
