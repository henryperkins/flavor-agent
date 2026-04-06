/**
 * Surface Composer
 *
 * Shared prompt textarea + fetch button used across all recommendation
 * surfaces that accept user input.
 */
import { Button, TextareaControl } from '@wordpress/components';

import { joinClassNames } from '../utils/format-count';

export default function SurfaceComposer( {
	prompt = '',
	onPromptChange,
	onFetch,
	placeholder = 'Describe what you want to achieve.',
	label = 'What are you trying to achieve?',
	hideLabelFromVision = true,
	rows = 3,
	help = '',
	fetchLabel = 'Get Suggestions',
	loadingLabel = 'Getting suggestions\u2026',
	fetchVariant = 'primary',
	fetchIcon = null,
	isLoading = false,
	disabled = false,
	className = '',
} ) {
	return (
		<div
			className={ joinClassNames(
				'flavor-agent-panel__composer',
				className
			) }
		>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ label }
				hideLabelFromVision={ hideLabelFromVision }
				placeholder={ placeholder }
				value={ prompt }
				onChange={ onPromptChange }
				rows={ rows }
				help={ help }
				disabled={ disabled }
				className="flavor-agent-prompt"
			/>

			<Button
				variant={ fetchVariant }
				onClick={ onFetch }
				disabled={ isLoading || disabled }
				icon={ fetchIcon }
				className="flavor-agent-fetch-button"
			>
				{ isLoading ? loadingLabel : fetchLabel }
			</Button>
		</div>
	);
}
