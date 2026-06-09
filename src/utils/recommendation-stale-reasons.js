import { __, sprintf } from '@wordpress/i18n';

export function getExecutableSurfaceEffectiveStaleReason( {
	clientStaleReason = null,
	reviewStaleReason = null,
	storedStaleReason = null,
} ) {
	if ( clientStaleReason ) {
		return clientStaleReason;
	}

	if ( reviewStaleReason === 'server-review' ) {
		return 'server-review';
	}

	if ( reviewStaleReason === 'docs-grounding-unavailable' ) {
		return 'docs-grounding-unavailable';
	}

	if (
		storedStaleReason === 'docs-grounding-unavailable' ||
		storedStaleReason === 'docs-grounding-changed' ||
		storedStaleReason === 'missing-resolved-signature'
	) {
		return storedStaleReason;
	}

	if (
		storedStaleReason === 'server' ||
		storedStaleReason === 'server-apply'
	) {
		return 'server-apply';
	}

	return null;
}

export function getExecutableSurfaceStaleMessage( {
	surfaceLabel,
	staleReasonType = null,
	liveContextLabel,
} ) {
	if ( ! staleReasonType ) {
		return '';
	}

	if ( staleReasonType === 'server-review' ) {
		return sprintf(
			/* translators: %s: recommendation surface label. */
			__(
				'This %s result no longer matches the current server review context. Refresh before reviewing or applying anything from the previous result.',
				'flavor-agent'
			),
			surfaceLabel
		);
	}

	if ( staleReasonType === 'server-apply' ) {
		return sprintf(
			/* translators: %s: recommendation surface label. */
			__(
				'This %s result no longer matches the current server-resolved apply context. Refresh before reviewing or applying anything from the previous result.',
				'flavor-agent'
			),
			surfaceLabel
		);
	}

	if ( staleReasonType === 'docs-grounding-unavailable' ) {
		return sprintf(
			/* translators: %s: recommendation surface label. */
			__(
				'This %s result no longer has trusted WordPress Developer Docs grounding. Refresh before reviewing or applying anything from the previous result.',
				'flavor-agent'
			),
			surfaceLabel
		);
	}

	if ( staleReasonType === 'docs-grounding-changed' ) {
		return sprintf(
			/* translators: %s: recommendation surface label. */
			__(
				'This %s result no longer matches the current WordPress Developer Docs grounding. Refresh before reviewing or applying anything from the previous result.',
				'flavor-agent'
			),
			surfaceLabel
		);
	}

	if ( staleReasonType === 'missing-resolved-signature' ) {
		return sprintf(
			/* translators: %s: recommendation surface label. */
			__(
				'This %s result is missing server-resolved apply context. Refresh before reviewing or applying anything from the previous result.',
				'flavor-agent'
			),
			surfaceLabel
		);
	}

	return sprintf(
		/* translators: 1: recommendation surface label. 2: live context description. */
		__(
			'This %1$s result no longer matches %2$s. Refresh before reviewing or applying anything from the previous result.',
			'flavor-agent'
		),
		surfaceLabel,
		liveContextLabel
	);
}
