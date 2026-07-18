# Ring III Attestation — design

**Status:** v1 implemented (external Global Styles / Style Book apply loop)
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
published key (fully independent), then re-hashes the live subject — exposed via a public route,
because core's relevant config and content reads are capability-gated (§6.2) — to confirm whether the attested content
has been altered since.

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
own history. Live-content checks additionally read the present state through FA's own public route
(§6.2), a present-state trust beyond the signing root. Removing the history-rewrite assumption is
the transparency-log level (§12); the present state can be cross-checked against the site's public
rendered CSS. Both are named next steps, not claimed as done.

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
    "governance": {
      "claim": "governed-change",
      "lane": "external-style-apply-v1",         // FA's owned attestation boundary
      "approvalSurface": "settings-ai-activity",
      "executor": "bounded-server-style-apply"
    },
    "operations": [ /* the applied, schema-bounded operations — public-safe, §4.3 */ ],
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

The example above shows the style lane. Flavor Agent owns exactly these Ring III lanes:

| Lane id | Surface value | Subject name | Subject scope | Canonical bytes | Executor id |
|---|---|---|---|---|---|
| `external-style-apply-v1` | `global-styles` / `style-book` | `wp_global_styles:{id}[#block]` | `global-styles` / `style-book-branch` | `Attestation\Canonicalizer` output for the full user config or selected block branch | `bounded-server-style-apply` |
| `external-template-apply-v1` | `template` | `wp_template:{theme}//{slug}` | `template` | `serialize_blocks( parse_blocks( content ) )` | `bounded-server-template-apply` |
| `external-template-part-apply-v1` | `template-part` | `wp_template_part:{theme}//{slug}` | `template-part` | `serialize_blocks( parse_blocks( content ) )` | `bounded-server-template-part-apply` |

The `predicate.governance` block remains intentionally narrow for every lane: WordPress approved
this external apply in `Settings > AI Activity`; Flavor Agent executed the named bounded
server-side apply; and the resulting subject hashed to the signed digest. It does not attest to
the site, model, or all AI-assisted work.

### 4.1 Subject scoping and canonical bytes

Style Book after-state is recorded as a **block branch only**
(`inc/Apply/StyleApplyExecutor.php` `trim_config_to_block_branch()`), not the full entity. The
subject therefore carries its scope explicitly. Template subjects use canonical block
serialization so their executor drift checks and attestation digests share the same bytes:

- **Global Styles** — `scope: "global-styles"`, digest over the full canonical user config.
- **Style Book** — `scope: "style-book-branch"`, `name` carries the block (`…#core/button`),
  digest over the extracted block branch.
- **Template** — `scope: "template"`, `name` is `wp_template:{theme}//{slug}`, digest over
  `serialize_blocks( parse_blocks( content ) )`.
- **Template part** — `scope: "template-part"`, `name` is
  `wp_template_part:{theme}//{slug}`, digest over the same canonical block serialization.

The verifier reads `scope` from the subject and recomputes the matching digest. Style Book
branch-scoping answers "was *this* change altered" without false positives from unrelated later
edits elsewhere in the entity. Template subjects embed the theme in the subject name, so
supersede chains intentionally do not cross theme switches.

### 4.2 Public-safe statement — hard contract (allowlist, not denylist)

The durable record **stores only**: canonical statement bytes, digests, signature, key ID,
surface, subject, timestamps, the optional **non-PII actor role**, and the **bounded operation
set** (§4.3). It **must not** store:

- prompt text,
- raw provider payloads,
- user display names or other PII (default to `actor.role`; full identity is opt-in),
- raw or full config blobs / private content beyond the bounded operations and their digests,
- anything copied **only** from prunable activity JSON.

The last clause is the load-bearing one: the durable table must never become a back door that
resurrects PII or content that the 90-day activity prune is meant to remove. Enforcement is a
builder-level allowlist plus a test that asserts no field outside the allowlist is persisted
(§11).

### 4.3 Operations are public-safe (decided)

The signed statement includes the applied **operations**, not only a digest. Style operations are
schema-bounded to validated `theme.json` paths and preset/validated values (the `recommend-style`
contract + the WCAG AA contrast gate, `inc/LLM/StyleContrastValidator.php`). Template and
template-part operations are bounded structural operations against one named theme entity. These
operations are the *substance* of the provenance claim ("what was changed") and are explicitly
allowlisted as public. The §4.2 prohibition targets raw config/content snapshots, prompts,
provider payloads, and PII — never the bounded operation set.

## 5. Data model — durable companion table

A new table, lifecycle-independent of AI Activity:

```
{prefix}_flavor_agent_attestations
  attestation_id     CHAR/VARCHAR  PRIMARY KEY     -- "att_…", stable, durable
  schema_version     SMALLINT
  surface            VARCHAR
  subject_name       VARCHAR                       -- lane-specific canonical subject name (§4)
  subject_scope      VARCHAR                       -- global-styles | style-book-branch | template | template-part
  after_digest       CHAR(64)                      -- sha256, == subject digest
  statement_bytes    LONGBLOB/LONGTEXT             -- exact canonical bytes that were signed
  signature          VARBINARY/TEXT                -- detached Ed25519 signature
  key_id             VARCHAR                       -- resolves into the key registry (§7), not this table
  reverts_attestation_id     VARCHAR NULL          -- back-reference to PRIOR attestation reverted (§8)
  supersedes_attestation_id  VARCHAR NULL          -- back-reference to PRIOR attestation superseded
  related_activity_id        VARCHAR NULL          -- optional, prunable context
  created_at         DATETIME
  INDEX (subject_name), INDEX (reverts_attestation_id), INDEX (supersedes_attestation_id)
```

Properties:

- **Retention-independent.** The activity prune cron
  (`inc/Activity/Repository.php` `PRUNE_CRON_HOOK = 'flavor_agent_prune_activity'`,
  `DEFAULT_RETENTION_DAYS = 90`) **never** touches this table. A proof with a 90-day TTL is
  "publicly inspectable activity evidence," not Ring III. (Retention-independence is about the
  *prune cron*, not uninstall: a deliberate uninstall removes this table and its options like any
  plugin data — see plan Task 5 — so it stays Plugin-Check clean.)
- **Self-contained.** Each row carries the full signed statement bytes; nothing it needs to be
  verified lives in `request_json`. The public route reads from this table, so
  `GET /attestations/{id}` resolves long after the activity row is gone.
- **Append-only / immutable.** No updates or deletes in normal operation — immutability *is* the
  proof. Supersession/reversion is expressed by **new** rows (§8), never by mutating prior ones.
- **Keys live elsewhere.** Public keys are kept in a separate durable registry (§7); `key_id`
  resolves into it, so historical rows stay verifiable across key rotation.

## 6. Components

Each unit has one purpose, a documented interface, and is independently testable.

| Unit | Purpose | Reuses / depends on |
|---|---|---|
| `Attestation\Canonicalizer` | **First-class public** deterministic serialize → sha256 of an artifact state, for Global Styles (full config) and Style Book (block branch). Single source of truth for *both* the executor's drift check and external attestation, so they can never diverge. Spec-documented for third-party reproduction. | Lift the now-private helpers `comparable_config` / `comparable_config_hash` / `canonicalize_values_deep` / `canonicalize_style_value` / `sort_keys_deep` / `trim_config_to_block_branch` out of `StyleApplyExecutor`. Its JS twin `getComparableGlobalStylesConfig` (referenced in `StyleApplyExecutor`) is part of the published canonicalization spec. |
| `Attestation\BlockContentCanonicalizer` | Deterministic `serialize_blocks( parse_blocks( content ) )` bytes and sha256 digest for template and template-part subjects. Single source of truth for executor drift checks and external attestation. | WordPress block parser and serializer |
| `Attestation\StatementBuilder` | (activity row + resolved before/after) → canonical in-toto Statement **bytes**. Enforces the §4.2 public-safe allowlist. | `Canonicalizer`, `Activity\Serializer` |
| `Attestation\Signer` | (canonical bytes) → detached Ed25519 signature. No key → no attestation (never a fake one). | `sodium_crypto_sign_detached` (bundled, PHP 8.2+); key source (§7) |
| `Attestation\Repository` | Append-only persistence + back-reference writes + lookups (`by id`, `reverts={id}`, `supersedes={id}`). | the new table |
| Public REST routes | (a) `GET /wp-json/flavor-agent/v1/attestations/{id}` — the signed envelope (§6.1); (b) `GET …/attestations/keys` — JWKS from the key registry (§7); (c) `GET …/attestations/{id}/subject-state` — the **current** canonical subject artifact computed live by the surface's canonicalizer, so a credential-less stranger can recompute the live digest without core's gated read (§6.2). All `permission_callback => __return_true`. | `Repository`, key registry, canonicalizers, apply executors |
| Verifier (script + `wp flavor-agent attestation verify {id}`) | The proof that it is stranger-verifiable: verify signature against JWKS, re-canonicalize the live subject, compare to subject digest, resolve revert chain → emit the §9 outcome. | published endpoints only |

`.well-known/` publication is **deferred** — it needs rewrite/physical-file plumbing rather than
a namespaced REST route, and the JWKS route covers v1.

### 6.1 Wire envelope (verification is byte-exact)

REST JSON reserializes nested objects (key order, whitespace, unicode escaping), so the signed
statement is **never** transported as a nested JSON object. The `{id}` route returns:

```jsonc
{
  "statement_b64": "<base64url of the EXACT canonical statement bytes that were signed>",
  "signature_b64": "<base64url of the detached Ed25519 signature>",
  "key_id": "…",
  "statement_json": { /* decoded convenience view only — NOT the verification input */ }
}
```

Verification is **always** performed over `base64url_decode(statement_b64)`; the decoded
`statement_json` is non-authoritative. The `subject-state` route uses the same discipline
(returns `subject_canonical_b64` + a convenience `subject_digest`), and the verifier hashes the
decoded bytes itself rather than trusting any digest a route reports.

### 6.2 Why a public subject-state route (and its honesty boundary)

Core's Global Styles config and editable template entity content are **not** anonymously readable.
A credential-less stranger therefore cannot fetch the live subject from core to recompute the
digest, so FA exposes the minimal canonical slice itself via `subject-state`. Disclosure is
bounded to eligible theme-territory subjects: canonical style config is semantically equivalent
to rendered CSS, while canonical template/template-part block serialization describes the
publicly rendered site structure. The route never returns prompts, provider payloads, activity
metadata, or PII.

Post-blocks is explicitly frozen out of Ring III. A public `subject-state` response for that lane
would disclose non-public `post_content`, while a title-bearing `subject_name` could leak content
metadata. That lane needs a separate conditional-subject-state and ID-only subject design before
it can become eligible.

Honesty boundary (code docs + slide): the **signature + provenance** half — who attested what,
when, and that the record is unaltered — is fully independent (published bytes + JWKS, no FA
trust). The **live-match** half reads the present state through FA's own route, so it carries a
present-state server-trust component (FA cannot forge the signed past, only potentially misreport
the live present); the site's public rendered CSS is a non-authoritative independent cross-check,
and the transparency-log level (§12) addresses history-rewrite over time.

## 7. Key management (self-signed + published)

- **Algorithm:** Ed25519 (`sodium_crypto_sign_*`, bundled in PHP 8.2+; FA already requires 8.2+).
- **Private key custody:** operator-set via a `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` constant / env —
  **never auto-generated into `wp_options`** (DB-read forgery would gut the guarantee). Absence of
  a key disables attestation cleanly, the way FA's other optional backends disable.
- **Public key:** derived from the configured private key and recorded in a **durable public-key
  registry** (`keyId → public JWK + status active|retired + createdAt`), separate from the single
  active private key in env/constant. The keys route serves the whole registry as JWKS
  (`kty: OKP`, `crv: Ed25519`, RFC 8037). On rotation the new key is registered `active` and the
  prior marked `retired` but **kept**, so historical attestations stay verifiable; each
  attestation row's `key_id` (and the statement's `site.keyId`) resolves into this registry.

## 8. Lifecycle & data flow

**Apply (attest what actually happened):**

1. `request-style-apply`, `request-template-apply`, or `request-template-part-apply` → pending row
   (unchanged).
2. Admin approves → `inc/Apply/PendingApplyDecision.php` second freshness check (`:81`,
   "Drift fails closed") → the lane's bounded server executor applies.
3. On success: `StatementBuilder` builds the canonical statement bytes; the `after` digest is
   computed from the **post-apply** state by the lane's canonicalizer (the same canonicalization
   used by that executor's pre-apply drift check, but a different input and moment) → `Signer`
   signs → `Repository` appends the row.
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
| `signature_valid` | Detached signature verifies over the decoded `statement_b64` bytes against the published key. |
| `record_tampered` | Signature fails — the statement bytes do not match the signature. |
| `live_matches_subject` | Re-canonicalized live subject digest == subject digest. The attested change is intact on the live site. |
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
- **Key rotation** → old public keys retained in the registry / JWKS.

## 11. Testing strategy

- **Canonicalizer determinism** — style golden-vector tests, parity with the JS twin, and
  preset-ref canonicalization cases (the `var:preset|…` ↔ `var(--wp--…)` family that caused the
  2026-06-21 undo-drift bug); block-content fixtures prove digest parity with the executor drift
  expression and reserialization idempotence.
- **Signature round-trip** — sign → verify; tampered bytes → `record_tampered`.
- **Wire-envelope determinism** — the signature still verifies over the served `statement_b64`
  *after* a full REST round-trip (guards against JSON reserialization).
- **Subject-state route** — its returned canonical bytes hash to the same digest a freshly-applied
  attestation signed.
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
3. **Broader surfaces** — extend beyond the three registered lanes only where a real public-safe
   artifact digest and an approval moment both exist. Post-blocks requires the separate privacy
   design described in §6.2 and §15.
4. **Public rendered-CSS digest** — add a deterministic public CSS projection as a
   non-authoritative cross-check, then optionally sign both the config and CSS digests so
   verifiers can choose their trust level. Removes the present-state server-trust of §6.2 once the
   projection is stable.

## 13. Talk-facing framing (maps to slide 7)

> FA's Ring III: at approval, the governed change is **signed** and bound to a **digest of the
> state it produced**, in a **durable** record anyone can fetch and check against the live site —
> *signed post text* (the signed statement), *durable credentials* (retention-independent,
> transparency-log-ready), *C2PA detection* (the additive emission layer). The signed past
> verifies independently; the live-present check is site-served and cross-checkable against
> rendered CSS. Honestly: this is tamper-evident **site-key self-attestation**, not third-party
> identity — and naming that is part of the model, not a footnote to it.

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
9. Operations are **public-safe** and signed in full (not digest-only); §4.2 prohibits raw
   blobs/PII/prompts/payloads, not the bounded operation set. — *review*
10. A public **`subject-state`** route exposes the live canonical subject (core's config read is
    capability-gated); live-match therefore carries a present-state server-trust component
    (§6.2). — *review*
11. **Wire envelope** is base64url statement + signature; verification is byte-exact over the
    decoded statement (§6.1). — *review*
12. Public keys live in a **durable registry** (§7), not just the env key; retired keys are
    kept. — *review*
13. Present-state verification: **subject-state route for v1**; public rendered-CSS digest and
    dual-signing are §12 forward levels (CSS canonicalization is too fragile to be
    foundational). — *user*
14. Ring III includes three explicit lanes: style, template, and template-part external applies;
    each has its own lane id, executor id, subject naming, scope, and canonicalizer. — *user*
15. Template subject names include the theme, so supersede chains intentionally do not cross
    theme switches. Post-blocks remains frozen pending a conditional public-subject design. —
    *user*

## 15. Out of scope (v1)

Advisory/editorial surfaces with no artifact (`recommend-content`, `recommend-navigation`);
in-editor block/template/template-part/style applies (no `Settings > AI Activity`
admin-approval moment). External template and template-part applies are in scope because they do
pass through that decision gate and the bounded server executors. External post-blocks apply is
frozen: its `post_content` is not necessarily public, the current public `subject-state` contract
would leak it, and a title-bearing subject name could leak metadata. Also out: `.well-known`
publication; transparency-log anchoring; C2PA emission; third-party/CA signer identity;
full-identity actor disclosure by default.
