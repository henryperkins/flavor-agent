#!/usr/bin/env python3
"""Hybrid (vector + lexical) code search over the release-state index.

Any agent can call this. Examples (after `source ~/.config/qdrant/env`):
  .venv/bin/python search.py "how is the activity log permission checked"
  .venv/bin/python search.py "register_rest_route" --mode sparse -k 5
  .venv/bin/python search.py "freshness signature" --lang php --json

Modes:
  hybrid (default)  dense bge-small + sparse bm25, fused with Reciprocal Rank Fusion
  dense             semantic only (good for intent / paraphrase)
  sparse            lexical bm25 only (good for exact identifiers / symbols)
"""
from __future__ import annotations

import argparse
import json
import sys

import common as C


def run_query(client, query: str, mode: str, k: int, lang: str | None):
    from qdrant_client import models

    query_filter = None
    if lang:
        query_filter = models.Filter(
            must=[models.FieldCondition(key="language", match=models.MatchValue(value=lang))]
        )

    dense, sparse = C.embed_query(query)
    sparse_vec = C.sparse_vector(sparse)

    if mode == "dense":
        res = client.query_points(
            C.COLLECTION, query=dense, using=C.DENSE_VECTOR_NAME,
            limit=k, with_payload=True, query_filter=query_filter,
        )
    elif mode == "sparse":
        res = client.query_points(
            C.COLLECTION, query=sparse_vec, using=C.SPARSE_VECTOR_NAME,
            limit=k, with_payload=True, query_filter=query_filter,
        )
    else:  # hybrid
        prefetch_n = max(20, k * 5)
        res = client.query_points(
            C.COLLECTION,
            prefetch=[
                models.Prefetch(query=dense, using=C.DENSE_VECTOR_NAME, limit=prefetch_n),
                models.Prefetch(query=sparse_vec, using=C.SPARSE_VECTOR_NAME, limit=prefetch_n),
            ],
            query=models.FusionQuery(fusion=models.Fusion.RRF),
            limit=k, with_payload=True, query_filter=query_filter,
        )
    return res.points


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("query", nargs="+", help="natural-language or code query")
    ap.add_argument("-k", "--limit", type=int, default=10, help="results to return")
    ap.add_argument("--mode", choices=["hybrid", "dense", "sparse"], default="hybrid")
    ap.add_argument("--lang", default=None,
                    help="filter: php | javascript | typescript | css | text")
    ap.add_argument("--path", default=None, help="client-side substring filter on file path")
    ap.add_argument("--json", action="store_true", help="emit JSON for programmatic use")
    ap.add_argument("--full", action="store_true", help="print full chunk code, not a snippet")
    args = ap.parse_args()

    query = " ".join(args.query)
    client = C.get_client()
    # Over-fetch when path-filtering client-side so we still fill -k.
    k = args.limit if not args.path else max(args.limit * 4, 40)
    points = run_query(client, query, args.mode, k, args.lang)

    rows = []
    for p in points:
        pl = p.payload or {}
        if args.path and args.path not in (pl.get("path") or ""):
            continue
        rows.append({
            "score": round(p.score, 4),
            "path": pl.get("path"),
            "start_line": pl.get("start_line"),
            "end_line": pl.get("end_line"),
            "symbol": pl.get("symbol"),
            "language": pl.get("language"),
            "code": pl.get("code", ""),
        })
        if len(rows) >= args.limit:
            break

    if args.json:
        print(json.dumps(rows, indent=2))
        return 0

    if not rows:
        print(f"No results for: {query!r} (mode={args.mode}"
              + (f", lang={args.lang}" if args.lang else "") + ")")
        return 0

    print(f"# {len(rows)} result(s) for {query!r}  [mode={args.mode}]\n")
    for i, r in enumerate(rows, 1):
        loc = f"{r['path']}:{r['start_line']}-{r['end_line']}"
        sym = f"  {r['symbol']}" if r["symbol"] else ""
        print(f"{i:>2}. {loc}{sym}   ({r['language']}, score={r['score']})")
        code = r["code"]
        if not args.full:
            lines = code.split("\n")
            code = "\n".join(lines[:4]) + ("\n    …" if len(lines) > 4 else "")
        print("\n".join("    " + ln for ln in code.split("\n")))
        print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
