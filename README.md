# Bizen Toolkit

A WordPress agency plugin that consolidates multiple third-party tools into a single modular system. Modules can be toggled on/off from a branded admin panel. Upstream version monitoring runs via WP-cron.

**Requires:** WordPress 6.7+, PHP 8.0+

---

## Modules

| Module | Description | Origin |
|--------|-------------|--------|
| `menu-enhancer` | Per-item enhancer for the WP nav-menu editor (collapse, scroll indicator, group highlight) | Commercial — Menu Management Enhancer v1.2 |
| `cf7-html-editor` | Adds a syntax-highlighted HTML editor to Contact Form 7 form fields | [CF7 Coder v1.0.1](https://wordpress.org/plugins/cf7-coder/) (GPL-2.0+) |
| `cf7-email-template` | Wraps CF7 emails in a custom HTML header/footer template with a live Ace editor preview | [HTML Template for CF7 v2.2.2](https://wordpress.org/plugins/cf7-html-email-template-extension/) (GPL-2.0+) |
| `acfml-sync-fix` | Keeps ACF field group definitions in sync across WPML languages | Core — written in-house |

---

## Installation

1. Clone or download this repository into `wp-content/plugins/bizen-toolkit/`
2. Activate **Bizen Toolkit** from the WordPress plugins list
3. Go to **Bizen Toolkit** in the admin menu to enable individual modules

Auto-updates are handled via GitHub — when a new version is pushed to `main`, WordPress will show the standard update prompt in the plugins list.

---

## Adding a module

Create `modules/<slug>/module.php` returning an anonymous class that extends `Bizen_Module`. The loader discovers it automatically — no registration needed.

```php
<?php
// modules/my-module/module.php
defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

    public function get_id(): string {
        return 'my-module';
    }

    public function get_name(): string {
        return 'My Module';
    }

    public function get_description(): string {
        return 'What this module does, in one sentence.';
    }

    public function boot(): void {
        // Hook into WordPress here. Only called when the module is enabled
        // and all dependencies are met.
        add_action( 'init', [ $this, 'init' ] );
    }
};
```

### Upstream source metadata

Set these on modules vendored from a third-party plugin so the version monitor and admin panel link back to the source:

```php
// Vendored from WP.org — enables weekly version check
public function get_source_slug(): ?string {
    return 'original-plugin-slug';
}

// Vendored from GitHub (only when not on WP.org)
public function get_source_repo(): ?string {
    return 'owner/repo';
}

// The upstream version that was vendored
public function get_source_version(): ?string {
    return '1.2.3';
}
```

In-house modules (not vendored) leave all three at their `null` default — they appear as **Core** in the admin panel.

### Dependencies and conflicts

```php
// Plugins that must be active for this module to boot
public function get_dependencies(): array {
    return [ 'contact-form-7/wp-contact-form-7.php' ];
}

// The original plugin being replaced — shown as a conflict if active alongside this module
public function get_conflicts(): array {
    return [
        [ 'file' => 'original-plugin/original-plugin.php', 'name' => 'Original Plugin Name' ],
    ];
}
```

---

## Releasing an update

Bump the version and push in one command:

```bash
make release v=1.2.3
```

This updates the version in the plugin header and the `BIZEN_TOOLKIT_VERSION` constant, commits, and pushes. WordPress will detect the new version on its next check and show the standard update prompt.

To build a distributable ZIP for manual installation:

```bash
make build
```
