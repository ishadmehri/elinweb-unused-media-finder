# Elinweb Unused Media Finder

[فارسی](README.fa.md)

A WordPress plugin that finds unreferenced media, shows where each file is used, finds duplicate files, and cleans up safely with a reversible quarantine.

> On a live site always **quarantine** first, never delete straight away, and take a backup before any deletion.

## What it does
- **Scan:** the database (posts, meta, options, terms, users, comments, plugin tables) and, optionally, the code of the active theme and plugins plus CSS/JS inside uploads.
- **Detection:** URLs (with sizes, `-scaled`, WebP, URL-encoded, `\u` and HTML entities, NFD), ids (featured image, galleries, blocks, shortcodes), Elementor (loops, dynamic tags, V4), ACF/SCF, Meta Box, Pods, JetEngine, WooCommerce, Gravity Forms, Fluent Forms, WPForms, CF7, Smart Slider.
- **Live updates:** after a full scan, saves in the admin are recorded without scanning again.
- **Media Library:** a "Usage" column and filter in list and grid mode, and a dialog with every place a file is used.
- **Card or list view** and a per-page choice (20, 40, 100, 200, 500) on the unreferenced tab; the choice is saved per user.
- **Duplicates:** files with identical content uploaded more than once. Sizes are compared first, and only same-size files get an MD5, so it stays light on large libraries. Attachments sharing one file (media translations) are not duplicates, and only unreferenced copies can be selected.
- **Cleanup:** unreferenced list with preview, quarantine with request logging (and the pages the requests came from), restore (one by one or every requested file at once), keep, permanent delete; extra files on disk; unregistered image sizes.
- **404 test:** for 1 hour to 3 days, quarantined files answer 404 like deleted files and every request is logged as a test request. Crawl the site meanwhile, restore what was requested, delete the rest. It ends by itself.
- **Download lists:** the Unreferenced (as filtered), Quarantine and Extra files lists as CSV (UTF-8 with BOM, opens in Excel) or TXT (one URL per line), every item, not only the page shown.
- **Safety:** attachments that share a file move together; a scan that reports a featured image as unreferenced is marked untrustworthy and quarantine and delete are refused.
- **WP-CLI:** `wp elinweb-unused-media-finder scan|status|unused|usage|quarantine|restore|delete|keep|quarantine-test`

## Data and uninstall
No settings page. The plugin creates four tables (`mtrk_refs`, `mtrk_paths`, `mtrk_files`, `mtrk_hashes`), several `mtrk_` options, the post meta `_mtrk_quarantine` and `_mtrk_keep`, and the user meta `mtrk_per_page` and `mtrk_view`. Uninstalling (not just deactivating) removes all of it; quarantined files are restored first. Request logs of quarantined files (time, the last few referring pages, the kind of request) stay in your own database.

## Requirements
WordPress 6.0+ and PHP 7.4+ (only run on PHP 8.2 so far).

## Languages
English (source) and Persian (`languages/elinweb-unused-media-finder-fa_IR.po`). To change a translation, edit the `.po` file and compile it:

```bash
wp i18n make-mo languages
```

## Tests
Run them on a development site, not a live one (they clear the scan result):

```bash
wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/check.php
```

The others: `live`, `cleanup`, `sizes`, `orphans`, `names`, `integrations` (tests the installed plugins and fails for any that is missing), `duplicates`, `uninstall`, `screen`, `quarantine-test` (also sends real HTTP requests to the site with `curl`), `export`.

## Releasing
`scripts/release.ps1` reads the version from the plugin header, builds `dist/elinweb-unused-media-finder-<version>.zip` from `HEAD`, and with `-Publish` tags it and creates the GitHub release. The WordPress.org `readme.txt` and header version must be bumped together.

## Roadmap
Open work and ideas are in [ROADMAP.md](ROADMAP.md).

## License
GPL-2.0-or-later
