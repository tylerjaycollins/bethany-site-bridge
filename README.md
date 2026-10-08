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
| `options` | Read/write/delete `wp_options` the same way — plugin settings, redirect lists, anything a settings screen owns. Blocklist for site-critical options; credential-looking names refused for read and write |
| `events` | The Events Calendar **recurring** events, including **"will not occur"** exclusion dates |
| `redirects` | Path → URL redirects managed over REST — legacy URLs after a page move, including the nested paths WordPress's own 404 guess can't rescue. Exact and `/*` prefix rules, per-rule hit/referer stats, and a nested-404 rescue (a multi-segment 404 whose last segment is exactly one published page/post's slug is 301'd there; off via option `bsb_redirects_settings` `{"rescue":false}`) |
| `content` | Find text across post content, post meta (Cornerstone builder JSON included — JSON-escaped slashes are searched too) and options; serialized-safe replace with a dry run listing every row |
| `files` | Read/write files under `wp-content/mu-plugins` and the active child theme — the SFTP replacement. Base64 transport (the host firewall rejects PHP in request bodies), PHP syntax lint before any write, redeclaration check, backups outside the auto-loaded dirs, sha1-checked overwrites, restore |
| `bulletin` | Builds a Sunday's `bulletin` post from plain item data sent by Rock RMS (`POST /bulletin`, dry run by default; Rock uses its own bulletin-only `X-Bulletin-Key`): Atlas's ACF mapping in PHP, order of service from Planning Center, scheduled for Friday 9:30 |
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
| `POST /site/update-plugin` | Install an update WordPress is already offering (`{plugin?, confirm}`; defaults to this plugin). The last manual step in a release, gone |
| `POST /site/purge-cache` | Clear the `page` / `static` / `object` / `opcache` layers (`{layers?}`); reports per layer what was cleared. `probe=1` lists the cache machinery present without clearing |
| `POST /site/flush-rewrites` | Settings → Permalinks → Save, over REST |
| `GET /options?search=` | Option names matching a term (no transients), with size and read/write flags |
| `GET /options/{name}` | One option, unserialized |
| `PUT /options/{name}` | Write `{value}` (any JSON). Requires `confirm=true`; before/after read back from the row |
| `DELETE /options/{name}` | Requires `confirm=true` |
| `GET /meta/{ref}` | All post meta, or `?keys=a,b`. Serialized values unpacked |
| `PUT /meta/{ref}` | Write meta. Requires `confirm=true`; returns before/after |
| `POST /events` | Create an event, optionally recurring |
| `GET /events/{ref}/recurrence` | Raw `_EventRecurrence` + occurrence list |
| `GET /events/{ref}/occurrences` | `{ count, dates[], occurrences[] }` |
| `PUT /events/{ref}/recurrence` | Replace the rule + exclusions |
| `GET /redirects` | Every redirect rule; `?resolve=/some/path` reports what that path would do |
| `PUT /redirects` | Add or replace a rule `{path, to, status?, note?}`. Requires `confirm=true`; returns before/after, the page it would shadow, and a worked example. `path` ending in `/*` is a prefix rule; a `*` at the end of `to` carries the remainder |
| `DELETE /redirects` | Remove a rule `{path}`. Requires `confirm=true` |
| `GET /content/find` | `?text=&in=posts,meta,options&post_type=&limit=` — every row containing the text, with snippets |
| `POST /content/replace` | `{from, to, in?, post_type?, limit?}` serialized-safe replace. Requires `confirm=true`; the dry run is the exact plan. Refuses needles under 4 chars and more rows than `limit` (default 200) |
| `PUT /events/{ref}` | Edit an event's ordinary fields (title, description, dates, venue, organizer, website, cost, terms, thumbnail, ACF). Only the fields passed change; recurrence is snapshotted and restored; dates on a recurring series need `apply_to=series` + `expected_count`. Requires `confirm=true` |
| `GET /files/{root}` | List files under `mu-plugins` or `theme` (no content) plus writability/lint capabilities; `?path=` reads one file: base64 content, sha1, size, declared functions. The path is a parameter, never a URL segment — the host's nginx serves any URI ending in `.php`/`.css` itself |
| `PUT /files/{root}` | Write `{path, ...}`:  `{content_base64, expected_sha1 (required when the file exists), force?}`. Requires `confirm=true`. .php is linted first (`php -l`, else `token_get_all(TOKEN_PARSE)`, else `opcache_compile_file`), refused on syntax error or probable redeclaration; the previous version is backed up and its name returned |
| `DELETE /files/{root}` | `{path}` moves the file to backups. Requires `confirm=true` |
| `POST /files/restore` | `{root, path, backup}` puts a backup back (current file backed up first). Requires `confirm=true` |

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
