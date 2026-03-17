# Recommend Template: LLM-Powered Template Composition Guidance

## Goal

Fill the `flavor-agent/recommend-template` 501 stub so it returns template composition suggestions — which template parts to assign to which areas, which patterns fit the template type — and surface those suggestions in a `PluginDocumentSettingPanel` in the Site Editor's Template tab.

## Architecture

Uses the Azure OpenAI chat deployment (`ResponsesClient::rank`). No embeddings or Qdrant — pattern candidates come from the WordPress pattern registry filtered by `templateTypes`. Gated on Azure chat options only (`endpoint`, `key`, `chat_deployment`), independent of the full pattern-recommendation stack.

```
  recommend-block (Anthropic)     recommend-patterns (Azure + Qdrant)     recommend-template (Azure chat only)
  ─────────────────────────────   ───────────────────────────────────     ────────────────────────────────────
  Client::chat()                  EmbeddingClient + QdrantClient          ResponsesClient::rank()
  Block Inspector panel           + ResponsesClient::rank()               PluginDocumentSettingPanel
                                  Inserter "Recommended" category         Site Editor Template tab
```

### Pipeline

```
  JS: TemplateRecommender.js
    ↓  POST /flavor-agent/v1/recommend-template
       { templateRef, templateType?, prompt? }
    ↓
  PHP: Agent_Controller → TemplateAbilities::recommend_template()
    ↓
  ServerCollector::for_template( $template_ref, $template_type )
    → resolve the edited template from the canonical ref, with slug fallback
    → parse_blocks() recursively to find assigned core/template-part blocks
    → for_template_parts() for all available parts
    → collect typed + generic pattern candidates from the registry
    → for_tokens() for theme design tokens
    ↓
  TemplatePrompt::build_system() + build_user( $context, $prompt )
    ↓
  ResponsesClient::rank( $system, $user )
    ↓
  TemplatePrompt::parse_response( $raw )
    ↓
  Return { suggestions, explanation }
```

## Backend

### Schema migration note

The current stub registers `templateType` as the sole required input field. This spec changes the required field to `templateRef` and demotes `templateType` to optional. Since the stub has always returned 501, no external consumer can currently depend on the old schema. This is a safe migration of an unimplemented contract.

### `ServerCollector::for_template( string $template_ref, ?string $template_type = null ): array|\WP_Error`

New public static method in `inc/Context/ServerCollector.php`.

**Parameters:**
- `$template_ref` — Template identifier from the Site Editor (`getEditedPostId()`). Treated as an opaque reference until runtime verification confirms it is a plain slug. If confirmed, can be renamed to `$template_slug` in a later iteration.
- `$template_type` — Optional normalized template type (e.g. `'single'`, `'page'`, `'404'`). Used for pattern filtering. If null, derived from `$template_ref` after stripping any `theme//` prefix and normalizing the remaining slug against the known type vocabulary.

**Template lookup:** Keep `$template_ref` intact for lookup. If it is already a canonical template ID (for example `theme-slug//single`), resolve it directly with `get_block_template()`. Only fall back to a slug-based list query when the ref is not already canonical:
```php
$template = null;

if ( str_contains( $template_ref, '//' ) ) {
    $template = get_block_template( $template_ref, 'wp_template' );
}

if ( ! $template ) {
    $slug = str_contains( $template_ref, '//' )
        ? substr( $template_ref, strpos( $template_ref, '//' ) + 2 )
        : $template_ref;

    $templates = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' );
    $template  = $templates[0] ?? null;
}

if ( ! $template ) {
    return new \WP_Error(
        'template_not_found',
        'Could not resolve the current template from the Site Editor context.',
        [ 'status' => 404 ]
    );
}
```
This keeps the unique identifier path authoritative while still supporting runtime shapes where `getEditedPostId()` yields only a slug.

**Returns:**
```php
[
    'templateRef'    => 'single-post-my-custom',
    'templateType'   => 'single',
    'title'          => 'Single Posts',
    'assignedParts'  => [
        [ 'slug' => 'header', 'area' => 'header' ],
        [ 'slug' => 'footer', 'area' => 'footer' ],
    ],
    'emptyAreas'     => [ 'sidebar' ],
    'availableParts' => [ /* from for_template_parts() */ ],
    'patterns'       => [ /* typed matches + generic fallbacks from the pattern registry */ ],
    'themeTokens'    => [ /* from for_tokens() */ ],
]
```

**`emptyAreas` computation:** Set difference between areas defined by available template parts and areas already assigned:
```php
$assigned_areas = array_unique( array_column( $assigned_parts, 'area' ) );
$known_areas    = array_unique( array_column( $available_parts, 'area' ) );
$empty_areas    = array_values( array_diff( $known_areas, $assigned_areas ) );
```
Limitation: custom areas not represented by any existing template part will not appear. Areas are theme-defined and discovered from the parts registry, not a fixed vocabulary.

**Internal helpers:**
- `extract_assigned_parts( array $blocks, array $part_area_lookup ): array` — Walks the full parsed block tree recursively, not just top-level blocks. Collects every `core/template-part` block, resolves `slug`, and derives `area` from block attrs when present or from the available-parts lookup keyed by slug.
- `derive_template_type( string $ref ): ?string` — Normalizes a template ref to the known type vocabulary (`index`, `home`, `front-page`, `singular`, `single`, `page`, `archive`, `author`, `category`, `tag`, `taxonomy`, `date`, `search`, `404`). Returns null if no match.
- `get_known_areas( array $available_parts ): string[]` — Collects the distinct `area` values from available template parts, representing the areas the theme defines.
- `collect_template_candidate_patterns( ?string $template_type ): array` — Keeps generic patterns eligible. When `$template_type` is known, include patterns whose `templateTypes` contain it **or** whose `templateTypes` array is empty. Sort typed matches ahead of generic fallbacks, dedupe by `name`, and return the union.

**Pattern candidate selection:** Do not hard-filter to template-typed patterns only. This spec intentionally mirrors the existing recommend-patterns behavior that keeps generic patterns eligible even when `templateType` is present. If the template type is unknown, pass all registry patterns through; if it is known, prefer typed matches but keep generic patterns in the candidate set.

### `TemplatePrompt` (`inc/LLM/TemplatePrompt.php`)

New class, PSR-4 autoloaded under `FlavorAgent\LLM`. Follows the same static-method pattern as `Prompt.php`.

**`build_system(): string`**

Heredoc system prompt. Key instructions:
- Return JSON: `{ "suggestions": [...], "explanation": "..." }`
- Each suggestion: `{ "label": string, "description": string, "templateParts": [{ "slug": string, "area": string, "reason": string }], "patternSuggestions": [string] }`
- `templateParts[].slug` must be a slug from the `availableParts` array (canonical ID, not display name)
- `patternSuggestions[]` must be registered pattern `name` values from the `patterns` array (canonical ID)
- Prioritize empty areas — suggest parts for areas that have no assignment
- Respect theme tokens — suggest patterns that use the theme's design language
- If no candidate patterns are available after typed + generic collection, focus suggestions on template part composition only and leave `patternSuggestions` empty
- No markdown fences, no text outside the JSON object

**`build_user( array $context, string $prompt = '' ): string`**

Markdown sections:
- `## Template` — type, title, current assignment summary
- `## Assigned Template Parts` — slug + area for each
- `## Empty Areas` — areas with no part
- `## Available Template Parts` — slug, title, area for each unused part
- `## Available Patterns` — name, title, description, and whether the pattern is a typed match or generic fallback. If the list is large, keep typed matches first and truncate generic fallbacks last.
- `## Theme Tokens` — compact token summary (colors, fonts, spacing)
- `## User Instruction` — the optional prompt, or "Suggest improvements for this template."

**`parse_response( string $raw ): array|\WP_Error`**

Strips markdown fences with the same regex as `Prompt::parse_response()`. JSON-decodes. Validates:
- Top-level has `suggestions` (array) and `explanation` (string)
- Each suggestion has `label` (string), `description` (string)
- `templateParts` if present is an array of objects with `slug` (required string), `area` (required string), `reason` (optional string, defaults to `''`)
- `patternSuggestions` if present is an array of strings
- All string values pass through `sanitize_text_field()`

Returns `WP_Error( 'parse_error', ... )` on failure.

### `TemplateAbilities::recommend_template( array $input )`

Fill the 501 stub in `inc/Abilities/TemplateAbilities.php`.

1. Extract `$template_ref = $input['templateRef']`, `$template_type = $input['templateType'] ?? null`, `$prompt = $input['prompt'] ?? ''`
2. Validate `$template_ref` is non-empty
3. **No explicit config validation.** `ResponsesClient::rank()` already validates the three Azure chat options internally and returns `WP_Error( 'missing_credentials', ..., [ 'status' => 400 ] )` if any are missing. Duplicating the check here would create two error paths with different codes. Let the downstream client handle it.
4. `$context = ServerCollector::for_template( $template_ref, $template_type )` — return the `WP_Error` if lookup fails
5. `$system = TemplatePrompt::build_system()`
6. `$user = TemplatePrompt::build_user( $context, $prompt )`
7. `$result = ResponsesClient::rank( $system, $user )` — return the `WP_Error` if it fails
8. `$payload = TemplatePrompt::parse_response( $result )` — return the `WP_Error` if it fails
9. Return `$payload`

Return type changes from `\WP_Error` to `array|\WP_Error`.

### REST route

`POST /flavor-agent/v1/recommend-template` in `Agent_Controller::register_routes()`.

- Permission: `edit_theme_options`
- Params: `templateRef` (required string), `templateType` (optional string), `prompt` (optional string)
- `templateRef` must **not** use `sanitize_key()`, because canonical refs may contain `//`. Use `sanitize_text_field()` plus a non-empty validation callback so the handler preserves `theme-slug//template-slug` identifiers.
- Handler: thin adapter that assembles `$input` from request params and delegates to `TemplateAbilities::recommend_template( $input )`. Returns `new \WP_REST_Response( $result, 200 )` on success, consistent with `handle_recommend_patterns()`. **No inline LLM logic** — follows the recommend-patterns thin-adapter pattern, not the recommend-block inline anti-pattern.

### Schema update (`Registration.php`)

Update the `recommend-template` ability registration:

**Input schema:**
```php
'input_schema' => [
    'type'       => 'object',
    'properties' => [
        'templateRef'  => [ 'type' => 'string', 'description' => 'Template identifier from the Site Editor.' ],
        'templateType' => [ 'type' => 'string', 'description' => 'Normalized template type (single, page, 404, etc.). Derived from templateRef if absent.' ],
        'prompt'       => [ 'type' => 'string' ],
    ],
    'required' => [ 'templateRef' ],
],
```

**Output schema:**
```php
'output_schema' => [
    'type'       => 'object',
    'properties' => [
        'suggestions' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'label'              => [ 'type' => 'string' ],
                    'description'        => [ 'type' => 'string' ],
                    'templateParts'      => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'   => [ 'type' => 'string' ],
                                'area'   => [ 'type' => 'string' ],
                                'reason' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'patternSuggestions' => [
                        'type'  => 'array',
                        'items' => [ 'type' => 'string' ],
                    ],
                ],
            ],
        ],
        'explanation' => [ 'type' => 'string' ],
    ],
],
```

### `check-status` and localization

- `InfraAbilities::check_status()` adds `recommend-template` to `availableAbilities` when `endpoint` + `key` + `chat_deployment` are set (independent of Qdrant/embeddings).
- `flavor-agent.php` adds `canRecommendTemplates` to the localized `flavorAgentData` object, gated on the same three options.

## Frontend

### Store additions (`src/store/index.js`)

**State:**
```js
templateRecommendations: [],
templateExplanation: '',
templateStatus: 'idle',   // idle | loading | ready | error
templateError: null,
templateRef: null,
```

**Actions:**
- `setTemplateStatus( status, error = null )` → `{ type: 'SET_TEMPLATE_STATUS', status, error }`
- `setTemplateRecommendations( templateRef, payload )` → `{ type: 'SET_TEMPLATE_RECS', templateRef, payload }`
- `fetchTemplateRecommendations( input )` — async thunk with an `AbortController` stored on `actions._templateAbort`, mirroring the existing pattern-request lifecycle. Aborts any in-flight template request, calls `POST /flavor-agent/v1/recommend-template`, and dispatches the response tagged with `input.templateRef`.
- `clearTemplateRecommendations()` → `{ type: 'CLEAR_TEMPLATE_RECS' }` — resets recommendations, explanation, error, and stored `templateRef`

**Selectors:**
- `getTemplateRecommendations( state )` → `state.templateRecommendations`
- `getTemplateExplanation( state )` → `state.templateExplanation`
- `getTemplateError( state )` → `state.templateError`
- `getTemplateResultRef( state )` → `state.templateRef`
- `isTemplateLoading( state )` → `state.templateStatus === 'loading'`
- `getTemplateStatus( state )` → `state.templateStatus`

**Reducer cases:**
- `SET_TEMPLATE_STATUS` — updates `templateStatus` and `templateError`
- `SET_TEMPLATE_RECS` — updates `templateRecommendations`, `templateExplanation`, `templateRef`, clears `templateError`, sets `templateStatus: 'ready'`
- `CLEAR_TEMPLATE_RECS` — resets `templateRecommendations: []`, `templateExplanation: ''`, `templateStatus: 'idle'`, `templateError: null`, `templateRef: null`

**Race/staleness guard:** The template request lifecycle must be isolated from block and pattern state, just like `patternStatus` is today. The thunk uses `AbortController` to cancel stale in-flight requests, and the reducer stores the `templateRef` that produced the current results. `TemplateRecommender` only renders explanation/cards when the stored `templateRef` matches the currently edited template.

### Shared util (`src/utils/template-types.js`)

Extract from `PatternRecommender.js`:
- `KNOWN_TEMPLATE_TYPES` set
- `normalizeTemplateType( slug )` function

Both `PatternRecommender.js` and `TemplateRecommender.js` import from this shared module. `PatternRecommender.js` is updated to import from the new location (no behavior change).

### `TemplateRecommender.js` (`src/templates/TemplateRecommender.js`)

Renders a `PluginDocumentSettingPanel` in the Template tab of the Site Editor sidebar.

**Gating:** Returns `null` when:
- `flavorAgentData.canRecommendTemplates` is false
- Not in the Site Editor (`core/edit-site` store unavailable)
- Not editing a `wp_template` (checked via `editSite.getEditedPostType()`)

**Template detection:**
- `templateRef` from `editSite.getEditedPostId()` (opaque identifier)
- `templateType` from `normalizeTemplateType( templateRef )` (may be undefined)

**Stale recommendation guard:** When `templateRef` changes (user navigates to a different template), dispatch `clearTemplateRecommendations()`. This ensures the panel never shows cards from a previously edited template.

**Request behavior:**
- Clicking `Get Suggestions` dispatches `fetchTemplateRecommendations( { templateRef, templateType, prompt } )`
- If the user switches templates mid-request, the component clears local results and the store aborts the in-flight request
- The component reads `getTemplateResultRef()` and only renders results when it matches the current `templateRef`

**UI structure:**
```
PluginDocumentSettingPanel title="AI Template Recommendations"
  ├─ <textarea> "What are you trying to achieve with this template?"
  ├─ <Button> "Get Suggestions" (disabled while loading)
  ├─ <Notice status="info"> (if loading)
  ├─ <Notice status="error"> (if request fails)
  ├─ explanation text (if results)
  └─ suggestion cards (if results):
       ├─ label + description
       ├─ "Template Parts" section:
       │    slug → area (reason)
       └─ "Suggested Patterns" section:
            resolved pattern titles when available from editor settings,
            falling back to canonical pattern names (informational, not clickable)
```

### Entry point (`src/index.js`)

Add `<TemplateRecommender />` to the `registerPlugin` render:
```jsx
import TemplateRecommender from './templates/TemplateRecommender';

registerPlugin( 'flavor-agent', {
    render: () => (
        <>
            <PatternRecommender />
            <InserterBadge />
            <TemplateRecommender />
        </>
    ),
} );
```

## Explicitly out of scope

- **One-click pattern insertion** — Pattern names in suggestions are informational. Clickable insertion is a separate follow-up task with its own fragility concerns.
- **Template part auto-assignment** — Programmatic template part swapping is complex and risky. Suggestions are advisory.
- **Qdrant vector search** — Template recommendations use direct pattern registry filtering, not embeddings.
- **`templateRef` → `templateSlug` rename** — Deferred until runtime verification confirms the identifier shape.

## File inventory

| Action | Path | Purpose |
|--------|------|---------|
| Create | `inc/LLM/TemplatePrompt.php` | System/user prompt assembly + response parsing |
| Create | `src/templates/TemplateRecommender.js` | Site Editor panel component |
| Create | `src/utils/template-types.js` | Shared `KNOWN_TEMPLATE_TYPES` + `normalizeTemplateType` |
| Modify | `inc/Context/ServerCollector.php` | Add `for_template()` + helpers |
| Modify | `inc/Abilities/TemplateAbilities.php` | Fill 501 stub |
| Modify | `inc/Abilities/Registration.php` | Expand input/output schemas |
| Modify | `inc/Abilities/InfraAbilities.php` | Add to `check-status` |
| Modify | `inc/REST/Agent_Controller.php` | Add thin REST route |
| Modify | `flavor-agent.php` | Add `canRecommendTemplates` localization |
| Modify | `src/store/index.js` | Template state, actions, selectors, reducer |
| Modify | `src/patterns/PatternRecommender.js` | Import from shared util |
| Modify | `src/index.js` | Register `<TemplateRecommender />` |
| Test | `src/templates/__tests__/TemplateRecommender.test.js` | Component gating and stale-guard tests (if component testing infra is added) |
| Test | `src/utils/__tests__/template-types.test.js` | `normalizeTemplateType` unit tests |
