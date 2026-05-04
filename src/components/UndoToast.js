/**
 * Flavor Agent — Undo Toast (presentational).
 *
 * Renders a single toast queued in the Flavor Agent store. The component is
 * pure props in / events out. Variant transitions (e.g. success → error after
 * an undo failure) live in the store; this component never owns variant state.
 *
 * Lifecycle effects it does own:
 *   - The `setTimeout` that fires `onDismiss` after `autoDismissMs`. The timer
 *     pauses while the toast is hovered or focus is within it; resumes on
 *     mouseleave / focusout. Under `prefers-reduced-motion: reduce` the timer
 *     is never scheduled (the toast persists until Undo or Close).
 *   - The hover / focus-within bookkeeping that calls `onInteractionChange` so
 *     the region can flag the queue entry for non-interacted FIFO eviction.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { check, closeSmall, undo } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

import { joinClassNames } from '../utils/format-count';

const VARIANT_ICONS = {
	success: check,
	error: closeSmall,
	warning: undo,
};

function getReducedMotionPreference() {
	if (
		typeof window === 'undefined' ||
		typeof window.matchMedia !== 'function'
	) {
		return false;
	}

	return window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
}

export default function UndoToast( {
	id,
	variant = 'success',
	title,
	detail = '',
	errorHint = '',
	undoLabel = __( 'Undo', 'flavor-agent' ),
	autoDismissMs = 6000,
	onUndo,
	onDismiss,
	undoDisabled = false,
	onInteractionChange = null,
} ) {
	const [ isPaused, setIsPaused ] = useState( false );
	const [ reducedMotion, setReducedMotion ] = useState(
		getReducedMotionPreference()
	);
	const remainingRef = useRef( autoDismissMs );
	const startedAtRef = useRef( 0 );
	const onDismissRef = useRef( onDismiss );

	useEffect( () => {
		onDismissRef.current = onDismiss;
	}, [ onDismiss ] );

	// Track reduced-motion preference changes so a mid-session toggle takes
	// effect on subsequent toasts.
	useEffect( () => {
		if (
			typeof window === 'undefined' ||
			typeof window.matchMedia !== 'function'
		) {
			return undefined;
		}

		const mq = window.matchMedia( '(prefers-reduced-motion: reduce)' );
		const handler = ( event ) => setReducedMotion( event.matches );

		if ( typeof mq.addEventListener === 'function' ) {
			mq.addEventListener( 'change', handler );

			return () => mq.removeEventListener( 'change', handler );
		}

		// Older browsers.
		mq.addListener( handler );

		return () => mq.removeListener( handler );
	}, [] );

	// Schedule / clear / pause / resume the auto-dismiss timer.
	//
	// While running, the effect schedules a `setTimeout`. Its cleanup clears
	// the timer AND subtracts the elapsed run-time from `remainingRef` so the
	// next run picks up where this one left off. Pausing (isPaused → true) or
	// switching to reduced motion makes the effect a no-op and returns no new
	// scheduling — only the cleanup of the previous run executes, which is
	// where the elapsed bookkeeping happens. This guarantees the cumulative
	// non-paused duration drives dismiss, not wall-clock.
	useEffect( () => {
		if ( reducedMotion || isPaused ) {
			return undefined;
		}

		startedAtRef.current = Date.now();
		const handle = window.setTimeout( () => {
			if ( typeof onDismissRef.current === 'function' ) {
				onDismissRef.current( id );
			}
		}, remainingRef.current );

		return () => {
			window.clearTimeout( handle );

			const elapsed = Date.now() - startedAtRef.current;

			remainingRef.current = Math.max(
				0,
				remainingRef.current - elapsed
			);
		};
	}, [ isPaused, reducedMotion, id ] );

	// Reset the remaining duration when the variant changes (e.g. a success
	// toast becoming an error toast after an undo failure carries a fresh
	// `autoDismissMs` from the store).
	useEffect( () => {
		remainingRef.current = autoDismissMs;
	}, [ autoDismissMs, variant ] );

	const reportInteraction = useCallback(
		( interacted ) => {
			if ( typeof onInteractionChange === 'function' ) {
				onInteractionChange( id, interacted );
			}
		},
		[ id, onInteractionChange ]
	);

	const handleMouseEnter = useCallback( () => {
		setIsPaused( true );
		reportInteraction( true );
	}, [ reportInteraction ] );

	const handleMouseLeave = useCallback( () => {
		setIsPaused( false );
		reportInteraction( false );
	}, [ reportInteraction ] );

	const handleFocus = useCallback( () => {
		setIsPaused( true );
		reportInteraction( true );
	}, [ reportInteraction ] );

	const handleBlur = useCallback(
		( event ) => {
			// `focusout` bubbles; we only care about leaving the toast root.
			if ( event.currentTarget.contains( event.relatedTarget ) ) {
				return;
			}

			setIsPaused( false );
			reportInteraction( false );
		},
		[ reportInteraction ]
	);

	const handleKeyDown = useCallback(
		( event ) => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();

				if ( typeof onDismiss === 'function' ) {
					onDismiss( id );
				}
			}
		},
		[ id, onDismiss ]
	);

	const handleUndoClick = useCallback( () => {
		if ( undoDisabled ) {
			return;
		}

		if ( typeof onUndo === 'function' ) {
			onUndo( id );
		}
	}, [ id, onUndo, undoDisabled ] );

	const handleUndoKeyDown = useCallback(
		( event ) => {
			if ( ! undoDisabled ) {
				return;
			}

			// Suppress activation when disabled but still tabbable.
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
			}
		},
		[ undoDisabled ]
	);

	const handleCloseClick = useCallback( () => {
		if ( typeof onDismiss === 'function' ) {
			onDismiss( id );
		}
	}, [ id, onDismiss ] );

	const className = joinClassNames(
		'flavor-agent-toast',
		`flavor-agent-toast--${ variant }`
	);
	const progressClassName = joinClassNames(
		'flavor-agent-toast__progress',
		isPaused ? 'is-paused' : '',
		reducedMotion ? 'is-static' : ''
	);
	const iconKey = variant in VARIANT_ICONS ? variant : 'success';
	const iconElement = VARIANT_ICONS[ iconKey ];
	const undoTitle = undoDisabled
		? __( 'Undo unavailable for this change', 'flavor-agent' )
		: undefined;

	return (
		// The toast wrapper is announced as a `status` region but also owns
		// the hover/focus/keyboard handlers that pause auto-dismiss and route
		// Escape to onDismiss. The interactive controls (Undo, Close) live
		// inside; the hover/focus handlers exist on the wrapper because the
		// pause behavior must apply when the pointer enters or focus lands
		// anywhere within the toast — not only on the buttons themselves.
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			role="status"
			aria-live="polite"
			className={ className }
			onMouseEnter={ handleMouseEnter }
			onMouseLeave={ handleMouseLeave }
			onFocus={ handleFocus }
			onBlur={ handleBlur }
			onKeyDown={ handleKeyDown }
			data-toast-id={ id }
			data-variant={ variant }
		>
			<span
				className={ joinClassNames(
					'flavor-agent-toast__icon',
					`flavor-agent-toast__icon--${ iconKey }`
				) }
				aria-hidden="true"
			>
				{ iconElement }
			</span>

			<span className="flavor-agent-toast__msg">
				<strong className="flavor-agent-toast__title">{ title }</strong>
				{ detail && (
					<span className="flavor-agent-toast__detail">
						{ detail }
					</span>
				) }
				{ variant === 'error' && errorHint && (
					<span className="flavor-agent-toast__error-hint">
						{ errorHint }
					</span>
				) }
			</span>

			<Button
				className="flavor-agent-toast__action"
				onClick={ handleUndoClick }
				onKeyDown={ handleUndoKeyDown }
				aria-disabled={ undoDisabled || undefined }
				tabIndex={ 0 }
				title={ undoTitle }
				icon={ undo }
			>
				{ undoLabel }
			</Button>

			<Button
				className="flavor-agent-toast__close"
				onClick={ handleCloseClick }
				icon={ closeSmall }
				label={ __( 'Dismiss', 'flavor-agent' ) }
			/>

			<span className={ progressClassName } aria-hidden="true" />
		</div>
	);
}
