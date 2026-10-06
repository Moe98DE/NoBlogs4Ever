# NoBlogs4Ever developer and operator shortcuts. Run `make help`.
COMPOSE ?= docker compose
PRODUCTION_COMPOSE = $(COMPOSE) -f compose.yaml -f compose.production.yaml
PLATFORM_DOMAIN ?= $(shell sed -n 's/^PLATFORM_DOMAIN=//p' $${NBE_ENV_FILE:-.env} 2>/dev/null | tr -d '"' || echo lvh.me)
WP = $(COMPOSE) run --rm cli wp --allow-root --url=http://$(or $(PLATFORM_DOMAIN),lvh.me)

.DEFAULT_GOAL := help
.PHONY: help init up dev down logs bootstrap seed shell test lint integration e2e backup backup-check restore-drill \
	versions runtime-evidence release-check release-check-strict production-check production-smoke smtp-test \
	monitoring-check large-import migration-performance migration-validate performance sbom vulnerability-scan clean-check lock-verify

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

## --- Development -----------------------------------------------------------
init: ## Create .env and random local secrets (never overwrites existing ones)
	python3 scripts/generate-secrets.py
	test -f .env || cp .env.example .env

up: ## Build the image and start the stack
	$(COMPOSE) up -d --build

dev: ## Start the stack with live-mounted PHP code (no rebuild needed after edits)
	$(COMPOSE) -f compose.yaml -f compose.dev.yaml up -d --build

down: ## Stop the stack (keeps data; use `docker compose down -v` to wipe it)
	$(COMPOSE) down

logs: ## Follow container logs
	$(COMPOSE) logs -f --tail=100

bootstrap: ## Install/upgrade the network and apply platform configuration (idempotent)
	$(COMPOSE) run --rm cli php /opt/nbe/scripts/bootstrap.php

seed: ## Create demo users, two sites, posts, media and comments (development only)
	$(WP) eval-file /opt/nbe/scripts/seed.php

shell: ## Open a shell in a one-off tools container (WP-CLI available as `wp --allow-root`)
	$(COMPOSE) run --rm cli bash

## --- Tests -----------------------------------------------------------------
test: ## Fast tests: PHP unit, crypto, Python, syntax, secret scan (no Docker needed)
	php tests/unit.php
	php tests/xml-unit.php
	node tests/contact-crypto.mjs
	python3 tests/test_secrets.py
	python3 tests/test_production_check.py
	python3 tests/test_operator_cli.py
	python3 scripts/secret-scan.py
	@find app config scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null && echo "PASS PHP syntax"

lint: ## PHP coding standard (downloads a checksum-pinned PHP-CS-Fixer)
	sh scripts/lint.sh

integration: ## Integration tests inside WordPress against the running stack
	$(WP) eval-file /opt/nbe/tests/integration.php

e2e: ## Browser tests (needs `make integration seed` first, plus `npm ci`)
	npm run test:e2e

## --- Operations ------------------------------------------------------------
backup: ## Encrypted Restic backup of database, uploads, jobs and configuration
	$(COMPOSE) --profile backup run --rm backup /scripts/backup.sh

backup-check: ## Verify the last backup is fresh and the repository is readable
	$(COMPOSE) --profile backup run --rm backup /scripts/backup-freshness.sh

restore-drill: ## Restore the latest backup into an isolated throwaway stack and verify it
	COMPOSE='$(COMPOSE)' bash scripts/restore-drill.sh

monitoring-check: ## Database, worker, storage, backup and migration health (exit code for monitors)
	COMPOSE='$(COMPOSE)' bash scripts/monitoring-health.sh

versions: ## Show deployed component versions
	$(WP) eval-file /opt/nbe/scripts/collect-versions.php

runtime-evidence: ## Record actual runtime versions into docs/evidence/
	COMPOSE='$(COMPOSE)' bash scripts/runtime-evidence.sh

production-check: ## Validate one production configuration (.env, secrets, TLS, Compose)
	python3 scripts/production-check.py --compose-command '$(COMPOSE)'

production-smoke: ## Boot the production overlay in isolation with throwaway TLS/secrets and verify hardening
	COMPOSE='$(COMPOSE)' bash tests/production-smoke.sh

smtp-test: ## Send one real test email with the production SMTP configuration
	$(PRODUCTION_COMPOSE) run --rm cli wp eval-file /opt/nbe/scripts/smtp-test.php --allow-root

large-import: ## Operator import of a reviewed archive below IMPORT_DIRECTORY (see docs)
	$(COMPOSE) run --rm importer php -d memory_limit=$${OPERATOR_IMPORT_PHP_MEMORY:-1024M} /usr/local/bin/wp eval-file /opt/nbe/scripts/operator-import.php --allow-root

migration-validate: ## Check a migrated site for leftover dependencies on its old host
	$(WP) eval-file /opt/nbe/scripts/validate-migration.php

migration-performance: ## Sample resource use while a migration job runs
	COMPOSE='$(COMPOSE)' bash scripts/migration-performance.sh

performance: ## Small concurrent load probe (observations, not capacity claims)
	python3 scripts/performance.py

## --- Release ---------------------------------------------------------------
release-check: ## Repository hygiene and supply-chain pinning
	python3 scripts/release-check.py

release-check-strict: ## Also require committed runtime/restore evidence
	python3 scripts/release-check.py --release

clean-check: ## Only check that no generated/private files would be published
	python3 scripts/release-check.py --clean-only

lock-verify: ## Re-download every locked dependency and verify its SHA-256
	python3 scripts/lock.py verify

sbom: ## CycloneDX SBOM of the local image (needs syft)
	bash scripts/supply-chain.sh sbom

vulnerability-scan: ## Vulnerability scan of the local image (needs trivy)
	bash scripts/supply-chain.sh scan
