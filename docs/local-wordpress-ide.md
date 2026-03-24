# Local WordPress IDE

This repo now includes a local WordPress development stack for the `Flavor Agent` plugin.

## What it gives you

- A local WordPress site in Docker.
- MariaDB for WordPress data.
- phpMyAdmin for quick database inspection.
- WP-CLI and Composer inside the WordPress container.
- A VS Code devcontainer that opens the repo from the live plugin mount point.

## Files

- `docker-compose.yml` — local WordPress, database, and phpMyAdmin services.
- `docker/wordpress/Dockerfile` — WordPress dev image with WP-CLI and Composer.
- `.devcontainer/devcontainer.json` — VS Code devcontainer configuration.
- `scripts/local-wordpress.ps1` — PowerShell helper for start/install/stop/wp/shell flows.
- `.env.example` — local defaults for ports, DB credentials, and admin bootstrap values.

## Prerequisites

- Docker Desktop
- VS Code with the Dev Containers extension if you want the container-based IDE

## Quick start

1. Copy `.env.example` to `.env` if you want custom ports or credentials.
2. Start Docker Desktop and wait until the engine is running.
3. Run:

```powershell
.\scripts\local-wordpress.ps1 install
```

If you're on Linux or macOS, run the same helper through PowerShell 7 (`pwsh`) if it's installed, or use the checked-in `docker-compose.yml` for a manual Docker Compose setup instead.

That command will:

- create `.env` from `.env.example` if needed
- build and start the local stack
- install WordPress if it is not already installed
- activate the `flavor-agent` plugin
- switch permalinks to `/%postname%/`

## URLs

- WordPress: `http://localhost:8888`
- phpMyAdmin: `http://localhost:8889`
- Default admin login: `admin` / `admin`

Change these in `.env` if needed.

## Common commands

```powershell
.\scripts\local-wordpress.ps1 start
.\scripts\local-wordpress.ps1 stop
.\scripts\local-wordpress.ps1 reset
.\scripts\local-wordpress.ps1 logs
.\scripts\local-wordpress.ps1 shell
.\scripts\local-wordpress.ps1 wp plugin list
.\scripts\local-wordpress.ps1 wp option get home
```

## VS Code devcontainer

Once Docker is running:

1. Open the repo in VS Code.
2. Run `Dev Containers: Reopen in Container`.
3. VS Code will attach to the `wordpress` service with the repo mounted at:

```text
/var/www/html/wp-content/plugins/flavor-agent
```

Inside the container you have:

- PHP
- Composer
- WP-CLI
- Node.js 20 from the devcontainer feature

## Development workflow

Inside the devcontainer or on the host, depending on your preference:

```bash
composer install
source ~/.nvm/nvm.sh && nvm use 20
npm ci
npm start
```

The plugin source is bind-mounted into the live WordPress plugins directory, so PHP and built assets update immediately in the local site.

On the host, stay on Node `20.x` / npm `10.x`. Newer npm `11` installs on this repo have produced nested `@wordpress/*` unpack `ENOENT` failures during `npm ci`.
