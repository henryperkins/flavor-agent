/**
 * Settings Recommendations
 *
 * Renders AI-suggested configuration changes in the Settings tab.
 */
import { PanelBody, Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { Icon, check, arrowRight } from '@wordpress/icons';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';

import { STORE_NAME } from '../store';

const FEEDBACK_MS = 1200;

export default function SettingsRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );
	const [ appliedKey, setAppliedKey ] = useState( null );
	const resetTimerRef = useRef( null );

	useEffect( () => {
		return () => {
			if ( resetTimerRef.current ) {
				window.clearTimeout( resetTimerRef.current );
			}
		};
	}, [] );

	const handleApply = useCallback(
		( suggestion ) => {
			applySuggestion( clientId, suggestion );
			const key = `${ suggestion.panel }-${ suggestion.label }`;

			if ( resetTimerRef.current ) {
				window.clearTimeout( resetTimerRef.current );
			}

			setAppliedKey( key );

			resetTimerRef.current = window.setTimeout( () => {
				setAppliedKey( null );
				resetTimerRef.current = null;
			}, FEEDBACK_MS );
		},
		[ clientId, applySuggestion ]
	);

	if ( ! suggestions.length ) {
		return null;
	}

	const grouped = {};
	for ( const s of suggestions ) {
		const key = s.panel || 'general';
		if ( ! grouped[ key ] ) {
			grouped[ key ] = [];
		}
		grouped[ key ].push( s );
	}

	return (
		<PanelBody title="AI Settings" initialOpen>
			{ Object.entries( grouped ).map( ( [ panel, items ] ) => (
				<div key={ panel } style={ { marginBottom: '12px' } }>
					{ Object.keys( grouped ).length > 1 && (
						<div className="flavor-agent-section-label">
							{ panelLabel( panel ) }
						</div>
					) }

					{ items.map( ( suggestion ) => {
						const key = `${ panel }-${ suggestion.label }`;
						return (
							<SuggestionCard
								key={ key }
								suggestion={ suggestion }
								onApply={ () => handleApply( suggestion ) }
								applied={ appliedKey === key }
							/>
						);
					} ) }
				</div>
			) ) }
		</PanelBody>
	);
}

function SuggestionCard( { suggestion, onApply, applied } ) {
	const { label, description, confidence, currentValue, suggestedValue } =
		suggestion;

	return (
		<div className="flavor-agent-card">
			<div
				className={ `flavor-agent-card__header${
					description ? ' flavor-agent-card__header--spaced' : ''
				}` }
			>
				<span className="flavor-agent-card__label">{ label }</span>
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
				<div className="flavor-agent-card__values">
					<code>{ formatValue( currentValue ) }</code>
					<Icon icon={ arrowRight } size={ 12 } />
					<code>{ formatValue( suggestedValue ) }</code>
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
	};
	return labels[ panel ] || panel;
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
