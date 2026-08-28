# Local development image for the Flavor Agent WordPress runtime.
#
# Defaults to the Docker Official WordPress 7.1.0 Apache/mod_php image.
# WordPress 7.1.0 is not yet published as a Docker Hardened Image (DHI's
# newest WordPress build tops out at 7.0.4), so this stays on the Docker
# Official image until DHI catches up. Switch WORDPRESS_BASE_IMAGE to a
# `dhi.io/wordpress:*-fpm-*-dev` tag to go back to DHI; this Dockerfile
# auto-detects and supports both base-image families.
#
# DHI publishes php-fpm variants only (there is no Apache/mod_php flavour), so
# build-setup.sh installs nginx and entrypoint.sh fronts php-fpm with it when
# a DHI base is used. Docker Official `wordpress:*-apache` images keep using
# mod_php. DHI also has no beta/RC/nightly WordPress channel, and
# scripts/wp70-e2e.js pins `wordpress:7.1.0-php8.2-apache` on purpose (PHP 8.2
# there keeps the plugin's declared floor under browser coverage).
ARG WORDPRESS_BASE_IMAGE=wordpress:7.1.0-php8.3-apache
FROM ${WORDPRESS_BASE_IMAGE}

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/wordpress/build-setup.sh /usr/local/bin/flavor-agent-build-setup.sh
COPY docker/wordpress/entrypoint.sh /usr/local/bin/flavor-agent-entrypoint.sh

RUN chmod +x /usr/local/bin/flavor-agent-build-setup.sh /usr/local/bin/flavor-agent-entrypoint.sh \
	&& /usr/local/bin/flavor-agent-build-setup.sh

ENTRYPOINT ["/usr/local/bin/flavor-agent-entrypoint.sh"]
