# Ring III Attestation — design

**Status:** design / future-work (not yet implemented)
**Date:** 2026-06-22
**Owner:** Henry Perkins
**Scope of v1:** the external Global Styles / Style Book apply loop only

This document specifies how Flavor Agent (FA) can genuinely satisfy **Ring III ("Attest")**
of the Expose · Govern · Attest trust model — *provenance a stranger can verify, beyond the
site* — rather than relabel the owner-side audit it already produces. It is written to double
as a roadmap / future-work artifact: §13 is the compact talk-facing framing.

---

## 1. Why this is needed

By the trust model's own definition, Ring III is not "a record exists." It is: **identify the
signer, detect alteration, verifiable by an outsider beyond the site** (the C2PA / signed-text /
EU AI Act Art. 50 family).

What FA produces today is strong **Ring II (Govern)** evidence, not Ring III:

- before/after snapshots on the activity row (`inc/Activity/Serializer.php` `before`/`after`);
- a SHA-256 **freshness** hash for staleness/drift (`inc/Support/RecommendationSignature.php`
  → `hash('sha256', …)`); this is an *integrity* hash, **not** a digital signature;
- WordPress-user attribution (`inc/Activity/Serializer.php` `userLabel`, apply `decidedBy`).

All of it is real and checkable **by the site admin**. None of it is cryptographically signed,
and none of it is verifiable **by an outsider**. A `grep` for `c2pa | openssl_sign | sodium_ |
x509` across `inc/` returns nothing. Closing this gap means crossing exactly one line:
**owner-trust → third-party verifiability.**

## 2. Approach (decided)

**Approach C — hybrid, self-signed + published.** At the approval moment, FA signs an
in-toto-style **attestation statement** that binds the governance decision to a **digest of the
artifact state it produced**, stores it in a durable companion table, and publishes the statement
and the public key at unauthenticated endpoints. A stranger verifies the signature against the
published key, then re-hashes the live entity to confirm whether the attested content has been
altered since — without trusting FA's admin UI.

Chosen over the two alternatives:

- **A (governance event only)** — same as C but without binding to the live artifact digest;
  attests accountability but not the resulting content state. C is A plus the content binding.
- **B (C2PA content credentials)** — the model's literal Ring III, but the wrong layer for FA
  (FA governs *changes*, it does not own a media pipeline) and blocked on unshipped core
  proposals (#421). Retained only as a **forward-compatible** emission target (§12).

## 3. The honesty statement (non-negotiable, ships in code docs AND on the slide)

This is **self-attestation**: tamper-evident and attributable to *this site's signing key*. The
trust root is the site itself. It is **not** third-party identity, and **not** a transparency
log. An outsider can confirm "this statement was signed by the key published at this domain and
the content has/has not changed since" — they cannot, in v1, confirm the site did not rewrite its
own history. Removing that last assumption is the transparency-log level (§12), named as the next
step, not claimed as done.

## 4. The attestation statement

An [in-toto Statement](https://in-toto.io) — chosen because it is the real provenance standard
and reads as serious to a contributor audience.

```jsonc
{
  "_type": "https://in-toto.io/Statement/v1",
  "subject": [
    {
      "name": "wp_global_styles:81",          // Style Book: "wp_global_styles:81#core/button"
      "scope": "global-styles",                 // "global-styles" | "style-book-branch"
      "digest": { "sha256": "<canonical AFTER-state digest>" }
    }
  ],
  "predicateType": "https://flavor-agent.dev/attestation/governed-change/v1",
  "predicate": {
    "attestationId": "att_…",                   // durable primary key (see §5)
    "schemaVersion": 1,
    "surface": "global-styles",                  // global-styles | style-book
    "operations": [ /* the applied, schema-bounded operations */ ],
    "before": { "sha256": "<canonical BEFORE-state digest>" },
    "after":  { "sha256": "<canonical AFTER-state digest>" },  // == subject.digest
    "freshnessSignature": "<existing recommendation sha256>",
    "actor": { "role": "administrator", "proposerVia": "mcp/flavor-agent" }, // non-PII by default
    "decision": "approve",
    "timestamps": { "requestedAt": "…", "decidedAt": "…" },
    "site": { "url": "https://example.com", "keyId": "…" },
    "revertsAttestationId":    null,             // set on an undo's attestation (§8)
    "supersedesAttestationId": null,             // set when a later change replaces an attested state
    "relatedActivityId":       "940073ab"        // OPTIONAL context only; prunable, never load-bearing
  }
}
```

### 4.1 Subject scoping (Style Book ≠ Global Styles)

Style Book after-state is recorded as a **block branch only**
(`inc/Apply/StyleApplyExecutor.php` `trim_config_to_block_branch()`), not the full entity. The
subject therefore carries its scope explicitly:

- **Global Styles** — `scope: "global-styles"`, digest over the full canonical user config.
- **Style Book** — `scope: "style-book-branch"`, `name` carries the block (`…#core/button`),
  digest over the extracted block branch.

The verifier reads `scope` from the subject and recomputes the matching digest. Branch-scoping
is chosen over a full-entity digest deliberately: it answers "was *this* change altered" without
false positives from unrelated later edits elsewhere in the entity.

### 4.2 Public-safe statement — hard contract (allowlist, not denylist)

The durable record **stores only**: canonical statement bytes, digests, signature, key ID,
surface, subject, timestamps, and the optional **non-PII actor role**. It **must not** store:

- prompt text,
- raw provider payloads,
- user display names or other PII (default to `actor.role`; full identity is opt-in),
- private style content beyond its digest,
- anything copied **only** from prunable activity JSON.

The last clause is the load-bearing one: the durable table must never become a back door that
resurrects PII or content that the 90-day activity prune is meant to remove. Enforcement is a
builder-level allowlist plus a test that asserts no field outside the allowlist is persisted
(§11).

## 5. Data model — durable companion table

A new table, lifecycle-independent of AI Activity:

```
{prefix}_flavor_agent_attestations
  attestation_id     CHAR/VARCHAR  PRIMARY KEY     -- "att_…", stable, durable
  schema_version     SMALLINT
  surface            VARCHAR
  subject_name       VARCHAR                       -- "wp_global_styles:81[#block]"
  subject_scope      VARCHAR                       -- global-styles | style-book-branch
  after_digest       CHAR(64)                      -- sha256, == subject digest
  statement_bytes    LONGBLOB/LONGTEXT             -- exact canonical bytes that were signed
  signature          VARBINARY/TEXT                -- detached Ed25519 signature
  key_id             VARCHAR
  reverts_attestation_id     VARCHAR NULL          -- forward link (§8)
  supersedes_attestation_id  VARCHAR NULL          -- forward link
  related_activity_id        VARCHAR NULL          -- optional, prunable context
  created_at         DATETIME
  INDEX (subject_name), INDEX (reverts_attestation_id), INDEX (supersedes_attestation_id)
```

Properties:

- **Retention-independent.** The activity prune cron
  (`inc/Activity/Repository.php` `PRUNE_CRON_HOOK = 'flavor_agent_prune_activity'`,
  `DEFAULT_RETENTION_DAYS = 90`) **never** touches this table. A proof with a 90-day TTL is
  "publicly inspectable activity evidence," not Ring III.
- **Self-contained.** Each row carries the full signed statement bytes; nothing it needs to be
  verified lives in `request_json`. The public route reads from this table, so
  `GET /attestations/{id}` resolves long after the activity row is gone.
- **Append-only / immutable.** No updates or deletes in normal operation — immutability *is* the
  proof. Supersession/reversion is expressed by **new** rows (§8), never by mutating prior ones.

## 6. Components

Each unit has one purpose, a documented interface, and is independently testable.

| Unit | Purpose | Reuses / depends on |
|---|---|---|
| `Attestation\Canonicalizer` | **First-class public** deterministic serialize → sha256 of an artifact state, for Global Styles (full config) and Style Book (block branch). Single source of truth for *both* the executor's drift check and external attestation, so they can never diverge. Spec-documented for third-party reproduction. | Lift the now-private helpers `comparable_config` / `comparable_config_hash` / `canonicalize_values_deep` / `canonicalize_style_value` / `sort_keys_deep` / `trim_config_to_block_branch` out of `StyleApplyExecutor`. Its JS twin `getComparableGlobalStylesConfig` (referenced in `StyleApplyExecutor`) is part of the published canonicalization spec. |
| `Attestation\StatementBuilder` | (activity row + resolved before/after) → canonical in-toto Statement **bytes**. Enforces the §4.2 public-safe allowlist. | `Canonicalizer`, `Activity\Serializer` |
| `Attestation\Signer` | (canonical bytes) → detached Ed25519 signature. No key → no attestation (never a fake one). | `sodium_crypto_sign_detached` (bundled, PHP 8.2+); key source (§7) |
| `Attestation\Repository` | Append-only persistence + forward-link writes + lookups (`by id`, `reverts={id}`, `supersedes={id}`). | the new table |
| Public REST routes | `GET /wp-json/flavor-agent/v1/attestations/{id}` (serves statement bytes + signature + keyId verbatim) and `GET /wp-json/flavor-agent/v1/attestations/keys` (JWKS). Both `permission_callback => __return_true`. | `Repository`, key store |
| Verifier (script + `wp flavor-agent attestation verify {id}`) | The proof that it is stranger-verifiable: verify signature against JWKS, re-canonicalize the live entity, compare to subject digest, resolve revert chain → emit the §9 outcome. | published endpoints only |

`.well-known/` publication is **deferred** — it needs rewrite/physical-file plumbing rather than
a namespaced REST route, and the JWKS route covers v1.

## 7. Key management (self-signed + published)

- **Algorithm:** Ed25519 (`sodium_crypto_sign_*`, bundled in PHP 8.2+; FA already requires 8.2+).
- **Private key custody:** operator-set via a `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` constant / env —
  **never auto-generated into `wp_options`** (DB-read forgery would gut the guarantee). Absence of
  a key disables attestation cleanly, the way FA's other optional backends disable.
- **Public key:** published as JWKS (`kty: OKP`, `crv: Ed25519`, RFC 8037) at the keys route, with
  a `keyId`. Rotation keeps prior public keys in the set so historical statements stay verifiable.

## 8. Lifecycle & data flow

**Apply (attest what actually happened):**

1. `request-style-apply` → pending row (unchanged).
2. Admin approves → `inc/Apply/PendingApplyDecision.php` second freshness check (`:81`,
   "Drift fails closed") → `StyleApplyExecutor` applies.
3. On success: `StatementBuilder` builds canonical bytes (the `after` digest reuses
   `StyleApplyExecutor::comparable_config_hash()` — the same function the apply path already uses
   to derive its freshness `baselineConfigHash`) → `Signer` signs → `Repository` appends the row.
4. `get-activity` / admin UI surface an "Attestation" artifact with a verify affordance.

**Undo (chained, never mutating):**

- An undo of change X produces a **new** attestation U with `revertsAttestationId = X.attestationId`.
- The prior row X is never mutated. Reverse discovery ("was X reverted?") is a **query** —
  `Repository::find_by_reverts(X)` / `GET /attestations?reverts=X` — preserving immutability.
- General supersession (a later change replacing an attested state) uses `supersedesAttestationId`
  the same way.

Chaining links **attestation IDs**, not activity IDs: activity rows prune, attestation rows do
not, so a chain through activity IDs would break. `relatedActivityId` remains optional context
only.

## 9. Verifier outcomes (explicit)

The verifier returns a **set** of these signals, not a single pass/fail — so a legitimate undo
reads as accountable, not as failure:

| Outcome | Meaning |
|---|---|
| `signature_valid` | Detached signature verifies over the served statement bytes against the published key. |
| `record_tampered` | Signature fails — the statement bytes do not match the signature. |
| `live_matches_subject` | Re-canonicalized live entity digest == subject digest. The attested change is intact on the live site. |
| `live_changed_since_attestation` | Live digest != subject digest, and no superseding/reverting attestation explains it. |
| `reverted_by_attestation` | Live digest != subject digest **and** a chained attestation (`revertsAttestationId` → this one) accounts for it. The change was legitimately undone. |

Combination example (a legitimately undone change): `signature_valid` **and**
`live_changed_since_attestation` is superseded by **`reverted_by_attestation`** once the chain is
resolved — the record is intact, the live state differs, and there is signed proof of *why*.

## 10. Error handling / fail-closed

- **No key configured** → attestation absent; row/UI shows "not attested." Never a placeholder
  signature.
- **Signing failure** → change still applied (apply is the governed action), recorded as
  applied-but-unattested, surfaced honestly.
- **Verifier drift** (`live_changed_since_attestation`) → a true verdict, not an error; that is
  the feature detecting alteration.
- **Key rotation** → old public keys retained in JWKS.

## 11. Testing strategy

- **Canonicalizer determinism** — golden-vector tests; parity with the JS twin; preset-ref
  canonicalization cases (the `var:preset|…` ↔ `var(--wp--…)` family that caused the 2026-06-21
  undo-drift bug).
- **Signature round-trip** — sign → verify; tampered bytes → `record_tampered`.
- **Verifier outcome matrix** — intact / altered / reverted / superseded; the undo case must
  resolve to `reverted_by_attestation`.
- **Public-safe contract** — a test asserting the persisted row contains *only* allowlisted
  fields (no prompt text, payloads, PII, raw content) even when handed an activity row full of
  them.
- **Retention independence** — run the activity prune; assert attestations survive and still
  verify.
- **Immutability** — no update/delete path; supersession creates new rows.

## 12. Forward-compatible extensions (the roadmap levels)

The `keyId` + statement shape make all of these purely additive:

1. **Transparency-log anchor** — publish statement hashes to a public append-only log
   (Sigstore/Rekor- or CT-style). Removes "the site could rewrite history" — the §3 trust-root
   upgrade. Maps to the slide's *durable credentials* row.
2. **C2PA emission** — when core's media path ships (#421), emit a C2PA manifest from the same
   statement for image/text outputs. This is Approach B, reached without being blocked on it.
3. **Broader surfaces** — extend beyond the style-apply loop only where a real artifact digest
   and an approval moment both exist.

## 13. Talk-facing framing (maps to slide 7)

> FA's Ring III: at approval, the governed change is **signed** and bound to a **digest of the
> state it produced**, in a **durable** record anyone can fetch and check against the live site —
> *signed post text* (the signed statement), *durable credentials* (retention-independent,
> transparency-log-ready), *C2PA detection* (the additive emission layer). Honestly: this is
> tamper-evident **site-key self-attestation**, not third-party identity — and naming that is part
> of the model, not a footnote to it.

## 14. Decisions on record

1. Genuine path designed (not a talk-only reframe). — *user*
2. Approach **C** (hybrid: sign the event, bind to artifact digest). — *user*
3. Primary trust root: **self-signed + published**; transparency-log and external CA are named
   forward levels. — *user*
4. Durable attestations **independent of AI Activity retention** (companion table). — *user*
5. Chaining via **attestation IDs** (`revertsAttestationId` / `supersedesAttestationId`);
   `relatedActivityId` optional context only. — *user*
6. **Explicit verifier outcomes** (§9), so undo is accountable, not failure. — *user*
7. **Public-safe statement** is a hard allowlist contract (§4.2). — *user*
8. Style Book subjects are **branch-scoped**; Canonicalizer is a public single-source unit. —
   *recommended, agreed*

## 15. Out of scope (v1)

Advisory/editorial surfaces with no artifact (`recommend-content`, `recommend-navigation`);
in-editor block/template applies (no admin-approval moment); `.well-known` publication;
transparency-log anchoring; C2PA emission; third-party/CA signer identity; full-identity actor
disclosure by default.
