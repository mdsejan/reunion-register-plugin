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

        $parking_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             INNER JOIN {$wpdb->postmeta} ps ON ps.post_id = p.ID AND ps.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND COALESCE(NULLIF(ps.meta_value,''),'pending') = 'approved'
               AND pm.meta_value = %s",
            '_reunion_parking_needed',
            '_reunion_status',
            REUNION_REG_CPT_SLUG,
            'হ্যাঁ'
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.meta_value AS batch, COALESCE(NULLIF(s.meta_value,''),'pending') AS status, COUNT(*) AS cnt,
                    COALESCE(SUM(CAST(g.meta_value AS DECIMAL(10,2))),0) AS guests_sum,
                    COALESCE(SUM(CAST(d.meta_value AS DECIMAL(10,2))),0) AS donation_sum,
                    COALESCE(SUM(CAST(a.meta_value AS DECIMAL(10,2))),0) AS amount_sum
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             GROUP BY b.meta_value, status",
            '_reunion_batch',
            '_reunion_status',
            '_reunion_guest_count',
            '_reunion_donation',
            '_reunion_total_amount',
            REUNION_REG_CPT_SLUG
        ), ARRAY_A );

        $stats = array(
            'total'           => 0,
            'by_status'       => array( 'pending' => 0, 'approved' => 0, 'rejected' => 0 ),
            'total_guests'    => 0,
            'total_donation'  => 0,
            'total_collected' => 0,
            'total_parking'   => $parking_count,
            'by_batch'        => array(),
        );

        foreach ( (array) $rows as $row ) {
            $batch        = ( '' !== (string) $row['batch'] ) ? $row['batch'] : '(ব্যাচ নেই)';
            $status       = $row['status'] ? $row['status'] : 'pending';
            $count        = (int) $row['cnt'];
            $guests_sum   = (int) round( (float) $row['guests_sum'] );
            $donation_sum = (float) $row['donation_sum'];
            $amount_sum   = (float) $row['amount_sum'];

            $stats['total'] += $count;

            if ( ! isset( $stats['by_status'][ $status ] ) ) {
                $stats['by_status'][ $status ] = 0;
            }
            $stats['by_status'][ $status ] += $count;

            if ( ! isset( $stats['by_batch'][ $batch ] ) ) {
                $stats['by_batch'][ $batch ] = array( 'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'guests' => 0, 'donation' => 0, 'amount' => 0 );
            }
            $stats['by_batch'][ $batch ]['total'] += $count;
            if ( ! isset( $stats['by_batch'][ $batch ][ $status ] ) ) {
                $stats['by_batch'][ $batch ][ $status ] = 0;
            }
            $stats['by_batch'][ $batch ][ $status ] += $count;

            if ( 'approved' === $status ) {
                $stats['total_guests']    += $guests_sum;
                $stats['total_donation']  += $donation_sum;
                $stats['total_collected'] += $amount_sum;
                $stats['by_batch'][ $batch ]['guests']   += $guests_sum;
                $stats['by_batch'][ $batch ]['donation'] += $donation_sum;
                $stats['by_batch'][ $batch ]['amount']   += $amount_sum;
            }
        }

        krsort( $stats['by_batch'] );

        return $stats;
    }

    public function render_summary_page() {
        $stats         = $this->get_registration_stats();
        $payment       = Reunion_Reg_Fields_Schema::get_payment_settings();
        $reg_fee       = (float) $payment['registration_fee'];
        $guest_fee     = (float) $payment['guest_fee'];
        $approved_total = (int) ( $stats['by_status']['approved'] ?? 0 );

        $per_page_options = array( 25, 50, 100 );
        $per_page = isset( $_GET['reunion_per_page'] ) ? (int) $_GET['reunion_per_page'] : 25;
        if ( ! in_array( $per_page, $per_page_options, true ) ) {
            $per_page = 25;
        }
        $total_batches = count( $stats['by_batch'] );
        $total_pages   = max( 1, (int) ceil( $total_batches / $per_page ) );
        $current_page  = isset( $_GET['reunion_paged'] ) ? max( 1, (int) $_GET['reunion_paged'] ) : 1;
        $current_page  = min( $current_page, $total_pages );
        $offset        = ( $current_page - 1 ) * $per_page;
        $paged_batches = array_slice( $stats['by_batch'], $offset, $per_page, true );

        $base_url = admin_url( 'edit.php?post_type=' . REUNION_REG_CPT_SLUG . '&page=reunion_reg_summary' );

        $grand_reg_fee_total   = $approved_total * $reg_fee;
        $grand_guest_fee_total = (int) $stats['total_guests'] * $guest_fee;
        ?>
        <style>
        .reunion-reg-summary-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:8px;}
        .reunion-reg-print-btn{white-space:nowrap;}
        .reunion-reg-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:14px 0 8px;}
        .reunion-reg-pagination .reunion-reg-perpage{display:flex;align-items:center;gap:8px;}
        .reunion-reg-pagination .reunion-reg-page-links{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
        .reunion-reg-pagination .reunion-reg-page-links a,.reunion-reg-pagination .reunion-reg-page-links span{padding:4px 10px;border:1px solid #dcdcde;border-radius:6px;background:#fff;text-decoration:none;line-height:1.4;}
        .reunion-reg-pagination .reunion-reg-page-links span.current{background:#2271b1;color:#fff;border-color:#2271b1;}
        .reunion-reg-print-table-wrap{display:none;}
        @media print{
            @page{size:A4;margin:12mm 10mm;}
            html.wp-toolbar{padding-top:0 !important;}
            #wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,.update-nag,.notice,.error,.updated,#screen-meta,#screen-meta-links{display:none !important;}
            #wpcontent{margin-left:0 !important;padding-left:0 !important;}
            body.wp-admin{background:#fff !important;}
            #wpbody-content{padding-bottom:0 !important;}
            .reunion-reg-summary-wrap{margin:0 !important;max-width:none !important;}
            .reunion-reg-summary-header{margin-bottom:12px !important;}
            .reunion-reg-print-btn{display:none !important;}
            .reunion-reg-summary-wrap h1{font-size:20px !important;margin:0 !important;color:#111 !important;}
            .reunion-reg-summary-wrap h2{font-size:15px !important;margin:14px 0 8px !important;color:#111 !important;}
            .reunion-reg-summary-cards{grid-template-columns:repeat(3,minmax(0,1fr)) !important;gap:10px !important;margin:12px 0 16px !important;}
            .reunion-reg-summary-cards>div{border:1px solid #bbb !important;box-shadow:none !important;break-inside:avoid;padding:12px 14px !important;background:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .reunion-reg-summary-cards>div>div:first-child{font-size:11px !important;}
            .reunion-reg-summary-cards>div>div:last-child{font-size:18px !important;}
            .reunion-reg-summary-table-wrap{overflow:visible !important;}
            .reunion-reg-paged-table-wrap{display:none !important;}
            .reunion-reg-pagination{display:none !important;}
            .reunion-reg-print-table-wrap{display:block !important;}
            .reunion-reg-summary-wrap table{border-collapse:collapse !important;width:100% !important;min-width:0 !important;font-size:11px !important;border:1px solid #444 !important;}
            .reunion-reg-summary-wrap table th,.reunion-reg-summary-wrap table td{border:1px solid #999 !important;padding:6px 7px !important;text-align:left !important;}
            .reunion-reg-summary-wrap table thead th{background:#f0f0f0 !important;font-weight:700 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .reunion-reg-summary-wrap table tfoot td{background:#ededed !important;font-weight:700 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .reunion-reg-summary-wrap table tr{break-inside:avoid;page-break-inside:avoid;}
            .reunion-reg-summary-wrap table thead{display:table-header-group;}
            .reunion-reg-summary-wrap table tfoot{display:table-footer-group;}
        }
        </style>
        <div class="wrap reunion-reg-summary-wrap">
            <div class="reunion-reg-summary-header">
                <h1>Registrations Summary</h1>
                <button type="button" class="button button-primary reunion-reg-print-btn" onclick="window.print()">Print Summary</button>
            </div>

            <div class="reunion-reg-summary-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:20px 0 28px;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Total Registrations</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;"><?php echo (int) $approved_total; ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Total Guests</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;"><?php echo (int) $stats['total_guests']; ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Reg. Fee</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;">&#2547;<?php echo esc_html( number_format( $grand_reg_fee_total, 0, '.', ',' ) ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Guest Fee</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;">&#2547;<?php echo esc_html( number_format( $grand_guest_fee_total, 0, '.', ',' ) ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Donation</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;">&#2547;<?php echo esc_html( number_format( (float) $stats['total_donation'], 0, '.', ',' ) ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Total Parking</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;"><?php echo (int) $stats['total_parking']; ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 24px;">
                    <div style="font-size:13px;color:#646970;">Total Collected</div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;">&#2547;<?php echo esc_html( number_format( (float) $stats['total_collected'], 0, '.', ',' ) ); ?></div>
                </div>
            </div>

            <h2>By Batch</h2>
            <?php if ( empty( $stats['by_batch'] ) ) : ?>
                <p>এখনো কোনো রেজিস্ট্রেশন জমা পড়েনি।</p>
            <?php else : ?>
                <div class="reunion-reg-pagination">
                    <form method="get" class="reunion-reg-perpage">
                        <input type="hidden" name="post_type" value="<?php echo esc_attr( REUNION_REG_CPT_SLUG ); ?>">
                        <input type="hidden" name="page" value="reunion_reg_summary">
                        <label for="reunion_per_page" style="font-size:13px;">Show</label>
                        <select name="reunion_per_page" id="reunion_per_page" onchange="this.form.submit()">
                            <?php foreach ( $per_page_options as $opt ) : ?>
                                <option value="<?php echo (int) $opt; ?>" <?php selected( $per_page, $opt ); ?>><?php echo (int) $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span style="font-size:13px;">per page</span>
                    </form>
                    <div style="font-size:13px;color:#646970;">
                        Showing <?php echo (int) ( $offset + 1 ); ?>–<?php echo (int) min( $offset + $per_page, $total_batches ); ?> of <?php echo (int) $total_batches; ?> batches
                    </div>
                </div>

                <div class="reunion-reg-summary-table-wrap reunion-reg-paged-table-wrap" style="overflow-x:auto;">
                    <table class="widefat striped" style="min-width:860px;">
                        <thead>
                            <tr>
                                <th>Batch</th>
                                <th>Members</th>
                                <th>Registration Fee</th>
                                <th>Guests</th>
                                <th>Guest Reg. Fee</th>
                                <th>Donation</th>
                                <th>Total Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $paged_batches as $batch => $counts ) :
                                $members        = (int) ( $counts['approved'] ?? 0 );
                                $reg_fee_total  = $members * $reg_fee;
                                $guests         = (int) ( $counts['guests'] ?? 0 );
                                $guest_fee_total = $guests * $guest_fee;
                                $donation       = (float) ( $counts['donation'] ?? 0 );
                                $total          = (float) ( $counts['amount'] ?? 0 );
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $batch ); ?></strong></td>
                                    <td><?php echo (int) $members; ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $reg_fee_total, 0, '.', ',' ) ); ?></td>
                                    <td><?php echo (int) $guests; ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $guest_fee_total, 0, '.', ',' ) ); ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $donation, 0, '.', ',' ) ); ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $total, 0, '.', ',' ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:700;background:#f6f7f7;">
                                <td>Total</td>
                                <td><?php echo (int) $approved_total; ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( $grand_reg_fee_total, 0, '.', ',' ) ); ?></td>
                                <td><?php echo (int) $stats['total_guests']; ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( $grand_guest_fee_total, 0, '.', ',' ) ); ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( (float) $stats['total_donation'], 0, '.', ',' ) ); ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( (float) $stats['total_collected'], 0, '.', ',' ) ); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="reunion-reg-pagination">
                        <div class="reunion-reg-page-links">
                            <?php
                            $link_base = add_query_arg( array( 'reunion_per_page' => $per_page ), $base_url );
                            if ( $current_page > 1 ) {
                                echo '<a href="' . esc_url( add_query_arg( 'reunion_paged', $current_page - 1, $link_base ) ) . '">&laquo; Previous</a>';
                            }
                            for ( $i = 1; $i <= $total_pages; $i++ ) {
                                if ( $i === $current_page ) {
                                    echo '<span class="current">' . (int) $i . '</span>';
                                } else {
                                    echo '<a href="' . esc_url( add_query_arg( 'reunion_paged', $i, $link_base ) ) . '">' . (int) $i . '</a>';
                                }
                            }
                            if ( $current_page < $total_pages ) {
                                echo '<a href="' . esc_url( add_query_arg( 'reunion_paged', $current_page + 1, $link_base ) ) . '">Next &raquo;</a>';
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="reunion-reg-summary-table-wrap reunion-reg-print-table-wrap" style="overflow-x:visible;">
                    <table class="widefat striped" style="min-width:0;width:100%;">
                        <thead>
                            <tr>
                                <th>Batch</th>
                                <th>Members</th>
                                <th>Registration Fee</th>
                                <th>Guests</th>
                                <th>Guest Reg. Fee</th>
                                <th>Donation</th>
                                <th>Total Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $stats['by_batch'] as $batch => $counts ) :
                                $members        = (int) ( $counts['approved'] ?? 0 );
                                $reg_fee_total  = $members * $reg_fee;
                                $guests         = (int) ( $counts['guests'] ?? 0 );
                                $guest_fee_total = $guests * $guest_fee;
                                $donation       = (float) ( $counts['donation'] ?? 0 );
                                $total          = (float) ( $counts['amount'] ?? 0 );
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $batch ); ?></strong></td>
                                    <td><?php echo (int) $members; ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $reg_fee_total, 0, '.', ',' ) ); ?></td>
                                    <td><?php echo (int) $guests; ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $guest_fee_total, 0, '.', ',' ) ); ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $donation, 0, '.', ',' ) ); ?></td>
                                    <td>&#2547;<?php echo esc_html( number_format( $total, 0, '.', ',' ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:700;background:#f6f7f7;">
                                <td>Total</td>
                                <td><?php echo (int) $approved_total; ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( $grand_reg_fee_total, 0, '.', ',' ) ); ?></td>
                                <td><?php echo (int) $stats['total_guests']; ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( $grand_guest_fee_total, 0, '.', ',' ) ); ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( (float) $stats['total_donation'], 0, '.', ',' ) ); ?></td>
                                <td>&#2547;<?php echo esc_html( number_format( (float) $stats['total_collected'], 0, '.', ',' ) ); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
