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

== Changelog ==

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
