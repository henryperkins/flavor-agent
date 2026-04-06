#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd -- "${script_dir}/.." && pwd)"
distignore_file="${plugin_dir}/.distignore"
output_dir="${1:-}"

if [[ -z "${output_dir}" ]]; then
	echo "Usage: $(basename "$0") <output-dir>" >&2
	exit 1
fi

mkdir -p -- "${output_dir}"

if command -v rsync >/dev/null 2>&1; then
	rsync_args=( -a )
	if [[ -f "${distignore_file}" ]]; then
		rsync_args+=( "--exclude-from=${distignore_file}" )
	fi

	rsync "${rsync_args[@]}" "${plugin_dir}/" "${output_dir}/"
else
	tar_args=( -cf - )
	if [[ -f "${distignore_file}" ]]; then
		tar_args+=( "--exclude-from=${distignore_file}" )
	fi

	(
		cd -- "${plugin_dir}"
		tar "${tar_args[@]}" .
	) | (
		cd -- "${output_dir}"
		tar -xf -
	)
fi

if [[ -f "${output_dir}/composer.json" ]] && command -v composer >/dev/null 2>&1; then
	rm -rf -- "${output_dir}/vendor"
	COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-dev-main}" \
		composer --working-dir="${output_dir}" dump-autoload --no-dev --classmap-authoritative --no-interaction >/dev/null
fi
