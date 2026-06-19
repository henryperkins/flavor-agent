# Governance Learning Report -- Design

- **Date:** 2026-06-19
- **Status:** Proposed design for review.
- **Owner:** Henry Perkins

## Problem

`docs/reference/current-open-work.md` keeps "Admin activity governance deepening and learning reports" open because `Settings > AI Activity` is a per-row approval, audit, and provenance console, not a report surface yet. Phase 8 shipped the bounded `learningAttribution` join contract, but Phase 9 still needs a read-only report layer that explains local recommendation outcomes before any ranking feedback, fixture harvest, or editable preference work can be considered.

The first report must stay governance-safe: local to the site, admin-only, bounded, and sanitized. It should make outcome patterns visible without exposing raw prompts, full block trees, provider payloads, generated text, or pattern content.

## Goals

- Add a report-only `learningReport` payload to global admin activity reads when explicitly requested.
- Surface the report in `Settings > AI Activity` under the existing summary cards.
- Explain outcome rates by surface, operation type, provider/model, validation reason, guideline version, ranking signal, and pattern trait.
- Persist only sanitized pattern trait slugs needed for trait reporting.
- Preserve the rule that `shown` is exposure only, not approval, rejection, or quality evidence.
- Keep the implementation bounded enough for targeted PHPUnit and admin Jest coverage.

## Non-Goals

- No automatic ranking changes.
- No fixture export.
- No editable preference summaries.
- No cross-site learning, hidden model memory, fine-tuning, or provider-specific adaptation.
- No raw prompt, provider payload, pattern content, full context, or full block-tree persistence for reports.
- No new rich visual diff viewer, notification workflow, or editor-side pending-apply visibility.

## Current Seams

- `FlavorAgent\Activity\Repository::query_admin()` already owns global admin activity data, pagination, summaries, filters, and projection-backed queries.
- `FlavorAgent\REST\Agent_Controller::handle_get_activity()` already gates global activity reads through `manage_options`.
- `FlavorAgent\Activity\RecommendationOutcomeMetrics` already calculates the first flat outcome metrics, but it does not cover undo rates, insert failures, adapted pattern outcomes, ranking-signal correlation, guideline-version groups, provider/model groups, or pattern traits.
- `FlavorAgent\Activity\RecommendationOutcome` is the server trust boundary for persisted outcome rows.
- `src/store/recommendation-outcomes.js` is the client builder for shown, selected, blocked, inserted, and failed outcome rows.
- `src/admin/activity-log.js` already renders server-provided summary cards and owns the admin request URL.

## Report Contract

The global activity REST response gains an optional top-level field when the admin request includes `includeReports=1`:

```json
{
	"learningReport": {
		"version": "governance-learning-report-v1",
		"generatedAt": "2026-06-19T00:00:00Z",
		"sampleSize": 125,
		"rowLimit": 500,
		"truncated": false,
		"summary": {
			"shownCount": 12,
			"reviewSelectionRate": 0.25,
			"applyConversionRate": 0.16,
			"undoRate": 0.08,
			"validationBlockedRate": 0.1,
			"insertFailedRate": 0.03
		},
		"groups": {
			"surfaces": [],
			"operationTypes": [],
			"providerModels": [],
			"validationReasons": [],
			"guidelineVersions": [],
			"rankingSignals": [],
			"patternTraits": []
		}
	}
}
```

Each group row uses the same compact shape:

```json
{
	"key": "hero-banner",
	"label": "Hero banner",
	"sampleSize": 8,
	"shownCount": 5,
	"selectedForReviewCount": 2,
	"appliedCount": 1,
	"undoneCount": 0,
	"staleBlockedCount": 0,
	"validationBlockedCount": 1,
	"insertFailedCount": 0,
	"reviewSelectionRate": 0.4,
	"applyConversionRate": 0.2,
	"undoRate": 0,
	"validationBlockedRate": 0.2,
	"representativeActivityId": "activity_123"
}
```

Counts are integers. Rates are rounded to four decimal places. Missing or unsupported dimensions return empty arrays, not placeholder strings.

## Bounded Query Strategy

The report should not scan unbounded JSON history on every admin page load. The first implementation uses the current admin filters and a fixed newest-first report window:

- Default `rowLimit`: 500 matching rows.
- Maximum report limit: 1000 matching rows if a filter increases it later.
- Response includes `sampleSize`, `rowLimit`, and `truncated`.
- The report uses the same global activity permission path as the admin page.
- Current page pagination remains independent from the report sample.

The summary cards continue to represent the full filtered result set, as they do today. The learning report represents a bounded recent sample and must label that contract through the metadata.

## Pattern Trait Persistence

Pattern trait reporting is included in v1. To make it durable, the client outcome payload should carry sanitized pattern traits at the moment the pattern recommendation outcome is recorded.

Allowed placement:

- For `shown` pattern outcomes: each `rankingSet` item may include `patternTraits`.
- For engaged pattern outcomes (`pattern_inserted_from_shelf`, `insert_failed`, `adapted_preview_shown`, `adapted_inserted_from_preview`, `adaptation_blocked`, `adapted_insert_failed`): the single outcome may include `patternTraits`.

Allowed values are compact slugs already produced by `PatternIndex::infer_layout_traits()`, such as `hero-banner`, `multi-column`, `gallery`, `call-to-action`, `query-loop`, `media-text`, `navigation`, `search`, `branding`, `social`, `simple`, `moderate-complexity`, `complex`, `media-rich`, `text-focused`, `mixed-content`, `site-chrome`, `testimonial`, `team-or-about`, `showcase`, `pricing`, and `contact`.

`RecommendationOutcome::normalize_entry()` must sanitize, dedupe, and cap these arrays. It must not accept pattern content, content previews, raw categories outside the existing recommendation response contract, block markup, provider payloads, or generated text for this report.

## Aggregation Rules

Use a dedicated report builder rather than expanding `RecommendationOutcomeMetrics` into an admin-report class. The metrics class can stay focused on flat outcome ratios; the new builder can use it where appropriate and own group rows, representative ids, and report metadata.

Suggested class:

- `FlavorAgent\Activity\GovernanceLearningReport`

Inputs:

- hydrated activity entries from the bounded report query;
- resolved admin status metadata when available;
- the same filters used for `query_admin()`.

Rules:

- `shown` increments exposure only.
- `selected_for_review` increments weak positive review engagement.
- apply rows and pattern insert outcomes increment applied/inserted counts.
- apply rows whose resolved status is `undone` increment undone counts.
- `validation_blocked` increments validation-blocked counts and groups by validation reason.
- `stale_blocked` increments context-drift counts but should not be treated as recommendation-quality failure by itself.
- `insert_failed` and `adapted_insert_failed` increment insert-failed counts.
- ranking-signal groups are built from persisted `contextEvidence` and `contextPenalties` keys, plus `rankingVersion` where present. Do not claim full `sourceSignals` reporting unless that field becomes explicitly persisted in sanitized outcome rows.
- provider/model and guideline version are read from `learningAttribution` first, then from existing request/admin metadata where safe.
- representative ids point to existing activity rows; reports do not embed row payloads.

## REST And UI

REST:

- Add an `includeReports` boolean arg to `GET /flavor-agent/v1/activity`.
- Only global admin requests can receive `learningReport`.
- Scoped editor reads ignore `includeReports`.
- Malformed report inputs should fail closed to no report data rather than exposing raw row payloads.

Admin UI:

- `src/admin/activity-log.js` appends `includeReports=1` for the AI Activity admin request.
- Store the server `learningReport` beside `summary`, `filterOptions`, and pagination data.
- Render a compact "Governance learning report" section under the existing summary grid.
- Use existing admin card styling patterns. No nested cards.
- Show a report metadata line when the sample is truncated.
- Group rows link to the representative activity by reusing the existing `activity` query parameter.

## Privacy And Capability Guardrails

- Admin page and global report stay behind `manage_options`.
- No report route for external agents.
- No raw prompt or provider payload in report output.
- No full pattern content, content preview, or block tree in report output.
- Pattern traits are sanitized slugs only.
- `learningAttribution` remains join metadata, not a freshness input.
- Guideline version is for attribution and comparison only; changing guidelines must not stale old recommendations.

## Testing

PHP:

- `RecommendationOutcomeTest`: pattern traits are sanitized, capped, persisted for shown ranking-set items, and persisted for engaged pattern outcomes.
- `RecommendationOutcomeMetrics` or new report-builder tests: report counts and rates cover shown, selected, applied, undone, validation-blocked, stale-blocked, insert-failed, adapted pattern events, provider/model, guideline version, ranking signal, and pattern trait groups.
- `ActivityRepositoryTest`: `query_admin()` returns `learningReport` only when requested, respects the report row limit, marks `truncated`, and does not disturb pagination or summary counts.
- `AgentControllerTest`: global `includeReports=1` returns the report for `manage_options`; scoped reads do not expose it.

JS:

- `recommendation-outcomes.test.js`: pattern outcomes carry sanitized trait slugs and omit raw content.
- `activity-log.test.js`: the admin request includes `includeReports=1`, renders report metadata and group rows, and links representative rows.
- `activity-log-utils.test.js`: any report normalization helper handles missing, malformed, and truncated report data.

Docs and checks:

```bash
composer run test:php -- --filter 'RecommendationOutcomeMetrics|GovernanceLearningReport|ActivityRepository|AgentControllerTest|RecommendationOutcomeTest'
npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js
npm run check:docs
git diff --check
```

## Documentation Updates After Implementation

- `docs/features/activity-and-audit.md`: document the new report-only governance layer.
- `docs/reference/governance-layer.md`: add the report as aggregate evidence, distinct from approval.
- `improving-levers.md`: mark Phase 9 report rows that are completed by this slice and leave fixture harvest/ranking feedback open.
- `docs/reference/current-open-work.md`: update the active queue without implying Phase 10+ completion.
- `STATUS.md` and `docs/SOURCE_OF_TRUTH.md`: summarize the shipped report and its limits.

## Risks

- Report-time JSON hydration can become expensive if it is unbounded. The v1 sample window and truncation metadata are part of the product contract, not an implementation detail.
- Pattern traits may be missing for older outcome rows. The report should simply omit those rows from `patternTraits` groups.
- Operation type can be ambiguous for outcome rows that only record `recommendation_outcome`. Prefer apply rows, request metadata, and learning attribution when available; otherwise leave the operation group blank.
- Ranking-signal reporting is limited to persisted evidence and penalty keys. If full `sourceSignals` are needed later, add a separate sanitized persistence change.

## Acceptance Criteria

- Admins see a bounded governance learning report in `Settings > AI Activity`.
- The report explains local outcomes by the Phase 9 dimensions, including pattern traits.
- Report output contains only counts, rates, labels, metadata, and representative activity ids.
- Existing summary cards, filters, pagination, approvals flow, and per-row governance evidence keep working.
- Tests cover the report contract, trait persistence, permission boundary, and UI rendering.
