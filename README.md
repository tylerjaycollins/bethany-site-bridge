# Bethany Site Bridge

A WordPress plugin that exposes over REST the parts of [bethanycentral.org](https://bethanycentral.org)
that core and plugin REST APIs don't reach — so site changes can be made programmatically
instead of clicked through wp-admin.

Single-site, purpose-built. Public only because it holds nothing secret and there's no
reason to hide it; not intended as a general-purpose plugin.

## Modules

| Module | Purpose |
|---|---|
| `site` | Read-only introspection: WP/PHP versions, active plugins, theme, post types, taxonomies, registered meta, capability report |
| `meta` | Read/write arbitrary post meta — the escape hatch for ACF fields and plugin meta in no REST whitelist |
| `events` | The Events Calendar **recurring** events, including **"will not occur"** exclusion dates |
| `updater` | One-click updates from this repo's releases |

### Why the events module exists

The Events Calendar ships no recurrence *write* path. Verified with `OPTIONS` against
every write surface — `tribe/events/v1/events`, `tec/v1/events`,
`tribe/power-automate/v1/create-events`, and `wp/v2/tribe_events` meta — none accept a
recurrence field, and `_EventRecurrence` isn't in the REST-exposed meta whitelist.
Recurrence is reachable only from PHP, hence this plugin.

It works through TEC's own `tribe_create_event()` / `tribe_update_event()` with a
`recurrence` key rather than writing `_EventRecurrence` directly, so occurrence
generation, timezone handling and series bookkeeping all stay in TEC's code.

## Install

WP admin → **Plugins → Add New → Upload Plugin** → the zip from
[Releases](../../releases) → Activate. After that, updates arrive as a normal
**Update now** button.

Auth reuses an existing shared secret, in precedence order `BSB_KEY` →
`ATLAS_EVENTS_KEY` → `ATLAS_PRLI_KEY`, defined in `wp-config.php` and sent by the caller
in the `X-Atlas-Key` header. The secret is read at *request* time, so it resolves whether
the constant lives in `wp-config.php` or a theme's `functions.php`. On activation an admin
notice appears if no secret is defined, or if The Events Calendar is inactive — rather
than silently returning 500 on the first call.

**No credentials live in this repo** — only constant *names*. Keep it that way.

## Endpoints

Base: `<site>/wp-json/atlas/v1`

| | |
|---|---|
| `GET /site` | Environment + capability report. `?meta_for=<post_type>` lists that type's registered meta keys; `?refresh_update=1` bypasses the manifest cache |
| `GET /meta/{ref}` | All post meta, or `?keys=a,b`. Serialized values unpacked |
| `PUT /meta/{ref}` | Write meta. Requires `confirm=true`; returns before/after |
| `POST /events` | Create an event, optionally recurring |
| `GET /events/{ref}/recurrence` | Raw `_EventRecurrence` + occurrence list |
| `GET /events/{ref}/occurrences` | `{ count, dates[], occurrences[] }` |
| `PUT /events/{ref}/recurrence` | Replace the rule + exclusions |

`{ref}` = post ID, TEC provisional occurrence ID, or slug. **Prefer the slug** — it
always resolves to the parent post.

### Recurrence spec

```json
{
  "freq": "weekly",
  "interval": 1,
  "weekdays": ["wednesday"],
  "until": "2027-04-21",
  "dates": ["2026-09-04"],
  "exclusions": ["2026-11-25", "2026-12-16"]
}
```

`freq`/`weekdays`/`until` describe a pattern. `dates` adds explicit one-off dates — use
this for an irregular series that no interval reproduces. `exclusions` are the "will not
occur" dates. Either `until` or `count` is required alongside `freq`.

## Safety

Every write takes `dry_run=true` and returns the dates it *would* generate, computed in
plain PHP independently of TEC — so a disagreement between the preview and TEC's actual
output is itself the signal that something is wrong.

- A write through a **provisional occurrence ID** is refused unless `apply_to=series` is
  passed explicitly
- `expected_count` turns a wrong occurrence count into a 409 instead of a silent truncation
- Writes report before/after counts and a `matches_preview` flag, and **wait** for TEC to
  finish regenerating first
- Exclusion dates matching no generated date come back as `inert_exclusions`, so a typo'd
  date isn't silently ignored
- Meta writes require `confirm=true` and refuse `_EventRecurrence` outright (writing it
  directly wouldn't regenerate occurrences)

These guards are not theoretical. On 2026-08-10 a plain `POST` of `start_date` to a
provisional occurrence ID — meaning to move a single event one week — rewrote the whole
series start and cut it from **30 occurrences to 5** on the live public calendar.

Two things to know about TEC that the guards encode:

- Provisional occurrence IDs sit above 10,000,000 and their permalinks carry the date
  (`/bethany-event/awana/2027-03-31`). Real post IDs are far below that.
- `tribe/events/v1` reports `recurring: null` even for recurring events, so that field
  can't be trusted. Use `GET /tec/v1/events`'s `recurring` / `in_series` / `series`
  filters instead.

## Releasing

Source of truth is the `atlas` repo at `wordpress/bethany-site-bridge.php`; this repo is
the distribution channel. Edit there, sync here, then:

1. Bump `Version:` in the plugin header
2. Bump `version` in `manifest.json` and point `package` at the new tag
3. Commit, then `git tag vX.Y.Z && git push --tags`

CI verifies the tag, plugin header and manifest all agree and that the package URL points
at the tag being built, then builds the zip with the correct top-level folder name and
attaches it to the release. WordPress sees the new `manifest.json` within 6 hours (or
immediately via **Check again** on the Updates screen).
