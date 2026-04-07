import { Button } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';

import { formatCount, joinClassNames } from '../utils/format-count';
import {
	ADVISORY_ONLY_LABEL,
	MANUAL_IDEAS_LABEL,
} from './surface-labels';

const DEFAULT_VISIBLE_COUNT = 5;

export default function AIAdvisorySection( {
	title = MANUAL_IDEAS_LABEL,
	advisoryLabel = ADVISORY_ONLY_LABEL,
	count = null,
	countLabel = '',
	countNoun = 'suggestion',
	description = '',
	meta = null,
	children = null,
	className = '',
	initialOpen = false,
	maxVisible = DEFAULT_VISIBLE_COUNT,
} ) {
	const [ isOpen, setIsOpen ] = useState( initialOpen );
	const [ showAll, setShowAll ] = useState( false );
	const resolvedCountLabel = countLabel || formatCount( count, countNoun );
	const childArray = useMemo( () => {
		if ( Array.isArray( children ) ) {
			return children.filter( Boolean );
		}

		if ( children ) {
			return [ children ];
		}

		return [];
	}, [ children ] );
	const advisoryContentKey = useMemo(
		() =>
			JSON.stringify( {
				count,
				maxVisible,
				childCount: childArray.length,
				childKeys: childArray.map( ( child, index ) => {
					const childKey = child?.key;

					return childKey === null || childKey === undefined
						? `index-${ index }`
						: String( childKey );
				} ),
			} ),
		[ childArray, count, maxVisible ]
	);

	useEffect( () => {
		setShowAll( false );
	}, [ advisoryContentKey ] );

	const totalChildren = childArray.length;
	const hasOverflow = totalChildren > maxVisible && ! showAll;
	const visibleChildren = hasOverflow
		? childArray.slice( 0, maxVisible )
		: childArray;

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-panel__group',
				'flavor-agent-advisory-section',
				! isOpen ? 'flavor-agent-advisory-section--collapsed' : '',
				className
			) }
		>
			<button
				type="button"
				className="flavor-agent-panel__group-header flavor-agent-advisory-section__toggle"
				onClick={ () => setIsOpen( ( prev ) => ! prev ) }
				aria-expanded={ isOpen }
			>
				<div className="flavor-agent-panel__group-title">{ title }</div>
				<div className="flavor-agent-card__meta">
					{ meta }
					{ advisoryLabel && (
						<span className="flavor-agent-pill">
							{ advisoryLabel }
						</span>
					) }
					{ resolvedCountLabel && (
						<span className="flavor-agent-pill">
							{ resolvedCountLabel }
						</span>
					) }
				</div>
				<span
					className="flavor-agent-advisory-section__chevron"
					aria-hidden="true"
				>
					{ isOpen ? '\u25B2' : '\u25BC' }
				</span>
			</button>

			{ isOpen && description && (
				<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
					{ description }
				</p>
			) }

			{ isOpen && visibleChildren.length > 0 && (
				<div className="flavor-agent-panel__group-body">
					{ visibleChildren }
				</div>
			) }

			{ isOpen && hasOverflow && (
				<Button
					variant="link"
					onClick={ () => setShowAll( true ) }
					className="flavor-agent-advisory-section__show-more"
				>
					{ `Show ${ totalChildren - maxVisible } more` }
				</Button>
			) }
		</div>
	);
}
