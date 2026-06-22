#!/usr/bin/env bash

set -euo pipefail

if ! command -v rg >/dev/null 2>&1; then
	echo "Doc freshness check requires ripgrep (rg)." >&2
	exit 2
fi

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
	"${repo_root}/docs/reference/governance-layer.md"
)

missing_live_docs=()
for f in "${live_docs[@]}"; do
	[[ -f "$f" ]] || missing_live_docs+=("$f")
done
if (( ${#missing_live_docs[@]} > 0 )); then
	printf 'Missing live doc: %s\n' "${missing_live_docs[@]}" >&2
	echo "Update the live_docs array in $(basename "$0") and re-run." >&2
	exit 1
fi

failed=0
total=0

# Fixed-string search -- pattern must NOT appear in the given files.
check_absent() {
	local description="$1" pattern="$2"
	shift 2
	(( ++total ))
	local output rc=0
	output=$(rg -n -F --no-heading --color never -- "$pattern" "$@" 2>&1) || rc=$?
	case "$rc" in
		0)
			echo "Doc freshness check failed: ${description}" >&2
			echo "$output" >&2
			(( ++failed ))
			;;
		1) ;;
		*)
			echo "Doc freshness check could not run: ${description} (rg exit=${rc})" >&2
			echo "$output" >&2
			(( ++failed ))
			;;
	esac
}

# Fixed-string search -- pattern MUST appear in the given files.
check_present_fixed() {
	local description="$1" pattern="$2"
	shift 2
	(( ++total ))
	local output rc=0
	output=$(rg -n -F --no-heading --color never -- "$pattern" "$@" 2>&1) || rc=$?
	case "$rc" in
		0) ;;
		1)
			echo "Doc freshness check failed: ${description}" >&2
			echo "  searched files:" >&2
			local f
			for f in "$@"; do
				echo "    - ${f}" >&2
			done
			(( ++failed ))
			;;
		*)
			echo "Doc freshness check could not run: ${description} (rg exit=${rc})" >&2
			echo "$output" >&2
			(( ++failed ))
			;;
	esac
}

# Regex search -- pattern MUST appear in the given files. Use only when the
# pattern genuinely needs regex semantics (e.g. `.*` across the line); prefer
# check_present_fixed for literal strings.
check_present_regex() {
	local description="$1" pattern="$2"
	shift 2
	(( ++total ))
	local output rc=0
	output=$(rg -n --no-heading --color never -- "$pattern" "$@" 2>&1) || rc=$?
	case "$rc" in
		0) ;;
		1)
			echo "Doc freshness check failed: ${description}" >&2
			echo "  searched files:" >&2
			local f
			for f in "$@"; do
				echo "    - ${f}" >&2
			done
			(( ++failed ))
			;;
		*)
			echo "Doc freshness check could not run: ${description} (rg exit=${rc})" >&2
			echo "$output" >&2
			(( ++failed ))
			;;
	esac
}

# Fixed-string search -- pattern MUST appear in EACH given file independently.
# Use this to enforce byte-parity of a shared fact across multiple docs.
check_present_in_each_fixed() {
	local description="$1" pattern="$2"
	shift 2
	(( ++total ))

	local missing=()
	local f rc
	for f in "$@"; do
		rc=0
		rg -n -F --no-heading --color never -- "$pattern" "$f" >/dev/null 2>&1 || rc=$?
		case "$rc" in
			0) ;;
			1) missing+=("$f") ;;
			*)
				echo "Doc freshness check could not run: ${description} (rg exit=${rc} on ${f})" >&2
				(( ++failed ))
				return
				;;
		esac
	done

	if (( ${#missing[@]} > 0 )); then
		echo "Doc freshness check failed: ${description}" >&2
		for f in "${missing[@]}"; do
			echo "  missing from: ${f}" >&2
		done
		(( ++failed ))
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

check_absent \
	'old plugin-managed chat fallback wording still appears in live docs' \
	'prefers plugin-managed providers' \
	"${live_docs[@]}"

check_absent \
	'old pattern registry patching wording still appears in live docs' \
	'client patches `__experimentalBlockPatterns`' \
	"${live_docs[@]}"

check_absent \
	'old pattern metadata patching convention still appears in live docs' \
	'Pattern recommendations patch editor settings non-destructively' \
	"${live_docs[@]}"

check_absent \
	'retired block structural actions admin setting/constant control wording still appears in live docs' \
	'the default-on admin setting, the developer constant, and the final override filter' \
	"${live_docs[@]}" \
	"${repo_root}/docs/features/block-recommendations.md" \
	"${repo_root}/docs/reference/release-surface-scope-review.md"

check_absent \
	'old seven-surface capability notice wording still appears in live docs' \
	'used by all seven recommendation surfaces' \
	"${live_docs[@]}"

check_present_fixed \
	'ability reference should still declare thirty ability contracts and feature gating' \
	'`inc/Abilities/Registration.php` defines 30 ability contracts' \
	"${repo_root}/docs/reference/abilities-and-routes.md"

check_present_fixed \
	'ability reference should describe split docs-grounding fingerprints' \
	'`docsGrounding` (`{ available, sourceTypes, count, contentFingerprint, runtimeFingerprint }`) and `docsGroundingFingerprint` (the content/applicability fingerprint alias)' \
	"${repo_root}/docs/reference/abilities-and-routes.md"

check_present_fixed \
	'Abilities Explorer docs should distinguish always-on preflight abilities from feature-gated recommendation abilities' \
	'Before enabling the Flavor Agent AI feature, the Explorer should list the 19 always-on helper and preflight abilities' \
	"${repo_root}/docs/reference/local-environment-setup.md"

check_present_fixed \
	'flavor-agent-readme should describe the eight shipped editor experiences' \
	'eight primary editor experiences' \
	"${repo_root}/docs/flavor-agent-readme.md"

check_present_fixed \
	'source of truth should describe eight first-party recommendation surfaces' \
	'Eight first-party recommendation surfaces exist today' \
	"${repo_root}/docs/SOURCE_OF_TRUTH.md"

check_present_regex \
	'feature surface matrix should include Style Book in the AI activity row' \
	'AI activity and undo.*Style Book' \
	"${repo_root}/docs/FEATURE_SURFACE_MATRIX.md"

check_present_regex \
	'pattern docs should mention both plugin settings and connectors for setup' \
	'Settings > Flavor Agent.*Settings > Connectors' \
	"${repo_root}/docs/features/pattern-recommendations.md"

check_present_fixed \
	'cross-surface validation reference should keep additive gate wording' \
	'Use these gates as additive hard stops' \
	"${repo_root}/docs/reference/cross-surface-validation-gates.md"

check_present_fixed \
	'agent runbooks should mention the cross-surface validation gate reference' \
	'cross-surface-validation-gates.md' \
	"${repo_root}/AGENTS.md" \
	"${repo_root}/CLAUDE.md" \
	"${repo_root}/.github/copilot-instructions.md"

check_present_in_each_fixed \
	'governance-layer contract doc is no longer referenced from the positioning docs' \
	'governance-layer.md' \
	"${repo_root}/docs/README.md" \
	"${repo_root}/CLAUDE.md" \
	"${repo_root}/.github/copilot-instructions.md" \
	"${repo_root}/docs/SOURCE_OF_TRUTH.md" \
	"${repo_root}/docs/FEATURE_SURFACE_MATRIX.md" \
	"${repo_root}/README.md"

check_absent \
	'agent execution runbooks should not reference the removed demo script filename' \
	'flavor-agent-demo-script.md' \
	"${repo_root}/docs/agentrunbook.md" \
	"${repo_root}/docs/reference/agentexecutionrunbook.md"

check_absent \
	'agent execution runbooks should not reference the removed demo evidence filename' \
	'flavor-agent-demo-evidence-agent-side.md' \
	"${repo_root}/docs/agentrunbook.md" \
	"${repo_root}/docs/reference/agentexecutionrunbook.md"

check_present_in_each_fixed \
	'agent execution runbooks should point at the portfolio companion doc' \
	'docs/flavoragentportfoliopackage.md' \
	"${repo_root}/docs/agentrunbook.md" \
	"${repo_root}/docs/reference/agentexecutionrunbook.md"

check_present_in_each_fixed \
	'agent execution runbooks should point at the attestation design companion doc' \
	'docs/reference/ring-iii-attestation-design.md' \
	"${repo_root}/docs/agentrunbook.md" \
	"${repo_root}/docs/reference/agentexecutionrunbook.md"

check_present_in_each_fixed \
	'agent execution runbooks should point at the undo drift bug companion doc' \
	'docs/reference/bug-undo-drift-serialization-2026-06-21.md' \
	"${repo_root}/docs/agentrunbook.md" \
	"${repo_root}/docs/reference/agentexecutionrunbook.md"

check_absent \
	'portfolio package should not preserve the old attestation test count' \
	'29 tests' \
	"${repo_root}/docs/flavoragentportfoliopackage.md"

check_absent \
	'portfolio package should not preserve the old attestation assertion count' \
	'82 assertions' \
	"${repo_root}/docs/flavoragentportfoliopackage.md"

# Parity guards: shared facts that must stay byte-identical across contributor
# runbooks (CLAUDE.md for Claude Code, copilot-instructions.md for Copilot).
# If one file is updated but the other isn't, the drifted copy no longer
# contains the canonical string and this check fails.
check_present_in_each_fixed \
	'webpack entry-point list drifted between CLAUDE.md and copilot-instructions.md' \
	'`src/index.js` (editor), `src/admin/settings-page.js` (settings page), and `src/admin/activity-log.js` (AI Activity admin page)' \
	"${repo_root}/CLAUDE.md" \
	"${repo_root}/.github/copilot-instructions.md"

check_present_in_each_fixed \
	'ability count or category list drifted between CLAUDE.md and copilot-instructions.md' \
	'30 abilities across block, pattern, template, navigation, docs, infra, content, style, and apply categories' \
	"${repo_root}/CLAUDE.md" \
	"${repo_root}/.github/copilot-instructions.md"

if (( failed > 0 )); then
	echo >&2
	echo "Doc freshness: ${failed} of ${total} checks failed." >&2
	echo "Run the relevant doc update(s) and re-check the live docs." >&2
	exit 1
fi
