<?php

declare(strict_types=1);

/**
 * Plugin Name:       Substack Sync Enhanced
 * Plugin URI:        https://github.com/dheerajchand/substack-sync-enhanced
 * Description:       Syncs a Substack RSS feed to your WordPress site with reliable image handling, content preservation, and automated scheduling. Enhanced fork of Substack Sync by Christopher S. Penn.
 * Version:           1.3.0
 * Author:            Dheeraj Chand (forked from Christopher S. Penn)
 * Author URI:        https://dheerajchand.com/
 * Original Author:   Christopher S. Penn (https://www.christopherspenn.com/)
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       substack-sync-enhanced
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      6.9
 * Requires PHP:      8.0
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
    die;
}

// Define Plugin Constants
define('SUBSTACK_SYNC_VERSION', '1.3.0');
define('SUBSTACK_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUBSTACK_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load plugin text domain for translations.
 */
function substack_sync_enhanced_load_textdomain(): void
{
    load_plugin_textdomain('substack-sync-enhanced', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'substack_sync_enhanced_load_textdomain');

/**
 * The code that runs during plugin activation.
 */
function substack_sync_enhanced_activate(): void
{
    require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-activator.php';
    Substack_Sync_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function substack_sync_enhanced_deactivate(): void
{
    require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-deactivator.php';
    Substack_Sync_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'substack_sync_enhanced_activate');
register_deactivation_hook(__FILE__, 'substack_sync_enhanced_deactivate');

// Include All Other Files
require_once SUBSTACK_SYNC_PLUGIN_DIR . 'admin/class-substack-sync-admin.php';
require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-cron.php';
require_once SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-substack-sync-processor.php';

// Initialize the classes
new Substack_Sync_Admin();
new Substack_Sync_Cron();
