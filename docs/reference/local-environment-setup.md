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

Configure text-generation credentials in `Settings > Connectors`. The OpenAI and Anthropic provider plugins own their provider-specific setup; do not use Flavor Agent's Azure/OpenAI Native embedding settings as a replacement for the Connectors runtime. Pattern recommendations still need a retrieval backend configured in `Settings > Flavor Agent`: Qdrant with plugin-owned embeddings, or a private Cloudflare AI Search instance for pattern retrieval.

## Cloudflare Pattern AI Search Metadata

Before selecting Cloudflare AI Search as the pattern retrieval backend, create a private AI Search instance for Flavor Agent pattern content and declare exactly these five custom metadata fields as filterable metadata in the Cloudflare dashboard:

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

Use a token with **AI Search:Edit** and **AI Search:Run** permissions for this private pattern instance. Do not reuse the built-in public WordPress developer-docs AI Search endpoint for pattern content.

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
