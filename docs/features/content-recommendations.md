# Content Recommendations

## 1. Exact User-Facing Surface

Flavor Agent ships a first-party post/page document panel for this lane.

The current surface and contract are:

- UI: post/page editor `PluginDocumentSettingPanel` titled `Content Recommendations`
- REST: `POST /flavor-agent/v1/recommend-content`
- Ability: `flavor-agent/recommend-content`

The REST + Abilities contract remains available so external agents or admin tools can use the same drafting, editing, and critique path.

## 2. Surfacing And Gating Conditions

- Requires `edit_posts`; when `postContext.postId` is positive, also requires `edit_post` for that post
- Requires a compatible text-generation provider through `Settings > Connectors`
- The first-party panel renders only for posts and pages
- `draft` mode accepts a prompt, title, or other working context
- `edit` and `critique` modes require `postContext.content`

## 3. End-To-End Flow

1. `ContentRecommender()` reads the current post/page ID, post context, selected content mode, and prompt from the editor
2. The store action `fetchContentRecommendations()` posts `mode`, optional `prompt`, and `postContext` to `POST /flavor-agent/v1/recommend-content`
3. `Agent_Controller::handle_recommend_content()` forwards that payload to `ContentAbilities::recommend_content()`
4. `ContentAbilities` normalizes the request, validates per-post edit access when `postContext.postId > 0`, and renders the current post content through `PostContentRenderer`
5. `WritingPrompt` builds the Henry-voice system prompt plus the request-specific user prompt via `PromptBudget`. The user prompt includes the rendered current-post text under `Existing draft` and, when the author has eligible same-author published posts in the same post type, a `## Site voice samples` section with up to three openings (~1500 chars each, paragraph-snapped) drawn through `PostVoiceSampleCollector`. Voice samples is the only section the budget will drop under pressure. When rendered HTML exposes useful attribute-borne text that would otherwise be stripped from the current post, `PostContentRenderer` appends it as an `[Attribute references]` bullet list.
6. `WritingPrompt::parse_response()` validates the returned JSON payload
7. The panel renders summary/content/notes/issues through the shared status and recommendation shell, with explicit editorial-only copy and a copy-to-clipboard handoff for generated text
8. When document scope is available, the REST handler persists successful and failed requests as read-only `request_diagnostic` activity rows, and the panel shows them in `Recent Content Requests`

## 4. Capability Contract

Input:

- `mode`: `draft`, `edit`, or `critique`
- `prompt`: optional writing instruction
- `voiceProfile`: optional extra voice notes
- `postContext`: optional post metadata and draft content. `postId` is optional; a positive ID enables server-side block rendering after an `edit_post` check, while absent or `0` uses the text fallback path.

Model-facing draft context:

- Rendered visible text is emitted first.
- Attribute-borne strings from `alt`, `title`, `aria-label`, and allowed `href` values are appended as `[Attribute references]` when they are not already present in the visible text.
- Top-level `core/post-title`, `core/post-excerpt`, and `core/post-content` self-references are intercepted so staged editor values are used and recursive post-content rendering is avoided.

Output:

- `mode`
- `title`
- `summary`
- `content`
- `notes[]`
- `issues[]` with `original`, `problem`, and `revision`

## 5. Guardrails And Failure Modes

- The lane is editorial-only. It does not mutate post content on its own.
- The first-party UI labels results as generated content guidance, not automatic patches.
- Generated draft/edit output includes a manual handoff: users can copy generated text and paste/selectively adapt it in the editor themselves.
- `edit` and `critique` return a `missing_existing_content` error when no draft is provided.
- A positive `postContext.postId` without `edit_post` access returns `rest_forbidden_context`.
- Current-post block rendering is postId-gated. Unsaved posts and external callers that omit `postId` keep the fallback text path and do not execute block render callbacks.
- Self-reference substitution is top-level only. If a `core/post-title` or `core/post-excerpt` block is nested inside another block, WordPress may render the saved value rather than the staged editor value.
- Attribute extraction is capped by count and per-value length. Layer 1 does not yet cap rendered visible text; the deferred `PromptBudget` follow-up should own that before broader Layer 2/3 context expansion.
- Voice samples are same-author, publish-only, password-protected-excluded, and `read_post`-gated per candidate. Render failures for an individual sample drop only that sample, never the parent recommendation.
- The `## Site voice samples` section is omitted entirely when no candidates qualify: new authors, unsupported post types, all candidates filtered out, or all renders failed.
- Sites can tune the prompt token budget via the `flavor_agent_prompt_budget_max_tokens` filter, scope `content`.
- Invalid model JSON returns a `parse_error`.
- The first-party UI is editorial-only. There is no preview/apply/undo flow tied to this surface, and copy-to-clipboard does not mutate editor content.
- Scoped content requests are persisted as read-only activity diagnostics when possible. They can appear in the inline request history and the admin audit page, but they are not undoable.

## 6. Primary Functions, Routes, And Abilities

- `inc/REST/Agent_Controller.php`
- `inc/Abilities/ContentAbilities.php`
- `inc/Context/PostContentRenderer.php`
- `inc/Context/PostVoiceSampleCollector.php`
- `inc/Context/ServerCollector.php`
- `inc/LLM/WritingPrompt.php`
- `src/content/ContentRecommender.js`
- `src/store/index.js`
- `flavor-agent/recommend-content`
- `POST /flavor-agent/v1/recommend-content`
