# Local WordPress IDE Workflow

This is the lightweight local-development guide referenced by the main doc index.

## Prerequisites

- PHP 8.0+
- Composer
- Node 20.x and npm 10.x
- Docker for the local WordPress stack and the WP 7.0 browser harness

## First-Time Setup

```bash
composer install
source ~/.nvm/nvm.sh && nvm use 20
npm ci
```

## Start The Local Stack

```bash
npm run wp:start
```

This brings up the repo's Docker-backed WordPress environment from `docker-compose.yml`.

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
- The repo pins Node 20 / npm 10 through `.nvmrc` and `.npmrc`
- The default `npm run test:e2e` command aggregates the quick Playground harness and the Docker-backed WP 7.0 harness

## Stop Or Reset The Stack

```bash
npm run wp:stop
npm run wp:reset
```

`npm run wp:reset` removes volumes and destroys local WordPress data.
