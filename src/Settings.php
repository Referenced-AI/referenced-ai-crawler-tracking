<?php

namespace Referenced;

class Settings
{
    const PAGE = 'referenced-ai-crawler-tracking';
    const GROUP = 'referenced_settings';

    public static function register()
    {
        add_action('admin_menu', [__CLASS__, 'addPage']);
        add_action('admin_init', [__CLASS__, 'registerSetting']);
        add_action('admin_post_referenced_send_now', [__CLASS__, 'handleSendNow']);
        add_action('admin_post_referenced_disconnect', [__CLASS__, 'handleDisconnect']);
    }

    public static function addPage()
    {
        add_options_page(
            __('Referenced', 'referenced-ai-crawler-tracking'),
            __('Referenced', 'referenced-ai-crawler-tracking'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render']
        );
    }

    public static function registerSetting()
    {
        register_setting(self::GROUP, 'referenced_token', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitizeToken'],
            'default' => '',
        ]);
    }

    /**
     * Masked display value: bullets plus the last four characters.
     */
    public static function mask($token)
    {
        return "\u{2022}\u{2022}\u{2022}\u{2022}" . substr((string) $token, -4);
    }

    /**
     * Create the token option with autoload "no" when it does not exist yet.
     * The activation hook does this too; this covers installs that predate it.
     */
    private static function ensureTokenOption()
    {
        if (get_option('referenced_token', null) === null) {
            add_option('referenced_token', '', '', 'no');
        }
    }

    public static function sanitizeToken($value)
    {
        static $running = false;
        if ($running) {
            return $value;
        }
        $running = true;

        $stored = (string) get_option('referenced_token', '');
        $submitted = trim(sanitize_text_field((string) $value));

        self::ensureTokenOption();

        // Empty or the unchanged masked value keeps the stored token. Clearing is the Disconnect button's job.
        if ($stored !== '' && ($submitted === '' || $submitted === self::mask($stored))) {
            $running = false;

            return $stored;
        }

        if ($submitted === '') {
            update_option('referenced_enabled', false);
            $running = false;

            return '';
        }

        if (! preg_match('/\A[A-Za-z0-9]{40}\z/', $submitted)) {
            add_settings_error(
                'referenced_token',
                'referenced_token_invalid',
                __('That does not look like a Referenced token. It should be 40 letters and numbers.', 'referenced-ai-crawler-tracking')
            );
            $running = false;

            return $stored;
        }

        update_option('referenced_enabled', true);
        $running = false;

        return $submitted;
    }

    public static function handleSendNow()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'referenced-ai-crawler-tracking'), '', ['response' => 403]);
        }
        check_admin_referer('referenced_send_now');

        referenced_send_hits();

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'sent' => 1], admin_url('options-general.php')));
        exit;
    }

    public static function handleDisconnect()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'referenced-ai-crawler-tracking'), '', ['response' => 403]);
        }
        check_admin_referer('referenced_disconnect');

        delete_option('referenced_token');
        add_option('referenced_token', '', '', 'no');
        update_option('referenced_enabled', false);

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'disconnected' => 1], admin_url('options-general.php')));
        exit;
    }

    private static function statusText()
    {
        $last = get_option('referenced_last_send');
        if (! is_array($last) || ! isset($last['at'])) {
            return __('Nothing sent yet.', 'referenced-ai-crawler-tracking');
        }

        $status = isset($last['status']) ? sanitize_text_field((string) $last['status']) : '';
        $at = gmdate('Y-m-d H:i', (int) $last['at']) . ' UTC';

        /* translators: 1: status message, 2: UTC date and time */
        return sprintf(__('Last send: %1$s (%2$s)', 'referenced-ai-crawler-tracking'), $status, $at);
    }

    public static function render()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $token = (string) get_option('referenced_token', '');
        $value = $token !== '' ? self::mask($token) : '';
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirect.
        $sent = isset($_GET['sent']);
        $disconnected = isset($_GET['disconnected']);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $action = admin_url('admin-post.php');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Referenced – AI Crawler Tracking', 'referenced-ai-crawler-tracking'); ?></h1>

            <?php if ($sent) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Sent. The status below shows the result.', 'referenced-ai-crawler-tracking'); ?></p></div>
            <?php endif; ?>
            <?php if ($disconnected) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Disconnected. Tracking is off and the token is removed.', 'referenced-ai-crawler-tracking'); ?></p></div>
            <?php endif; ?>

            <?php settings_errors('referenced_token'); ?>

            <p>
                <?php echo esc_html__('This plugin counts visits from known AI crawlers and sends daily totals to Referenced. Each send contains: bot name, path, date, count, plugin version and WordPress version, and your home URL. Nothing about visitors is collected or sent.', 'referenced-ai-crawler-tracking'); ?>
                <a href="<?php echo esc_url('https://getreferenced.ai/privacy'); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Privacy', 'referenced-ai-crawler-tracking'); ?></a>
                &middot;
                <a href="<?php echo esc_url('https://getreferenced.ai/terms'); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Terms', 'referenced-ai-crawler-tracking'); ?></a>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="referenced_token"><?php echo esc_html__('Token', 'referenced-ai-crawler-tracking'); ?></label></th>
                        <td>
                            <input type="text" id="referenced_token" name="referenced_token" class="regular-text" autocomplete="off" spellcheck="false" value="<?php echo esc_attr($value); ?>" />
                            <p class="description">
                                <?php
                                if ($token !== '') {
                                    echo esc_html__('To replace the token, paste a new one over this and save. Leaving it as is keeps the current token.', 'referenced-ai-crawler-tracking');
                                } else {
                                    echo esc_html__('Paste the token from Referenced, under Settings, Connections.', 'referenced-ai-crawler-tracking');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save token', 'referenced-ai-crawler-tracking')); ?>
            </form>

            <p><strong><?php echo esc_html__('Status:', 'referenced-ai-crawler-tracking'); ?></strong> <?php echo esc_html(self::statusText()); ?></p>

            <div style="display:flex;gap:8px;align-items:center;">
                <form method="post" action="<?php echo esc_url($action); ?>">
                    <input type="hidden" name="action" value="referenced_send_now" />
                    <?php wp_nonce_field('referenced_send_now'); ?>
                    <?php submit_button(__('Send now', 'referenced-ai-crawler-tracking'), 'primary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url($action); ?>">
                    <input type="hidden" name="action" value="referenced_disconnect" />
                    <?php wp_nonce_field('referenced_disconnect'); ?>
                    <?php submit_button(__('Disconnect', 'referenced-ai-crawler-tracking'), 'secondary', 'submit', false); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
