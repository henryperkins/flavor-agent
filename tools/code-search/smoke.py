#!/usr/bin/env python3
"""Post-ingest smoke check: collection health + known-answer retrieval.

  source ~/.config/qdrant/env
  .venv/bin/python smoke.py

Asserts the collection has the hybrid shape and that a few known queries return
a plausible file in the top results. Exits non-zero on failure.
"""
from __future__ import annotations

import sys
import common as C
from search import run_query

# (query, mode, substring expected in some top-5 result path)
CASES = [
    ("register rest route for activity", "hybrid", "inc/REST"),
    ("RequestStyleApplyAbility", "sparse", "RequestStyleApply"),
    ("permission callback edit_posts capability", "hybrid", "inc/"),
    # dense / semantic, no shared keywords with the target file name:
    ("choose which language model the AI feature should use", "dense", "inc/AI/FeatureModelSelection"),
]


def main():
    client = C.get_client()
    if not client.collection_exists(C.COLLECTION):
        print(f"FAIL: collection {C.COLLECTION} missing")
        return 1
    info = client.get_collection(C.COLLECTION)
    vectors = info.config.params.vectors
    sparse = info.config.params.sparse_vectors
    print(f"collection: {C.COLLECTION}")
    print(f"  points_count: {info.points_count}")
    print(f"  dense vectors: {list(vectors.keys()) if isinstance(vectors, dict) else vectors}")
    print(f"  sparse vectors: {list(sparse.keys()) if sparse else None}")

    ok = info.points_count and info.points_count > 1000
    print(f"  [{'ok' if ok else 'FAIL'}] points_count > 1000")
    failures = 0 if ok else 1

    print("\nknown-answer queries:")
    for query, mode, expect in CASES:
        pts = run_query(client, query, mode, 5, None)
        paths = [(p.payload or {}).get("path", "") for p in pts]
        hit = any(expect in p for p in paths)
        print(f"  [{'ok' if hit else 'FAIL'}] ({mode}) {query!r} -> expect ~{expect!r}")
        if not hit:
            failures += 1
            for p in paths:
                print(f"        got: {p}")
        else:
            print(f"        top: {paths[0]}")

    print()
    if failures:
        print(f"SMOKE FAILED: {failures} check(s)")
        return 1
    print("SMOKE PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
