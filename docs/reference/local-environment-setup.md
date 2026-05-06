# Local Environment Setup

Flavor Agent development should use a WordPress nightly/trunk install with the current AI, connector, Gutenberg, MCP, and beta-testing companion plugins installed. A stock stable WordPress container is not representative for editor, Abilities API, Connectors, or MCP behavior.

## Baseline Tools

Install repository dependencies first:

```bash
composer install
source ~/.nvm/nvm.sh && nvm use
npm ci
```

The local WordPress stack also expects Docker, Docker Compose, PHP, Composer, WP-CLI, and Playwright browsers to be available on the host when running the full verification pipeline.

## WordPress Image Pinning

The primary local stack defaults to `wordpress:beta`, which tracks the current RC/beta. WordPress does not publish a `nightly` Docker tag; `beta` is the closest bleeding-edge tag on Docker Hub. To pin a specific build (for example, to match CI), override `WORDPRESS_BASE_IMAGE` in `.env`:

```env
# Current RC/beta (default)
WORDPRESS_BASE_IMAGE=wordpress:beta

# Latest stable (downgrade if you need to test against ship-released WordPress)
# WORDPRESS_BASE_IMAGE=wordpress:php8.2-apache

# Pin a specific RC (matches the WP 7.0 E2E harness)
# WORDPRESS_BASE_IMAGE=wordpress:beta-7.0-RC2-php8.2-apache
```

The separate `FLAVOR_AGENT_WP70_BASE_IMAGE` stays pinned to `wordpress:beta-7.0-RC2-php8.2-apache` for the reproducible WP 7.0 E2E harness (`npm run test:e2e:wp70`).

## Start And Install WordPress

Start the Docker stack:

```bash
npm run wp:start
```

The first run creates `.env` from `.env.example` through `scripts/ensure-local-env.js` before starting containers. The wrapper in `scripts/docker-compose.js` uses the Docker Compose CLI plugin when available and falls back to `docker-compose`.
The WordPress service also listens on the configured `WORDPRESS_PORT` inside the container so Site Health REST API and loopback checks can call `http://localhost:8888` from both the host and the container. On startup it ensures `wp-content/upgrade` is writable by the web server user for plugin and theme update checks.

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
	ai-services \
	ai-provider-for-openai \
	ai-provider-for-anthropic \
	plugin-check \
	--activate \
	--force \
	--allow-root
```

Install the MCP Adapter from GitHub. It is not distributed through the WordPress.org plugin directory, so clone it into `wp-content/plugins` and install its Composer dependencies:

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
- `ai-services`
- `ai-provider-for-openai`
- `ai-provider-for-anthropic`
- `mcp-adapter`
- `plugin-check`

Configure text-generation credentials in `Settings > Connectors`. The OpenAI and Anthropic provider plugins own their provider-specific setup; do not use Flavor Agent's embedding settings as a replacement for the Connectors runtime. In `Settings > Flavor Agent`, configure one Embedding Model for semantic features, then choose Pattern Storage when testing pattern recommendations: Qdrant uses the Embedding Model plus Qdrant, while Cloudflare AI Search uses a private managed pattern index. Developer Docs uses Flavor Agent's built-in public endpoint and does not require local Cloudflare credentials.

## WP 7.0 Browser Harness Scope

`scripts/wp70-e2e.js` provisions a deterministic Docker-backed browser harness for editor and Site Editor regressions. It is not the full representative local runtime described above unless a test explicitly extends it with companion plugins.

The bootstrap installs and activates the `ai` plugin from WordPress.org because Flavor Agent declares `Requires Plugins: ai` in its plugin header — without it `wp plugin activate flavor-agent` would refuse to run. To install additional companions for a specific spec (for example `gutenberg` or `ai-services`), set `FLAVOR_AGENT_WP70_COMPANION_PLUGINS` to a comma-separated slug list before running `npm run wp:e2e:wp70:bootstrap`; `ai` is always force-prepended to the list.

Current WP 7.0 browser specs exercise Flavor Agent editor behavior and selected Abilities API routes, but they do not validate the dedicated MCP server or the AI plugin Settings UI. Use the representative local runtime for MCP/AI-plugin manual checks, or extend `scripts/wp70-e2e.js` only when adding a dedicated MCP or AI-plugin Playwright spec.

## Cloudflare Pattern AI Search Metadata

Before selecting Cloudflare AI Search as the pattern retrieval backend, create a private AI Search instance for Flavor Agent pattern content. Flavor Agent reuses the Cloudflare account/token saved for the Embedding Model and only asks for the pattern index name in Pattern Storage. Declare exactly these five custom metadata fields as filterable metadata in the Cloudflare dashboard:

| Field | Type |
| --- | --- |
| `pattern_name` | Text |
| `candidate_type` | Text |
| `source` | Text |
| `synced_id` | Text |
| `public_safe` | Boolean |

Dashboard setup:

1. Open the Cloudflare dashboard and select the account that owns the AI Search instance.
2. Go to **AI > AI Search**, open the namespace used for local pattern testing, then open the private pattern-search instance.
3. Open the instance metadata or indexing configuration and add the five custom metadata fields listed above.
4. Mark each field as available for filtering so search requests can use `filters.pattern_name`.
5. Save the instance configuration and wait for the dashboard to finish applying the schema before running the first Flavor Agent pattern sync.

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

Run `npm run check:docs` whenever contributor-facing setup guidance changes.

## References

- WordPress Beta Tester supports nightly, beta, and release-candidate update channels and a bleeding-edge trunk channel: https://wordpress.org/plugins/wordpress-beta-tester/
- WP-CLI `wp core update` accepts `--version=nightly`: https://developer.wordpress.org/cli/commands/core/update/
- MCP Adapter is installed from `WordPress/mcp-adapter`: https://github.com/WordPress/mcp-adapter
