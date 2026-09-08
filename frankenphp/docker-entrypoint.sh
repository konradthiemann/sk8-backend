#!/bin/sh
set -e

# Railway injects $PORT; fall back to 8000 for local runs.
if [ -z "${SERVER_NAME:-}" ]; then
	SERVER_NAME=":${PORT:-8000}"
fi
export SERVER_NAME

# Only the web server needs the full startup sequence. Any other command
# (composer, bin/console, phpunit, bash, ...) is executed as-is.
if [ "$1" = 'frankenphp' ]; then
	if [ -z "$(ls -A vendor/ 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	php bin/console -V

	echo 'Waiting for the database ...'
	attempts=60
	until php bin/console dbal:run-sql -q 'SELECT 1' >/dev/null 2>&1; do
		attempts=$((attempts - 1))
		if [ "$attempts" -eq 0 ]; then
			echo 'The database is not reachable.' >&2
			php bin/console dbal:run-sql -q 'SELECT 1' || true
			exit 1
		fi
		sleep 1
	done
	echo 'Database is reachable.'

	# Production always migrates on start (single-instance deployment, ADR-005).
	# Locally, opt in with RUN_MIGRATIONS=1.
	if [ "${APP_ENV:-}" = 'prod' ] || [ "${RUN_MIGRATIONS:-0}" = '1' ]; then
		php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
	fi
fi

exec "$@"
