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
            'person1_name'     => isset( $input['person1_name'] ) ? sanitize_text_field( trim( $input['person1_name'] ) ) : '',
            'person1_number'   => isset( $input['person1_number'] ) ? sanitize_text_field( trim( $input['person1_number'] ) ) : '',
            'person2_name'     => isset( $input['person2_name'] ) ? sanitize_text_field( trim( $input['person2_name'] ) ) : '',
            'person2_number'   => isset( $input['person2_number'] ) ? sanitize_text_field( trim( $input['person2_number'] ) ) : '',
            'person3_name'     => isset( $input['person3_name'] ) ? sanitize_text_field( trim( $input['person3_name'] ) ) : '',
            'person3_number'   => isset( $input['person3_number'] ) ? sanitize_text_field( trim( $input['person3_number'] ) ) : '',
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
                        <th colspan="2"><h3 style="margin: 1.2em 0 0.2em;">Mobile Banking — 3 Persons</h3><p class="description" style="margin:0;">Each number supports bKash / Nagad / Rocket (Personal). Shown on the frontend as a single compact row with gateway badges and one copy button per person.</p></th>
                    </tr>
                    <tr>
                        <th><label for="person1_name">Person 1 — Name / Label</label></th>
                        <td><input type="text" id="person1_name" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[person1_name]" value="<?php echo esc_attr( $settings['person1_name'] ); ?>" class="regular-text" placeholder="Person 1"></td>
                    </tr>
                    <tr>
                        <th><label for="person1_number">Person 1 — Mobile Number</label></th>
                        <td><input type="text" id="person1_number" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[person1_number]" value="<?php echo esc_attr( $settings['person1_number'] ); ?>" class="regular-text" placeholder="01XXXXXXXXX"></td>
                    </tr>
                    <tr>
                        <th><label for="person2_name">Person 2 — Name / Label</label></th>
                        <td><input type="text" id="person2_name" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[person2_name]" value="<?php echo esc_attr( $settings['person2_name'] ); ?>" class="regular-text" placeholder="Person 2"></td>
                    </tr>
                    <tr>
                        <th><label for="person2_number">Person 2 — Mobile Number</label></th>
                        <td><input type="text" id="person2_number" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[person2_number]" value="<?php echo esc_attr( $settings['person2_number'] ); ?>" class="regular-text" placeholder="01XXXXXXXXX"></td>
                    </tr>
                    <tr>
                        <th><label for="person3_name">Person 3 — Name / Label</label></th>
                        <td><input type="text" id="person3_name" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[person3_name]" value="<?php echo esc_attr( $settings['person3_name'] ); ?>" class="regular-text" placeholder="Person 3"></td>
                    </tr>
                    <tr>
                        <th><label for="person3_number">Person 3 — Mobile Number</label></th>
                        <td><input type="text" id="person3_number" name="<?php echo esc_attr( REUNION_REG_PAYMENT_SETTINGS_OPTION ); ?>[person3_number]" value="<?php echo esc_attr( $settings['person3_number'] ); ?>" class="regular-text" placeholder="01XXXXXXXXX"></td>
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
