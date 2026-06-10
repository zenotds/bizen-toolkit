PLUGIN_SLUG := bizen-toolkit
DIST_DIR    := dist
BUILD_DIR   := $(DIST_DIR)/$(PLUGIN_SLUG)

# Read version straight from the plugin header (no external tools needed).
VERSION  := $(shell sed -n 's/^.*Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' $(PLUGIN_SLUG).php | head -1)
ZIP_FILE := $(DIST_DIR)/$(PLUGIN_SLUG)-$(VERSION).zip

.DEFAULT_GOAL := build

.PHONY: build pot mo clean release

## build: Compile translations, then create a ready-to-install ZIP in dist/
build: mo clean
	@echo "Building $(PLUGIN_SLUG) v$(VERSION)..."
	@mkdir -p "$(BUILD_DIR)"
	@rsync -a --exclude-from=.buildignore . "$(BUILD_DIR)/"
	@cd "$(DIST_DIR)" && zip -rq "$(PLUGIN_SLUG)-$(VERSION).zip" "$(PLUGIN_SLUG)"
	@rm -rf "$(BUILD_DIR)"
	@echo "Done → $(ZIP_FILE)"

## mo: Compile all .po files in languages/ to .mo
mo:
	@for po in languages/*.po; do \
		mo="$${po%.po}.mo"; \
		msgfmt -o "$$mo" "$$po" && echo "  compiled $$mo"; \
	done

## clean: Remove the dist/ directory
clean:
	@rm -rf "$(DIST_DIR)"

## release v=X.Y.Z: Bump version in plugin header + constant, commit, push
release:
	@test -n "$(v)" || (echo "Usage: make release v=X.Y.Z" && exit 1)
	@sed -i '' 's/^\( \* Version:[[:space:]]*\)[0-9][0-9.]*/\1$(v)/' $(PLUGIN_SLUG).php
	@sed -i '' "s/define( 'BIZEN_TOOLKIT_VERSION',[[:space:]]*'[0-9][0-9.]*' )/define( 'BIZEN_TOOLKIT_VERSION',      '$(v)' )/" $(PLUGIN_SLUG).php
	@git add -A
	@git commit -m "Release v$(v)"
	@git push
	@echo "Released v$(v) — WordPress will prompt for update on next check"
