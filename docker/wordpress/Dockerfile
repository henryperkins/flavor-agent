ARG WORDPRESS_BASE_IMAGE=wordpress:php8.2-apache
FROM ${WORDPRESS_BASE_IMAGE}

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
	&& apt-get install -y --no-install-recommends curl git less mariadb-client unzip zip \
	&& git config --global --add safe.directory /var/www/html/wp-content/plugins/flavor-agent \
	&& install-php-extensions dom simplexml xml xmlreader xmlwriter \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x /usr/local/bin/wp
