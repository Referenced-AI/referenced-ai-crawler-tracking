<?php

namespace Referenced;

defined( 'ABSPATH' ) || exit;

class Settings {

	const PAGE  = 'referenced-ai-crawler-tracking';
	const GROUP = 'referenced_settings';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'addPage' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/referenced-ai-crawler-tracking.php' ), array( __CLASS__, 'actionLinks' ) );
		add_action( 'admin_init', array( __CLASS__, 'registerSetting' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'addWidget' ) );
		add_action( 'admin_post_referenced_send_now', array( __CLASS__, 'handleSendNow' ) );
		add_action( 'admin_post_referenced_disconnect', array( __CLASS__, 'handleDisconnect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'connectNotice' ) );
		add_action( 'admin_post_referenced_dismiss', array( __CLASS__, 'handleDismiss' ) );
	}

	const DISMISS_META = 'referenced_dismissed_connect_notice';

	/**
	 * Prompt to connect on the Plugins and Dashboard screens only, until dismissed.
	 */
	public static function connectNotice() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || '' !== (string) get_option( 'referenced_token', '' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}

		$connect = admin_url( 'options-general.php?page=' . self::PAGE );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=referenced_dismiss' ), 'referenced_dismiss' );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php echo esc_html__( 'Referenced is not connected yet.', 'referenced-ai-crawler-tracking' ); ?></strong>
				<?php echo esc_html__( 'Add your token to start tracking AI crawler visits.', 'referenced-ai-crawler-tracking' ); ?>
				<a href="<?php echo esc_url( $connect ); ?>"><?php echo esc_html__( 'Connect', 'referenced-ai-crawler-tracking' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php echo esc_html__( 'Dismiss', 'referenced-ai-crawler-tracking' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handleDismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'referenced-ai-crawler-tracking' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'referenced_dismiss' );
		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function addPage() {
		add_options_page(
			__( 'Referenced', 'referenced-ai-crawler-tracking' ),
			__( 'Referenced', 'referenced-ai-crawler-tracking' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( $hook ) {
		$base = dirname( __DIR__ ) . '/referenced-ai-crawler-tracking.php';
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'referenced-admin', plugins_url( 'assets/admin.css', $base ), array(), referenced_version() );

			return;
		}
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'referenced-admin', plugins_url( 'assets/admin.css', $base ), array(), referenced_version() );
		wp_enqueue_script( 'referenced-admin', plugins_url( 'assets/admin.js', $base ), array(), referenced_version(), true );
	}

	public static function addWidget() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'referenced_widget', __( 'AI Crawler Visits', 'referenced-ai-crawler-tracking' ), array( __CLASS__, 'renderWidget' ) );
		}
	}

	public static function renderWidget() {
		global $wpdb;
		$table     = referenced_table();
		$connected = '' !== (string) get_option( 'referenced_token', '' );
		$hits      = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned custom table; dashboard needs current counts.
			$wpdb->prepare(
				"SELECT COALESCE(SUM(count), 0) FROM {$table} WHERE date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Tracker::pruneCutoff( time() )
			)
		);
		$last      = get_option( 'referenced_last_send' );
		$state     = '';
		$label     = __( 'Not connected', 'referenced-ai-crawler-tracking' );
		if ( $connected ) {
			$failed = is_array( $last ) && isset( $last['status'] ) && 0 !== strpos( (string) $last['status'], 'Sent' );
			$state  = $failed ? 'is-warn' : 'is-on';
			$label  = $failed ? __( 'Attention', 'referenced-ai-crawler-tracking' ) : __( 'Connected', 'referenced-ai-crawler-tracking' );
		}
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		?>
		<div class="referenced-wrap ref-widget">
			<div class="ref-widget-body">
				<div>
					<div class="ref-big"><?php echo esc_html( number_format_i18n( $hits ) ); ?></div>
					<p class="ref-muted ref-inline"><?php echo esc_html__( 'AI crawler visits in the last 7 days', 'referenced-ai-crawler-tracking' ); ?></p>
				</div>
				<span class="ref-pill <?php echo esc_attr( $state ); ?>"><span class="ref-dot" aria-hidden="true"></span><?php echo esc_html( $label ); ?></span>
			</div>
			<div class="ref-widget-footer">
				<a href="<?php echo esc_url( $url ); ?>"><?php echo $connected ? esc_html__( 'Settings', 'referenced-ai-crawler-tracking' ) : esc_html__( 'Connect', 'referenced-ai-crawler-tracking' ); ?></a>
				<a href="<?php echo esc_url( 'https://getreferenced.ai' ); ?>" target="_blank" rel="noopener">
					<?php echo esc_html__( 'Open Referenced', 'referenced-ai-crawler-tracking' ); ?>
					<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 19.5 15-15m0 0H8.25m11.25 0v11.25"/></svg>
				</a>
			</div>
		</div>
		<?php
	}

	public static function actionLinks( $links ) {
		$url   = admin_url( 'options-general.php?page=' . self::PAGE );
		$label = '' === (string) get_option( 'referenced_token', '' ) ? __( 'Connect', 'referenced-ai-crawler-tracking' ) : __( 'Settings', 'referenced-ai-crawler-tracking' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' );

		return $links;
	}

	public static function registerSetting() {
		register_setting(
			self::GROUP,
			'referenced_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitizeToken' ),
			)
		);
	}

	/**
	 * Masked display value: bullets plus the last four characters.
	 */
	public static function mask( $token ) {
		return "\u{2022}\u{2022}\u{2022}\u{2022}" . substr( (string) $token, -4 );
	}

	/**
	 * Create the token option with autoload off when it does not exist yet.
	 * The activation hook does this too; this covers installs that predate it.
	 */
	private static function ensureTokenOption() {
		// No registered default, so a missing row reads as false.
		if ( false === get_option( 'referenced_token' ) ) {
			add_option( 'referenced_token', '', '', false );
		}
	}

	public static function sanitizeToken( $value ) {
		static $running = false;
		if ( $running ) {
			return $value;
		}
		$running = true;

		$stored    = (string) get_option( 'referenced_token', '' );
		$submitted = trim( sanitize_text_field( (string) $value ) );

		self::ensureTokenOption();

		// Empty or the unchanged masked value keeps the stored token. Clearing is the Disconnect button's job.
		if ( '' !== $stored && ( '' === $submitted || self::mask( $stored ) === $submitted ) ) {
			$running = false;

			return $stored;
		}

		if ( '' === $submitted ) {
			update_option( 'referenced_enabled', '0' );
			$running = false;

			return '';
		}

		if ( ! preg_match( '/\A[A-Za-z0-9]{40}\z/', $submitted ) ) {
			add_settings_error(
				'referenced_token',
				'referenced_token_invalid',
				__( 'That does not look like a Referenced token. It should be 40 letters and numbers.', 'referenced-ai-crawler-tracking' )
			);
			$running = false;

			return $stored;
		}

		update_option( 'referenced_enabled', '1' );
		$running = false;

		return $submitted;
	}

	public static function handleSendNow() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'referenced-ai-crawler-tracking' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'referenced_send_now' );

		$args = array( 'page' => self::PAGE );
		if ( '' !== (string) get_option( 'referenced_token', '' ) ) {
			referenced_send_hits();
			$args['sent'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function handleDisconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'referenced-ai-crawler-tracking' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'referenced_disconnect' );

		$args = array( 'page' => self::PAGE );
		if ( '' !== (string) get_option( 'referenced_token', '' ) ) {
			delete_option( 'referenced_token' );
			add_option( 'referenced_token', '', '', false );
			update_option( 'referenced_enabled', '0' );
			delete_user_meta( get_current_user_id(), self::DISMISS_META );
			$args['disconnected'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	private static function statusText() {
		$last = get_option( 'referenced_last_send' );
		if ( ! is_array( $last ) || ! isset( $last['at'] ) ) {
			return __( 'Nothing sent yet.', 'referenced-ai-crawler-tracking' );
		}

		$status = isset( $last['status'] ) ? sanitize_text_field( (string) $last['status'] ) : '';
		// A successful send's status already carries the time; print it once.
		if ( strpos( $status, ' UTC' ) !== false ) {
			return $status;
		}
		$at = gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC';

		/* translators: 1: status message, 2: UTC date and time */
		return sprintf( __( 'Last send: %1$s (%2$s)', 'referenced-ai-crawler-tracking' ), $status, $at );
	}

	/**
	 * Heroicons outline (MIT) as inline SVG. Static markup, no user input.
	 */
	private static function icon( $name ) {
		$paths = array(
			'info'  => 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
			'link'  => 'M12.75 19.5v-.75a7.5 7.5 0 0 0-7.5-7.5H4.5m0-6.75h.75c7.87 0 14.25 6.38 14.25 14.25v.75M6 18.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
			'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
			'doc'   => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
			'x'     => 'm9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
		);

		return '<svg class="ref-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $paths[ $name ] . '"/></svg>';
	}

	/**
	 * Provider logo file stem for a bot slug, or '' for the letter-tile fallback.
	 * Drop {stem}.svg into assets/crawlers/ to switch a provider to its logo.
	 */
	private static function logoStem( $slug ) {
		$map = array(
			'gptbot'     => 'chatgpt',
			'oai-'       => 'chatgpt',
			'chatgpt-'   => 'chatgpt',
			'claude'     => 'claude',
			'perplexity' => 'perplexity',
			'google'     => 'google',
			'applebot'   => 'apple',
			'bingbot'    => 'bing',
			'bravebot'   => 'brave',
			'amazonbot'  => 'amazon',
			'amzn-'      => 'amazon',
			'meta-'      => 'meta',
			'bytespider' => 'bytedance',
			'cohere'     => 'cohere',
		);
		foreach ( $map as $prefix => $stem ) {
			if ( 0 === strpos( $slug, $prefix ) ) {
				return $stem;
			}
		}

		return '';
	}

	private static function crawlerCell( $slug, $names ) {
		$name = isset( $names[ $slug ] ) ? (string) $names[ $slug ] : $slug;
		$stem = self::logoStem( $slug );
		$dir  = dirname( __DIR__ ) . '/assets/crawlers/';
		if ( '' !== $stem && file_exists( $dir . $stem . '.svg' ) ) {
			$base = dirname( __DIR__ ) . '/referenced-ai-crawler-tracking.php';
			$tile = '<img src="' . esc_url( plugins_url( 'assets/crawlers/' . $stem . '.svg', $base ) ) . '" alt="" />';
		} else {
			$tile = '<span>' . esc_html( strtoupper( substr( $name, 0, 1 ) ) ) . '</span>';
		}

		return '<span class="ref-crawler"><span class="ref-tile">' . $tile . '</span>' . esc_html( $name ) . '</span>';
	}

	/**
	 * Last 7 days of stored counts: totals per bot, plus the busiest paths.
	 */
	private static function renderCounts() {
		global $wpdb;
		$table = referenced_table();
		$since = Tracker::pruneCutoff( time() );
		$names = Tracker::vocabulary( get_option( 'referenced_bots' ) );

		$bots = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned custom table; admin screen needs current counts.
			$wpdb->prepare(
				"SELECT bot, SUM(count) AS hits, COUNT(DISTINCT path) AS paths FROM {$table} WHERE date >= %s GROUP BY bot ORDER BY hits DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);
		$top  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned custom table; admin screen needs current counts.
			$wpdb->prepare(
				"SELECT path, SUM(count) AS hits FROM {$table} WHERE date >= %s GROUP BY path ORDER BY hits DESC, path LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);
		?>
		<?php if ( empty( $bots ) ) : ?>
			<div class="ref-card">
				<div class="ref-card-head"><h2><?php echo self::icon( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?><?php echo esc_html__( 'Crawler Visits, Last 7 Days', 'referenced-ai-crawler-tracking' ); ?></h2></div>
				<div class="ref-card-body ref-card-body--flush">
					<p class="ref-empty"><?php echo esc_html__( 'No AI crawler visits yet. Visits will appear here as they happen.', 'referenced-ai-crawler-tracking' ); ?></p>
				</div>
			</div>
		<?php else : ?>
			<div class="ref-card">
				<div class="ref-card-head"><h2><?php echo self::icon( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?><?php echo esc_html__( 'Crawler Visits, Last 7 Days', 'referenced-ai-crawler-tracking' ); ?></h2></div>
				<div class="ref-card-body ref-card-body--flush">
					<table class="ref-table">
						<thead><tr>
							<th><?php echo esc_html__( 'Crawler', 'referenced-ai-crawler-tracking' ); ?></th>
							<th class="ref-num"><?php echo esc_html__( 'Visits', 'referenced-ai-crawler-tracking' ); ?></th>
							<th class="ref-num"><?php echo esc_html__( 'Pages', 'referenced-ai-crawler-tracking' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $bots as $row ) : ?>
							<tr>
								<td><?php echo self::crawlerCell( $row['bot'], $names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside crawlerCell(). ?></td>
								<td class="ref-num"><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
								<td class="ref-num"><?php echo esc_html( number_format_i18n( (int) $row['paths'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<div class="ref-card">
				<div class="ref-card-head"><h2><?php echo self::icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?><?php echo esc_html__( 'Most Visited Pages', 'referenced-ai-crawler-tracking' ); ?></h2></div>
				<div class="ref-card-body ref-card-body--flush">
					<table class="ref-table">
						<thead><tr>
							<th><?php echo esc_html__( 'Page', 'referenced-ai-crawler-tracking' ); ?></th>
							<th class="ref-num"><?php echo esc_html__( 'Visits', 'referenced-ai-crawler-tracking' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $top as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
								<td class="ref-num"><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$token = (string) get_option( 'referenced_token', '' );
		$value = '' !== $token ? self::mask( $token ) : '';
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirect.
		$sent         = isset( $_GET['sent'] );
		$disconnected = isset( $_GET['disconnected'] );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
		$action = admin_url( 'admin-post.php' );
		$last   = get_option( 'referenced_last_send' );
		$state  = '';
		if ( is_array( $last ) && isset( $last['status'] ) ) {
			$state = 0 === strpos( (string) $last['status'], 'Sent' ) ? 'is-on' : 'is-warn';
		}
		?>
		<div class="wrap referenced-wrap">
			<h1>
				<?php echo esc_html__( 'Referenced', 'referenced-ai-crawler-tracking' ); ?>
				<span class="ref-title-muted"><?php echo esc_html__( 'AI Crawler Tracking', 'referenced-ai-crawler-tracking' ); ?></span>
			</h1>

			<?php if ( $sent ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Sent. The status below shows the result.', 'referenced-ai-crawler-tracking' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $disconnected ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Disconnected. Tracking is off and the token is removed.', 'referenced-ai-crawler-tracking' ); ?></p></div>
			<?php endif; ?>

			<div class="ref-card">
				<div class="ref-card-head"><h2><?php echo self::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?><?php echo esc_html__( 'What It Does', 'referenced-ai-crawler-tracking' ); ?></h2></div>
				<div class="ref-card-body">
				<p><?php echo esc_html__( 'Counts visits from known AI crawlers and sends daily totals to Referenced.', 'referenced-ai-crawler-tracking' ); ?></p>
				<p class="ref-muted">
					<?php echo esc_html__( 'Each send contains: bot name, path, date, count, plugin version and WordPress version, and your home URL. Nothing about visitors is collected or sent.', 'referenced-ai-crawler-tracking' ); ?>
				</p>
				</div>
			</div>

			<div class="ref-card">
				<div class="ref-card-head"><h2><?php echo self::icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?><?php echo esc_html__( 'Connection', 'referenced-ai-crawler-tracking' ); ?></h2><span class="ref-pill <?php echo '' !== $token ? 'is-on' : ''; ?>"><span class="ref-dot" aria-hidden="true"></span><?php echo '' !== $token ? esc_html__( 'Connected', 'referenced-ai-crawler-tracking' ) : esc_html__( 'Not connected', 'referenced-ai-crawler-tracking' ); ?></span></div>
				<div class="ref-card-body">
				<form method="post" action="options.php">
					<?php settings_fields( self::GROUP ); ?>
					<label class="ref-label" for="referenced_token"><?php echo esc_html__( 'Token', 'referenced-ai-crawler-tracking' ); ?></label>
					<div class="ref-token">
						<input type="text" id="referenced_token" name="referenced_token" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $value ); ?>" />
						<?php submit_button( __( 'Save token', 'referenced-ai-crawler-tracking' ), 'ref-btn-primary', 'referenced_save', false ); ?>
					</div>
					<p class="description">
						<?php
						if ( '' !== $token ) {
							echo esc_html__( 'To replace the token, paste a new one over this and save. Leaving it as is keeps the current token.', 'referenced-ai-crawler-tracking' );
						} else {
							echo esc_html__( 'Paste the token from Referenced, under Settings, Connections. Nothing is counted or sent until a token is saved.', 'referenced-ai-crawler-tracking' );
						}
						?>
					</p>
				</form>

				<?php if ( '' !== $token ) : ?>
					<hr class="ref-sep" />
					<div class="ref-status <?php echo esc_attr( $state ); ?>">
						<span class="ref-dot" aria-hidden="true"></span>
						<span><?php echo esc_html( self::statusText() ); ?></span>
					</div>
					<div class="ref-actions">
						<form method="post" action="<?php echo esc_url( $action ); ?>">
							<input type="hidden" name="action" value="referenced_send_now" />
							<?php wp_nonce_field( 'referenced_send_now' ); ?>
							<?php submit_button( __( 'Send now', 'referenced-ai-crawler-tracking' ), 'secondary', 'referenced_send_now', false ); ?>
						</form>
						<p class="ref-muted ref-inline"><?php echo esc_html__( 'Totals are sent automatically every hour. Send now pushes today and yesterday straight away.', 'referenced-ai-crawler-tracking' ); ?></p>
					</div>
				<?php endif; ?>
				</div>
			</div>

			<?php self::renderCounts(); ?>

			<?php if ( '' !== $token ) : ?>
				<div class="ref-card ref-card--danger">
					<div class="ref-card-head"><h2><?php echo self::icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?><?php echo esc_html__( 'Disconnect', 'referenced-ai-crawler-tracking' ); ?></h2></div>
					<div class="ref-card-body">
						<p class="ref-muted"><?php echo esc_html__( 'Removes the token and turns tracking off. Stored counts are kept until they are 7 days old.', 'referenced-ai-crawler-tracking' ); ?></p>
						<div class="ref-actions">
							<button type="button" class="button ref-btn-danger" data-ref-dialog="referenced-disconnect-dialog"><?php echo esc_html__( 'Disconnect', 'referenced-ai-crawler-tracking' ); ?></button>
						</div>
					</div>
				</div>

				<dialog id="referenced-disconnect-dialog">
					<h2><?php echo esc_html__( 'Disconnect From Referenced?', 'referenced-ai-crawler-tracking' ); ?></h2>
					<p class="ref-muted"><?php echo esc_html__( 'Tracking stops and the token is deleted. You can reconnect later by pasting a token again.', 'referenced-ai-crawler-tracking' ); ?></p>
					<form method="post" action="<?php echo esc_url( $action ); ?>" class="ref-actions">
						<input type="hidden" name="action" value="referenced_disconnect" />
						<?php wp_nonce_field( 'referenced_disconnect' ); ?>
						<?php submit_button( __( 'Disconnect', 'referenced-ai-crawler-tracking' ), 'ref-btn-danger', 'referenced_disconnect', false ); ?>
						<button type="button" class="button" data-ref-close><?php echo esc_html__( 'Cancel', 'referenced-ai-crawler-tracking' ); ?></button>
					</form>
				</dialog>
			<?php endif; ?>

			<div class="ref-footer">
				<span class="ref-made">
					<?php echo esc_html__( 'Made with', 'referenced-ai-crawler-tracking' ); ?>
					<svg class="ref-heart" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2 6.342a3.375 3.375 0 0 1 6-2.088 3.375 3.375 0 0 1 5.997 2.26c-.063 2.134-1.618 3.76-2.955 4.784a14.437 14.437 0 0 1-2.676 1.61c-.02.01-.038.017-.05.022l-.014.006-.004.002h-.002a.75.75 0 0 1-.592.001h-.002l-.004-.003-.015-.006a5.528 5.528 0 0 1-.232-.107 14.395 14.395 0 0 1-2.535-1.557C3.564 10.22 1.999 8.558 1.999 6.38L2 6.342Z"/></svg>
					<?php echo esc_html__( 'by the Referenced team', 'referenced-ai-crawler-tracking' ); ?>
				</span>
				<span class="ref-legal">
					<a href="<?php echo esc_url( 'https://getreferenced.ai/privacy' ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Privacy', 'referenced-ai-crawler-tracking' ); ?></a>
					&middot;
					<a href="<?php echo esc_url( 'https://getreferenced.ai/terms' ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Terms', 'referenced-ai-crawler-tracking' ); ?></a>
				</span>
			</div>
		</div>
		<?php
	}
}
