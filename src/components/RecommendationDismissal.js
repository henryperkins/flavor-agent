import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { buildDismissalOutcomes } from '../store/recommendation-dismissal';

const STORE_NAME = 'flavor-agent';

export default function RecommendationDismissal( props ) {
	const outcomes = buildDismissalOutcomes( props );
	// An unidentified or stale displayed card must not create observation evidence.
	if ( ! outcomes.length ) {
		return null;
	}
	return <DismissalControl { ...props } outcomes={ outcomes } />;
}

function DismissalControl( {
	surface,
	suggestions,
	currentRequestSignature,
	target = {},
	document,
	outcomes,
} ) {
	const { recordRecommendationOutcome, dismissRecommendationSuggestions } =
		useDispatch( STORE_NAME );
	const [ status, setStatus ] = useState( '' );
	const shownObservation = useRef( null );
	const identityKey = JSON.stringify( outcomes );
	const clientId = target.clientId || '';
	const documentKey = JSON.stringify( document || null );
	const visibleLabel =
		suggestions.length === 1
			? [
					suggestions[ 0 ]?.label,
					suggestions[ 0 ]?.title,
					suggestions[ 0 ]?.name,
			  ].find( ( value ) => typeof value === 'string' && value.trim() )
			: '';
	const targetLabel =
		visibleLabel ||
		sprintf(
			/* translators: %d: number of explicitly visible suggestions. */
			_n(
				'%d visible suggestion',
				'%d visible suggestions',
				outcomes.length,
				'flavor-agent'
			),
			outcomes.length
		);
	const dismissLabel = sprintf(
		/* translators: %s: visible suggestion title or visible suggestion count. */
		__( 'Dismiss for now: %s', 'flavor-agent' ),
		targetLabel
	);

	useEffect( () => {
		setStatus( '' );
		const observation = {
			active: true,
			promise: Promise.resolve( false ),
		};
		shownObservation.current = observation;
		if ( typeof recordRecommendationOutcome === 'function' ) {
			const displayed = JSON.parse( identityKey );
			observation.promise = Promise.resolve(
				recordRecommendationOutcome( {
					event: 'shown',
					surface,
					recommendationSetId: displayed[ 0 ].recommendationSetId,
					sourceRequestSignature:
						displayed[ 0 ].sourceRequestSignature,
					shownSuggestionKeys: displayed.map(
						( item ) => item.suggestionKey
					),
					resultCount: displayed.length,
					document: JSON.parse( documentKey ) || undefined,
					target: clientId ? { clientId } : {},
				} )
			)
				.then( ( result ) => result?.persistence?.status === 'server' )
				.catch( () => false );
		}
		return () => {
			observation.active = false;
		};
	}, [
		identityKey,
		clientId,
		documentKey,
		recordRecommendationOutcome,
		surface,
	] );

	async function dismiss() {
		const observation = shownObservation.current;
		const isCurrent = () =>
			observation?.active && shownObservation.current === observation;
		if ( ! isCurrent() ) {
			return;
		}
		setStatus( 'recording' );
		try {
			const wasShown = await observation.promise;
			// A replacement or unmount invalidates both dispatch and its receipt.
			if ( ! isCurrent() ) {
				return;
			}
			if (
				! wasShown ||
				typeof dismissRecommendationSuggestions !== 'function'
			) {
				setStatus( 'error' );
				return;
			}
			const results = await dismissRecommendationSuggestions( {
				surface,
				suggestions,
				currentRequestSignature,
				target,
				document,
			} );
			if ( ! isCurrent() ) {
				return;
			}
			setStatus(
				Array.isArray( results ) &&
					results.length &&
					results.every(
						( result ) => result?.persistence?.status === 'server'
					)
					? 'recorded'
					: 'error'
			);
		} catch {
			if ( isCurrent() ) {
				setStatus( 'error' );
			}
		}
	}

	return (
		<div className="flavor-agent-recommendation-dismissal">
			<Button
				variant="tertiary"
				size="small"
				aria-label={ dismissLabel }
				onClick={ dismiss }
				disabled={ status === 'recording' || status === 'recorded' }
			>
				{ status === 'recorded'
					? __( 'Dismissal recorded', 'flavor-agent' )
					: __( 'Dismiss for now', 'flavor-agent' ) }
			</Button>
			{ status === 'recorded' && (
				<span role="status">
					{ __(
						'You can still review or apply these suggestions.',
						'flavor-agent'
					) }
				</span>
			) }
			{ status === 'error' && (
				<span role="status">
					{ __(
						'Dismissal could not be recorded. Refresh and try again.',
						'flavor-agent'
					) }
				</span>
			) }
		</div>
	);
}
