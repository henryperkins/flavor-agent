# Cloudflare Browser Run UI Audit Design

## Status

Approved with an operator-supplied public target on 2026-05-06.

## Problem

Flavor Agent UI audits often need quick visual evidence from wp-admin, the block editor, the Site Editor, settings, and multi-step recommendation workflows. The existing Playwright harnesses are still the source of automated assertions, but they require a local browser setup and are not always the fastest way to collect shareable screenshots during manual review or CI triage.

Cloudflare Browser Run's `/screenshot` endpoint can capture rendered pages from a public URL. Operators can point the utility at a staging site or reachable tunnel without adding a local browser dependency to the review workflow.

## Goals

- Add an optional screenshot audit utility for Flavor Agent UI review.
- Accept the remote audit host from `--base-url`, manifest `baseUrl`, absolute manifest URLs, or `BROWSER_RUN_DEFAULT_BASE_URL`.
- Support named presets for common audit surfaces: Flavor Agent settings, wp-admin pages, block editor, Site Editor, and explicit workflow manifests.
- Capture PNG artifacts plus traceable metadata for each screenshot.
- Keep the utility out of default `npm run verify` and out of plugin runtime behavior.
- Make authentication explicit and temporary, without committing cookies, tokens, or generated screenshots.
- Let CI use the utility as supporting visual evidence when local browser proof is unavailable, while preserving the existing validation-gate requirement to record blockers or waivers for missing Playwright coverage.

## Non-Goals

- Do not replace Playwright tests or reduce the current browser harness expectations.
- Do not add a user-facing Flavor Agent feature or wp-admin control.
- Do not store WordPress admin cookies, Cloudflare API tokens, or generated screenshots in git.
- Do not require Cloudflare Browser Run for local development, `npm run verify`, or release packaging.
- Do not add a localhost tunnel dependency for the default workflow.

## Target Environment

The audit target must be a WordPress site that Cloudflare Browser Run can reach. The utility should not mutate the site. It only requests URLs through Cloudflare Browser Run and writes local artifacts under the Flavor Agent checkout.

The script should accept a base URL for staging or temporary environments:

```bash
npm run audit:screenshot -- --base-url="https://example.test"
```

Cloudflare Browser Run must be able to reach the target URL. Localhost URLs are unsupported unless the operator supplies a reachable tunnel URL as `--base-url`.

## Command Shape

Expose the tool through an npm script:

```bash
npm run audit:screenshot -- --preset=settings --base-url="https://example.test"
npm run audit:screenshot -- --preset=block-editor --base-url="https://example.test" --url="/wp-admin/post.php?post=123&action=edit"
npm run audit:screenshot -- --preset=site-editor --base-url="https://example.test" --url="/wp-admin/site-editor.php"
npm run audit:screenshot -- --manifest=docs/audits/admin-ui-flow.json
```

The underlying script should live at:

```text
scripts/browser-run-screenshot.js
```

The script should be intentionally dependency-light and use Node's built-in `fetch`, `fs`, and `path` APIs where possible.

## Required Configuration

Cloudflare credentials must come from environment variables:

```text
CLOUDFLARE_ACCOUNT_ID
CLOUDFLARE_API_TOKEN
```

The token needs Cloudflare Browser Rendering edit permission for the account.

Authenticated WordPress pages need one of these explicit inputs:

- `--cookies-file=/path/to/cookies.json`
- `BROWSER_RUN_COOKIES_JSON`
- `--extra-headers-file=/path/to/headers.json`

Cookie and header files must be read from operator-provided paths and must never be created by the script in the repository. Missing auth input should fail fast for admin/editor presets with a clear message.

## Presets

Presets define defaults, not hidden behavior. Operators can override URL, viewport, selector, and full-page settings on the command line.

| Preset | Default path | Purpose |
| --- | --- | --- |
| `settings` | `/wp-admin/options-general.php?page=flavor-agent` | Capture `Settings > Flavor Agent` setup and validation copy. |
| `admin` | `/wp-admin/` | Capture general wp-admin state or an explicitly supplied admin URL. |
| `block-editor` | supplied by `--url` | Capture post editor canvas, inspector, inserter, and Flavor Agent panel states. |
| `site-editor` | `/wp-admin/site-editor.php` | Capture Site Editor templates, template parts, Global Styles, and Style Book workflows. |
| `workflow` | manifest-defined | Capture a named sequence of visual workflow steps. |

The first implementation can capture URLs only. It should leave scripted browser interactions out of scope unless a later plan explicitly adds Playwright/CDP control. Workflow manifests therefore describe a list of URLs and capture options, not click automation.

## Workflow Manifest

Workflow manifests should be JSON files with explicit steps:

```json
{
  "name": "settings-audit",
  "baseUrl": "https://example.test",
  "defaults": {
    "viewport": {
      "width": 1440,
      "height": 1200,
      "deviceScaleFactor": 1
    },
    "fullPage": true
  },
  "steps": [
    {
      "id": "settings",
      "url": "/wp-admin/options-general.php?page=flavor-agent"
    },
    {
      "id": "site-editor",
      "url": "/wp-admin/site-editor.php"
    }
  ]
}
```

Each step should resolve relative URLs against the manifest `baseUrl` or the command-line `--base-url`.

## Cloudflare Request Contract

The script should call:

```text
POST https://api.cloudflare.com/client/v4/accounts/{accountId}/browser-rendering/screenshot
```

Each request must provide either a `url` or `html`. This utility should use `url` for its initial scope.

Default request body:

```json
{
  "url": "https://example.test/wp-admin/options-general.php?page=flavor-agent",
  "viewport": {
    "width": 1440,
    "height": 1200,
    "deviceScaleFactor": 1
  },
  "screenshotOptions": {
    "fullPage": true
  },
  "gotoOptions": {
    "waitUntil": "networkidle0",
    "timeout": 45000
  }
}
```

The script should pass optional `cookies`, `setExtraHTTPHeaders`, `selector`, `userAgent`, `addStyleTag`, and `addScriptTag` fields only when explicitly supplied by preset, manifest, or command-line options.

## Output

Write artifacts under:

```text
output/browser-run/{timestamp}-{run-name}/
```

For each step, write:

```text
{step-id}.png
{step-id}.json
```

Metadata JSON should include:

- step ID
- preset or manifest name
- final URL
- timestamp
- viewport
- selector, if any
- full-page flag
- Cloudflare account ID suffix only, not the full ID
- HTTP status and response headers that are safe to persist
- output filename

The output directory must be ignored by git through the existing `output/` convention.

## CI And Validation Use

This utility is supporting evidence only. It should not run as part of `npm run verify`.

For CI jobs or manual release notes, Browser Run screenshots can support:

- settings-page copy/layout review
- admin activity review
- block editor panel review
- Site Editor visual workflow review
- comparison artifacts when local Playwright browsers are unavailable

If a required Playwright harness is unavailable or known-red, the validation record must still call that out as a blocker or explicit waiver. Browser Run screenshots can attach visual evidence, but they do not prove behavior by themselves.

## Error Handling

- Missing `CLOUDFLARE_ACCOUNT_ID` or `CLOUDFLARE_API_TOKEN`: exit nonzero with a setup message.
- Missing auth for admin/editor presets: exit nonzero and explain accepted cookie/header inputs.
- Cloudflare 4xx or 5xx response: exit nonzero, write a failure metadata file, and redact token/header values.
- Invalid manifest: exit nonzero with the JSON path and validation error.
- Invalid URL: exit nonzero before calling Cloudflare.
- Empty response body: exit nonzero and write metadata only.

The tool should never print cookie values, authorization headers, or Cloudflare API tokens.

## Documentation Updates

Implementation should update:

- `package.json` with the npm script.
- `docs/reference/cross-surface-validation-gates.md` to describe Browser Run screenshots as optional supporting evidence.
- `docs/reference/local-environment-setup.md` or a focused audit doc with the target URL contract, required env vars, auth handling, and artifact location.

## Verification

Implementation should include a unit-testable parser/request-builder layer so behavior can be validated without live Cloudflare calls. Tests should cover:

- preset URL resolution
- manifest parsing
- required env validation
- admin-auth requirement
- Cloudflare request body construction
- metadata redaction
- nonzero failure behavior

Manual live verification should be one explicit command against an operator-supplied public target when Cloudflare credentials and a temporary admin auth input are available. If credentials or auth are unavailable, record that as a verification blocker rather than treating the live step as passed.

## References

- Cloudflare Browser Run screenshot endpoint: https://developers.cloudflare.com/browser-run/quick-actions/screenshot-endpoint/
- Cross-surface validation gates: `docs/reference/cross-surface-validation-gates.md`
- Cloudflare Browser Run documentation: https://developers.cloudflare.com/browser-run/
