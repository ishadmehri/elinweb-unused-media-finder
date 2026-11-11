<?php

namespace ElinwebUnusedMediaFinder;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Bulk reads of postmeta for a scan: the result must be current, so a cache would be wrong.

/**
 * Files on disk that no attachment owns: leftover sizes of a known image that its metadata no longer lists
 * (e.g. after regenerating thumbnails), and files that never were in the library (FTP uploads, imports).
 *
 * Only folders holding at least one attachment are read, non-recursively, so plugin folders (gravity_forms form
 * uploads, wc-logs, elementor, ...) are never touched. Never listed: an attachment's own files (main, sizes,
 * original before -scaled, image-editor backups), WebP/AVIF copies next to them, and anything a reference names.
 */
final class Orphans
{
    private const DIRS = 20; // folders per request
    private const ZIP_CAP = 209715200; // 200 MB per downloaded ZIP

    /** @return array{next: ?int, total: int, files: array<int,array{path:string,bytes:int,type:string,owner:int,kept:bool}>} */
    public static function step(int $cursor): array
    {
        $dirs = self::dirs();
        $files = [];
        foreach (array_slice($dirs, $cursor, self::DIRS) as $dir) {
            $files = array_merge($files, self::scan_dir($dir));
        }
        $next = $cursor + self::DIRS;
        return ['next' => $next < count($dirs) ? $next : null, 'total' => count($dirs), 'files' => $files];
    }

    /**
     * Deletes the given upload-relative paths, but only those a fresh look at their folder still lists as
     * deletable: a path from the browser is never trusted on its own.
     * @return array{files:int,bytes:int,skipped:int}
     */
    public static function delete(array $paths): array
    {
        $base = wp_upload_dir(null, false)['basedir'];
        $out = ['files' => 0, 'bytes' => 0, 'skipped' => 0];
        $listed = self::listed($paths, true);
        foreach ($paths as $path) {
            if (!isset($listed[$path])) {
                $out['skipped']++;
                continue;
            }
            wp_delete_file("$base/$path");
            $out['files']++;
            $out['bytes'] += $listed[$path];
        }
        return $out;
    }

    /** Whether a fresh look at its folder lists this upload-relative path as an extra file (kept or not). */
    public static function is_extra(string $path): bool
    {
        return $path !== '' && isset(self::listed([$path], false)[$path]);
    }

    /**
     * The given upload-relative paths that a fresh look at their folder still lists (so they are real extra files),
     * with their sizes. A path from the browser is never trusted on its own.
     * @return array<string,int> path => bytes
     */
    private static function listed(array $paths, bool $deletable_only): array
    {
        $byDir = [];
        foreach ($paths as $path) {
            $byDir[dirname($path) === '.' ? '' : dirname($path)][] = $path;
        }
        $listed = [];
        foreach (array_keys($byDir) as $dir) {
            if (!in_array($dir, self::dirs(), true)) {
                continue;
            }
            foreach (self::scan_dir($dir) as $f) {
                if (!$deletable_only || !$f['kept']) {
                    $listed[$f['path']] = $f['bytes'];
                }
            }
        }
        return array_intersect_key($listed, array_flip($paths));
    }

    /**
     * A temporary ZIP of the given extra files, for the caller to send and delete.
     * Only files the fresh listing shows go in, and one ZIP is capped: PHP's time and memory make big ones fail.
     * @return array{file:string,count:int,bytes:int}|array{error:string}
     */
    public static function zip(array $paths, int $cap = self::ZIP_CAP): array
    {
        $listed = self::listed($paths, false);
        if (!$listed) {
            return ['error' => __('Nothing selected.', 'elinweb-unused-media-finder')];
        }
        $bytes = array_sum($listed);
        if ($bytes > $cap) {
            /* translators: 1: size of the selection, 2: size limit of one ZIP */
            return ['error' => sprintf(__('The selection is %1$s; one ZIP is limited to %2$s. Select fewer files.', 'elinweb-unused-media-finder'), size_format($bytes), size_format($cap))];
        }
        if (!class_exists('ZipArchive')) {
            return ['error' => __('ZIP support (ZipArchive) is not available on this server.', 'elinweb-unused-media-finder')];
        }
        $base = wp_upload_dir(null, false)['basedir'];
        $tmp = wp_tempnam('mtrk-extra.zip');
        $zip = new \ZipArchive();
        if (!$tmp || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return ['error' => __('Could not create the ZIP file.', 'elinweb-unused-media-finder')];
        }
        foreach (array_keys($listed) as $path) {
            $zip->addFile("$base/$path", $path);
            $zip->setCompressionName($path, \ZipArchive::CM_STORE); // audio, video and images don't shrink; skip the CPU time
        }
        if (!$zip->close()) {
            wp_delete_file($tmp);
            return ['error' => __('Could not create the ZIP file.', 'elinweb-unused-media-finder')];
        }
        return ['file' => $tmp, 'count' => count($listed), 'bytes' => $bytes];
    }

    /** Folders (relative to uploads, '' = uploads itself) that hold at least one attachment. */
    private static function dirs(): array
    {
        global $wpdb;
        static $dirs = null;
        if ($dirs === null) {
            $dirs = [];
            foreach ($wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file'") as $file) {
                $dir = dirname(ltrim(wp_normalize_path((string) $file), '/'));
                $dirs[$dir === '.' ? '' : $dir] = true;
            }
            $dirs = array_keys($dirs);
            sort($dirs);
        }
        return $dirs;
    }

    /** @return array<int,array{path:string,bytes:int,type:string,owner:int,kept:bool}> */
    private static function scan_dir(string $dir): array
    {
        global $wpdb;
        $abs = wp_upload_dir(null, false)['basedir'] . ($dir === '' ? '' : "/$dir");
        $names = @scandir($abs);
        if (!$names) {
            return [];
        }

        // What the attachments of this folder own.
        $like = $dir === '' ? '%' : $wpdb->esc_like("$dir/") . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.post_id, f.meta_value AS file, m.meta_value AS meta, b.meta_value AS backup
             FROM $wpdb->postmeta f
             LEFT JOIN $wpdb->postmeta m ON m.post_id = f.post_id AND m.meta_key = '_wp_attachment_metadata'
             LEFT JOIN $wpdb->postmeta b ON b.post_id = f.post_id AND b.meta_key = '_wp_attachment_backup_sizes'
             WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE %s",
            $like
        ));
        $known = [];  // stem => true: owned files, and through the stem their .webp/.avif copies
        $owners = []; // unsized stem of a main file => attachment id
        foreach ($rows as $r) {
            $file = ltrim(wp_normalize_path((string) $r->file), '/');
            if ((dirname($file) === '.' ? '' : dirname($file)) !== $dir) {
                continue; // LIKE also matched deeper folders
            }
            $main = wp_basename($file);
            $own = [$main];
            $meta = maybe_unserialize($r->meta);
            if (is_array($meta)) {
                $own[] = $meta['original_image'] ?? '';
                $own[] = is_string($meta['thumb'] ?? null) ? $meta['thumb'] : ''; // audio/video cover art
                foreach ((array) ($meta['sizes'] ?? []) as $s) {
                    $own[] = $s['file'] ?? '';
                }
            }
            foreach ((array) maybe_unserialize($r->backup) as $s) {
                $own[] = is_array($s) ? ($s['file'] ?? '') : '';
            }
            foreach (array_filter($own) as $name) {
                $known[Scanner::stem($name)] = true;
            }
            $owners[Scanner::key($main)] = (int) $r->post_id;
        }

        $media = '/\.(' . implode('|', array_map(fn($e) => preg_quote($e, '/'), self::extensions())) . ')$/i';
        $found = [];
        foreach ($names as $name) {
            if ($name[0] === '.' || !preg_match($media, $name) || isset($known[Scanner::stem($name)]) || !is_file("$abs/$name")) {
                continue;
            }
            $stem = Scanner::stem($name);
            $owner = preg_match('/-\d+x\d+$/', $stem) ? ($owners[Scanner::key($name)] ?? 0) : 0;
            $path = $dir === '' ? $name : "$dir/$name";
            $found[$path] = ['path' => $path, 'bytes' => (int) filesize("$abs/$name"), 'type' => $owner ? 'size' : 'file', 'owner' => $owner, 'kept' => false];
        }
        $referenced = Scanner::referenced(array_keys($found));
        foreach ($found as $path => $f) {
            $found[$path]['kept'] = isset($referenced[md5(Scanner::stem($path))]);
        }
        return array_values($found);
    }

    /** File extensions WordPress accepts as uploads (php and the like never are). */
    private static function extensions(): array
    {
        $ext = [];
        foreach (array_keys(get_allowed_mime_types()) as $list) {
            $ext = array_merge($ext, explode('|', $list));
        }
        return array_merge($ext, ['webp', 'avif']);
    }
}
