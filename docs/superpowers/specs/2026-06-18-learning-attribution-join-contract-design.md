# Learning Attribution Join Contract Design

## Goal

Implement Phase 8's attribution prerequisite without adding reports, ranking feedback, fixture export, or UI. Every non-signature-only recommendation generation gets a server-minted `generationId`, and existing request diagnostic, recommendation outcome, apply, and undo rows preserve that id plus bounded join metadata.

## Scope

In scope:

- Mint one `generationId` for each non-`resolveSignatureOnly` recommendation request.
- Persist compact `learningAttribution` metadata on request diagnostic rows.
- Return the same attribution object to the editor so shown, selected-for-review, apply/insert, stale-blocked, validation-blocked, and insert-failed rows can carry it.
- Normalize the object server-side in `RecommendationOutcome` so outcome rows stay privacy-safe and bounded.
- Preserve the original apply row's attribution through undo transitions.
- Add PHP and JS tests for round-trip and propagation behavior.

Out of scope:

- Admin aggregate reports.
- Ranking or prompt behavior changes.
- Fixture export.
- New database columns or indexes.
- Raw prompts, full block trees, provider payloads, or generated text in outcome rows.

## Contract

`learningAttribution` is a bounded object. `generationId` is required whenever the object is present; all other fields are optional and sanitized.

```json
{
	"generationId": "recgen:template:01J...",
	"recommendationSetId": "template:12:hash_abc",
	"sourceRequestSignature": "hash_def",
	"guidelineVersion": "gv1:...",
	"docsContentFingerprint": "dcf1:...",
	"docsRuntimeFingerprint": "drf1:...",
	"provider": "wordpress_ai_client",
	"model": "provider-managed",
	"rankingVersion": "contextual-ranking-v1",
	"validationVocabularyVersion": "validation-reasons-v1"
}
```

`generationId` is the durable join key. `recommendationSetId` groups one returned set, and `suggestionKey` identifies an item within that set. Future reports should join engaged rows back to the request diagnostic row by `generationId`, then use set/suggestion keys for per-suggestion grouping.

## Architecture

`RecommendationAbilityExecution` owns server-side generation identity because every recommendation ability already passes through it. It appends a sanitized `learningAttribution` object to `requestMeta`, persists that object on request diagnostic rows, and leaves signature-only preview calls untouched.

The editor store treats the response attribution as inherited metadata. `decorateRecommendationPayload()` copies it into each suggestion's `recommendationOutcome`, `buildRecommendationOutcomeEntry()` writes it on diagnostic rows, and `getRecommendationIdentityForApply()` carries it into apply rows through the existing `request.recommendation` payload.

`RecommendationOutcome::normalize_entry()` is the server trust boundary for JS-created outcome rows. It allowlists and bounds the same fields before storing them under `after.outcome.learningAttribution` and `request.recommendation.learningAttribution`.

Undo remains a transition on the original activity row. It must not recompute attribution; preserving the stored `request.recommendation.learningAttribution` is the expected behavior.

## Data Placement

- Request diagnostics:
  - `request.learningAttribution`
  - `request.ai.learningAttribution`
- Recommendation outcomes:
  - `after.outcome.learningAttribution`
  - `request.recommendation.learningAttribution`
- Apply rows:
  - `request.recommendation.learningAttribution`
- Undo rows:
  - preserve the apply row's `request.recommendation.learningAttribution`

No schema migration is required because these fields live in existing JSON columns.

## Validation

PHP:

- `RecommendationAbilityExecutionTest` proves successful non-signature requests include and persist `generationId`.
- `RecommendationOutcomeTest` proves the normalizer preserves allowlisted attribution fields and drops oversized/unknown/private data.
- `ActivityRepository` or serializer coverage proves JSON round-trip preservation for diagnostic and apply-like rows.

JS:

- `recommendation-outcomes.test.js` proves response attribution decorates suggestions, builds shown outcomes, selected outcomes, blocked outcomes, and apply identities.
- `activity-undo.test.js` or store action coverage proves apply row attribution survives an undo transition.

Docs:

- Mark Phase 8 complete only after tests pass.
- Update `docs/reference/current-open-work.md` and `improving-levers.md` without promoting reports or ranking feedback.
