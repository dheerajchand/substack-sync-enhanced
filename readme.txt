=== Substack Sync Enhanced ===
Contributors: dheerajchand
Tags: substack, rss, sync, import, newsletter
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.3.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Automatically sync your Substack newsletter to WordPress with reliable image handling, link preservation, and duplicate detection.

== Description ==

Substack Sync Enhanced automatically imports your Substack newsletter posts into your self-hosted WordPress site, giving you true ownership and a permanent archive of your content.

This is an enhanced fork of [Substack Sync](https://github.com/cspenn/substack-wp-sync) by Christopher S. Penn, with critical bug fixes, security improvements, and WordPress.org compliance.

= Key Features =

* **Automated Synchronization** - Hourly cron job fetches new content from your Substack RSS feed
* **Full Content Preservation** - HTML formatting, links, and embedded content are preserved
* **Reliable Image Handling** - Images are downloaded to your WordPress media library with URL rewriting in post content, so images are served from your site
* **Duplicate Detection** - Images are tracked by original URL to prevent re-downloading on updates
* **GUID-Based Post Tracking** - Prevents duplicate posts and intelligently updates existing content
* **Status Preservation** - Published posts stay published when updated (no draft regression)
* **Category Mapping** - Keyword-based automatic category assignment
* **Batch Processing** - Progressive sync with real-time progress tracking
* **Error Recovery** - Automatic retry system (up to 3 attempts) with detailed logging
* **Rollback** - Remove imported posts (all, failed only, or by date range)
* **Feed Caching** - RSS feed is fetched once per sync session, not per-post

= What Was Fixed =

This fork addresses the following issues from the original plugin:

1. **Draft regression bug** - The original plugin would revert published posts to draft on every sync cycle
2. **Image URL rewriting** - Images are now served from your WordPress media library, not hotlinked from Substack's CDN
3. **Image duplicate detection** - Re-syncing no longer creates duplicate images in your media library
4. **RSS feed caching** - Batch sync now fetches the feed once instead of once per post
5. **Debug info leakage** - Server file paths are no longer exposed in AJAX error responses
6. **Settings validation** - All settings are properly sanitized before saving
7. **WordPress coding standards** - Properly enqueued scripts/styles, i18n support, output escaping

= Credits =

This plugin is a fork of [Substack Sync](https://github.com/cspenn/substack-wp-sync) by **Christopher S. Penn** ([christopherspenn.com](https://www.christopherspenn.com/)). The original plugin is licensed under Apache-2.0, and this fork maintains that license.

If this plugin helps you, Christopher asks that you consider supporting:

* [Greater Boston Food Bank](https://gbfb.org) - Fighting hunger in our communities
* [Baypath Humane Society of Hopkinton, Massachusetts](https://baypathhumane.org) - Caring for animals in need

== Installation ==

1. Upload the `substack-sync-enhanced` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Substack Sync to configure your RSS feed URL
4. Enter your Substack feed URL (e.g., `https://yourname.substack.com/feed`)
5. Choose your default author, post status, and category mappings
6. Click "Save Settings" then use "Sync Now" for the initial import

= WP-Cron Note =

This plugin uses WP-Cron for hourly synchronization. On low-traffic sites or some shared hosting, WP-Cron may not fire reliably. If you notice syncs not running, set up a real server-side cron job:

`*/15 * * * * curl -s https://yoursite.com/wp-cron.php > /dev/null 2>&1`

== Frequently Asked Questions ==

= Does this sync comments? =

No. Substack does not provide a public API for comments, so comment synchronization is not possible in either direction.

= Can I push posts from WordPress to Substack? =

No. Substack does not have a write API, so syncing is one-way only (Substack to WordPress).

= Will this sync subscriber-only posts? =

No. The Substack RSS feed only includes free/public posts. Subscriber-only content is not available via RSS.

= What happens to my images? =

Images are downloaded from Substack and stored in your WordPress media library. The image URLs in your post content are rewritten to point to your local copies. This means your images will still work even if Substack changes their CDN or if you delete your Substack.

= Will updating a post on Substack update it on WordPress? =

Yes. The plugin checks for existing posts by GUID and updates their content. The post status is preserved - if you published the post on WordPress, it stays published.

= Is this compatible with the original Substack Sync plugin? =

They use the same database table and settings, so you should deactivate the original before activating this one. Your existing sync data will be preserved.

== Changelog ==

= 1.1.0 =
* **Fork from Substack Sync 1.0.2 by Christopher S. Penn**
* Fixed: Published posts no longer revert to draft on sync updates
* Fixed: Image URLs in post content are rewritten to local WordPress copies
* Added: Image duplicate detection via attachment meta tracking
* Fixed: Batch sync now caches the RSS feed instead of re-fetching per post
* Fixed: Debug file paths no longer leaked in AJAX error responses
* Added: Settings sanitize callback for proper input validation
* Changed: JavaScript and CSS extracted to external files (wp_enqueue_script/style)
* Added: Full internationalization (i18n) support with text domain
* Fixed: All output properly escaped per WordPress coding standards
* Changed: Global function names prefixed to avoid conflicts
* Added: WordPress.org standard readme.txt

== Upgrade Notice ==

= 1.1.0 =
Critical bug fixes: posts no longer revert to draft on sync, images are properly hosted locally, and batch sync performance is significantly improved.
