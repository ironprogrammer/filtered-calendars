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

			$clean[] = array(
				'id'       => $candidate,
				'name'     => $name,
				'url'      => $url,
				'keywords' => $keywords,
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
	 * Record the outcome of a fetch/filter pass for a calendar.
	 *
	 * @param string $slug     Calendar slug.
	 * @param array  $outcome  { kept:int, dropped:int, dropped_titles:string[], status:string }
	 */
	public static function record_stats( $slug, array $outcome ) {
		$all = self::get_stats();

		$prev  = isset( $all[ $slug ] ) ? $all[ $slug ] : array();
		$total = isset( $prev['total_dropped'] ) ? (int) $prev['total_dropped'] : 0;

		$dropped_titles = isset( $outcome['dropped_titles'] ) ? array_values( $outcome['dropped_titles'] ) : array();

		$all[ $slug ] = array(
			'last_fetch'     => time(),
			'last_kept'      => isset( $outcome['kept'] ) ? (int) $outcome['kept'] : 0,
			'last_dropped'   => isset( $outcome['dropped'] ) ? (int) $outcome['dropped'] : 0,
			'total_dropped'  => $total + ( isset( $outcome['dropped'] ) ? (int) $outcome['dropped'] : 0 ),
			'recent_dropped' => array_slice( $dropped_titles, 0, 10 ),
			'status'         => isset( $outcome['status'] ) ? $outcome['status'] : 'ok',
		);

		update_option( self::OPTION_STATS, $all, false );
	}
}
