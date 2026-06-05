# Phase 3 Validation Feedback Design Review

**Source:** Review of `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md`  
**Date:** 2026-06-04

## Findings

- **High:** The outcome reason contract is internally inconsistent. The spec says `decorateRecommendationPayload()` should put the primary validation reason onto per-suggestion outcomes and that `selected_for_review` / apply / `validation_blocked` reasons are uncapped, but the current builders only carry identity from `suggestion.recommendationOutcome`; `reason` only comes from the explicit argument. Apply rows also use `getRecommendationIdentityForApply()`, which returns only identity fields. Existing review callers pass `reason: 'review_opened'`, so a literal implementation either drops validation reasons on selected/apply rows or overwrites existing event-reason/dedupe semantics. Clarify a separate `validationReason` field, or restrict the claim to `validation_blocked` plus `shown.rankingSet`.
  Refs: `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md:104`, `src/store/recommendation-outcomes.js:512`, `src/store/recommendation-outcomes.js:692`, `src/templates/TemplateRecommender.js:750`

- **Medium:** The signature invariance test requirement pushes the wrong boundary. The prose says to keep diagnostics out of request payloads or strip them at call sites, but the test surface asks raw `RecommendationSignature` / resolved / review payloads differing only by `validationReasons` to hash identically. Since `RecommendationSignature::from_payload()` hashes the normalized full payload, that test can only pass by teaching the core hash helper to globally ignore those keys. That broadens the signature contract beyond the stated call-site boundary. Test the sanitized call-site payloads instead, or explicitly make global key-stripping the design.
  Refs: `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md:113`, `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md:181`, `inc/Support/RecommendationSignature.php:9`

- **Medium:** The vocabulary is too narrow for the "specific non-block apply-time codes" acceptance criterion. Style apply can fail for preset metadata/reference mismatch, missing or invalid freeform values, unavailable presets, missing/unregistered Style Book block targets, unavailable theme variation, and no executable operations, but the spec only defines `unsupported_scope`, `unsupported_path`, and `failed_contrast` for style plus the generic fallback. Template validation has similar unmapped branches for malformed operations, duplicate area mutation, same-slug no-op, invalid placement, and repeated pattern inserts. Without a complete mapping table and tests, many deterministic failures will still collapse to `operation_validation_failed`.
  Refs: `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md:64`, `src/utils/style-operations.js:1017`, `inc/LLM/TemplatePrompt.php:1141`

- **Low:** The `getSuggestionKey()` guard points to the wrong path. The spec cites `suggestion-keys.js` as if it were under `src/store`, but the actual implementation is `src/inspector/suggestion-keys.js`. That should be corrected before the plan is handed off so the exclusion guard lands on the real key function.
  Refs: `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md:112`, `src/inspector/suggestion-keys.js:37`

No code changes made during the review.
