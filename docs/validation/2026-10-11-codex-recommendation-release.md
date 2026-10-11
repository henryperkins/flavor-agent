# Codex Recommendation Release Validation and Deployment

The replacement ZIP was qualified from clean, detached checkouts of `a622fe0ccb1df416e2cee5de80eac3f2e5b694e4` (`Fix Codex recommendations and request diagnostics`) and uploaded to `https://hperkins.blog`, WordPress.com site `253647414`. Version metadata remains `0.1.3`. This record and the status/reference updates are subsequent documentation changes; the packaged runtime belongs to the immutable candidate above.

## Change and Verification Scope

Flavor Agent omits its optional default token budget when the selected model does not advertise that option. Explicit caller limits, provider/model selection, reasoning options, and response-schema fallback remain covered. Failed recommendation diagnostics expose `Request failed` and `Undo not applicable`, preserving the underlying provider error independently of undo.

The complete Jest and PHPUnit runs include the nearest WordPress AI Client, persistence-assurance, and Activity utility regressions. Playground covers block recommendations, request diagnostics, patterns, content, navigation, and editor apply/undo; the Site Editor suites cover templates, template parts, Global Styles, Style Book, Activity, settings, and shared request/apply behavior. Browser recommendation fixtures use synthetic responses. The Codex capability regression uses the real WordPress AI Client SDK with synthetic model metadata, rather than a live Codex service request.

## Qualification Results

| Gate | Result |
| --- | --- |
| Canonical `verify:strict` | 9/9 passed, 0 failed, 0 skipped steps |
| Build, JS/PHP lint, documentation | Passed |
| Plugin Check in canonical gate | Passed, no errors |
| Jest | 126 suites, 2,417 tests passed |
| PHPUnit | 2,653 tests, 13,208 assertions passed |
| Playground | 49/49 passed, 0 skips or retries |
| WordPress 7.1 with Gutenberg 23.9.0 | 78/78 passed, 0 skips or retries |
| WordPress 7.1 bundled editor | 77 passed, 1 runtime-inapplicable fixture, 0 failures or retries |
| Gutenberg 24.1.0 compatibility | 78/78 passed, 0 skips or retries |
| Archive and source/build comparison | Passed; 221 files, 210 non-vendor byte matches, 12 build files |
| Extracted PHP syntax | All 202 packaged PHP files passed on PHP 8.2.33 |
| Extracted-package native runtime | 237 checks passed, including 188 packaged class autoloads |
| Extracted-package Codex regression | 12 checks passed; unsupported optional budget omitted and explicit settings preserved |
| Extracted-package Plugin Check | Passed, no errors; no finding-code exclusions |

The bundled editor deliberately omits the registered shared Guidelines management fixture because that fixture requires the Gutenberg plugin. That same test passed in both complete Gutenberg legs. This is an explicit runtime applicability exclusion, with no failed application case left unqualified.

The first strict attempt passed the seven non-browser gates but Playground recorded 48 passes and one catalog-hydration failure. Its trace shows Chromium `ERR_NETWORK_CHANGED` cancelling the core pattern-catalog and other REST requests while the second Docker runtime was starting. That timing supports an environment explanation. The incomplete subsequent Site Editor leg was stopped and is not qualification evidence. The initial logs and trace are preserved. A diagnostic invocation with an overly anchored title filter selected no tests and supplies no assertion evidence. With both Docker networks established, a fresh complete strict run passed all nine gates without source changes, assertion changes, skipped steps, or retries.

## Source, Environment, and Artifact

- Candidate: `a622fe0ccb1df416e2cee5de80eac3f2e5b694e4`.
- ZIP: `dist/flavor-agent.zip`, 1,313,627 bytes, one `flavor-agent/` directory.
- SHA-256: `c116d6eae1dad804a2b019aba4483bf2a8739de30542e02d9e222519950dc917`.
- All 835 tracked candidate files matched across the primary and both verification checkouts before the documentation updates. Both verification checkouts remained clean.
- All 12 tested build files matched across both verification checkouts and remained byte-identical after `npm run dist` rebuilt the assets. Archive CRC, path safety, inventory, metadata, production autoloading, and source/build checks passed.
- Node 24.21.0, npm 11.19.0, Composer 2.10.3, host PHP 8.4.26, Playwright 1.60.0, Docker 29.1.3.
- Task-local PHP timezone UTC; host settings were preserved.
- Docker WordPress 7.1 / PHP 8.2.33, AI 1.4.0, Plugin Check 2.1.0; separate bundled, Gutenberg 23.9.0, and Gutenberg 24.1.0 legs.
- Playground CLI 3.1.51, WordPress 7.1 / PHP 8.3.
- The cached Docker base override and MariaDB 11.4, exact lockfile hashes, runtime inventories, ports, environment overrides, and complete commands are retained with the evidence. Unrelated local runtimes were preserved.

The canonical command was `CI=1 npm run verify:strict -- --output=output/release-a622fe0/verify-recheck`, after sourcing the recorded task-specific `release-env.sh`. Compatibility legs used `FLAVOR_AGENT_WP70_GUTENBERG=false` and `24.1.0` with `npx playwright test -c playwright.wp70.config.js --reporter=list,json`. Packaging used `PLUGIN_SLUG=flavor-agent COMPOSER_ROOT_VERSION=0.1.3 npm run dist` from the qualified checkout. The extracted Codex probe confirms its `WordPressAIClient.php` originates in the extracted ZIP and restores its temporary local model-selection option.

Evidence is retained under `output/release-a622fe0/`: `qualification.json`, `verify-recheck/summary.json` and all step logs, `initial-run-disposition.json`, `initial-playground-artifacts/`, source/build manifests, `post-dist-parity.json`, `compatibility/`, `package-audit/`, and `artifact.sha256`. These files establish candidate/artifact byte identity. GitHub tag and release publication were not performed by this replacement-deployment task; the Verify and WordPress.com artifact workflows remain manually disabled.

## Production Deployment Evidence

The user explicitly authorized deployment. WordPress.com `plugin.upload` accepted the qualified base64 ZIP with overwrite enabled and reported `Plugin ZIP installed`, Flavor Agent 0.1.3 active. The exact primary ZIP matches the qualified/uploaded SHA-256 above. The preexisting local ZIP was backed up separately; it is not claimed to be an exact copy of the previous production installation. Managed backup status reported a finished, successful backup at `2026-10-11 04:36:18 UTC` and no backup in progress.

Immediately before and after upload, the site retained 34 installed plugins, 25 active and 9 inactive, with no changes to plugin versions or activation states. Flavor Agent remained active at 0.1.3, Gutenberg at 24.1.0, and Scriptorium AI Provider for Codex at 2.1.2. All nine packaged public JavaScript/CSS assets returned HTTP 200 and matched the qualified manifest byte for byte; the baseline had seven matches. The public homepage independently returned HTTP 200 without a critical-error page.

Production receipts, backup status, plugin inventories, downloaded public asset bytes, hashes, and public health evidence are retained under `output/release-a622fe0-production/`, including `upload-receipt.json`, `deployment-evidence.json`, `post-upload-plugins.json`, `public-after.log`, and the timestamped asset-verification directories. Installed PHP hashes were not directly read from the server.

## Live Recommendation Boundary

Live Codex generation remains awaiting the user's authenticated editor test. The available workspace browser redirects the admin URL to a WordPress.com login challenge (HTTP 403). The user confirmed their signed-in browser is only on their own computer. An admin URL alone does not transfer that browser session.

After deployment, the user was asked to hard-refresh page 6, select the original Group block, open **AI Recommendations**, and click **Get Suggestions**, then report the result or the exact error and fresh AI Activity diagnostics. No production request has yet been independently observed or confirmed by the user. Local synthetic capability checks and public asset matches do not establish a live provider response.
