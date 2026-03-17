# Flavor Agent: Abilities API Integration

## Goal

Expose the Flavor Agent plugin's recommendation engine and block/pattern/template introspection as WordPress Abilities API abilities, making them discoverable and invocable by WordPress core AI orchestration and third-party AI agents.

## Architecture

Two independent consumer paths share the same LLM layer:

1. **JS Inspector path** (existing): Client-side context collection -> `POST /flavor-agent/v1/recommend-block` -> `Prompt` + `Client` -> structured suggestions in Inspector UI. The request/response flow is unchanged, but the client now hard-enforces `contentOnly` restrictions and safely merges nested attribute updates before applying them.

2. **Abilities path** (new): Orchestrator calls ability with minimal input -> server-side context assembly via `ServerCollector` -> `Prompt` + `Client` -> structured output. Self-sufficient; callers need no knowledge of Flavor Agent internals.

The Abilities API hooks (`wp_abilities_api_categories_init`, `wp_abilities_api_init`) only fire on WP 6.9+. On WP 6.5-6.8, the existing REST endpoint works as before.

## Ability Inventory

### Category

`flavor-agent` with label "Flavor Agent".

### Block-level

| ID                              | Type         | Input                                                                                                        | Output                                                                                                                                 | Status      |
| ------------------------------- | ------------ | ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- | ----------- |
| `flavor-agent/recommend-block`  | Action (LLM) | `{ selectedBlock: { blockName, attributes, innerBlocks, isInsideContentOnly?, blockVisibility? }, prompt? }` | `{ settings: Suggestion[], styles: Suggestion[], block: Suggestion[], explanation }`                                                   | Implemented |
| `flavor-agent/introspect-block` | Read-only    | `{ blockName }`                                                                                              | `{ name, title, category, supports, inspectorPanels, contentAttributes, configAttributes, styles, variations, parent, allowedBlocks }` | Implemented |

### Patterns

| ID                                | Type         | Input                                                                            | Output                                                                                         | Status                                                     |
| --------------------------------- | ------------ | -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| `flavor-agent/recommend-patterns` | Action (LLM) | `{ postType, blockContext?: { blockName, attributes }, templateType?, prompt?, visiblePatternNames?: string[] }` | `{ recommendations: [{ name, title, score, reason, categories, content }] }`                   | Implemented; see `2026-03-16-recommend-patterns-design.md` |
| `flavor-agent/list-patterns`      | Read-only    | `{ categories?, blockTypes?, templateTypes? }`                                   | `{ patterns: [{ name, title, description, categories, blockTypes, templateTypes, content }] }` | Implemented                                                |

### Templates & Template Parts

| ID                                 | Type         | Input                       | Output                                                                                                                          | Status      |
| ---------------------------------- | ------------ | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ----------- |
| `flavor-agent/recommend-template`  | Action (LLM) | `{ templateType, prompt? }` | `{ suggestions: [{ label, description, templateParts: [{ area, name, reason }], patternSuggestions: string[] }], explanation }` | Stubbed     |
| `flavor-agent/list-template-parts` | Read-only    | `{ area? }`                 | `{ templateParts: [{ slug, title, area, content }] }`                                                                           | Implemented |

### Navigation

| ID                                  | Type         | Input                                       | Output                                                                                             | Status  |
| ----------------------------------- | ------------ | ------------------------------------------- | -------------------------------------------------------------------------------------------------- | ------- |
| `flavor-agent/recommend-navigation` | Action (LLM) | `{ menuId? \| navigationMarkup?, prompt? }` | `{ suggestions: [{ label, description, structureUpdates, overlayRecommendation? }], explanation }` | Stubbed |

### Infrastructure

| ID                              | Type      | Input    | Output                                                                                      | Status                                                                                |
| ------------------------------- | --------- | -------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------ |
| `flavor-agent/get-theme-tokens` | Read-only | _(none)_ | `{ colors, gradients, fontSizes, fontFamilies, spacing, shadows, layout, enabledFeatures }` | Implemented                                                                           |
| `flavor-agent/check-status`     | Read-only | _(none)_ | `{ configured: bool, model: string                                                          | null, availableAbilities: string[], backends?: { anthropic, azure_openai, qdrant } }` | Implemented with additive backend metadata |

### Suggestion Schema (shared)

Canonical visibility state lives in `selectedBlock.attributes.metadata.blockVisibility`. The top-level `selectedBlock.blockVisibility` field is accepted only as a backward-compatible alias and is normalized server-side before context assembly.

Each suggestion object in `recommend-block` output:

```json
{
  "label": "string — human-readable name",
  "description": "string — one sentence rationale",
  "panel": "string — general|layout|position|advanced|color|typography|dimensions|border|shadow|background",
  "attributeUpdates": "object — exact attribute key/value pairs to set",
  "confidence": "number? — 0.0-1.0",
  "preview": "string? — hex color for visual swatch",
  "presetSlug": "string? — theme preset slug",
  "cssVar": "string? — CSS custom property"
}
```

## Server-Side Context Assembly

### `ServerCollector.php`

Static methods that build context from PHP APIs:

| Method                                                                            | PHP Sources                                                            | Used By                                                     |
| --------------------------------------------------------------------------------- | ---------------------------------------------------------------------- | ----------------------------------------------------------- |
| `for_block( string $block_name, array $attributes, array $inner_blocks )`         | `WP_Block_Type_Registry::get_registered()`, `wp_get_global_settings()` | `recommend-block`                                           |
| `for_tokens()`                                                                    | `wp_get_global_settings()`, `wp_get_global_styles()`                   | `get-theme-tokens`, also called internally by `for_block()` |
| `for_patterns( ?array $categories, ?array $block_types, ?array $template_types )` | `WP_Block_Patterns_Registry::get_all_registered()`                     | `list-patterns`, `recommend-patterns`                       |
| `for_template_parts( ?string $area )`                                             | `get_block_templates( [], 'wp_template_part' )`                        | `list-template-parts`, `recommend-template`                 |
| `introspect_block_type( string $block_name )`                                     | `WP_Block_Type_Registry::get_registered()`                             | `introspect-block`, also called by `for_block()`            |

### Block Introspection (PHP equivalent of JS `block-inspector.js`)

`introspect_block_type()` reads a `WP_Block_Type` object and produces:

- `supports` object
- `inspectorPanels` mapping (reuses the same `SUPPORT_TO_PANEL` logic from the JS module)
- `attributes` schema split by `role: content` vs. config
- `styles` array from the block type's registered styles
- `variations` array
- `parent`, `allowedBlocks`, `apiVersion`

### Theme Tokens (PHP equivalent of JS `theme-tokens.js`)

`for_tokens()` calls `wp_get_global_settings()` with origin separation and produces the same `summarizeTokens()` shape: colors with slugs + hex, font sizes with slugs + values, spacing scale, shadows, layout constraints.

## File Structure

```
inc/
├── Abilities/
│   ├── Registration.php        # Category + all 9 wp_register_ability calls; check-status schema includes backends metadata
│   ├── BlockAbilities.php      # recommend-block, introspect-block callbacks
│   ├── PatternAbilities.php    # recommend-patterns (implemented), list-patterns callbacks
│   ├── TemplateAbilities.php   # recommend-template (stub), list-template-parts callbacks
│   ├── NavigationAbilities.php # recommend-navigation (stub) callback
│   └── InfraAbilities.php      # get-theme-tokens, check-status (dynamic backend readiness) callbacks
├── AzureOpenAI/
│   ├── EmbeddingClient.php     # Azure OpenAI /openai/v1/embeddings client
│   ├── ResponsesClient.php     # Azure OpenAI /openai/v1/responses client (GPT-5.4)
│   └── QdrantClient.php        # Qdrant Cloud REST API client
├── Context/
│   └── ServerCollector.php     # Server-side context assembly
├── LLM/
│   ├── Client.php              # (existing, unchanged)
│   └── Prompt.php              # (existing, unchanged — block system prompt reused)
├── Patterns/
│   └── PatternIndex.php        # Pattern sync orchestrator (fingerprint, embed, upsert)
├── REST/
│   └── Agent_Controller.php    # REST controller with recommend-block, sync-patterns, recommend-patterns routes
└── Settings.php                # Settings UI with Anthropic, Azure OpenAI, Qdrant sections + sync panel
```

## Related Design

- `2026-03-16-recommend-patterns-design.md` is the authoritative implementation design for the `recommend-patterns` ability, the pattern sync lifecycle, the admin sync button, and the additive `check-status` contract expansion.

## Bootstrap Change

Two lines added to `flavor-agent.php`:

```php
add_action( 'wp_abilities_api_categories_init', [ FlavorAgent\Abilities\Registration::class, 'register_category' ] );
add_action( 'wp_abilities_api_init', [ FlavorAgent\Abilities\Registration::class, 'register_abilities' ] );
```

## Build Scope

### Implemented (this iteration)

- `Registration.php` with all 9 abilities registered (full schemas)
- `ServerCollector.php` with `introspect_block_type()`, `for_block()`, `for_tokens()`, `for_patterns()`, `for_template_parts()`
- `BlockAbilities.php` — `recommend-block` (LLM call via existing Prompt + Client) and `introspect-block` (read-only)
- `PatternAbilities.php` — `list-patterns` (read-only)
- `TemplateAbilities.php` — `list-template-parts` (read-only)
- `InfraAbilities.php` — `get-theme-tokens` and `check-status` (read-only)
- `NavigationAbilities.php` — stub returning `WP_Error('not_implemented')`
- Bootstrap hooks

### Stubbed (future iterations)

- `recommend-template` — needs template-specific LLM system prompt + `ServerCollector::for_template()`
- `recommend-navigation` — needs navigation-specific LLM system prompt + `ServerCollector::for_navigation()`

Stubbed abilities are registered with full `input_schema`/`output_schema` so orchestrators discover them. Callbacks return `WP_Error('not_implemented', 'This ability is planned but not yet available.', ['status' => 501])`.

### Implemented (recommend-patterns iteration)

- `recommend-patterns` — Azure OpenAI embeddings + Qdrant vector search + GPT-5.4 LLM ranking. See `2026-03-16-recommend-patterns-design.md`.
- `check-status` — dynamic `availableAbilities` computation + `backends` metadata (additive, backward-compatible)
- `Agent_Controller.php` — added `POST /flavor-agent/v1/sync-patterns` route
- `Settings.php` — added Azure OpenAI, Qdrant credential sections + sync status panel + admin JS enqueue
- `flavor-agent.php` — added lifecycle hooks for pattern index (cron, theme switch, plugin activation/deactivation)

## Backward Compatibility

- WP 6.5-6.8: Abilities hooks never fire. Plugin works via REST endpoint only.
- WP 6.9+: Abilities register alongside the existing REST endpoint. Both paths work.
- Existing JS Inspector consumers keep the same REST contract, with additional client-side guard rails around nested attribute merges and `contentOnly` enforcement.

## Verification

- `wp-abilities/v1/abilities` returns all 9 abilities with schemas
- `wp-abilities/v1/categories` returns `flavor-agent` category
- `introspect-block` with `{ "blockName": "core/group" }` returns capability manifest
- `get-theme-tokens` returns current theme's design tokens
- `check-status` reports API key and model configuration today; the recommend-patterns iteration keeps those top-level fields and adds backend-specific readiness metadata
- `recommend-block` with `{ "selectedBlock": { "blockName": "core/group", "attributes": { "metadata": { "blockVisibility": false } }, "innerBlocks": [] } }` preserves the explicit boolean visibility state in the prompt (requires API key)
- `recommend-block` accepts legacy `{ "selectedBlock": { "blockVisibility": { "viewport": { "mobile": false } } } }` input and normalizes it into `attributes.metadata.blockVisibility`
- `list-patterns` returns filtered pattern inventory
- `list-template-parts` returns template parts
- Stubbed abilities return 501 with descriptive message
- Plugin still works on WP 6.5 without fatals (abilities code never loads)
