<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the public form's POST submission: nonce/honeypot/rate-limit
 * checks, sanitization against the field schema, duplicate detection, and
 * saving the entry. Never calls email or SMS code directly — it only fires
 * 'reunion_reg_after_submit' at the end for anything that wants to react
 * to a brand-new (still-pending) registration.
 */
class Reunion_Reg_Form_Submission {

    public function __construct() {
        add_action( 'admin_post_reunion_reg_submit', array( $this, 'handle_submission' ) );
        add_action( 'admin_post_nopriv_reunion_reg_submit', array( $this, 'handle_submission' ) );
    }

    /**
     * Get the visitor's IP address, accounting for common proxy/CDN headers.
     * Used for rate limiting only — not treated as authoritative for security decisions.
     */
    private function get_client_ip() {
        $headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip_list = explode( ',', $_SERVER[ $header ] );
                $ip      = trim( $ip_list[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '';
    }

    /**
     * Redirects back to the form after a failed validation, saving the visitor's
     * already-typed values in a short-lived transient (one-time use, 15 minutes)
     * so the form can re-populate itself instead of making them start over.
     */
    private function redirect_with_retry( $status, $clean ) {
        $token = wp_generate_password( 12, false, false );
        set_transient( 'reunion_reg_retry_' . $token, $clean, 15 * MINUTE_IN_SECONDS );

        $redirect_url = add_query_arg(
            array(
                'reunion_reg_status' => $status,
                'reunion_reg_retry'  => $token,
            ),
            wp_get_referer() ?: home_url()
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handle form submission: validate, sanitize, save as CPT entry.
     */
    public function handle_submission() {

        // Verify nonce - protects against CSRF
        if (
            ! isset( $_POST[ REUNION_REG_NONCE_FIELD ] ) ||
            ! wp_verify_nonce( $_POST[ REUNION_REG_NONCE_FIELD ], REUNION_REG_NONCE_ACTION )
        ) {
            wp_die( 'Security check failed. Please go back and try again.' );
        }

        // Honeypot check: real users never fill this hidden field; bots often do.
        if ( ! empty( $_POST['reunion_website'] ) ) {
            // Silently pretend success so bots don't learn the honeypot was hit.
            wp_safe_redirect( add_query_arg( 'reunion_reg_status', 'success', wp_get_referer() ?: home_url() ) );
            exit;
        }

        // Time-trap: forms filled in under 4 seconds are almost always bots.
        $loaded_at = isset( $_POST['reunion_form_loaded_at'] ) ? absint( $_POST['reunion_form_loaded_at'] ) : 0;
        if ( $loaded_at && ( time() - $loaded_at ) < 4 ) {
            wp_safe_redirect( add_query_arg( 'reunion_reg_status', 'success', wp_get_referer() ?: home_url() ) );
            exit;
        }

        // Rate limiting: max 3 submissions per IP per 10 minutes.
        $ip = $this->get_client_ip();
        if ( $ip ) {
            $rate_key   = 'reunion_reg_rate_' . md5( $ip );
            $rate_count = (int) get_transient( $rate_key );

            if ( $rate_count >= 3 ) {
                wp_safe_redirect( add_query_arg( 'reunion_reg_status', 'rate_limited', wp_get_referer() ?: home_url() ) );
                exit;
            }

            set_transient( $rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS );
        }

        $fields = Reunion_Reg_Fields_Schema::get_fields();
        $clean  = array();
        $file_uploads = array();
        $has_missing_required = false;

        foreach ( $fields as $key => $field ) {

            if ( 'computed' === $field['type'] || 'file' === $field['type'] ) {
                continue;
            }

            $is_applicable = Reunion_Reg_Fields_Schema::field_is_applicable( $field, $clean );
            $raw_value     = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

            switch ( $field['type'] ) {
                case 'email':
                    $value = sanitize_email( $raw_value );
                    break;
                case 'tel':
                    $value = preg_replace( '/[^0-9+\-\s()]/', '', $raw_value );
                    $value = sanitize_text_field( $value );
                    break;
                case 'number':
                    $value = is_numeric( $raw_value ) ? (float) $raw_value : 0;
                    if ( isset( $field['min'] ) ) {
                        $value = max( (float) $field['min'], $value );
                    }
                    if ( isset( $field['max'] ) ) {
                        $value = min( (float) $field['max'], $value );
                    }
                    break;
                case 'select':
                case 'radio':
                    $value = sanitize_text_field( $raw_value );
                    if ( '' !== $value && ! in_array( $value, array_map( 'strval', $field['options'] ), true ) ) {
                        $value = ''; // reject values not in the allowed list
                    }
                    break;
                default:
                    $value = sanitize_text_field( $raw_value );
                    break;
            }

            if ( ! $is_applicable ) {
                // Field is hidden for this submission (e.g. bank fields when mobile
                // banking was chosen) — don't store stray/leftover data for it.
                $value = ( 'number' === $field['type'] ) ? 0 : '';
            } elseif ( ! empty( $field['required'] ) && ( '' === $value || null === $value ) ) {
                $has_missing_required = true;
            }

            $clean[ $key ] = $value;
        }

        // Registration fee + per-guest fee + optional donation, calculated here from
        // the admin-configured settings — never from anything the client submitted,
        // so a tampered "total" in the browser can never change what gets recorded.
        $payment_settings      = Reunion_Reg_Fields_Schema::get_payment_settings();
        $guest_count           = isset( $clean['guest_count'] ) ? (float) $clean['guest_count'] : 0;
        $donation              = isset( $clean['donation'] ) ? (float) $clean['donation'] : 0;
        $clean['total_amount'] = (float) $payment_settings['registration_fee'] + ( $guest_count * (float) $payment_settings['guest_fee'] ) + $donation;

        foreach ( $fields as $fk => $fv ) {
            if ( ( $fv['type'] ?? '' ) !== 'file' ) { continue; }
            $uploaded = isset( $_FILES[ $fk ] ) ? $_FILES[ $fk ] : null;
            $has_upload = is_array( $uploaded ) && isset( $uploaded['error'] ) && 4 !== (int) $uploaded['error'];
            $is_applicable = Reunion_Reg_Fields_Schema::field_is_applicable( $fv, $clean );
            if ( ! $is_applicable ) { continue; }
            $is_required_file = ! empty( $fv['required'] );
            if ( ! $has_upload ) {
                if ( $is_required_file ) { $has_missing_required = true; }
                continue;
            }
            if ( 0 !== (int) $uploaded['error'] ) {
                foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
                $this->redirect_with_retry( 'error', array() );
            }
            $max_bytes = 2 * 1024 * 1024;
            $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp' );
            $allowed_exts  = array( 'jpg', 'jpeg', 'png', 'webp' );
            $check = wp_check_filetype( $uploaded['name'] ?? '', array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
            $ext_ok  = $check && ! empty( $check['ext'] ) && in_array( strtolower( $check['ext'] ), $allowed_exts, true );
            $mime_ok = ! empty( $uploaded['type'] ) && in_array( strtolower( $uploaded['type'] ), $allowed_mimes, true );
            if ( ! $ext_ok && ! $mime_ok ) {
                $finfo_ok = false;
                if ( ! empty( $uploaded['tmp_name'] ) && is_uploaded_file( $uploaded['tmp_name'] ) ) {
                    $finfo = @finfo_open( FILEINFO_MIME_TYPE );
                    if ( $finfo ) {
                        $detected = @finfo_file( $finfo, $uploaded['tmp_name'] );
                        @finfo_close( $finfo );
                        if ( $detected && in_array( strtolower( $detected ), $allowed_mimes, true ) ) { $finfo_ok = true; }
                    }
                    if ( ! $finfo_ok ) {
                        $imginfo = @getimagesize( $uploaded['tmp_name'] );
                        if ( $imginfo && isset( $imginfo['mime'] ) && in_array( strtolower( $imginfo['mime'] ), $allowed_mimes, true ) ) { $finfo_ok = true; }
                    }
                }
                if ( ! $finfo_ok ) {
                    foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
                    $this->redirect_with_retry( 'invalid_file', array() );
                }
            }
            $size = isset( $uploaded['size'] ) ? (int) $uploaded['size'] : 0;
            if ( $size > $max_bytes ) {
                foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
                $this->redirect_with_retry( 'file_too_large', array() );
            }
            if ( ! empty( $uploaded['tmp_name'] ) && is_uploaded_file( $uploaded['tmp_name'] ) ) {
                $real_size = @filesize( $uploaded['tmp_name'] );
                if ( $real_size && $real_size > $max_bytes ) {
                    foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
                    $this->redirect_with_retry( 'file_too_large', array() );
                }
            }
            $overrides = array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
            $move = wp_handle_upload( $uploaded, $overrides );
            if ( ! $move || ! empty( $move['error'] ) ) {
                foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
                $this->redirect_with_retry( 'error', array() );
            }
            if ( ! empty( $move['file'] ) && @filesize( $move['file'] ) > $max_bytes ) {
                @unlink( $move['file'] );
                foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
                $this->redirect_with_retry( 'file_too_large', array() );
            }
            $file_uploads[ $fk ] = $move;
        }

        if ( $has_missing_required ) {
            foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
            $this->redirect_with_retry( 'missing_fields', $clean );
        }

        // Duplicate / uniqueness guard: same Phone, same Email, or same Transaction ID
        // already exists anywhere in the system (regardless of which field matches).
        global $wpdb;

        $duplicate_checks = array(
            'phone'  => $clean['phone'],
            'email'  => $clean['email'],
            'tnx_id' => $clean['tnx_id'],
        );

        $duplicate_field = null;

        foreach ( $duplicate_checks as $field_key => $field_value ) {
            if ( empty( $field_value ) ) {
                continue;
            }

            $meta_key = '_reunion_' . $field_key;

            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                 AND pm.meta_value = %s
                 AND p.post_type = %s
                 AND p.post_status = 'publish'
                 LIMIT 1",
                $meta_key,
                $field_value,
                REUNION_REG_CPT_SLUG
            ) );

            if ( $found ) {
                $duplicate_field = $field_key;
                break;
            }
        }

        if ( $duplicate_field ) {
            foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
            $reason = array(
                'phone'  => 'duplicate_phone',
                'email'  => 'duplicate_email',
                'tnx_id' => 'duplicate_tnx',
            );
            $this->redirect_with_retry( $reason[ $duplicate_field ], $clean );
        }

        $post_id = wp_insert_post( array(
            'post_type'   => REUNION_REG_CPT_SLUG,
            'post_title'  => $clean['full_name'] . ' — ' . $clean['tnx_id'],
            'post_status' => 'publish',
        ), true );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            foreach ( $file_uploads as $prev ) { if ( ! empty( $prev['file'] ) ) { @unlink( $prev['file'] ); } }
            $this->redirect_with_retry( 'error', $clean );
        }

        foreach ( $clean as $key => $value ) {
            update_post_meta( $post_id, '_reunion_' . $key, $value );
        }
        if ( ! empty( $file_uploads['payment_receipt']['url'] ) ) {
            update_post_meta( $post_id, '_reunion_payment_receipt_url', esc_url_raw( $file_uploads['payment_receipt']['url'] ) );
            update_post_meta( $post_id, '_reunion_payment_receipt_file', sanitize_text_field( $file_uploads['payment_receipt']['file'] ) );
        }
        if ( ! empty( $file_uploads['applicant_photo']['url'] ) ) {
            update_post_meta( $post_id, '_reunion_applicant_photo_url', esc_url_raw( $file_uploads['applicant_photo']['url'] ) );
            update_post_meta( $post_id, '_reunion_applicant_photo_file', sanitize_text_field( $file_uploads['applicant_photo']['file'] ) );
        }
        $clean['payment_receipt_url'] = $file_uploads['payment_receipt']['url'] ?? '';
        $clean['applicant_photo_url'] = $file_uploads['applicant_photo']['url'] ?? '';

        update_post_meta( $post_id, '_reunion_submitted_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_reunion_submitted_ip', sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        update_post_meta( $post_id, '_reunion_status', 'pending' );

        /**
         * Fires after a new registration has been saved (status: pending).
         * No core module currently listens to this — it's provided so any
         * future addition (e.g. an internal "new registration" admin alert)
         * can hook in without needing to modify this file.
         */
        do_action( 'reunion_reg_after_submit', $post_id, $clean );

        wp_safe_redirect( add_query_arg( 'reunion_reg_status', 'success', wp_get_referer() ?: home_url() ) );
        exit;
    }
}
