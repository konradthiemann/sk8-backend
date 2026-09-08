# Every target runs its command natively when `php` is available on the host,
# otherwise inside the dev container (docker compose run). Nothing else differs.
SHELL := /bin/sh
.DEFAULT_GOAL := help

PHP_BIN := $(shell command -v php 2>/dev/null)
ifeq ($(PHP_BIN),)
	EXEC := docker compose run --rm --no-deps php
else
	EXEC :=
endif

ARGS ?=

.PHONY: help build up down logs sh composer console test phpstan cs cs-fix check migrate openapi

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

## Docker

build: ## Build the dev image
	docker compose build

up: ## Start the dev server on http://localhost:8000
	docker compose up -d --wait
	@echo "Backend: http://localhost:8000/api/health  Docs: http://localhost:8000/api/doc"

down: ## Stop and remove the dev container
	docker compose down --remove-orphans

logs: ## Follow the dev server logs
	docker compose logs -f php

sh: ## Open a shell in the dev container
	docker compose run --rm --no-deps php bash

## PHP tooling (ARGS="..." passes extra arguments)

composer: ## Run composer, e.g. make composer ARGS="require foo/bar"
	$(EXEC) composer $(ARGS)

console: ## Run bin/console, e.g. make console ARGS="debug:router"
	$(EXEC) bin/console $(ARGS)

test: ## Migrate the test database and run PHPUnit
	$(EXEC) composer test -- $(ARGS)

phpstan: ## Static analysis (level max)
	$(EXEC) composer phpstan

cs: ## Coding-standard check (dry run, shows diff)
	$(EXEC) composer cs

cs-fix: ## Fix coding-standard violations
	$(EXEC) composer cs-fix

check: ## phpstan + cs (dry run) + phpunit
	$(EXEC) composer check

migrate: ## Run pending Doctrine migrations (dev database)
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

openapi: ## Dump the OpenAPI spec to var/openapi.json
	$(EXEC) sh -c 'bin/console nelmio:apidoc:dump --format=json > var/openapi.json && echo "written var/openapi.json"'
