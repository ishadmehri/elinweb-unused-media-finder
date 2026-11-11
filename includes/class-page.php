<?php

namespace ElinwebUnusedMediaFinder;

defined('ABSPATH') || exit;

/** The plugin's own screen (Media → Unused Media Finder), in tabs. Unreferenced media comes first. */
final class Page
{
    private const PER_PAGE = 40;
    private const PER_PAGE_CHOICES = [20, 40, 100, 200, 500];

    public static function init(): void
    {
        add_action('wp_ajax_mtrk_scan', [self::class, 'ajax_scan']);
        add_action('wp_ajax_mtrk_sizes', [self::class, 'ajax_sizes']);
        add_action('wp_ajax_mtrk_orphans', [self::class, 'ajax_orphans']);
        add_action('wp_ajax_mtrk_zip', [self::class, 'ajax_zip']);
        add_action('wp_ajax_mtrk_act', [self::class, 'ajax_act']);
        add_action('wp_ajax_mtrk_preview', [self::class, 'ajax_preview']);
        add_action('wp_ajax_mtrk_dups', [self::class, 'ajax_dups']);
        add_action('wp_ajax_mtrk_export', [self::class, 'ajax_export']);
        add_action('wp_ajax_mtrk_qtest', [self::class, 'ajax_qtest']);
        add_action('wp_ajax_mtrk_merge', [self::class, 'ajax_merge']);
        add_action('wp_ajax_mtrk_where', [self::class, 'ajax_where']);
    }

    public static function url(string $tab, array $args = []): string
    {
        return add_query_arg(['page' => 'elinweb-unused-media-finder', 'tab' => $tab] + $args, admin_url('upload.php'));
    }

    public static function suspect_text(): string
    {
        $ids = (array) (Scanner::last()['suspect'] ?? []);
        return sprintf(
            /* translators: 1: number of images, 2: attachment ids */
            __('This scan cannot be trusted: %1$s featured image(s) (such as %2$s) that are certainly in use came back unreferenced. Quarantine and delete are disabled. Run the scan again; if it repeats, report it.', 'elinweb-unused-media-finder'),
            number_format_i18n(count($ids)),
            '#' . implode(wp_get_list_item_separator() . '#', array_slice($ids, 0, 5))
        );
    }

    public static function scope(array $last): string
    {
        $scope = $last['code'] ? __('Database + source code', 'elinweb-unused-media-finder') : __('Database only', 'elinweb-unused-media-finder');
        return $last['tables'] ? $scope . __(', with plugin tables', 'elinweb-unused-media-finder') : $scope;
    }

    public static function render(): void
    {
        $last = Scanner::last();
        $quarantined = count(Cleanup::quarantined());
        $tabs = [
            'unused' => __('Unreferenced', 'elinweb-unused-media-finder'),
            'duplicates' => __('Duplicates', 'elinweb-unused-media-finder'),
            'quarantine' => $quarantined ? sprintf('%s (%s)', __('Quarantine', 'elinweb-unused-media-finder'), number_format_i18n($quarantined)) : __('Quarantine', 'elinweb-unused-media-finder'),
            'orphans' => __('Extra files', 'elinweb-unused-media-finder'),
            'sizes' => __('Unregistered sizes', 'elinweb-unused-media-finder'),
            'scan' => __('Scan', 'elinweb-unused-media-finder'),
        ];
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which tab to show
        if (!isset($tabs[$tab])) {
            $tab = $last ? 'unused' : 'scan';
        }
        ?>
        <div class="wrap mtrk-page">
            <h1><?php esc_html_e('Unused Media Finder', 'elinweb-unused-media-finder'); ?></h1>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Unused Media Finder sections', 'elinweb-unused-media-finder'); ?>">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="<?php echo esc_url(self::url($key)); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php self::done_notice(); ?>
            <?php if (!empty($last['suspect'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(self::suspect_text()); ?></p></div>
            <?php endif; ?>
            <?php
            if (!$last && $tab !== 'scan' && $tab !== 'quarantine') {
                printf(
                    '<p>%s <a href="%s">%s</a></p>',
                    esc_html__('This section needs the result of a full scan.', 'elinweb-unused-media-finder'),
                    esc_url(self::url('scan')),
                    esc_html__('Run a scan', 'elinweb-unused-media-finder')
                );
            } else {
                self::{"tab_$tab"}($last);
            }
            ?>
        </div>
        <?php
    }

    /** After an action the page reloads with ?done=act&n=&b=; say what happened. */
    private static function done_notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks which notice to print; the action itself ran over a nonce-checked AJAX call
        $act = sanitize_key(wp_unslash($_GET['done'] ?? ''));
        $n = absint($_GET['n'] ?? 0);
        $bytes = absint($_GET['b'] ?? 0);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $messages = [
            /* translators: %s: number of media items */
            'quarantine' => __('%s media moved to quarantine.', 'elinweb-unused-media-finder'),
            /* translators: %s: number of media items */
            'restore' => __('%s media restored to their original place.', 'elinweb-unused-media-finder'),
            /* translators: %s: number of media items */
            'delete' => __('%s media deleted permanently.', 'elinweb-unused-media-finder'),
            /* translators: %s: number of media items */
            'keep' => __('%s media will be kept and left this list.', 'elinweb-unused-media-finder'),
            /* translators: %s: number of media items */
            'unkeep' => __('The "keep" mark was removed from %s media.', 'elinweb-unused-media-finder'),
        ];
        if (!isset($messages[$act])) {
            return;
        }
        $text = sprintf($messages[$act], number_format_i18n($n));
        if ($bytes) {
            /* translators: %s: size, e.g. 12 MB */
            $text .= ' ' . sprintf(__('Space freed: %s', 'elinweb-unused-media-finder'), size_format($bytes));
        }
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
    }

    // ---- unreferenced ----------------------------------------------------------------------------------------

    private static function tab_unused(array $last): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display filters (type, order, page number) that change nothing; they must keep working in pagination links and bookmarks
        $type = sanitize_key(wp_unslash($_GET['type'] ?? ''));
        $order = sanitize_key(wp_unslash($_GET['order'] ?? 'largest'));
        $paged = max(1, absint($_GET['paged'] ?? 1));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        [$per, $view] = self::screen();
        $list = Cleanup::unused($paged, $per, $type, $order);
        ?>
        <p class="mtrk-lead">
            <?php
            printf(
                /* translators: 1: number of media items, 2: size */
                esc_html__('%1$s unreferenced media, %2$s in total.', 'elinweb-unused-media-finder'),
                '<strong>' . esc_html(number_format_i18n($list['total'])) . '</strong>',
                '<strong>' . esc_html(size_format($list['bytes']) ?: '0 B') . '</strong>'
            );
            ?>
        </p>
        <?php self::scope_notice($last); ?>
        <p class="description"><?php esc_html_e('Use outside the site (newsletters, social media, other sites) cannot be detected. The safe way: quarantine first, wait a few weeks, and only delete what received no requests in that time.', 'elinweb-unused-media-finder'); ?></p>

        <form method="get" class="mtrk-filters">
            <input type="hidden" name="page" value="elinweb-unused-media-finder">
            <input type="hidden" name="tab" value="unused">
            <label for="mtrk-type" class="screen-reader-text"><?php esc_html_e('Type', 'elinweb-unused-media-finder'); ?></label>
            <select name="type" id="mtrk-type">
                <?php foreach (['' => __('All types', 'elinweb-unused-media-finder'), 'image' => __('Image', 'elinweb-unused-media-finder'), 'video' => __('Video', 'elinweb-unused-media-finder'), 'audio' => __('Audio', 'elinweb-unused-media-finder'), 'document' => __('Document', 'elinweb-unused-media-finder')] as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($type, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="mtrk-order" class="screen-reader-text"><?php esc_html_e('Order', 'elinweb-unused-media-finder'); ?></label>
            <select name="order" id="mtrk-order">
                <?php foreach (['largest' => __('Largest first', 'elinweb-unused-media-finder'), 'newest' => __('Newest first', 'elinweb-unused-media-finder'), 'oldest' => __('Oldest first', 'elinweb-unused-media-finder')] as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($order, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php self::screen_fields($per, $view); ?>
            <button class="button"><?php esc_html_e('Apply', 'elinweb-unused-media-finder'); ?></button>
        </form>

        <?php if (!$list['items']) : ?>
            <p><?php esc_html_e('No unreferenced media found.', 'elinweb-unused-media-finder'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <?php self::export_bar('unused', ['type' => $type, 'order' => $order]); ?>

        <div class="mtrk-bulk" data-scope="unused">
            <label><input type="checkbox" class="mtrk-all"> <?php esc_html_e('Select all on this page', 'elinweb-unused-media-finder'); ?></label>
            <span class="mtrk-count" aria-live="polite"></span>
            <button type="button" class="button button-primary" data-act="quarantine" disabled><?php esc_html_e('Quarantine', 'elinweb-unused-media-finder'); ?></button>
            <button type="button" class="button" data-act="keep" disabled><?php esc_html_e('Keep', 'elinweb-unused-media-finder'); ?></button>
            <button type="button" class="button mtrk-danger" data-act="delete" disabled><?php esc_html_e('Delete permanently', 'elinweb-unused-media-finder'); ?></button>
        </div>
        <?php if ($view === 'list') : ?>
            <?php self::media_table($list['items']); ?>
        <?php else : ?>
            <ul class="mtrk-grid">
                <?php foreach ($list['items'] as $id => $bytes) : ?>
                    <?php $file = wp_basename((string) get_attached_file($id)); ?>
                    <li class="mtrk-item">
                        <input type="checkbox" class="mtrk-pick" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Select %s', 'elinweb-unused-media-finder'), $file)); ?>">
                        <button type="button" class="mtrk-thumb mtrk-preview" data-id="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Preview %s', 'elinweb-unused-media-finder'), $file)); ?>" aria-haspopup="dialog">
                            <?php echo wp_get_attachment_image($id, 'medium', true, ['alt' => '', 'loading' => 'lazy']); ?>
                        </button>
                        <span class="mtrk-item-name" dir="auto"><?php echo esc_html($file); ?></span>
                        <span class="mtrk-item-meta"><?php echo esc_html(size_format($bytes) ?: '—'); ?> · <?php echo esc_html(get_the_date('', $id)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
        self::pagination($list['total'], $per, $paged);
    }

    /**
     * Items per page and grid/list view: from the request when given, otherwise the user's last choice. Only the
     * filter form's own submit (valid nonce) remembers them for this user; a link or a bookmark without it is shown
     * as asked but saves nothing.
     * @return array{0:int,1:string}
     */
    private static function screen(): array
    {
        $save = isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mtrk_screen') && current_user_can(Scanner::CAP);
        $user = get_current_user_id();
        $per = absint($_GET['per_page'] ?? 0);
        if (in_array($per, self::PER_PAGE_CHOICES, true)) {
            $save && update_user_meta($user, 'mtrk_per_page', $per);
        } else {
            $per = (int) get_user_meta($user, 'mtrk_per_page', true);
            $per = in_array($per, self::PER_PAGE_CHOICES, true) ? $per : self::PER_PAGE;
        }
        $view = sanitize_key(wp_unslash($_GET['view'] ?? ''));
        if (in_array($view, ['grid', 'list'], true)) {
            $save && update_user_meta($user, 'mtrk_view', $view);
        } else {
            $view = get_user_meta($user, 'mtrk_view', true) === 'list' ? 'list' : 'grid';
        }
        return [$per, $view];
    }

    /** $view '' leaves the view choice out (lists that have only one layout). */
    private static function screen_fields(int $per, string $view): void
    {
        wp_nonce_field('mtrk_screen', '_wpnonce', false);
        ?>
        <?php if ($view !== '') : ?>
            <label for="mtrk-view" class="screen-reader-text"><?php esc_html_e('View mode', 'elinweb-unused-media-finder'); ?></label>
            <select name="view" id="mtrk-view">
                <option value="grid" <?php selected($view, 'grid'); ?>><?php esc_html_e('Card view', 'elinweb-unused-media-finder'); ?></option>
                <option value="list" <?php selected($view, 'list'); ?>><?php esc_html_e('List view', 'elinweb-unused-media-finder'); ?></option>
            </select>
        <?php endif; ?>
        <label for="mtrk-per-page" class="screen-reader-text"><?php esc_html_e('Per page', 'elinweb-unused-media-finder'); ?></label>
        <select name="per_page" id="mtrk-per-page">
            <?php foreach (self::PER_PAGE_CHOICES as $n) : ?>
                <option value="<?php echo (int) $n; ?>" <?php selected($per, $n); ?>><?php echo esc_html(sprintf(/* translators: %s: number */ __('%s per page', 'elinweb-unused-media-finder'), number_format_i18n($n))); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function pagination(int $total, int $per, int $paged): void
    {
        $pages = (int) ceil($total / max(1, $per));
        if ($pages > 1) {
            // paginate_links() copies every current query arg back into each link: drop the notice and the screen nonce
            // (page links never re-save the screen choice).
            $strip = fn($link) => remove_query_arg(['done', 'n', 'b', '_wpnonce'], $link);
            add_filter('paginate_links', $strip);
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post((string) paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $paged,
                'total' => $pages,
            ])) . '</div></div>';
            remove_filter('paginate_links', $strip);
        }
    }

    /** "Download list" buttons for the whole list as filtered, every page. The 'orphans' list is built in the browser. */
    private static function export_bar(string $list, array $args = []): void
    {
        ?>
        <p class="mtrk-export" data-list="<?php echo esc_attr($list); ?>" data-args="<?php echo esc_attr((string) wp_json_encode($args)); ?>">
            <button type="button" class="button mtrk-export-btn" data-format="csv"><?php esc_html_e('Download list (CSV)', 'elinweb-unused-media-finder'); ?></button>
            <button type="button" class="button mtrk-export-btn" data-format="txt"><?php esc_html_e('Download list (TXT)', 'elinweb-unused-media-finder'); ?></button>
            <span class="description"><?php esc_html_e('Every item of the list, not only this page. CSV opens in Excel or Google Sheets (there is no .xlsx); TXT has one URL per line.', 'elinweb-unused-media-finder'); ?></span>
            <span class="mtrk-export-status" aria-live="polite"></span>
        </p>
        <?php
    }

    /**
     * List view: one row per attachment, with its status when $status is set (duplicates).
     * $dup (duplicates): ['counts' => id => places, 'refs' => id => first references, 'keep' => default kept id, 'group' => n]
     * adds the first places of use under the status and a "Keep this copy" choice.
     * @param array<int,int> $items id => bytes
     */
    private static function media_table(array $items, bool $status = false, ?array $pickable = null, array $dup = []): void
    {
        $counts = $dup['counts'] ?? ($status ? Scanner::counts(array_keys($items)) : []);
        ?>
        <table class="widefat striped mtrk-mtable">
            <thead><tr>
                <td class="check-column"></td>
                <?php if ($dup) : ?><th scope="col" class="mtrk-col-keep"><?php esc_html_e('Keep this copy', 'elinweb-unused-media-finder'); ?></th><?php endif; ?>
                <th scope="col" class="mtrk-col-thumb"><span class="screen-reader-text"><?php esc_html_e('Preview', 'elinweb-unused-media-finder'); ?></span></th>
                <th scope="col"><?php esc_html_e('File', 'elinweb-unused-media-finder'); ?></th>
                <?php if ($status) : ?><th scope="col"><?php esc_html_e('Status', 'elinweb-unused-media-finder'); ?></th><?php endif; ?>
                <th scope="col"><?php esc_html_e('Type', 'elinweb-unused-media-finder'); ?></th>
                <th scope="col"><?php esc_html_e('Size', 'elinweb-unused-media-finder'); ?></th>
                <th scope="col"><?php esc_html_e('Uploaded', 'elinweb-unused-media-finder'); ?></th>
                <th scope="col"><?php esc_html_e('Uploaded to', 'elinweb-unused-media-finder'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $id => $bytes) : ?>
                <?php
                $path = (string) get_post_meta($id, '_wp_attached_file', true);
                $file = wp_basename($path);
                $parent = (int) wp_get_post_parent_id($id);
                $can_pick = $pickable === null || in_array($id, $pickable, true);
                ?>
                <tr>
                    <th scope="row" class="check-column">
                        <?php if ($can_pick) : ?>
                            <input type="checkbox" class="mtrk-pick" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Select %s', 'elinweb-unused-media-finder'), $file)); ?>">
                        <?php endif; ?>
                    </th>
                    <?php if ($dup) : ?>
                        <td class="mtrk-col-keep">
                            <?php if (!get_post_meta($id, Cleanup::META, true)) : ?>
                                <input type="radio" class="mtrk-keep" name="mtrk-keep-<?php echo (int) $dup['group']; ?>" value="<?php echo (int) $id; ?>" <?php checked($id, $dup['keep']); ?> aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Keep %s', 'elinweb-unused-media-finder'), $file)); ?>">
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="mtrk-col-thumb">
                        <button type="button" class="mtrk-thumb mtrk-preview" data-id="<?php echo (int) $id; ?>" aria-haspopup="dialog" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Preview %s', 'elinweb-unused-media-finder'), $file)); ?>"><?php echo wp_get_attachment_image($id, [48, 48], true, ['alt' => '', 'loading' => 'lazy']); ?></button>
                    </td>
                    <td><strong dir="auto"><?php echo esc_html($file); ?></strong><br><bdi class="mtrk-from" dir="ltr"><?php echo esc_html(dirname($path) === '.' ? '/' : dirname($path)); ?></bdi> <span class="mtrk-from">#<?php echo (int) $id; ?></span></td>
                    <?php if ($status) : ?>
                        <td>
                            <?php echo wp_kses(Admin::badge($id, $counts[$id] ?? 0), Admin::allowed_html()); ?>
                            <?php if (!empty($dup['refs'][$id])) : ?>
                                <?php echo wp_kses(Admin::inline($id, $dup['refs'][$id], $counts[$id] ?? 0), Admin::allowed_html()); ?>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><?php echo esc_html((string) get_post_mime_type($id)); ?></td>
                    <td><?php echo esc_html(size_format($bytes) ?: '—'); ?></td>
                    <td><?php echo esc_html(get_the_date('', $id)); ?></td>
                    <td><?php echo $parent ? '<a href="' . esc_url((string) get_edit_post_link($parent, 'raw')) . '">' . esc_html(get_the_title($parent) ?: "#$parent") . '</a>' : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ---- duplicates ------------------------------------------------------------------------------------------

    private static function tab_duplicates(array $last): void
    {
        $checked = (int) get_option('mtrk_dups_at');
        ?>
        <p class="description"><?php esc_html_e('Files with exactly identical content that were uploaded more than once. Sizes are compared first, and only same-size files are checked by content hash (MD5). Attachments that share one file from the start (such as media translations) are not counted as duplicates. Only unreferenced copies can be selected. When copies are in use, choose the copy to keep and replace the others with it: a preview shows every place that will be rewritten, and every merge can be undone.', 'elinweb-unused-media-finder'); ?></p>
        <div id="mtrk-dups">
            <p>
                <button type="button" class="button<?php echo $checked ? '' : ' button-primary'; ?> mtrk-dups-check"><?php echo $checked ? esc_html__('Check again', 'elinweb-unused-media-finder') : esc_html__('Check for duplicates', 'elinweb-unused-media-finder'); ?></button>
                <?php if ($checked) : ?>
                    <span class="mtrk-note"><?php echo esc_html(sprintf(/* translators: %s: date */ __('Last checked: %s', 'elinweb-unused-media-finder'), wp_date(get_option('date_format') . ' H:i', $checked))); ?></span>
                <?php endif; ?>
            </p>
            <p>
                <label><input type="checkbox" class="mtrk-dups-extras" <?php checked((bool) get_option(Duplicates::WITH_EXTRAS)); ?>> <?php esc_html_e('Also compare extra files (files on disk that no media item owns)', 'elinweb-unused-media-finder'); ?></label><br>
                <span class="description"><?php esc_html_e('Finds stray copies of library files (FTP uploads, imports). Needs a recent full scan; extra files whose URL is used somewhere cannot be selected.', 'elinweb-unused-media-finder'); ?></span>
            </p>
            <p class="mtrk-dups-status" aria-live="polite"></p>
        </div>
        <?php
        self::merges_box();
        if (!$checked) {
            return;
        }
        $paged = max(1, absint($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the page number of the list
        [$per] = self::screen();
        $list = Duplicates::groups($paged, $per);
        ?>
        <p class="mtrk-lead">
            <?php
            printf(
                /* translators: 1: number of groups, 2: size */
                esc_html__('%1$s duplicate group(s); the extra copies take %2$s in total.', 'elinweb-unused-media-finder'),
                '<strong>' . esc_html(number_format_i18n($list['total'])) . '</strong>',
                '<strong>' . esc_html(size_format($list['bytes']) ?: '0 B') . '</strong>'
            );
            ?>
        </p>
        <?php if (!$list['groups']) : ?>
            <p><?php esc_html_e('No duplicate files found.', 'elinweb-unused-media-finder'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <form method="get" class="mtrk-filters">
            <input type="hidden" name="page" value="elinweb-unused-media-finder">
            <input type="hidden" name="tab" value="duplicates">
            <?php self::screen_fields($per, ''); ?>
            <button class="button"><?php esc_html_e('Apply', 'elinweb-unused-media-finder'); ?></button>
        </form>
        <div class="mtrk-bulk" data-scope="duplicates">
            <span class="mtrk-count" aria-live="polite"></span>
            <button type="button" class="button button-primary" data-act="quarantine" disabled><?php esc_html_e('Quarantine', 'elinweb-unused-media-finder'); ?></button>
            <button type="button" class="button mtrk-danger" data-act="delete" disabled><?php esc_html_e('Delete permanently', 'elinweb-unused-media-finder'); ?></button>
        </div>
        <?php
        $ids = array_merge([], ...array_column($list['groups'], 'ids'));
        $counts = Scanner::counts($ids);
        $refs = Scanner::refs_for(array_keys(array_filter($counts))); // one query for the page: the first places of every used copy
        foreach ($list['groups'] as $n => $group) {
            // Only copies nothing references (and not kept or already quarantined) can be picked.
            $quarantined = array_filter($group['ids'], fn($id) => (bool) get_post_meta($id, Cleanup::META, true));
            $pickable = array_values(array_filter($group['ids'], fn($id) => empty($counts[$id]) && !in_array($id, $quarantined, true)));
            $free_files = array_filter($group['paths'], fn($f) => !$f['kept']);
            // The copy to keep by default: the most used, then the oldest; never a quarantined one or an extra file.
            $keepable = array_values(array_diff($group['ids'], $quarantined));
            usort($keepable, fn($a, $b) => [($counts[$b] ?? 0), $a] <=> [($counts[$a] ?? 0), $b]);
            $mergeable = $keepable && count($group['ids']) + count($group['paths']) > 1;
            ?>
            <section class="mtrk-dup">
                <h3><?php echo esc_html(sprintf(/* translators: 1: number of copies, 2: size of one copy */ __('%1$s copies, %2$s each', 'elinweb-unused-media-finder'), number_format_i18n($group['copies']), size_format($group['size']) ?: '—')); ?></h3>
                <?php if (!$pickable && !$free_files) : ?>
                    <p class="mtrk-note"><?php esc_html_e('Every copy is in use; there is nothing safe to delete.', 'elinweb-unused-media-finder'); ?></p>
                <?php endif; ?>
                <?php if ($group['ids']) : ?>
                    <?php self::media_table(array_fill_keys($group['ids'], $group['size']), true, $pickable, ['counts' => $counts, 'refs' => $refs, 'keep' => $keepable[0] ?? 0, 'group' => $n]); ?>
                <?php endif; ?>
                <?php if ($group['paths']) : ?>
                    <?php self::extra_files_table($group['paths']); ?>
                <?php endif; ?>
                <?php if ($mergeable) : ?>
                    <p><button type="button" class="button mtrk-merge" aria-haspopup="dialog"><?php esc_html_e('Replace the other copies with the selected one…', 'elinweb-unused-media-finder'); ?></button></p>
                <?php endif; ?>
            </section>
            <?php
        }
        self::pagination($list['total'], $per, $paged);
    }

    /** Extra files inside a duplicate group: can be deleted (never quarantined, no attachment to restore), not previewed by id. */
    private static function extra_files_table(array $paths): void
    {
        $root = wp_normalize_path(wp_upload_dir(null, false)['basedir']);
        ?>
        <table class="widefat striped mtrk-mtable mtrk-xtable">
            <tbody>
            <?php foreach ($paths as $path => $f) : ?>
                <tr>
                    <th scope="row" class="check-column">
                        <?php if (!$f['kept']) : ?>
                            <input type="checkbox" class="mtrk-xpick" value="<?php echo esc_attr($path); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Select %s', 'elinweb-unused-media-finder'), wp_basename($path))); ?>">
                        <?php endif; ?>
                    </th>
                    <td>
                        <code dir="ltr"><?php echo esc_html("$root/$path"); ?></code><br>
                        <button type="button" class="button-link mtrk-xpreview" data-path="<?php echo esc_attr($path); ?>" data-bytes="<?php echo (int) $f['bytes']; ?>" aria-haspopup="dialog"><?php esc_html_e('Preview', 'elinweb-unused-media-finder'); ?></button>
                    </td>
                    <td>
                        <?php if ($f['kept']) : ?>
                            <?php esc_html_e('Its URL appears somewhere; it is kept', 'elinweb-unused-media-finder'); ?>
                            <button type="button" class="button-link mtrk-where" data-path="<?php echo esc_attr($path); ?>" aria-haspopup="dialog"><?php esc_html_e('Where?', 'elinweb-unused-media-finder'); ?></button>
                        <?php else : ?>
                            <?php esc_html_e('No media item owns this file', 'elinweb-unused-media-finder'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(size_format($f['bytes']) ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** The latest merges, with Undo (and Continue for one that was interrupted). */
    private static function merges_box(): void
    {
        $merges = Merge::recent(20);
        if (!$merges) {
            return;
        }
        $states = [
            'done' => __('Done', 'elinweb-unused-media-finder'),
            'undone' => __('Undone', 'elinweb-unused-media-finder'),
            'rolledback' => __('Rolled back', 'elinweb-unused-media-finder'),
            'failed' => __('Failed', 'elinweb-unused-media-finder'),
            'logging' => __('Failed', 'elinweb-unused-media-finder'),
            'applying' => __('Interrupted', 'elinweb-unused-media-finder'),
            'undoing' => __('Interrupted', 'elinweb-unused-media-finder'),
        ];
        $open = (bool) array_intersect(array_column($merges, 'state'), ['applying', 'undoing']);
        $name = fn(int $id) => $id && get_post_type($id) === 'attachment' ? wp_basename((string) get_attached_file($id)) . " #$id" : "#$id";
        ?>
        <details class="mtrk-merges"<?php echo $open ? ' open' : ''; ?>>
            <summary><?php esc_html_e('Recent merges', 'elinweb-unused-media-finder'); ?></summary>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Date', 'elinweb-unused-media-finder'); ?></th>
                    <th scope="col"><?php esc_html_e('Kept copy', 'elinweb-unused-media-finder'); ?></th>
                    <th scope="col"><?php esc_html_e('Replaced copy', 'elinweb-unused-media-finder'); ?></th>
                    <th scope="col"><?php esc_html_e('Values changed', 'elinweb-unused-media-finder'); ?></th>
                    <th scope="col"><?php esc_html_e('Result', 'elinweb-unused-media-finder'); ?></th>
                    <td></td>
                </tr></thead>
                <tbody>
                <?php foreach ($merges as $m) : ?>
                    <tr>
                        <td><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $m['created'])); ?></td>
                        <td dir="auto"><?php echo esc_html($name((int) $m['keep_id'])); ?></td>
                        <td dir="auto"><?php echo $m['dup_id'] ? esc_html($name((int) $m['dup_id'])) : '<code dir="ltr">' . esc_html($m['dup_path']) . '</code>'; ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $m['n'])); ?></td>
                        <td><strong><?php echo esc_html($states[$m['state']] ?? $m['state']); ?></strong> <span class="mtrk-note"><?php echo esc_html(Merge::result($m)['message']); ?></span></td>
                        <td>
                            <?php if ($m['state'] === 'applying') : ?>
                                <button type="button" class="button mtrk-merge-step" data-merge="<?php echo (int) $m['id']; ?>"><?php esc_html_e('Continue', 'elinweb-unused-media-finder'); ?></button>
                            <?php endif; ?>
                            <?php if (Merge::undoable($m)) : ?>
                                <button type="button" class="button mtrk-merge-undo" data-merge="<?php echo (int) $m['id']; ?>"><?php esc_html_e('Undo this merge', 'elinweb-unused-media-finder'); ?></button>
                            <?php elseif ($m['outcome'] === 'deleted') : ?>
                                <span class="mtrk-note"><?php esc_html_e('Cannot be undone: the copy was deleted permanently.', 'elinweb-unused-media-finder'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('Only the last 50 merges are logged and can be undone. Undo brings the copy back from quarantine and restores every value that has not been changed since.', 'elinweb-unused-media-finder'); ?></p>
        </details>
        <?php
    }

    /**
     * The dry run shown before a merge, computed from the database now: per copy, the places that will be rewritten and
     * the ones that cannot be. @return array{html: string, items: array} items: the copies that can be replaced
     */
    private static function merge_preview(int $keep, array $ids, array $paths): array
    {
        $items = [];
        ob_start();
        ?>
        <div class="mtrk-head">
            <?php echo wp_get_attachment_image($keep, [60, 60], true, ['alt' => '']); ?>
            <div>
                <?php esc_html_e('Kept copy:', 'elinweb-unused-media-finder'); ?>
                <strong dir="auto"><?php echo esc_html(wp_basename((string) get_attached_file($keep)) . " #$keep"); ?></strong>
            </div>
        </div>
        <?php foreach (array_merge($ids, $paths) as $dup) : ?>
            <?php
            $plan = Merge::plan($keep, $dup);
            $rows = [];
            foreach ($plan['changes'] as $ch) {
                $rows[] = ['attachment_id' => 0, 'source_type' => $ch['src_type'], 'source_id' => $ch['src_id'], 'source_key' => $ch['src_key'], 'how' => $ch['how']];
            }
            $ok = $plan['error'] === '' && !$plan['blocked'];
            if ($ok) {
                $items[] = $dup;
            }
            ?>
            <section class="mtrk-merge-copy">
                <h3 dir="auto"><?php echo is_int($dup) ? esc_html(wp_basename((string) get_attached_file($dup)) . " #$dup") : '<code dir="ltr">' . esc_html($dup) . '</code>'; ?></h3>
                <?php if ($plan['error'] !== '') : ?>
                    <p class="mtrk-note"><?php echo esc_html($plan['error']); ?></p>
                <?php else : ?>
                    <?php if ($rows) : ?>
                        <p><?php esc_html_e('Will be rewritten to the kept copy:', 'elinweb-unused-media-finder'); ?></p>
                        <ul class="mtrk-list">
                            <?php foreach (Admin::places($rows) as $place) : ?>
                                <?php $fields = array_values(array_column($place, null, 'source_key')); ?>
                                <li><?php echo wp_kses(Admin::item($fields), Admin::allowed_html()); ?> <span class="mtrk-note"><?php echo esc_html(sprintf(/* translators: %s: number of values */ __('(%s value(s))', 'elinweb-unused-media-finder'), number_format_i18n(count($place)))); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif (!$plan['blocked']) : ?>
                        <p><?php echo is_int($dup) ? esc_html__('Nothing refers to this copy; it will only be quarantined (or deleted).', 'elinweb-unused-media-finder') : esc_html__('No place to rewrite was found.', 'elinweb-unused-media-finder'); ?></p>
                    <?php endif; ?>
                    <?php if ($plan['blocked']) : ?>
                        <p><strong><?php esc_html_e('Cannot be rewritten safely:', 'elinweb-unused-media-finder'); ?></strong></p>
                        <ul class="mtrk-list">
                            <?php foreach ($plan['blocked'] as [$ref, $reason]) : ?>
                                <li><?php echo wp_kses(Admin::item([$ref]), Admin::allowed_html()); ?><br><span class="mtrk-note"><?php echo esc_html($reason); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="notice notice-warning inline"><?php esc_html_e('This copy will not be replaced. Change these places by hand (see "Where it is used"), then open this preview again.', 'elinweb-unused-media-finder'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <p><label><input type="checkbox" class="mtrk-merge-delete"> <?php esc_html_e('Delete the replaced copies permanently instead of quarantining them', 'elinweb-unused-media-finder'); ?></label></p>
        <p class="mtrk-merge-warn notice notice-warning inline" hidden><?php esc_html_e('Deleting cannot be undone, and neither can these merges then. In quarantine the old URLs keep working and the merge can be undone.', 'elinweb-unused-media-finder'); ?></p>
        <p class="description"><?php esc_html_e('Only the copies without a refused place are replaced. Every changed value is logged before it is written; a merge can be undone under "Recent merges". Extra files are never deleted here. Afterwards, purge your page cache and CDN.', 'elinweb-unused-media-finder'); ?></p>
        <p>
            <button type="button" class="button button-primary mtrk-merge-go" data-keep="<?php echo (int) $keep; ?>" data-items="<?php echo esc_attr((string) wp_json_encode($items)); ?>"<?php disabled(!$items); ?>>
                <?php echo esc_html(sprintf(/* translators: %s: number of copies */ __('Replace %s copy(ies)', 'elinweb-unused-media-finder'), number_format_i18n(count($items)))); ?>
            </button>
        </p>
        <div class="mtrk-merge-result" aria-live="polite"></div>
        <?php
        return ['html' => (string) ob_get_clean(), 'items' => $items];
    }

    // ---- quarantine ------------------------------------------------------------------------------------------

    private static function tab_quarantine(?array $last): void
    {
        $rows = Cleanup::quarantined();
        $kinds = [
            'site' => __('Pages of this site', 'elinweb-unused-media-finder'),
            'google' => __('Google', 'elinweb-unused-media-finder'),
            'external' => __('Other sites', 'elinweb-unused-media-finder'),
            'bot' => __('Bots', 'elinweb-unused-media-finder'),
            'direct' => __('Direct', 'elinweb-unused-media-finder'),
        ];
        ?>
        <p class="description"><?php esc_html_e('Quarantined files were moved out of their original path. If anyone asks for them, the plugin serves the file and logs the request. Anything that received no requests for a few weeks is safe to delete. A request from "Pages of this site" means something still links to the file that the scan did not find: restore it.', 'elinweb-unused-media-finder'); ?></p>
        <p class="mtrk-probe notice inline" data-url="<?php echo esc_url(Cleanup::probe_url()); ?>" aria-live="polite"><?php esc_html_e('Checking that requests for quarantined files reach WordPress…', 'elinweb-unused-media-finder'); ?></p>
        <?php
        if ($rows || Cleanup::test_active()) {
            self::test_box();
        }
        ?>
        <?php if (!$rows) : ?>
            <p><?php esc_html_e('Nothing is quarantined.', 'elinweb-unused-media-finder'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <?php self::export_bar('quarantine'); ?>
        <?php $requested = Cleanup::requested(); ?>
        <?php if ($requested) : ?>
            <p class="mtrk-requested">
                <?php echo esc_html(sprintf(/* translators: %s: number of media items */ __('%s quarantined media received at least one request (from any source, at any time while quarantined, including during a 404 test).', 'elinweb-unused-media-finder'), number_format_i18n(count($requested)))); ?>
                <button type="button" class="button" data-act="restore" data-ids="<?php echo esc_attr(implode(' ', $requested)); ?>"><?php esc_html_e('Restore every file that was requested', 'elinweb-unused-media-finder'); ?></button>
            </p>
        <?php endif; ?>
        <div class="mtrk-bulk" data-scope="quarantine">
            <label><input type="checkbox" class="mtrk-all"> <?php esc_html_e('Select all', 'elinweb-unused-media-finder'); ?></label>
            <span class="mtrk-count" aria-live="polite"></span>
            <button type="button" class="button button-primary" data-act="restore" disabled><?php esc_html_e('Restore', 'elinweb-unused-media-finder'); ?></button>
            <button type="button" class="button mtrk-danger" data-act="delete" disabled><?php esc_html_e('Delete permanently', 'elinweb-unused-media-finder'); ?></button>
        </div>
        <table class="widefat striped mtrk-qtable">
            <thead><tr>
                <td class="check-column"></td>
                <th scope="col"><?php esc_html_e('Media', 'elinweb-unused-media-finder'); ?></th>
                <th scope="col"><?php esc_html_e('Quarantined since', 'elinweb-unused-media-finder'); ?></th>
                <th scope="col"><?php esc_html_e('Requests', 'elinweb-unused-media-finder'); ?></th>
                <th scope="col"><?php esc_html_e('Last request', 'elinweb-unused-media-finder'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $id => $q) : ?>
                <?php
                $hits = (array) $q['hits'];
                $file = wp_basename((string) get_attached_file($id));
                ?>
                <tr class="<?php echo !empty($hits['site']) || !empty($q['test']) ? 'mtrk-warn' : ''; ?>">
                    <th scope="row" class="check-column"><input type="checkbox" class="mtrk-pick" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Select %s', 'elinweb-unused-media-finder'), $file)); ?>"></th>
                    <td class="mtrk-qmedia">
                        <button type="button" class="mtrk-thumb mtrk-preview" data-id="<?php echo (int) $id; ?>" aria-haspopup="dialog" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('Preview %s', 'elinweb-unused-media-finder'), $file)); ?>"><?php echo wp_get_attachment_image($id, [60, 60], true, ['alt' => '']); ?></button>
                        <span dir="auto"><?php echo esc_html($file); ?></span>
                    </td>
                    <td><?php echo esc_html(sprintf(/* translators: %s: time span */ __('%s ago', 'elinweb-unused-media-finder'), human_time_diff((int) $q['time']))); ?></td>
                    <td>
                        <?php if (!$hits) : ?>
                            <?php esc_html_e('None', 'elinweb-unused-media-finder'); ?>
                        <?php else : ?>
                            <?php foreach ($kinds as $kind => $label) : ?>
                                <?php if (!empty($hits[$kind])) : ?>
                                    <span class="mtrk-chip mtrk-chip-<?php echo esc_attr($kind); ?>"><?php echo esc_html($label . ': ' . number_format_i18n($hits[$kind])); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!empty($q['test'])) : ?>
                                <span class="mtrk-chip mtrk-chip-test"><?php echo esc_html(sprintf(/* translators: %s: number of requests */ __('During the 404 test: %s', 'elinweb-unused-media-finder'), number_format_i18n((int) $q['test']))); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($q['last']) : ?>
                            <?php echo esc_html(sprintf(/* translators: %s: time span */ __('%s ago', 'elinweb-unused-media-finder'), human_time_diff((int) $q['last']))); ?>
                            <?php foreach ((array) ($q['pages'] ?? array_filter([$q['from']])) as $from) : // referring pages, newest first ?>
                                <br><bdi class="mtrk-from" dir="ltr"><?php echo esc_html(wp_parse_url($from, PHP_URL_HOST) . wp_parse_url($from, PHP_URL_PATH)); ?></bdi>
                            <?php endforeach; ?>
                        <?php else : ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** For a few hours quarantined files answer 404 like deleted ones, so a crawl shows which pages still ask for them. */
    private static function test_box(): void
    {
        $info = Cleanup::test_info();
        $when = get_option('date_format') . ' H:i';
        ?>
        <div class="mtrk-qtest">
            <h2><?php esc_html_e('404 test', 'elinweb-unused-media-finder'); ?></h2>
            <p><?php esc_html_e('For a limited time, quarantined files answer exactly like deleted files (error 404) instead of being served. Meanwhile crawl your site (Screaming Frog, a broken-link checker, or just browse it): every request is logged below with the page it came from. Restore what was requested; what nobody requested is safe to delete. The test ends by itself.', 'elinweb-unused-media-finder'); ?></p>
            <ul class="mtrk-qtest-caveats">
                <li><?php esc_html_e('During the test, visitors and search engines get a 404 for these files: keep it short and avoid peak hours.', 'elinweb-unused-media-finder'); ?></li>
                <li><?php esc_html_e('Purge the page cache and the CDN cache before you crawl; cached pages and files never ask WordPress.', 'elinweb-unused-media-finder'); ?></li>
                <li><?php esc_html_e('The test only sees requests that reach WordPress (see the check above).', 'elinweb-unused-media-finder'); ?></li>
            </ul>
            <p class="mtrk-qtest-probe notice notice-warning inline" hidden><?php esc_html_e('The check above failed: requests for missing files do not reach WordPress here, so the test cannot log anything.', 'elinweb-unused-media-finder'); ?></p>
            <?php if (Cleanup::test_active()) : ?>
                <p>
                    <strong><?php echo esc_html(sprintf(/* translators: 1: date and time, 2: time span */ __('The 404 test is running until %1$s (%2$s left).', 'elinweb-unused-media-finder'), wp_date($when, $info['until']), human_time_diff($info['until']))); ?></strong>
                    <button type="button" class="button" data-qtest="stop"><?php esc_html_e('Stop the test', 'elinweb-unused-media-finder'); ?></button>
                </p>
            <?php else : ?>
                <p>
                    <label for="mtrk-qtest-hours"><?php esc_html_e('Duration', 'elinweb-unused-media-finder'); ?></label>
                    <select id="mtrk-qtest-hours">
                        <?php foreach ([1 => __('1 hour', 'elinweb-unused-media-finder'), 6 => __('6 hours', 'elinweb-unused-media-finder'), 24 => __('24 hours', 'elinweb-unused-media-finder'), 72 => __('3 days', 'elinweb-unused-media-finder')] as $hours => $label) : ?>
                            <option value="<?php echo (int) $hours; ?>" <?php selected($hours, 24); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button button-primary" data-qtest="start"><?php esc_html_e('Start the 404 test', 'elinweb-unused-media-finder'); ?></button>
                </p>
                <?php if ($info) : ?>
                    <p class="mtrk-note"><?php echo esc_html(sprintf(/* translators: 1: start date and time, 2: end date and time */ __('Last test: from %1$s to %2$s.', 'elinweb-unused-media-finder'), wp_date($when, $info['start']), wp_date($when, $info['until']))); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---- orphan files ----------------------------------------------------------------------------------------

    private static function tab_orphans(array $last): void
    {
        ?>
        <p class="description"><?php esc_html_e('Files on disk that no media owns: old sizes of an image that are no longer in its metadata (for example after regenerating thumbnails) and files that were never added to the library (FTP uploads, imports). Only folders that contain media are read; plugin folders (such as form uploads) are not touched.', 'elinweb-unused-media-finder'); ?></p>
        <?php if ((int) ($last['paths'] ?? 0) < 2) : ?>
            <p><?php esc_html_e('Run the scan again with this version for this section.', 'elinweb-unused-media-finder'); ?> <a href="<?php echo esc_url(self::url('scan')); ?>"><?php esc_html_e('Scan', 'elinweb-unused-media-finder'); ?></a></p>
            <?php return; ?>
        <?php endif; ?>
        <?php self::scope_notice($last); ?>
        <div id="mtrk-orphans" data-scan="<?php echo (int) ($last['time'] ?? 0); ?>" data-base="<?php echo esc_url(wp_upload_dir(null, false)['baseurl']); ?>" data-root="<?php echo esc_attr(wp_normalize_path(wp_upload_dir(null, false)['basedir'])); ?>">
            <p><button type="button" class="button mtrk-orphans-check"><?php esc_html_e('Check for extra files', 'elinweb-unused-media-finder'); ?></button></p>
            <p class="mtrk-orphans-status" aria-live="polite"></p>
            <div class="mtrk-orphans-result" hidden>
                <?php self::export_bar('orphans'); ?>
                <div class="mtrk-bulk">
                    <label><input type="checkbox" class="mtrk-orphans-all"> <?php esc_html_e('Select everything deletable', 'elinweb-unused-media-finder'); ?></label>
                    <span class="mtrk-count" aria-live="polite"></span>
                    <button type="button" class="button mtrk-orphans-zip" disabled><?php esc_html_e('Download selected as ZIP', 'elinweb-unused-media-finder'); ?></button>
                    <button type="button" class="button mtrk-danger mtrk-orphans-delete" disabled><?php esc_html_e('Delete permanently', 'elinweb-unused-media-finder'); ?></button>
                </div>
                <table class="widefat striped">
                    <thead><tr>
                        <td class="check-column"></td>
                        <th scope="col"><?php esc_html_e('File', 'elinweb-unused-media-finder'); ?></th>
                        <th scope="col"><?php esc_html_e('Type', 'elinweb-unused-media-finder'); ?></th>
                        <th scope="col"><?php esc_html_e('Size', 'elinweb-unused-media-finder'); ?></th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
                <p><button type="button" class="button mtrk-orphans-more" hidden></button></p>
            </div>
        </div>
        <?php
    }

    // ---- unregistered sizes ----------------------------------------------------------------------------------

    private static function tab_sizes(array $last): void
    {
        ?>
        <p class="description"><?php esc_html_e('Files made for sizes that no theme or plugin registers any more (for example a previous theme\'s sizes). WordPress no longer serves them, but they stay on disk as long as the image exists. A file whose exact URL appears somewhere, or that is the same as an active size, is kept.', 'elinweb-unused-media-finder'); ?></p>
        <?php if (empty($last['paths'])) : ?>
            <p><?php esc_html_e('Run the scan again with this version for this section.', 'elinweb-unused-media-finder'); ?> <a href="<?php echo esc_url(self::url('scan')); ?>"><?php esc_html_e('Scan', 'elinweb-unused-media-finder'); ?></a></p>
            <?php return; ?>
        <?php endif; ?>
        <?php self::scope_notice($last); ?>
        <div id="mtrk-sizes" data-scan="<?php echo (int) ($last['time'] ?? 0); ?>">
            <p><button type="button" class="button mtrk-sizes-check"><?php esc_html_e('Check sizes', 'elinweb-unused-media-finder'); ?></button></p>
            <p class="mtrk-sizes-status" aria-live="polite"></p>
            <div class="mtrk-sizes-result" hidden>
                <table class="widefat striped mtrk-sizes-table">
                    <thead><tr>
                        <td class="check-column"><input type="checkbox" class="mtrk-sizes-all" aria-label="<?php esc_attr_e('Select all', 'elinweb-unused-media-finder'); ?>"></td>
                        <th scope="col"><?php esc_html_e('Image size', 'elinweb-unused-media-finder'); ?></th>
                        <th scope="col"><?php esc_html_e('Dimensions', 'elinweb-unused-media-finder'); ?></th>
                        <th scope="col"><?php esc_html_e('Deletable file', 'elinweb-unused-media-finder'); ?></th>
                        <th scope="col"><?php esc_html_e('Size', 'elinweb-unused-media-finder'); ?></th>
                        <th scope="col"><?php esc_html_e('Kept', 'elinweb-unused-media-finder'); ?></th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
                <p class="description"><?php esc_html_e('Deleting cannot be undone; take a backup first. The WebP/AVIF versions of the same files are deleted too.', 'elinweb-unused-media-finder'); ?></p>
                <p><button type="button" class="button button-primary mtrk-sizes-clean" disabled><?php esc_html_e('Delete the files of the selected sizes', 'elinweb-unused-media-finder'); ?></button></p>
            </div>
        </div>
        <?php
    }

    // ---- scan ------------------------------------------------------------------------------------------------

    private static function tab_scan(?array $last): void
    {
        $job = Scanner::job();
        if ($last) {
            $summary = Scanner::summary();
            $library = admin_url('upload.php?mode=list&mtrk_usage=');
            ?>
            <p>
                <?php
                printf(
                    /* translators: 1: date, 2: scope, 3: duration */
                    esc_html__('Last scan: %1$s — %2$s — took %3$s', 'elinweb-unused-media-finder'),
                    esc_html(wp_date(get_option('date_format') . ' H:i', $last['time'])),
                    esc_html(self::scope($last)),
                    esc_html(human_time_diff(0, max(1, $last['secs'])))
                );
                ?>
            </p>
            <div class="mtrk-cards">
                <a class="mtrk-card mtrk-card-unused" href="<?php echo esc_url(self::url('unused')); ?>"><strong><?php echo esc_html(number_format_i18n($summary['unused'])); ?></strong><?php esc_html_e('Unreferenced', 'elinweb-unused-media-finder'); ?></a>
                <a class="mtrk-card" href="<?php echo esc_url($library . 'used'); ?>"><strong><?php echo esc_html(number_format_i18n($summary['used'])); ?></strong><?php esc_html_e('Used', 'elinweb-unused-media-finder'); ?></a>
                <a class="mtrk-card" href="<?php echo esc_url($library . 'new'); ?>"><strong><?php echo esc_html(number_format_i18n($summary['new'])); ?></strong><?php esc_html_e('New, unreferenced', 'elinweb-unused-media-finder'); ?></a>
            </div>
            <p class="description"><?php esc_html_e('After a scan, changes to posts, meta, terms, users and settings are recorded live; plugin tables and code files are only updated by a full scan.', 'elinweb-unused-media-finder'); ?></p>
            <?php
        } elseif (!$job) {
            echo '<p>' . esc_html__('No scan has been run yet.', 'elinweb-unused-media-finder') . '</p>';
        }
        ?>
        <h2><?php esc_html_e('New scan', 'elinweb-unused-media-finder'); ?></h2>
        <form id="mtrk-form" <?php echo $job ? 'hidden' : ''; ?>>
            <fieldset>
                <legend class="screen-reader-text"><?php esc_html_e('Scan scope', 'elinweb-unused-media-finder'); ?></legend>
                <p>
                    <label><input type="radio" name="code" value="0"> <strong><?php esc_html_e('Database only', 'elinweb-unused-media-finder'); ?></strong></label><br>
                    <span class="description"><?php esc_html_e('Fast. Posts and pages, meta (Elementor, WooCommerce, ACF), settings and widgets, terms, users and comments.', 'elinweb-unused-media-finder'); ?></span>
                </p>
                <p>
                    <label><input type="radio" name="code" value="1" checked> <strong><?php esc_html_e('Database + source code', 'elinweb-unused-media-finder'); ?></strong></label><br>
                    <span class="description"><?php esc_html_e('Plus the files of the active theme, active plugins, mu-plugins and the CSS/JS files inside the uploads folder (such as Elementor\'s generated CSS). Run this before any deletion.', 'elinweb-unused-media-finder'); ?></span>
                </p>
                <p>
                    <label><input type="checkbox" name="tables" value="1" checked> <?php esc_html_e('Also check plugins\' own tables', 'elinweb-unused-media-finder'); ?></label><br>
                    <span class="description"><?php esc_html_e('Such as sliders and form builders. Log, session and cache tables are skipped.', 'elinweb-unused-media-finder'); ?></span>
                </p>
            </fieldset>
            <p class="description"><?php esc_html_e('The previous scan\'s result is cleared. If you close the page mid-scan, it continues from where it stopped next time.', 'elinweb-unused-media-finder'); ?></p>
            <?php submit_button(__('Start scan', 'elinweb-unused-media-finder'), 'primary', 'submit', false); ?>
        </form>
        <div id="mtrk-progress" <?php echo $job ? '' : 'hidden'; ?> data-running="<?php echo esc_attr(wp_json_encode($job ? Scanner::progress($job) : null)); ?>">
            <progress max="100" value="0"></progress>
            <p class="mtrk-label" aria-live="polite"></p>
            <button type="button" class="button mtrk-cancel"><?php esc_html_e('Stop scan', 'elinweb-unused-media-finder'); ?></button>
            <button type="button" class="button button-primary mtrk-retry" hidden><?php esc_html_e('Continue', 'elinweb-unused-media-finder'); ?></button>
        </div>
        <?php
    }

    private static function scope_notice(array $last): void
    {
        if (!$last['code']) {
            printf(
                '<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__('The last scan was "database only"; URLs written directly in theme or plugin code were not checked. Run a "database + source code" scan before deleting.', 'elinweb-unused-media-finder'),
                esc_url(self::url('scan')),
                esc_html__('Scan', 'elinweb-unused-media-finder')
            );
        }
    }

    // ---- preview ---------------------------------------------------------------------------------------------

    private static function preview_html(int $id): string
    {
        $file = (string) get_attached_file($id);
        $meta = wp_get_attachment_metadata($id);
        $mime = (string) get_post_mime_type($id);
        $url = (string) wp_get_attachment_url($id);
        $quarantined = (bool) get_post_meta($id, Cleanup::META, true);
        $kept = (bool) get_post_meta($id, Cleanup::KEEP, true);
        $count = Scanner::counts([$id])[$id] ?? 0;
        $parent = (int) wp_get_post_parent_id($id);
        $twins = array_diff(Cleanup::group_ids($id), [$id]);

        ob_start();
        ?>
        <div class="mtrk-preview-layout">
        <div class="mtrk-preview-media">
            <?php if (strpos($mime, 'image/') === 0) : ?>
                <?php echo wp_get_attachment_image($id, 'large', false, ['alt' => '']); ?>
            <?php elseif (strpos($mime, 'video/') === 0) : ?>
                <video src="<?php echo esc_url($url); ?>" controls preload="metadata"></video>
            <?php elseif (strpos($mime, 'audio/') === 0) : ?>
                <audio src="<?php echo esc_url($url); ?>" controls preload="metadata"></audio>
            <?php else : ?>
                <?php echo wp_get_attachment_image($id, 'thumbnail', true, ['alt' => '']); ?>
            <?php endif; ?>
        </div>
        <div class="mtrk-preview-info">
        <dl class="mtrk-details">
            <dt><?php esc_html_e('Status', 'elinweb-unused-media-finder'); ?></dt>
            <dd><?php echo wp_kses(Admin::badge($id, $count, false), Admin::allowed_html()); ?></dd>
            <dt><?php esc_html_e('File', 'elinweb-unused-media-finder'); ?></dt>
            <dd><bdi dir="ltr"><?php echo esc_html((string) get_post_meta($id, '_wp_attached_file', true)); ?></bdi></dd>
            <dt><?php esc_html_e('Size on disk', 'elinweb-unused-media-finder'); ?></dt>
            <dd><?php echo esc_html(size_format(Cleanup::bytes($id, $meta)) ?: '—'); ?><?php echo !empty($meta['sizes']) ? esc_html(sprintf(/* translators: %s: number of sizes */ __(' (with %s sizes)', 'elinweb-unused-media-finder'), number_format_i18n(count($meta['sizes'])))) : ''; ?></dd>
            <?php if (!empty($meta['width'])) : ?>
                <dt><?php esc_html_e('Dimensions', 'elinweb-unused-media-finder'); ?></dt>
                <dd><bdi dir="ltr"><?php echo (int) $meta['width'] . '×' . (int) $meta['height']; ?></bdi></dd>
            <?php endif; ?>
            <dt><?php esc_html_e('Uploaded', 'elinweb-unused-media-finder'); ?></dt>
            <dd><?php echo esc_html(get_the_date('', $id)); ?></dd>
            <?php if ($parent) : ?>
                <dt><?php esc_html_e('Uploaded to', 'elinweb-unused-media-finder'); ?></dt>
                <dd><a href="<?php echo esc_url((string) get_edit_post_link($parent, 'raw')); ?>"><?php echo esc_html(get_the_title($parent) ?: "#$parent"); ?></a> <span class="mtrk-note"><?php esc_html_e('(this is only where it was uploaded, not a use)', 'elinweb-unused-media-finder'); ?></span></dd>
            <?php endif; ?>
            <?php if ($twins) : ?>
                <dt><?php esc_html_e('Shared file', 'elinweb-unused-media-finder'); ?></dt>
                <dd><?php echo esc_html(sprintf(/* translators: %s: attachment ids */ __('With attachment %s; they are moved or deleted together.', 'elinweb-unused-media-finder'), '#' . implode(wp_get_list_item_separator() . '#', $twins))); ?></dd>
            <?php endif; ?>
        </dl>
        <div class="mtrk-actions">
            <?php if ($quarantined) : ?>
                <button type="button" class="button button-primary" data-act="restore" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('Restore', 'elinweb-unused-media-finder'); ?></button>
                <button type="button" class="button mtrk-danger" data-act="delete" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('Delete permanently', 'elinweb-unused-media-finder'); ?></button>
            <?php elseif ($kept) : ?>
                <button type="button" class="button" data-act="unkeep" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('Remove the keep mark', 'elinweb-unused-media-finder'); ?></button>
            <?php elseif (!$count && Scanner::last()) : ?>
                <button type="button" class="button button-primary" data-act="quarantine" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('Quarantine', 'elinweb-unused-media-finder'); ?></button>
                <button type="button" class="button" data-act="keep" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('Keep', 'elinweb-unused-media-finder'); ?></button>
                <button type="button" class="button mtrk-danger" data-act="delete" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('Delete permanently', 'elinweb-unused-media-finder'); ?></button>
            <?php else : ?>
                <button type="button" class="button mtrk-open" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('Where it is used', 'elinweb-unused-media-finder'); ?></button>
            <?php endif; ?>
            <a class="button-link" href="<?php echo esc_url((string) get_edit_post_link($id, 'raw')); ?>"><?php esc_html_e('Edit media', 'elinweb-unused-media-finder'); ?></a>
        </div>
        </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // ---- ajax ------------------------------------------------------------------------------------------------

    public static function ajax_scan(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        if ($do === 'start') {
            $started = Scanner::start(!empty($_POST['code']), !empty($_POST['tables']));
            isset($started['error']) ? wp_send_json_error($started['error'], 500) : wp_send_json_success($started);
        }
        if ($do === 'step') {
            wp_send_json_success(Scanner::run_step());
        }
        if ($do === 'cancel') {
            Scanner::cancel();
            wp_send_json_success();
        }
        wp_send_json_error(__('Invalid request.', 'elinweb-unused-media-finder'), 400);
    }

    public static function ajax_sizes(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        if (empty(Scanner::last()['paths'])) {
            wp_send_json_error(__('Run a full scan first.', 'elinweb-unused-media-finder'), 409);
        }
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        $names = array_map('sanitize_text_field', (array) wp_unslash($_POST['names'] ?? []));
        if ($do === 'clean' && !$names) {
            wp_send_json_error(__('No size selected.', 'elinweb-unused-media-finder'), 400);
        }
        if ($do === 'clean' && !Scanner::trusted()) {
            wp_send_json_error(self::suspect_text(), 409);
        }
        wp_send_json_success(Sizes::step(absint($_POST['cursor'] ?? 0), $names, $do === 'clean'));
    }

    public static function ajax_orphans(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        if ((int) (Scanner::last()['paths'] ?? 0) < 2) {
            wp_send_json_error(__('Run the scan again with this version.', 'elinweb-unused-media-finder'), 409);
        }
        if (sanitize_key(wp_unslash($_POST['do'] ?? '')) === 'delete') {
            if (!Scanner::trusted()) {
                wp_send_json_error(self::suspect_text(), 409);
            }
            // Paths are only looked up: Orphans::delete() deletes nothing its own fresh listing doesn't show.
            $paths = array_map(fn($p) => (string) $p, (array) wp_unslash($_POST['paths'] ?? [])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a path is only compared with a fresh listing of the folder, never used as given
            wp_send_json_success(Orphans::delete($paths));
        }
        wp_send_json_success(Orphans::step(absint($_POST['cursor'] ?? 0)));
    }

    /** Sends the ZIP of the selected extra files as a download (or a JSON error the page shows). */
    public static function ajax_zip(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $paths = json_decode((string) wp_unslash($_POST['paths'] ?? '[]'), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON list; each path is checked against a fresh listing of its folder
        $zip = Orphans::zip(is_array($paths) ? array_map('strval', $paths) : []);
        if (isset($zip['error'])) {
            wp_send_json_error($zip['error'], 400);
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="extra-files-' . gmdate('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($zip['file']));
        readfile($zip['file']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the temporary ZIP to the browser
        wp_delete_file($zip['file']);
        exit;
    }

    /** Sends the whole Unreferenced (as filtered) or Quarantine list as a CSV or TXT download (or a JSON error). */
    public static function ajax_export(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $list = sanitize_key(wp_unslash($_POST['list'] ?? ''));
        $format = sanitize_key(wp_unslash($_POST['format'] ?? '')) === 'txt' ? 'txt' : 'csv';
        if (!in_array($list, Export::LISTS, true)) {
            wp_send_json_error(__('Invalid request.', 'elinweb-unused-media-finder'), 400);
        }
        if ($list === 'unused' && !Scanner::last()) {
            wp_send_json_error(__('Run a full scan first.', 'elinweb-unused-media-finder'), 409);
        }
        $ids = Export::ids($list, sanitize_key(wp_unslash($_POST['type'] ?? '')), sanitize_key(wp_unslash($_POST['order'] ?? 'largest')));
        nocache_headers();
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/plain') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . ($list === 'unused' ? 'unused' : 'quarantined') . '-media-' . current_time('Ymd-His') . ".$format\""); // not wp_date(): calendar plugins rewrite its digits
        header('X-Mtrk-Rows: ' . count($ids));
        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the response body as a stream, not a file
        Export::write($out, $list, $format, $ids);
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- same stream
        exit;
    }

    /** Starts or stops the 404 test. */
    public static function ajax_qtest(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        if ($do === 'start') {
            if (!Cleanup::start_test(absint($_POST['hours'] ?? 24))) {
                wp_send_json_error(__('Choose 1, 6, 24 or 72 hours.', 'elinweb-unused-media-finder'), 400);
            }
            wp_send_json_success(['until' => Cleanup::test_until()]);
        }
        if ($do === 'stop') {
            Cleanup::stop_test();
            wp_send_json_success();
        }
        wp_send_json_error(__('Invalid request.', 'elinweb-unused-media-finder'), 400);
    }

    public static function ajax_dups(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        if (!empty($_POST['start']) && (int) (Scanner::last()['paths'] ?? 0) < 2 && !empty($_POST['extras'])) {
            wp_send_json_error(__('Run the scan again with this version.', 'elinweb-unused-media-finder'), 409); // extra files need the exact-paths index
        }
        wp_send_json_success(Duplicates::step(!empty($_POST['start']), !empty($_POST['extras'])));
    }

    public static function ajax_act(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $act = sanitize_key(wp_unslash($_POST['act'] ?? ''));
        $ids = array_filter(array_map('absint', (array) ($_POST['ids'] ?? [])));
        if (!$ids) {
            wp_send_json_error(__('Nothing selected.', 'elinweb-unused-media-finder'), 400);
        }
        if (in_array($act, ['quarantine', 'delete'], true) && !Scanner::trusted()) {
            wp_send_json_error(self::suspect_text(), 409);
        }
        $bytes = 0;
        switch ($act) {
            case 'quarantine':
                $done = Cleanup::quarantine($ids);
                break;
            case 'restore':
                $done = Cleanup::restore($ids);
                break;
            case 'delete':
                $out = Cleanup::delete($ids);
                $done = $out['ids'];
                $bytes = $out['bytes'];
                break;
            case 'keep':
            case 'unkeep':
                $done = Cleanup::keep($ids, $act === 'keep');
                break;
            default:
                wp_send_json_error(__('Invalid request.', 'elinweb-unused-media-finder'), 400);
        }
        wp_send_json_success(['done' => $done, 'bytes' => $bytes]);
    }

    /** Merge in a duplicate group: preview (dry run), run and step (apply in budgeted requests), undo. */
    public static function ajax_merge(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        $keep = absint($_POST['keep'] ?? 0);
        $merge = absint($_POST['merge'] ?? 0);
        // Extra files: a path is only used after Orphans::is_extra() found it in a fresh listing of its folder.
        $paths = array_map(fn($p) => (string) $p, (array) wp_unslash($_POST['paths'] ?? [])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with a fresh listing of the folder, never used as given
        if ($do === 'preview') {
            $ids = array_values(array_filter(array_map('absint', (array) wp_unslash($_POST['ids'] ?? []))));
            wp_send_json_success(self::merge_preview($keep, $ids, $paths));
        }
        if ($do === 'run') {
            $id = absint($_POST['id'] ?? 0);
            $r = Merge::start($keep, $id ?: ($paths[0] ?? ''), !empty($_POST['delete']));
        } elseif ($do === 'step') {
            $r = Merge::step($merge);
        } elseif ($do === 'undo') {
            $r = Merge::undo($merge);
        } else {
            wp_send_json_error(__('Invalid request.', 'elinweb-unused-media-finder'), 400);
        }
        isset($r['error']) ? wp_send_json_error($r['error'], 409) : wp_send_json_success($r);
    }

    /** "Where?" for an extra file whose URL appears somewhere. */
    public static function ajax_where(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $path = (string) wp_unslash($_POST['path'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with a fresh listing of its folder before use, escaped on output
        if (!Orphans::is_extra($path)) {
            wp_send_json_error(__('This file is not an extra file of the uploads folder.', 'elinweb-unused-media-finder'), 404);
        }
        wp_send_json_success(['html' => Admin::where_html($path), 'title' => __('Where it is used', 'elinweb-unused-media-finder')]);
    }

    public static function ajax_preview(): void
    {
        Admin::guard(check_ajax_referer('mtrk', false, false));
        $id = absint($_POST['id'] ?? 0);
        if (get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('Media not found.', 'elinweb-unused-media-finder'), 404);
        }
        wp_send_json_success(['html' => self::preview_html($id), 'title' => get_the_title($id) ?: wp_basename((string) get_attached_file($id))]);
    }
}
