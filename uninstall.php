<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/class-scanner.php';
require_once __DIR__ . '/includes/class-cleanup.php';

// A function keeps the temporary variables out of the global scope.
(function () {
    global $wpdb;

    // Quarantined files go back first: uninstalling must not leave media broken.
    ElinwebUnusedMediaFinder\Cleanup::restore(array_keys(ElinwebUnusedMediaFinder\Cleanup::quarantined()));
    // The closed folder is removed only when nothing is left in it. A file that could not be moved back (its
    // original path is taken, or the folder is not writable) stays there: deleting it would destroy the only copy.
    $qdir = get_option('mtrk_quarantine_dir');
    if ($qdir) {
        $dir = wp_upload_dir(null, false)['basedir'] . '/' . $qdir;
        if (is_dir($dir)) {
            $guards = ['index.php', '.htaccess']; // the two files the plugin put there itself
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            $kept = false;
            foreach ($items as $item) {
                if (!$item->isDir() && !in_array($item->getFilename(), $guards, true)) {
                    $kept = true;
                    break;
                }
            }
            if (!$kept) {
                foreach ($items as $item) {
                    if ($item->isDir()) {
                        @rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty quarantine folder; WP_Filesystem may need credentials here
                    } else {
                        wp_delete_file($item->getPathname());
                    }
                }
                @rmdir($dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- same folder, now empty
            }
        }
    }

    // The merge undo log goes too: a replaced copy that was quarantined has just been restored above, so it is not lost.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dropping the plugin's own tables on uninstall
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mtrk_refs, {$wpdb->prefix}mtrk_paths, {$wpdb->prefix}mtrk_files, {$wpdb->prefix}mtrk_hashes, {$wpdb->prefix}mtrk_xhashes, {$wpdb->prefix}mtrk_merges, {$wpdb->prefix}mtrk_merge_rows");
    foreach (['mtrk_job', 'mtrk_last_scan', 'mtrk_files', 'mtrk_lock', 'mtrk_db', 'mtrk_files_ready', 'mtrk_dups_at', 'mtrk_dups_run', 'mtrk_dups_x', 'mtrk_quarantine', 'mtrk_quarantine_dir', 'mtrk_q_test'] as $option) {
        delete_option($option);
    }
    delete_post_meta_by_key('_mtrk_quarantine');
    delete_post_meta_by_key('_mtrk_keep');
    delete_metadata('user', 0, 'mtrk_per_page', '', true);
    delete_metadata('user', 0, 'mtrk_view', '', true);
})();
