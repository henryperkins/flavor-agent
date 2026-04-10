#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd -- "${script_dir}/.." && pwd)"
plugin_slug="${PLUGIN_SLUG:-$(basename "${plugin_dir}")}"
wp_root="${WP_PLUGIN_CHECK_PATH:-$(cd -- "${plugin_dir}/../../.." && pwd)}"
prepare_release_script="${script_dir}/prepare-release.sh"
plugins_dir="${wp_root}/wp-content/plugins"
staged_plugin_dir=''

if [[ ! -f "${wp_root}/wp-config.php" ]]; then
	echo "Expected a WordPress root at ${wp_root}. Set WP_PLUGIN_CHECK_PATH to override." >&2
	exit 1
fi

if [[ ! -d "${plugins_dir}" ]]; then
	echo "Expected a plugins directory at ${plugins_dir}." >&2
	exit 1
fi

cleanup() {
	if [[ -n "${staged_plugin_dir}" && -d "${staged_plugin_dir}" && -z "${PLUGIN_CHECK_KEEP_STAGE:-}" ]]; then
		rm -rf -- "${staged_plugin_dir}"
	fi
}

stage_plugin() {
	staged_plugin_dir="$(mktemp -d "${plugins_dir}/${plugin_slug}-plugin-check-XXXXXX")"
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
stage_plugin
describe_stage

args=(
	plugin
	check
	"$(basename "${staged_plugin_dir}")"
	"--path=${wp_root}"
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
