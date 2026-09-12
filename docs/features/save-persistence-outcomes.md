# Save persistence outcomes

Editor Apply changes the open document. It does not save it. New block, structural block, template, template-part, Global Styles, and Style Book apply notices say that the change is in the editor and still needs saving. Flavor Agent observes WordPress saves; it never initiates one.

This implementation is unreleased. The normative contract is [save-persistence outcomes 1.3](../superpowers/specs/2026-09-11-save-persistence-outcomes-design.md).

## Evidence and display

The activity API supplies four independent facts for the activity view:

| Fact | Meaning |
| --- | --- |
| Request status | What happened to the apply or save request. A failed save response leaves persistence undetermined. |
| Persistence verdict | The recorded change was present, absent, or could not be verified in a particular saved version. Missing evidence remains unknown. |
| Verification coverage | Whether an eligible apply has actually been compared. An inconclusive comparison is distinct from never being compared. |
| Undo state | Whether the editor undid the apply. Undo does not itself save or invalidate an earlier persistence verdict. |

Only the server writes `save_confirmed`, `save_discarded`, and `save_unverifiable`. The editor can report `save_attempted` and `save_failed`. A failed or lost response can coexist with a confirmed saved change. The latest server capture sequence with a verdict controls the displayed persistence state, even when background jobs finish out of order.

A recommendation with some operations present and others absent is discarded with `partial_persistence`. Any inconclusive operation makes the comparison unverifiable. Unsupported attribute extraction and ambiguous block identity never become guessed successes or discards. Global Styles comparisons concern saved user overrides, not effective styles inherited from the theme.

New single and batch block applies capture the complete live pre-apply attributes separately from the narrower Undo snapshot. Unchanged attributes help distinguish the target from other blocks of the same type; the recommendation's earlier context is not used as that identity evidence.

Historical rows keep an unknown lane. New external governed applies are labelled `server-executed`; a pending request in that lane does not prove execution or persistence. Historical attestations continue to describe the governed execution they signed, separately from later saved-version evidence.

## Capture and delivery

`wp_after_insert_post` captures saved content, complete registered block attribute schemas, entity identity, and an indexed apply boundary. Autosaves, revisions, and transitions to or from trash are excluded. The paired `before_delete_post` / `after_delete_post` observers handle template resets after resolving and inspecting the fallback identity. Repeated template hooks within one REST save are deduplicated. Headerless saves receive distinct server occurrence IDs, including saves from another session or user.

The server candidate set is independent of the open activity panel and the client's session candidates. An apply delivered after capture is not compared retrospectively with that version. Client attempts cannot enlarge the server's coverage denominator.

The editor middleware attaches `X-Flavor-Agent-Save-Occurrence` to qualifying core-data entity writes. Its version-1 session-storage outbox is scoped to REST root and current user, separate from the existing version-4 activity cache. Attempts and reconciliation survive scope changes and reloads. Closing the tab may leave an unknown result. Telemetry never delays or changes WordPress's save result.

Explicit reconciliation uses `GET /flavor-agent/v1/activity?applyIds=...&saveOccurrenceId=...` with at most 50 apply IDs. Every existing apply must pass its own contextual permission check. The response contains resolved activity entries and a `pending` flag; it contains no saved-content snapshot. Client attempts must belong to the current user's stored apply. Verdict ownership and scope come from that original apply; the server records the saver separately. Frozen client observation times order request status only; they never order persistence verdicts.

## Storage and operations

The activity table moves to schema version 6 with immutable `apply_lane`, `linked_apply_activity_id`, and `save_occurrence_id` columns. Private `flavor_agent_save_occurrences` storage keeps capture sequence, frozen content and schemas, candidate boundary, and a durable comparison cursor.

WordPress cron runs `flavor_agent_verify_saved_applies` in batches of at most 25 applies or approximately 50 ms between comparisons. The bound is cooperative: one parser invocation cannot be interrupted. Failed work retries without advancing past an unwritten verdict. A site with non-executing WordPress cron retains missing coverage until its worker runs or the capture expires.

`flavor_agent_save_verification_age_days` defaults to seven days, is capped at seven days, and is clamped below positive activity retention. Completed conclusive captures remove their content and schema payload immediately. Inconclusive payloads expire through worker/daily retention maintenance. Small eligibility metadata remains while a candidate apply survives. Linked verdicts survive their own date cutoff while the original apply remains, and uninstall removes the private table, options, and worker schedule.

## Metrics

Save-lifecycle rows are excluded before taking the existing newest-500-row learning-report sample. Existing recommendation metric definitions are unchanged; editor apply and pattern insertion metrics describe editor state.

| Added metric | Definition |
| --- | --- |
| `saveAttemptedOccurrences` | Distinct linked occurrence IDs with a recorded attempt. |
| `savePersistedRate` | Confirmed applies / conclusively compared applies. |
| `saveDiscardedRate` | Discarded applies / conclusively compared applies. |
| `saveUnverifiableRate` | Compared applies without a conclusive resolved verdict / compared applies. |
| `verificationCoverageRate` | Compared applies / server-eligible applies. |
| `unverifiedCoverageCount` | Server-eligible applies with no verdict of any kind. |

Linked evidence is loaded beyond the displayed page and date window. Zero denominators return `0.0`; coverage is shown separately. These signals do not alter recommendation ranking or establish measured production quality improvement.
