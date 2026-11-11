<?php

namespace ElinwebUnusedMediaFinder;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- A merge reads and writes single rows by primary key: the stored value must be compared byte for byte and the new one written exactly, without the filters of the core APIs (which may change it); caches are cleaned after each write. The undo log is the plugin's own table.
// Every dynamic part of a query is a table name from $wpdb (prefix or core table property), a column name from a fixed list, an int cast, or a fragment built with $wpdb->prepare().

/**
 * "Merge" in a duplicate group: every reference to a copy D that can be rewritten exactly is pointed at the kept copy
 * K (the files are identical), then D is quarantined, or deleted when asked. Preview first; every old and new value
 * is logged before anything is written; each rewritten value is proven with the scanner's own extractors (D gone, K
 * there, nothing else changed); a copy with any reference that cannot be rewritten safely is refused as a whole.
 */
final class Merge
{
    private const BUDGET = 8;                     // seconds per request
    private const MAX_REFS = 1000;                // places per copy
    private const MAX_BYTES = 32 * MB_IN_BYTES;   // old + new values per copy, so one merge's log stays bounded
    private const KEEP_LOGS = 50;                 // merges kept in the log; older ones can no longer be undone
    private const CACHE_META = ['_elementor_css', '_elementor_element_cache']; // built from _elementor_data; Elementor rebuilds them
    private const META = ['post' => 'postmeta', 'term' => 'termmeta', 'user' => 'usermeta'];
    private const OWNER = ['postmeta' => 'post_id', 'termmeta' => 'term_id', 'usermeta' => 'user_id'];
    private const COLS = ['post_content', 'post_excerpt'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_merges';
    }

    public static function rows_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_merge_rows';
    }

    /** The undo log, created on the first merge: most sites never merge. */
    private static function ready(bool $create = false): bool
    {
        global $wpdb;
        $exists = fn() => (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::rows_table())));
        if ($exists() || !$create) {
            return $exists();
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            keep_id bigint(20) unsigned NOT NULL DEFAULT 0,
            dup_id bigint(20) unsigned NOT NULL DEFAULT 0,
            dup_path varchar(500) NOT NULL DEFAULT '',
            perm tinyint(1) NOT NULL DEFAULT 0,
            state varchar(12) NOT NULL DEFAULT '',
            outcome varchar(12) NOT NULL DEFAULT '',
            note longtext NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;");
        // One row per changed value; old and new values compressed. done: 0 pending, 1 written, 2 reverted, 3 skipped.
        dbDelta('CREATE TABLE ' . self::rows_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            merge_id bigint(20) unsigned NOT NULL,
            tbl varchar(20) NOT NULL DEFAULT '',
            row_key varchar(191) NOT NULL DEFAULT '',
            col varchar(20) NOT NULL DEFAULT '',
            src_type varchar(20) NOT NULL DEFAULT '',
            src_id bigint(20) unsigned NOT NULL DEFAULT 0,
            src_key varchar(255) NOT NULL DEFAULT '',
            how varchar(4) NOT NULL DEFAULT '',
            old_value longtext NOT NULL,
            new_value longtext NOT NULL,
            done tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY merge (merge_id,done)
        ) $charset;");
        return $exists();
    }

    // ---- plan ------------------------------------------------------------------------------------------------

    /**
     * What merging $dup into $keep would change, read from the database now (never taken from the browser).
     * @param int|string $dup attachment id, or the upload-relative path of an extra file
     * @return array{dup:int|string, error:string, changes:array[], blocked:array[]} blocked: [ref row, reason]
     */
    public static function plan(int $keep, $dup): array
    {
        $out = ['dup' => $dup, 'error' => '', 'changes' => [], 'blocked' => []];
        $c = self::context($keep, $dup);
        if (is_string($c)) {
            $out['error'] = $c;
            return $out;
        }
        if (is_int($dup)) {
            $refs = Scanner::refs($dup, self::MAX_REFS + 1);
        } else {
            $found = Scanner::locate($dup, self::MAX_REFS + 1);
            $refs = $found['refs'];
            if (!$found['complete'] && count($refs) <= self::MAX_REFS) {
                $out['error'] = __('The search for the places that use this file did not finish in time, so it cannot be replaced safely.', 'elinweb-unused-media-finder');
                return $out;
            }
        }
        if (count($refs) > self::MAX_REFS) {
            /* translators: %s: maximum number of places */
            $out['error'] = sprintf(__('This copy is used in more than %s places; that is more than one merge handles.', 'elinweb-unused-media-finder'), number_format_i18n(self::MAX_REFS));
            return $out;
        }
        $caches = [];
        $bytes = 0;
        foreach ($refs as $r) {
            $result = self::place($c, $r);
            if (isset($result['blocked'])) {
                $out['blocked'][] = [$r, $result['blocked']];
                continue;
            }
            foreach ($result['changes'] as $ch) {
                if ($ch['tbl'] === 'cache') {
                    $caches[$ch['row_key']] = $ch; // after the data they are built from
                    continue;
                }
                $out['changes'][] = $ch;
                $bytes += strlen($ch['old']) + strlen($ch['new']);
            }
        }
        $out['changes'] = array_merge($out['changes'], array_values($caches));
        if ($bytes > self::MAX_BYTES) {
            /* translators: %s: size, e.g. 32 MB */
            $out['error'] = sprintf(__('The values to rewrite take more than %s; that is more than one merge handles.', 'elinweb-unused-media-finder'), size_format(self::MAX_BYTES));
        }
        return $out;
    }

    /** @return array|string what a plan needs, or why there can be none */
    private static function context(int $keep, $dup)
    {
        if (!Scanner::trusted()) {
            return Scanner::last() ? Page::suspect_text() : __('Run a full scan first.', 'elinweb-unused-media-finder');
        }
        if (get_post_type($keep) !== 'attachment') {
            return __('The copy to keep is not a media item.', 'elinweb-unused-media-finder');
        }
        if (get_post_meta($keep, Cleanup::META, true)) {
            return __('The copy to keep is quarantined; restore it first.', 'elinweb-unused-media-finder');
        }
        $kfile = (string) get_attached_file($keep);
        $base = untrailingslashit(wp_normalize_path(wp_upload_dir(null, false)['basedir']));
        if (is_int($dup)) {
            if ($dup === $keep || in_array($dup, Scanner::twins($keep), true)) {
                return __('This is the kept copy itself, or shares its file: there is nothing to replace.', 'elinweb-unused-media-finder');
            }
            if (get_post_type($dup) !== 'attachment') {
                return __('Media not found.', 'elinweb-unused-media-finder');
            }
            if (get_post_meta($dup, Cleanup::META, true)) {
                return __('This copy is already quarantined.', 'elinweb-unused-media-finder');
            }
            $twins = array_diff(Scanner::twins($dup), [$dup]);
            if ($twins) {
                /* translators: %s: attachment ids */
                return sprintf(__('This copy shares its file with attachment %s; replacing such media is not supported.', 'elinweb-unused-media-finder'), '#' . implode(wp_get_list_item_separator() . '#', $twins));
            }
            $dfile = (string) get_attached_file($dup);
        } else {
            if (!Orphans::is_extra($dup)) {
                return __('This file is not an extra file of the uploads folder.', 'elinweb-unused-media-finder');
            }
            $dfile = "$base/$dup";
        }
        if (!is_file($kfile) || !is_file($dfile) || md5_file($kfile) !== md5_file($dfile)) {
            return __('The two files are not identical (MD5); only exact duplicates can be merged.', 'elinweb-unused-media-finder');
        }
        $map = self::map($keep, $dup);
        if (!$map) {
            return __('A file name contains characters (spaces, quotes, backslashes) that cannot be rewritten safely.', 'elinweb-unused-media-finder');
        }
        $links = [];
        if (is_int($dup)) {
            $links[] = (string) get_permalink($dup); // a link to its attachment page would break: refused, not rewritten
        }
        return ['keep' => $keep, 'dup' => $dup, 'map' => $map, 're' => self::patterns($map), 'scanner' => new Scanner(), 'links' => array_filter($links)];
    }

    /** Upload-relative path of every file of D => the file of K that replaces it: the same size name, else K's full size. */
    private static function map(int $keep, $dup): array
    {
        $rel = fn(int $id) => ltrim(wp_normalize_path((string) get_post_meta($id, '_wp_attached_file', true)), '/');
        $dir = fn(string $path) => dirname($path) === '.' ? '' : dirname($path) . '/';
        $k = $rel($keep);
        $kmeta = (array) wp_get_attachment_metadata($keep);
        if (is_string($dup)) {
            $map = [$dup => $k];
        } else {
            $d = $rel($dup);
            $dmeta = (array) wp_get_attachment_metadata($dup);
            $map = [$d => $k];
            if (!empty($dmeta['original_image'])) {
                $map[$dir($d) . $dmeta['original_image']] = empty($kmeta['original_image']) ? $k : $dir($k) . $kmeta['original_image'];
            }
            foreach ((array) ($dmeta['sizes'] ?? []) as $name => $s) {
                if (!empty($s['file'])) {
                    $map[$dir($d) . $s['file']] = empty($kmeta['sizes'][$name]['file']) ? $k : $dir($k) . $kmeta['sizes'][$name]['file'];
                }
            }
        }
        foreach ($map as $from => $to) {
            if ($from === '' || $to === '' || preg_match('~[\s"\'<>\\\\]~', $from . $to)) {
                return [];
            }
        }
        return $map;
    }

    /**
     * The URL forms rewritten: the uploads path written plainly, with JSON-escaped slashes, or with URL-encoded names,
     * always after the uploads folder ("wp-content/uploads/") and never followed by more of a file name. Anything
     * else (CDN paths without it, %2F slashes, \u escapes, other letter case) stays and then refuses the place.
     * @return array<string,string> regex => replacement
     */
    private static function patterns(array $map): array
    {
        $base = trim((string) wp_parse_url(wp_upload_dir(null, false)['baseurl'], PHP_URL_PATH), '/');
        $base = $base === '' ? '/' : "/$base/";
        $enc = fn(string $p) => implode('/', array_map('rawurlencode', explode('/', $p)));
        $esc = fn(string $p) => str_replace('/', '\/', $p);
        $out = [];
        uksort($map, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($map as $d => $k) {
            foreach (array_unique([$d => $k, $enc($d) => $enc($k)]) as $fd => $fk) {
                $fd = (string) $fd;
                $out['~(?<=' . preg_quote($base, '~') . ')' . preg_quote($fd, '~') . '(?![\w.%-])~'] = $fk;
                $out['~(?<=' . preg_quote($esc($base), '~') . ')' . preg_quote($esc($fd), '~') . '(?![\w.%-])~'] = $esc($fk);
            }
        }
        return $out;
    }

    /** @return ?string the text with D's URLs pointed at K's files; null when a regex fails (huge or broken input) */
    private static function urls(array $c, string $s): ?string
    {
        foreach ($c['re'] as $re => $to) {
            $s = preg_replace_callback($re, fn() => $to, $s);
            if (!is_string($s)) {
                return null;
            }
        }
        return $s;
    }

    /** One place (a post, a meta key of an object, an option, ...): its changes, or why it cannot be rewritten. */
    private static function place(array $c, array $r): array
    {
        $type = (string) $r['source_type'];
        $key = (string) $r['source_key'];
        if ($type === 'keep') {
            return ['blocked' => __('You marked this copy as used outside the site ("Keep").', 'elinweb-unused-media-finder')];
        }
        if ($type === 'post' && $key === '') {
            return self::post($c, $r);
        }
        if ($type === 'post' && in_array($key, self::CACHE_META, true)) {
            return ['changes' => [self::change('cache', 'meta:' . (int) $r['source_id'] . ":$key", '', $r, '', '')]];
        }
        if ($type === 'file' && self::css_file($key) !== '') {
            return ['changes' => [self::change('cache', 'file:' . $key, '', $r, '', '')]];
        }
        if (isset(self::META[$type]) && !($type === 'term' && $key === 'description')) {
            return self::meta($c, $r);
        }
        if ($type === 'option') {
            return self::option($c, $r);
        }
        return ['blocked' => __('References in comments, term descriptions, plugin tables and code files are not rewritten.', 'elinweb-unused-media-finder')];
    }

    private static function post(array $c, array $r): array
    {
        global $wpdb;
        $id = (int) $r['source_id'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT post_content, post_excerpt FROM $wpdb->posts WHERE ID = %d", $id), ARRAY_A);
        $changes = [];
        foreach ($row ? self::COLS : [] as $col) {
            $old = (string) $row[$col];
            if (!self::names($c, 'post', $old)) {
                continue;
            }
            $new = self::content($c, $old);
            if ($new === null || !self::proven($c, 'post', $old, $new)) {
                return ['blocked' => self::refusal($c, $old)];
            }
            $changes[] = self::change('posts', (string) $id, $col, $r, $old, $new);
        }
        return ['changes' => $changes];
    }

    /** Post content: URLs, the wp-image-D class, core media block ids, gallery/playlist shortcode ids. */
    private static function content(array $c, string $s): ?string
    {
        $s = self::urls($c, $s);
        if ($s === null || !is_int($c['dup'])) {
            return $s;
        }
        $d = $c['dup'];
        $k = (string) $c['keep'];
        foreach ($c['links'] as $link) {
            if (strpos($s, $link) !== false) {
                return null;
            }
        }
        if (preg_match('/[?&](?:amp;|#038;)?attachment_id=' . $d . '(?!\d)/', $s)) {
            return null;
        }
        $token = fn(string $list) => (string) preg_replace('/(?<!\d)' . $d . '(?!\d)/', $k, $list);
        $steps = [
            fn($s) => preg_replace('/\bwp-image-' . $d . '(?!\d)/', "wp-image-$k", $s),
            fn($s) => preg_replace('/(\bdata-id=["\']?)' . $d . '(?!\d)/', '${1}' . $k, $s), // gallery images (older markup)
            fn($s) => preg_replace_callback(
                '~<!-- wp:(?:core/)?(?:image|cover|media-text|video|audio|file|gallery) \{.*?\} /?-->~s',
                fn($m) => (string) preg_replace_callback(
                    '/("(?:id|mediaId)":)' . $d . '(?![\d.])|("ids":\[)([\d,]*)(\])/',
                    fn($x) => isset($x[2]) && $x[2] !== '' ? $x[2] . $token($x[3]) . $x[4] : $x[1] . $k,
                    $m[0]
                ),
                $s
            ),
            fn($s) => preg_replace_callback('/(\[(?:gallery|playlist)\b[^\]]*?\bids\s*=\s*["\']?)([\d, ]+)/i', fn($m) => $m[1] . $token($m[2]), $s),
        ];
        foreach ($steps as $step) {
            $s = $step($s);
            if (!is_string($s)) {
                return null;
            }
        }
        return $s;
    }

    private static function meta(array $c, array $r): array
    {
        global $wpdb;
        $table = self::META[$r['source_type']];
        $pk = $table === 'usermeta' ? 'umeta_id' : 'meta_id';
        $owner = self::OWNER[$table];
        $sid = (int) $r['source_id'];
        $key = (string) $r['source_key'];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $pk AS id, meta_value AS v FROM {$wpdb->$table} WHERE $owner = %d AND meta_key = %s ORDER BY $pk", $sid, $key), ARRAY_A);
        $changes = [];
        foreach ($rows as $m) {
            $old = (string) $m['v'];
            if (!self::names($c, $table, $old, $key, $sid)) {
                continue;
            }
            $new = self::value($c, $old);
            if ($new === null || !self::proven($c, $table, $old, $new, $key, $sid)) {
                return ['blocked' => self::refusal($c, $old)];
            }
            $changes[] = self::change($table, (string) $m['id'], 'meta_value', $r, $old, $new);
        }
        return ['changes' => $changes];
    }

    private static function option(array $c, array $r): array
    {
        global $wpdb;
        $name = (string) $r['source_key'];
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name));
        if ($old === null || !self::names($c, 'option', $old, $name)) {
            return ['changes' => []];
        }
        $new = self::value($c, $old, $name);
        if ($new === null || !self::proven($c, 'option', $old, $new, $name)) {
            return ['blocked' => self::refusal($c, $old)];
        }
        return ['changes' => [self::change('options', $name, 'option_value', $r, $old, $new)]];
    }

    /**
     * A stored value: a whole id or list of ids, serialized PHP, JSON, or text. Structured values are decoded only
     * when encoding them again gives back exactly the stored bytes; inside, strings get the URL rewrite and an
     * object holding both an id of D and a URL of D (Elementor and widget images) gets K's id.
     */
    private static function value(array $c, string $v, string $option = ''): ?string
    {
        if (is_int($c['dup']) && preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', $v)) {
            return (string) preg_replace('/(?<!\d)' . $c['dup'] . '(?!\d)/', (string) $c['keep'], $v);
        }
        if (is_serialized($v)) {
            $data = @unserialize(trim($v), ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- no objects allowed, and the result must re-serialize to exactly these bytes (maybe_unserialize would allow objects)
            if (($data === false && trim($v) !== 'b:0;') || self::has_object($data) || serialize($data) !== $v) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- comparing with the stored bytes
                return null;
            }
            if (!self::walk($data, $c)) {
                return null;
            }
            if ($option !== '' && strpos($option, 'theme_mods_') === 0 && is_array($data) && self::is_dup($data['custom_logo'] ?? null, $c)) {
                $data['custom_logo'] = is_int($data['custom_logo']) ? $c['keep'] : (string) $c['keep'];
            }
            return serialize($data); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the stored format; maybe_serialize() would double-serialize a string
        }
        if (preg_match('/^\s*[\[{]/', $v)) {
            $data = json_decode($v);
            foreach ($data === null ? [] : [0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                if (wp_json_encode($data, $flags) === $v) {
                    $new = self::walk($data, $c) ? wp_json_encode($data, $flags) : false;
                    return is_string($new) ? $new : null;
                }
            }
        }
        return self::urls($c, $v);
    }

    private static function walk(&$x, array $c): bool
    {
        if (is_string($x)) {
            $new = self::urls($c, $x);
            if ($new === null) {
                return false;
            }
            $x = $new;
            return true;
        }
        if (!is_array($x) && !$x instanceof \stdClass) {
            return true;
        }
        $url = false;
        foreach ($x as $k => &$v) {
            $before = $v;
            if (!self::walk($v, $c)) {
                return false;
            }
            $url = $url || ($k === 'url' && $v !== $before);
        }
        unset($v);
        if ($url) { // {"id": D, "url": "<a file of D>"}: the id is D's for sure, so it becomes K's
            foreach (['id', 'attachment_id'] as $key) {
                $id = is_array($x) ? ($x[$key] ?? null) : ($x->$key ?? null);
                if (!self::is_dup($id, $c)) {
                    continue;
                }
                $to = is_int($id) ? $c['keep'] : (string) $c['keep'];
                if (is_array($x)) {
                    $x[$key] = $to;
                } else {
                    $x->$key = $to;
                }
            }
        }
        return true;
    }

    private static function is_dup($id, array $c): bool
    {
        return is_int($c['dup']) && (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id === $c['dup'];
    }

    private static function has_object($x): bool
    {
        if (is_object($x)) {
            return true;
        }
        foreach (is_array($x) ? $x : [] as $v) {
            if (self::has_object($v)) {
                return true;
            }
        }
        return false;
    }

    /** Whether a scan would count this value as a use of D. */
    private static function names(array $c, string $source, string $value, string $key = '', int $sid = 0): bool
    {
        return is_int($c['dup'])
            ? isset($c['scanner']->found($source, $value, $key, $sid)[$c['dup']])
            : in_array(Scanner::stem($c['dup']), $c['scanner']->stems($value), true);
    }

    /**
     * The proof, with the scanner's own extractors: the new value no longer names D, it names K, only D's paths
     * left and only K's paths came in, and every other media reference is exactly as before.
     */
    private static function proven(array $c, string $source, string $old, string $new, string $key = '', int $sid = 0): bool
    {
        $s = $c['scanner'];
        $was = array_keys($s->found($source, $old, $key, $sid));
        $now = array_keys($s->found($source, $new, $key, $sid));
        $own = [$c['keep'], is_int($c['dup']) ? $c['dup'] : 0];
        $rest = function (array $ids) use ($own): array {
            $ids = array_values(array_diff($ids, $own));
            sort($ids);
            return $ids;
        };
        $stems = fn(array $paths) => array_map([Scanner::class, 'stem'], $paths);
        $gone = array_diff($s->stems($old), $s->stems($new));
        $came = array_diff($s->stems($new), $s->stems($old));
        return !self::names($c, $source, $new, $key, $sid)
            && in_array($c['keep'], $now, true)
            && $rest($was) === $rest($now)
            && !array_diff($gone, $stems(array_keys($c['map'])))
            && !array_diff($came, $stems(array_values($c['map'])));
    }

    private static function refusal(array $c, string $value): string
    {
        foreach ($c['links'] as $link) {
            if (strpos($value, $link) !== false) {
                return __('It links to the attachment page of this copy.', 'elinweb-unused-media-finder');
            }
        }
        return __('It refers to this copy in a form that cannot be rewritten exactly (for example an id inside a plugin\'s block or setting, a file the kept copy has no match for, or an encoded URL).', 'elinweb-unused-media-finder');
    }

    private static function change(string $tbl, string $row_key, string $col, array $r, string $old, string $new): array
    {
        return [
            'tbl' => $tbl, 'row_key' => $row_key, 'col' => $col,
            'src_type' => (string) $r['source_type'], 'src_id' => (int) $r['source_id'], 'src_key' => (string) $r['source_key'], 'how' => (string) $r['how'],
            'old' => $old, 'new' => $new,
        ];
    }

    /** Elementor's generated CSS file of a post (a cache it rebuilds from the data), as a scan names it; '' otherwise. */
    private static function css_file(string $key): string
    {
        if (!preg_match('~(?:^|/)elementor/css/post-\d+\.css$~', $key)) {
            return '';
        }
        $abs = wp_normalize_path(path_is_absolute($key) ? $key : WP_CONTENT_DIR . "/$key");
        $dir = wp_normalize_path(wp_upload_dir(null, false)['basedir']) . '/elementor/css/';
        return strpos($abs, $dir) === 0 && strpos($abs, '..') === false ? $abs : '';
    }

    // ---- run -------------------------------------------------------------------------------------------------

    /**
     * Plans again, writes the undo log, then applies. Nothing is written when any place is refused.
     * @param int|string $dup
     */
    public static function start(int $keep, $dup, bool $delete): array
    {
        global $wpdb;
        $plan = self::plan($keep, $dup);
        if ($plan['error'] !== '') {
            return ['error' => $plan['error']];
        }
        if ($plan['blocked']) {
            return ['error' => __('Some places cannot be rewritten safely, so nothing was changed. Open the preview to see them.', 'elinweb-unused-media-finder')];
        }
        if (!self::ready(true)) {
            return ['error' => __('The undo log could not be created, so nothing was changed.', 'elinweb-unused-media-finder')];
        }
        $ok = $wpdb->insert(self::table(), [
            'created' => time(), 'user_id' => get_current_user_id(), 'keep_id' => $keep, 'dup_id' => is_int($dup) ? $dup : 0,
            'dup_path' => is_string($dup) ? $dup : '', 'perm' => $delete ? 1 : 0, 'state' => 'logging', 'outcome' => '', 'note' => '',
        ]);
        $id = (int) $wpdb->insert_id;
        foreach ($plan['changes'] as $ch) {
            $ok = $ok && $wpdb->insert(self::rows_table(), [
                'merge_id' => $id, 'tbl' => $ch['tbl'], 'row_key' => $ch['row_key'], 'col' => $ch['col'],
                'src_type' => $ch['src_type'], 'src_id' => $ch['src_id'], 'src_key' => mb_substr($ch['src_key'], 0, 255), 'how' => $ch['how'],
                'old_value' => self::pack($ch['old']), 'new_value' => self::pack($ch['new']), 'done' => 0,
            ]);
        }
        if (!$ok || !$id) {
            $error = $wpdb->last_error;
            $wpdb->delete(self::rows_table(), ['merge_id' => $id]);
            $wpdb->delete(self::table(), ['id' => $id]);
            /* translators: %s: database error */
            return ['error' => sprintf(__('The undo log could not be written, so nothing was changed. Database error: %s', 'elinweb-unused-media-finder'), $error ?: '—')];
        }
        $wpdb->update(self::table(), ['state' => 'applying'], ['id' => $id]);
        self::prune();
        return ['merge' => $id, 'done' => false]; // planning may have used this request's time; step() applies
    }

    /**
     * Applies logged changes for BUDGET seconds and returns; the call after the last value re-indexes, verifies, then
     * quarantines or deletes. Resumable: a request that dies leaves the rows already written marked in the log.
     */
    public static function step(int $id): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => __('No such merge.', 'elinweb-unused-media-finder')];
        }
        if ($m['state'] === 'logging') { // the request died while writing the log: nothing was applied
            $m = self::set($m, ['state' => 'failed', 'note' => __('The undo log was not completed; nothing was changed.', 'elinweb-unused-media-finder')]);
        }
        if ($m['state'] !== 'applying') {
            return self::result($m);
        }
        if (!self::rows($id, 0, 1)) {
            return self::finish($m);
        }
        $deadline = microtime(true) + self::BUDGET;
        while ($rows = self::rows($id, 0, 20)) {
            foreach ($rows as $row) {
                if ($row['tbl'] === 'cache') {
                    self::clear($row['row_key']);
                    self::mark($row, 1);
                    continue;
                }
                if (self::current($row) !== self::unpack($row['old_value'])) {
                    /* translators: %s: a place, e.g. post #12 (content) */
                    return self::rollback($m, sprintf(__('%s changed after the preview, so the merge was rolled back. Open the preview again.', 'elinweb-unused-media-finder'), self::label($row)));
                }
                if ($row['tbl'] === 'posts' && !self::revised($id, (int) $row['row_key'])) {
                    wp_save_post_revision((int) $row['row_key']); // editors can restore the content as it was
                }
                if (!self::write($row, self::unpack($row['new_value']))) {
                    /* translators: %s: a place, e.g. post #12 (content) */
                    return self::rollback($m, sprintf(__('%s could not be written, so the merge was rolled back.', 'elinweb-unused-media-finder'), self::label($row)));
                }
                self::mark($row, 1);
            }
            if (microtime(true) > $deadline) {
                break;
            }
        }
        return ['merge' => $id, 'done' => false];
    }

    private static function finish(array $m): array
    {
        $rows = self::rows((int) $m['id']);
        self::reindex($rows, $m);
        foreach ($rows as $row) {
            if ($row['tbl'] === 'cache') {
                self::clear($row['row_key']); // again: a page view during the merge may have rebuilt it from the old data
            }
        }
        if ($m['dup_id']) {
            $left = Scanner::refs((int) $m['dup_id'], 20);
        } else {
            $found = Scanner::locate($m['dup_path'], 20);
            $left = $found['refs'];
            if (!$found['complete'] && !$left) {
                return self::rollback($m, __('The check afterwards did not finish in time, so the merge was rolled back.', 'elinweb-unused-media-finder'));
            }
        }
        if ($left) {
            /* translators: %s: list of places */
            return self::rollback($m, sprintf(__('The copy is still used afterwards (%s), so every change was rolled back.', 'elinweb-unused-media-finder'), implode(wp_get_list_item_separator(), array_map([self::class, 'label'], $left))));
        }
        $outcome = 'left';
        if ($m['dup_id']) {
            $dup = (int) $m['dup_id'];
            if ($m['perm']) {
                $outcome = in_array($dup, Cleanup::delete([$dup])['ids'], true) ? 'deleted' : 'left';
            } else {
                $outcome = in_array($dup, Cleanup::quarantine([$dup]), true) ? 'quarantined' : 'left';
            }
        }
        return self::result(self::set($m, ['state' => 'done', 'outcome' => $outcome]));
    }

    /** Puts back every written value that still holds what the merge wrote; a value changed since is left alone. */
    private static function rollback(array $m, string $reason): array
    {
        $rows = self::rows((int) $m['id'], 1);
        self::revert($rows);
        self::reindex(self::rows((int) $m['id']), $m);
        return self::result(self::set($m, ['state' => 'rolledback', 'note' => $reason]));
    }

    private static function revert(array $rows): void
    {
        foreach ($rows as $row) {
            if ($row['tbl'] === 'cache') {
                self::clear($row['row_key']);
                self::mark($row, 2);
                continue;
            }
            $ok = self::current($row) === self::unpack($row['new_value']) && self::write($row, self::unpack($row['old_value']));
            self::mark($row, $ok ? 2 : 3);
        }
    }

    /**
     * Undo: brings the copy back from quarantine, then restores every logged value that still holds what the merge
     * wrote (a value changed since is skipped and reported). Resumable, like step().
     */
    public static function undo(int $id): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => __('No such merge.', 'elinweb-unused-media-finder')];
        }
        if ($m['outcome'] === 'deleted') {
            return ['error' => __('The replaced copy was deleted permanently, so this merge cannot be undone.', 'elinweb-unused-media-finder')];
        }
        if (!in_array($m['state'], ['done', 'applying', 'undoing'], true)) {
            return ['error' => __('This merge has nothing to undo.', 'elinweb-unused-media-finder')];
        }
        $dup = (int) $m['dup_id'];
        if ($m['state'] !== 'undoing') {
            if ($dup && get_post_type($dup) !== 'attachment') {
                return ['error' => __('The replaced copy no longer exists, so this merge cannot be undone.', 'elinweb-unused-media-finder')];
            }
            if ($dup && get_post_meta($dup, Cleanup::META, true) && !in_array($dup, Cleanup::restore([$dup]), true)) {
                return ['error' => __('The replaced copy could not be moved back from quarantine (its original path may be taken), so nothing was changed.', 'elinweb-unused-media-finder')];
            }
            $m = self::set($m, ['state' => 'undoing', 'outcome' => $m['outcome'] === 'quarantined' ? 'restored' : $m['outcome']]);
        }
        $deadline = microtime(true) + self::BUDGET;
        while ($rows = self::rows($id, 1, 20)) {
            self::revert($rows);
            if (microtime(true) > $deadline) {
                return ['merge' => $id, 'done' => false];
            }
        }
        self::reindex(self::rows($id), $m);
        $skipped = self::rows($id, 3);
        $note = $skipped
            /* translators: %s: list of places */
            ? sprintf(__('Values changed after the merge were left as they are: %s', 'elinweb-unused-media-finder'), implode(wp_get_list_item_separator(), array_map([self::class, 'label'], $skipped)))
            : '';
        return self::result(self::set($m, ['state' => 'undone', 'note' => $note]));
    }

    // ---- rows ------------------------------------------------------------------------------------------------

    /** @return string|null the value stored now, byte for byte; null when the row is gone */
    private static function current(array $row): ?string
    {
        global $wpdb;
        switch ($row['tbl']) {
            case 'posts':
                $col = in_array($row['col'], self::COLS, true) ? $row['col'] : 'post_content';
                return $wpdb->get_var($wpdb->prepare("SELECT `$col` FROM $wpdb->posts WHERE ID = %d", (int) $row['row_key']));
            case 'postmeta':
            case 'termmeta':
            case 'usermeta':
                $pk = $row['tbl'] === 'usermeta' ? 'umeta_id' : 'meta_id';
                return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->{$row['tbl']}} WHERE $pk = %d", (int) $row['row_key']));
            case 'options':
                return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $row['row_key']));
        }
        return null;
    }

    /** Writes the exact value and cleans the caches that hold it; true only when the database now holds it. */
    private static function write(array $row, string $value): bool
    {
        global $wpdb;
        $owner = (int) $row['src_id'];
        switch ($row['tbl']) {
            case 'posts':
                if (!in_array($row['col'], self::COLS, true)) {
                    return false;
                }
                $ok = $wpdb->update($wpdb->posts, [$row['col'] => $value], ['ID' => (int) $row['row_key']]);
                clean_post_cache((int) $row['row_key']);
                break;
            case 'postmeta':
            case 'termmeta':
            case 'usermeta':
                $ok = $wpdb->update($wpdb->{$row['tbl']}, ['meta_value' => $value], [$row['tbl'] === 'usermeta' ? 'umeta_id' : 'meta_id' => (int) $row['row_key']]); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the row is found by its primary key; meta_value is only the column written
                $type = array_search($row['tbl'], self::META, true);
                wp_cache_delete($owner, "{$type}_meta");
                if ($type === 'post') {
                    clean_post_cache($owner);
                } elseif ($type === 'user') {
                    clean_user_cache($owner);
                }
                break;
            case 'options':
                $ok = $wpdb->update($wpdb->options, ['option_value' => $value], ['option_name' => $row['row_key']]);
                wp_cache_delete($row['row_key'], 'options');
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
                break;
            default:
                return false;
        }
        return $ok !== false && self::current($row) === $value;
    }

    /** Elementor caches built from data the merge changed: removed, so Elementor builds them again from the new data. */
    private static function clear(string $key): void
    {
        global $wpdb;
        if (preg_match('/^meta:(\d+):(.+)$/', $key, $m) && in_array($m[2], self::CACHE_META, true)) {
            delete_post_meta((int) $m[1], $m[2]);
        } elseif (strpos($key, 'file:') === 0 && ($file = self::css_file(substr($key, 5))) !== '') {
            if (is_file($file)) {
                wp_delete_file($file);
            }
            if (preg_match('~/post-(\d+)\.css$~', $file, $p)) {
                delete_post_meta((int) $p[1], '_elementor_css'); // the file is rebuilt when this is missing
            }
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Scanner::table() . " WHERE source_type = 'file' AND source_key = %s", substr($key, 5)));
        }
    }

    /** Re-reads every source the merge touched (and any the copy is still indexed in) with the live index. */
    private static function reindex(array $rows, array $m): void
    {
        $sources = [];
        $refs = $m['dup_id'] ? Scanner::refs((int) $m['dup_id'], self::MAX_REFS) : [];
        foreach (array_merge($rows, array_map(fn($r) => ['src_type' => $r['source_type'], 'src_id' => $r['source_id'], 'src_key' => $r['source_key']], $refs)) as $row) {
            $type = $row['src_type'];
            if ($type === 'option') {
                $sources['option'][$row['src_key']] = true;
            } elseif (in_array($type, ['post', 'term', 'user'], true)) {
                $sources[$type][(int) $row['src_id']] = true;
            }
        }
        Scanner::reindex(array_map('array_keys', $sources));
    }

    private static function revised(int $merge, int $post): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::rows_table() . " WHERE merge_id = %d AND tbl = 'posts' AND row_key = %s AND done = 1", $merge, (string) $post));
    }

    /** Rows of a merge in log order; $done filters by state, $limit pages. */
    private static function rows(int $merge, ?int $done = null, int $limit = 0): array
    {
        global $wpdb;
        if (!self::ready()) {
            return [];
        }
        $sql = $wpdb->prepare('SELECT * FROM ' . self::rows_table() . ' WHERE merge_id = %d', $merge)
            . ($done === null ? '' : $wpdb->prepare(' AND done = %d', $done))
            . ' ORDER BY id' . ($limit ? $wpdb->prepare(' LIMIT %d', $limit) : '');
        return $wpdb->get_results($sql, ARRAY_A);
    }

    private static function mark(array $row, int $done): void
    {
        global $wpdb;
        $wpdb->update(self::rows_table(), ['done' => $done], ['id' => (int) $row['id']]);
    }

    private static function pack(string $s): string
    {
        return function_exists('gzdeflate') ? 'z:' . base64_encode((string) gzdeflate($s, 6)) : "r:$s";
    }

    private static function unpack(string $s): string
    {
        return strpos($s, 'z:') === 0 ? (string) gzinflate((string) base64_decode(substr($s, 2))) : substr($s, 2);
    }

    // ---- the log ---------------------------------------------------------------------------------------------

    public static function get(int $id): ?array
    {
        global $wpdb;
        if (!self::ready()) {
            return null;
        }
        $m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return $m ?: null;
    }

    private static function set(array $m, array $fields): array
    {
        global $wpdb;
        $wpdb->update(self::table(), $fields, ['id' => (int) $m['id']]);
        return $fields + $m;
    }

    /** The latest merges, newest first, with the number of values each changed. */
    public static function recent(int $n = 20): array
    {
        global $wpdb;
        if (!self::ready()) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT m.*, (SELECT COUNT(*) FROM ' . self::rows_table() . " r WHERE r.merge_id = m.id AND r.tbl <> 'cache') AS n FROM " . self::table() . ' m ORDER BY m.id DESC LIMIT %d',
            $n
        ), ARRAY_A);
    }

    /** Keeps the log bounded: only the last KEEP_LOGS merges stay (and can be undone). */
    private static function prune(): void
    {
        global $wpdb;
        $old = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table() . " WHERE state NOT IN ('logging', 'applying', 'undoing') ORDER BY id DESC LIMIT 1000000 OFFSET %d", self::KEEP_LOGS)));
        if ($old) {
            $wpdb->query('DELETE FROM ' . self::rows_table() . ' WHERE merge_id IN (' . implode(',', $old) . ')');
            $wpdb->query('DELETE FROM ' . self::table() . ' WHERE id IN (' . implode(',', $old) . ')');
        }
    }

    /** Whether "Undo" can be offered for this log entry. */
    public static function undoable(array $m): bool
    {
        return in_array($m['state'], ['done', 'applying', 'undoing'], true) && $m['outcome'] !== 'deleted';
    }

    /** A place in plain text: post #12 (content), option site_icon, … */
    public static function label(array $r): string
    {
        $type = $r['src_type'] ?? $r['source_type'];
        $id = (int) ($r['src_id'] ?? $r['source_id']);
        $key = (string) ($r['src_key'] ?? $r['source_key']);
        if ($type === 'option') {
            /* translators: %s: option name */
            return sprintf(__('option %s', 'elinweb-unused-media-finder'), $key);
        }
        if ($type === 'file') {
            return $key;
        }
        /* translators: 1: kind of object (post, term, user), 2: its id, 3: field */
        return sprintf(__('%1$s #%2$s (%3$s)', 'elinweb-unused-media-finder'), $type, $id, $key === '' ? __('content', 'elinweb-unused-media-finder') : $key);
    }

    /** What happened, in words, for the screen and WP-CLI. */
    public static function result(array $m): array
    {
        // Places counted like counts(): a post (term, user) with several fields is one place.
        $place = fn($r) => $r['src_type'] . '|' . $r['src_id'] . (in_array($r['src_type'], ['post', 'term', 'user'], true) ? '' : '|' . $r['src_key']);
        $places = count(array_unique(array_map($place, array_filter(self::rows((int) $m['id']), fn($r) => $r['tbl'] !== 'cache'))));
        /* translators: %s: number of places */
        $text = sprintf(__('References rewritten in %s place(s).', 'elinweb-unused-media-finder'), number_format_i18n($places));
        $outcomes = [
            'quarantined' => __('The replaced copy was quarantined.', 'elinweb-unused-media-finder'),
            'deleted' => __('The replaced copy was deleted permanently.', 'elinweb-unused-media-finder'),
            'left' => $m['dup_id']
                ? __('The replaced copy could not be moved to quarantine; it is now unreferenced and listed under Unreferenced.', 'elinweb-unused-media-finder')
                : __('The extra file stays on disk; after the next scan finds no reference to it, it can be deleted.', 'elinweb-unused-media-finder'),
        ];
        switch ($m['state']) {
            case 'done':
                $text .= ' ' . ($outcomes[$m['outcome']] ?? '');
                break;
            case 'undone':
                $text = trim(__('The merge was undone.', 'elinweb-unused-media-finder') . ' ' . $m['note']);
                break;
            case 'undoing':
            case 'applying':
                $text = __('Interrupted; continue or undo it.', 'elinweb-unused-media-finder');
                break;
            default:
                $text = (string) $m['note'];
        }
        return ['merge' => (int) $m['id'], 'done' => true, 'state' => $m['state'], 'outcome' => $m['outcome'], 'message' => $text];
    }
}
