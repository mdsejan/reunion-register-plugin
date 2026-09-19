<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Reunion_Reg_Summary_Report {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_summary_page' ) );
    }

    public function add_summary_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'Registrations Summary',
            'Summary',
            'manage_options',
            'reunion_reg_summary',
            array( $this, 'render_summary_page' )
        );
    }

    private function get_registration_stats() {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.meta_value AS batch, COALESCE(NULLIF(s.meta_value,''),'pending') AS status, COUNT(*) AS cnt,
                    COALESCE(SUM(CAST(g.meta_value AS DECIMAL(10,2))),0) AS guests_sum,
                    COALESCE(SUM(CAST(a.meta_value AS DECIMAL(10,2))),0) AS amount_sum
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             GROUP BY b.meta_value, status",
            '_reunion_batch',
            '_reunion_status',
            '_reunion_guest_count',
            '_reunion_total_amount',
            REUNION_REG_CPT_SLUG
        ), ARRAY_A );

        $stats = array(
            'total'           => 0,
            'by_status'       => array( 'pending' => 0, 'approved' => 0, 'rejected' => 0 ),
            'total_guests'    => 0,
            'total_collected' => 0,
            'by_batch'        => array(),
        );

        foreach ( (array) $rows as $row ) {
            $batch      = ( '' !== (string) $row['batch'] ) ? $row['batch'] : '(ব্যাচ নেই)';
            $status     = $row['status'] ? $row['status'] : 'pending';
            $count      = (int) $row['cnt'];
            $guests_sum = (int) round( (float) $row['guests_sum'] );
            $amount_sum = (float) $row['amount_sum'];

            $stats['total'] += $count;

            if ( ! isset( $stats['by_status'][ $status ] ) ) {
                $stats['by_status'][ $status ] = 0;
            }
            $stats['by_status'][ $status ] += $count;

            if ( ! isset( $stats['by_batch'][ $batch ] ) ) {
                $stats['by_batch'][ $batch ] = array( 'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'guests' => 0, 'amount' => 0 );
            }
            $stats['by_batch'][ $batch ]['total'] += $count;
            if ( ! isset( $stats['by_batch'][ $batch ][ $status ] ) ) {
                $stats['by_batch'][ $batch ][ $status ] = 0;
            }
            $stats['by_batch'][ $batch ][ $status ] += $count;

            if ( 'approved' === $status ) {
                $stats['total_guests'] += $guests_sum;
                $stats['total_collected'] += $amount_sum;
                $stats['by_batch'][ $batch ]['guests'] += $guests_sum;
                $stats['by_batch'][ $batch ]['amount'] += $amount_sum;
            }
        }

        krsort( $stats['by_batch'] );

        return $stats;
    }

    public function render_summary_page() {
        $stats    = $this->get_registration_stats();
        $statuses = Reunion_Reg_Fields_Schema::get_statuses();
        ?>
        <div class="wrap">
            <h1>Registrations Summary</h1>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:20px 0 28px;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Total Registrations</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;"><?php echo (int) $stats['total']; ?></div>
                </div>
                <?php foreach ( $statuses as $key => $label ) : ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                        <div style="font-size:13px;color:#646970;"><?php echo esc_html( $label ); ?></div>
                        <div style="font-size:28px;font-weight:700;line-height:1.2;"><?php echo (int) ( $stats['by_status'][ $key ] ?? 0 ); ?></div>
                    </div>
                <?php endforeach; ?>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Total Guests</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;"><?php echo (int) $stats['total_guests']; ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Total Collected Amount</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;">৳<?php echo esc_html( number_format( (float) $stats['total_collected'], 0, '.', ',' ) ); ?></div>
                </div>
            </div>

            <h2>By Batch</h2>
            <?php if ( empty( $stats['by_batch'] ) ) : ?>
                <p>এখনো কোনো রেজিস্ট্রেশন জমা পড়েনি।</p>
            <?php else : ?>
                <div style="overflow-x:auto;">
                    <table class="widefat striped" style="min-width:860px;">
                        <thead>
                            <tr>
                                <th>Batch</th>
                                <th>Total Registrations</th>
                                <th>Pending</th>
                                <th>Rejected</th>
                                <th>Approved Students</th>
                                <th>Total Guests</th>
                                <th>Total Amount Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $stats['by_batch'] as $batch => $counts ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $batch ); ?></strong></td>
                                    <td><?php echo (int) $counts['total']; ?></td>
                                    <td><?php echo (int) ( $counts['pending'] ?? 0 ); ?></td>
                                    <td><?php echo (int) ( $counts['rejected'] ?? 0 ); ?></td>
                                    <td><?php echo (int) ( $counts['approved'] ?? 0 ); ?></td>
                                    <td><?php echo (int) ( $counts['guests'] ?? 0 ); ?></td>
                                    <td>৳<?php echo esc_html( number_format( (float) ( $counts['amount'] ?? 0 ), 0, '.', ',' ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:700;background:#f6f7f7;">
                                <td>Total</td>
                                <td><?php echo (int) $stats['total']; ?></td>
                                <td><?php echo (int) ( $stats['by_status']['pending'] ?? 0 ); ?></td>
                                <td><?php echo (int) ( $stats['by_status']['rejected'] ?? 0 ); ?></td>
                                <td><?php echo (int) ( $stats['by_status']['approved'] ?? 0 ); ?></td>
                                <td><?php echo (int) $stats['total_guests']; ?></td>
                                <td>৳<?php echo esc_html( number_format( (float) $stats['total_collected'], 0, '.', ',' ) ); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
