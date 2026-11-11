<?php
/**
 * Plugin Name: Elinweb Unused Media Finder
 * Plugin URI: https://github.com/ishadmehri/elinweb-unused-media-finder
 * Description: Find unreferenced media, see where each file is used, find duplicate files, and clean up safely with a reversible quarantine.
 * Version: 0.11.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Iman Shadmehri
 * Author URI: https://profiles.wordpress.org/imansh/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: elinweb-unused-media-finder
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('MTRK_VERSION', '0.11.0');
define('MTRK_FILE', __FILE__);

require __DIR__ . '/includes/class-scanner.php';
require __DIR__ . '/includes/class-live.php';
require __DIR__ . '/includes/class-sizes.php';
require __DIR__ . '/includes/class-orphans.php';
require __DIR__ . '/includes/class-cleanup.php';
require __DIR__ . '/includes/class-duplicates.php';
require __DIR__ . '/includes/class-merge.php';
require __DIR__ . '/includes/class-export.php';
require __DIR__ . '/includes/class-admin.php';
require __DIR__ . '/includes/class-page.php';

register_activation_hook(__FILE__, ['ElinwebUnusedMediaFinder\Scanner', 'install']);
add_action('plugins_loaded', ['ElinwebUnusedMediaFinder\Scanner', 'upgrade']);
add_action('plugins_loaded', ['ElinwebUnusedMediaFinder\Cleanup', 'serve'], 1); // requests for quarantined files
add_action('delete_attachment', ['ElinwebUnusedMediaFinder\Cleanup', 'forget']);
add_action('added_post_meta', ['ElinwebUnusedMediaFinder\Scanner', 'file_meta'], 10, 4); // keeps the attachments index current
add_action('updated_post_meta', ['ElinwebUnusedMediaFinder\Scanner', 'file_meta'], 10, 4);

// A deleted attachment or post takes its references with it; saves are re-indexed live.
add_action('deleted_post', ['ElinwebUnusedMediaFinder\Scanner', 'forget']);
ElinwebUnusedMediaFinder\Live::init();

if (defined('WP_CLI') && WP_CLI) {
    require __DIR__ . '/includes/class-cli.php';
    WP_CLI::add_command('elinweb-unused-media-finder', ElinwebUnusedMediaFinder\CLI::class);
}

if (is_admin()) {
    ElinwebUnusedMediaFinder\Admin::init();
}
