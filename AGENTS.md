# Repository Guidelines

## MCP Tooling
Use the available MCP server tools liberally when they can speed up implementation, verification, or research. Prefer the `wpds` MCP server for WordPress Design System work. Use the Cloudflare AI Search MCP server liberally as a vector index of WordPress developer documentation when you need targeted WordPress API, block editor, REST API, hooks, or core-behavior lookup faster than manual browsing.

## Project Structure & Module Organization
`flavor-agent.php` is the plugin bootstrap and hook registration entrypoint. PHP application code lives in `inc/` under the `FlavorAgent\\` namespace, with focused areas such as `Abilities/`, `REST/`, `Admin/`, `Activity/`, and provider integrations. Editor and admin UI code lives in `src/`; compiled assets are emitted to `build/`, and release-ready packages are staged in `dist/`. Tests are split between `tests/phpunit/` for PHP and `tests/e2e/` for Playwright. Product and source-of-truth docs live in `docs/` plus root files like `readme.txt` and `STATUS.md`.

## Build, Test, and Development Commands
Run `composer install` and `npm install` first. Use `npm run build` for production assets and `npm run start` for watch mode during Gutenberg/editor work. Use `npm run wp:start`, `npm run wp:stop`, and `npm run wp:reset` to manage the local Docker WordPress stack from `docker-compose.yml`. Quality checks are `npm run lint:js`, `composer run lint:php`, and `npm run lint:plugin`. Test commands are `npm run test:unit`, `composer run test:php`, and `npm run test:e2e` (Playground plus WP 7.0 coverage). Run `npm run check:docs` whenever feature behavior or contributor-facing docs change.

## Coding Style & Naming Conventions
Match the existing tab-based indentation in both PHP and JS. PHP files should keep `declare(strict_types=1);`, use PSR-4 classes in `inc/`, and follow existing `PascalCase.php` naming. The PHPCS ruleset is WordPress-oriented, but allows short arrays and non-Yoda conditions. In `src/`, UI components use `PascalCase.js`; helpers and utilities use lower-case or kebab-case filenames. Edit source files in `inc/` and `src/`; do not hand-edit generated output in `build/` or release artifacts in `dist/`.

## Testing Guidelines
Add or update the closest test for every behavior change. JS unit tests live beside features in `src/**/__tests__/*.test.js` and run through `@wordpress/scripts`. PHP tests live in `tests/phpunit/*Test.php` and boot through `tests/phpunit/bootstrap.php`. Add Playwright coverage for editor and admin regressions when UI flows change. There is no declared coverage threshold, but PRs should show meaningful automated coverage for new logic.

## Commit & Pull Request Guidelines
Recent history is clearest when it uses short imperative subjects such as `Add template part recommender helpers and tests`. Follow that style and avoid placeholder commits like `up`; squash or rewrite them before opening a PR. PRs should describe the user-visible change, list the lint/test commands you ran, link related issues when applicable, and include screenshots or recordings for editor or wp-admin UI changes. Call out required config or environment details such as `WP_PLUGIN_CHECK_PATH` or backend provider settings.
