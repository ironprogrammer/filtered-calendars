=== Filtered Calendars ===
Contributors: ironprogrammer
Plugin URI: https://github.com/ironprogrammer/filtered-calendars
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later

Re-serve external iCalendar (.ics) feeds with unwanted events removed by keyword, preserving each calendar's name and timezones.

== What it does ==

Source code, issues, and contributions: https://github.com/ironprogrammer/filtered-calendars

Point it at a source calendar (e.g. a school or district .ics URL), give it a
list of keywords to drop (e.g. "board", "budget"), and subscribe your calendar
app to the plugin's URL instead of the original:

    https://your-site.example/filtered-calendars/{slug}/

Events whose name, location, notes, or categories match any keyword are removed.
The calendar envelope — its display name, PRODID, and VTIMEZONE definitions —
passes through untouched, so Apple Calendar, Google Calendar, or any client shows
the origin calendar exactly as if you'd subscribed directly.

The `filtered-calendars` path segment is configurable in Settings → Filtered
Calendars if you'd prefer something else (e.g. `/calendars/{slug}/`).

== How matching works ==

One keyword per line in the "Drop events matching" box:

* Case-insensitive substring match.
* `*` is a wildcard, matching any run of characters (e.g. `staff *meeting`).
* Lines beginning with `#` are ignored (comments).
* Matches against each event's SUMMARY (name), LOCATION, DESCRIPTION (notes),
  and CATEGORIES.

Use "Preview filter" in the editor to dry-run a URL + keywords against the live
calendar before saving — it shows exactly which events would be dropped.

Tip: broad words can catch legitimate events. Prefer specific terms and use the
preview to check for false positives.

== Caching ==

* Each calendar has a Refresh frequency (every 15 minutes, hourly, every 6
  hours, daily, or weekly; daily by default). The filtered result is cached for
  that interval, so reader polls within the window are served from cache without
  re-fetching the origin or re-filtering. Calendars change rarely, so a longer
  interval keeps origin requests low.
* A separate 7-day "last known good" copy is kept so that if the origin is
  briefly down, your app still gets the most recent successful calendar (marked
  as served-from-cache in the admin).
* Upstream fetches use conditional GET (If-None-Match / If-Modified-Since), so an
  unchanged calendar costs a cheap 304.
* The filtered response carries an ETag and a Cache-Control max-age matching the
  refresh interval, and answers a client's If-None-Match with 304 Not Modified.

Individual events are never stored as WordPress posts.

== Diagnostics ==

Settings → Filtered Calendars shows, per calendar: how many events were dropped
and kept on the most recent fetch, when it was last checked, the refresh cadence,
and the names of the most recently dropped events — so you can confirm it's doing
what you expect. (Counts are a snapshot of the latest refresh, not a running
tally of every request.)

== Development ==

The admin UI is a React app built with @wordpress/scripts:

    npm install
    npm run build     # production
    npm run start     # watch mode

Calendar configs are stored in the `filtered_calendars_configs` option and
read/written through the core /wp/v2/settings REST endpoint. Diagnostic stats
live in `filtered_calendars_stats`. Custom REST routes under
`filtered-calendars/v1` power the stats panel and live preview.
