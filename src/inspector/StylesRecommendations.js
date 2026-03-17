/**
 * Styles Recommendations
 *
 * Renders AI-suggested style changes in the Appearance tab.
 */
import { PanelBody, Button, ButtonGroup } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { arrowRight, check, styles as stylesIcon } from '@wordpress/icons';

import { STORE_NAME } from '../store';
import { getSuggestionKey, getSuggestionPanel } from './suggestion-keys';

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

	useEffect( () => {
		if ( resetTimerRef.current ) {
			window.clearTimeout( resetTimerRef.current );
			resetTimerRef.current = null;
		}

		setAppliedKey( null );
	}, [ suggestions ] );

	const handleApply = useCallback(
		async ( suggestion, key ) => {
			const didApply = await applySuggestion( clientId, suggestion );

			if ( ! didApply ) {
				return;
			}

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
		const key = getSuggestionPanel( s );
		if ( ! byPanel[ key ] ) {
			byPanel[ key ] = [];
		}
		byPanel[ key ].push( s );
	}

	return (
		<PanelBody title="AI Style Suggestions" initialOpen icon={ stylesIcon }>
			<div className="flavor-agent-panel">
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
										onClick={ () =>
											void handleApply( s, key )
										}
										title={ s.description }
										icon={ applied ? check : undefined }
										disabled={ applied }
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

				{ attributeSuggestions.some( ( s ) =>
					[ 'color', 'typography', 'dimensions', 'border' ].includes(
						s.panel
					)
				) && (
					<p className="flavor-agent-subpanel-hint flavor-agent-panel__note">
						More suggestions appear in the Color, Typography,
						Dimensions, and Border panels above.
					</p>
				) }
			</div>
		</PanelBody>
	);
}

function StyleSuggestionRow( { suggestion, onApply, applied } ) {
	const { label, description, preview, cssVar } = suggestion;

	return (
		<div className="flavor-agent-card">
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
						{ cssVar && (
							<code className="flavor-agent-pill flavor-agent-pill--code">
								{ cssVar }
							</code>
						) }
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
		</div>
	);
}

function isColor( str ) {
	return /^(#|rgb|hsl|var\()/.test( str );
}

function formatCount( count, noun ) {
	return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}

function panelLabel( panel ) {
	const labels = {
		general: 'General',
		layout: 'Layout',
		position: 'Position',
		advanced: 'Advanced',
		effects: 'Effects',
	};

	return labels[ panel ] || panel;
}
