<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sends the approval SMS via a generically-configurable HTTP gateway, and
 * owns the SMS Settings admin page. Entirely self-contained: it discovers
 * what to do purely by listening for 'reunion_reg_after_approve'. No other
 * module calls maybe_send_approval_sms() directly — deleting this file
 * would leave the approval workflow working exactly the same, it just
 * wouldn't send SMS anymore.
 */
class Reunion_Reg_SMS_Gateway {

    public function __construct() {
        add_action( 'reunion_reg_after_approve', array( $this, 'maybe_send_approval_sms' ), 10, 2 );

        add_action( 'admin_menu', array( $this, 'add_sms_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_sms_settings' ) );
    }

    /**
     * Checks SMS settings and sends the approval SMS if enabled & configured.
     */
    public function maybe_send_approval_sms( $post_id, $reg_id ) {
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );

        if ( empty( $settings['enabled'] ) ) {
            return false;
        }

        $phone   = get_post_meta( $post_id, '_reunion_phone', true );
        $message = $this->build_approval_sms_message( $post_id, $reg_id );

        return $this->send_sms( $phone, $message );
    }

    /**
     * SMS sending — driven entirely by the Settings > Reunion SMS page.
     * Works with most Bangladeshi SMS gateways since they generally accept
     * a simple GET or POST request with API key, sender ID, recipient, and message.
     * If no gateway URL/key is configured yet, this safely does nothing.
     */
    private function send_sms( $phone, $message ) {
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );

        $api_url    = isset( $settings['api_url'] ) ? trim( $settings['api_url'] ) : '';
        $api_key    = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';
        $sender_id  = isset( $settings['sender_id'] ) ? trim( $settings['sender_id'] ) : '';
        $method     = isset( $settings['method'] ) && $settings['method'] === 'GET' ? 'GET' : 'POST';

        if ( empty( $api_url ) || empty( $api_key ) || empty( $phone ) ) {
            return false; // Not configured yet — silently skip.
        }

        $params = array(
            'api_key'    => $api_key,
            'sender_id'  => $sender_id,
            'to'         => $phone,
            'number'     => $phone, // some gateways use "number" instead of "to"
            'message'    => $message,
            'sms'        => $message, // some gateways use "sms" instead of "message"
        );

        $params = apply_filters( 'reunion_reg_sms_params', $params, $phone, $message );

        if ( $method === 'GET' ) {
            $response = wp_remote_get( add_query_arg( $params, $api_url ), array( 'timeout' => 15 ) );
        } else {
            $response = wp_remote_post( $api_url, array(
                'timeout' => 15,
                'body'    => $params,
            ) );
        }

        if ( is_wp_error( $response ) ) {
            error_log( 'Reunion Registration SMS error: ' . $response->get_error_message() );
            return false;
        }

        return true;
    }

    /**
     * Build the SMS message for an approved registration using the admin-defined template.
     * Supported placeholders: {name}, {reg_id}, {batch}, {phone}, {site_name}
     */
    private function build_approval_sms_message( $post_id, $reg_id ) {
        $settings = get_option( REUNION_REG_SMS_SETTINGS_OPTION, array() );
        $template = ! empty( $settings['template'] )
            ? $settings['template']
            : 'Dear {name}, your reunion registration is confirmed. Registration ID: {reg_id}. Please keep this for check-in. - {site_name}';

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
     * SMS Settings admin page — generic fields that fit most SMS gateway APIs.
     */
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
            'method'    => isset( $input['method'] ) && $input['method'] === 'GET' ? 'GET' : 'POST',
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
            <p>Configure your SMS gateway here so an SMS is automatically sent to applicants when their registration is approved. Works with most Bangladeshi gateways (Alpha SMS, BD Bulk SMS, SSL Wireless, etc) that accept a simple HTTP request.</p>

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
                            <input type="text" id="sms_api_url" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[api_url]" value="<?php echo esc_attr( $settings['api_url'] ?? '' ); ?>" class="regular-text" placeholder="https://api.yourgateway.com/sms/send">
                            <p class="description">The endpoint your SMS gateway provides for sending a message.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_method">Request Method</label></th>
                        <td>
                            <select id="sms_method" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[method]">
                                <option value="POST" <?php selected( ( $settings['method'] ?? 'POST' ), 'POST' ); ?>>POST</option>
                                <option value="GET" <?php selected( ( $settings['method'] ?? '' ), 'GET' ); ?>>GET</option>
                            </select>
                            <p class="description">Check your gateway's documentation for which method it expects.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_api_key">API Key</label></th>
                        <td>
                            <input type="text" id="sms_api_key" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_sender_id">Sender ID (optional)</label></th>
                        <td>
                            <input type="text" id="sms_sender_id" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[sender_id]" value="<?php echo esc_attr( $settings['sender_id'] ?? '' ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sms_template">SMS Message Template</label></th>
                        <td>
                            <textarea id="sms_template" name="<?php echo esc_attr( REUNION_REG_SMS_SETTINGS_OPTION ); ?>[template]" rows="4" class="large-text"><?php echo esc_textarea( $settings['template'] ?? 'Dear {name}, your reunion registration is confirmed. Registration ID: {reg_id}. Please keep this for check-in. - {site_name}' ); ?></textarea>
                            <p class="description">Available placeholders: <code>{name}</code>, <code>{reg_id}</code>, <code>{batch}</code>, <code>{phone}</code>, <code>{site_name}</code></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save SMS Settings' ); ?>
            </form>

            <p style="color:#666;">
                <strong>Note:</strong> Different gateways use different parameter names (e.g. some expect <code>to</code>, others <code>number</code> or <code>recipient</code>). This plugin sends common variants together (<code>to</code>, <code>number</code>, <code>message</code>, <code>sms</code>) to maximize compatibility, but if your gateway uses something unusual, you may need a small code adjustment — let your developer know which gateway you've chosen.
            </p>
        </div>
        <?php
    }
}
