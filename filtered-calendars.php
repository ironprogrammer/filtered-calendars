<?php
/**
 * Plugin Name:       Filtered Calendars
 * Plugin URI:        https://github.com/ironprogrammer/filtered-calendars
 * Description:        Re-serve external iCalendar (.ics) feeds with unwanted events removed by keyword, while preserving the origin calendar's identity (name, timezones, event details).
 * Version:           1.0.3
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Brian Alexander
 * Author URI:        https://github.com/ironprogrammer
 * License:           GPL-2.0-or-later
 * Text Domain:       filtered-calendars
 */

namespace FilteredCalendars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FILTERED_CALENDARS_VERSION', '1.0.3' );
define( 'FILTERED_CALENDARS_FILE', __FILE__ );
define( 'FILTERED_CALENDARS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FILTERED_CALENDARS_URL', plugin_dir_url( __FILE__ ) );

require_once FILTERED_CALENDARS_DIR . 'includes/class-store.php';
require_once FILTERED_CALENDARS_DIR . 'includes/class-filter.php';
require_once FILTERED_CALENDARS_DIR . 'includes/class-server.php';
require_once FILTERED_CALENDARS_DIR . 'includes/class-rest-controller.php';
require_once FILTERED_CALENDARS_DIR . 'includes/class-admin.php';

/**
 * Boot the plugin once all classes are loaded.
 */
function bootstrap() {
	add_action( 'init', array( Store::class, 'register' ) );
	( new Server() )->register();
	( new Rest_Controller() )->register();

	if ( is_admin() ) {
		( new Admin() )->register();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * On activation, register the rewrite rule then flush so the pretty feed URL works immediately.
 */
function activate() {
	Server::add_rewrite_rule();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * On deactivation, drop the rewrite rule.
 */
function deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
