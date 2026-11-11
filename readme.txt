=== Elinweb Unused Media Finder ===
Contributors: imansh
Tags: media library, unused media, duplicate images, cleanup, storage
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find unreferenced media, see where each file is used, find duplicate files, and clean up safely with a reversible quarantine.

== Description ==

Elinweb Unused Media Finder scans your site and tells you which files in the Media Library are not used anywhere, where the others are used, and which files are exact duplicates. Then it helps you remove them with less risk.

A permanent delete removes the files from the server, so the plugin is built around caution: quarantine first (reversible), delete later, and refuse to continue when a scan looks wrong. No scan can see every use of a file, so keep a backup and quarantine before you delete.

**What it does**

* **Scan.** The database (posts, meta, options, terms, users, comments, plugin tables) and, if you choose, the code of the active theme and plugins plus CSS/JS inside uploads.
* **Recognises how media is referenced.** URLs (with size suffixes, `-scaled`, WebP, URL-encoded, `\u`-escaped, HTML entities, Unicode-normalised), ids (featured image, galleries, blocks, shortcodes), Elementor (loops, dynamic tags, V4), ACF / Secure Custom Fields, Meta Box, Pods, JetEngine, WooCommerce, Gravity Forms, Fluent Forms, WPForms, Contact Form 7 and Smart Slider.
* **Live updates.** After one full scan, saves in the admin are recorded without scanning again.
* **Media Library integration.** A "Usage" column and filter in list and grid mode, and a dialog showing every place a file is used.
* **Unreferenced list.** Card or list view, 20 to 500 items per page, preview, filter by type, sort by size or date.
* **Duplicates.** Files with identical content that were uploaded more than once. Sizes are compared first and only same-size files are hashed (MD5), so large libraries stay light. Attachments that share one file (media translations) are not duplicates, and only unreferenced copies can be selected. An option adds the extra files on disk, to catch stray copies of library files.
* **Quarantine.** Moves a file (all sizes and WebP copies) out of its public path into a closed folder, but still answers requests for the old URL and logs them, with the pages they came from. If nothing asks for it for a few weeks, delete it; if something does, restore it with one click, or restore every requested file at once.
* **404 test.** For 1 hour to 3 days, quarantined files answer exactly like deleted files (404) while every request is still logged. Crawl your site meanwhile (Screaming Frog, a broken-link checker, or just browse it), restore what was requested, and delete the rest without waiting weeks. It ends by itself.
* **Download lists.** The Unreferenced (as filtered), Quarantine and Extra files lists as a CSV file (opens in Excel or Google Sheets) or a TXT file with one URL per line, with URL, path, server path and size of every item.
* **Extra files.** Leftover sizes and files on disk that no media item owns, with a preview, the full server path, and a ZIP download of the selection.
* **Unregistered sizes.** Files generated for image sizes that no theme or plugin registers any more.
* **Safety.** A scan that reports a featured image as unreferenced is marked untrustworthy and quarantine and delete are refused. Attachments sharing one file move together.
* **WP-CLI.** `wp elinweb-unused-media-finder scan|status|unused|usage|quarantine|restore|delete|keep|quarantine-test`

**What it cannot see**

Use outside your site (newsletters, social networks, other sites hotlinking a file) cannot be detected. That is why quarantine exists. Always take a backup before deleting.

**Languages**

English and Persian (fa_IR).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it.
3. Go to **Media → Unused Media Finder → Scan** and run a "Database + source code" scan.
4. Review the **Unreferenced** tab. Quarantine, wait, then delete.

== Frequently Asked Questions ==

= Will it delete anything on its own? =

No. Nothing is quarantined or deleted unless you select items and press the button.

= Is quarantine really safe? =

A quarantined file is still served for its old URL, so pages that still use it keep working, and the request is logged so you can see it. One caveat: if your server answers requests for missing image files itself (an nginx or LiteSpeed rule, or a CDN), WordPress never sees them, the file returns a 404 and nothing is logged. The plugin tests this and warns you on the Quarantine tab.

= What does the 404 test do to my visitors? =

While it runs, visitors and search engines get a 404 for quarantined files, exactly as if they were deleted. That is the point: you see which pages still ask for them. Keep it short, avoid peak hours, and purge page and CDN caches before you crawl. Your own admin screens still see the files.

= What data does it store? =

It has no settings page. It creates up to five tables (`mtrk_refs`, `mtrk_paths`, `mtrk_files`, `mtrk_hashes`, and `mtrk_xhashes` while a duplicates check includes extra files), a few options prefixed `mtrk_`, the post meta `_mtrk_quarantine` and `_mtrk_keep`, and the user meta `mtrk_per_page` and `mtrk_view`. For quarantined files it logs the time, the last few referring URLs and the kind of each request (from the user agent), locally in the database. Nothing is sent to any other server.

= What happens when I uninstall it? =

Quarantined files are moved back to their original place first, then the tables, options and meta above are removed. A file that cannot go back (its original path is taken again, or the folder is not writable) is not deleted: it stays in the `mtrk-quarantine-…` folder inside the uploads folder for you to handle. Deactivating keeps everything.

= Does it work on multisite? =

It has only been tested on single sites. Uninstall cleanup does not handle every site of a network yet.

= A file is listed as used but I know it is not. =

Open "Where it is used". Tables of plugins you removed long ago and caches (for example Yoast's indexable tables) can mention a file without using it. Logs and well-known caches are skipped; if you find another one, please report it.

== Screenshots ==

1. Unreferenced media: preview, keep, quarantine or delete, in card or list view.
2. Duplicates: files with identical content, with the option to include extra files.
3. Extra files: files on disk that no media item owns, with full path, preview and ZIP download.
4. Unregistered sizes: thumbnails of image sizes that nothing registers any more.
5. Scan: a full scan of the database, plugin tables and source code, with progress.

== Changelog ==

= 0.10.0 =
* Quarantine: a timed 404 test (1 hour to 3 days). While it runs, quarantined files answer 404 like deleted files and every request is logged as a test request, so a crawl of the site shows which files are still used. It ends by itself; WP-CLI: `wp elinweb-unused-media-finder quarantine-test start|stop|status`.
* Quarantine: the last five pages that requested a file are shown, and one button restores every file that was requested.
* Download the Unreferenced (with its type filter), Quarantine and Extra files lists as CSV (UTF-8, opens in Excel) or TXT (one URL per line). Every item is included, not only the page shown.

= 0.9.4 =
* The per-page and view choices on the plugin's screen are only remembered when they come from its own filter form (nonce plus capability check); a link without the nonce shows the list as asked but saves nothing.
* Every AJAX handler checks its nonce and the user's capability itself.
* Page links of the lists no longer carry the screen nonce or repeat the last action's notice.

= 0.9.3 =
* Renamed from "Elin Media Tracker" to "Elinweb Unused Media Finder". The WP-CLI command is now `wp elinweb-unused-media-finder`.
* The "Where it is used" dialog escapes every label and title it shows.
* Uninstall never deletes a quarantined file it could not move back; restore keeps such a file in quarantine.
* Readme: no absolute claims about deleting or about pages never breaking.

= 0.9.2 =
* Screenshots added to the plugin page.

= 0.9.1 =
* Passes the Plugin Check plugin without errors: added missing translator comments, sanitized the request headers read by the quarantine, and documented why the plugin's own SQL is built the way it is.
* Fixed: on PHP older than 8.2 with a single-byte locale (common on Windows), non-Latin file names such as Persian ones were corrupted while indexing, so the scan refused to start.
* The bundled Persian translation now loads through WordPress itself (6.5 and later); the manual load call is gone.

= 0.9.0 =
* Extra files: "Download selected as ZIP" (up to 200 MB per ZIP; only files the plugin lists as extra can be included).

= 0.8.1 =
* Fixed: the "Where it is used" dialog failed with "Could not reach the server" when a media item was referenced by a term, user or comment without an edit screen. If something else breaks there, the dialog now shows the real error.

= 0.8.0 =
* Duplicates: an option to also compare extra files, so stray copies of library files (FTP uploads, imports) show up in their group. Extra files can be previewed and deleted there.
* The "Outside the library" label now reads "No media item owns this file".

= 0.7.3 =
* Extra files and Unregistered sizes keep their last result while you move between tabs of the plugin page (per browser tab, until the next scan), with the time it was checked.

= 0.7.2 =
* Extra files: a modal preview (image, audio, video) for every file, with the full server path and an "Open in a new tab" link.

= 0.7.1 =
* Extra files show the full server path and an "Open in a new tab" link.
* Cover art listed in audio/video metadata is no longer reported as an extra file.

= 0.7.0 =
* The interface source language is now English, with a bundled Persian (fa_IR) translation.
* Prepared for the WordPress.org directory: readme.txt, plugin header, translation template.

= 0.6.1 =
* Yoast indexable tables and the Titan scanner option no longer count as usage.

= 0.6.0 =
* New Duplicates tab.
* List view and a per-page choice (20, 40, 100, 200, 500) for the unreferenced list.
* Release script.

= 0.5.1 =
* Aparat poster ids are recognised.
* A scan that loses featured images is marked untrustworthy and deletion is refused.

= 0.5.0 =
* An attachments index instead of an in-memory map, so scans use little memory on large libraries.

== Upgrade Notice ==

= 0.7.0 =
The interface is now English unless your site language is Persian.

= 0.6.1 =
Run a new full scan after updating; earlier results still count Yoast cache tables as usage.
