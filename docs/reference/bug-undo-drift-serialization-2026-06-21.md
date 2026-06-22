# Bug: drift-safe undo always fails on Global Styles applies (preset serialization mismatch)

- **Date found:** 2026-06-21 (live dry-run against `hperkins.blog`)
- **Severity:** High — breaks the demo's closer (Beat 6, drift-safe undo) and leaves applied style changes non-reversible via the governed path.
- **Status:** Fixed 2026-06-22 — preset-ref canonicalization across all four undo comparison sites (server + client, Global Styles + Style Book); see **Resolution**. The live droplet still needs the activity-row reconciliation noted under "Cleanup already done" below.
- **Component:** `inc/Apply/StyleApplyExecutor.php`

## Summary

Undoing an **executed** Global Styles apply fails with `flavor_agent_undo_drift`
("Global Styles changed after Flavor Agent applied this suggestion and cannot be undone
automatically") **even when nothing external touched the site** between approve and undo.
The undo's after-state equality check compares two *different serializations of the same
value* and concludes the entity drifted.

## Reproduce

1. `flavor-agent/recommend-style` → get a suggestion whose operation sets a preset color
   (e.g. `color.background` → `parchment-100`).
2. `flavor-agent/request-style-apply` with those operations + signatures → pending row.
3. Approve as admin (`POST /flavor-agent/v1/activity/{id}/decision` `{decision:"approve"}`)
   → row flips to `applied`, the change lands on the live entity.
4. `flavor-agent/undo-activity {activityId}` → **fails** `flavor_agent_undo_drift`.

No out-of-band edit happens between 3 and 4. (Real run: pending `1383ee14-…`, applied at
`23:50:08`, undo attempted `23:50:20`.)

## Observed vs expected

- **Observed:** every preset-valued style apply is non-undoable via the governed path.
- **Expected:** with no intervening change, undo restores the recorded `before` snapshot.

## Evidence (the smoking gun)

Same logical value, two serializations:

| Source | `styles.color.background` |
|---|---|
| **Live `wp_global_styles` post (post 81)** after apply | `var(--wp--preset--color--parchment-100)` (resolved CSS custom property) |
| **Recorded `after_state` snapshot** on the activity row | `var:preset\|color\|parchment-100` (theme.json preset ref) |

The recorded `after_state` holds the operation's `value` field (the canonical theme.json preset
reference) exactly as Flavor Agent wrote it. The persisted post holds the **same value after
WordPress core normalized it on save** to the resolved CSS custom property — Flavor Agent does
**not** write `cssVar`; core converts the stored `var:preset|…` into `var(--wp--…)`. The two
serializations encode one value but never byte-match. (Confirmed by live reproduction — see Resolution.)

## Root cause

`StyleApplyExecutor::undo()` decides drift here:

```php
// inc/Apply/StyleApplyExecutor.php:329
if ( self::comparable_config( $live ) !== self::comparable_config( $after_config ) ) {
    return new \WP_Error( 'flavor_agent_undo_drift', ... );
}
```

`comparable_config()` (`:80-85`) only **sorts keys** (`sort_keys_deep`) before the
`comparable_config_hash()` / `!==` comparison — it performs **no value canonicalization**.
So `$live` (read from the post → `var(--wp--preset--color--parchment-100)`) and
`$after_config` (recorded snapshot → `var:preset|color|parchment-100`) hash differently and
the equality check fails. The same mismatch makes the `already_undone` check at `:325` miss too.

**Correction (confirmed 2026-06-22 by live reproduction):** there is only one viable fix point,
not two. Flavor Agent already writes the canonical `value` (`var:preset|color|slug`) at
`StyleApplyExecutor.php:212`; **WordPress core normalizes it to `var(--wp--preset--color--slug)`
when the `wp_global_styles` post is saved.** So the earlier "persistence side" option — make the
write store `var:preset|…` — rests on a wrong premise: the write already does, and core overrides
it (forcing the stored form would mean fighting core's own serialization). The fix is therefore
**comparison-side canonicalization**: make `comparable_config()` collapse
`var(--wp--preset--color--X)` and `var:preset|color|X` (and font-size, spacing, custom, etc.) to a
single form before hashing, applied to both `$live` and the recorded snapshots. See **Resolution**.

## Resolution (fixed 2026-06-22)

**Fix:** comparison-side canonicalization in `inc/Apply/StyleApplyExecutor.php`.
`comparable_config()` now runs every string leaf through a new `canonicalize_style_value()`,
which maps a resolved CSS custom property (`var(--wp--preset--color--x)`) back to its theme.json
reference form (`var:preset|color|x`); non-preset strings pass through untouched. Because
`comparable_config()` is the single chokepoint for the undo drift check, the `already_undone`
check, **and** the approval-time `baselineConfigHash` (`comparable_config_hash()`, used in both
`ApplyAbilities.php:186` and `PendingApplyDecision.php:92`), the false drift is removed everywhere
consistently. Genuine drift (a different slug or a literal value) still hashes differently and
still fails closed — Beat 6's real drift detection is preserved.

The same root cause had **three more comparison sites**, all fixed RED→GREEN in this pass:
- **Server, Style Book** — `undo_style_book_branch()` compared block branches via `sort_keys_deep` directly (`StyleApplyExecutor.php:385-387`), bypassing `comparable_config()`; now wrapped in `canonicalize_values_deep`.
- **Client, Global Styles** — `configsMatch()` (`src/utils/style-operations.js`) now canonicalizes via a JS port (`canonicalizeStyleValue` / `canonicalizePresetRefsDeep`).
- **Client, Style Book** — `getComparableConfigBranchAtPath()` now canonicalizes the same way.

The JS canonicalizer is applied **only** in the undo comparisons, never in `getComparableGlobalStylesConfig()` — that function also feeds `buildGlobalStylesRecommendationContextSignature`, whose output must stay byte-identical to the server's signature recomputation. A parity-guard test locks that split.

Core's `WP_Theme_JSON::convert_variables_to_css_var()` is **not callable** in this runtime
(WP 7.1-alpha — verified absent), so the canonicalizer is self-contained rather than delegating to
core.

**Tests (TDD, written failing first):**
- PHP `tests/phpunit/StyleApplyExecutorTest.php`: `test_comparable_config_treats_preset_ref_and_resolved_css_var_as_equal`, `test_undo_succeeds_when_core_normalized_the_stored_preset_to_a_resolved_css_var`, `test_style_book_undo_succeeds_when_core_normalized_the_block_branch_preset`.
- JS `src/utils/__tests__/style-operations.test.js`: global-styles and style-book "core-normalized resolved CSS var" undo tests, plus the `getComparableGlobalStylesConfig … byte-stable` signature parity guard.

Verified: `StyleApplyExecutorTest` 19/19; `style-operations.test.js` 28/28; `node scripts/verify.js --skip-e2e` → **pass** (build, lint-js, lint-plugin, unit, lint-php, test-php 1567/1567; E2E + check-docs not in that run).

**Live re-verification** (nightly container, WP 7.1-alpha, user Global Styles post 6) — both previously failed `flavor_agent_undo_drift`:
- Global Styles: apply `var:preset|color|accent-1` → post stores `var(--wp--preset--color--accent-1)` → `undo()` → `{result: undone}`, empty before snapshot restored.
- Style Book: apply `var:preset|font-size|small` on the `core/paragraph` branch → post stores `var(--wp--preset--font-size--small)` → `undo()` → `{result: undone}`, branch removed.
Post restored after each probe.

## Why it matters

- **Demo Beat 6 ("drift-safe undo — the closer") is broken.** A normal undo with no external
  edit fails as if the site drifted — the opposite of the intended story. The genuine
  fail-closed-on-real-drift behavior can't be shown until the false positive is fixed.
- Applied Global Styles are persisted in non-canonical (resolved-CSS-var) form.

## Suggested test coverage

- Unit: `comparable_config()` treats `var(--wp--preset--color--parchment-100)` and
  `var:preset|color|parchment-100` as equal.
- Integration: recommend-style → request-style-apply → approve → **undo succeeds** and
  restores the `before` snapshot, asserting the live post returns to its pre-apply content.

## Cleanup already done (2026-06-21, on the live droplet)

Because the governed undo refused, post 81 was **manually restored** to its pre-apply content
`{"version":3,"isGlobalStylesUserThemeJSON":true}` (revision 230) and global-styles/theme.json
caches flushed. Verified: resolved stylesheet back to theme defaults
(`background: parchment-100`, `text: ink-700`); the `ink-900` override is gone.

**Left inconsistent (cleanup TODO):** activity row `1383ee14-5f9e-4ce5-80fa-f5b76b23656f`
still reads `executionResult: applied` / `undo: failed`, even though the styles were reverted
out-of-band. Also a stale `request_diagnostic` failure row `a62ac12c-…` ("Provider not
registered: codex", from before the provider was fixed) sits at `global_styles:81`. Both should
be removed/reconciled before recording the demo.
