import { Button, ButtonGroup } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useState } from '@wordpress/element';

import AIActivitySection from '../components/AIActivitySection';
import AIAdvisorySection from '../components/AIAdvisorySection';
import AIStatusNotice from '../components/AIStatusNotice';
import CapabilityNotice from '../components/CapabilityNotice';
import RecommendationHero from '../components/RecommendationHero';
import SurfaceComposer from '../components/SurfaceComposer';
import SurfacePanelIntro from '../components/SurfacePanelIntro';
import { STORE_NAME } from '../store';
import { getSurfaceCapability } from '../utils/capability-flags';

const SUPPORTED_POST_TYPES = new Set( [ 'post', 'page' ] );
const CONTENT_MODES = [ 'draft', 'edit', 'critique' ];
const CONTENT_MODE_CONFIG = {
	draft: {
		label: 'Draft',
		title: 'Start a fresh draft',
		placeholder:
			'Describe the draft you want (for example: concise launch post for store managers).',
		helperText: 'Works from a title, short brief, or rough outline.',
		fetchLabel: 'Generate Draft',
		starterPrompts: [
			'Draft an opening that gets to the point faster.',
			'Sketch a sharper structure for this piece.',
			'Write a cleaner closing section.',
		],
	},
	edit: {
		label: 'Edit',
		title: 'Refine what is already here',
		placeholder:
			'Describe the revision pass (for example: tighten intro and trim repetition).',
		helperText: 'Best when this post already has copy you want to tighten.',
		fetchLabel: 'Revise Draft',
		starterPrompts: [
			'Tighten the pacing and transitions.',
			'Cut repetition and sharpen the voice.',
			'Make each section pull its weight.',
		],
	},
	critique: {
		label: 'Critique',
		title: 'Stress-test the draft',
		placeholder:
			'Describe the critique focus (for example: clarity gaps and weak transitions).',
		helperText: 'Flags weak lines, clarity gaps, and structural drift.',
		fetchLabel: 'Run Critique',
		starterPrompts: [
			'Point out the weakest lines and why they miss.',
			'Critique the structure for drift or repetition.',
			'Flag anything that sounds vague or generic.',
		],
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

function ContentIssueCard( { issue = {} } ) {
	if ( ! issue?.original && ! issue?.problem && ! issue?.revision ) {
		return null;
	}

	return (
		<div className="flavor-agent-card">
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
					Suggested rewrite: { issue.revision }
				</p>
			) }
		</div>
	);
}

export default function ContentRecommender() {
	const canRecommend = getSurfaceCapability( 'content' ).available;
	const {
		activityEntries,
		contentError,
		contentMode,
		contentRecommendation,
		contentStatus,
		postContext,
	} = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const store = select( STORE_NAME );

		return {
			activityEntries: ( store.getActivityLog?.() || [] )
				.filter( ( entry ) => entry?.surface === 'content' )
				.reverse(),
			contentError: store.getContentError?.() || null,
			contentMode: store.getContentMode?.() || 'draft',
			contentRecommendation: store.getContentRecommendation?.() || null,
			contentStatus: store.getContentStatus?.() || 'idle',
			postContext: {
				postId: editor?.getCurrentPostId?.() || null,
				postType: editor?.getCurrentPostType?.() || '',
				title: editor?.getEditedPostAttribute?.( 'title' ) || '',
				excerpt: editor?.getEditedPostAttribute?.( 'excerpt' ) || '',
				content: editor?.getEditedPostAttribute?.( 'content' ) || '',
				slug: editor?.getEditedPostAttribute?.( 'slug' ) || '',
				status: editor?.getEditedPostAttribute?.( 'status' ) || '',
			},
		};
	}, [] );
	const { clearContentError, fetchContentRecommendations, setContentMode } =
		useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const hasSupportedPost = SUPPORTED_POST_TYPES.has( postContext.postType );
	const hasResult =
		contentStatus === 'ready' && Boolean( contentRecommendation );
	const hasOutput =
		hasResult && hasRecommendationOutput( contentRecommendation );
	const statusNotice = useSelect(
		( select ) =>
			select( STORE_NAME ).getSurfaceStatusNotice( 'content', {
				requestStatus: contentStatus,
				requestError: contentError,
				hasResult,
				hasSuggestions: hasOutput,
				emptyMessage:
					hasResult && ! hasOutput
						? 'No content recommendation was returned for the current request.'
						: '',
				onDismissAction: Boolean( contentError ),
			} ),
		[ contentError, contentStatus, hasOutput, hasResult ]
	);
	const handleFetch = useCallback( () => {
		fetchContentRecommendations( {
			mode: contentMode,
			prompt,
			postContext: {
				postId: postContext.postId,
				postType: postContext.postType,
				title: postContext.title,
				excerpt: postContext.excerpt,
				content: postContext.content,
				slug: postContext.slug,
				status: postContext.status,
			},
		} );
	}, [ contentMode, fetchContentRecommendations, postContext, prompt ] );

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

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-content-recommender"
			title="Content Recommendations"
		>
			<div className="flavor-agent-panel flavor-agent-content-recommender">
				<SurfacePanelIntro
					eyebrow={ documentTypeLabel }
					introCopy="Draft from a brief, tighten the current copy, or run a critique without leaving the editor."
					meta={
						documentStatusLabel ? (
							<span className="flavor-agent-pill flavor-agent-pill--prominent">
								{ documentStatusLabel }
							</span>
						) : null
					}
				>
					<div
						className={ `flavor-agent-content-recommender__document-title${
							hasDocumentTitle ? '' : ' is-untitled'
						}` }
					>
						{ documentTitle }
					</div>
				</SurfacePanelIntro>

				{ ! canRecommend && <CapabilityNotice surface="content" /> }

				{ canRecommend && (
					<>
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
											isPressed={ mode === contentMode }
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
							placeholder={ activeMode.placeholder }
							helperText={ activeMode.helperText }
							rows={ 4 }
							onFetch={ handleFetch }
							fetchLabel={ activeMode.fetchLabel }
							loadingLabel="Requesting content…"
							isLoading={ contentStatus === 'loading' }
							className="flavor-agent-content-recommender__composer"
							starterPromptsLayout="stacked"
							starterPrompts={ activeMode.starterPrompts }
						/>
						<AIStatusNotice
							notice={ statusNotice }
							onDismiss={ clearContentError }
						/>

						{ hasResult && (
							<RecommendationHero
								eyebrow="Latest Content Recommendation"
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
								initialOpen={ true }
							>
								{ ( contentRecommendation?.notes || [] ).map(
									( note, index ) => (
										<div
											className="flavor-agent-card"
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
										/>
									)
								) }
							</AIAdvisorySection>
						) : null }

						<AIActivitySection
							title="Recent Content Requests"
							description={ `Flavor Agent keeps request history for this ${ documentNoun }, including failed attempts.` }
							entries={ activityEntries }
							initialOpen={ false }
							maxVisible={ 4 }
						/>
					</>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}
