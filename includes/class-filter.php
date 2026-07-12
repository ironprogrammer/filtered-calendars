<?php
/**
 * Keyword matching and VEVENT-level removal for iCalendar (RFC 5545) feeds.
 *
 * iCalendar is a line-based format, not XML, so this doesn't use DOMDocument.
 * Filtering works on the raw upstream text: the VCALENDAR envelope (PRODID,
 * X-WR-CALNAME, VTIMEZONE definitions, and any other components) passes through
 * byte-for-byte, so the calendar keeps the origin's identity and timezones in
 * the reader. Only top-level VEVENT blocks whose text matches a keyword are
 * removed.
 *
 * Two representations of each line matter here:
 *  - "physical" lines: exactly as they appear on the wire, including RFC 5545
 *    folding (continuation lines begin with a space or tab). We keep and re-emit
 *    these verbatim for fidelity.
 *  - "logical" lines: physical lines unfolded, used only to read property values
 *    (SUMMARY, etc.) when deciding whether to drop an event.
 */

namespace FilteredCalendars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Filter {

	/**
	 * Properties whose values make up an event's searchable text. SUMMARY (the
	 * event name) is the primary target; the rest catch keywords that only show
	 * up in the location, notes, or categories.
	 */
	const HAYSTACK_PROPS = array( 'SUMMARY', 'LOCATION', 'DESCRIPTION', 'CATEGORIES' );

	/**
	 * Turn a textarea of keyword rules into compiled regex patterns.
	 *
	 * Each non-empty line is one rule. Matching is case-insensitive substring;
	 * `*` is a wildcard (matches any run of characters). Lines beginning with `#`
	 * are treated as comments.
	 *
	 * @param string $keywords Raw textarea contents.
	 * @return string[] Compiled PCRE patterns.
	 */
	public static function compile( $keywords ) {
		$patterns = array();

		$lines = preg_split( '/\r\n|\r|\n/', (string) $keywords );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			// Escape everything, then re-open the wildcard.
			$escaped = preg_quote( $line, '/' );
			$escaped = str_replace( '\*', '.*', $escaped );

			$patterns[] = '/' . $escaped . '/iu';
		}

		return $patterns;
	}

	/**
	 * Filter a calendar document, removing VEVENTs that match any pattern.
	 *
	 * @param string   $ical     Upstream calendar body.
	 * @param string[] $patterns Compiled patterns from compile().
	 * @return array { ical:string, kept:int, dropped:int, dropped_titles:string[] } or null if not a calendar.
	 */
	public static function apply( $ical, array $patterns ) {
		if ( ! self::is_feed( $ical ) ) {
			return null;
		}

		$eol   = ( false !== strpos( $ical, "\r\n" ) ) ? "\r\n" : "\n";
		$lines = preg_split( '/\r\n|\r|\n/', $ical );

		$out            = array();
		$event          = array();
		$in_event       = false;
		$kept           = 0;
		$dropped        = 0;
		$dropped_titles = array();

		foreach ( $lines as $line ) {
			$trimmed = rtrim( $line );

			if ( ! $in_event ) {
				if ( 0 === strcasecmp( $trimmed, 'BEGIN:VEVENT' ) ) {
					$in_event = true;
					$event    = array( $line );
				} else {
					$out[] = $line;
				}
				continue;
			}

			// Inside a VEVENT: collect until END:VEVENT, then decide its fate.
			$event[] = $line;
			if ( 0 === strcasecmp( $trimmed, 'END:VEVENT' ) ) {
				$in_event = false;

				$props    = self::properties( $event );
				$haystack = self::haystack( $props );

				if ( self::matches( $haystack, $patterns ) ) {
					++$dropped;
					if ( isset( $props['SUMMARY'] ) && '' !== $props['SUMMARY'] ) {
						$dropped_titles[] = $props['SUMMARY'];
					}
				} else {
					++$kept;
					foreach ( $event as $event_line ) {
						$out[] = $event_line;
					}
				}
			}
		}

		// An unterminated final VEVENT (truncated feed): keep what we have rather
		// than silently dropping it.
		if ( $in_event ) {
			foreach ( $event as $event_line ) {
				$out[] = $event_line;
			}
			++$kept;
		}

		return array(
			'ical'           => implode( $eol, $out ),
			'kept'           => $kept,
			'dropped'        => $dropped,
			'dropped_titles' => array_values( array_filter( $dropped_titles, 'strlen' ) ),
		);
	}

	/**
	 * Quick check: does this body look like an iCalendar document?
	 *
	 * Used to tell a real .ics feed from an HTML error/landing page.
	 *
	 * @param string $ical Candidate body.
	 * @return bool
	 */
	public static function is_feed( $ical ) {
		if ( '' === trim( (string) $ical ) ) {
			return false;
		}
		// BEGIN:VCALENDAR is the required first component of any iCalendar stream.
		return false !== stripos( $ical, 'BEGIN:VCALENDAR' );
	}

	/**
	 * Extract the calendar's display name (X-WR-CALNAME), used to prefill a name.
	 *
	 * @param string $ical Upstream calendar body.
	 * @return string Empty string if absent.
	 */
	public static function calendar_name( $ical ) {
		if ( '' === trim( (string) $ical ) ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $ical );

		// Only read the header, before the first component begins.
		$header = array();
		foreach ( $lines as $line ) {
			$trimmed = rtrim( $line );
			if ( 0 === strcasecmp( $trimmed, 'BEGIN:VEVENT' )
				|| 0 === strcasecmp( $trimmed, 'BEGIN:VTIMEZONE' ) ) {
				break;
			}
			$header[] = $line;
		}

		$props = self::properties( $header );
		if ( isset( $props['X-WR-CALNAME'] ) && '' !== $props['X-WR-CALNAME'] ) {
			return $props['X-WR-CALNAME'];
		}
		return '';
	}

	/**
	 * Does the haystack match any pattern?
	 *
	 * @param string   $haystack Concatenated searchable text.
	 * @param string[] $patterns Compiled patterns.
	 * @return bool
	 */
	public static function matches( $haystack, array $patterns ) {
		if ( '' === $haystack ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $haystack ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the searchable text for an event from its parsed properties.
	 *
	 * @param array $props Property name => unescaped value.
	 * @return string
	 */
	private static function haystack( array $props ) {
		$parts = array();
		foreach ( self::HAYSTACK_PROPS as $name ) {
			if ( isset( $props[ $name ] ) && '' !== $props[ $name ] ) {
				$parts[] = $props[ $name ];
			}
		}
		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Parse a block of physical lines into property name => value.
	 *
	 * Unfolds continuation lines (RFC 5545 §3.1), strips any property parameters
	 * (everything between the name and the first unquoted ':'), and unescapes the
	 * TEXT value. When a property repeats (e.g. multiple CATEGORIES), values are
	 * joined with newlines so all of them are searchable.
	 *
	 * @param string[] $block Physical lines, e.g. one VEVENT.
	 * @return array<string,string> Uppercase property name => value.
	 */
	private static function properties( array $block ) {
		$logical = self::unfold( $block );
		$props   = array();

		foreach ( $logical as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}

			// The property name ends at the first ';' (params) or ':' (value).
			$semi     = strpos( $line, ';' );
			$name_end = ( false !== $semi && $semi < $colon ) ? $semi : $colon;
			$name     = strtoupper( trim( substr( $line, 0, $name_end ) ) );
			if ( '' === $name ) {
				continue;
			}

			$value = self::unescape_text( substr( $line, $colon + 1 ) );

			if ( isset( $props[ $name ] ) ) {
				$props[ $name ] .= "\n" . $value;
			} else {
				$props[ $name ] = $value;
			}
		}

		return $props;
	}

	/**
	 * Unfold RFC 5545 content lines: a CRLF followed by a space or tab is a
	 * continuation of the previous line.
	 *
	 * @param string[] $lines Physical lines.
	 * @return string[] Logical (unfolded) lines.
	 */
	private static function unfold( array $lines ) {
		$logical = array();
		foreach ( $lines as $line ) {
			if ( '' !== $line && ( ' ' === $line[0] || "\t" === $line[0] ) && ! empty( $logical ) ) {
				$logical[ count( $logical ) - 1 ] .= substr( $line, 1 );
			} else {
				$logical[] = $line;
			}
		}
		return $logical;
	}

	/**
	 * Unescape an iCalendar TEXT value: \n / \N -> newline, and \\ \, \; -> the
	 * literal character (RFC 5545 §3.3.11).
	 *
	 * @param string $value Raw property value.
	 * @return string
	 */
	private static function unescape_text( $value ) {
		return preg_replace_callback(
			'/\\\\([nN\\\\,;])/',
			static function ( $m ) {
				$c = $m[1];
				if ( 'n' === $c || 'N' === $c ) {
					return "\n";
				}
				return $c;
			},
			(string) $value
		);
	}
}
