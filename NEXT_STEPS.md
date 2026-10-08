# NEXT_STEPS — bethany-site-bridge

Living backlog for the Bethany Site Bridge plugin. Ships by tag push (CI builds the zip); the site updates through the plugin's own updater.

## Pending features

- **Default organizer + venue on event save.** Tyler's rule (2026-09-03): every
  `tribe_events` post must have an organizer and a venue. When either is empty on
  save, set `_EventOrganizerID` = 1005 (Bethany Office) and `_EventVenueID` = 1003
  (Bethany Baptist Church). Why: the theme's Organizer block (Cornerstone looper on
  the single-event template) lists EVERY organizer on the site when the field is
  empty — Fall Cleanup Day showed all 12 people. Put it in the `tweaks` module next
  to `bsb_events_apply_tec_defaults()`, hook `save_post_tribe_events` (late
  priority, skip autosave/revisions), and also call it from `bsb_events_create()`
  so Atlas-created events get it. Backfill of the 16 organizer-less upcoming events
  was done by hand on 2026-09-03, so no data migration is needed at ship time.
  Sub-option: fix the looper's empty-filter behavior in the theme instead — more
  correct, but Cornerstone edits can't go through git, so prefer the plugin.

## Polish items

## Operational tooling

## Maintenance

## Done (recent)

- 0.14.0 — bulletin module (`POST /bulletin`, `GET /bulletin/{sunday}`, `PUT /bulletin/pco-credentials`)
  for Rock RMS's bulletin build (rock-upgrades NEXT_STEPS "Bulletin in Rock"). Atlas still pushes the live
  bulletin until the cutover; Rock calls this in dry-run until then.

- 0.13.0 — posts module: create/read/update any post type, ACF via update_field()
  so repeaters land with their name/_name pairs intact. Built because pushing the
  "Along the Way" journal issue had no route that didn't need a WP application password.

- 0.9.2 — DB-verified option writes (poisoned notoptions cache on the live host).
- 0.9.1 — GF poke token settable over REST.
- 0.9.0 — tweaks module absorbed the site's Code Snippets #5–#8.
