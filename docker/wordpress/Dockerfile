ARG WORDPRESS_BASE_IMAGE=wordpress:php8.2-apache
FROM ${WORDPRESS_BASE_IMAGE}

RUN apt-get update \
	&& apt-get install -y --no-install-recommends curl git less mariadb-client unzip zip \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x /usr/local/bin/wp
