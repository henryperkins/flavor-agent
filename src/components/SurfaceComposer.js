/**
 * Surface Composer
 *
 * Shared prompt textarea + fetch button used across all recommendation
 * surfaces that accept user input.
 */
import { Button, TextareaControl } from '@wordpress/components';

import { joinClassNames } from '../utils/format-count';

function matchesSubmitShortcut( event, submitShortcut ) {
	if ( ! submitShortcut || submitShortcut === 'none' ) {
		return false;
	}

	if ( submitShortcut === 'enter' ) {
		return event.key === 'Enter' && ! event.shiftKey;
	}

	return (
		event.key === 'Enter' &&
		( event.metaKey || event.ctrlKey ) &&
		! event.shiftKey
	);
}

export default function SurfaceComposer( {
	prompt = '',
	onPromptChange,
	onFetch,
	placeholder = 'Describe what you want to achieve.',
	label = 'What are you trying to achieve?',
	hideLabelFromVision = false,
	rows = 3,
	help = '',
	title = '',
	eyebrow = '',
	helperText = '',
	starterPrompts = [],
	submitShortcut = 'mod+enter',
	submitHint = '',
	onStarterPromptClick = null,
	meta = null,
	fetchLabel = 'Get Suggestions',
	loadingLabel = 'Getting suggestions\u2026',
	fetchVariant = 'primary',
	fetchIcon = null,
	isLoading = false,
	disabled = false,
	className = '',
} ) {
	const resolvedHelperText = helperText || help;
	const hasHeader = eyebrow || title || meta;
	const canFetch = typeof onFetch === 'function';

	const handleKeyDown = ( event ) => {
		if (
			disabled ||
			isLoading ||
			! canFetch ||
			! matchesSubmitShortcut( event, submitShortcut )
		) {
			return;
		}

		event.preventDefault();
		onFetch();
	};

	const handleStarterPromptClick = ( starterPrompt ) => {
		if ( typeof onPromptChange === 'function' ) {
			onPromptChange( starterPrompt );
		}

		if ( typeof onStarterPromptClick === 'function' ) {
			onStarterPromptClick( starterPrompt );
		}
	};

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-panel__composer',
				className
			) }
		>
			{ hasHeader && (
				<div className="flavor-agent-panel__composer-header">
					<div className="flavor-agent-panel__composer-copy">
						{ eyebrow && (
							<p className="flavor-agent-panel__composer-eyebrow">
								{ eyebrow }
							</p>
						) }
						{ title && (
							<div className="flavor-agent-panel__composer-title">
								{ title }
							</div>
						) }
					</div>
					{ meta && (
						<div className="flavor-agent-panel__composer-meta">
							{ meta }
						</div>
					) }
				</div>
			) }

			<TextareaControl
				__nextHasNoMarginBottom
				label={ label }
				hideLabelFromVision={ hideLabelFromVision }
				placeholder={ placeholder }
				value={ prompt }
				onChange={ onPromptChange }
				onKeyDown={ handleKeyDown }
				rows={ rows }
				help={ resolvedHelperText }
				disabled={ disabled }
				className="flavor-agent-prompt"
			/>

			{ starterPrompts.length > 0 && (
				<div
					className="flavor-agent-panel__composer-starters"
					role="group"
					aria-label="Starter prompts"
				>
					{ starterPrompts.map( ( starterPrompt ) => (
						<Button
							key={ starterPrompt }
							size="small"
							variant="secondary"
							onClick={ () =>
								handleStarterPromptClick( starterPrompt )
							}
							disabled={ disabled || isLoading }
							className="flavor-agent-panel__composer-starter"
						>
							{ starterPrompt }
						</Button>
					) ) }
				</div>
			) }

			<div className="flavor-agent-panel__composer-actions">
				{ submitHint && (
					<p className="flavor-agent-panel__composer-helper">
						{ submitHint }
					</p>
				) }

				<Button
					variant={ fetchVariant }
					onClick={ canFetch ? onFetch : undefined }
					disabled={ isLoading || disabled || ! canFetch }
					icon={ fetchIcon }
					className="flavor-agent-fetch-button"
				>
					{ isLoading ? loadingLabel : fetchLabel }
				</Button>
			</div>
		</div>
	);
}
