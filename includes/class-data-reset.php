<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Reunion_Reg_Data_Reset {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_reset_page' ) );
        add_action( 'admin_post_reunion_reg_reset_data', array( $this, 'handle_reset' ) );
    }

    public function add_reset_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'Reset Data',
            'Reset Data',
            'manage_options',
            'reunion_reg_reset',
            array( $this, 'render_reset_page' )
        );
    }

    public function render_reset_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }
        global $wpdb;

        $counts = wp_count_posts( REUNION_REG_CPT_SLUG );
        $total  = 0;
        $trash  = 0;
        foreach ( (array) $counts as $status => $count ) {
            if ( 'trash' === $status ) {
                $trash = (int) $count;
                continue;
            }
            $total += (int) $count;
        }

        $counter = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            REUNION_REG_COUNTER_OPTION
        ) );

        $reset_url = admin_url( 'admin-post.php' );
        ?>
        <div class="wrap">
            <h1>Reset Registration Data</h1>
            <p>Use this after testing is complete to delete all registration entries and restart the Registration ID counter from the beginning.</p>
            <p><strong>Current entries: <?php echo (int) $total; ?></strong><?php echo $trash ? ' <span style="color:#666;">(' . (int) $trash . ' in trash — also deleted on reset)</span>' : ''; ?></p>
            <p><strong>Current ID counter: <?php echo null !== $counter ? (int) $counter : 0; ?></strong> <span style="color:#666;">(next approval would be #<?php echo (int) $counter + 1; ?> — after reset it restarts at #1, e.g. 2021-0001)</span></p>
            <div style="background:#fff;border:1px solid #f5c6c2;border-left:4px solid #d63638;border-radius:4px;padding:16px 20px;max-width:640px;margin:20px 0;">
                <p style="margin:0 0 8px;color:#611a15;"><strong>Warning: This permanently deletes all registrations (Pending, Approved, Rejected) and cannot be undone.</strong></p>
                <p style="margin:0;color:#666;">Payment &amp; Fee Settings and SMS Settings are kept. Only registration entries and the ID counter are reset.</p>
            </div>
            <form method="POST" action="<?php echo esc_url( $reset_url ); ?>" id="reunion-reg-reset-form">
                <input type="hidden" name="action" value="reunion_reg_reset_data">
                <?php wp_nonce_field( 'reunion_reg_reset_data', 'reunion_reg_reset_nonce' ); ?>
                <p>Type <code>RESET</code> in the box below to enable the reset button:</p>
                <p>
                    <input type="text" name="reunion_reg_reset_confirm" id="reunion_reg_reset_confirm" class="regular-text" placeholder="Type RESET here" autocomplete="off" style="font-weight:700;letter-spacing:2px;">
                </p>
                <?php submit_button( 'Reset All Data & Restart IDs', 'delete', 'submit', false, array( 'id' => 'reunion-reg-reset-btn', 'disabled' => 'disabled' ) ); ?>
            </form>
            <script>
            (function () {
                var input = document.getElementById( 'reunion_reg_reset_confirm' );
                var btn   = document.getElementById( 'reunion-reg-reset-btn' );
                var form  = document.getElementById( 'reunion-reg-reset-form' );
                if ( ! input || ! btn || ! form ) {
                    return;
                }
                input.addEventListener( 'input', function () {
                    btn.disabled = ( input.value.trim() !== 'RESET' );
                } );
                form.addEventListener( 'submit', function ( e ) {
                    if ( input.value.trim() !== 'RESET' ) {
                        e.preventDefault();
                        return false;
                    }
                    return confirm( 'Delete ALL registrations and restart Registration IDs from the start? This cannot be undone.' );
                } );
            })();
            </script>
        </div>
        <?php
    }

    public function handle_reset() {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        if ( ! isset( $_POST['reunion_reg_reset_nonce'] ) || ! wp_verify_nonce( $_POST['reunion_reg_reset_nonce'], 'reunion_reg_reset_data' ) ) {
            wp_die( 'Security check failed. Please go back and try again.' );
        }

        $typed = isset( $_POST['reunion_reg_reset_confirm'] ) ? trim( wp_unslash( $_POST['reunion_reg_reset_confirm'] ) ) : '';

        if ( 'RESET' !== $typed ) {
            wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'reset_not_confirmed', admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG . '&page=reunion_reg_reset' ) ) );
            exit;
        }

        // Delete directly via SQL: get_posts( 'any' ) misses trash/auto-draft
        // rows, and wp_delete_post() only trashes them anyway. We want every
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            REUNION_REG_CPT_SLUG
        ) );

        foreach ( $ids as $id ) {
            $wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $id ), array( '%d' ) );
            $wpdb->delete( $wpdb->posts, array( 'ID' => (int) $id ), array( '%d' ) );
        }

        clean_post_cache( $ids );

        // Delete the counter row directly. delete_option() can silently fail
        // to clear it when an object-cache drop-in (Redis/Memcached on
        // Local) keeps serving the stale value after the delete.
        $wpdb->delete( $wpdb->options, array( 'option_name' => REUNION_REG_COUNTER_OPTION ), array( '%s' ) );
        wp_cache_delete( REUNION_REG_COUNTER_OPTION, 'options' );
        wp_cache_flush();

        $left = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            REUNION_REG_CPT_SLUG
        ) );
        $counter_gone = null === $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            REUNION_REG_COUNTER_OPTION
        ) );

        if ( 0 === $left && $counter_gone ) {
            wp_safe_redirect( add_query_arg( 'reunion_admin_notice', 'reset_done', admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG . '&page=reunion_reg_reset' ) ) );
        } else {
            wp_safe_redirect( add_query_arg(
                array( 'reunion_admin_notice' => 'reset_partial', 'left' => $left ),
                admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG . '&page=reunion_reg_reset' )
            ) );
        }
        exit;
    }
}
