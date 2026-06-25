"""Shared config, file discovery, and the symbol-aware chunker for the
Flavor Agent release-state code-search index.

Embedding / Qdrant imports are intentionally lazy (inside functions) so this
module — and especially the pure `chunk_file` chunker — can be imported and
unit-tested without `fastembed` or `qdrant-client` installed.

Architecture (matches the live `flavor-agent-release-code` collection):
  dense  vector "dense"  -> BAAI/bge-small-en-v1.5 (384-dim, Cosine)
  sparse vector "sparse" -> Qdrant/bm25 (IDF modifier)  => true hybrid search
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
import uuid
from dataclasses import dataclass, asdict
from pathlib import Path

# --- Identity of the index ------------------------------------------------

COLLECTION = os.environ.get("RELEASE_CODE_COLLECTION", "flavor-agent-release-code")
DENSE_MODEL = "BAAI/bge-small-en-v1.5"   # 384-dim, matches existing collection
DENSE_DIM = 384
SPARSE_MODEL = "Qdrant/bm25"             # IDF-modifier sparse, lexical half of hybrid
DENSE_VECTOR_NAME = "dense"
SPARSE_VECTOR_NAME = "sparse"

# Deterministic namespace so re-ingests are idempotent (stable point IDs).
_ID_NS = uuid.UUID("6f9b3d2e-1c4a-4e7b-9a2d-release-code".replace("release-code", "0a1b2c3d4e5f"))

# --- Embedding runtime limits --------------------------------------------
# Keep onnxruntime memory + thread fan-out bounded so the embedder does not
# balloon (a 256-chunk batch spiked to ~4GB RSS and thrashed swap on a small
# box). Small sub-batches keep activation buffers tiny; pinning to CPU avoids
# the AzureExecutionProvider path. Override via env if running on a big box.
import os as _os  # noqa: E402
EMBED_THREADS = int(_os.environ.get("EMBED_THREADS", "2"))
EMBED_BATCH = int(_os.environ.get("EMBED_BATCH", "32"))
_PROVIDERS = ["CPUExecutionProvider"]

# --- Chunker tuning -------------------------------------------------------
# bge-small truncates at 512 tokens (~1.6k chars of code). Keep chunks under
# that so the tail of a function is not silently dropped before embedding.
MAX_CHARS = 1600
MAX_LINES = 60
OVERLAP_LINES = 10

# --- Scope: "source code that is part of the release-state plugin" --------
# First-party, authored source that ships verbatim (PHP, assets, readme) or
# compiles into the shipped bundles (src/). Tests, vendor (third-party),
# build/ (generated), docs, and tooling are excluded — all .distignore'd or
# not "the plugin".
INCLUDE_GLOBS = [
    "flavor-agent.php",
    "inc/**/*.php",
    "src/**/*.js",
    "src/**/*.jsx",
    "src/**/*.ts",
    "src/**/*.tsx",
    "src/**/*.css",
    "assets/**/*.js",
    "assets/**/*.css",
    "readme.txt",
]
EXCLUDE_SUBSTRINGS = (
    "/__tests__/",
    "/test-utils/",
    "/node_modules/",
    "/vendor/",
    "/build/",
)
EXCLUDE_SUFFIXES = (
    ".test.js", ".test.jsx", ".test.ts", ".test.tsx",
    ".stories.js", ".stories.jsx", ".stories.ts", ".stories.tsx",
)

_LANG_BY_EXT = {
    ".php": "php",
    ".js": "javascript",
    ".jsx": "javascript",
    ".ts": "typescript",
    ".tsx": "typescript",
    ".css": "css",
    ".txt": "text",
}

# --- Symbol boundary detection -------------------------------------------
# A "boundary" is a line that begins a top-level-ish symbol. We segment the
# file at boundaries: file-header (imports/namespace) up to the first symbol,
# then each symbol up to the next. This never splits the *start* of a symbol
# and needs no fragile brace matching across heredocs/regex/template strings.

_PHP_BOUNDARY = re.compile(
    r"^\s*(?:abstract\s+|final\s+)?"
    r"(?:(?:class|interface|trait|enum)\s+(?P<type>\w+)"
    r"|(?:public\s+|private\s+|protected\s+|static\s+|final\s+|abstract\s+)*"
    r"function\s+&?\s*(?P<fn>\w+)\s*\()"
)
_JS_BOUNDARY = re.compile(
    r"^\s*(?:export\s+)?(?:default\s+)?"
    r"(?:(?:abstract\s+)?class\s+(?P<cls>\w+)"
    r"|(?:async\s+)?function\s*\*?\s*(?P<fn>\w+)"
    r"|(?:const|let|var)\s+(?P<arrow>[A-Za-z_$][\w$]*)\s*=\s*"
    r"(?:async\s+)?(?:function\b|\([^)]*\)\s*(?::[^=]+)?=>|[A-Za-z_$][\w$]*\s*=>))"
)


def detect_language(rel_path: str) -> str:
    return _LANG_BY_EXT.get(Path(rel_path).suffix.lower(), "text")


def _boundary_symbol(line: str, language: str) -> str | None:
    """Return the symbol name if `line` starts a symbol, else None."""
    if language == "php":
        m = _PHP_BOUNDARY.match(line)
        if m:
            return m.group("type") or m.group("fn")
    elif language in ("javascript", "typescript"):
        m = _JS_BOUNDARY.match(line)
        if m:
            return m.group("cls") or m.group("fn") or m.group("arrow")
    return None


@dataclass
class Chunk:
    code: str
    start_line: int          # 1-based, inclusive
    end_line: int            # 1-based, inclusive
    symbol: str | None
    language: str

    def to_payload(self, rel_path: str, repo_sha: str) -> dict:
        segs = rel_path.split("/")
        return {
            "code": self.code,
            "path": rel_path,
            "language": self.language,
            "symbol": self.symbol,
            "start_line": self.start_line,
            "end_line": self.end_line,
            "repo_sha": repo_sha,
            "pathSegments": segs,
            "segmentHash": hashlib.sha256(self.code.encode("utf-8")).hexdigest(),
        }

    def content_hash(self) -> str:
        return hashlib.sha256(self.code.encode("utf-8")).hexdigest()

    def point_id(self, rel_path: str) -> str:
        # Include a content hash so distinct chunks sharing a line range
        # (e.g. an over-long single physical line) never collide, while
        # re-ingesting identical content at the same place stays idempotent.
        key = f"{rel_path}#{self.start_line}-{self.end_line}#{self.content_hash()[:16]}"
        return str(uuid.uuid5(_ID_NS, key))


def _trim_blank_edges(lines: list[str], start: int) -> tuple[list[str], int, int]:
    """Drop fully-blank leading/trailing lines; return (lines, start_line, end_line)
    with 1-based inclusive line numbers relative to the original file."""
    s, e = 0, len(lines) - 1
    while s <= e and not lines[s].strip():
        s += 1
    while e >= s and not lines[e].strip():
        e -= 1
    if s > e:
        return [], 0, 0
    return lines[s:e + 1], start + s, start + e


def _window_segment(seg_lines: list[str], seg_start: int, symbol: str | None,
                    language: str) -> list[Chunk]:
    """Split one over-long segment into overlapping line windows."""
    out: list[Chunk] = []
    i = 0
    n = len(seg_lines)
    while i < n:
        window = seg_lines[i:i + MAX_LINES]
        # Respect the char cap too: shrink the window until it fits.
        while len(window) > 1 and len("\n".join(window)) > MAX_CHARS:
            window = window[:-1]
        trimmed, s_line, e_line = _trim_blank_edges(window, seg_start + i)
        if trimmed:
            out.append(Chunk("\n".join(trimmed), s_line, e_line, symbol, language))
        # This window already reached EOF: stop, don't crawl the tail with
        # shrinking 1-line steps that produce near-duplicate chunks.
        if i + len(window) >= n:
            break
        i += max(1, len(window) - OVERLAP_LINES)
    return out


def chunk_file(rel_path: str, text: str, language: str | None = None) -> list[Chunk]:
    """Segment a file into symbol-aware chunks with accurate 1-based line numbers.

    Strategy: find symbol-start boundaries, segment the file at them, then split
    any segment that exceeds MAX_CHARS/MAX_LINES into overlapping windows.
    Code languages segment by symbol; everything else windows the whole file.
    """
    if language is None:
        language = detect_language(rel_path)
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    lines = text.split("\n")
    if not any(l.strip() for l in lines):
        return []

    symbol_aware = language in ("php", "javascript", "typescript")
    boundaries: list[int] = [0]
    symbols: dict[int, str | None] = {0: None}
    if symbol_aware:
        for idx, line in enumerate(lines):
            sym = _boundary_symbol(line, language)
            if sym is not None and idx not in symbols:
                boundaries.append(idx)
                symbols[idx] = sym
        boundaries = sorted(set(boundaries))

    chunks: list[Chunk] = []
    for b_i, start in enumerate(boundaries):
        end = boundaries[b_i + 1] if b_i + 1 < len(boundaries) else len(lines)
        seg_lines = lines[start:end]
        symbol = symbols.get(start)
        trimmed, s_line, e_line = _trim_blank_edges(seg_lines, start + 1)  # 1-based
        if not trimmed:
            continue
        seg_text = "\n".join(trimmed)
        if len(seg_text) <= MAX_CHARS and len(trimmed) <= MAX_LINES:
            chunks.append(Chunk(seg_text, s_line, e_line, symbol, language))
        else:
            chunks.extend(_window_segment(trimmed, s_line, symbol, language))
    return chunks


# --- File discovery -------------------------------------------------------

def repo_root() -> Path:
    here = Path(__file__).resolve()
    # tools/code-search/common.py -> repo root is two parents up.
    return here.parent.parent.parent


def repo_sha(root: Path | None = None) -> str:
    root = root or repo_root()
    try:
        return subprocess.check_output(
            ["git", "-C", str(root), "rev-parse", "HEAD"],
            text=True, stderr=subprocess.DEVNULL,
        ).strip()
    except Exception:
        return "unknown"


def discover_files(root: Path | None = None) -> list[str]:
    """Return repo-relative POSIX paths of in-scope release source files."""
    root = root or repo_root()
    seen: set[str] = set()
    out: list[str] = []
    for pattern in INCLUDE_GLOBS:
        for p in sorted(root.glob(pattern)):
            if not p.is_file():
                continue
            rel = p.relative_to(root).as_posix()
            if rel in seen:
                continue
            if any(s in f"/{rel}" for s in EXCLUDE_SUBSTRINGS):
                continue
            if rel.endswith(EXCLUDE_SUFFIXES):
                continue
            seen.add(rel)
            out.append(rel)
    return out


# --- Scope predicate + incremental planning + index state ----------------
# Rule form of INCLUDE_GLOBS, so we can test an arbitrary changed path for
# membership (globs can only be listed, not queried per-path with `**`).
SCOPE_FILES = {"flavor-agent.php", "readme.txt"}
SCOPE_DIRS = {
    "inc/": {".php"},
    "src/": {".js", ".jsx", ".ts", ".tsx", ".css"},
    "assets/": {".js", ".css"},
}


def _excluded(rel: str) -> bool:
    slashed = f"/{rel}"
    if any(s in slashed for s in EXCLUDE_SUBSTRINGS):
        return True
    return rel.endswith(EXCLUDE_SUFFIXES)


def in_scope(rel: str) -> bool:
    """Is this repo-relative path part of the release-state source we index?
    Equivalent to INCLUDE_GLOBS, but queryable for one arbitrary path (needed
    to classify git-diff output)."""
    rel = rel.replace("\\", "/")
    if _excluded(rel):
        return False
    if rel in SCOPE_FILES:
        return True
    suffix = Path(rel).suffix.lower()
    return any(rel.startswith(root) and suffix in exts
               for root, exts in SCOPE_DIRS.items())


def plan_from_name_status(text: str) -> tuple[list[str], list[str]]:
    """Parse `git diff --name-status -M -C` output into (reindex, remove)
    in-scope path lists. Added/Modified/Type-changed and rename/copy targets
    are reindexed; Deleted and rename sources are removed. Out-of-scope paths
    are dropped. `remove` excludes anything also being reindexed."""
    reindex: list[str] = []
    remove: list[str] = []
    for line in text.splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        code = parts[0][:1]
        if code in ("A", "M", "T") and len(parts) >= 2:
            if in_scope(parts[1]):
                reindex.append(parts[1])
        elif code == "D" and len(parts) >= 2:
            if in_scope(parts[1]):
                remove.append(parts[1])
        elif code in ("R", "C") and len(parts) >= 3:
            old, new = parts[1], parts[2]
            if code == "R" and in_scope(old):
                remove.append(old)          # the old name no longer exists
            if in_scope(new):
                reindex.append(new)         # fresh content at the new name
    reindex = list(dict.fromkeys(reindex))
    rset = set(reindex)
    remove = [p for p in dict.fromkeys(remove) if p not in rset]
    return reindex, remove


def state_path() -> Path:
    return Path(__file__).resolve().parent / ".index-state.json"


def read_index_state() -> dict:
    p = state_path()
    if p.exists():
        try:
            return json.loads(p.read_text())
        except Exception:
            return {}
    return {}


def write_index_state(head: str, points: int | None = None) -> None:
    data: dict = {"head": head}
    if points is not None:
        data["points"] = points
    state_path().write_text(json.dumps(data, indent=2) + "\n")


# --- Lazy embedding / client helpers (require deps installed) -------------

_dense_model = None
_sparse_model = None


def get_models():
    global _dense_model, _sparse_model
    if _dense_model is None:
        from fastembed import TextEmbedding, SparseTextEmbedding
        _dense_model = TextEmbedding(
            DENSE_MODEL, threads=EMBED_THREADS, providers=_PROVIDERS
        )
        _sparse_model = SparseTextEmbedding(
            SPARSE_MODEL, threads=EMBED_THREADS, providers=_PROVIDERS
        )
    return _dense_model, _sparse_model


def embed_documents(texts: list[str]):
    dense_m, sparse_m = get_models()
    # Small batch_size bounds onnxruntime peak memory on constrained boxes.
    dense = [v.tolist() for v in dense_m.embed(texts, batch_size=EMBED_BATCH)]
    sparse = list(sparse_m.embed(texts, batch_size=EMBED_BATCH))
    return dense, sparse


def embed_query(text: str):
    dense_m, sparse_m = get_models()
    dense = next(iter(dense_m.query_embed(text))).tolist()
    sparse = next(iter(sparse_m.query_embed(text)))
    return dense, sparse


def get_client():
    from qdrant_client import QdrantClient
    url = os.environ.get("QDRANT_URL")
    api_key = os.environ.get("QDRANT_API_KEY")
    if not url or not api_key:
        raise SystemExit(
            "Set QDRANT_URL and QDRANT_API_KEY first: source ~/.config/qdrant/env"
        )
    return QdrantClient(url=url, api_key=api_key, timeout=60)


def sparse_vector(embedding):
    """fastembed SparseEmbedding -> qdrant SparseVector."""
    from qdrant_client import models
    return models.SparseVector(
        indices=embedding.indices.tolist(),
        values=embedding.values.tolist(),
    )
