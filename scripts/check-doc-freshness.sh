#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

live_docs=(
	"${repo_root}/flavor-agent.php"
	"${repo_root}/readme.txt"
	"${repo_root}/CLAUDE.md"
	"${repo_root}/.github/copilot-instructions.md"
	"${repo_root}/STATUS.md"
	"${repo_root}/docs/SOURCE_OF_TRUTH.md"
	"${repo_root}/docs/FEATURE_SURFACE_MATRIX.md"
	"${repo_root}/docs/flavor-agent-readme.md"
	"${repo_root}/docs/features/activity-and-audit.md"
	"${repo_root}/docs/features/pattern-recommendations.md"
	"${repo_root}/docs/features/settings-backends-and-sync.md"
	"${repo_root}/docs/reference/abilities-and-routes.md"
)

fail=0

for f in "${live_docs[@]}"; do
	[[ -f "$f" ]] || { echo "Missing live doc: $f" >&2; fail=1; }
done

# Fixed-string search — pattern must NOT appear in the given files.
check_absent() {
	local description="$1"
	local pattern="$2"
	shift 2

	local output
	if output=$(rg -n -F --no-heading --color never -- "$pattern" "$@"); then
		echo "Doc freshness check failed: ${description}" >&2
		echo "$output" >&2
		fail=1
	fi
}

# Regex search — pattern MUST appear in the given files.
check_present() {
	local description="$1"
	local pattern="$2"
	shift 2

	if ! rg -n --no-heading --color never -- "$pattern" "$@" >/dev/null; then
		echo "Doc freshness check failed: ${description}" >&2
		fail=1
	fi
}

check_absent \
	'old 11-ability wording still appears in live docs' \
	'11 abilities' \
	"${live_docs[@]}"

check_absent \
	'old five-experience summary still appears in live docs' \
	'five primary editor experiences' \
	"${live_docs[@]}"

check_absent \
	'block-only plugin description still appears in live docs' \
	'LLM-powered block recommendations in the native Inspector sidebar' \
	"${live_docs[@]}"

check_absent \
	'old activity-log boot field name still appears in live docs' \
	'defaultLimit' \
	"${live_docs[@]}"

check_absent \
	'old activity-history wording still omits style surfaces' \
	'Block, template, and template-part applies write structured activity entries' \
	"${repo_root}/CLAUDE.md"

check_absent \
	'old pattern setup wording still requires direct provider chat' \
	'Active direct provider chat + embeddings' \
	"${repo_root}/docs/features/settings-backends-and-sync.md"

check_present \
	'ability reference should still declare thirteen abilities' \
	'All thirteen abilities are registered' \
	"${repo_root}/docs/reference/abilities-and-routes.md"

check_present \
	'flavor-agent-readme should describe the seven shipped editor experiences' \
	'seven primary editor experiences' \
	"${repo_root}/docs/flavor-agent-readme.md"

check_present \
	'feature surface matrix should include Style Book in the AI activity row' \
	'AI activity and undo.*Style Book' \
	"${repo_root}/docs/FEATURE_SURFACE_MATRIX.md"

check_present \
	'pattern docs should mention both plugin settings and connectors for setup' \
	'Settings > Flavor Agent.*Settings > Connectors' \
	"${repo_root}/docs/features/pattern-recommendations.md"

if [[ "${fail}" -ne 0 ]]; then
	echo "Run the relevant doc update(s) and re-check the live docs." >&2
	exit 1
fi
