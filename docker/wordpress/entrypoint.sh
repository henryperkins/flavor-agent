#!/usr/bin/env bash
#
# Local dev entrypoint: prepare writable paths, bring up the HTTP front door,
# then hand off to the base image's WordPress entrypoint.
#
# Docker Hardened Images ship php-fpm only, so nginx (installed by
# build-setup.sh) terminates HTTP and proxies to php-fpm on 127.0.0.1:9000.
# Docker Official `*-apache` images keep using mod_php and the vhost rewrite.
#
# Either way the container also listens on WORDPRESS_LOOPBACK_PORT so that
# WordPress loopback traffic (Site Health, wp-cron, REST-to-REST) can reach
# http://localhost:<published port> from inside the container using the same
# origin the browser uses.
#
set -Eeuo pipefail

loopback_port="${WORDPRESS_LOOPBACK_PORT:-80}"
docroot='/var/www/html'

prepare_writable_paths() {
	# WordPress requires wp-content/upgrade to exist and be writable by the PHP
	# runtime user before it will run plugin/theme update checks.
	mkdir -p "${docroot}/wp-content/upgrade"

	if [ "$(id -u)" = '0' ]; then
		chown -R www-data:www-data "${docroot}/wp-content/upgrade"
	fi

	chmod 775 "${docroot}/wp-content/upgrade"
}

configure_apache_loopback() {
	if [ "${loopback_port}" = '80' ]; then
		return
	fi

	if ! grep -Eq "^Listen[[:space:]]+${loopback_port}([[:space:]]|$)" /etc/apache2/ports.conf; then
		printf '\nListen %s\n' "${loopback_port}" >>/etc/apache2/ports.conf
	fi

	sed "s/<VirtualHost \*:80>/<VirtualHost *:${loopback_port}>/" \
		/etc/apache2/sites-available/000-default.conf \
		>/etc/apache2/sites-available/localhost-loopback.conf

	a2ensite localhost-loopback >/dev/null
}

configure_nginx() {
	local loopback_listen=''

	if [ "${loopback_port}" != '80' ]; then
		loopback_listen="listen ${loopback_port};"
	fi

	# Debian's nginx includes both conf.d/*.conf and sites-enabled/*; drop the
	# packaged default site so it cannot claim port 80 first.
	rm -f /etc/nginx/sites-enabled/default

	cat >/etc/nginx/conf.d/flavor-agent-wordpress.conf <<NGINX_CONF
server {
    listen 80;
    ${loopback_listen}
    server_name _;

    root ${docroot};
    index index.php;

    # Logs go to the container streams so \`docker compose logs\` matches the
    # behaviour of the Apache-based Docker Official image.
    access_log /dev/stdout;
    error_log /dev/stderr;

    # Generous for a dev box: media uploads and plugin/theme zip installs.
    client_max_body_size 128m;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;

        # Must come after the include so it overrides it. Debian's
        # fastcgi_params ships a security workaround that sets
        # \`HTTP_HOST \$host\`, which strips the client-supplied port; its own
        # comment notes that deployments relying on the port "may therefore
        # break". This stack always runs on a non-80 published port, so
        # WordPress would compare http://localhost/ against a home_url of
        # http://localhost:${loopback_port} and 301-redirect to itself forever.
        # nginx >= 1.30 offers \$host\$is_request_port\$request_port; Debian
        # trixie ships 1.26, so pass the raw Host header — which is what
        # Apache/mod_php did before this image moved to php-fpm. Safe here
        # because this container is a localhost-only development harness.
        fastcgi_param HTTP_HOST \$http_host;

        # Server-side provider calls (AI recommendations, pattern indexing) can
        # outlive nginx's 60s default. mod_php had no equivalent ceiling, so
        # raise it to keep behaviour comparable across both base images.
        fastcgi_read_timeout 300s;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ /\. { deny all; }
    location = /wp-config.php { deny all; }
}
NGINX_CONF

	nginx -t
}

# Declaring ENTRYPOINT in a Dockerfile resets the base image's CMD, and the
# right default differs per base family, so resolve it here instead of pinning
# one in the Dockerfile.
if [ "$#" -eq 0 ]; then
	if command -v apache2-foreground >/dev/null 2>&1; then
		set -- apache2-foreground
	else
		set -- php-fpm
	fi
fi

case "${1:-}" in
	apache2*)
		prepare_writable_paths
		configure_apache_loopback
		exec docker-entrypoint.sh "$@"
		;;
	php-fpm)
		prepare_writable_paths
		configure_nginx
		# nginx daemonizes; php-fpm stays PID 1 so that a PHP crash takes the
		# container down and `restart: unless-stopped` brings it back, matching
		# how apache2-foreground behaved.
		nginx
		exec docker-entrypoint.sh "$@"
		;;
	*)
		# `docker compose run wordpress wp ...`, `bash`, etc.
		exec "$@"
		;;
esac
