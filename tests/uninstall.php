<?php
/**
 * Uninstall: quarantined files go back, the closed folder goes away, and a file that cannot go back is never destroyed.
 * Runs the real uninstall.php, so it drops the plugin's tables and options; they are recreated at the end.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/uninstall.php
 */

use ElinwebUnusedMediaFinder\Cleanup;
use ElinwebUnusedMediaFinder\Scanner;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

$fail = 0;
$made = [];
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . "$what\n";
    $fail += $ok ? 0 : 1;
};
$base = wp_upload_dir()['basedir'];
$uninstall = function (): void {
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', 'elinweb-unused-media-finder/elinweb-unused-media-finder.php');
    }
    require dirname(__DIR__) . '/uninstall.php';
};
$quarantine = function (array $ids): string { // quarantines and returns the closed folder
    mtrk_fixture_scan(false, false);
    Cleanup::quarantine($ids);
    return wp_upload_dir()['basedir'] . '/' . get_option('mtrk_quarantine_dir');
};

$taken = mtrk_fixture_media('mtrk-un-taken.jpg', $made);
$free = mtrk_fixture_media('mtrk-un-free.jpg', $made);
$plain = mtrk_fixture_media('mtrk-un-plain.jpg', $made);
file_put_contents("$base/2026/10/mtrk-un-taken.jpg", 'original');
file_put_contents("$base/2026/10/mtrk-un-free.jpg", 'free');
file_put_contents("$base/2026/10/mtrk-un-plain.jpg", 'plain');
$leftover = null;

try {
    // 1. One file's original path is taken again, so it cannot go back.
    $dir = $quarantine([$taken, $free]);
    $is(!file_exists("$base/2026/10/mtrk-un-taken.jpg") && is_dir($dir), 'setup: both files are in quarantine');
    file_put_contents("$base/2026/10/mtrk-un-taken.jpg", 'uploaded since');
    $uninstall();
    $is(file_get_contents("$base/2026/10/mtrk-un-free.jpg") === 'free', 'uninstall: a file whose path is free goes back');
    $is(file_get_contents("$base/2026/10/mtrk-un-taken.jpg") === 'uploaded since', 'uninstall: a file uploaded since is not overwritten');
    $is(file_exists("$dir/2026/10/mtrk-un-taken.jpg") && file_get_contents("$dir/2026/10/mtrk-un-taken.jpg") === 'original', 'uninstall: the file that could not go back is NOT deleted');
    $leftover = $dir;

    // 2. Everything goes back: the folder is removed.
    Scanner::install();
    $dir = $quarantine([$plain]);
    $is(is_dir($dir), 'setup: second round is in quarantine');
    Cleanup::start_test(1);
    $uninstall();
    $is(get_option(Cleanup::TEST) === false, 'uninstall: the 404 test option is removed');
    $is(file_get_contents("$base/2026/10/mtrk-un-plain.jpg") === 'plain', 'uninstall: the file is back');
    $is(!is_dir($dir), 'uninstall: the empty closed folder is removed');
} finally {
    foreach ($made as $id) {
        wp_delete_post($id, true);
    }
    foreach ([$leftover, $dir ?? null] as $d) {
        if ($d && is_dir($d)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($d);
        }
    }
    foreach (['taken', 'free', 'plain'] as $n) {
        @unlink("$base/2026/10/mtrk-un-$n.jpg");
    }
    Scanner::install();
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
