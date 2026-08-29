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

.PHONY: stan
stan: ## Statische Analyse
	$(RUN) vendor/bin/phpstan analyse

.PHONY: cs
cs: ## Coding Standards prüfen
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: cs-fix
cs-fix: ## Coding Standards korrigieren
	$(RUN) vendor/bin/php-cs-fixer fix

# Schreibt composer.lock ABSICHTLICH um und laesst die Untergrenze stehen — wer
# das Ziel einzeln aufruft, will genau das. Zum Zurueckkommen: `make install`.
.PHONY: lowest
lowest: ## Niedrigste erlaubte Abhängigkeitsversionen (schreibt composer.lock um)
	$(RUN) composer update --prefer-lowest --prefer-stable

# Haengt bewusst NICHT an `lowest`: Die Sperrdatei ist der festgehaltene Stand des
# Projekts, kein Nebenprodukt eines Testlaufs. Bliebe die Untergrenze darin stehen,
# liefe jedes spaetere `make test` still auf Symfony 5.4 statt 7.4 — und ein
# unbemerkt mitcommitteter Lock traefe jede Installation.
#
# Deshalb: sichern, Untergrenze ziehen, testen, in JEDEM Fall zuruecksetzen. Das
# `trap ... EXIT` greift auch bei fehlschlagenden Tests und bei Strg-C; der
# Rueckgabewert von phpunit bleibt erhalten, weil der Trap-Befehl ihn nicht
# ueberschreibt. Wiederhergestellt wird aus einer Kopie und nicht per `git
# checkout` — sonst verloere ein Lauf mit ungesicherten Lock-Aenderungen sie.
#
# vendor/ wird mit zurueckgesetzt. Nur die Sperrdatei zu heilen waere die
# schlimmere Haelfte: Der Lock zeigte auf die Obergrenze, installiert waere die
# Untergrenze, und kein Ziel sagte es einem.
.PHONY: test-lowest
test-lowest: ## Tests auf der Untergrenze (ohne Container-Abdruck; setzt composer.lock zurück)
	@mkdir -p var
	@cp composer.lock var/composer.lock.vor-lowest
	@trap 'mv -f var/composer.lock.vor-lowest composer.lock; $(RUN) composer install' EXIT; \
		$(RUN) composer update --prefer-lowest --prefer-stable && \
		$(RUN) vendor/bin/phpunit --testsuite unit,integration --exclude-group fingerprint

.PHONY: sh
sh: ## Shell im Container
	$(RUN) sh

.PHONY: down
down: ## Container und Volumes entfernen
	$(DC) down -v
