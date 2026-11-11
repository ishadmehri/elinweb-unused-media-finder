<?php
/**
 * Merge in a duplicate group: the dry run changes nothing; each supported reference is rewritten to exactly the
 * expected value and look-alikes stay; a copy with an unsupported reference is refused and nothing changes; the kept
 * copy is untouched; the duplicate is quarantined (deleted only when asked); undo restores the old values byte for
 * byte and skips a value changed since; a second run is harmless; the handler checks nonce and capability; an extra
 * file is merged by URL; a revision is kept; real HTTP requests for the old URL. Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/merge.php
 */

use ElinwebUnusedMediaFinder\Cleanup;
use ElinwebUnusedMediaFinder\Merge;
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
$base = wp_upload_dir()['baseurl'];
$admin = get_current_user_id();
$saved_test = get_option(Cleanup::TEST);

add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', fn() => function () {
    throw new RuntimeException('wp_die');
});
$ajax = function (array $post): array {
    $_POST = $_REQUEST = $post;
    ob_start();
    try {
        Page::ajax_merge();
    } catch (RuntimeException $e) {
        // expected: wp_send_json_*() ends with wp_die()
    }
    $json = json_decode((string) ob_get_clean(), true);
    $_POST = $_REQUEST = [];
    return is_array($json) ? $json : [];
};
$curl = function (string $url, string $referer = ''): array {
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $head = (string) shell_exec('curl --noproxy "*" -s -o ' . $null . ' -D - ' . ($referer ? '-e ' . escapeshellarg($referer) . ' ' : '') . escapeshellarg($url));
    preg_match('#^HTTP/\S+ (\d+)#', $head, $status);
    preg_match('/^X-Mtrk:\s*(\S+)/mi', $head, $mark);
    return [(int) ($status[1] ?? 0), $mark[1] ?? ''];
};
$run = function (int $keep, $dup, bool $delete = false): array {
    $r = Merge::start($keep, $dup, $delete);
    while (empty($r['error']) && empty($r['done'])) {
        $r = Merge::step((int) $r['merge']);
    }
    return $r;
};
$undo = function (int $merge): array {
    $r = Merge::undo($merge);
    while (empty($r['error']) && empty($r['done'])) {
        $r = Merge::undo((int) $r['merge']);
    }
    return $r;
};
$post = function (array $args) use (&$made): int {
    $id = wp_insert_post(wp_slash($args + ['post_status' => 'publish', 'post_title' => 'mtrk merge']));
    $made[] = $id;
    return $id;
};
$sizes = function (int $id, string $name, array $sizes) {
    $meta = ['file' => "2026/10/$name.jpg", 'width' => 2, 'height' => 2, 'sizes' => []];
    foreach ($sizes as $size => $file) {
        $meta['sizes'][$size] = ['file' => $file, 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg'];
    }
    update_post_meta($id, '_wp_attachment_metadata', $meta);
};

// Byte-identical copies (fixture media are the same 2×2 JPEG). K lacks D's "large" size; O is only a look-alike.
$k = mtrk_fixture_media('mtrk-mg-k.jpg', $made);
$d = mtrk_fixture_media('mtrk-mg-d.jpg', $made);
$u = mtrk_fixture_media('mtrk-mg-u.jpg', $made);   // has an unsupported reference
$e = mtrk_fixture_media('mtrk-mg-e.jpg', $made);   // merged with --delete
$o = mtrk_fixture_media('mtrk-mg-dx.jpg', $made);  // similar name, not a copy in this merge
$sizes($k, 'mtrk-mg-k', ['thumbnail' => 'mtrk-mg-k-150x150.jpg', 'medium' => 'mtrk-mg-k-300x200.jpg']);
$sizes($d, 'mtrk-mg-d', ['thumbnail' => 'mtrk-mg-d-150x150.jpg', 'large' => 'mtrk-mg-d-1024x768.jpg']);
copy("$dir/mtrk-mg-k.jpg", "$dir/mtrk-mg-x.jpg"); // an extra file, same bytes
$file = fn(string $n) => "$base/2026/10/$n";

// Templates: {D} becomes K where it must change, {d} stays D (look-alikes); {Dt}/{Dl}/{Dm} are D's thumbnail, large, main.
$fill = function (string $tpl, bool $after) use ($d, $k, $o, $file): string {
    return strtr($tpl, [
        '{D}' => $after ? $k : $d, '{d}' => $d, '{O}' => $o,
        '{Dm}' => $file($after ? 'mtrk-mg-k.jpg' : 'mtrk-mg-d.jpg'),
        '{Dt}' => $file($after ? 'mtrk-mg-k-150x150.jpg' : 'mtrk-mg-d-150x150.jpg'),
        '{Dl}' => $file($after ? 'mtrk-mg-k.jpg' : 'mtrk-mg-d-1024x768.jpg'), // K has no "large": its full size
        '{Om}' => $file('mtrk-mg-dx.jpg'),
    ]);
};
$content_tpl = "<!-- wp:image {\"id\":{D},\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"{Dl}\" alt=\"\" class=\"wp-image-{D}\"/></figure>\n<!-- /wp:image -->\n"
    . "<!-- wp:cover {\"url\":\"{Dm}\",\"id\":{D}} -->\n<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-{D}\" src=\"{Dm}\"/></div>\n<!-- /wp:cover -->\n"
    . "<p>Call 1{d}2, ref {d}5, wp-image-{d}9 is not ours. <img src=\"{Om}\" class=\"wp-image-{O}\"></p>\n"
    . "[gallery ids=\"{O},{D},1{d}\"]\n<a href=\"{Dt}?v=2\">thumb</a>";
$excerpt_tpl = 'See {Dt}';
$p1 = $post(['post_content' => $fill($content_tpl, false), 'post_excerpt' => $fill($excerpt_tpl, false)]);
$p2 = $post(['post_content' => 'plain']);
update_post_meta($p2, '_thumbnail_id', $d);
update_post_meta($p2, '_product_image_gallery', "$o,$d,1$d");
update_post_meta($p2, '_price', "$d"); // the number D in a field that is not media: not a reference, stays
$ser_old = ['img' => $file('mtrk-mg-d-150x150.jpg'), 'note' => 'ünïcode ' . $file('mtrk-mg-dx.jpg'), 'n' => 1.5, 'list' => [$d, "x$d"]];
$ser_new = ['img' => $file('mtrk-mg-k-150x150.jpg')] + $ser_old;
update_post_meta($p2, 'mtrk_mg_ser', $ser_old);
$el = fn(bool $after) => [[
    'id' => 'a1b2c3', 'elType' => 'widget', 'widgetType' => 'image', 'elements' => [],
    'settings' => [
        'image' => ['url' => $after ? $file('mtrk-mg-k.jpg') : $file('mtrk-mg-d.jpg'), 'id' => $after ? $k : $d, 'size' => '', 'alt' => '', 'source' => 'library'],
        'gallery' => [['id' => $after ? $k : $d, 'url' => $after ? $file('mtrk-mg-k-150x150.jpg') : $file('mtrk-mg-d-150x150.jpg')], ['id' => $o, 'url' => $file('mtrk-mg-dx.jpg')]],
        'count' => (int) "{$d}5", 'empty' => new stdClass(), 'ratio' => 0.5,
    ],
]];
$p3 = $post(['post_content' => '']);
update_post_meta($p3, '_elementor_data', wp_slash(wp_json_encode($el(false))));
update_post_meta($p3, '_elementor_css', ['status' => 'file', 'css' => 'background:url(' . $file('mtrk-mg-d.jpg') . ')']);
$term = wp_insert_term('mtrk merge term ' . wp_generate_password(4, false), 'product_cat');
$term = is_wp_error($term) ? wp_insert_term('mtrk merge term ' . wp_generate_password(4, false), 'category')['term_id'] : $term['term_id'];
update_term_meta($term, 'thumbnail_id', $d);
update_user_meta($admin, 'mtrk_mg_avatar', $file('mtrk-mg-d.jpg'));
update_option('mtrkt_mg_widget', ['title' => 'w', 'attachment_id' => $d, 'url' => $file('mtrk-mg-d.jpg'), 'other_id' => $d], false);
update_option('theme_mods_mtrkt', ['custom_logo' => $d, 'header' => 'x'], false);
$p4 = $post(['post_content' => '<img src="' . $file('mtrk-mg-u.jpg') . '">']); // U: fine here...
$comment = wp_insert_comment(['comment_post_ID' => $p4, 'comment_content' => 'look ' . $file('mtrk-mg-u.jpg'), 'comment_approved' => 1]); // ...but not in a comment
$p5 = $post(['post_content' => '<img src="' . $file('mtrk-mg-x.jpg') . '"> and <img src="' . $file('mtrk-mg-x10.jpg') . '">']);
$p6 = $post(['post_content' => '<img src="' . $file('mtrk-mg-e.jpg') . '" class="wp-image-' . $e . '">']);
$css = wp_upload_dir()['basedir'] . "/elementor/css/post-$p3.css";
wp_mkdir_p(dirname($css));
file_put_contents($css, '.x{background:url(' . $file('mtrk-mg-d.jpg') . ')}');

// Everything a merge could touch, raw from the database.
$snapshot = function () use ($wpdb, &$made, $term, $admin, $comment, $k): array {
    $ids = implode(',', array_map('intval', $made));
    return [
        'posts' => $wpdb->get_results("SELECT ID, post_content, post_excerpt FROM $wpdb->posts WHERE ID IN ($ids) ORDER BY ID", ARRAY_A),
        'postmeta' => $wpdb->get_results("SELECT meta_id, meta_key, meta_value FROM $wpdb->postmeta WHERE post_id IN ($ids) AND meta_key NOT IN ('_elementor_css', '_edit_lock', '_mtrk_quarantine') ORDER BY meta_id", ARRAY_A),
        'termmeta' => $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM $wpdb->termmeta WHERE term_id = %d ORDER BY meta_id", $term), ARRAY_A),
        'usermeta' => $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $wpdb->usermeta WHERE user_id = %d AND meta_key = 'mtrk_mg_avatar'", $admin)),
        'options' => $wpdb->get_results("SELECT option_name, option_value, autoload FROM $wpdb->options WHERE option_name IN ('mtrkt_mg_widget', 'theme_mods_mtrkt') ORDER BY option_name", ARRAY_A),
        'comment' => $wpdb->get_var($wpdb->prepare("SELECT comment_content FROM $wpdb->comments WHERE comment_ID = %d", $comment)),
        'keep' => $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d ORDER BY meta_id", $k), ARRAY_A),
    ];
};
$raw = fn(int $post, string $key) => $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s", $post, $key));
$merge_d = 0;

try {
    mtrk_fixture_scan(true, false); // with code: the Elementor CSS file is a reference too
    $is(Scanner::status($d, Scanner::counts([$d])[$d] ?? 0) === 'used' && (Scanner::counts([$k])[$k] ?? 0) === 0, 'setup: D is used, K is not');

    // Dry run: the plan, and nothing changes.
    $before = $snapshot();
    $plan = Merge::plan($k, $d);
    $where = array_map(fn($c) => $c['tbl'] . ':' . $c['src_type'] . ':' . $c['src_id'] . ':' . $c['src_key'] . ':' . $c['col'], $plan['changes']);
    $is($plan['error'] === '' && $plan['blocked'] === [], 'plan: every reference of D is supported');
    foreach (["posts:post:$p1::post_content", "posts:post:$p1::post_excerpt", "postmeta:post:$p2:_thumbnail_id:meta_value", "postmeta:post:$p2:_product_image_gallery:meta_value", "postmeta:post:$p2:mtrk_mg_ser:meta_value", "postmeta:post:$p3:_elementor_data:meta_value", "termmeta:term:$term:thumbnail_id:meta_value", "usermeta:user:$admin:mtrk_mg_avatar:meta_value", 'options:option:0:mtrkt_mg_widget:option_value', 'options:option:0:theme_mods_mtrkt:option_value', "cache:post:$p3:_elementor_css:"] as $w) {
        $is(in_array($w, $where, true), "plan: $w");
    }
    $is((bool) preg_grep('~^cache:file:0:.*elementor/css/post-' . $p3 . '\.css:$~', $where), 'plan: the Elementor CSS file is a cache to rebuild');
    $is(!preg_grep("~:$p2:_price:~", $where), 'plan: a number that is not a reference is not touched');
    WP_CLI::runcommand("elinweb-unused-media-finder duplicates merge $k $d", ['launch' => false, 'return' => true, 'exit_error' => false]);
    $is($snapshot() === $before && !get_post_meta($d, Cleanup::META, true), 'dry run (plan and the CLI without --yes): nothing changed');

    // Unsupported: U is in a comment, so U is refused as a whole and nothing changes.
    $plan_u = Merge::plan($k, $u);
    $is(count($plan_u['blocked']) === 1 && $plan_u['blocked'][0][0]['source_type'] === 'comment', 'unsupported: the comment is listed with its place');
    $r = $run($k, $u);
    $is(!empty($r['error']) && $snapshot() === $before && !get_post_meta($u, Cleanup::META, true), 'unsupported: refused, nothing changed, U not quarantined');

    // The merge.
    $r = $run($k, $d);
    $merge_d = (int) ($r['merge'] ?? 0);
    $is(($r['state'] ?? '') === 'done' && $r['outcome'] === 'quarantined', 'merge: done, D quarantined');
    $p = get_post($p1);
    clean_post_cache($p1);
    $p1row = $wpdb->get_row($wpdb->prepare("SELECT post_content, post_excerpt FROM $wpdb->posts WHERE ID = %d", $p1));
    $is($p1row->post_content === $fill($content_tpl, true), 'content: URLs (size, size K lacks, main), wp-image class, block ids, gallery ids rewritten; look-alikes kept');
    $is($p1row->post_excerpt === $fill($excerpt_tpl, true), 'excerpt: rewritten');
    $revs = wp_get_post_revisions($p1);
    $is((bool) array_filter($revs, fn($rev) => $rev->post_content === $fill($content_tpl, false)), 'content: a revision holds the old content');
    $is($raw($p2, '_thumbnail_id') === (string) $k, 'meta: featured image id');
    $is($raw($p2, '_product_image_gallery') === "$o,$k,1$d", 'meta: product gallery list (1D kept)');
    $is($raw($p2, '_price') === (string) $d, 'meta: the number D in another field kept');
    $ser = $raw($p2, 'mtrk_mg_ser');
    $is($ser === serialize($ser_new) && unserialize($ser) === $ser_new, 'meta: serialized URL rewritten, string lengths valid, the rest identical');
    $is($raw($p3, '_elementor_data') === wp_json_encode($el(true)), 'elementor: image and gallery objects {id,url} rewritten, the look-alike and other numbers kept');
    $is($raw($p3, '_elementor_css') === null && !file_exists($css), 'elementor: CSS cache meta and file removed for a rebuild');
    $is(get_term_meta($term, 'thumbnail_id', true) === (string) $k, 'term meta: thumbnail id');
    $is(get_user_meta($admin, 'mtrk_mg_avatar', true) === $file('mtrk-mg-k.jpg'), 'user meta: URL');
    wp_cache_delete('mtrkt_mg_widget', 'options');
    wp_cache_delete('alloptions', 'options');
    $is(get_option('mtrkt_mg_widget') === ['title' => 'w', 'attachment_id' => $k, 'url' => $file('mtrk-mg-k.jpg'), 'other_id' => $d], 'option: widget {attachment_id,url} rewritten, other_id kept');
    $is(get_option('theme_mods_mtrkt') === ['custom_logo' => $k, 'header' => 'x'], 'option: theme mod custom_logo');
    $is($wpdb->get_var("SELECT autoload FROM $wpdb->options WHERE option_name = 'mtrkt_mg_widget'") === $before['options'][0]['autoload'], 'option: autoload unchanged');
    $after = $snapshot();
    $is($after['keep'] === $before['keep'] && $after['comment'] === $before['comment'], 'K and unrelated rows unchanged');
    $is((Scanner::counts([$d])[$d] ?? 0) === 0 && (Scanner::counts([$k])[$k] ?? 0) >= 7, 'index: D has no reference, K is used');
    $is(!file_exists("$dir/mtrk-mg-d.jpg") && (bool) get_post_meta($d, Cleanup::META, true), 'D: its file is in quarantine');

    // HTTP: the old URL is still served from quarantine, and 404 during the test.
    $durl = $file('mtrk-mg-d.jpg');
    $is($curl($durl, home_url('/merge-check/'))[0] === 200, 'http: the old D URL answers 200 from quarantine');
    Cleanup::start_test(1);
    $is($curl($durl, home_url('/merge-check/')) === [404, '404-test'], 'http: during the 404 test it answers 404');
    Cleanup::stop_test();

    // Twice: harmless.
    $again = $snapshot();
    $r2 = $run($k, $d);
    $is(!empty($r2['error']) && $snapshot() === $again, 'twice: the second run is refused and changes nothing');

    // Undo, with one value changed since the merge.
    $wpdb->update($wpdb->posts, ['post_excerpt' => 'changed by hand'], ['ID' => $p1]);
    $r = $undo($merge_d);
    $now = $snapshot();
    $is(($r['state'] ?? '') === 'undone' && strpos($r['message'], "post #$p1 (content)") !== false, 'undo: done, the changed value is reported');
    $is($wpdb->get_var($wpdb->prepare("SELECT post_excerpt FROM $wpdb->posts WHERE ID = %d", $p1)) === 'changed by hand', 'undo: a value changed since is left alone');
    $wpdb->update($wpdb->posts, ['post_excerpt' => $fill($excerpt_tpl, false)], ['ID' => $p1]);
    $restored = $snapshot();
    $is($restored === $before, 'undo: every other value is back byte for byte');
    if ($restored !== $before) {
        foreach ($before as $part => $rows) {
            if ($restored[$part] !== $rows) {
                echo "  differs: $part\n";
                var_dump(array_udiff((array) $restored[$part], (array) $rows, fn($x, $y) => $x <=> $y));
            }
        }
    }
    $is(file_exists("$dir/mtrk-mg-d.jpg") && !get_post_meta($d, Cleanup::META, true), 'undo: D is back from quarantine');
    $is(!empty(Merge::undo($merge_d)['error']), 'undo: a second undo is refused');

    // --delete: the copy goes for good, and its merge cannot be undone.
    $r = $run($k, $e, true);
    $is(($r['state'] ?? '') === 'done' && $r['outcome'] === 'deleted' && !get_post($e), 'delete: E deleted permanently when asked');
    $is($wpdb->get_var($wpdb->prepare("SELECT post_content FROM $wpdb->posts WHERE ID = %d", $p6)) === '<img src="' . $file('mtrk-mg-k.jpg') . '" class="wp-image-' . $k . '">', 'delete: its reference points at K');
    $is(!empty(Merge::undo((int) $r['merge'])['error']), 'delete: undo refused');

    // An extra file, referenced by URL only.
    $r = $run($k, '2026/10/mtrk-mg-x.jpg');
    $is(($r['state'] ?? '') === 'done' && $r['outcome'] === 'left' && file_exists("$dir/mtrk-mg-x.jpg"), 'extra: merged, the file stays on disk');
    $is($wpdb->get_var($wpdb->prepare("SELECT post_content FROM $wpdb->posts WHERE ID = %d", $p5)) === '<img src="' . $file('mtrk-mg-k.jpg') . '"> and <img src="' . $file('mtrk-mg-x10.jpg') . '">', 'extra: its URL points at K, the look-alike x10 kept');
    $is(Scanner::locate('2026/10/mtrk-mg-x.jpg')['refs'] === [], 'extra: no reference left');
    $is(!empty(Merge::plan($k, '2026/10/mtrk-mg-k.jpg')['error']) && !empty(Merge::plan($k, '../../wp-config.php')['error']), 'extra: a library file or a path outside uploads is refused');

    // The handler.
    $r = $ajax(['do' => 'preview', 'keep' => $k, 'ids' => [$d]]);
    $is(($r['success'] ?? null) === false, 'ajax: no nonce, refused');
    $subscriber = wp_insert_user(['user_login' => 'mtrk_mg_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
    wp_set_current_user($subscriber);
    $r = $ajax(['do' => 'run', 'keep' => $k, 'id' => $d, '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false && !get_post_meta($d, Cleanup::META, true), 'ajax: without the capability, refused');
    wp_set_current_user($admin);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subscriber);
    $before_preview = $snapshot();
    $r = $ajax(['do' => 'preview', 'keep' => $k, 'ids' => [$d, $u], '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? false) && $r['data']['items'] === [$d] && strpos($r['data']['html'], 'Cannot be rewritten safely') !== false, 'ajax: the preview offers D, refuses U');
    $is($snapshot() === $before_preview, 'ajax: the preview changed nothing');
} finally {
    $saved_test === false ? delete_option(Cleanup::TEST) : update_option(Cleanup::TEST, $saved_test, false);
    if ($comment) {
        wp_delete_comment($comment, true);
    }
    foreach (array_reverse($made) as $id) {
        foreach (wp_get_post_revisions($id) as $rev) {
            wp_delete_post($rev->ID, true);
        }
        wp_delete_post($id, true); // takes quarantined files with it
    }
    foreach (glob("$dir/mtrk-mg-*") ?: [] as $f) {
        unlink($f);
    }
    @unlink($css);
    wp_delete_term($term, 'product_cat');
    wp_delete_term($term, 'category');
    delete_user_meta($admin, 'mtrk_mg_avatar');
    delete_option('mtrkt_mg_widget');
    delete_option('theme_mods_mtrkt');
    $wpdb->query('DELETE FROM ' . Merge::rows_table() . ' WHERE merge_id IN (SELECT id FROM ' . Merge::table() . ' WHERE keep_id = ' . (int) $k . ')');
    $wpdb->query('DELETE FROM ' . Merge::table() . ' WHERE keep_id = ' . (int) $k);
}

$is(!get_post($k) && !get_post($d) && !glob("$dir/mtrk-mg-*"), 'cleanup: fixtures and files are gone');

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
