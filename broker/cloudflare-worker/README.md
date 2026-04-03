# Codex Broker on Cloudflare

This directory contains the first Cloudflare-hosted broker shell for the WordPress `ai-provider-for-codex` plugin.

It is intentionally split into two layers:

1. a public Worker API that handles WordPress site registration, request signing, local connection/session state, and admin setup
2. a future stateful Codex runtime adapter that will handle real ChatGPT/Codex login, account refresh, rate-limit reads, and text execution

## What is implemented

- Cloudflare Worker API under `/v1/wordpress/*`
- D1-backed tables for installation codes, registered sites, auth sessions, and user connections
- Site-scoped HMAC verification matching the WordPress plugin contract
- Admin-only installation-code minting via `POST /v1/admin/installation-codes`
- Deployable health endpoints: `/readyz` and `/healthz`
- Broker-hosted connect page at `/connect/{authSessionId}`

## What is not implemented yet

- real Codex auth/login completion
- broker code issuance
- live account snapshots
- rate-limit refresh
- text generation execution

Right now the Worker deploys a production-ready broker shell, but `BROKER_RUNTIME_MODE=unavailable` means connection and generation flows stop at the runtime boundary with explicit errors instead of pretending to work.

## Why the runtime is separate

The audited Codex app-server surface is stateful and transport-oriented:

- it runs as `codex app-server`
- the stable transport is stdio, with websocket marked experimental
- managed ChatGPT login emits async `account/login/completed` notifications

That does not fit a pure stateless Worker. On Cloudflare, the realistic next step is:

- Worker + D1 for the public broker API
- a stateful runtime, most likely Cloudflare Containers plus a Durable Object, to host `codex app-server`
- a broker-hosted device-code UX instead of app-server's localhost browser callback flow

## Setup

1. Install dependencies:

```bash
cd broker/cloudflare-worker
npm install
```

2. Create the D1 database:

```bash
npx wrangler d1 create codex-broker-wp-hperkins
```

3. Copy the returned `database_id` into `wrangler.jsonc`.

4. Apply migrations:

```bash
npm run db:migrate:remote
```

5. Set secrets:

```bash
openssl rand -base64 32 | tr -d '\n' | npx wrangler secret put BROKER_ENCRYPTION_KEY
openssl rand -hex 24 | tr -d '\n' | npx wrangler secret put BROKER_ADMIN_TOKEN
```

`BROKER_ENCRYPTION_KEY` must decode to 32 bytes. The command above satisfies that.

6. Deploy:

```bash
npm run deploy
```

## Minting an installation code

After deploy, create a one-time installation code for the WordPress plugin:

```bash
curl -X POST "https://YOUR-WORKER.workers.dev/v1/admin/installation-codes" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"label":"wp.hperkins.com","expiresInSeconds":3600}'
```

Use the returned `installationCode` on the WordPress settings page for `ai-provider-for-codex`.

## Next runtime milestone

To make live authentication work on Cloudflare, add a runtime adapter that:

- starts a stateful Codex app-server session
- uses ChatGPT managed device-code login
- observes `account/login/completed`
- stores and restores Codex home/auth state across container restarts
- maps `account/read`, `account/rateLimits/read`, `model/list`, and turn execution into the broker contract
