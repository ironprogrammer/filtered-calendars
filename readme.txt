=== Filtered Calendars ===
Contributors: ironprogrammer
Plugin URI: https://github.com/ironprogrammer/filtered-calendars
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Re-serve external iCalendar (.ics) feeds with unwanted events removed by
keyword, while keeping each calendar's original identity (name, timezones,
event details) in your calendar app.

Source code, issues, and contributions: https://github.com/ironprogrammer/filtered-calendars

== What it does ==

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

* Upstream calendars are fetched at most once per 15 minutes (a WordPress
  transient), so many client polls cost one origin request. Transients expire on
  their own — nothing accumulates in the database.
* A separate 24-hour "last known good" copy is kept so that if the origin is
  briefly down, your app still gets the most recent successful calendar (marked
  as served-from-cache in the admin).
* Upstream fetches use conditional GET (If-None-Match / If-Modified-Since).
* The filtered response carries an ETag and Cache-Control, and answers a client's
  If-None-Match with 304 Not Modified.

Individual events are never stored as WordPress posts.

== Diagnostics ==

Settings → Filtered Calendars shows, per calendar: how many events were dropped
and kept on the last fetch, a cumulative all-time dropped count, and the names of
the most recently dropped events — so you can confirm it's doing what you expect.

== Development ==

The admin UI is a React app built with @wordpress/scripts:

    npm install
    npm run build     # production
    npm run start     # watch mode

Calendar configs are stored in the `filtered_calendars_configs` option and
read/written through the core /wp/v2/settings REST endpoint. Diagnostic stats
live in `filtered_calendars_stats`. Custom REST routes under
`filtered-calendars/v1` power the stats panel and live preview.
