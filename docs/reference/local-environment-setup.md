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

The primary local stack defaults to the Docker Official WordPress image (Apache/mod_php), with MariaDB on a Docker Hardened Image (DHI):

```env
WORDPRESS_BASE_IMAGE=wordpress:7.1.0-php8.3-apache
MARIADB_IMAGE=dhi.io/mariadb:11.8-debian13-dev
```

WordPress 7.1 has no Docker Hardened Image yet — DHI's newest WordPress build is `7.0.4` — so the WordPress container stays on the Docker Official image until DHI catches up. MariaDB already runs on DHI.

DHI images are pulled from `dhi.io` and need a (free) Docker account: `docker login dhi.io`. When the WordPress container runs on a DHI base, the `-dev` tags are mandatory, not a preference — that container needs a shell, `apt-get`, `curl`, Composer and WP-CLI for `npm run wp:*`, `scripts/plugin-check.sh` and the E2E harnesses, and non-dev DHI tags ship none of those. Treat that image as a development harness only.

Two DHI properties shape the setup when the WordPress container runs on a DHI base:

- **php-fpm only.** DHI publishes no Apache/mod_php WordPress variant, so `docker/wordpress/build-setup.sh` installs nginx and `docker/wordpress/entrypoint.sh` fronts php-fpm (127.0.0.1:9000) with it. The published port and the in-container loopback listener are unchanged.
- **No beta/RC/nightly channel.** DHI ships stable releases only. Tracking trunk still requires the Docker Official `wordpress:beta-*` images.

`docker/wordpress/Dockerfile` supports both base-image families and detects which one it is building on, so switching is a one-line `.env` change:

```env
# WordPress 7.1 stable on PHP 8.2 (matches the Site Editor E2E harness)
# WORDPRESS_BASE_IMAGE=wordpress:7.1.0-php8.2-apache

# Track 7.1 pre-releases as they roll forward (RC/beta line for the 7.1 major)
# WORDPRESS_BASE_IMAGE=wordpress:beta-7.1-php8.2-apache

# Newest pre-release of any major (rolls onto 7.2 betas when they land)
# WORDPRESS_BASE_IMAGE=wordpress:beta

# Newest DHI WordPress build (7.0.4; php-fpm + nginx, one major behind)
# WORDPRESS_BASE_IMAGE=dhi.io/wordpress:7.0.4-debian13-php8.3-fpm-dev

# Move the WordPress container back to DHI once DHI publishes a 7.1 build
# WORDPRESS_BASE_IMAGE=dhi.io/wordpress:7.1.x-debian13-php8.3-fpm-dev

# Docker Official MariaDB
# MARIADB_IMAGE=mariadb:11.4
```

WordPress does not publish a `nightly` Docker tag; the `beta-*` tags are the closest bleeding-edge tags on Docker Hub, and they cover release candidates as well as betas. Keeping the `7.1` segment matters: the tag rolls forward on its own through RC2 and later but stays on the 7.1 line, whereas the bare `beta` tag silently jumps to 7.2 betas as soon as those start publishing.

The separate `FLAVOR_AGENT_WP70_BASE_IMAGE` stays pinned to the exact stable `wordpress:7.1.0-php8.2-apache` for the reproducible Site Editor E2E harness (`npm run test:e2e:wp70`). The fully qualified patch tag is deliberate: the floating `7.1` tag can be republished, which would silently change what the release gates verified. Holding that harness on PHP 8.2 while the dev container runs PHP 8.3 is also deliberate — it keeps the plugin's declared `Requires PHP: 8.2` floor under continuous browser coverage. DHI publishes no 7.1 WordPress build, so moving that harness to DHI would change what the release gates verify and is a deliberate decision, not a drop-in swap.

The `wp70` segment in `scripts/wp70-e2e.js`, `playwright.wp70.config.js`, the `FLAVOR_AGENT_WP70_*` variables, and the `@wp70-site-editor` spec tag is a historical name from when that harness was introduced on WordPress 7.0. It identifies the Docker-backed Site Editor harness, not the WordPress version it runs; both Playwright harnesses now run WordPress 7.1.

### Migrating An Existing Stack To DHI

DHI runs MariaDB as uid **65532**; the Docker Official image used uid **999**. An existing `db_data` volume is therefore unreadable to the DHI server, and the switch needs a one-time volume reset (this destroys the local database):

```bash
npm run wp:reset     # docker compose down -v
npm run wp:rebuild
```

The DHI MariaDB entrypoint provisions only the `root` account — it has no `MARIADB_DATABASE`/`MARIADB_USER` handling and no `/docker-entrypoint-initdb.d` hook. `docker-compose.yml` therefore creates the application database and user from a Compose config mounted at `/etc/mariadb/bootstrap.sql` and passed to the server via `MARIADB_OPTIONS=--init-file=...`. The SQL replays on every start, so anything added to it must stay idempotent. The Docker Official image ignores `MARIADB_OPTIONS` and keeps using the `MARIADB_*` variables, which is why both are set.

DHI also ships a minimal `/etc/passwd`, `/etc/group` and no `init-system-helpers`. `build-setup.sh` restores the `adm` group, the `www-data` account and `init-system-helpers` before installing packages, because Debian maintainer scripts (nginx) fail without them. `www-data` is aliased onto uid/gid **65532** — the DHI `nonroot` account the php-fpm pool already runs as — rather than the conventional 33, so files the WordPress entrypoint chowns to `www-data` stay writable by PHP.

No Docker Hardened Image is published for phpMyAdmin, so that service stays on `phpmyadmin:5-apache`.

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
Devcontainer and Codespaces runs cannot rely on that script, because Compose resolves `docker-compose.yml` before any npm script can execute. Two things cover the gap: every interpolated variable in `docker-compose.yml` carries the same default as `.env.example`, so the stack builds with no `.env` at all, and the `initializeCommand` in `.devcontainer/devcontainer.json` copies `.env.example` to `.env` on the host before container creation. The copy is no-clobber, so a customized `.env` is never overwritten.
The WordPress service also listens on the configured `WORDPRESS_PORT` inside the container so Site Health REST API and loopback checks can call `http://localhost:8888` from both the host and the container. On startup it ensures `wp-content/upgrade` is writable by the web server user for plugin and theme update checks.

### Browser Auth Base URL

Browser automation must use the same origin WordPress advertises in its `home` option. `localhost` and `127.0.0.1` are separate browser cookie origins; if a probe logs in on one host and WordPress redirects to the other, wp-admin appears logged out and editor-store waits time out.

For one-off Playwright probes against the Docker-backed local stack, resolve the canonical browser URL first:

```bash
npm run --silent wp:browser-url
```

Use that value as the Playwright `baseURL` and login target. The helper prefers explicit overrides such as `FLAVOR_AGENT_BROWSER_BASE_URL`, then reads `wp option get home` from the WordPress container, and only falls back to `http://localhost:${WORDPRESS_PORT:-8888}` when the container is unavailable. The dedicated Site Editor harness remains separate: use `getWp70HarnessConfig().baseURL`, because the harness sets WordPress `home` and `siteurl` to the same origin during bootstrap.

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

Install the MCP Adapter from GitHub, pinned to `v0.6.1` (2026-08-13). As of that release the upstream README recommends the WordPress-plugin form installed from the GitHub Releases ZIP and documents Composer as the plugin-developer library path; the git-clone development-version section was removed upstream, reversing the Composer-primary framing that held at v0.5.0. Upstream also ships a WP.org-format `readme.txt` (`Stable tag: 0.6.1`, `Contributors: wordpressdotorg`) for Plugin Check compliance, but its Installation section still points at GitHub releases and no live `wordpress.org/plugins` listing is referenced anywhere in the v0.6.1 tree.

Representative local setup keeps the clone-and-composer block below, pinned to a tag for reproducibility, because the clone needs `composer install` to generate the adapter's autoloader. Re-run `composer install` after every version change — `includes/Autoloader.php` requires `vendor/autoload_packages.php` (Jetpack Autoloader, adopted in upstream #233) with no fallback to `vendor/autoload.php`, so a stale vendor tree makes the plugin bail out silently before `mcp_adapter_init` fires.

The upstream-recommended alternative is a one-liner:

```bash
docker compose exec -T wordpress wp plugin install \
	https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip \
	--activate --force --allow-root
```

Any ZIP install must be **>= 0.6.1**. The published v0.6.0 ZIP omitted `tests/` while its Jetpack Autoloader manifest still referenced those paths, shipping dangling classmap entries — including `WP_CLI` / `WP_CLI_Command` stubs — that can fatal a site on a bare `class_exists( 'WP_CLI' )`. Upstream fixed this in `23cb53e` (#284) by adding `exclude-from-classmap`. Note that PR #284's description claims it also added a release-ZIP verifier, but no such verifier shipped in the tag, so this defect class remains unguarded upstream.

```bash
docker compose exec -T wordpress bash -lc 'set -e
cd /var/www/html/wp-content/plugins
if [ ! -d mcp-adapter/.git ]; then
	rm -rf mcp-adapter
	git clone https://github.com/WordPress/mcp-adapter.git mcp-adapter
fi
cd mcp-adapter
git fetch --tags origin
git checkout v0.6.1
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

## Site Editor Browser Harness Scope

`scripts/wp70-e2e.js` provisions a deterministic Docker-backed browser harness for editor and Site Editor regressions. It is not the full representative local runtime described above unless a test explicitly extends it with companion plugins.

The bootstrap installs and activates the `ai` plugin from WordPress.org because Flavor Agent declares `Requires Plugins: ai` in its plugin header — without it `wp plugin activate flavor-agent` would refuse to run. To install additional companions for a specific spec (for example a provider connector), set `FLAVOR_AGENT_WP70_COMPANION_PLUGINS` to a comma-separated list before running `npm run wp:e2e:wp70:bootstrap`; `ai` is always force-prepended to the list. Entries accept a `slug@version` form (`gutenberg@23.6.2, plugin-check`) so a recorded gate names the exact package it verified — an unpinned slug installs whatever is current that day, which is not reproducible.

### Gutenberg browser gate

The default harness runs the editor that ships inside the pinned WordPress image, so Gutenberg is opt-in — installing it by default would silently change what every existing WP 7.0 gate verifies.

```bash
npm run wp:e2e:wp70:bootstrap:gutenberg   # pinned Gutenberg (23.7.1) + WP 7.0.0
npm run test:e2e:wp70
```

`--with-gutenberg=<version>` (or `FLAVOR_AGENT_WP70_GUTENBERG`) targets another point on the line; use `23.6.2` rather than `23.6.0` for the 23.6 line, and `23.7.1` rather than `23.7.0`, because each earlier tag was superseded. Bootstrap prints the WordPress image and the resolved companion list — copy both into the compatibility record in `gutenberg-feature-tracking.md`, since "ran against Gutenberg" without a version is not evidence.

Current Site Editor browser specs exercise Flavor Agent editor behavior and selected Abilities API routes, but they do not validate the dedicated MCP server or the AI plugin Settings UI. Use the representative local runtime for MCP/AI-plugin manual checks, or extend `scripts/wp70-e2e.js` only when adding a dedicated MCP or AI-plugin Playwright spec.

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
- MCP Adapter source repo (currently the active local-setup path, pinned to `v0.6.1`): https://github.com/WordPress/mcp-adapter. As of the upstream README at v0.6.1 (2026-08-13) the recommended install is the WordPress-plugin form from the GitHub Releases ZIP, with Composer documented as the plugin-developer library path; upstream removed its git-clone development-version section. A WP.org-format `readme.txt` exists for Plugin Check compliance, but there is still no live `wordpress.org/plugins` listing. Adapter requires WordPress 6.9+ (Abilities API is core from 6.9), below Flavor Agent's own 7.0 floor.
