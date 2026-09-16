<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the public registration form via the [reunion_registration_form]
 * shortcode. Pure presentation — validation and saving happen in
 * class-form-submission.php, which this class never calls directly (the
 * form simply POSTs to admin-post.php?action=reunion_reg_submit).
 */
class Reunion_Reg_Frontend_Form {

    public function __construct() {
        add_shortcode( 'reunion_registration_form', array( $this, 'render_form' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    /**
     * Registers (but does not force-enqueue) the form's CSS/JS so they're
     * only actually loaded on pages that use the shortcode — enqueued from
     * within render_form() itself, right when the shortcode runs.
     */
    public function register_assets() {
        wp_register_style(
            'reunion-reg-frontend-form',
            REUNION_REG_PLUGIN_URL . 'assets/css/frontend-form.css',
            array(),
            REUNION_REG_VERSION
        );

        wp_register_script(
            'reunion-reg-frontend-form',
            REUNION_REG_PLUGIN_URL . 'assets/js/frontend-form.js',
            array(),
            REUNION_REG_VERSION,
            true
        );
    }

    /**
     * Render the frontend form via shortcode.
     */
    public function render_form() {
        $fields           = Reunion_Reg_Fields_Schema::get_fields();
        $sticky           = $this->get_sticky_values();
        $values           = $this->get_current_form_values( $fields, $sticky );
        $payment_settings = Reunion_Reg_Fields_Schema::get_payment_settings();

        wp_enqueue_style( 'reunion-reg-frontend-form' );
        wp_enqueue_script( 'reunion-reg-frontend-form' );
        wp_localize_script( 'reunion-reg-frontend-form', 'ReunionRegConfig', array(
            'registrationFee' => (float) $payment_settings['registration_fee'],
            'guestFee'        => (float) $payment_settings['guest_fee'],
        ) );

        $status_map = array(
            'success'         => array( 'success', 'ধন্যবাদ! আপনার রেজিস্ট্রেশন সফলভাবে জমা হয়েছে। আমরা আপনার পেমেন্ট যাচাই করে শীঘ্রই যোগাযোগ করব।' ),
            'duplicate_phone' => array( 'error', 'এই মোবাইল নম্বর দিয়ে ইতিমধ্যে একটি রেজিস্ট্রেশন করা হয়েছে। ভুল মনে হলে সরাসরি আমাদের সাথে যোগাযোগ করুন।' ),
            'duplicate_email' => array( 'error', 'এই ইমেইল ঠিকানা দিয়ে ইতিমধ্যে একটি রেজিস্ট্রেশন করা হয়েছে। ভুল মনে হলে সরাসরি আমাদের সাথে যোগাযোগ করুন।' ),
            'duplicate_tnx'   => array( 'error', 'এই ট্রানজেকশন আইডি ইতিমধ্যে জমা দেওয়া হয়েছে। অনুগ্রহ করে আবার যাচাই করুন, অথবা ভুল মনে হলে আমাদের সাথে যোগাযোগ করুন।' ),
            'rate_limited'    => array( 'error', 'অল্প সময়ের মধ্যে অনেকবার চেষ্টা করা হয়েছে। কিছুক্ষণ অপেক্ষা করে আবার চেষ্টা করুন।' ),
            'missing_fields'  => array( 'error', 'অনুগ্রহ করে সব আবশ্যক (*) ফিল্ড সঠিকভাবে পূরণ করে আবার চেষ্টা করুন।' ),
            'error'           => array( 'error', 'দুঃখিত, কিছু একটা সমস্যা হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।' ),
        );

        $status  = isset( $_GET['reunion_reg_status'] ) ? sanitize_text_field( wp_unslash( $_GET['reunion_reg_status'] ) ) : '';
        $notice  = isset( $status_map[ $status ] ) ? $status_map[ $status ] : null;
        $success = ( 'success' === $status );

        $section_titles = array(
            1 => '১. ব্যক্তিগত বিবরণ',
            2 => '২. পেশাগত বিবরণ',
            3 => '৩. উৎসবের তথ্য ও ফি',
            4 => '৪. পেমেন্ট পদ্ধতি',
        );

        // Fields that should span the full width of the responsive grid
        // instead of sharing a row with a neighbouring field.
        $full_width_fields = array( 'permanent_address', 'present_address', 'profession', 'gift_dress', 'total_amount', 'payment_channel' );

        ob_start();
        ?>

        <div class="reunion-reg-wrapper">

            <?php if ( $success ) : ?>
                <div class="reunion-reg-thankyou">
                    <div class="reunion-reg-thankyou-icon">✓</div>
                    <h2>ধন্যবাদ!</h2>
                    <p><?php echo esc_html( $notice[1] ); ?></p>
                </div>
            <?php elseif ( $notice ) : ?>
                <div class="reunion-reg-notice reunion-reg-<?php echo esc_attr( $notice[0] ); ?>">
                    <?php echo esc_html( $notice[1] ); ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $success ) : ?>
            <div class="reunion-reg-card">
                <div class="reunion-reg-heading">
                    <h2>রেজিস্ট্রেশন ফরম</h2>
                    <p>নিচের তথ্যগুলো সঠিকভাবে পূরণ করে আপনার আসনটি নিশ্চিত করুন।</p>
                </div>

                <form class="reunion-reg-form" method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="reunion_reg_submit">
                    <?php wp_nonce_field( REUNION_REG_NONCE_ACTION, REUNION_REG_NONCE_FIELD ); ?>

                    <!-- Honeypot field: hidden from real users via CSS, bots tend to fill every field -->
                    <div class="reunion-reg-hp-field" aria-hidden="true">
                        <label for="reunion_website">Website</label>
                        <input type="text" name="reunion_website" id="reunion_website" tabindex="-1" autocomplete="off">
                    </div>
                    <input type="hidden" name="reunion_form_loaded_at" value="<?php echo esc_attr( time() ); ?>">

                    <?php
                    $current_section = null;

                    foreach ( $fields as $key => $field ) :

                        if ( $field['section'] !== $current_section ) {
                            if ( null !== $current_section ) {
                                echo '</div>'; // close previous .reunion-section-grid
                            }
                            $current_section = $field['section'];
                            echo '<h3 class="reunion-section-title">' . esc_html( $section_titles[ $current_section ] ?? '' ) . '</h3>';
                            echo '<div class="reunion-section-grid">';
                        }

                        $is_applicable = Reunion_Reg_Fields_Schema::field_is_applicable( $field, $values );
                        $extra_class   = in_array( $key, $full_width_fields, true ) ? ' reunion-field-full' : '';

                        echo $this->render_field_markup( $key, $field, $values[ $key ], $is_applicable, $extra_class ); // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped inside render_field_markup()

                        if ( 'payment_channel' === $key ) {
                            echo $this->render_payment_instructions( $payment_settings, $values['payment_channel'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped inside render_payment_instructions()
                        }

                    endforeach;
                    echo '</div>'; // close last .reunion-section-grid
                    ?>

                    <button type="submit" class="reunion-reg-submit-btn">নিবন্ধন সম্পন্ন করুন</button>
                    <p class="reunion-reg-terms">
                        <?php if ( ! empty( $payment_settings['terms_url'] ) ) : ?>
                            "নিবন্ধন সম্পন্ন করুন" বাটনে ক্লিক করার মাধ্যমে আপনি আমাদের <a href="<?php echo esc_url( $payment_settings['terms_url'] ); ?>" target="_blank" rel="noopener noreferrer">ইভেন্টের শর্তাবলী</a> মেনে নিচ্ছেন।
                        <?php else : ?>
                            "নিবন্ধন সম্পন্ন করুন" বাটনে ক্লিক করার মাধ্যমে আপনি আমাদের ইভেন্টের শর্তাবলী মেনে নিচ্ছেন।
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }

    /**
     * Reads (and consumes) sticky form values saved by the submission handler
     * when a submission fails validation, so the visitor doesn't have to
     * retype everything. One-time use: the transient is deleted immediately
     * after being read.
     */
    private function get_sticky_values() {
        if ( empty( $_GET['reunion_reg_retry'] ) ) {
            return array();
        }

        $token         = sanitize_text_field( wp_unslash( $_GET['reunion_reg_retry'] ) );
        $transient_key = 'reunion_reg_retry_' . $token;
        $data          = get_transient( $transient_key );

        if ( ! is_array( $data ) ) {
            return array();
        }

        delete_transient( $transient_key );

        return $data;
    }

    /**
     * Resolves the value each field should currently display: sticky (re-populated
     * after a failed submission) takes priority, then the field's own 'default'.
     */
    private function get_current_form_values( $fields, $sticky ) {
        $values = array();

        foreach ( $fields as $key => $field ) {
            if ( isset( $sticky[ $key ] ) && '' !== $sticky[ $key ] ) {
                $values[ $key ] = $sticky[ $key ];
            } elseif ( isset( $field['default'] ) ) {
                $values[ $key ] = $field['default'];
            } else {
                $values[ $key ] = '';
            }
        }

        return $values;
    }

    /**
     * Renders the markup for a single field on the public form: label, the
     * appropriate input control for its type, and an optional helper note.
     */
    private function render_field_markup( $key, $field, $current_value, $is_applicable, $extra_class = '' ) {
        $wrapper_classes = array( 'reunion-field', 'reunion-field-' . $key );
        if ( $extra_class ) {
            $wrapper_classes[] = trim( $extra_class );
        }

        $wrapper_attrs = '';
        if ( ! empty( $field['depends_on'] ) ) {
            $wrapper_classes[] = 'reunion-conditional';
            $wrapper_attrs    .= ' data-depends-field="' . esc_attr( $field['depends_on']['field'] ) . '"';
            $wrapper_attrs    .= ' data-depends-value="' . esc_attr( $field['depends_on']['value'] ) . '"';
            if ( ! $is_applicable ) {
                $wrapper_attrs .= ' style="display:none;"';
            }
        }

        // A field that depends on another field's value must NEVER carry a static
        // 'required' attribute while it's hidden — a required-but-not-focusable
        // input blocks native HTML5 validation entirely (silently, with no visible
        // error to the visitor). For conditional fields we only set 'required' when
        // they currently apply, and hand the rest to JS (data-conditional-required)
        // so it can flip the attribute live as the visitor changes their choice.
        $is_conditional  = ! empty( $field['depends_on'] );
        $wants_required  = ! empty( $field['required'] );
        $render_required = $is_conditional ? ( $wants_required && $is_applicable ) : $wants_required;
        $required_attr   = $render_required ? 'required' : '';
        $conditional_required_attr = ( $is_conditional && $wants_required ) ? ' data-conditional-required="1"' : '';

        ob_start();
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>"<?php echo $wrapper_attrs; /* phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() pieces above */ ?>>

            <?php if ( 'computed' !== $field['type'] ) : ?>
                <label for="reunion_<?php echo esc_attr( $key ); ?>">
                    <?php echo esc_html( $field['label'] ); ?>
                    <?php if ( $wants_required ) : ?><span class="required">*</span><?php endif; ?>
                </label>
            <?php endif; ?>

            <?php if ( 'select' === $field['type'] ) : ?>
                <select name="<?php echo esc_attr( $key ); ?>" id="reunion_<?php echo esc_attr( $key ); ?>" <?php echo $required_attr; ?><?php echo $conditional_required_attr; /* phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() pieces above */ ?>>
                    <option value="" disabled <?php selected( empty( $current_value ) ); ?>><?php echo esc_html( $field['placeholder'] ?? 'সিলেক্ট করুন' ); ?></option>
                    <?php foreach ( $field['options'] as $option ) : ?>
                        <option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) $current_value, (string) $option ); ?>><?php echo esc_html( $option ); ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ( 'radio' === $field['type'] ) : ?>
                <div class="reunion-radio-group">
                    <?php foreach ( $field['options'] as $option ) : ?>
                        <label class="reunion-radio-pill">
                            <input
                                type="radio"
                                name="<?php echo esc_attr( $key ); ?>"
                                value="<?php echo esc_attr( $option ); ?>"
                                <?php checked( (string) $current_value, (string) $option ); ?>
                                <?php echo $required_attr; ?>
                            >
                            <span><?php echo esc_html( $option ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

            <?php elseif ( 'computed' === $field['type'] ) : ?>
                <div class="reunion-total-box">
                    <span class="reunion-total-label"><?php echo esc_html( $field['label'] ); ?>:</span>
                    <strong class="reunion-total-value">৳<span id="reunion_total_number">0</span></strong>
                </div>

            <?php else : ?>
                <input
                    type="<?php echo esc_attr( 'number' === $field['type'] ? 'number' : $field['type'] ); ?>"
                    name="<?php echo esc_attr( $key ); ?>"
                    id="reunion_<?php echo esc_attr( $key ); ?>"
                    value="<?php echo esc_attr( $current_value ); ?>"
                    placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                    <?php if ( isset( $field['min'] ) ) : ?>min="<?php echo esc_attr( $field['min'] ); ?>"<?php endif; ?>
                    <?php if ( isset( $field['max'] ) ) : ?>max="<?php echo esc_attr( $field['max'] ); ?>"<?php endif; ?>
                    <?php echo $required_attr; ?><?php echo $conditional_required_attr; /* phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() pieces above */ ?>
                >
            <?php endif; ?>

            <?php if ( ! empty( $field['note'] ) ) : ?>
                <p class="reunion-field-note"><?php echo esc_html( $field['note'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the two toggleable payment-instruction boxes (mobile banking / bank
     * account), shown just after the "পেমেন্ট মাধ্যম" choice. Numbers/details come
     * from Registrations → Payment & Fee Settings so the client can update them
     * without touching code.
     */
    private function render_payment_instructions( $settings, $current_channel ) {
        $mobile_visible = ( 'মোবাইল ব্যাংকিং' === $current_channel || empty( $current_channel ) );
        $bank_visible   = ( 'ব্যাংক একাউন্ট' === $current_channel );

        ob_start();
        ?>
        <div class="reunion-field-full reunion-conditional reunion-pay-instructions" data-depends-field="payment_channel" data-depends-value="মোবাইল ব্যাংকিং" <?php echo $mobile_visible ? '' : 'style="display:none;"'; ?>>
            <div class="reunion-pay-box">
                <p class="reunion-pay-box-title">📱 মোবাইল ব্যাংকিং নিয়মাবলী:</p>
                <p>১. আপনার হিসাবকৃত সর্বমোট টাকা নিচের যেকোনো একটি নম্বরে <strong>Send Money</strong> করুন:</p>
                <div class="reunion-pay-chips">
                    <span class="reunion-chip reunion-chip-bkash">বিকাশ (Personal)<br><?php echo esc_html( $settings['bkash_number'] ? $settings['bkash_number'] : 'নম্বর শীঘ্রই যোগ হবে' ); ?></span>
                    <span class="reunion-chip reunion-chip-nagad">নগদ (Personal)<br><?php echo esc_html( $settings['nagad_number'] ? $settings['nagad_number'] : 'নম্বর শীঘ্রই যোগ হবে' ); ?></span>
                    <span class="reunion-chip reunion-chip-rocket">রকেট (Personal)<br><?php echo esc_html( $settings['rocket_number'] ? $settings['rocket_number'] : 'নম্বর শীঘ্রই যোগ হবে' ); ?></span>
                </div>
                <p>২. সফলভাবে টাকা পাঠানোর পর ট্রানজেকশন আইডি (TxID) সংগ্রহ করুন এবং নিচের ফিল্ডগুলো পূরণ করুন।</p>
            </div>
        </div>
        <div class="reunion-field-full reunion-conditional reunion-pay-instructions" data-depends-field="payment_channel" data-depends-value="ব্যাংক একাউন্ট" <?php echo $bank_visible ? '' : 'style="display:none;"'; ?>>
            <div class="reunion-pay-box reunion-pay-box-bank">
                <p class="reunion-pay-box-title">🏦 ব্যাংক ট্রান্সফার নিয়মাবলী:</p>
                <?php if ( ! empty( $settings['bank_details'] ) ) : ?>
                    <p><?php echo nl2br( esc_html( $settings['bank_details'] ) ); ?></p>
                <?php else : ?>
                    <p>ব্যাংক একাউন্টের বিস্তারিত তথ্য শীঘ্রই যোগ করা হবে। এখন অনুগ্রহ করে "মোবাইল ব্যাংকিং" ব্যবহার করুন অথবা সরাসরি যোগাযোগ করুন।</p>
                <?php endif; ?>
                <p>টাকা পাঠানোর পর ব্যাংকের নাম ও ট্রানজেকশন/রেফারেন্স নম্বর নিচে দিন।</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
