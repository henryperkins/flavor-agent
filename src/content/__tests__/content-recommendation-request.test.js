import { buildContentRecommendationRequestSignature } from '../../utils/recommendation-request-signature';
import { getContentRecommendationFreshness } from '../content-recommendation-request';

const BASE_POST_CONTEXT = {
	postId: 42,
	postType: 'post',
	title: 'Working draft',
	excerpt: '',
	content: 'Retail floors. WordPress themes.',
	slug: 'working-draft',
	status: 'draft',
};

describe( 'buildContentRecommendationRequestSignature', () => {
	test( 'returns the same signature for equivalent inputs regardless of key order', () => {
		const signatureA = buildContentRecommendationRequestSignature( {
			mode: 'draft',
			prompt: 'Tighten the opener.',
			postContext: BASE_POST_CONTEXT,
		} );
		const signatureB = buildContentRecommendationRequestSignature( {
			postContext: { ...BASE_POST_CONTEXT },
			prompt: 'Tighten the opener.',
			mode: 'draft',
		} );

		expect( signatureA ).toBe( signatureB );
	} );

	test( 'changes when the mode changes', () => {
		const draft = buildContentRecommendationRequestSignature( {
			mode: 'draft',
			prompt: 'Tighten the opener.',
			postContext: BASE_POST_CONTEXT,
		} );
		const critique = buildContentRecommendationRequestSignature( {
			mode: 'critique',
			prompt: 'Tighten the opener.',
			postContext: BASE_POST_CONTEXT,
		} );

		expect( draft ).not.toBe( critique );
	} );

	test( 'changes when the prompt changes', () => {
		const promptA = buildContentRecommendationRequestSignature( {
			mode: 'draft',
			prompt: 'Tighten the opener.',
			postContext: BASE_POST_CONTEXT,
		} );
		const promptB = buildContentRecommendationRequestSignature( {
			mode: 'draft',
			prompt: 'Sharper closer.',
			postContext: BASE_POST_CONTEXT,
		} );

		expect( promptA ).not.toBe( promptB );
	} );

	test( 'changes when post title, excerpt, content, slug, or status changes', () => {
		const baseline = buildContentRecommendationRequestSignature( {
			mode: 'draft',
			prompt: 'Tighten the opener.',
			postContext: BASE_POST_CONTEXT,
		} );

		for ( const overrides of [
			{ title: 'Brand-new draft' },
			{ excerpt: 'Edited excerpt.' },
			{ content: 'Retail floors changed.' },
			{ slug: 'working-draft-2' },
			{ status: 'pending' },
		] ) {
			const variant = buildContentRecommendationRequestSignature( {
				mode: 'draft',
				prompt: 'Tighten the opener.',
				postContext: { ...BASE_POST_CONTEXT, ...overrides },
			} );

			expect( variant ).not.toBe( baseline );
		}
	} );

	test( 'normalizes prompt whitespace', () => {
		const normal = buildContentRecommendationRequestSignature( {
			mode: 'draft',
			prompt: 'Tighten the opener.',
			postContext: BASE_POST_CONTEXT,
		} );
		const padded = buildContentRecommendationRequestSignature( {
			mode: 'draft',
			prompt: '   Tighten the opener.   ',
			postContext: BASE_POST_CONTEXT,
		} );

		expect( padded ).toBe( normal );
	} );
} );

describe( 'getContentRecommendationFreshness', () => {
	function freshness( overrides = {} ) {
		return getContentRecommendationFreshness( {
			contentRecommendation: {
				mode: 'draft',
				content: 'Some draft text.',
			},
			contentRequestPrompt: 'Tighten the opener.',
			storedRequestSignature: buildContentRecommendationRequestSignature(
				{
					mode: 'draft',
					prompt: 'Tighten the opener.',
					postContext: BASE_POST_CONTEXT,
				}
			),
			currentMode: 'draft',
			currentPrompt: 'Tighten the opener.',
			currentPostContext: BASE_POST_CONTEXT,
			status: 'ready',
			...overrides,
		} );
	}

	test( 'reports a fresh result when current inputs match the stored signature', () => {
		const result = freshness();

		expect( result.hasStoredResult ).toBe( true );
		expect( result.isStaleResult ).toBe( false );
		expect( result.hasFreshResult ).toBe( true );
	} );

	test( 'reports stale when the prompt drifts', () => {
		const result = freshness( {
			currentPrompt: 'Sharper closer.',
		} );

		expect( result.isStaleResult ).toBe( true );
		expect( result.hasFreshResult ).toBe( false );
	} );

	test( 'reports stale when the mode drifts', () => {
		const result = freshness( {
			currentMode: 'critique',
		} );

		expect( result.isStaleResult ).toBe( true );
		expect( result.hasFreshResult ).toBe( false );
	} );

	test( 'reports stale when the post content drifts', () => {
		const result = freshness( {
			currentPostContext: {
				...BASE_POST_CONTEXT,
				content: 'Retail floors changed.',
			},
		} );

		expect( result.isStaleResult ).toBe( true );
		expect( result.hasFreshResult ).toBe( false );
	} );

	test( 'treats results without a stored signature as stale', () => {
		const result = freshness( { storedRequestSignature: '' } );

		expect( result.isStaleResult ).toBe( true );
		expect( result.hasFreshResult ).toBe( false );
	} );

	test( 'reports no stored result when status is not ready', () => {
		const result = freshness( { status: 'loading' } );

		expect( result.hasStoredResult ).toBe( false );
		expect( result.isStaleResult ).toBe( false );
		expect( result.hasFreshResult ).toBe( false );
	} );

	test( 'reports no stored result when there is no recommendation payload', () => {
		const result = freshness( { contentRecommendation: null } );

		expect( result.hasStoredResult ).toBe( false );
		expect( result.isStaleResult ).toBe( false );
		expect( result.hasFreshResult ).toBe( false );
	} );
} );
