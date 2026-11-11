<?php
/**
 * Where a duplicate copy is used: the first places of every used library copy under its badge (fetched for the whole
 * page in one query), the "Where it is used" dialog from those rows, and "Where?" for an extra file whose URL appears
 * somewhere (searched on demand: found in content, meta, settings, term and user meta; look-alikes not found).
 * Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/duplicates-where.php
 */

use ElinwebUnusedMediaFinder\Admin;
use ElinwebUnusedMediaFinder\Duplicates;
use ElinwebUnusedMediaFinder\Page;
use ElinwebUnusedMediaFinder\Scanner;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

switch_to_locale('en_US'); // the checks read English texts; the site may run in another language

global $wpdb;
$fail = 0;
$made = [];
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . "$what\n";
    $fail += $ok ? 0 : 1;
};
$dir = wp_upload_dir()['basedir'] . '/2026/10';
$url = wp_upload_dir()['baseurl'] . '/2026/10';
$admin = get_current_user_id();

// An AJAX handler ends in wp_die(); make that an exception and keep the JSON it printed.
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', fn() => function () {
    throw new RuntimeException('wp_die');
});
$ajax = function (callable $handler, array $post): array {
    $_POST = $_REQUEST = $post;
    ob_start();
    try {
        $handler();
    } catch (RuntimeException $e) {
        // expected: wp_send_json_*() ends with wp_die()
    }
    // ajax_usage() catches Throwable, so the test's wp_die exception adds a second JSON answer: keep the first.
    $out = (string) ob_get_clean();
    $second = strpos($out, '}{"success"');
    $json = json_decode($second === false ? $out : substr($out, 0, $second + 1), true);
    $_POST = $_REQUEST = [];
    return is_array($json) ? $json : [];
};
$post = function (string $title, string $content = '') use (&$made): int {
    $id = wp_insert_post(['post_title' => $title, 'post_status' => 'publish', 'post_content' => $content]);
    $made[] = $id;
    return $id;
};

// A duplicate group: $a (used in 5 posts) and $b (one featured image); same bytes (fixture media are identical).
$a = mtrk_fixture_media('mtrk-dw-a.jpg', $made);
$b = mtrk_fixture_media('mtrk-dw-b.jpg', $made);
$posts = [];
for ($i = 1; $i <= 5; $i++) {
    $posts[] = $post("mtrk dw post $i", '<img src="' . $url . '/mtrk-dw-a.jpg">');
}
$featured = $post('mtrk dw featured');
update_post_meta($featured, '_thumbnail_id', $b);

// An extra file (a stray copy) whose URL appears in places the scan reads, and look-alikes that must not be found.
copy("$dir/mtrk-dw-a.jpg", "$dir/mtrk-dw-x.jpg");
copy("$dir/mtrk-dw-a.jpg", "$dir/mtrk-dw-lonely.jpg"); // an extra file nothing names
$rel = '2026/10/mtrk-dw-x.jpg';
$in_content = $post('mtrk dw x content', '<p><img src="' . $url . '/mtrk-dw-x.jpg"></p>');
$in_meta = $post('mtrk dw x meta');
update_post_meta($in_meta, 'mtrk_dw_json', wp_slash(wp_json_encode(['bg' => ['url' => "$url/mtrk-dw-x.jpg"]])));
$lookalike = $post('mtrk dw lookalike', '<img src="' . $url . '/mtrk-dw-x10.jpg"> <img src="' . wp_upload_dir()['baseurl'] . '/2025/01/mtrk-dw-x.jpg"> mtrk-dw-x.jpg');
update_option('mtrkt_dw_option', ['slides' => [['image' => "$url/mtrk-dw-x.jpg"]]], false);
$term = wp_insert_term('mtrk dw term ' . wp_generate_password(4, false), 'category')['term_id'];
update_term_meta($term, 'mtrk_dw_banner', "$url/mtrk-dw-x.jpg");
update_user_meta($admin, 'mtrk_dw_avatar', "$url/mtrk-dw-x.jpg");

try {
    mtrk_fixture_scan(false, false);
    $r = Duplicates::step(true, true);
    while (!$r['done']) {
        $r = Duplicates::step();
    }

    // refs_for(): one query for many ids, at most $per rows each.
    $before = $wpdb->num_queries;
    $refs = Scanner::refs_for([$a, $b, 0, $a], 2);
    $is($wpdb->num_queries - $before === 1, 'refs_for: one query for the whole page');
    $is(count($refs[$a] ?? []) === 2 && count($refs[$b] ?? []) === 1, 'refs_for: limited per id (2 of 5), all of a small one');
    $is(($refs[$b][0]['source_key'] ?? '') === '_thumbnail_id' && (int) $refs[$b][0]['source_id'] === $featured, 'refs_for: rows shaped like refs()');
    $is(Scanner::refs_for([]) === [] && Scanner::refs_for([0, -1]) === [], 'refs_for: no ids, no query result');

    // The rows of the Duplicates tab.
    $_GET = ['page' => 'elinweb-unused-media-finder', 'tab' => 'duplicates', 'per_page' => '500'];
    ob_start();
    Page::render();
    $html = (string) ob_get_clean();
    $_GET = [];
    $row = function (int $id) use ($html): string { // the table row of one attachment
        preg_match('~<tr>(?:(?!</tr>).)*?value="' . $id . '"(?:(?!</tr>).)*</tr>~s', $html, $m);
        return $m[0] ?? '';
    };
    $ra = $row($a);
    $rb = $row($b);
    $is(substr_count($ra, '<li>') === 3 && strpos($ra, 'mtrk dw post 1') !== false && strpos($ra, 'mtrk dw post 4') === false, 'rows: a used copy lists its first 3 places with their titles');
    $is((bool) preg_match('~class="button-link mtrk-open" data-id="' . $a . '"[^>]*>\+2 more<~', $ra), 'rows: "+2 more" opens the dialog of that copy');
    $is(strpos($ra, 'post.php?post=' . $posts[0] . '&amp;action=edit') !== false, 'rows: titles link to the edit screen');
    $is(substr_count($rb, '<li>') === 1 && strpos($rb, 'mtrk dw featured') !== false && strpos($rb, 'mtrk-open" data-id="' . $b . '"') !== false, 'rows: a copy used once shows that place and a "Where it is used" link');
    $is((bool) preg_match('~class="mtrk-keep" name="mtrk-keep-\d+" value="' . $a . '"\s+checked~', $html), 'rows: the most used copy is the default "keep"');
    $is(strpos($html, 'class="button mtrk-merge"') !== false, 'rows: the group has the replace button');
    $is((bool) preg_match('~mtrk-where" data-path="' . preg_quote($rel, '~') . '"~', $html), 'rows: a kept extra file has a "Where?" action');
    $is(strpos($html, 'mtrk-where" data-path="2026/10/mtrk-dw-lonely.jpg"') === false, 'rows: an extra file nothing names has none');

    // The dialog those rows open.
    $r = $ajax([Admin::class, 'ajax_usage'], ['id' => (string) $a, '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? false) && substr_count($r['data']['html'], '<li>') === 5, 'dialog: "Where it is used" lists all 5 places');

    // Locating the extra file's references.
    $found = Scanner::locate($rel);
    $places = array_map(fn($r) => $r['source_type'] . ':' . $r['source_id'] . ':' . $r['source_key'], $found['refs']);
    sort($places);
    $want = ["option:0:mtrkt_dw_option", "post:$in_content:", "post:$in_meta:mtrk_dw_json", "term:$term:mtrk_dw_banner", "user:$admin:mtrk_dw_avatar"];
    sort($want);
    $is($found['complete'], 'locate: the search finished');
    $is($places === $want, 'locate: content, JSON meta, serialized option, term meta and user meta found');
    $is(!in_array("post:$lookalike:", $places, true), 'locate: look-alikes (x10, another folder, a bare name) not found');
    // A scan of plugin tables never reads the plugin's own tables (the extra files list names every stray path).
    mtrk_fixture_scan(false, true);
    $with_tables = array_map(fn($r) => $r['source_type'], Scanner::locate($rel)['refs']);
    $is(!in_array('table', $with_tables, true) && count($with_tables) === 5, 'locate: with plugin tables, the plugin\'s own tables are not read');
    $is(!isset(Scanner::referenced(['2026/10/mtrk-dw-lonely.jpg'])[md5('2026/10/mtrk-dw-lonely')]), 'scan: the extra files list does not make a stray file "referenced"');
    $none = Scanner::locate('2026/10/mtrk-dw-lonely.jpg');
    $is($none['refs'] === [] && $none['complete'], 'locate: a file nothing names: nothing found');
    $is(count(Scanner::locate($rel, 2)['refs']) === 2 && !Scanner::locate($rel, 2)['complete'], 'locate: stops at the limit and says so');

    // "Where?" handler: nonce, capability, only real extra files.
    $r = $ajax([Page::class, 'ajax_where'], ['path' => $rel]);
    $is(($r['success'] ?? null) === false, 'where: no nonce, refused');
    $subscriber = wp_insert_user(['user_login' => 'mtrk_dw_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
    wp_set_current_user($subscriber);
    $r = $ajax([Page::class, 'ajax_where'], ['path' => $rel, '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false, 'where: without the capability, refused');
    wp_set_current_user($admin);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subscriber);
    $r = $ajax([Page::class, 'ajax_where'], ['path' => '2026/10/mtrk-dw-a.jpg', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false, 'where: a library file is not an extra file, refused');
    $r = $ajax([Page::class, 'ajax_where'], ['path' => '../../wp-config.php', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false, 'where: a path outside uploads, refused');
    $r = $ajax([Page::class, 'ajax_where'], ['path' => $rel, '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? false) && strpos($r['data']['html'], 'mtrk dw x content') !== false && strpos($r['data']['html'], 'mtrk_dw_banner') !== false, 'where: lists the places, rendered like the dialog');
    $r = $ajax([Page::class, 'ajax_where'], ['path' => '2026/10/mtrk-dw-lonely.jpg', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? false) && strpos($r['data']['html'], 'could not locate it') !== false, 'where: says so honestly when it finds nothing');
} finally {
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true);
    }
    foreach (glob("$dir/mtrk-dw-*") ?: [] as $f) {
        unlink($f);
    }
    delete_option('mtrkt_dw_option');
    wp_delete_term($term, 'category');
    delete_user_meta($admin, 'mtrk_dw_avatar');
    $r = Duplicates::step(true, false); // a check without extra files: drops their table and leaves a consistent state
    while (!$r['done']) {
        $r = Duplicates::step();
    }
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
