<?php
/**
 * Plugin Name: Bethany Site Bridge
 * Description: A working surface for making bethanycentral.org changes over REST —
 *              site introspection, arbitrary post meta, and The Events Calendar
 *              recurrence (including "will not occur" dates), none of which core
 *              or plugin REST APIs expose — plus the site's custom PHP tweaks
 *              (formerly Code Snippets). Consumed by Atlas and by Claude Code.
 * Version:     0.13.0
 * Author:      Tyler Collins
 * License:     GPL-2.0-or-later
 * Update URI:  https://github.com/tylerjaycollins/bethany-site-bridge
 *
 * UPDATES: one-click from wp-admin. The "Update URI" header above makes WP check the
 * public repo's manifest.json instead of wordpress.org, so new versions show a normal
 * "Update now" button — no more zip uploads. The repo is public and holds no
 * credentials (only constant NAMES like BSB_KEY; never values). Keep it that way.
 *
 * WHAT THIS IS FOR
 * Not an Atlas-specific shim. It's the general-purpose hatch for working on this
 * site collaboratively: when core REST or a plugin's REST API can't reach something,
 * a module goes here instead of the work becoming a manual click-through in wp-admin.
 * Atlas is one consumer; ad-hoc site work from Claude Code is the other.
 *
 * MODULES (add new ones the same way — a section below, routes in the init block)
 *   site    — read-only introspection: versions, active plugins, theme, post types,
 *             taxonomies, REST-exposed meta. Answers "what is this site actually
 *             running" without guessing through REST probes.
 *   meta    — read/write arbitrary post meta. The escape hatch for ACF fields and
 *             plugin meta that isn't in any REST whitelist.
 *   options — read/write/delete wp_options the same way (plugin settings, redirect
 *             lists, anything a settings screen owns), with a blocklist for the
 *             options that can take the site down or leak a secret.
 *   events  — The Events Calendar RECURRING events + "will not occur" exclusions.
 *   redirects — path → URL redirects managed over REST (legacy URLs after a
 *             page move; the nested paths WordPress's own 404 guess can't rescue),
 *             plus a nested-404 rescue and per-rule hit/referer stats.
 *   content — find text across post content, post meta and options (core search
 *             only sees post content), and a serialized-safe replace.
 *   files   — read/write files under wp-content/mu-plugins and the child theme:
 *             the SFTP replacement, with lint, backups and sha1-checked overwrites.
 *   posts   — create/read/update posts of ANY post type, with ACF written
 *             through update_field() so repeaters and their name/_name key
 *             pairs land correctly (v0.13.0). Core REST does this only for a
 *             logged-in user; a shared secret is not one.
 *   tweaks  — the site's custom PHP, absorbed from the Code Snippets plugin (v0.9.0)
 *             so it ships through this plugin's one-click update instead of being
 *             hand-edited in wp-admin: trip-update nested URLs (snippet #5), trip
 *             breadcrumbs (#6), [ff_date] shortcode (#7), and the Atlas GF poke (#8).
 *             The poke token comes from the ATLAS_GF_POKE_TOKEN constant
 *             (wp-config.php or theme functions.php), or from a WP option set via
 *             PUT /site/gf-poke-token (v0.9.1) when no file access is available —
 *             the token VALUE stays out of this file because the repo is public. Every
 *             function is function_exists()-guarded so the plugin and the old
 *             snippets can overlap during cutover; deactivate the snippets after
 *             updating.
 *
 * INSTALL — plugin zip, no SFTP or theme-file-editor needed
 *   1. Build:  mkdir -p /tmp/pkg/bethany-site-bridge
 *              cp bethany-site-bridge.php /tmp/pkg/bethany-site-bridge/
 *              cd /tmp/pkg && zip -rq ~/Downloads/bethany-site-bridge.zip bethany-site-bridge
 *   2. WP admin → Plugins → Add New → Upload Plugin → the zip → Activate.
 *   The zip must contain the FOLDER, not a bare .php file, or WP rejects it.
 *
 * AUTH — nothing new to configure.
 * Reuses the existing Pretty Links secret. Read at REQUEST time (during
 * rest_api_init), so it resolves whether the constant is defined in wp-config.php
 * OR in the theme's functions.php — plugins parse before themes, but by the time a
 * request arrives the theme has loaded. Precedence:
 *   BSB_KEY  →  ATLAS_EVENTS_KEY  →  ATLAS_PRLI_KEY
 * Sent by the caller in the X-Atlas-Key header.
 *
 * REST NAMESPACE: atlas/v1 — deliberately unchanged. /links is already live there
 * and wired into lib/pretty-links.ts; the namespace is just a URL prefix and
 * churning it would mean a code change + redeploy for zero benefit.
 *
 * ENDPOINTS (base <site>/wp-json/atlas/v1)
 *   GET  /site                      → environment + capability report
 *   POST /site/check-updates        → force core's plugin update check (see below)
 *   PUT  /site/gf-poke-token        → store the GF poke token as a WP option
 *   POST /site/update-plugin        → install an offered plugin update (default: this plugin). confirm=true.
 *   POST /site/purge-cache          → clear page / static / object / opcache layers; ?probe=1 lists what's there
 *   POST /site/flush-rewrites       → the Permalinks → Save step
 *   GET  /options?search=           → option names matching (discovery)
 *   GET  /options/{name}            → one option, unserialized
 *   PUT  /options/{name}            → write {value}. confirm=true; before/after
 *   DELETE /options/{name}          → delete. confirm=true
 *   GET  /meta/{ref}                → post meta (all, or ?keys=a,b)
 *   PUT  /meta/{ref}                → write meta. Requires confirm=true.
 *   GET  /events/{ref}/recurrence   → raw _EventRecurrence + occurrence list
 *   GET  /events/{ref}/occurrences  → every generated date for the series
 *   POST /events                    → create (optionally recurring). dry_run ok.
 *   PUT  /events/{ref}/recurrence   → replace rule + exclusions. dry_run ok.
 *   GET  /redirects                 → every rule (+ ?resolve=/some/path to test one)
 *   PUT  /redirects                 → add/replace a rule {path,to,status,note}. confirm=true.
 *   DELETE /redirects               → remove a rule {path}. confirm=true.
 *   GET  /content/find?text=        → where a string appears: posts, meta, options
 *   POST /content/replace           → {from,to,in?} serialized-safe replace. confirm=true.
 *   PUT  /events/{ref}              → edit an event's ordinary fields. confirm=true.
 *   GET  /files/{root}              → list files (root = mu-plugins | theme); ?path= reads one (base64 + sha1)
 *   PUT  /files/{root}              → write {path, content_base64, expected_sha1}. confirm=true.
 *   DELETE /files/{root}            → {path} move to backups. confirm=true.
 *   (the file path is a PARAMETER, never a URL segment: the host's nginx serves
 *    any URI ending in .php/.css itself — 403/404 — before WordPress runs)
 *   POST /files/restore             → {root, path, backup} put a backup back. confirm=true.
 *   POST /posts                     → create {post_type,title,status?,slug?,content?,acf?,meta?,terms?}. confirm=true.
 *   GET  /posts/{ref}               → read one back; ?full=true returns ACF values, else their shape
 *   PUT  /posts/{ref}               → partial update; ACF repeaters replace wholesale. confirm=true.
 *
 * {ref} = post ID, TEC provisional occurrence ID, or slug. Prefer the SLUG.
 *
 * WHAT A REST CREATE SKIPS (and this plugin therefore does by hand).
 * tribe_create_event() only does what TEC's own PHP API does. Twice now that has
 * meant a created event was subtly unlike a hand-built one:
 *   v0.7.0 — taxonomies. tax_input is silently dropped, so events were created
 *            with NO category, and the category is what puts them on their
 *            program page.
 *   v0.8.0 — ACF field defaults. An ACF default is only applied on an ACF save,
 *            and this path bypasses ACF, so fields land UNSET rather than
 *            defaulted. That's why 15 events could carry a correct _EventURL and
 *            still show no "Register Now" button.
 * Both are now applied explicitly and READ BACK off the saved post, because "we
 * asked for it" and "it stuck" are different claims. If a third such gap turns up,
 * find it the same way: diff a created event's meta against a hand-built one.
 *
 * ⚠️ SAFETY — why the guards exist.
 * On 2026-08-10 a plain POST of start_date to a TEC *provisional occurrence ID*
 * (10009085), meaning to move one Awana night, silently rewrote the whole series
 * start and cut it from 30 occurrences to 5 on the live public calendar. So:
 *   - every write takes dry_run=true and reports what it WOULD do
 *   - writes through a provisional ID are refused unless apply_to=series is passed
 *   - expected_count turns a wrong occurrence count into a 409, not a silent truncation
 *   - writes echo before/after state so a bad change is visible immediately
 *   - meta writes require confirm=true and refuse _EventRecurrence outright
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** TEC provisional occurrence IDs start here. Real post IDs are far below it. */
if ( ! defined( 'BSB_PROVISIONAL_FLOOR' ) ) {
	define( 'BSB_PROVISIONAL_FLOOR', 10000000 );
}

add_action( 'rest_api_init', function () {
	$auth = 'bsb_auth';

	// --- site ---
	register_rest_route( 'atlas/v1', '/site', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_site_report', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/site/check-updates', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_site_check_updates', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/site/gf-poke-token', array(
		array( 'methods' => 'PUT', 'callback' => 'bsb_site_put_gf_poke_token', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/site/update-plugin', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_site_update_plugin', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/site/purge-cache', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_site_purge_cache', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/site/flush-rewrites', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_site_flush_rewrites', 'permission_callback' => $auth ),
	) );

	// --- options ---
	register_rest_route( 'atlas/v1', '/options', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_options_search', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/options/(?P<name>[^/]+)', array(
		array( 'methods' => 'GET',    'callback' => 'bsb_options_get',    'permission_callback' => $auth ),
		array( 'methods' => 'PUT',    'callback' => 'bsb_options_put',    'permission_callback' => $auth ),
		array( 'methods' => 'DELETE', 'callback' => 'bsb_options_delete', 'permission_callback' => $auth ),
	) );

	// --- meta ---
	register_rest_route( 'atlas/v1', '/meta/(?P<ref>[^/]+)', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_meta_get', 'permission_callback' => $auth ),
		array( 'methods' => 'PUT', 'callback' => 'bsb_meta_put', 'permission_callback' => $auth ),
	) );

	// --- events ---
	register_rest_route( 'atlas/v1', '/events', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_events_create', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/events/(?P<ref>[^/]+)', array(
		array( 'methods' => 'PUT', 'callback' => 'bsb_events_update', 'permission_callback' => $auth ),
	) );

	// --- content ---
	register_rest_route( 'atlas/v1', '/content/find', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_content_find', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/content/replace', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_content_replace', 'permission_callback' => $auth ),
	) );

	// --- files ---
	register_rest_route( 'atlas/v1', '/files/restore', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_files_restore', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/files/(?P<root>mu-plugins|theme)', array(
		array( 'methods' => 'GET',    'callback' => 'bsb_files_list_or_get', 'permission_callback' => $auth ),
		array( 'methods' => 'PUT',    'callback' => 'bsb_files_put',         'permission_callback' => $auth ),
		array( 'methods' => 'DELETE', 'callback' => 'bsb_files_delete',      'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/events/(?P<ref>[^/]+)/recurrence', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_events_get_recurrence', 'permission_callback' => $auth ),
		array( 'methods' => 'PUT', 'callback' => 'bsb_events_put_recurrence', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/events/(?P<ref>[^/]+)/occurrences', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_events_get_occurrences', 'permission_callback' => $auth ),
	) );

	// --- redirects ---
	register_rest_route( 'atlas/v1', '/redirects', array(
		array( 'methods' => 'GET',    'callback' => 'bsb_redirects_list',   'permission_callback' => $auth ),
		array( 'methods' => 'PUT',    'callback' => 'bsb_redirects_put',    'permission_callback' => $auth ),
		array( 'methods' => 'DELETE', 'callback' => 'bsb_redirects_delete', 'permission_callback' => $auth ),
	) );
	// --- posts ---
	register_rest_route( 'atlas/v1', '/posts', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_posts_create', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/posts/(?P<ref>[^/]+)', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_posts_get',    'permission_callback' => $auth ),
		array( 'methods' => 'PUT', 'callback' => 'bsb_posts_update', 'permission_callback' => $auth ),
	) );
} );

/* ================================================================== *
 * Auth + install diagnostics
 * ================================================================== */

/** The shared secret in play, or '' if none is defined. */
function bsb_secret() {
	foreach ( array( 'BSB_KEY', 'ATLAS_EVENTS_KEY', 'ATLAS_PRLI_KEY' ) as $c ) {
		if ( defined( $c ) && constant( $c ) !== '' ) {
			return (string) constant( $c );
		}
	}
	return '';
}

/** Shared-secret auth via the X-Atlas-Key header (constant-time compare). */
function bsb_auth( WP_REST_Request $req ) {
	$secret = bsb_secret();
	if ( $secret === '' ) {
		return new WP_Error( 'bsb_no_key', 'No shared secret defined (BSB_KEY / ATLAS_EVENTS_KEY / ATLAS_PRLI_KEY)', array( 'status' => 500 ) );
	}
	$key = (string) $req->get_header( 'x-atlas-key' );
	if ( $key === '' || ! hash_equals( $secret, $key ) ) {
		return new WP_Error( 'bsb_forbidden', 'Invalid or missing X-Atlas-Key', array( 'status' => 403 ) );
	}
	return true;
}

/** Fail loudly in wp-admin rather than silently 500ing on the first call. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$problems = array();
	if ( bsb_secret() === '' ) {
		$problems[] = 'no shared secret found — define <code>BSB_KEY</code> in <code>wp-config.php</code>. Every request returns 500 until then.';
	}
	if ( ! function_exists( 'tribe_create_event' ) ) {
		$problems[] = 'The Events Calendar is not active — the events module\'s reads work, writes return 501.';
	}
	if ( bsb_gf_poke_token() === '' ) {
		$problems[] = 'no GF poke token — define <code>ATLAS_GF_POKE_TOKEN</code> or set it via <code>PUT /site/gf-poke-token</code>. Until then the Gravity Forms → Atlas poke is disabled and the GF → Sheets sync falls back to its daily cron.';
	}
	if ( ! $problems ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Bethany Site Bridge:</strong></p><ul style="list-style:disc;margin-left:2em">';
	foreach ( $problems as $p ) {
		echo '<li>' . wp_kses( $p, array( 'code' => array() ) ) . '</li>';
	}
	echo '</ul></div>';
} );

/* ================================================================== *
 * MODULE: updater — one-click updates from the public GitHub repo
 * ================================================================== */

/**
 * Where update checks look. WP 5.8+ reads the "Update URI" plugin header; when its
 * host isn't wordpress.org it fires update_plugins_{host}, which is the hook below.
 * The manifest is served raw from the repo's default branch.
 *
 * The repo is deliberately PUBLIC and contains no credentials — only constant NAMES
 * (BSB_KEY etc.), never values. Keep it that way: never paste a secret into this file.
 */
const BSB_UPDATE_URI      = 'https://github.com/tylerjaycollins/bethany-site-bridge';
const BSB_UPDATE_MANIFEST = 'https://raw.githubusercontent.com/tylerjaycollins/bethany-site-bridge/main/manifest.json';
const BSB_UPDATE_CACHE    = 'bsb_update_manifest';
const BSB_SLUG            = 'bethany-site-bridge';

/** Fetch + cache the release manifest. $force bypasses the cache. */
function bsb_fetch_manifest( $force = false ) {
	if ( ! $force ) {
		$cached = get_site_transient( BSB_UPDATE_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	$res = wp_remote_get( BSB_UPDATE_MANIFEST, array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/json' ),
	) );
	if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
		// Cache the failure briefly so a broken manifest doesn't stall every admin page.
		set_site_transient( BSB_UPDATE_CACHE, array( 'error' => true ), 15 * MINUTE_IN_SECONDS );
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) ) {
		set_site_transient( BSB_UPDATE_CACHE, array( 'error' => true ), 15 * MINUTE_IN_SECONDS );
		return null;
	}
	set_site_transient( BSB_UPDATE_CACHE, $data, 6 * HOUR_IN_SECONDS );
	return $data;
}

/** This plugin's installed version, straight from its own header. */
function bsb_installed_version() {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( __FILE__, false, false );
	return isset( $data['Version'] ) ? $data['Version'] : '0.0.0';
}

/**
 * The update check. Fires for any plugin whose Update URI host is github.com, so the
 * first thing it does is confirm the call is about THIS plugin — otherwise we'd answer
 * on behalf of someone else's GitHub-hosted plugin.
 */
add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file, $locales ) {
	unset( $locales );
	if ( empty( $plugin_data['UpdateURI'] ) || untrailingslashit( $plugin_data['UpdateURI'] ) !== BSB_UPDATE_URI ) {
		return $update;
	}

	$m = bsb_fetch_manifest();
	if ( ! $m || ! empty( $m['error'] ) ) {
		return $update;
	}

	$installed = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : bsb_installed_version();
	if ( version_compare( $m['version'], $installed, '<=' ) ) {
		return false; // up to date — false tells WP "no update", per the Update URI contract
	}

	return array(
		'id'           => BSB_UPDATE_URI,
		'slug'         => BSB_SLUG,
		'plugin'       => $plugin_file,
		'version'      => $m['version'],
		'url'          => BSB_UPDATE_URI,
		'package'      => $m['package'],
		'tested'       => isset( $m['tested'] ) ? $m['tested'] : '',
		'requires_php' => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
	);
}, 10, 4 );

/**
 * Populate the "View version x.y.z details" modal from the manifest, so the changelog
 * is readable before clicking Update.
 */
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== BSB_SLUG ) {
		return $result;
	}
	$m = bsb_fetch_manifest();
	if ( ! $m || ! empty( $m['error'] ) ) {
		return $result;
	}
	return (object) array(
		'name'          => isset( $m['name'] ) ? $m['name'] : 'Bethany Site Bridge',
		'slug'          => BSB_SLUG,
		'version'       => $m['version'],
		'author'        => isset( $m['author'] ) ? $m['author'] : '',
		'homepage'      => BSB_UPDATE_URI,
		'requires'      => isset( $m['requires'] ) ? $m['requires'] : '',
		'requires_php'  => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
		'tested'        => isset( $m['tested'] ) ? $m['tested'] : '',
		'download_link' => $m['package'],
		'sections'      => isset( $m['sections'] ) && is_array( $m['sections'] )
			? $m['sections']
			: array( 'description' => 'REST bridge for bethanycentral.org.' ),
	);
}, 10, 3 );

/**
 * Advertise "up to date" so wp-admin offers the auto-update toggle.
 *
 * WP only shows "Enable auto-updates" for a plugin it finds in the update_plugins
 * transient — under `response` (update pending) or `no_update` (current). But core's
 * Update URI path `continue`s past a plugin whose filter reports no update, so it lands
 * in NEITHER bucket and the Plugins screen says "Auto-updates are not available for this
 * plugin". Adding the no_update entry ourselves is what makes the toggle appear.
 *
 * Deliberately cheap — this fires on every transient read, so no HTTP here.
 */
add_filter( 'site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$file = plugin_basename( __FILE__ );

	// A real pending update wins; don't mask it.
	if ( isset( $transient->response ) && is_array( $transient->response ) && isset( $transient->response[ $file ] ) ) {
		return $transient;
	}
	if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
		$transient->no_update = array();
	}
	if ( isset( $transient->no_update[ $file ] ) ) {
		return $transient;
	}

	$transient->no_update[ $file ] = (object) array(
		'id'           => BSB_UPDATE_URI,
		'slug'         => BSB_SLUG,
		'plugin'       => $file,
		'new_version'  => bsb_installed_version(),
		'url'          => BSB_UPDATE_URI,
		'package'      => '',
		'icons'        => array(),
		'banners'      => array(),
		'banners_rtl'  => array(),
		'tested'       => '',
		'requires_php' => '',
	);
	return $transient;
} );

/** Drop the cached manifest whenever WP finishes updating this plugin. */
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {
	unset( $upgrader );
	if ( isset( $hook_extra['type'] ) && $hook_extra['type'] === 'plugin' ) {
		delete_site_transient( BSB_UPDATE_CACHE );
	}
}, 10, 2 );

/** Update status for the /site report — so a caller can tell it's talking to a stale build. */
function bsb_update_status( $force = false ) {
	$installed = bsb_installed_version();
	$m         = bsb_fetch_manifest( $force );
	if ( ! $m || ! empty( $m['error'] ) ) {
		return array(
			'installed'        => $installed,
			'latest'           => null,
			'update_available' => null,
			'note'             => 'manifest unreachable — check ' . BSB_UPDATE_MANIFEST,
		);
	}
	return array(
		'installed'        => $installed,
		'latest'           => $m['version'],
		'update_available' => version_compare( $m['version'], $installed, '>' ),
		'manifest'         => BSB_UPDATE_MANIFEST,
	);
}

/**
 * POST /site/check-updates — force WordPress to re-run its own plugin update check.
 *
 * WHY THIS EXISTS: **two different caches** decide whether the "Update now" link
 * appears in wp-admin, and refreshing the wrong one looks like the release failed.
 *
 *   1. THIS plugin's manifest cache (BSB_UPDATE_CACHE, 6h) — what
 *      GET /site?refresh_update=1 busts. It only affects what /site *reports*.
 *   2. Core's `update_plugins` site transient — what the Plugins screen actually
 *      reads. Refreshed by wp_update_plugins() on WP-Cron, roughly every 12h, and
 *      WP-Cron is traffic-triggered so it can lag much longer on a quiet site.
 *
 * After the 0.8.0 release /site correctly said `update_available: true` while
 * wp-admin offered no update at all — cache 1 was fresh, cache 2 was stale. The
 * documented workaround is Dashboard → Updates → "Check again", which is a button
 * hunt at the end of every release. This does the same thing over REST so a release
 * can end with "the button is now there" as a verified fact rather than a hope.
 *
 * Drops both caches, runs core's check synchronously, then reports which bucket of
 * the transient this plugin landed in: `response` (update pending → the link is
 * showing) or `no_update` (already current).
 */
function bsb_site_check_updates( WP_REST_Request $req ) {
	unset( $req );
	$file = plugin_basename( __FILE__ );

	delete_site_transient( BSB_UPDATE_CACHE ); // ours, so the manifest is refetched
	delete_site_transient( 'update_plugins' ); // core's, so wp_update_plugins() really runs

	// Always loaded from wp-includes, but this endpoint is worthless if it isn't.
	if ( ! function_exists( 'wp_update_plugins' ) ) {
		require_once ABSPATH . WPINC . '/update.php';
	}
	wp_update_plugins();

	$transient = get_site_transient( 'update_plugins' );
	$pending   = is_object( $transient ) && isset( $transient->response[ $file ] );
	$current   = is_object( $transient ) && isset( $transient->no_update[ $file ] );

	// Read the answer out of core's transient rather than re-deriving it from the
	// manifest: the question being asked is "does wp-admin show the button", and
	// only the transient can answer that.
	$offered = null;
	if ( $pending && isset( $transient->response[ $file ]->new_version ) ) {
		$offered = (string) $transient->response[ $file ]->new_version;
	}

	return rest_ensure_response( array(
		'checked'          => true,
		'plugin_file'      => $file,
		'update_offered'   => $pending,
		'offered_version'  => $offered,
		'listed_as_current'=> $current,
		'status'           => bsb_update_status( true ),
		'note'             => $pending
			? 'wp-admin is now offering the update — Plugins → Update now.'
			: ( $current
				? 'Core reports this plugin as already current; nothing to install.'
				: 'This plugin is in NEITHER bucket of the update transient — core is not tracking it. Check the Update URI header and that the site can reach the manifest host.' ),
	) );
}

/* ================================================================== *
 * Shared helpers
 * ================================================================== */

function bsb_occurrences_table() {
	global $wpdb;
	return $wpdb->prefix . 'tec_occurrences';
}

function bsb_has_occurrences_table() {
	global $wpdb;
	$t = bsb_occurrences_table();
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
}

/**
 * Resolve a post ID / TEC provisional occurrence ID / slug to a real post ID.
 * $post_type limits a slug lookup; pass '' to search any type.
 * Returns array( post_id, was_provisional ) or WP_Error.
 */
function bsb_resolve( $ref, $post_type = '' ) {
	global $wpdb;
	$ref = (string) $ref;

	if ( ctype_digit( $ref ) ) {
		$id = (int) $ref;

		if ( $id < BSB_PROVISIONAL_FLOOR ) {
			if ( ! get_post( $id ) ) {
				return new WP_Error( 'bsb_not_found', "No post with ID $id", array( 'status' => 404 ) );
			}
			if ( $post_type !== '' && get_post_type( $id ) !== $post_type ) {
				return new WP_Error( 'bsb_wrong_type', "Post $id is not a $post_type", array( 'status' => 400 ) );
			}
			return array( $id, false );
		}

		// Provisional occurrence ID. Map through the occurrences table without
		// depending on TEC's internal provisional-ID base — try each plausible one.
		if ( ! bsb_has_occurrences_table() ) {
			return new WP_Error( 'bsb_no_occ_table', 'tec_occurrences table not found — cannot resolve a provisional ID on this TEC version', array( 'status' => 501 ) );
		}
		$t = bsb_occurrences_table();
		foreach ( array( 10000000, 100000000, 1000000000 ) as $base ) {
			$occ_id = $id - $base;
			if ( $occ_id <= 0 ) {
				continue;
			}
			$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM `$t` WHERE occurrence_id = %d", $occ_id ) );
			if ( $post_id && get_post( $post_id ) ) {
				return array( $post_id, true );
			}
		}
		return new WP_Error( 'bsb_unresolved', "Could not resolve provisional ID $ref to a parent post", array( 'status' => 404 ) );
	}

	// Slug.
	$slug = sanitize_title( $ref );
	$sql  = "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status NOT IN ('trash','auto-draft')";
	$args = array( $slug );
	if ( $post_type !== '' ) {
		$sql   .= ' AND post_type = %s';
		$args[] = $post_type;
	}
	$sql    .= ' ORDER BY ID ASC LIMIT 1';
	$post_id = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	if ( $post_id ) {
		return array( $post_id, false );
	}
	return new WP_Error( 'bsb_not_found', "No post with slug \"$slug\"" . ( $post_type ? " of type $post_type" : '' ), array( 'status' => 404 ) );
}

/* ================================================================== *
 * MODULE: site — read-only introspection
 * ================================================================== */

/**
 * What is this site actually running? Cheaper and more reliable than inferring it
 * from REST probes and rendered-page markup.
 */
function bsb_site_report( WP_REST_Request $req ) {
	global $wp_version, $wpdb;

	$plugins = array();
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all    = get_plugins();
	$active = (array) get_option( 'active_plugins', array() );
	foreach ( $all as $file => $data ) {
		if ( ! in_array( $file, $active, true ) && ! is_plugin_active_for_network( $file ) ) {
			continue;
		}
		$plugins[] = array(
			'name'    => $data['Name'],
			'version' => $data['Version'],
			'file'    => $file,
		);
	}
	usort( $plugins, function ( $a, $b ) {
		return strcasecmp( $a['name'], $b['name'] );
	} );

	$types = array();
	foreach ( get_post_types( array(), 'objects' ) as $pt ) {
		$types[] = array(
			'name'        => $pt->name,
			'label'       => $pt->label,
			'public'      => (bool) $pt->public,
			'show_in_rest' => (bool) $pt->show_in_rest,
			'rest_base'   => $pt->rest_base ? $pt->rest_base : $pt->name,
		);
	}

	$taxes = array();
	foreach ( get_taxonomies( array(), 'objects' ) as $tx ) {
		$taxes[] = array(
			'name'         => $tx->name,
			'label'        => $tx->label,
			'object_type'  => $tx->object_type,
			'show_in_rest' => (bool) $tx->show_in_rest,
		);
	}

	// Which meta keys are REST-exposed for a given post type — the whitelist that
	// determines whether wp/v2 can touch a field at all.
	$meta_for = (string) $req->get_param( 'meta_for' );
	$exposed  = null;
	if ( $meta_for !== '' ) {
		$exposed = array();
		foreach ( (array) get_registered_meta_keys( 'post', $meta_for ) as $key => $cfg ) {
			$exposed[] = array(
				'key'          => $key,
				'type'         => isset( $cfg['type'] ) ? $cfg['type'] : null,
				'single'       => ! empty( $cfg['single'] ),
				'show_in_rest' => ! empty( $cfg['show_in_rest'] ),
			);
		}
	}

	$theme = wp_get_theme();

	return rest_ensure_response( array(
		'site' => array(
			'name'        => get_bloginfo( 'name' ),
			'home_url'    => home_url(),
			'site_url'    => site_url(),
			'wp_version'  => $wp_version,
			'php_version' => PHP_VERSION,
			'mysql'       => $wpdb->db_version(),
			'timezone'    => wp_timezone_string(),
			'locale'      => get_locale(),
			'is_multisite' => is_multisite(),
			'permalink_structure' => get_option( 'permalink_structure' ),
		),
		'theme' => array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
		),
		'bridge' => array(
			'version'        => bsb_installed_version(),
			'secret_defined' => bsb_secret() !== '',
			'modules'        => array( 'site', 'meta', 'options', 'events', 'redirects', 'content', 'posts', 'files', 'tweaks', 'updater' ),
			'redirect_rules' => count( bsb_redirects_all() ),
			// Where the GF poke token is coming from — never the value itself.
			'gf_poke_token'  => ( defined( 'ATLAS_GF_POKE_TOKEN' ) && ATLAS_GF_POKE_TOKEN !== '' )
				? 'constant'
				: ( bsb_option_read( 'bsb_gf_poke_token' ) !== '' ? 'option' : null ),
			// ?refresh_update=1 bypasses the 6h manifest cache.
			'update'         => bsb_update_status(
				filter_var( $req->get_param( 'refresh_update' ), FILTER_VALIDATE_BOOLEAN )
			),
		),
		'capabilities' => array(
			'tec_active'            => function_exists( 'tribe_create_event' ),
			'tec_recurrence_writes' => function_exists( 'tribe_create_event' ) && function_exists( 'tribe_update_event' ),
			'tec_occurrences_table' => bsb_has_occurrences_table(),
			'acf_active'            => function_exists( 'get_field' ),
			'pretty_links_bridge'   => function_exists( 'atlas_prli_auth' ),
			'hummingbird_page_cache'=> has_action( 'wphb_clear_page_cache' ) !== false,
			'files'                 => bsb_files_capabilities(),
			'opcache'               => function_exists( 'opcache_reset' ),
			'object_cache'          => wp_using_ext_object_cache(),
		),
		'active_plugins' => $plugins,
		'post_types'     => $types,
		'taxonomies'     => $taxes,
		'registered_meta' => $exposed, // null unless ?meta_for=<post_type>
	) );
}


/* ------------------------------------------------------------------ *
 * site: operations that used to be a wp-admin click
 * ------------------------------------------------------------------ */

/**
 * POST /site/update-plugin {plugin?, confirm} — install an update WordPress is already
 * offering. Defaults to THIS plugin, which closes the last manual step in a release:
 * tag → CI zip → check-updates → (this) → verify /site reports the new version.
 *
 * Mirrors wp-admin's own "Update now" (wp_ajax_update_plugin): refresh the update
 * transient, require the plugin to be in `response`, then Plugin_Upgrader::bulk_upgrade()
 * — bulk_upgrade, not upgrade(), because upgrade() deactivates the plugin first and
 * relies on the admin page to offer re-activation, while bulk_upgrade swaps the files
 * in place and keeps an active plugin active. Only ever installs what core is already
 * offering; it cannot be pointed at an arbitrary zip.
 */
function bsb_site_update_plugin( WP_REST_Request $req ) {
	$file = trim( (string) $req->get_param( 'plugin' ) );
	if ( $file === '' ) {
		$file = plugin_basename( __FILE__ );
	}
	if ( ! preg_match( '#^[a-z0-9_.\-]+/[a-z0-9_.\-]+\.php$#i', $file ) ) {
		return new WP_Error( 'bsb_bad_plugin', '"plugin" must be a plugin file like folder/plugin.php', array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! function_exists( 'wp_update_plugins' ) ) {
		require_once ABSPATH . WPINC . '/update.php';
	}
	if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
		return new WP_Error( 'bsb_not_installed', "No installed plugin at $file", array( 'status' => 404 ) );
	}

	delete_site_transient( BSB_UPDATE_CACHE );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$transient = get_site_transient( 'update_plugins' );

	$before  = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
	$offered = ( is_object( $transient ) && isset( $transient->response[ $file ]->new_version ) )
		? (string) $transient->response[ $file ]->new_version : null;
	$package = ( $offered && isset( $transient->response[ $file ]->package ) ) ? (string) $transient->response[ $file ]->package : null;

	$summary = array(
		'plugin'    => $file,
		'name'      => isset( $before['Name'] ) ? $before['Name'] : null,
		'installed' => isset( $before['Version'] ) ? $before['Version'] : null,
		'offered'   => $offered,
		'package'   => $package,
		'active'    => is_plugin_active( $file ),
	);
	if ( ! $offered ) {
		return rest_ensure_response( array_merge( array( 'updated' => false, 'note' => 'WordPress is not offering an update for this plugin — nothing to install' ), $summary ) );
	}
	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array_merge( array( 'dry_run' => true, 'note' => 'confirm=true was not passed — nothing installed' ), $summary ) );
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$results  = $upgrader->bulk_upgrade( array( $file ) );
	$result   = is_array( $results ) && array_key_exists( $file, $results ) ? $results[ $file ] : null;

	$errors = array();
	foreach ( (array) $skin->get_errors()->get_error_messages() as $m ) {
		$errors[] = (string) $m;
	}
	if ( is_wp_error( $result ) ) {
		$errors[] = $result->get_error_message();
	}

	wp_clean_plugins_cache( true );
	delete_site_transient( BSB_UPDATE_CACHE );
	$after   = file_exists( WP_PLUGIN_DIR . '/' . $file ) ? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false ) : array();
	$now     = isset( $after['Version'] ) ? $after['Version'] : null;
	$ok      = $now !== null && $offered !== null && version_compare( $now, $offered, '>=' );

	return rest_ensure_response( array_merge( array(
		'updated'         => $ok,
		'installed_after' => $now,
		'active_after'    => is_plugin_active( $file ),
		'upgrader_result' => is_wp_error( $result ) ? 'error' : ( $result === true ? 'ok' : ( $result === false ? 'false' : ( $result === null ? 'null' : 'array' ) ) ),
		'errors'          => $errors,
		'messages'        => array_values( array_filter( array_map( 'strval', (array) $skin->get_upgrade_messages() ) ) ),
		'note'            => $file === plugin_basename( __FILE__ )
			? 'Self-update: the version that answers the NEXT request is the truth — re-read GET /site.'
			: null,
	), $summary ) );
}

/**
 * POST /site/purge-cache {layers?, probe?} — clear the caches that swallow REST edits.
 *
 * Every content change through REST has run into this: Hummingbird's page cache and
 * the host's static server cache both keep serving the old render until someone
 * clears them in wp-admin. Layers, each reported as cleared / unavailable:
 *   page     Hummingbird page cache (wphb_clear_page_cache) — also fires Hummingbird's
 *            integration hook, which on WPMU DEV hosting is what reaches the static cache
 *   static   the hosting static server cache, via whichever Hummingbird/Dashboard API
 *            method is present (probe=1 lists candidates without clearing anything)
 *   object   wp_cache_flush()
 *   opcache  opcache_reset()
 * Default is every layer. A wrong guess here costs a re-render, so no confirm gate.
 */
function bsb_site_purge_cache( WP_REST_Request $req ) {
	$all    = array( 'page', 'static', 'object', 'opcache' );
	$want   = $req->get_param( 'layers' );
	$layers = is_array( $want ) && $want ? array_values( array_intersect( $all, array_map( 'strval', $want ) ) ) : $all;
	$probe  = filter_var( $req->get_param( 'probe' ), FILTER_VALIDATE_BOOLEAN );

	// What cache-ish machinery is present. Cheap, read-only; drives `static` below and
	// tells the caller what the next iteration of this endpoint should call by name.
	$found = array(
		'wphb_clear_page_cache_listeners' => has_action( 'wphb_clear_page_cache' ) !== false,
		'wphb_clear_cache_url_listeners'  => has_action( 'wphb_clear_cache_url' ) !== false,
		'hummingbird_hosting_api'         => array(),
		'wpmudev_dashboard_api'           => array(),
		'functions'                       => array(),
	);
	if ( class_exists( '\Hummingbird\Core\Utils' ) && method_exists( '\Hummingbird\Core\Utils', 'get_api' ) ) {
		$api = \Hummingbird\Core\Utils::get_api();
		if ( is_object( $api ) && isset( $api->hosting ) && is_object( $api->hosting ) ) {
			$found['hummingbird_hosting_api'] = array_values( preg_grep( '/cache|purge|clear|static|fast_cgi/i', get_class_methods( $api->hosting ) ) );
		}
	}
	if ( class_exists( 'WPMUDEV_Dashboard' ) && isset( \WPMUDEV_Dashboard::$api ) && is_object( \WPMUDEV_Dashboard::$api ) ) {
		$found['wpmudev_dashboard_api'] = array_values( preg_grep( '/cache|purge|static|hosting/i', get_class_methods( \WPMUDEV_Dashboard::$api ) ) );
	}
	foreach ( array( 'wphb_clear_static_cache', 'wpmudev_hosting_purge_static_cache', 'wpmudev_purge_static_cache' ) as $fn ) {
		if ( function_exists( $fn ) ) {
			$found['functions'][] = $fn;
		}
	}
	if ( $probe ) {
		return rest_ensure_response( array( 'probe' => true, 'found' => $found, 'note' => 'nothing cleared' ) );
	}

	$done = array();
	if ( in_array( 'page', $layers, true ) ) {
		if ( $found['wphb_clear_page_cache_listeners'] ) {
			do_action( 'wphb_clear_page_cache' );
			$done['page'] = 'cleared (wphb_clear_page_cache)';
		} else {
			$done['page'] = 'unavailable — nothing listens on wphb_clear_page_cache (Hummingbird page caching off?)';
		}
	}
	if ( in_array( 'static', $layers, true ) ) {
		$done['static'] = 'unavailable — no static-cache purge method found; run with probe=1 and wire the named method';
		$tried = array();
		foreach ( $found['functions'] as $fn ) {
			$tried[] = $fn;
			call_user_func( $fn );
			$done['static'] = "cleared ($fn)";
			break;
		}
		if ( strpos( $done['static'], 'cleared' ) !== 0 && $found['hummingbird_hosting_api'] ) {
			$api = \Hummingbird\Core\Utils::get_api();
			foreach ( array( 'purge_static_cache', 'clear_static_cache', 'purge_cache', 'clear_cache', 'clear_fast_cgi_cache', 'purge_fast_cgi_cache' ) as $m ) {
				if ( in_array( $m, $found['hummingbird_hosting_api'], true ) ) {
					$tried[] = "hosting->$m";
					$r = call_user_func( array( $api->hosting, $m ) );
					$done['static'] = is_wp_error( $r ) ? "error (hosting->$m): " . $r->get_error_message() : "cleared (hosting->$m)";
					break;
				}
			}
		}
		if ( strpos( $done['static'], 'cleared' ) !== 0 && $found['wphb_clear_cache_url_listeners'] ) {
			do_action( 'wphb_clear_cache_url' );
			$tried[] = 'wphb_clear_cache_url';
			$done['static'] = 'fired wphb_clear_cache_url (Hummingbird integrations hook) — verify with a fresh GET; no direct static-cache API found';
		}
		$done['static_tried'] = $tried;
	}
	if ( in_array( 'object', $layers, true ) ) {
		$done['object'] = wp_cache_flush() ? 'cleared (wp_cache_flush)' : 'wp_cache_flush returned false';
	}
	if ( in_array( 'opcache', $layers, true ) ) {
		$done['opcache'] = function_exists( 'opcache_reset' ) ? ( @opcache_reset() ? 'cleared (opcache_reset)' : 'opcache_reset returned false (restricted?)' ) : 'unavailable';
	}
	return rest_ensure_response( array( 'purged' => true, 'layers' => $done, 'found' => $found ) );
}

/** POST /site/flush-rewrites — what Settings → Permalinks → Save does, without the page. */
function bsb_site_flush_rewrites( WP_REST_Request $req ) {
	unset( $req );
	$before = (array) get_option( 'rewrite_rules', array() );
	flush_rewrite_rules( false );
	$after = (array) get_option( 'rewrite_rules', array() );
	return rest_ensure_response( array(
		'flushed'      => true,
		'rules_before' => count( $before ),
		'rules_after'  => count( $after ),
		'added'        => array_values( array_diff( array_keys( $after ), array_keys( $before ) ) ),
		'removed'      => array_values( array_diff( array_keys( $before ), array_keys( $after ) ) ),
	) );
}

/* ================================================================== *
 * MODULE: meta — the general escape hatch
 * ================================================================== */

/** Meta keys that must never be written here, with the reason. */
function bsb_meta_blocked() {
	return array(
		'_EventRecurrence' => 'Writing this directly does not regenerate occurrences — use PUT /events/{ref}/recurrence instead.',
		'_edit_lock'       => 'WordPress internal.',
		'_edit_last'       => 'WordPress internal.',
	);
}

function bsb_meta_get( WP_REST_Request $req ) {
	$resolved = bsb_resolve( $req->get_param( 'ref' ) );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post_id, $was_provisional ) = $resolved;

	$wanted = array_filter( array_map( 'trim', explode( ',', (string) $req->get_param( 'keys' ) ) ) );
	$all    = get_post_meta( $post_id );
	$out    = array();
	foreach ( (array) $all as $key => $values ) {
		if ( $wanted && ! in_array( $key, $wanted, true ) ) {
			continue;
		}
		$decoded = array_map( 'maybe_unserialize', (array) $values );
		$out[ $key ] = count( $decoded ) === 1 ? $decoded[0] : $decoded;
	}
	ksort( $out );

	return rest_ensure_response( array(
		'post_id'             => $post_id,
		'post_type'           => get_post_type( $post_id ),
		'slug'                => get_post_field( 'post_name', $post_id ),
		'title'               => get_the_title( $post_id ),
		'ref_was_provisional' => $was_provisional,
		'meta'                => $out,
	) );
}

function bsb_meta_put( WP_REST_Request $req ) {
	$resolved = bsb_resolve( $req->get_param( 'ref' ) );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post_id, $was_provisional ) = $resolved;

	$values = $req->get_param( 'values' );
	if ( ! is_array( $values ) || ! $values ) {
		return new WP_Error( 'bsb_no_values', 'A "values" object of key => value pairs is required', array( 'status' => 400 ) );
	}

	$blocked = bsb_meta_blocked();
	foreach ( array_keys( $values ) as $key ) {
		if ( isset( $blocked[ $key ] ) ) {
			return new WP_Error( 'bsb_blocked_key', "Refusing to write \"$key\": " . $blocked[ $key ], array( 'status' => 409 ) );
		}
	}

	// A provisional ref means the caller is holding an occurrence but the meta
	// lives on the parent — exactly the confusion behind the 2026-08-10 incident.
	if ( $was_provisional && $req->get_param( 'apply_to' ) !== 'series' ) {
		return new WP_Error( 'bsb_provisional_ref', sprintf(
			'That is a provisional OCCURRENCE id — its meta lives on parent post %d and a write affects the whole series. Pass apply_to=series to confirm, or reference the slug "%s".',
			$post_id, get_post_field( 'post_name', $post_id )
		), array( 'status' => 409 ) );
	}

	$before = array();
	foreach ( array_keys( $values ) as $key ) {
		$before[ $key ] = get_post_meta( $post_id, $key, true );
	}

	$dry = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );
	if ( $dry || ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array(
			'dry_run'   => true,
			'post_id'   => $post_id,
			'note'      => $dry ? 'dry_run=true — nothing written' : 'confirm=true was not passed — nothing written',
			'before'    => $before,
			'would_set' => $values,
		) );
	}

	// wp_slash() first: update_post_meta() expects slashed input (it wp_unslash()es),
	// and REST JSON params arrive unslashed. Without it every backslash in a value
	// vanished on write — Cornerstone's JSON-in-a-string meta landed corrupted and the
	// page rendered empty (found 2026-09-03).
	$after = array();
	foreach ( $values as $key => $value ) {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
		$after[ $key ] = get_post_meta( $post_id, $key, true );
	}

	return rest_ensure_response( array(
		'updated' => true,
		'post_id' => $post_id,
		'before'  => $before,
		'after'   => $after,
	) );
}

/* ================================================================== *
 * MODULE: options — wp_options, the other escape hatch
 * ================================================================== *
 *
 * Plugin settings screens are just options. Quick Page/Post Redirect's rule list,
 * SmartCrawl's settings, Hummingbird toggles, TEC display options — all reachable
 * here without a wp-admin session. Same shape as /meta: GET unserializes, PUT takes
 * {value} (any JSON — arrays are stored serialized like WP does itself), requires
 * confirm=true, echoes before/after read back from the option table.
 *
 * BLOCKLIST — two kinds. Options that can take the site down or lock everyone out
 * are refused for write (and delete): siteurl/home, active_plugins, roles, the
 * theme pair, admin_email, cron. Options whose NAME looks like a secret are refused
 * for read AND write, so a listing can never leak a key through this channel; the
 * bridge's own gf-poke token has its own endpoint for exactly that reason.
 */

function bsb_options_blocked_write() {
	return array(
		'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet',
		'wp_user_roles', 'admin_email', 'users_can_register', 'default_role', 'cron', 'db_version',
		'initial_db_version', 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major',
	);
}

function bsb_options_is_secret( $name ) {
	return (bool) preg_match( '/(secret|token|password|passwd|api_?key|private_?key|_key$|^key_|nonce|salt|(^|_)auth(_|$))/i', $name );
}

function bsb_options_name( WP_REST_Request $req ) {
	$name = trim( (string) $req->get_param( 'name' ) );
	if ( $name === '' || strlen( $name ) > 191 || preg_match( '/[^\w\-.:@\/]/', $name ) ) {
		return new WP_Error( 'bsb_bad_option', 'Option name is missing or contains characters an option name never has', array( 'status' => 400 ) );
	}
	return $name;
}

/** Unserialize-safe read straight from the table so a poisoned cache can't lie. */
function bsb_options_row( $name ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ), ARRAY_A );
	if ( ! $row ) {
		return null;
	}
	return array( 'value' => maybe_unserialize( $row['option_value'] ), 'autoload' => $row['autoload'], 'bytes' => strlen( $row['option_value'] ) );
}

/** GET /options?search=term — names only (plus size and autoload), for finding the right option. */
function bsb_options_search( WP_REST_Request $req ) {
	global $wpdb;
	$term = trim( (string) $req->get_param( 'search' ) );
	if ( strlen( $term ) < 2 ) {
		return new WP_Error( 'bsb_short_search', 'Pass ?search= with at least 2 characters', array( 'status' => 400 ) );
	}
	$limit = min( 200, max( 1, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options}
		 WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d",
		'%' . $wpdb->esc_like( $term ) . '%', '\_transient\_%', $limit
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array(
			'name'     => $r['option_name'],
			'autoload' => $r['autoload'],
			'bytes'    => (int) $r['bytes'],
			'readable' => ! bsb_options_is_secret( $r['option_name'] ),
			'writable' => ! bsb_options_is_secret( $r['option_name'] ) && ! in_array( $r['option_name'], bsb_options_blocked_write(), true ),
		);
	}
	return rest_ensure_response( array( 'count' => count( $out ), 'options' => $out, 'note' => 'transients are excluded' ) );
}

function bsb_options_get( WP_REST_Request $req ) {
	$name = bsb_options_name( $req );
	if ( is_wp_error( $name ) ) {
		return $name;
	}
	if ( bsb_options_is_secret( $name ) ) {
		return new WP_Error( 'bsb_secret_option', "Refusing to read \"$name\": its name looks like a credential", array( 'status' => 403 ) );
	}
	$row = bsb_options_row( $name );
	return rest_ensure_response( array(
		'name'     => $name,
		'exists'   => $row !== null,
		'value'    => $row ? $row['value'] : null,
		'type'     => $row ? gettype( $row['value'] ) : null,
		'autoload' => $row ? $row['autoload'] : null,
		'bytes'    => $row ? $row['bytes'] : 0,
		'writable' => ! in_array( $name, bsb_options_blocked_write(), true ),
	) );
}

/** PUT /options/{name} {value, confirm, dry_run, autoload?} — write one option, verified against the row. */
function bsb_options_put( WP_REST_Request $req ) {
	$name = bsb_options_name( $req );
	if ( is_wp_error( $name ) ) {
		return $name;
	}
	if ( bsb_options_is_secret( $name ) ) {
		return new WP_Error( 'bsb_secret_option', "Refusing to write \"$name\": its name looks like a credential — use the dedicated endpoint if one exists", array( 'status' => 403 ) );
	}
	if ( in_array( $name, bsb_options_blocked_write(), true ) ) {
		return new WP_Error( 'bsb_blocked_option', "Refusing to write \"$name\": it can take the site down or lock everyone out", array( 'status' => 409 ) );
	}
	$params = $req->get_json_params();
	if ( ! is_array( $params ) || ! array_key_exists( 'value', $params ) ) {
		return new WP_Error( 'bsb_no_value', 'A JSON body with a "value" key is required (null is allowed, absence is not)', array( 'status' => 400 ) );
	}
	$value = $params['value'];
	if ( is_object( $value ) ) {
		$value = json_decode( wp_json_encode( $value ), true );
	}
	$before = bsb_options_row( $name );

	$dry = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );
	if ( $dry || ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array(
			'dry_run'   => true,
			'name'      => $name,
			'note'      => $dry ? 'dry_run=true — nothing written' : 'confirm=true was not passed — nothing written',
			'exists'    => $before !== null,
			'before'    => $before ? $before['value'] : null,
			'would_set' => $value,
		) );
	}

	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( $name, 'options' );
	$autoload = $req->get_param( 'autoload' );
	if ( $before === null ) {
		add_option( $name, $value, '', $autoload === null ? false : (bool) filter_var( $autoload, FILTER_VALIDATE_BOOLEAN ) );
	} else {
		update_option( $name, $value, $autoload === null ? null : (bool) filter_var( $autoload, FILTER_VALIDATE_BOOLEAN ) );
	}
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( $name, 'options' );
	$after     = bsb_options_row( $name );
	$persisted = $after !== null && maybe_serialize( $after['value'] ) === maybe_serialize( $value );
	if ( ! $persisted ) {
		return new WP_Error( 'bsb_write_failed', 'The option row does not hold the value after writing', array(
			'status' => 500,
			'before' => $before ? $before['value'] : null,
			'after'  => $after ? $after['value'] : null,
		) );
	}
	return rest_ensure_response( array(
		'updated'   => true,
		'persisted' => true,
		'name'      => $name,
		'created'   => $before === null,
		'before'    => $before ? $before['value'] : null,
		'after'     => $after['value'],
		'autoload'  => $after['autoload'],
	) );
}

/** DELETE /options/{name} {confirm} */
function bsb_options_delete( WP_REST_Request $req ) {
	$name = bsb_options_name( $req );
	if ( is_wp_error( $name ) ) {
		return $name;
	}
	if ( bsb_options_is_secret( $name ) || in_array( $name, bsb_options_blocked_write(), true ) ) {
		return new WP_Error( 'bsb_blocked_option', "Refusing to delete \"$name\"", array( 'status' => 409 ) );
	}
	$before = bsb_options_row( $name );
	if ( $before === null ) {
		return new WP_Error( 'bsb_not_found', "No option named \"$name\"", array( 'status' => 404 ) );
	}
	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array( 'dry_run' => true, 'note' => 'confirm=true was not passed — nothing deleted', 'name' => $name, 'before' => $before['value'] ) );
	}
	delete_option( $name );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( $name, 'options' );
	$gone = bsb_options_row( $name ) === null;
	return rest_ensure_response( array( 'deleted' => $gone, 'persisted' => $gone, 'name' => $name, 'before' => $before['value'] ) );
}

/* ================================================================== *
 * MODULE: events — recurrence + "will not occur" dates
 * ================================================================== */

/** Every generated occurrence for a series, oldest first. */
function bsb_events_occurrences_for( $post_id ) {
	global $wpdb;
	if ( ! bsb_has_occurrences_table() ) {
		return null;
	}
	$t    = bsb_occurrences_table();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT occurrence_id, start_date, end_date FROM `$t` WHERE post_id = %d ORDER BY start_date ASC",
		$post_id
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array(
			'occurrence_id' => (int) $r['occurrence_id'],
			'start_date'    => $r['start_date'],
			'end_date'      => $r['end_date'],
			'date'          => substr( (string) $r['start_date'], 0, 10 ),
		);
	}
	return $out;
}

/**
 * Wait for TEC to finish regenerating occurrences after a write, then report.
 *
 * Why this exists: TEC 6 rebuilds the occurrence table AFTER the write returns, so
 * reading it back immediately reports the OLD state. On 2026-08-11 the Awana repair
 * came back "after_count: 5, matches_preview: false" while the write had in fact
 * fully succeeded (30 occurrences, verified seconds later). A false failure is worse
 * than no report — it invites a retry, and retries against recurrence are how the
 * 2026-08-10 damage happened. So poll until the table matches, bounded.
 *
 * Returns array( occurrences, settled_bool, waited_ms ).
 */
function bsb_events_settle( $post_id, array $expected, $tries = 12, $wait_us = 400000 ) {
	$waited = 0;
	for ( $i = 0; $i <= $tries; $i++ ) {
		$occ   = bsb_events_occurrences_for( $post_id );
		$dates = is_array( $occ ) ? wp_list_pluck( $occ, 'date' ) : null;
		if ( $dates !== null && $dates === $expected ) {
			return array( $occ, true, (int) round( $waited / 1000 ) );
		}
		if ( $i < $tries ) {
			usleep( $wait_us );
			$waited += $wait_us;
		}
	}
	return array( bsb_events_occurrences_for( $post_id ), false, (int) round( $waited / 1000 ) );
}

function bsb_weekday_number( $name ) {
	$map = array(
		'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
		'friday' => 5, 'saturday' => 6, 'sunday' => 7,
	);
	$k = strtolower( trim( (string) $name ) );
	return isset( $map[ $k ] ) ? $map[ $k ] : null;
}

/**
 * A TEC "Custom > Date" single-date entry — used for extra dates AND exclusions.
 *
 * Shape calibrated 2026-08-11 against the real _EventRecurrence on this site
 * (TEC 6.17.2 / TEC Pro 7.8.0, read via GET /events/awana/recurrence). Every rule
 * and exclusion carries the SERIES start/end datetimes plus isOffStart — not the
 * entry's own date. Omitting those was the main gap in the first draft.
 */
function bsb_date_entry( $ymd, $series_start, $series_end, $with_times = false ) {
	$custom = array(
		'date' => array( 'date' => $ymd ),
	);

	// Additional-date RULES carry explicit times in the real meta ("same-time":"no"
	// + start-time/end-time/end-day), which is what TEC's own UI writes — see the
	// MOMents spring-2026 series. EXCLUSIONS are date-only and use "same-time":"yes"
	// (proven on the Awana repair). Matching each shape rather than assuming one
	// works for both.
	if ( $with_times ) {
		$custom['same-time']  = 'no';
		$custom['start-time'] = gmdate( 'g:ia', strtotime( $series_start ) );
		$custom['end-time']   = gmdate( 'g:ia', strtotime( $series_end ) );
		$custom['end-day']    = '0';
	} else {
		$custom['same-time'] = 'yes';
	}

	$custom['type']     = 'Date';
	$custom['interval'] = 1;

	return array(
		'type'           => 'Custom',
		'custom'         => $custom,
		'EventStartDate' => $series_start,
		'EventEndDate'   => $series_end,
		'isOffStart'     => false,
	);
}

/**
 * Returns array( recurrence_array, WP_Error|null ).
 * $series_start/$series_end are full 'Y-m-d H:i:s' datetimes for the series.
 */
function bsb_build_recurrence( array $spec, $series_start, $series_end ) {
	$rules      = array();
	$exclusions = array();

	$freq = isset( $spec['freq'] ) ? strtolower( (string) $spec['freq'] ) : '';

	if ( $freq !== '' ) {
		// Interval and weekday numbers are STRINGS in the real meta ("1", ["3"]).
		$interval = isset( $spec['interval'] ) ? max( 1, (int) $spec['interval'] ) : 1;
		$custom   = array( 'interval' => (string) $interval, 'same-time' => 'yes' );

		if ( $freq === 'weekly' ) {
			$days = array();
			foreach ( (array) ( isset( $spec['weekdays'] ) ? $spec['weekdays'] : array() ) as $d ) {
				$n = bsb_weekday_number( $d );
				if ( $n === null ) {
					return array( null, new WP_Error( 'bsb_bad_weekday', "Unrecognized weekday \"$d\"", array( 'status' => 400 ) ) );
				}
				$days[] = (string) $n;
			}
			if ( ! $days ) {
				return array( null, new WP_Error( 'bsb_no_weekday', 'weekly recurrence needs at least one entry in "weekdays"', array( 'status' => 400 ) ) );
			}
			$custom['week'] = array( 'day' => $days );
			$custom['type'] = 'Weekly';
		} elseif ( $freq === 'monthly' ) {
			$custom['month'] = array(
				'number' => isset( $spec['month_number'] ) ? (string) $spec['month_number'] : '1',
				'day'    => isset( $spec['month_weekday'] ) ? (string) bsb_weekday_number( $spec['month_weekday'] ) : '',
			);
			$custom['type']  = 'Monthly';
		} else {
			return array( null, new WP_Error( 'bsb_bad_freq', 'freq must be "weekly" or "monthly"', array( 'status' => 400 ) ) );
		}

		$rule = array( 'type' => 'Custom', 'custom' => $custom );

		// The real meta omits end-count entirely when end-type is "On" — match it
		// rather than sending an empty string.
		if ( ! empty( $spec['until'] ) ) {
			$rule['end-type'] = 'On';
			$rule['end']      = (string) $spec['until'];
		} elseif ( ! empty( $spec['count'] ) ) {
			$rule['end-type']  = 'After';
			$rule['end-count'] = (int) $spec['count'];
		} else {
			return array( null, new WP_Error( 'bsb_no_end', 'recurrence needs either "until" or "count"', array( 'status' => 400 ) ) );
		}

		$rule['EventStartDate'] = $series_start;
		$rule['EventEndDate']   = $series_end;
		$rule['isOffStart']     = false;

		$rules[] = $rule;
	}

	// Explicit additional dates — the clean way to express an irregular series.
	foreach ( (array) ( isset( $spec['dates'] ) ? $spec['dates'] : array() ) as $ymd ) {
		$ymd = substr( (string) $ymd, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return array( null, new WP_Error( 'bsb_bad_date', "Bad date \"$ymd\" in dates (want YYYY-MM-DD)", array( 'status' => 400 ) ) );
		}
		$rules[] = bsb_date_entry( $ymd, $series_start, $series_end, true );
	}

	// "Will not occur" dates.
	foreach ( (array) ( isset( $spec['exclusions'] ) ? $spec['exclusions'] : array() ) as $ymd ) {
		$ymd = substr( (string) $ymd, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return array( null, new WP_Error( 'bsb_bad_date', "Bad date \"$ymd\" in exclusions (want YYYY-MM-DD)", array( 'status' => 400 ) ) );
		}
		$exclusions[] = bsb_date_entry( $ymd, $series_start, $series_end );
	}

	if ( ! $rules ) {
		return array( null, new WP_Error( 'bsb_no_rules', 'recurrence needs "freq" or a non-empty "dates" list', array( 'status' => 400 ) ) );
	}

	return array(
		array( 'rules' => $rules, 'exclusions' => $exclusions, 'description' => null ),
		null,
	);
}

/**
 * Independently compute the dates the spec describes, in plain PHP, so a dry run
 * can be checked WITHOUT trusting TEC to agree. If TEC's real output later differs
 * from this, that mismatch is itself the finding.
 */
function bsb_preview_dates( array $spec, $start_ymd ) {
	$dates = array();
	$freq  = isset( $spec['freq'] ) ? strtolower( (string) $spec['freq'] ) : '';

	if ( $freq === 'weekly' ) {
		$interval = isset( $spec['interval'] ) ? max( 1, (int) $spec['interval'] ) : 1;
		$days     = array();
		foreach ( (array) ( isset( $spec['weekdays'] ) ? $spec['weekdays'] : array() ) as $d ) {
			$n = bsb_weekday_number( $d );
			if ( $n !== null ) {
				$days[] = $n;
			}
		}
		sort( $days );
		$until = ! empty( $spec['until'] ) ? (string) $spec['until'] : null;
		$limit = ! empty( $spec['count'] ) ? (int) $spec['count'] : 500;

		$cursor     = new DateTimeImmutable( $start_ymd );
		$week_start = $cursor->modify( 'monday this week' );
		$guard      = 0;
		while ( count( $dates ) < $limit && $guard++ < 600 ) {
			foreach ( $days as $dow ) {
				$d = $week_start->modify( '+' . ( $dow - 1 ) . ' days' );
				$s = $d->format( 'Y-m-d' );
				if ( $s < $start_ymd ) {
					continue;
				}
				if ( $until !== null && $s > $until ) {
					break 2;
				}
				if ( count( $dates ) >= $limit ) {
					break 2;
				}
				$dates[] = $s;
			}
			$week_start = $week_start->modify( '+' . ( 7 * $interval ) . ' days' );
		}
	}

	foreach ( (array) ( isset( $spec['dates'] ) ? $spec['dates'] : array() ) as $ymd ) {
		$dates[] = substr( (string) $ymd, 0, 10 );
	}

	// TEC always emits the SERIES START as an occurrence in its own right, whether or
	// not a rule generates it. For a weekly series the rule usually covers it anyway;
	// for a dates-only series it does not, and leaving it out undercounts by one.
	// (Confirmed on the MOMents spring-2026 series: start 2026-02-20 + 4 date rules =
	// 5 occurrences.)
	$dates[] = $start_ymd;

	$excluded = array();
	foreach ( (array) ( isset( $spec['exclusions'] ) ? $spec['exclusions'] : array() ) as $ymd ) {
		$excluded[] = substr( (string) $ymd, 0, 10 );
	}

	$dates = array_values( array_unique( $dates ) );
	sort( $dates );
	$kept    = array_values( array_diff( $dates, $excluded ) );
	$skipped = array_values( array_intersect( $dates, $excluded ) );

	// Exclusions matching nothing are almost always a typo or wrong weekday —
	// surface them rather than letting them pass silently.
	$inert = array_values( array_diff( $excluded, $dates ) );

	// The start date survives its own exclusion — observed on Awana while its start
	// sat on the excluded 2027-03-24. Model it so the preview stays truthful.
	$start_excluded = in_array( $start_ymd, $excluded, true );
	if ( $start_excluded ) {
		$kept = array_values( array_unique( array_merge( array( $start_ymd ), $kept ) ) );
		sort( $kept );
	}

	return array(
		'generated'        => $kept,
		'count'            => count( $kept ),
		'excluded'         => $skipped,
		'inert_exclusions' => $inert,
		'start_date_excluded_but_still_emitted' => $start_excluded ? $start_ymd : null,
	);
}

function bsb_events_get_recurrence( WP_REST_Request $req ) {
	$resolved = bsb_resolve( $req->get_param( 'ref' ), 'tribe_events' );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post_id, $was_provisional ) = $resolved;

	$occ = bsb_events_occurrences_for( $post_id );

	return rest_ensure_response( array(
		'post_id'             => $post_id,
		'slug'                => get_post_field( 'post_name', $post_id ),
		'title'               => get_the_title( $post_id ),
		'ref_was_provisional' => $was_provisional,
		'start_date'          => get_post_meta( $post_id, '_EventStartDate', true ),
		'end_date'            => get_post_meta( $post_id, '_EventEndDate', true ),
		'timezone'            => get_post_meta( $post_id, '_EventTimezone', true ),
		'is_recurring'        => function_exists( 'tribe_is_recurring_event' ) ? (bool) tribe_is_recurring_event( $post_id ) : null,
		'recurrence_meta'     => get_post_meta( $post_id, '_EventRecurrence', true ),
		'occurrence_count'    => is_array( $occ ) ? count( $occ ) : null,
		'occurrences'         => $occ,
	) );
}

function bsb_events_get_occurrences( WP_REST_Request $req ) {
	$resolved = bsb_resolve( $req->get_param( 'ref' ), 'tribe_events' );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post_id ) = $resolved;
	$occ = bsb_events_occurrences_for( $post_id );
	if ( $occ === null ) {
		return new WP_Error( 'bsb_no_occ_table', 'tec_occurrences table not found', array( 'status' => 501 ) );
	}
	return rest_ensure_response( array(
		'post_id'     => $post_id,
		'title'       => get_the_title( $post_id ),
		'count'       => count( $occ ),
		'dates'       => wp_list_pluck( $occ, 'date' ),
		'occurrences' => $occ,
	) );
}

function bsb_events_require_tec() {
	if ( ! function_exists( 'tribe_create_event' ) || ! function_exists( 'tribe_update_event' ) ) {
		return new WP_Error( 'bsb_no_tec', 'tribe_create_event()/tribe_update_event() unavailable — is The Events Calendar active?', array( 'status' => 501 ) );
	}
	return true;
}

/**
 * Apply the ACF field-group defaults for tribe_events to a freshly created event.
 *
 * WHY THIS EXISTS: an ACF default value is only written during an ACF save, and
 * tribe_create_event() bypasses ACF entirely. So a REST-created event has those
 * fields UNSET rather than defaulted — which is not the same thing to the theme.
 * Concretely: `use_event_url_for_registration` defaults to on and is what renders
 * the "Register Now" button from _EventURL. Events created through this endpoint
 * before v0.8.0 had a correct _EventURL and no button (all 15 fall-2026 Family
 * Meals, 2026-08-11). Same shape as the v0.7.0 taxonomy bug: anything outside
 * TEC's own PHP API doesn't happen unless we make it happen.
 *
 * Driven off ACF's own field groups rather than a hardcoded key list, so a field
 * added to the group later is picked up without touching this plugin. Writes via
 * update_field() with the field KEY, which is what correctly stores both halves of
 * ACF's `name` => value / `_name` => key pair — writing the value alone leaves the
 * field unrecognized and inert.
 *
 * @param int   $post_id   The new event.
 * @param array $overrides field name => value from the caller; wins over the default.
 * @return array{applied: array|null, note: string|null}
 */
function bsb_events_apply_acf_defaults( $post_id, array $overrides = array() ) {
	if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'update_field' ) ) {
		return array( 'applied' => null, 'note' => 'ACF not active — no field defaults applied' );
	}

	$groups = acf_get_field_groups( array( 'post_type' => 'tribe_events' ) );
	if ( ! $groups ) {
		return array( 'applied' => array(), 'note' => 'no ACF field groups target tribe_events' );
	}

	$applied = array();
	foreach ( $groups as $group ) {
		$fields = acf_get_fields( $group );
		if ( ! $fields ) {
			continue;
		}
		foreach ( $fields as $field ) {
			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( $name === '' || empty( $field['key'] ) ) {
				continue;
			}

			$has_override = array_key_exists( $name, $overrides );

			// Never clobber a value that's already on the post. tribe_create_event()
			// doesn't set these, but a filter or another plugin might, and a default
			// silently overwriting a real value would be a worse bug than the one
			// this function fixes.
			if ( ! $has_override && metadata_exists( 'post', $post_id, $name ) ) {
				continue;
			}

			$value = $has_override
				? $overrides[ $name ]
				: ( array_key_exists( 'default_value', $field ) ? $field['default_value'] : '' );

			update_field( $field['key'], $value, $post_id );
			$applied[ $name ] = $value;
		}
	}

	return array( 'applied' => $applied, 'note' => null );
}

/**
 * Currency meta TEC writes on a normal admin save but skips on the REST path.
 * Cosmetic while cost is empty, but it's part of looking like a hand-built event.
 */
function bsb_events_apply_tec_defaults( $post_id ) {
	$defaults = array(
		'_EventCurrencyCode'     => 'USD',
		'_EventCurrencySymbol'   => '$',
		'_EventCurrencyPosition' => 'prefix',
	);
	foreach ( $defaults as $key => $value ) {
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			update_post_meta( $post_id, $key, $value );
		}
	}
	return array_keys( $defaults );
}

/** POST /events — create a single or recurring event. */
function bsb_events_create( WP_REST_Request $req ) {
	$dry = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

	$start = (string) $req->get_param( 'start_date' );
	$end   = (string) $req->get_param( 'end_date' );
	$title = (string) $req->get_param( 'title' );
	if ( $title === '' || $start === '' || $end === '' ) {
		return new WP_Error( 'bsb_bad_input', 'title, start_date and end_date are required', array( 'status' => 400 ) );
	}

	$spec       = (array) ( $req->get_param( 'recurrence' ) ? $req->get_param( 'recurrence' ) : array() );
	$recurrence = null;
	$preview    = null;

	if ( $spec ) {
		list( $recurrence, $err ) = bsb_build_recurrence( $spec, $start, $end );
		if ( $err ) {
			return $err;
		}
		$preview = bsb_preview_dates( $spec, substr( $start, 0, 10 ) );

		$expected = $req->get_param( 'expected_count' );
		if ( $expected !== null && (int) $expected !== (int) $preview['count'] ) {
			return new WP_Error( 'bsb_count_mismatch', sprintf(
				'expected_count=%d but the spec describes %d dates — refusing to write. Dates: %s',
				(int) $expected, (int) $preview['count'], implode( ', ', $preview['generated'] )
			), array( 'status' => 409 ) );
		}
	}

	$args = array(
		'post_title'       => $title,
		'post_status'      => (string) ( $req->get_param( 'status' ) ? $req->get_param( 'status' ) : 'draft' ),
		'post_content'     => (string) $req->get_param( 'description' ),
		'post_excerpt'     => (string) $req->get_param( 'excerpt' ),
		'EventStartDate'   => substr( $start, 0, 10 ),
		'EventEndDate'     => substr( $end, 0, 10 ),
		'EventStartHour'   => substr( $start, 11, 2 ),
		'EventStartMinute' => substr( $start, 14, 2 ),
		'EventEndHour'     => substr( $end, 11, 2 ),
		'EventEndMinute'   => substr( $end, 14, 2 ),
		'EventTimezone'    => (string) ( $req->get_param( 'timezone' ) ? $req->get_param( 'timezone' ) : 'America/Chicago' ),
		'EventURL'         => (string) $req->get_param( 'website' ),
		'EventShowMap'     => true,
		'EventShowMapLink' => true,
	);

	if ( $req->get_param( 'venue' ) ) {
		$args['venue'] = array( 'VenueID' => (int) $req->get_param( 'venue' ) );
	}
	if ( $req->get_param( 'organizer' ) ) {
		$args['organizer'] = array( 'OrganizerID' => array_map( 'intval', (array) $req->get_param( 'organizer' ) ) );
	}
	// NOTE: taxonomies are deliberately NOT passed as tax_input. tribe_create_event()
	// silently drops it (wp_insert_post's tax_input path needs an assign_terms
	// capability check that doesn't survive this REST context), so on 2026-08-11 the
	// MOMents event was created with NO category — and the category is what makes the
	// events appear on their program page. Set the terms explicitly after create, and
	// echo them back so a silent no-op can't happen unnoticed again.
	$cats  = $req->get_param( 'categories' ) ? array_map( 'intval', (array) $req->get_param( 'categories' ) ) : array();
	$tags  = $req->get_param( 'tags' ) ? array_map( 'intval', (array) $req->get_param( 'tags' ) ) : array();
	$thumb = $req->get_param( 'featured_media' ) ? (int) $req->get_param( 'featured_media' ) : 0;

	// ACF field-group defaults are applied unless the caller opts out. Defaulting to
	// ON is the point of the fix: the failure mode this prevents is silent, so a
	// caller who doesn't know to ask is exactly the caller who needs it.
	$apply_acf = $req->get_param( 'apply_acf_defaults' ) === null
		? true
		: filter_var( $req->get_param( 'apply_acf_defaults' ), FILTER_VALIDATE_BOOLEAN );
	$acf_overrides = (array) ( $req->get_param( 'acf' ) ? $req->get_param( 'acf' ) : array() );

	if ( $recurrence ) {
		$args['recurrence'] = $recurrence;
	}

	if ( $dry ) {
		return rest_ensure_response( array(
			'dry_run'          => true,
			'would_create'     => $title,
			'start_date'       => $start,
			'end_date'         => $end,
			'preview'          => $preview,
			'recurrence_array' => $recurrence,
		) );
	}

	$check = bsb_events_require_tec();
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$post_id = tribe_create_event( $args );
	if ( ! $post_id ) {
		return new WP_Error( 'bsb_create_failed', 'tribe_create_event() returned falsy', array( 'status' => 500 ) );
	}

	// Terms + featured image, set directly (see the note above the $cats block).
	if ( $cats ) {
		wp_set_object_terms( $post_id, $cats, 'tribe_events_cat' );
	}
	if ( $tags ) {
		wp_set_object_terms( $post_id, $tags, 'post_tag' );
	}
	if ( $thumb ) {
		set_post_thumbnail( $post_id, $thumb );
	}

	// ACF field-group defaults + TEC's currency meta — neither of which
	// tribe_create_event() writes. See bsb_events_apply_acf_defaults().
	$acf_result = array( 'applied' => null, 'note' => 'skipped via apply_acf_defaults=false' );
	if ( $apply_acf ) {
		$acf_result = bsb_events_apply_acf_defaults( $post_id, $acf_overrides );
		bsb_events_apply_tec_defaults( $post_id );
	}

	// Read the terms back off the post rather than echoing the request — that's the
	// difference between "we asked for it" and "it actually stuck".
	$applied_cats = wp_get_object_terms( $post_id, 'tribe_events_cat', array( 'fields' => 'names' ) );
	$applied_tags = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
	$applied_thumb = (int) get_post_thumbnail_id( $post_id );

	// Same principle for ACF: report what the POST now holds, not what we sent.
	$acf_readback = null;
	if ( is_array( $acf_result['applied'] ) && function_exists( 'get_field' ) ) {
		$acf_readback = array();
		foreach ( array_keys( $acf_result['applied'] ) as $field_name ) {
			$acf_readback[ $field_name ] = get_field( $field_name, $post_id );
		}
	}

	// Same TEC regeneration lag as on update — wait before reporting.
	$expected = $preview ? $preview['generated'] : array();
	list( $occ, $settled, $waited_ms ) = bsb_events_settle( $post_id, $expected );

	return rest_ensure_response( array(
		'created'          => true,
		'post_id'          => $post_id,
		'slug'             => get_post_field( 'post_name', $post_id ),
		'permalink'        => get_permalink( $post_id ),
		'categories'       => is_wp_error( $applied_cats ) ? null : $applied_cats,
		'tags'             => is_wp_error( $applied_tags ) ? null : $applied_tags,
		'featured_media'   => $applied_thumb ? $applied_thumb : null,
		'acf_defaults'     => $acf_readback,
		'acf_note'         => $acf_result['note'],
		'preview'          => $preview,
		'occurrence_count' => is_array( $occ ) ? count( $occ ) : null,
		'occurrence_dates' => is_array( $occ ) ? wp_list_pluck( $occ, 'date' ) : null,
		'matches_preview'  => $preview ? $settled : null,
		'settled_after_ms' => $waited_ms,
		'note'             => ( $preview && ! $settled )
			? 'Occurrences had not matched the preview yet — TEC regenerates after the write returns. Re-check GET /events/' . get_post_field( 'post_name', $post_id ) . '/occurrences before assuming failure.'
			: null,
	) );
}


/**
 * PUT /events/{ref} — edit an existing event's ordinary fields.
 *
 * The missing sibling of POST /events. TEC's own REST is the alternative and it
 * is the reason this exists: a partial POST to tribe/events/v1/events/{id} wipes
 * whatever it wasn't told about — recurrence, website, thumbnail, organizer
 * (found 2026-08). This goes through tribe_update_event() with ONLY the fields
 * passed, reads everything back off the saved post, and guards the two ways an
 * edit hurts a recurring series:
 *   - dates on a recurring event move EVERY occurrence, so changing them needs
 *     apply_to=series AND expected_count equal to the current occurrence count;
 *   - _EventRecurrence is snapshotted before the write and restored if the update
 *     path dropped it (reported as recurrence_restored), and occurrence counts are
 *     compared before/after.
 * A provisional occurrence id is refused without apply_to=series, same as /meta.
 */
function bsb_events_update( WP_REST_Request $req ) {
	$resolved = bsb_resolve( $req->get_param( 'ref' ), 'tribe_events' );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post_id, $was_provisional ) = $resolved;
	$apply_series = $req->get_param( 'apply_to' ) === 'series';
	if ( $was_provisional && ! $apply_series ) {
		return new WP_Error( 'bsb_provisional_ref', sprintf(
			'That is a provisional OCCURRENCE id — edits land on parent post %d and affect the whole series. Pass apply_to=series to confirm, or reference the slug "%s".',
			$post_id, get_post_field( 'post_name', $post_id )
		), array( 'status' => 409 ) );
	}

	$p        = $req->get_params();
	$has      = function ( $k ) use ( $p ) { return array_key_exists( $k, $p ) && $p[ $k ] !== null; };
	$editable = array( 'title', 'description', 'excerpt', 'status', 'start_date', 'end_date', 'all_day', 'timezone', 'website', 'cost', 'venue', 'organizer', 'categories', 'tags', 'featured_media', 'acf' );
	$given    = array_values( array_filter( $editable, $has ) );
	if ( ! $given ) {
		return new WP_Error( 'bsb_no_fields', 'Nothing to change — pass one or more of: ' . implode( ', ', $editable ), array( 'status' => 400 ) );
	}
	if ( $has( 'start_date' ) !== $has( 'end_date' ) ) {
		return new WP_Error( 'bsb_dates_pair', 'start_date and end_date must be changed together (TEC derives one from the other otherwise)', array( 'status' => 400 ) );
	}
	if ( $has( 'status' ) && ! in_array( $p['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
		return new WP_Error( 'bsb_bad_status', 'status must be publish, draft, pending or private', array( 'status' => 400 ) );
	}

	$is_recurring = function_exists( 'tribe_is_recurring_event' ) ? (bool) tribe_is_recurring_event( $post_id ) : (bool) get_post_meta( $post_id, '_EventRecurrence', true );
	$occ_before   = bsb_events_occurrences_for( $post_id );
	$dates_change = $has( 'start_date' ) || $has( 'all_day' ) || $has( 'timezone' );
	if ( $is_recurring && $dates_change ) {
		$count = is_array( $occ_before ) ? count( $occ_before ) : null;
		if ( ! $apply_series || $req->get_param( 'expected_count' ) === null || (int) $req->get_param( 'expected_count' ) !== (int) $count ) {
			return new WP_Error( 'bsb_series_dates', sprintf(
				'This is a RECURRING series with %s occurrences — changing its dates moves every one of them. Pass apply_to=series AND expected_count=%s to confirm that is intended.',
				$count === null ? '?' : $count, $count === null ? '<count>' : $count
			), array( 'status' => 409 ) );
		}
	}

	$snapshot = function () use ( $post_id ) {
		$venue = (int) get_post_meta( $post_id, '_EventVenueID', true );
		$orgs  = array_map( 'intval', (array) get_post_meta( $post_id, '_EventOrganizerID', false ) );
		$acf   = array();
		if ( function_exists( 'get_field_objects' ) ) {
			foreach ( (array) get_field_objects( $post_id ) as $name => $f ) {
				$acf[ $name ] = isset( $f['value'] ) ? $f['value'] : null;
			}
		}
		$cats = wp_get_object_terms( $post_id, 'tribe_events_cat', array( 'fields' => 'ids' ) );
		$tags = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
		return array(
			'title'          => get_the_title( $post_id ),
			'status'         => get_post_status( $post_id ),
			'description'    => (string) get_post_field( 'post_content', $post_id ),
			'excerpt'        => (string) get_post_field( 'post_excerpt', $post_id ),
			'start_date'     => get_post_meta( $post_id, '_EventStartDate', true ),
			'end_date'       => get_post_meta( $post_id, '_EventEndDate', true ),
			'all_day'        => get_post_meta( $post_id, '_EventAllDay', true ) === 'yes',
			'timezone'       => get_post_meta( $post_id, '_EventTimezone', true ),
			'website'        => get_post_meta( $post_id, '_EventURL', true ),
			'cost'           => get_post_meta( $post_id, '_EventCost', true ),
			'venue'          => $venue ? $venue : null,
			'organizer'      => array_values( array_filter( $orgs ) ),
			'categories'     => is_wp_error( $cats ) ? null : array_map( 'intval', $cats ),
			'tags'           => is_wp_error( $tags ) ? null : array_map( 'intval', $tags ),
			'featured_media' => (int) get_post_thumbnail_id( $post_id ) ?: null,
			'acf'            => $acf,
			'recurrence'     => get_post_meta( $post_id, '_EventRecurrence', true ),
		);
	};
	$before = $snapshot();

	$args = array();
	if ( $has( 'title' ) )       { $args['post_title']   = (string) $p['title']; }
	if ( $has( 'description' ) ) { $args['post_content'] = (string) $p['description']; }
	if ( $has( 'excerpt' ) )     { $args['post_excerpt'] = (string) $p['excerpt']; }
	if ( $has( 'status' ) )      { $args['post_status']  = (string) $p['status']; }
	if ( $has( 'start_date' ) ) {
		$start = (string) $p['start_date'];
		$end   = (string) $p['end_date'];
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $end ) ) {
			return new WP_Error( 'bsb_bad_date', 'Dates must be "YYYY-MM-DD HH:MM" (or YYYY-MM-DD for all-day)', array( 'status' => 400 ) );
		}
		$args['EventStartDate']   = substr( $start, 0, 10 );
		$args['EventEndDate']     = substr( $end, 0, 10 );
		$args['EventStartHour']   = strlen( $start ) > 10 ? substr( $start, 11, 2 ) : '00';
		$args['EventStartMinute'] = strlen( $start ) > 10 ? substr( $start, 14, 2 ) : '00';
		$args['EventEndHour']     = strlen( $end ) > 10 ? substr( $end, 11, 2 ) : '23';
		$args['EventEndMinute']   = strlen( $end ) > 10 ? substr( $end, 14, 2 ) : '59';
	}
	if ( $has( 'all_day' ) )  { $args['EventAllDay']   = filter_var( $p['all_day'], FILTER_VALIDATE_BOOLEAN ) ? 'yes' : ''; }
	if ( $has( 'timezone' ) ) { $args['EventTimezone'] = (string) $p['timezone']; }
	if ( $has( 'website' ) )  { $args['EventURL']      = (string) $p['website']; }
	if ( $has( 'cost' ) )     { $args['EventCost']     = (string) $p['cost']; }
	if ( $has( 'venue' ) )    { $args['venue']         = array( 'VenueID' => (int) $p['venue'] ); }
	if ( $has( 'organizer' ) ) { $args['organizer']    = array( 'OrganizerID' => array_map( 'intval', (array) $p['organizer'] ) ); }
	if ( $dates_change && ! $has( 'start_date' ) ) {
		// TEC re-derives dates from the args it gets; keep the existing ones explicit so a
		// timezone/all-day change can't shift them.
		$args['EventStartDate']   = substr( (string) $before['start_date'], 0, 10 );
		$args['EventEndDate']     = substr( (string) $before['end_date'], 0, 10 );
		$args['EventStartHour']   = substr( (string) $before['start_date'], 11, 2 );
		$args['EventStartMinute'] = substr( (string) $before['start_date'], 14, 2 );
		$args['EventEndHour']     = substr( (string) $before['end_date'], 11, 2 );
		$args['EventEndMinute']   = substr( (string) $before['end_date'], 14, 2 );
	}

	$would = array();
	foreach ( $given as $k ) {
		$would[ $k ] = $p[ $k ];
	}
	$dry = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );
	if ( $dry || ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array(
			'dry_run'          => true,
			'note'             => $dry ? 'dry_run=true — nothing written' : 'confirm=true was not passed — nothing written',
			'post_id'          => $post_id,
			'slug'             => get_post_field( 'post_name', $post_id ),
			'is_recurring'     => $is_recurring,
			'occurrence_count' => is_array( $occ_before ) ? count( $occ_before ) : null,
			'before'           => array_intersect_key( $before, $would + array( 'start_date' => 1, 'end_date' => 1 ) ),
			'would_set'        => $would,
			'tec_args'         => $args,
			'warning'          => $is_recurring ? 'Recurring series: _EventRecurrence is snapshotted and restored if the update drops it; occurrence counts are compared before/after.' : null,
		) );
	}

	$check = bsb_events_require_tec();
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$restored = false;
	if ( $args ) {
		$ok = tribe_update_event( $post_id, $args );
		if ( ! $ok ) {
			return new WP_Error( 'bsb_update_failed', 'tribe_update_event() returned falsy — nothing else was applied', array( 'status' => 500 ) );
		}
		if ( $is_recurring && $before['recurrence'] && get_post_meta( $post_id, '_EventRecurrence', true ) != $before['recurrence'] ) {
			update_post_meta( $post_id, '_EventRecurrence', $before['recurrence'] );
			$restored = true;
		}
	}
	if ( $has( 'categories' ) )     { wp_set_object_terms( $post_id, array_map( 'intval', (array) $p['categories'] ), 'tribe_events_cat' ); }
	if ( $has( 'tags' ) )           { wp_set_object_terms( $post_id, array_map( 'intval', (array) $p['tags'] ), 'post_tag' ); }
	if ( $has( 'featured_media' ) ) {
		if ( (int) $p['featured_media'] ) { set_post_thumbnail( $post_id, (int) $p['featured_media'] ); } else { delete_post_thumbnail( $post_id ); }
	}
	$acf_note = null;
	if ( $has( 'acf' ) ) {
		if ( ! function_exists( 'update_field' ) ) {
			$acf_note = 'ACF not active — acf values were NOT written';
		} else {
			foreach ( (array) $p['acf'] as $name => $value ) {
				update_field( (string) $name, $value, $post_id );
			}
		}
	}
	clean_post_cache( $post_id );

	list( $occ_after, , $waited ) = bsb_events_settle( $post_id, is_array( $occ_before ) ? wp_list_pluck( $occ_before, 'date' ) : array() );
	$after   = $snapshot();
	$changed = array();
	foreach ( $after as $k => $v ) {
		if ( $k !== 'recurrence' && $v != $before[ $k ] ) {
			$changed[] = $k;
		}
	}
	$n_before = is_array( $occ_before ) ? count( $occ_before ) : null;
	$n_after  = is_array( $occ_after ) ? count( $occ_after ) : null;
	return rest_ensure_response( array(
		'updated'                 => true,
		'post_id'                 => $post_id,
		'slug'                    => get_post_field( 'post_name', $post_id ),
		'permalink'               => get_permalink( $post_id ),
		'changed'                 => $changed,
		'before'                  => array_diff_key( $before, array( 'recurrence' => 1 ) ),
		'after'                   => array_diff_key( $after, array( 'recurrence' => 1 ) ),
		'is_recurring'            => $is_recurring,
		'occurrence_count_before' => $n_before,
		'occurrence_count_after'  => $n_after,
		'recurrence_restored'     => $restored,
		'settled_after_ms'        => $waited,
		'acf_note'                => $acf_note,
		'warning'                 => ( $is_recurring && $n_before !== null && $n_after !== null && $n_before !== $n_after && ! $dates_change )
			? 'Occurrence count changed on a non-date edit — TEC may still be regenerating; re-check GET /events/{ref}/occurrences before assuming damage.'
			: null,
	) );
}

/** PUT /events/{ref}/recurrence — replace rule + exclusions on an existing series. */
function bsb_events_put_recurrence( WP_REST_Request $req ) {
	$dry = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

	$raw_ref  = (string) $req->get_param( 'ref' );
	$resolved = bsb_resolve( $raw_ref, 'tribe_events' );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post_id, $was_provisional ) = $resolved;

	// A provisional ID looks like "one night" but every write lands on the whole
	// series. Make that explicit rather than silent.
	if ( $was_provisional && $req->get_param( 'apply_to' ) !== 'series' ) {
		return new WP_Error( 'bsb_provisional_ref', sprintf(
			'%s is a provisional OCCURRENCE id — writing to it changes the ENTIRE series (parent post %d). Pass apply_to=series to confirm, or reference the slug "%s" instead.',
			$raw_ref, $post_id, get_post_field( 'post_name', $post_id )
		), array( 'status' => 409 ) );
	}

	$spec = (array) ( $req->get_param( 'recurrence' ) ? $req->get_param( 'recurrence' ) : array() );
	if ( ! $spec ) {
		return new WP_Error( 'bsb_no_spec', 'A "recurrence" object is required', array( 'status' => 400 ) );
	}

	// Series start/end: either the caller moves them, or keep what's on the post.
	// Every rule and exclusion embeds these, so they must be resolved first.
	$start = (string) ( $req->get_param( 'start_date' ) ? $req->get_param( 'start_date' ) : get_post_meta( $post_id, '_EventStartDate', true ) );
	$end   = (string) ( $req->get_param( 'end_date' ) ? $req->get_param( 'end_date' ) : get_post_meta( $post_id, '_EventEndDate', true ) );

	list( $recurrence, $err ) = bsb_build_recurrence( $spec, $start, $end );
	if ( $err ) {
		return $err;
	}

	$preview = bsb_preview_dates( $spec, substr( $start, 0, 10 ) );

	$expected = $req->get_param( 'expected_count' );
	if ( $expected !== null && (int) $expected !== (int) $preview['count'] ) {
		return new WP_Error( 'bsb_count_mismatch', sprintf(
			'expected_count=%d but the spec describes %d dates — refusing to write. Dates: %s',
			(int) $expected, (int) $preview['count'], implode( ', ', $preview['generated'] )
		), array( 'status' => 409 ) );
	}

	$before = bsb_events_occurrences_for( $post_id );

	if ( $dry ) {
		return rest_ensure_response( array(
			'dry_run'          => true,
			'post_id'          => $post_id,
			'slug'             => get_post_field( 'post_name', $post_id ),
			'current_count'    => is_array( $before ) ? count( $before ) : null,
			'current_dates'    => is_array( $before ) ? wp_list_pluck( $before, 'date' ) : null,
			'preview'          => $preview,
			'recurrence_array' => $recurrence,
		) );
	}

	$check = bsb_events_require_tec();
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$args = array( 'recurrence' => $recurrence );
	if ( $req->get_param( 'start_date' ) ) {
		$s                        = (string) $req->get_param( 'start_date' );
		$args['EventStartDate']   = substr( $s, 0, 10 );
		$args['EventStartHour']   = substr( $s, 11, 2 );
		$args['EventStartMinute'] = substr( $s, 14, 2 );
	}
	if ( $req->get_param( 'end_date' ) ) {
		$e                      = (string) $req->get_param( 'end_date' );
		$args['EventEndDate']   = substr( $e, 0, 10 );
		$args['EventEndHour']   = substr( $e, 11, 2 );
		$args['EventEndMinute'] = substr( $e, 14, 2 );
	}

	$ok = tribe_update_event( $post_id, $args );
	if ( ! $ok ) {
		return new WP_Error( 'bsb_update_failed', 'tribe_update_event() returned falsy', array( 'status' => 500 ) );
	}

	list( $after, $settled, $waited_ms ) = bsb_events_settle( $post_id, $preview['generated'] );
	$after_dates = is_array( $after ) ? wp_list_pluck( $after, 'date' ) : null;

	return rest_ensure_response( array(
		'updated'         => true,
		'post_id'         => $post_id,
		'slug'            => get_post_field( 'post_name', $post_id ),
		'before_count'    => is_array( $before ) ? count( $before ) : null,
		'after_count'     => is_array( $after ) ? count( $after ) : null,
		'after_dates'     => $after_dates,
		'preview'         => $preview,
		'matches_preview' => $settled,
		'settled_after_ms' => $waited_ms,
		'note'            => $settled
			? null
			: 'Occurrences had not matched the preview yet. TEC regenerates them after the write returns, so this is often just timing — re-check GET /events/' . get_post_field( 'post_name', $post_id ) . '/occurrences before assuming failure, and do NOT retry the write blindly.',
	) );
}

/* ================================================================== *
 * MODULE: redirects — path → URL redirects, managed over REST
 * ================================================================== *
 *
 * Added 2026-09-03 for /contact/email-preferences. The Contact page moved under
 * About on 2025-05-30 and its child moved with it; WordPress's own 404 guess
 * rescues the bare /contact/ but never a nested path, so the child's old URL
 * went dead in every email footer that carried it. Nothing on the site could add
 * that redirect over REST — the Pretty Links mu-plugin bridge sanitize_title()s
 * the slashes out of a slug, and Quick Page/Post Redirect is wp-admin only — so
 * this module is that hatch. It is deliberately independent of both plugins.
 *
 * STORAGE — one option, `bsb_redirects`, a JSON object keyed by normalized path:
 *   "/contact/email-preferences": { "to": "/about/contact/email-preferences",
 *                                   "status": 301, "note": "...", "updated": "..." }
 * Written through bsb_option_write(), so the poisoned-notoptions-cache problem
 * that bit 0.9.1 can't make a rule look saved when it isn't.
 *
 * MATCHING — paths are lower-cased, url-decoded, slash-collapsed, trailing slash
 * dropped; the query string is ignored for matching and carried over to the
 * destination when the destination has none. A key ending in "/*" is a PREFIX
 * rule: "/contact/*" matches /contact/anything (children only, not /contact
 * itself). Put "*" at the end of that rule's "to" to carry the remainder along
 * ("/about/contact/*"); without it the whole subtree lands on one URL. Exact
 * rules win over prefix rules; a longer prefix wins over a shorter one.
 *
 * SERVING — template_redirect at priority 0, so a rule wins over a real page at
 * the same path (that is what an explicit redirect means; PUT reports it as
 * `shadows` so it's never a surprise). Cost on an unmatched request is one
 * option read. A rule that would send a request to itself is refused on write
 * and skipped on serve, so a typo can't loop the site.
 */

const BSB_REDIRECTS_OPTION = 'bsb_redirects';

/** "/Contact//Email-Preferences/?x=1" → "/contact/email-preferences"; keeps a trailing "/*". */
function bsb_redirects_normalize_path( $path ) {
	$path = (string) $path;
	$p    = parse_url( $path, PHP_URL_PATH );
	if ( ! is_string( $p ) ) {
		$p = $path;
	}
	$p    = strtolower( rawurldecode( $p ) );
	$wild = substr( $p, -2 ) === '/*';
	if ( $wild ) {
		$p = substr( $p, 0, -2 );
	}
	$p = '/' . trim( preg_replace( '#/+#', '/', $p ), '/' );
	return $wild ? rtrim( $p, '/' ) . '/*' : $p;
}

/** Every rule, keyed by normalized path. Cached per request; $reload after a write. */
function bsb_redirects_all( $reload = false ) {
	static $cache = null;
	if ( is_array( $cache ) && ! $reload ) {
		return $cache;
	}
	$raw   = bsb_option_read( BSB_REDIRECTS_OPTION );
	$data  = $raw !== '' ? json_decode( $raw, true ) : array();
	$cache = is_array( $data ) ? $data : array();
	return $cache;
}

/** DB-verified save of the whole rule set. */
function bsb_redirects_save( array $rules ) {
	ksort( $rules );
	$json = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$ok   = bsb_option_write( BSB_REDIRECTS_OPTION, $json );
	bsb_redirects_all( true );
	return $ok;
}

/**
 * The rule that applies to $path, or null. Returns the matched key, the resolved
 * "to" (prefix remainder already substituted) and the status.
 */
function bsb_redirects_resolve( $path ) {
	$rules = bsb_redirects_all();
	$path  = bsb_redirects_normalize_path( $path );

	if ( isset( $rules[ $path ] ) && substr( $path, -2 ) !== '/*' ) {
		$r = $rules[ $path ];
		return array( 'rule' => $path, 'to' => (string) $r['to'], 'status' => (int) $r['status'] );
	}

	$best = null;
	foreach ( $rules as $key => $r ) {
		if ( substr( $key, -2 ) !== '/*' ) {
			continue;
		}
		$prefix = substr( $key, 0, -1 ); // "/contact/*" → "/contact/"
		if ( strpos( $path, $prefix ) === 0 && ( $best === null || strlen( $key ) > strlen( $best ) ) ) {
			$best = $key;
		}
	}
	if ( $best === null ) {
		return null;
	}
	$r         = $rules[ $best ];
	$remainder = substr( $path, strlen( $best ) - 1 ); // what followed the prefix
	$to        = (string) $r['to'];
	$star      = strrpos( $to, '*' );
	if ( $star !== false ) {
		$to = substr( $to, 0, $star ) . $remainder . substr( $to, $star + 1 );
	}
	return array( 'rule' => $best, 'to' => $to, 'status' => (int) $r['status'] );
}

/** Absolute destination: site-relative "to" gets home_url(); the request's query rides along. */
function bsb_redirects_target_url( $to, $request_query = '' ) {
	if ( isset( $to[0] ) && $to[0] === '/' ) {
		$to = home_url( $to );
	}
	if ( $request_query !== '' && strpos( $to, '?' ) === false ) {
		$to .= '?' . $request_query;
	}
	return $to;
}

/** True when $target is this site at the same normalized path — i.e. a loop. */
function bsb_redirects_is_self( $target, $path ) {
	$t_host = strtolower( (string) parse_url( $target, PHP_URL_HOST ) );
	$here   = strtolower( (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( $t_host !== '' && $t_host !== $here ) {
		return false;
	}
	return bsb_redirects_normalize_path( (string) parse_url( $target, PHP_URL_PATH ) ) === bsb_redirects_normalize_path( $path );
}

/** The front-end hook. */
function bsb_redirects_serve() {
	if ( empty( $_SERVER['REQUEST_URI'] ) || ! bsb_redirects_all() ) {
		return;
	}
	$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	$path  = (string) parse_url( $uri, PHP_URL_PATH );
	$query = (string) parse_url( $uri, PHP_URL_QUERY );

	// A site installed in a subdirectory carries that prefix on every request.
	$home_path = rtrim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( $home_path !== '' && strpos( $path, $home_path ) === 0 ) {
		$path = substr( $path, strlen( $home_path ) );
	}

	$hit = bsb_redirects_resolve( $path );
	if ( ! $hit ) {
		return;
	}
	$target = bsb_redirects_target_url( $hit['to'], $query );
	if ( bsb_redirects_is_self( $target, $path ) ) {
		return;
	}
	bsb_redirects_record_hit( $hit['rule'] );
	nocache_headers();
	wp_redirect( $target, $hit['status'], 'Bethany Site Bridge' );
	exit;
}
add_action( 'template_redirect', 'bsb_redirects_serve', 0 );

/* ---------- stats + nested-404 rescue (0.11.0) ---------------------- *
 *
 * STATS answer "is anything still using this old URL, and from where?" — the
 * question that couldn't be answered on 2026-09-03 when a dead footer link turned
 * up with no way to trace its source. Kept in a SEPARATE option (`bsb_redirects_stats`)
 * so the rules option stays a clean, hand-editable config. Hits are counted in the
 * object cache and persisted at most once per 10 minutes per key, so a hot legacy
 * URL costs one option write per 10 minutes, not per request. Referers are stored
 * as scheme+host+path (no query), truncated.
 *
 * RESCUE: a multi-segment 404 whose LAST segment is the slug of exactly one
 * published page or post is 301'd to that post — the moved-parent case that
 * produced this whole module. WordPress's own guess never does this for nested
 * paths. One unambiguous match only; anything else is left as a 404. Every rescue
 * is logged (path → post) so a permanent rule can be written for it later. Off
 * switch: option `bsb_redirects_settings` = {"rescue": false} (PUT /options).
 */

const BSB_REDIRECTS_STATS_OPTION    = 'bsb_redirects_stats';
const BSB_REDIRECTS_SETTINGS_OPTION = 'bsb_redirects_settings';
const BSB_REDIRECTS_STATS_TTL       = 600; // seconds between persists per key

function bsb_redirects_settings() {
	$raw  = bsb_option_read( BSB_REDIRECTS_SETTINGS_OPTION );
	$data = $raw !== '' ? json_decode( $raw, true ) : array();
	$data = is_array( $data ) ? $data : array();
	return array( 'rescue' => array_key_exists( 'rescue', $data ) ? (bool) $data['rescue'] : true );
}

function bsb_redirects_stats() {
	$raw  = bsb_option_read( BSB_REDIRECTS_STATS_OPTION );
	$data = $raw !== '' ? json_decode( $raw, true ) : array();
	if ( ! is_array( $data ) ) {
		$data = array();
	}
	return array_merge( array( 'rules' => array(), 'rescues' => array(), 'rescued_paths' => array() ), $data );
}

/** scheme://host/path of the referer, or '' — never the query string. */
function bsb_redirects_referer() {
	if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
		return '';
	}
	$r = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
	$p = wp_parse_url( $r );
	if ( ! is_array( $p ) || empty( $p['host'] ) ) {
		return '';
	}
	return substr( ( isset( $p['scheme'] ) ? $p['scheme'] : 'https' ) . '://' . $p['host'] . ( isset( $p['path'] ) ? $p['path'] : '/' ), 0, 200 );
}

/**
 * Count one hit on $key (a rule path, or "rescue:" . path). Persists to the option
 * when this key hasn't been persisted for BSB_REDIRECTS_STATS_TTL seconds.
 */
function bsb_redirects_record_hit( $key, array $extra = array() ) {
	$ck  = 'bsb_rd_hits_' . md5( $key );
	$lk  = 'bsb_rd_last_' . md5( $key );
	$ref = bsb_redirects_referer();

	$n = wp_cache_get( $ck, 'bsb' );
	$n = ( $n === false ? 0 : (int) $n ) + 1;
	wp_cache_set( $ck, $n, 'bsb', 3600 );
	if ( $ref !== '' ) {
		wp_cache_set( $ck . '_ref', $ref, 'bsb', 3600 );
	}
	if ( wp_cache_get( $lk, 'bsb' ) !== false ) {
		return; // persisted recently — the counter keeps accumulating in cache
	}

	$stats  = bsb_redirects_stats();
	$bucket = strpos( $key, 'rescue:' ) === 0 ? 'rescued_paths' : 'rules';
	$k      = strpos( $key, 'rescue:' ) === 0 ? substr( $key, 7 ) : $key;
	$row    = isset( $stats[ $bucket ][ $k ] ) && is_array( $stats[ $bucket ][ $k ] ) ? $stats[ $bucket ][ $k ] : array( 'hits' => 0 );
	$row['hits']     = (int) $row['hits'] + $n;
	$row['last_hit'] = current_time( 'c' );
	$last_ref        = wp_cache_get( $ck . '_ref', 'bsb' );
	if ( is_string( $last_ref ) && $last_ref !== '' ) {
		$row['last_referer'] = $last_ref;
		$row['referers']     = isset( $row['referers'] ) && is_array( $row['referers'] ) ? $row['referers'] : array();
		if ( ! in_array( $last_ref, $row['referers'], true ) ) {
			array_unshift( $row['referers'], $last_ref );
			$row['referers'] = array_slice( $row['referers'], 0, 10 );
		}
	}
	foreach ( $extra as $ek => $ev ) {
		$row[ $ek ] = $ev;
	}
	$stats[ $bucket ][ $k ] = $row;
	if ( $bucket === 'rescued_paths' ) {
		// Newest-first log of the last 50 distinct rescues, for writing permanent rules.
		$stats['rescues'] = array_values( array_filter( (array) $stats['rescues'], function ( $r ) use ( $k ) {
			return ! ( is_array( $r ) && isset( $r['path'] ) && $r['path'] === $k );
		} ) );
		array_unshift( $stats['rescues'], array_merge( array( 'path' => $k ), $extra, array( 'at' => $row['last_hit'], 'hits' => $row['hits'] ) ) );
		$stats['rescues'] = array_slice( $stats['rescues'], 0, 50 );
	}
	bsb_option_write( BSB_REDIRECTS_STATS_OPTION, wp_json_encode( $stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	wp_cache_set( $ck, 0, 'bsb', 3600 );
	wp_cache_set( $lk, 1, 'bsb', BSB_REDIRECTS_STATS_TTL );
}

/** Exactly one published page/post whose slug is $slug, or null. */
function bsb_redirects_unique_post_for_slug( $slug ) {
	global $wpdb;
	$slug = sanitize_title( $slug );
	if ( $slug === '' ) {
		return null;
	}
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type IN ('page','post') LIMIT 2",
		$slug
	) );
	return count( $ids ) === 1 ? (int) $ids[0] : null;
}

function bsb_redirects_rescue() {
	if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$settings = bsb_redirects_settings();
	if ( ! $settings['rescue'] ) {
		return;
	}
	$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	$path  = bsb_redirects_normalize_path( (string) parse_url( $uri, PHP_URL_PATH ) );
	$query = (string) parse_url( $uri, PHP_URL_QUERY );
	$segs  = array_values( array_filter( explode( '/', $path ) ) );
	if ( count( $segs ) < 2 ) {
		return; // single segment: WordPress's own guess already had its chance
	}
	$post_id = bsb_redirects_unique_post_for_slug( end( $segs ) );
	if ( ! $post_id ) {
		return;
	}
	$target = get_permalink( $post_id );
	if ( ! $target || bsb_redirects_is_self( $target, $path ) ) {
		return;
	}
	if ( $query !== '' && strpos( $target, '?' ) === false ) {
		$target .= '?' . $query;
	}
	bsb_redirects_record_hit( 'rescue:' . $path, array( 'to' => get_permalink( $post_id ), 'post_id' => $post_id ) );
	nocache_headers();
	wp_redirect( $target, 301, 'Bethany Site Bridge (rescue)' );
	exit;
}
add_action( 'template_redirect', 'bsb_redirects_rescue', 1 );

/** The post a rule would shadow (a real page at that path), or null. Prefix rules probe the parent. */
function bsb_redirects_shadows( $path ) {
	$probe = substr( $path, -2 ) === '/*' ? substr( $path, 0, -2 ) : $path;
	if ( $probe === '' || $probe === '/' ) {
		return null;
	}
	$id = url_to_postid( home_url( $probe ) );
	if ( ! $id ) {
		return null;
	}
	return array( 'id' => $id, 'title' => get_the_title( $id ), 'link' => get_permalink( $id ) );
}

/** Validate + normalize a rule. Returns array( path, to, status, shadows ) or WP_Error. */
function bsb_redirects_validate( $path, $to, $status ) {
	$path = trim( (string) $path );
	if ( $path === '' || $path[0] !== '/' ) {
		return new WP_Error( 'bsb_bad_path', '"path" must be site-relative and start with "/", e.g. /old/page or /old/* for a prefix — not a full URL', array( 'status' => 400 ) );
	}
	$path = bsb_redirects_normalize_path( $path );
	if ( $path === '/' || $path === '/*' ) {
		return new WP_Error( 'bsb_bad_path', 'Refusing a rule for the site root — that would redirect every request', array( 'status' => 400 ) );
	}

	$to = trim( (string) $to );
	if ( $to === '' || ! ( $to[0] === '/' || preg_match( '#^https?://#i', $to ) ) ) {
		return new WP_Error( 'bsb_bad_to', '"to" must be an absolute http(s) URL or a site-relative path starting with "/"', array( 'status' => 400 ) );
	}
	$to = esc_url_raw( $to );
	if ( $to === '' ) {
		return new WP_Error( 'bsb_bad_to', '"to" did not survive URL sanitization', array( 'status' => 400 ) );
	}

	$status = $status === null || $status === '' ? 301 : (int) $status;
	if ( ! in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
		return new WP_Error( 'bsb_bad_status', '"status" must be 301, 302, 307 or 308', array( 'status' => 400 ) );
	}

	$probe = substr( $path, -2 ) === '/*' ? substr( $path, 0, -2 ) . '/x' : $path;
	if ( bsb_redirects_is_self( bsb_redirects_target_url( str_replace( '*', 'x', $to ) ), $probe ) ) {
		return new WP_Error( 'bsb_self_redirect', 'That rule would redirect the path to itself', array( 'status' => 400 ) );
	}

	return array( 'path' => $path, 'to' => $to, 'status' => $status, 'shadows' => bsb_redirects_shadows( $path ) );
}

/** GET /redirects — every rule; ?resolve=/some/path also reports what that path would do. */
function bsb_redirects_list( WP_REST_Request $req ) {
	$rules = bsb_redirects_all( true );
	$stats = bsb_redirects_stats();
	$out   = array();
	foreach ( $rules as $path => $r ) {
		$st    = isset( $stats['rules'][ $path ] ) ? $stats['rules'][ $path ] : array( 'hits' => 0 );
		$out[] = array_merge( array( 'path' => $path ), $r, array( 'stats' => $st ) );
	}
	$resp = array(
		'count'          => count( $out ),
		'rules'          => $out,
		'rescue_enabled' => bsb_redirects_settings()['rescue'],
		'rescues'        => $stats['rescues'],
		'stats_note'     => 'hits persist at most every ' . BSB_REDIRECTS_STATS_TTL . 's per path, so counts lag by up to that; referers are host+path only',
	);

	$probe = $req->get_param( 'resolve' );
	if ( $probe !== null && $probe !== '' ) {
		$hit = bsb_redirects_resolve( (string) $probe );
		$resp['resolve'] = array(
			'path'   => bsb_redirects_normalize_path( (string) $probe ),
			'match'  => $hit,
			'target' => $hit ? bsb_redirects_target_url( $hit['to'] ) : null,
		);
	}
	return rest_ensure_response( $resp );
}

/** PUT /redirects {path, to, status?, note?, confirm} — add or replace one rule. */
function bsb_redirects_put( WP_REST_Request $req ) {
	$v = bsb_redirects_validate( $req->get_param( 'path' ), $req->get_param( 'to' ), $req->get_param( 'status' ) );
	if ( is_wp_error( $v ) ) {
		return $v;
	}
	$rules  = bsb_redirects_all( true );
	$before = isset( $rules[ $v['path'] ] ) ? $rules[ $v['path'] ] : null;
	$note   = $req->get_param( 'note' );
	$rule   = array(
		'to'      => $v['to'],
		'status'  => $v['status'],
		'note'    => $note !== null ? sanitize_text_field( (string) $note ) : ( $before ? (string) $before['note'] : '' ),
		'updated' => current_time( 'c' ),
	);

	// What a real request would do once the rule is in — computed against the
	// candidate set, so the preview is the behaviour, not a restatement of the input.
	$candidate = $rules;
	$candidate[ $v['path'] ] = $rule;
	$example_path = substr( $v['path'], -2 ) === '/*' ? substr( $v['path'], 0, -2 ) . '/example' : $v['path'];
	$preview = array(
		'path'    => $v['path'],
		'before'  => $before,
		'after'   => $rule,
		'shadows' => $v['shadows'],
		'example' => array( 'request' => home_url( $example_path ), 'redirects_to' => bsb_redirects_target_url( str_replace( '*', 'example', $v['to'] ) ) ),
	);

	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array_merge( array( 'dry_run' => true, 'note' => 'confirm=true was not passed — nothing written' ), $preview ) );
	}

	if ( ! bsb_redirects_save( $candidate ) ) {
		return new WP_Error( 'bsb_write_failed', 'The option row does not hold the rule after writing — the redirect is NOT in place.', array( 'status' => 500 ) );
	}
	$live = bsb_redirects_resolve( $example_path );
	return rest_ensure_response( array_merge( array( 'updated' => true, 'persisted' => true ), $preview, array(
		'live' => array( 'request' => home_url( $example_path ), 'redirects_to' => $live ? bsb_redirects_target_url( $live['to'] ) : null, 'status' => $live ? $live['status'] : null ),
	) ) );
}

/** DELETE /redirects {path, confirm} — remove one rule. */
function bsb_redirects_delete( WP_REST_Request $req ) {
	$path = trim( (string) $req->get_param( 'path' ) );
	if ( $path === '' || $path[0] !== '/' ) {
		return new WP_Error( 'bsb_bad_path', '"path" must be the rule\'s site-relative path, starting with "/"', array( 'status' => 400 ) );
	}
	$path  = bsb_redirects_normalize_path( $path );
	$rules = bsb_redirects_all( true );
	if ( ! isset( $rules[ $path ] ) ) {
		return new WP_Error( 'bsb_not_found', 'No rule for ' . $path, array( 'status' => 404 ) );
	}
	$removed = $rules[ $path ];
	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array( 'dry_run' => true, 'note' => 'confirm=true was not passed — nothing removed', 'path' => $path, 'rule' => $removed ) );
	}
	unset( $rules[ $path ] );
	if ( ! bsb_redirects_save( $rules ) ) {
		return new WP_Error( 'bsb_write_failed', 'The option row still holds the rule after writing — it is NOT removed.', array( 'status' => 500 ) );
	}
	return rest_ensure_response( array( 'removed' => true, 'persisted' => true, 'path' => $path, 'rule' => $removed, 'remaining' => count( $rules ) ) );
}

/* ================================================================== *
 * MODULE: content — find and replace across posts, meta and options
 * ================================================================== *
 *
 * Core's ?search= only sees post content. The places a URL or a phrase actually
 * hides on this site are post META (Cornerstone stores every page's builder
 * layout as a JSON string in _cornerstone_data; menus keep their URLs in
 * _menu_item_url) and OPTIONS (widgets, plugin settings, redirect lists). On
 * 2026-09-03 a dead legacy link could not be located anywhere on the site
 * because none of those were searchable. This module is that search, plus the
 * replace the 2026-08 domain move needed.
 *
 * Two things a naive str_replace gets wrong, both handled here:
 *   - SERIALIZED values (most plugin options, ACF arrays). Replacing text inside a
 *     PHP-serialized string changes its length and corrupts the whole value. The
 *     value is unserialized, walked, and re-serialized instead.
 *   - JSON-ESCAPED slashes. Cornerstone's builder JSON stores "/contact/x" as
 *     "\/contact\/x", so a search for the plain path misses it. When the needle
 *     contains "/", its escaped twin is searched (and replaced) as well.
 *
 * Replace is confirm=true with a dry run that lists every row it would touch and
 * the occurrence count in each; it refuses needles under 4 characters and caps the
 * rows per call (default 200) so a broad needle can't run away.
 */

function bsb_content_areas( $in ) {
	$all = array( 'posts', 'meta', 'options' );
	if ( ! is_array( $in ) ) {
		$in = $in === null || $in === '' ? $all : array_map( 'trim', explode( ',', (string) $in ) );
	}
	return array_values( array_intersect( $all, array_map( 'strval', $in ) ) );
}

function bsb_content_snippet( $haystack, $needle, $radius = 60 ) {
	$pos = stripos( $haystack, $needle );
	if ( $pos === false ) {
		return null;
	}
	$start = max( 0, $pos - $radius );
	$snip  = substr( $haystack, $start, strlen( $needle ) + 2 * $radius );
	return ( $start > 0 ? '…' : '' ) . $snip . ( $start + strlen( $snip ) < strlen( $haystack ) ? '…' : '' );
}

/** The needle and, when it carries a slash, its JSON-escaped twin. */
function bsb_content_needles( $text ) {
	$n = array( $text );
	if ( strpos( $text, '/' ) !== false ) {
		$n[] = str_replace( '/', '\/', $text );
	}
	return $n;
}

/** Recursive, type-preserving replace through arrays/objects; returns [value, count]. */
function bsb_content_replace_deep( $value, array $from, array $to ) {
	$count = 0;
	if ( is_string( $value ) ) {
		$value = str_replace( $from, $to, $value, $c );
		return array( $value, (int) $c );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			list( $value[ $k ], $c ) = bsb_content_replace_deep( $v, $from, $to );
			$count += $c;
		}
		return array( $value, $count );
	}
	if ( is_object( $value ) ) {
		foreach ( get_object_vars( $value ) as $k => $v ) {
			list( $value->$k, $c ) = bsb_content_replace_deep( $v, $from, $to );
			$count += $c;
		}
		return array( $value, $count );
	}
	return array( $value, 0 );
}

/** Replace inside a raw DB string that may be serialized; returns [new_raw, count]. */
function bsb_content_replace_raw( $raw, array $from, array $to ) {
	if ( is_serialized( $raw ) ) {
		$data = @unserialize( $raw );
		if ( $data !== false || $raw === 'b:0;' ) {
			list( $data, $c ) = bsb_content_replace_deep( $data, $from, $to );
			return array( serialize( $data ), $c );
		}
	}
	$new = str_replace( $from, $to, $raw, $c );
	return array( $new, (int) $c );
}

function bsb_content_count( $raw, array $needles ) {
	$n = 0;
	foreach ( $needles as $needle ) {
		$n += substr_count( $raw, $needle );
	}
	return $n;
}

/**
 * Every row containing any needle, per area. Shared by find and replace so the
 * dry run and the write see the same rows.
 */
function bsb_content_scan( $text, array $areas, $post_type, $limit ) {
	global $wpdb;
	$needles = bsb_content_needles( $text );
	$likes   = array();
	foreach ( $needles as $n ) {
		$likes[] = '%' . $wpdb->esc_like( $n ) . '%';
	}
	$rows = array( 'posts' => array(), 'meta' => array(), 'options' => array() );
	$truncated = array();

	if ( in_array( 'posts', $areas, true ) ) {
		$where = array();
		$args  = array();
		foreach ( $likes as $l ) {
			$where[] = '(post_content LIKE %s OR post_title LIKE %s OR post_excerpt LIKE %s)';
			array_push( $args, $l, $l, $l );
		}
		$type_sql = "post_type NOT IN ('revision','auto-draft','oembed_cache','customize_changeset')";
		if ( $post_type ) {
			$type_sql = 'post_type = %s';
			$args[]   = $post_type;
		}
		$args[] = $limit + 1;
		$res    = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_type, post_status, post_title, post_name, post_content, post_excerpt FROM {$wpdb->posts}
			 WHERE (" . implode( ' OR ', $where ) . ") AND $type_sql AND post_status <> 'trash' ORDER BY ID DESC LIMIT %d",
			$args
		), ARRAY_A );
		if ( count( $res ) > $limit ) {
			$truncated[] = 'posts';
			$res = array_slice( $res, 0, $limit );
		}
		foreach ( $res as $r ) {
			$fields = array();
			foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $f ) {
				$c = bsb_content_count( (string) $r[ $f ], $needles );
				if ( $c ) {
					$fields[ $f ] = $c;
				}
			}
			$rows['posts'][] = array(
				'id'      => (int) $r['ID'],
				'type'    => $r['post_type'],
				'status'  => $r['post_status'],
				'title'   => $r['post_title'],
				'slug'    => $r['post_name'],
				'link'    => get_permalink( (int) $r['ID'] ),
				'fields'  => $fields,
				'snippet' => bsb_content_snippet( (string) $r['post_content'], $needles[0] ) ?: bsb_content_snippet( (string) $r['post_content'], end( $needles ) ),
			);
		}
	}

	if ( in_array( 'meta', $areas, true ) ) {
		$where = array();
		$args  = array();
		foreach ( $likes as $l ) {
			$where[] = 'm.meta_value LIKE %s';
			$args[]  = $l;
		}
		$type_sql = "p.post_type NOT IN ('revision','auto-draft')";
		if ( $post_type ) {
			$type_sql = 'p.post_type = %s';
			$args[]   = $post_type;
		}
		$args[] = $limit + 1;
		$res    = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value, p.post_type, p.post_title FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE (" . implode( ' OR ', $where ) . ") AND $type_sql AND m.meta_key NOT IN ('_edit_lock','_edit_last')
			 ORDER BY m.post_id DESC, m.meta_id ASC LIMIT %d",
			$args
		), ARRAY_A );
		if ( count( $res ) > $limit ) {
			$truncated[] = 'meta';
			$res = array_slice( $res, 0, $limit );
		}
		foreach ( $res as $r ) {
			$rows['meta'][] = array(
				'meta_id'     => (int) $r['meta_id'],
				'post_id'     => (int) $r['post_id'],
				'post_type'   => $r['post_type'],
				'post_title'  => $r['post_title'],
				'key'         => $r['meta_key'],
				'occurrences' => bsb_content_count( (string) $r['meta_value'], $needles ),
				'serialized'  => is_serialized( $r['meta_value'] ),
				'bytes'       => strlen( (string) $r['meta_value'] ),
				'snippet'     => bsb_content_snippet( (string) $r['meta_value'], $needles[0] ) ?: bsb_content_snippet( (string) $r['meta_value'], end( $needles ) ),
			);
		}
	}

	if ( in_array( 'options', $areas, true ) ) {
		$where = array();
		$args  = array();
		foreach ( $likes as $l ) {
			$where[] = 'option_value LIKE %s';
			$args[]  = $l;
		}
		$args[] = $limit + 1;
		$res    = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE (" . implode( ' OR ', $where ) . ") AND option_name NOT LIKE '\_transient\_%%' AND option_name NOT LIKE '\_site\_transient\_%%'
			 ORDER BY option_name LIMIT %d",
			$args
		), ARRAY_A );
		if ( count( $res ) > $limit ) {
			$truncated[] = 'options';
			$res = array_slice( $res, 0, $limit );
		}
		foreach ( $res as $r ) {
			$secret = bsb_options_is_secret( $r['option_name'] );
			$rows['options'][] = array(
				'name'        => $r['option_name'],
				'occurrences' => bsb_content_count( (string) $r['option_value'], $needles ),
				'serialized'  => is_serialized( $r['option_value'] ),
				'bytes'       => strlen( (string) $r['option_value'] ),
				'writable'    => ! $secret && ! in_array( $r['option_name'], bsb_options_blocked_write(), true ),
				'snippet'     => $secret ? '(credential-looking name — content withheld)' : ( bsb_content_snippet( (string) $r['option_value'], $needles[0] ) ?: bsb_content_snippet( (string) $r['option_value'], end( $needles ) ) ),
			);
		}
	}

	return array( 'needles' => $needles, 'rows' => $rows, 'truncated' => $truncated );
}

/** GET /content/find?text=&in=posts,meta,options&post_type=&limit= */
function bsb_content_find( WP_REST_Request $req ) {
	$text = (string) $req->get_param( 'text' );
	if ( strlen( $text ) < 3 ) {
		return new WP_Error( 'bsb_short_needle', 'Pass ?text= with at least 3 characters', array( 'status' => 400 ) );
	}
	$areas = bsb_content_areas( $req->get_param( 'in' ) );
	if ( ! $areas ) {
		return new WP_Error( 'bsb_bad_area', '"in" must be some of posts, meta, options', array( 'status' => 400 ) );
	}
	$limit = min( 500, max( 1, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
	$scan  = bsb_content_scan( $text, $areas, (string) $req->get_param( 'post_type' ), $limit );
	return rest_ensure_response( array(
		'text'      => $text,
		'searched'  => $scan['needles'],
		'counts'    => array_map( 'count', $scan['rows'] ),
		'truncated' => $scan['truncated'],
		'results'   => $scan['rows'],
	) );
}

/** POST /content/replace {from, to, in?, post_type?, limit?, confirm, dry_run} */
function bsb_content_replace( WP_REST_Request $req ) {
	global $wpdb;
	$from = (string) $req->get_param( 'from' );
	$to   = (string) $req->get_param( 'to' );
	if ( strlen( $from ) < 4 ) {
		return new WP_Error( 'bsb_short_needle', '"from" must be at least 4 characters — anything shorter replaces far more than intended', array( 'status' => 400 ) );
	}
	if ( $from === $to ) {
		return new WP_Error( 'bsb_noop', '"from" and "to" are identical', array( 'status' => 400 ) );
	}
	$areas = bsb_content_areas( $req->get_param( 'in' ) );
	if ( ! $areas ) {
		return new WP_Error( 'bsb_bad_area', '"in" must be some of posts, meta, options', array( 'status' => 400 ) );
	}
	$limit = min( 2000, max( 1, (int) ( $req->get_param( 'limit' ) ?: 200 ) ) );
	$scan  = bsb_content_scan( $from, $areas, (string) $req->get_param( 'post_type' ), $limit );
	if ( $scan['truncated'] ) {
		return new WP_Error( 'bsb_too_many', 'More than ' . $limit . ' matching rows in: ' . implode( ', ', $scan['truncated'] ) . ' — narrow with in=/post_type= or raise limit (max 2000) deliberately', array( 'status' => 409 ) );
	}
	$needles_from = bsb_content_needles( $from );
	$needles_to   = bsb_content_needles( $to );
	if ( count( $needles_to ) !== count( $needles_from ) ) {
		// "from" has a slash and "to" doesn't (or vice versa): map both variants of from to the plain to.
		$needles_to = array_fill( 0, count( $needles_from ), $to );
		if ( strpos( $to, '/' ) !== false && count( $needles_from ) === 2 ) {
			$needles_to = array( $to, str_replace( '/', '\/', $to ) );
		}
	}

	// Options that must not be touched are dropped from the plan, not silently rewritten.
	$skipped = array();
	foreach ( $scan['rows']['options'] as $i => $o ) {
		if ( ! $o['writable'] ) {
			$skipped[] = $o['name'];
			unset( $scan['rows']['options'][ $i ] );
		}
	}
	$scan['rows']['options'] = array_values( $scan['rows']['options'] );
	$total = array_map( 'count', $scan['rows'] );

	$dry = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );
	if ( $dry || ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array(
			'dry_run'         => true,
			'note'            => $dry ? 'dry_run=true — nothing written' : 'confirm=true was not passed — nothing written',
			'from'            => $from,
			'to'              => $to,
			'searched'        => $needles_from,
			'would_touch'     => $total,
			'skipped_options' => $skipped,
			'plan'            => $scan['rows'],
		) );
	}

	$done = array( 'posts' => array(), 'meta' => array(), 'options' => array() );
	foreach ( $scan['rows']['posts'] as $p ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $p['id'] ), ARRAY_A );
		if ( ! $row ) {
			continue;
		}
		$upd = array();
		$c   = 0;
		foreach ( $row as $f => $v ) {
			$new = str_replace( $needles_from, $needles_to, (string) $v, $cc );
			if ( $cc ) {
				$upd[ $f ] = $new;
				$c        += $cc;
			}
		}
		if ( $upd ) {
			$wpdb->update( $wpdb->posts, $upd, array( 'ID' => $p['id'] ) );
			clean_post_cache( $p['id'] );
		}
		$done['posts'][] = array( 'id' => $p['id'], 'title' => $p['title'], 'replaced' => $c, 'fields' => array_keys( $upd ) );
	}
	foreach ( $scan['rows']['meta'] as $m ) {
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $m['meta_id'] ) );
		if ( $raw === null ) {
			continue;
		}
		list( $new, $c ) = bsb_content_replace_raw( (string) $raw, $needles_from, $needles_to );
		if ( $c && $new !== $raw ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $m['meta_id'] ) );
			wp_cache_delete( $m['post_id'], 'post_meta' );
		}
		$done['meta'][] = array( 'meta_id' => $m['meta_id'], 'post_id' => $m['post_id'], 'key' => $m['key'], 'replaced' => $c );
	}
	foreach ( $scan['rows']['options'] as $o ) {
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $o['name'] ) );
		if ( $raw === null ) {
			continue;
		}
		list( $new, $c ) = bsb_content_replace_raw( (string) $raw, $needles_from, $needles_to );
		if ( $c && $new !== $raw ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $new ), array( 'option_name' => $o['name'] ) );
			wp_cache_delete( $o['name'], 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		$done['options'][] = array( 'name' => $o['name'], 'replaced' => $c );
	}

	// Read back: anything still matching is a row this pass could not rewrite.
	$after = bsb_content_scan( $from, $areas, (string) $req->get_param( 'post_type' ), $limit );
	$left  = array_map( 'count', $after['rows'] );
	return rest_ensure_response( array(
		'replaced'        => true,
		'from'            => $from,
		'to'              => $to,
		'touched'         => array_map( 'count', $done ),
		'skipped_options' => $skipped,
		'still_matching'  => $left,
		'details'         => $done,
		'note'            => 'Rows were written directly (no save hooks fired). Run POST /site/purge-cache so the public pages re-render.',
	) );
}

/* ================================================================== *
 * MODULE: files — the SFTP replacement, fenced
 * ================================================================== *
 *
 * This host gives us no SFTP, the Theme File Editor can't save (its loopback
 * check is blocked by the firewall in front of the site), and the same firewall
 * 403s any request body containing PHP source. So "put PHP on the site" has been
 * impossible from anywhere but the host's own file manager. This module is the
 * hatch: file content travels base64-encoded, and the writable area is exactly
 * two roots — wp-content/mu-plugins and the active child theme. Nothing else is
 * addressable, and this plugin's own directory is deliberately not among them.
 *
 * WHY IT IS CAREFUL. A PHP file in mu-plugins is executed on EVERY request with
 * no activation step; a parse error there white-screens the whole site, and so
 * does the REST API — meaning the tool that made the mistake can't undo it. So:
 *   - .php content is syntax-checked BEFORE it touches disk: `php -l` when exec
 *     is available, otherwise opcache_compile_file() (compiles without running;
 *     a ParseError is caught). No lint path available → .php is refused unless
 *     force=true, and the caller should have linted locally.
 *   - function names declared in the new file that already exist at runtime but
 *     were NOT declared by the file's current version are reported as a probable
 *     redeclaration fatal; the write needs force=true to proceed.
 *   - every overwrite and delete first copies the current file to a backup dir
 *     OUTSIDE both roots (a *.bak in mu-plugins would itself be auto-loaded),
 *     under wp-content/bsb-backups/<random>/, and reports the backup path;
 *     POST /files/restore puts one back.
 *   - overwriting needs expected_sha1 = the sha1 of what is there now, so two
 *     people can't clobber each other, and a caller always reads before writing.
 *   - allowed extensions only: php css js json txt md html svg xml. No dotfiles.
 * Writes are confirm=true two-phase; the dry run reports the lint verdict and the
 * size/line delta, so a bad file is caught before the token is even issued.
 */

const BSB_FILES_MAX_BYTES = 2 * 1024 * 1024;

function bsb_files_root_dir( $root ) {
	if ( $root === 'mu-plugins' ) {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	}
	if ( $root === 'theme' ) {
		return get_stylesheet_directory();
	}
	return null;
}

function bsb_files_allowed_ext() {
	return array( 'php', 'css', 'js', 'json', 'txt', 'md', 'html', 'svg', 'xml' );
}

/** Backup directory (created on demand, randomised once, index.php dropped in). */
function bsb_files_backup_dir() {
	$token = bsb_option_read( 'bsb_files_backup_token' );
	if ( $token === '' ) {
		$token = wp_generate_password( 16, false, false );
		bsb_option_write( 'bsb_files_backup_token', $token );
	}
	$dir = WP_CONTENT_DIR . '/bsb-backups/' . $token;
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( WP_CONTENT_DIR . '/bsb-backups/index.php', "<?php // silence\n" );
		@file_put_contents( $dir . '/index.php', "<?php // silence\n" );
	}
	return $dir;
}

function bsb_files_exec_available() {
	if ( ! function_exists( 'exec' ) ) {
		return false;
	}
	$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
	return ! in_array( 'exec', $disabled, true );
}

/** Path to a CLI php binary usable for -l, or ''. */
function bsb_files_php_cli() {
	$candidates = array();
	if ( defined( 'PHP_BINARY' ) && PHP_BINARY && strpos( PHP_BINARY, 'fpm' ) === false ) {
		$candidates[] = PHP_BINARY;
	}
	if ( defined( 'PHP_BINDIR' ) ) {
		$candidates[] = PHP_BINDIR . '/php';
	}
	array_push( $candidates, '/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
	foreach ( array_unique( $candidates ) as $c ) {
		if ( $c && @is_executable( $c ) ) {
			return $c;
		}
	}
	return '';
}

function bsb_files_capabilities() {
	$mu    = bsb_files_root_dir( 'mu-plugins' );
	$theme = bsb_files_root_dir( 'theme' );
	return array(
		'mu_plugins_dir'      => $mu,
		'mu_plugins_writable' => is_dir( $mu ) ? wp_is_writable( $mu ) : wp_is_writable( dirname( $mu ) ),
		'theme_dir'           => $theme,
		'theme_writable'      => wp_is_writable( $theme ),
		'lint'                => bsb_files_exec_available() && bsb_files_php_cli() !== '' ? 'php -l' : ( function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' ) ? 'token_get_all' : ( function_exists( 'opcache_compile_file' ) ? 'opcache_compile_file' : 'none' ) ),
	);
}

/**
 * Resolve root+relative path to an absolute path INSIDE the root. Returns
 * array( abs, rel ) or WP_Error. Refuses traversal, dotfiles, unknown extensions.
 */
function bsb_files_resolve( $root, $rel, $must_exist = false ) {
	$dir = bsb_files_root_dir( $root );
	if ( ! $dir ) {
		return new WP_Error( 'bsb_bad_root', 'root must be mu-plugins or theme', array( 'status' => 400 ) );
	}
	$rel = str_replace( '\\', '/', (string) $rel );
	$rel = trim( preg_replace( '#/+#', '/', $rel ), '/' );
	if ( $rel === '' || strpos( $rel, "\0" ) !== false ) {
		return new WP_Error( 'bsb_bad_path', 'A file path is required', array( 'status' => 400 ) );
	}
	foreach ( explode( '/', $rel ) as $seg ) {
		if ( $seg === '.' || $seg === '..' || $seg === '' || $seg[0] === '.' ) {
			return new WP_Error( 'bsb_bad_path', 'Path segments may not be ".", ".." or start with a dot', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._\-]+$/', $seg ) ) {
			return new WP_Error( 'bsb_bad_path', "Unexpected characters in path segment \"$seg\"", array( 'status' => 400 ) );
		}
	}
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, bsb_files_allowed_ext(), true ) ) {
		return new WP_Error( 'bsb_bad_ext', 'Extension must be one of ' . implode( ', ', bsb_files_allowed_ext() ), array( 'status' => 400 ) );
	}
	$abs      = rtrim( $dir, '/' ) . '/' . $rel;
	$real_dir = realpath( $dir );
	$real     = file_exists( $abs ) ? realpath( $abs ) : realpath( dirname( $abs ) ) . '/' . basename( $abs );
	if ( ! $real_dir || ! $real || strpos( $real, rtrim( $real_dir, '/' ) . '/' ) !== 0 ) {
		return new WP_Error( 'bsb_bad_path', 'Path resolves outside the root', array( 'status' => 400 ) );
	}
	if ( file_exists( $real ) && is_link( $abs ) ) {
		return new WP_Error( 'bsb_bad_path', 'Refusing to operate on a symlink', array( 'status' => 400 ) );
	}
	if ( $must_exist && ! is_file( $real ) ) {
		return new WP_Error( 'bsb_not_found', "No file at $root/$rel", array( 'status' => 404 ) );
	}
	return array( $real, $rel );
}

/** Syntax-check PHP source. Returns array( ok bool|null, method, message ). null = no lint available. */
function bsb_files_lint_php( $source ) {
	$tmp_dir = get_temp_dir();
	$tmp     = rtrim( $tmp_dir, '/' ) . '/bsb-lint-' . wp_generate_password( 8, false, false ) . '.php';
	if ( @file_put_contents( $tmp, $source ) === false ) {
		return array( null, 'none', 'could not write a temp file to lint' );
	}
	try {
		$php = bsb_files_exec_available() ? bsb_files_php_cli() : '';
		if ( $php !== '' ) {
			$out  = array();
			$code = 1;
			@exec( escapeshellarg( $php ) . ' -d display_errors=1 -l ' . escapeshellarg( $tmp ) . ' 2>&1', $out, $code );
			$msg = trim( str_replace( $tmp, '<file>', implode( "\n", $out ) ) );
			return array( $code === 0, 'php -l', $msg );
		}
		// The tokenizer in TOKEN_PARSE mode runs the real parser without executing
		// anything and throws ParseError on a syntax error — no exec, no opcache, no
		// temp file needed. Available since PHP 7.0.
		if ( function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' ) ) {
			try {
				token_get_all( $source, TOKEN_PARSE );
				return array( true, 'token_get_all', 'No syntax errors detected' );
			} catch ( \ParseError $e ) {
				return array( false, 'token_get_all', 'Parse error: ' . $e->getMessage() . ' on line ' . $e->getLine() );
			} catch ( \Throwable $e ) {
				// fall through to the next method
			}
		}
		if ( function_exists( 'opcache_compile_file' ) ) {
			try {
				$ok = @opcache_compile_file( $tmp );
				// false without a ParseError means "could not compile HERE" (restricted
				// paths, disabled for this SAPI) — indeterminate, not invalid.
				return array( $ok ? true : null, 'opcache_compile_file', $ok ? 'No syntax errors detected' : 'opcache_compile_file returned false — lint indeterminate on this host; lint locally and pass force=true' );
			} catch ( \ParseError $e ) {
				return array( false, 'opcache_compile_file', 'Parse error: ' . $e->getMessage() . ' on line ' . $e->getLine() );
			} catch ( \Throwable $e ) {
				return array( false, 'opcache_compile_file', get_class( $e ) . ': ' . $e->getMessage() );
			}
		}
		return array( null, 'none', 'no lint path on this host (exec disabled, no opcache) — lint locally and pass force=true' );
	} finally {
		@unlink( $tmp );
	}
}

/** Top-level-ish function names declared in PHP source (cheap regex; class methods excluded by indentation heuristics are NOT attempted — names are just names). */
function bsb_files_declared_functions( $source ) {
	preg_match_all( '/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', (string) $source, $m );
	return array_values( array_unique( $m[1] ) );
}

function bsb_files_describe( $abs, $rel ) {
	$content = (string) file_get_contents( $abs );
	return array(
		'path'     => $rel,
		'bytes'    => strlen( $content ),
		'lines'    => $content === '' ? 0 : substr_count( $content, "\n" ) + 1,
		'sha1'     => sha1( $content ),
		'modified' => gmdate( 'c', (int) filemtime( $abs ) ),
	);
}

/** GET /files/{root} — list, or with ?path= read one file. The path is a parameter on purpose (see the header). */
function bsb_files_list_or_get( WP_REST_Request $req ) {
	$path = (string) $req->get_param( 'path' );
	return $path !== '' ? bsb_files_get( $req ) : bsb_files_list( $req );
}

/** every allowed-extension file under the root (recursive), no content. */
function bsb_files_list( WP_REST_Request $req ) {
	$root = (string) $req->get_param( 'root' );
	$dir  = bsb_files_root_dir( $root );
	if ( ! $dir ) {
		return new WP_Error( 'bsb_bad_root', 'root must be mu-plugins or theme', array( 'status' => 400 ) );
	}
	$out = array();
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			/** @var SplFileInfo $f */
			if ( ! $f->isFile() || $it->getDepth() > 6 ) {
				continue;
			}
			$rel = ltrim( str_replace( rtrim( $dir, '/' ), '', str_replace( '\\', '/', $f->getPathname() ) ), '/' );
			if ( strpos( '/' . $rel, '/.' ) !== false ) {
				continue;
			}
			if ( ! in_array( strtolower( $f->getExtension() ), bsb_files_allowed_ext(), true ) ) {
				continue;
			}
			$out[] = array( 'path' => $rel, 'bytes' => $f->getSize(), 'modified' => gmdate( 'c', $f->getMTime() ) );
			if ( count( $out ) >= 500 ) {
				break;
			}
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
	return rest_ensure_response( array( 'root' => $root, 'dir' => $dir, 'exists' => is_dir( $dir ), 'count' => count( $out ), 'files' => $out, 'capabilities' => bsb_files_capabilities() ) );
}

function bsb_files_get( WP_REST_Request $req ) {
	$r = bsb_files_resolve( (string) $req->get_param( 'root' ), (string) $req->get_param( 'path' ), true );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	list( $abs, $rel ) = $r;
	$content = (string) file_get_contents( $abs );
	return rest_ensure_response( array_merge( array( 'root' => $req->get_param( 'root' ) ), bsb_files_describe( $abs, $rel ), array(
		'content_base64' => base64_encode( $content ),
		'declares'       => strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ) === 'php' ? bsb_files_declared_functions( $content ) : null,
	) ) );
}

/** Copy the current file to the backup dir; returns the backup's basename. */
function bsb_files_backup( $abs, $root, $rel ) {
	$dir  = bsb_files_backup_dir();
	$name = $root . '__' . str_replace( '/', '__', $rel ) . '.' . gmdate( 'Ymd-His' ) . '.bak';
	if ( ! @copy( $abs, $dir . '/' . $name ) ) {
		return new WP_Error( 'bsb_backup_failed', 'Could not write the backup — refusing to change the file', array( 'status' => 500 ) );
	}
	return $name;
}

/** PUT /files/{root}/{path} {content_base64, expected_sha1?, force?, confirm} */
function bsb_files_put( WP_REST_Request $req ) {
	$root = (string) $req->get_param( 'root' );
	$r    = bsb_files_resolve( $root, (string) $req->get_param( 'path' ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	list( $abs, $rel ) = $r;
	$b64 = (string) $req->get_param( 'content_base64' );
	if ( $b64 === '' ) {
		return new WP_Error( 'bsb_no_content', 'content_base64 is required (base64 of the full new file)', array( 'status' => 400 ) );
	}
	$content = base64_decode( $b64, true );
	if ( $content === false ) {
		return new WP_Error( 'bsb_bad_base64', 'content_base64 is not valid base64', array( 'status' => 400 ) );
	}
	if ( strlen( $content ) > BSB_FILES_MAX_BYTES ) {
		return new WP_Error( 'bsb_too_big', 'File exceeds ' . BSB_FILES_MAX_BYTES . ' bytes', array( 'status' => 413 ) );
	}
	$exists  = is_file( $abs );
	$current = $exists ? bsb_files_describe( $abs, $rel ) : null;
	$force   = filter_var( $req->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
	$is_php  = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ) === 'php';

	if ( $exists ) {
		$expected = (string) $req->get_param( 'expected_sha1' );
		if ( $expected === '' ) {
			return new WP_Error( 'bsb_sha_required', 'This file exists — pass expected_sha1 (its current sha1 is ' . $current['sha1'] . ', from GET) so a stale copy can\'t overwrite a newer one', array( 'status' => 409, 'current_sha1' => $current['sha1'] ) );
		}
		if ( ! hash_equals( $current['sha1'], strtolower( $expected ) ) ) {
			return new WP_Error( 'bsb_sha_mismatch', 'expected_sha1 does not match the file on disk (' . $current['sha1'] . ') — it changed since you read it. Re-read and try again.', array( 'status' => 409, 'current_sha1' => $current['sha1'] ) );
		}
		if ( $current['sha1'] === sha1( $content ) ) {
			return rest_ensure_response( array( 'updated' => false, 'note' => 'Identical content — nothing to write', 'file' => $current ) );
		}
	}

	$lint = array( null, 'n/a', 'not PHP' );
	$redeclare = array();
	if ( $is_php ) {
		if ( strpos( ltrim( $content ), '<?php' ) !== 0 ) {
			return new WP_Error( 'bsb_not_php', 'A .php file must start with <?php', array( 'status' => 400 ) );
		}
		$lint = bsb_files_lint_php( $content );
		if ( $lint[0] === false ) {
			return new WP_Error( 'bsb_lint_failed', 'PHP syntax check failed (' . $lint[1] . '): ' . $lint[2], array( 'status' => 422 ) );
		}
		if ( $lint[0] === null && ! $force ) {
			return new WP_Error( 'bsb_no_lint', $lint[2], array( 'status' => 501 ) );
		}
		$old_names = $exists ? bsb_files_declared_functions( (string) file_get_contents( $abs ) ) : array();
		foreach ( bsb_files_declared_functions( $content ) as $fn ) {
			if ( function_exists( $fn ) && ! in_array( $fn, $old_names, true ) ) {
				$redeclare[] = $fn;
			}
		}
		if ( $redeclare && ! $force ) {
			return new WP_Error( 'bsb_redeclare', 'These functions already exist at runtime and are not declared by the current version of this file — loading it would fatal unless each is function_exists()-guarded: ' . implode( ', ', $redeclare ) . '. Pass force=true only if they are guarded.', array( 'status' => 409, 'functions' => $redeclare ) );
		}
	}

	$preview = array(
		'root'      => $root,
		'path'      => $rel,
		'exists'    => $exists,
		'current'   => $current,
		'new'       => array( 'bytes' => strlen( $content ), 'lines' => $content === '' ? 0 : substr_count( $content, "\n" ) + 1, 'sha1' => sha1( $content ) ),
		'lint'      => array( 'ok' => $lint[0], 'method' => $lint[1], 'message' => $lint[2] ),
		'redeclare' => $redeclare,
		'backup_to' => $exists ? bsb_files_backup_dir() : null,
	);
	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array_merge( array( 'dry_run' => true, 'note' => 'confirm=true was not passed — nothing written' ), $preview ) );
	}

	$backup = null;
	if ( $exists ) {
		$backup = bsb_files_backup( $abs, $root, $rel );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
	} elseif ( ! is_dir( dirname( $abs ) ) && ! wp_mkdir_p( dirname( $abs ) ) ) {
		return new WP_Error( 'bsb_mkdir_failed', 'Could not create ' . dirname( $rel ), array( 'status' => 500 ) );
	}
	// Write beside, then rename: a reader (or the autoloader) never sees a half file.
	$tmp = $abs . '.bsb-tmp-' . wp_generate_password( 6, false, false );
	if ( @file_put_contents( $tmp, $content ) !== strlen( $content ) || ! @rename( $tmp, $abs ) ) {
		@unlink( $tmp );
		return new WP_Error( 'bsb_write_failed', 'Could not write the file (permissions?)', array( 'status' => 500 ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $abs, true );
	}
	$after = bsb_files_describe( $abs, $rel );
	return rest_ensure_response( array_merge( array(
		'updated'   => true,
		'persisted' => $after['sha1'] === sha1( $content ),
		'backup'    => $backup,
		'after'     => $after,
		'note'      => $is_php && $root === 'mu-plugins' ? 'mu-plugin written — it runs on the NEXT request. Immediately GET /site; if that fails, the host file manager and the backup above are the way back.' : null,
	), $preview ) );
}

/** DELETE /files/{root}/{path} {confirm} — moves the file to backups rather than deleting. */
function bsb_files_delete( WP_REST_Request $req ) {
	$root = (string) $req->get_param( 'root' );
	$r    = bsb_files_resolve( $root, (string) $req->get_param( 'path' ), true );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	list( $abs, $rel ) = $r;
	$current = bsb_files_describe( $abs, $rel );
	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array( 'dry_run' => true, 'note' => 'confirm=true was not passed — nothing removed', 'root' => $root, 'file' => $current, 'backup_to' => bsb_files_backup_dir() ) );
	}
	$backup = bsb_files_backup( $abs, $root, $rel );
	if ( is_wp_error( $backup ) ) {
		return $backup;
	}
	if ( ! @unlink( $abs ) ) {
		return new WP_Error( 'bsb_delete_failed', 'Backup written but the file could not be removed', array( 'status' => 500, 'backup' => $backup ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $abs, true );
	}
	return rest_ensure_response( array( 'removed' => true, 'root' => $root, 'file' => $current, 'backup' => $backup ) );
}

/** POST /files/restore {root, path, backup, confirm} — put a backup back (current file is backed up first). */
function bsb_files_restore( WP_REST_Request $req ) {
	$root = (string) $req->get_param( 'root' );
	$r    = bsb_files_resolve( $root, (string) $req->get_param( 'path' ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	list( $abs, $rel ) = $r;
	$name = basename( (string) $req->get_param( 'backup' ) );
	$src  = bsb_files_backup_dir() . '/' . $name;
	if ( $name === '' || ! preg_match( '/\.bak$/', $name ) || ! is_file( $src ) ) {
		return new WP_Error( 'bsb_no_backup', 'No such backup', array( 'status' => 404 ) );
	}
	$content = (string) file_get_contents( $src );
	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array( 'dry_run' => true, 'note' => 'confirm=true was not passed — nothing restored', 'root' => $root, 'path' => $rel, 'backup' => $name, 'backup_sha1' => sha1( $content ), 'current' => is_file( $abs ) ? bsb_files_describe( $abs, $rel ) : null ) );
	}
	$prior = null;
	if ( is_file( $abs ) ) {
		$prior = bsb_files_backup( $abs, $root, $rel );
		if ( is_wp_error( $prior ) ) {
			return $prior;
		}
	}
	$tmp = $abs . '.bsb-tmp-' . wp_generate_password( 6, false, false );
	if ( @file_put_contents( $tmp, $content ) !== strlen( $content ) || ! @rename( $tmp, $abs ) ) {
		@unlink( $tmp );
		return new WP_Error( 'bsb_write_failed', 'Could not restore (permissions?)', array( 'status' => 500 ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $abs, true );
	}
	return rest_ensure_response( array( 'restored' => true, 'root' => $root, 'path' => $rel, 'from_backup' => $name, 'replaced_backed_up_as' => $prior, 'after' => bsb_files_describe( $abs, $rel ) ) );
}

/* ================================================================== *
 * MODULE: posts — create, read and update posts of any post type
 * ================================================================== *
 *
 * Core REST creates posts, but only for a logged-in WordPress user holding
 * the capability; this plugin's shared secret grants no such user, so a push
 * from Atlas or Claude Code previously meant minting an application password
 * or hand-writing meta. This module closes that gap (v0.13.0).
 *
 * ACF fields are written through update_field(), NOT as raw meta, so ACF
 * itself maintains the `name` => value and `_name` => field-key pairs that
 * the meta module makes you supply by hand. That is what makes repeaters
 * writable here: pass acf: { articles: [ {...}, {...} ] } and ACF unrolls the
 * rows into articles_0_*, articles_1_* with every key in place. Writing that
 * by hand is ~16 meta entries per row and one missed `_name` leaves the field
 * silently inert.
 *
 * Refuses post types that belong to another module (events) or that hold
 * builder and plugin internals, where a blind insert corrupts more than it
 * writes.
 */

/** Post types this module will not touch, and why. */
function bsb_posts_blocked() {
	return array(
		'tribe_events'      => 'use the events module — recurrence and occurrences need it',
		'attachment'        => 'media needs a real upload, not a post insert',
		'revision'          => 'not a content type',
		'acf-field'         => 'ACF storage — field groups belong in code',
		'acf-field-group'   => 'ACF storage — field groups belong in code',
		'acf-post-type'     => 'ACF storage — post types belong in code',
		'acf-taxonomy'      => 'ACF storage — taxonomies belong in code',
		'cs_template'       => 'Cornerstone layout data — edit in the builder',
		'cs_layout'         => 'Cornerstone layout data — edit in the builder',
		'cs_layout_single'  => 'Cornerstone layout data — edit in the builder',
		'cs_layout_archive' => 'Cornerstone layout data — edit in the builder',
		'cs_header'         => 'Cornerstone layout data — edit in the builder',
		'cs_footer'         => 'Cornerstone layout data — edit in the builder',
		'cs_global_block'   => 'Cornerstone layout data — edit in the builder',
	);
}

/** Compact description of a post — what a caller needs to verify a write. */
function bsb_posts_describe( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return null;
	}
	return array(
		'id'         => (int) $post->ID,
		'post_type'  => $post->post_type,
		'status'     => $post->post_status,
		'title'      => $post->post_title,
		'slug'       => $post->post_name,
		'author'     => (int) $post->post_author,
		'permalink'  => get_permalink( $post ),
		'content_chars' => strlen( (string) $post->post_content ),
		'modified'   => $post->post_modified_gmt,
	);
}

/**
 * Per-field shape of the post's ACF values, read back from the database.
 * Deliberately a summary, not the values: it is the cheap diff that tells you
 * a repeater actually landed nine rows with the right sub-keys, without
 * echoing 50KB of body copy back at the caller. Pass full=true for values.
 */
function bsb_posts_acf_summary( $post_id ) {
	if ( ! function_exists( 'get_fields' ) ) {
		return null;
	}
	$fields = get_fields( $post_id );
	if ( ! $fields ) {
		return array();
	}
	$out = array();
	foreach ( $fields as $name => $value ) {
		if ( is_array( $value ) ) {
			$row = array( 'type' => 'array', 'count' => count( $value ) );
			$first = reset( $value );
			if ( is_array( $first ) ) {
				$row['row_keys'] = array_keys( $first );
				$empty = array();
				foreach ( array_keys( $first ) as $k ) {
					$blank = 0;
					foreach ( $value as $r ) {
						if ( ! isset( $r[ $k ] ) || $r[ $k ] === '' || $r[ $k ] === array() ) {
							$blank++;
						}
					}
					if ( $blank ) {
						$empty[ $k ] = $blank;
					}
				}
				if ( $empty ) {
					$row['blank_by_key'] = $empty;
				}
			}
			$out[ $name ] = $row;
		} elseif ( is_string( $value ) ) {
			$out[ $name ] = array( 'type' => 'string', 'chars' => strlen( $value ) );
		} else {
			$out[ $name ] = array( 'type' => gettype( $value ), 'value' => $value );
		}
	}
	return $out;
}

/** Default author: the lowest-ID administrator, so posts don't land ownerless. */
function bsb_posts_default_author() {
	$admins = get_users( array(
		'role'    => 'administrator',
		'orderby' => 'ID',
		'order'   => 'ASC',
		'number'  => 1,
		'fields'  => 'ID',
	) );
	return $admins ? (int) $admins[0] : 0;
}

/** Write acf / meta / terms / featured image. Returns human notes. */
function bsb_posts_apply_extras( $post_id, WP_REST_Request $req ) {
	$notes = array();

	$acf = $req->get_param( 'acf' );
	if ( is_array( $acf ) && $acf ) {
		if ( ! function_exists( 'update_field' ) ) {
			$notes[] = 'acf IGNORED — ACF is not active on this site';
		} else {
			foreach ( $acf as $name => $value ) {
				update_field( (string) $name, $value, $post_id );
			}
			$notes[] = 'acf written: ' . implode( ', ', array_keys( $acf ) );
		}
	}

	$meta = $req->get_param( 'meta' );
	if ( is_array( $meta ) && $meta ) {
		$blocked = bsb_meta_blocked();
		foreach ( $meta as $key => $value ) {
			if ( in_array( (string) $key, $blocked, true ) ) {
				$notes[] = "meta $key refused (blocklisted)";
				continue;
			}
			update_post_meta( $post_id, (string) $key, $value );
		}
		$notes[] = 'meta written: ' . implode( ', ', array_keys( $meta ) );
	}

	$terms = $req->get_param( 'terms' );
	if ( is_array( $terms ) && $terms ) {
		foreach ( $terms as $tax => $vals ) {
			if ( ! taxonomy_exists( (string) $tax ) ) {
				$notes[] = "taxonomy $tax does not exist — skipped";
				continue;
			}
			$vals = is_array( $vals ) ? $vals : array( $vals );
			$list = array();
			foreach ( $vals as $v ) {
				$list[] = is_numeric( $v ) ? (int) $v : (string) $v;
			}
			$res = wp_set_object_terms( $post_id, $list, (string) $tax );
			$notes[] = is_wp_error( $res ) ? "terms on $tax failed: " . $res->get_error_message() : "terms set on $tax";
		}
	}

	$thumb = $req->get_param( 'featured_image' );
	if ( $thumb ) {
		set_post_thumbnail( $post_id, (int) $thumb );
		$notes[] = 'featured image set to ' . (int) $thumb;
	}

	return $notes;
}

/** Shared post-args builder. $existing = null on create. */
function bsb_posts_args( WP_REST_Request $req, $existing = null ) {
	$args = array();
	$map  = array(
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
		'status'  => 'post_status',
	);
	foreach ( $map as $param => $field ) {
		$v = $req->get_param( $param );
		if ( $v !== null ) {
			$args[ $field ] = (string) $v;
		}
	}
	$slug = $req->get_param( 'slug' );
	if ( $slug !== null && (string) $slug !== '' ) {
		$args['post_name'] = sanitize_title( (string) $slug );
	}
	foreach ( array( 'parent' => 'post_parent', 'menu_order' => 'menu_order', 'author' => 'post_author' ) as $param => $field ) {
		$v = $req->get_param( $param );
		if ( $v !== null && $v !== '' ) {
			$args[ $field ] = (int) $v;
		}
	}
	$date = $req->get_param( 'date' );
	if ( $date !== null && (string) $date !== '' ) {
		$args['post_date'] = (string) $date;
	}
	return $args;
}

/**
 * POST /posts {post_type, title, status?, slug?, content?, excerpt?, parent?,
 *              menu_order?, author?, date?, acf?, meta?, terms?,
 *              featured_image?, confirm, dry_run}
 */
function bsb_posts_create( WP_REST_Request $req ) {
	$dry  = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );
	$type = (string) $req->get_param( 'post_type' );

	if ( $type === '' ) {
		return new WP_Error( 'bsb_bad_input', 'post_type is required', array( 'status' => 400 ) );
	}
	$blocked = bsb_posts_blocked();
	if ( isset( $blocked[ $type ] ) ) {
		return new WP_Error( 'bsb_type_blocked', sprintf( 'post_type "%s" is refused here: %s', $type, $blocked[ $type ] ), array( 'status' => 400 ) );
	}
	if ( ! post_type_exists( $type ) ) {
		return new WP_Error( 'bsb_type_unknown', sprintf( 'post_type "%s" is not registered — check GET /site for the list', $type ), array( 'status' => 400 ) );
	}

	$title = (string) $req->get_param( 'title' );
	$slug  = sanitize_title( (string) $req->get_param( 'slug' ) );
	if ( $title === '' && $slug === '' ) {
		return new WP_Error( 'bsb_bad_input', 'title or slug is required', array( 'status' => 400 ) );
	}

	$collision = null;
	if ( $slug !== '' ) {
		$hit = get_page_by_path( $slug, OBJECT, $type );
		if ( $hit ) {
			$collision = array(
				'id'     => (int) $hit->ID,
				'status' => $hit->post_status,
				'note'   => 'a post of this type already holds that slug — WordPress will suffix the new one. PUT /posts/' . $slug . ' to update it instead.',
			);
		}
	}

	$acf   = is_array( $req->get_param( 'acf' ) ) ? (array) $req->get_param( 'acf' ) : array();
	$metas = is_array( $req->get_param( 'meta' ) ) ? (array) $req->get_param( 'meta' ) : array();
	$terms = is_array( $req->get_param( 'terms' ) ) ? (array) $req->get_param( 'terms' ) : array();

	$acf_plan = array();
	foreach ( $acf as $name => $value ) {
		$acf_plan[ $name ] = is_array( $value )
			? array( 'rows' => count( $value ) )
			: array( 'chars' => strlen( (string) $value ) );
	}

	$plan = array(
		'post_type'      => $type,
		'title'          => $title,
		'status'         => (string) ( $req->get_param( 'status' ) ? $req->get_param( 'status' ) : 'draft' ),
		'slug'           => $slug !== '' ? $slug : '(derived from title)',
		'content_chars'  => strlen( (string) $req->get_param( 'content' ) ),
		'acf'            => $acf_plan,
		'meta_keys'      => array_keys( $metas ),
		'terms'          => array_keys( $terms ),
		'slug_collision' => $collision,
		'acf_active'     => function_exists( 'update_field' ),
	);

	if ( $dry || ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array(
			'dry_run' => true,
			'note'    => $dry ? 'dry_run=true — nothing written' : 'confirm=true was not passed — nothing written',
			'plan'    => $plan,
		) );
	}

	$args = bsb_posts_args( $req );
	$args['post_type'] = $type;
	if ( ! isset( $args['post_status'] ) || $args['post_status'] === '' ) {
		$args['post_status'] = 'draft';
	}
	if ( ! isset( $args['post_author'] ) ) {
		$args['post_author'] = bsb_posts_default_author();
	}

	$id = wp_insert_post( $args, true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	$notes = bsb_posts_apply_extras( (int) $id, $req );

	return rest_ensure_response( array(
		'created' => true,
		'post'    => bsb_posts_describe( (int) $id ),
		'acf'     => bsb_posts_acf_summary( (int) $id ),
		'notes'   => $notes,
	) );
}

/** GET /posts/{ref}?full= — read a post back, with its ACF shape or values. */
function bsb_posts_get( WP_REST_Request $req ) {
	$res = bsb_resolve( (string) $req->get_param( 'ref' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	list( $id ) = $res;

	$out = array( 'post' => bsb_posts_describe( $id ) );
	if ( filter_var( $req->get_param( 'full' ), FILTER_VALIDATE_BOOLEAN ) && function_exists( 'get_fields' ) ) {
		$out['acf_values'] = get_fields( $id );
	} else {
		$out['acf'] = bsb_posts_acf_summary( $id );
	}
	return rest_ensure_response( $out );
}

/**
 * PUT /posts/{ref} — partial update. Only the fields you send change.
 * ACF repeaters are replaced wholesale, which is how ACF works: send the
 * complete array of rows, not a patch of one.
 */
function bsb_posts_update( WP_REST_Request $req ) {
	$dry = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

	$res = bsb_resolve( (string) $req->get_param( 'ref' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	list( $id, $provisional ) = $res;
	if ( $provisional ) {
		return new WP_Error( 'bsb_provisional', 'That is a provisional occurrence id — use the events module', array( 'status' => 400 ) );
	}

	$type    = get_post_type( $id );
	$blocked = bsb_posts_blocked();
	if ( isset( $blocked[ $type ] ) ) {
		return new WP_Error( 'bsb_type_blocked', sprintf( 'post %d is a "%s": %s', $id, $type, $blocked[ $type ] ), array( 'status' => 400 ) );
	}

	$args = bsb_posts_args( $req, $id );
	$acf  = is_array( $req->get_param( 'acf' ) ) ? (array) $req->get_param( 'acf' ) : array();

	$acf_plan = array();
	foreach ( $acf as $name => $value ) {
		$acf_plan[ $name ] = is_array( $value )
			? array( 'rows' => count( $value ), 'note' => 'replaces the whole field' )
			: array( 'chars' => strlen( (string) $value ) );
	}

	$plan = array(
		'ref'          => $id,
		'post_type'    => $type,
		'before'       => bsb_posts_describe( $id ),
		'post_fields'  => array_keys( $args ),
		'acf'          => $acf_plan,
		'acf_before'   => bsb_posts_acf_summary( $id ),
	);

	if ( $dry || ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array(
			'dry_run' => true,
			'note'    => $dry ? 'dry_run=true — nothing written' : 'confirm=true was not passed — nothing written',
			'plan'    => $plan,
		) );
	}

	if ( $args ) {
		$args['ID'] = $id;
		$upd = wp_update_post( $args, true );
		if ( is_wp_error( $upd ) ) {
			return $upd;
		}
	}

	$notes = bsb_posts_apply_extras( $id, $req );

	return rest_ensure_response( array(
		'updated' => true,
		'post'    => bsb_posts_describe( $id ),
		'acf'     => bsb_posts_acf_summary( $id ),
		'notes'   => $notes,
	) );
}

/* ================================================================== *
 * MODULE: tweaks — the site's custom PHP, absorbed from Code Snippets
 * ================================================================== *
 *
 * Formerly Code Snippets #5–#8, moved here in v0.9.0 so changes ship through
 * this plugin's one-click update instead of being hand-edited in wp-admin (the
 * 2026-08 domain move meant editing a URL inside a live snippet — exactly the
 * kind of change that should be a git commit + release).
 *
 * CUTOVER: every named function is function_exists()-guarded, so this plugin
 * and the old snippets can be active at the same time without a fatal. While
 * they overlap, the permalink/breadcrumb/shortcode hooks are idempotent and the
 * GF poke fires twice (harmless — the second sync is a no-op). Deactivate
 * snippets #5–#8 after updating; don't leave the overlap in place forever.
 */

/* ---------- Atlas GF poke (was snippet #8) ------------------------ *
 *
 * Poke Atlas on every Gravity Forms submission so the GF -> Google Sheets sync
 * pulls new entries immediately (Atlas's daily cron catches anything missed).
 * Dumb on purpose: fires for every form; Atlas ignores forms with no active
 * connection, so this code never needs editing when connections change.
 * Non-blocking with a 2s cap — a form submission never waits on Atlas.
 *
 * The token is NEVER in this file — the distribution repo is public. It comes
 * from the ATLAS_GF_POKE_TOKEN constant (wp-config.php or theme functions.php),
 * or from the bsb_gf_poke_token option, set over authenticated REST for hosts
 * where no file access is available (the constant wins if both exist). Without
 * either, the poke is skipped and the admin notice says so; the sync still
 * runs on Atlas's daily cron, just not instantly.
 */

/**
 * Option read/write hardened against this host's persistent object cache.
 *
 * Observed 2026-08-21 (v0.9.1): update_option() reported success but every
 * later request read the option as missing. The "notoptions" negative-lookup
 * cache had been populated by earlier reads (the admin notice checks for the
 * token on every admin page), and the cleanup add_option() performs wasn't
 * propagating through the host's persistent object cache. So: writes go
 * through update_option() and are then VERIFIED against the database row
 * directly, with a raw upsert as the fallback; reads fall back to the
 * database whenever the cache says "missing", healing the cache when the row
 * turns out to exist. Values are plain strings — no serialization needed.
 */
function bsb_option_read( $name ) {
	$v = get_option( $name, '' );
	if ( $v !== '' && $v !== false ) {
		return (string) $v;
	}
	global $wpdb;
	$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	if ( $row !== null && $row !== '' ) {
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_set( $name, $row, 'options' );
		return (string) $row;
	}
	return '';
}

/** Returns true only when the database row verifiably holds $value. */
function bsb_option_write( $name, $value ) {
	global $wpdb;
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( $name, 'options' );
	update_option( $name, $value, false );
	$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	if ( $row !== (string) $value ) {
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
			 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
			$name, $value
		) );
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	}
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( $name, 'options' );
	return $row === (string) $value;
}

/** The poke token: constant wins, then the option set via PUT /site/gf-poke-token. */
if ( ! function_exists( 'bsb_gf_poke_token' ) ) {
	function bsb_gf_poke_token() {
		if ( defined( 'ATLAS_GF_POKE_TOKEN' ) && ATLAS_GF_POKE_TOKEN !== '' ) {
			return (string) ATLAS_GF_POKE_TOKEN;
		}
		return bsb_option_read( 'bsb_gf_poke_token' );
	}
}

/**
 * PUT /site/gf-poke-token — store the poke token as a WP option.
 *
 * Exists because this site's Theme File Editor can't save (its loopback fatal-check
 * is blocked by the firewall in front of the site) and wp-config needs SFTP. Takes
 * confirm=true like the other write endpoints; never echoes the token back.
 */
function bsb_site_put_gf_poke_token( WP_REST_Request $req ) {
	$token = (string) $req->get_param( 'token' );
	if ( $token === '' ) {
		return new WP_Error( 'bsb_no_token', 'A non-empty "token" is required', array( 'status' => 400 ) );
	}
	$constant_set = defined( 'ATLAS_GF_POKE_TOKEN' ) && ATLAS_GF_POKE_TOKEN !== '';
	$option_set   = bsb_option_read( 'bsb_gf_poke_token' ) !== '';

	if ( ! filter_var( $req->get_param( 'confirm' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return rest_ensure_response( array(
			'dry_run'           => true,
			'note'              => 'confirm=true was not passed — nothing written',
			'currently_set_via' => $constant_set ? 'constant' : ( $option_set ? 'option' : null ),
			'token_length'      => strlen( $token ),
		) );
	}

	// DB-verified write — v0.9.1's plain update_option() "succeeded" while the
	// row never became readable (see bsb_option_write for the whole story).
	$persisted = bsb_option_write( 'bsb_gf_poke_token', $token );
	if ( ! $persisted ) {
		return new WP_Error( 'bsb_write_failed', 'The option row does not hold the value after writing — the token is NOT set.', array( 'status' => 500 ) );
	}

	return rest_ensure_response( array(
		'updated'      => true,
		'set_via'      => 'option',
		'persisted'    => true, // verified against the DB row, not update_option's return
		'token_length' => strlen( $token ),
		'note'         => $constant_set
			? 'ATLAS_GF_POKE_TOKEN is also defined and SHADOWS this option — the constant is what the poke will send.'
			: null,
	) );
}

if ( ! function_exists( 'bsb_gf_atlas_poke' ) ) {
	function bsb_gf_atlas_poke( $entry, $form ) {
		unset( $entry );
		$token = bsb_gf_poke_token();
		if ( $token === '' ) {
			return;
		}
		wp_remote_post(
			'https://atlas.bethanycentral.org/api/webhooks/gf?token=' . rawurlencode( $token ),
			array(
				'timeout'  => 2,
				'blocking' => false,
				'body'     => array( 'form_id' => isset( $form['id'] ) ? $form['id'] : 0 ),
			)
		);
	}
}
add_action( 'gform_after_submission', 'bsb_gf_atlas_poke', 10, 2 );

/* ---------- Nest trip-update URLs under their trip (was snippet #5) *
 *
 * Turns   /trip-update/<update-slug>
 * into    /short-term-trips/<trip-slug>/<update-slug>
 *
 * Scoped under the /short-term-trips/ base + two path segments, so it can't
 * hijack pages or other URLs.
 */

// 1) Rewrite the permalink WordPress prints for a trip-update.
if ( ! function_exists( 'bcc_trip_update_permalink' ) ) {
	function bcc_trip_update_permalink( $link, $post ) {
		if ( $post->post_type !== 'trip-update' ) {
			return $link;
		}
		$trip_id = get_post_meta( $post->ID, 'associated_trip', true );
		if ( ! $trip_id ) {
			return $link; // no trip linked yet — leave the default /trip-update/ URL
		}
		$trip = get_post( $trip_id );
		if ( ! $trip || $trip->post_type !== 'short-term-trips' ) {
			return $link;
		}
		return home_url( user_trailingslashit( 'short-term-trips/' . $trip->post_name . '/' . $post->post_name ) );
	}
}
add_filter( 'post_type_link', 'bcc_trip_update_permalink', 10, 2 );

// 2) Teach WordPress to resolve that nested URL back to the trip-update.
//    (Update slugs are unique per post type, so $matches[2] is enough to find it;
//    $matches[1], the trip slug, is just for a readable URL.)
if ( ! function_exists( 'bcc_trip_update_rewrite' ) ) {
	function bcc_trip_update_rewrite() {
		add_rewrite_rule(
			'^short-term-trips/([^/]+)/([^/]+)/?$',
			'index.php?trip-update=$matches[2]',
			'top'
		);
	}
}
add_action( 'init', 'bcc_trip_update_rewrite' );

/**
 * One-time rewrite flush, replacing the "do a Permalinks > Save after install"
 * step the snippet needed. Bump BSB_REWRITE_VERSION only when the rewrite rules
 * above actually change — a flush on every load would be expensive.
 */
const BSB_REWRITE_VERSION = 1;
add_action( 'init', function () {
	// Hardened read/write (bsb_option_read/write): with plain get_option this
	// site's poisoned notoptions cache made the guard read 0 forever, which
	// would mean a full rewrite flush on EVERY request.
	if ( (int) bsb_option_read( 'bsb_rewrite_version' ) !== BSB_REWRITE_VERSION ) {
		flush_rewrite_rules();
		bsb_option_write( 'bsb_rewrite_version', (string) BSB_REWRITE_VERSION );
	}
}, 20 );

/* ---------- Trip breadcrumbs (was snippet #6) ---------------------- *
 *
 * Themeco Pro `x_breadcrumbs_data` filter.
 *
 *  short-term-trips single:  Home > Outreach > Short Term Trips > [Trip]
 *  trip-update single:       Home > Outreach > Short Term Trips > [Trip] > [Update]
 *
 * "Short Term Trips" links to the landing page /outreach/short-term-trips/.
 * The [Trip] crumb links to that trip's own page (resolved from the update's
 * ACF `associated_trip` field).
 */
if ( ! function_exists( 'bcc_trip_breadcrumbs' ) ) {
	function bcc_trip_breadcrumbs( $crumbs, $args ) {
		unset( $args );
		if ( empty( $crumbs ) || ! is_singular( array( 'short-term-trips', 'trip-update' ) ) ) {
			return $crumbs;
		}

		$home    = $crumbs[0];          // keep the existing Home crumb
		$current = end( $crumbs );      // keep the existing current-page crumb

		// The "Outreach" parent-section crumb.
		$outreach = array(
			'type'  => 'custom',
			'url'   => home_url( '/outreach/' ),
			'label' => 'Outreach',
		);

		// The "Short Term Trips" landing-page crumb.
		$landing = array(
			'type'  => 'custom',
			'url'   => home_url( '/outreach/short-term-trips/' ),
			'label' => 'Short Term Trips',
		);

		// A trip single: Home > Outreach > Short Term Trips > [this trip]
		if ( is_singular( 'short-term-trips' ) ) {
			return array( $home, $outreach, $landing, $current );
		}

		// A trip update: Home > Outreach > Short Term Trips > [parent trip] > [this update]
		$trail   = array( $home, $outreach, $landing );
		$trip_id = get_post_meta( get_queried_object_id(), 'associated_trip', true );
		if ( $trip_id ) {
			$trail[] = array(
				'type'  => 'short-term-trips',
				'url'   => get_permalink( $trip_id ),
				'label' => get_the_title( $trip_id ),
			);
		}
		$trail[] = $current;
		return $trail;
	}
}
add_filter( 'x_breadcrumbs_data', 'bcc_trip_breadcrumbs', 10, 2 );

/* ---------- [ff_date] Fall Festival shortcode (was snippet #7) ----- *
 *
 * Fall Festival date, computed as the LAST Saturday of September. Shows this
 * year's date until the festival has passed, then next year's.
 *   [ff_date]                -> September 26th
 *   [ff_date format="full"]  -> Saturday, September 26th
 * Used on /fallfestival (page 26967).
 */
if ( ! function_exists( 'bsb_ff_date_shortcode' ) ) {
	function bsb_ff_date_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'format' => 'short' ), $atts, 'ff_date' );
		$tz   = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $tz );
		$year = (int) $now->format( 'Y' );
		$ff   = new DateTimeImmutable( 'last saturday of september ' . $year, $tz );
		if ( $now > $ff->setTime( 23, 59, 59 ) ) {
			$ff = new DateTimeImmutable( 'last saturday of september ' . ( $year + 1 ), $tz );
		}
		$day    = (int) $ff->format( 'j' );
		$suffix = 'th';
		if ( ! in_array( $day % 100, array( 11, 12, 13 ), true ) ) {
			$map = array( 1 => 'st', 2 => 'nd', 3 => 'rd' );
			if ( isset( $map[ $day % 10 ] ) ) {
				$suffix = $map[ $day % 10 ];
			}
		}
		$base = $ff->format( 'F' ) . ' ' . $day . $suffix;
		return 'full' === $atts['format'] ? $ff->format( 'l' ) . ', ' . $base : $base;
	}
}
add_shortcode( 'ff_date', 'bsb_ff_date_shortcode' );
