<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static-only utility — generates the next Registration ID in
 * BATCH-NNNN format, using a global sequential counter shared across
 * all batches.
 *
 * BUG FIX (race condition): the previous implementation did
 *   $next = (int) get_option(...) + 1; update_option(..., $next);
 * as two separate calls. Under concurrent requests (e.g. an admin
 * double-clicking Approve, or several approvals happening within the
 * same second), two requests could both read the same "before" value
 * and both compute the same "next" number, handing out duplicate
 * Registration IDs.
 *
 * The fix below uses a single atomic SQL statement —
 * INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID(expr) — which is
 * the standard MySQL idiom for a race-free counter on a non-AUTO_INCREMENT
 * column. LAST_INSERT_ID() is connection-local, so even when many requests
 * hit this at the same instant, each one's very next
 * "SELECT LAST_INSERT_ID()" is guaranteed to return the exact value that
 * request's own increment produced — never a value claimed by another
 * concurrent request.
 */
class Reunion_Reg_ID_Generator {

    public static function generate_registration_id( $batch ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic counter, see class docblock.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, LAST_INSERT_ID(1), 'no')
             ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)",
            REUNION_REG_COUNTER_OPTION
        ) );

        $next_number = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

        // The write above bypassed update_option(), so make sure WordPress's
        // object cache doesn't keep serving a stale cached value if anything
        // ever calls get_option( REUNION_REG_COUNTER_OPTION ) directly.
        wp_cache_delete( REUNION_REG_COUNTER_OPTION, 'options' );

        $padded = str_pad( $next_number, 4, '0', STR_PAD_LEFT );
        $batch  = sanitize_text_field( $batch );

        return $batch . '-' . $padded;
    }
}
