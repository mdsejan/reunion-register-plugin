<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Approval SMS via BulkSMSBD, plus the SMS Settings admin page and the
 * SMS status/resend UI on the Registrations screens.
 *
 * Entirely self-contained: it discovers what to do purely by listening for
 * 'reunion_reg_after_approve'. No other module calls
 * maybe_send_approval_sms() directly — deleting this file would leave the
 * approval workflow working exactly the same, it just wouldn't send SMS
 * anymore. All BulkSMSBD-specific logic lives inside send_sms() and
 * error_meaning() so the gateway is easy to swap later.
 */
class Reunion_Reg_SMS_Gateway {

    /** BulkSMSBD send endpoint (used when no API URL is saved in settings). */
    const DEFAULT_API_URL = 'http://bulksmsbd.net/api/smsapi';

    /** BulkSMSBD balance endpoint. */
    const BALANCE_API_URL = 'http://bulksmsbd.net/api/getBalanceApi';

    public function __construct() {
        add_action( 'reunion_reg_after_approve', array( $this, 'maybe_send_approval_sms' ), 10, 2 );

        add_action( 'admin_menu', array( $this, 'add_sms_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_sms_settings' ) );

        // Registrations list: an "SMS" column right after Status + resend row action.
        add_filter( 'manage_' . REUNION_REG_CPT_SLUG . '_posts_columns', array( $this, 'add_sms_column' ), 20 );
        add_action( 'manage_' . REUNION_REG_CPT_SLUG . '_posts_custom_column', array( $this, 'render_sms_column' ), 10, 2 );
        add_filter( 'post_row_actions', array( $this, 'add_resend_row_action' ), 20, 2 );

        // Edit screen: SMS Status side meta box.
        add_action( 'add_meta_boxes', array( $this, 'add_sms_meta_box' ) );

        // Resend + test SMS admin-post handlers, and their admin notices.
        add_action( 'admin_post_reunion_reg_resend_sms', array( $this, 'handle_resend_sms' ) );
        add_action( 'admin_post_reunion_reg_test_sms', array( $this, 'handle_test_sms' ) );
        add_action( 'admin_notices', array( $this, 'show_sms_admin_notices' ) );
    }

    /**
     * Checks SMS settings and sends the approval SMS if enabled & configured.
     * Returns the ['success','code','message'] result from send_sms().
     *
     * @param int         $post_id Registration post ID.
     * @param string      $reg_id  Registration ID (from the after_approve hook).
     * @param bool        $force   True to ignore the "enabled" toggle and the
     *                             "already sent" guard (manual resend).
     */
    public function maybe_send_approval_sms( $post_id, $reg_id, $force = false ) {
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );

        if ( ! $force && empty( $settings['enabled'] ) ) {
            return array( 'success' => false, 'code' => 0, 'message' => 'SMS sending is disabled in SMS Settings.' );
        }

        $post_id = absint( $post_id );
        $reg_id  = $reg_id ? $reg_id : get_post_meta( $post_id, '_reunion_reg_id', true );

        // Auto-send is skipped if an SMS was already sent successfully.
        if ( ! $force && 'sent' === get_post_meta( $post_id, '_reunion_sms_status', true ) ) {
            return array( 'success' => true, 'code' => 0, 'message' => 'SMS already sent.' );
        }

        $phone   = get_post_meta( $post_id, '_reunion_phone', true );
        $message = $this->build_approval_sms_message( $post_id, $reg_id );

        $result = $this->send_sms( $phone, $message );

        $attempts = (int) get_post_meta( $post_id, '_reunion_sms_attempts', true ) + 1;

        update_post_meta( $post_id, '_reunion_sms_status', ! empty( $result['success'] ) ? 'sent' : 'failed' );
        update_post_meta( $post_id, '_reunion_sms_code', isset( $result['code'] ) ? (int) $result['code'] : 0 );
        update_post_meta( $post_id, '_reunion_sms_msg', isset( $result['message'] ) ? (string) $result['message'] : '' );
        update_post_meta( $post_id, '_reunion_sms_time', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_reunion_sms_attempts', $attempts );

        return $result;
    }

    /**
     * Sends one SMS through BulkSMSBD (POST only). All gateway-specific
     * knowledge lives here + error_meaning() so this method is the only
     * thing to rewrite when swapping providers.
     *
     * @return array { success: bool, code: int, message: string }
     */
    private function send_sms( $phone, $message ) {
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );

        $api_url   = ( isset( $settings['api_url'] ) && trim( $settings['api_url'] ) !== '' ) ? trim( $settings['api_url'] ) : self::DEFAULT_API_URL;
        $api_key   = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';
        $sender_id = isset( $settings['sender_id'] ) ? trim( $settings['sender_id'] ) : '';

        if ( empty( $api_key ) ) {
            error_log( 'Reunion Registration SMS error: API key is not configured.' );
            return array( 'success' => false, 'code' => 0, 'message' => 'API key is not configured in SMS Settings.' );
        }

        if ( empty( $sender_id ) ) {
            error_log( 'Reunion Registration SMS error: Sender ID is not configured.' );
            return array( 'success' => false, 'code' => 0, 'message' => 'Sender ID is not configured in SMS Settings.' );
        }

        $number = $this->normalize_bd_phone( $phone );
        if ( empty( $number ) ) {
            error_log( 'Reunion Registration SMS error: invalid recipient number "' . $phone . '".' );
            return array( 'success' => false, 'code' => 1001, 'message' => $this->error_meaning( 1001 ) );
        }

        if ( '' === trim( (string) $message ) ) {
            return array( 'success' => false, 'code' => 1003, 'message' => $this->error_meaning( 1003 ) );
        }

        $params = array(
            'api_key'  => $api_key,
            'type'     => 'text',
            'senderid' => $sender_id,
            'number'   => $number,
            'message'  => $message,
        );

        $params = apply_filters( 'reunion_reg_sms_params', $params, $number, $message );

        $response = wp_remote_post( $api_url, array(
            'timeout' => 20,
            'body'    => $params,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Reunion Registration SMS error: ' . $response->get_error_message() );
            return array( 'success' => false, 'code' => 0, 'message' => $response->get_error_message() );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        $code = ( is_array( $data ) && isset( $data['response_code'] ) ) ? (int) $data['response_code'] : 0;

        // Only 202 means "submitted".
        if ( 202 === $code ) {
            return array( 'success' => true, 'code' => 202, 'message' => $this->error_meaning( 202 ) );
        }

        $meaning = $this->error_meaning( $code );

        if ( '' === $meaning ) {
            if ( is_array( $data ) ) {
                foreach ( array( 'error_message', 'success_message', 'message', 'msg' ) as $key ) {
                    if ( ! empty( $data[ $key ] ) ) {
                        $meaning = (string) $data[ $key ];
                        break;
                    }
                }
            } elseif ( '' !== trim( (string) $body ) ) {
                $meaning = trim( (string) $body );
            }
            if ( '' === $meaning ) {
                $meaning = sprintf( 'Unexpected gateway response (HTTP %d).', $http_code );
            }
        }

        error_log( 'Reunion Registration SMS error: code ' . $code . ' — ' . $meaning );

        return array( 'success' => false, 'code' => $code, 'message' => $meaning );
    }

    /**
     * Human-readable meaning for a BulkSMSBD response_code.
     */
    private function error_meaning( $code ) {
        $code = (int) $code;

        if ( $code >= 1013 && $code <= 1021 ) {
            return 'Gateway/price configuration error';
        }

        $map = array(
            202  => 'SMS submitted successfully',
            1001 => 'Invalid number',
            1002 => 'Sender ID incorrect or disabled',
            1003 => 'Required fields missing',
            1005 => 'Internal error',
            1006 => 'Balance validity not available',
            1007 => 'Insufficient balance',
            1011 => 'User ID not found',
            1012 => 'Masking SMS must be in Bengali',
            1031 => 'Account not verified',
            1032 => 'IP not whitelisted',
        );

        return isset( $map[ $code ] ) ? $map[ $code ] : '';
    }

    /**
     * Normalize any Bangladesh mobile number to BulkSMSBD's 88017XXXXXXXX
     * format. Accepts 017…, +88017…, 88017…, 17…. Returns an empty string
     * when the value can't be resolved to a 13-digit 8801… number.
     */
    public function normalize_bd_phone( $phone ) {
        $digits = preg_replace( '/\D/', '', (string) $phone );

        if ( '' === $digits ) {
            return '';
        }

        if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '880' ) ) {
            $normalized = $digits;
        } elseif ( 11 === strlen( $digits ) && 0 === strpos( $digits, '0' ) ) {
            $normalized = '88' . $digits;
        } elseif ( 10 === strlen( $digits ) && 0 === strpos( $digits, '1' ) ) {
            $normalized = '880' . $digits;
        } else {
            return '';
        }

        if ( 13 === strlen( $normalized ) && 0 === strpos( $normalized, '8801' ) ) {
            return $normalized;
        }

        return '';
    }

    /**
     * Build the SMS message for an approved registration using the admin-defined template.
     * Supported placeholders: {name}, {reg_id}, {batch}, {phone}, {site_name}
     */
    private function build_approval_sms_message( $post_id, $reg_id ) {
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );
        $template = ! empty( $settings['template'] )
            ? $settings['template']
            : $this->default_template();

        $replacements = array(
            '{name}'      => get_post_meta( $post_id, '_reunion_full_name', true ),
            '{reg_id}'    => $reg_id,
            '{batch}'     => get_post_meta( $post_id, '_reunion_batch', true ),
            '{phone}'     => get_post_meta( $post_id, '_reunion_phone', true ),
            '{site_name}' => get_bloginfo( 'name' ),
        );

        return strtr( $template, $replacements );
    }

    /**
     * Default SMS template — shared by the message builder and the settings textarea.
     */
    private function default_template() {
        return 'Dear {name}, Registration confirmed for Bagbari KM High School Centenary Celebration 2026. Reg ID: {reg_id}. Please keep this for check-in.';
    }

    // -----------------------------------------------------------------
    // Registrations list: SMS column + resend row action
    // -----------------------------------------------------------------

    public function add_sms_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( 'reunion_status' === $key ) {
                $new_columns['reunion_sms'] = 'SMS';
            }
        }
        if ( ! isset( $new_columns['reunion_sms'] ) ) {
            $new_columns['reunion_sms'] = 'SMS';
        }
        return $new_columns;
    }

    public function render_sms_column( $column, $post_id ) {
        if ( 'reunion_sms' !== $column ) {
            return;
        }

        $status = get_post_meta( $post_id, '_reunion_sms_status', true );

        if ( 'sent' === $status ) {
            echo '<span style="color:#1e4620;font-weight:600;">Submitted</span>';
        } elseif ( 'failed' === $status ) {
            $code = get_post_meta( $post_id, '_reunion_sms_code', true );
            $msg  = get_post_meta( $post_id, '_reunion_sms_msg', true );
            $tip  = $msg !== '' ? $msg : 'SMS failed';
            if ( '' !== (string) $code && '0' !== (string) $code ) {
                $tip = 'Code ' . $code . ': ' . $tip;
            }
            echo '<span style="color:#b32d2e;font-weight:600;" title="' . esc_attr( $tip ) . '">Failed</span>';
        } else {
            echo '<span style="color:#646970;">Not sent</span>';
        }
    }

    public function add_resend_row_action( $actions, $post ) {
        if ( ! $post || $post->post_type !== REUNION_REG_CPT_SLUG ) {
            return $actions;
        }

        // Only offer resend for approved registrations (never rejected).
        if ( 'approved' !== Reunion_Reg_CPT::get_status( $post->ID ) ) {
            return $actions;
        }

        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=reunion_reg_resend_sms&post_id=' . $post->ID ),
            'reunion_reg_resend_sms_' . $post->ID
        );

        $actions['reunion_resend_sms'] = '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'Resend the approval SMS to this applicant?\');">Resend SMS</a>';

        return $actions;
    }

    // -----------------------------------------------------------------
    // Edit screen: SMS Status side meta box
    // -----------------------------------------------------------------

    public function add_sms_meta_box() {
        global $post;
        if ( ! $post || get_post_type( $post ) !== REUNION_REG_CPT_SLUG ) {
            return;
        }
        if ( empty( get_post_meta( $post->ID, '_reunion_submitted_at', true ) ) ) {
            return;
        }

        add_meta_box(
            'reunion_reg_sms_status',
            'SMS Status',
            array( $this, 'render_sms_meta_box' ),
            REUNION_REG_CPT_SLUG,
            'side',
            'default'
        );
    }

    public function render_sms_meta_box( $post ) {
        $sms_status = get_post_meta( $post->ID, '_reunion_sms_status', true );
        $code       = get_post_meta( $post->ID, '_reunion_sms_code', true );
        $msg        = get_post_meta( $post->ID, '_reunion_sms_msg', true );
        $time       = get_post_meta( $post->ID, '_reunion_sms_time', true );
        $attempts   = (int) get_post_meta( $post->ID, '_reunion_sms_attempts', true );
        $reg_status = Reunion_Reg_CPT::get_status( $post->ID );

        if ( 'sent' === $sms_status ) {
            $badge = '<span style="color:#1e4620;font-weight:600;">Submitted</span>';
        } elseif ( 'failed' === $sms_status ) {
            $badge = '<span style="color:#b32d2e;font-weight:600;">Failed</span>';
        } else {
            $badge = '<span style="color:#646970;">Not sent</span>';
        }

        echo '<p><strong>Status:</strong> ' . wp_kses_post( $badge ) . '</p>';

        if ( '' !== (string) $code && '0' !== (string) $code ) {
            $meaning = $this->error_meaning( $code );
            if ( '' === $meaning ) {
                $meaning = $msg;
            }
            echo '<p><strong>Response:</strong> ' . esc_html( $code ) . ( '' !== $meaning ? ' — ' . esc_html( $meaning ) : '' ) . '</p>';
        }

        echo '<p><strong>Last attempt:</strong> ' . ( $time ? esc_html( $time ) : '—' ) . '</p>';
        echo '<p><strong>Attempts:</strong> ' . esc_html( $attempts ) . '</p>';

        if ( 'approved' === $reg_status ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=reunion_reg_resend_sms&post_id=' . $post->ID ),
                'reunion_reg_resend_sms_' . $post->ID
            );
            echo '<p><a href="' . esc_url( $url ) . '" class="button" onclick="return confirm(\'Resend the approval SMS to this applicant?\');">Resend SMS</a></p>';
        }
    }

    // -----------------------------------------------------------------
    // Admin-post handlers + notices
    // -----------------------------------------------------------------

    public function handle_resend_sms() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

        if ( ! $post_id || get_post_type( $post_id ) !== REUNION_REG_CPT_SLUG ) {
            wp_die( 'Invalid registration entry.' );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 'reunion_reg_resend_sms_' . $post_id );

        if ( 'approved' !== Reunion_Reg_CPT::get_status( $post_id ) ) {
            $this->store_sms_notice( array( 'success' => false, 'code' => 0, 'message' => 'SMS can only be sent for approved registrations.' ) );
        } else {
            $reg_id = get_post_meta( $post_id, '_reunion_reg_id', true );
            $result = $this->maybe_send_approval_sms( $post_id, $reg_id, true );
            $this->store_sms_notice( $result );
        }

        wp_safe_redirect( add_query_arg( 'reunion_sms_notice', '1', wp_get_referer() ?: admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG ) ) );
        exit;
    }

    public function handle_test_sms() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 'reunion_reg_test_sms' );

        $phone = isset( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : '';

        $message = sprintf( 'Test SMS from %s — BulkSMSBD gateway is working.', get_bloginfo( 'name' ) );
        $result  = $this->send_sms( $phone, $message );

        $this->store_sms_notice( $result );

        wp_safe_redirect( add_query_arg( array(
            'post_type'          => REUNION_REG_CPT_SLUG,
            'page'               => 'reunion_reg_sms_settings',
            'reunion_sms_notice' => '1',
        ), admin_url( 'edit.php' ) ) );
        exit;
    }

    private function store_sms_notice( $result ) {
        set_transient(
            'reunion_reg_sms_notice_' . get_current_user_id(),
            array(
                'success' => ! empty( $result['success'] ),
                'code'    => isset( $result['code'] ) ? (int) $result['code'] : 0,
                'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
            ),
            MINUTE_IN_SECONDS
        );
    }

    public function show_sms_admin_notices() {
        if ( empty( $_GET['reunion_sms_notice'] ) ) {
            return;
        }

        $key  = 'reunion_reg_sms_notice_' . get_current_user_id();
        $data = get_transient( $key );
        delete_transient( $key );

        if ( ! is_array( $data ) ) {
            return;
        }

        if ( ! empty( $data['success'] ) ) {
            $css_class = 'notice-success';
            $text      = 'SMS sent successfully.';
        } else {
            $css_class = 'notice-error';
            $detail    = '';
            if ( ! empty( $data['code'] ) ) {
                $detail = $this->error_meaning( $data['code'] );
            }
            if ( '' === $detail && ! empty( $data['message'] ) ) {
                $detail = $data['message'];
            }
            $text = 'SMS failed' . ( '' !== $detail ? ': ' . $detail : '' );
            if ( ! empty( $data['code'] ) ) {
                $text .= ' (code ' . (int) $data['code'] . ')';
            }
        }

        echo '<div class="notice ' . esc_attr( $css_class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
    }

    // -----------------------------------------------------------------
    // SMS Settings admin page
    // -----------------------------------------------------------------

    public function add_sms_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'SMS Settings',
            'SMS Settings',
            'manage_options',
            'reunion_reg_sms_settings',
            array( $this, 'render_sms_settings_page' )
        );
    }

    public function register_sms_settings() {
        register_setting( 'reunion_reg_sms_settings_group', REUNION_REG_SMS_SETTINGS_OPTION, array(
            'sanitize_callback' => array( $this, 'sanitize_sms_settings' ),
        ) );
    }

    public function sanitize_sms_settings( $input ) {
        return array(
            'enabled'   => ! empty( $input['enabled'] ) ? 1 : 0,
            'api_url'   => isset( $input['api_url'] ) ? esc_url_raw( trim( $input['api_url'] ) ) : '',
            'api_key'   => isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : '',
            'sender_id' => isset( $input['sender_id'] ) ? sanitize_text_field( trim( $input['sender_id'] ) ) : '',
            'template'  => isset( $input['template'] ) ? sanitize_textarea_field( $input['template'] ) : '',
        );
    }

    public function render_sms_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );
        ?>
        <div class="wrap">
            <h1>SMS Settings</h1>
            <p>Configure your BulkSMSBD gateway here so an SMS is automatically sent to applicants when their registration is approved.</p>

            <form method="POST" action="options.php">
                <?php settings_fields( 'reunion_reg_sms_settings_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="sms_enabled">Enable SMS</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="sms_enabled" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                Send SMS automatically when a registration is approved
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_api_url">Gateway API URL</label></th>
                        <td>
                            <input type="text" id="sms_api_url" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[api_url]" value="<?php echo esc_attr( $settings['api_url'] ?? '' ); ?>" class="regular-text" placeholder="http://bulksmsbd.net/api/smsapi">
                            <p class="description">The BulkSMSBD endpoint. Leave empty to use the default: <code><?php echo esc_html( self::DEFAULT_API_URL ); ?></code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_api_key">API Key</label></th>
                        <td>
                            <input type="text" id="sms_api_key" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_sender_id">Sender ID (required)</label></th>
                        <td>
                            <input type="text" id="sms_sender_id" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[sender_id]" value="<?php echo esc_attr( $settings['sender_id'] ?? '' ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_template">SMS Message Template</label></th>
                        <td>
                            <textarea id="sms_template" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[template]" rows="4" class="large-text"><?php echo esc_textarea( $settings['template'] ?? $this->default_template() ); ?></textarea>
                            <p class="description">Available placeholders: <code>{name}</code>, <code>{reg_id}</code>, <code>{batch}</code>, <code>{phone}</code>, <code>{site_name}</code></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save SMS Settings' ); ?>
            </form>

            <p style="color:#666;">
                <strong>Note:</strong> This plugin sends SMS through <strong>BulkSMSBD</strong>. Your server IP must be whitelisted in your BulkSMSBD account, and masking sender IDs require the SMS text to be in Bengali.
            </p>

            <hr>

            <h2>Gateway Balance</h2>
            <?php $balance = $this->get_balance(); ?>
            <p>
                <?php if ( ! empty( $balance['ok'] ) ) : ?>
                    Current balance: <strong><?php echo esc_html( $balance['text'] ); ?></strong>
                <?php else : ?>
                    <span style="color:#b32d2e;">Could not retrieve balance: <?php echo esc_html( $balance['text'] ); ?></span>
                <?php endif; ?>
            </p>

            <h2>Send Test SMS</h2>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="reunion_reg_test_sms">
                <?php wp_nonce_field( 'reunion_reg_test_sms' ); ?>
                <input type="text" name="test_phone" class="regular-text" placeholder="01XXXXXXXXX" required>
                <?php submit_button( 'Send Test SMS', 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Fetch the current BulkSMSBD balance. Successful lookups are cached for
     * a short while so the settings page doesn't hit the API on every load.
     */
    private function get_balance() {
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );
        $api_key  = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

        if ( empty( $api_key ) ) {
            return array( 'ok' => false, 'text' => 'API key not configured.' );
        }

        $cached = get_transient( 'reunion_reg_sms_balance' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get( add_query_arg( 'api_key', rawurlencode( $api_key ), self::BALANCE_API_URL ), array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            return array( 'ok' => false, 'text' => $response->get_error_message() );
        }

        $body = trim( (string) wp_remote_retrieve_body( $response ) );
        $data = json_decode( $body, true );

        $text = '';
        if ( is_array( $data ) ) {
            if ( isset( $data['balance'] ) ) {
                $text = (string) $data['balance'];
            } elseif ( isset( $data['Balance'] ) ) {
                $text = (string) $data['Balance'];
            }
        } elseif ( '' !== $body ) {
            $text = $body;
        }

        if ( '' === $text ) {
            return array( 'ok' => false, 'text' => 'Unexpected response from the balance API.' );
        }

        $result = array( 'ok' => true, 'text' => $text );
        set_transient( 'reunion_reg_sms_balance', $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }
}
