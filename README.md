# Bizen Toolkit

A WordPress agency plugin that consolidates multiple third-party tools into a single modular system. Modules can be toggled on/off from a branded admin panel. Upstream version monitoring runs via WP-cron.

**Requires:** WordPress 6.7+, PHP 8.0+

---

## Modules

| Module | Description | Origin |
|--------|-------------|--------|
| `menu-enhancer` | Per-item enhancer for the WP nav-menu editor (collapse, scroll indicator, group highlight) | Inspired by Menu Management Enhancer v1.2 |
| `cf7-html-editor` | Adds a syntax-highlighted HTML editor to Contact Form 7 form fields | [CF7 Coder v1.0.1](https://wordpress.org/plugins/cf7-coder/) (GPL-2.0+) |
| `cf7-email-template` | Wraps CF7 emails in a custom HTML header/footer template with a live Ace editor preview | [HTML Template for CF7 v2.2.2](https://wordpress.org/plugins/cf7-html-email-template-extension/) (GPL-2.0+) |
| `acfml-sync-fix` | Keeps ACF field group definitions in sync across WPML languages | Core — written in-house |
| `disable-comments` | Disables the comment system site-wide — closes comments everywhere and hides the Comments menu and Discussion settings | Core — written in-house |
| `disable-flamingo-addressbook` | Stops Flamingo from saving contact data to its address book (inbound messages are kept) | [Disable Flamingo Addressbook v1.0](https://wordpress.org/plugins/disable-flamingo-addressbook/) (GPL-2.0+) |
| `svg-flatten` | Flattens uploaded SVGs — CSS moves onto the elements as presentation attributes and ids are namespaced, so two Illustrator exports can be inlined on the same page | Core — written in-house |
| `ai-disclosure` | Flags AI-generated and AI-modified images and prints the official EU disclosure label beside them; reads provenance from XMP and C2PA on upload and adds a review queue under Media | Core — written in-house |

---

## AI disclosure: theme integration

The `ai-disclosure` module filters `wp_get_attachment_image` and `wp_content_img_tag`, which covers themes rendering images through the core helpers. Themes that build their own markup — a Twig macro emitting `<picture>`, a page builder, a hand-written gallery — never reach those filters, and ask for the label instead:

```php
apply_filters( 'bizen_ai_disclosure_badge', '', $attachment_id, $position )
```

It returns the markup, or an empty string when the image needs no disclosure or the module is switched off, so the caller carries no dependency on it.

`$position` is one of `bottom-right` (the default), `bottom-left`, `top-right` or `top-left`. Which corner works is a property of the composition rather than of the file — the same photo is clear in the corner of a card and buried under an overlay panel in a hero — so the template decides. Wrap your own element in `.bizen-ai-media`, adding `--fill` where the image is stretched to a parent that sizes it.

Passing `none` leaves the label off that one placement, for a layout that carries the disclosure another way — a caption under the image, or a second instance of the same photo on the page that is already labelled. It silences a placement, not an image: the same photo keeps its label everywhere else, and an unrecognised value falls back to the default corner rather than to silence, so a typo shows the label instead of hiding it.

It is the wrong tool for "this image never needs a label". An AI image that is not a deepfake — a plainly stylised illustration, which fails the resemblance test — owes no disclosure anywhere, and that is a fact about the file. Record it on the attachment instead, or every template that uses the image has to remember to suppress it.

The label's colourway — solid black or half transparent — is a site-wide setting on the **Media → AI Disclosure** screen. `bizen_ai_disclosure_icon_variant` overrides it per image where a composition needs it.

[Timber AVIF](https://github.com/zenotds/timber-avif) v6.1 and later wires this into its `image()` macro through a `disclosure` option.

### Not covered yet: video and audio

Art. 50(4) covers image, audio and video alike. This module handles raster images only, and widening the MIME list would not be enough:

- The renderer filters `wp_get_attachment_image` and `wp_content_img_tag`. Core renders video through the `[video]` shortcode and the video block, which share none of that markup.
- The disclosure is owed at first exposure, so for video it belongs on the poster frame — before anyone presses play, and not in player chrome that only appears on hover.
- Provenance in MP4 sits in boxes whose position varies far more than it does in JPEG or PNG, so the head-and-tail scan that reliably finds it in a still is not a safe assumption there.
- Audio has no visual surface at all: the disclosure has to sit beside the player as text.
- The review queue filters on image MIME types and uses thumbnails; video needs poster frames and a different empty state.

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
