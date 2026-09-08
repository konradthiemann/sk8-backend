#syntax=docker/dockerfile:1

# Multi-stage build on top of FrankenPHP (Caddy + PHP in one process).
# Stages:
#   frankenphp_base  shared runtime: PHP extensions, Composer, Caddyfile, entrypoint
#   frankenphp_dev   local development (source is bind-mounted, see compose.yaml)
#   frankenphp_prod  production image with vendored dependencies and warmed cache
# The last stage is the default target, so `docker build .` produces the prod image.

FROM dunglas/frankenphp:1-php8.5 AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# Debian mirrors over HTTPS only (plain HTTP is blocked in some networks)
RUN <<-EOT
	sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources
	apt-get update
	apt-get install -y --no-install-recommends git unzip
	install-php-extensions pdo_pgsql intl zip apcu opcache
	rm -rf /var/lib/apt/lists/*
EOT

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1
ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"
# Address Caddy listens on; the entrypoint derives it from $PORT when unset (Railway).
ENV SERVER_NAME=":8000"

COPY frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY frankenphp/Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=30s --interval=10s --timeout=5s CMD php -r 'exit(false === @file_get_contents("http://localhost:8000/api/health", context: stream_context_create(["http" => ["timeout" => 3]])) ? 1 : 0);'

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]


FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
# Xdebug is installed but disabled; enable per run with XDEBUG_MODE=debug.
ENV XDEBUG_MODE=off

RUN <<-EOT
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	git config --system --add safe.directory /app
EOT

COPY frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/


FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# Install dependencies first so this layer is only rebuilt when they change.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist --no-cache

COPY . ./

RUN <<-EOT
	mkdir -p var/cache var/log
	composer dump-autoload --no-dev --optimize --classmap-authoritative
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	php bin/console cache:warmup
	chmod +x bin/console
EOT
