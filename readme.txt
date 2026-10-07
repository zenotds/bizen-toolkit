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
* **ACFML Sync Fix** — Removes the ACFML repeater sync checkbox and its stored option, so repeater rows are never synced across languages by accident.
* **ACFML: Copy once everywhere** — Runs every ACF field group in Expert mode with every field on Copy once, and realigns WPML's per-key settings when a field group is saved: saving the original no longer overwrites the translations, and pages can still be duplicated in bulk.
* **Independent WPML duplicates** — Turns WPML "Duplicate" into a one-off copy that opens in the WordPress editor and is no longer overwritten by the original.
* **WPML media in every language** — Every media file uploaded in one language is copied into the media library of all the others, and existing media missing from a language is filled in. With FileBird, the copies follow the original's folder.
* **Disable Flamingo Addressbook** — Stops Flamingo from saving contact data to its address book; inbound messages are still logged.
* **Disable Comments** — Turns the WordPress comment system off site-wide and removes it from the admin.
* **Flatten SVG on Upload** — Rewrites uploaded SVGs so several of them can be inlined on the same page without their styles and ids colliding.
* **AI Disclosure** — Flags AI-generated and AI-modified images and prints the official EU disclosure label beside them on the front end, with a review queue under Media.

== Changelog ==

= 1.4.16 =
* CF7 HTML Editor is now a core module, maintained in-house instead of tracked against CF7 Coder (which has the same problem upstream). Settings already saved on existing forms are kept
* CF7 HTML Editor: the code editor is back on Contact Form 7 6.2. CF7 6.2 moved its menu under a new Dashboard page, so the edit screen's admin hook changed from toplevel_page_wpcf7 to contact_page_wpcf7 and the editor never loaded. The screen is now matched by page slug, which also fixes "Add Contact Form" on non-English admins, where the hook carries the translated menu title
* CF7 HTML Editor: the editor respects the "Disable syntax highlighting" profile setting and loads WordPress's HTML lint rules. CF7's live configuration check runs again when the editor loses focus
* CF7 HTML Editor: form settings are saved as Contact Form 7 properties, so they survive a duplicate, a REST save and CF7's live configuration check — which until now reset every switch — and are kept on the very first save of a new form, where they were lost
* CF7 HTML Editor: the front-end options now apply whichever way the shortcode names the form (id, hash or title); before, only the hash worked
* CF7 HTML Editor: "Remove Auto tags p and br" turns off CF7's autop for that form instead of stripping tags from the rendered HTML, which also removed the closing tag of CF7's screen-reader status paragraph. Tags written by hand in the template are now kept
* CF7 HTML Editor: redirect URLs with a query string work (the & was written as &#038; into the script); an empty ACF redirect field falls back to the URL set on the form
* CF7 HTML Editor: auto-hidden success messages show again on the next submit; the submit button is re-enabled after spam and other responses too; URL pre-fill handles checkbox groups and values with quotes, and never touches CF7's internal fields
* HTML Template for CF7: new default header and footer — the Bizen layout: header band with the logo, body panel, footer with the company details and a closing note under the frame, stacking on phones. Applies to forms whose template was never saved; saved templates are left as they are
* HTML Template for CF7: new tags for header and footer. [company_*] prints the field of the same name from the ACF options page ("Anagrafica"), so company details live in one place; [site_logo] prints the custom logo scaled into 210×84 (or the site name), [site_domain] the domain without www, [mail_note] a closing line in the form's language that tells the autoresponder apart from the staff notification. Filters: bizen_cf7_email_template_logo, bizen_cf7_email_template_company_tag, bizen_cf7_email_template_mail_note, bizen_cf7_email_template_style
* HTML Template for CF7: the email is one valid HTML document. CF7 had already wrapped the body in its own <!doctype html>, so header + body + footer nested a document inside a table and left no <head> for styles; the template's base styles and phone media query now go in the <head>. The admin preview uses the same document

= 1.4.14 =
* ACFML: Copy once everywhere: `wp bizen-acfml realign` now also stores Expert mode and Copy once in the field group definitions, which until now changed only when a group was saved from the ACF admin. Local JSON files are rewritten in place with a new modified time, keeping their indentation, so ACF offers the sync wherever the database copy is older; groups that live only in the database are updated directly. Neither goes through the ACF save, which from WP-CLI would write clone fields expanded into the JSON

= 1.4.13 =
* ACFML: Copy once everywhere now runs every field group in Expert mode with every field on Copy once, instead of extending the "Each language has its own content" mode. That mode makes ACFML exclude the posts from the Translation Editor, and Translation Management then refused to duplicate them in bulk. Mode and preference are forced when ACF loads and saves the groups, local JSON included, so fields added later start on Copy once too. The new `bizen_acfml_copy_once_field` filter keeps chosen fields out
* ACFML: Copy once everywhere: realigning the WPML settings — on field group save and with `wp bizen-acfml realign` — now covers every group, builds the key patterns from the ACF fields themselves (clone fields included) and no longer touches the underscored field-key twins, which ACFML resets on every save. Keys set to "Don't translate" are realigned too, since every field is now Copy once

= 1.4.12 =
* New module, Independent WPML duplicates: "Duplicate" gives a one-off copy. WPML keeps duplicates bound to the original — it rewrites them on every save and always opens them in its own Translation Editor — so the copy is unlinked right away, the same reset the "Translate independently" button does. `wp bizen-wpml unlink-duplicates` unlinks the ones already on the site, and the `bizen_wpml_independent_duplicates_unlink` filter keeps chosen duplicates synced
* New module, ACFML: Copy once everywhere: the "Each language has its own content" mode sets every field to Copy once, text fields included, and realigns WPML's per-meta-key settings when the field group is saved. Keys left on Copy were still pushing the original's flexible layouts, repeater rows and selects onto the translations. Keys set to "Don't translate" or locked by a wpml-config.xml are left alone. `wp bizen-acfml realign` does the same for existing groups without saving them

= 1.4.10 =
* The modules list is grouped into sections — Form, Media and Admin — instead of one flat table. Still a single page and a single Save: with a module count this size, tabs would hide more than they organise, and splitting the form would let saving one tab switch off the modules on the others
* Modules declare their section through get_category(); a module that does not is listed under "Other" rather than left out
* The "Tools" tab is now "Updates", which is what it holds: the upstream version monitor. The "Modules" tab label is translatable again — it was hard-coded in Italian

= 1.4.9 =
* AI Disclosure: the white EU label is back as a third style, alongside solid black and half transparent — the Commission's white pill with dark lettering, for imagery dark enough to swallow the black one. The badge now carries its variant as a class, and the white one gets a dark hairline instead of a light one
* AI Disclosure: reading provenance metadata on upload is now a switch on the AI Disclosure screen, and it is off by default. AI providers do not mark their images consistently yet, so for now every status starts as a manual decision. Turning it on affects new uploads only and never overwrites a status already on record

= 1.4.8 =
* AI Disclosure: a placement can now pass "none" as the position to leave the label off, for a layout that carries the disclosure another way. It silences one placement rather than the image, and an unrecognised position still falls back to the default corner — on a compliance marker a typo should show the label, not hide it

= 1.4.7 =
* AI Disclosure: the EU label style selector sits below the image grid rather than above it, and still renders when the queue is empty

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
