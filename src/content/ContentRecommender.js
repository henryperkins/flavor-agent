import { Button, ButtonGroup } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import AIActivitySection from '../components/AIActivitySection';
import AIAdvisorySection from '../components/AIAdvisorySection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import RecommendationHero from '../components/RecommendationHero';
import StaleResultBanner from '../components/StaleResultBanner';
import SurfaceComposer from '../components/SurfaceComposer';
import { useContentDerivedContext } from './use-content-derived-context';
import { STORE_NAME } from '../store';
import {
	getConnectorApprovalNotice,
	getSurfaceCapability,
} from '../utils/capability-flags';
import { joinClassNames } from '../utils/format-count';
import { getContentRecommendationFreshness } from './content-recommendation-request';

const SUPPORTED_POST_TYPES = new Set( [ 'post', 'page' ] );
const CONTENT_MODES = [ 'draft', 'edit', 'critique' ];
const CONTENT_MODE_CONFIG = {
	draft: {
		label: 'Draft',
		title: 'Generate draft text',
		placeholder:
			'Describe the draft you want (for example: concise launch post for store managers).',
		helperText: 'Generated text is for review and manual copy.',
		fetchLabel: 'Generate Draft Text',
		starterPrompts: [
			'Sharper opening',
			'Cleaner structure',
			'Stronger closing',
		],
	},
	edit: {
		label: 'Edit',
		title: 'Generate revision text',
		placeholder:
			'Describe the revision pass (for example: tighten intro and trim repetition).',
		helperText: 'Review the revision and copy useful text manually.',
		fetchLabel: 'Generate Revision Text',
		starterPrompts: [
			'Tighter pacing',
			'Less repetition',
			'Sharper voice',
		],
	},
	critique: {
		label: 'Critique',
		title: 'Stress-test the draft',
		placeholder:
			'Describe the critique focus (for example: clarity gaps and weak transitions).',
		helperText: 'Flags issues without changing the post.',
		fetchLabel: 'Generate Critique',
		starterPrompts: [ 'Weakest lines', 'Structure drift', 'Vague wording' ],
	},
};

function getContentModeConfig( mode = 'draft' ) {
	return CONTENT_MODE_CONFIG[ mode ] || CONTENT_MODE_CONFIG.draft;
}

function formatModeLabel( mode = '' ) {
	return getContentModeConfig( mode ).label;
}

function formatContextLabel( value = '' ) {
	return String( value )
		.trim()
		.replace( /[_-]+/g, ' ' )
		.replace( /\b\w/g, ( character ) => character.toUpperCase() );
}

function summarizePrompt( prompt = '' ) {
	const summary = String( prompt ).replace( /\s+/g, ' ' ).trim();

	if ( ! summary ) {
		return 'No prompt saved';
	}

	return summary.length > 72 ? `${ summary.slice( 0, 69 ) }...` : summary;
}

function hasRecommendationOutput( recommendation ) {
	return Boolean(
		recommendation?.title ||
			recommendation?.summary ||
			recommendation?.content ||
			( Array.isArray( recommendation?.notes ) &&
				recommendation.notes.length > 0 ) ||
			( Array.isArray( recommendation?.issues ) &&
				recommendation.issues.length > 0 )
	);
}

function ContentRequestSummary( {
	modeLabel = '',
	prompt = '',
	isOpen = false,
	onToggle,
} ) {
	return (
		<button
			type="button"
			className="flavor-agent-content-recommender__refine-toggle"
			aria-expanded={ isOpen }
			onClick={ onToggle }
		>
			<span className="flavor-agent-content-recommender__refine-copy">
				<span className="flavor-agent-content-recommender__refine-label">
					Refine request
				</span>
				<span className="flavor-agent-content-recommender__refine-summary">
					<span className="flavor-agent-pill">{ modeLabel }</span>
					<span className="flavor-agent-content-recommender__prompt-summary">
						{ summarizePrompt( prompt ) }
					</span>
				</span>
			</span>
			<span
				className="flavor-agent-content-recommender__refine-chevron"
				aria-hidden="true"
			>
				{ isOpen ? '\u25B2' : '\u25BC' }
			</span>
		</button>
	);
}

async function copyTextToClipboard( text = '' ) {
	const value = String( text );

	if ( ! value.trim() ) {
		return false;
	}

	const clipboard =
		typeof window !== 'undefined' ? window.navigator?.clipboard : null;

	if ( ! clipboard || typeof clipboard.writeText !== 'function' ) {
		return false;
	}

	try {
		await clipboard.writeText( value );
		return true;
	} catch {
		return false;
	}
}

function ContentBody( { content = '' } ) {
	const paragraphs = String( content )
		.split( /\n\s*\n/ )
		.map( ( paragraph ) => paragraph.trim() )
		.filter( Boolean );

	if ( paragraphs.length === 0 ) {
		return null;
	}

	return (
		<div className="flavor-agent-panel__group-body">
			{ paragraphs.map( ( paragraph, index ) => (
				<p
					key={ `content-paragraph-${ index }` }
					className="flavor-agent-card__description"
				>
					{ paragraph }
				</p>
			) ) }
		</div>
	);
}

function ContentIssueCard( { issue = {}, compact = false } ) {
	if ( ! issue?.original && ! issue?.problem && ! issue?.revision ) {
		return null;
	}

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-card',
				compact ? 'flavor-agent-content-recommender__issue-card' : ''
			) }
		>
			<div className="flavor-agent-card__label">
				{ issue?.original || 'Voice issue' }
			</div>
			{ issue?.problem && (
				<p className="flavor-agent-card__description">
					{ issue.problem }
				</p>
			) }
			{ issue?.revision && (
				<p className="flavor-agent-card__description">
					Suggested wording: { issue.revision }
				</p>
			) }
		</div>
	);
}

export default function ContentRecommender() {
	const canRecommend = getSurfaceCapability( 'content' ).available;
	const {
		activityLog,
		contentError,
		contentErrorDetails,
		contentMode,
		contentRecommendation,
		contentRecommendationRequestSignature,
		contentRequestPrompt,
		contentStatus,
		postId,
		postType,
		postTitle,
		postExcerpt,
		postContent,
		postSlug,
		postStatus,
	} = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const store = select( STORE_NAME );

		return {
			activityLog: store.getActivityLog?.() || null,
			contentError: store.getContentError?.() || null,
			contentErrorDetails: store.getContentErrorDetails?.() || null,
			contentMode: store.getContentMode?.() || 'draft',
			contentRecommendation: store.getContentRecommendation?.() || null,
			contentRecommendationRequestSignature:
				store.getContentRecommendationRequestSignature?.() || '',
			contentRequestPrompt: store.getContentRequestPrompt?.() || '',
			contentStatus: store.getContentStatus?.() || 'idle',
			postId: editor?.getCurrentPostId?.() || null,
			postType: editor?.getCurrentPostType?.() || '',
			postTitle: editor?.getEditedPostAttribute?.( 'title' ) || '',
			postExcerpt: editor?.getEditedPostAttribute?.( 'excerpt' ) || '',
			postContent: editor?.getEditedPostAttribute?.( 'content' ) || '',
			postSlug: editor?.getEditedPostAttribute?.( 'slug' ) || '',
			postStatus: editor?.getEditedPostAttribute?.( 'status' ) || '',
		};
	}, [] );
	const { activityEntries, postContext } = useContentDerivedContext( {
		activityLog,
		postId,
		postType,
		title: postTitle,
		excerpt: postExcerpt,
		content: postContent,
		slug: postSlug,
		status: postStatus,
	} );
	const { clearContentError, fetchContentRecommendations, setContentMode } =
		useDispatch( STORE_NAME );
	const initialHasOutput =
		contentStatus === 'ready' &&
		hasRecommendationOutput( contentRecommendation );
	const [ prompt, setPrompt ] = useState( '' );
	const [ copiedContent, setCopiedContent ] = useState( '' );
	const [ isComposerOpen, setIsComposerOpen ] = useState(
		() => ! initialHasOutput
	);
	const hydratedSignatureRef = useRef( '' );

	useEffect( () => {
		if (
			contentStatus !== 'ready' ||
			! contentRecommendationRequestSignature
		) {
			return;
		}

		if (
			hydratedSignatureRef.current ===
			contentRecommendationRequestSignature
		) {
			return;
		}

		hydratedSignatureRef.current = contentRecommendationRequestSignature;
		setPrompt( contentRequestPrompt || '' );
	}, [
		contentRecommendationRequestSignature,
		contentRequestPrompt,
		contentStatus,
	] );
	const hasSupportedPost = SUPPORTED_POST_TYPES.has( postContext.postType );
	const freshness = useMemo(
		() =>
			getContentRecommendationFreshness( {
				contentRecommendation,
				storedRequestSignature: contentRecommendationRequestSignature,
				currentMode: contentMode,
				currentPrompt: prompt,
				currentPostContext: {
					postId,
					postType,
					title: postTitle,
					excerpt: postExcerpt,
					content: postContent,
					slug: postSlug,
					status: postStatus,
				},
				status: contentStatus,
			} ),
		[
			contentMode,
			contentRecommendation,
			contentRecommendationRequestSignature,
			contentStatus,
			postContent,
			postExcerpt,
			postId,
			postSlug,
			postStatus,
			postTitle,
			postType,
			prompt,
		]
	);
	const hasResult = freshness.hasStoredResult;
	const isStaleResult = freshness.isStaleResult;
	const hasOutput =
		hasResult && hasRecommendationOutput( contentRecommendation );
	useEffect( () => {
		if ( contentStatus !== 'ready' ) {
			return;
		}

		setIsComposerOpen( ! hasOutput );
	}, [ contentRecommendationRequestSignature, contentStatus, hasOutput ] );
	const statusNotice = useSelect(
		( select ) =>
			select( STORE_NAME ).getSurfaceStatusNotice( 'content', {
				requestStatus: contentStatus,
				requestError: contentError,
				requestErrorDetails: contentErrorDetails,
				isStale: isStaleResult,
				hasResult,
				hasSuggestions: hasOutput,
				emptyMessage:
					hasResult && ! hasOutput
						? 'No content recommendation was returned for the current request.'
						: '',
				onDismissAction: Boolean( contentError ),
			} ),
		[
			contentError,
			contentErrorDetails,
			contentStatus,
			hasOutput,
			hasResult,
			isStaleResult,
		]
	);
	const connectorApprovalNotice = useMemo(
		() => getConnectorApprovalNotice( 'content', contentErrorDetails ),
		[ contentErrorDetails ]
	);
	const handleFetch = useCallback( () => {
		fetchContentRecommendations( {
			mode: contentMode,
			prompt,
			postContext,
		} );
	}, [ contentMode, fetchContentRecommendations, postContext, prompt ] );
	const handleCopyContent = useCallback( async () => {
		const content = contentRecommendation?.content || '';
		const copied = await copyTextToClipboard( content );

		if ( copied ) {
			setCopiedContent( content );
		}
	}, [ contentRecommendation?.content ] );

	if ( ! hasSupportedPost ) {
		return null;
	}

	const activeMode = getContentModeConfig( contentMode );
	const documentTypeLabel =
		formatContextLabel( postContext.postType ) || 'Post';
	const documentStatusLabel = formatContextLabel( postContext.status );
	const documentNoun = documentTypeLabel.toLowerCase();
	const hasDocumentTitle = Boolean( postContext.title.trim() );
	const documentTitle = hasDocumentTitle
		? postContext.title.trim()
		: `Untitled ${ documentNoun }`;
	const generatedContent = String( contentRecommendation?.content || '' );
	const hasGeneratedContent = generatedContent.trim() !== '';
	const copyButtonLabel =
		hasGeneratedContent && copiedContent === generatedContent
			? 'Copied text'
			: 'Copy generated text';

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-content-recommender"
			title="Content Recommendations"
		>
			<div className="flavor-agent-panel flavor-agent-content-recommender">
				<div className="flavor-agent-content-recommender__context">
					<div
						className={ `flavor-agent-content-recommender__document-title${
							hasDocumentTitle ? '' : ' is-untitled'
						}` }
					>
						{ documentTitle }
					</div>
					<div className="flavor-agent-card__meta">
						<span className="flavor-agent-pill">
							{ documentTypeLabel }
						</span>
						{ documentStatusLabel && (
							<span className="flavor-agent-pill flavor-agent-pill--prominent">
								{ documentStatusLabel }
							</span>
						) }
					</div>
				</div>

				{ ! canRecommend && <CapabilityNotice surface="content" /> }

				{ canRecommend && (
					<>
						{ connectorApprovalNotice && (
							<CapabilityNotice
								surface="content"
								notice={ connectorApprovalNotice }
							/>
						) }
						<AIStatusNotice
							notice={
								connectorApprovalNotice ? null : statusNotice
							}
							onDismiss={ clearContentError }
						/>

						{ isStaleResult && (
							<StaleResultBanner
								message={ `This ${ documentNoun } changed since the last ${ activeMode.label.toLowerCase() } request — refresh before relying on the previous text.` }
								onRefresh={ handleFetch }
								isRefreshing={ contentStatus === 'loading' }
							/>
						) }

						{ hasOutput && (
							<ContentRequestSummary
								modeLabel={ activeMode.label }
								prompt={ prompt || contentRequestPrompt }
								isOpen={ isComposerOpen }
								onToggle={ () =>
									setIsComposerOpen(
										( currentValue ) => ! currentValue
									)
								}
							/>
						) }

						{ ( ! hasOutput || isComposerOpen ) && (
							<SurfaceComposer
								title={ activeMode.title }
								meta={
									<ButtonGroup
										className="flavor-agent-content-recommender__modes"
										aria-label="Content mode"
									>
										{ CONTENT_MODES.map( ( mode ) => (
											<Button
												key={ mode }
												className="flavor-agent-content-recommender__mode-option"
												variant="tertiary"
												isPressed={
													mode === contentMode
												}
												onClick={ () =>
													setContentMode( mode )
												}
											>
												{ formatModeLabel( mode ) }
											</Button>
										) ) }
									</ButtonGroup>
								}
								prompt={ prompt }
								onPromptChange={ setPrompt }
								label={ `What should Flavor Agent do with this ${ documentNoun }?` }
								hideLabelFromVision
								placeholder={ activeMode.placeholder }
								helperText={ activeMode.helperText }
								rows={ 3 }
								onFetch={ handleFetch }
								fetchLabel={ activeMode.fetchLabel }
								loadingLabel="Requesting content…"
								isLoading={ contentStatus === 'loading' }
								className="flavor-agent-content-recommender__composer"
								starterPrompts={ activeMode.starterPrompts }
							/>
						) }

						{ hasOutput && (
							<RecommendationHero
								eyebrow=""
								title={
									contentRecommendation?.title ||
									`${ formatModeLabel(
										contentRecommendation?.mode ||
											contentMode
									) } result`
								}
								description={
									contentRecommendation?.summary || ''
								}
								tone={ formatModeLabel(
									contentRecommendation?.mode || contentMode
								) }
								primaryActionLabel={
									hasGeneratedContent ? copyButtonLabel : ''
								}
								onPrimaryAction={
									hasGeneratedContent
										? handleCopyContent
										: undefined
								}
								primaryActionDisabled={
									isStaleResult && hasGeneratedContent
								}
								className="flavor-agent-content-recommender__result"
							>
								<ContentBody
									content={
										contentRecommendation?.content || ''
									}
								/>
							</RecommendationHero>
						) }

						{ ( Array.isArray( contentRecommendation?.notes ) &&
							contentRecommendation.notes.length > 0 ) ||
						( Array.isArray( contentRecommendation?.issues ) &&
							contentRecommendation.issues.length > 0 ) ? (
							<AIAdvisorySection
								title="Editorial Notes"
								advisoryLabel="Review"
								count={
									( contentRecommendation?.notes || [] )
										.length +
									( contentRecommendation?.issues || [] )
										.length
								}
								countNoun="note"
								initialOpen={ false }
								maxVisible={ 3 }
								className="flavor-agent-content-recommender__editorial-notes"
							>
								{ ( contentRecommendation?.notes || [] ).map(
									( note, index ) => (
										<div
											className="flavor-agent-card flavor-agent-content-recommender__note-card"
											key={ `note-${ index }` }
										>
											<p className="flavor-agent-card__description">
												{ note }
											</p>
										</div>
									)
								) }
								{ ( contentRecommendation?.issues || [] ).map(
									( issue, index ) => (
										<ContentIssueCard
											key={ `issue-${ index }` }
											issue={ issue }
											compact
										/>
									)
								) }
							</AIAdvisorySection>
						) : null }

						<AIActivitySection
							title="Recent Content Requests"
							description={ `Recent requests for this ${ documentNoun }.` }
							entries={ activityEntries }
							initialOpen={ false }
							maxVisible={ 1 }
						/>
					</>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}
