<?php
/**
 * REST endpoints under filtered-calendars/v1.
 *
 * - GET  /stats            Diagnostic counts per calendar (read-only).
 * - POST /preview          Dry-run a URL + keywords: which events are kept vs dropped.
 *
 * Calendar CRUD itself goes through the core /wp/v2/settings endpoint (see Store).
 */

namespace FilteredCalendars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Controller {

	const NAMESPACE = 'filtered-calendars/v1';

	/**
	 * Register routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Define the routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( Store::class, 'normalize_url' ),
					),
					'keywords' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Only administrators (users who can manage options) may read stats or preview.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return per-calendar stats keyed by slug.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_stats() {
		return rest_ensure_response( Store::get_stats() );
	}

	/**
	 * Dry-run filtering against a live upstream fetch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$url      = $request->get_param( 'url' );
		$keywords = $request->get_param( 'keywords' );

		if ( '' === $url ) {
			return new \WP_Error(
				'fc_bad_url',
				__( 'Enter a calendar (.ics) URL.', 'filtered-calendars' ),
				array( 'status' => 400 )
			);
		}

		$response = $this->http_get( $url );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'fc_fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$body = wp_remote_retrieve_body( $response );

		$patterns = Filter::compile( $keywords );

		$total   = Filter::apply( $body, array() );        // no rules => everything kept, gives total & titles
		$applied = Filter::apply( $body, $patterns );

		if ( null === $total || null === $applied ) {
			return new \WP_Error(
				'fc_parse_failed',
				__( 'That URL did not return an iCalendar (.ics) feed.', 'filtered-calendars' ),
				array( 'status' => 422 )
			);
		}

		return rest_ensure_response(
			array(
				'title'          => Filter::calendar_name( $body ),
				'resolved_url'   => $url,
				'total'          => $total['kept'],
				'kept'           => $applied['kept'],
				'dropped'        => $applied['dropped'],
				'dropped_titles' => $applied['dropped_titles'],
			)
		);
	}

	/**
	 * Shared HTTP GET with the plugin's timeout and user agent.
	 *
	 * @param string $url URL to fetch.
	 * @return array|\WP_Error
	 */
	private function http_get( $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'FilteredCalendars/' . FILTERED_CALENDARS_VERSION . '; ' . home_url( '/' ),
			)
		);
	}
}
