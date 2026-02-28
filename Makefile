.PHONY: help start stop restart status logs shell cli

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

start: ## Start wp-env
	npx @wordpress/env start

stop: ## Stop wp-env
	npx @wordpress/env stop

restart: ## Restart wp-env
	npx @wordpress/env stop && npx @wordpress/env start

status: ## Show plugin status
	npx @wordpress/env run cli wp plugin list

logs: ## Show WordPress debug log
	npx @wordpress/env run cli cat /var/www/html/wp-content/debug.log 2>/dev/null || echo "No debug log found."

shell: ## Open a shell in the WordPress container
	npx @wordpress/env run cli bash

cli: ## Run WP-CLI (usage: make cli CMD="option list")
	npx @wordpress/env run cli wp $(CMD)
