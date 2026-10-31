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
require_once __DIR__ . '/src/Settings.php';
// uninstall.php is loaded by WordPress itself on uninstall; it is not required here.
