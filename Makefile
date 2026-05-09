PLUGIN_SLUG := media-picker-for-immich
VERSION := $(shell grep -m1 'Version:' $(PLUGIN_SLUG).php | awk '{print $$NF}')
DIST_DIR := dist
ZIP := $(DIST_DIR)/$(PLUGIN_SLUG)-$(VERSION).zip

.PHONY: help start stop restart status logs shell cli check release

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

check: release ## Run Plugin Check on the built release zip
	@npx @wordpress/env run cli wp plugin is-installed plugin-check 2>/dev/null || \
		npx @wordpress/env run cli wp plugin install plugin-check --activate
	@npx @wordpress/env run cli wp plugin is-active plugin-check 2>/dev/null || \
		npx @wordpress/env run cli wp plugin activate plugin-check
	npx @wordpress/env run cli sh -c '\
		set -e; \
		PLUGINS_DIR=/var/www/html/wp-content/plugins; \
		CHECK_SLUG=$(PLUGIN_SLUG)-pcp; \
		trap "rm -rf $$PLUGINS_DIR/$$CHECK_SLUG" EXIT; \
		rm -rf $$PLUGINS_DIR/$$CHECK_SLUG; \
		TMP=$$(mktemp -d); \
		unzip -q $$PLUGINS_DIR/$(PLUGIN_SLUG)/$(ZIP) -d $$TMP; \
		mv $$TMP/$(PLUGIN_SLUG) $$PLUGINS_DIR/$$CHECK_SLUG; \
		rm -rf $$TMP; \
		wp plugin check $$CHECK_SLUG --slug=$(PLUGIN_SLUG) $(ARGS)'

release: ## Build release zip (dist/immich-media-picker-VERSION.zip)
	@echo "Building $(ZIP)..."
	@rm -rf $(DIST_DIR)/$(PLUGIN_SLUG)
	@mkdir -p $(DIST_DIR)/$(PLUGIN_SLUG)
	@rsync -a --exclude-from=.distignore . $(DIST_DIR)/$(PLUGIN_SLUG)/
	@cd $(DIST_DIR) && zip -rq $(PLUGIN_SLUG)-$(VERSION).zip $(PLUGIN_SLUG)
	@rm -rf $(DIST_DIR)/$(PLUGIN_SLUG)
	@echo "Created $(ZIP)"
