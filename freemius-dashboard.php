<?php
/**
 * Plugin Name:       Freemius Dashboard
 * Plugin URI:        https://github.com/soulsites/freemius-to-wordpress
 * Description:       Verbindet WordPress mit der Freemius API und zeigt Käufe, Kundendaten und Umsatz in einem minimalistischen Dashboard (Material M3) an.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Freemius Dashboard
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       freemius-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FSD_VERSION', '1.2.0' );
define( 'FSD_PLUGIN_FILE', __FILE__ );
define( 'FSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FSD_OPTION_KEY', 'fsd_settings' );
define( 'FSD_EMAIL_OPTION_KEY', 'fsd_email_settings' );

require_once FSD_PLUGIN_DIR . 'includes/class-fsd-api.php';
require_once FSD_PLUGIN_DIR . 'includes/class-fsd-settings.php';
require_once FSD_PLUGIN_DIR . 'includes/class-fsd-email-settings.php';
require_once FSD_PLUGIN_DIR . 'includes/class-fsd-webhook.php';
require_once FSD_PLUGIN_DIR . 'includes/class-fsd-dashboard.php';
require_once FSD_PLUGIN_DIR . 'includes/class-fsd-plugin.php';

FSD_Plugin::instance();
