#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd -- "${script_dir}/.." && pwd)"
plugin_slug="${PLUGIN_SLUG:-$(basename "${plugin_dir}")}"

load_local_env() {
	local env_file="${plugin_dir}/.env"
	local line key value

	if [[ ! -f "${env_file}" ]]; then
		return
	fi

	while IFS= read -r line || [[ -n "${line}" ]]; do
		line="${line%$'\r'}"

		if [[ -z "${line}" || "${line}" =~ ^[[:space:]]*# ]]; then
			continue
		fi

		if [[ ! "${line}" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
			continue
		fi

		key="${BASH_REMATCH[1]}"

		if [[ -n "${!key+x}" ]]; then
			continue
		fi

		value="${BASH_REMATCH[2]}"
		value="${value#"${value%%[![:space:]]*}"}"
		value="${value%"${value##*[![:space:]]}"}"

		if [[ "${value}" == \"*\" && "${value}" == *\" ]]; then
			value="${value:1:${#value}-2}"
		elif [[ "${value}" == \'*\' && "${value}" == *\' ]]; then
			value="${value:1:${#value}-2}"
		fi

		export "${key}=${value}"
	done < "${env_file}"
}

load_local_env

wp_root="${WP_PLUGIN_CHECK_PATH:-$(cd -- "${plugin_dir}/../../.." && pwd)}"
prepare_release_script="${script_dir}/prepare-release.sh"
plugins_dir="${wp_root}/wp-content/plugins"
stage_parent="${PLUGIN_CHECK_STAGE_DIR:-${TMPDIR:-/tmp}}"
staged_plugin_dir=''

if [[ ! -f "${wp_root}/wp-config.php" ]]; then
	echo "Expected a WordPress root at ${wp_root}. Set WP_PLUGIN_CHECK_PATH to override." >&2
	exit 1
fi

if [[ ! -d "${plugins_dir}" ]]; then
	echo "Expected a plugins directory at ${plugins_dir}." >&2
	exit 1
fi

prepare_stage_parent() {
	mkdir -p -- "${stage_parent}"
	stage_parent="$(cd -- "${stage_parent}" && pwd)"

	if [[ ! -w "${stage_parent}" ]]; then
		echo "Expected a writable Plugin Check staging directory at ${stage_parent}. Set PLUGIN_CHECK_STAGE_DIR to override." >&2
		exit 1
	fi
}

cleanup() {
	if [[ -n "${staged_plugin_dir}" && -d "${staged_plugin_dir}" && -z "${PLUGIN_CHECK_KEEP_STAGE:-}" ]]; then
		rm -rf -- "${staged_plugin_dir}"
	fi
}

stage_plugin() {
	staged_plugin_dir="$(mktemp -d "${stage_parent%/}/${plugin_slug}-plugin-check-XXXXXX")"
	bash "${prepare_release_script}" "${staged_plugin_dir}"
}

describe_stage() {
	echo "Plugin Check staged release: ${staged_plugin_dir}" >&2
	echo "Plugin Check will scan these files:" >&2
	(
		cd -- "${staged_plugin_dir}"
		find . -type f | sort | sed 's#^\./# - #'
	) >&2
}

trap cleanup EXIT
prepare_stage_parent
stage_plugin
describe_stage

args=(
	plugin
	check
	"${staged_plugin_dir}"
	"--path=${wp_root}"
	"--exclude-directories=vendor,node_modules"
	"--ignore-codes=WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound,WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in,WordPress.DB.SlowDBQuery.slow_db_query_tax_query"
	"--slug=${plugin_slug}"
)

if [[ " $* " != *" --format="* ]]; then
	args+=( "--format=strict-table" )
fi

if [[ -n "${PLUGIN_CHECK_KEEP_STAGE:-}" ]]; then
	echo "Staged plugin for plugin-check at ${staged_plugin_dir}" >&2
fi

args+=( "$@" )

wp "${args[@]}"
