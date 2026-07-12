# Filtered Calendars

A WordPress plugin that re-serves external iCalendar (`.ics`) feeds with unwanted
events removed by keyword — while keeping each calendar's original identity
(display name, timezones, event details) in your calendar app.

> **Source:** <https://github.com/ironprogrammer/filtered-calendars>
> Built for the common case of subscribing to a busy shared calendar — a school
> or district calendar, say — where you only want some of the events. Point the
> plugin at the calendar, list the words you don't want (e.g. `board`, `budget`),
> and subscribe to the cleaned URL instead.
>
> A sibling of [Filtered Feeds](https://github.com/ironprogrammer/filtered-feeds),
> which does the same thing for RSS/Atom.

## How it works

You subscribe your calendar app to the plugin's URL instead of the origin
calendar:

```
https://your-site.example/filtered-calendars/{slug}/
```

On request, the plugin fetches the upstream `.ics` feed, removes events whose
**name, location, notes, or categories** match any of your keywords, and streams
the result back. The `VCALENDAR` envelope (display name, PRODID, `VTIMEZONE`
definitions) passes through untouched, so Apple Calendar, Google Calendar — or
any client — shows the origin calendar exactly as if you had subscribed directly.

Individual events are **never** stored as WordPress posts.

## Why not the RSS approach?

iCalendar is a line-based format (RFC 5545), not XML, so this plugin does **not**
reuse Filtered Feeds' `DOMDocument` filter. Instead it parses the calendar
stream directly: it unfolds RFC 5545 continuation lines to read each event's
properties, drops matching top-level `VEVENT` blocks, and re-emits every other
line **byte-for-byte** — preserving folding, timezones, and any components
(`VTODO`, `VALARM`, custom `X-` properties) it doesn't need to touch.

## Filtering

One keyword per line in **Settings → Filtered Calendars**:

- Case-insensitive substring match.
- `*` is a wildcard, matching any run of characters (e.g. `staff *meeting`).
- Lines beginning with `#` are comments.
- Matches each event's `SUMMARY` (name), `LOCATION`, `DESCRIPTION` (notes), and
  `CATEGORIES`.

A **Preview filter** button dry-runs a URL + keyword list against the live
calendar and shows exactly which events would be dropped, so you can tune the
list before saving.

## Caching

- Upstream calendars are fetched at most once per 15 minutes via a WordPress
  transient, so many client polls cost one origin request. Transients expire on
  their own — nothing accumulates in the database.
- A separate 24-hour "last known good" copy backstops upstream errors, so a
  brief outage at the origin doesn't break your subscription (the admin flags
  when a calendar is served from this fallback).
- Upstream fetches use conditional GET (`If-None-Match` / `If-Modified-Since`).
- The filtered response carries an `ETag` and `Cache-Control`, and answers a
  client's `If-None-Match` with `304 Not Modified`.

## Diagnostics

Per calendar, the admin shows how many events were dropped and kept on the last
fetch, a cumulative all-time dropped count, and the names of the most recently
dropped events — so you can confirm it's doing what you expect.

## Configurable path

The `filtered-calendars` URL segment can be changed in the admin (e.g. to
`calendars`), in which case calendars are served at
`https://your-site.example/calendars/{slug}/`. Rewrite rules are flushed
automatically when the path changes. A trailing `.ics` on the URL is accepted
too, for clients that sniff the file extension.

## Requirements

- WordPress 6.9+
- PHP 7.4+

## Installation

1. Download `filtered-calendars.zip` from the
   [latest release](https://github.com/ironprogrammer/filtered-calendars/releases)
   and install it via **Plugins → Add New → Upload Plugin** (the zip already
   contains the compiled admin app).
2. Activate **Filtered Calendars** in Plugins.
3. Go to **Settings → Filtered Calendars**, add a calendar, and subscribe your
   app to the generated URL.

## Development

The admin UI is a React (DataViews) app built with `@wordpress/scripts`
(Node 24). The compiled `build/` directory is **not** committed — build it
before running the plugin from a source checkout:

```bash
npm install
npm run build     # production build
npm run start     # watch mode
```

### Linting

JS/CSS is linted with `@wordpress/scripts`; PHP with PHPCS (WordPress-Extra +
PHP 7.4 compatibility) via Composer:

```bash
npm run lint:js
npm run lint:css
composer install
composer run lint       # PHPCS; `composer run lint:fix` to auto-fix
```

### Continuous integration

- **[`lint.yml`](.github/workflows/lint.yml)** — runs the PHP and JS/CSS
  linters and verifies the build on every push and pull request.
- **[`plugin-check.yml`](.github/workflows/plugin-check.yml)** — runs the
  official [WordPress Plugin Check](https://github.com/WordPress/plugin-check-action).
- **[`codeql.yml`](.github/workflows/codeql.yml)** — CodeQL static analysis of
  the JavaScript.
- **[Dependabot](.github/dependabot.yml)** — weekly npm, Composer, and Actions
  updates.

Release zips are produced automatically: pushing a `v*` tag runs
[`.github/workflows/release.yml`](.github/workflows/release.yml), which builds
the app, packages the plugin with `wp-scripts plugin-zip`, and attaches the zip
to a GitHub Release.

## Architecture

- **`includes/class-store.php`** — calendar configs and the path setting, exposed
  via the core `/wp/v2/settings` REST endpoint; server-managed diagnostic stats.
- **`includes/class-filter.php`** — keyword → pattern compilation and iCalendar
  `VEVENT` removal (operates on raw RFC 5545 text to preserve the envelope).
- **`includes/class-server.php`** — the public `/{base}/{slug}/` endpoint: fetch,
  cache, conditional GET, filter, and serve.
- **`includes/class-rest-controller.php`** — `filtered-calendars/v1` routes for
  stats and the live preview.
- **`includes/class-admin.php`** — settings page and asset loading.
- **`src/`** — React (DataViews) admin app built with `@wordpress/scripts`.

## License

GPL-2.0-or-later.
