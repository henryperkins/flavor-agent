# Release-state code search (Qdrant hybrid)

Vector + hybrid (semantic **and** lexical) search over the **release-state
source** of the Flavor Agent plugin — the first-party code that ships verbatim
(`flavor-agent.php`, `inc/**`, `assets/**`, `readme.txt`) or compiles into the
shipped bundles (`src/**`). Tests, `vendor/`, `build/`, docs, and tooling are
excluded (they are `.distignore`'d — not "the plugin").

This whole directory is dev-only and is ignored by `.distignore`, so it never
goes into the plugin ZIP.

## Index design

| Piece | Value |
|-------|-------|
| Qdrant collection | `flavor-agent-release-code` |
| Dense vector `dense` | `BAAI/bge-small-en-v1.5` (384-dim, Cosine) — semantic |
| Sparse vector `sparse` | `Qdrant/bm25` (IDF modifier) — lexical / exact identifiers |
| Hybrid fusion | Reciprocal Rank Fusion (RRF) over both prefetches |
| Chunking | symbol-aware (PHP/JS/TS classes, functions, methods, arrow components); over-long symbols windowed to fit the 384-model's 512-token limit |
| Payload | `code, path, language, symbol, start_line, end_line, repo_sha, pathSegments, segmentHash` |

Embeddings run locally via `fastembed` (ONNX, CPU) — **no API key** for the
embedder. Only `QDRANT_URL` / `QDRANT_API_KEY` are needed (from
`~/.config/qdrant/env`).

## Setup (once)

```bash
cd tools/code-search
uv venv --python 3.11 .venv
uv pip install --python .venv/bin/python -r requirements.txt
```

## Build / refresh the index

```bash
source ~/.config/qdrant/env
.venv/bin/python index.py --wipe         # snapshot + recreate + full re-ingest
.venv/bin/python index.py                # full idempotent upsert (all files)
.venv/bin/python index.py --incremental  # only files changed since last index
.venv/bin/python index.py --since HEAD~5 # only files changed in HEAD~5..HEAD
.venv/bin/python index.py --incremental --plan   # show the change-set, no embed
.venv/bin/python index.py --dry-run      # full-scope chunk report, no Qdrant calls
```

`--wipe` snapshots the existing collection server-side before recreating it
(per this deployment's "snapshot before destructive ops" policy).

### Staying fresh (incremental + git hook)

A code-search index is only useful if it tracks the code. `--incremental`
re-indexes **only** the files changed since the last run: it reads the baseline
sha from `.index-state.json`, runs `git diff` against `HEAD`, deletes points for
changed/removed paths, re-embeds the changed files, and advances the baseline —
typically a few seconds. The baseline only advances on success, so a skipped or
failed run is retried on the next commit (self-healing). A single-instance lock
(`.index.lock`) stops a burst of commits from stacking embedders.

Install the post-commit hook to keep it automatic:

```bash
tools/code-search/install-hook.sh             # install (backs up any existing hook)
tools/code-search/install-hook.sh --uninstall # remove
```

The hook runs `index.py --incremental` **detached**, so it never blocks or fails
a commit, and silently no-ops if the venv or `~/.config/qdrant/env` is absent.
Output goes to `tools/code-search/incremental.log`. (The hook lives in
`.git/hooks/` and is per-clone — re-run the installer after a fresh clone.)

## Search — the interface any agent uses

```bash
source ~/.config/qdrant/env
.venv/bin/python search.py "how is activity-log access permission decided"
.venv/bin/python search.py "register_rest_route" --mode sparse -k 5
.venv/bin/python search.py "freshness signature drift check" --lang php
.venv/bin/python search.py "RecommendBlockAbility capability" --json   # machine-readable
```

Flags: `-k/--limit` (default 10) · `--mode hybrid|dense|sparse` (default
`hybrid`) · `--lang php|javascript|typescript|css|text` · `--path SUBSTR` ·
`--full` (full chunk, not a 4-line snippet) · `--json`.

### Pointing a different agent at it

It is a plain CLI, so any agent that can run a shell command can use it:

- **Claude Code / Codex / Cursor / shell:** run the `search.py` line above. Use
  `--json` to parse results (`[{score, path, start_line, end_line, symbol,
  language, code}]`).
- **As a wrapper:** `import common; common.embed_query(...)` +
  `common.get_client().query_points(...)` to embed it in another tool or an MCP
  server.

When to pick a mode: `hybrid` for general "where/how is X" questions; `sparse`
when you know the exact symbol/function name; `dense` for paraphrased intent
with no shared keywords.
