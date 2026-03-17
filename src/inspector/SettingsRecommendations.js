/**
 * Settings Recommendations
 *
 * Renders AI-suggested configuration changes in the Settings tab.
 */
import { PanelBody, Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { Icon, check, arrowRight } from '@wordpress/icons';

import { STORE_NAME } from '../store';

export default function SettingsRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );

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
						<div
							style={ {
								fontSize: '11px',
								fontWeight: 600,
								textTransform: 'uppercase',
								letterSpacing: '0.5px',
								color: 'var(--wp-components-color-foreground-secondary, #757575)',
								marginBottom: '6px',
							} }
						>
							{ panelLabel( panel ) }
						</div>
					) }

					{ items.map( ( suggestion, i ) => (
						<SuggestionCard
							key={ `${ panel }-${ suggestion.label }` }
							suggestion={ suggestion }
							onApply={ () =>
								applySuggestion( clientId, suggestion )
							}
						/>
					) ) }
				</div>
			) ) }
		</PanelBody>
	);
}

function SuggestionCard( { suggestion, onApply } ) {
	const { label, description, confidence, currentValue, suggestedValue } =
		suggestion;

	return (
		<div
			style={ {
				padding: '8px 10px',
				marginBottom: '6px',
				background: 'var(--wp-components-color-background, #f0f0f0)',
				borderRadius: '4px',
				border: '1px solid var(--wp-components-color-accent-inverted, #e0e0e0)',
			} }
		>
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					marginBottom: description ? '4px' : 0,
				} }
			>
				<span style={ { fontWeight: 500, fontSize: '13px' } }>
					{ label }
				</span>
				<Button
					variant="primary"
					size="small"
					onClick={ onApply }
					icon={ check }
					label="Apply"
					style={ {
						minWidth: 'auto',
						padding: '0 8px',
						height: '24px',
					} }
				>
					Apply
				</Button>
			</div>

			{ description && (
				<p
					style={ {
						margin: '0 0 4px',
						fontSize: '12px',
						color: 'var(--wp-components-color-foreground-secondary, #757575)',
						lineHeight: '1.4',
					} }
				>
					{ description }
				</p>
			) }

			{ currentValue !== undefined && suggestedValue !== undefined && (
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: '6px',
						fontSize: '11px',
						fontFamily: 'monospace',
					} }
				>
					<span
						style={ {
							opacity: 0.6,
							textDecoration: 'line-through',
						} }
					>
						{ formatValue( currentValue ) }
					</span>
					<Icon icon={ arrowRight } size={ 12 } />
					<span style={ { fontWeight: 600 } }>
						{ formatValue( suggestedValue ) }
					</span>
				</div>
			) }

			{ confidence != null && (
				<div
					style={ {
						marginTop: '4px',
						height: '3px',
						borderRadius: '2px',
						background:
							'var(--wp-components-color-accent-inverted, #e0e0e0)',
						overflow: 'hidden',
					} }
				>
					<div
						style={ {
							width: `${ Math.round( confidence * 100 ) }%`,
							height: '100%',
							background:
								'var(--wp-components-color-accent, #3858e9)',
							borderRadius: '2px',
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

function formatValue( val ) {
	if ( val === true ) {
		return 'true';
	}
	if ( val === false ) {
		return 'false';
	}
	if ( val == null ) {
		return 'none';
	}
	if ( typeof val === 'object' ) {
		return JSON.stringify( val );
	}
	return String( val );
}
