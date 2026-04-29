# LLM Context for Flavor Agent (wp-hperkins-com)

Use this file when asking an LLM to review/modify `flavor-agent` code.
The goal is to avoid overloading the model with the full WordPress install and still give enough signal for correct assumptions.

## Static install anchors

- WordPress site path: `/home/ubuntu/wp-hperkins-com`
- Plugin source path: `/home/ubuntu/flavor-agent`
- Installed in WP as symlink: `wp-content/plugins/flavor-agent -> /home/ubuntu/flavor-agent`
- Plugin main entry on disk: `/home/ubuntu/flavor-agent/flavor-agent.php`
- WP plugin basename when loaded through the symlink: `flavor-agent/flavor-agent.php`
- Plugin version: check `FLAVOR_AGENT_VERSION` in `flavor-agent.php` (currently `0.1.0`)

## Host constraints to pass explicitly

- This is **not** a stock WP plugin project; it is a local plugin in a larger WordPress installation with many active plugins.
- Treat WordPress core + dependencies as available only via their public APIs.
- Do not infer plugin internals unless a file path is supplied.
- For behavior that touches REST routes, hooks, editor internals, or caching, assume existing integration points already exist and validate with repo tests/build.

## Runtime plugin/theme snapshot for `wp-hperkins-com`

Snapshot reviewed in this context on 2026-04-28. Refresh before relying on exact runtime state:

- Active plugin basenames: `wp option get active_plugins --path=/home/ubuntu/wp-hperkins-com --format=json`
- Theme options: `wp option get stylesheet --path=/home/ubuntu/wp-hperkins-com`, `wp option get template --path=/home/ubuntu/wp-hperkins-com`, and `wp option get current_theme --path=/home/ubuntu/wp-hperkins-com`

Known active dependency slugs, normalized for LLM context. Do not treat this list as exact `active_plugins` basename values or load order unless refreshed from the database:

- `ai-provider-for-anthropic`
- `ai-provider-for-openai`
- `ai-services`
- `ai`
- `akismet`
- `enable-abilities-for-mcp`
- `flavor-agent`
- `google-site-kit`
- `gutenberg`
- `hdc-ai-media-modal`
- `jetpack-backup`
- `jetpack-boost`
- `jetpack-search`
- `jetpack`
- `mcp-adapter`
- `plugin-check`
- `woocommerce-gateway-stripe`
- `woocommerce-payments`
- `woocommerce-paypal-payments`
- `woocommerce`
- `wordpress-beta-tester`

Theme context:
- `stylesheet`: `henrys-digital-canvas`
- `template`: `twentytwentyfive`
- `current_theme`: `Henry's Digital Canvas`

## Source map to give the LLM first

- Bootstrap: `flavor-agent.php`
- Server integration classes: `inc/`
- Client UI + editor integrations: `src/`
- REST routes/abilities: `inc/Abilities/`, `inc/REST/`
- Settings/admin: `inc/Admin/`, `src/admin/`
- Prompt/LLM plumbing: `inc/LLM/`
- Patterns/index: `inc/Patterns/`
- Tests:
  - PHP: `tests/phpunit/`
  - JS: `src/**/__tests__/`
  - E2E: `tests/e2e/`

## Preferred LLM request preamble

Paste this at the top of each request (edit values as needed):

```
Context:
I am modifying the Flavor Agent plugin for the live WordPress install at `/home/ubuntu/wp-hperkins-com`.
Source is in `/home/ubuntu/flavor-agent` and mounted as plugin symlink `wp-content/plugins/flavor-agent`.
Do not assume full WordPress internals; only assume contracts for these plugin internals:
- flavor-agent.php, inc/, src/, tests/phpunit/, tests/e2e/.
Build artifacts may be inspected to understand compiled output, but do not edit build/ or dist/ directly.
Relevant active plugins include gutenberg, wordpress-beta-tester, ai, ai-services, ai-provider-for-openai, ai-provider-for-anthropic, mcp-adapter, plugin-check, enable-abilities-for-mcp, and WooCommerce.
When you need behavior in WordPress core, use stable public API references and flag assumptions.
Focus your changes on the provided files and keep edits minimal.
```

## Session prep checklist

Before sending the prompt:

1. Update this file if active plugins/theme changed.
2. Include exact file list you changed (`inc/...`, `src/...`, tests).
3. Tell the model whether to target PHP, JS, tests, or docs.
4. If behavior depends on runtime state, include it explicitly:
   - editor route/surface (`Block`, `Template`, `Template Part`, etc.)
	   - REST route touched
	   - capability assumptions (`edit_posts`, `edit_theme_options`, `manage_options`)
5. If exact plugin identity or load order matters, refresh `active_plugins` and paste plugin basenames, not normalized slugs.

## Runtime loop note

- For PHP changes: hot-reload through direct file edit in `/home/ubuntu/flavor-agent` is enough.
- For JS/CSS changes: run `npm run build` or watch via `npm run start`, then validate in wp-hperkins-com.
- If UI/stale behavior looks unchanged: clear WP caches + browser hard refresh.
