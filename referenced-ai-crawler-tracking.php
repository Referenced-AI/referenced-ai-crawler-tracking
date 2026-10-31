<?php
/**
 * Plugin Name: Referenced – AI Crawler Tracking
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

defined('ABSPATH') || exit;

require_once __DIR__ . '/src/Tracker.php';
// Settings screen arrives separately; tolerate its absence so activation never fatals.
if (file_exists(__DIR__ . '/src/Settings.php')) {
    require_once __DIR__ . '/src/Settings.php';
}
// uninstall.php is loaded by WordPress itself on uninstall; it is not required here.

use Referenced\Tracker;

defined('REFERENCED_INGEST_URL') || define('REFERENCED_INGEST_URL', 'https://getreferenced.ai');

/**
 * Plugin version from the file header, read once.
 */
function referenced_version()
{
    static $version = null;
    if ($version === null) {
        $data = get_file_data(__FILE__, ['Version' => 'Version']);
        $version = (string) $data['Version'];
    }

    return $version;
}

function referenced_table()
{
    global $wpdb;

    return $wpdb->prefix . 'referenced_bot_hits';
}

function referenced_activate()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = referenced_table();
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  date date NOT NULL,
  bot varchar(40) NOT NULL,
  path varchar(500) NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY hit (date,bot,path(191))
) {$charset};");

    // Token must never autoload; create it up front so the Settings API cannot autoload it on first save.
    add_option('referenced_token', '', '', 'no');

    if (! wp_next_scheduled('referenced_send_hits')) {
        wp_schedule_event(time(), 'hourly', 'referenced_send_hits');
    }
}

function referenced_deactivate()
{
    wp_clear_scheduled_hook('referenced_send_hits');
}

register_activation_hook(__FILE__, 'referenced_activate');
register_deactivation_hook(__FILE__, 'referenced_deactivate');

/**
 * Count a request when it comes from a known AI crawler. Cheap path: only
 * autoloaded options are read until a bot matches.
 */
function referenced_record_hit()
{
    if (! get_option('referenced_enabled', true)) {
        return;
    }
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || defined('REST_REQUEST') || defined('WP_CLI')) {
        return;
    }
    if (is_user_logged_in()) {
        return;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = Tracker::normalisePath($uri);
    if ($path === null || Tracker::isExcludedPath($path)) {
        return;
    }

    $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
    $vocabulary = get_option('referenced_bots');
    if (! is_array($vocabulary) || $vocabulary === []) {
        $vocabulary = Tracker::DEFAULT_BOTS;
    }
    $bot = Tracker::matchBot($agent, $vocabulary);
    if ($bot === null) {
        return;
    }

    global $wpdb;
    $table = referenced_table();
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$table} (date, bot, path, count) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE count = count + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate('Y-m-d'),
            $bot,
            $path
        )
    );
}

// template_redirect (not init): REST_REQUEST is only defined after init, and
// template_redirect never fires for REST, admin, ajax or cron requests.
add_action('template_redirect', 'referenced_record_hit', 1);

/**
 * Send today's and yesterday's totals (also the heartbeat), then prune.
 * Cron handler and the settings screen's Send Now.
 */
function referenced_send_hits()
{
    $token = (string) get_option('referenced_token', '');
    if ($token === '') {
        return;
    }

    global $wpdb;
    $table = referenced_table();
    $dates = Tracker::sendDates(time());

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT date, bot, path, count FROM {$table} WHERE date IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $dates['today'],
            $dates['yesterday']
        ),
        ARRAY_A
    );
    if (! is_array($rows)) {
        $rows = [];
    }

    $bodies = Tracker::buildPayloads($rows, [
        'version' => referenced_version(),
        'wp_version' => get_bloginfo('version'),
        'home_url' => home_url('/'),
    ]);

    $code = 0;
    $responseBody = '';
    foreach ($bodies as $body) {
        $response = wp_remote_post(REFERENCED_INGEST_URL . '/api/ingest/bot-hits', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            $code = 0;
            $responseBody = '';
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $responseBody = (string) wp_remote_retrieve_body($response);
        }
    }

    if ($code >= 200 && $code < 300) {
        $decoded = json_decode($responseBody, true);
        $vocabulary = Tracker::acceptVocabulary(is_array($decoded) && isset($decoded['bots']) ? $decoded['bots'] : null);
        if ($vocabulary !== null) {
            update_option('referenced_bots', $vocabulary, true);
        }
    }

    update_option('referenced_last_send', [
        'at' => time(),
        'status' => sanitize_text_field(Tracker::statusLine($code, $responseBody, time())),
    ], false);

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table} WHERE date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            Tracker::pruneCutoff(time())
        )
    );
}

add_action('referenced_send_hits', 'referenced_send_hits');

// Settings screen (Task 6): guarded so the bootstrap works before the class exists.
if (class_exists('Referenced\\Settings') && method_exists('Referenced\\Settings', 'register')) {
    \Referenced\Settings::register();
}
