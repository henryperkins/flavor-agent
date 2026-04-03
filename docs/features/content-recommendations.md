# Content Recommendations

## 1. Exact User-Facing Surface

There is no first-party Gutenberg panel for this lane yet.

The current scaffold is programmatic:

- REST: `POST /flavor-agent/v1/recommend-content`
- Ability: `flavor-agent/recommend-content`

This exists so a future post-editor UI, external agent, or admin tool can attach to a stable contract instead of inventing one later.

## 2. Surfacing And Gating Conditions

- Requires `edit_posts`
- Requires a compatible chat backend through `Settings > Flavor Agent` or `Settings > Connectors`
- `draft` mode accepts a prompt, title, or other working context
- `edit` and `critique` modes require `postContext.content`

## 3. End-To-End Flow

1. A caller posts `mode`, optional `voiceProfile`, optional `prompt`, and optional `postContext`
2. `Agent_Controller::handle_recommend_content()` forwards that payload to `ContentAbilities::recommend_content()`
3. `ContentAbilities` normalizes the request, validates required content for `edit` and `critique`, and calls `ChatClient`
4. `WritingPrompt` builds the Henry-voice system prompt plus the request-specific user prompt
5. `WritingPrompt::parse_response()` validates the returned JSON payload

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
- No first-party UI is shipped yet, so there is no preview/apply/undo flow tied to this scaffold.

## 6. Primary Functions, Routes, And Abilities

- `inc/REST/Agent_Controller.php`
- `inc/Abilities/ContentAbilities.php`
- `inc/LLM/WritingPrompt.php`
- `flavor-agent/recommend-content`
- `POST /flavor-agent/v1/recommend-content`
