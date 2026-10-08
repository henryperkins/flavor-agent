# UI theme and style review fixes — October 8, 2026

All seven confirmed findings from the UI theme and style review have source fixes and automated regressions. This record covers the local checkout based on `bf12ff25425643cc742ad5a8a995066ceeb5468a`, including its uncommitted changes. It is evidence for these fixes, not a release artifact or deployment record. Pre-existing skill, prompt, configuration, documentation, and concurrent unrelated PHP changes were preserved.

## Findings and resulting behavior

| Finding | Fix | Regression coverage |
| --- | --- | --- |
| P1: A same-shaped sibling reorder can redirect a structural removal. | Template and template-part snapshots carry the original client ID and complete subtree signature. Trusted request context supplies the expected target through normalization, review models, preparation, and final execution. Reordered, edited, or malformed targets fail before mutation. | Editor block identity, template helpers and actions, template/template-part recommenders, PHP ability and prompt tests; browser sibling-swap cases. |
| P1: Shared Apply can mutate after the editor or request changes during asynchronous validation. | Capture editor scope, block identity, settings, and style configuration before preflight. Re-read them and the panel's latest request after preflight. Superseded responses cannot overwrite newer state; drift cannot execute or emit success/activity. | Deferred-preflight matrix across template, template part, Global Styles, and Style Book; browser manual-edit race. |
| P2: Original/adapted pattern insertion can use an obsolete insertion target. | Compare synchronous insertion snapshots after validation and immediately before dispatch, including target, source content, editor settings, and adaptation context. Abandoned requests cannot refresh an obsolete input. | Both insertion variants, context/source drift, cancellation; browser delayed-target cases. |
| P2: Concurrent pattern activation can insert twice. | Original/adapted insertion share a synchronous lock and busy state through preflight, mutation, verification, and cleanup. Target changes during a pending mutation retain the lock. Unmount cleanup removes only the inserted IDs and emits no late notice. | Same-tick double activation, deferred insertion, target changes, unmount rollback; browser duplicate activation cases. |
| P2: A completed response can overwrite the next local prompt draft. | A shared draft hook hydrates untouched panels while preserving edited or cleared drafts. All six affected panels use it; executable panels also read the latest prompt synchronously at Apply. | Content, Navigation, Template, Template Part, Global Styles, Style Book, and hook tests; browser Content pending-response case. |
| P2: Settings never polls a sync already indexing when the page opens. | Initialization resumes the busy/polling lifecycle for a saved indexing state and restores the button when it finishes. | Settings controller tests; browser initial-indexing case. |
| P2: Activity hides authorized pending post decisions behind theme permissions. | The server serializes viewer-specific `canDecide` using the same contextual authorization as the decision route. Activity actions and automatic/focused claims require an explicit granted row permission. Missing or denied permission remains unavailable. | Activity UI, permissions, serializer, and page tests; browser granted/denied/missing permission cases. |

Ordinary object-valued block attributes remain intact during identity/preparation normalization. Only actual RichText values or complete RichText records become HTML strings. Documentation describes the new freshness, insertion, polling, and per-row permission contracts.

## Verification

The final nonbrowser aggregate ran:

```text
node scripts/verify.js --skip-e2e --strict --json
```

The inspected `output/verify/summary.json` reports `pass`: seven steps passed, none failed. The two browser steps were intentionally excluded from this command and are recorded separately below.

| Check | Result |
| --- | --- |
| Production build | Passed; three existing webpack size/performance warnings. |
| JavaScript lint | Passed. |
| Plugin Check | Passed, no errors found. |
| Full Jest suite | 125 suites, 2,295 tests passed. |
| PHP lint | Passed. |
| Documentation check | Passed. |
| Full PHPUnit suite | 2,553 tests, 11,980 assertions passed. |

Jest now excludes adjacent `.claude/worktrees` checkouts and generated `output` fixtures from both suite and module discovery. Discovery confirmed 125 current-checkout suites and zero suites from those directories.

### Browser fixtures

Browser checks use rebuilt current-checkout assets and separate local fixtures. The Docker project is `flavor-agent-ui-oct08`, WordPress port 29404 and phpMyAdmin port 29405, with resets disabled after its fresh bootstrap. The harness is named `wp70`, but its installed WordPress core reports **7.1**, with Gutenberg **23.9.0** and Plugin Check **2.1.0**. Host runtimes include Node **24.15.0** and PHP **8.3.33**.

The served editor, admin, and activity JavaScript/CSS files all match local build SHA-256 hashes (`output/ui-review-asset-check.json`). Playground runs separately on port 29612 with an isolated temporary directory. Browser requests are mocked where necessary to hold a validation response and exercise deterministic races; the checks prove editor/admin behavior rather than live-provider output quality.

All **59 Docker Site Editor cases** and **40 Playground cases** have passing evidence across the full runs and the successful focused reruns. The initial full browser runs themselves exited with fixture failures; those historical runs remain failed. The reruns use the same built product sources, with only test setup/selectors corrected. No unresolved browser failure or unavailable matching harness remains.

| Browser run | Recorded result | Evidence |
| --- | --- | --- |
| Docker Site Editor full run | 54 passed, 5 fixture failures, 20.0 minutes. | `output/ui-review-wp70.log`, `output/playwright-wp70`. |
| Docker pattern/draft focused rerun | Authentication plus all five regressions passed: 6 passed, 1.5 minutes. | `output/playwright-wp70-ui-focused/.last-run.json` reports `passed`, no failed tests. |
| Docker structural-target focused rerun | Authentication plus all three regressions passed: 4 passed, 2.6 minutes. | `output/playwright-wp70-ui-target/.last-run.json` reports `passed`, no failed tests. |
| Playground full run | 37 passed, 3 fixture failures, 18.4 minutes. | `output/ui-review-playground-final.log`, `output/playwright-playground-final`. |
| Playground focused rerun | Docs warning plus all five pattern/draft regressions passed: 6 passed, 2.8 minutes. | `output/ui-review-playground-focused-final.log`, `output/playwright-playground-focused-final`. |

The Site Editor runs use `playwright.wp70.config.js`, with the isolated Docker environment variables above and separate artifact directories for the focused specs. The Playground full run uses the ignored `output/playground-final.config.cjs` wrapper; its focused wrapper, `output/playground-focused-final.config.cjs`, selects separate artifacts while retaining the same isolated server settings. The focused tests are `flavor-agent.ui-draft-pattern-regressions.spec.js`, `flavor-agent.ui-target-regressions.spec.js`, and the repaired Playground docs-grounding warning case. The full Docker run also covers `flavor-agent.ui-admin-regressions.spec.js`.

Final syntax/scoped JavaScript lint checks pass for all three new browser specs and the repaired docs-warning helper. The combined owned diff passes `git diff --check`, and `npm run check:docs` passes after adding this record. The full aggregate's product-source inputs have remained unchanged since its build started.

### Earlier failures and corrections

- The first aggregate loaded a test before its updated `rawHandler` mock was present. That fixture was corrected; the final full aggregate passed.
- An initial Playground attempt exhausted its 120-second server startup allowance. The isolated retry used a 300-second startup allowance without extending individual test timeouts. Playground's file-unlock warnings did not prevent the retry from serving WordPress.
- A late welcome guide blocked the docs-grounding warning test's Inserter click. Its helper now closes that known overlay whenever it appears during an action.
- New adapted-pattern and target-review fixtures originally selected visible button text as an exact accessible name. The product includes the pattern/suggestion title in its accessible label; the tests were corrected to select the complete label.
- A briefly overlapping Docker test setup removed the shared harness storage-state file, causing two setup failures. Subsequent runs are serialized against that fixture and write browser artifacts to distinct directories.

The first aggregate logs are retained in `output/verify-ui-first`; the initial complete Docker and Playground logs are retained separately from their successful focused reruns. No source fix is inferred from a setup-only browser failure. Playground cleaned up its own servers after testing.
