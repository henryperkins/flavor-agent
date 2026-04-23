#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

live_docs=(
	"${repo_root}/flavor-agent.php"
	"${repo_root}/readme.txt"
	"${repo_root}/AGENTS.md"
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
	"${repo_root}/docs/reference/cross-surface-validation-gates.md"
)

fail=0

for f in "${live_docs[@]}"; do
	[[ -f "$f" ]] || { echo "Missing live doc: $f" >&2; fail=1; }
done

# Fixed-string search -- pattern must NOT appear in the given files.
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

# Regex search -- pattern MUST appear in the given files.
check_present() {
	local description="$1"
	local pattern="$2"
	shift 2

	if ! rg -n --no-heading --color never -- "$pattern" "$@" >/dev/null; then
		echo "Doc freshness check failed: ${description}" >&2
		fail=1
	fi
}

# Regex search -- pattern MUST appear in EACH given file independently.
# Use this to enforce byte-parity of a shared fact across multiple docs.
check_present_in_each() {
	local description="$1"
	local pattern="$2"
	shift 2

	local missing=()
	local f
	for f in "$@"; do
		if ! rg -n --no-heading --color never -- "$pattern" "$f" >/dev/null; then
			missing+=("$f")
		fi
	done

	if (( ${#missing[@]} > 0 )); then
		echo "Doc freshness check failed: ${description}" >&2
		for f in "${missing[@]}"; do
			echo "  missing from: ${f}" >&2
		done
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
	'renamed settings-page entry point still appears as src/admin/sync-button.js in live docs' \
	'src/admin/sync-button.js' \
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
	'ability reference should still declare twenty abilities' \
	'All twenty abilities are registered' \
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

check_present \
	'cross-surface validation reference should keep additive gate wording' \
	'Use these gates as additive hard stops' \
	"${repo_root}/docs/reference/cross-surface-validation-gates.md"

check_present \
	'agent runbooks should mention the cross-surface validation gate reference' \
	'cross-surface-validation-gates\.md' \
	"${repo_root}/AGENTS.md" \
	"${repo_root}/CLAUDE.md" \
	"${repo_root}/.github/copilot-instructions.md"

# Parity guards: shared facts that must stay byte-identical across contributor
# runbooks (CLAUDE.md for Claude Code, copilot-instructions.md for Copilot).
# If one file is updated but the other isn't, the drifted copy no longer
# contains the canonical string and this check fails.
check_present_in_each \
	'webpack entry-point list drifted between CLAUDE.md and copilot-instructions.md' \
	'`src/index\.js` \(editor\), `src/admin/settings-page\.js` \(settings page\), and `src/admin/activity-log\.js` \(AI Activity admin page\)' \
	"${repo_root}/CLAUDE.md" \
	"${repo_root}/.github/copilot-instructions.md"

check_present_in_each \
	'ability count drifted between CLAUDE.md and copilot-instructions.md' \
	'20 abilities across block, pattern, template, navigation, docs, infra, content, and style categories, including design inspection helpers' \
	"${repo_root}/CLAUDE.md" \
	"${repo_root}/.github/copilot-instructions.md"

# Skill replicas must match the canonical .claude/skills/ tree. Edit skills
# only in .claude/skills/ and run scripts/sync-skills.sh to refresh replicas.
canonical_skills="${repo_root}/.claude/skills"
for replica in "${repo_root}/.cursor/skills" "${repo_root}/.codex/skills" "${repo_root}/.github/skills"; do
	if [[ ! -d "${replica}" ]]; then
		echo "Skill replica missing: ${replica}" >&2
		fail=1
		continue
	fi
	if ! diff -rq "${canonical_skills}" "${replica}" >/dev/null 2>&1; then
		echo "Skill replica drift: ${replica} differs from ${canonical_skills}." >&2
		echo "Run scripts/sync-skills.sh to replicate canonical skills." >&2
		diff -rq "${canonical_skills}" "${replica}" >&2 || true
		fail=1
	fi
done

if [[ "${fail}" -ne 0 ]]; then
	echo "Run the relevant doc update(s) and re-check the live docs." >&2
	exit 1
fi
