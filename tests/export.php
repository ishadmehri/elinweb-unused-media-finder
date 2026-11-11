<?php
/**
 * Download list: the CSV (BOM, quoting of commas, quotes, new lines and Persian text, the formula guard, every chunk,
 * the type filter, the quarantine columns), the TXT file, and the handler's nonce and capability checks.
 * Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/export.php
 */

use ElinwebUnusedMediaFinder\Cleanup;
use ElinwebUnusedMediaFinder\Export;
use ElinwebUnusedMediaFinder\Page;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

$fail = 0;
$made = [];
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . "$what\n";
    $fail += $ok ? 0 : 1;
};
$base = wp_upload_dir()['basedir'] . '/2026/10';
$admin = get_current_user_id();
$titled = function (string $file, string $title) use (&$made): int {
    $id = mtrk_fixture_media($file, $made);
    wp_update_post(['ID' => $id, 'post_title' => wp_slash($title)]);
    return $id;
};
$file = function (string $list, string $format, array $ids, int $chunk = 500): string {
    $out = fopen('php://memory', 'w+');
    Export::write($out, $list, $format, $ids, $chunk);
    rewind($out);
    return (string) stream_get_contents($out);
};
/** @return array<int,array<string,string>> id => row by column name */
$parse = function (string $csv): array {
    $in = fopen('php://memory', 'w+');
    fwrite($in, substr($csv, 3)); // without the BOM
    rewind($in);
    $head = fgetcsv($in, 0, ',', '"', '');
    $rows = [];
    while (($r = fgetcsv($in, 0, ',', '"', '')) !== false) {
        $rows[(int) $r[0]] = array_combine($head, $r);
    }
    return $rows;
};

// Exceptions instead of wp_die() for the handler checks (the refusals; a real download ends with exit).
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', fn() => function () {
    throw new RuntimeException('wp_die');
});
$ajax = function (array $post): array {
    $_POST = $_REQUEST = $post;
    ob_start();
    try {
        Page::ajax_export();
    } catch (RuntimeException $e) {
        // expected
    }
    $json = json_decode((string) ob_get_clean(), true);
    $_POST = $_REQUEST = [];
    return is_array($json) ? $json : [];
};

$fa = $titled('mtrk-ex-fa.jpg', "تصویر, \"آزمایشی\"\nخط دوم");
$eq = $titled('mtrk-ex-eq.jpg', '=HYPERLINK("http://example.com","x")');
$mi = $titled('mtrk-ex-mi.jpg', '-2+3');
$at = $titled('mtrk-ex-at.jpg', '@SUM(A1)');
file_put_contents("$base/mtrk-ex-doc.pdf", '%PDF-1.4');
$doc = wp_insert_attachment(['post_title' => 'mtrk doc', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], "$base/mtrk-ex-doc.pdf");
$made[] = $doc;
$used = mtrk_fixture_media('mtrk-ex-used.jpg', $made);
$made[] = wp_insert_post(['post_title' => 'mtrk export', 'post_status' => 'publish', 'post_content' => '<img src="' . wp_get_attachment_url($used) . '">']);
$subscriber = wp_insert_user(['user_login' => 'mtrk_ex_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$images = [$fa, $eq, $mi, $at];

try {
    mtrk_fixture_scan(false, false);

    // Which items: every page of the list, with the type filter.
    $all = Export::ids('unused');
    $img = Export::ids('unused', 'image');
    $docs = Export::ids('unused', 'document');
    $is(count($all) === Cleanup::unused(1, 20)['total'] && count($all) >= 5, 'ids: every unreferenced item, not one page (' . count($all) . ')');
    $is(!array_diff($images, $img) && !in_array($doc, $img, true) && !in_array($used, $all, true), 'ids: type image has the images, not the PDF nor the used one');
    $is(in_array($doc, $docs, true) && !array_intersect($images, $docs), 'ids: type document has the PDF only');

    // CSV: BOM, header, every chunk, quoting, formula guard.
    $ours = array_merge($images, [$doc]);
    $csv = $file('unused', 'csv', $ours, 2); // chunks of 2: three chunks
    $rows = $parse($csv);
    $is(strpos($csv, "\xEF\xBB\xBF") === 0, 'csv: starts with a UTF-8 BOM');
    $is(strtok(substr($csv, 3), "\n") === 'id,title,url,path,server_path,size_bytes,mime,uploaded,status,edit_url', 'csv: header of the unreferenced list');
    $is(array_keys($rows) === $ours, 'csv: one row per item, from every chunk, in order');
    $is($rows[$fa]['title'] === get_post_field('post_title', $fa, 'raw') && strpos($rows[$fa]['title'], "\n") !== false, 'csv: commas, quotes, a new line and Persian text survive a round trip');
    $is(strpos($csv, '"تصویر, ""آزمایشی""') !== false, 'csv: such a field is quoted, its quotes doubled');
    $is($rows[$eq]['title'] === "'" . get_post_field('post_title', $eq, 'raw') && $rows[$mi]['title'] === "'-2+3" && $rows[$at]['title'] === "'@SUM(A1)", 'csv: = - @ cells get a leading quote');
    $is(Export::cell("\tx") === "'\tx" && Export::cell("\rx") === "'\rx" && Export::cell('+1') === "'+1" && Export::cell('a=b') === 'a=b' && Export::cell(5) === 5, 'csv: tab, CR and + too; other text and numbers untouched');
    $r = $rows[$fa];
    $is($r['url'] === wp_get_attachment_url($fa) && $r['path'] === '2026/10/mtrk-ex-fa.jpg' && $r['server_path'] === wp_normalize_path("$base/mtrk-ex-fa.jpg"), 'csv: url, path relative to uploads, absolute server path');
    $is((int) $r['size_bytes'] === Cleanup::unused(1, PHP_INT_MAX)['items'][$fa] && $r['mime'] === 'image/jpeg' && $r['status'] === 'unreferenced', 'csv: size as the list shows it, mime, status');
    $is((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $r['uploaded']) && strpos($r['edit_url'], "post=$fa&action=edit") !== false, 'csv: ISO upload date, edit link');
    $is($rows[$doc]['mime'] === 'application/pdf', 'csv: the PDF row');

    // TXT: one full URL per line, nothing else.
    $txt = $file('unused', 'txt', $ours, 2);
    $is($txt === implode("\n", array_map('wp_get_attachment_url', $ours)) . "\n", 'txt: one URL per line, every chunk, no BOM');

    // Quarantine: its own columns.
    Cleanup::quarantine([$fa]);
    Cleanup::hit($fa, home_url('/a-page/'), 'Mozilla', true);
    Cleanup::hit($fa, '', 'Mozilla');
    $is(in_array($fa, Export::ids('quarantine'), true) && !in_array($fa, Export::ids('unused'), true), 'quarantine: the item moved from one list to the other');
    $csv = $file('quarantine', 'csv', [$fa]);
    $q = $parse($csv)[$fa];
    $is(strtok(substr($csv, 3), "\n") === 'id,title,url,path,server_path,size_bytes,mime,uploaded,status,edit_url,requests,requested_during_test,last_request,referring_pages', 'quarantine: header with the request columns');
    $is($q['status'] === 'quarantined' && $q['requests'] === '2' && $q['requested_during_test'] === '1' && $q['referring_pages'] === home_url('/a-page/'), 'quarantine: requests, test requests, referring pages');
    $is((bool) preg_match('/^\d{4}-\d\d-\d\dT/', $q['last_request']) && is_file($q['server_path']) && strpos($q['server_path'], Cleanup::qdir()) === 0, 'quarantine: last request ISO, server path where the file is now');

    // The handler refuses before it sends anything.
    $r = $ajax(['list' => 'unused', 'format' => 'csv']);
    $is(($r['success'] ?? null) === false, 'ajax: no nonce, refused');
    wp_set_current_user($subscriber);
    $r = $ajax(['list' => 'unused', 'format' => 'csv', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false, 'ajax: valid nonce without the capability, refused');
    wp_set_current_user($admin);
    $r = $ajax(['list' => 'users', 'format' => 'csv', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false, 'ajax: an unknown list, refused');
} finally {
    wp_set_current_user($admin);
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true);
    }
    foreach (glob("$base/mtrk-ex-*") ?: [] as $f) {
        unlink($f);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subscriber);
}

$is(!get_post($fa) && !glob("$base/mtrk-ex-*") && !get_userdata($subscriber), 'cleanup: fixtures and the test user are gone');

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
