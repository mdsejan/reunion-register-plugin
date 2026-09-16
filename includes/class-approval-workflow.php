<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Approve/Reject workflow + the editable-entry save handler.
 *
 * This class has no idea email or SMS notifications exist. It only fires
 * 'reunion_reg_after_approve' and 'reunion_reg_after_reject' — whatever
 * listens for those (class-email-notifications.php, class-sms-gateway.php)
 * is entirely their own business.
 */
class Reunion_Reg_Approval_Workflow {

    public function __construct() {
        add_action( 'admin_post_reunion_reg_approve', array( $this, 'handle_approve' ) );
        add_action( 'admin_post_reunion_reg_reject', array( $this, 'handle_reject' ) );
        add_action( 'admin_post_reunion_reg_save_edit', array( $this, 'handle_save_edit' ) );
        add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
    }

    /**
     * Validate the post_id + nonce pair used by approve/reject links.
     * Returns the post_id on success, or wp_die()s on failure.
     */
    private function verify_admin_action_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

        if ( ! $post_id || get_post_type( $post_id ) !== REUNION_REG_CPT_SLUG ) {
            wp_die( 'Invalid registration entry.' );
        }

        $nonce_action = REUNION_REG_ADMIN_ACTION_NONCE . '_' . $post_id;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], $nonce_action ) ) {
            wp_die( 'Security check failed. Please go back and try again.' );
        }

        return $post_id;
    }

    /**
     * Handle Approve action: generate Reg ID, set status, fire the after_approve hook.
     */
    public function handle_approve() {
        $post_id  = $this->verify_admin_action_request();
        $override = isset( $_GET['override'] ) && $_GET['override'] === '1';

        // Avoid re-approving / double-assigning a Reg ID if already approved
        if ( Reunion_Reg_CPT::get_status( $post_id ) === 'approved' ) {
            wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'already_approved', wp_get_referer() ?: admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG ) ) );
            exit;
        }

        // Only allow override from 'rejected', or normal flow from 'pending'
        if ( ! $override && Reunion_Reg_CPT::get_status( $post_id ) !== 'pending' ) {
            wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'already_processed', wp_get_referer() ?: admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG ) ) );
            exit;
        }

        // Reuse existing Reg ID if this entry was previously approved and then rejected
        // (rare edge case); otherwise generate a fresh one.
        $existing_reg_id = get_post_meta( $post_id, '_reunion_reg_id', true );
        $batch  = get_post_meta( $post_id, '_reunion_batch', true );
        $reg_id = $existing_reg_id ? $existing_reg_id : Reunion_Reg_ID_Generator::generate_registration_id( $batch );

        update_post_meta( $post_id, '_reunion_status', 'approved' );
        update_post_meta( $post_id, '_reunion_reg_id', $reg_id );
        update_post_meta( $post_id, '_reunion_approved_at', current_time( 'mysql' ) );

        do_action( 'reunion_reg_after_approve', $post_id, $reg_id );

        wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'approved', wp_get_referer() ?: admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG ) ) );
        exit;
    }

    /**
     * Handle Reject action: set status, fire the after_reject hook.
     */
    public function handle_reject() {
        $post_id  = $this->verify_admin_action_request();
        $override = isset( $_GET['override'] ) && $_GET['override'] === '1';

        if ( Reunion_Reg_CPT::get_status( $post_id ) === 'rejected' ) {
            wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'already_processed', wp_get_referer() ?: admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG ) ) );
            exit;
        }

        if ( ! $override && Reunion_Reg_CPT::get_status( $post_id ) !== 'pending' ) {
            wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'already_processed', wp_get_referer() ?: admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG ) ) );
            exit;
        }

        update_post_meta( $post_id, '_reunion_status', 'rejected' );
        update_post_meta( $post_id, '_reunion_rejected_at', current_time( 'mysql' ) );

        do_action( 'reunion_reg_after_reject', $post_id );

        wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'rejected', wp_get_referer() ?: admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG ) ) );
        exit;
    }

    /**
     * Handle Save Changes from the editable meta box — lets admin correct typos
     * or fill in data for manually-added offline entries, at any status.
     */
    public function handle_save_edit() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : ( isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0 );

        if ( ! $post_id || get_post_type( $post_id ) !== REUNION_REG_CPT_SLUG ) {
            wp_die( 'Invalid registration entry.' );
        }

        $nonce = isset( $_POST['reunion_reg_edit_nonce'] ) ? $_POST['reunion_reg_edit_nonce'] : ( isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '' );
        if ( ! wp_verify_nonce( $nonce, REUNION_REG_ADMIN_ACTION_NONCE . '_edit_' . $post_id ) ) {
            wp_die( 'Security check failed. Please go back and try again.' );
        }

        $fields = Reunion_Reg_Fields_Schema::get_fields();

        foreach ( $fields as $key => $field ) {
            $raw_value = isset( $_POST[ 'reunion_edit_' . $key ] ) ? wp_unslash( $_POST[ 'reunion_edit_' . $key ] ) : '';

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

            update_post_meta( $post_id, '_reunion_' . $key, $value );
        }

        // Keep post title in sync with name/tnx_id for easier searching in admin
        $name   = get_post_meta( $post_id, '_reunion_full_name', true );
        $tnx_id = get_post_meta( $post_id, '_reunion_tnx_id', true );
        wp_update_post( array(
            'ID'         => $post_id,
            'post_title' => trim( $name . ' — ' . $tnx_id, ' —' ),
        ) );

        wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'saved', get_edit_post_link( $post_id, 'raw' ) ) );
        exit;
    }

    /**
     * Admin notices shown after approve/reject redirect.
     */
    public function show_admin_notices() {
        if ( empty( $_GET['reunion_admin_notice'] ) ) {
            return;
        }

        $notice = sanitize_text_field( $_GET['reunion_admin_notice'] );
        $map = array(
            'approved'          => array( 'success', 'Registration approved. Confirmation email sent.' ),
            'rejected'          => array( 'success', 'Registration rejected. Notification email sent.' ),
            'already_approved'  => array( 'warning', 'This entry was already approved.' ),
            'already_processed' => array( 'warning', 'This entry has already been processed.' ),
            'saved'             => array( 'success', 'Changes saved successfully.' ),
            'reset_done'        => array( 'success', 'All registrations deleted. Registration IDs will restart from the beginning.' ),
            'reset_not_confirmed' => array( 'warning', 'Reset cancelled: you must type RESET to confirm.' ),
        );

        if ( ! isset( $map[ $notice ] ) && 'reset_partial' !== $notice ) {
            return;
        }

        if ( 'reset_partial' === $notice ) {
            $left = isset( $_GET['left'] ) ? absint( $_GET['left'] ) : -1;
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( 'Reset incomplete: %d registration rows still remain in the database, and/or the counter row was not cleared. Check Trash and try again.', $left ) ) . '</p></div>';
            return;
        }

        list( $type, $text ) = $map[ $notice ];
        $css_class = $type === 'success' ? 'notice-success' : 'notice-warning';

        echo '<div class="notice ' . esc_attr( $css_class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
    }
}
