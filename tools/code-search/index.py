#!/usr/bin/env python3
"""Build / refresh the `flavor-agent-release-code` hybrid index.

Indexes the first-party, release-state source of the plugin (see common.py
INCLUDE_GLOBS) as symbol-aware chunks with a dense (bge-small) + sparse (bm25)
vector each, so any agent can run vector, lexical, or hybrid search.

Usage (after `source ~/.config/qdrant/env`):
  .venv/bin/python index.py --wipe          # snapshot, recreate, full re-ingest
  .venv/bin/python index.py                  # full idempotent upsert
  .venv/bin/python index.py --incremental    # only files changed since last index
  .venv/bin/python index.py --since <ref>    # only files changed in <ref>..HEAD
  .venv/bin/python index.py --incremental --plan   # show what would change, no embed
  .venv/bin/python index.py --dry-run        # full-scope chunk report, no Qdrant
"""
from __future__ import annotations

import argparse
import gc
import os
import subprocess
import sys
from collections import Counter

import common as C


def ensure_collection(client, wipe: bool):
    from qdrant_client import models
    exists = client.collection_exists(C.COLLECTION)

    if exists and wipe:
        print(f"Snapshotting {C.COLLECTION} before recreate (safety per deployment policy)...")
        snap = client.create_snapshot(collection_name=C.COLLECTION, wait=True)
        print(f"  snapshot: {getattr(snap, 'name', snap)}")
        client.delete_collection(C.COLLECTION)
        exists = False

    if not exists:
        print(f"Creating collection {C.COLLECTION} (dense {C.DENSE_DIM} + sparse idf)...")
        client.create_collection(
            collection_name=C.COLLECTION,
            vectors_config={
                C.DENSE_VECTOR_NAME: models.VectorParams(
                    size=C.DENSE_DIM, distance=models.Distance.COSINE
                )
            },
            sparse_vectors_config={
                C.SPARSE_VECTOR_NAME: models.SparseVectorParams(
                    modifier=models.Modifier.IDF
                )
            },
        )
    else:
        print(f"Using existing collection {C.COLLECTION} (upsert mode).")

    index_fields = {
        "path": models.PayloadSchemaType.KEYWORD,
        "language": models.PayloadSchemaType.KEYWORD,
        "symbol": models.PayloadSchemaType.KEYWORD,
        "pathSegments": models.PayloadSchemaType.KEYWORD,
        "start_line": models.PayloadSchemaType.INTEGER,
        "end_line": models.PayloadSchemaType.INTEGER,
    }
    for field, schema in index_fields.items():
        try:
            client.create_payload_index(C.COLLECTION, field_name=field, field_schema=schema)
        except Exception:
            pass


def chunks_for_paths(root, rels):
    """(rel, Chunk) records for the given repo-relative paths that exist."""
    records = []
    lang_counter = Counter()
    for rel in rels:
        fp = root / rel
        if not fp.is_file():
            continue
        text = fp.read_text(encoding="utf-8", errors="replace")
        language = C.detect_language(rel)
        for ch in C.chunk_file(rel, text, language):
            records.append((rel, ch))
            lang_counter[language] += 1
    return records, lang_counter


def upsert_records(client, records, sha, batch):
    """Embed + upsert (rel, Chunk) records in memory-bounded batches."""
    from qdrant_client import models
    total = 0
    for start in range(0, len(records), batch):
        chunk_batch = records[start:start + batch]
        texts = [ch.code for _, ch in chunk_batch]
        dense, sparse = C.embed_documents(texts)
        points = []
        for (rel, ch), dvec, svec in zip(chunk_batch, dense, sparse):
            points.append(
                models.PointStruct(
                    id=ch.point_id(rel),
                    vector={
                        C.DENSE_VECTOR_NAME: dvec,
                        C.SPARSE_VECTOR_NAME: C.sparse_vector(svec),
                    },
                    payload=ch.to_payload(rel, sha),
                )
            )
        client.upsert(C.COLLECTION, points=points, wait=True)
        total += len(points)
        del points, dense, sparse, texts, chunk_batch
        gc.collect()
        print(f"  upserted {total}/{len(records)}", flush=True)
    return total


def delete_paths(client, paths):
    """Delete every point whose payload `path` is in `paths`."""
    from qdrant_client import models
    paths = [p for p in dict.fromkeys(paths)]
    if not paths:
        return
    flt = models.Filter(
        should=[models.FieldCondition(key="path", match=models.MatchValue(value=p))
                for p in paths]
    )
    client.delete(C.COLLECTION, points_selector=models.FilterSelector(filter=flt), wait=True)


def git_name_status(root, base, head="HEAD"):
    return subprocess.check_output(
        ["git", "-C", str(root), "diff", "--name-status", "-M", "-C", base, head],
        text=True,
    )


# --- single-instance lock (so a burst of commits doesn't pile up embedders) --

def _lock_path():
    return C.state_path().with_name(".index.lock")


def _pid_alive(pid):
    """Read-only liveness probe. Never signals: os.kill(pid, 0) on Windows
    raises WinError 87 for almost any pid (sig 0 is CTRL_C_EVENT) — or
    interrupts a live process group — so probe via a SYNCHRONIZE handle."""
    if os.name == "nt":
        import ctypes
        k32 = ctypes.windll.kernel32
        handle = k32.OpenProcess(0x00100000, False, pid)  # SYNCHRONIZE
        if not handle:
            return False                                  # gone → stale
        try:
            return k32.WaitForSingleObject(handle, 0) == 0x102  # WAIT_TIMEOUT → running
        finally:
            k32.CloseHandle(handle)
    try:
        os.kill(pid, 0)              # raises if not running
        return True
    except ProcessLookupError:
        return False
    except PermissionError:
        return True                  # exists, owned by someone else


def acquire_lock():
    lock = _lock_path()
    if lock.exists():
        try:
            pid = int(lock.read_text().strip())
        except ValueError:
            pid = 0                  # unreadable → stale
        if pid > 0 and _pid_alive(pid):
            return None              # held by a live process
        # stale lock; take it over
    lock.write_text(str(os.getpid()))
    return lock


def release_lock(lock):
    try:
        if lock and lock.exists():
            lock.unlink()
    except OSError:
        pass


# --- entry points ---------------------------------------------------------

def run_full(args, root, sha):
    files = C.discover_files(root)
    if args.limit_files:
        files = files[:args.limit_files]
    records, lang_counter = chunks_for_paths(root, files)
    print(f"in-scope files: {len(files)}   chunks: {len(records)}")
    for lang, n in sorted(lang_counter.items()):
        print(f"  {lang:<12} {n} chunks")

    if args.dry_run:
        print("\n[dry-run] sample chunks:")
        for rel, ch in records[:8]:
            print(f"  {rel}:{ch.start_line}-{ch.end_line}  symbol={ch.symbol}")
        return 0

    client = C.get_client()
    ensure_collection(client, args.wipe)
    upsert_records(client, records, sha, args.batch)
    info = client.get_collection(C.COLLECTION)
    C.write_index_state(sha, info.points_count)
    print(f"done. collection points_count={info.points_count}")
    return 0


def run_incremental(args, root, sha):
    one_shot = bool(args.since)
    base = args.since or C.read_index_state().get("head")
    if not base:
        C.write_index_state(sha)
        print(f"No baseline recorded; set current HEAD {sha[:10]} as baseline. "
              f"Run `index.py --wipe` for a full build if the index is empty.")
        return 0

    client = None
    rounds = 0
    # Drain loop: keep applying until the index reaches HEAD. We hold the lock
    # for the whole call, so commits that land mid-run (and whose own hook run
    # was lock-skipped) are still picked up here — no commit is left behind.
    while True:
        head_sha = C.repo_sha(root)               # re-read each round
        reindex, remove = C.plan_from_name_status(git_name_status(root, base, "HEAD"))
        print(f"incremental {base[:10]}..{head_sha[:10]}: "
              f"{len(reindex)} to reindex, {len(remove)} to remove")
        for p in reindex:
            print(f"  ~ {p}")
        for p in remove:
            print(f"  - {p}")

        if args.plan or args.dry_run:
            return 0

        if not reindex and not remove:
            C.write_index_state(head_sha)
            if rounds == 0:
                print("no in-scope changes; baseline advanced.")
            return 0

        if client is None:
            client = C.get_client()
            if not client.collection_exists(C.COLLECTION):
                print("collection missing; run `index.py --wipe` first.")
                return 1

        # Delete stale points for every affected path first (reindex paths get
        # re-added below with fresh chunk boundaries; removed paths just go away).
        delete_paths(client, reindex + remove)
        records, _ = chunks_for_paths(root, reindex)
        total = upsert_records(client, records, head_sha, args.batch)
        info = client.get_collection(C.COLLECTION)
        C.write_index_state(head_sha, info.points_count)
        print(f"done. {len(reindex)} file(s) reindexed ({total} chunks), "
              f"{len(remove)} removed; collection points_count={info.points_count}")
        rounds += 1

        if one_shot:
            return 0
        base = head_sha               # advance; loop re-checks for newer commits


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--wipe", action="store_true", help="snapshot + recreate before full ingest")
    ap.add_argument("--incremental", action="store_true",
                    help="only re-index files changed since the last index (state file)")
    ap.add_argument("--since", metavar="REF",
                    help="incremental: re-index files changed in REF..HEAD")
    ap.add_argument("--plan", action="store_true",
                    help="incremental: print what would change and exit (no embed)")
    ap.add_argument("--dry-run", action="store_true", help="report only, no Qdrant writes")
    ap.add_argument("--limit-files", type=int, default=None, help="debug: only first N files")
    ap.add_argument("--batch", type=int, default=128, help="upsert batch size")
    args = ap.parse_args()

    root = C.repo_root()
    sha = C.repo_sha(root)
    print(f"repo: {root}  @ {sha[:10]}")

    incremental = args.incremental or bool(args.since)
    if not incremental:
        return run_full(args, root, sha)

    lock = acquire_lock()
    if lock is None:
        print("another index run is in progress; skipping.")
        return 0
    try:
        return run_incremental(args, root, sha)
    finally:
        release_lock(lock)


if __name__ == "__main__":
    sys.exit(main())
