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
 * The core plugin class for processing Substack content.
 *
 * This class handles fetching RSS feeds, processing content, and importing posts.
 */
class Substack_Sync_Processor
{
    /**
     * Plugin settings.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        $this->settings = get_option('substack_sync_settings', []);
    }

    /**
     * Run the sync process.
     *
     * Main method that orchestrates the synchronization process.
     *
     * @param bool $return_status Whether to return detailed status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    public function run_sync(bool $return_status = false)
    {
        if (empty($this->settings['feed_url'])) {
            error_log('Substack Sync: No feed URL configured');

            if ($return_status) {
                return [
                    'success' => false,
                    'error' => 'No feed URL configured',
                    'total_posts' => 0,
                    'posts_processed' => 0,
                ];
            }

            return;
        }

        $feed = fetch_feed($this->settings['feed_url']);

        if (is_wp_error($feed)) {
            error_log('Substack Sync: Error fetching feed - ' . $feed->get_error_message());

            if ($return_status) {
                return [
                    'success' => false,
                    'error' => 'Error fetching feed: ' . $feed->get_error_message(),
                    'total_posts' => 0,
                    'posts_processed' => 0,
                ];
            }

            return;
        }

        $items = $feed->get_items();
        $total_posts = count($items);
        $posts_processed = 0;
        $posts_imported = 0;
        $posts_updated = 0;
        $posts_skipped = 0;
        $errors = [];

        if ($return_status && $total_posts === 0) {
            return [
                'success' => true,
                'total_posts' => 0,
                'posts_processed' => 0,
                'posts_imported' => 0,
                'posts_updated' => 0,
                'posts_skipped' => 0,
                'message' => 'No posts found in feed',
            ];
        }

        foreach ($items as $item) {
            try {
                $result = $this->process_feed_item($item, $return_status);
                $posts_processed++;

                if ($return_status && isset($result['action'])) {
                    switch ($result['action']) {
                        case 'imported':
                            $posts_imported++;

                            break;
                        case 'updated':
                            $posts_updated++;

                            break;
                        case 'skipped':
                            $posts_skipped++;

                            break;
                    }
                }
            } catch (Exception $e) {
                error_log('Substack Sync: Error processing post - ' . $e->getMessage());
                $errors[] = $e->getMessage();
                $posts_processed++;
            }
        }

        if ($return_status) {
            return [
                'success' => true,
                'total_posts' => $total_posts,
                'posts_processed' => $posts_processed,
                'posts_imported' => $posts_imported,
                'posts_updated' => $posts_updated,
                'posts_skipped' => $posts_skipped,
                'errors' => $errors,
                'message' => sprintf(
                    'Processed %d posts: %d imported, %d updated, %d skipped',
                    $posts_processed,
                    $posts_imported,
                    $posts_updated,
                    $posts_skipped
                ),
            ];
        }
    }

    /**
     * Process a single feed item.
     *
     * @param SimplePie_Item $item The feed item to process.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function process_feed_item($item, bool $return_status = false)
    {
        $guid = $item->get_id();
        $existing_post = $this->get_existing_post($guid);
        $post_title = $item->get_title();

        if ($existing_post) {
            $result = $this->update_post($item, $existing_post, $return_status);

            if ($return_status) {
                return [
                    'action' => $result['success'] ? 'updated' : ($result['message'] && strpos($result['message'], 'Skipped') !== false ? 'skipped' : 'error'),
                    'post_title' => $post_title,
                    'post_id' => $existing_post['post_id'],
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? "Updated: {$post_title}",
                ];
            }
        } else {
            $result = $this->import_post($item, $return_status);

            if ($return_status) {
                return [
                    'action' => $result['success'] ? 'imported' : ($result['message'] && strpos($result['message'], 'Skipped') !== false ? 'skipped' : 'error'),
                    'post_title' => $post_title,
                    'post_id' => $result['post_id'] ?? null,
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? "Imported: {$post_title}",
                ];
            }
        }
    }

    /**
     * Fetch the RSS feed, using a transient cache to avoid re-fetching during batch sync.
     *
     * @param bool $force_refresh Whether to bypass the cache.
     * @return \SimplePie|WP_Error The parsed feed or error.
     */
    private function get_cached_feed(bool $force_refresh = false)
    {
        $cache_key = 'substack_sync_feed_cache';

        if (! $force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $feed = fetch_feed($this->settings['feed_url']);

        if (! is_wp_error($feed)) {
            // Cache parsed feed items for 10 minutes (covers a full batch sync session)
            $items_data = [];
            foreach ($feed->get_items() as $item) {
                $items_data[] = [
                    'id' => $item->get_id(),
                    'title' => $item->get_title(),
                    'content' => $item->get_content(),
                    'date' => $item->get_date('Y-m-d H:i:s'),
                ];
            }
            set_transient($cache_key, $items_data, 10 * MINUTE_IN_SECONDS);

            return $items_data;
        }

        return $feed;
    }

    /**
     * Clear the feed cache (called when a batch sync completes).
     */
    public function clear_feed_cache(): void
    {
        delete_transient('substack_sync_feed_cache');
    }

    /**
     * Process individual posts with detailed progress tracking.
     *
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset Starting offset.
     * @return array<string, mixed> Detailed status information.
     */
    public function run_batch_sync(int $batch_size = 1, int $offset = 0): array
    {
        if (empty($this->settings['feed_url'])) {
            return [
                'success' => false,
                'error' => 'No feed URL configured',
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
            ];
        }

        // Use cached feed to avoid re-fetching on every batch request
        $force_refresh = ($offset === 0);
        $items = $this->get_cached_feed($force_refresh);

        if (is_wp_error($items)) {
            return [
                'success' => false,
                'error' => 'Error fetching feed: ' . $items->get_error_message(),
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
            ];
        }

        $total_posts = count($items);

        if ($total_posts === 0) {
            return [
                'success' => true,
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
                'message' => 'No posts found in feed',
            ];
        }

        $batch_items = array_slice($items, $offset, $batch_size);
        $posts_processed = 0;
        $processed_posts = [];
        $errors = [];

        foreach ($batch_items as $item) {
            try {
                $result = $this->process_cached_feed_item($item, true);
                $posts_processed++;
                $processed_posts[] = $result;
            } catch (Exception $e) {
                error_log('Substack Sync: Error processing post - ' . $e->getMessage());
                $errors[] = $e->getMessage();
                $posts_processed++;
                $processed_posts[] = [
                    'action' => 'error',
                    'post_title' => $item['title'] ?? 'Unknown',
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                ];
            }
        }

        $new_offset = $offset + $batch_size;
        $has_more = $new_offset < $total_posts;

        // Clear the feed cache when batch sync is complete
        if (! $has_more) {
            $this->clear_feed_cache();
        }

        return [
            'success' => true,
            'total_posts' => $total_posts,
            'posts_processed' => $posts_processed,
            'current_offset' => $offset,
            'next_offset' => $new_offset,
            'has_more' => $has_more,
            'progress_percentage' => round(($new_offset / $total_posts) * 100, 1),
            'processed_posts' => $processed_posts,
            'errors' => $errors,
        ];
    }

    /**
     * Process a single cached feed item (array format from transient cache).
     *
     * @param array<string, mixed> $item The cached feed item.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function process_cached_feed_item(array $item, bool $return_status = false)
    {
        $guid = $item['id'];
        $existing_post = $this->get_existing_post($guid);
        $post_title = $item['title'];

        $content = $this->process_content($item['content']);
        $full_text = $post_title . ' ' . $content;
        $categories = $this->apply_category_mapping($full_text);

        $post_data = [
            'post_title' => $post_title,
            'post_content' => $content,
            'post_status' => $this->settings['default_post_status'] ?? 'draft',
            'post_author' => $this->settings['default_author'] ?? 1,
            'post_date' => $item['date'],
            'post_type' => 'post',
        ];

        if (! empty($categories)) {
            $post_data['post_category'] = $categories;
        }

        if ($existing_post) {
            $post_data['ID'] = $existing_post['post_id'];
            // Preserve existing post status
            $current_post = get_post($existing_post['post_id']);
            if ($current_post) {
                $post_data['post_status'] = $current_post->post_status;
            }

            if ($this->should_skip_post($guid)) {
                if ($return_status) {
                    return ['action' => 'skipped', 'post_title' => $post_title, 'post_id' => $existing_post['post_id'], 'success' => false, 'message' => "Skipped: {$post_title} (max retries exceeded)"];
                }
                return;
            }

            $post_id = wp_update_post($post_data);
            $action = 'updated';
        } else {
            if ($this->should_skip_post($guid)) {
                if ($return_status) {
                    return ['action' => 'skipped', 'post_title' => $post_title, 'post_id' => null, 'success' => false, 'message' => "Skipped: {$post_title} (max retries exceeded)"];
                }
                return;
            }

            $post_id = wp_insert_post($post_data);
            $action = 'imported';
        }

        if ($post_id && ! is_wp_error($post_id)) {
            $this->log_sync($post_id, $guid, $action, $post_title);
            $this->process_post_images($post_id, $post_data['post_content']);

            if ($return_status) {
                return ['action' => $action, 'post_title' => $post_title, 'post_id' => $post_id, 'success' => true, 'message' => "Successfully {$action}: {$post_title}"];
            }
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error occurred';
            error_log("Substack Sync: Failed to {$action} post - {$error_message}");
            $this->log_sync($existing_post['post_id'] ?? 0, $guid, 'error', $post_title, $error_message);

            if ($return_status) {
                return ['action' => 'error', 'post_title' => $post_title, 'post_id' => $existing_post['post_id'] ?? null, 'success' => false, 'message' => "Failed to {$action}: {$post_title} - {$error_message}"];
            }
        }
    }

    /**
     * Check if a post with the given GUID already exists.
     *
     * @param string $guid The Substack post GUID.
     * @return array<string, mixed>|null The existing post data or null.
     */
    private function get_existing_post(string $guid): ?array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE substack_guid = %s", $guid),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Import a new post from Substack.
     *
     * @param SimplePie_Item $item The feed item to import.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function import_post($item, bool $return_status = false)
    {
        $post_data = $this->prepare_post_data($item);
        $post_title = $post_data['post_title'];
        $guid = $item->get_id();

        // Check if we should skip due to max retries
        if ($this->should_skip_post($guid)) {
            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => null,
                    'message' => "Skipped: {$post_title} (max retries exceeded)",
                ];
            }

            return;
        }

        $post_id = wp_insert_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->log_sync($post_id, $guid, 'imported', $post_title);
            $this->process_post_images($post_id, $post_data['post_content']);

            if ($return_status) {
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'message' => "Successfully imported: {$post_title}",
                ];
            }
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error occurred';
            error_log("Substack Sync: Failed to import post - {$error_message}");
            $this->log_sync(0, $guid, 'error', $post_title, $error_message);

            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => null,
                    'message' => "Failed to import: {$post_title} - {$error_message}",
                ];
            }
        }
    }

    /**
     * Update an existing post.
     *
     * @param SimplePie_Item $item The feed item.
     * @param array<string, mixed> $existing_post The existing post data.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function update_post($item, array $existing_post, bool $return_status = false)
    {
        $post_data = $this->prepare_post_data($item);
        $post_data['ID'] = $existing_post['post_id'];
        // Preserve the existing post status — never revert a published post to draft
        $current_post = get_post($existing_post['post_id']);
        if ($current_post) {
            $post_data['post_status'] = $current_post->post_status;
        }
        $post_title = $post_data['post_title'];
        $guid = $item->get_id();

        // Check if we should skip due to max retries
        if ($this->should_skip_post($guid)) {
            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => $existing_post['post_id'],
                    'message' => "Skipped: {$post_title} (max retries exceeded)",
                ];
            }

            return;
        }

        $post_id = wp_update_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->log_sync($post_id, $guid, 'updated', $post_title);
            $this->process_post_images($post_id, $post_data['post_content']);

            if ($return_status) {
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'message' => "Successfully updated: {$post_title}",
                ];
            }
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error occurred';
            error_log("Substack Sync: Failed to update post - {$error_message}");
            $this->log_sync($existing_post['post_id'], $guid, 'error', $post_title, $error_message);

            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => $existing_post['post_id'],
                    'message' => "Failed to update: {$post_title} - {$error_message}",
                ];
            }
        }
    }

    /**
     * Prepare post data for WordPress insertion.
     *
     * @param SimplePie_Item $item The feed item.
     * @return array<string, mixed> Post data array.
     */
    private function prepare_post_data($item): array
    {
        $content = $this->process_content($item->get_content());
        $title = $item->get_title();

        // Apply category mapping based on content and title
        $full_text = $title . ' ' . $content;
        $categories = $this->apply_category_mapping($full_text);

        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $this->settings['default_post_status'] ?? 'draft',
            'post_author' => $this->settings['default_author'] ?? 1,
            'post_date' => $item->get_date('Y-m-d H:i:s'),
            'post_type' => 'post',
        ];

        // Add categories if mapping found any
        if (! empty($categories)) {
            $post_data['post_category'] = $categories;
        }

        return $post_data;
    }

    /**
     * Process and clean content from Substack.
     *
     * @param string $content The raw content from Substack.
     * @return string The processed content.
     */
    private function process_content(string $content): string
    {
        // Replace Substack-specific elements with subscription links
        $subscription_link = sprintf(
            '<div class="substack-subscribe-block"><a href="%s" target="_blank">Subscribe to our newsletter</a></div>',
            esc_url($this->settings['feed_url'] ?? '')
        );

        // Remove or replace Substack interactive elements
        $content = preg_replace('/<div[^>]*class="[^"]*subscription[^"]*"[^>]*>.*?<\/div>/is', $subscription_link, $content);
        $content = preg_replace('/<div[^>]*class="[^"]*like-button[^"]*"[^>]*>.*?<\/div>/is', '', $content);

        return $content;
    }

    /**
     * Process and import images from post content.
     *
     * Downloads images to the WP media library, rewrites URLs in post content
     * to point to the local copies, and sets the first image as featured.
     * Detects duplicates by checking attachment meta for the original URL.
     *
     * @param int $post_id The WordPress post ID.
     * @param string $content The post content.
     */
    private function process_post_images(int $post_id, string $content): void
    {
        $doc = new DOMDocument();
        @$doc->loadHTML(
            mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $images = $doc->getElementsByTagName('img');
        $first_image_set = has_post_thumbnail($post_id);
        $url_replacements = [];

        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            if (empty($src) || ! filter_var($src, FILTER_VALIDATE_URL)) {
                continue;
            }

            // Check for existing attachment with this original URL (duplicate detection)
            $attachment_id = $this->find_existing_attachment($src);

            if (! $attachment_id) {
                // Download the image to the media library
                $attachment_id = media_sideload_image($src, $post_id, '', 'id');

                if (is_wp_error($attachment_id)) {
                    error_log('Substack Sync: Failed to sideload image ' . $src . ' - ' . $attachment_id->get_error_message());
                    continue;
                }

                // Store the original URL in attachment meta for future duplicate detection
                update_post_meta($attachment_id, '_substack_original_url', $src);
            }

            // Get the local URL and queue the replacement
            $local_url = wp_get_attachment_url($attachment_id);
            if ($local_url) {
                $url_replacements[$src] = $local_url;
            }

            // Set the first successfully imported image as featured
            if (! $first_image_set) {
                set_post_thumbnail($post_id, $attachment_id);
                $first_image_set = true;
            }
        }

        // Rewrite image URLs in post content to point to local copies
        if (! empty($url_replacements)) {
            $updated_content = get_post_field('post_content', $post_id);
            foreach ($url_replacements as $original_url => $local_url) {
                $updated_content = str_replace($original_url, $local_url, $updated_content);
            }
            wp_update_post([
                'ID' => $post_id,
                'post_content' => $updated_content,
            ]);
        }
    }

    /**
     * Find an existing media attachment by its original Substack URL.
     *
     * @param string $original_url The original external image URL.
     * @return int|false Attachment ID if found, false otherwise.
     */
    private function find_existing_attachment(string $original_url)
    {
        global $wpdb;

        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_substack_original_url' AND meta_value = %s LIMIT 1",
                $original_url
            )
        );

        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Log sync activity to the database.
     *
     * @param int $post_id The WordPress post ID.
     * @param string $substack_guid The Substack GUID.
     * @param string $status The sync status.
     * @param string $post_title The post title for reference.
     * @param string $error_message Optional error message.
     */
    private function log_sync(int $post_id, string $substack_guid, string $status, string $post_title = '', string $error_message = ''): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        // Get existing record to preserve retry count
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT retry_count FROM $table_name WHERE substack_guid = %s", $substack_guid)
        );

        $retry_count = 0;
        if ($existing && $status === 'error') {
            $retry_count = $existing->retry_count + 1;
        }

        $wpdb->replace(
            $table_name,
            [
                'post_id' => $post_id,
                'substack_guid' => $substack_guid,
                'substack_title' => $post_title,
                'sync_date' => current_time('mysql'),
                'last_modified' => current_time('mysql'),
                'status' => $status,
                'retry_count' => $retry_count,
                'error_message' => $error_message,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * Get sync statistics for resumable operations.
     *
     * @return array<string, mixed> Sync statistics.
     */
    public function get_sync_stats(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_synced,
                SUM(CASE WHEN status = 'imported' THEN 1 ELSE 0 END) as imported_count,
                SUM(CASE WHEN status = 'updated' THEN 1 ELSE 0 END) as updated_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                MAX(sync_date) as last_sync_date
            FROM $table_name
        ", ARRAY_A);

        return [
            'total_synced' => intval($stats['total_synced'] ?? 0),
            'imported_count' => intval($stats['imported_count'] ?? 0),
            'updated_count' => intval($stats['updated_count'] ?? 0),
            'error_count' => intval($stats['error_count'] ?? 0),
            'last_sync_date' => $stats['last_sync_date'] ?? null,
        ];
    }

    /**
     * Get posts that need retry due to errors.
     *
     * @param int $max_retries Maximum number of retries allowed.
     * @return array<array<string, mixed>> Posts that need retry.
     */
    public function get_posts_needing_retry(int $max_retries = 3): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT substack_guid, substack_title, retry_count, error_message 
                FROM $table_name 
                WHERE status = 'error' AND retry_count < %d 
                ORDER BY sync_date ASC
            ", $max_retries),
            ARRAY_A
        );
    }

    /**
     * Check if a post should be skipped due to max retries.
     *
     * @param string $guid The Substack GUID.
     * @param int $max_retries Maximum retries allowed.
     * @return bool True if post should be skipped.
     */
    private function should_skip_post(string $guid, int $max_retries = 3): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $retry_count = $wpdb->get_var(
            $wpdb->prepare("SELECT retry_count FROM $table_name WHERE substack_guid = %s AND status = 'error'", $guid)
        );

        return $retry_count !== null && intval($retry_count) >= $max_retries;
    }

    /**
     * Reset retry count for a specific post.
     *
     * @param string $guid The Substack GUID.
     * @return bool True if reset successfully.
     */
    public function reset_post_retry_count(string $guid): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        return $wpdb->update(
            $table_name,
            ['retry_count' => 0, 'status' => 'pending'],
            ['substack_guid' => $guid],
            ['%d', '%s'],
            ['%s']
        ) !== false;
    }

    /**
     * Get recent sync logs for display.
     *
     * @param int $limit Number of logs to retrieve.
     * @return array<array<string, mixed>> Recent sync logs.
     */
    public function get_recent_sync_logs(int $limit = 50): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT substack_guid, substack_title, sync_date, status, error_message 
                FROM $table_name 
                ORDER BY sync_date DESC 
                LIMIT %d
            ", $limit),
            ARRAY_A
        );
    }

    /**
     * Rollback all synced posts.
     *
     * @return int Number of posts deleted.
     */
    public function rollback_all_posts(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $post_ids = $wpdb->get_col("SELECT post_id FROM $table_name WHERE post_id > 0");
        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Clear the sync log
        $wpdb->query("DELETE FROM $table_name");

        return $deleted_count;
    }

    /**
     * Rollback only failed posts.
     *
     * @return int Number of posts deleted.
     */
    public function rollback_failed_posts(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $post_ids = $wpdb->get_col("SELECT post_id FROM $table_name WHERE status = 'error' AND post_id > 0");
        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Remove failed entries from log
        $wpdb->delete($table_name, ['status' => 'error'], ['%s']);

        return $deleted_count;
    }

    /**
     * Rollback posts by date range.
     *
     * @param string $date_from Start date.
     * @param string $date_to End date.
     * @return int Number of posts deleted.
     */
    public function rollback_posts_by_date(string $date_from, string $date_to): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $post_ids = $wpdb->get_col(
            $wpdb->prepare("
                SELECT post_id 
                FROM $table_name 
                WHERE post_id > 0 
                AND sync_date BETWEEN %s AND %s
            ", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        );

        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Remove entries from log
        $wpdb->query(
            $wpdb->prepare("
                DELETE FROM $table_name 
                WHERE sync_date BETWEEN %s AND %s
            ", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        );

        return $deleted_count;
    }

    /**
     * Fetch post URLs from the Substack sitemap.
     *
     * The RSS feed only returns recent posts. The sitemap contains ALL posts.
     *
     * @return array<string> List of post URLs from the sitemap.
     */
    public function get_sitemap_post_urls(): array
    {
        if (empty($this->settings['feed_url'])) {
            return [];
        }

        // Derive the sitemap URL from the feed URL
        $feed_url = $this->settings['feed_url'];
        $sitemap_url = preg_replace('#/feed$#', '/sitemap.xml', $feed_url);

        $response = wp_remote_get($sitemap_url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            error_log('Substack Sync: Failed to fetch sitemap - ' . $response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return [];
        }

        // Parse the sitemap XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            error_log('Substack Sync: Failed to parse sitemap XML');
            return [];
        }

        $post_urls = [];
        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? '';

        if ($ns) {
            $xml->registerXPathNamespace('sm', $ns);
            $urls = $xml->xpath('//sm:url/sm:loc');
        } else {
            $urls = $xml->xpath('//url/loc');
        }

        if ($urls) {
            foreach ($urls as $url) {
                $url_str = (string) $url;
                // Only include post URLs (contain /p/)
                if (strpos($url_str, '/p/') !== false) {
                    $post_urls[] = $url_str;
                }
            }
        }

        return $post_urls;
    }

    /**
     * Scrape a single Substack post page and extract content.
     *
     * @param string $url The post URL to scrape.
     * @return array<string, mixed>|null Extracted post data or null on failure.
     */
    private function scrape_substack_post(string $url): ?array
    {
        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            error_log('Substack Sync: Failed to fetch post ' . $url . ' - ' . $response->get_error_message());
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return null;
        }

        $doc = new DOMDocument();
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($doc);

        // Extract title from meta tag or h1
        $title = '';
        $title_meta = $xpath->query('//meta[@property="og:title"]');
        if ($title_meta->length > 0) {
            $title = $title_meta->item(0)->getAttribute('content');
        }
        if (empty($title)) {
            $h1 = $xpath->query('//h1');
            if ($h1->length > 0) {
                $title = $h1->item(0)->textContent;
            }
        }

        // Extract publish date
        $date = '';
        $date_meta = $xpath->query('//meta[@property="article:published_time"]');
        if ($date_meta->length > 0) {
            $date = $date_meta->item(0)->getAttribute('content');
        }
        // Convert ISO 8601 to MySQL datetime
        if (! empty($date)) {
            $timestamp = strtotime($date);
            $date = $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : '';
        }

        // Extract content from the post body
        $content = '';
        $body_nodes = $xpath->query('//div[contains(@class, "body")]//div[contains(@class, "available-content")]');
        if ($body_nodes->length > 0) {
            $content = $doc->saveHTML($body_nodes->item(0));
        }

        // Fallback: try the subtitle-based approach
        if (empty($content)) {
            $body_nodes = $xpath->query('//div[contains(@class, "post-content")]');
            if ($body_nodes->length > 0) {
                $content = $doc->saveHTML($body_nodes->item(0));
            }
        }

        if (empty($title) || empty($content)) {
            error_log('Substack Sync: Could not extract title or content from ' . $url);
            return null;
        }

        return [
            'id' => $url, // Use URL as GUID for sitemap-sourced posts
            'title' => trim($title),
            'content' => $content,
            'date' => $date ?: current_time('mysql'),
            'source_url' => $url,
        ];
    }

    /**
     * Run a full sync using the sitemap (all posts, not just RSS recent).
     *
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset Starting offset.
     * @return array<string, mixed> Detailed status information.
     */
    public function run_sitemap_sync(int $batch_size = 1, int $offset = 0): array
    {
        $cache_key = 'substack_sync_sitemap_urls';
        $force_refresh = ($offset === 0);

        if ($force_refresh) {
            $post_urls = $this->get_sitemap_post_urls();
            if (empty($post_urls)) {
                return [
                    'success' => false,
                    'error' => 'No posts found in sitemap',
                    'total_posts' => 0,
                    'posts_processed' => 0,
                    'has_more' => false,
                ];
            }
            set_transient($cache_key, $post_urls, 30 * MINUTE_IN_SECONDS);
        } else {
            $post_urls = get_transient($cache_key);
            if ($post_urls === false) {
                return [
                    'success' => false,
                    'error' => 'Sitemap cache expired. Please restart the sync.',
                    'total_posts' => 0,
                    'posts_processed' => 0,
                    'has_more' => false,
                ];
            }
        }

        $total_posts = count($post_urls);
        $batch_urls = array_slice($post_urls, $offset, $batch_size);
        $posts_processed = 0;
        $processed_posts = [];
        $errors = [];

        foreach ($batch_urls as $url) {
            try {
                // Check if already synced using the URL as GUID
                $existing = $this->get_existing_post($url);
                if ($existing) {
                    $posts_processed++;
                    $processed_posts[] = [
                        'action' => 'skipped',
                        'post_title' => $existing['substack_title'] ?? basename($url),
                        'post_id' => $existing['post_id'],
                        'success' => true,
                        'message' => 'Already synced: ' . ($existing['substack_title'] ?? basename($url)),
                    ];
                    continue;
                }

                // Scrape the post
                $post_data = $this->scrape_substack_post($url);
                if (! $post_data) {
                    $posts_processed++;
                    $processed_posts[] = [
                        'action' => 'error',
                        'post_title' => basename($url),
                        'success' => false,
                        'message' => 'Failed to scrape: ' . $url,
                    ];
                    continue;
                }

                // Import via the cached feed item processor
                $result = $this->process_cached_feed_item($post_data, true);
                $posts_processed++;
                $processed_posts[] = $result;
            } catch (Exception $e) {
                error_log('Substack Sync: Error processing sitemap post ' . $url . ' - ' . $e->getMessage());
                $errors[] = $e->getMessage();
                $posts_processed++;
                $processed_posts[] = [
                    'action' => 'error',
                    'post_title' => basename($url),
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                ];
            }
        }

        $new_offset = $offset + $batch_size;
        $has_more = $new_offset < $total_posts;

        if (! $has_more) {
            delete_transient($cache_key);
        }

        return [
            'success' => true,
            'total_posts' => $total_posts,
            'posts_processed' => $posts_processed,
            'current_offset' => $offset,
            'next_offset' => $new_offset,
            'has_more' => $has_more,
            'progress_percentage' => round(($new_offset / $total_posts) * 100, 1),
            'processed_posts' => $processed_posts,
            'errors' => $errors,
        ];
    }

    /**
     * Import posts from a Substack export ZIP file.
     *
     * The ZIP contains HTML files for each post and a posts.csv with metadata.
     *
     * @param string $zip_path Path to the uploaded ZIP file.
     * @return array<string, mixed> Import results.
     */
    public function import_from_zip(string $zip_path): array
    {
        if (! file_exists($zip_path)) {
            return ['success' => false, 'error' => 'ZIP file not found', 'imported' => 0];
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file', 'imported' => 0];
        }

        // Extract to a temp directory
        $temp_dir = get_temp_dir() . 'substack_import_' . uniqid();
        if (! wp_mkdir_p($temp_dir)) {
            $zip->close();
            return ['success' => false, 'error' => 'Failed to create temp directory', 'imported' => 0];
        }

        $zip->extractTo($temp_dir);
        $zip->close();

        // Parse posts.csv for metadata
        $csv_path = $temp_dir . '/posts.csv';
        $posts_meta = [];
        if (file_exists($csv_path)) {
            $handle = fopen($csv_path, 'r');
            if ($handle) {
                $headers = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) >= count($headers)) {
                        $post_meta = array_combine($headers, $row);
                        // Key by the slug or post_id for matching to HTML files
                        $slug = $post_meta['post_id'] ?? $post_meta['slug'] ?? '';
                        if (! empty($slug)) {
                            $posts_meta[$slug] = $post_meta;
                        }
                    }
                }
                fclose($handle);
            }
        }

        // Find all HTML files in the extracted directory
        $html_files = glob($temp_dir . '/*.html') ?: [];
        // Also check subdirectories
        $html_files = array_merge($html_files, glob($temp_dir . '/posts/*.html') ?: []);

        $imported = 0;
        $skipped = 0;
        $errors_list = [];

        foreach ($html_files as $html_file) {
            $filename = basename($html_file, '.html');
            $html_content = file_get_contents($html_file);

            if (empty($html_content)) {
                continue;
            }

            // Try to match metadata from CSV
            $meta = $posts_meta[$filename] ?? [];

            // Extract title from HTML if not in CSV
            $title = $meta['title'] ?? '';
            if (empty($title)) {
                // Try to extract from HTML <title> or <h1>
                if (preg_match('#<title>([^<]+)</title>#i', $html_content, $matches)) {
                    $title = trim($matches[1]);
                } elseif (preg_match('#<h1[^>]*>([^<]+)</h1>#i', $html_content, $matches)) {
                    $title = trim($matches[1]);
                } else {
                    $title = ucwords(str_replace('-', ' ', $filename));
                }
            }

            // Get date from CSV or use file modification time
            $date = '';
            if (! empty($meta['post_date'])) {
                $date = $meta['post_date'];
            } elseif (! empty($meta['published_at'])) {
                $date = $meta['published_at'];
            }
            if (! empty($date)) {
                $timestamp = strtotime($date);
                $date = $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : current_time('mysql');
            } else {
                $date = current_time('mysql');
            }

            // Use a consistent GUID for dedup
            $guid = 'substack-import-' . sanitize_title($filename);

            // Check if already imported
            if ($this->get_existing_post($guid)) {
                $skipped++;
                continue;
            }

            // Clean the HTML — extract just the body content if it's a full HTML document
            if (stripos($html_content, '<body') !== false) {
                if (preg_match('#<body[^>]*>(.*)</body>#is', $html_content, $body_match)) {
                    $html_content = $body_match[1];
                }
            }

            $content = $this->process_content($html_content);
            $full_text = $title . ' ' . $content;
            $categories = $this->apply_category_mapping($full_text);

            $post_data = [
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => $this->settings['default_post_status'] ?? 'draft',
                'post_author' => $this->settings['default_author'] ?? 1,
                'post_date' => $date,
                'post_type' => 'post',
            ];

            if (! empty($categories)) {
                $post_data['post_category'] = $categories;
            }

            $post_id = wp_insert_post($post_data);

            if ($post_id && ! is_wp_error($post_id)) {
                $this->log_sync($post_id, $guid, 'imported', $title);
                $this->process_post_images($post_id, $content);
                $imported++;
            } else {
                $error_msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
                $this->log_sync(0, $guid, 'error', $title, $error_msg);
                $errors_list[] = "Failed to import '{$title}': {$error_msg}";
            }
        }

        // Clean up temp directory
        $this->recursive_rmdir($temp_dir);

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors_list,
            'total_files' => count($html_files),
            'message' => sprintf(
                'Imported %d posts, skipped %d (already imported), %d errors from %d HTML files',
                $imported,
                $skipped,
                count($errors_list),
                count($html_files)
            ),
        ];
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Directory path.
     */
    private function recursive_rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursive_rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Apply category mapping based on keywords in post content.
     *
     * @param string $content The post content to analyze.
     * @return array<int> Array of category IDs.
     */
    private function apply_category_mapping(string $content): array
    {
        $category_mappings = $this->settings['category_mapping'] ?? [];
        $assigned_categories = [];

        if (empty($category_mappings)) {
            return $assigned_categories;
        }

        foreach ($category_mappings as $mapping) {
            if (empty($mapping['keyword']) || empty($mapping['category'])) {
                continue;
            }

            $keyword = strtolower(trim($mapping['keyword']));
            $content_lower = strtolower($content);

            // Check if keyword exists in content
            if (strpos($content_lower, $keyword) !== false) {
                $category_id = intval($mapping['category']);
                if ($category_id > 0 && ! in_array($category_id, $assigned_categories)) {
                    $assigned_categories[] = $category_id;
                }
            }
        }

        return $assigned_categories;
    }
}
