<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin meta boxes for a single registration entry: the manual/offline
 * entry form shown on "Add New", and the editable field view shown on
 * every existing entry (with Approve/Reject links).
 */
class Reunion_Reg_Admin_Entry_Metabox {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_entry_meta_box' ) );

        // Handle manual/offline entries created via the native "Add Post" screen
        add_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10, 3 );

        add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    /**
     * Registers (but doesn't force-load) the shared total-calculator script —
     * it's actually enqueued only when render_admin_total_calc_script() runs,
     * i.e. only on screens where one of our meta boxes is actually shown.
     */
    public function register_assets() {
        wp_register_script(
            'reunion-reg-admin-total-calc',
            REUNION_REG_PLUGIN_URL . 'assets/js/admin-total-calc.js',
            array(),
            REUNION_REG_VERSION,
            true
        );
    }

    public function add_entry_meta_box() {
        global $post;

        $is_new = ! $post || $post->post_status === 'auto-draft' || empty( get_post_meta( $post->ID, '_reunion_submitted_at', true ) );

        if ( $is_new ) {
            add_meta_box(
                'reunion_reg_manual_entry',
                'Manual / Offline Registration Entry',
                array( $this, 'render_manual_entry_meta_box' ),
                REUNION_REG_CPT_SLUG,
                'normal',
                'high'
            );
        } else {
            add_meta_box(
                'reunion_reg_details',
                'Registration Details',
                array( $this, 'render_entry_meta_box' ),
                REUNION_REG_CPT_SLUG,
                'normal',
                'high'
            );
        }
    }

    /**
     * Small shared marker that wires up assets/js/admin-total-calc.js for a
     * given set of field IDs — used on both admin entry screens (manual add
     * + edit). Admin can still type over the calculated result afterward,
     * this only saves them doing the arithmetic by hand.
     */
    private function render_admin_total_calc_script( $guest_field_id, $donation_field_id, $total_field_id ) {
        $settings = Reunion_Reg_Fields_Schema::get_payment_settings();

        wp_enqueue_script( 'reunion-reg-admin-total-calc' );
        ?>
        <div
            class="reunion-admin-total-calc"
            data-guest-field="<?php echo esc_attr( $guest_field_id ); ?>"
            data-donation-field="<?php echo esc_attr( $donation_field_id ); ?>"
            data-total-field="<?php echo esc_attr( $total_field_id ); ?>"
            data-fee="<?php echo esc_attr( (float) $settings['registration_fee'] ); ?>"
            data-guest-fee="<?php echo esc_attr( (float) $settings['guest_fee'] ); ?>"
        ></div>
        <?php
    }

    /**
     * Meta box shown only on the "Add New" screen — lets admin manually enter
     * an offline/hand-payment registration. Saves via the native Publish button.
     * New entries created this way default to "Approved" status.
     */
    public function render_manual_entry_meta_box( $post ) {
        $fields = Reunion_Reg_Fields_Schema::get_fields();

        wp_nonce_field( 'reunion_reg_manual_entry_save', 'reunion_reg_manual_entry_nonce' );

        echo '<p style="color:#666;">Use this form to record a registration collected offline (cash, hand-to-hand bKash/Nagad, etc). It will be marked <strong>Approved</strong> automatically when published, a Registration ID will be generated, and a confirmation email will be sent.</p>';

        echo '<table class="form-table"><tbody>';
        foreach ( $fields as $key => $field ) {
            echo '<tr>';
            echo '<th style="width:180px;text-align:left;">' . esc_html( $field['label'] );
            if ( $field['required'] ) {
                echo ' <span style="color:#d63638;">*</span>';
            }
            echo '</th>';
            echo '<td>';

            if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
                echo '<select name="reunion_manual_' . esc_attr( $key ) . '" id="reunion_manual_' . esc_attr( $key ) . '" style="min-width:220px;">';
                echo '<option value="">-- Select --</option>';
                foreach ( $field['options'] as $option ) {
                    $selected_attr = isset( $field['default'] ) ? selected( $field['default'], $option, false ) : '';
                    echo '<option value="' . esc_attr( $option ) . '" ' . $selected_attr . '>' . esc_html( $option ) . '</option>';
                }
                echo '</select>';
            } elseif ( in_array( $field['type'], array( 'number', 'computed' ), true ) ) {
                $default_val = isset( $field['default'] ) ? $field['default'] : 0;
                echo '<input type="number" name="reunion_manual_' . esc_attr( $key ) . '" id="reunion_manual_' . esc_attr( $key ) . '" value="' . esc_attr( $default_val ) . '" style="min-width:140px;">';
            } else {
                echo '<input type="text" name="reunion_manual_' . esc_attr( $key ) . '" id="reunion_manual_' . esc_attr( $key ) . '" style="min-width:280px;">';
            }

            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p style="color:#666;"><em>Tip: For Transaction ID, you can enter something like "CASH-' . esc_html( gmdate( 'Ymd' ) ) . '-01" if payment was taken in cash without a digital transaction.</em></p>';

        $this->render_admin_total_calc_script( 'reunion_manual_guest_count', 'reunion_manual_donation', 'reunion_manual_total_amount' );
    }

    /**
     * Save handler for the native "Add Post" / "Publish" flow — used only for
     * manually-entered offline registrations. Auto-approves on save.
     */
    public function handle_manual_add_post_save( $post_id, $post, $update ) {
        // Skip autosaves, revisions, and anything not triggered by our manual entry form
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['reunion_reg_manual_entry_nonce'] ) ||
             ! wp_verify_nonce( $_POST['reunion_reg_manual_entry_nonce'], 'reunion_reg_manual_entry_save' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Only run this on the very first save (when there's no submitted_at yet) —
        // afterwards, editing happens through the regular editable meta box/save_edit flow.
        if ( ! empty( get_post_meta( $post_id, '_reunion_submitted_at', true ) ) ) {
            return;
        }

        $fields = Reunion_Reg_Fields_Schema::get_fields();
        $clean  = array();

        foreach ( $fields as $key => $field ) {
            $raw_value = isset( $_POST[ 'reunion_manual_' . $key ] ) ? wp_unslash( $_POST[ 'reunion_manual_' . $key ] ) : '';

            switch ( $field['type'] ) {
                case 'email':
                    $value = sanitize_email( $raw_value );
                    break;
                case 'tel':
                    $value = sanitize_text_field( preg_replace( '/[^0-9+\-\s()]/', '', $raw_value ) );
                    break;
                case 'number':
                case 'computed':
                    $value = is_numeric( $raw_value ) ? (float) $raw_value : 0;
                    break;
                case 'select':
                case 'radio':
                    $value = sanitize_text_field( $raw_value );
                    if ( '' !== $value && ! in_array( $value, array_map( 'strval', $field['options'] ), true ) ) {
                        $value = '';
                    }
                    break;
                default:
                    $value = sanitize_text_field( $raw_value );
                    break;
            }

            $clean[ $key ] = $value;
            update_post_meta( $post_id, '_reunion_' . $key, $value );
        }

        update_post_meta( $post_id, '_reunion_submitted_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_reunion_submitted_ip', 'manual-admin-entry' );

        // Update title for easier searching
        remove_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10 );
        wp_update_post( array(
            'ID'         => $post_id,
            'post_title' => trim( $clean['full_name'] . ' — ' . $clean['tnx_id'], ' —' ),
        ) );
        add_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10, 3 );

        // Auto-approve manual entries since admin is the one verifying offline payment.
        // Email/SMS are never called from here directly — the after_approve hook
        // below is all that's needed; class-email-notifications.php and
        // class-sms-gateway.php each listen for it independently.
        if ( ! empty( $clean['full_name'] ) ) {
            $reg_id = Reunion_Reg_ID_Generator::generate_registration_id( $clean['batch'] );
            update_post_meta( $post_id, '_reunion_status', 'approved' );
            update_post_meta( $post_id, '_reunion_reg_id', $reg_id );
            update_post_meta( $post_id, '_reunion_approved_at', current_time( 'mysql' ) );

            do_action( 'reunion_reg_after_approve', $post_id, $reg_id );
        } else {
            update_post_meta( $post_id, '_reunion_status', 'pending' );
        }
    }

    public function render_entry_meta_box( $post ) {
        $fields = Reunion_Reg_Fields_Schema::get_fields();
        $status = Reunion_Reg_CPT::get_status( $post->ID );
        $reg_id = get_post_meta( $post->ID, '_reunion_reg_id', true );

        $save_url = admin_url( 'admin-post.php' );

        echo '<form method="POST" action="' . esc_url( $save_url ) . '">';
        echo '<input type="hidden" name="action" value="reunion_reg_save_edit">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr( $post->ID ) . '">';
        wp_nonce_field( REUNION_REG_ADMIN_ACTION_NONCE . '_edit_' . $post->ID, 'reunion_reg_edit_nonce' );

        echo '<table class="form-table"><tbody>';

        echo '<tr><th style="width:180px;text-align:left;">Status</th><td>' . Reunion_Reg_CPT::status_badge_html( $status ) . '</td></tr>';
        echo '<tr><th style="text-align:left;">Registration ID</th><td>' . ( $reg_id ? '<strong>' . esc_html( $reg_id ) . '</strong>' : '<em>Not assigned yet (assigned on approval)</em>' ) . '</td></tr>';

        foreach ( $fields as $key => $field ) {
            if ( 'file' === $field['type'] ) { continue; }
            $value = get_post_meta( $post->ID, '_reunion_' . $key, true );
            echo '<tr>';
            echo '<th style="width:180px;text-align:left;">' . esc_html( $field['label'] ) . '</th>';
            echo '<td>';

            if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
                echo '<select name="reunion_edit_' . esc_attr( $key ) . '" id="reunion_edit_' . esc_attr( $key ) . '" style="min-width:220px;">';
                echo '<option value="">-- Select --</option>';
                foreach ( $field['options'] as $option ) {
                    echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
                }
                echo '</select>';
            } elseif ( in_array( $field['type'], array( 'number', 'computed' ), true ) ) {
                echo '<input type="number" name="reunion_edit_' . esc_attr( $key ) . '" id="reunion_edit_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="min-width:140px;">';
            } else {
                echo '<input type="text" name="reunion_edit_' . esc_attr( $key ) . '" id="reunion_edit_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="min-width:280px;">';
            }

            echo '</td>';
            echo '</tr>';
        }
        $receipt_url = get_post_meta( $post->ID, '_reunion_payment_receipt_url', true );
        if ( $receipt_url ) {
            echo '<tr><th style="text-align:left;">পেমেন্ট রসিদ</th><td><a href="' . esc_url( $receipt_url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $receipt_url ) . '" alt="Payment receipt" style="max-width:220px;height:auto;border:1px solid #e5e7eb;border-radius:8px;"></a><br><a href="' . esc_url( $receipt_url ) . '" target="_blank" rel="noopener">Open full size</a></td></tr>';
        }
        $photo_url = get_post_meta( $post->ID, '_reunion_applicant_photo_url', true );
        if ( $photo_url ) {
            echo '<tr><th style="text-align:left;">আবেদনকারীর ছবি</th><td><a href="' . esc_url( $photo_url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $photo_url ) . '" alt="Applicant photo" style="max-width:220px;height:auto;border:1px solid #e5e7eb;border-radius:8px;"></a><br><a href="' . esc_url( $photo_url ) . '" target="_blank" rel="noopener">Open full size</a></td></tr>';
        }
        $submitted_at = get_post_meta( $post->ID, '_reunion_submitted_at', true );
        echo '<tr><th style="text-align:left;">Submitted At</th><td>' . esc_html( $submitted_at ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<p style="margin-top:12px;"><button type="submit" class="button">Save Changes</button></p>';
        echo '</form>';

        $this->render_admin_total_calc_script( 'reunion_edit_guest_count', 'reunion_edit_donation', 'reunion_edit_total_amount' );

        echo '<hr style="margin:16px 0;">';

        if ( $status === 'pending' ) {
            $approve_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=reunion_reg_approve&post_id=' . $post->ID ),
                REUNION_REG_ADMIN_ACTION_NONCE . '_' . $post->ID
            );
            $reject_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=reunion_reg_reject&post_id=' . $post->ID ),
                REUNION_REG_ADMIN_ACTION_NONCE . '_' . $post->ID
            );

            echo '<p>';
            echo '<a href="' . esc_url( $approve_url ) . '" class="button button-primary" style="margin-right:8px;" onclick="return confirm(\'Approve this registration? An email will be sent to the applicant.\');">Approve</a>';
            echo '<a href="' . esc_url( $reject_url ) . '" class="button" onclick="return confirm(\'Reject this registration? An email will be sent to the applicant.\');">Reject</a>';
            echo '</p>';
        } else {
            $other_status = $status === 'approved' ? 'reject' : 'approve';
            $other_label  = $status === 'approved' ? 'Reject Instead' : 'Approve Instead';
            $other_url    = wp_nonce_url(
                admin_url( 'admin-post.php?action=reunion_reg_' . $other_status . '&post_id=' . $post->ID . '&override=1' ),
                REUNION_REG_ADMIN_ACTION_NONCE . '_' . $post->ID
            );

            echo '<p style="color:#666;"><em>This entry has been ' . esc_html( strtolower( Reunion_Reg_Fields_Schema::get_statuses()[ $status ] ) ) . '.</em></p>';
            echo '<p><a href="' . esc_url( $other_url ) . '" class="button" onclick="return confirm(\'Change status to ' . esc_js( $other_status ) . 'd? This will send a notification email to the applicant.\');">' . esc_html( $other_label ) . ' (Override)</a></p>';
        }
    }
}
