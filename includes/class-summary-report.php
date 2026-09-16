<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Summary dashboard — adds a submenu page showing total registrations,
 * a breakdown by status, and a breakdown by batch x status.
 */
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

    /**
     * Builds counts in a single grouped query: total, per-status, and per-batch
     * x per-status — used by the Summary admin page.
     */
    private function get_registration_stats() {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.meta_value AS batch, s.meta_value AS status, COUNT(*) AS cnt
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             GROUP BY b.meta_value, s.meta_value",
            '_reunion_batch',
            '_reunion_status',
            REUNION_REG_CPT_SLUG
        ), ARRAY_A );

        $stats = array(
            'total'    => 0,
            'by_status' => array( 'pending' => 0, 'approved' => 0, 'rejected' => 0 ),
            'by_batch'  => array(),
        );

        foreach ( (array) $rows as $row ) {
            $batch  = ( '' !== (string) $row['batch'] ) ? $row['batch'] : '(ব্যাচ নেই)';
            $status = $row['status'] ? $row['status'] : 'pending';
            $count  = (int) $row['cnt'];

            $stats['total'] += $count;

            if ( ! isset( $stats['by_status'][ $status ] ) ) {
                $stats['by_status'][ $status ] = 0;
            }
            $stats['by_status'][ $status ] += $count;

            if ( ! isset( $stats['by_batch'][ $batch ] ) ) {
                $stats['by_batch'][ $batch ] = array( 'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0 );
            }
            $stats['by_batch'][ $batch ]['total'] += $count;
            if ( ! isset( $stats['by_batch'][ $batch ][ $status ] ) ) {
                $stats['by_batch'][ $batch ][ $status ] = 0;
            }
            $stats['by_batch'][ $batch ][ $status ] += $count;
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

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0 28px;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;min-width:140px;">
                    <div style="font-size:13px;color:#646970;">Total Registrations</div>
                    <div style="font-size:28px;font-weight:700;"><?php echo (int) $stats['total']; ?></div>
                </div>
                <?php foreach ( $statuses as $key => $label ) : ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;min-width:140px;">
                        <div style="font-size:13px;color:#646970;"><?php echo esc_html( $label ); ?></div>
                        <div style="font-size:28px;font-weight:700;"><?php echo (int) ( $stats['by_status'][ $key ] ?? 0 ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2>By Batch</h2>
            <?php if ( empty( $stats['by_batch'] ) ) : ?>
                <p>এখনো কোনো রেজিস্ট্রেশন জমা পড়েনি।</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:720px;">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Total</th>
                            <?php foreach ( $statuses as $label ) : ?>
                                <th><?php echo esc_html( $label ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $stats['by_batch'] as $batch => $counts ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $batch ); ?></strong></td>
                                <td><?php echo (int) $counts['total']; ?></td>
                                <?php foreach ( array_keys( $statuses ) as $status_key ) : ?>
                                    <td><?php echo (int) ( $counts[ $status_key ] ?? 0 ); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
