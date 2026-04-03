# Codex Broker Provider API Contract

> Created: 2026-04-03
> Verified against public docs: 2026-04-03
> Status: draft contract for the standalone `ai-provider-for-codex` design

## Purpose

Define the server-to-server and browser callback contract between:

1. the WordPress plugin `ai-provider-for-codex`
2. the hosted Codex broker service

This contract is intentionally narrower than Codex app-server itself. WordPress should never talk to Codex app-server directly.

## Source Audit Notes

This contract is a broker-normalized surface, not a raw mirror of Codex app-server.

As of 2026-04-03, the local Codex source audit shows:

1. `chatgptAuthTokens` exists on the app-server auth surface, but the checked-in protocol marks it experimental / internal-only and its request/refresh fields currently diverge from the public app-server docs
2. app-server exposes rate limits as `RateLimitSnapshot` windows, not `requestsPerMinute` / `tokensPerMinute` counters
3. app-server exposes per-model metadata such as `defaultReasoningEffort`, `supportedReasoningEfforts`, and `inputModalities`

Design implication:

- where this broker contract intentionally simplifies app-server semantics, the broker must perform that normalization explicitly instead of assuming those fields already exist upstream

## WordPress Provider Alignment

This broker contract exists to back a WordPress AI provider plugin that should look as close as possible to the official WordPress.org provider plugins.

That means:

1. WordPress-facing generation should still flow through the AI Client provider abstraction
2. provider metadata, model metadata, and result metadata should be normalized into WordPress-friendly shapes
3. browser setup should remain narrow and provider-oriented
4. Codex-specific auth complexity should stay behind the broker

Important caveat:

- the WordPress AI Client documents support checks such as `is_supported_for_text_generation()` as deterministic and local
- therefore, the provider plugin should satisfy support checks from a cached local connection snapshot, not by turning every support check into a live broker round-trip
- this contract may still expose lightweight readiness-refresh endpoints, but those are for snapshot refresh, admin status UI, connect callbacks, or explicit recovery flows

## Transport Rules

All broker endpoints must:

1. require HTTPS
2. return JSON
3. use UTF-8
4. reject unsigned server-to-server requests

## Trust Model

### Browser Flows

Browser redirects are used only for:

- site installation onboarding
- per-user connect flow

Browser redirects never carry persistent access tokens back to WordPress.

### Server-To-Server Flows

WordPress authenticates to the broker with a site-scoped HMAC signature.

Required headers:

- `X-Codex-Site-Id`
- `X-Codex-Timestamp`
- `X-Codex-Signature`

Signature input:

```text
HTTP_METHOD + "\n" +
REQUEST_PATH + "\n" +
TIMESTAMP + "\n" +
SHA256(REQUEST_BODY)
```

The broker validates:

1. site exists
2. timestamp freshness
3. signature correctness

## Installation Endpoints

### `POST /v1/wordpress/installations/exchange`

Purpose:

- exchange a one-time installation code for a long-lived site identity

Request:

```json
{
  "installationCode": "one-time-code-from-broker-onboarding",
  "siteUrl": "https://example.com",
  "homeUrl": "https://example.com",
  "adminEmail": "admin@example.com",
  "wpVersion": "7.0",
  "pluginVersion": "0.1.0"
}
```

Response:

```json
{
  "siteId": "site_123",
  "siteSecret": "secret_abc",
  "brokerBaseUrl": "https://broker.example.com",
  "defaultModel": "gpt-5.3-codex",
  "allowedModels": [
    "gpt-5-codex",
    "gpt-5.3-codex"
  ]
}
```

Notes:

- `siteSecret` is stored only in WordPress options
- broker should support installation secret rotation later

## User Connection Endpoints

### `POST /v1/wordpress/connections/start`

Purpose:

- create a broker connect URL for the current WordPress user

Signed request body:

```json
{
  "wpUserId": 42,
  "wpUserEmail": "editor@example.com",
  "wpUserDisplayName": "Editor User",
  "state": "local-short-lived-state",
  "returnUrl": "https://example.com/wp-admin/admin.php?page=codex-connect"
}
```

Response:

```json
{
  "connectUrl": "https://broker.example.com/connect?state=local-short-lived-state"
}
```

### `POST /v1/wordpress/connections/exchange`

Purpose:

- exchange a one-time broker code for a persistent opaque connection

Signed request body:

```json
{
  "wpUserId": 42,
  "state": "local-short-lived-state",
  "brokerCode": "broker_one_time_code"
}
```

Response:

```json
{
  "connectionId": "conn_123",
  "brokerUserId": "user_456",
  "status": "linked",
  "account": {
    "email": "editor@example.com",
    "planType": "pro",
    "authMode": "chatgpt"
  },
  "models": [
    "gpt-5-codex",
    "gpt-5.3-codex"
  ],
  "defaults": {
    "model": "gpt-5.3-codex",
    "reasoningEffort": "medium"
  },
  "rateLimits": {
    "limitId": "codex",
    "planType": "pro",
    "primary": {
      "usedPercent": 25,
      "windowDurationMins": 15,
      "resetsAt": 1730947200
    },
    "secondary": null
  },
  "sessionExpiresAt": "2026-04-04T12:00:00Z"
}
```

### `GET /v1/wordpress/connections/{connectionId}`

Purpose:

- refresh account snapshot and linked status

Signed query parameters:

- `wpUserId`

Response:

```json
{
  "connectionId": "conn_123",
  "status": "linked",
  "account": {
    "email": "editor@example.com",
    "planType": "pro",
    "authMode": "chatgpt"
  },
  "models": [
    "gpt-5-codex",
    "gpt-5.3-codex"
  ],
  "rateLimits": {
    "limitId": "codex",
    "planType": "pro",
    "primary": {
      "usedPercent": 25,
      "windowDurationMins": 15,
      "resetsAt": 1730947200
    },
    "secondary": null
  },
  "sessionExpiresAt": "2026-04-04T12:00:00Z"
}
```

### `POST /v1/wordpress/connections/{connectionId}/disconnect`

Purpose:

- revoke a linked account connection

Signed request body:

```json
{
  "wpUserId": 42
}
```

Response:

```json
{
  "connectionId": "conn_123",
  "status": "revoked"
}
```

## Model Endpoints

### `GET /v1/wordpress/models`

Purpose:

- return models available for the current user connection

Signed query parameters:

- `wpUserId`
- `connectionId`

Response:

```json
{
  "models": [
    {
      "provider": "codex",
      "id": "gpt-5-codex",
      "label": "GPT-5 Codex",
      "inputModalities": [ "text", "image" ],
      "defaultReasoningEffort": "medium",
      "supportedReasoningEfforts": [ "low", "medium", "high" ],
      "supportsStructuredOutput": true,
      "supportsReasoningEffort": true
    },
    {
      "provider": "codex",
      "id": "gpt-5.3-codex",
      "label": "GPT-5.3 Codex",
      "inputModalities": [ "text", "image" ],
      "defaultReasoningEffort": "medium",
      "supportedReasoningEfforts": [ "low", "medium", "high" ],
      "supportsStructuredOutput": true,
      "supportsReasoningEffort": true
    }
  ],
  "defaultModel": "gpt-5.3-codex"
}
```

## Support Snapshot Endpoint

### `POST /v1/wordpress/support/text`

Purpose:

- refresh or validate the cached readiness snapshot for a linked user without performing a full generation request
- support admin status UI, explicit reconnect flows, and cache refresh jobs
- not be required for every `is_supported_for_text_generation()` call on a normal WordPress page load

Signed request body:

```json
{
  "wpUserId": 42,
  "connectionId": "conn_123",
  "model": "gpt-5.3-codex",
  "reasoningEffort": "medium"
}
```

Response:

```json
{
  "supported": true,
  "reason": "ready",
  "checkedAt": "2026-04-03T12:00:00Z"
}
```

Possible `reason` values:

- `ready`
- `site_unregistered`
- `user_unlinked`
- `connection_expired`
- `model_unavailable`
- `broker_unreachable`

Provider rule:

- the WordPress provider should persist the latest broker-backed readiness snapshot locally and answer AI Client support checks from that local state

## Text Generation Endpoint

### `POST /v1/wordpress/responses/text`

Purpose:

- execute a text generation request through the linked Codex session

Signed request body:

```json
{
  "wpUserId": 42,
  "connectionId": "conn_123",
  "requestId": "req_789",
  "input": "Summarize this repository.",
  "systemInstruction": "You are a coding assistant.",
  "model": "gpt-5.3-codex",
  "modelPreferences": [
    "gpt-5.3-codex",
    "gpt-5-codex"
  ],
  "reasoningEffort": "medium",
  "responseFormat": null,
  "context": {
    "siteUrl": "https://example.com",
    "pluginSlug": "flavor-agent",
    "surface": "block-recommendations"
  }
}
```

Response:

```json
{
  "requestId": "req_789",
  "provider": "codex",
  "model": "gpt-5.3-codex",
  "outputText": "Repository summary goes here.",
  "finishReason": "stop",
  "usage": {
    "inputTokens": 1200,
    "outputTokens": 420,
    "reasoningTokens": 180
  },
  "account": {
    "planType": "pro"
  },
  "rateLimits": {
    "limitId": "codex",
    "planType": "pro",
    "primary": {
      "usedPercent": 25,
      "windowDurationMins": 15,
      "resetsAt": 1730947200
    },
    "secondary": null
  }
}
```

Notes:

- `responseFormat` is the broker-level analogue of app-server `outputSchema`
- `maxOutputTokens` is intentionally omitted from v1 because it is not a first-class Codex app-server turn parameter in the audited source
- response metadata should be mapped so WordPress consumers can treat Codex like a normal provider result, not a Codex-only special case

## Structured Output Extension

Optional v1.1 extension:

- allow `responseFormat` with JSON Schema

Request fragment:

```json
{
  "responseFormat": {
    "type": "json_schema",
    "schema": {
      "type": "object",
      "properties": {
        "summary": { "type": "string" }
      },
      "required": [ "summary" ]
    }
  }
}
```

Response fragment:

```json
{
  "outputText": "{\"summary\":\"Repository summary\"}",
  "structuredOutput": {
    "summary": "Repository summary"
  }
}
```

## Error Contract

All broker errors should use this shape:

```json
{
  "error": {
    "code": "connection_expired",
    "message": "The linked Codex session has expired.",
    "status": 401,
    "retryable": false
  }
}
```

Recommended error codes:

- `site_unregistered`
- `invalid_signature`
- `unknown_connection`
- `connection_expired`
- `connection_revoked`
- `model_unavailable`
- `rate_limited`
- `broker_unreachable`
- `service_unavailable`
- `codex_execution_failed`

Mapping rule:

- the WordPress plugin should convert these into `WP_Error` values with stable plugin-prefixed codes

## Plugin Callback Routes

The WordPress plugin should expose local browser callback routes only for connect flows.

Recommended local routes:

- `GET /wp-admin/admin.php?page=codex-broker-connect`
- `POST /wp-json/codex-provider/v1/connect/start`
- `POST /wp-json/codex-provider/v1/connect/disconnect`
- `GET /wp-json/codex-provider/v1/status`

These routes are local WordPress concerns and are not part of the hosted broker API.

Recommended WordPress behavior:

- the plugin's provider implementation reads local connection and snapshot tables
- local callback and status routes are responsible for keeping that snapshot fresh enough for deterministic support checks
- broker endpoints remain server-to-server runtime dependencies, not general browser APIs

## Security Rules

1. no Codex access token is returned to WordPress
2. all persistent broker-side sessions are keyed by opaque `connectionId`
3. all signed requests include timestamp replay protection
4. broker rejects site requests for users not linked to the connection
5. broker must rotate secrets and invalidate compromised installations

## Logging Rules

WordPress local logs may store:

- request IDs
- provider/model used
- status
- timing
- high-level error codes

WordPress local logs must not store:

- Codex bearer tokens
- raw broker secrets
- full prompt content unless the calling plugin explicitly owns and consents to logging it

## External References

- Codex app-server: <https://developers.openai.com/codex/app-server>
- Codex authentication: <https://developers.openai.com/codex/auth>
- Codex CLI reference: <https://developers.openai.com/codex/cli/reference>
