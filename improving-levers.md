# Improving Recommendation Levers

**Scope:** Actionable exploration for making Flavor Agent recommendations more contextual, WordPress-native, design-minded, and freshness-aware.

**Repo baseline checked:** May 19, 2026, against the live `/home/dev/flavor-agent` tree.

**North star:** Recommendations should feel like they came from a careful WordPress designer who understands the active theme, supported block controls, surrounding layout, site guidelines, current WordPress APIs, accessibility constraints, and when doing nothing is better than adding noise.

## Pipeline

These levers form one pipeline:

1. Context quality determines what the model knows.
2. Prompt design determines what the model optimizes for.
3. Pattern retrieval and index quality determine which concrete design assets are available.
4. Validation rules determine what is safe and WordPress-native.
5. Ranking weights determine what rises to the top.
6. Docs and guideline grounding determine currentness, brand fit, and site-specific taste.
7. Freshness signatures determine whether a recommendation still applies.

The highest-return path is not simply "make the model smarter." It is to make design signal explicit, structured, measurable, and reused across prompts, validators, ranking, diagnostics, and freshness signatures.

## Current Groundwork

The repo already has useful rails:

- `inc/LLM/Prompt.php` gives block recommendations block state, Inspector panels, active style, content-only restrictions, bindable attributes, theme tokens, parent context, sibling summaries, structural ancestors, allowed pattern operations, WordPress docs guidance, and the user instruction.
- `src/context/collector.js` already gathers parent context, sibling summaries, structural identity, visual hints, theme-token summaries, and block operation context.
- `inc/LLM/StylePrompt.php` already accepts `designSemantics` for Global Styles and Style Book contexts, then warns the model not to over-commit when semantic context is weak. Treat `designSemantics` as the shared cross-surface name rather than introducing a parallel block-only semantic system.
- `inc/Patterns/PatternIndex.php` already infers layout traits such as `hero-banner`, `multi-column`, `call-to-action`, `query-loop`, `media-rich`, `text-focused`, `pricing`, and `contact`.
- `inc/Abilities/PatternAbilities.php` already filters visible pattern scope, checks backend readiness, searches Qdrant or Cloudflare AI Search, rehydrates readable synced patterns, dedupes candidates, applies small ranking hints, diversifies candidates, and asks an LLM reranker to score the final set.
- `inc/Support/RankingContract.php` already normalizes recommendation ranking metadata to a consistent shape.
- Ability output schemas in `inc/Abilities/Registration.php` expose `ranking` metadata to external consumers.
- Phases 0, 1, and 2 plus Contextual Ranking V1 are shipped: `tests/phpunit/RecommendationEvaluationTest.php` plus `tests/phpunit/fixtures/recommendation-evaluation-*` provide the fixture-backed metrics stub, strict LLM schemas in `inc/LLM/ResponseSchema.php` accept nullable `ranking` objects, `inc/Support/DesignSemantics.php` normalizes shared semantic context for block/template/template-part prompts, block/style/template/template-part/navigation parsers blend model, deterministic, and `RecommendationContextScorer` context scores through `RankingContract::blend_score()`, and pattern recommendations emit `contextual_ranking_v1` source-signals via plugin-generated defaults.
- `inc/Support/DocsGuidanceResult.php` fingerprints docs guidance with policy, status, coverage, URLs, source types, content hashes, retrieved timestamps, published dates, and freshness labels.
- `inc/Abilities/RecommendationAbilityExecution.php` injects formatted site guidelines into the recommendation system instruction.

## Priority 0: Add A Fixture-Backed Measurement Stub

**Goal:** Establish cheap baseline metrics before changing prompts, ranking weights, design semantics, freshness inputs, or pattern metadata.

### Why This Comes First

The proposed ranking weights and design-signal sets are starting hypotheses. They cannot be proven from first principles. A fixture-backed harness gives each later phase a baseline and makes tuning defensible.

This first pass should avoid live provider calls. It only needs static fixtures, deterministic parser/validator runs, and simple ratios.

### Initial Fixtures

Keep the first fixture set intentionally small. It should prove the evaluator and parser materialization path, not claim broad recommendation quality. Start with:

- An already-good block that would count model noise.
- A block or structural-operation fixture with accepted and rejected executable operations.
- A preset-backed style operation versus a raw/freeform style operation.
- A template or pattern advisory suggestion with no executable operation.
- Parser-backed probes for high-confidence no-op versus lower-confidence real change, high-confidence raw/freeform value versus lower-confidence preset-backed operation, and high-confidence invalid/downgraded operation versus lower-confidence valid operation.

### Initial Metrics

Track at least:

- `invalidOperationRate`: rejected executable operations divided by proposed executable operations.
- `presetAdherenceRate`: preset-backed values divided by style/color/spacing/typography values that could use presets.
- `noOpRate`: suggestions that would not change the current context divided by total suggestions.
- `noiseRate`: suggestions emitted when a fixture is marked already-good divided by total suggestions for that fixture.

Later phases can add top-three relevance, contrast preservation, stale false positives, and provider-to-provider ranking stability.

### Implementation Seams

- PHP fixture runner near current ability and parser tests in `tests/phpunit/`.
- JS fixture coverage near `src/context/__tests__/collector.test.js` and store/actionability tests.
- Existing budget tests in `tests/phpunit/PromptBudgetTest.php` should be reused when new prompt sections are added.

### Acceptance Criteria

- The harness can run without provider credentials.
- Baseline metrics are recorded before Phase 1 changes ranking behavior.
- Every later phase names the metric it expects to move or preserve.

## Priority 1: Add Design Semantics Summaries

**Goal:** Give each recommendation surface a compact design diagnosis before raw details.

### Current Gap

Block prompts have strong raw data, and style prompts already have `designSemantics`. The missing piece is a normalized cross-surface semantic summary that says what the current block or surface is doing and what design issue, if any, should be solved.

The model currently has to infer this from attributes, structural labels, visual hints, theme tokens, and surrounding blocks. That works sometimes, but it is fragile and provider-dependent.

### Proposed Shape

Extend the existing `designSemantics` concept across recommendation surfaces. Shared keys should keep the same meaning everywhere; surface-specific keys can live under explicit nested keys such as `block`, `style`, `template`, or `pattern`.

```json
{
  "sectionRole": "hero|header|footer|card|sidebar|post-body|cta|archive-list|unknown",
  "visualDensity": "sparse|balanced|dense|unknown",
  "contrastContext": "dark-parent|light-parent|image-overlay|unknown",
  "layoutRhythm": "constrained|full-width|grid|stacked|media-text|sidebar|unknown",
  "typographyRole": "heading|body|metadata|navigation|callout|unknown",
  "tokenAffinity": {
    "color": ["base", "contrast", "accent"],
    "spacing": ["medium", "large"],
    "fontSize": ["body", "heading"]
  },
  "existingDesignScore": 0.72,
  "mainDesignIssue": "contrast|spacing|hierarchy|rhythm|alignment|consistency|accessibility|none|unknown",
  "negativeSignals": [
    "no-typography-support",
    "parent-already-supplies-contrast"
  ]
}
```

### Naming Boundary

Use `designSemantics` as the shared contract name because the style surfaces already use it. Avoid adding a separate `designContext` schema unless it is only a temporary adapter for backward compatibility.

Shared keys:

- `sectionRole`
- `visualDensity`
- `contrastContext`
- `layoutRhythm`
- `typographyRole`
- `tokenAffinity`
- `mainDesignIssue`
- `negativeSignals`

Surface-specific keys should be nested. For example, block-only execution constraints belong in `designSemantics.block`, while Style Book occurrence summaries can remain in `designSemantics.styleBook`.

### Implementation Seams

- Client collection: `src/context/collector.js`
- Server-side semantic normalization: `inc/Support/DesignSemantics.php`, applied from `inc/Abilities/BlockAbilities.php` and `inc/Abilities/TemplateAbilities.php` before prompt assembly and resolved/review signature generation.
- Block prompt assembly: `inc/LLM/Prompt.php`
- Style prompt assembly: `inc/LLM/StylePrompt.php`
- Template prompt assembly: `inc/LLM/TemplatePrompt.php`
- Template-part prompt assembly: `inc/LLM/TemplatePartPrompt.php`
- Freshness/signature builders: `src/utils/block-recommendation-context.js`, `src/templates/template-recommender-helpers.js`, `src/template-parts/template-part-recommender-helpers.js`, `src/utils/style-operations.js`, `inc/Support/RecommendationResolvedSignature.php`, and `inc/Support/RecommendationReviewSignature.php`
- Signature regression tests: `src/utils/__tests__/block-recommendation-context.test.js`, `src/templates/__tests__/template-recommender-helpers.test.js`, `src/template-parts/__tests__/template-part-recommender-helpers.test.js`, and `src/utils/__tests__/style-operations.test.js`

### High-Value Inputs

- Current values versus theme default or Global Styles values.
- Raw values that could be replaced by known theme presets.
- Neighbor style summaries, not only sibling block names.
- Parent background/text tone and layout width behavior.
- Template-part identity and resolved area, including header/footer/sidebar roles that should not fall through to `unknown`.
- Token role classification from theme token names, such as `base`, `contrast`, `accent`, `muted`, or semantic names.
- Negative context, such as unsupported panels, locked editing modes, parent-supplied contrast, or absent block support.

### Acceptance Criteria

- Block recommendation fixtures can show compact `designSemantics` in the prompt without inflating raw payload size.
- Style prompts continue using the existing `designSemantics` field instead of introducing a competing concept.
- Prompt tests prove unsupported controls become negative signals, not model invitations.
- Existing content-only and Inspector-panel guards continue to reject unsupported mutations.
- Resolved/review freshness signatures include the normalized semantic projection, so surrounding rhythm, contrast, or role changes stale old recommendations.
- Added prompt sections stay budget-aware through `PromptBudget`, but the semantic section's own roughly 80-token cap must be enforced before assembly in the formatter that builds the semantic prompt lines. The `PromptBudget::add_section()` priority argument is not a per-section token limit.
- Metrics gate: `noiseRate` does not increase on already-good fixtures, and `invalidOperationRate` does not increase on locked/content-only fixtures.

## Priority 2: Move Ranking To Composite Scores

**Goal:** Stop letting model confidence dominate ordering when deterministic evidence is stronger.

### Current Gap

The current working tree already has the first composite-ranking implementation slice, but the strategic gap remains: deterministic quality signals need to keep expanding beyond the initial blend so confidence-only suggestions cannot dominate when validators, presets, no-op checks, design fit, or context fit say otherwise.

Historically, `RankingContract::resolve_score_candidate()` returned the first numeric candidate. Block parsing used the equivalent of:

```php
RankingContract::resolve_score_candidate(
	$ranking_input['score'] ?? null,
	$s['score'] ?? null,
	$ranking_input['confidence'] ?? null,
	$confidence
);
```

That made model confidence too influential. The current in-tree Phase 0/1 work changes the parser shape to "blend model and deterministic components"; the remaining work is to make the deterministic and context components richer and metric-backed instead of treating the first blend as final tuning.

### Proposed Score

Use a composite score instead of first-available score:

```text
final = (0.30 * model)
      + (0.45 * deterministic)
      + (0.25 * context)
```

Suggested components:

- `model`: model-provided score or confidence, capped so uncalibrated confidence cannot dominate.
- `deterministic`: the existing `derive_score()`-style signal set, expanded to include valid operation, supported panel, exact preset usage, non-empty executable change, no-op avoidance, validator pass or downgrade state.
- `context`: user prompt match, block/template role match, parent/sibling harmony, design issue match, accessibility improvement.

### Ranking Dimensions

Normalize these dimensions across block, style, template, template-part, navigation, and pattern surfaces:

- `relevance`: solves the user instruction or visible design issue.
- `designFit`: respects section role, parent tone, sibling rhythm, token affinity, and typography role.
- `wpNativeFit`: uses theme presets, block supports, style variations, Global Styles, patterns, and native APIs.
- `safety`: validator pass, partial downgrade, advisory-only fallback, or reject.
- `impact`: visible improvement over tiny tweaks.
- `specificity`: exact attribute, preset, path, operation, or pattern name.
- `novelty`: not already applied and not a duplicate suggestion.
- `accessibility`: contrast, readability, focus, and responsive sanity.

### Implementation Seams

- Ranking contract: `inc/Support/RankingContract.php`
- Block parsing: `inc/LLM/Prompt.php`
- Style parsing: `inc/LLM/StylePrompt.php`
- Template parsing: `inc/LLM/TemplatePrompt.php`
- Template-part parsing: `inc/LLM/TemplatePartPrompt.php`
- Navigation parsing: `inc/LLM/NavigationPrompt.php`
- Pattern shaping: `inc/Abilities/PatternAbilities.php`

### Acceptance Criteria

- A vague high-confidence suggestion no longer outranks a lower-confidence suggestion with a valid executable operation and clear design fit.
- Tests cover malformed model ranking, missing model score, confidence-only response, and deterministic fallback.
- `ranking.sourceSignals` tells consumers why the score exists.
- The existing `derive_score()` tests remain meaningful by becoming deterministic-component coverage instead of fallback-only coverage.
- Metrics gate: `invalidOperationRate` and `noOpRate` do not increase, and `presetAdherenceRate` stays flat or improves.

## Priority 3: Add Ranking Objects To Strict LLM Schemas

**Goal:** Make the model provide structured ranking reasons and signals when strict schema enforcement is active.

### Current Gap

Parsers already look for optional `ranking` objects. Ability output schemas expose ranking metadata. The current working tree now adds nullable `ranking` objects to the strict LLM schemas and prompt examples. This priority remains in the roadmap as a hardening/verification gate: keep that contract explicit, schema-tested, prompt-tested, and tied to the composite ranking parser behavior.

### Proposed Schema

The strict suggestion item schemas should keep a nullable `ranking` object:

```json
{
  "score": 0.82,
  "reason": "Improves contrast while preserving the theme accent palette.",
  "sourceSignals": ["user_prompt_match", "theme_preset", "contrast_improvement"],
  "designPrinciple": "contrast",
  "risk": "low"
}
```

Keep deterministic ranking authoritative. The model ranking object should explain and seed the composite score, not replace validation.

### Acceptance Criteria

- `tests/phpunit/ResponseSchemaTest.php` proves block, style, template, template-part, and navigation LLM schemas accept `ranking`.
- Parser tests prove model `ranking.score` is weighted, not blindly trusted.
- Schema changes do not weaken strict validation for unrelated fields.
- Prompt-budget tests prove the extra schema guidance does not force high-value prompt sections out of budgeted prompts.
- Metrics gate: top-level parser fixtures still produce the same or lower `invalidOperationRate` after schemas begin accepting `ranking`.

## Priority 4: Let Validation Feed Ranking And Diagnostics

**Goal:** Use validation outcomes as quality signals, not just filters.

### Current Strengths

- Block validation filters unsafe attribute updates, unsupported panels, bindable attributes, content-only restrictions, and structural operations.
- Style validation checks supported style paths, preset/freeform value validity, Global Styles versus Style Book scope, and contrast.
- Template validation checks template-part areas, insertion anchors, assigned/unused parts, pattern candidates, and operation validity.
- Pattern recommendations filter against visible patterns and readable synced patterns.

### Improvements

- Keep safe advisory remnants when executable operations fail.
- Attach `rejectedOperations` and `validationReasons` consistently across block, style, template, and template-part surfaces.
- Penalize partial validity instead of always discarding the entire suggestion.
- Persist validator feedback into diagnostics only when it changes actionability, changes ranking, explains an empty result, explains a downgraded suggestion, or explains a rejected operation. Routine successful validation should remain out of diagnostics.
- Record common downgrade reasons, such as stale target path, failed contrast, unsupported path, unsupported panel, no-op update, or parent/child mismatch.

### Design Validators

Add deterministic validators and scorers for:

- Contrast preservation or improvement.
- Preset usage versus raw value.
- Typography readability.
- Spacing scale adherence.
- Duplicate and no-op detection.
- Parent/sibling already-matches detection.
- Responsive visibility sanity.
- Excessive visual complexity.

### Acceptance Criteria

- Diagnostics can explain why a suggestion was downgraded, rejected, or kept advisory-only.
- Ranking components include validation state.
- UI surfaces can render a concise reason without exposing raw provider payloads.
- Diagnostics stay bounded and sanitized: no raw provider payloads, no full block trees, and no routine pass logs.
- Metrics gate: rejected executable operations lower the final score, and advisory remnants do not increase `invalidOperationRate`.

## Priority 5: Structure Guidelines And Add Guidelines Freshness

**Goal:** Treat guidelines as first-class design constraints that affect prompt output and recommendation freshness.

### Current Gap

Guidelines are formatted as text by `Guidelines::format_prompt_context()` and injected by `RecommendationAbilityExecution::execute_with_system_instruction()`. That improves generation, but recommendation signatures are computed inside individual ability callbacks and do not obviously include a guidelines fingerprint.

Changing brand or design guidance can therefore change generation without clearly invalidating old recommendation results.

This invalidation is desired. If an admin changes the site's design or brand guidance, old recommendations were generated under different constraints. The fingerprint should be based on a normalized structured projection, not raw text, so whitespace-only or ordering-only changes do not create unnecessary churn.

### Proposed Guideline Model

Keep compatibility with existing `site`, `copy`, `images`, `additional`, and block-specific guidelines, but derive a structured summary:

```json
{
  "designGuidelines": {
    "brandTone": "calm and precise",
    "colorPreferences": "muted base with accent only for CTAs",
    "typographyPreferences": "avoid all caps except short labels",
    "spacingDensity": "generous vertical rhythm on landing pages",
    "imageTreatment": "prefer documentary screenshots",
    "accessibilityRequirements": "preserve readable contrast",
    "patternPreferences": "prefer simple editorial patterns",
    "avoid": ["raw hex colors", "decorative shadows on editorial content"],
    "blockSpecificRules": {
      "core/paragraph": "keep lead paragraphs concise"
    }
  }
}
```

### Implementation Seams

- Guideline storage and formatting: `inc/Guidelines.php`
- Prompt formatting: `inc/Guidelines/PromptGuidelinesFormatter.php`
- Execution injection: `inc/Abilities/RecommendationAbilityExecution.php`
- Ability signatures: `inc/Abilities/BlockAbilities.php`, `StyleAbilities.php`, `TemplateAbilities.php`, `PatternAbilities.php`
- Signature hashing: `inc/Support/RecommendationResolvedSignature.php` and `inc/Support/RecommendationSignature.php`

### Acceptance Criteria

- Recommendation responses include or internally use a stable `guidelinesFingerprint`.
- For signature-backed recommendation surfaces, `resolveSignatureOnly` changes when relevant guidelines change. That currently means block, style, template, template-part, and navigation; pattern recommendations do not expose `resolveSignatureOnly` today.
- Pattern recommendations either expose a pattern-specific `guidelinesFingerprint` in their response/ranking diagnostics or first add an explicit pattern signature-only contract before claiming `resolveSignatureOnly` parity.
- Existing behavior remains compatible with upstream `WordPress\AI\format_guidelines_for_prompt()` when available.
- Tests prove guideline-only changes stale previous block/style/template/template-part/navigation results and prove the chosen pattern-specific freshness signal changes when pattern-relevant guidelines change.
- Whitespace-only guideline changes do not stale results when the normalized structured projection is unchanged.
- Metrics gate: guideline-specific fixtures preserve `presetAdherenceRate` and lower `noiseRate` when guidelines explicitly say to avoid a class of suggestion.

## Priority 6: Split Docs Content Freshness From Runtime Freshness

**Goal:** Avoid unnecessary stale states when docs guidance is merely refreshed without content changing.

### Current Gap

`DocsGuidanceResult::fingerprint()` includes `retrievedAt`. That is useful for diagnostics and source-health tracking, but it can make recommendation freshness sensitive to cache refresh timing even when the actual docs content and policy are unchanged.

### Proposed Split

Use two fingerprints:

- `docsContentFingerprint`: policy, status, coverage, source URL, source type, content hash, published date, and freshness label.
- `docsRuntimeFingerprint`: everything in the content fingerprint plus retrieved time, transport, cache/runtime status, and coverage check timestamps.

Use content fingerprint for recommendation applicability. Use runtime fingerprint for diagnostics and support screens.

Rollout note: existing cached applicability signatures that mixed content and runtime fields will become non-comparable once. Treat that one-time invalidation as expected migration behavior.

### Acceptance Criteria

- Two docs fetches with the same content hash do not stale recommendation apply contexts solely because `retrievedAt` changed.
- Docs diagnostics still show the latest retrieval time.
- Existing fail-closed docs-grounding behavior remains intact for unavailable or untrusted guidance.
- Metrics gate: stale false positives decrease for unchanged docs content with refreshed retrieval timestamps.

## Priority 7: Enrich Pattern Index Metadata And Component Scores

**Goal:** Make pattern recommendations less dependent on semantic similarity alone.

### Current State

Pattern index text and LLM candidate payloads already include title, description, categories, block types, template types, inferred traits, Pattern Overrides metadata, structure summary, content preview, and content block count.

### Proposed Metadata

Add structured design metadata during indexing:

```json
{
  "sectionRole": "hero|feature-grid|footer|pricing|testimonial|query-listing|cta|unknown",
  "layoutShape": "single-column|two-column|grid|media-left|media-right|cover|card-deck|unknown",
  "visualDensity": "sparse|balanced|dense|unknown",
  "colorMood": "light|dark|contrast|accent|image-heavy|unknown",
  "typographyRole": "editorial|marketing|navigation|metadata-heavy|unknown",
  "interactionRole": "navigation|form|link-collection|static-content|unknown",
  "templateAreaAffinity": "header|footer|sidebar|content|root|unknown",
  "styleTokenUsage": ["base", "contrast", "accent"],
  "blockComplexity": "low|medium|high",
  "contentSpecificity": "generic|topic-specific"
}
```

### Component Score

Use component scores before LLM reranking:

```text
score = (0.45 * semantic)
      + (0.25 * structure)
      + (0.15 * design)
      + (0.10 * area)
      + (0.05 * override)
```

The LLM reranker should receive those component scores and ranking hints, not only a single candidate score.

Use `ranking.rankingHint.componentScores` as the round-trip field for component scores. That keeps the work inside the existing `rankingHint` seam exposed by `RankingContract` and avoids inventing a second pattern-ranking metadata channel.

### Implementation Seams

- Pattern index text and payload metadata: `inc/Patterns/PatternIndex.php`
- Qdrant retrieval: `inc/Patterns/Retrieval/QdrantPatternRetrievalBackend.php`
- Cloudflare AI Search retrieval: `inc/Patterns/Retrieval/CloudflareAISearchPatternRetrievalBackend.php`
- Candidate shaping and reranking: `inc/Abilities/PatternAbilities.php`

### Acceptance Criteria

- Pattern ranking reasons can cite design compatibility, structure compatibility, template-area fit, and Pattern Overrides separately.
- Component scores round-trip through `ranking.rankingHint.componentScores`.
- Existing browse/rank-only pattern surface stays bounded. This does not add pattern apply, undo, or a new review lane.
- Metrics gate: pattern fixtures improve or preserve top-three relevance while preserving visible-scope and readable-synced-pattern filtering behavior.

## Priority 8: Expand The Offline Evaluation Harness

**Goal:** Expand the Phase 0 stub into a broader quality harness after the first implementation slices prove the core metrics are useful.

### Fixtures

Add fixtures that are intentionally broader than the Phase 0 stub:

- Hero cover with image overlay.
- Paragraph inside content-only template.
- Navigation in header.
- Query loop in archive template.
- Footer template part.
- Global Styles typography target.
- Style Book block target.
- Pattern insertion inside constrained group.
- Pattern insertion near custom block with Pattern Overrides.
- Template with empty and assigned template-part slots.
- Template part with locked, content-only, and nested insertion targets.
- Already-good Global Styles and Style Book contexts where the expected output is no recommendation.
- Guideline-sensitive fixtures where a brand/design rule should suppress a class of suggestion.
- Docs-freshness fixtures where `retrievedAt` changes but content hashes do not.
- Pattern fixtures covering visible-scope filtering, readable synced-pattern filtering, and design metadata/component-score tie breaking.

### Expanded Metrics

Add:

- Contrast pass or preserved-contrast rate.
- Top-three relevance.
- Stale false positives and false negatives.
- Provider-to-provider ranking stability.
- Prompt token delta by surface.

### Implementation Seams

- PHP fixtures near current ability tests in `tests/phpunit/*AbilitiesTest.php`.
- JS fixtures near `src/context/__tests__/collector.test.js` and store/actionability tests.
- Browser coverage in `tests/e2e/flavor-agent.smoke.spec.js` only for user-visible regressions.

### Acceptance Criteria

- A prompt or ranking change can be compared against baseline metrics.
- Known-good contexts produce fewer noisy suggestions.
- Known-bad contexts produce higher-ranked actionable suggestions.
- Prompt token deltas are visible per surface before expanding prompt sections again.

## Suggested Implementation Order

Status note (updated 2026-06-04): Phases 0, 1, and 2 are shipped, along with Contextual Ranking V1 (filled Priority 2's `context` blend component, absorbed a slice of Phase 3 via validation/no-op/stale-docs penalties, and seeded part of Phase 6 via pattern-surface contextual scoring). Phase 3 (Validation Feedback And Diagnostics) shipped 2026-06-04 via #29 (`c2a22f5`). Archived plans live under `docs/reference/archive/` and `docs/superpowers/plans/archive/`. Phases 4–7 remain unshipped.

### Phase 0: Measurement Stub

- [x] Add fixture payloads for common block, style, template, and pattern contexts.
- [x] Add deterministic metric output for `invalidOperationRate`, `presetAdherenceRate`, `noOpRate`, and `noiseRate`.
- [x] Keep provider calls mocked or fixture-backed.
- [x] Record baseline output before changing ranking weights or prompt sections.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluation|PromptBudgetTest'
npm run test:unit -- --runInBand src/context/__tests__/collector.test.js
npm run check:docs
git diff --check
```

### Phase 1: Ranking And Schema Hardening

- [x] Add optional `ranking` objects to strict LLM schemas.
- [x] Add composite ranking helpers to `RankingContract`, reusing `derive_score()` as the deterministic component seam.
- [x] Update block/style/template/template-part/navigation parsers to compute weighted scores.
- [x] Add parser tests for high-confidence vague suggestions versus lower-confidence executable suggestions.
- [x] Re-run Phase 0 metrics and record the expected movement: lower `noOpRate`, no higher `invalidOperationRate`, and flat-or-better `presetAdherenceRate`.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|ResponseSchemaTest|RankingContractTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|TemplatePartPromptTest|NavigationAbilitiesTest'
npm run check:docs
git diff --check
```

### Phase 2: Design Semantics Summary

- [x] Derive block-level `designSemantics` from collector visual hints, parent context, sibling summaries, theme tokens, and operation constraints.
- [x] Normalize/sanitize server-side design semantics.
- [x] Add prompt sections for block, template, and template-part surfaces.
- [x] Reuse existing style `designSemantics` instead of creating a parallel style-only schema.
- [x] Include the normalized semantic projection in resolved/review freshness signatures.
- [x] Update the local context-signature builders for block, template, template-part, Global Styles, and Style Book so semantic changes can stale stored results before server apply/review checks run.
- [x] Add PromptBudget assertions for each new prompt section.
- [x] Re-run Phase 0 metrics and record the expected movement: no higher `noiseRate` and no higher `invalidOperationRate`.

**Verification:**

```bash
npm run test:unit -- --runInBand src/context/__tests__/collector.test.js
composer run test:php -- --filter 'RecommendationEvaluationTest|BlockAbilitiesTest|PromptGuidanceTest|StylePromptTest|TemplatePromptTest|TemplatePartPromptTest'
npm run test:unit -- --runInBand src/utils/__tests__/recommendation-design-semantics.test.js src/utils/__tests__/block-recommendation-context.test.js src/templates/__tests__/template-recommender-helpers.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/template-part-recommender-helpers.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/utils/__tests__/style-operations.test.js
npm run check:docs
git diff --check
```

### Phase 3: Validation Feedback And Diagnostics

Shipped 2026-06-04 via #29 (`c2a22f5`) as a versioned cross-surface `validation-reasons-v1` signal — design in `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md`, implementation in the matching plan. The design spec supersedes the validation half of the original checklist below; boxes are checked against what actually landed.

- [x] Normalize validation reasons across executable surfaces.
- [x] Include validation state in ranking components.
- [x] Preserve advisory remnants when executable operations are rejected but the design advice remains useful.
- [x] Persist concise diagnostic reasons for request-time activity records only when validation changes actionability, ranking, empty-result explanation, downgrade state, or rejection state.
- [x] Re-run Phase 0 metrics and record the expected movement: rejected executable operations lower ranking score without increasing `invalidOperationRate`.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|TemplatePartPromptTest|RecommendationAbilityExecutionTest'
npm run test:unit -- --runInBand src/store/__tests__/store-actions.test.js src/store/__tests__/executable-surface-runtime.test.js
npm run check:docs
git diff --check
```

### Phase 4: Guidelines Freshness

- [ ] Derive a stable guidelines fingerprint from a normalized structured guideline projection.
- [ ] Pass that fingerprint into ability execution input.
- [ ] Include the fingerprint in resolved/review signatures for block, style, template, template-part, and navigation.
- [ ] Add a pattern-specific guidelines freshness signal, or add a deliberate `resolveSignatureOnly` contract for pattern recommendations before asserting signature-only parity.
- [ ] Add signature-only tests proving guidelines changes stale old results on signature-backed surfaces.
- [ ] Add pattern tests proving pattern-relevant guidelines change the chosen pattern freshness signal.
- [ ] Add tests proving whitespace-only guideline changes do not stale old results.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|GuidelinesTest|RecommendationAbilityExecutionTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|PatternAbilitiesTest|NavigationAbilitiesTest'
npm run check:docs
git diff --check
```

### Phase 5: Docs Fingerprint Split

- [ ] Add content and runtime fingerprint helpers to `DocsGuidanceResult`.
- [ ] Use content fingerprints in recommendation applicability signatures.
- [ ] Keep runtime fingerprints in diagnostics and public docs-grounding summaries.
- [ ] Add tests proving identical content with different `retrievedAt` does not stale applicability.
- [ ] Treat the first rollout as a one-time invalidation of old mixed content/runtime applicability signatures.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|DocsGuidanceResultTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|PatternAbilitiesTest'
npm run check:docs
git diff --check
```

### Phase 6: Pattern Metadata And Component Ranking

- [ ] Extend `PatternIndex::infer_layout_traits()` or add a sibling metadata extractor for structured design metadata.
- [ ] Store metadata in Qdrant point payloads and Cloudflare AI Search-indexed documents.
- [ ] Compute semantic, structure, design, area, and override component scores.
- [ ] Pass component scores to the LLM reranker and expose them through `ranking.rankingHint.componentScores`.
- [ ] Preserve visible pattern scope and readable synced-pattern filtering.
- [ ] Re-run Phase 0 metrics and record the expected movement: preserve visible-scope safety and improve or preserve pattern top-three relevance once that expanded metric exists.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|PatternAbilitiesTest|PatternIndexTest|PatternRetrievalBackendFactoryTest|CloudflarePatternSearchInstanceManagerTest'
npm run test:unit -- --runInBand src/patterns/__tests__/recommendation-utils.test.js src/patterns/__tests__/PatternRecommender.test.js
npm run check:docs
git diff --check
```

### Phase 7: Expanded Evaluation Harness

- [ ] Add contrast pass or preserved-contrast metrics.
- [ ] Add top-three relevance metrics.
- [ ] Add stale false-positive and false-negative metrics.
- [ ] Add prompt token delta by surface.
- [ ] Add comparison output for prompt and ranking experiments.
- [ ] Keep provider calls mocked or fixture-backed unless explicitly running live evaluation.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluation'
npm run test:unit -- --runInBand
npm run check:docs
git diff --check
```

## Risk Controls

- Do not widen pattern recommendations into apply/undo semantics while improving rank quality.
- Do not let docs guidance crowd out local design context for purely visual suggestions.
- Do not hash volatile diagnostics or labels into applicability signatures.
- Do not hash raw guideline text when a normalized structured guideline projection is available.
- Do not remove fail-closed docs-grounding behavior for API/currentness-sensitive recommendations.
- Do not make model ranking authoritative over validator results.
- Do not let added prompt sections bypass `PromptBudget` caps.
- Do not persist routine successful validation details as diagnostics.
- Do not create a second style semantics system when `StylePrompt` already has `designSemantics`.
- Do not claim a metrics gate passed without running `RecommendationEvaluationTest` in the same phase verification.
- Do not claim pattern `resolveSignatureOnly` freshness parity unless the pattern ability actually exposes that input or an intentionally separate pattern freshness signal is documented and tested.

## Practical Stop Line

The first useful milestone is complete when:

- the fixture-backed Phase 0 metrics stub exists and has baseline output;
- strict LLM schemas can accept structured ranking;
- composite ranking prevents confidence-only suggestions from dominating;
- block/style/template/template-part prompts include explicit `designSemantics` summaries;
- guideline changes participate in freshness signatures;
- validation reasons are available for ranking and diagnostics;
- docs retrieval refreshes do not stale unchanged guidance content;
- targeted PHP and JS tests cover those contracts;
- each phase can report at least one metric movement or preservation target.

After that, pattern metadata enrichment and expanded evaluation metrics become the next strategic improvements.
