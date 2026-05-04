#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

printf 'Checking markdown drift markers for duplicate canonical blocks...\n'

patterns=(
  "For agentic workers: REQUIRED SUB-SKILL"
  "Treat this as a code review, not an implementation pass."
  "Keep confirmed findings and open questions separate"
  "Activity and undo only for the main block-owned action path."
  "General block-tree mutation."
  "Review-first deterministic operations."
  "Embedded \`Navigation Ideas\` inside the selected navigation block"
)

canonical_files=(
  "docs/reference/agentic-plan-implementation-guide.md"
  "docs/reference/review-response-protocol.md"
  "docs/reference/surfaces/release-stop-lines.md"
)

has_violation=0

for pattern in "${patterns[@]}"; do
  matches=$(rg -lF "$pattern" docs --glob '*.md' || true)
  if [[ -z "$matches" ]]; then
    continue
  fi

  extras=$(printf '%s\n' "$matches" | rg -vFf <(printf '%s\n' "${canonical_files[@]}") || true)

  if [[ -n "$extras" ]]; then
    echo
    echo "Possible duplicate drift: $pattern"
    printf '  matches: \n%s\n' "$matches"
    has_violation=1
  fi

done

if (( has_violation )); then
  echo
  echo 'Drift check failed. Update docs to reference shared canonical blocks, or add a new canonical location intentionally.'
  exit 1
fi

echo 'No drift signatures outside canonical files were found.'
