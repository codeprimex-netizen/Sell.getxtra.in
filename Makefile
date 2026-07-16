# Sell.getxtra.in — developer & ops convenience targets.
.DEFAULT_GOAL := help
.PHONY: help install key migrate rollback seed serve worker schedule \
        test lint analyse syntax ci docker-build up down

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

install: ## Install PHP dependencies
	composer install

key: ## Generate an APP_KEY value
	@php -r "echo 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

migrate: ## Apply pending database migrations
	php bin/console migrate

rollback: ## Roll back the last migration batch
	php bin/console rollback

seed: ## Seed roles/permissions, feature flags, categories, coupons
	php bin/console seed

serve: ## Run the dev web server on :8000
	php -S 127.0.0.1:8000 -t public

worker: ## Process the job queue
	php bin/console queue:work

schedule: ## Run due scheduled tasks
	php bin/console schedule:run

test: ## Run all offline test suites
	php bin/run-tests.php

lint: ## PHPCS (PSR-12)
	composer lint

analyse: ## PHPStan static analysis
	composer analyse

syntax: ## php -l across the tree
	@find src public bin database resources tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null && echo "syntax OK"

ci: syntax test ## Run the local CI gate (syntax + tests)

docker-build: ## Build the production image
	docker build -f deploy/Dockerfile -t sell-getxtra:local .

up: ## Start the HA stack (LB + web + workers + Redis + MySQL)
	docker compose -f deploy/ha/docker-compose.yml up --build

down: ## Stop the HA stack
	docker compose -f deploy/ha/docker-compose.yml down
