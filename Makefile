.PHONY: rewrite-up rewrite-test rewrite-probe rewrite-down rewrite-lint rewrite-audit rewrite-qa

# Isolated rewrite stack, see app/docs/teststack.md
REWRITE = docker compose -p redeemer-rewrite -f compose.rewrite.yaml
export REWRITE_UID ?= $(shell id -u)
export REWRITE_GID ?= $(shell id -g)

# Builds the image, installs the locked dependencies, clears the compiled container and starts nginx/PHP-FPM, PostgreSQL 13, OpenLDAP and Mailpit
rewrite-up:
	$(REWRITE) build php
	$(REWRITE) run --rm composer composer install --no-interaction --prefer-dist
	$(REWRITE) stop php
	$(REWRITE) run --rm --no-deps tools php bin/console cache:clear --env=prod --no-warmup
	$(REWRITE) run --rm --no-deps tools php bin/console cache:clear --env=test --no-warmup
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

# Static checks without the stack: composer validate, PHP/Twig/YAML lint, PHPStan, workflow lint
rewrite-lint:
	$(REWRITE) build php
	$(REWRITE) run --rm composer composer install --no-interaction --prefer-dist
	$(REWRITE) run --rm --no-deps tools composer validate --strict
	$(REWRITE) run --rm --no-deps tools sh -c 'find src tests config -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
	$(REWRITE) run --rm --no-deps tools php bin/console lint:twig templates
	$(REWRITE) run --rm --no-deps tools php bin/console lint:yaml config
	$(REWRITE) run --rm --no-deps tools vendor/bin/phpstan analyse --no-progress --memory-limit=512M
	docker run --rm -v "$(CURDIR):/repo" -w /repo rhysd/actionlint:1.7.7@sha256:887a259a5a534f3c4f36cb02dca341673c6089431057242cdc931e9f133147e9 -color

# Checks composer.lock against the advisory database (needs the internet, so it runs in the composer container)
rewrite-audit:
	$(REWRITE) run --rm composer composer audit --locked --no-interaction

# Everything the CI runs: static checks, dependency audit, full PHPUnit suite
rewrite-qa: rewrite-lint rewrite-audit rewrite-test
