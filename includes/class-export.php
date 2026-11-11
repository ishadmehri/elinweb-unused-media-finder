<?php

namespace ElinwebUnusedMediaFinder;

defined('ABSPATH') || exit;

/**
 * The Unreferenced and Quarantine lists as a CSV (for a spreadsheet) or a TXT file (one URL per line).
 * Rows are written to the stream as they are built, a chunk of attachments at a time, so a large library is never
 * held in memory as text. Extra files are exported in the browser from the list it already holds.
 */
final class Export
{
    public const LISTS = ['unused', 'quarantine'];
    private const CHUNK = 500;

    /** Ids of every item of the list, in the tab's order (all pages, not only the one shown). @return int[] */
    public static function ids(string $list, string $type = '', string $order = 'largest'): array
    {
        if ($list === 'quarantine') {
            return array_keys(Cleanup::quarantined());
        }
        // ponytail: unused() holds one small [id, date, bytes] entry per item to sort them; the rows are streamed.
        return array_keys(Cleanup::unused(1, PHP_INT_MAX, $type, $order)['items']);
    }

    /** Writes the file to $out (php://output for a download, php://memory in tests). @param resource $out */
    public static function write($out, string $list, string $format, array $ids, int $chunk = self::CHUNK): void
    {
        $quarantine = $list === 'quarantine';
        if ($format === 'csv') {
            fwrite($out, "\xEF\xBB\xBF"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes to the response or memory stream, not a file. BOM: Excel reads Persian text as UTF-8 only with it
            fputcsv($out, array_keys(self::row(0, $quarantine)), ',', '"', '');
        }
        foreach (array_chunk($ids, max(1, $chunk)) as $part) {
            _prime_post_caches($part, false, true);
            foreach ($part as $id) {
                $row = self::row((int) $id, $quarantine);
                if ($format === 'csv') {
                    fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
                } else {
                    fwrite($out, $row['url'] . "\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above
                }
            }
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime(); // the primed posts are not needed again; keeps memory flat on big libraries
            }
        }
    }

    /** One row; with $id 0 only its keys matter (the CSV header). */
    public static function row(int $id, bool $quarantine): array
    {
        $rel = (string) get_post_meta($id, '_wp_attached_file', true);
        $row = [
            'id' => $id,
            'title' => (string) get_post_field('post_title', $id, 'raw'),
            'url' => (string) wp_get_attachment_url($id),
            'path' => $rel,
            'server_path' => wp_normalize_path((string) get_attached_file($id)),
            'size_bytes' => $id ? Cleanup::bytes($id, wp_get_attachment_metadata($id)) : 0,
            'mime' => (string) get_post_mime_type($id),
            'uploaded' => (string) get_post_time('c', true, $id),
            'status' => $quarantine ? 'quarantined' : 'unreferenced',
            'edit_url' => admin_url('post.php?post=' . $id . '&action=edit'),
        ];
        if (!$quarantine) {
            return $row;
        }
        $q = get_post_meta($id, Cleanup::META, true);
        $q = is_array($q) ? $q : [];
        if ($rel !== '' && $q) {
            $dir = Cleanup::qdir();
            $row['server_path'] = "$dir/$rel"; // where the file is now
            $row['size_bytes'] = array_sum(array_map(fn($f) => (int) @filesize("$dir/$f"), (array) ($q['files'] ?? []))); // what quarantine holds
        }
        $last = (int) ($q['last'] ?? 0);
        return $row + [
            'requests' => array_sum((array) ($q['hits'] ?? [])),
            'requested_during_test' => (int) ($q['test'] ?? 0),
            'last_request' => $last ? gmdate('c', $last) : '',
            'referring_pages' => implode(' | ', (array) ($q['pages'] ?? array_filter([$q['from'] ?? '']))),
        ];
    }

    /** A spreadsheet must not run a cell as a formula: text starting with = + - @ tab or CR gets a leading quote. */
    public static function cell($value)
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
