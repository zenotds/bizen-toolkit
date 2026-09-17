=== Bizen Toolkit ===
Contributors: bizen
Author URI: https://bizen.it
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agency plugin that consolidates multiple WordPress tools into a single modular system.

== Description ==

Bizen Toolkit is an internal agency plugin that bundles several WordPress enhancements into one place. Modules can be toggled on or off individually from the admin panel without deactivating the whole plugin.

Included modules:

* **Menu Enhancer** — Adds per-item controls to the WordPress nav-menu editor: collapse/expand groups, scroll indicator, and group highlight.
* **CF7 HTML Editor** — Replaces the plain textarea in Contact Form 7 with a syntax-highlighted HTML editor.
* **CF7 Email Template** — Wraps CF7 outgoing emails in a custom HTML header/footer template, editable with a live preview.
* **ACFML Sync Fix** — Keeps ACF field group definitions in sync across WPML languages.
* **Disable Flamingo Addressbook** — Stops Flamingo from saving contact data to its address book; inbound messages are still logged.
* **Disable Comments** — Turns the WordPress comment system off site-wide and removes it from the admin.
* **Flatten SVG on Upload** — Rewrites uploaded SVGs so several of them can be inlined on the same page without their styles and ids colliding.
* **AI Disclosure** — Flags AI-generated and AI-modified images and prints the official EU disclosure label beside them on the front end, with a review queue under Media.

== Changelog ==

= 1.4.6 =
* AI Disclosure: the EU label style setting moved out of the modules list and onto the AI Disclosure screen, beside the queue it affects. It saves on change, previews the choice against a mid-grey backdrop — on white the half-transparent option is indistinguishable from the solid one — and asks for manage_options, while the queue itself still only needs upload_files
* The per-module settings hook added to the admin panel in 1.4.5 has been reverted. The modules list is a set of switches, and a module's settings belong on the module's own screen; save_settings() is gone from the base class again

= 1.4.5 =
* AI Disclosure: provenance is now read from C2PA as well as XMP. ChatGPT and Gemini ship a C2PA manifest and no XMP packet at all, so images from either were arriving unflagged — the IPTC term travels through the manifest as a readable string, so it can be found without a C2PA parser
* AI Disclosure: the EU label files are cropped to the artwork. The Commission ships them on a canvas where the pill covers 77% of the width and only 47% of the height, so most of the CSS height was buying empty space and the label rendered at about half the size it was set to
* AI Disclosure: the label style is now a site-wide setting on the module's row in the toolkit panel — solid black or half transparent — rather than a per-image filter. The white variants have been dropped
* Modules can now render and save their own settings in the admin panel: render_settings() was declared on the base class but never actually called, and save_settings() is new alongside it

= 1.4.4 =
* AI Disclosure: a card that no longer matches the active filter now leaves the view on its own, instead of sitting there until the page is reloaded. Clearing the last one returns to page one of the same view, because removing rows shifts the rest forward and a stale page offset would skip over images nobody had seen
* AI Disclosure: new "To review" bulk action sends images back to the queue — that state used to be reachable only from the field on the attachment
* AI Disclosure: added undo. Each image returns to its own previous status, so a mixed selection is restored one by one, and cards that had already left the view come back where they stood

= 1.4.3 =
* AI Disclosure: the review queue now works by selection — tick the images that belong together and apply a status to all of them at once, with shift-click for ranges and a toolbar that stays put while the grid scrolls
* AI Disclosure: added a search field, so a batch is usually everything matching a filename fragment rather than a hunt through pages
* AI Disclosure: the EU label is 22px instead of 18px, and 18px on small screens
* The Italian translation keeps "AI" rather than turning it into "IA", matching the wording baked into the EU icons

= 1.4.2 =
* New module: AI Disclosure — flags AI-generated and AI-modified images and prints the official EU label beside them, as required of whoever publishes them by AI Act art. 50(4)
* The label is a DOM sibling of the image rather than a watermark burnt into the pixels, so it survives every responsive crop; which corner it takes is chosen per template, since what is free in a card sits under the overlay panel in a hero
* Provenance is read from the file's XMP on upload, ahead of any plugin that strips metadata: IPTC trainedAlgorithmicMedia marks an image generated, compositeWithTrainedAlgorithmicMedia marks it modified
* A file carrying no marker stays unreviewed rather than being recorded as AI-free — stripped metadata and a camera photo are indistinguishable
* New review queue under Media → AI Disclosure, with bulk marking, plus a per-attachment field in the media library
* Ships the European Commission's icon set for labelling AI-generated content
* Themes that build their own image markup can ask for the label through the bizen_ai_disclosure_badge filter; Timber AVIF v6.1 uses it
* Italian translation updated

= 1.4.1 =
* Plugin icon is now shown in the WordPress update screens and the plugin details modal
* The green Bizen mark ships as icon.svg plus 128x128 and 256x256 PNG fallbacks, following the WordPress plugin asset naming convention
* The white mark used by the admin menu moved to menu-icon.svg, so the two are no longer the same file

= 1.4.0 =
* New module: Flatten SVG on Upload — rewrites uploaded SVGs so several exports can be inlined on the same page
* Declarations in the SVG style block are resolved against the cascade and written onto the elements as presentation attributes, so the page can still recolour an icon
* Ids that nothing references are dropped; gradients, clip paths and masks that survive are namespaced per file
* Illustrator slice rectangles, generator comments and the XML prolog are stripped
* Files whose CSS cannot be reproduced element by element (media queries, pseudo-classes, descendant selectors) are left untouched
* Italian translation updated

= 1.3.0 =
* New module: Disable Comments — turns the WordPress comment system off site-wide
* Comments and pings are forced closed on every post and page, existing ones included, without touching the database
* Existing comments are hidden on the front end; comment feeds, the REST comment routes and XML-RPC pingbacks are blocked
* Comments menu, Discussion settings, admin-bar node and dashboard widget are removed, and direct URL access to those screens is redirected
* Italian translation updated

= 1.2.0 =
* Fixed an undefined array key warning in the CF7 Email Template mail components

= 1.1.3 =
* Disable Flamingo Addressbook: the Address Book screen is now hidden from the admin menu, with direct URL access redirected to Inbound Messages

= 1.1.2 =
* Maintenance release

= 1.1.1 =
* New module: Disable Flamingo Addressbook — stops Flamingo from saving contact data to its address book

= 1.1.0 =
* Added README.md and readme.txt documentation

= 1.0.7 =
* Raised the minimum supported WordPress version to 6.7
* Build tooling improvements in the Makefile

= 1.0.6 =
* Added GitHub-based auto-update support
* Admin panel redesigned with tabbed layout (Modules / Tools)
* Version monitor now distinguishes Core modules from unmonitored upstream modules

= 1.0.4 =
* Initial release
