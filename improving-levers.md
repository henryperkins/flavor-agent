# Improving Recommendation Levers

**Scope:** Actionable exploration for making Flavor Agent recommendations more contextual, WordPress-native, design-minded, and freshness-aware.

**Repo baseline checked:** June 6, 2026, against the live `/home/dev/flavor-agent` tree.

## Current implementation state

As of 2026-06-06, this file is a roadmap and execution plan, not an achieved contract document.

- Shipped in this codebase: Phases 0, 1, 2, and 3, plus Contextual Ranking V1 and the Phase 4 request-diagnostic guideline attribution id.
- Remaining unshipped work: engaged outcome attribution for the future learning loop, docs freshness split (Phase 5), pattern metadata and component-score ranking (Phase 6), expanded validators (Priority 4), and the larger Phase 7+ measurement / learning loop.
- Priority 5 remains scoped to attribution metadata rather than a production stale gate, consistent with current guidance in this file and recent implementation evidence.
- Adapted pattern preview is a related product-surface outline, not an implemented part of this roadmap. If pursued, it should build on the unshipped pattern relevance work here and the shared deterministic mutation engine described in `docs/features/pattern-recommendations-adapted-preview.md`.

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

## Priority 5: Tie Guidelines To Recommendations For Attribution

**Goal:** Keep structuring guidelines for prompt steering, and stamp the guideline version that produced each recommendation request onto request-diagnostic activity metadata — as **attribution metadata for the future learning loop, not a freshness or staleness input**.

> Re-scoped 2026-06-04. The original "guideline freshness" goal (make guideline edits stale prior recommendations) was dropped after review — see Current Gap below.

### Current Gap And Scope Correction (2026-06-04)

Guidelines are formatted as text by `Guidelines::format_prompt_context()` and injected by `RecommendationAbilityExecution::execute_with_system_instruction()`, so they already steer generation. Every fresh generation reads current guidelines.

An earlier version of this priority proposed feeding a guidelines fingerprint into recommendation *freshness* signatures so guideline edits would stale prior recommendations. **That freshness goal was dropped.** Recommendations are generated on demand and live only for the session/selection; for guideline-change invalidation to ever fire, the same unapplied recommendation would have to survive a server-side guideline edit mid-flow — a real-world non-issue — and a global guideline fingerprint used as a staleness input would also over-invalidate unrelated recommendations. Guideline-as-staleness is therefore explicitly out of scope.

The genuine, future-facing need is **attribution**. When the live learning loop (see the Phase 3 reframing) consumes apply / undo / ignore outcomes, two recommendations produced in the same context under different guideline sets must not be conflated. Recording the guideline version that produced each recommendation request keeps outcomes attributable to their constraint set and enables before/after measurement when guidelines change. The version id is a fingerprint of a normalized structured projection (not raw text), so whitespace-only or ordering-only edits do not change it — only semantic changes do.

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

- Version-id source: `Guidelines::version_id()` computes a `gv1:` fingerprint from the normalized structured guideline projection at generation time, so cosmetic edits do not change it and no persisted option is required.
- Stamp at generation: `inc/Abilities/RecommendationAbilityExecution.php` records the in-effect id onto request-diagnostic rows for recommendation requests.
- Activity persistence: `inc/Activity/Repository.php` and `inc/Activity/Serializer.php` already carry the id through the request JSON payload; no schema column is required for the current attribution seam.
- Future outcome propagation: if the learning loop starts consuming engaged outcome/apply rows as the primary signal, copy the originating request's `guidelineVersion` forward rather than recomputing the then-current guideline id.
- Explicitly **not** touched: resolved/review freshness signatures (`inc/Support/RecommendationResolvedSignature.php`, `inc/Support/RecommendationReviewSignature.php`). The id is never a freshness input.

### Acceptance Criteria

- A stable **guideline version id** is derived from the normalized structured guideline projection. Whitespace-only or ordering-only edits do not change it; only semantic changes do.
- Each recommendation request's request-diagnostic activity row carries the guideline version id that was in effect at generation, as attribution metadata.
- The id is attribution-only: it is **not** part of any resolved/review freshness signature, does **not** change `resolveSignatureOnly`, and does **not** stale or regenerate prior recommendations on any surface.
- Existing behavior remains compatible with upstream `WordPress\AI\format_guidelines_for_prompt()` when available.
- Tests prove: the id is recorded on request-diagnostic rows; semantic guideline changes change it; whitespace/order-only changes do not.
- Future learning-loop work should add tests proving engaged outcome/apply rows preserve the originating request's id and that no freshness signature or `resolveSignatureOnly` result changes when guidelines change.
- The prompt-steering behavior is unchanged — guidelines still shape generation exactly as today; this priority only adds recorded attribution.

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

## Priority 9: Build A Site-Local Learning Layer

**Goal:** Turn Flavor Agent's own activity and outcome records into explainable, site-local ranking evidence before considering any model fine-tuning, hidden memory, or provider-specific adaptation.

### Operating Principles

- Learning stays local to the WordPress site unless an operator explicitly exports fixtures.
- The first learning layer is read/report-only. It explains outcomes before it changes ranking.
- Aggregates and derived preference summaries are preferred over raw prompts, full block trees, or provider payloads.
- Every future score adjustment must be traceable to a local outcome, validation reason, ranking component, guideline version, docs fingerprint, pattern trait, or operation type.
- `shown` is exposure, not approval. Strong learning signals come from selected-for-review, apply/insert, undo, validation-blocked, stale-blocked, and failed insertion events.
- Derived preferences should become explicit, editable site guidance instead of invisible model memory.

### Attribution Contract

The learning layer needs a generation-level join contract that is stable across PHP-created request diagnostics, JS-created outcome rows, and apply/undo rows:

```json
{
  "generationId": "recgen:template:...",
  "recommendationSetId": "set:template:...",
  "surface": "block|content|pattern|template|template-part|navigation|global-styles|style-book",
  "ability": "flavor-agent/recommend-template",
  "sourceRequestSignature": "...",
  "guidelineVersion": "gv1:...",
  "docsContentFingerprint": "...",
  "provider": "anthropic",
  "model": "claude-sonnet-4-6",
  "rankingVersion": "contextual_ranking_v1",
  "validationVocabularyVersion": "validation-reasons-v1"
}
```

Keep this metadata small and join-oriented. The request-diagnostic row can hold the richest generation metadata; engaged outcome/apply rows should carry the stable join keys and the originating attribution ids needed to query back to the request when details are required.

### Outcome Signal Weights

Start with conservative, auditable weights:

- `shown`: exposure count only.
- `selected_for_review`: weak positive signal.
- `applied` / `pattern_inserted_from_shelf`: strong positive signal.
- `undo`: strong negative signal for the operation/context pair, not necessarily for the whole surface.
- `validation_blocked`: negative safety signal tied to the validation reason and operation type.
- `stale_blocked`: context-drift signal; do not penalize the recommendation quality without corroborating evidence.
- `insert_failed`: negative execution signal tied to pattern insertability or target context.
- ignored-after-shown: weak negative only after a replacement request, entity switch, or enough elapsed time; never infer rejection from a single impression.

### Learning Outputs

The first useful outputs are operator/developer reports, not automatic behavior changes:

- Surface health: exposure, review, apply, undo, and blocked rates by surface.
- Operation health: apply/undo/block rates by operation type and validation reason.
- Ranking health: which `ranking.sourceSignals` correlate with review/apply/undo.
- Pattern health: top-three relevance, insert failures, visible-scope misses, and trait/component-score correlations.
- Guideline health: outcome changes across `guidelineVersion` values without using the id as freshness.
- Provider/model health: model/provider outcome differences after normalizing by surface and operation type.
- Fixture harvest: high-signal clusters that should become offline evaluation fixtures.

### Implementation Seams

- Join keys and attribution metadata: `src/store/recommendation-outcomes.js`, `src/store/activity-undo.js`, `inc/Abilities/RecommendationAbilityExecution.php`, and `inc/Activity/RecommendationOutcome.php`.
- Aggregation/query layer: `inc/Activity/Repository.php`, `inc/Activity/RecommendationOutcomeMetrics.php`, and `inc/REST/Agent_Controller.php`.
- Admin reports: `src/admin/activity-log.js` and `src/admin/activity-log-utils.js`, gated behind `manage_options`.
- Offline fixture harvest: `tests/phpunit/RecommendationEvaluationTest.php` and `tests/phpunit/fixtures/recommendation-evaluation-*`.
- Ranking feedback application: `inc/Support/RankingContract.php`, surface parsers, and `inc/Support/RecommendationContextScorer.php`.
- Preference surfacing: `Guidelines::format_prompt_context()` and the settings/admin guideline save path, with explicit operator review before any derived preference becomes prompt guidance.

### Acceptance Criteria

- A server-minted generation id joins request-diagnostic rows to shown, selected-for-review, apply, undo, and blocked/failed outcome rows.
- Learning reports can explain outcome rates by surface, operation type, validation reason, ranking signal, guideline version, provider/model, and pattern trait without exposing raw provider payloads.
- No automatic ranking change ships before the report-only layer and fixture-harvest path prove that the aggregate signal is useful.
- Fixture generation is explicit and reviewable; private site content is not exported by default.
- Any ranking adjustment is bounded, versioned, and visible in `ranking.sourceSignals` or diagnostics.

## Suggested Implementation Order

Status note (updated 2026-06-06): Phases 0, 1, 2, and 3 are shipped, along with Contextual Ranking V1 (filled Priority 2's `context` blend component, absorbed validation/no-op/stale-docs penalties, and seeded part of Phase 6 via pattern-surface contextual scoring). Phase 3 (Validation Feedback And Diagnostics) shipped 2026-06-04 via #29 (`c2a22f5`); its implementation plan is archived at `docs/superpowers/plans/archive/2026-06-04-phase-3-validation-feedback.md`. Archived plans live under `docs/reference/archive/` and `docs/superpowers/plans/archive/`. Phase 4's initial request-diagnostic attribution seam is shipped, but engaged outcome propagation remains future learning-loop work. Phases 5-12 remain unshipped. Re-sequenced 2026-06-04: Priority 5 / Phase 4 was re-scoped from "guideline freshness" to a small "guideline attribution id" seam (guideline-as-staleness dropped as a real-world non-issue) and demoted; the higher felt-value work - pattern relevance (Phase 6 / Priority 7) and the still-unshipped design validators (Priority 4) - should come first.

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

Shipped 2026-06-04 via #29 (`c2a22f5`) as a versioned cross-surface `validation-reasons-v1` signal — design in `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md`, implementation plan archived at `docs/superpowers/plans/archive/2026-06-04-phase-3-validation-feedback.md`. The design spec supersedes the validation half of the original checklist below; boxes are checked against what actually landed.

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

### Phase 4: Guideline Attribution Id (demoted — future-proofing, not a milestone gate)

Re-scoped 2026-06-04 from "Guidelines Freshness." Guideline-as-staleness was dropped as a real-world non-issue (see Priority 5); this is now a small attribution seam. Sequence it **after** the felt-value work (pattern relevance, design validators) — do it when the learning loop is actually on the horizon.

- [x] Derive a stable guideline version id from the normalized structured guideline projection at generation time; cosmetic edits do not change it.
- [x] Stamp the in-effect id onto request-diagnostic activity rows for recommendation requests.
- [x] Persist and expose the id through the existing request JSON payload carried by the activity repository/serializer.
- [x] Tests: id recorded on request-diagnostic rows; semantic changes alter the id; whitespace/order-only changes do not.
- [ ] Future learning-loop work: propagate the originating request id to engaged outcome/apply rows if those rows become the attribution source, and prove no freshness signature or `resolveSignatureOnly` result changes when guidelines change.

**Verification:**

```bash
composer run test:php -- --filter 'Guidelines|RecommendationAbilityExecution|Activity|Serializer'
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

### Phase 8: Learning Attribution Join Contract

- [ ] Mint a server-side `generationId` for each non-signature-only recommendation request.
- [ ] Carry `generationId`, `recommendationSetId`, `sourceRequestSignature`, `guidelineVersion`, docs content/runtime fingerprints, provider/model, ranking version, and validation vocabulary version through request-diagnostic metadata.
- [ ] Propagate the originating generation/join ids into shown, selected-for-review, apply/insert, undo, stale-blocked, validation-blocked, and insert-failed rows.
- [ ] Keep the join payload bounded and avoid storing raw provider payloads, full block trees, or large prompt context in outcome rows.
- [ ] Add serializer and repository tests proving the join survives round-trips across diagnostic, outcome, apply, and undo rows.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationAbilityExecution|RecommendationOutcome|ActivitySerializer|ActivityRepository'
npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js src/store/__tests__/activity-undo.test.js src/store/__tests__/store-actions.test.js
npm run check:docs
git diff --check
```

### Phase 9: Learning Reports

- [ ] Add read-only aggregate queries for outcome rates by surface, operation type, validation reason, ranking signal, guideline version, provider/model, and pattern trait.
- [ ] Surface those aggregates in the admin activity UI behind `manage_options`.
- [ ] Treat `shown` as exposure only; avoid deriving ignored-after-shown negatives until replacement/time-window semantics are defined and tested.
- [ ] Keep reports bounded and sanitized; link to representative activity rows instead of embedding raw prompts or full context payloads.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationOutcomeMetrics|ActivityRepository|AgentControllerTest'
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
npm run check:docs
git diff --check
```

### Phase 10: Fixture Harvest From Learning Signals

- [ ] Identify high-signal clusters from local reports, such as frequent undo for raw color operations, repeated validation blocks by operation type, or pattern insert failures by trait.
- [ ] Add an explicit, operator/developer-controlled fixture export path that redacts or summarizes private content by default.
- [ ] Convert exported clusters into offline `RecommendationEvaluationTest` fixtures before changing ranking behavior.
- [ ] Record expected metric movement for each harvested fixture.

**Verification:**

```bash
composer run test:php -- --filter 'RecommendationEvaluation|RecommendationOutcomeMetrics'
npm run check:docs
git diff --check
```

### Phase 11: Bounded Local Ranking Feedback

- [ ] Apply site-local aggregate signals as small, capped ranking adjustments only after the report and fixture layers are in place.
- [ ] Version each adjustment family and expose it through `ranking.sourceSignals` or diagnostics.
- [ ] Penalize operation/context pairs with repeated undo or validation blocks; do not globally suppress a surface from sparse data.
- [ ] Boost only stable positives, such as repeated apply/review of preset-backed, validation-safe operations in similar contexts.
- [ ] Provide an operator switch or diagnostic setting to disable learned ranking adjustments for troubleshooting.

**Verification:**

```bash
composer run test:php -- --filter 'RankingContract|RecommendationEvaluation|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|PatternAbilitiesTest'
npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js
npm run check:docs
git diff --check
```

### Phase 12: Editable Site Preference Summaries

- [ ] Derive candidate preference summaries from repeated local outcomes, such as "prefer editorial patterns" or "avoid raw shadows on article content."
- [ ] Present candidates to operators as explicit guideline suggestions rather than silently injecting them into prompts.
- [ ] Record the guideline version before and after accepted preference changes so later reports can measure effect without using guideline ids for freshness.
- [ ] Keep rejected preference candidates out of future prompts unless the operator later accepts them.

**Verification:**

```bash
composer run test:php -- --filter 'Guidelines|RecommendationOutcomeMetrics|RecommendationAbilityExecution'
npm run check:docs
git diff --check
```

## Risk Controls

- Do not widen pattern recommendations into apply/undo semantics while improving rank quality.
- Do not let docs guidance crowd out local design context for purely visual suggestions.
- Do not hash volatile diagnostics or labels into applicability signatures.
- Do not hash raw guideline text when a normalized structured guideline projection is available.
- Do not turn the guideline version id into a freshness or staleness input. It is attribution metadata recorded on request diagnostics and future learning-loop outcomes; guideline edits must not stale, gate, or regenerate prior recommendations.
- Do not remove fail-closed docs-grounding behavior for API/currentness-sensitive recommendations.
- Do not make model ranking authoritative over validator results.
- Do not let added prompt sections bypass `PromptBudget` caps.
- Do not persist routine successful validation details as diagnostics.
- Do not create a second style semantics system when `StylePrompt` already has `designSemantics`.
- Do not claim a metrics gate passed without running `RecommendationEvaluationTest` in the same phase verification.
- Do not claim pattern `resolveSignatureOnly` freshness parity unless the pattern ability actually exposes that input or an intentionally separate pattern freshness signal is documented and tested.
- Do not treat a single `shown` event as approval, rejection, or quality evidence.
- Do not add hidden model memory, cross-site learning, or automatic fine-tuning from activity rows.
- Do not let learned ranking adjustments bypass validation, capability checks, or content-only/editing-mode restrictions.
- Do not turn derived preferences into prompt guidance without explicit operator review.

## Practical Stop Line

The first useful milestone through Phase 3 is complete when:

- the fixture-backed Phase 0 metrics stub exists and has baseline output;
- strict LLM schemas can accept structured ranking;
- composite ranking prevents confidence-only suggestions from dominating;
- block/style/template/template-part prompts include explicit `designSemantics` summaries;
- validation reasons are available for ranking and diagnostics;
- targeted PHP and JS tests cover those contracts;
- each phase can report at least one metric movement or preservation target.

After that, design validators, docs fingerprint splitting, pattern metadata enrichment, the site-local learning layer, and expanded evaluation metrics remain the next strategic improvements.
