<?php

namespace ElinwebUnusedMediaFinder;

defined('ABSPATH') || exit;

/** Media library integration (usage column, filter, modal) and the assets shared with the plugin page. */
final class Admin
{
    public static function init(): void
    {
        Page::init();
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_enqueue_media', [self::class, 'enqueue']);
        add_action('wp_ajax_mtrk_usage', [self::class, 'ajax_usage']);
        add_filter('manage_media_columns', [self::class, 'column']);
        add_filter('manage_upload_sortable_columns', fn($cols) => $cols + ['mtrk' => ['mtrk', false]]);
        add_filter('posts_clauses', [self::class, 'sort_clauses'], 10, 2);
        add_filter('ajax_query_attachments_args', [self::class, 'grid_query']);
        add_action('manage_media_custom_column', [self::class, 'cell'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'filter_select']);
        add_action('pre_get_posts', [self::class, 'filter_query']);
        add_filter('posts_where', [self::class, 'filter_where'], 10, 2);
        add_filter('attachment_fields_to_edit', [self::class, 'field'], 10, 2);
    }

    public static function menu(): void
    {
        add_media_page(__('Unused Media Finder', 'elinweb-unused-media-finder'), __('Unused Media Finder', 'elinweb-unused-media-finder'), Scanner::CAP, 'elinweb-unused-media-finder', [Page::class, 'render']);
    }

    public static function assets(string $hook): void
    {
        if (in_array($hook, ['upload.php', 'post.php', 'media_page_elinweb-unused-media-finder'], true)) {
            self::enqueue();
        }
        if ($hook === 'upload.php' && current_user_can(Scanner::CAP)) { // the grid's usage filter extends media-views
            wp_enqueue_script('mtrk-grid', plugins_url('assets/grid.js', MTRK_FILE), ['media-views', 'mtrk'], MTRK_VERSION, true);
        }
    }

    public static function enqueue(): void
    {
        if (!is_admin() || !current_user_can(Scanner::CAP) || wp_script_is('mtrk')) {
            return;
        }
        wp_enqueue_style('mtrk', plugins_url('assets/admin.css', MTRK_FILE), [], MTRK_VERSION);
        wp_enqueue_script('mtrk', plugins_url('assets/admin.js', MTRK_FILE), [], MTRK_VERSION, true);
        wp_add_inline_script('mtrk', 'window.mtrk = ' . wp_json_encode([
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mtrk'),
            'uploads' => ['url' => wp_upload_dir(null, false)['baseurl'], 'root' => wp_normalize_path(wp_upload_dir(null, false)['basedir'])],
            'i18n' => [
                'zipBuilding' => __('Building the ZIP…', 'elinweb-unused-media-finder'),
                'exportBuilding' => __('Preparing the list…', 'elinweb-unused-media-finder'),
                /* translators: %s: number of rows */
                'exportDone' => __('List downloaded: %s row(s).', 'elinweb-unused-media-finder'),
                /* translators: %s: number of files */
                'zipDone' => __('%s file(s) added to the ZIP.', 'elinweb-unused-media-finder'),
                /* translators: %s: number of folders left */
                'dupsList' => __('Listing extra files… %s folder(s) left', 'elinweb-unused-media-finder'),
                /* translators: 1: number of media, 2: number of extra files */
                'confirmMixed' => __('%1$s media and %2$s extra file(s) will be deleted permanently. This cannot be undone. Continue?', 'elinweb-unused-media-finder'),
                'title' => __('Where it is used', 'elinweb-unused-media-finder'),
                'close' => __('Close', 'elinweb-unused-media-finder'),
                'loading' => __('Loading…', 'elinweb-unused-media-finder'),
                'error' => __('Could not reach the server.', 'elinweb-unused-media-finder'),
                'done' => __('Scan finished.', 'elinweb-unused-media-finder'),
                'stopping' => __('Stopping…', 'elinweb-unused-media-finder'),
                /* translators: %s: number of images checked */
                'sizesChecking' => __('Checking… %s image(s)', 'elinweb-unused-media-finder'),
                'sizesNone' => __('No unregistered sizes found.', 'elinweb-unused-media-finder'),
                'mb' => __('MB', 'elinweb-unused-media-finder'),
                'kb' => __('KB', 'elinweb-unused-media-finder'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesFound' => __('%1$s file(s), %2$s deletable.', 'elinweb-unused-media-finder'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesConfirm' => __('%1$s file(s) (%2$s) will be deleted permanently. Continue?', 'elinweb-unused-media-finder'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesDeleted' => __('%1$s file(s) deleted (%2$s).', 'elinweb-unused-media-finder'),
                'preview' => __('Preview', 'elinweb-unused-media-finder'),
                /* translators: %s: number of attachments left */
                'dupsSizes' => __('Comparing sizes… %s media left', 'elinweb-unused-media-finder'),
                /* translators: %s: number of files left */
                'dupsHashes' => __('Comparing the content of same-size files… %s file(s) left', 'elinweb-unused-media-finder'),
                'usageLabel' => __('Usage status', 'elinweb-unused-media-finder'),
                'usageAll' => __('Usage status: all', 'elinweb-unused-media-finder'),
                'usageUsed' => __('Used', 'elinweb-unused-media-finder'),
                'usageUnused' => __('Unreferenced', 'elinweb-unused-media-finder'),
                'usageNew' => __('New, unreferenced', 'elinweb-unused-media-finder'),
                /* translators: %s: number of items */
                'selected' => __('%s selected', 'elinweb-unused-media-finder'),
                /* translators: %s: number of media items */
                'confirmDelete' => __('%s media and all their files will be deleted permanently. This cannot be undone. Continue?', 'elinweb-unused-media-finder'),
                /* translators: %s: number of files */
                'confirmFiles' => __('%s file(s) will be deleted permanently. This cannot be undone. Continue?', 'elinweb-unused-media-finder'),
                /* translators: %s: number of folders */
                'orphansChecking' => __('Checking… %s folder(s)', 'elinweb-unused-media-finder'),
                'orphansNone' => __('No extra files found.', 'elinweb-unused-media-finder'),
                /* translators: 1: number of files, 2: size with unit */
                'orphansFound' => __('%1$s file(s), %2$s deletable.', 'elinweb-unused-media-finder'),
                /* translators: %s: attachment id */
                'orphanSize' => __('Extra size of attachment #%s', 'elinweb-unused-media-finder'),
                'orphanFile' => __('No media item owns this file', 'elinweb-unused-media-finder'),
                /* translators: %s: date */
                'checkedAt' => __('Last checked: %s', 'elinweb-unused-media-finder'),
                'noPreview' =>__('No preview for this file type.', 'elinweb-unused-media-finder'),
                'fileLabel' => __('File', 'elinweb-unused-media-finder'),
                'sizeLabel' => __('Size', 'elinweb-unused-media-finder'),
                'openTab' =>__('Open in a new tab', 'elinweb-unused-media-finder'),
                'orphanKept' =>__('Its URL appears somewhere; it is kept', 'elinweb-unused-media-finder'),
                'more' => __('Show more', 'elinweb-unused-media-finder'),
                'probeOk' => __('Requests for quarantined files reach WordPress: visitors are served the file and the request is logged.', 'elinweb-unused-media-finder'),
                'probeFail' => __('Requests for missing files do not reach WordPress (probably an nginx/LiteSpeed rule for image files, or a CDN). A quarantined file returns a 404 to visitors and no hit is logged. In this setup, quarantine is only a reversible delete.', 'elinweb-unused-media-finder'),
            ],
        ]) . ';', 'before');
    }

    // ---- ajax ------------------------------------------------------------------------------------------------

    public static function ajax_usage(): void
    {
        self::guard(check_ajax_referer('mtrk', false, false));
        $id = absint($_POST['id'] ?? 0);
        if (get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('Media not found.', 'elinweb-unused-media-finder'), 404);
        }
        try {
            wp_send_json_success(['html' => self::usage_html($id)]);
        } catch (\Throwable $e) { // say what broke, instead of a bare "could not reach the server"
            wp_send_json_error($e->getMessage(), 500);
        }
    }

    /** Every AJAX handler starts with self::guard(check_ajax_referer('mtrk', false, false)): stops unless the nonce is valid and the user may manage media. */
    public static function guard($nonce): void
    {
        if (!$nonce) {
            wp_send_json_error(__('Your session has expired; reload the page.', 'elinweb-unused-media-finder'), 403);
        }
        if (!current_user_can(Scanner::CAP)) {
            wp_send_json_error(__('You are not allowed to do this.', 'elinweb-unused-media-finder'), 403);
        }
    }

    // ---- usage modal -----------------------------------------------------------------------------------------

    private static function usage_html(int $id): string
    {
        $refs = Scanner::refs($id);
        $status = Scanner::status($id, count($refs));
        $last = Scanner::last();
        $groups = [];
        foreach ($refs as $r) {
            $owned = in_array($r['source_type'], ['post', 'term', 'user', 'comment'], true);
            $groups[$owned ? $r['source_type'] . '|' . $r['source_id'] : $r['source_type'] . '|' . $r['source_id'] . '|' . $r['source_key']][] = $r;
        }

        ob_start();
        ?>
        <div class="mtrk-head">
            <?php echo wp_get_attachment_image($id, [60, 60], true); ?>
            <div>
                <strong><?php echo esc_html(get_the_title($id) ?: wp_basename((string) get_attached_file($id))); ?></strong><br>
                <?php echo wp_kses(self::badge($id, count($groups), false), self::allowed_html()); ?>
            </div>
        </div>
        <?php if (get_post_meta($id, Cleanup::META, true)) : ?>
            <p>
                <?php esc_html_e('This media is quarantined: its files were moved out of their original path and requests for them are logged.', 'elinweb-unused-media-finder'); ?>
                <a href="<?php echo esc_url(admin_url('upload.php?page=elinweb-unused-media-finder&tab=quarantine')); ?>"><?php esc_html_e('Quarantine', 'elinweb-unused-media-finder'); ?></a>
            </p>
        <?php endif; ?>
        <?php if ($status === 'none') : ?>
            <p>
                <?php esc_html_e('No scan has been run yet.', 'elinweb-unused-media-finder'); ?>
                <a href="<?php echo esc_url(admin_url('upload.php?page=elinweb-unused-media-finder')); ?>"><?php esc_html_e('Scan', 'elinweb-unused-media-finder'); ?></a>
            </p>
        <?php elseif ($status === 'new') : ?>
            <p><?php esc_html_e('This media was uploaded after the last full scan and has not been used in posts, meta or settings since. Plugin tables and code files are only checked by a full scan.', 'elinweb-unused-media-finder'); ?></p>
        <?php elseif ($status === 'unused') : ?>
            <p><?php esc_html_e('No reference to this media was found within the scope of the last scan.', 'elinweb-unused-media-finder'); ?></p>
        <?php else : ?>
            <ul class="mtrk-list">
                <?php foreach ($groups as $rows) : ?>
                    <li><?php echo wp_kses(self::item($rows), self::allowed_html()); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($refs) >= 500) : ?>
                <p class="mtrk-note"><?php esc_html_e('Only the first 500 items are shown.', 'elinweb-unused-media-finder'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($last) : ?>
            <p class="mtrk-note">
                <?php
                printf(
                    /* translators: 1: date, 2: scope */
                    esc_html__('Last full scan: %1$s — %2$s. Changes since then to posts, meta, terms, users and settings are recorded live; plugin tables and code only by a full scan. Use outside the site (email, social media, other sites) cannot be detected.', 'elinweb-unused-media-finder'),
                    esc_html(wp_date(get_option('date_format') . ' H:i', $last['time'])),
                    esc_html(Page::scope($last))
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /** One line of the modal: the object (post, term, ...) with a link, and the fields that hold the reference. */
    private static function item(array $rows): string
    {
        $type = $rows[0]['source_type'];
        $sid = (int) $rows[0]['source_id'];
        $key = $rows[0]['source_key'];
        $fields = implode(wp_get_list_item_separator(), array_map(fn($r) => self::field_label($r['source_key'], $r['how']), $rows));

        switch ($type) {
            case 'post':
                $post = get_post($sid);
                if (!$post) {
                    /* translators: %d: post id */
                    return esc_html(sprintf(__('Deleted post #%d', 'elinweb-unused-media-finder'), $sid));
                }
                $pto = get_post_type_object($post->post_type);
                $pso = get_post_status_object($post->post_status);
                $meta = [esc_html($pto ? $pto->labels->singular_name : $post->post_type), esc_html($pso ? $pso->label : $post->post_status), $fields];
                $view = is_post_type_viewable($post->post_type) && $post->post_status === 'publish'
                    ? sprintf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($post)), esc_html__('View', 'elinweb-unused-media-finder'))
                    : '';
                return self::line(get_the_title($post) ?: __('(no title)', 'elinweb-unused-media-finder'), get_edit_post_link($post->ID, 'raw'), $meta) . $view;

            case 'term':
                $term = get_term($sid);
                if (!$term || is_wp_error($term)) {
                    /* translators: %d: term id */
                    return esc_html(sprintf(__('Deleted term #%d', 'elinweb-unused-media-finder'), $sid));
                }
                $tax = get_taxonomy($term->taxonomy);
                return self::line($term->name, get_edit_term_link($term), [esc_html($tax ? $tax->labels->singular_name : $term->taxonomy), $fields]);

            case 'user':
                $user = get_userdata($sid);
                if (!$user) {
                    /* translators: %d: user id */
                    return esc_html(sprintf(__('Deleted user #%d', 'elinweb-unused-media-finder'), $sid));
                }
                return self::line($user->display_name, get_edit_user_link($sid), [esc_html__('User', 'elinweb-unused-media-finder'), $fields]);

            case 'comment':
                $comment = get_comment($sid);
                /* translators: %d: comment id */
                $title = sprintf(__('Comment #%d', 'elinweb-unused-media-finder'), $sid);
                return $comment
                    ? self::line($title, get_edit_comment_link($comment), [esc_html(get_the_title((int) $comment->comment_post_ID)), $fields])
                    : esc_html($title);

            case 'option':
                if (strpos($key, 'theme_mods_') === 0) {
                    return self::line(__('Theme customizer', 'elinweb-unused-media-finder'), admin_url('customize.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                if (strpos($key, 'widget_') === 0) {
                    return self::line(__('Widgets', 'elinweb-unused-media-finder'), admin_url('widgets.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                $named = ['site_icon' => __('Site icon', 'elinweb-unused-media-finder'), 'site_logo' => __('Site logo', 'elinweb-unused-media-finder')];
                return self::line($named[$key] ?? __('Settings', 'elinweb-unused-media-finder'), '', ['<code>' . esc_html($key) . '</code>']);

            case 'file':
                return self::line(__('Code file', 'elinweb-unused-media-finder'), '', ['<code dir="ltr">' . esc_html($key) . '</code>']);

            case 'keep':
                return self::line(__('Kept by you', 'elinweb-unused-media-finder'), '', [esc_html__('Marked as used outside the site.', 'elinweb-unused-media-finder')])
                    . sprintf(' <button type="button" class="button-link" data-act="unkeep" data-id="%d">%s</button>', (int) $rows[0]['attachment_id'], esc_html__('Remove the mark', 'elinweb-unused-media-finder'));

            case 'shared':
                /* translators: %d: attachment id */
                $title = get_the_title($sid) ?: sprintf(__('Attachment #%d', 'elinweb-unused-media-finder'), $sid);
                return self::line($title, get_edit_post_link($sid, 'raw') ?: '', [esc_html__('It has the same file and is in use; deleting this attachment deletes the shared file and breaks the other one\'s image too.', 'elinweb-unused-media-finder')]);

            default:
                return self::plugin_row($key, $sid);
        }
    }

    /** Plugin tables we know get a name and an edit link; the rest show table.column and the row id. */
    private static function plugin_row(string $key, int $sid): string
    {
        global $wpdb;
        $known = [ // table without prefix => [plugin, table holding the title, edit url]
            'gf_form_meta' => ['Gravity Forms', 'gf_form', 'admin.php?page=gf_edit_forms&id=%d'],
            'fluentform_forms' => ['Fluent Forms', 'fluentform_forms', 'admin.php?page=fluent_forms&route=editor&form_id=%d'],
        ];
        $table = (string) strtok($key, '.');
        $name = strpos($table, $wpdb->prefix) === 0 ? substr($table, strlen($wpdb->prefix)) : '';
        if ($sid && isset($known[$name])) {
            [$plugin, $titles, $url] = $known[$name];
            $title = $wpdb->get_var($wpdb->prepare("SELECT title FROM `{$wpdb->prefix}$titles` WHERE id = %d", $sid)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- $titles is a table name from the fixed list above
            /* translators: %d: form id */
            return self::line($title ?: sprintf(__('Form #%d', 'elinweb-unused-media-finder'), $sid), admin_url(sprintf($url, $sid)), [esc_html($plugin)]);
        }
        /* translators: %d: row id */
        $row = $sid ? sprintf(__('Row #%d', 'elinweb-unused-media-finder'), $sid) : '';
        return self::line(__('Plugin table', 'elinweb-unused-media-finder'), '', array_filter(['<code dir="ltr">' . esc_html($key) . '</code>', esc_html($row)]));
    }

    /** The markup item(), badge() and line() build (their text is already escaped); anything else is stripped on output. */
    public static function allowed_html(): array
    {
        return [
            'a' => ['href' => true, 'target' => true, 'rel' => true],
            'strong' => [], 'br' => [], 'code' => ['dir' => true],
            'span' => ['class' => true],
            'button' => ['type' => true, 'class' => true, 'data-id' => true, 'data-act' => true, 'aria-haspopup' => true],
        ];
    }

    /** $meta parts are already escaped HTML. */
    private static function line(string $title, ?string $url, array $meta): string
    {
        $title = $url ? sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($title)) : esc_html($title);
        return '<strong>' . $title . '</strong><br><span class="mtrk-meta">' . implode(' · ', array_filter($meta)) . '</span>';
    }

    private static function field_label(string $key, string $how): string
    {
        $labels = [
            '' => __('Content', 'elinweb-unused-media-finder'),
            '_thumbnail_id' => __('Featured image', 'elinweb-unused-media-finder'),
            'thumbnail_id' => __('Featured image', 'elinweb-unused-media-finder'),
            '_product_image_gallery' => __('Product gallery', 'elinweb-unused-media-finder'),
            '_elementor_data' => __('Elementor', 'elinweb-unused-media-finder'),
            'description' => __('Description', 'elinweb-unused-media-finder'),
        ];
        $label = isset($labels[$key]) ? esc_html($labels[$key]) : '<code dir="ltr">' . esc_html($key) . '</code>';
        $how = $how === 'id' ? __('By ID', 'elinweb-unused-media-finder') : __('By URL', 'elinweb-unused-media-finder');
        return $label . ' <span class="mtrk-how">(' . esc_html($how) . ')</span>';
    }

    public static function badge(int $id, int $count, bool $button = true): string
    {
        $status = get_post_meta($id, Cleanup::META, true) ? 'quarantine' : Scanner::status($id, $count);
        $text = [
            'quarantine' => __('Quarantined', 'elinweb-unused-media-finder'),
            'none' => __('Not scanned', 'elinweb-unused-media-finder'),
            /* translators: %s: number of places */
            'used' => sprintf(__('Used in %s place(s)', 'elinweb-unused-media-finder'), number_format_i18n($count)),
            'unused' => __('Unreferenced', 'elinweb-unused-media-finder'),
            'new' => __('New, unreferenced', 'elinweb-unused-media-finder'),
        ][$status];
        return $button
            ? sprintf('<button type="button" class="mtrk-badge mtrk-%s mtrk-open" data-id="%d" aria-haspopup="dialog">%s</button>', $status, $id, esc_html($text))
            : sprintf('<span class="mtrk-badge mtrk-%s">%s</span>', $status, esc_html($text));
    }

    // ---- media library ---------------------------------------------------------------------------------------

    public static function column(array $cols): array
    {
        if (current_user_can(Scanner::CAP)) {
            $cols['mtrk'] = __('Usage', 'elinweb-unused-media-finder');
        }
        return $cols;
    }

    public static function cell(string $col, int $id): void
    {
        if ($col !== 'mtrk') {
            return;
        }
        static $counts = null;
        if ($counts === null) { // one query for the whole page
            global $wp_query;
            $counts = Scanner::counts(wp_list_pluck($wp_query->posts ?? [], 'ID'));
        }
        echo wp_kses(self::badge($id, $counts[$id] ?? 0), self::allowed_html());
    }

    public static function filter_select($post_type): void
    {
        if ($post_type !== 'attachment' || !current_user_can(Scanner::CAP)) {
            return;
        }
        $current = sanitize_key(wp_unslash($_GET['mtrk_usage'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only marks the selected option of the list filter
        $options = [
            '' => __('Usage status: all', 'elinweb-unused-media-finder'),
            'used' => __('Used', 'elinweb-unused-media-finder'),
            'unused' => __('Unreferenced', 'elinweb-unused-media-finder'),
            'new' => __('New, unreferenced', 'elinweb-unused-media-finder'),
        ];
        echo '<label for="mtrk-usage" class="screen-reader-text">' . esc_html__('Usage status', 'elinweb-unused-media-finder') . '</label><select name="mtrk_usage" id="mtrk-usage">';
        foreach ($options as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public static function filter_query(\WP_Query $q): void
    {
        global $pagenow;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a Media Library list filter, like core's own filters there; it only narrows what is shown
        if ($q->is_main_query() && $pagenow === 'upload.php' && !empty($_GET['mtrk_usage']) && current_user_can(Scanner::CAP)) {
            $q->set('mtrk_usage', sanitize_key(wp_unslash($_GET['mtrk_usage'])));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /** List view sorted by the usage column: fewest places first (ascending), so unreferenced media leads. */
    public static function sort_clauses(array $clauses, \WP_Query $q): array
    {
        global $wpdb;
        if ($q->get('orderby') !== 'mtrk' || !is_admin() || !current_user_can(Scanner::CAP)) {
            return $clauses;
        }
        $clauses['join'] .= ' LEFT JOIN (SELECT attachment_id, COUNT(*) AS n FROM ' . Scanner::table() . " GROUP BY attachment_id) mtrk ON mtrk.attachment_id = $wpdb->posts.ID";
        $dir = strtoupper((string) $q->get('order')) === 'DESC' ? 'DESC' : 'ASC';
        $clauses['orderby'] = "COALESCE(mtrk.n, 0) $dir, $wpdb->posts.post_date DESC";
        return $clauses;
    }

    /** Grid view: WordPress drops unknown query args before this filter, so the usage filter comes from the request. */
    public static function grid_query(array $query): array
    {
        $usage = sanitize_key(wp_unslash($_REQUEST['query']['mtrk_usage'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter of core's query-attachments request; it only narrows what is shown
        if ($usage && current_user_can(Scanner::CAP)) {
            $query['mtrk_usage'] = $usage;
        }
        return $query;
    }

    public static function filter_where(string $where, \WP_Query $q): string
    {
        $usage = $q->get('mtrk_usage');
        return $usage ? $where . Scanner::where($usage) : $where;
    }

    /** Grid view / media modal: the same badge in the attachment details panel. */
    public static function field(array $fields, \WP_Post $post): array
    {
        if (current_user_can(Scanner::CAP)) {
            $fields['mtrk'] = [
                'label' => __('Usage', 'elinweb-unused-media-finder'),
                'input' => 'html',
                'html' => self::badge($post->ID, Scanner::counts([$post->ID])[$post->ID] ?? 0),
            ];
        }
        return $fields;
    }
}
