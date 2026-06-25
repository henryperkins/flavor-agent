#!/usr/bin/env bash
# Install (or remove) the post-commit hook that keeps the release-state
# code-search index fresh. Idempotent; backs up any pre-existing hook.
#
#   tools/code-search/install-hook.sh            # install
#   tools/code-search/install-hook.sh --uninstall
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(git -C "$here" rev-parse --show-toplevel)"
hooks_dir="$(git -C "$repo_root" rev-parse --git-path hooks)"
src="$here/hooks/post-commit"
dest="$hooks_dir/post-commit"
marker="release-state code-search index"

if [[ "${1:-}" == "--uninstall" ]]; then
  if [[ -f "$dest" ]] && grep -q "$marker" "$dest"; then
    rm -f "$dest"
    echo "Removed $dest"
  else
    echo "No Flavor Agent post-commit hook installed; nothing to do."
  fi
  exit 0
fi

mkdir -p "$hooks_dir"
if [[ -e "$dest" ]] && ! grep -q "$marker" "$dest" 2>/dev/null; then
  backup="$dest.bak.$(date +%s 2>/dev/null || echo prev)"
  cp "$dest" "$backup"
  echo "WARNING: an existing post-commit hook was found and backed up to:"
  echo "  $backup"
  echo "Re-add its commands to the new hook if you still need them."
fi

cp "$src" "$dest"
chmod +x "$dest"
echo "Installed post-commit hook -> $dest"
echo "It will run 'index.py --incremental' detached after each commit."
echo "(Needs tools/code-search/.venv and ~/.config/qdrant/env; otherwise it no-ops.)"
