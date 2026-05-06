# Browser Run Screenshot Audits

Flavor Agent can collect optional screenshot evidence through Cloudflare Browser Run when a public WordPress URL is easier to reach than the local Playwright harness. This is supporting visual evidence only. It does not replace `npm run test:e2e:playground`, `npm run test:e2e:wp70`, or the blocker/waiver record required by `docs/reference/cross-surface-validation-gates.md`.

## Target URL

Cloudflare Browser Run must be able to reach the target URL. Provide a public WordPress target with `--base-url`, a manifest `baseUrl`, absolute manifest step URLs, or `BROWSER_RUN_DEFAULT_BASE_URL`. Localhost URLs are unsupported unless the operator supplies a reachable tunnel URL.

## Credentials

Set Cloudflare Browser Rendering credentials in the shell before running the utility:

```bash
export CLOUDFLARE_ACCOUNT_ID="..."
export CLOUDFLARE_API_TOKEN="..."
```

The token needs Browser Rendering edit permission for the account.

Authenticated WordPress pages require one explicit temporary auth input:

```bash
npm run audit:screenshot -- --preset=settings --base-url="https://example.test" --cookies-file=/tmp/wp-admin-cookies.json
npm run audit:screenshot -- --preset=settings --base-url="https://example.test" --extra-headers-file=/tmp/wp-admin-headers.json
BROWSER_RUN_COOKIES_JSON="$(cat /tmp/wp-admin-cookies.json)" npm run audit:screenshot -- --preset=settings --base-url="https://example.test"
BROWSER_RUN_DEFAULT_BASE_URL="https://example.test" npm run audit:screenshot -- --preset=settings --cookies-file=/tmp/wp-admin-cookies.json
```

Do not commit cookies, header files, Cloudflare tokens, or generated screenshots. The script never creates auth files in the repository.

## Presets

```bash
npm run audit:screenshot -- --preset=settings --base-url="https://example.test"
npm run audit:screenshot -- --preset=admin --base-url="https://example.test"
npm run audit:screenshot -- --preset=block-editor --base-url="https://example.test" --url="/wp-admin/post.php?post=123&action=edit"
npm run audit:screenshot -- --preset=site-editor --base-url="https://example.test" --url="/wp-admin/site-editor.php"
npm run audit:screenshot -- --manifest=docs/audits/admin-ui-flow.json
```

Useful overrides:

```bash
npm run audit:screenshot -- --preset=settings --base-url="https://example.test" --viewport=390x844@2 --no-full-page
npm run audit:screenshot -- --preset=settings --base-url="https://example.test" --selector="#wpbody-content"
```

The `settings`, `admin`, `block-editor`, and `site-editor` presets fail fast without WordPress auth. Manifest steps also require auth when their resolved URL targets `/wp-admin/` or `/wp-login.php`.

## Workflow Manifests

Workflow manifests are URL-only capture lists. They do not describe clicks or scripted browser interactions.

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

Command-line `--base-url`, `--viewport`, `--selector`, and full-page flags override manifest defaults for all steps in that run.

## Output

Artifacts are written under:

```text
output/browser-run/{timestamp}-{run-name}/
```

Each step writes:

```text
{step-id}.png
{step-id}.json
```

Metadata records the step ID, preset or manifest name, final URL, timestamp, viewport, selector, full-page flag, Cloudflare account ID suffix, safe response headers, HTTP status, and output filename. It does not persist token values, cookie values, authorization headers, or `set-cookie` response headers.

## Validation Use

Use these screenshots for quick settings-page, wp-admin, block editor, and Site Editor visual evidence when local screenshots are unavailable or when release notes need shareable artifacts.

For release validation, keep the normal evidence ladder:

1. Run the nearest targeted PHPUnit and JS suites.
2. Run `node scripts/verify.js --skip-e2e`.
3. Run the targeted Playwright harnesses that match the changed surfaces.
4. Record a blocker or explicit waiver when a required browser harness is known-red or unavailable.
5. Attach Browser Run screenshots only as supporting visual evidence.

If Cloudflare credentials or temporary WordPress auth are unavailable, record the live Browser Run step as blocked instead of treating it as passed.
