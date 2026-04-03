# Codex Broker Provider Architecture

> Created: 2026-04-03
> Verified against public docs: 2026-04-03
> Status: forward-looking architecture draft for a separate standalone plugin, not current Flavor Agent behavior

## Purpose

Define a concrete architecture for a WordPress.org-compatible standalone provider plugin that:

1. lets individual WordPress users connect a Codex / ChatGPT account without API keys
2. exposes Codex-backed models to the WordPress AI Client as a provider
3. remains consumable by Gutenberg/admin products such as Flavor Agent through existing AI Client, REST, and Abilities layers

This document assumes the provider plugin will be published separately from Flavor Agent.

## Product Summary

Working name:

- plugin slug: `ai-provider-for-codex`
- provider ID: `codex`

High-level product shape:

1. a WordPress plugin registers the `codex` provider and manages WordPress-side UX
2. a hosted broker service manages Codex auth, account state, and request execution
3. each WordPress user links their own Codex account to the broker
4. WordPress feature plugins call the provider through the normal WordPress AI Client path

The plugin should feel structurally similar to the official WordPress.org AI provider plugins:

- provider registration first
- narrow setup and status UI second
- no end-user prompt playground or feature-specific editor UX inside the provider plugin

## Why A Hosted Broker Exists

The public Codex docs and local Codex source both describe non-API-key auth on the Codex app-server account surface, including:

- `chatgpt` managed browser login
- `account/read` and `account/rateLimits/read` for account and quota state
- `chatgptAuthTokens` as an external-token path, but with a caveat: the checked-in protocol source marks it experimental / internal-only and its field shape currently diverges from the public docs

That makes the broker the correct home for:

- ChatGPT/Codex login initiation
- token persistence and refresh
- Codex account status
- Codex app-server or SDK execution

The WordPress plugin should not attempt to own or persist Codex bearer tokens directly.

## Non-Goals

This architecture does not aim to:

1. mirror the full Codex `config.toml` surface in WordPress
2. require a local `codex` binary on the WordPress host
3. treat `Settings > Connectors` as the primary auth store
4. expose Codex sandbox, shell, worktree, or local MCP controls inside WordPress
5. turn the provider plugin into an editor product with its own block/template UX

## Constraints

### OpenAI / Codex Constraints

As of 2026-04-03, public Codex docs indicate:

1. Codex app-server is the deep integration surface for rich clients
2. non-key auth lives on the app-server account flow, not the normal OpenAI API auth surface
3. standard OpenAI API calls still document Bearer API key auth

Design implication:

- the plugin should integrate with a service that owns Codex app-server sessions instead of trying to recreate Codex auth semantics in pure WordPress PHP

### WordPress.org Constraints

As of 2026-04-03, WordPress.org plugin guidelines permit serviceware, but require:

1. the hosted service must provide substantive functionality
2. the plugin must clearly document the external service, data flow, privacy, and terms
3. external requests require clear user consent
4. no iframe-based admin UI for the service
5. no offloaded JS/CSS/assets unrelated to the actual service
6. no trialware pattern where the service only validates access to locally-contained functionality

Design implication:

- the broker must be the real Codex execution layer, not a fake license gate

## WordPress Provider Plugin Shape

To fit WordPress.org expectations, `ai-provider-for-codex` should look and behave like a normal AI provider plugin first, and a Codex broker client second.

That means the plugin should:

1. register `codex` through the WordPress AI Client provider registry
2. expose provider metadata that can be surfaced by core connector and provider UI
3. expose models in a provider-native shape and let consuming plugins express model preferences instead of hard-coding Codex assumptions
4. answer support checks from local provider state so consuming plugins can rely on deterministic `is_supported_*()` behavior
5. return provider/model/usage metadata in the same result-oriented shape that other provider plugins expose through the AI Client
6. keep its own admin surface limited to broker installation, user connection, status, and troubleshooting

It should not:

1. become a second AI application in wp-admin
2. own feature-specific prompt builders or playground UIs
3. require consuming plugins to learn a Codex-only PHP API

### Connectors Posture

Official WordPress provider plugins inherit credential handling from `Settings > Connectors` when they use standard API-key auth. Codex is different.

For v1:

- register like a normal provider plugin so WordPress can treat `codex` as a provider
- allow core connector/provider surfaces to show Codex as installed and available
- keep actual site installation and per-user account linking in plugin-owned local admin pages
- do not try to force ChatGPT/Codex session state into the Connectors API key store

If core later gains first-class support for service-backed per-user auth providers, the Codex setup flow can migrate closer to stock connector UX. Until then, the plugin should mimic the provider-plugin form factor without pretending Codex auth is a normal API-key connector.

## System Overview

```text
+----------------------------+         +-----------------------------+
| WordPress Site             |         | Hosted Codex Broker         |
|                            |         |                             |
| ai-provider-for-codex      |  HTTPS  | Site registry               |
| - provider registration    +-------->+ User connection service     |
| - user connect UX          |         | Codex auth/session service  |
| - broker client            |         | Codex execution adapter     |
| - local status caching     |         | Model catalog service       |
+-------------+--------------+         +--------------+--------------+
              |                                            |
              | wp_ai_client_prompt()                      | Codex app-server
              |                                            | or Codex SDK
              v                                            v
+----------------------------+         +-----------------------------+
| Feature Plugins            |         | OpenAI / Codex Runtime      |
| Flavor Agent, future UIs   |         | ChatGPT login + Codex       |
+----------------------------+         +-----------------------------+
```

## Runtime Ownership Boundaries

### WordPress Plugin Owns

1. provider registration with the WordPress AI Client
2. site-level broker installation settings
3. current-user connect/disconnect UI
4. short-lived local auth state during browser callbacks
5. request signing to the broker
6. local readiness checks and friendly error states
7. account snapshot caching for UI only

### Broker Owns

1. Codex login flows
2. ChatGPT token persistence and refresh
3. user-to-site connection records
4. Codex session orchestration
5. model catalog and account entitlement resolution
6. request execution and normalization
7. broker-side audit logging and abuse controls

### Feature Plugins Own

1. prompts and model preferences
2. product-specific validation and permissions
3. editor/admin UI
4. apply/preview/undo semantics
5. abilities and REST routes for their own products

## WordPress Plugin Structure

Recommended module layout:

```text
ai-provider-for-codex/
├── plugin.php
├── readme.txt
├── inc/
│   ├── Provider/
│   │   ├── Registry.php
│   │   ├── CodexProvider.php
│   │   ├── ProviderMetadata.php
│   │   ├── ModelCatalog.php
│   │   └── SupportChecks.php
│   ├── Broker/
│   │   ├── Client.php
│   │   ├── RequestSigner.php
│   │   ├── SiteRegistration.php
│   │   └── ResponseMapper.php
│   ├── Auth/
│   │   ├── ConnectionRepository.php
│   │   ├── ConnectionSnapshotRepository.php
│   │   ├── AuthStateRepository.php
│   │   ├── ConnectController.php
│   │   └── DisconnectController.php
│   ├── Admin/
│   │   ├── SiteSettings.php
│   │   ├── UserConnectionPage.php
│   │   └── Assets.php
│   ├── REST/
│   │   ├── ConnectController.php
│   │   └── StatusController.php
│   └── Database/
│       └── Installer.php
├── src/
│   └── admin/
│       └── codex-connect-app.js
└── build/
```

## Broker Structure

Recommended broker modules:

```text
broker/
├── api/
│   ├── installations
│   ├── connections
│   ├── accounts
│   ├── models
│   └── responses
├── services/
│   ├── site-registry
│   ├── user-connections
│   ├── codex-auth
│   ├── codex-sessions
│   ├── model-catalog
│   └── rate-limits
├── adapters/
│   ├── codex-app-server
│   └── codex-sdk
└── persistence/
```

## Data Model

### Site-Level Settings In WordPress

Store in options:

- `codex_broker_base_url`
- `codex_broker_site_id`
- `codex_broker_site_secret`
- `codex_broker_default_model`
- `codex_broker_default_reasoning_effort`
- `codex_broker_allowed_models`
- `codex_broker_enable_feature_plugins`

### User Connection Records In WordPress

Use a custom table instead of raw user meta so expiry, multisite, and audit fields are explicit.

Suggested table: `{$wpdb->prefix}codex_broker_connections`

Columns:

- `id`
- `blog_id`
- `user_id`
- `connection_id`
- `broker_user_id`
- `status`
- `account_email`
- `plan_type`
- `default_model`
- `allowed_models_json`
- `rate_limits_json`
- `session_expires_at`
- `last_verified_at`
- `created_at`
- `updated_at`

Suggested status values:

- `unlinked`
- `pending`
- `linked`
- `expired`
- `revoked`
- `error`

### Short-Lived Browser Callback State

Suggested table: `{$wpdb->prefix}codex_broker_auth_states`

Columns:

- `state`
- `blog_id`
- `user_id`
- `redirect_to`
- `expires_at`
- `created_at`

This row exists only long enough to validate the connect callback and exchange a one-time broker code.

## Authentication And Linking Flow

### Site Installation Flow

Purpose:

- establish trust between a WordPress site and the hosted broker

Recommended flow:

1. admin opens `Settings > Codex Broker`
2. plugin sends the admin to broker onboarding
3. broker creates or selects a broker-side site installation
4. broker redirects back with a one-time installation code
5. plugin exchanges that code for:
   - `site_id`
   - `site_secret`
   - broker metadata
6. plugin stores those values in site options

Result:

- the site can sign server-to-server requests to the broker without storing user bearer tokens

### User Connect Flow

Purpose:

- link a WordPress user to a Codex account managed by the broker

Recommended flow:

1. logged-in user clicks `Connect Codex`
2. plugin creates a short-lived local `state`
3. browser is redirected to the broker connect URL
4. broker starts the Codex `chatgpt` login flow
5. broker completes login and creates a broker-side user connection
6. broker redirects back with:
   - original `state`
   - one-time `broker_code`
7. plugin validates `state`
8. plugin exchanges `broker_code` with the broker
9. broker returns:
   - `connection_id`
   - account snapshot
   - allowed model list
   - expiry metadata
10. plugin persists a local connection row

Important rule:

- WordPress stores only the opaque `connection_id` and display metadata, never the ChatGPT or Codex tokens

### Disconnect Flow

1. user clicks `Disconnect Codex`
2. plugin calls broker disconnect endpoint
3. broker invalidates the linked connection
4. plugin marks the local row `revoked`

## Request Execution Flow

### Text Generation Flow

1. a feature plugin calls `wp_ai_client_prompt()`
2. prompt selects `codex` by explicit provider or model preference
3. `CodexProvider` checks:
   - broker configured
   - current user linked
   - requested modality supported
   - requested model available
4. `CodexProvider` signs a broker request with:
   - site identity
   - current user identity
   - opaque `connection_id`
   - normalized prompt payload
5. broker resolves the linked Codex session
6. broker executes the request through Codex app-server or Codex SDK
7. broker normalizes the result to the provider response shape
8. plugin maps it back into WordPress AI Client result objects

### Model Selection Precedence

The provider should not clone full `config.toml` semantics. Use a WordPress-native precedence stack:

1. request-level explicit model
2. request-level model preference list
3. user default model
4. site default model
5. broker recommended default for the account

Optional request-level settings:

- reasoning effort
- structured output / JSON Schema when the broker can enforce it
- service tier only if the broker intentionally exposes it

Avoid promising per-turn `maxOutputTokens` or `temperature` controls in v1 unless the broker implements them independently of Codex app-server, because they are not first-class app-server turn parameters in the audited source.

Do not expose:

- sandbox mode
- local MCP server definitions
- writable roots
- shell approvals
- worktree controls

Those remain broker/runtime concerns, not provider settings.

## Provider Contract

The provider plugin should present itself like any other AI Client provider:

1. provider ID: `codex`
2. provider registration and metadata should be sufficient for core to treat it like a standard provider plugin, even though auth is broker-backed
3. modality support:
   - text generation: yes
   - structured text / JSON: yes, when broker can enforce it
   - image generation: no in v1
   - audio generation: no in v1
   - embeddings: no in v1 unless explicitly added later
4. models returned dynamically from the broker per user/account
5. support checks must be user-aware, not site-global
6. provider support checks should read a local cached broker snapshot instead of making live broker requests during every `is_supported_*()` call
7. provider results should include normalized provider/model metadata so consuming plugins can inspect provenance the same way they would for other providers

Design rule:

- a site may have the `codex` provider installed, but any given user may still be unsupported until they connect their account

## Readiness States

Recommended readiness model:

- `broker_unconfigured`
- `site_unregistered`
- `user_unlinked`
- `connection_expired`
- `model_unavailable`
- `broker_unreachable`
- `ready`

Feature plugins should surface these states cleanly rather than treating the provider as globally available or unavailable.

The provider should persist a short-lived local readiness snapshot per user connection so these states can be computed locally during normal WordPress page loads.

## Security Model

### WordPress To Broker Trust

Use a site-scoped shared secret issued by the broker at installation time.

Each server-to-server request should include:

- `X-Codex-Site-Id`
- `X-Codex-Timestamp`
- `X-Codex-Signature`

Signature base string should include:

- method
- path
- timestamp
- body hash

The broker rejects stale timestamps and invalid signatures.

### User Identity

Each request should also send:

- `wp_user_id`
- `connection_id`
- optional `blog_id`

The broker must verify:

1. the connection belongs to the calling site
2. the connection belongs to the referenced user
3. the connection is still linked and valid

### Token Storage

Rules:

1. WordPress never stores Codex bearer tokens
2. broker stores the minimum token material needed for session refresh
3. local tables store only opaque IDs plus display metadata

## Broker-Side Execution Choice

Preferred default:

- use Codex app-server for interactive Codex account-backed execution

Optional fallback:

- use Codex SDK for automation or service-managed jobs where app-server semantics are not needed

Broker design should hide that distinction from WordPress. The provider contract should remain stable regardless of the underlying Codex runtime.

Source audit notes:

- the Python `Codex` convenience wrapper currently exposes threads, turns, and model listing, but not convenience methods for `account/login/start`, `account/read`, or `account/rateLimits/read`
- the lower-level Python `AppServerClient.request(...)` surface can still call those RPCs directly
- the TypeScript SDK is a CLI wrapper optimized for local thread execution and does not expose the auth/account surface needed for per-user broker linking

Implementation implication:

- a broker that needs Codex-managed login, account inspection, and rate-limit reads should prefer a direct app-server JSON-RPC adapter, or a thin wrapper around `AppServerClient.request(...)`, over the higher-level SDK facades

## Caching Strategy

Local plugin cache:

- account snapshot: short TTL
- model list: short TTL
- support checks: very short TTL

Broker cache:

- model catalog by account/plan
- rate-limit snapshot
- recent account details

Never cache:

- browser callback states after use
- disconnected connection validity

## WordPress.org Review Strategy

The plugin directory submission should be framed as a service plugin, not a generic AI shell.

Readme requirements:

1. explain that the plugin requires a hosted Codex broker service
2. explain exactly what data is sent to the broker
3. link to:
   - broker service homepage
   - terms of use
   - privacy policy
4. explain that user consent is established during site installation and per-user account linking
5. explain that no OpenAI / Codex API keys are stored in WordPress

Admin UX rules:

1. no iframe for the broker UI
2. local JS/CSS assets only
3. explicit user action before remote requests
4. clear disconnect control

## Multisite

Initial recommendation:

- treat each site as a separate broker installation in v1

Reason:

- simpler audit trail
- simpler secret rotation
- easier user support

Do not attempt network-wide shared user connections in v1.

## Failure Modes

### Broker Unavailable

Behavior:

- support checks fail closed
- provider reports unavailable with a specific broker status
- editor surfaces show setup or retry messaging

### User Session Expired

Behavior:

- broker returns `connection_expired`
- plugin marks the local connection row expired
- user is asked to reconnect

### Model No Longer Allowed

Behavior:

- broker returns a provider error with replacement suggestions
- plugin falls back to the next allowed model preference when possible

### Site Secret Compromised

Behavior:

- broker rotates the installation secret
- plugin requires a new site installation exchange

## Implementation Phases

### Phase 1: Broker MVP

1. site installation handshake
2. user connect/disconnect
3. account snapshot
4. dynamic model listing
5. text generation only

### Phase 2: WordPress Provider MVP

1. register `codex` provider
2. implement per-user support checks
3. add site settings page
4. add user connect page
5. add broker client and request signing

### Phase 3: Feature Plugin Pilot

1. test with a small feature plugin or private Flavor Agent branch
2. verify:
   - support checks
   - model preference routing
   - useful error states
   - account expiry handling

### Phase 4: Directory Hardening

1. review readme for serviceware disclosure
2. review privacy/terms links
3. remove non-runtime files from build artifact
4. run Plugin Check and plugin review checklist

## Broker v1 Acceptance Checklist

### Provider-Plugin Shape

- `codex` registers through the WordPress AI Client provider registry, not a private Flavor Agent hook
- provider metadata is complete enough for WordPress.org-style provider discovery and status UI
- the plugin exposes only provider setup, account connection, and health/status surfaces
- consuming plugins continue to use normal AI Client model-preference and result APIs

### Auth And Session Ownership

- site installation uses a broker-issued site identity and shared secret
- per-user linking defaults to managed `chatgpt` login
- external token auth is explicitly treated as unstable and excluded from the stable v1 UX
- WordPress stores no Codex bearer tokens or refresh tokens

### Provider Runtime Behavior

- model lists come from the broker per linked user/account
- support checks are satisfied from a local cached broker snapshot, not a live request on every page render
- generation requests are signed server-to-server and normalized back to standard provider result metadata
- rate-limit state is modeled as snapshot windows, not invented RPM/TPM counters
- provider settings do not promise `maxOutputTokens` or `temperature` unless the broker implements them itself

### WordPress.org Review Posture

- readme clearly discloses the external broker service, privacy policy, and terms
- no iframe-based broker UI is embedded in wp-admin
- remote requests happen only after explicit setup or user connection actions, plus normal signed generation traffic
- the plugin can explain why its service is substantive and why Codex execution cannot happen locally in stock WordPress PHP

## Open Questions

1. whether the broker should support multiple Codex runtimes from day one or standardize on app-server first
2. whether WordPress AI Client provider registration can expose useful per-user account metadata in a standard way or needs custom UI
3. whether the plugin should allow site admins to require account linking only for specific roles
4. whether v1 should support only specific Codex model aliases or all account-visible coding models

## External References

- Codex app-server: <https://developers.openai.com/codex/app-server>
- Codex authentication: <https://developers.openai.com/codex/auth>
- Codex CLI reference: <https://developers.openai.com/codex/cli/reference>
- Codex config basics: <https://developers.openai.com/codex/config-basic>
- Detailed Plugin Guidelines: <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>
- Common Issues handbook: <https://developer.wordpress.org/plugins/wordpress-org/common-issues/>
- Plugin Developer FAQ: <https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/>
