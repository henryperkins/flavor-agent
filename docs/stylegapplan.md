# Style Surface Follow-Up Checklist

Validated against the current tree on 2026-04-02.

This document replaces the earlier "exact gap list" framing. The previous draft treated the shipped Global Styles slice as if it were still missing. That is no longer accurate.

## Verified Current State

- The repo already ships an end-to-end Global Styles recommendation flow:
  - backend ability: `inc/Abilities/StyleAbilities.php`
  - REST adapter: `inc/REST/Agent_Controller.php`
  - ability schema: `inc/Abilities/Registration.php`
  - UI surface: `src/global-styles/GlobalStylesRecommender.js`
  - apply/undo executor: `src/utils/style-operations.js`
  - shared activity/store wiring: `src/store/index.js`, `src/store/activity-history.js`, `src/components/AIActivitySection.js`, `src/components/ActivitySessionBootstrap.js`
- The current style contract is intentionally Global Styles only:
  - `StyleAbilities::recommend_style()` rejects any `scope.surface` other than `global-styles`
  - the request schema only describes a Global Styles scope and context
  - capability bootstrap only exposes `globalStyles`
- The current style executor supports only:
  - `set_styles`
  - `set_theme_variation`
- The current style validator is stricter than the previous draft implied:
  - `inc/LLM/StylePrompt.php` validates supported style paths
  - preset-backed paths require known preset slugs
  - freeform values are limited to specific safe paths and value shapes
  - `customCSS` is rejected by the current style prompt contract and tests
- There is no shipped Style Book surface:
  - no `src/style-book/`
  - no `style-book` surface key in capabilities, store, or activity wiring
- There is no shipped theme-level per-block style executor:
  - no `styles.blocks.<blockName>` support
  - no `set_block_styles` operation
  - no block-style execution contract in `src/context/theme-tokens.js`
- Block recommendations are theme-aware, but they do not yet have the same hard execution-contract validation as the Global Styles surface:
  - the prompt strongly guides token-safe output
  - `Prompt::parse_response()` sanitizes payload shape
  - but the block path does not currently hard-reject raw CSS channels the way the style path does
  - `customCSS` still appears in block inspector/server introspection capability metadata because Gutenberg exposes it as a support

## Correctness Review Of The Previous Draft

Keep these points. They are correct:

- The current style contract is hard-coded to `global-styles`.
- There is no shipped Style Book review/apply/undo flow.
- There is no theme-level per-block style application path via `styles.blocks`.
- Activity, capability, and store wiring are currently Global Styles specific.
- Block-side validation is weaker than style-side validation if the product requirement is "theme-safe by default everywhere."

Cut or rewrite these points. They are inaccurate or overstated:

- "The repo only has a site-level style flow, not a reusable review flow."
  - Inaccurate as written. The repo does ship a real review/apply/undo flow already; the actual gap is that it is not generalized beyond Global Styles.
- "There is no hard validator for style output by default."
  - Inaccurate for the style surface. `StylePrompt` already validates supported paths, preset slugs, and safe freeform values.
- "An explicit custom CSS opt-in path is required to make the current claim honest."
  - Not proven by the current codebase. The current product/docs position is "custom CSS is out of scope for v1," not "custom CSS is supported behind opt-in." Treat this as a product decision, not a mechanical gap.
- "Add `allowCustomCss` now."
  - Do this only if the product thesis changes. It should not be bundled into the Style Book and `styles.blocks` work by default.

## Execution Checklist

### Phase 1: Generalize the style contract beyond Global Styles

Goal:
- Keep one style recommendation ability and one route.
- Allow that contract to serve both `global-styles` and `style-book`.

Checklist:
- Update `inc/Abilities/StyleAbilities.php`
  - accept `scope.surface` values `global-styles` and `style-book`
  - split context building into surface-specific helpers instead of one Global Styles-only path
  - keep shared validation and shared response shape
- Update `inc/REST/Agent_Controller.php`
  - keep `POST /flavor-agent/v1/recommend-style`
  - pass through any new Style Book scope/context fields
- Update `inc/Abilities/Registration.php`
  - widen the `flavor-agent/recommend-style` schema from "Global Styles scope" to "style surface scope"
  - add any new Style Book context fields explicitly
- Update `inc/Abilities/SurfaceCapabilities.php`
  - add a `styleBook` surface alongside `globalStyles`
- Update `flavor-agent.php`
  - localize a Style Book capability flag and structured surface payload
- Update `src/utils/capability-flags.js`
  - add legacy/structured keys for `style-book`

Done when:
- one ability and one REST route accept either style surface
- request validation is shared
- capability payloads expose both surfaces cleanly

### Phase 2: Add theme-level per-block style operations

Goal:
- support theme-level block defaults under `styles.blocks[ blockName ]`
- keep execution inside `theme.json`-safe state, not runtime-only block variation switching

Checklist:
- Update `inc/Abilities/StyleAbilities.php`
  - derive block-scoped supported style paths for a requested block name
  - use `ServerCollector::introspect_block_type()` to limit paths to supported controls
- Update `inc/LLM/StylePrompt.php`
  - broaden the prompt from "Global Styles advisor" to a style-surface advisor
  - add a block-style operation type, e.g. `set_block_styles`
  - keep `blockName` first-class in the operation payload
- Update `src/context/theme-tokens.js`
  - add a block-style execution contract helper parallel to the current Global Styles helper
- Update `src/utils/style-operations.js`
  - add deterministic apply for `set_block_styles`
  - write to `styles.blocks[ blockName ]` on the active global styles entity
  - capture before/after state for undo validation
  - add deterministic undo for the same payload

Done when:
- the backend can describe supported block-style paths
- the client can apply and undo `styles.blocks` mutations safely
- theme style variations remain supported through `set_theme_variation`

### Phase 3: Ship the Style Book surface

Goal:
- expose the shared style contract from the Site Editor Style Book
- support request, review, apply, undo, and scoped activity

Checklist:
- Create:
  - `src/style-book/StyleBookRecommender.js`
  - `src/style-book/__tests__/StyleBookRecommender.test.js`
- Update `src/index.js`
  - register the new surface
- Reuse shared UI shells where possible:
  - `src/components/CapabilityNotice.js`
  - `src/components/AIStatusNotice.js`
  - `src/components/AIReviewSection.js`
  - `src/components/AIActivitySection.js`
- Update `src/store/index.js`
  - recommended: refactor current Global Styles state into a keyed style-surface model
  - avoid cloning an entire second state machine if possible
- Update `src/store/activity-history.js`
  - add a stable Style Book scope key format
- Update `src/components/ActivitySessionBootstrap.js`
  - detect Style Book context and bootstrap scoped activity
- Update `src/components/AIActivitySection.js`
  - ensure labels/target summaries are correct for Style Book entries
- Update activity server handling if needed:
  - `inc/Activity/Permissions.php`
  - `inc/Activity/Serializer.php`

Done when:
- the Style Book surface can request style recommendations
- executable suggestions can be reviewed before apply
- applied suggestions can be undone while state still matches
- history is scoped to the reviewed Style Book target

### Phase 4: Decide the custom CSS product stance

This is a product decision, not a confirmed code gap.

Option A: Keep CSS out of scope
- Keep current behavior
- Tighten docs so they say "theme.json-safe only" instead of implying future CSS support

Option B: Add explicit opt-in CSS support
- add `allowCustomCss` to the request contract
- gate it behind explicit user consent and `edit_css`
- add separate validation for bounded CSS output

Recommendation:
- Do not implement this inside the current follow-up unless product explicitly chooses Option B.

Done when:
- the docs and code agree on whether CSS is permanently out of scope or intentionally opt-in

### Phase 5: Harden block-side validation

Goal:
- make the block recommendation path match the style path's "theme-safe by default" posture more closely

Checklist:
- Update `inc/LLM/Prompt.php`
  - add explicit default bans for `customCSS`, `style.css`, and raw CSS strings
  - prefer preset-backed values whenever the relevant token family exists
- Update block validation/enforcement
  - reject unsafe attribute updates after parse, not just by prompt instruction
  - keep allowed freeform values limited to controls that genuinely support them
- Keep `ServerCollector` / block inspector support metadata as discovery-only
  - do not treat "Gutenberg exposes customCSS" as "Flavor Agent may recommend it by default"

Done when:
- block recommendations are structurally sanitized
- unsafe CSS channels are rejected in code, not only discouraged in prompt text

### Phase 6: Docs and verification closeout

Checklist:
- Update:
  - `docs/features/style-and-theme-intelligence.md`
  - `docs/reference/abilities-and-routes.md`
  - `docs/FEATURE_SURFACE_MATRIX.md`
  - `STATUS.md`
- Add or extend tests:
  - `tests/phpunit/StyleAbilitiesTest.php`
  - `tests/phpunit/StylePromptTest.php`
  - `tests/phpunit/RegistrationTest.php`
  - `tests/phpunit/AgentControllerTest.php`
  - `tests/phpunit/PromptRulesTest.php`
  - `src/utils/__tests__/style-operations.test.js`
  - `src/global-styles/__tests__/GlobalStylesRecommender.test.js`
  - `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
  - new Style Book tests

Done when:
- docs no longer describe the current Global Styles slice as missing
- both PHP and JS tests cover the new contract
- the feature matrix reflects the new surface and apply/undo behavior accurately

## Recommended Implementation Order

1. Generalize the shared style contract.
2. Add `styles.blocks` support plus deterministic apply/undo.
3. Build the Style Book surface on top of that shared contract.
4. Decide the custom CSS stance.
5. Harden block-side validation.
6. Refresh docs and verification.

## Short Acceptance Statement

The plugin can honestly claim full theme-style support only when all of the following are true:

- Global Styles and Style Book both use the same validated style recommendation contract.
- Theme-level per-block styles can be reviewed, applied, and undone through `styles.blocks`.
- Recommendations stay `theme.json`-safe by default.
- Any custom CSS support is either explicitly out of scope or explicitly opt-in and capability-gated.
