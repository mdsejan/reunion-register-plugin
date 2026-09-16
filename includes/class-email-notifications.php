<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sends the applicant-facing confirmation/rejection emails. This class is
 * entirely self-contained: it discovers what to do purely by listening for
 * 'reunion_reg_after_approve' / 'reunion_reg_after_reject'. No other module
 * calls send_approval_email() or send_rejection_email() directly — if this
 * whole file were deleted, the approval workflow would keep working exactly
 * the same, it just wouldn't send emails anymore.
 */
class Reunion_Reg_Email_Notifications {

    public function __construct() {
        add_action( 'reunion_reg_after_approve', array( $this, 'send_approval_email' ), 10, 2 );
        add_action( 'reunion_reg_after_reject', array( $this, 'send_rejection_email' ), 10, 1 );
    }

    /**
     * Email: sent to the applicant when their registration is approved.
     */
    public function send_approval_email( $post_id, $reg_id ) {
        $name  = get_post_meta( $post_id, '_reunion_full_name', true );
        $email = get_post_meta( $post_id, '_reunion_email', true );
        $batch = get_post_meta( $post_id, '_reunion_batch', true );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf( '[%s] Your Registration is Confirmed — %s', $site_name, $reg_id );

        $message  = "Dear {$name},\n\n";
        $message .= "Great news! Your reunion registration has been verified and approved.\n\n";
        $message .= "Registration ID: {$reg_id}\n";
        $message .= "Batch: {$batch}\n\n";
        $message .= "Please keep this Registration ID safe — you may be asked to show it at the event for check-in.\n\n";
        $message .= "We look forward to seeing you there!\n\n";
        $message .= "— {$site_name}";

        $message = apply_filters( 'reunion_reg_approval_email_body', $message, $post_id, $reg_id );
        $subject = apply_filters( 'reunion_reg_approval_email_subject', $subject, $post_id, $reg_id );

        wp_mail( $email, $subject, $message );
    }

    /**
     * Email: sent to the applicant when their registration is rejected.
     */
    public function send_rejection_email( $post_id ) {
        $name  = get_post_meta( $post_id, '_reunion_full_name', true );
        $email = get_post_meta( $post_id, '_reunion_email', true );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf( '[%s] Update on Your Registration', $site_name );

        $message  = "Dear {$name},\n\n";
        $message .= "Thank you for registering for the reunion. Unfortunately, we were unable to verify your payment / transaction details, so we could not confirm your registration at this time.\n\n";
        $message .= "If you believe this is a mistake, or if you'd like to resubmit with correct payment details, please contact us directly so we can help resolve it.\n\n";
        $message .= "We're sorry for the inconvenience.\n\n";
        $message .= "— {$site_name}";

        $message = apply_filters( 'reunion_reg_rejection_email_body', $message, $post_id );
        $subject = apply_filters( 'reunion_reg_rejection_email_subject', $subject, $post_id );

        wp_mail( $email, $subject, $message );
    }
}
