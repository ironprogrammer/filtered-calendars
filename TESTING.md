# Testing Filtered Calendars locally

Two ways to run the plugin in a real WordPress without touching your own site.
Both need **Node 20+** (this project is developed on Node 24).

Build the admin app once first, so the settings screen loads:

```bash
npm install
npm run build
```

---

## Option A — WordPress Playground CLI (recommended)

Runs WordPress on WASM with real outbound networking, and the bundled
[`.playground/blueprint.json`](.playground/blueprint.json) **auto-seeds a
calendar** pointed at the PPS district feed with `board` and `budget` as drop
keywords — so there's something to see immediately.

```bash
npx @wp-playground/cli@latest start \
  --port=9400 \
  --blueprint=.playground/blueprint.json
```

The current directory is auto-detected as a plugin, mounted, and activated, and
you're auto-logged-in as `admin`. Then open:

| What | URL |
| --- | --- |
| Admin screen | <http://127.0.0.1:9400/wp-admin/options-general.php?page=filtered-calendars> |
| Discovery index | <http://127.0.0.1:9400/filtered-calendars/> |
| The filtered feed | <http://127.0.0.1:9400/filtered-calendars/pps-district/> |
| Feed (query-var form) | <http://127.0.0.1:9400/?filtered_calendar=pps-district> |

### What to check

The upstream PPS calendar has 217 events; with `board` + `budget` filtered,
the served feed has **175** — every "School Board", "Board of Education", and
"Community Budget Review Committee" event removed — while `X-WR-CALNAME`
(`District Calendar`) and the `VTIMEZONE` block pass through untouched:

```bash
curl -s "http://127.0.0.1:9400/?filtered_calendar=pps-district" -o feed.ics
grep -c 'BEGIN:VEVENT' feed.ics                 # -> 175
grep -icE '^SUMMARY.*(board|budget)' feed.ics   # -> 0
grep -i 'X-WR-CALNAME' feed.ics                 # -> X-WR-CALNAME:District Calendar
```

> **Pretty-permalink note:** the `/filtered-calendars/{slug}/` URL depends on a
> rewrite rule. The plugin flushes rules for you, but the first request right
> after boot can 302 while that settles — just reload, or use the
> `?filtered_calendar={slug}` query-var form, which always works.

---

## Option B — the admin flow (any local WP)

To exercise the UI as a user would, on Playground or any local WordPress:

1. **Settings → Filtered Calendars → Add calendar.**
2. Paste a source `.ics` URL, e.g.
   `https://www.pps.net/fs/calendar-manager/events.ics?calendar_ids=159`
   (a `webcal://` link works too). Tab out of the field — the name fills in
   from the calendar automatically.
3. In **Drop events matching**, enter one keyword per line:
   ```
   board
   budget
   ```
4. Click **Preview filter** to dry-run against the live feed — it lists exactly
   which events would be dropped.
5. **Save**, then copy the **Subscribe URL** and open it (or add it to Apple
   Calendar / Google Calendar). The *Filtered* column shows the drop/keep counts
   after the first fetch.

---

## Resetting / stopping

- **Stop** either server with `Ctrl-C` in its terminal.
- Playground stores its site under `~/.wordpress-playground/sites/`; add
  `--reset` to `start` to wipe it and re-run the blueprint from scratch.

> `@wp-now/wp-now` also works (`npx @wp-now/wp-now start`) but is deprecated in
> favor of the Playground CLI above.
