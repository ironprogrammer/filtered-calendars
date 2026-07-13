<?php
/**
 * Persistence for calendar configurations and diagnostic stats.
 *
 * Calendar configs live in a single option exposed through the core
 * /wp/v2/settings REST endpoint (the standard WordPress way), so the admin app
 * reads and writes them with @wordpress/core-data. Stats are a separate,
 * server-managed option.
 */

namespace FilteredCalendars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Store {

	const OPTION_FEEDS = 'filtered_calendars_configs';
	const OPTION_STATS = 'filtered_calendars_stats';
	const OPTION_BASE  = 'filtered_calendars_base';
	const OPTION_FLUSH = 'filtered_calendars_flush_needed';
	const DEFAULT_BASE = 'filtered-calendars';

	// How often (in minutes) to re-fetch a source calendar. Calendars change
	// infrequently, so the default is daily; the editor offers these choices.
	const DEFAULT_REFRESH = 1440;
	const ALLOWED_REFRESH = array( 15, 60, 360, 1440, 10080 );

	/**
	 * Register options with the REST-enabled settings API.
	 */
	public static function register() {
		register_setting(
			'filtered_calendars',
			self::OPTION_BASE,
			array(
				'type'              => 'string',
				'description'       => __( 'URL path segment that filtered calendars live under.', 'filtered-calendars' ),
				'default'           => self::DEFAULT_BASE,
				'sanitize_callback' => array( __CLASS__, 'sanitize_base' ),
				'show_in_rest'      => true,
			)
		);

		// When the path segment changes, defer a rewrite flush to the next init,
		// once the rule has been re-registered with the new value.
		add_action(
			'update_option_' . self::OPTION_BASE,
			static function () {
				update_option( self::OPTION_FLUSH, 1, false );
			}
		);

		register_setting(
			'filtered_calendars',
			self::OPTION_FEEDS,
			array(
				'type'              => 'array',
				'description'       => __( 'Configured filtered calendars.', 'filtered-calendars' ),
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_feeds' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'       => array( 'type' => 'string' ),
								'name'     => array( 'type' => 'string' ),
								'url'      => array(
									'type'   => 'string',
									'format' => 'uri',
								),
								'keywords' => array( 'type' => 'string' ),
								'refresh'  => array( 'type' => 'integer' ),
							),
							'additionalProperties' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Normalize the calendars array on save: assign slugs, trim, dedupe ids.
	 *
	 * @param mixed $feeds Raw value from the REST/settings save.
	 * @return array
	 */
	public static function sanitize_feeds( $feeds ) {
		if ( ! is_array( $feeds ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $feeds as $feed ) {
			if ( ! is_array( $feed ) ) {
				continue;
			}

			$name = isset( $feed['name'] ) ? sanitize_text_field( $feed['name'] ) : '';
			$url  = isset( $feed['url'] ) ? self::normalize_url( $feed['url'] ) : '';

			// A calendar needs at least a source URL to be useful.
			if ( '' === $url ) {
				continue;
			}

			if ( '' === $name ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				$name = $host ? $host : __( 'Untitled calendar', 'filtered-calendars' );
			}

			// Derive a stable slug from the id (if editing) or the name.
			$base = isset( $feed['id'] ) && '' !== $feed['id'] ? $feed['id'] : $name;
			$slug = sanitize_title( $base );
			if ( '' === $slug ) {
				$slug = 'calendar';
			}

			// Guarantee uniqueness.
			$candidate = $slug;
			$suffix    = 2;
			while ( isset( $seen[ $candidate ] ) ) {
				$candidate = $slug . '-' . $suffix;
				++$suffix;
			}
			$seen[ $candidate ] = true;

			$keywords = isset( $feed['keywords'] ) ? (string) $feed['keywords'] : '';
			// Keep newlines; strip tags and control junk without collapsing lines.
			$keywords = sanitize_textarea_field( $keywords );

			$refresh = isset( $feed['refresh'] ) ? (int) $feed['refresh'] : self::DEFAULT_REFRESH;
			$refresh = self::sanitize_refresh( $refresh );

			$clean[] = array(
				'id'       => $candidate,
				'name'     => $name,
				'url'      => $url,
				'keywords' => $keywords,
				'refresh'  => $refresh,
			);
		}

		return $clean;
	}

	/**
	 * Normalize a source URL: accept webcal:// (what many calendars advertise)
	 * by mapping it to https://, then run it through esc_url_raw.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( 0 === stripos( $url, 'webcal://' ) ) {
			$url = 'https://' . substr( $url, strlen( 'webcal://' ) );
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Clamp a refresh interval (minutes) to one of the allowed choices.
	 *
	 * @param int $minutes Raw value.
	 * @return int
	 */
	public static function sanitize_refresh( $minutes ) {
		$minutes = (int) $minutes;
		return in_array( $minutes, self::ALLOWED_REFRESH, true ) ? $minutes : self::DEFAULT_REFRESH;
	}

	/**
	 * The refresh interval (minutes) for a feed config, with a sane default.
	 *
	 * @param array $feed Feed config.
	 * @return int
	 */
	public static function get_refresh( array $feed ) {
		$minutes = isset( $feed['refresh'] ) ? (int) $feed['refresh'] : self::DEFAULT_REFRESH;
		return self::sanitize_refresh( $minutes );
	}

	/**
	 * Normalize the URL path segment, falling back to the default when empty.
	 *
	 * @param mixed $base Raw value.
	 * @return string
	 */
	public static function sanitize_base( $base ) {
		$slug = sanitize_title( (string) $base );
		return '' !== $slug ? $slug : self::DEFAULT_BASE;
	}

	/**
	 * The current URL path segment (e.g. "filtered-calendars").
	 *
	 * @return string
	 */
	public static function get_base() {
		$base = get_option( self::OPTION_BASE, self::DEFAULT_BASE );
		$base = sanitize_title( (string) $base );
		return '' !== $base ? $base : self::DEFAULT_BASE;
	}

	/**
	 * All configured calendars.
	 *
	 * @return array
	 */
	public static function get_feeds() {
		$feeds = get_option( self::OPTION_FEEDS, array() );
		return is_array( $feeds ) ? $feeds : array();
	}

	/**
	 * Look up one calendar by slug.
	 *
	 * @param string $slug Calendar slug.
	 * @return array|null
	 */
	public static function get_feed( $slug ) {
		foreach ( self::get_feeds() as $feed ) {
			if ( isset( $feed['id'] ) && $feed['id'] === $slug ) {
				return $feed;
			}
		}
		return null;
	}

	/**
	 * Read all stats.
	 *
	 * @return array
	 */
	public static function get_stats() {
		$stats = get_option( self::OPTION_STATS, array() );
		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Record the outcome of the most recent fetch/filter pass for a calendar.
	 *
	 * This is a snapshot of the last refresh, not a running tally: it's only
	 * written when the upstream is actually re-fetched (a cache miss), so it
	 * reflects "what this calendar looks like now", not how many times it has
	 * been served.
	 *
	 * @param string $slug     Calendar slug.
	 * @param array  $outcome  { kept:int, dropped:int, dropped_titles:string[], status:string }
	 */
	public static function record_stats( $slug, array $outcome ) {
		$all = self::get_stats();

		$dropped_titles = isset( $outcome['dropped_titles'] ) ? array_values( $outcome['dropped_titles'] ) : array();

		$all[ $slug ] = array(
			'last_fetch'     => time(),
			'last_kept'      => isset( $outcome['kept'] ) ? (int) $outcome['kept'] : 0,
			'last_dropped'   => isset( $outcome['dropped'] ) ? (int) $outcome['dropped'] : 0,
			'dropped_titles' => array_slice( $dropped_titles, 0, 10 ),
			'status'         => isset( $outcome['status'] ) ? $outcome['status'] : 'ok',
		);

		update_option( self::OPTION_STATS, $all, false );
	}
}
