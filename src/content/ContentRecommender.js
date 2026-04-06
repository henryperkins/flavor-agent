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
import SurfaceScopeBar from '../components/SurfaceScopeBar';
import { STORE_NAME } from '../store';
import { getSurfaceCapability } from '../utils/capability-flags';

const SUPPORTED_POST_TYPES = new Set( [ 'post', 'page' ] );
const CONTENT_MODES = [ 'draft', 'edit', 'critique' ];

function formatModeLabel( mode = '' ) {
	switch ( mode ) {
		case 'edit':
			return 'Edit';
		case 'critique':
			return 'Critique';
		default:
			return 'Draft';
	}
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
			)) }
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
				<p className="flavor-agent-card__description">{ issue.problem }</p>
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
	const {
		clearContentError,
		fetchContentRecommendations,
		setContentMode,
	} = useDispatch( STORE_NAME );
	const [ prompt, setPrompt ] = useState( '' );
	const hasSupportedPost =
		Boolean( postContext.postId ) &&
		SUPPORTED_POST_TYPES.has( postContext.postType );
	const hasResult =
		contentStatus === 'ready' && Boolean( contentRecommendation );
	const hasOutput = hasResult && hasRecommendationOutput( contentRecommendation );
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
	const handleFetch = useCallback(
		() => {
			fetchContentRecommendations( {
				mode: contentMode,
				prompt,
				postContext: {
					postType: postContext.postType,
					title: postContext.title,
					excerpt: postContext.excerpt,
					content: postContext.content,
					slug: postContext.slug,
					status: postContext.status,
				},
			} );
		},
		[ contentMode, fetchContentRecommendations, postContext, prompt ]
	);

	if ( ! hasSupportedPost ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="flavor-agent-content-recommender"
			title="Content Recommendations"
		>
			<SurfacePanelIntro
				eyebrow="Content Recommendations"
				introCopy="Draft, edit, or critique the current post without leaving the editor. Flavor Agent records each request in the scoped activity history, including failed attempts."
				meta={
					<span className="flavor-agent-pill">
						{ postContext.postType }
					</span>
				}
			/>

			{ ! canRecommend && <CapabilityNotice surface="content" /> }

			{ canRecommend && (
				<>
					<SurfaceScopeBar
						scopeLabel="Current document"
						scopeValue={ postContext.title || `#${ postContext.postId }` }
						meta={ formatModeLabel( contentMode ) }
					/>
					<div className="flavor-agent-panel__group flavor-agent-panel__group-body">
						<ButtonGroup className="flavor-agent-panel__composer-starters">
							{ CONTENT_MODES.map( ( mode ) => (
								<Button
									key={ mode }
									variant={
										mode === contentMode ? 'primary' : 'secondary'
									}
									onClick={ () => setContentMode( mode ) }
								>
									{ formatModeLabel( mode ) }
								</Button>
							) ) }
						</ButtonGroup>
					</div>
					<SurfaceComposer
						prompt={ prompt }
						onPromptChange={ setPrompt }
						label="What should Flavor Agent do with this post?"
						placeholder="Ask for a new draft, a tighter edit, or a critique of the current content."
						helperText="Draft can work from a title or brief. Edit and critique work best when the current post content already has material to work from."
						rows={ 4 }
						onFetch={ handleFetch }
						fetchLabel={ `${ formatModeLabel( contentMode ) } Content` }
						loadingLabel="Requesting content…"
						isLoading={ contentStatus === 'loading' }
						starterPrompts={ [
							'Draft an opening that gets to the point faster.',
							'Edit this post for tighter rhythm and cleaner transitions.',
							'Critique the current draft and flag any lines that drift.',
						] }
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
									contentRecommendation?.mode || contentMode
								) } result`
							}
							description={ contentRecommendation?.summary || '' }
							tone={ formatModeLabel(
								contentRecommendation?.mode || contentMode
							) }
						>
							<ContentBody content={ contentRecommendation?.content || '' } />
						</RecommendationHero>
					)}

					{ ( Array.isArray( contentRecommendation?.notes ) &&
						contentRecommendation.notes.length > 0 ) ||
					( Array.isArray( contentRecommendation?.issues ) &&
						contentRecommendation.issues.length > 0 ) ? (
						<AIAdvisorySection
							title="Editorial Notes"
							advisoryLabel="Review"
							count={
								( contentRecommendation?.notes || [] ).length +
								( contentRecommendation?.issues || [] ).length
							}
							countNoun="note"
							initialOpen={ true }
						>
							{ ( contentRecommendation?.notes || [] ).map( ( note, index ) => (
								<div className="flavor-agent-card" key={ `note-${ index }` }>
									<p className="flavor-agent-card__description">{ note }</p>
								</div>
							) ) }
							{ ( contentRecommendation?.issues || [] ).map( ( issue, index ) => (
								<ContentIssueCard key={ `issue-${ index }` } issue={ issue } />
							) ) }
						</AIAdvisorySection>
					) : null }

					<AIActivitySection
						title="Recent Content Requests"
						description="Flavor Agent keeps request-only history for this document, including failed requests that still need attention."
						entries={ activityEntries }
						initialOpen={ false }
						maxVisible={ 4 }
					/>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}
