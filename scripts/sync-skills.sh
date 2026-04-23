#!/usr/bin/env bash

# Syncs skill packs from the canonical .claude/skills/ tree into the
# .cursor/, .codex/, and .github/ replicas so all four tool-specific
# dirs stay byte-identical. Edit skills in .claude/skills/ only; this
# script (or check-doc-freshness.sh) will flag drift.

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
canonical="${repo_root}/.claude/skills"

if [[ ! -d "${canonical}" ]]; then
	echo "Canonical skills dir missing: ${canonical}" >&2
	exit 1
fi

for replica in "${repo_root}/.cursor/skills" "${repo_root}/.codex/skills" "${repo_root}/.github/skills"; do
	mkdir -p "${replica}"
	rsync -a --delete "${canonical}/" "${replica}/"
	echo "Synced ${canonical} -> ${replica}"
done
