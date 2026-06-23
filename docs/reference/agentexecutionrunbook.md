# Flavor Agent demo — agent-side execution runbook

*How the governed-AI loop was driven end-to-end through **Claude Code over MCP** against the
live droplet `hperkins.blog`, and the environment prep that made the tools return real content.
Companion docs:*

- `docs/flavoragentportfoliopackage.md` — portfolio framing and demo narrative extracts.
- `docs/reference/ring-iii-attestation-design.md` — Ring III trust boundary, verifier outcomes, and honesty statement.
- `docs/reference/bug-undo-drift-serialization-2026-06-21.md` — the Beat-6 undo bug found + fixed during this run.

*Recorded 2026-06-22. Secrets (app passwords, API keys) are shown as placeholders — never commit the real values.*

---

## 0. Environment facts (verify these first)

- **Local `wp-cli` and the droplet are the SAME database.** `wp --path=/home/dev/hperkinsblog`
  has `DB_HOST=localhost`, `DB_NAME=hperkinsblog`, and a page created via `wp-cli` is immediately
  served by `https://hperkins.blog`. So **every `wp-cli` edit (options, users, roles, posts) takes
  effect on the live MCP surface.** Confirm with:
  ```bash
  wp --path=/home/dev/hperkinsblog config get DB_HOST          # localhost
  # create a probe page, then:
  curl -s -o /dev/null -w '%{http_code}\n' https://hperkins.blog/wp-json/wp/v2/pages/<id>   # 200 == same DB
  ```
- **Two MCP servers are live** under the `mcp` REST namespace: universal
  `…/mcp/mcp-adapter-default-server` (read-only) and dedicated `…/mcp/flavor-agent`
  (the 11 governed write tools). Keep it to exactly these two — a third server breaks Beat 2.

---

## 1. Wire Claude Code to the dedicated server as a least-privilege identity

The demo's separation-of-duties story needs the agent to **propose but not approve**, and to read
only **its own** content. That means a dedicated non-admin user, not `hperkins`.

```bash
P=/home/dev/hperkinsblog

# 1a. Custom least-privilege role: can act on styles + its OWN content, cannot approve, cannot touch others' work.
wp --path=$P role create flavor_agent_demo "Flavor Agent Demo"
wp --path=$P cap add flavor_agent_demo read edit_theme_options edit_posts edit_pages edit_published_pages
#   NOTE: deliberately NO manage_options (can't approve) and NO edit_others_* (own-work-only).

# 1b. Dedicated user with that role.
wp --path=$P user create flavor-agent-demo flavor-agent-demo@hperkins.blog --role=flavor_agent_demo --porcelain   # -> user #4

# 1c. App password for the MCP credential (shown once).
APP_PW=$(wp --path=$P user application-password create flavor-agent-demo "Claude Code MCP" --porcelain)
APP_PW=${APP_PW// /}                                   # strip display spaces -> canonical 24 chars

# 1d. Register in Claude Code — HTTP transport, LOCAL scope so the credential is never committed.
B64=$(printf '%s' "flavor-agent-demo:$APP_PW" | base64 | tr -d '\n')
claude mcp add -s local --transport http hperkins-flavor \
  https://hperkins.blog/wp-json/mcp/flavor-agent \
  --header "Authorization: Basic $B64"

# 1e. Restart Claude Code so the tools load into the session.
```

Verify (raw MCP handshake, independent of the client UI):
```bash
curl -s -D - -u "flavor-agent-demo:$APP_PW" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"probe","version":"1"}}}' \
  https://hperkins.blog/wp-json/mcp/flavor-agent          # capture the Mcp-Session-Id header,
# then POST tools/list with that header -> expect the 11 governed tools.
```

> The 2026-06-18 evidence captures were curl-based (the recipe in the evidence doc). This run added
> the actual persisted `claude mcp` registration so Claude Code drives it as a first-class client.

---

## 2. Fix the AI provider (the #1 demo-killer — was live-broken)

**Symptom:** every `recommend-*` tool returned `Provider not registered: codex`.

**Root cause:** `wpai_feature_flavor-agent_field_developer` was `{provider:"codex", model:"gpt-5.5"}`,
but `scriptorium-ai-provider-for-codex` is **inactive** (and points at a local `127.0.0.1:4317`
runtime, unavailable on the droplet). OpenAI is active with a real key.

```bash
# Inspect (don't dump all options — that leaks API keys into the terminal):
wp --path=$P option get 'wpai_feature_flavor-agent_field_developer' --format=json   # {"provider":"codex","model":"gpt-5.5"}

# Switch to the working OpenAI connector:
wp --path=$P option update 'wpai_feature_flavor-agent_field_developer' \
  '{"provider":"openai","model":"gpt-5.1"}' --format=json
```

After this, `recommend-style`/`recommend-content` resolve to `openai`/`gpt-5.1` (via the connector
fallback chain) and return real content. **If `recommend-*` ever errors again, check this option first.**

---

## 3. Create agent-owned content so page-scope reads return data

The read tools (`list-activity`/`get-activity`) enforce the scope's **contextual** capability:
a `page:N` scope is gated by `current_user_can('edit_post', N)`. The demo user can only read a page
it can edit — which (with the role above, no `edit_others_*`) means a page **it authored**.

```bash
wp --path=$P post create --post_type=page --post_status=publish --post_author=4 \
  --post_title="Flavor Agent Demo" \
  --post_content='<!-- wp:paragraph --><p>…seed copy…</p><!-- /wp:paragraph -->' --porcelain   # -> page 256

wp --path=$P eval 'echo user_can(4,"edit_post",256)?"Y":"-";'   # Y  (agent can read page:256 activity)
wp --path=$P eval 'echo user_can(4,"edit_post",79)?"Y":"-";'    # -  (someone else's page stays denied)
```

Capability cheat-sheet (why authorship alone isn't enough): `edit_post` maps to `edit_posts`
(own draft post) / `edit_published_posts` (own published post) / `edit_pages` + `edit_published_pages`
(own published **page**) / `edit_others_*` (others'). The role grants the page caps so an own
published page is readable; `global_styles:*` is gated by `edit_theme_options`.

---

## 4. Drive the governed style loop (Beats 3 → 6)

All four tool calls below run **as the agent** (`flavor-agent-demo`) via the
`mcp__hperkins-flavor__*` tools, except the approval, which is the **human admin** step.

### 4a. `recommend-style` (Beat 3) — get real AI suggestions + freshness signatures

```jsonc
// tool: flavor-agent-recommend-style
{
  "prompt": "Make exactly two paired color changes using Imladris preset tokens only: set color.background to parchment-100 and set color.text to ink-900. Do not change headings, fonts, spacing, or anything else.",
  "scope": { "surface":"global-styles", "globalStylesId":"81", "scopeKey":"global_styles:81",
             "stylesheet":"hperkins-tokens", "entityKind":"postType", "entityName":"wp_global_styles", "postType":"wp_global_styles" },
  "styleContext": { "currentConfig": {}, "mergedConfig": {} },
  "document": { "scopeKey":"global_styles:81", "postType":"wp_global_styles", "entityId":"81", "entityKind":"postType", "entityName":"wp_global_styles", "stylesheet":"hperkins-tokens" }
}
```
Returns `suggestions[].operations` plus `resolvedContextSignature` + `reviewContextSignature`.

**Two gotchas that cost several retries — script around them:**
1. **Executable operations need resolvable contrast.** With an empty `styleContext`, a *single*
   color change (e.g. text-only) gets downgraded to `tone:"advisory"` with `operations:[]`
   (`failed_contrast`, "unresolved background"). To get executable ops either (a) ask for a
   **background+text pair** so contrast is checkable within the operation set, or (b) pass a real
   `styleContext` with the current background.
2. **The freshness signature is byte-sensitive.** `recommend-style` and `request-style-apply` must
   receive **byte-identical `scope`, `styleContext`, and `prompt`**, and the `styleContext` must
   match the **live entity** state. Passing a non-empty `styleContext` that doesn't reflect the live
   post → `resolvedContextSignature` recomputes differently → `request-style-apply` fails
   *"The style recommendation context is stale."* For an empty live post, pass empty `styleContext`.
   **After a prior apply has changed the entity**, re-run `flavor-agent/get-theme-styles` and reuse
   its returned `scope` + `styleContext` as the next request base, then re-derive fresh signatures
   with `preview-recommend-style` (signature-only, free, no AI call).

### 4b. `request-style-apply` (Beat 3) — propose, don't mutate

```jsonc
// tool: flavor-agent-request-style-apply  -> returns { activityId, status:"pending", expiresAt }
{
  "prompt":  "<BYTE-IDENTICAL to 4a>",
  "scope":   { …same object as 4a… },
  "styleContext": { "currentConfig": {}, "mergedConfig": {} },
  "operations": [ … the chosen suggestion's operations … ],
  "signatures": { "resolvedContextSignature":"…", "reviewContextSignature":"…" },   // from 4a
  "suggestion": { "label":"…", "description":"…" },
  "requestReference": "undo-fix-verify-2026-06-22"
}
```
Creates a **pending** activity row. **Nothing on the site changes yet.** There is no "approve" tool —
by design.

### 4c. Approve (Beat 5) — the HUMAN admin step

Approval is an admin REST action (`manage_options`), not an agent ability. Driven server-side as admin:

```bash
ID=<activityId from 4b>
wp --path=$P eval '
  wp_set_current_user(1);                       // act as admin hperkins
  $r = new WP_REST_Request("POST", "/flavor-agent/v1/activity/'"$ID"'/decision");
  $r->set_param("decision", "approve");         // enum: approve | reject
  $resp = rest_do_request($r);
  $e = $resp->get_data()["entry"];
  echo "HTTP ".$resp->get_status()." executionResult=".$e["executionResult"]." decidedBy=".$e["apply"]["decidedBy"]."\n";
'   # -> HTTP 200 executionResult=applied decidedBy=1
```
(Equivalent in the demo: an administrator clicks approve in **Settings → AI Activity**.) The change
now lands on the live entity; the approval re-checks the freshness baseline first. With a signing key configured, approval also **signs the row's attestation** (§4e).

> Note: WordPress core normalizes the stored value on save — `var:preset|color|parchment-100`
> becomes `var(--wp--preset--color--parchment-100)` in the `wp_global_styles` post. This is expected
> and is what the Beat-6 undo fix had to account for.

### 4d. `undo-activity` (Beat 6) — drift-safe reversal

```jsonc
// tool: flavor-agent-undo-activity  -> { result:"undone", entry.undo.status:"undone", undoneAt:… }
{ "activityId": "<activityId>" }
```
Restores the recorded `before` snapshot server-side. (This is the beat the serialization bug broke;
after the fix it returns `result:"undone"` and the front end self-restores — verified live.)
When a signing key is configured, the undo also writes a **chained revert attestation** (`revertsAttestationId` → the apply's id) — see §4e.

### 4e. Attestation (Ring III) — sign at approval, verify as an outsider

Ring III is **key-gated**: with no signing key, approval and undo record *no* attestation (never a fake one), so this beat shows nothing. Set a site key once — environment prep, like §2 (secret never printed; in-place write preserves perms; health-check over real HTTPS, not wp-cli):

```bash
wp --path=$P eval '
  $sk  = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
  $def = "\ndefine( \"FLAVOR_AGENT_ATTEST_PRIVATE_KEY\", \"".base64_encode($sk)."\" );\n";
  $f   = ABSPATH."wp-config.php"; $c = file_get_contents($f);
  if ( strpos($c, "FLAVOR_AGENT_ATTEST_PRIVATE_KEY") === false )
    file_put_contents($f, str_replace("/* That\x27s all, stop editing!", $def."\n/* That\x27s all, stop editing!", $c));
  echo "inserted\n";'
curl -s -o /dev/null -w "site %{http_code} (must be 200)\n" https://hperkins.blog/
wp --path=$P eval 'echo \FlavorAgent\Attestation\KeyManager::configured() ? "key OK, jwks=".count(\FlavorAgent\Attestation\KeyManager::jwks()["keys"])."\n" : "NO KEY\n";'
```

With a key set, **4c approval signs** an append-only detached Ed25519 in-toto statement for the row; `get-activity` then carries `attestation: { id, verifyUrl }`, and **4d undo** writes the chained revert. Verify three independent ways — all public, no credentials:

```bash
ATT=<attestation.id from get-activity>

# (a) Stranger beyond the site — standalone, no WordPress, no creds:
php tools/attestation-verify.php https://hperkins.blog "$ATT"
#   -> {"attestationId":"…","outcomes":["signature_valid","live_matches_subject"]}
#      reverted row -> "reverted_by_attestation"; later edit -> "live_changed_since_attestation"

# (b) Site runtime:
wp --path=$P flavor-agent attestation verify "$ATT"          # Success: Attestation verified.

# (c) Raw public envelope a third party fetches (no auth):
curl -s https://hperkins.blog/wp-json/flavor-agent/v1/attestations/keys                  # JWKS (Ed25519 public key)
curl -s https://hperkins.blog/wp-json/flavor-agent/v1/attestations/$ATT                  # signed in-toto statement + signature
curl -s https://hperkins.blog/wp-json/flavor-agent/v1/attestations/$ATT/subject-state    # live canonical subject + digest
```

The verifier separates **signature validity** (signed by the site key, untampered) from **live-match** (the signed after-digest still equals the live entity), so an undo or any later edit reports `reverted_by_attestation` / `live_changed_since_attestation` while the signature stays valid. v1 is **site-key self-attestation** — no third-party identity or transparency log, and no prompts, payloads, or PII in the signed statement.

---

## 5. Content lane (Track B) — `recommend-content`

Editorial-only (no apply path); returns AI copy directly and logs a `review` row at the document scope.

```jsonc
// tool: flavor-agent-recommend-content
{
  "mode": "draft",                                  // draft | edit | critique
  "prompt": "Draft a short, two-paragraph opening … in Henry's voice.",
  "postContext": { "postId":256, "title":"Flavor Agent Demo", "postType":"page", "status":"publish", "content":"…", "siteTitle":"Henry Perkins" },
  "document": { "scopeKey":"page:256", "postType":"page", "entityId":"256", "entityKind":"postType", "entityName":"page" }
}
```
Then `list-activity { scopeKey:"page:256" }` returns the row (readable because the agent owns page 256).

---

## 6. Read tools — confirm content comes back

```jsonc
flavor-agent-list-activity { "scopeKey":"global_styles:81", "limit":10 }   // rows attributed to userLabel:"flavor-agent-demo"
flavor-agent-list-activity { "scopeKey":"page:256", "limit":10 }
flavor-agent-get-activity  { "activityId":"<id>" }                          // full lifecycle: pending->applied->undone
```
`list-activity` requires a `scopeKey` (e.g. `global_styles:81`, `page:256`). Reads are denied for any
scope the identity can't edit (contextual capability) — e.g. `page:79` (author 0) → `Permission denied`.

---

## 7. Consolidated gotchas (the things that bit during this run)

| Gotcha | Symptom | Fix |
|---|---|---|
| Provider misconfig | `Provider not registered: codex` on every `recommend-*` | set `wpai_feature_flavor-agent_field_developer` → `{openai, gpt-5.1}` (§2) |
| Stale freshness signature | `request-style-apply` → *"…context is stale…"* | byte-identical `scope`+`styleContext`+`prompt`; `styleContext` must match the live entity (§4a) |
| No executable operations | suggestion `tone:"advisory"`, `operations:[]` | ask for a bg+text **pair**, or pass a real `styleContext` so contrast resolves (§4a) |
| Undo false-drift | `flavor_agent_undo_drift` with no external edit | **fixed** — preset-ref canonicalization (see the bug doc) |
| Contextual read denial | `list-activity` → `Permission denied` | the identity must be able to `edit_post` the scope entity; demo agent reads own pages + global styles only |
| Secret leakage | `option list --search='*provider*'` printed the OpenAI key + Cloudflare token | read specific options only; rotate any keys that hit a shared transcript |
| Attestation absent / dormant | `get-activity` row has no `attestation`; verifier has nothing to check | key-gated — set `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` (§4e); no key → no attestation, by design |

---

## 8. Teardown / reset (after the demo)

```bash
# Revoke the MCP credential
UUID=$(wp --path=$P user application-password list flavor-agent-demo --name="Claude Code MCP" --field=uuid)
wp --path=$P user application-password delete flavor-agent-demo "$UUID"
claude mcp remove hperkins-flavor -s local

# (Optional) remove the demo identity + page, and clear test activity rows
wp --path=$P post delete 256 --force
wp --path=$P user delete 4 --reassign=1
wp --path=$P role delete flavor_agent_demo
#   delete leftover/inconsistent activity rows from wp_flavor_agent_activity as needed.

# Leave the provider on openai (it's the working config); only revert to codex if that runtime is live.

# Attestation: removing the FLAVOR_AGENT_ATTEST_PRIVATE_KEY define disables Ring III. The signed rows are
#   append-only / retention-independent — leave them as provenance, or clear the attestations table deliberately.
```

---

### Live verification status (2026-06-22)

Full loop confirmed green through the real MCP path on `hperkins.blog`: `recommend-style` →
`request-style-apply` (pending `940073ab`) → admin approve (`applied`, `decidedBy:1`) →
`undo-activity` (`undone`) → front end self-restored to theme defaults (`ink-700`, no `ink-900`).
Page-scope content lane confirmed at `page:256`. Provider serving `openai`/`gpt-5.1`. Ring III attestation independently verified once a signing key is configured — `signature_valid` + live-match via `php tools/attestation-verify.php <base> <id>` and `wp flavor-agent attestation verify <id>`, with a chained revert attestation on undo.
