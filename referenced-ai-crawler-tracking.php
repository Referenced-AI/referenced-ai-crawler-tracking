<?php
/**
 * Plugin Name: Referenced – AI Crawler Tracking
 * Plugin URI: https://getreferenced.ai
 * Description: Counts visits from known AI crawlers and reports daily totals to Referenced.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Referenced
 * Author URI: https://getreferenced.ai
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: referenced-ai-crawler-tracking
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Tracker.php';
require_once __DIR__ . '/src/Settings.php';
// uninstall.php is loaded by WordPress itself on uninstall; it is not required here.

use Referenced\Tracker;

defined( 'REFERENCED_INGEST_URL' ) || define( 'REFERENCED_INGEST_URL', 'https://getreferenced.ai' );

/**
 * Plugin version from the file header, read once.
 */
function referenced_version() {
	static $version = null;
	if ( null === $version ) {
		$data    = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
		$version = (string) $data['Version'];
	}

	return $version;
}

function referenced_table() {
	global $wpdb;

	return $wpdb->prefix . 'referenced_bot_hits';
}

function referenced_activate() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = referenced_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  date date NOT NULL,
  bot varchar(40) NOT NULL,
  path varchar(500) COLLATE utf8mb4_bin NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY hit (date,bot,path(191))
) {$charset};"
	);

	// Token must never autoload; create it up front so the Settings API cannot autoload it on first save.
	add_option( 'referenced_token', '', '', false );
	// Consent flag: missing or '0' means off; saving a valid token turns it on.
	add_option( 'referenced_enabled', '0' );

	if ( ! wp_next_scheduled( 'referenced_send_hits' ) ) {
		wp_schedule_event( time(), 'hourly', 'referenced_send_hits' );
	}
}

function referenced_deactivate() {
	wp_clear_scheduled_hook( 'referenced_send_hits' );
}

register_activation_hook( __FILE__, 'referenced_activate' );
register_deactivation_hook( __FILE__, 'referenced_deactivate' );

/**
 * Count a request when it comes from a known AI crawler. Cheap path: only
 * autoloaded options are read until a bot matches.
 */
function referenced_record_hit() {
	if ( '1' !== get_option( 'referenced_enabled', '0' ) ) {
		return;
	}
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) ) {
		return;
	}
	if ( is_user_logged_in() ) {
		return;
	}

	// Raw value is deliberate: it is only parsed and matched, never output, and sanitising would alter the stored path.
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched only, never output or stored raw.
	$path = Tracker::normalisePath( $uri );
	if ( null === $path || Tracker::isExcludedPath( $path ) ) {
		return;
	}

	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to the bot vocabulary only, never output or stored.
	$bot   = Tracker::matchBot( $agent, Tracker::vocabulary( get_option( 'referenced_bots' ) ) );
	if ( null === $bot ) {
		return;
	}

	global $wpdb;
	$table = referenced_table();
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned custom table; write-only counters, caching would be wrong.
		$wpdb->prepare(
			"INSERT INTO {$table} (date, bot, path, count) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE count = count + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			gmdate( 'Y-m-d' ),
			$bot,
			$path
		)
	);
}

// template_redirect (not init): REST_REQUEST is only defined after init, and
// template_redirect never fires for REST, admin, ajax or cron requests.
add_action( 'template_redirect', 'referenced_record_hit', 1 );

/**
 * Send today's and yesterday's totals (also the heartbeat). Cron handler and the
 * settings screen's Send Now. Pruning old rows runs on every tick, connected or not.
 */
function referenced_send_hits() {
	global $wpdb;
	$table = referenced_table();

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned custom table; write-only counters, caching would be wrong.
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Tracker::pruneCutoff( time() )
		)
	);

	$token = (string) get_option( 'referenced_token', '' );
	if ( '' === $token ) {
		return;
	}

	// The server keeps at most 500 distinct paths per site per day, first come, so send each day's busiest first.
	$rows = array();
	foreach ( Tracker::sendDates( time() ) as $date ) {
		$day = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned custom table; an hourly read that must see current counts.
			$wpdb->prepare(
				"SELECT date, bot, path, count FROM {$table} WHERE date = %s ORDER BY count DESC, bot, path LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			),
			ARRAY_A
		);
		if ( is_array( $day ) ) {
			$rows = array_merge( $rows, $day );
		}
	}

	$bodies = Tracker::buildPayloads(
		$rows,
		array(
			'version'    => referenced_version(),
			'wp_version' => get_bloginfo( 'version' ),
			'home_url'   => home_url( '/' ),
		)
	);

	$code         = 0;
	$responseBody = '';
	$error        = '';
	$okBodies     = array();
	foreach ( $bodies as $body ) {
		$response = wp_remote_post(
			untrailingslashit( REFERENCED_INGEST_URL ) . '/api/ingest/bot-hits',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$code         = 0;
			$responseBody = '';
			$error        = $response->get_error_message();
			break;
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$responseBody = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 200 && $code < 300 ) {
			$okBodies[] = $responseBody;
		} elseif ( in_array( $code, array( 401, 403, 429 ), true ) ) {
			// The rest of this tick's bodies would meet the same answer; stop.
			break;
		}
	}

	if ( $code >= 200 && $code < 300 ) {
		$decoded    = json_decode( $responseBody, true );
		$vocabulary = Tracker::acceptVocabulary( is_array( $decoded ) && isset( $decoded['bots'] ) ? $decoded['bots'] : null );
		if ( null !== $vocabulary ) {
			update_option( 'referenced_bots', $vocabulary, true );
		}
	}

	update_option(
		'referenced_last_send',
		array(
			'at'     => time(),
			'status' => sanitize_text_field( Tracker::statusLine( $code, $responseBody, time(), Tracker::sumAccepted( $okBodies ), $error ) ),
		),
		false
	);
}

add_action( 'referenced_send_hits', 'referenced_send_hits' );

// Self-heal: re-create the hourly event if something cleared it (a cron-clearing plugin, a restore).
add_action(
	'admin_init',
	function () {
		if ( ! wp_next_scheduled( 'referenced_send_hits' ) ) {
			wp_schedule_event( time(), 'hourly', 'referenced_send_hits' );
		}
	}
);

\Referenced\Settings::register();
