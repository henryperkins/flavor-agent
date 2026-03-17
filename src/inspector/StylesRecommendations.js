/**
 * Styles Recommendations
 *
 * Renders AI-suggested style changes in the Appearance tab.
 */
import { PanelBody, Button, ButtonGroup } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { check, styles as stylesIcon } from '@wordpress/icons';

import { STORE_NAME } from '../store';

export default function StylesRecommendations( { clientId, suggestions } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );

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
					<div
						style={ {
							fontSize: '11px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							color: 'var(--wp-components-color-foreground-secondary)',
							marginBottom: '8px',
						} }
					>
						Block Style
					</div>

					<ButtonGroup
						style={ {
							display: 'flex',
							flexWrap: 'wrap',
							gap: '4px',
						} }
					>
						{ variationSuggestions.map( ( s ) => (
							<Button
								key={ `variation-${ s.label }` }
								variant={
									s.isCurrentStyle ? 'primary' : 'secondary'
								}
								size="compact"
								onClick={ () => applySuggestion( clientId, s ) }
								title={ s.description }
							>
								{ s.label }
								{ s.isRecommended && (
									<span
										style={ {
											marginLeft: '4px',
											fontSize: '10px',
											opacity: 0.7,
										} }
									>
										*
									</span>
								) }
							</Button>
						) ) }
					</ButtonGroup>
				</div>
			) }

			{ Object.entries( byPanel ).map( ( [ panel, items ] ) => (
				<div key={ panel } style={ { marginBottom: '10px' } }>
					<div
						style={ {
							fontSize: '11px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							color: 'var(--wp-components-color-foreground-secondary)',
							marginBottom: '6px',
						} }
					>
						{ panel }
					</div>

					{ items.map( ( s ) => (
						<StyleSuggestionRow
							key={ `${ panel }-${ s.label }` }
							suggestion={ s }
							onApply={ () => applySuggestion( clientId, s ) }
						/>
					) ) }
				</div>
			) ) }

			{ attributeSuggestions.some( ( s ) =>
				[ 'color', 'typography', 'dimensions', 'border' ].includes(
					s.panel
				)
			) && (
				<p
					style={ {
						fontSize: '11px',
						color: 'var(--wp-components-color-foreground-secondary)',
						marginTop: '8px',
						fontStyle: 'italic',
					} }
				>
					More suggestions appear in the Color, Typography,
					Dimensions, and Border panels above.
				</p>
			) }
		</PanelBody>
	);
}

function StyleSuggestionRow( { suggestion, onApply } ) {
	const { label, description, preview, cssVar } = suggestion;

	return (
		<div
			style={ {
				display: 'flex',
				alignItems: 'center',
				gap: '8px',
				padding: '6px 8px',
				marginBottom: '4px',
				background: 'var(--wp-components-color-background, #f0f0f0)',
				borderRadius: '4px',
				border: '1px solid var(--wp-components-color-accent-inverted, #e0e0e0)',
			} }
		>
			{ preview && isColor( preview ) && (
				<span
					style={ {
						flexShrink: 0,
						width: '20px',
						height: '20px',
						borderRadius: '4px',
						backgroundColor: preview,
						border: '1px solid rgba(0,0,0,0.15)',
					} }
				/>
			) }

			<div style={ { flex: 1, minWidth: 0 } }>
				<div
					style={ {
						fontSize: '12px',
						fontWeight: 500,
						lineHeight: '1.3',
					} }
				>
					{ label }
				</div>
				{ description && (
					<div
						style={ {
							fontSize: '11px',
							color: 'var(--wp-components-color-foreground-secondary)',
							lineHeight: '1.3',
							marginTop: '1px',
						} }
					>
						{ description }
					</div>
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
				label="Apply"
				style={ { flexShrink: 0 } }
			/>
		</div>
	);
}

function isColor( str ) {
	return /^(#|rgb|hsl|var\()/.test( str );
}
