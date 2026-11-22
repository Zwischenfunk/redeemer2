.PHONY: rewrite-up rewrite-test rewrite-probe rewrite-down

# Isolated rewrite stack, see app/docs/teststack.md
REWRITE = docker compose -p redeemer-rewrite -f compose.rewrite.yaml
export REWRITE_UID ?= $(shell id -u)
export REWRITE_GID ?= $(shell id -g)

# Builds the image, installs the locked dependencies and starts nginx/PHP-FPM, PostgreSQL 13, OpenLDAP and Mailpit
rewrite-up:
	$(REWRITE) build php
	$(REWRITE) run --rm composer composer install --no-interaction --prefer-dist
	$(REWRITE) up -d --wait nginx
	@echo "App:     http://127.0.0.1:$${REWRITE_HTTP_PORT:-18480}/wien/"
	@echo "Mailpit: http://127.0.0.1:$${REWRITE_MAIL_UI_PORT:-18481}/"

# Full PHPUnit suite (stack probe + HTTP checks) against the running stack
rewrite-test: rewrite-up
	$(REWRITE) run --rm tools vendor/bin/phpunit

# Only the stack probe (fixtures, DB, LDAP, Mailpit, no egress); used by the later CI job
rewrite-probe: rewrite-up
	$(REWRITE) run --rm tools vendor/bin/phpunit --testsuite stack

# Stops the stack and deletes its volumes, so the next start is fresh
rewrite-down:
	$(REWRITE) --profile tools down --volumes --remove-orphans
