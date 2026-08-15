DC := DOCKER_UID=$(shell id -u) DOCKER_GID=$(shell id -g) docker compose
RUN := $(DC) run --rm php

.DEFAULT_GOAL := help

.PHONY: help
help: ## Zeigt diese Übersicht
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: build
build: ## Baut das PHP-Image
	$(DC) build php

.PHONY: update
update: ## composer update im Container
	$(RUN) composer update

.PHONY: install
install: ## composer install im Container
	$(RUN) composer install

.PHONY: test
test: ## Unit- + Integrationstests
	$(RUN) vendor/bin/phpunit --testsuite unit,integration

.PHONY: test-unit
test-unit: ## Nur Unit-Tests (kein Kernel)
	$(RUN) vendor/bin/phpunit --testsuite unit

.PHONY: test-redis
test-redis: ## Tests gegen echten Broker
	$(DC) up -d redis
	$(RUN) vendor/bin/phpunit --group redis

.PHONY: stan
stan: ## Statische Analyse
	$(RUN) vendor/bin/phpstan analyse

.PHONY: cs
cs: ## Coding Standards prüfen
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: cs-fix
cs-fix: ## Coding Standards korrigieren
	$(RUN) vendor/bin/php-cs-fixer fix

.PHONY: lowest
lowest: ## Niedrigste erlaubte Abhängigkeitsversionen
	$(RUN) composer update --prefer-lowest --prefer-stable

.PHONY: test-lowest
test-lowest: lowest ## Tests auf der Untergrenze (ohne Container-Abdruck)
	$(RUN) vendor/bin/phpunit --testsuite unit,integration --exclude-group fingerprint

.PHONY: sh
sh: ## Shell im Container
	$(RUN) sh

.PHONY: down
down: ## Container und Volumes entfernen
	$(DC) down -v
