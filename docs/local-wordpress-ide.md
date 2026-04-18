# Local WordPress IDE Workflow

This is the lightweight local-development guide referenced by the main doc index.

## Prerequisites

- PHP 8.0+
- Composer
- Node 24.x and npm 11.x via `.nvmrc` (Node 20.x and npm 10.x also supported)
- Docker Desktop (Windows: WSL2 backend) for the local WordPress stack and the WP 7.0 browser harness

## First-Time Setup

```bash
composer install
source ~/.nvm/nvm.sh && nvm use
npm ci
```

If you want to review or override the Docker defaults, copy `.env.example` to `.env` before starting the stack. The `wp:*` npm scripts also create `.env` from `.env.example` automatically when it is missing.

## WordPress Version Policy (Bleeding Edge)

The primary local stack defaults to `wordpress:beta` (tracks the current RC/beta release) so this plugin is developed against WordPress 7.0+. The official WordPress Docker image does not publish a `nightly` tag — `beta` is the closest bleeding-edge tag available on Docker Hub.

To pin to a specific bleeding-edge build (e.g., to match CI), override `WORDPRESS_BASE_IMAGE` in `.env`:

```env
# Current RC/beta (default)
WORDPRESS_BASE_IMAGE=wordpress:beta

# Latest stable (downgrade if you need to test against ship-released WordPress)
# WORDPRESS_BASE_IMAGE=wordpress:php8.2-apache

# Pin a specific RC (matches the WP 7.0 E2E harness)
# WORDPRESS_BASE_IMAGE=wordpress:beta-7.0-RC2-php8.2-apache
```

The separate `FLAVOR_AGENT_WP70_BASE_IMAGE` stays pinned to `wordpress:beta-7.0-RC2-php8.2-apache` for the reproducible WP 7.0 E2E harness (`npm run test:e2e:wp70`).

## Start The Local Stack

```bash
npm run wp:start
```

This brings up the repo's Docker-backed WordPress environment from `docker-compose.yml` and bootstraps a local `.env` from `.env.example` when needed.

## Build The Plugin

```bash
npm run build
```

For active development, use:

```bash
npm start
```

## Recommended Verification Loop

```bash
npm run lint:js
npm run test:unit -- --runInBand
composer lint:php
vendor/bin/phpunit
```

Run browser coverage as needed:

```bash
npm run test:e2e
```

## Useful Notes

- `build/` is gitignored, so run `npm run build` before testing in WordPress
- `vendor/` is gitignored, so run `composer install` after a fresh clone
- `.nvmrc` defaults to Node 24 / npm 11, and the repo also supports Node 20 / npm 10 through the `package.json` engine range under `.npmrc`'s `engine-strict` check
- The default `npm run test:e2e` command aggregates the quick Playground harness and the Docker-backed WP 7.0 harness
- On Windows, prefer Docker Desktop with the WSL2 backend. Start Docker Desktop before `npm run wp:start`.

## Cleaning Up Playground Temp Directories (Windows)

The WP Playground CLI (used by the Playground E2E harness) creates per-run temp directories under `%TEMP%` named like `node.exe-playground-cli-site-*`. These can accumulate to several GB over time because Playground does not always clean them up on exit. Run periodically:

```powershell
Get-ChildItem $env:TEMP -Filter "*playground*" -Directory |
  Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
```

## Stop Or Reset The Stack

```bash
npm run wp:stop
npm run wp:reset
```

`npm run wp:reset` removes volumes and destroys local WordPress data.
