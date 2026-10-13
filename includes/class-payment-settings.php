<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Payment & Fee Settings admin page — lets the client update bKash/Nagad/Rocket
 * numbers, bank transfer details, the registration/guest fee amounts, and the
 * Terms & Conditions link, whenever they change, without needing a developer
 * to edit code.
 */
class Reunion_Reg_Payment_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_payment_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_payment_settings' ) );
    }

    public function add_payment_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'Payment & Fee Settings',
            'Payment & Fee Settings',
            'manage_options',
            'reunion_reg_payment_settings',
            array( $this, 'render_payment_settings_page' )
        );
    }

    public function register_payment_settings() {
        register_setting( 'reunion_reg_payment_settings_group', REUNION_REG_PAYMENT_SETTINGS_OPTION, array(
            'sanitize_callback' => array( $this, 'sanitize_payment_settings' ),
        ) );
    }

    public function sanitize_payment_settings( $input ) {
        $allowed_gateways = array_keys( Reunion_Reg_Fields_Schema::get_gateways() );

        return array(
            'registration_fee' => isset( $input['registration_fee'] ) ? max( 0, (float) $input['registration_fee'] ) : 0,
            'guest_fee'        => isset( $input['guest_fee'] ) ? max( 0, (float) $input['guest_fee'] ) : 0,
            'person1_name'     => isset( $input['person1_name'] ) ? sanitize_text_field( trim( $input['person1_name'] ) ) : '',
            'person1_number'   => isset( $input['person1_number'] ) ? sanitize_text_field( trim( $input['person1_number'] ) ) : '',
            'person1_gateways' => self::sanitize_gateways( $input, 'person1_gateways', $allowed_gateways ),
            'person2_name'     => isset( $input['person2_name'] ) ? sanitize_text_field( trim( $input['person2_name'] ) ) : '',
            'person2_number'   => isset( $input['person2_number'] ) ? sanitize_text_field( trim( $input['person2_number'] ) ) : '',
            'person2_gateways' => self::sanitize_gateways( $input, 'person2_gateways', $allowed_gateways ),
            'person3_name'     => isset( $input['person3_name'] ) ? sanitize_text_field( trim( $input['person3_name'] ) ) : '',
            'person3_number'   => isset( $input['person3_number'] ) ? sanitize_text_field( trim( $input['person3_number'] ) ) : '',
            'person3_gateways' => self::sanitize_gateways( $input, 'person3_gateways', $allowed_gateways ),
            'bank_details'     => isset( $input['bank_details'] ) ? sanitize_textarea_field( $input['bank_details'] ) : '',
            'reg_end_datetime' => ( isset( $input['reg_end_datetime'] ) && ! empty( $input['reg_end_datetime'] ) ) ? date( 'Y-m-d H:i:s', strtotime( $input['reg_end_datetime'] ) ) : '',
            'terms_url'        => isset( $input['terms_url'] ) ? esc_url_raw( trim( $input['terms_url'] ) ) : '',
        );
    }

    /**
     * Keeps only the gateway slugs the plugin actually supports, so a tampered
     * form can't inject arbitrary values into the stored option.
     */
    private static function sanitize_gateways( $input, $key, $allowed ) {
        if ( empty( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
            return array();
        }

        $checked = array_map( 'sanitize_key', wp_unslash( $input[ $key ] ) );

        return array_values( array_intersect( $allowed, $checked ) );
    }

    public function render_payment_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }
        $settings = Reunion_Reg_Fields_Schema::get_payment_settings();
        $gateways = Reunion_Reg_Fields_Schema::get_gateways();
        $option   = REUNION_REG_PAYMENT_SETTINGS_OPTION;
        ?>
        <div class="wrap">
            <h1>Payment & Fee Settings</h1>
            <p>These values control the payment instructions and the auto-calculated "সর্বমোট প্রদেয় টাকা" total shown on the public registration form.</p>

            <form method="POST" action="options.php">
                <?php settings_fields( 'reunion_reg_payment_settings_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="registration_fee">Registration Fee (৳ per member)</label></th>
                        <td><input type="number" step="1" min="0" id="registration_fee" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[registration_fee]" value="<?php echo esc_attr( $settings['registration_fee'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="guest_fee">Guest Fee (৳ per additional guest)</label></th>
                        <td><input type="number" step="1" min="0" id="guest_fee" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[guest_fee]" value="<?php echo esc_attr( $settings['guest_fee'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th colspan="2"><h3 style="margin: 1.2em 0 0.2em;">Mobile Banking — 3 Persons</h3><p class="description" style="margin:0;">Tick only the gateways each number actually supports. The public form shows badges and dropdown options strictly from these selections, and any person with an empty mobile number is hidden entirely.</p></th>
                    </tr>
                    <?php for ( $i = 1; $i <= 3; $i++ ) :
                        $name_key     = "person{$i}_name";
                        $number_key   = "person{$i}_number";
                        $gateways_key = "person{$i}_gateways";
                        $active       = isset( $settings[ $gateways_key ] ) ? (array) $settings[ $gateways_key ] : array();
                        ?>
                        <tr>
                            <th><label for="<?php echo esc_attr( $name_key ); ?>">Person <?php echo esc_html( $i ); ?> — Name / Label</label></th>
                            <td><input type="text" id="<?php echo esc_attr( $name_key ); ?>" name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $name_key ); ?>]" value="<?php echo esc_attr( $settings[ $name_key ] ); ?>" class="regular-text" placeholder="Person <?php echo esc_attr( $i ); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="<?php echo esc_attr( $number_key ); ?>">Person <?php echo esc_html( $i ); ?> — Mobile Number</label></th>
                            <td><input type="text" id="<?php echo esc_attr( $number_key ); ?>" name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $number_key ); ?>]" value="<?php echo esc_attr( $settings[ $number_key ] ); ?>" class="regular-text" placeholder="01XXXXXXXXX"></td>
                        </tr>
                        <tr>
                            <th>Person <?php echo esc_html( $i ); ?> — Active Gateways</th>
                            <td>
                                <?php foreach ( $gateways as $gw_key => $gw_label ) : ?>
                                    <label style="display:inline-flex;align-items:center;gap:4px;margin-right:14px;">
                                        <input type="checkbox" name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $gateways_key ); ?>][]" value="<?php echo esc_attr( $gw_key ); ?>" <?php checked( in_array( $gw_key, $active, true ) ); ?>>
                                        <?php echo esc_html( $gw_label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endfor; ?>
                    <tr>
                        <th><label for="bank_details">Bank Transfer Details</label></th>
                        <td>
                            <textarea id="bank_details" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[bank_details]" rows="4" class="large-text" placeholder="Bank name, account name, account number, branch..."><?php echo esc_textarea( $settings['bank_details'] ); ?></textarea>
                            <p class="description">Shown to visitors who choose "ব্যাংক একাউন্ট" as their payment method. Leave blank to show a "coming soon" message instead.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="reg_end_datetime">Registration Deadline</label></th>
                        <td>
                            <input type="datetime-local" id="reg_end_datetime" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[reg_end_datetime]" value="<?php echo esc_attr( ! empty( $settings['reg_end_datetime'] ) ? str_replace( ' ', 'T', $settings['reg_end_datetime'] ) : '' ); ?>">
                            <p class="description">After this date/time the public registration form is hidden and submissions are blocked. Leave blank for no deadline (form stays open).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="terms_url">Terms & Conditions Page URL</label></th>
                        <td>
                            <input type="url" id="terms_url" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[terms_url]" value="<?php echo esc_attr( $settings['terms_url'] ); ?>" class="regular-text" placeholder="https://yoursite.com/terms">
                            <p class="description">If set, "ইভেন্টের শর্তাবলী" under the Submit button on the public form becomes a clickable link to this page. Leave blank to show it as plain text.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Payment & Fee Settings' ); ?>
            </form>
        </div>
        <?php
    }
}
