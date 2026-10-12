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

        // Counters shown in the SMS Logs summary are cached briefly.
        delete_transient( 'reunion_reg_sms_counts' );

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
        return 'Dear {name}, reg confirmed for Bagbari KM High School Centenary \'26. Reg ID: {reg_id}. Keep this ID to show at gate check-in.';
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

            <h2>Send Test SMS</h2>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="reunion_reg_test_sms">
                <?php wp_nonce_field( 'reunion_reg_test_sms' ); ?>
                <input type="text" name="test_phone" class="regular-text" placeholder="01XXXXXXXXX" required>
                <?php submit_button( 'Send Test SMS', 'secondary', 'submit', false ); ?>
            </form>

            <?php $this->render_sms_logs_section(); ?>
        </div>
        <?php
    }

    /**
     * SMS Logs section: summary counters, Reg ID/phone search, a paginated
     * log table sorted by send time (newest first), and paginate_links().
     */
    private function render_sms_logs_section() {
        $search   = isset( $_GET['sms_log_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sms_log_search'] ) ) : '';
        $per_page = isset( $_GET['sms_log_per_page'] ) ? absint( $_GET['sms_log_per_page'] ) : 50;
        if ( ! in_array( $per_page, array( 20, 50 ), true ) ) {
            $per_page = 50;
        }
        $paged = isset( $_GET['sms_log_page'] ) ? max( 1, absint( $_GET['sms_log_page'] ) ) : 1;

        $counts = $this->get_sms_counts();
        $logs   = $this->get_sms_logs( $per_page, $paged, $search );
        $rows   = $logs['rows'];
        $total  = $logs['total'];
        $pages  = max( 1, (int) ceil( $total / $per_page ) );

        $base_url = add_query_arg(
            array( 'post_type' => REUNION_REG_CPT_SLUG, 'page' => 'reunion_reg_sms_settings' ),
            admin_url( 'edit.php' )
        );
        ?>
        <hr>
        <h2>SMS Logs</h2>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0 20px;">
            <div style="flex:1;min-width:180px;background:#f0f6ff;border:1px solid #c5d9f1;border-radius:8px;padding:14px;">
                <div style="font-size:12px;color:#50575e;text-transform:uppercase;letter-spacing:.5px;">Total Sent</div>
                <div style="font-size:26px;font-weight:700;color:#1e4620;"><?php echo esc_html( $counts['sent'] ); ?></div>
            </div>
            <div style="flex:1;min-width:180px;background:#fdecea;border:1px solid #f1c0bb;border-radius:8px;padding:14px;">
                <div style="font-size:12px;color:#50575e;text-transform:uppercase;letter-spacing:.5px;">Total Failed</div>
                <div style="font-size:26px;font-weight:700;color:#b32d2e;"><?php echo esc_html( $counts['failed'] ); ?></div>
            </div>
            <div style="flex:1;min-width:180px;background:#fff8e1;border:1px solid #f0dca0;border-radius:8px;padding:14px;">
                <div style="font-size:12px;color:#50575e;text-transform:uppercase;letter-spacing:.5px;">Total Pending</div>
                <div style="font-size:26px;font-weight:700;color:#8a6d3b;"><?php echo esc_html( $counts['pending'] ); ?></div>
            </div>
        </div>

        <form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="post_type" value="<?php echo esc_attr( REUNION_REG_CPT_SLUG ); ?>">
            <input type="hidden" name="page" value="reunion_reg_sms_settings">
            <input type="search" name="sms_log_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by Reg ID or Phone" style="min-width:260px;">
            <select name="sms_log_per_page">
                <option value="20" <?php selected( $per_page, 20 ); ?>>20 per page</option>
                <option value="50" <?php selected( $per_page, 50 ); ?>>50 per page</option>
            </select>
            <?php submit_button( 'Search', 'secondary', 'submit', false ); ?>
            <?php if ( '' !== $search ) : ?>
                <a href="<?php echo esc_url( $base_url ); ?>" class="button">Clear</a>
            <?php endif; ?>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:22%;">Applicant Name</th>
                    <th style="width:18%;">Reg ID</th>
                    <th style="width:18%;">Phone Number</th>
                    <th style="width:14%;">SMS Status</th>
                    <th style="width:28%;">Sent Date/Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="5">No SMS records found.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row->full_name !== null && $row->full_name !== '' ? $row->full_name : '—' ); ?></td>
                            <td><?php echo $row->reg_id ? '<strong>' . esc_html( $row->reg_id ) . '</strong>' : '—'; ?></td>
                            <td><?php echo esc_html( $row->phone !== null && $row->phone !== '' ? $row->phone : '—' ); ?></td>
                            <td>
                                <?php if ( 'sent' === $row->sms_status ) : ?>
                                    <span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;background:#e6f4ea;color:#1e4620;">Success</span>
                                <?php else : ?>
                                    <span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;background:#fdecea;color:#b32d2e;">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row->sms_time ? esc_html( mysql2date( 'd M Y, g:i a', $row->sms_time ) ) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ) : ?>
            <div class="tablenav" style="margin-top:12px;">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post( paginate_links( array(
                        'base'      => add_query_arg(
                            array(
                                'post_type'        => REUNION_REG_CPT_SLUG,
                                'page'             => 'reunion_reg_sms_settings',
                                'sms_log_search'   => $search,
                                'sms_log_per_page' => $per_page,
                                'sms_log_page'     => '%#%',
                            ),
                            admin_url( 'edit.php' )
                        ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $pages,
                        'prev_text' => '«',
                        'next_text' => '»',
                        'type'      => 'plain',
                    ) ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <p style="color:#646970;margin-top:8px;">
            <?php
            $showing_from = $total ? ( ( $paged - 1 ) * $per_page + 1 ) : 0;
            $showing_to   = min( $total, $paged * $per_page );
            /* translators: 1: first record number, 2: last record number, 3: total records. */
            echo esc_html( sprintf( 'Showing %1$d–%2$d of %3$d SMS records.', $showing_from, $showing_to, $total ) );
            ?>
        </p>
        <?php
    }

    /**
     * Summary counters for the SMS log. Uses two direct aggregate $wpdb
     * queries (no post objects fetched) and caches the result briefly.
     */
    private function get_sms_counts() {
        global $wpdb;

        $cached = get_transient( 'reunion_reg_sms_counts' );
        if ( is_array( $cached ) && isset( $cached['sent'], $cached['failed'], $cached['pending'] ) ) {
            return $cached;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM( CASE WHEN sms.meta_value = 'sent' THEN 1 ELSE 0 END ) AS sent,
                SUM( CASE WHEN sms.meta_value = 'failed' THEN 1 ELSE 0 END ) AS failed
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} sms ON sms.post_id = p.ID AND sms.meta_key = '_reunion_sms_status'
             WHERE p.post_type = %s AND p.post_status = 'publish'",
            REUNION_REG_CPT_SLUG
        ) );

        // Pending = approved registrations that have never had an SMS attempt.
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_reunion_status' AND st.meta_value = 'approved'
             LEFT JOIN {$wpdb->postmeta} sms ON sms.post_id = p.ID AND sms.meta_key = '_reunion_sms_status'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND ( sms.meta_value IS NULL OR sms.meta_value = '' OR sms.meta_value = 'pending' )",
            REUNION_REG_CPT_SLUG
        ) );

        $counts = array(
            'sent'    => $row ? (int) $row->sent : 0,
            'failed'  => $row ? (int) $row->failed : 0,
            'pending' => $pending,
        );

        set_transient( 'reunion_reg_sms_counts', $counts, 2 * MINUTE_IN_SECONDS );

        return $counts;
    }

    /**
     * Paginated SMS log rows (newest first). Joins each post's meta once so
     * no per-row get_post_meta() calls are needed. Returns rows + total count.
     *
     * @return array { rows: object[], total: int }
     */
    private function get_sms_logs( $per_page, $paged, $search ) {
        global $wpdb;

        $per_page = max( 1, (int) $per_page );
        $paged    = max( 1, (int) $paged );
        $offset   = ( $paged - 1 ) * $per_page;

        $joins = "INNER JOIN {$wpdb->postmeta} sms ON sms.post_id = p.ID AND sms.meta_key = '_reunion_sms_status'
            LEFT JOIN {$wpdb->postmeta} nm ON nm.post_id = p.ID AND nm.meta_key = '_reunion_full_name'
            LEFT JOIN {$wpdb->postmeta} rg ON rg.post_id = p.ID AND rg.meta_key = '_reunion_reg_id'
            LEFT JOIN {$wpdb->postmeta} ph ON ph.post_id = p.ID AND ph.meta_key = '_reunion_phone'
            LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = p.ID AND tm.meta_key = '_reunion_sms_time'";

        $where  = "WHERE p.post_type = %s AND p.post_status = 'publish'";
        $params = array( REUNION_REG_CPT_SLUG );

        if ( '' !== $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where   .= ' AND ( rg.meta_value LIKE %s OR ph.meta_value LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }

        $from = "FROM {$wpdb->posts} p {$joins} {$where}";

        $total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) ' . $from, $params ) );

        $sql = 'SELECT p.ID, nm.meta_value AS full_name, rg.meta_value AS reg_id, ph.meta_value AS phone, sms.meta_value AS sms_status, tm.meta_value AS sms_time '
            . $from
            . ' ORDER BY tm.meta_value DESC, p.ID DESC LIMIT %d OFFSET %d';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ) );

        return array( 'rows' => $rows ? $rows : array(), 'total' => $total );
    }

}
