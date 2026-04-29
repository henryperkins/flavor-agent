# Content Recommendations

## 1. Exact User-Facing Surface

Flavor Agent ships a first-party post/page document panel for this lane.

The current surface and contract are:

- UI: post/page editor `PluginDocumentSettingPanel` titled `Content Recommendations`
- REST: `POST /flavor-agent/v1/recommend-content`
- Ability: `flavor-agent/recommend-content`

The REST + Abilities contract remains available so external agents or admin tools can use the same drafting, editing, and critique path.

## 2. Surfacing And Gating Conditions

- Requires `edit_posts`
- Requires a compatible text-generation provider through `Settings > Connectors`
- The first-party panel renders only for posts and pages
- `draft` mode accepts a prompt, title, or other working context
- `edit` and `critique` modes require `postContext.content`

## 3. End-To-End Flow

1. `ContentRecommender()` reads the current post/page context, selected content mode, and prompt from the editor
2. The store action `fetchContentRecommendations()` posts `mode`, optional `prompt`, and `postContext` to `POST /flavor-agent/v1/recommend-content`
3. `Agent_Controller::handle_recommend_content()` forwards that payload to `ContentAbilities::recommend_content()`
4. `ContentAbilities` normalizes the request, validates required content for `edit` and `critique`, and calls `ChatClient`
5. `WritingPrompt` builds the Henry-voice system prompt plus the request-specific user prompt
6. `WritingPrompt::parse_response()` validates the returned JSON payload
7. The panel renders summary/content/notes/issues through the shared status and recommendation shell, without mutating post content

## 4. Capability Contract

Input:

- `mode`: `draft`, `edit`, or `critique`
- `prompt`: optional writing instruction
- `voiceProfile`: optional extra voice notes
- `postContext`: optional post metadata and draft content

Output:

- `mode`
- `title`
- `summary`
- `content`
- `notes[]`
- `issues[]` with `original`, `problem`, and `revision`

## 5. Guardrails And Failure Modes

- The lane is editorial-only. It does not mutate post content on its own.
- `edit` and `critique` return a `missing_existing_content` error when no draft is provided.
- Invalid model JSON returns a `parse_error`.
- The first-party UI is editorial-only. There is no preview/apply/undo flow tied to this surface.

## 6. Primary Functions, Routes, And Abilities

- `inc/REST/Agent_Controller.php`
- `inc/Abilities/ContentAbilities.php`
- `inc/LLM/WritingPrompt.php`
- `src/content/ContentRecommender.js`
- `src/store/index.js`
- `flavor-agent/recommend-content`
- `POST /flavor-agent/v1/recommend-content`
