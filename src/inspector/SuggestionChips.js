/**
 * Suggestion chips for Inspector sub-panels.
 * Renders compact apply buttons inside ToolsPanel grids.
 */
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';

import { STORE_NAME } from '../store';

export default function SuggestionChips( { clientId, suggestions, label } ) {
	const { applySuggestion } = useDispatch( STORE_NAME );

	return (
		<div
			style={ {
				gridColumn: '1 / -1',
				display: 'flex',
				flexWrap: 'wrap',
				gap: '4px',
				padding: '4px 0',
			} }
			aria-label={ label }
		>
			{ suggestions.map( ( s ) => (
				<Button
					key={ `${ s.panel }-${ s.label }` }
					variant="secondary"
					size="small"
					onClick={ () => applySuggestion( clientId, s ) }
					title={ s.description || s.label }
					style={ {
						fontSize: '11px',
						padding: '2px 8px',
						height: 'auto',
						lineHeight: '1.6',
					} }
				>
					{ s.label }
					{ s.preview && (
						<span
							style={ {
								display: 'inline-block',
								width: '12px',
								height: '12px',
								borderRadius: '2px',
								backgroundColor: s.preview,
								marginLeft: '4px',
								verticalAlign: 'middle',
								border: '1px solid rgba(0,0,0,0.1)',
							} }
						/>
					) }
				</Button>
			) ) }
		</div>
	);
}
