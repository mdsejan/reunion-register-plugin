<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Reunion_Reg_Print_Reports {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
    }

    public function add_submenu_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'Print & Export Reports',
            'Print Reports',
            'edit_posts',
            'reunion-print-reports',
            array( $this, 'render_page' )
        );
    }

    private function check_access() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }
    }

    private function get_approved_gift_data() {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                g.meta_value AS gift,
                b.meta_value AS batch
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s
            INNER JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
            INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
            WHERE p.post_type = %s
              AND p.post_status = 'publish'
              AND COALESCE(NULLIF(s.meta_value,''),'pending') = 'approved'
              AND g.meta_value != ''",
            '_reunion_gift_dress',
            '_reunion_batch',
            '_reunion_status',
            REUNION_REG_CPT_SLUG
        ), ARRAY_A );

        return $rows ? $rows : array();
    }

    private function compute_summary( $rows ) {
        $gift_totals = array();
        $batch_matrix = array();

        foreach ( $rows as $row ) {
            $gift  = $row['gift'] ?: 'অনির্ধারিত';
            $batch = $row['batch'] ?: '(ব্যাচ নেই)';

            if ( ! isset( $gift_totals[ $gift ] ) ) {
                $gift_totals[ $gift ] = 0;
            }
            $gift_totals[ $gift ]++;

            if ( ! isset( $batch_matrix[ $batch ] ) ) {
                $batch_matrix[ $batch ] = array();
            }
            if ( ! isset( $batch_matrix[ $batch ][ $gift ] ) ) {
                $batch_matrix[ $batch ][ $gift ] = 0;
            }
            $batch_matrix[ $batch ][ $gift ]++;
        }

        krsort( $batch_matrix );

        $summary = array();
        foreach ( $gift_totals as $gift => $count ) {
            $buffer = (int) ceil( $count * 0.05 );
            $summary[] = array(
                'gift'        => $gift,
                'exact'       => $count,
                'buffer'      => $buffer,
                'final_order' => $count + $buffer,
            );
        }

        usort( $summary, function( $a, $b ) {
            return strcmp( $a['gift'], $b['gift'] );
        });

        return array(
            'summary'      => $summary,
            'batch_matrix' => $batch_matrix,
            'total_rows'   => count( $rows ),
        );
    }

    public function render_page() {
        $this->check_access();
        $rows    = $this->get_approved_gift_data();
        $data    = $this->compute_summary( $rows );
        $summary = $data['summary'];
        $matrix  = $data['batch_matrix'];
        $total   = $data['total_rows'];
        $now     = current_time( 'Y-m-d H:i' );
        ?>
        <style>
        .reunion-print-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px;}
        .reunion-print-btn{white-space:nowrap;}
        .reunion-gift-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-bottom:20px;}
        .reunion-gift-card h2{font-size:16px;margin:0 0 14px;padding-bottom:10px;border-bottom:1px solid #dcdcde;}
        .reunion-gift-card table{margin-top:8px;}
        @media print{
            @page{size:A4;margin:12mm 10mm;}
            html.wp-toolbar{padding-top:0 !important;}
            #wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,.update-nag,.notice,.error,.updated,#screen-meta,#screen-meta-links{display:none !important;}
            #wpcontent{margin-left:0 !important;padding-left:0 !important;}
            body.wp-admin{background:#fff !important;}
            #wpbody-content{padding-bottom:0 !important;}
            .reunion-print-wrap{margin:0 !important;max-width:none !important;}
            .reunion-print-header{margin-bottom:12px !important;}
            .reunion-print-btn{display:none !important;}
            .reunion-print-wrap h1{font-size:20px !important;margin:0 !important;color:#111 !important;}
            .reunion-print-wrap h2{font-size:15px !important;margin:14px 0 8px !important;color:#111 !important;}
            .reunion-gift-card{border:1px solid #bbb !important;box-shadow:none !important;break-inside:avoid;padding:12px 14px !important;background:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .reunion-gift-card table{border-collapse:collapse !important;width:100% !important;min-width:0 !important;font-size:11px !important;}
            .reunion-gift-card table th,.reunion-gift-card table td{border:1px solid #999 !important;padding:6px 7px !important;text-align:left !important;}
            .reunion-gift-card table thead th{background:#f0f0f0 !important;font-weight:700 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .reunion-gift-card table tfoot td{background:#ededed !important;font-weight:700 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .reunion-gift-card table tr{break-inside:avoid;page-break-inside:avoid;}
            .reunion-gift-card table thead{display:table-header-group;}
            .reunion-gift-card table tfoot{display:table-footer-group;}
        }
        </style>
        <div class="wrap reunion-print-wrap">
            <div class="reunion-print-header">
                <h1>T-Shirt &amp; Gift Order Summary</h1>
                <button type="button" class="button button-primary reunion-print-btn" onclick="window.print()">Print T-Shirt Summary</button>
            </div>

            <div class="reunion-gift-card">
                <h2>Overall Size Summary</h2>
                <?php if ( empty( $summary ) ) : ?>
                    <p>এখনো অনুমোদিত রেজিস্ট্রেশন নেই।</p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Size Name</th>
                                <th>Exact Count</th>
                                <th>+5% Buffer</th>
                                <th>Final Order Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $summary as $row ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $row['gift'] ); ?></strong></td>
                                    <td><?php echo (int) $row['exact']; ?></td>
                                    <td><?php echo (int) $row['buffer']; ?></td>
                                    <td><strong><?php echo (int) $row['final_order']; ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:700;background:#f6f7f7;">
                                <td>Total (Approved: <?php echo (int) $total; ?>)</td>
                                <td><?php echo (int) array_sum( array_column( $summary, 'exact' ) ); ?></td>
                                <td><?php echo (int) array_sum( array_column( $summary, 'buffer' ) ); ?></td>
                                <td><?php echo (int) array_sum( array_column( $summary, 'final_order' ) ); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>

            <div class="reunion-gift-card">
                <h2>Batch-wise Breakdown Matrix</h2>
                <?php if ( empty( $matrix ) ) : ?>
                    <p>এখনো অনুমোদিত রেজিস্ট্রেশন নেই।</p>
                <?php else : ?>
                    <?php
                    $all_gifts = array();
                    foreach ( $matrix as $batch_gifts ) {
                        foreach ( array_keys( $batch_gifts ) as $gift ) {
                            if ( ! in_array( $gift, $all_gifts, true ) ) {
                                $all_gifts[] = $gift;
                            }
                        }
                    }
                    sort( $all_gifts );
                    ?>
                    <div style="overflow-x:auto;">
                        <table class="widefat striped" style="min-width:600px;">
                            <thead>
                                <tr>
                                    <th>SSC Batch</th>
                                    <?php foreach ( $all_gifts as $gift ) : ?>
                                        <th><?php echo esc_html( $gift ); ?></th>
                                    <?php endforeach; ?>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $matrix as $batch => $gifts ) :
                                    $batch_total = array_sum( $gifts );
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $batch ); ?></strong></td>
                                        <?php foreach ( $all_gifts as $gift ) : ?>
                                            <td><?php echo isset( $gifts[ $gift ] ) ? (int) $gifts[ $gift ] : '—'; ?></td>
                                        <?php endforeach; ?>
                                        <td><strong><?php echo (int) $batch_total; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:700;background:#f6f7f7;">
                                    <td>Grand Total</td>
                                    <?php foreach ( $all_gifts as $gift ) :
                                        $col_total = 0;
                                        foreach ( $matrix as $batch_gifts ) {
                                            $col_total += isset( $batch_gifts[ $gift ] ) ? $batch_gifts[ $gift ] : 0;
                                        }
                                    ?>
                                        <td><?php echo (int) $col_total; ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo (int) $total; ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <p style="color:#646970;font-size:12px;margin-top:8px;">Generated: <?php echo esc_html( $now ); ?></p>
        </div>
        <?php
    }
}
