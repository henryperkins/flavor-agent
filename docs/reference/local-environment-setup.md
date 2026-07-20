# Local Environment Setup

Flavor Agent development should use a WordPress nightly/trunk install with the current AI, connector-provider, Gutenberg, MCP, and beta-testing companion plugins installed. A stock stable WordPress container is not representative for editor, Abilities API, Connectors, or MCP behavior.

## Baseline Tools

Install repository dependencies first:

```bash
composer install
source ~/.nvm/nvm.sh && nvm use
npm ci
```

Node runtimes for this repo must be **major 20 or 24** (matching `package.json` `engines.node: ^20 || ^24`), with **24** as the default selector via `.node-version`.

The local WordPress stack also expects Docker, Docker Compose, PHP, Composer, WP-CLI, and Playwright browsers to be available on the host when running the full verification pipeline.

## WordPress Image Pinning

The primary local stack defaults to `wordpress:beta`, which tracks the current RC/beta. WordPress does not publish a `nightly` Docker tag; `beta` is the closest bleeding-edge tag on Docker Hub. To pin a specific build (for example, to match CI), override `WORDPRESS_BASE_IMAGE` in `.env`:

```env
# Current RC/beta (default)
WORDPRESS_BASE_IMAGE=wordpress:beta

# Latest stable (downgrade if you need to test against ship-released WordPress)
# WORDPRESS_BASE_IMAGE=wordpress:php8.2-apache

# Pin stable 7.0.0 (matches the WP 7.0 E2E harness)
# WORDPRESS_BASE_IMAGE=wordpress:7.0.0-php8.2-apache
```

The separate `FLAVOR_AGENT_WP70_BASE_IMAGE` stays pinned to the exact stable `wordpress:7.0.0-php8.2-apache` for the reproducible WP 7.0 E2E harness (`npm run test:e2e:wp70`). The fully qualified patch tag is deliberate: the floating `7.0` tag can be republished, which would silently change what the release gates verified.

## Start And Install WordPress

Start the Docker stack:

```bash
npm run wp:start
```
If you changed the Dockerfile or need to refresh the mutable WordPress image, run `wp:rebuild` instead. It pulls the current base image, rebuilds, and starts the stack:

```bash
npm run wp:rebuild
```

The first run creates `.env` from `.env.example` through `scripts/ensure-local-env.js` before starting containers. The wrapper in `scripts/docker-compose.js` uses the Docker Compose CLI plugin when available and falls back to `docker-compose`.
The WordPress service also listens on the configured `WORDPRESS_PORT` inside the container so Site Health REST API and loopback checks can call `http://localhost:8888` from both the host and the container. On startup it ensures `wp-content/upgrade` is writable by the web server user for plugin and theme update checks.

### Browser Auth Base URL

Browser automation must use the same origin WordPress advertises in its `home` option. `localhost` and `127.0.0.1` are separate browser cookie origins; if a probe logs in on one host and WordPress redirects to the other, wp-admin appears logged out and editor-store waits time out.

For one-off Playwright probes against the Docker-backed local stack, resolve the canonical browser URL first:

```bash
npm run --silent wp:browser-url
```

Use that value as the Playwright `baseURL` and login target. The helper prefers explicit overrides such as `FLAVOR_AGENT_BROWSER_BASE_URL`, then reads `wp option get home` from the WordPress container, and only falls back to `http://localhost:${WORDPRESS_PORT:-8888}` when the container is unavailable. The dedicated WP 7.0 harness remains separate: use `getWp70HarnessConfig().baseURL`, because the harness sets WordPress `home` and `siteurl` to the same origin during bootstrap.

Install WordPress if the database volume is new. The examples below use the Docker Compose CLI form; if your host only has `docker-compose`, use `node scripts/docker-compose.js exec -T ...` or the wrapper-backed npm scripts instead.

```bash
docker compose exec -T wordpress wp core is-installed --allow-root || \
	docker compose exec -T wordpress wp core install \
		--url=http://localhost:8888 \
		--title='Flavor Agent Local' \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email \
		--allow-root
```

Move the install to the current nightly build. Do this even when the Docker image uses `wordpress:beta`; the container image is only the starting point, and this project should be checked against trunk/nightly behavior.

```bash
docker compose exec -T wordpress wp core update --version=nightly --force --allow-root
```

Install and activate the required WordPress.org companion plugins:

```bash
docker compose exec -T wordpress wp plugin install \
	wordpress-beta-tester \
	gutenberg \
	ai \
	ai-provider-for-openai \
	ai-provider-for-anthropic \
	ai-provider-for-google \
	plugin-check \
	--activate \
	--force \
	--allow-root
```

Install the MCP Adapter from GitHub. The upstream README at v0.5.0 (2026-04-15) treats Composer as the primary install method and the plugin form as an alternative, and does not mention WP.org. The 22 Apr 2026 AI contributor summary recorded an intent to add WP.org as the primary distribution, but no WP.org listing is live as of 2026-05-26 and the README has not been updated. Representative local setup clones from `WordPress/mcp-adapter` into `wp-content/plugins` and installs its Composer dependencies; if WP.org publication lands, swap the clone-and-composer block below for `wp plugin install mcp-adapter --activate --allow-root`.

```bash
docker compose exec -T wordpress bash -lc 'set -e
cd /var/www/html/wp-content/plugins
if [ ! -d mcp-adapter/.git ]; then
	rm -rf mcp-adapter
	git clone https://github.com/WordPress/mcp-adapter.git mcp-adapter
fi
cd mcp-adapter
git pull --ff-only
composer install --no-interaction --prefer-dist
wp plugin activate mcp-adapter --allow-root'
```

Activate Flavor Agent and refresh permalinks:

```bash
docker compose exec -T wordpress wp plugin activate flavor-agent --allow-root
docker compose exec -T wordpress wp rewrite structure '/%postname%/' --hard --allow-root
```

In wp-admin, confirm `Tools > Beta Testing` is set to bleeding-edge nightlies. The WP-CLI nightly update above is the deterministic setup step; the Beta Tester plugin keeps the admin update channel visible and aligned for manual refreshes.

## Required Runtime Plugins

The expected local runtime includes these active slugs:

- `flavor-agent`
- `wordpress-beta-tester`
- `gutenberg`
- `ai`
- `ai-provider-for-openai`
- `ai-provider-for-anthropic`
- `ai-provider-for-google`
- `mcp-adapter`
- `plugin-check`

Configure text-generation credentials in `Settings > Connectors`. The WordPress 7.0 Field Guide identifies Anthropic, Google, and OpenAI as the default Connectors screen providers, so the representative local runtime installs the matching WordPress.org-authored provider connector plugins when available. Provider plugins own their provider-specific setup; do not use Flavor Agent's embedding settings as a replacement for the Connectors runtime. In `Settings > Flavor Agent`, configure one Embedding Model for semantic features, then choose Pattern Storage when testing pattern recommendations: Qdrant uses the Embedding Model plus Qdrant, while Cloudflare AI Search uses a private managed pattern index. Developer Docs uses Flavor Agent's built-in public endpoint and does not require local Cloudflare credentials.

## Abilities Explorer (AI plugin Experiment)

The canonical AI plugin ships an Abilities Explorer Experiment that mounts at `Tools > Abilities Explorer` once enabled. It auto-discovers every ability registered with `wp_register_ability()` that declares `meta.show_in_rest = true`, shows the input/output schemas, and lets operators dispatch the ability with custom JSON input directly from wp-admin. It is the primary local harness for verifying Flavor Agent ability wiring without writing a Playwright spec.

Enable it in `Settings > AI > Experiments > Abilities Explorer`, then refresh wp-admin. The Tools menu will pick up the new screen.

Flavor Agent conventions when using the Explorer:

- **Before enabling the Flavor Agent AI feature, the Explorer should list the 20 always-on helper and preflight abilities.** That set is the thirteen helper/read abilities, the docs search, and the six `preview-recommend-*` siblings. After enabling the Flavor Agent feature in `Settings > AI`, the eight `recommend-*` abilities and the seven external-apply abilities (`request-style-apply`, `request-template-apply`, `request-template-part-apply`, `request-post-blocks-apply`, `get-activity`, `list-activity`, `undo-activity`) register too, bringing the full list to 35 abilities.
- **Use the preview siblings to dry-run recommendations.** Clicking *Run* on `flavor-agent/recommend-block` with the auto-generated example input invokes the LLM because `resolveSignatureOnly` defaults to `false`. Use `flavor-agent/preview-recommend-block` (and the other four `preview-recommend-*` siblings) instead — they force signature-only execution server-side, strip `clientRequest` to avoid transient writes, and return only the freshness signatures. No chat backend hit, no activity row, no Activity log entry.
- **Helper abilities are safe to click.** The ten externally-discoverable read helpers (`introspect-block`, `list-allowed-blocks`, `list-patterns`, `get-pattern`, `list-template-parts`, `list-templates`, `get-active-theme`, `get-theme-presets`, `get-theme-styles`, `get-theme-tokens`) are read-only and side-effect-free.
- **Three abilities stay editor-internal.** `list-synced-patterns`, `get-synced-pattern`, and `check-status` are not marked `mcp.public` and stay scoped to Abilities-API consumers; the Explorer still lists them because it reads `show_in_rest`, not `mcp.public`.

## WP 7.0 Browser Harness Scope

`scripts/wp70-e2e.js` provisions a deterministic Docker-backed browser harness for editor and Site Editor regressions. It is not the full representative local runtime described above unless a test explicitly extends it with companion plugins.

The bootstrap installs and activates the `ai` plugin from WordPress.org because Flavor Agent declares `Requires Plugins: ai` in its plugin header — without it `wp plugin activate flavor-agent` would refuse to run. To install additional companions for a specific spec (for example `gutenberg` or a provider connector), set `FLAVOR_AGENT_WP70_COMPANION_PLUGINS` to a comma-separated slug list before running `npm run wp:e2e:wp70:bootstrap`; `ai` is always force-prepended to the list.

Current WP 7.0 browser specs exercise Flavor Agent editor behavior and selected Abilities API routes, but they do not validate the dedicated MCP server or the AI plugin Settings UI. Use the representative local runtime for MCP/AI-plugin manual checks, or extend `scripts/wp70-e2e.js` only when adding a dedicated MCP or AI-plugin Playwright spec.

## Remote Screenshot Audits

For quick visual evidence from a public WordPress target, use the optional Cloudflare Browser Run Quick Actions utility:

```bash
export CLOUDFLARE_ACCOUNT_ID="..."
export CLOUDFLARE_API_TOKEN="..."
npm run audit:screenshot -- --preset=settings --base-url="https://example.test" --cookies-file=/tmp/wp-admin-cookies.json
```

The current Cloudflare product name is Browser Run, but the Quick Actions screenshot endpoint and required token permission still use the former `Browser Rendering` name.

For repeatable visual audits of the plugin, prefer the wrapper:

```bash
npm run audit:visual -- --target=wp-hperkins --suite=core
```

The wrapper combines Quick Actions URL checkpoints with Browser Run CDP workflow screenshots. The built-in `wp-hperkins` target uses `https://wp.hperkins.com` plus the local native WordPress root at `/home/dev/wp-hperkins-com` to mint and clean up short-lived WP-CLI auth cookies automatically. Use `--base-url` plus `--wp-path`, or `--base-url` plus `--cookies-file`, for other reachable WordPress targets.

Provide a reachable WordPress target with `--base-url`, a manifest `baseUrl`, or `BROWSER_RUN_DEFAULT_BASE_URL`. Localhost URLs are unsupported unless the operator supplies a reachable tunnel URL.

Admin/editor screenshots require temporary cookies, `BROWSER_RUN_COOKIES_JSON`, or an explicit extra-headers JSON file. Do not commit those auth inputs or the generated screenshots. Artifacts are written under `output/browser-run/{timestamp}-{run-name}/` and are ignored through the existing `output/` convention.

Browser Run screenshots are supporting visual evidence only. They do not replace the Playwright harnesses, and missing browser assertions still need the blocker or waiver record described in `docs/reference/cross-surface-validation-gates.md`. More usage examples live in `docs/reference/browser-run-screenshot-audits.md`.

## Cloudflare Pattern AI Search Metadata

Before selecting Cloudflare AI Search as the pattern retrieval backend, save Cloudflare account ID and API token values under Embedding Model; the embedding model can be saved explicitly or left blank to use the default Workers AI embedding model. When Cloudflare AI Search Pattern Storage is selected, Flavor Agent creates or adopts a dedicated managed AI Search instance named `flavor-agent-patterns-{site_hash}` in Cloudflare's `default` namespace. The token must have AI Search permissions in addition to Workers AI embedding access.

The managed instance uses built-in storage, Cloudflare-managed R2 and Vectorize resources, hybrid keyword/vector indexing, 1024-token chunks, 15 percent overlap, and exactly these five custom metadata fields:

| Field | Type |
| --- | --- |
| `pattern_name` | Text |
| `candidate_type` | Text |
| `source` | Text |
| `synced_id` | Text |
| `public_safe` | Boolean |

The normal setup path is to select Cloudflare AI Search Pattern Storage on `Settings > Flavor Agent`, then click the standard `Save Changes` button. The save flow creates or adopts the managed pattern index. Existing deterministic instances are adopted only when the schema, Flavor Agent owner marker, and normalized AI Search embedding model prove compatibility. If a prior provisioning run with the same credential signature created the instance but failed before validating the marker, Flavor Agent can repair the missing owner marker only when that compatible deterministic instance is still empty. Blank or unsupported Embedding Model values normalize to `@cf/qwen/qwen3-embedding-0.6b` for this private index path. Pattern item upload/list/delete operations use Cloudflare's documented `default` namespace item routes. If an existing `flavor-agent-patterns-{site_hash}` instance has an incompatible schema, belongs to another install, already contains items without the owner marker, or was created with a different normalized AI Search embedding model, Flavor Agent blocks adoption; fix or remove that conflicting Cloudflare instance and save settings again. After changing the Embedding Model value, save Pattern Storage again so the managed AI Search signature is revalidated before the next sync.

Use an Embedding Model token that also has **AI Search:Edit** and **AI Search:Run** permissions for this private pattern instance. Do not reuse the built-in public WordPress developer-docs AI Search endpoint for pattern content.

## Plugin Check

`npm run lint:plugin` and the `lint-plugin` step in `npm run verify` run WP-CLI on the host against a real WordPress root. When using the Docker stack, export the Docker-backed WordPress root and database environment first:

```bash
export WP_PLUGIN_CHECK_PATH="$(docker volume inspect wordpress_wordpress_data --format '{{ .Mountpoint }}')"
export WORDPRESS_DB_HOST="$(docker exec wordpress-db-1 hostname -i):3306"
export WORDPRESS_DB_NAME=wordpress
export WORDPRESS_DB_USER=wordpress
export WORDPRESS_DB_PASSWORD=wordpress
```

The host user needs traverse access to the Docker volume path and read access to the WordPress files:

```bash
sudo setfacl -m "u:$(id -un):--x" \
	/var/lib/docker \
	/var/lib/docker/volumes \
	/var/lib/docker/volumes/wordpress_wordpress_data
sudo setfacl -R -m "u:$(id -un):rx" \
	"${WP_PLUGIN_CHECK_PATH}/wp-content" \
	"${WP_PLUGIN_CHECK_PATH}/wp-content/plugins"
```

On the verified Docker-backed local stack, that mountpoint resolves to `/var/lib/docker/volumes/wordpress_wordpress_data/_data`.

The wrapper stages the release copy outside the Docker volume in `${TMPDIR:-/tmp}` by default, so it does not require host write access to `wp-content/plugins`. To use a different writable staging directory, set:

```bash
export PLUGIN_CHECK_STAGE_DIR="$(pwd)/tmp/plugin-check"
```

Then run:

```bash
npm run lint:plugin
```

If the host cannot access Docker volume paths, run `npm run verify -- --skip=lint-plugin` only as an explicit local waiver and record that Plugin Check was not exercised.

On Windows with Docker Desktop, the Linux Docker volume path is usually not visible to host PHP/WP-CLI. Set `PLUGIN_CHECK_USE_DOCKER=1` in `.env` to run the same staged Plugin Check command inside the `wordpress` container after `npm run wp:start` and the WordPress install/bootstrap steps have completed. This keeps Plugin Check as an active gate without requiring host access to `/var/lib/docker/volumes`.

## Build, Stop, And Reset

Build the plugin once before testing in WordPress, since `build/` is gitignored:

```bash
npm run build       # production build
npm start           # webpack watch for active development
```

Stop or reset the Docker stack:

```bash
npm run wp:stop     # stop containers
npm run wp:reset    # docker compose down -v (destroys volumes)
```

On Windows, prefer Docker Desktop with the WSL2 backend. Start Docker Desktop before `npm run wp:start`.

## Cleaning Up Playground Temp Directories (Windows)

The WP Playground CLI (used by the Playground E2E harness) creates per-run temp directories under `%TEMP%` named like `node.exe-playground-cli-site-*`. These can accumulate to several GB over time because Playground does not always clean them up on exit. Run periodically:

```powershell
Get-ChildItem $env:TEMP -Filter "*playground*" -Directory |
  Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
```

## Verification

Use the aggregate verifier after the local runtime is prepared:

```bash
npm run verify
```

For a faster loop during development:

```bash
npm run verify -- --skip-e2e
```

Run `npm run check:docs` whenever contributor-facing setup guidance changes. The script requires `rg` (ripgrep) on `PATH`; without it the script exits 2 with a preflight message, and verify-driven strict runs mark the `check-docs` step as skipped (contributing to `incomplete`).

## References

- WordPress Beta Tester supports nightly, beta, and release-candidate update channels and a bleeding-edge trunk channel: https://wordpress.org/plugins/wordpress-beta-tester/
- WP-CLI `wp core update` accepts `--version=nightly`: https://developer.wordpress.org/cli/commands/core/update/
- MCP Adapter source repo (currently the active local-setup path): https://github.com/WordPress/mcp-adapter. The upstream README at v0.5.0 (2026-04-15) treats Composer as the primary install method and the plugin form as an alternative, and does not mention WP.org. The 22 Apr 2026 AI contributor summary recorded an intent to add WP.org as the primary distribution, but no WP.org listing is live as of 2026-05-26 and the README has not been updated.
