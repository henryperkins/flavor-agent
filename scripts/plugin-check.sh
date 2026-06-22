#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd -- "${script_dir}/.." && pwd)"
plugin_slug="${PLUGIN_SLUG:-flavor-agent}"

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

run_docker_plugin_check() {
	docker compose exec -T wordpress bash -s -- "${plugin_slug}" "$@" <<'DOCKER_PLUGIN_CHECK'
set -euo pipefail

	plugin_slug="$1"
	shift

cd /var/www/html/wp-content/plugins/flavor-agent

stage_parent="${PLUGIN_CHECK_STAGE_DIR:-${TMPDIR:-/tmp}}"
mkdir -p -- "${stage_parent}"
stage_parent="$(cd -- "${stage_parent}" && pwd)"
staged_plugin_dir="$(mktemp -d "${stage_parent%/}/${plugin_slug}-plugin-check-XXXXXX")"

cleanup() {
	if [[ -n "${staged_plugin_dir}" && -d "${staged_plugin_dir}" && -z "${PLUGIN_CHECK_KEEP_STAGE:-}" ]]; then
		rm -rf -- "${staged_plugin_dir}"
	fi
}

	trap cleanup EXIT

	resolve_format_mode() {
		local has_format=0
		local format=''
		local previous_format=0
		local arg

		for arg in "$@"; do
			if [[ "${previous_format}" == "1" ]]; then
				format="${arg}"
				has_format=1
				previous_format=0
				continue
			fi

			if [[ "${arg}" == "--format" ]]; then
				previous_format=1
				continue
			fi

			if [[ "${arg}" == --format=* ]]; then
				format="${arg#--format=}"
				has_format=1
			fi
		done

		if [[ "${has_format}" == "0" ]]; then
			printf 'default\n'
		elif [[ "${format}" == "strict-table" ]]; then
			printf 'strict-table\n'
		else
			printf 'custom\n'
		fi
	}

	run_plugin_check_command() {
		local strict_table_output="$1"
		local output=''
		local status=0

		shift

		set +e
		output="$(wp "$@" 2>&1)"
		status=$?
		set -e

		if [[ -n "${output}" ]]; then
			printf '%s\n' "${output}"
		fi

		if [[ "${status}" -ne 0 ]]; then
			return "${status}"
		fi

		if [[ "${strict_table_output}" == "1" ]] \
			&& printf '%s\n' "${output}" | awk -F '\t' 'NR > 1 && $3 == "ERROR" { found = 1 } END { exit found ? 0 : 1 }'; then
			echo "Plugin Check reported errors; see output above." >&2
			return 1
		fi
	}

	bash scripts/prepare-release.sh "${staged_plugin_dir}"

echo "Plugin Check staged release: ${staged_plugin_dir}" >&2
echo "Plugin Check will scan these files:" >&2
(
	cd -- "${staged_plugin_dir}"
	find . -type f | sort | sed 's#^\./# - #'
) >&2

args=(
	plugin
	check
	"${staged_plugin_dir}"
	"--path=/var/www/html"
	"--exclude-directories=vendor,node_modules"
	"--ignore-codes=WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound,WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in,WordPress.DB.SlowDBQuery.slow_db_query_tax_query"
	"--slug=${plugin_slug}"
)

	format_mode="$(resolve_format_mode "$@")"

	if [[ "${format_mode}" == "default" ]]; then
		args+=( "--format=strict-table" )
		strict_table_output=1
	elif [[ "${format_mode}" == "strict-table" ]]; then
		strict_table_output=1
	else
		strict_table_output=0
	fi

if [[ -n "${PLUGIN_CHECK_KEEP_STAGE:-}" ]]; then
	echo "Staged plugin for plugin-check at ${staged_plugin_dir}" >&2
fi

args+=( "$@" )

	run_plugin_check_command "${strict_table_output}" "${args[@]}" --allow-root
DOCKER_PLUGIN_CHECK
}

if [[ "${PLUGIN_CHECK_USE_DOCKER:-}" == "1" || "${PLUGIN_CHECK_USE_DOCKER:-}" == "true" ]]; then
	run_docker_plugin_check "$@"
	exit $?
fi

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

resolve_format_mode() {
	local has_format=0
	local format=''
	local previous_format=0
	local arg

	for arg in "$@"; do
		if [[ "${previous_format}" == "1" ]]; then
			format="${arg}"
			has_format=1
			previous_format=0
			continue
		fi

		if [[ "${arg}" == "--format" ]]; then
			previous_format=1
			continue
		fi

		if [[ "${arg}" == --format=* ]]; then
			format="${arg#--format=}"
			has_format=1
		fi
	done

	if [[ "${has_format}" == "0" ]]; then
		printf 'default\n'
	elif [[ "${format}" == "strict-table" ]]; then
		printf 'strict-table\n'
	else
		printf 'custom\n'
	fi
}

args=(
	plugin
	check
	"${staged_plugin_dir}"
	"--path=${wp_root}"
	"--exclude-directories=vendor,node_modules"
	"--ignore-codes=WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound,WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in,WordPress.DB.SlowDBQuery.slow_db_query_tax_query"
	"--slug=${plugin_slug}"
)

format_mode="$(resolve_format_mode "$@")"

if [[ "${format_mode}" == "default" ]]; then
	args+=( "--format=strict-table" )
	strict_table_output=1
elif [[ "${format_mode}" == "strict-table" ]]; then
	strict_table_output=1
else
	strict_table_output=0
fi

if [[ -n "${PLUGIN_CHECK_KEEP_STAGE:-}" ]]; then
	echo "Staged plugin for plugin-check at ${staged_plugin_dir}" >&2
fi

args+=( "$@" )

run_plugin_check_command() {
	local strict_table_output="$1"
	local output=''
	local status=0

	shift

	set +e
	output="$(wp "$@" 2>&1)"
	status=$?
	set -e

	if [[ -n "${output}" ]]; then
		printf '%s\n' "${output}"
	fi

	if [[ "${status}" -ne 0 ]]; then
		return "${status}"
	fi

	if [[ "${strict_table_output}" == "1" ]] \
		&& printf '%s\n' "${output}" | awk -F '\t' 'NR > 1 && $3 == "ERROR" { found = 1 } END { exit found ? 0 : 1 }'; then
		echo "Plugin Check reported errors; see output above." >&2
		return 1
	fi
}

run_plugin_check_command "${strict_table_output}" "${args[@]}"
