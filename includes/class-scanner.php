<?php

namespace ElinwebUnusedMediaFinder;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- This class works on the plugin's own index tables, which only exist here (no core API, so no cache to use).
// Every dynamic part of a query is a table name built from $wpdb->prefix, an int cast with (int)/intval/absint, an md5 hash,
// or a fragment already built with $wpdb->prepare(). Identifiers cannot be placeholders before WordPress 6.2 (minimum is 6.0).

/**
 * Reads the database (and, if asked, theme/plugin code) once, pulls out every reference to a media file
 * and stores it in one table. "Is this unused?" and "where is this used?" are both answered from that table,
 * so the cost grows with the size of the content, not with media × content.
 */
final class Scanner
{
    public const CAP = 'manage_options';
    private const DB = 4; // schema version; bump when install() changes

    private const JOB = 'mtrk_job';
    private const LAST = 'mtrk_last_scan';
    private const FILES = 'mtrk_files';
    private const LOCK = 'mtrk_lock';
    private const ROWS = 300;                // rows per batch
    private const BYTES = 8 * MB_IN_BYTES;   // ...and at most this much text, so page-builder JSON can't exhaust memory
    public const BUDGET = 8;                 // seconds of work per AJAX request
    private const MAX_FILE = MB_IN_BYTES;
    private const CODE_EXT = '/\.(php|css|js|json|html?|twig|scss|less)$/i';
    private const SKIP_DIRS = ['node_modules', 'vendor', '.git'];
    private const ACF_TYPES = ['image', 'file', 'gallery'];
    // Keys that hold a media id: attachment_id, image_id, poster_id / posterId, bgImgID, logo_id, cover_id, ...
    public const MEDIA_KEY = '(?:image|img|media|poster|cover|logo|icon|banner|background|bg|avatar|photo|picture|thumbnail|thumb|attachment|video|audio|file)_?id';
    private const MEDIA_TYPES = [ // Meta Box, JetEngine: field types whose value is media
        'image', 'image_advanced', 'image_upload', 'plupload_image', 'thickbox_image', 'single_image',
        'file', 'file_advanced', 'file_upload', 'video', 'media', 'gallery',
    ];
    private const META = [ // table => [source type, owner column, primary key]
        'postmeta' => ['post', 'post_id', 'meta_id'],
        'termmeta' => ['term', 'term_id', 'meta_id'],
        'usermeta' => ['user', 'user_id', 'umeta_id'],
        'commentmeta' => ['comment', 'comment_id', 'meta_id'],
    ];
    private const ID_META = ['_thumbnail_id', 'thumbnail_id'];
    public const ID_OPTIONS = ['site_icon', 'site_logo', 'woocommerce_placeholder_image'];
    private const LOG_TYPES = '(^|_)logs?$'; // MySQL REGEXP: post types that are logs (taxopress_logs, wp_log)
    private const LOG_OPTIONS = '/^(itsec_file_change|rank_math_seo_analysis_results$|simple_history|titan_scanner$)/'; // logs and caches
    private const SKIP_META ="'_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes'";

    /** @var array<string,string> normalized upload path => attachment ids "12,34": lookups made so far */
    private array $keys = [];
    /** @var array<int,bool> attachment id => is an attachment: lookups made so far */
    private array $ids = [];
    /** @var array<int,true> attachments whose references this instance wrote; their twins are re-checked */
    private array $touched = [];
    /** @var array<string,true> md5 of every exactly referenced upload path (stem), flushed by save() */
    private array $sized = [];
    /** @var array<string,?string[]> meta keys holding media ids in field plugins: null = the whole value, or sub-keys */
    private array $media_keys;
    /** @var array<string,?string[]> option names holding media ids (JetEngine options pages), same shape */
    private array $option_keys = [];
    /** @var array<string,array<string,?string[]>> table => column => sub-keys (JetEngine Custom Content Types) */
    private array $table_keys = [];
    /** @var string[] */
    private array $patterns;
    /** @var array<string,true> ACF field keys whose value is a media id */
    private array $acf;
    /** Extra WHERE for every batch; set when re-indexing a few sources instead of scanning everything. */
    private string $restrict = '';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_refs';
    }

    public static function paths_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_paths';
    }

    public static function files_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_files';
    }

    /**
     * The attachments' own index: normalized path (for lookups) and a hash of the exact file (for twins), so no
     * request has to hold every attachment in memory or scan postmeta.meta_value, which has no index.
     */
    public static function rebuild_index(): bool
    {
        global $wpdb;
        $table = self::files_table();
        $wpdb->query("TRUNCATE TABLE $table");
        $cursor = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_id > %d ORDER BY meta_id LIMIT 2000", $cursor), ARRAY_N);
            $values = [];
            foreach ($rows as [$meta_id, $id, $file]) {
                $values[] = $wpdb->prepare('(%d,%s,%s)', $id, mb_substr(self::key((string) $file), 0, 255), md5((string) $file));
                $cursor = (int) $meta_id;
            }
            if ($values) {
                $wpdb->query("REPLACE INTO $table (attachment_id, path_key, file_hash) VALUES " . implode(',', $values));
            }
        } while (count($rows) === 2000);
        // A missing or short index (table not created, a host refusing TRUNCATE/REPLACE) would make every id-based
        // reference vanish and featured images look unreferenced: check it rather than trust it.
        $want = (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file'");
        $have = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($have !== $want) {
            delete_option('mtrk_files_ready');
            return false;
        }
        update_option('mtrk_files_ready', self::DB);
        return true;
    }

    /**
     * Featured images the scan left unreferenced. Every one of them is used, so any here means the scan went wrong
     * on this site; deletions are refused until a clean scan.
     * @return int[]
     */
    public static function missed_featured(): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col("SELECT DISTINCT a.ID FROM $wpdb->postmeta m
            JOIN $wpdb->posts p ON p.ID = m.post_id
            JOIN $wpdb->posts a ON a.ID = m.meta_value AND a.post_type = 'attachment'
            WHERE m.meta_key = '_thumbnail_id' AND p.post_type NOT IN ('revision', 'attachment') AND p.post_status NOT IN ('auto-draft', 'trash')
              AND p.post_type NOT REGEXP '" . self::LOG_TYPES . "'
              AND NOT EXISTS (SELECT 1 FROM " . self::table() . ' r WHERE r.attachment_id = a.ID) LIMIT 50'));
    }

    /** Keeps the index current when an attachment's file is set or changed (uploads, edits, imports). */
    public static function file_meta($meta_id, $object_id, $key, $value): void
    {
        global $wpdb;
        if ($key === '_wp_attached_file' && is_string($value)) {
            $wpdb->replace(self::files_table(), ['attachment_id' => (int) $object_id, 'path_key' => mb_substr(self::key($value), 0, 255), 'file_hash' => md5($value)]);
        }
    }

    /** @return int[] attachments pointing at the same file as $id, itself included */
    public static function twins(int $id): array
    {
        global $wpdb;
        $table = self::files_table();
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT f.attachment_id FROM $table f JOIN $table a ON a.file_hash = f.file_hash WHERE a.attachment_id = %d ORDER BY f.attachment_id", $id)));
        return $ids ?: [$id];
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            source_type varchar(20) NOT NULL,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_key varchar(255) NOT NULL DEFAULT '',
            how varchar(4) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY ref (attachment_id,source_type,source_id,source_key(150)),
            KEY source (source_type,source_id)
        ) $charset;");
        // md5 of every upload path a reference names exactly ("2024/05/a-750x500"), so cleanups can keep it.
        dbDelta('CREATE TABLE ' . self::paths_table() . " (
            hash char(32) NOT NULL,
            PRIMARY KEY  (hash)
        ) $charset;");
        dbDelta('CREATE TABLE ' . self::files_table() . " (
            attachment_id bigint(20) unsigned NOT NULL,
            path_key varchar(255) NOT NULL DEFAULT '',
            file_hash char(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (attachment_id),
            KEY path_key (path_key(191)),
            KEY file_hash (file_hash)
        ) $charset;");
        // Duplicate check: size and content hash per attachment file (see Duplicates); survives full scans.
        dbDelta('CREATE TABLE ' . Duplicates::table() . " (
            attachment_id bigint(20) unsigned NOT NULL,
            file_hash char(32) NOT NULL DEFAULT '',
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            content_hash varchar(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (attachment_id),
            KEY file_size (file_size),
            KEY content_hash (content_hash),
            KEY file_hash (file_hash)
        ) $charset;");
        update_option('mtrk_db', self::DB);
    }

    /** Builds the attachments index if this install hasn't yet (after an upgrade). */
    public static function ensure_index(): void
    {
        if ((int) get_option('mtrk_files_ready') !== self::DB) {
            self::rebuild_index(); // once after an upgrade; every full scan rebuilds it anyway
        }
    }

    public static function upgrade(): void
    {
        if ((int) get_option('mtrk_db') !== self::DB) {
            self::install();
        }
    }

    /** Normalized uploads path without its extension(s): lowercase, NFC, no leading slash. */
    public static function stem(string $path): string
    {
        // ASCII-only on purpose: before PHP 8.2 strtolower() follows the process locale and can corrupt UTF-8 bytes.
        $path = strtr(ltrim(wp_normalize_path($path), '/'), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        if (class_exists('Normalizer') && preg_match('/[^\x00-\x7f]/', $path)) {
            // WordPress stores names NFC-normalized; a decomposed reference (macOS, some editors) must still match.
            $path = \Normalizer::normalize($path, \Normalizer::FORM_C) ?: $path;
        }
        return preg_replace('/(\.[a-z0-9]{2,5}){1,2}$/', '', $path);
    }

    /** Matching key: "2024/05/a-300x200.jpg.webp", "2024/05/a-scaled.jpg" and "2024/05/a.jpg" all become "2024/05/a". */
    public static function key(string $path): string
    {
        return self::unsize(self::stem($path));
    }

    private static function unsize(string $stem): string
    {
        return preg_replace('/(-\d+x\d+|-scaled|-rotated|-e\d{13})+$/', '', $stem);
    }

    /** @return array<string,true> which of these stems some reference names exactly (by md5) */
    public static function referenced(array $stems): array
    {
        global $wpdb;
        $hashes = array_map(fn($s) => "'" . md5(self::stem($s)) . "'", $stems);
        if (!$hashes) {
            return [];
        }
        return array_fill_keys($wpdb->get_col('SELECT hash FROM ' . self::paths_table() . ' WHERE hash IN (' . implode(',', $hashes) . ')'), true);
    }

    public function __construct()
    {
        self::ensure_index();

        $chars = '[^\s"\'<>()\[\]{}|?#,;*]';
        $ext = '\.[a-z0-9]{2,5}(?![a-z0-9])';
        $this->patterns = ["~(?<![0-9])((?:19|20)[0-9]{2}/[01][0-9]/$chars+$ext)~i"];
        $base = trim((string) wp_parse_url(wp_upload_dir(null, false)['baseurl'], PHP_URL_PATH), '/');
        if ($base !== '') {
            $this->patterns[] = '~' . preg_quote($base, '~') . "/($chars+$ext)~i";
        }
        $this->patterns[] = "~\\\$upload\\\$/($chars+$ext)~i"; // Smart Slider stores uploads paths as $upload$/...
        $this->acf = $this->acf_media_fields();
        $this->media_keys = $this->media_fields();
    }

    // ---- job -------------------------------------------------------------------------------------------------

    public static function start(bool $code, bool $tables): array
    {
        global $wpdb;
        $steps = ['posts', 'postmeta', 'termmeta', 'usermeta', 'commentmeta', 'options', 'terms', 'comments'];
        if ($tables) {
            foreach (self::extra_tables() as $table) {
                $steps[] = "table:$table";
            }
        }
        if ($code) {
            $steps[] = 'files';
        }

        if (!self::rebuild_index()) { // keep the previous results rather than produce wrong ones
            return ['error' => sprintf(
                /* translators: %s: database error */
                __('Building the media index failed on this site, so the scan did not start and the previous result was left untouched. Database error: %s', 'elinweb-unused-media-finder'),
                $wpdb->last_error ?: '—'
            )];
        }
        $wpdb->query('TRUNCATE TABLE ' . self::table());
        $wpdb->query('TRUNCATE TABLE ' . self::paths_table());
        delete_option(self::LAST);
        $job = [
            'steps' => $steps,
            'i' => 0,
            'cursor' => 0,
            'code' => $code,
            'tables' => $tables,
            'started' => time(),
            'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM $wpdb->posts WHERE post_type = 'attachment'"),
        ];
        update_option(self::JOB, $job, false);
        return self::progress($job);
    }

    /** Works through batches for BUDGET seconds. Batches are idempotent (INSERT IGNORE), so a retried request is harmless. */
    public static function run_step(): array
    {
        global $wpdb;
        $job = self::job();
        if (!$job) {
            return ['done' => true];
        }
        if (!add_option(self::LOCK, time(), '', false)) {
            if (time() - (int) get_option(self::LOCK) < 60) {
                return ['busy' => true] + self::progress($job);
            }
            update_option(self::LOCK, time(), false); // a request died holding the lock
        }

        try {
            $scanner = new self();
            $deadline = microtime(true) + self::BUDGET;
            do {
                $next = $scanner->batch($job['steps'][$job['i']], $job);
                if ($next === null) {
                    $job['i']++;
                    $job['cursor'] = 0;
                    unset($job['total']);
                } else {
                    $job['cursor'] = $next;
                }
                update_option(self::JOB, $job, false);
            } while ($job['i'] < count($job['steps']) && microtime(true) < $deadline);

            if ($job['i'] >= count($job['steps'])) {
                $wpdb->query($wpdb->prepare( // "keep" marks survive rescans (and protect twins via share())
                    'INSERT IGNORE INTO ' . self::table() . " (attachment_id, source_type, source_id, source_key, how) SELECT post_id, 'keep', 0, '', 'id' FROM $wpdb->postmeta WHERE meta_key = %s",
                    Cleanup::KEEP
                ));
                $scanner->share();
                update_option(self::LAST, [
                    'time' => time(),
                    'max_id' => $job['max_id'],
                    'code' => $job['code'],
                    'tables' => $job['tables'],
                    'secs' => time() - $job['started'],
                    'paths' => 2, // exact referenced paths recorded: 1 = sized files only, 2 = all (orphan files need 2)
                    'suspect' => self::missed_featured(), // non-empty: this scan is not trusted, deletions refused
                ], false);
                delete_option(self::JOB);
                delete_option(self::FILES);
                return ['done' => true];
            }
            return self::progress($job);
        } finally {
            delete_option(self::LOCK);
        }
    }

    public static function cancel(): void
    {
        delete_option(self::JOB);
        delete_option(self::FILES);
        delete_option(self::LOCK);
    }

    public static function job(): ?array
    {
        $job = get_option(self::JOB);
        return is_array($job) ? $job : null;
    }

    /** A finished scan that passed its own check; only then may anything be quarantined or deleted. */
    public static function trusted(): bool
    {
        $last = self::last();
        return $last && empty($last['suspect']);
    }

    public static function last(): ?array
    {
        $last = get_option(self::LAST);
        return is_array($last) ? $last : null;
    }

    public static function progress(array $job): array
    {
        $n = count($job['steps']);
        $frac = empty($job['total']) ? 0 : min(1, $job['cursor'] / $job['total']);
        return [
            'done' => false,
            'percent' => (int) floor(($job['i'] + $frac) / $n * 100),
            'label' => self::label($job['steps'][min($job['i'], $n - 1)]),
        ];
    }

    private static function label(string $step): string
    {
        $labels = [
            'posts' => __('Post content', 'elinweb-unused-media-finder'),
            'postmeta' => __('Post meta', 'elinweb-unused-media-finder'),
            'termmeta' => __('Term meta', 'elinweb-unused-media-finder'),
            'usermeta' => __('User meta', 'elinweb-unused-media-finder'),
            'commentmeta' => __('Comment meta', 'elinweb-unused-media-finder'),
            'options' => __('Settings', 'elinweb-unused-media-finder'),
            'terms' => __('Term descriptions', 'elinweb-unused-media-finder'),
            'comments' => __('Comments', 'elinweb-unused-media-finder'),
            'files' => __('Code files', 'elinweb-unused-media-finder'),
        ];
        /* translators: %s: database table name */
        return $labels[$step] ?? sprintf(__('Table %s', 'elinweb-unused-media-finder'), substr($step, 6));
    }

    // ---- results ---------------------------------------------------------------------------------------------

    /** @return array<int,int> attachment id => number of places (a post with three fields is one place), one query */
    public static function counts(array $ids): array
    {
        global $wpdb;
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }
        $out = [];
        $place = "source_type, source_id, IF(source_type IN ('post', 'term', 'user', 'comment'), '', source_key)";
        foreach ($wpdb->get_results("SELECT attachment_id, COUNT(DISTINCT $place) FROM " . self::table() . ' WHERE attachment_id IN (' . implode(',', $ids) . ') GROUP BY attachment_id', ARRAY_N) as [$id, $n]) {
            $out[(int) $id] = (int) $n;
        }
        return $out;
    }

    /**
     * none = never scanned, used, unused, new = uploaded after the last full scan and not used anywhere the live
     * index sees (it may still be in plugin tables or code, which only a full scan reads).
     */
    public static function status(int $id, int $count): string
    {
        $last = self::last();
        if (!$last) {
            return 'none';
        }
        if ($count) {
            return 'used';
        }
        return $id > $last['max_id'] ? 'new' : 'unused';
    }

    public static function refs(int $id, int $limit = 500): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT attachment_id, source_type, source_id, source_key, how FROM ' . self::table() . ' WHERE attachment_id = %d ORDER BY source_type, source_id, source_key LIMIT %d', $id, $limit), ARRAY_A);
    }

    /**
     * The first $per references of each attachment, for a whole page of rows in one query (one indexed branch per id).
     * @return array<int,array[]> attachment id => rows shaped like refs()
     */
    public static function refs_for(array $ids, int $per = 8): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $table = self::table();
        $per = max(1, $per);
        $parts = array_map(fn($id) => "(SELECT attachment_id, source_type, source_id, source_key, how FROM $table WHERE attachment_id = $id ORDER BY source_type, source_id, source_key LIMIT $per)", $ids);
        $out = [];
        foreach ($wpdb->get_results(implode(' UNION ALL ', $parts), ARRAY_A) as $r) {
            $out[(int) $r['attachment_id']][] = $r;
        }
        return $out;
    }

    /**
     * Where a scan would find this upload path (an extra file), searched now: the index keeps only a hash of each
     * referenced path, not its place. Same sources, filters and URL forms as a full scan within the last scan's
     * scope (plugin tables and code files only when it read them). Stops after $budget seconds or $limit places.
     * $code = false leaves code files out: reading every theme and plugin file is the slow part.
     * @return array{refs: array[], complete: bool} refs shaped like refs(), attachment_id 0 and how 'url'
     */
    public static function locate(string $path, int $limit = 20, int $budget = self::BUDGET, bool $code = true): array
    {
        global $wpdb;
        $scanner = new self();
        $stem = self::stem($path);
        $name = (string) preg_replace('/(\.[a-z0-9]{2,5}){1,2}$/i', '', wp_basename($path));
        // How the name can be written: as is, URL-encoded, \u-escaped in JSON. LIKE only narrows; stems() decides.
        $terms = array_unique([$name, rawurlencode($name), substr((string) wp_json_encode($name), 1, -1)]);
        $deadline = microtime(true) + $budget;
        $last = self::last() ?: ['code' => false, 'tables' => false];
        $skip = apply_filters('mtrk_skip_options', self::LOG_OPTIONS);
        $out = [];
        $complete = true;

        // One source: rows whose columns mention the name, then the exact check. $key(row, column) names the place.
        $search = function (string $table, string $pk, string $select, array $cols, string $where, string $type, callable $key) use ($wpdb, $scanner, $stem, $terms, $deadline, $limit, $skip, &$out, &$complete): bool {
            $like = [];
            foreach ($cols as $c) {
                foreach ($terms as $t) {
                    $like[] = $wpdb->prepare("t.`$c` LIKE %s", '%' . $wpdb->esc_like($t) . '%');
                }
            }
            $list = implode(', ', array_map(fn($c) => "t.`$c`", $cols));
            $cursor = 0;
            do {
                if (microtime(true) > $deadline || count($out) >= $limit) {
                    $complete = false;
                    return false;
                }
                $rows = $pk === ''
                    ? $wpdb->get_results("SELECT 0 AS pk, $list FROM `$table` t WHERE (" . implode(' OR ', $like) . ") $where LIMIT 1000", ARRAY_A)
                    : $wpdb->get_results("SELECT t.`$pk` AS pk, $select$list FROM `$table` t WHERE t.`$pk` > $cursor AND (" . implode(' OR ', $like) . ") $where ORDER BY t.`$pk` LIMIT 200", ARRAY_A);
                foreach ($rows as $r) {
                    $cursor = (int) $r['pk'];
                    if ($type === 'option' && preg_match($skip, $r['k'])) {
                        continue;
                    }
                    foreach ($cols as $c) {
                        if (in_array($stem, $scanner->stems((string) $r[$c]), true)) {
                            $k = $key($r, $c);
                            $sid = (int) ($r['sid'] ?? $r['pk']);
                            $out["$type|$sid|$k"] = ['attachment_id' => 0, 'source_type' => $type, 'source_id' => $sid, 'source_key' => $k, 'how' => 'url'];
                        }
                    }
                }
                if ($pk === '' && count($rows) === 1000) {
                    $complete = false; // a keyless table with this many matches: not read to the end
                }
            } while ($pk !== '' && count($rows) === 200);
            return true;
        };

        $none = fn() => '';
        $search($wpdb->posts, 'ID', '', ['post_content', 'post_excerpt'], "AND t.post_type NOT IN ('revision', 'attachment') AND t.post_type NOT REGEXP '" . self::LOG_TYPES . "' AND t.post_status <> 'auto-draft'", 'post', $none);
        foreach (self::META as $meta => [$type, $owner, $pk]) {
            $where = 'AND t.meta_key NOT IN (' . self::SKIP_META . ')';
            if ($meta === 'postmeta') {
                $where .= " AND NOT EXISTS (SELECT 1 FROM $wpdb->posts p WHERE p.ID = t.post_id AND (p.post_type = 'revision' OR p.post_type REGEXP '" . self::LOG_TYPES . "'))";
            }
            $search($wpdb->$meta, $pk, "t.`$owner` AS sid, t.meta_key AS k, ", ['meta_value'], $where, $type, fn($r) => (string) $r['k']);
        }
        $search($wpdb->options, 'option_id', '0 AS sid, t.option_name AS k, ', ['option_value'], "AND t.option_name NOT LIKE '%\\_transient\\_%' AND t.option_name NOT LIKE 'mtrk\\_%'", 'option', fn($r) => (string) $r['k']);
        $search($wpdb->term_taxonomy, 'term_taxonomy_id', 't.term_id AS sid, ', ['description'], '', 'term', fn() => 'description');
        $search($wpdb->comments, 'comment_ID', '', ['comment_content'], '', 'comment', $none);
        if (!empty($last['tables'])) {
            foreach (self::extra_tables() as $table) {
                $cfg = self::discover($table);
                if ($cfg) {
                    $search($table, (string) $cfg[1], '', $cfg[3], '', 'table', fn($r, $c) => "$table.$c");
                }
            }
        }
        if ($code && !empty($last['code'])) {
            $root = wp_normalize_path(WP_CONTENT_DIR) . '/';
            foreach (self::code_files() as $file) {
                if (microtime(true) > $deadline || count($out) >= $limit) {
                    $complete = false;
                    break;
                }
                if (@filesize($file) > self::MAX_FILE || ($text = @file_get_contents($file)) === false) {
                    continue;
                }
                $mentions = array_filter($terms, fn($t) => stripos($text, $t) !== false);
                if ($mentions && in_array($stem, $scanner->stems($text), true)) {
                    $k = str_replace($root, '', $file);
                    $out["file|0|$k"] = ['attachment_id' => 0, 'source_type' => 'file', 'source_id' => 0, 'source_key' => $k, 'how' => 'url'];
                }
            }
        }
        return ['refs' => array_slice(array_values($out), 0, $limit), 'complete' => $complete];
    }

    public static function summary(): array
    {
        global $wpdb;
        $last = self::last();
        if (!$last) {
            return [];
        }
        $max = (int) $last['max_id'];
        [$scanned, $after] = array_map('intval', $wpdb->get_row("SELECT SUM(ID <= $max), SUM(ID > $max) FROM $wpdb->posts WHERE post_type = 'attachment'", ARRAY_N));
        [$used, $used_after] = array_map('intval', $wpdb->get_row("SELECT COUNT(DISTINCT r.attachment_id), COUNT(DISTINCT IF(r.attachment_id > $max, r.attachment_id, NULL)) FROM " . self::table() . " r JOIN $wpdb->posts p ON p.ID = r.attachment_id", ARRAY_N));
        return [
            'used' => $used,
            'unused' => $scanned - ($used - $used_after),
            'new' => $after - $used_after,
        ];
    }

    /** WHERE fragment for the media library "usage" filter. */
    public static function where(string $usage): string
    {
        global $wpdb;
        $last = self::last();
        if (!$last) {
            return ' AND 1=0';
        }
        $max = (int) $last['max_id'];
        $in = "$wpdb->posts.ID IN (SELECT attachment_id FROM " . self::table() . ')';
        switch ($usage) {
            case 'used':
                return " AND $in";
            case 'unused':
                return " AND $wpdb->posts.ID <= $max AND NOT $in";
            case 'new':
                return " AND $wpdb->posts.ID > $max AND NOT $in";
        }
        return '';
    }

    /**
     * Live index: re-reads just these sources with the same extractors as a full scan and replaces their
     * references. $sources: type => ids (for 'option': option names).
     */
    public static function reindex(array $sources): void
    {
        global $wpdb;
        $steps = [ // source type => [scan step => column that restricts it]
            'post' => ['posts' => 'ID', 'postmeta' => 'post_id'],
            'term' => ['termmeta' => 'term_id', 'terms' => 'term_id'],
            'user' => ['usermeta' => 'user_id'],
            'comment' => ['commentmeta' => 'comment_id', 'comments' => 'comment_ID'],
            'option' => ['options' => 'option_name'],
        ];
        $scanner = new self();
        foreach (array_intersect_key($sources, $steps) as $type => $ids) {
            if (!$ids) {
                continue;
            }
            $list = implode(',', array_map(fn($id) => $type === 'option' ? $wpdb->prepare('%s', $id) : (int) $id, $ids));
            $where = " WHERE source_type = '$type' AND " . ($type === 'option' ? 'source_key' : 'source_id') . " IN ($list)";
            foreach ($wpdb->get_col('SELECT DISTINCT attachment_id FROM ' . self::table() . $where) as $id) {
                $scanner->touched[(int) $id] = true; // losing a reference may release a twin
            }
            $wpdb->query('DELETE FROM ' . self::table() . $where);
            foreach ($steps[$type] as $step => $col) {
                $scanner->restrict = "AND t.`$col` IN ($list)";
                $job = ['cursor' => 0];
                do {
                    $job['cursor'] = $scanner->batch($step, $job);
                } while ($job['cursor'] !== null);
            }
        }
        $scanner->share(array_keys($scanner->touched));
    }

    /** Re-checks the twins of these attachments (after a reference was added or removed outside a scan). */
    public static function reshare(array $ids): void
    {
        (new self())->share(array_map('intval', $ids));
    }

    public static function forget(int $post_id): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE attachment_id = %d OR (source_type IN ('post', 'shared') AND source_id = %d)", $post_id, $post_id));
        $wpdb->delete(self::files_table(), ['attachment_id' => $post_id]);
        $wpdb->delete(Duplicates::table(), ['attachment_id' => $post_id]);
    }

    /**
     * Deleting an attachment deletes its file even when another attachment points at the same file (WordPress only
     * checks that for the legacy "thumb"). So when one member of such a group is used, the others are marked used
     * too, with a 'shared' reference to the used one. After a full scan all groups are checked; with $affected
     * (live saves) only the groups of those attachments.
     */
    private function share(?array $affected = null): void
    {
        global $wpdb;
        $table = self::table();
        $files = self::files_table();
        $in = '';
        if ($affected !== null) {
            if (!$affected) {
                return;
            }
            $in = " WHERE file_hash IN (SELECT file_hash FROM $files WHERE attachment_id IN (" . implode(',', array_map('intval', $affected)) . '))';
        }
        $groups = [];
        foreach ($wpdb->get_col("SELECT GROUP_CONCAT(attachment_id ORDER BY attachment_id) FROM $files$in GROUP BY file_hash HAVING COUNT(*) > 1") as $list) {
            $groups[] = array_map('intval', explode(',', $list));
        }
        $members = $groups ? array_merge(...$groups) : [];
        if ($affected === null) {
            $wpdb->query("DELETE FROM $table WHERE source_type = 'shared'");
        } elseif ($members) {
            $wpdb->query("DELETE FROM $table WHERE source_type = 'shared' AND attachment_id IN (" . implode(',', $members) . ')');
        }
        if (!$groups) {
            return;
        }
        $used = array_fill_keys(array_map('intval', $wpdb->get_col("SELECT DISTINCT attachment_id FROM $table WHERE attachment_id IN (" . implode(',', $members) . ')')), true);
        $refs = [];
        foreach ($groups as $group) {
            foreach (array_filter($group, fn($id) => isset($used[$id])) as $source) {
                foreach ($group as $id) {
                    if ($id !== $source) {
                        $refs[] = [$id, 'shared', $source, '', 'id'];
                    }
                }
            }
        }
        $this->save($refs);
    }

    // ---- batches ---------------------------------------------------------------------------------------------

    /** Processes one batch of $step; returns the next cursor, or null when the step is finished. */
    private function batch(string $step, array &$job): ?int
    {
        global $wpdb;
        $cursor = (int) $job['cursor'];
        if ($step === 'posts') {
            return $this->posts($cursor, $job);
        }
        if (isset(self::META[$step])) {
            return $this->meta($step, $cursor, $job);
        }
        if ($step === 'options') {
            return $this->options($cursor, $job);
        }
        if ($step === 'terms') {
            return $this->scan_table($wpdb->term_taxonomy, $cursor, $job, ['term', 'term_taxonomy_id', 'term_id', ['description'], 'description']);
        }
        if ($step === 'comments') {
            return $this->scan_table($wpdb->comments, $cursor, $job, ['comment', 'comment_ID', 'comment_ID', ['comment_content'], '']);
        }
        if ($step === 'files') {
            return $this->files($cursor, $job);
        }
        return $this->scan_table(substr($step, 6), $cursor, $job, null);
    }

    private function posts(int $cursor, array &$job): ?int
    {
        global $wpdb;
        $rows = $this->rows($wpdb->posts, 'ID', 't.post_content, t.post_excerpt', 'LENGTH(t.post_content) + LENGTH(t.post_excerpt)',
            "AND t.post_type NOT IN ('revision', 'attachment') AND t.post_type NOT REGEXP '" . self::LOG_TYPES . "' AND t.post_status <> 'auto-draft'", $cursor, $job);
        $refs = [];
        foreach ($rows as $r) {
            $text = $r['post_content'] . "\n" . $r['post_excerpt'];
            $this->add($refs, $this->in_text($text) + $this->in_content($text), 'post', (int) $r['pk'], '');
        }
        $this->save($refs);
        return $rows ? (int) end($rows)['pk'] : null;
    }

    private function meta(string $name, int $cursor, array &$job): ?int
    {
        global $wpdb;
        [$type, $owner, $pk] = self::META[$name];
        $table = $wpdb->$name;
        $where = 'AND t.meta_key NOT IN (' . self::SKIP_META . ')';
        if ($name === 'postmeta') {
            $where .= " AND NOT EXISTS (SELECT 1 FROM $wpdb->posts p WHERE p.ID = t.post_id AND (p.post_type = 'revision' OR p.post_type REGEXP '" . self::LOG_TYPES . "'))";
        }
        $rows = $this->rows($table, $pk, "t.`$owner` AS sid, t.meta_key AS k, t.meta_value AS v", 'LENGTH(t.meta_value)', $where, $cursor, $job);
        $acf = $this->acf_ids($table, $owner, 'meta_key', 'meta_value', $rows);
        $refs = [];
        foreach ($rows as $i => $r) {
            $v = (string) $r['v'];
            $field = array_key_exists($r['k'], $this->media_keys) ? $this->only($this->media_ids($v, $this->media_keys[$r['k']])) : [];
            $this->add($refs, $this->in_text($v) + $this->in_meta($r['k'], $v) + ($acf[$i] ?? []) + $field, $type, (int) $r['sid'], $r['k']);
        }
        $this->save($refs);
        return $rows ? (int) end($rows)['pk'] : null;
    }

    private function options(int $cursor, array &$job): ?int
    {
        global $wpdb;
        $rows = $this->rows($wpdb->options, 'option_id', '0 AS sid, t.option_name AS k, t.option_value AS v', 'LENGTH(t.option_value)',
            "AND t.option_name NOT LIKE '%\\_transient\\_%' AND t.option_name NOT LIKE 'mtrk\\_%'", $cursor, $job);
        $acf = $this->acf_ids($wpdb->options, '', 'option_name', 'option_value', $rows);
        $refs = [];
        $skip = apply_filters('mtrk_skip_options', self::LOG_OPTIONS);
        foreach ($rows as $i => $r) {
            if (preg_match($skip, $r['k'])) {
                continue;
            }
            $v = (string) $r['v'];
            $ids = $this->in_serialized($v);
            if (in_array($r['k'], self::ID_OPTIONS, true)) {
                $ids[] = $v;
            }
            $field = array_key_exists($r['k'], $this->option_keys) ? $this->only($this->media_ids($v, $this->option_keys[$r['k']])) : [];
            $this->add($refs, $this->in_text($v) + $this->only($ids) + ($acf[$i] ?? []) + $field, 'option', 0, $r['k']);
        }
        $this->save($refs);
        return $rows ? (int) end($rows)['pk'] : null;
    }

    /**
     * A table read for upload paths only.
     * $cfg: [source type, primary key, owner column, columns, source key]; null = discover it (plugin tables).
     */
    private function scan_table(string $table, int $cursor, array &$job, ?array $cfg): ?int
    {
        global $wpdb;
        $cfg = $cfg ?? self::discover($table);
        if (!$cfg) {
            return null;
        }
        [$type, $pk, $owner, $cols, $key] = $cfg;
        $list = implode(', ', array_map(fn($c) => "t.`$c`", $cols));
        if ($pk) {
            $size = implode(' + ', array_map(fn($c) => "COALESCE(LENGTH(t.`$c`), 0)", $cols));
            $rows = $this->rows($table, $pk, ($owner ? "t.`$owner` AS sid, " : '') . $list, $size, '', $cursor, $job);
            $next = $rows ? (int) end($rows)['pk'] : null;
        } else {
            // ponytail: OFFSET paging for tables without a numeric primary key; slow only on huge keyless tables.
            if (!isset($job['total'])) {
                $job['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            }
            $rows = $wpdb->get_results("SELECT $list FROM `$table` t LIMIT " . self::ROWS . " OFFSET $cursor", ARRAY_A);
            $next = count($rows) === self::ROWS ? $cursor + self::ROWS : null;
        }
        $refs = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $v = (string) $r[$c];
                $field = array_key_exists($c, $this->table_keys[$table] ?? []) ? $this->only($this->media_ids($v, $this->table_keys[$table][$c])) : [];
                $this->add($refs, $this->in_text($v) + $this->only($this->in_serialized($v)) + $field, $type, (int) ($r['sid'] ?? $r['pk'] ?? 0), $key ?? "$table.$c");
            }
        }
        $this->save($refs);
        return $next;
    }

    private function files(int $cursor, array &$job): ?int
    {
        if ($cursor === 0) {
            update_option(self::FILES, self::code_files(), false);
        }
        $files = (array) get_option(self::FILES, []);
        $job['total'] = count($files);
        $end = min(count($files), $cursor + 100);
        $root = wp_normalize_path(WP_CONTENT_DIR) . '/';
        $refs = [];
        for ($i = $cursor; $i < $end; $i++) {
            $file = $files[$i];
            if (@filesize($file) > self::MAX_FILE || ($text = @file_get_contents($file)) === false) {
                continue;
            }
            $this->add($refs, $this->in_text($text), 'file', 0, str_replace($root, '', $file));
        }
        $this->save($refs);
        return $end < count($files) ? $end : null;
    }

    /** Next rows after $cursor by primary key: at most ROWS rows and about BYTES of text. */
    private function rows(string $table, string $pk, string $cols, string $size, string $where, int $cursor, array &$job): array
    {
        global $wpdb;
        if (!isset($job['total'])) {
            $job['total'] = (int) $wpdb->get_var("SELECT MAX(`$pk`) FROM `$table`");
        }
        $sizes = $wpdb->get_results("SELECT t.`$pk`, $size FROM `$table` t WHERE t.`$pk` > $cursor $where {$this->restrict} ORDER BY t.`$pk` LIMIT " . self::ROWS, ARRAY_N);
        $ids = [];
        $bytes = 0;
        foreach ($sizes as [$id, $n]) {
            $ids[] = (int) $id;
            $bytes += (int) $n;
            if ($bytes > self::BYTES) {
                break;
            }
        }
        if (!$ids) {
            return [];
        }
        $rows = $wpdb->get_results("SELECT t.`$pk` AS pk, $cols FROM `$table` t WHERE t.`$pk` IN (" . implode(',', $ids) . ") ORDER BY t.`$pk`", ARRAY_A);
        $this->prefetch($rows);
        return $rows;
    }

    private function add(array &$refs, array $found, string $type, int $sid, string $key): void
    {
        foreach ($found as $id => $how) {
            if ($type === 'post' && $id === $sid) {
                continue; // an attachment's own data
            }
            $refs[] = [$id, $type, $sid, $key, $how];
        }
    }

    private function save(array $refs): void
    {
        global $wpdb;
        foreach ($refs as $r) {
            $this->touched[$r[0]] = true;
        }
        foreach (array_chunk($refs, 300) as $chunk) {
            $values = array_map(fn($r) => $wpdb->prepare('(%d,%s,%d,%s,%s)', $r[0], $r[1], $r[2], mb_substr($r[3], 0, 255), $r[4]), $chunk);
            $wpdb->query('INSERT IGNORE INTO ' . self::table() . ' (attachment_id, source_type, source_id, source_key, how) VALUES ' . implode(',', $values));
        }
        foreach (array_chunk(array_keys($this->sized), 500) as $chunk) {
            $wpdb->query('INSERT IGNORE INTO ' . self::paths_table() . " (hash) VALUES ('" . implode("'),('", $chunk) . "')");
        }
        $this->sized = [];
    }

    // ---- extraction ------------------------------------------------------------------------------------------

    /** Upload paths in any text: URLs (absolute, relative, CDN), JSON-escaped, percent-encoded or \u-escaped. */
    private function in_text(string $text): array
    {
        $found = [];
        foreach ($this->paths_in($text) as $path) {
            $stem = self::stem($path);
            foreach ($this->lookup(self::unsize($stem)) as $id) {
                $found[$id] = 'url';
            }
            $this->sized[md5($stem)] = true; // every exact path: size cleanup and orphan files check against it
        }
        return $found;
    }

    /** @return string[] upload-relative paths, decoded (URL-encoded, \u-escaped, entities, JSON slashes) */
    private function paths_in(string $text): array
    {
        if (stripos($text, '%2F') !== false) { // URL-encoded storage, e.g. Elementor dynamic tag settings
            $text .= "\n" . urldecode($text);
        }
        if (strpos($text, '&#') !== false) { // non-Latin names written as numeric HTML entities
            $text .= "\n" . html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (strpos($text, '\/') !== false) {
            $text = str_replace('\/', '/', $text);
        }
        $paths = [];
        foreach ($this->patterns as $re) {
            if (!preg_match_all($re, $text, $m)) {
                continue;
            }
            foreach (array_unique($m[1]) as $path) {
                if (strpos($path, '%') !== false) {
                    $path = rawurldecode($path);
                }
                if (strpos($path, '\u') !== false) {
                    $path = (string) json_decode('"' . $path . '"');
                }
                $paths[$path] = true;
            }
        }
        return array_keys($paths);
    }

    /** @return string[] the stem of every upload path in this text, read the way a scan reads it */
    public function stems(string $text): array
    {
        return array_values(array_unique(array_map([self::class, 'stem'], $this->paths_in($text))));
    }

    /**
     * What a scan reads in one value: attachment id => 'url'|'id'. $source: 'post' (content or excerpt), a meta
     * table ('postmeta', 'termmeta', 'usermeta', 'commentmeta') or 'option'; $sid owns the value (meta only).
     * Merge uses it to prove that a rewritten value names the kept copy and nothing else changed.
     */
    public function found(string $source, string $value, string $key = '', int $sid = 0): array
    {
        global $wpdb;
        if ($source === 'post') {
            return $this->in_text($value) + $this->in_content($value);
        }
        $row = [['sid' => $sid, 'k' => $key, 'v' => $value]];
        if ($source === 'option') {
            $ids = $this->in_serialized($value);
            if (in_array($key, self::ID_OPTIONS, true)) {
                $ids[] = $value;
            }
            $field = array_key_exists($key, $this->option_keys) ? $this->only($this->media_ids($value, $this->option_keys[$key])) : [];
            return $this->in_text($value) + $this->only($ids) + ($this->acf_ids($wpdb->options, '', 'option_name', 'option_value', $row)[0] ?? []) + $field;
        }
        $field = array_key_exists($key, $this->media_keys) ? $this->only($this->media_ids($value, $this->media_keys[$key])) : [];
        return $this->in_text($value) + $this->in_meta($key, $value) + ($this->acf_ids($wpdb->$source, self::META[$source][1], 'meta_key', 'meta_value', $row)[0] ?? []) + $field;
    }

    /** Resolves every path in a batch of rows with one query instead of one per path. */
    private function prefetch(array $rows): void
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                foreach (is_string($value) && $value !== '' ? $this->paths_in($value) : [] as $path) {
                    $keys[] = self::unsize(self::stem($path));
                }
            }
        }
        $this->resolve($keys);
    }

    /** Ids in post content: image classes, core media blocks, gallery-style shortcodes. */
    private function in_content(string $c): array
    {
        $ids = [];
        if (preg_match_all('/\bwp-image-(\d+)/', $c, $m)) {
            $ids = $m[1];
        }
        // Any block (plugin blocks too): attributes named like a media id, e.g. posterId, bgImgID, logo_id.
        if (preg_match_all('~<!-- wp:[\w/-]+ (\{.*?\}) /?-->~s', $c, $m)) {
            foreach ($m[1] as $json) {
                if (preg_match_all('/"[a-z_]*' . self::MEDIA_KEY . '"\s*:\s*"?(\d+)/i', $json, $mm)) {
                    $ids = array_merge($ids, $mm[1]);
                }
            }
        }
        if (preg_match_all('~<!-- wp:(?:core/)?(?:image|cover|media-text|video|audio|file|gallery) (\{.*?\}) /?-->~s', $c, $m)) {
            foreach ($m[1] as $json) {
                if (preg_match_all('/"(?:id|mediaId)":(\d+)|"ids":\[([\d,]+)\]/', $json, $mm)) {
                    $ids = array_merge($ids, $mm[1], explode(',', implode(',', $mm[2])));
                }
            }
        }
        if (preg_match_all('/\[[\w-]+\s[^\]]*?\b(?:ids|include|images?|poster|cover|logo|background|thumbnail|photo|[a-z_]*' . self::MEDIA_KEY . ')\s*=\s*["\']?([\d, ]+)/i', $c, $m)) {
            foreach ($m[1] as $list) {
                $ids = array_merge($ids, explode(',', $list));
            }
        }
        return $this->only($ids) + $this->in_acf_blocks($c);
    }

    /** ACF blocks keep field values in the block comment: {"data":{"img":12,"_img":"field_abc"}} or {"data":{"field_abc":12}}. */
    private function in_acf_blocks(string $c): array
    {
        if (!$this->acf || strpos($c, '<!-- wp:acf/') === false || !preg_match_all('~<!-- wp:acf/[\w-]+ (\{.*?\}) /?-->~s', $c, $m)) {
            return [];
        }
        $ids = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true)['data'] ?? [];
            foreach (is_array($data) ? $data : [] as $k => $v) {
                $field = strpos((string) $k, 'field_') === 0 ? $k : ($data["_$k"] ?? '');
                if (is_string($field) && isset($this->acf[$field])) {
                    $ids = array_merge($ids, array_filter((array) $v, 'is_scalar'));
                }
            }
        }
        return $this->only($ids);
    }

    private function in_meta(string $key, string $v): array
    {
        $ids = $this->in_serialized($v);
        if (in_array($key, self::ID_META, true)) {
            $ids[] = $v;
        } elseif ($key === '_product_image_gallery') {
            $ids = array_merge($ids, explode(',', $v));
        } elseif ($key === '_elementor_data') {
            if (strpos($v, 'elementor-tag') !== false) {
                $v .= urldecode($v); // dynamic tag settings (fallback images) are URL-encoded JSON
            }
            // Classic controls: {"url":..,"id":12} (element ids are quoted strings). V4 atomic: image-attachment-id.
            if (preg_match_all('/"id":(\d+)|"image-attachment-id","value":(\d+)/', $v, $m)) {
                $ids = array_merge($ids, $m[1], $m[2]);
            }
        }
        return $this->only($ids);
    }

    /** Ids under well-known keys inside serialized or JSON values (widgets, theme mods, avatars, galleries). */
    private function in_serialized(string $v): array
    {
        if (strpos($v, '"') === false) {
            return [];
        }
        $ids = [];
        if (preg_match_all('/"(?:[a-z_]*' . self::MEDIA_KEY . '|custom_logo|site_logo|site_icon)"(?:;(?:i:|s:\d+:")|\s*:\s*"?)(\d+)/i', $v, $m)) {
            $ids = $m[1];
        }
        if (preg_match_all('/"ids";a:\d+:\{([^}]*)\}/', $v, $m)) {
            foreach ($m[1] as $list) {
                if (preg_match_all('/i:\d+;(?:i:|s:\d+:")(\d+)/', $list, $mm)) {
                    $ids = array_merge($ids, $mm[1]);
                }
            }
        }
        return $ids;
    }

    /**
     * ACF stores image/file/gallery fields as bare ids; the sibling "_name" row holds the field key, and the field
     * key tells the type. Without that check every number field would look like a media id.
     * $owner = '' for the options table.
     */
    private function acf_ids(string $table, string $owner, string $key, string $val, array $rows): array
    {
        global $wpdb;
        if (!$this->acf) {
            return [];
        }
        $cands = [];
        foreach ($rows as $i => $r) {
            if (($r['k'][0] ?? '_') !== '_' && preg_match('/^(\d+|a:\d+:\{.*\})$/s', (string) $r['v'])) {
                $cands[$i] = $owner === '' ? $wpdb->prepare('%s', '_' . $r['k']) : $wpdb->prepare('(%d,%s)', $r['sid'], '_' . $r['k']);
            }
        }
        if (!$cands) {
            return [];
        }
        $select = ($owner === '' ? '0' : "`$owner`") . " AS sid, `$key` AS k, `$val` AS v";
        $where = ($owner === '' ? "`$key`" : "(`$owner`, `$key`)") . ' IN (' . implode(',', array_unique($cands)) . ')';
        $fields = [];
        foreach ($wpdb->get_results("SELECT $select FROM `$table` WHERE $where", ARRAY_A) as $f) {
            if (isset($this->acf[$f['v']])) {
                $fields[$f['sid'] . '|' . $f['k']] = true;
            }
        }
        $out = [];
        foreach (array_keys($cands) as $i) {
            $r = $rows[$i];
            if (isset($fields[$r['sid'] . '|_' . $r['k']])) {
                $out[$i] = $this->only((array) maybe_unserialize($r['v']));
            }
        }
        return $out;
    }

    private function acf_media_fields(): array
    {
        global $wpdb;
        $keys = [];
        foreach ($wpdb->get_results("SELECT post_name, post_content FROM $wpdb->posts WHERE post_type = 'acf-field'") as $f) {
            $s = maybe_unserialize($f->post_content);
            if (in_array($s['type'] ?? '', self::ACF_TYPES, true)) {
                $keys[$f->post_name] = true;
            }
        }
        if (function_exists('acf_get_local_fields')) { // fields registered in PHP
            foreach ((array) acf_get_local_fields() as $f) {
                if (in_array($f['type'] ?? '', self::ACF_TYPES, true)) {
                    $keys[$f['key']] = true;
                }
            }
        }
        return $keys;
    }

    /**
     * Meta keys that field plugins other than ACF use for media ids, from their own field registries. Values may be
     * an id, "1,2,3", a serialized or JSON array, {"id":..,"url":..}, or (null => sub-keys) a group/repeater.
     * @return array<string,?string[]>
     */
    private function media_fields(): array
    {
        global $wpdb;
        $keys = [];

        // Meta Box (registered in code): the field id is the meta key; a group keeps its sub-fields in one value.
        if (function_exists('rwmb_get_registry')) {
            foreach (rwmb_get_registry('meta_box')->all() as $box) {
                foreach ((array) ($box->meta_box['fields'] ?? []) as $f) {
                    $sub = ($f['type'] ?? '') === 'group' ? self::media_subfields((array) ($f['fields'] ?? []), 'id', 'fields') : null;
                    if ($sub) {
                        $keys[$f['id']] = $sub;
                    } elseif (in_array($f['type'] ?? '', self::MEDIA_TYPES, true) && !empty($f['id'])) {
                        $keys[$f['id']] = null;
                    }
                }
            }
        }

        // Pods: field definitions are _pods_field posts; values sit under the field name (and _pods_<name>).
        foreach ($wpdb->get_col("SELECT p.post_name FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = 'type' AND m.meta_value = 'file' WHERE p.post_type = '_pods_field'") as $name) {
            $keys[$name] = null;
            $keys["_pods_$name"] = null;
        }

        $jet = self::jet_engine();
        $this->option_keys = $jet['options'];
        $this->table_keys = $jet['tables'];
        return (array) apply_filters('mtrk_media_meta_keys', $keys + $jet['meta']);
    }

    /**
     * JetEngine's media fields, wherever their values live (format from its source, 3.9):
     * meta boxes (option jet_engine_meta_boxes), post types and taxonomies ({prefix}jet_post_types / jet_taxonomies)
     * → meta keys; options pages (jet_post_types, status 'page') → one option named after the page, or one per
     * field; Custom Content Types (status 'content-type') → columns of {prefix}jet_cct_<slug>.
     * @return array{meta: array, options: array, tables: array} key => null (whole value) or media sub-keys
     */
    public static function jet_engine(): array
    {
        global $wpdb;
        $out = ['meta' => [], 'options' => [], 'tables' => []];
        $meta = [];
        foreach ((array) get_option('jet_engine_meta_boxes', []) as $box) {
            $meta = array_merge($meta, (array) ($box['meta_fields'] ?? []));
        }
        $taxonomies = $wpdb->prefix . 'jet_taxonomies';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($taxonomies)))) {
            foreach ($wpdb->get_col("SELECT meta_fields FROM `$taxonomies`") as $list) {
                $meta = array_merge($meta, (array) maybe_unserialize($list));
            }
        }
        $types = $wpdb->prefix . 'jet_post_types';
        $rows = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($types)))
            ? $wpdb->get_results("SELECT slug, status, args, meta_fields FROM `$types`", ARRAY_A) : [];
        foreach ($rows as $row) {
            $fields = (array) maybe_unserialize($row['meta_fields']);
            if ($row['status'] === 'page') {
                $args = (array) maybe_unserialize($row['args']);
                if (($args['storage_type'] ?? 'default') === 'separate') {
                    $prefix = ($args['option_prefix'] ?? true) ? $row['slug'] . '_' : '';
                    foreach ($fields as $f) {
                        if ($media = self::jet_media((array) $f)) {
                            $out['options'][$prefix . $media[0]] = $media[1];
                        }
                    }
                } elseif ($names = self::jet_media_names($fields)) {
                    $out['options'][$row['slug']] = $names;
                }
            } elseif ($row['status'] === 'content-type') {
                foreach ($fields as $f) {
                    if ($media = self::jet_media((array) $f)) {
                        $out['tables'][$wpdb->prefix . 'jet_cct_' . $row['slug']][$media[0]] = $media[1];
                    }
                }
            } else {
                $meta = array_merge($meta, $fields);
            }
        }
        foreach ($meta as $f) {
            if ($media = self::jet_media((array) $f)) {
                $out['meta'][$media[0]] = $media[1];
            }
        }
        return $out;
    }

    /** @return array{0:string,1:?string[]}|null [field name, media sub-fields of a repeater or null] */
    private static function jet_media(array $f): ?array
    {
        if (empty($f['name'])) {
            return null;
        }
        if (($f['type'] ?? '') === 'repeater') {
            $sub = self::media_subfields((array) ($f['repeater-fields'] ?? []), 'name', 'repeater-fields');
            return $sub ? [$f['name'], $sub] : null;
        }
        return in_array($f['type'] ?? '', self::MEDIA_TYPES, true) ? [$f['name'], null] : null;
    }

    /** Names of every media field (repeaters: their media sub-fields), for values stored together in one array. */
    private static function jet_media_names(array $fields): array
    {
        $names = [];
        foreach ($fields as $f) {
            $media = self::jet_media((array) $f);
            if ($media) {
                $names = array_merge($names, $media[1] ?? [$media[0]]);
            }
        }
        return $names;
    }

    /** Names of media sub-fields, at any depth (groups in groups). */
    private static function media_subfields(array $fields, string $idKey, string $childKey): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (in_array($f['type'] ?? '', self::MEDIA_TYPES, true) && !empty($f[$idKey])) {
                $out[] = $f[$idKey];
            }
            $out = array_merge($out, self::media_subfields((array) ($f[$childKey] ?? []), $idKey, $childKey));
        }
        return $out;
    }

    /** Media ids in a field value; with $sub, only under those keys (at any depth). */
    private function media_ids(string $v, ?array $sub): array
    {
        $data = maybe_unserialize($v);
        if (is_string($data) && preg_match('/^\s*[\[{]/', $data)) {
            $data = json_decode($data, true) ?? $data;
        }
        $ids = [];
        $walk = function ($x, bool $in) use (&$walk, &$ids, $sub): void {
            if (is_array($x)) {
                if ($in && isset($x['id']) && is_scalar($x['id'])) {
                    $ids[] = $x['id']; // {"id":12,"url":"..."}: the id, not width/height and friends
                    return;
                }
                foreach ($x as $k => $y) {
                    $walk($y, $in || ($sub !== null && in_array($k, $sub, true)));
                }
            } elseif ($in && is_scalar($x) && preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', (string) $x)) {
                $ids = array_merge($ids, explode(',', (string) $x));
            }
        };
        $walk($data, $sub === null);
        return $ids;
    }

    /** @return int[] attachments whose path normalizes to $key */
    private function lookup(string $key): array
    {
        if (!isset($this->keys[$key])) {
            $this->resolve([$key]);
        }
        $list = $this->keys[$key] ?? '';
        return $list === '' ? [] : array_map('intval', explode(',', $list));
    }

    /** Looks these normalized paths up in the attachments index (indexed IN query) and remembers the answers. */
    private function resolve(array $keys): void
    {
        global $wpdb;
        $todo = array_values(array_filter(array_unique(array_map('strval', $keys)), fn($k) => !isset($this->keys[$k])));
        foreach (array_chunk($todo, 500) as $chunk) {
            $found = array_fill_keys($chunk, '');
            $in = implode(',', array_map(fn($k) => $wpdb->prepare('%s', mb_substr($k, 0, 255)), $chunk));
            foreach ($wpdb->get_results('SELECT attachment_id, path_key FROM ' . self::files_table() . " WHERE path_key IN ($in)", ARRAY_N) as [$id, $key]) {
                if (isset($found[$key])) { // exact: the column's collation may match more loosely than PHP
                    $found[$key] .= ($found[$key] === '' ? '' : ',') . $id;
                    $this->ids[(int) $id] = true;
                }
            }
            foreach ($found as $key => $list) {
                $this->keys[(string) $key] = $list;
            }
        }
    }

    private function only(array $ids): array
    {
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $unknown = array_diff($ids, array_keys($this->ids));
        if ($unknown) {
            global $wpdb;
            $found = array_map('intval', $wpdb->get_col('SELECT attachment_id FROM ' . self::files_table() . ' WHERE attachment_id IN (' . implode(',', $unknown) . ')'));
            foreach ($unknown as $id) {
                $this->ids[$id] = in_array($id, $found, true);
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (!empty($this->ids[$id])) {
                $out[$id] = 'id';
            }
        }
        return $out;
    }

    // ---- discovery -------------------------------------------------------------------------------------------

    private static function extra_tables(): array
    {
        global $wpdb;
        $core = array_values($wpdb->tables('all'));
        $own = $wpdb->prefix . 'mtrk_'; // this plugin's tables (index, extra files list, merge log) name paths without using them
        // Logs, sessions, caches and indexes derived from content: they mention files without using them.
        $skip = apply_filters('mtrk_skip_tables', '/_(log|logs|sessions|cache)$|actionscheduler_|wc_admin_|_lookup$|_stats$|simple_history|rank_math_internal_links$|yoast_(indexable|seo_)/i');
        $out = [];
        foreach ($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')) as $t) {
            $other_site = is_multisite() && preg_match('/^' . preg_quote($wpdb->base_prefix, '/') . '\d+_/', $t) && $wpdb->prefix === $wpdb->base_prefix;
            if (!in_array($t, $core, true) && strpos($t, $own) !== 0 && !preg_match($skip, $t) && !$other_site) {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function discover(string $table): ?array
    {
        global $wpdb;
        $pk = null;
        $pri = 0;
        $cols = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A) as $c) {
            if ($c['Key'] === 'PRI') {
                $pri++;
                if (preg_match('/^(tiny|small|medium|big)?int/i', $c['Type'])) {
                    $pk = $c['Field'];
                }
            } elseif (preg_match('/char|text|json/i', $c['Type'])) {
                $cols[] = $c['Field'];
            }
        }
        return $cols ? ['table', $pri === 1 ? $pk : null, null, $cols, null] : null;
    }

    /** Active theme(s), active plugins, mu-plugins, and code files (css/js/...) inside uploads. */
    private static function code_files(): array
    {
        $dirs = [get_stylesheet_directory(), get_template_directory(), WPMU_PLUGIN_DIR, wp_upload_dir(null, false)['basedir']];
        $self = plugin_basename(MTRK_FILE);
        $plugins = array_merge((array) get_option('active_plugins', []), array_keys((array) get_site_option('active_sitewide_plugins', [])));
        foreach ($plugins as $p) {
            if ($p !== $self) {
                $dirs[] = WP_PLUGIN_DIR . '/' . (strpos($p, '/') ? dirname($p) : $p);
            }
        }
        $files = [];
        foreach (array_unique(array_map('wp_normalize_path', $dirs)) as $dir) {
            if (is_file($dir)) {
                $files[] = $dir;
                continue;
            }
            if (!is_dir($dir)) {
                continue;
            }
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    fn($f) => $f->isDir() ? !in_array($f->getFilename(), self::SKIP_DIRS, true) : (bool) preg_match(self::CODE_EXT, $f->getFilename())
                ));
                foreach ($it as $f) {
                    $files[] = wp_normalize_path($f->getPathname());
                }
            } catch (\UnexpectedValueException $e) {
                continue; // unreadable directory
            }
        }
        return array_values(array_unique($files));
    }
}
