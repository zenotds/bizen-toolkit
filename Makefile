PLUGIN_SLUG := bizen-toolkit
DIST_DIR    := dist
BUILD_DIR   := $(DIST_DIR)/$(PLUGIN_SLUG)

# Read version straight from the plugin header (no external tools needed).
VERSION  := $(shell sed -n 's/^.*Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' $(PLUGIN_SLUG).php | head -1)
ZIP_FILE := $(DIST_DIR)/$(PLUGIN_SLUG)-$(VERSION).zip

.DEFAULT_GOAL := build

.PHONY: build pot mo clean

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
