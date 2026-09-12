# Save persistence outcomes verification - 2026-09-12

## Implementation and evidence boundary

This local implementation follows [save-persistence contract 1.3](../superpowers/specs/2026-09-11-save-persistence-outcomes-design.md). Editor Apply changes editor state. A separate server observer captures each qualifying saved version, and a durable worker compares the recorded change with that frozen version. Activity views and metrics keep request status, persistence, verification coverage, and Undo independent. Historical outcome definitions and ranking remain unchanged.

- Branch: `claude/save-persistence-outcomes-design`.
- Base commit: `da478deb343e3d0ef2008fafab1a7ace258592ae`.
- Evidence applies to the uncommitted working tree, including new files, rather than the base commit alone.
- Source manifest before final verification: `output/save-persistence/corrected-source-before.json` (702 tracked and untracked source/configuration/test files; documentation and generated output excluded).
- No commit, push, release, deployment, or hosted-runner restoration is part of this evidence.

The implementation and operational contract are described in [save persistence outcomes](../features/save-persistence-outcomes.md); the completed tasks and verification scope are tracked in the [implementation plan](../superpowers/plans/2026-09-12-save-persistence-outcomes.md).

## Verification repairs

The installed dependency tree did not match the existing lockfiles. Restoring it with `composer install --no-interaction --prefer-dist` and `npm ci` restored the existing Jest VM-module command without changing package versions or replacing real UUID generation. A regression now exercises real WordPress block creation and cloning.

The Playground blueprint now unpacks Gutenberg 23.9.0 directly into the plugin directory and activates it, avoiding the failing Windows directory move. The Global Styles stale-result browser fixture waits for complete template hydration before taking its first recommendation snapshot. Stale-result rejection remains asserted.

Before registering the integrated observer, the repaired working-tree baseline passed Playground 17/17 and Docker Gutenberg 23.9.0 32/32. Those baseline results are separate from the final feature gates below.

## Final verification

The corrected strict run passed all nine steps, with no skips, from `2026-09-12T16:34:13.572Z` to `2026-09-12T16:49:04.965Z` (891,393 ms). Every step exited 0. The separate required bundled-editor leg passed 34/34, exit 0, from `2026-09-12T16:53:06.2427182Z` to `2026-09-12T16:59:18.1319554Z`, with Gutenberg confirmed inactive before and after execution.

```powershell
$env:PLUGIN_CHECK_USE_DOCKER='1'
$env:FLAVOR_AGENT_WP70_PORT='9544'
$env:FLAVOR_AGENT_WP70_PHPMYADMIN_PORT='9545'
$env:FLAVOR_AGENT_WP70_GUTENBERG='23.9.0'
$env:FLAVOR_AGENT_WP70_RESET='0'
node scripts/verify.js --strict --output=output/save-persistence/verify-corrected
```

```text
VERIFY_RESULT={"status":"pass","summaryPath":"output\\save-persistence\\verify-corrected\\summary.json","counts":{"total":9,"passed":9,"failed":0,"skipped":0}}
```

The summary is `output/save-persistence/verify-corrected/summary.json`, with SHA-256 `75c3aa32b1b543552f55508a92de7d201a7c43095f2c50540c92fa6b3565b890`. Per-step stdout/stderr logs are adjacent. The separate bundled-editor command uses the same port/reset overrides and explicitly deactivates Gutenberg in the reused test stack:

```powershell
$env:FLAVOR_AGENT_WP70_GUTENBERG='false'
docker exec flavor-agent-wp70-wordpress-1 wp plugin deactivate gutenberg --allow-root
docker exec flavor-agent-wp70-wordpress-1 wp plugin get gutenberg --fields=version,status --format=json --allow-root
npm run test:e2e:wp70 -- --output=output/save-persistence/browser/bundled-editor-artifacts
```

An earlier attempt with `FLAVOR_AGENT_WP70_RESET=0` retained active Gutenberg even though the install flag was `false`. A live WP-CLI check detected the mismatch. That attempt was stopped and excluded from bundled-editor evidence (`output/save-persistence/browser/final-bundled-result.json`). The corrected leg checks that Gutenberg is inactive before and after execution; its log/result are `bundled-editor.log` and `bundled-editor-result.json` in the same directory. The warm-reuse prerequisite is now documented in [local environment setup](../reference/local-environment-setup.md#gutenberg-browser-gate).

| Gate | Result |
| --- | --- |
| Production build | Pass; three webpack bundle-size/performance warnings |
| JavaScript lint | Pass |
| Plugin Check | Pass on the staged release payload; no errors |
| Jest | 116 suites / 1,944 tests passed |
| PHP lint | Pass |
| Documentation | Pass |
| PHPUnit | 2,347 tests / 11,072 assertions passed |
| Playground | 17/17 passed |
| Docker, exact Gutenberg 23.9.0 | 34/34 passed |
| Docker, bundled editor | 34/34 passed; Gutenberg inactive before and after |

Host tools: Windows, Node `24.15.0`, npm `11.13.0`, PHP CLI `8.3.32`, Composer `2.10.1`. Playground reports WordPress 7.1 / PHP 8.3. The Docker browser harness reports WordPress 7.1 / PHP 8.2.33, with process-local HTTP/phpMyAdmin port overrides and its existing database (`FLAVOR_AGENT_WP70_RESET=0`). WP-CLI confirmed active Gutenberg 23.9.0 for the exact-plugin leg. Plugin Check uses the separate local Docker WordPress stack.

Final integrity checks found no changes to the 702-file source manifest or seven recorded build files across the corrected strict and bundled-editor runs. The source digest is `634759e36cb2edcd8206a5c153d33bed1c374acb18a161b5fe7e1b4ecf471387` (SHA-256 of the JSON-encoded, path-sorted map of raw file SHA-256 values). Details are in `output/save-persistence/final-integrity.json` and `corrected-build-hashes.json`.

| Artifact | SHA-256 |
| --- | --- |
| `build/index.js` | `ca9a1a508bb6f163277239999a9469e519734980b19443ca859ee8406c99926e` |
| `build/activity-log.js` | `b3558161f9297dbb90cdbb384ce9d5e2f2d7126ab0f1423e5c8ca72e2043e208` |
| `browser/bundled-editor.log` | `6d2774a40406d87173177dec149cef0722a7e959d33cbf4a8b70384f59a79946` |
| `browser/bundled-editor-result.json` | `ef71ac0af94e69c32fa2886d6455c87ecfd4369e38cbe0bdeeadec7a63633ea1` |

The first strict run also passed all nine steps against an unchanged 702-file source manifest (`98e06bab43aec5b9013c816a7be9c614effc533e5f5b48c921e31bb19471aa97`). Inspection of its real browser evidence then exposed a missing block identity snapshot: the collector supplied `currentAttributes`, while the new builder expected `attributes`. A two-block browser regression and four unit regressions reproduced the gap. The corrected store suites passed 143 tests, the strengthened browser cases passed 3/3 including authentication, and the corrected full run above then passed. The earlier run remains in `output/save-persistence/verify-final/`; it does not substitute for the corrected source's evidence.

## Behavior and review coverage

The new browser cases use fixture recommendation output and real activity storage, WordPress saves, editor Undo, server comparisons, and the assurance API. They prove that Apply leaves saved content unchanged; Save confirms the change; Undo alone preserves that confirmation; and a subsequent Save records the reverted change as discarded. A successful server write whose response is lost produces both a failed request status and a confirmed persistence verdict. A forged client confirmation is rejected with HTTP 403.

The tests explicitly advance the registered production worker hook through WP-CLI. They verify the worker and its durable data path, but do not establish unattended cron execution. Desktop and 390-pixel mobile captures verify readable, separate persistence/coverage/Undo feedback and no document overflow. The final Gutenberg screenshots and saved-state JSON are preserved under `output/save-persistence/browser/final-gutenberg-artifacts/`; the bundled leg has its own `bundled-editor-artifacts/` directory. Earlier focused evidence remains in the same parent directory.

Independent review identified four issues that were reproduced and fixed: reconciliation lost across an in-flight reload, request ordering based on truncated ISO timestamps, custom post types with default REST bases/namespaces, and false confirmation when a supposedly removed block survived after an edit or move. The focused regressions passed before the full verification run.

The subsequent identity regression uses two paragraphs with distinct unchanged classes. It requires the stored apply to carry the original complete attributes and the server to distinguish its saved target from its neighbor. Single and batch applies must take that witness from the live pre-apply editor attributes, independently of the recommendation's context and the narrower undo snapshot.

PHP fixtures cover all six editor apply types, schema defaults and HTML extraction, partial persistence, ambiguous identity, unsupported extraction, template reset fallback, autosave/revision/trash exclusions, frozen candidate boundaries, late delivery, ordering, retry, expiry, permissions, pruning, and uninstall. Client tests cover selective and multi-entity saves, metadata-only outbox storage, reload, failed/lost responses, ignored requests, scope changes, and bounded reconciliation. Existing learning metric definitions remain intact; six save metrics are additive.

This is local correctness evidence. It does not establish production recommendation improvement, restore hosted CI or scheduled corpus execution, or certify a new release archive.
