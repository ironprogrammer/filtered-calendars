<?php
/**
 * Public calendar endpoint: /filtered-calendars/{slug}/
 *
 * Fetches the upstream .ics feed (cached), removes matching VEVENTs, and streams
 * the result with the correct content type and HTTP caching. Upstream fetches use
 * conditional GET; a last-known-good copy backstops upstream errors.
 */

namespace FilteredCalendars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Server {

	const QUERY_VAR       = 'filtered_calendar';
	const QUERY_VAR_INDEX = 'filtered_calendar_index';
	const CACHE_FRESH     = 900;    // 15 minutes of "fresh" upstream cache.
	const CACHE_GOOD      = 86400;  // 24 hours of stale-if-error fallback.

	// Bump when the rewrite rules below change, so existing installs re-flush
	// on the next request without needing a manual deactivate/reactivate.
	const REWRITE_VERSION = 1;
	const OPTION_RW_VER   = 'filtered_calendars_rw_version';

	/**
	 * Hook up rewrite, query var, and request handling.
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_action( 'init', array( $this, 'maybe_flush' ), 20 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
	}

	/**
	 * Register the pretty permalink for filtered calendars, under the configured path.
	 */
	public static function add_rewrite_rule() {
		$base    = Store::get_base();
		$escaped = preg_quote( $base, '/' );

		// A single calendar: /{base}/{slug}/  (also allow a trailing .ics so the
		// URL reads like a calendar file to readers that sniff the extension).
		add_rewrite_rule(
			'^' . $escaped . '/([^/]+?)(?:\.ics)?/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		// The index of configured calendars: /{base}/
		add_rewrite_rule(
			'^' . $escaped . '/?$',
			'index.php?' . self::QUERY_VAR_INDEX . '=1',
			'top'
		);
	}

	/**
	 * Flush rewrite rules once after the path segment changes.
	 *
	 * Runs after add_rewrite_rule() has registered the rule with the new value,
	 * so the regenerated rules reflect the updated path.
	 */
	public function maybe_flush() {
		$needs_flush = (bool) get_option( Store::OPTION_FLUSH );

		// Also flush once when the rewrite rules themselves have changed shape.
		if ( (int) get_option( self::OPTION_RW_VER ) !== self::REWRITE_VERSION ) {
			$needs_flush = true;
			update_option( self::OPTION_RW_VER, self::REWRITE_VERSION, false );
		}

		if ( $needs_flush ) {
			flush_rewrite_rules();
			delete_option( Store::OPTION_FLUSH );
		}
	}

	/**
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_INDEX;
		return $vars;
	}

	/**
	 * If this request targets a filtered calendar, serve it and stop.
	 */
	public function maybe_serve() {
		if ( get_query_var( self::QUERY_VAR_INDEX ) ) {
			$this->serve_index();
			exit;
		}

		$slug = get_query_var( self::QUERY_VAR );
		if ( '' === $slug || null === $slug ) {
			return;
		}

		$feed = Store::get_feed( $slug );
		if ( ! $feed ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Calendar not found.';
			exit;
		}

		$this->serve( $feed );
		exit;
	}

	/**
	 * Render a simple human-readable index of configured calendars at the base path.
	 *
	 * This is a discovery/reminder aid: visiting /{base}/ lists each calendar's
	 * name, source, and subscribe URL. It is intentionally lightweight (no theme)
	 * and not indexed by search engines.
	 */
	private function serve_index() {
		$feeds = Store::get_feeds();
		$base  = trailingslashit( home_url( Store::get_base() ) );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		$title = __( 'Filtered Calendars', 'filtered-calendars' );

		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;color:#1e1e1e}h1{font-size:1.5rem}ul{list-style:none;padding:0}li{padding:16px 0;border-top:1px solid #e0e0e0}.name{font-weight:600}.src{color:#646970;font-size:.85rem;word-break:break-all}a{color:#2271b1}.url{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.url a{word-break:break-all}.copy{font:inherit;font-size:.8rem;line-height:1.4;padding:2px 8px;border:1px solid #c3c4c7;border-radius:3px;background:#f6f7f7;color:#2271b1;cursor:pointer}.copy:hover{background:#f0f0f1}.copy.copied{color:#008a20;border-color:#008a20}.sub{font-size:.85rem}.empty{color:#646970}</style>';
		echo '</head><body>';
		echo '<h1>' . esc_html( $title ) . '</h1>';

		if ( empty( $feeds ) ) {
			echo '<p class="empty">' . esc_html__( 'No filtered calendars are configured yet.', 'filtered-calendars' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $feeds as $feed ) {
				if ( empty( $feed['id'] ) ) {
					continue;
				}
				$subscribe = $base . rawurlencode( $feed['id'] ) . '/';
				$webcal    = preg_replace( '#^https?://#i', 'webcal://', $subscribe );
				$name      = isset( $feed['name'] ) && '' !== $feed['name'] ? $feed['name'] : $feed['id'];

				echo '<li>';
				echo '<div class="name">' . esc_html( $name ) . '</div>';
				if ( ! empty( $feed['url'] ) ) {
					echo '<div class="src">' . esc_html( $feed['url'] ) . '</div>';
				}
				echo '<div class="url"><a href="' . esc_url( $subscribe ) . '">' . esc_html( $subscribe ) . '</a>';
				echo '<button type="button" class="copy" data-url="' . esc_attr( $subscribe ) . '">' . esc_html__( 'Copy', 'filtered-calendars' ) . '</button>';
				echo '<a class="sub" href="' . esc_url( $webcal, array( 'webcal' ) ) . '">' . esc_html__( 'Subscribe', 'filtered-calendars' ) . '</a>';
				echo '</div>';
				echo '</li>';
			}
			echo '</ul>';

			$this->print_copy_script();
		}

		echo '</body></html>';
	}

	/**
	 * Inline "copy to clipboard" behavior for the index page.
	 *
	 * The Async Clipboard API is only available in secure contexts, so this
	 * falls back to a hidden-textarea + execCommand copy over plain HTTP.
	 */
	private function print_copy_script() {
		$copied = esc_js( __( 'Copied!', 'filtered-calendars' ) );

		// A static inline script; the only interpolation ($copied) is esc_js()'d above.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static JS string, dynamic part already escaped.
		echo "<script>(function(){function copy(text){if(navigator.clipboard&&navigator.clipboard.writeText){return navigator.clipboard.writeText(text);}return new Promise(function(res,rej){var ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.focus();ta.select();try{document.execCommand('copy')?res():rej();}catch(e){rej(e);}document.body.removeChild(ta);});}document.addEventListener('click',function(e){var b=e.target.closest('.copy');if(!b){return;}copy(b.getAttribute('data-url')).then(function(){var label=b.textContent;b.textContent='{$copied}';b.classList.add('copied');setTimeout(function(){b.textContent=label;b.classList.remove('copied');},1500);});});})();</script>";
	}

	/**
	 * Fetch, filter, and output one calendar.
	 *
	 * @param array $feed Calendar config.
	 */
	private function serve( array $feed ) {
		$upstream = $this->get_upstream( $feed['url'] );

		if ( empty( $upstream['body'] ) ) {
			status_header( 502 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Upstream calendar is unavailable.';
			exit;
		}

		$patterns = Filter::compile( $feed['keywords'] );
		$result   = Filter::apply( $upstream['body'], $patterns );

		if ( null === $result ) {
			// Couldn't parse: pass the upstream through unfiltered rather than error.
			$body = $upstream['body'];
			Store::record_stats(
				$feed['id'],
				array(
					'kept'    => 0,
					'dropped' => 0,
					'status'  => 'parse_error',
				)
			);
		} else {
			$body = $result['ical'];
			Store::record_stats(
				$feed['id'],
				array(
					'kept'           => $result['kept'],
					'dropped'        => $result['dropped'],
					'dropped_titles' => $result['dropped_titles'],
					'status'         => $upstream['stale'] ? 'stale' : 'ok',
				)
			);
		}

		// ETag reflects the exact bytes we're about to send (post-filter).
		$etag = '"' . md5( $body ) . '"';

		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		if ( '' !== $if_none_match && $this->etag_matches( $if_none_match, $etag ) ) {
			status_header( 304 );
			header( 'ETag: ' . $etag );
			header( 'Cache-Control: max-age=' . self::CACHE_FRESH );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: max-age=' . self::CACHE_FRESH );
		header( 'X-Robots-Tag: noindex' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar body, not HTML.
		echo $body;
	}

	/**
	 * Get upstream calendar body, from cache or network, with stale fallback.
	 *
	 * @param string $url Upstream URL.
	 * @return array { body:string, stale:bool }
	 */
	private function get_upstream( $url ) {
		$key      = 'fc_up_' . md5( $url );
		$good_key = 'fc_good_' . md5( $url );

		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['body'] ) ) {
			$cached['stale'] = false;
			return $cached;
		}

		// Conditional GET using validators from the last good copy.
		$last_good = get_transient( $good_key );
		$headers   = array();
		if ( is_array( $last_good ) ) {
			if ( ! empty( $last_good['etag'] ) ) {
				$headers['If-None-Match'] = $last_good['etag'];
			}
			if ( ! empty( $last_good['last_modified'] ) ) {
				$headers['If-Modified-Since'] = $last_good['last_modified'];
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'headers'    => $headers,
				'user-agent' => 'FilteredCalendars/' . FILTERED_CALENDARS_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->stale_or_empty( $last_good );
		}

		$code = wp_remote_retrieve_response_code( $response );

		// Upstream says nothing changed — reuse the last good body.
		if ( 304 === $code && is_array( $last_good ) ) {
			$fresh = array( 'body' => $last_good['body'] );
			set_transient( $key, $fresh, self::CACHE_FRESH );
			set_transient( $good_key, $last_good, self::CACHE_GOOD );
			$fresh['stale'] = false;
			return $fresh;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== $code || '' === $body ) {
			return $this->stale_or_empty( $last_good );
		}

		$record = array(
			'body'          => $body,
			'etag'          => wp_remote_retrieve_header( $response, 'etag' ),
			'last_modified' => wp_remote_retrieve_header( $response, 'last-modified' ),
		);

		set_transient( $key, array( 'body' => $body ), self::CACHE_FRESH );
		set_transient( $good_key, $record, self::CACHE_GOOD );

		return array(
			'body'  => $body,
			'stale' => false,
		);
	}

	/**
	 * Fall back to the last good copy (marked stale) or an empty result.
	 *
	 * @param mixed $last_good Cached good record or false.
	 * @return array
	 */
	private function stale_or_empty( $last_good ) {
		if ( is_array( $last_good ) && ! empty( $last_good['body'] ) ) {
			return array(
				'body'  => $last_good['body'],
				'stale' => true,
			);
		}
		return array(
			'body'  => '',
			'stale' => true,
		);
	}

	/**
	 * Compare a client's If-None-Match against our ETag (handles lists and W/ prefix).
	 *
	 * @param string $header Raw If-None-Match value.
	 * @param string $etag   Our current ETag.
	 * @return bool
	 */
	private function etag_matches( $header, $etag ) {
		if ( '*' === $header ) {
			return true;
		}
		$normalized = str_replace( 'W/', '', $etag );
		foreach ( array_map( 'trim', explode( ',', $header ) ) as $candidate ) {
			if ( str_replace( 'W/', '', $candidate ) === $normalized ) {
				return true;
			}
		}
		return false;
	}
}
