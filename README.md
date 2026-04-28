# Siege Analytics Sync for Substack

A WordPress plugin that automatically syncs your Substack newsletter content to your WordPress site, with reliable image handling, content preservation, and duplicate detection.

## Attribution

This is a fork of [Substack Sync](https://github.com/cspenn/substack-wp-sync) by **Christopher S. Penn** ([christopherspenn.com](https://www.christopherspenn.com/)), licensed under Apache-2.0. All original work is credited to Christopher, and this fork maintains the same license.

If this plugin helps you, Christopher asks that you consider supporting:
- [Greater Boston Food Bank](https://gbfb.org) - Fighting hunger in our communities
- [Baypath Humane Society of Hopkinton, Massachusetts](https://baypathhumane.org) - Caring for animals in need

## What's Changed from the Original

This fork fixes several critical bugs and brings the plugin up to WordPress.org plugin directory standards:

| # | Fix | Type |
|---|-----|------|
| 1 | Published posts no longer revert to draft on sync updates | Bug fix |
| 2 | Image URLs in post content rewritten to local WordPress copies | Bug fix |
| 3 | Image duplicate detection prevents re-downloading | Bug fix |
| 4 | RSS feed cached during batch sync (was fetching N times for N posts) | Performance |
| 5 | Server file paths no longer leaked in AJAX errors | Security |
| 6 | Settings sanitize callback for proper input validation | Security |
| 7 | JavaScript/CSS extracted to external files | WP.org compliance |
| 8 | Full internationalization (i18n) support | WP.org compliance |
| 9 | All output properly escaped | WP.org compliance |
| 10 | Global function names properly prefixed | WP.org compliance |
| 11 | WordPress.org standard readme.txt | WP.org compliance |

## Features

- **Automated Synchronization:** Hourly cron job fetches new content from Substack RSS feed
- **Full Content Preservation:** HTML formatting, links, and embedded content pass through intact
- **Reliable Image Handling:** Images downloaded to WP media library with URL rewriting
- **Duplicate Detection:** GUID-based post tracking and image URL tracking prevent duplicates
- **Full Archive Sync:** Sitemap-based scraping imports all posts, not just recent RSS items
- **ZIP Import:** Import from Substack's data export (Settings > Export in Substack)
- **Category Mapping:** Keyword-based automatic category assignment
- **Batch Processing:** Progressive sync with real-time progress tracking via AJAX
- **Error Recovery:** Automatic retry (up to 3 attempts) with detailed logging
- **Rollback:** Remove imported posts (all, failed only, or by date range)

## Installation

1. Upload the `siege-analytics-sync-for-substack` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Settings > Substack Sync** to configure your RSS feed URL

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher

## License

Apache License Version 2.0 - see [LICENSE](LICENSE) file for details.
