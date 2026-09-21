<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Reunion_Reg_Gate_Checkin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
        add_action( 'admin_init', array( $this, 'handle_print_view' ) );
    }

    public function add_submenu_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'Gate Check-in Sheet (Batch-wise)',
            'Gate Check-in Sheet',
            'edit_posts',
            'reunion-gate-checkin',
            array( $this, 'render_page' )
        );
    }

    public function handle_print_view() {
        if ( ! isset( $_GET['page'] ) || 'reunion-gate-checkin' !== $_GET['page'] ) {
            return;
        }
        if ( ! isset( $_GET['action'] ) || 'print_gate_sheet' !== $_GET['action'] ) {
            return;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        $batch_filter = isset( $_GET['batch'] ) ? sanitize_text_field( $_GET['batch'] ) : '';
        $this->render_standalone_print_page( $batch_filter );
        exit;
    }

    private function render_standalone_print_page( $batch_filter = '' ) {
        $rows = $this->get_gate_checkin_data( $batch_filter );
        $batches = $this->get_approved_batches();
        $batch_label = $batch_filter ?: 'All Batches';

        if ( $batch_filter && isset( array_flip( $batches )[ $batch_filter ] ) ) {
            $batch_groups = array( $batch_filter => $rows );
        } else {
            $batch_groups = array();
            foreach ( $rows as $row ) {
                $b = $row['batch'] ?: '(ব্যাচ নেই)';
                if ( ! isset( $batch_groups[ $b ] ) ) {
                    $batch_groups[ $b ] = array();
                }
                $batch_groups[ $b ][] = $row;
            }
        }
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <title>Gate Check-in Sheet — <?php echo esc_html( $batch_label ); ?></title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: Arial, sans-serif; font-size: 12px; color: #000; background: #fff; padding: 20px; }
                .gch-header { text-align: center; margin-bottom: 18px; padding-bottom: 10px; border-bottom: 2px solid #333; }
                .gch-header h1 { font-size: 18px; margin-bottom: 4px; }
                .gch-header p { font-size: 13px; color: #333; }
                .gch-meta { font-size: 12px; margin-bottom: 12px; color: #444; }
                .gch-meta strong { color: #000; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
                th, td { border: 1px solid #555; padding: 5px 6px; text-align: left; font-size: 11px; }
                th { background: #e0e0e0; font-weight: 700; }
                td.signature { min-width: 120px; height: 36px; border: 1px dashed #555; }
                .batch-page-block { page-break-after: always; break-after: page; }
                .batch-page-block:last-child { page-break-after: auto; break-after: auto; }
                .no-data { color: #666; font-style: italic; }
                .footer-note { margin-top: 16px; font-size: 11px; color: #888; text-align: center; }
            </style>
        </head>
        <body>
        <div class="gch-header">
            <h1>Reunion Registration Gate Check-in Sheet</h1>
            <p>SSC Batch: <?php echo esc_html( $batch_label ); ?></p>
        </div>
        <div class="gch-meta">
            <strong>Date:</strong> <?php echo esc_html( current_time( 'Y-m-d H:i' ) ); ?>
        </div>
        <?php if ( empty( $rows ) ) : ?>
            <p class="no-data">এখনো অনুমোদিত রেজিস্ট্রেশন নেই।</p>
        <?php else : ?>
            <?php foreach ( $batch_groups as $blabel => $entries ) :
                $total_members = count( $entries );
                $total_guests  = 0;
                foreach ( $entries as $e ) {
                    $total_guests += (int) ( $e['guest_count'] ?? 0 );
                }
                $sl = 0;
            ?>
                <div class="batch-page-block">
                    <div class="gch-meta">
                        <strong>Batch:</strong> <?php echo esc_html( $blabel ); ?>
                        &nbsp;|&nbsp;
                        <strong>Total Approved Members:</strong> <?php echo (int) $total_members; ?>
                        &nbsp;|&nbsp;
                        <strong>Total Guests:</strong> <?php echo (int) $total_guests; ?>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>SL</th>
                                <th>Reg ID</th>
                                <th>Member Name</th>
                                <th>Mobile</th>
                                <th>Gift Size</th>
                                <th>Guests</th>
                                <th>Parking</th>
                                <th>Received &amp; Signature</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $entries as $entry ) :
                                                                $sl++;
                            ?>
                                <tr>
                                    <td><?php echo (int) $sl; ?></td>
                                    <td><strong><?php echo esc_html( $entry['reg_id'] ?? '—' ); ?></strong></td>
                                    <td><?php echo esc_html( $entry['full_name'] ?? '—' ); ?></td>
                                    <td><?php echo esc_html( $entry['phone'] ?? '—' ); ?></td>
                                    <td><?php echo esc_html( $entry['gift_dress'] ?? '—' ); ?></td>
                                    <td><?php echo (int) ( $entry['guest_count'] ?? 0 ); ?></td>
                                    <td><?php echo esc_html( $entry['parking'] ?? '—' ); ?></td>
                                    <td class="signature"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
            <div class="footer-note">Generated: <?php echo esc_html( current_time( 'Y-m-d H:i' ) ); ?></div>
        <?php endif; ?>
        <script>window.onload = function() { window.print(); };</script>
        </body>
        </html>
        <?php
    }

    private function get_approved_batches() {
        global $wpdb;

        $batches = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT b.meta_value FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND COALESCE(NULLIF(s.meta_value,''),'pending') = 'approved'
               AND b.meta_value != ''
             ORDER BY CAST(b.meta_value AS UNSIGNED) ASC",
            '_reunion_batch',
            '_reunion_status',
            REUNION_REG_CPT_SLUG
        ) );

        return $batches ? $batches : array();
    }

    private function get_gate_checkin_data( $batch_filter = '' ) {
        global $wpdb;

        $where_batch = '';
        if ( $batch_filter ) {
            $where_batch = $wpdb->prepare( " AND b.meta_value = %s", $batch_filter );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.ID,
                b.meta_value AS batch,
                r.meta_value AS reg_id,
                n.meta_value AS full_name,
                ph.meta_value AS phone,
                g.meta_value AS gift_dress,
                gc.meta_value AS guest_count,
                pk.meta_value AS parking,
                s.meta_value AS status
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
            INNER JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
            INNER JOIN {$wpdb->postmeta} n ON n.post_id = p.ID AND n.meta_key = %s
            INNER JOIN {$wpdb->postmeta} ph ON ph.post_id = p.ID AND ph.meta_key = %s
            INNER JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} gc ON gc.post_id = p.ID AND gc.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} pk ON pk.post_id = p.ID AND pk.meta_key = %s
            INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
            WHERE p.post_type = %s
              AND p.post_status = 'publish'
              AND COALESCE(NULLIF(s.meta_value,''),'pending') = 'approved'
              {$where_batch}
            ORDER BY CAST(b.meta_value AS UNSIGNED) ASC, r.meta_value ASC",
            '_reunion_batch',
            '_reunion_reg_id',
            '_reunion_full_name',
            '_reunion_phone',
            '_reunion_gift_dress',
            '_reunion_guest_count',
            '_reunion_parking_needed',
            '_reunion_status',
            REUNION_REG_CPT_SLUG
        ), ARRAY_A );

        return $rows ? $rows : array();
    }

    public function render_page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        $batch = isset( $_GET['batch'] ) ? sanitize_text_field( $_GET['batch'] ) : '';
        $batches = $this->get_approved_batches();
        ?>
        <div class="wrap">
            <h1>Gate Check-in Sheet</h1>
            <div class="reunion-gift-card">
                <h2>Batch Selection</h2>
                <form method="GET" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" target="_blank" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                    <input type="hidden" name="post_type" value="<?php echo esc_attr( REUNION_REG_CPT_SLUG ); ?>">
                    <input type="hidden" name="page" value="reunion-gate-checkin">
                    <input type="hidden" name="action" value="print_gate_sheet">
                    <div>
                        <label for="gch_batch" style="display:block;margin-bottom:4px;font-weight:600;">Select Batch</label>
                        <select name="batch" id="gch_batch">
                            <option value="">All Batches</option>
                            <?php foreach ( $batches as $b ) : ?>
                                <option value="<?php echo esc_attr( $b ); ?>" <?php selected( $batch, $b ); ?>><?php echo esc_html( $b ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="button button-primary">Open Print Preview &amp; Print</button>
                </form>
            </div>
        </div>
        <?php
    }
}