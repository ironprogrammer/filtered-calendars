<?php
/**
 * Admin screen registration and asset loading.
 *
 * The UI is a React app (DataViews) mounted under Settings → Filtered Calendars.
 */

namespace FilteredCalendars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const PAGE_SLUG = 'filtered-calendars';

	/**
	 * Hook up the menu and assets.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( FILTERED_CALENDARS_FILE ),
			array( $this, 'action_links' )
		);
	}

	/**
	 * Add a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'filtered-calendars' )
		);
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Add the settings submenu page.
	 */
	public function add_menu() {
		add_options_page(
			__( 'Filtered Calendars', 'filtered-calendars' ),
			__( 'Filtered Calendars', 'filtered-calendars' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The mount point for the React app.
	 */
	public function render() {
		echo '<div class="wrap"><div id="filtered-calendars-root"></div></div>';
	}

	/**
	 * Load the built assets only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_file = FILTERED_CALENDARS_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'filtered-calendars',
			FILTERED_CALENDARS_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// DataViews isn't registered as a standalone core style handle (yet), so
		// its stylesheet is imported into our entry and compiled into
		// build/style-index.css by wp-scripts. That keeps a single stylesheet
		// under one handle instead of maintaining a hand-copied duplicate.
		wp_enqueue_style(
			'filtered-calendars',
			FILTERED_CALENDARS_URL . 'build/style-index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		// Expose the site root (for building subscribe links) and the default path
		// segment, so the app can preview URLs as the user edits the path setting.
		wp_add_inline_script(
			'filtered-calendars',
			'window.filteredCalendarsData = ' . wp_json_encode(
				array(
					'homeUrl'     => home_url( '/' ),
					'defaultBase' => Store::DEFAULT_BASE,
				)
			) . ';',
			'before'
		);

		wp_set_script_translations( 'filtered-calendars', 'filtered-calendars' );
	}
}
