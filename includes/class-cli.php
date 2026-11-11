<?php

namespace ElinwebUnusedMediaFinder;

use WP_CLI;
use WP_CLI\Utils;

defined('ABSPATH') || exit;

/**
 * Find unreferenced media, see where media is used, and act on it.
 *
 * ## EXAMPLES
 *
 *     wp elinweb-unused-media-finder scan --code
 *     wp elinweb-unused-media-finder unused --format=csv > unused.csv
 *     wp elinweb-unused-media-finder usage 123
 *     wp elinweb-unused-media-finder quarantine $(wp elinweb-unused-media-finder unused --format=ids)
 *     wp elinweb-unused-media-finder quarantine-test start --hours=6
 */
final class CLI
{
    /**
     * Runs a full scan (replaces the previous result).
     *
     * ## OPTIONS
     *
     * [--code]
     * : Also read the active theme, active plugins, mu-plugins and CSS/JS inside uploads.
     *
     * [--no-tables]
     * : Skip plugins' own database tables.
     */
    public function scan(array $args, array $assoc): void
    {
        $started = Scanner::start((bool) Utils\get_flag_value($assoc, 'code', false), (bool) Utils\get_flag_value($assoc, 'tables', true));
        if (isset($started['error'])) {
            WP_CLI::error($started['error']);
        }
        $bar = Utils\make_progress_bar('Scanning', 100);
        $at = 0;
        do {
            $p = Scanner::run_step();
            if (!empty($p['busy'])) {
                sleep(2); // a scan step from the admin screen holds the lock
            }
            $now = (int) ($p['percent'] ?? 100);
            $bar->tick(max(0, $now - $at));
            $at = max($at, $now);
        } while (empty($p['done']));
        $bar->tick(max(0, 100 - $at));
        $bar->finish();
        $this->status([], []);
    }

    /**
     * Shows the last scan and the counts.
     *
     * @subcommand status
     */
    public function status(array $args, array $assoc): void
    {
        $last = Scanner::last();
        if (!$last) {
            WP_CLI::warning('No scan yet: run `wp elinweb-unused-media-finder scan --code`.');
            return;
        }
        $s = Scanner::summary();
        WP_CLI::log(sprintf('Last scan: %s, %s%s, %ss', wp_date('Y-m-d H:i', $last['time']), $last['code'] ? 'database + code' : 'database only', $last['tables'] ? ' + plugin tables' : '', $last['secs']));
        WP_CLI::log(sprintf('Used: %d  Unreferenced: %d  New, unreferenced: %d  Quarantined: %d', $s['used'], $s['unused'], $s['new'], count(Cleanup::quarantined())));
        if (!Scanner::trusted()) {
            WP_CLI::warning('Not trusted: featured images came out unreferenced (#' . implode(', #', $last['suspect']) . '). Quarantine and delete are refused; rerun the scan and report it if it repeats.');
        }
    }

    /**
     * Lists unreferenced media from the last full scan (not quarantined, not kept).
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : image, video, audio or document.
     *
     * [--order=<order>]
     * : largest, newest or oldest.
     * ---
     * default: largest
     * ---
     *
     * [--format=<format>]
     * : table, csv, json, ids or count.
     * ---
     * default: table
     * ---
     */
    public function unused(array $args, array $assoc): void
    {
        $list = Cleanup::unused(1, PHP_INT_MAX, (string) ($assoc['type'] ?? ''), (string) $assoc['order']);
        $format = (string) $assoc['format'];
        if ($format === 'ids') {
            WP_CLI::log(implode(' ', array_keys($list['items'])));
            return;
        }
        if ($format === 'count') {
            WP_CLI::log((string) $list['total']);
            return;
        }
        $rows = [];
        foreach ($list['items'] as $id => $bytes) {
            $rows[] = [
                'id' => $id,
                'file' => (string) get_post_meta($id, '_wp_attached_file', true),
                'bytes' => $bytes,
                'size' => size_format($bytes),
                'uploaded' => get_the_date('Y-m-d', $id),
                'type' => get_post_mime_type($id),
            ];
        }
        Utils\format_items($format, $rows, ['id', 'file', 'size', 'bytes', 'uploaded', 'type']);
        if ($format === 'table') {
            WP_CLI::log(sprintf('%d unreferenced, %s', $list['total'], size_format($list['bytes']) ?: '0 B'));
        }
    }

    /**
     * Shows where an attachment is used.
     *
     * ## OPTIONS
     *
     * <id>
     * : Attachment id.
     *
     * [--format=<format>]
     * ---
     * default: table
     * ---
     */
    public function usage(array $args, array $assoc): void
    {
        $id = (int) $args[0];
        if (get_post_type($id) !== 'attachment') {
            WP_CLI::error("#$id is not an attachment.");
        }
        $refs = Scanner::refs($id);
        WP_CLI::log(sprintf('#%d %s: %s', $id, get_post_meta($id, '_wp_attached_file', true), Scanner::status($id, Scanner::counts([$id])[$id] ?? 0)));
        if ($refs) {
            Utils\format_items($assoc['format'], $refs, ['source_type', 'source_id', 'source_key', 'how']);
        }
    }

    /**
     * Moves unreferenced media out of its public path; requests for it are still answered and logged.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Attachment ids.
     */
    public function quarantine(array $args): void
    {
        $done = Cleanup::quarantine(array_map('intval', $args));
        WP_CLI::success(sprintf('%d quarantined, %d skipped (referenced or already quarantined).', count($done), count($args) - count(array_intersect($args, $done))));
    }

    /**
     * Puts quarantined media back.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Attachment ids.
     */
    public function restore(array $args): void
    {
        WP_CLI::success(sprintf('%d restored.', count(Cleanup::restore(array_map('intval', $args)))));
    }

    /**
     * Deletes quarantined or unreferenced media for good, with all its files. Referenced media is refused.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Attachment ids.
     *
     * [--yes]
     * : Don't ask.
     */
    public function delete(array $args, array $assoc): void
    {
        WP_CLI::confirm(sprintf('Permanently delete %d attachment(s) and their files?', count($args)), $assoc);
        $out = Cleanup::delete(array_map('intval', $args));
        WP_CLI::success(sprintf('%d deleted, %s freed, %d refused (referenced).', count($out['ids']), size_format($out['bytes']) ?: '0 B', count($args) - count(array_intersect($args, $out['ids']))));
    }

    /**
     * For a limited time, quarantined files answer 404 like deleted files; requests are still logged.
     *
     * Crawl the site while it runs, then restore what was requested. Visitors and search engines get the 404s too.
     *
     * ## OPTIONS
     *
     * <action>
     * : start, stop or status.
     *
     * [--hours=<hours>]
     * : How long the test runs: 1, 6, 24 or 72.
     * ---
     * default: 24
     * ---
     *
     * @subcommand quarantine-test
     */
    public function quarantine_test(array $args, array $assoc): void
    {
        $action = (string) ($args[0] ?? '');
        if ($action === 'start' && !Cleanup::start_test((int) $assoc['hours'])) {
            WP_CLI::error('--hours must be 1, 6, 24 or 72.');
        } elseif ($action === 'stop') {
            Cleanup::stop_test();
        } elseif (!in_array($action, ['start', 'status'], true)) {
            WP_CLI::error('Use start, stop or status.');
        }
        $info = Cleanup::test_info();
        if (Cleanup::test_active()) {
            WP_CLI::log(sprintf('404 test running until %s.', wp_date('Y-m-d H:i', $info['until'])));
        } else {
            WP_CLI::log($info ? sprintf('404 test off. Last test: %s to %s.', wp_date('Y-m-d H:i', $info['start']), wp_date('Y-m-d H:i', $info['until'])) : '404 test off.');
        }
        $requested = Cleanup::requested();
        WP_CLI::log(sprintf('Quarantined: %d  Requested: %d%s', count(Cleanup::quarantined()), count($requested), $requested ? ' (#' . implode(', #', $requested) . ')' : ''));
    }

    /**
     * Replaces duplicate copies with one kept copy (merge), or undoes a merge.
     *
     * merge: every reference to each duplicate that can be rewritten exactly is pointed at the kept copy (a copy with
     * any reference that cannot is refused as a whole), then the duplicate is quarantined. Every changed value is
     * logged first. Without --yes nothing changes: the plan is printed (a dry run).
     *
     * ## OPTIONS
     *
     * <action>
     * : merge or undo.
     *
     * <ids>...
     * : merge: the attachment id to keep, then the duplicate copies (attachment ids, or upload-relative paths of extra files). undo: the merge id.
     *
     * [--dry-run]
     * : Only show what would change (also the default without --yes).
     *
     * [--delete]
     * : Delete the replaced copies permanently instead of quarantining them (the merge can then not be undone).
     *
     * [--yes]
     * : Apply it.
     *
     * ## EXAMPLES
     *
     *     wp elinweb-unused-media-finder duplicates merge 12 34 56
     *     wp elinweb-unused-media-finder duplicates merge 12 34 --yes
     *     wp elinweb-unused-media-finder duplicates undo 7 --yes
     */
    public function duplicates(array $args, array $assoc): void
    {
        $action = (string) array_shift($args);
        $apply = Utils\get_flag_value($assoc, 'yes', false) && !Utils\get_flag_value($assoc, 'dry-run', false);
        if ($action === 'undo') {
            $m = Merge::get((int) ($args[0] ?? 0));
            if (!$m) {
                WP_CLI::error('No such merge.');
            }
            WP_CLI::log(sprintf('Merge #%d: kept #%d, replaced %s, state %s. %s', $m['id'], $m['keep_id'], $m['dup_id'] ? '#' . $m['dup_id'] : $m['dup_path'], $m['state'], Merge::result($m)['message']));
            if (!$apply) {
                WP_CLI::log(Merge::undoable($m) ? 'Dry run: add --yes to undo it.' : 'It cannot be undone.');
                return;
            }
            $r = $this->merge_loop(Merge::undo((int) $m['id']), 'undo');
            WP_CLI::success($r['message']);
            return;
        }
        if ($action !== 'merge' || count($args) < 2) {
            WP_CLI::error('Use: duplicates merge <keep_id> <dup>... or duplicates undo <merge_id>.');
        }
        $keep = (int) array_shift($args);
        $failed = 0;
        foreach ($args as $arg) {
            $dup = ctype_digit((string) $arg) ? (int) $arg : (string) $arg;
            $plan = Merge::plan($keep, $dup);
            WP_CLI::log(sprintf('%s -> #%d', is_int($dup) ? "#$dup" : $dup, $keep));
            if ($plan['error'] !== '') {
                WP_CLI::warning($plan['error']);
                $failed++;
                continue;
            }
            foreach ($plan['changes'] as $ch) {
                WP_CLI::log(sprintf('  rewrite  %s [%s] %s', Merge::label($ch), $ch['how'], $ch['tbl'] === 'cache' ? '(cache, rebuilt)' : $ch['tbl'] . '.' . $ch['col']));
            }
            foreach ($plan['blocked'] as [$ref, $reason]) {
                WP_CLI::log(sprintf('  REFUSED  %s: %s', Merge::label($ref), $reason));
            }
            if ($plan['blocked']) {
                WP_CLI::warning('Not mergeable: change the refused places by hand, then run this again.');
                $failed++;
                continue;
            }
            if (!$apply) {
                WP_CLI::log('  Dry run: add --yes to apply.');
                continue;
            }
            $r = $this->merge_loop(Merge::start($keep, $dup, (bool) Utils\get_flag_value($assoc, 'delete', false)), 'step');
            WP_CLI::log(sprintf('  merge #%d: %s. %s', $r['merge'], $r['state'], $r['message']));
            $failed += $r['state'] === 'done' ? 0 : 1;
        }
        if ($apply) {
            WP_CLI::log('Purge the page cache and CDN so visitors get the updated pages.');
        }
        $failed ? WP_CLI::error("$failed copy(ies) not merged.") : WP_CLI::success($apply ? 'Merged.' : 'Dry run finished; nothing was changed.');
    }

    private function merge_loop(array $r, string $do): array
    {
        while (empty($r['error']) && empty($r['done'])) {
            $r = $do === 'undo' ? Merge::undo((int) $r['merge']) : Merge::step((int) $r['merge']);
        }
        if (!empty($r['error'])) {
            WP_CLI::error($r['error']);
        }
        return $r;
    }

    /**
     * Marks media as used outside the site, or (--undo) removes the mark.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Attachment ids.
     *
     * [--undo]
     * : Remove the mark.
     */
    public function keep(array $args, array $assoc): void
    {
        $undo = (bool) Utils\get_flag_value($assoc, 'undo', false);
        WP_CLI::success(sprintf('%d %s.', count(Cleanup::keep(array_map('intval', $args), !$undo)), $undo ? 'unmarked' : 'kept'));
    }
}
