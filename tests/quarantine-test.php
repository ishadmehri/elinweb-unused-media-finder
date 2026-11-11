<?php
/**
 * The 404 test: start, stop and expiry, the allowed durations, requests logged as test hits with their referring pages,
 * "restore every file that was requested", the AJAX handler's nonce and capability checks, and the real HTTP answer
 * (404 while the test runs, the file from quarantine after it). Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/quarantine-test.php
 */

use ElinwebUnusedMediaFinder\Cleanup;
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
$saved = get_option(Cleanup::TEST);
$admin = get_current_user_id();
$meta = function (int $id): array {
    wp_cache_delete($id, 'post_meta'); // the HTTP request wrote it in another process
    return (array) get_post_meta($id, Cleanup::META, true);
};

// An AJAX handler ends in wp_die(); make that an exception so the test goes on, and keep the JSON it printed.
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', fn() => function () {
    throw new RuntimeException('wp_die');
});
$ajax = function (array $post): array {
    $_POST = $_REQUEST = $post;
    ob_start();
    try {
        Page::ajax_qtest();
    } catch (RuntimeException $e) {
        // expected: wp_send_json_*() ends with wp_die()
    }
    $json = json_decode((string) ob_get_clean(), true);
    $_POST = $_REQUEST = [];
    return is_array($json) ? $json : [];
};

// The real request, from outside this process. Returns [status, X-Mtrk header].
$curl = function (string $url, string $referer = ''): array {
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $head = (string) shell_exec('curl --noproxy "*" -s -o ' . $null . ' -D - ' . ($referer ? '-e ' . escapeshellarg($referer) . ' ' : '') . escapeshellarg($url));
    preg_match('#^HTTP/\S+ (\d+)#', $head, $status);
    preg_match('/^X-Mtrk:\s*(\S+)/mi', $head, $mark);
    return [(int) ($status[1] ?? 0), $mark[1] ?? ''];
};

$a = mtrk_fixture_media('mtrk-qt-a.jpg', $made);
$b = mtrk_fixture_media('mtrk-qt-b.jpg', $made);
$c = mtrk_fixture_media('mtrk-qt-c.jpg', $made);
$subscriber = wp_insert_user(['user_login' => 'mtrk_qt_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$url = '';

try {
    // State: off by default, the allowed durations only, ends by itself.
    delete_option(Cleanup::TEST);
    $is(Cleanup::test_until() === null && !Cleanup::test_active(), 'state: off when the option is absent');
    $is(!Cleanup::start_test(0) && !Cleanup::start_test(5) && !Cleanup::start_test(168) && get_option(Cleanup::TEST) === false, 'state: durations other than 1, 6, 24, 72 hours are refused');
    foreach (Cleanup::TEST_HOURS as $h) {
        $ok = Cleanup::start_test($h);
        $is($ok && Cleanup::test_active() && abs(Cleanup::test_until() - (time() + $h * HOUR_IN_SECONDS)) <= 2, "state: a $h-hour test runs until now + $h h");
    }
    $is(Cleanup::start_test() && abs(Cleanup::test_until() - time() - DAY_IN_SECONDS) <= 2, 'state: 24 hours by default');
    Cleanup::stop_test();
    $info = Cleanup::test_info();
    $is(!Cleanup::test_active() && $info && $info['until'] <= time() && $info['start'] > 0, 'state: stop ends it and keeps when it ran');
    update_option(Cleanup::TEST, ['start' => time() - 7200, 'until' => time() - 1], false);
    $is(!Cleanup::test_active(), 'state: expires by itself (until in the past)');
    $is(!array_key_exists(Cleanup::TEST, wp_load_alloptions()), 'state: the option is not autoloaded');

    // Requests: test hits are counted apart, the last five distinct referring pages are kept.
    mtrk_fixture_scan(false, false);
    $is(Cleanup::quarantine([$a, $b, $c]) === [$a, $b, $c], 'setup: three media quarantined');
    $home = home_url('/');
    foreach ([1, 2, 3, 4, 5, 6, 2] as $n) {
        Cleanup::hit($a, $home . "page-$n/", 'Mozilla', true);
    }
    Cleanup::hit($a, admin_url('upload.php'), 'Mozilla', true);
    Cleanup::hit($b, '', 'Mozilla');
    $qa = $meta($a);
    $qb = $meta($b);
    $is($qa['test'] === 7 && array_sum($qa['hits']) === 7, 'hits: 7 test hits; the admin screen is not counted');
    $is($qa['pages'] === [$home . 'page-2/', $home . 'page-6/', $home . 'page-5/', $home . 'page-4/', $home . 'page-3/'], 'hits: last 5 distinct referring pages, newest first');
    $is($qa['from'] === $home . 'page-2/' && $qa['last'] >= time() - 5, 'hits: "from" and "last" kept as before');
    $is(!isset($qb['test']) && $qb['hits'] === ['direct' => 1] && !isset($qb['pages']), 'hits: a hit outside the test is not a test hit');

    // Restore every file that was requested: a and b, not c.
    $requested = Cleanup::requested();
    $is($requested === [$a, $b] || $requested === [$b, $a], 'requested: exactly the media with a logged request');
    $restored = Cleanup::restore($requested);
    sort($restored);
    $is($restored === [$a, $b] && file_exists("$base/mtrk-qt-a.jpg") && file_exists("$base/mtrk-qt-b.jpg"), 'requested: restored to their place');
    $is(array_keys(Cleanup::quarantined()) === [$c] && !file_exists("$base/mtrk-qt-c.jpg"), 'requested: the other one stays quarantined');

    // The handler: nonce first, then capability, then the duration.
    delete_option(Cleanup::TEST);
    $r = $ajax(['do' => 'start', 'hours' => '1']);
    $is(($r['success'] ?? null) === false && !Cleanup::test_active(), 'ajax: no nonce, refused');
    $r = $ajax(['do' => 'start', 'hours' => '1', '_ajax_nonce' => 'bogus']);
    $is(($r['success'] ?? null) === false && !Cleanup::test_active(), 'ajax: bad nonce, refused');
    wp_set_current_user($subscriber);
    $r = $ajax(['do' => 'start', 'hours' => '1', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false && !Cleanup::test_active(), 'ajax: valid nonce without the capability, refused');
    wp_set_current_user($admin);
    $r = $ajax(['do' => 'start', 'hours' => '5', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === false && !Cleanup::test_active(), 'ajax: a duration outside the choices, refused');
    $r = $ajax(['do' => 'start', 'hours' => '6', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === true && Cleanup::test_active() && abs(Cleanup::test_until() - time() - 6 * HOUR_IN_SECONDS) <= 2, 'ajax: admin starts a 6-hour test');
    $r = $ajax(['do' => 'stop', '_ajax_nonce' => wp_create_nonce('mtrk')]);
    $is(($r['success'] ?? null) === true && !Cleanup::test_active(), 'ajax: admin stops it');

    // Real HTTP requests for the quarantined file.
    $url = (string) wp_get_attachment_url($c);
    $is($curl(Cleanup::probe_url()) === [204, 'ok'], 'http: requests for missing upload files reach WordPress (probe)');
    Cleanup::start_test(1);
    $is($curl($url, $home . 'http-check/') === [404, '404-test'], 'http: during the test, 404 with X-Mtrk: 404-test');
    $qc = $meta($c);
    $is(($qc['test'] ?? 0) === 1 && ($qc['pages'] ?? []) === [$home . 'http-check/'], 'http: the request was logged as a test hit with its page');
    $is($curl($url, admin_url('upload.php?page=elinweb-unused-media-finder'))[0] === 200, 'http: your own admin screens still get the file during the test');
    Cleanup::stop_test();
    $is($curl($url, $home . 'http-check/') === [200, ''], 'http: without the test, 200 from quarantine');
    $is(($meta($c)['test'] ?? 0) === 1 && array_sum($meta($c)['hits']) === 2, 'http: logged, not as a test hit');
} finally {
    $saved === false ? delete_option(Cleanup::TEST) : update_option(Cleanup::TEST, $saved, false);
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true); // takes quarantined files with it
    }
    foreach (glob("$base/mtrk-qt-*") ?: [] as $f) {
        unlink($f);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subscriber);
}

$is(!get_post($a) && !Cleanup::match((string) wp_parse_url($url, PHP_URL_PATH)) && !get_userdata($subscriber), 'cleanup: fixtures, quarantine entries and the test user are gone');

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
