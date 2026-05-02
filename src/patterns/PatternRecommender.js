/**
 * Pattern Recommender
 *
 * Fetches AI-ranked pattern recommendations and renders them as a
 * Flavor Agent-owned shelf inside the inserter. This surface stays
 * browse-only from Gutenberg's perspective: it does not rewrite the
 * native pattern registry or category metadata.
 *
 * Two modes:
 * - Passive: fetches on editor load using postType
 * - Active: fetches on inserter search input with prompt
 * - Unavailable: keeps the inserter surface visible with a shared
 *   capability notice when ranking backends are missing
 *
 * Pattern settings reads and DOM discovery are split so selector
 * degradation stays isolated from the settings compatibility path.
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { cloneBlock, createBlock, parse } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useRef,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import CapabilityNotice from '../components/CapabilityNotice';
import { STORE_NAME } from '../store';
import { formatCount } from '../utils/format-count';
import { getSurfaceCapability } from '../utils/capability-flags';
import {
	getTemplatePartAreaLookup,
	inferTemplatePartArea,
} from '../utils/template-part-areas';
import { normalizeTemplateType } from '../utils/template-types';
import { getVisiblePatternNames } from '../utils/visible-patterns';
import { findInserterContainer, findInserterSearchInput } from './inserter-dom';
import { getAllowedPatterns } from './pattern-settings';
import {
	buildRecommendedPatterns,
	getPatternRecommendationInsights,
} from './recommendation-utils';

const SEARCH_DEBOUNCE_MS = 400;
const INSERTER_SLOT_CLASS = 'flavor-agent-pattern-inserter-slot';

function getNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

function buildAncestorEntries( editor, inserterRootClientId ) {
	if ( ! inserterRootClientId ) {
		return [];
	}

	const ancestors = [];
	let parentId = inserterRootClientId;

	while ( parentId ) {
		ancestors.unshift( {
			clientId: parentId,
			blockName: editor.getBlockName?.( parentId ) || '',
			attributes: editor.getBlockAttributes?.( parentId ) || {},
		} );
		parentId = editor.getBlockRootClientId?.( parentId ) ?? null;
	}

	return ancestors;
}

function buildInsertionContext( editor, inserterRootClientId, insertionPoint ) {
	const ancestorEntries = buildAncestorEntries(
		editor,
		inserterRootClientId
	);
	const rootEntry = ancestorEntries[ ancestorEntries.length - 1 ] || null;
	const areaLookup = getTemplatePartAreaLookup();
	const nearestTemplatePart = [ ...ancestorEntries ]
		.reverse()
		.find( ( entry ) => entry.blockName === 'core/template-part' );
	const templatePartArea = nearestTemplatePart
		? inferTemplatePartArea( nearestTemplatePart.attributes, areaLookup )
		: '';
	const templatePartSlug = getNonEmptyString(
		nearestTemplatePart?.attributes?.slug
	);
	const containerLayout = getNonEmptyString(
		rootEntry?.attributes?.layout?.type
	);
	const siblingOrder = editor.getBlockOrder?.( inserterRootClientId ) || [];
	const insertIndex = insertionPoint?.index ?? siblingOrder.length;
	const nearbySiblings = [];
	const start = Math.max( 0, insertIndex - 3 );
	const end = Math.min( siblingOrder.length, insertIndex + 3 );

	for ( let i = start; i < end; i++ ) {
		const name = editor.getBlockName?.( siblingOrder[ i ] );

		if ( name ) {
			nearbySiblings.push( name );
		}
	}

	return {
		rootBlock: rootEntry?.blockName || null,
		ancestors: ancestorEntries
			.map( ( entry ) => entry.blockName )
			.filter( Boolean ),
		nearbySiblings,
		...( templatePartArea ? { templatePartArea } : {} ),
		...( templatePartSlug ? { templatePartSlug } : {} ),
		...( containerLayout ? { containerLayout } : {} ),
	};
}

function getPatternTitle( pattern ) {
	return getNonEmptyString( pattern?.title ) || pattern?.name || 'Pattern';
}

function resolvePatternBlocks( pattern ) {
	if (
		pattern?.type === 'user' &&
		pattern?.syncStatus !== 'unsynced' &&
		pattern?.id
	) {
		return [ createBlock( 'core/block', { ref: pattern.id } ) ];
	}

	if ( Array.isArray( pattern?.blocks ) && pattern.blocks.length > 0 ) {
		return pattern.blocks;
	}

	if ( typeof pattern?.content === 'string' && pattern.content.trim() ) {
		try {
			return parse( pattern.content );
		} catch ( error ) {
			return [];
		}
	}

	return [];
}

function getUnreadableSyncedPatternCount( diagnostics ) {
	const count = Number(
		diagnostics?.filteredCandidates?.unreadableSyncedPatterns ?? 0
	);

	return Number.isFinite( count ) ? Math.max( 0, count ) : 0;
}

function getUnreadableSyncedPatternMessage( diagnostics ) {
	const count = getUnreadableSyncedPatternCount( diagnostics );

	if ( count <= 0 ) {
		return '';
	}

	return `${ formatCount( count, 'synced pattern' ) } ${
		count === 1 ? 'was' : 'were'
	} skipped because current WordPress permissions do not allow read access.`;
}

function PatternFilteredCandidateNotice( { diagnostics } ) {
	const message = getUnreadableSyncedPatternMessage( diagnostics );

	if ( ! message ) {
		return null;
	}

	return (
		<p className="flavor-agent-pattern-summary__filtered-note">
			{ message }
		</p>
	);
}

function getPatternEmptyMessage( recommendations, diagnostics ) {
	const unreadableMessage = getUnreadableSyncedPatternMessage( diagnostics );

	if ( unreadableMessage ) {
		return unreadableMessage;
	}

	return Array.isArray( recommendations ) && recommendations.length > 0
		? 'Flavor Agent found ranked patterns, but Gutenberg is not currently exposing those patterns for this insertion point.'
		: '';
}

function PatternShelf( { items, onInsert, diagnostics } ) {
	return (
		<div
			className="flavor-agent-pattern-summary flavor-agent-pattern-shelf"
			role="status"
			aria-live="polite"
		>
			<div className="flavor-agent-pattern-summary__header">
				<span className="flavor-agent-pill">Flavor Agent</span>
				<span className="flavor-agent-pill">
					{ formatCount( items.length, 'recommendation' ) }
				</span>
			</div>
			<p className="flavor-agent-pattern-summary__copy">
				AI-ranked patterns stay local to this shelf. Gutenberg&apos;s
				native pattern registry stays unchanged.
			</p>
			<PatternFilteredCandidateNotice diagnostics={ diagnostics } />
			<div className="flavor-agent-pattern-shelf__items">
				{ items.map( ( { pattern, recommendation } ) => {
					const insights = getPatternRecommendationInsights(
						pattern,
						recommendation
					);

					return (
						<div
							key={ pattern.name }
							className="flavor-agent-pattern-shelf__item"
						>
							<div className="flavor-agent-pattern-shelf__body">
								<div className="flavor-agent-pattern-shelf__title">
									{ getPatternTitle( pattern ) }
								</div>
								{ recommendation?.reason && (
									<p className="flavor-agent-pattern-shelf__reason">
										{ recommendation.reason }
									</p>
								) }
								{ insights.length > 0 && (
									<ul className="flavor-agent-pattern-shelf__insights">
										{ insights.map( ( insight ) => (
											<li
												key={ insight }
												className="flavor-agent-pattern-shelf__insight"
											>
												{ insight }
											</li>
										) ) }
									</ul>
								) }
							</div>
							<Button
								variant="secondary"
								size="small"
								onClick={ () => onInsert( pattern ) }
							>
								Insert
							</Button>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}

function PatternInserterNotice( {
	status,
	error = '',
	message = '',
	onRetry,
} ) {
	let resolvedMessage =
		message ||
		'Preparing pattern recommendations for this insertion point.';

	if ( status === 'loading' ) {
		resolvedMessage = 'Ranking patterns for this insertion point.';
	} else if ( status === 'error' ) {
		resolvedMessage =
			error ||
			'Pattern recommendation request failed for this insertion point.';
	} else if ( status === 'empty' && ! message ) {
		resolvedMessage =
			'Flavor Agent did not find a strong pattern match for this insertion point yet.';
	}

	return (
		<div
			className="flavor-agent-pattern-summary"
			role="status"
			aria-live="polite"
		>
			<div className="flavor-agent-pattern-summary__header">
				<span className="flavor-agent-pill">Flavor Agent</span>
				{ status === 'empty' && (
					<span className="flavor-agent-pill">No matches yet</span>
				) }
				{ status === 'error' && (
					<span className="flavor-agent-pill">Ranking failed</span>
				) }
			</div>
			<p className="flavor-agent-pattern-summary__copy">
				{ resolvedMessage }
			</p>
			{ status === 'error' && typeof onRetry === 'function' && (
				<Button
					variant="link"
					onClick={ onRetry }
					className="flavor-agent-pattern-summary__retry"
				>
					Retry
				</Button>
			) }
		</div>
	);
}

export default function PatternRecommender() {
	const canRecommend = getSurfaceCapability( 'pattern' ).available;
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const isInserterOpen = useSelect(
		( select ) => select( editorStore ).isInserterOpened(),
		[]
	);
	const templateType = useSelect( ( select ) => {
		const editSite = select( 'core/edit-site' );

		if ( ! editSite?.getEditedPostType ) {
			return undefined;
		}

		if ( editSite.getEditedPostType() !== 'wp_template' ) {
			return undefined;
		}

		return normalizeTemplateType( editSite.getEditedPostId() );
	}, [] );
	const selectedBlockName = useSelect( ( select ) => {
		const clientId = select( blockEditorStore ).getSelectedBlockClientId();

		if ( ! clientId ) {
			return null;
		}

		return select( blockEditorStore ).getBlockName( clientId );
	}, [] );
	const insertionPoint = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlockInsertionPoint?.() || null,
		[]
	);
	const inserterRootClientId = insertionPoint?.rootClientId ?? null;
	const insertionIndex = insertionPoint?.index;
	const insertionContext = useSelect(
		( select ) => {
			if ( ! insertionPoint ) {
				return null;
			}

			const editor = select( blockEditorStore );

			return buildInsertionContext(
				editor,
				inserterRootClientId,
				insertionPoint
			);
		},
		[ inserterRootClientId, insertionPoint ]
	);
	const visiblePatternNames = useSelect(
		( select ) => {
			return getVisiblePatternNames(
				inserterRootClientId,
				select( blockEditorStore )
			);
		},
		[ inserterRootClientId ]
	);
	const allowedPatterns = useSelect(
		( select ) => {
			return getAllowedPatterns(
				inserterRootClientId,
				select( blockEditorStore )
			);
		},
		[ inserterRootClientId ]
	);
	const { patternError, patternStatus, recommendations, patternDiagnostics } =
		useSelect( ( select ) => {
			const store = select( STORE_NAME );

			return {
				patternError: store.getPatternError?.() || '',
				patternStatus: store.getPatternStatus(),
				recommendations: store.getPatternRecommendations(),
				patternDiagnostics: store.getPatternDiagnostics?.() || null,
			};
		}, [] );
	const { fetchPatternRecommendations } = useDispatch( STORE_NAME );
	const { insertBlocks } = useDispatch( blockEditorStore );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const observerRef = useRef( null );
	const listenerRef = useRef( null );
	const debounceRef = useRef( null );
	const noticeObserverRef = useRef( null );
	const noticeSlotRef = useRef( null );

	if ( ! noticeSlotRef.current && typeof document !== 'undefined' ) {
		const noticeSlot = document.createElement( 'div' );
		noticeSlot.className = INSERTER_SLOT_CLASS;
		noticeSlotRef.current = noticeSlot;
	}

	const shouldRenderInserterAffordance =
		isInserterOpen &&
		( ! canRecommend ||
			patternStatus === 'loading' ||
			patternStatus === 'error' ||
			patternStatus === 'ready' ||
			patternStatus === 'idle' );
	const recommendedPatterns = useMemo(
		() => buildRecommendedPatterns( recommendations, allowedPatterns ),
		[ allowedPatterns, recommendations ]
	);

	const buildBaseInput = useCallback( () => {
		const input = {
			postType,
			visiblePatternNames,
		};

		if ( templateType ) {
			input.templateType = templateType;
		}

		if ( insertionContext ) {
			input.insertionContext = insertionContext;
		}

		return input;
	}, [ postType, templateType, visiblePatternNames, insertionContext ] );

	const clearSearchDebounce = useCallback( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
			debounceRef.current = null;
		}
	}, [] );

	const scheduleSearchFetch = useCallback(
		( callback ) => {
			clearSearchDebounce();
			debounceRef.current = setTimeout( () => {
				debounceRef.current = null;
				callback();
			}, SEARCH_DEBOUNCE_MS );
		},
		[ clearSearchDebounce ]
	);

	const handleRetry = useCallback( () => {
		if ( ! canRecommend || ! postType ) {
			return;
		}

		fetchPatternRecommendations( buildBaseInput() );
	}, [
		canRecommend,
		postType,
		buildBaseInput,
		fetchPatternRecommendations,
	] );

	const handleInsertPattern = useCallback(
		( pattern ) => {
			const blocks = resolvePatternBlocks( pattern );

			if ( blocks.length === 0 ) {
				return;
			}

			insertBlocks(
				blocks.map( ( block ) => cloneBlock( block ) ),
				insertionIndex,
				inserterRootClientId,
				false
			);
			createSuccessNotice(
				sprintf(
					/* translators: %s: block pattern title. */
					__( 'Block pattern "%s" inserted.' ),
					getPatternTitle( pattern )
				),
				{
					type: 'snackbar',
					id: 'inserter-notice',
				}
			);
		},
		[
			createSuccessNotice,
			insertBlocks,
			insertionIndex,
			inserterRootClientId,
		]
	);

	useEffect( () => {
		if ( ! canRecommend || ! postType ) {
			return;
		}

		fetchPatternRecommendations( buildBaseInput() );
	}, [
		canRecommend,
		postType,
		buildBaseInput,
		fetchPatternRecommendations,
	] );

	useEffect( () => {
		const noticeSlot = noticeSlotRef.current;

		if ( ! noticeSlot ) {
			return undefined;
		}

		const cleanupNotice = () => {
			if ( noticeObserverRef.current ) {
				noticeObserverRef.current.disconnect();
				noticeObserverRef.current = null;
			}

			if ( noticeSlot?.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}
		};

		const attachNotice = ( inserterContainer ) => {
			if ( ! noticeSlot || ! inserterContainer ) {
				return;
			}

			if ( noticeSlot.parentNode === inserterContainer ) {
				return;
			}

			if ( noticeSlot.parentNode ) {
				noticeSlot.parentNode.removeChild( noticeSlot );
			}

			inserterContainer.insertBefore(
				noticeSlot,
				inserterContainer.firstChild
			);
		};

		const syncNotice = () => {
			const inserterContainer = findInserterContainer( document );

			if ( ! inserterContainer ) {
				return;
			}

			attachNotice( inserterContainer );
		};

		if ( ! shouldRenderInserterAffordance ) {
			cleanupNotice();
			return undefined;
		}

		syncNotice();

		if ( ! window.MutationObserver ) {
			return cleanupNotice;
		}

		// Gutenberg can replace the inserter container without toggling the
		// open state, so keep the slot attached for the whole affordance lifetime.
		const observer = new window.MutationObserver( () => {
			syncNotice();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		noticeObserverRef.current = observer;

		return () => {
			cleanupNotice();
		};
	}, [ shouldRenderInserterAffordance ] );

	const handleSearchInput = useCallback(
		( value ) => {
			scheduleSearchFetch( () => {
				if ( ! postType ) {
					return;
				}

				// buildBaseInput() already carries the latest insertionContext.
				const input = buildBaseInput();
				const trimmedValue = value.trim();

				if ( trimmedValue ) {
					input.prompt = trimmedValue;
				}

				if ( selectedBlockName ) {
					input.blockContext = { blockName: selectedBlockName };
				}

				fetchPatternRecommendations( input );
			} );
		},
		[
			postType,
			buildBaseInput,
			selectedBlockName,
			fetchPatternRecommendations,
			scheduleSearchFetch,
		]
	);

	useEffect( () => {
		const cleanupBindings = () => {
			clearSearchDebounce();

			if ( observerRef.current ) {
				observerRef.current.disconnect();
				observerRef.current = null;
			}

			if ( listenerRef.current ) {
				listenerRef.current.el.removeEventListener(
					'input',
					listenerRef.current.fn
				);
				listenerRef.current = null;
			}
		};

		if ( ! canRecommend || ! isInserterOpen ) {
			cleanupBindings();
			return undefined;
		}

		function attachToSearch( searchInput ) {
			if ( listenerRef.current?.el === searchInput ) {
				return;
			}

			if ( listenerRef.current ) {
				listenerRef.current.el.removeEventListener(
					'input',
					listenerRef.current.fn
				);
			}

			const fn = ( event ) => handleSearchInput( event.target.value );

			searchInput.addEventListener( 'input', fn );
			listenerRef.current = { el: searchInput, fn };
		}

		function syncSearchInput() {
			const input = findInserterSearchInput( document );

			if ( ! input ) {
				return;
			}

			attachToSearch( input );
		}

		syncSearchInput();

		if ( ! window.MutationObserver ) {
			return cleanupBindings;
		}

		const observer = new window.MutationObserver( () => {
			syncSearchInput();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		observerRef.current = observer;

		return () => {
			cleanupBindings();
		};
	}, [
		canRecommend,
		clearSearchDebounce,
		isInserterOpen,
		handleSearchInput,
	] );

	if ( shouldRenderInserterAffordance && noticeSlotRef.current ) {
		let notice = null;

		if ( ! canRecommend ) {
			notice = <CapabilityNotice surface="pattern" />;
		} else if (
			patternStatus === 'ready' &&
			recommendedPatterns.length > 0
		) {
			notice = (
				<PatternShelf
					items={ recommendedPatterns }
					onInsert={ handleInsertPattern }
					diagnostics={ patternDiagnostics }
				/>
			);
		} else if ( patternStatus === 'error' ) {
			notice = (
				<PatternInserterNotice
					status="error"
					error={ patternError }
					onRetry={ handleRetry }
				/>
			);
		} else if ( patternStatus === 'ready' ) {
			notice = (
				<PatternInserterNotice
					status="empty"
					message={ getPatternEmptyMessage(
						recommendations,
						patternDiagnostics
					) }
				/>
			);
		} else {
			notice = <PatternInserterNotice status="loading" />;
		}

		return createPortal( notice, noticeSlotRef.current );
	}

	return null;
}
