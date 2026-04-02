/**
 * Settings Recommendations
 *
 * Renders AI-suggested configuration changes in the Settings tab.
 */
import { PanelBody, Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { Icon, check, arrowRight } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { formatCount } from '../utils/format-count';
import groupByPanel from './group-by-panel';
import { DELEGATED_SETTINGS_PANELS } from './panel-delegation';
import { getSuggestionKey } from './suggestion-keys';
import useSuggestionApplyFeedback from './use-suggestion-apply-feedback';

export default function SettingsRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );
	const { appliedKey, handleApply } = useSuggestionApplyFeedback( {
		applySuggestion,
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

	return (
		<PanelBody title="AI Settings" initialOpen>
			<div className="flavor-agent-panel">
				{ Object.entries( grouped ).map( ( [ panel, items ] ) => (
					<div key={ panel } className="flavor-agent-panel__group">
						{ Object.keys( grouped ).length > 1 && (
							<div className="flavor-agent-panel__group-header">
								<div className="flavor-agent-panel__group-title">
									{ panelLabel( panel ) }
								</div>
								<span className="flavor-agent-pill">
									{ formatCount(
										items.length,
										'suggestion'
									) }
								</span>
							</div>
						) }

						<div className="flavor-agent-panel__group-body">
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
									/>
								);
							} ) }
						</div>
					</div>
				) ) }
			</div>
		</PanelBody>
	);
}

function SuggestionCard( { suggestion, onApply, applied } ) {
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
					{ confidenceLabel && (
						<div className="flavor-agent-card__meta">
							<span className="flavor-agent-pill">
								{ confidenceLabel }
							</span>
						</div>
					) }
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
