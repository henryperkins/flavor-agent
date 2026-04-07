/**
 * Settings Recommendations
 *
 * Renders AI-suggested configuration changes in the Settings tab.
 */
import { PanelBody, Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { Icon, check, arrowRight } from '@wordpress/icons';

import InlineActionFeedback from '../components/InlineActionFeedback';
import RecommendationLane from '../components/RecommendationLane';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import { STORE_NAME } from '../store';
import { formatCount } from '../utils/format-count';
import groupByPanel from './group-by-panel';
import { DELEGATED_SETTINGS_PANELS } from './panel-delegation';
import { getSuggestionKey } from './suggestion-keys';
import useSuggestionApplyFeedback from './use-suggestion-apply-feedback';

function buildSettingsFeedback( suggestion, key ) {
	return {
		key,
		label: suggestion?.label || 'Suggestion',
	};
}

export default function SettingsRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );
	const { appliedKey, feedback, handleApply } = useSuggestionApplyFeedback( {
		applySuggestion,
		buildFeedback: buildSettingsFeedback,
		clientId,
		getKey: getSuggestionKey,
		suggestions,
	} );

	if ( ! suggestions.length ) {
		return null;
	}

	const grouped = groupByPanel( suggestions, DELEGATED_SETTINGS_PANELS );

	if ( ! Object.keys( grouped ).length ) {
		return null;
	}

	const groupedEntries = Object.entries( grouped );
	const visibleSuggestionCount = groupedEntries.reduce(
		( total, [ , items ] ) => total + items.length,
		0
	);

	return (
		<PanelBody title="AI Settings" initialOpen>
			<div className="flavor-agent-panel">
				<SurfacePanelIntro
					eyebrow="Block Settings"
					introCopy="Settings suggestions stay grouped with the native controls they change so local apply actions remain easy to verify."
					meta={
						<>
							<span className="flavor-agent-pill">
								{ formatCount( visibleSuggestionCount, 'suggestion' ) }
							</span>
							{ groupedEntries.length > 1 && (
								<span className="flavor-agent-pill">
									{ formatCount( groupedEntries.length, 'panel' ) }
								</span>
							) }
						</>
					}
				/>

				{ groupedEntries.map( ( [ panel, items ] ) => (
					<RecommendationLane
						key={ panel }
						title={ panelLabel( panel ) }
						tone="Apply now"
						count={ items.length }
						countNoun="suggestion"
						description="These suggestions map directly to the native settings in this panel."
					>
						{ items.map( ( suggestion ) => {
							const key = getSuggestionKey( suggestion );
							return (
								<SuggestionCard
									key={ key }
									suggestion={ suggestion }
									onApply={ () =>
										void handleApply( suggestion )
									}
									applied={ appliedKey === key }
									feedback={
										feedback?.key === key ? feedback : null
									}
								/>
							);
						} ) }
					</RecommendationLane>
				) ) }
			</div>
		</PanelBody>
	);
}

function SuggestionCard( { suggestion, onApply, applied, feedback } ) {
	const { label, description, confidence, currentValue, suggestedValue } =
		suggestion;
	const confidenceLabel =
		confidence !== null && confidence !== undefined
			? formatConfidenceLabel( confidence )
			: null;

	return (
		<div className="flavor-agent-card">
			<div
				className={ `flavor-agent-card__header${
					description || confidenceLabel
						? ' flavor-agent-card__header--spaced'
						: ''
				}` }
			>
				<div className="flavor-agent-card__lead">
					<span className="flavor-agent-card__label">{ label }</span>
					<div className="flavor-agent-card__meta">
						<span className="flavor-agent-pill">Apply now</span>
						{ confidenceLabel && (
							<span className="flavor-agent-pill">
								{ confidenceLabel }
							</span>
						) }
					</div>
				</div>
				<Button
					size="small"
					variant="tertiary"
					onClick={ onApply }
					icon={ applied ? check : arrowRight }
					label={ applied ? 'Applied' : 'Apply suggestion' }
					className={ `flavor-agent-card__apply${
						applied ? ' flavor-agent-card__apply--applied' : ''
					}` }
					disabled={ applied }
				/>
			</div>

			{ description && (
				<p className="flavor-agent-card__description">
					{ description }
				</p>
			) }

			{ currentValue !== undefined && suggestedValue !== undefined && (
				<div className="flavor-agent-card__value-grid">
					<div className="flavor-agent-card__value">
						<span className="flavor-agent-card__value-label">
							Current
						</span>
						<code>{ formatValue( currentValue ) }</code>
					</div>
					<Icon
						icon={ arrowRight }
						size={ 14 }
						className="flavor-agent-card__value-arrow"
					/>
					<div className="flavor-agent-card__value">
						<span className="flavor-agent-card__value-label">
							Suggested
						</span>
						<code>{ formatValue( suggestedValue ) }</code>
					</div>
				</div>
			) }

			{ confidence !== null && confidence !== undefined && (
				<div
					className="flavor-agent-card__confidence"
					aria-hidden="true"
				>
					<div
						className="flavor-agent-card__confidence-bar"
						style={ {
							width: `${ clampConfidence( confidence ) }%`,
						} }
					/>
				</div>
			) }

			{ feedback && (
				<InlineActionFeedback compact message={ `${ label }.` } />
			) }
		</div>
	);
}

function panelLabel( panel ) {
	const labels = {
		general: 'General',
		layout: 'Layout',
		position: 'Position',
		advanced: 'Advanced',
		alignment: 'Alignment',
		bindings: 'Bindings',
		list: 'List View',
		'list-view': 'List View',
	};
	return labels[ panel ] || panel;
}

function formatConfidenceLabel( confidence ) {
	return `${ clampConfidence( confidence ) }% confidence`;
}

function formatValue( value ) {
	if ( value === true ) {
		return 'true';
	}

	if ( value === false ) {
		return 'false';
	}

	if ( value === null || value === undefined ) {
		return 'none';
	}

	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}

	return String( value );
}

function clampConfidence( confidence ) {
	return Math.max( 0, Math.min( 100, Math.round( confidence * 100 ) ) );
}
