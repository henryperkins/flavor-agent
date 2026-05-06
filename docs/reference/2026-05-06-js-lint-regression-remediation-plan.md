# JS Lint Regression Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the uncommitted changeset to a green JavaScript lint state without changing settings behavior.

**Architecture:** This is a formatting-only remediation. The implementation should use the repo-native WordPress Scripts lint fixer or an equivalent minimal one-line formatting edit in the changed E2E spec, then rerun the exact failed lint gate plus the lightweight regression checks that were already green during review.

**Tech Stack:** `@wordpress/scripts` ESLint/Prettier integration, Playwright E2E spec source, repo docs checks, `git diff --check`.

---

## Confirmed Finding Covered

- [ ] **CI-blocking JavaScript lint regression:** `npm run lint:js` exits `1` because [tests/e2e/flavor-agent.settings.spec.js](/home/dev/flavor-agent/tests/e2e/flavor-agent.settings.spec.js:112) formats the `Developer Docs Prewarm` `not.toContainText()` assertion across multiple lines. ESLint reports:

```text
tests/e2e/flavor-agent.settings.spec.js
  112:48  error  Replace the multi-line string argument with a one-line argument  prettier/prettier
```

## Files To Change

- Modify: `tests/e2e/flavor-agent.settings.spec.js`
- Do not modify: PHP source, JS source, generated build assets, docs other than this plan, or release artifacts.

## Task 1: Fix The E2E Spec Formatting

**Files:**

- Modify: `tests/e2e/flavor-agent.settings.spec.js:112`

- [ ] **Step 1: Confirm the lint failure is still present**

Run:

```bash
npm run lint:js -- tests/e2e/flavor-agent.settings.spec.js
```

Expected before the fix:

```text
tests/e2e/flavor-agent.settings.spec.js
  112:48  error  Replace `...` with ` 'Developer Docs Prewarm' `  prettier/prettier
```

- [ ] **Step 2: Apply the minimal formatting fix**

Preferred repo-native command:

```bash
npx wp-scripts lint-js --fix tests/e2e/flavor-agent.settings.spec.js
```

If applying manually instead, replace this block:

```js
await expect( docsSection ).not.toContainText(
	'Developer Docs Prewarm'
);
```

with this one-line assertion:

```js
await expect( docsSection ).not.toContainText( 'Developer Docs Prewarm' );
```

- [ ] **Step 3: Review the exact diff**

Run:

```bash
git diff -- tests/e2e/flavor-agent.settings.spec.js
```

Expected: the only behavior-relevant change is formatting the `Developer Docs Prewarm` assertion onto one line. The assertion text and selector stay unchanged.

- [ ] **Step 4: Run the failed lint gate**

Run:

```bash
npm run lint:js
```

Expected:

```text
wp-scripts lint-js src/
```

and exit code `0`.

## Task 2: Reconfirm The Review Baseline

**Files:**

- Read-only verification unless a command exposes a new issue.

- [ ] **Step 1: Re-run the targeted PHP regression suite from the review**

Run:

```bash
composer run test:php -- --filter 'RegistrationTest|SettingsRegistrarTest|SettingsTest|PatternAbilitiesTest|CloudflarePatternSearchClientTest|PluginLifecycleTest'
```

Expected:

```text
OK (175 tests, 1221 assertions)
```

- [ ] **Step 2: Re-run the admin JS unit regression**

Run:

```bash
npm run test:unit -- src/admin/__tests__/settings-page-controller.test.js --runInBand
```

Expected:

```text
Test Suites: 1 passed, 1 total
Tests:       10 passed, 10 total
```

- [ ] **Step 3: Re-run docs and whitespace gates**

Run:

```bash
npm run check:docs
git diff --check
```

Expected:

```text
scripts/check-doc-freshness.sh
```

and `git diff --check` prints no output.

## Task 3: Optional Aggregate Verification

**Files:**

- Read-only verification.

- [ ] **Step 1: Run the fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: final `VERIFY_RESULT` JSON reports `"status":"pass"`. E2E steps may be marked skipped because of `--skip-e2e`.

- [ ] **Step 2: Decide whether browser E2E is necessary**

This finding is a static formatting failure in a Playwright spec, not a runtime browser behavior change. Full E2E is optional for this remediation unless other E2E source changes are made during implementation.

If browser coverage is requested, run the settings spec through the repo's normal E2E path:

```bash
npm run test:e2e -- tests/e2e/flavor-agent.settings.spec.js
```

Expected: the settings spec passes in the configured local WordPress/Playground environment.

## Acceptance Criteria

- [ ] `tests/e2e/flavor-agent.settings.spec.js` keeps the `Developer Docs Prewarm` negative assertion and only changes formatting.
- [ ] `npm run lint:js` exits `0`.
- [ ] Targeted PHP and admin JS tests from the review still pass.
- [ ] `npm run check:docs` and `git diff --check` pass.
- [ ] No unrelated source, build, or release artifact changes are introduced while fixing the lint regression.
