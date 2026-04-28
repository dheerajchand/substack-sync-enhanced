<?php

declare(strict_types=1);

/**
 * Substack Sync - WordPress Plugin
 *
 * Copyright (c) 2025 Christopher S. Penn
 * Licensed under Apache License Version 2.0
 *
 * NO SUPPORT PROVIDED. USE AT YOUR OWN RISK.
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for the admin area.
 */
class Substack_Sync_Admin
{
    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_substack_sync_now', [$this, 'handle_sync_now']);
        add_action('wp_ajax_substack_sync_batch', [$this, 'handle_batch_sync']);
        add_action('wp_ajax_substack_retry_failed', [$this, 'handle_retry_failed']);
        add_action('wp_ajax_substack_rollback_posts', [$this, 'handle_rollback_posts']);
        add_action('wp_ajax_substack_get_sync_stats', [$this, 'handle_get_sync_stats']);
        add_action('wp_ajax_substack_sitemap_sync', [$this, 'handle_sitemap_sync']);
        add_action('wp_ajax_substack_zip_import', [$this, 'handle_zip_import']);
    }

    /**
     * Register the administration menu for this plugin.
     */
    public function add_admin_menu(): void
    {
        add_options_page(
            'Siege Analytics Sync for Substack',
            'Substack Sync',
            'manage_options',
            'substack-sync',
            [$this, 'settings_page_html']
        );
    }

    /**
     * Enqueue admin scripts and styles on the plugin settings page only.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'settings_page_substack-sync') {
            return;
        }

        wp_enqueue_style(
            'substack-sync-admin',
            plugin_dir_url(__FILE__) . 'css/substack-sync-admin.css',
            [],
            SUBSTACK_SYNC_VERSION
        );

        wp_enqueue_script(
            'substack-sync-admin',
            plugin_dir_url(__FILE__) . 'js/substack-sync-admin.js',
            [],
            SUBSTACK_SYNC_VERSION,
            true
        );

        // Pass data to JS
        $categories = get_categories(['hide_empty' => false]);
        wp_localize_script('substack-sync-admin', 'substackSyncAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('substack_sync_nonce'),
            'categoryOptions' => array_map(function ($cat) {
                return ['id' => $cat->term_id, 'name' => $cat->name];
            }, $categories),
        ]);
    }

    /**
     * Register settings using the Settings API.
     */
    public function register_settings(): void
    {
        register_setting('substack_sync_settings_group', 'substack_sync_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section(
            'substack_sync_main',
            'Main Settings',
            [$this, 'settings_section_callback'],
            'substack-sync'
        );

        add_settings_field(
            'feed_url',
            'RSS Feed URL',
            [$this, 'feed_url_callback'],
            'substack-sync',
            'substack_sync_main'
        );

        add_settings_field(
            'default_author',
            'Default Author',
            [$this, 'default_author_callback'],
            'substack-sync',
            'substack_sync_main'
        );

        add_settings_field(
            'default_post_status',
            'Default Post Status',
            [$this, 'default_post_status_callback'],
            'substack-sync',
            'substack_sync_main'
        );

        add_settings_field(
            'category_mapping',
            'Category Mapping',
            [$this, 'category_mapping_callback'],
            'substack-sync',
            'substack_sync_main'
        );

        add_settings_field(
            'delete_data_on_uninstall',
            'Delete Data on Uninstall',
            [$this, 'delete_data_callback'],
            'substack-sync',
            'substack_sync_main'
        );
    }

    /**
     * Sanitize and validate settings before saving.
     *
     * @param array<string, mixed> $input The raw input from the settings form.
     * @return array<string, mixed> The sanitized settings.
     */
    public function sanitize_settings(array $input): array
    {
        $sanitized = [];

        // Feed URL — must be a valid URL
        $sanitized['feed_url'] = isset($input['feed_url']) ? esc_url_raw($input['feed_url']) : '';

        // Default author — must be a valid user ID
        $sanitized['default_author'] = isset($input['default_author']) ? absint($input['default_author']) : 1;

        // Default post status — whitelist allowed values
        $sanitized['default_post_status'] = isset($input['default_post_status']) && in_array($input['default_post_status'], ['draft', 'publish'], true)
            ? $input['default_post_status']
            : 'draft';

        // Category mapping — sanitize each keyword and validate category IDs
        $sanitized['category_mapping'] = [];
        if (! empty($input['category_mapping']) && is_array($input['category_mapping'])) {
            foreach ($input['category_mapping'] as $mapping) {
                $keyword = isset($mapping['keyword']) ? sanitize_text_field($mapping['keyword']) : '';
                $category = isset($mapping['category']) ? absint($mapping['category']) : 0;
                if (! empty($keyword) && $category > 0) {
                    $sanitized['category_mapping'][] = [
                        'keyword' => $keyword,
                        'category' => $category,
                    ];
                }
            }
        }

        // Delete data on uninstall — boolean
        $sanitized['delete_data_on_uninstall'] = ! empty($input['delete_data_on_uninstall']);

        return $sanitized;
    }

    /**
     * Settings section callback.
     */
    public function settings_section_callback(): void
    {
        echo '<div class="substack-sync-disclaimer">';
        echo '<h3>' . esc_html__('IMPORTANT DISCLAIMER', 'siege-analytics-sync-for-substack') . '</h3>';
        echo '<p><strong>' . esc_html__('This plugin is provided "as is" without warranty. The author is not responsible for any issues, data loss, or damage that may occur from using this plugin.', 'siege-analytics-sync-for-substack') . '</strong></p>';
        echo '</div>';

        echo '<div class="substack-sync-info">';
        echo '<h3>' . esc_html__('Support Your Community', 'siege-analytics-sync-for-substack') . '</h3>';
        echo '<p>' . esc_html__('If this plugin helps you, please consider supporting these worthy causes:', 'siege-analytics-sync-for-substack') . '</p>';
        echo '<ul>';
        echo '<li><a href="https://gbfb.org?utm_source=substack_sync_plugin&amp;utm_medium=referral" target="_blank" rel="noopener noreferrer">' . esc_html__('Greater Boston Food Bank', 'siege-analytics-sync-for-substack') . '</a> - ' . esc_html__('Fighting hunger in our communities', 'siege-analytics-sync-for-substack') . '</li>';
        echo '<li><a href="https://baypathhumane.org?utm_source=substack_sync_plugin&amp;utm_medium=referral" target="_blank" rel="noopener noreferrer">' . esc_html__('Baypath Humane Society of Hopkinton, Massachusetts', 'siege-analytics-sync-for-substack') . '</a> - ' . esc_html__('Caring for animals in need', 'siege-analytics-sync-for-substack') . '</li>';
        echo '</ul>';
        echo '</div>';
        echo '<p>' . esc_html__('Configure your Substack synchronization settings below.', 'siege-analytics-sync-for-substack') . '</p>';
    }

    /**
     * Feed URL field callback.
     */
    public function feed_url_callback(): void
    {
        $options = get_option('substack_sync_settings', []);
        $value = $options['feed_url'] ?? '';
        echo '<input type="url" name="substack_sync_settings[feed_url]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Enter your Substack RSS feed URL (e.g., https://yourname.substack.com/feed)', 'siege-analytics-sync-for-substack') . '</p>';
    }

    /**
     * Default author field callback.
     */
    public function default_author_callback(): void
    {
        $options = get_option('substack_sync_settings', []);
        $selected = $options['default_author'] ?? 1;

        wp_dropdown_users([
            'name' => 'substack_sync_settings[default_author]',
            'selected' => $selected,
            'show_option_none' => 'Select an author',
        ]);
        echo '<p class="description">' . esc_html__('Choose the WordPress user to be set as the author for imported posts.', 'siege-analytics-sync-for-substack') . '</p>';
    }

    /**
     * Default post status field callback.
     */
    public function default_post_status_callback(): void
    {
        $options = get_option('substack_sync_settings', []);
        $selected = $options['default_post_status'] ?? 'draft';

        echo '<select name="substack_sync_settings[default_post_status]">';
        echo '<option value="draft"' . selected($selected, 'draft', false) . '>' . esc_html__('Draft', 'siege-analytics-sync-for-substack') . '</option>';
        echo '<option value="publish"' . selected($selected, 'publish', false) . '>' . esc_html__('Published', 'siege-analytics-sync-for-substack') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Choose whether new posts should be imported as drafts or published immediately.', 'siege-analytics-sync-for-substack') . '</p>';
    }

    /**
     * Category mapping field callback.
     */
    public function category_mapping_callback(): void
    {
        $options = get_option('substack_sync_settings', []);
        $mappings = $options['category_mapping'] ?? [];

        echo '<div id="category-mapping-container">';
        echo '<p class="description">' . esc_html__('Map keywords found in posts to WordPress categories. Posts containing these keywords will be automatically assigned to the selected categories.', 'siege-analytics-sync-for-substack') . '</p>';

        $categories = get_categories(['hide_empty' => false]);

        if (empty($mappings)) {
            $mappings = [['keyword' => '', 'category' => '']];
        }

        foreach ($mappings as $index => $mapping) {
            echo '<div class="category-mapping-row">';
            echo '<label>' . esc_html__('Keyword:', 'siege-analytics-sync-for-substack') . ' </label>';
            echo '<input type="text" name="substack_sync_settings[category_mapping][' . intval($index) . '][keyword]" value="' . esc_attr($mapping['keyword'] ?? '') . '" placeholder="' . esc_attr__('e.g., marketing, tutorial', 'siege-analytics-sync-for-substack') . '" />';
            echo '<label>' . esc_html__('Category:', 'siege-analytics-sync-for-substack') . ' </label>';
            echo '<select name="substack_sync_settings[category_mapping][' . intval($index) . '][category]">';
            echo '<option value="">' . esc_html__('Select Category', 'siege-analytics-sync-for-substack') . '</option>';

            foreach ($categories as $category) {
                $selected = selected($mapping['category'] ?? '', $category->term_id, false);
                echo '<option value="' . intval($category->term_id) . '"' . $selected . '>' . esc_html($category->name) . '</option>';
            }

            echo '</select>';
            echo '<button type="button" class="button remove-mapping" onclick="removeCategoryMapping(this)">' . esc_html__('Remove', 'siege-analytics-sync-for-substack') . '</button>';
            echo '</div>';
        }

        echo '</div>';
        echo '<button type="button" class="button" onclick="addCategoryMapping()">' . esc_html__('Add Mapping', 'siege-analytics-sync-for-substack') . '</button>';
    }

    /**
     * Delete data field callback.
     */
    public function delete_data_callback(): void
    {
        $options = get_option('substack_sync_settings', []);
        $checked = isset($options['delete_data_on_uninstall']) && $options['delete_data_on_uninstall'];

        echo '<input type="checkbox" name="substack_sync_settings[delete_data_on_uninstall]" value="1"' . checked($checked, true, false) . ' />';
        echo '<p class="description">' . esc_html__('Check this box if you want to delete all plugin data when uninstalling.', 'siege-analytics-sync-for-substack') . '</p>';
    }

    /**
     * Display the settings page HTML.
     */
    public function settings_page_html(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
        $processor = new Substack_Sync_Processor();
        $stats = $processor->get_sync_stats();
        $failed_posts = $processor->get_posts_needing_retry();

        ?>
        <div class="wrap substack-sync-wrap">
            <h1><?php echo esc_html__('Siege Analytics Sync for Substack', 'siege-analytics-sync-for-substack'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active"><?php echo esc_html__('General Settings', 'siege-analytics-sync-for-substack'); ?></a>
                <a href="#sync" class="nav-tab"><?php echo esc_html__('Sync & Import', 'siege-analytics-sync-for-substack'); ?></a>
                <a href="#manage" class="nav-tab"><?php echo esc_html__('Manage Posts', 'siege-analytics-sync-for-substack'); ?></a>
                <a href="#logs" class="nav-tab"><?php echo esc_html__('Logs & Statistics', 'siege-analytics-sync-for-substack'); ?></a>
            </nav>

            <div id="general" class="tab-content" style="display: block;">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('substack_sync_settings_group');
                    do_settings_sections('substack-sync');
                    submit_button(esc_html__('Save Settings', 'siege-analytics-sync-for-substack'));
                    ?>
                </form>
            </div>

            <div id="sync" class="tab-content" style="display: none;">
                <h2><?php echo esc_html__('Manual Sync & Import', 'siege-analytics-sync-for-substack'); ?></h2>
                <div class="sync-overview">
                    <div class="sync-stats-grid">
                        <div class="stat-card">
                            <h3><?php echo esc_html__('Total Synced', 'siege-analytics-sync-for-substack'); ?></h3>
                            <p class="stat-value"><?php echo intval($stats['total_synced']); ?></p>
                        </div>
                        <div class="stat-card stat-card--imported">
                            <h3><?php echo esc_html__('Imported', 'siege-analytics-sync-for-substack'); ?></h3>
                            <p class="stat-value"><?php echo intval($stats['imported_count']); ?></p>
                        </div>
                        <div class="stat-card stat-card--updated">
                            <h3><?php echo esc_html__('Updated', 'siege-analytics-sync-for-substack'); ?></h3>
                            <p class="stat-value"><?php echo intval($stats['updated_count']); ?></p>
                        </div>
                        <div class="stat-card stat-card--errors">
                            <h3><?php echo esc_html__('Errors', 'siege-analytics-sync-for-substack'); ?></h3>
                            <p class="stat-value"><?php echo intval($stats['error_count']); ?></p>
                        </div>
                    </div>

                    <?php if ($stats['last_sync_date']) : ?>
                    <p><strong><?php echo esc_html__('Last Sync:', 'siege-analytics-sync-for-substack'); ?></strong> <?php echo esc_html(wp_date('F j, Y g:i a', strtotime($stats['last_sync_date']))); ?></p>
                    <?php endif; ?>
                </div>

                <div class="sync-actions" style="margin: 20px 0;">
                    <button type="button" id="sync-now-btn" class="button button-primary"><?php echo esc_html__('Sync Now', 'siege-analytics-sync-for-substack'); ?></button>
                    <?php if (! empty($failed_posts)) : ?>
                        <button type="button" id="retry-failed-btn" class="button button-secondary">
                            <?php
                            /* translators: %d: number of failed posts */
                            echo esc_html(sprintf(__('Retry Failed Posts (%d)', 'siege-analytics-sync-for-substack'), count($failed_posts)));
                            ?>
                        </button>
                    <?php endif; ?>
                </div>

                <div id="sync-status"></div>

                <hr style="margin: 30px 0;">

                <h2><?php echo esc_html__('Full Archive Sync (Sitemap)', 'siege-analytics-sync-for-substack'); ?></h2>
                <p><?php echo esc_html__('The RSS feed only includes recent posts. Use sitemap sync to import ALL posts from your Substack archive.', 'siege-analytics-sync-for-substack'); ?></p>
                <div style="margin: 10px 0;">
                    <button type="button" id="sitemap-sync-btn" class="button button-secondary"><?php echo esc_html__('Sync All Posts (Sitemap)', 'siege-analytics-sync-for-substack'); ?></button>
                    <p class="description"><?php echo esc_html__('Scrapes each post from your Substack site. Slower than RSS but gets all posts. Already-synced posts are skipped.', 'siege-analytics-sync-for-substack'); ?></p>
                </div>
                <div id="sitemap-sync-status"></div>

                <hr style="margin: 30px 0;">

                <h2><?php echo esc_html__('Import from Substack Export (ZIP)', 'siege-analytics-sync-for-substack'); ?></h2>
                <p><?php echo esc_html__('Upload a ZIP file exported from Substack (Settings > Export in your Substack dashboard). This imports all posts including their full content.', 'siege-analytics-sync-for-substack'); ?></p>
                <div style="margin: 10px 0;">
                    <input type="file" id="substack-zip-file" accept=".zip">
                    <button type="button" id="zip-import-btn" class="button button-secondary"><?php echo esc_html__('Import ZIP', 'siege-analytics-sync-for-substack'); ?></button>
                </div>
                <div id="zip-import-status"></div>
            </div>

            <div id="manage" class="tab-content" style="display: none;">
                <h2><?php echo esc_html__('Manage Synced Posts', 'siege-analytics-sync-for-substack'); ?></h2>
                <div class="manage-actions">
                    <div class="substack-sync-warning">
                        <h3><?php echo esc_html__('Warning: Destructive Actions', 'siege-analytics-sync-for-substack'); ?></h3>
                        <p><?php echo esc_html__('These actions will permanently delete WordPress posts that were imported from Substack. This cannot be undone.', 'siege-analytics-sync-for-substack'); ?></p>
                    </div>

                    <h3><?php echo esc_html__('Rollback Options', 'siege-analytics-sync-for-substack'); ?></h3>
                    <p><?php echo esc_html__('Select which synced posts to remove from WordPress:', 'siege-analytics-sync-for-substack'); ?></p>

                    <div>
                        <button type="button" id="rollback-all-btn" class="button button-secondary"><?php echo esc_html__('Remove All Synced Posts', 'siege-analytics-sync-for-substack'); ?></button>
                        <p class="description"><?php echo esc_html__('Removes all posts that were imported from Substack', 'siege-analytics-sync-for-substack'); ?></p>
                    </div>

                    <div>
                        <button type="button" id="rollback-failed-btn" class="button"><?php echo esc_html__('Remove Failed Posts Only', 'siege-analytics-sync-for-substack'); ?></button>
                        <p class="description"><?php echo esc_html__('Removes only posts that had errors during sync', 'siege-analytics-sync-for-substack'); ?></p>
                    </div>

                    <div>
                        <label><?php echo esc_html__('Remove posts by date range:', 'siege-analytics-sync-for-substack'); ?></label><br>
                        <input type="date" id="rollback-date-from"> <?php echo esc_html__('to', 'siege-analytics-sync-for-substack'); ?>
                        <input type="date" id="rollback-date-to">
                        <button type="button" id="rollback-date-btn" class="button"><?php echo esc_html__('Remove Date Range', 'siege-analytics-sync-for-substack'); ?></button>
                    </div>
                </div>

                <div id="rollback-status"></div>
            </div>

            <div id="logs" class="tab-content" style="display: none;">
                <h2><?php echo esc_html__('Sync Logs & Statistics', 'siege-analytics-sync-for-substack'); ?></h2>

                <?php if (! empty($failed_posts)) : ?>
                <div class="failed-posts-section" style="margin-bottom: 30px;">
                    <h3 style="color: #dc3232;">
                        <?php
                        /* translators: %d: number of failed posts */
                        echo esc_html(sprintf(__('Failed Posts (%d)', 'siege-analytics-sync-for-substack'), count($failed_posts)));
                        ?>
                    </h3>
                    <div class="failed-posts-list">
                        <?php foreach ($failed_posts as $post) : ?>
                            <div class="failed-post-item">
                                <strong><?php echo esc_html($post['substack_title']); ?></strong>
                                <br>
                                <small>
                                    <?php
                                    /* translators: %d: number of retry attempts */
                                    echo esc_html(sprintf(__('Attempts: %d', 'siege-analytics-sync-for-substack'), intval($post['retry_count'])));
                                    ?>
                                    | <?php echo esc_html__('Error:', 'siege-analytics-sync-for-substack'); ?> <?php echo esc_html($post['error_message']); ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="sync-log-section">
                    <h3><?php echo esc_html__('Recent Activity', 'siege-analytics-sync-for-substack'); ?></h3>
                    <div id="sync-activity-log" class="sync-activity-log">
                        <div style="color: #666;"><?php echo esc_html__('Loading recent sync activity...', 'siege-analytics-sync-for-substack'); ?></div>
                    </div>
                    <button type="button" id="refresh-logs-btn" class="button" style="margin-top: 10px;"><?php echo esc_html__('Refresh Logs', 'siege-analytics-sync-for-substack'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX sync now request.
     */
    public function handle_sync_now(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        check_ajax_referer('substack_sync_nonce');

        try {
            require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
            $processor = new Substack_Sync_Processor();
            $result = $processor->run_sync(true);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['error']);
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle AJAX batch sync request for progressive sync.
     */
    public function handle_batch_sync(): void
    {
        // Prevent any output before JSON response
        ob_clean();

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);

            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'substack_sync_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);

            return;
        }

        $offset = intval($_POST['offset'] ?? 0);
        $batch_size = intval($_POST['batch_size'] ?? 1);

        try {
            require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
            $processor = new Substack_Sync_Processor();
            $result = $processor->run_batch_sync($batch_size, $offset);

            // Ensure clean JSON response
            wp_send_json_success($result);
        } catch (Throwable $e) {
            error_log('Substack Sync AJAX Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error([
                'message' => 'Sync error: ' . $e->getMessage(),
                'has_more' => false,
            ]);
        }
    }

    /**
     * Handle retry failed posts AJAX request.
     */
    public function handle_retry_failed(): void
    {
        ob_clean();

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);

            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'substack_sync_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);

            return;
        }

        try {
            require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
            $processor = new Substack_Sync_Processor();
            $failed_posts = $processor->get_posts_needing_retry();

            if (empty($failed_posts)) {
                wp_send_json_success(['message' => 'No failed posts to retry']);

                return;
            }

            $retried_count = 0;
            foreach ($failed_posts as $failed_post) {
                // Reset retry count to allow retry
                $processor->reset_post_retry_count($failed_post['substack_guid']);
                $retried_count++;
            }

            wp_send_json_success([
                'message' => "Reset retry status for {$retried_count} posts. Run sync again to retry them.",
                'retried_count' => $retried_count,
            ]);
        } catch (Throwable $e) {
            error_log('Substack Sync Retry Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Retry error: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle rollback posts AJAX request.
     */
    public function handle_rollback_posts(): void
    {
        ob_clean();

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);

            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'substack_sync_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);

            return;
        }

        $type = sanitize_text_field(wp_unslash($_POST['type'] ?? ''));

        try {
            require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
            $processor = new Substack_Sync_Processor();

            $deleted_count = 0;

            switch ($type) {
                case 'all':
                    $deleted_count = $processor->rollback_all_posts();

                    break;
                case 'failed':
                    $deleted_count = $processor->rollback_failed_posts();

                    break;
                case 'date':
                    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
                    $date_to = sanitize_text_field($_POST['date_to'] ?? '');
                    $deleted_count = $processor->rollback_posts_by_date($date_from, $date_to);

                    break;
                default:
                    wp_send_json_error(['message' => 'Invalid rollback type']);

                    return;
            }

            wp_send_json_success([
                'message' => "Successfully removed {$deleted_count} posts from WordPress",
                'deleted_count' => $deleted_count,
            ]);
        } catch (Throwable $e) {
            error_log('Substack Sync Rollback Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Rollback error: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle get sync stats AJAX request.
     */
    public function handle_get_sync_stats(): void
    {
        ob_clean();

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);

            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'substack_sync_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);

            return;
        }

        try {
            require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
            $processor = new Substack_Sync_Processor();
            $logs = $processor->get_recent_sync_logs(50);

            wp_send_json_success([
                'logs' => $logs,
            ]);
        } catch (Throwable $e) {
            error_log('Substack Sync Stats Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Stats error: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle AJAX sitemap sync request (batch processing).
     */
    public function handle_sitemap_sync(): void
    {
        ob_clean();

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'substack_sync_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }

        $offset = intval($_POST['offset'] ?? 0);
        $batch_size = intval($_POST['batch_size'] ?? 1);

        try {
            require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
            $processor = new Substack_Sync_Processor();
            $result = $processor->run_sitemap_sync($batch_size, $offset);

            wp_send_json_success($result);
        } catch (Throwable $e) {
            error_log('Substack Sync Sitemap Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error([
                'message' => 'Sitemap sync error: ' . $e->getMessage(),
                'has_more' => false,
            ]);
        }
    }

    /**
     * Handle AJAX ZIP import request.
     */
    public function handle_zip_import(): void
    {
        ob_clean();

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'substack_sync_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }

        if (empty($_FILES['zip_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
            return;
        }

        $file = $_FILES['zip_file'];

        // Validate the file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Upload error: ' . $file['error']]);
            return;
        }

        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'zip') {
            wp_send_json_error(['message' => 'Please upload a ZIP file']);
            return;
        }

        try {
            require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';
            $processor = new Substack_Sync_Processor();
            $result = $processor->import_from_zip($file['tmp_name']);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Throwable $e) {
            error_log('Substack Sync ZIP Import Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Import error: ' . $e->getMessage()]);
        }
    }
}
