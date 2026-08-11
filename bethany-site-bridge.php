<?php
/**
 * Plugin Name: Bethany Site Bridge
 * Description: A working surface for making bethanycentral.org changes over REST —
 *              site introspection, arbitrary post meta, and The Events Calendar
 *              recurrence (including "will not occur" dates), none of which core
 *              or plugin REST APIs expose. Consumed by Atlas and by Claude Code.
 * Version:     0.7.0
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
 *   events  — The Events Calendar RECURRING events + "will not occur" exclusions.
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
 *   GET  /meta/{ref}                → post meta (all, or ?keys=a,b)
 *   PUT  /meta/{ref}                → write meta. Requires confirm=true.
 *   GET  /events/{ref}/recurrence   → raw _EventRecurrence + occurrence list
 *   GET  /events/{ref}/occurrences  → every generated date for the series
 *   POST /events                    → create (optionally recurring). dry_run ok.
 *   PUT  /events/{ref}/recurrence   → replace rule + exclusions. dry_run ok.
 *
 * {ref} = post ID, TEC provisional occurrence ID, or slug. Prefer the SLUG.
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

	// --- meta ---
	register_rest_route( 'atlas/v1', '/meta/(?P<ref>[^/]+)', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_meta_get', 'permission_callback' => $auth ),
		array( 'methods' => 'PUT', 'callback' => 'bsb_meta_put', 'permission_callback' => $auth ),
	) );

	// --- events ---
	register_rest_route( 'atlas/v1', '/events', array(
		array( 'methods' => 'POST', 'callback' => 'bsb_events_create', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/events/(?P<ref>[^/]+)/recurrence', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_events_get_recurrence', 'permission_callback' => $auth ),
		array( 'methods' => 'PUT', 'callback' => 'bsb_events_put_recurrence', 'permission_callback' => $auth ),
	) );
	register_rest_route( 'atlas/v1', '/events/(?P<ref>[^/]+)/occurrences', array(
		array( 'methods' => 'GET', 'callback' => 'bsb_events_get_occurrences', 'permission_callback' => $auth ),
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
			'modules'        => array( 'site', 'meta', 'events', 'updater' ),
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
		),
		'active_plugins' => $plugins,
		'post_types'     => $types,
		'taxonomies'     => $taxes,
		'registered_meta' => $exposed, // null unless ?meta_for=<post_type>
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

	$after = array();
	foreach ( $values as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
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

	// Read the terms back off the post rather than echoing the request — that's the
	// difference between "we asked for it" and "it actually stuck".
	$applied_cats = wp_get_object_terms( $post_id, 'tribe_events_cat', array( 'fields' => 'names' ) );
	$applied_tags = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
	$applied_thumb = (int) get_post_thumbnail_id( $post_id );

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
