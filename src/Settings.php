<?php

namespace Referenced;

defined( 'ABSPATH' ) || exit;

class Settings {

	const PAGE  = 'referenced-ai-crawler-tracking';
	const GROUP = 'referenced_settings';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'addPage' ) );
		add_action( 'admin_init', array( __CLASS__, 'registerSetting' ) );
		add_action( 'admin_post_referenced_send_now', array( __CLASS__, 'handleSendNow' ) );
		add_action( 'admin_post_referenced_disconnect', array( __CLASS__, 'handleDisconnect' ) );
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
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Referenced – AI Crawler Tracking', 'referenced-ai-crawler-tracking' ); ?></h1>

			<?php if ( $sent ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Sent. The status below shows the result.', 'referenced-ai-crawler-tracking' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $disconnected ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Disconnected. Tracking is off and the token is removed.', 'referenced-ai-crawler-tracking' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php echo esc_html__( 'This plugin counts visits from known AI crawlers and sends daily totals to Referenced. Each send contains: bot name, path, date, count, plugin version and WordPress version, and your home URL. Nothing about visitors is collected or sent.', 'referenced-ai-crawler-tracking' ); ?>
				<a href="<?php echo esc_url( 'https://getreferenced.ai/privacy' ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Privacy', 'referenced-ai-crawler-tracking' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( 'https://getreferenced.ai/terms' ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Terms', 'referenced-ai-crawler-tracking' ); ?></a>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="referenced_token"><?php echo esc_html__( 'Token', 'referenced-ai-crawler-tracking' ); ?></label></th>
						<td>
							<input type="text" id="referenced_token" name="referenced_token" class="regular-text" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $value ); ?>" />
							<p class="description">
								<?php
								if ( '' !== $token ) {
									echo esc_html__( 'To replace the token, paste a new one over this and save. Leaving it as is keeps the current token.', 'referenced-ai-crawler-tracking' );
								} else {
									echo esc_html__( 'Paste the token from Referenced, under Settings, Connections.', 'referenced-ai-crawler-tracking' );
								}
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save token', 'referenced-ai-crawler-tracking' ), 'primary', 'referenced_save' ); ?>
			</form>

			<p><strong><?php echo esc_html__( 'Status:', 'referenced-ai-crawler-tracking' ); ?></strong> <?php echo esc_html( self::statusText() ); ?></p>

			<?php if ( '' !== $token ) : ?>
				<div style="display:flex;gap:8px;align-items:center;">
					<form method="post" action="<?php echo esc_url( $action ); ?>">
						<input type="hidden" name="action" value="referenced_send_now" />
						<?php wp_nonce_field( 'referenced_send_now' ); ?>
						<?php submit_button( __( 'Send now', 'referenced-ai-crawler-tracking' ), 'primary', 'referenced_send_now', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( $action ); ?>">
						<input type="hidden" name="action" value="referenced_disconnect" />
						<?php wp_nonce_field( 'referenced_disconnect' ); ?>
						<?php submit_button( __( 'Disconnect', 'referenced-ai-crawler-tracking' ), 'secondary', 'referenced_disconnect', false ); ?>
					</form>
				</div>
			<?php else : ?>
				<p><?php echo esc_html__( 'Save a token above to start sending.', 'referenced-ai-crawler-tracking' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
