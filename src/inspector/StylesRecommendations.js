/**
 * Styles Recommendations
 *
 * Renders AI-suggested style changes in the Appearance tab.
 */
import { PanelBody, Button, ButtonGroup } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { check, styles as stylesIcon } from '@wordpress/icons';

import { STORE_NAME } from '../store';

const FEEDBACK_MS = 1200;

export default function StylesRecommendations( { clientId, suggestions } ) {
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
		( suggestion, key ) => {
			applySuggestion( clientId, suggestion );

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

	const variationSuggestions = suggestions.filter(
		( s ) => s.type === 'style_variation'
	);
	const attributeSuggestions = suggestions.filter(
		( s ) => s.type !== 'style_variation'
	);

	const byPanel = {};
	for ( const s of attributeSuggestions ) {
		if (
			[ 'color', 'typography', 'dimensions', 'border' ].includes(
				s.panel
			)
		) {
			continue;
		}
		const key = s.panel || 'general';
		if ( ! byPanel[ key ] ) {
			byPanel[ key ] = [];
		}
		byPanel[ key ].push( s );
	}

	return (
		<PanelBody title="AI Style Suggestions" initialOpen icon={ stylesIcon }>
			{ variationSuggestions.length > 0 && (
				<div style={ { marginBottom: '12px' } }>
					<div className="flavor-agent-section-label">
						Block Style
					</div>

					<ButtonGroup className="flavor-agent-style-variations">
						{ variationSuggestions.map( ( s ) => {
							const key = `variation-${ s.label }`;
							const applied = appliedKey === key;

							return (
								<Button
									key={ key }
									variant={
										s.isCurrentStyle || applied
											? 'primary'
											: 'secondary'
									}
									size="compact"
									onClick={ () => handleApply( s, key ) }
									title={ s.description }
									icon={ applied ? check : undefined }
									disabled={ applied }
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
				</div>
			) }

			{ Object.entries( byPanel ).map( ( [ panel, items ] ) => (
				<div key={ panel } style={ { marginBottom: '10px' } }>
					<div className="flavor-agent-section-label">{ panel }</div>

					{ items.map( ( s ) => {
						const key = `${ panel }-${ s.label }`;
						return (
							<StyleSuggestionRow
								key={ key }
								suggestion={ s }
								onApply={ () => handleApply( s, key ) }
								applied={ appliedKey === key }
							/>
						);
					} ) }
				</div>
			) ) }

			{ attributeSuggestions.some( ( s ) =>
				[ 'color', 'typography', 'dimensions', 'border' ].includes(
					s.panel
				)
			) && (
				<p className="flavor-agent-subpanel-hint">
					More suggestions appear in the Color, Typography,
					Dimensions, and Border panels above.
				</p>
			) }
		</PanelBody>
	);
}

function StyleSuggestionRow( { suggestion, onApply, applied } ) {
	const { label, description, preview, cssVar } = suggestion;

	return (
		<div className="flavor-agent-card">
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: '8px',
				} }
			>
				{ preview && isColor( preview ) && (
					<span
						className="flavor-agent-chip__preview"
						style={ {
							width: '20px',
							height: '20px',
							borderRadius: '4px',
							backgroundColor: preview,
							flexShrink: 0,
						} }
					/>
				) }

				<div className="flavor-agent-style-row__info">
					<div className="flavor-agent-style-row__label">
						{ label }
					</div>
					{ description && (
						<p className="flavor-agent-style-row__description">
							{ description }
						</p>
					) }
					{ cssVar && (
						<code
							style={ {
								fontSize: '10px',
								opacity: 0.5,
								display: 'block',
								marginTop: '2px',
							} }
						>
							{ cssVar }
						</code>
					) }
				</div>

				<Button
					variant="tertiary"
					size="small"
					onClick={ onApply }
					icon={ check }
					label={ applied ? 'Applied' : 'Apply' }
					className={ `flavor-agent-card__apply${
						applied ? ' flavor-agent-card__apply--applied' : ''
					}` }
					disabled={ applied }
					style={ { flexShrink: 0 } }
				/>
			</div>
		</div>
	);
}

function isColor( str ) {
	return /^(#|rgb|hsl|var\()/.test( str );
}
