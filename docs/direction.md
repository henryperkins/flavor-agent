## Direction read

Looking ahead, the plugin is mostly pointed the right way.

Current WordPress 7.0 signals favor:

- **server-scoped AI feature endpoints**, not arbitrary client-side prompt execution
- **Connectors + AI Client** as the platform trust layer
- **DataViews/DataForm** for richer wp-admin audit/reporting UIs
- **Abilities** as a machine-readable contract, but not necessarily the first-party runtime for every editor interaction
- **native editor panels over legacy/metabox-era UI**, especially with collaboration architecture still settling

On that front, Flavor Agent is broadly aligned:

- first-party UI still runs through **scoped REST endpoints + the `flavor-agent` store**
- provider integration is **connector-aware**
- the audit page already uses **DataViews/DataForm**
- Abilities are **additive/public**, not the only runtime path
- the plugin lives in **native Gutenberg/wp-admin surfaces**, not a parallel AI shell

That’s good news. The architecture is more “on trend” than off trend.

## What looks consistent

I did **not** find a major runtime mismatch where a shipped surface is documented one way but wired so differently that it seems broken.

In particular:

- `SurfaceCapabilities` and `flavorAgentData` are coherently shaping the main shipped surfaces in flavor-agent.php and SurfaceCapabilities.php.
- Block recommendation execution is correctly routed through `ChatClient`, which in turn can use direct provider config or fall back to the WordPress AI Client.
- The admin audit page is real, wired, and modern: ActivityPage.php + activity-log.js.
- The editor entrypoint in index.js clearly mounts the current major UI surfaces.

So the core product wiring is in decent shape.

## Inconsistencies and issues I found

### 1. The plugin’s outward-facing description undersells the product

The most public description is stale.

- flavor-agent.php still says: **“LLM-powered block recommendations in the native Inspector sidebar.”**
- But the actual shipped product now includes:
  - pattern recommendations
  - template recommendations
  - template-part recommendations
  - navigation recommendations
  - global styles recommendations
  - style book recommendations
  - admin AI activity/audit UI

That means the plugin advertises itself as a narrower product than it really is.

**Why it matters:** plugin list/admin discovery, future readmes, and reviewer expectations all start here.

### 2. Contributor-facing summaries are behind the code

Both CLAUDE.md and copilot-instructions.md are stale in important ways.

They still summarize the plugin as block/pattern/template/template-part/navigation + activity, but they **omit the shipped style surfaces**:

- GlobalStylesRecommender.js
- StyleBookRecommender.js

They also describe index.js too narrowly. The docs say it registers the pattern/template/template-part surfaces, but the real entrypoint also renders:

- `ActivitySessionBootstrap`
- `BlockRecommendationsDocumentPanel`
- `InserterBadge`
- `GlobalStylesRecommender`
- `StyleBookRecommender`

So the code is broader than the contributor docs admit.

### 3. Ability and route counts are out of sync across docs

This is the biggest backend-contract drift.

Registration.php registers **13 abilities**, including:

- `flavor-agent/recommend-content`
- `flavor-agent/recommend-style`

But the contributor docs say **11 abilities**.

Also:

- SOURCE_OF_TRUTH.md says Registration.php has **12 ability registrations**
- later in the same file it lists **13 abilities**

Similarly, some docs omit current REST routes like:

- `POST /flavor-agent/v1/recommend-content`
- `POST /flavor-agent/v1/recommend-style`
- `POST /flavor-agent/v1/activity/{id}/undo`

So the backend contract is stronger than some of the docs describe.

### 4. The block recommendation flow diagram is stale

SOURCE_OF_TRUTH.md has two conflicting descriptions of the block LLM path:

- one section correctly says block recommendations use `ChatClient::chat()`
- the flow diagram later still shows a direct `WordPressAIClient::chat()` step

The code path is clear:

- `BlockAbilities::recommend_block()` → `ChatClient::chat()`
- `ChatClient::chat()` → direct provider first, WordPress AI Client fallback

That doc drift is subtle, but it matters for future provider/debugging work.

### 5. The boot-data contract docs are stale

The JS boot globals have drifted from what the contributor docs claim.

For `flavorAgentActivityLog`:

- docs say it exposes `defaultLimit`
- runtime actually localizes `defaultPerPage`
- and also includes `maxPerPage`, `locale`, and `timeZone`

For `flavorAgentData`:

the docs underdescribe it. Runtime also includes:

- `settingsUrl`
- `connectorsUrl`
- `canManageFlavorAgentSettings`
- structured `capabilities.surfaces`
- `canRecommendContent`
- `canRecommendGlobalStyles`
- `canRecommendStyleBook`

That’s not a code bug, but it is a **contract documentation bug**.

### 6. Style Book activity is shipped, but the surface matrix under-docs it

FEATURE_SURFACE_MATRIX.md describes inline AI activity as appearing in:

- block
- template
- template-part
- global styles

But `AIActivitySection` is also used in:

- StyleBookRecommender.js

So the matrix is missing one shipped surface.

### 7. The content lane is half-advertised, half-hidden

Backend-wise, the content surface exists:

- `flavor-agent/recommend-content`
- `POST /flavor-agent/v1/recommend-content`

But there is still **no first-party editor UI** for it.

At the same time, the editor boot payload includes `canRecommendContent`.

So the product boundary is a little muddy:

- backend/programmatic surface: yes
- shipped first-party UI: no
- editor payload signal: yes

That’s not wrong, but it makes the content lane feel more “shipped” than it really is.

### 8. JS translations appear to be missing

I didn’t find any `wp_set_script_translations()` calls for the three JS bundles:

- editor bundle
- settings-page bundle
- activity-log bundle

Given the amount of JS UI here, that likely means translated JS strings won’t load properly even though the plugin is using translatable UI copy.

This is the clearest **real frontend issue** I found, as opposed to docs drift.

### 9. Naming is inconsistent on the activity admin surface

The same surface is labeled differently in different places:

- menu title: **AI Activity**
- page title: **Flavor Agent Activity**
- settings CTA button: **Activity Log**
- app heading: **AI Activity Log**

Small, but messy. It weakens the surface identity.

## What I’d prioritize next

If you want this repo to match current WordPress direction cleanly, I’d do this in order:

1. **Tighten the advertised surface inventory**

   - update flavor-agent.php description
   - update CLAUDE.md
   - update copilot-instructions.md

2. **Reconcile backend contract docs**

   - correct ability counts
   - correct REST route inventory
   - fix the stale block-flow diagram
   - document the content lane as explicitly programmatic-only

3. **Fix JS i18n**

   - add script translation wiring for all three bundles

4. **Keep standalone admin surfaces on public WPDS runtime hooks**

   - make plugin-owned admin pages load real WPDS design tokens instead of fallback-only `var(--wpds-*, fallback)` usage
   - bundle package CSS when `@wordpress/scripts` externalizes styles to non-registered handles (for example DataViews)
   - prefer public admin-scheme CSS plus a plugin-owned token bridge over private `@wordpress/theme` ThemeProvider APIs

5. **Normalize activity naming**

   - choose one label: probably `AI Activity`

6. **Lean further into current platform trends**
    - expand the DataViews/DataForm audit page with more provenance
    - show provider/model/source metadata in the activity log
    - keep first-party UI on REST/store for now
   - avoid re-architecting around client-side abilities until that layer settles more

## Bottom line

The **runtime architecture is ahead of the docs**.

That’s the big story.

The plugin is already aligned with current WordPress trends in the important ways:
native surfaces, scoped REST contracts, Connectors/AI Client awareness, admin-native audit UI.

But its **advertised surface area is inconsistent** across:

- plugin header copy
- contributor docs
l- canonical docs
- boot-data contract notes

And the one standout **real frontend issue** is the likely missing JS translation setup.
