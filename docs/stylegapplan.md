## Exact gap list to make the answer a real **yes**

Here’s the surgical version: if you want the plugin to satisfy that sentence **literally**, I’d treat these as the required gaps.

## 1) The style contract is still **Global Styles-only**, not shared across Global Styles + Style Book

### Why this is a gap

Right now the backend and client style flow are hard-coded to the `global-styles` surface:

- StyleAbilities.php rejects anything where `scope.surface !== 'global-styles'`
- GlobalStylesRecommender.js is the only shipped style UI
- index.js only registers `GlobalStylesRecommender`
- index.js, activity-history.js, and capability wiring all use `global-styles`-specific state/scope names

So the repo has a **site-level style flow**, but not a reusable “theme style review flow” that can also power Style Book.

### Exact implementation

#### Backend

- In StyleAbilities.php

  - change `recommend_style()` to accept `scope.surface` values:
    - `global-styles`
    - `style-book`
  - split context building into two paths:
    - `build_global_styles_context()`
    - `build_style_book_context()`
  - keep a single ability and route; do **not** create a second backend contract unless you absolutely must

- In Agent_Controller.php

  - keep `POST /flavor-agent/v1/recommend-style`
  - widen request validation so `scope` can describe either Global Styles or Style Book
  - add optional fields needed by Style Book, e.g.:
    - `blockName`
    - `styleBookContext`
    - `allowCustomCss`

- In Registration.php
  - widen the `flavor-agent/recommend-style` input schema to match the above

#### Capability/bootstrap

- In SurfaceCapabilities.php
  - add a `styleBook` surface alongside `globalStyles`
- In flavor-agent.php
  - localize:
    - `canRecommendStyleBook`
    - `capabilities.surfaces.styleBook`
- In capability-flags.js
  - add:
    - legacy flag key for `style-book`
    - structured surface key mapping for `styleBook`

### Definition of done

You can send a style request with either surface and hit the same backend contract, with shared validation and shared review semantics.

---

## 2) There is **no shipped Style Book review flow**

### Why this is a gap

There is no `src/style-book/` implementation in the tree, and the roadmap docs still describe Style Book expansion as deferred follow-up work.

What exists today is:

- GlobalStylesRecommender.js

What does **not** exist:

- `src/style-book/StyleBookRecommender.js`

### Exact implementation

#### New UI surface

Create:

- `src/style-book/StyleBookRecommender.js`
- `src/style-book/__tests__/StyleBookRecommender.test.js`

#### Register it

- In index.js
  - import and render `<StyleBookRecommender />`

#### Reuse existing review components

Use the same shells already proven in the Global Styles surface:

- CapabilityNotice.js
- AIStatusNotice.js
- AIReviewSection.js
- AIActivitySection.js

#### Runtime behavior

The new Style Book surface should:

- render only when the Site Editor is in Style Book context
- resolve the reviewed block type or preview block identity
- send `scope.surface = 'style-book'`
- show:
  - suggestions
  - explanation
  - review before apply
  - apply
  - undo
  - activity history

#### Store/activity wiring

You have two implementation choices:

1. **Recommended:** factor current global-style state into a reusable style-surface slice
2. **Faster but uglier:** add a parallel style-book slice

I’d recommend refactoring the current `globalStyles*` state in index.js into a keyed style-surface state model instead of cloning it.

Also update:

- activity-history.js
- ActivitySessionBootstrap.js
- AIActivitySection.js

to support a Style Book scope key such as:

- `style-book:<globalStylesId>:<blockName>`

### Definition of done

From the Style Book, a user can:

- request theme-aware suggestions
- review them
- apply them
- undo them
- see history scoped to that reviewed block/style-book context

---

## 3) The current style contract does **not** support true theme-level **per-block styles** (`styles.blocks`)

### Why this is a gap

This is the biggest functional gap after Style Book.

Current supported style operations are site-level paths like:

- `color.background`
- `color.text`
- `elements.button.color.background`
- `typography.fontSize`
- `spacing.blockGap`
- `border.*`
- `shadow`

But nothing in the current contract targets:

- `styles.blocks.<blockName>...`

So the plugin does **not** yet support true theme-level per-block style recommendations in the `theme.json` sense.

### Exact implementation

#### Backend contract

In StyleAbilities.php:

- add a block-scoped supported-path builder, e.g.:
  - `supported_block_style_paths( string $block_name ): array`
- derive supported paths from block supports using:
  - `ServerCollector::introspect_block_type( $block_name )`

This should allow only theme-safe block-level style paths, for example:

- color
- typography
- border
- shadow
- spacing
- duotone
- background controls only where supported

#### Prompt contract

In StylePrompt.php:

- broaden the prompt from “Global Styles advisor” to a theme styling advisor
- add a `Supported block style paths` section when `scope.surface === 'style-book'`
- add a new executable operation type:
  - `set_block_styles`
- make that operation explicit, e.g. it should carry:
  - `blockName`
  - `path`
  - `value`
  - `valueType`
  - `presetType`
  - `presetSlug`

Do **not** hide the block name inside a path array; keep it first-class.

#### Client execution contract

In theme-tokens.js:

- add a helper like:
  - `buildBlockStylesExecutionContractFromSettings( settings, blockName )`

This should mirror the current Global Styles execution contract, but for a specific block type.

#### Apply/undo

In style-operations.js:

- add deterministic apply/undo for `set_block_styles`
- write to the current global styles entity under:
  - `styles.blocks[ blockName ]`
- preserve existing user config
- capture targeted before/after block-style state for undo validation

### Important scope rule

For “style variations” inside this theme-safe contract:

- keep **theme style variation** selection executable via existing `set_theme_variation`
- do **not** make registered block-style variation switching executable in the Style Book flow unless you first define a persisted theme-level representation for it

That avoids accidentally leaving `theme.json`-safe land.

### Definition of done

The style flow can review and apply theme-level block defaults, not just site-level defaults.

---

## 4) There is no **explicit custom CSS opt-in** path

### Why this is a gap

The current implementation is stricter than the requirement:

- StylePrompt.php always bans raw/custom CSS
- the tests verify `customCSS` is rejected
- the docs explicitly keep `customCSS` out of scope

Your requirement is narrower and more nuanced:

- default = `theme.json`-compatible only
- exception = allow custom CSS **only when the user explicitly asks**

That second branch does not exist today.

### Exact implementation

#### UI

Add an explicit per-request control in:

- BlockRecommendationsPanel.js
- GlobalStylesRecommender.js
- `src/style-book/StyleBookRecommender.js` (new)

Use a checkbox/toggle like:

- “Allow custom CSS for this request”

Default: **off**

#### Request transport

In:

- index.js
- Agent_Controller.php
- Registration.php

add:

- `allowCustomCss: boolean`

#### Server-side enforcement

In:

- Prompt.php
- StylePrompt.php

branch on `allowCustomCss`:

- if `false`
  - forbid `customCSS`
  - forbid `style.css`
  - forbid raw CSS payloads
  - forbid arbitrary non-theme-safe values
- if `true`
  - only allow custom CSS suggestions when the current user can `edit_css`
  - make CSS suggestions explicit and bounded

### Recommended design choice

Do **not** infer this only from freeform prompt text.  
Make it an explicit UI/request flag so consent is deterministic and testable.

### Definition of done

Custom CSS is impossible by default, but intentionally available when the user opts in and has the right capability.

---

## 5) Block recommendations need a **hard validator** for theme.json-safe output by default

### Why this is a gap

The block prompt is theme-aware, but it is not as strictly validated as the style contract.

Also, `customCSS` still appears as a modeled capability in:

- block-inspector.js
- ServerCollector.php

So block recommendations are “guided toward theme-safe output,” but not yet locked down enough to support the exact claim you quoted.

### Exact implementation

#### Prompt rules

In Prompt.php:

- add an explicit default rule:
  - block style suggestions must stay inside supported Gutenberg/theme.json-compatible controls unless `allowCustomCss` is true
- explicitly ban by default:
  - `customCSS`
  - `style.css`
  - raw CSS strings
  - arbitrary hex/pixel values where theme presets exist

#### Hard validation

In `Prompt::parse_response()` or an adjacent validation helper:

- reject `attributeUpdates` that touch:
  - `customCSS`
  - `style.css`
  - other raw CSS channels
- reject non-preset style payloads where a preset-backed theme token exists
- allow freeform only where the underlying block support actually permits it and the path is part of the supported contract

#### Tests

Extend:

- PromptRulesTest.php
- BlockAbilitiesTest.php

with explicit failure cases for:

- raw CSS by default
- `customCSS` by default
- preset mismatch
- unsupported style paths

### Definition of done

The block-side story matches the style-side story: theme-safe by default, CSS only by explicit opt-in.

---

## Recommended implementation order

To keep churn low and avoid building two style engines, I’d do it in this order:

1. **Generalize the style contract** from `global-styles` to shared style surfaces
2. **Add `styles.blocks` support** and deterministic apply/undo
3. **Build the Style Book surface** on top of that shared contract
4. **Add explicit custom CSS opt-in**
5. **Harden block recommendation validation**
6. **Refresh docs/tests/e2e**

That order lines up with the repo’s own note that Style Book expansion should reuse the current style contract instead of duplicating Global Styles UI logic.

## Minimal file set to touch

If I were opening the implementation branch, these would be the primary files:

- StyleAbilities.php
- StylePrompt.php
- Agent_Controller.php
- Registration.php
- SurfaceCapabilities.php
- flavor-agent.php
- index.js
- GlobalStylesRecommender.js
- `src/style-book/StyleBookRecommender.js` **(new)**
- style-operations.js
- theme-tokens.js
- capability-flags.js
- index.js
- activity-history.js
- ActivitySessionBootstrap.js
- AIActivitySection.js
- BlockRecommendationsPanel.js

And tests/docs:

- `src/style-book/__tests__/StyleBookRecommender.test.js` **(new)**
- style-operations.test.js
- GlobalStylesRecommender.test.js
- BlockRecommendationsPanel.test.js
- StylePromptTest.php
- StyleAbilitiesTest.php
- PromptRulesTest.php
- RegistrationTest.php
- AgentControllerTest.php
- style-and-theme-intelligence.md
- abilities-and-routes.md
- FEATURE_SURFACE_MATRIX.md
- STATUS.md

## If you want the shortest honest acceptance statement

After those gaps are closed, the claim becomes accurate if the plugin can truthfully say:

- it supports theme-aware recommendations for:
  - presets
  - theme style variations
  - theme-level per-block styles
  - Style Book review/apply/undo flows
- it defaults to theme.json-safe output
- it only allows custom CSS when the user explicitly opts in
