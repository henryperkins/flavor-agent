#!/usr/bin/env bash
#
# Build-time provisioning for the local WordPress dev container.
#
# Two base-image families are supported, detected here rather than declared:
#
#   * Docker Hardened Images (`dhi.io/wordpress:*-fpm-*-dev`) — the default.
#     DHI publishes php-fpm variants only, so nginx is installed here and
#     started by entrypoint.sh.
#   * Docker Official Images (`wordpress:*-apache`) — mod_php, no nginx.
#     Still required: DHI has no beta/RC/nightly WordPress channel, and
#     scripts/wp70-e2e.js deliberately pins `wordpress:7.0.0-php8.2-apache`
#     for release-gate reproducibility.
#
set -Eeuo pipefail

if command -v apache2-foreground >/dev/null 2>&1; then
	server_model='apache'
else
	server_model='fpm'
fi
echo "build-setup: server model = ${server_model}"

# --- Restore accounts and helpers that DHI strips ---------------------------
#
# DHI ships a minimal /etc/passwd + /etc/group (root, nonroot 65532, nobody)
# and drops init-system-helpers. Debian maintainer scripts fail without them:
# nginx-common chowns to `www-data:adm`, then calls update-rc.d, invoke-rc.d
# and deb-systemd-helper.
#
# `www-data` is aliased onto uid/gid 65532 — the DHI `nonroot` account the
# php-fpm pool already runs as (/etc/php-*/php-fpm.d/zz-wordpress.conf) —
# rather than the Debian-conventional 33. The upstream WordPress entrypoint
# hardcodes `www-data` when it chowns the docroot, so aliasing is what keeps
# those files writable by PHP. A separate uid 33 would leave php-fpm unable to
# write uploads, plugin installs or core updates.
#
# On Docker Official images every one of these already exists and is left
# untouched.
if ! getent group adm >/dev/null; then
	printf 'adm:x:4:\n' >>/etc/group
fi
if ! getent group www-data >/dev/null; then
	printf 'www-data:x:65532:\n' >>/etc/group
fi
if ! getent passwd www-data >/dev/null; then
	printf 'www-data:x:65532:65532:www-data:/var/www:/usr/sbin/nologin\n' >>/etc/passwd
fi

# Stop dpkg from trying to start services while building.
printf '#!/bin/sh\nexit 101\n' >/usr/sbin/policy-rc.d
chmod +x /usr/sbin/policy-rc.d

packages=(curl git less mariadb-client unzip zip)
if [ "${server_model}" = 'fpm' ]; then
	packages+=(nginx)
fi

apt-get update
# init-system-helpers first, on its own: nginx's maintainer scripts need
# update-rc.d / invoke-rc.d / deb-systemd-helper, which DHI removes.
apt-get install -y --no-install-recommends init-system-helpers
apt-get install -y --no-install-recommends "${packages[@]}"

# The nginx package drops its placeholder page into what is also WordPress's
# docroot; remove it so it cannot end up in the wordpress_data volume.
rm -f /var/www/html/index.nginx-debian.html

git config --global --add safe.directory /var/www/html/wp-content/plugins/flavor-agent

# DHI already bundles dom/simplexml/xml/xmlreader/xmlwriter and ships none of
# the docker-php-ext-* helpers that install-php-extensions drives, so only run
# it when something is actually missing (i.e. on Docker Official images).
missing=()
for extension in dom simplexml xml xmlreader xmlwriter; do
	if ! php -m | grep -qix "${extension}"; then
		missing+=("${extension}")
	fi
done
if [ "${#missing[@]}" -gt 0 ]; then
	echo "build-setup: installing PHP extensions: ${missing[*]}"
	install-php-extensions "${missing[@]}"
else
	echo 'build-setup: required PHP extensions already present'
fi

curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x /usr/local/bin/wp

rm -f /usr/sbin/policy-rc.d
rm -rf /var/lib/apt/lists/*
