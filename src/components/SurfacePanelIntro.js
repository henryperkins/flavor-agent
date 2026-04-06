/**
 * Surface Panel Intro
 *
 * Shared intro section used across all recommendation surfaces.
 * Renders an eyebrow label, optional meta badges, and intro copy.
 */
import { joinClassNames } from '../utils/format-count';

export default function SurfacePanelIntro( {
	eyebrow,
	introCopy = '',
	meta = null,
	className = '',
	children = null,
} ) {
	return (
		<div
			className={ joinClassNames(
				'flavor-agent-panel__intro',
				className
			) }
		>
			{ eyebrow && (
				<p className="flavor-agent-panel__eyebrow">{ eyebrow }</p>
			) }

			{ meta && <div className="flavor-agent-card__meta">{ meta }</div> }

			{ introCopy && (
				<p className="flavor-agent-panel__intro-copy">{ introCopy }</p>
			) }

			{ children }
		</div>
	);
}
