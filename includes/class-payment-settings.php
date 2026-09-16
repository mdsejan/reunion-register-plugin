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
        return array(
            'registration_fee' => isset( $input['registration_fee'] ) ? max( 0, (float) $input['registration_fee'] ) : 0,
            'guest_fee'        => isset( $input['guest_fee'] ) ? max( 0, (float) $input['guest_fee'] ) : 0,
            'bkash_number'     => isset( $input['bkash_number'] ) ? sanitize_text_field( trim( $input['bkash_number'] ) ) : '',
            'nagad_number'     => isset( $input['nagad_number'] ) ? sanitize_text_field( trim( $input['nagad_number'] ) ) : '',
            'rocket_number'    => isset( $input['rocket_number'] ) ? sanitize_text_field( trim( $input['rocket_number'] ) ) : '',
            'bank_details'     => isset( $input['bank_details'] ) ? sanitize_textarea_field( $input['bank_details'] ) : '',
            'terms_url'        => isset( $input['terms_url'] ) ? esc_url_raw( trim( $input['terms_url'] ) ) : '',
        );
    }

    public function render_payment_settings_page() {
        $settings = Reunion_Reg_Fields_Schema::get_payment_settings();
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
                        <th><label for="bkash_number">bKash Personal Number</label></th>
                        <td><input type="text" id="bkash_number" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[bkash_number]" value="<?php echo esc_attr( $settings['bkash_number'] ); ?>" class="regular-text" placeholder="01XXXXXXXXX"></td>
                    </tr>
                    <tr>
                        <th><label for="nagad_number">Nagad Personal Number</label></th>
                        <td><input type="text" id="nagad_number" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[nagad_number]" value="<?php echo esc_attr( $settings['nagad_number'] ); ?>" class="regular-text" placeholder="01XXXXXXXXX"></td>
                    </tr>
                    <tr>
                        <th><label for="rocket_number">Rocket Personal Number</label></th>
                        <td><input type="text" id="rocket_number" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[rocket_number]" value="<?php echo esc_attr( $settings['rocket_number'] ); ?>" class="regular-text" placeholder="01XXXXXXXXX"></td>
                    </tr>
                    <tr>
                        <th><label for="bank_details">Bank Transfer Details</label></th>
                        <td>
                            <textarea id="bank_details" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[bank_details]" rows="4" class="large-text" placeholder="Bank name, account name, account number, branch..."><?php echo esc_textarea( $settings['bank_details'] ); ?></textarea>
                            <p class="description">Shown to visitors who choose "ব্যাংক একাউন্ট" as their payment method. Leave blank to show a "coming soon" message instead.</p>
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
