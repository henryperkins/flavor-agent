#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd -- "${script_dir}/.." && pwd)"
plugin_slug="${PLUGIN_SLUG:-$(basename "${plugin_dir}")}"
prepare_release_script="${script_dir}/prepare-release.sh"
dist_dir="${plugin_dir}/dist"
staging_root=''
required_build_files=(
	"build/index.asset.php"
	"build/index.js"
	"build/admin.asset.php"
	"build/admin.js"
	"build/activity-log.asset.php"
	"build/activity-log.js"
)

if ! command -v zip >/dev/null 2>&1; then
	echo "zip is required to build a distribution archive." >&2
	exit 1
fi

cleanup() {
	if [[ -n "${staging_root}" && -d "${staging_root}" ]]; then
		rm -rf -- "${staging_root}"
	fi
}

ensure_build_artifacts() {
	local required_file=''
	local missing_files=()

	echo "Building frontend assets for release..."
	(
		cd -- "${plugin_dir}"
		npm run build
	)

	for required_file in "${required_build_files[@]}"; do
		if [[ ! -f "${plugin_dir}/${required_file}" ]]; then
			missing_files+=( "${required_file}" )
		fi
	done

	if [[ "${#missing_files[@]}" -gt 0 ]]; then
		echo "Release build is missing required frontend assets:" >&2
		printf ' - %s\n' "${missing_files[@]}" >&2
		exit 1
	fi
}

trap cleanup EXIT

ensure_build_artifacts
mkdir -p -- "${dist_dir}"
staging_root="$(mktemp -d)"

bash "${prepare_release_script}" "${staging_root}/${plugin_slug}"

archive_path="${dist_dir}/${plugin_slug}.zip"
rm -f -- "${archive_path}"

(
	cd -- "${staging_root}"
	zip -qr "${archive_path}" "${plugin_slug}"
)

echo "Built ${archive_path}"
