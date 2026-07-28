# Governed style-apply lane, exercised live — 2026-07-28

The full external-apply lane was driven end to end against a real site:
`get-theme-styles` → `recommend-style` → `request-style-apply` → approve →
undo. Most of it works as documented. Two things do not.

| Finding | Severity |
| --- | --- |
| Approved apply emitted **no attestation** (`attestationStatus: "not_configured"`) while the site publishes an active JWKS key | **P0** |
| **Undo reported success but did not revert the change** | **P0** |

## Environment

`https://hperkins.blog` — WordPress.com Atomic, Flavor Agent `0.1.0`, AI plugin
`1.2.0`, Anthropic connector on `claude-opus-5`, an administrator account via a
since-revoked application password. Activity row `825f94bf-644c-4ef2-8885-19fdae3ee516`.

## What worked

**Recommendation.** `recommend-style` returned three suggestions with bounded
operations. Governance signal was visibly working: one suggestion carried
`validationReasons: [{code: "raw_value_when_preset_available", severity:
"downgraded"}]` for using a literal `1.65` line-height where a preset existed,
and a third was correctly typed `tone: "advisory"` with no operations.

**Review gate.** `request-style-apply` returned `status: "pending"` with a 24h
`expiresAt` and mutated nothing.

**Approval and execution.** The decision route executed server-side and recorded
`executionResult: "applied"`, `persistence: {status: "server"}`. State moved from
`before.userConfig.styles.typography: null` to
`after…: {"fontSize": "var:preset|font-size|md"}`.

**Provenance.** The row captured `requestedBy`/`requestedAt`, `decidedBy` 252106238,
`decidedByName: "Henry"`, `decidedAt`, the decision note, and three signatures —
`resolvedContextSignature`, `reviewContextSignature`, `baselineConfigHash`.

That is the governance contract behaving correctly: schema-bounded operation,
held for a human, executed server-side, fully attributed.

## Finding 1 — approved apply was not attested

The row records:

```json
"attestationStatus": "not_configured",
"attestationErrorCode": null
```

`global-styles` is an eligible surface (`AttestationService::ELIGIBLE_SURFACES`),
so `record_apply()` ran and returned early at
`AttestationService.php:28` because `KeyManager::configured()` is false —
that method requires a **private** key (`KeyManager.php:28-30`).

The problem is what an outside observer sees. The site's public JWKS is live and
advertises an **active** key:

```text
GET /wp-json/flavor-agent/v1/attestations/keys
{"keys":[{"kty":"OKP","crv":"Ed25519","kid":"212313e9433250dc782136e7d315c83f",
          "use":"sig","alg":"EdDSA","status":"active",
          "createdAt":"2026-06-22T12:13:47+00:00"}]}
```

So the public key is registered and published while signing is unavailable. A
verifier fetching that JWKS would reasonably conclude the lane is attested. It is
not: approved applies are silently recorded as unattested, and the release notes
claim "Ring III attestation on three of the four lanes."

Either the private key must be present wherever the public key is advertised, or
a key without a usable private half must not be published as `status: "active"`.
`attestationStatus: "not_configured"` should also surface in the admin approval
UI rather than only in the raw row.

## Finding 2 — undo reported success but did not revert

`POST /flavor-agent/v1/activity/{id}/undo` returned **HTTP 200** and recorded:

```json
"undo": {"canUndo": false, "status": "undone", "error": null,
         "undoneAt": "2026-07-28T04:29:28+00:00"}
```

The change was still live. Confirmed two independent ways:

| Source | `styles.typography` before apply | after "successful" undo |
| --- | --- | --- |
| `get-theme-styles` → `styleContext.currentConfig` | `null` | `{"fontSize":"var:preset|font-size|md"}` |
| `wp/v2/global-styles/81` (authoritative entity) | *(absent)* | `{"fontSize":"var(--wp--preset--font-size--md)"}` |

The second read bypasses Flavor Agent entirely, so this is not a stale-cache
artifact of the plugin's own reader.

This is the most damaging failure mode available to this product: the row now
asserts `status: "undone"` with a timestamp, so the audit trail states the change
was reversed when it was not. Anyone auditing after the fact is misled, and the
operator has no signal that intervention is needed.

**Mitigating detail, and why it is only mitigating:** the applied value happened
to equal what the theme already resolved for body `fontSize`, so `mergedConfig`
was byte-identical before and after and the site never looked different. The
defect is in the governance record, not in rendered output — but nothing about
the apply guaranteed that coincidence, and a value that *did* differ would have
been left in place just the same.

The site was restored manually by removing the `typography` key from
`wp/v2/global-styles/81` (the entity held only `css` and `typography`; the
original held only `css`). Verified back to `null` from both readers.

## Required before release

- [ ] Do not publish a JWKS key as `status: "active"` when no private key is
      available to sign with, or provision the private key on any site that
      advertises one.
- [ ] Surface `attestationStatus` in `Settings > AI Activity` so an unattested
      apply on an attested lane is visible to the approver.
- [ ] Fix undo so it either reverts or reports failure. A `status: "undone"`
      transition must be written **after** a verified revert, never before.
- [ ] Add a regression test that asserts the *live subject state* after undo,
      not just the recorded undo status. The current suite asserts the row
      transition, which is exactly what passed here while the site stayed changed.
- [ ] Re-run this lane end to end after the fixes.

## Reproduction

`get-theme-styles` returns `scope` and `styleContext` in the shape
`recommend-style` wants, so the whole lane chains without hand-built context:

```text
GET  /wp-json/wp-abilities/v1/abilities/flavor-agent/get-theme-styles/run
POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-style/run
       {"input":{"scope":…,"styleContext":…,"prompt":…}}
POST /wp-json/wp-abilities/v1/abilities/flavor-agent/request-style-apply/run
       {"input":{"scope":…,"styleContext":…,"prompt":…,"operations":…,
                 "signatures":{"resolvedContextSignature":…,"reviewContextSignature":…}}}
POST /wp-json/flavor-agent/v1/activity/{id}/decision  {"decision":"approve"}
POST /wp-json/flavor-agent/v1/activity/{id}/undo      {}
GET  /wp-json/wp/v2/global-styles/{id}?context=edit    <-- check the real state
```

Read-only abilities require **GET**; ability payloads are wrapped as
`{"input": {...}}`.
