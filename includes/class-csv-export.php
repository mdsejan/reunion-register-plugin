<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CSV Export — adds a submenu page under Registrations with quick-export
 * buttons plus a custom Batch/Status filter form, and the export handler.
 */
class Reunion_Reg_CSV_Export {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_export_page' ) );
        add_action( 'admin_post_reunion_reg_export_csv', array( $this, 'handle_csv_export' ) );
    }

    public function add_export_page() {
        add_submenu_page(
            'edit.php?post_type=' . REUNION_REG_CPT_SLUG,
            'Export Registrations',
            'Export CSV',
            'manage_options',
            'reunion_reg_export',
            array( $this, 'render_export_page' )
        );
    }

    public function render_export_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }
        $export_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=reunion_reg_export_csv' ),
            'reunion_reg_export_csv'
        );
        // Reused from the admin list table's own Batch filter — a plain public
        // data lookup, not the kind of feature coupling the hooks are for.
        $batches  = Reunion_Reg_Admin_List_Table::get_used_batches();
        $statuses = Reunion_Reg_Fields_Schema::get_statuses();
        ?>
        <div class="wrap">
            <h1>Export Registrations</h1>
            <p>Download registration entries as a CSV file (includes Reg ID, status, and all submitted fields). Useful for printing attendance lists or record-keeping.</p>
            <p>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">Download CSV (All Entries)</a>
                <a href="<?php echo esc_url( add_query_arg( 'status', 'approved', $export_url ) ); ?>" class="button">Download CSV (Approved Only)</a>
            </p>

            <hr style="margin:24px 0;">

            <h2>Custom Export</h2>
            <p>Pick a batch and/or a status to export just that slice — both can be combined (e.g. "2010 batch, Approved only").</p>
            <form method="GET" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="reunion_reg_export_csv">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'reunion_reg_export_csv' ) ); ?>">
                <table class="form-table">
                    <tr>
                        <th><label for="export_batch">Batch</label></th>
                        <td>
                            <select id="export_batch" name="batch">
                                <option value="">All Batches</option>
                                <?php foreach ( $batches as $batch ) : ?>
                                    <option value="<?php echo esc_attr( $batch ); ?>"><?php echo esc_html( $batch ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="export_status">Status</label></th>
                        <td>
                            <select id="export_status" name="status">
                                <option value="">All Statuses</option>
                                <?php foreach ( $statuses as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Download Filtered CSV' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Neutralizes CSV/spreadsheet formula injection: if a cell's value starts
     * with a character a spreadsheet app treats as the start of a formula
     * (=, +, -, @, tab, or carriage return), prefix it with a single quote so
     * it's forced to render as plain text instead of being evaluated. Every
     * field on the public form is free-text-ish enough that a malicious
     * applicant could otherwise plant a formula (e.g. "=cmd|'/c calc'!A1")
     * that runs when an admin opens the exported file in Excel/Sheets.
     */
    private function csv_safe_value( $value ) {
        $value = (string) $value;

        if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $value;
        }

        return $value;
    }

    public function handle_csv_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'reunion_reg_export_csv' ) ) {
            wp_die( 'Security check failed. Please go back and try again.' );
        }

        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $batch_filter  = isset( $_GET['batch'] ) ? sanitize_text_field( $_GET['batch'] ) : '';

        $query_args = array(
            'post_type'      => REUNION_REG_CPT_SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
        );

        $meta_query = array();

        if ( $status_filter && array_key_exists( $status_filter, Reunion_Reg_Fields_Schema::get_statuses() ) ) {
            $meta_query[] = array(
                'key'   => '_reunion_status',
                'value' => $status_filter,
            );
        }

        if ( $batch_filter ) {
            $meta_query[] = array(
                'key'   => '_reunion_batch',
                'value' => $batch_filter,
            );
        }

        if ( $meta_query ) {
            if ( count( $meta_query ) > 1 ) {
                $meta_query['relation'] = 'AND';
            }
            $query_args['meta_query'] = $meta_query;
        }

        $entries = get_posts( $query_args );
        $fields  = Reunion_Reg_Fields_Schema::get_fields();

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );

        $filename_parts = array( 'reunion-registrations' );
        if ( $batch_filter ) {
            $filename_parts[] = sanitize_file_name( $batch_filter );
        }
        if ( $status_filter ) {
            $filename_parts[] = sanitize_file_name( $status_filter );
        }
        $filename_parts[] = gmdate( 'Y-m-d' );
        header( 'Content-Disposition: attachment; filename=' . implode( '-', $filename_parts ) . '.csv' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM so Bengali names/text display correctly in Excel
        fwrite( $output, "\xEF\xBB\xBF" );

        $header_row = array( 'Registration ID', 'Status' );
        foreach ( $fields as $field ) {
            $header_row[] = $field['label'];
        }
        $header_row[] = 'Submitted At';
        $header_row[] = 'Approved At';
        fputcsv( $output, $header_row );

        foreach ( $entries as $entry ) {
            $status_key   = Reunion_Reg_CPT::get_status( $entry->ID );
            $statuses_map = Reunion_Reg_Fields_Schema::get_statuses();

            $row = array(
                get_post_meta( $entry->ID, '_reunion_reg_id', true ),
                isset( $statuses_map[ $status_key ] ) ? $statuses_map[ $status_key ] : 'Pending',
            );
            foreach ( $fields as $key => $field ) {
                if ( 'file' === ( $field['type'] ?? '' ) ) {
                    $url = get_post_meta( $entry->ID, '_reunion_' . $key . '_url', true );
                    if ( ! $url ) {
                        $aid = (int) get_post_meta( $entry->ID, '_reunion_' . $key . '_id', true );
                        if ( $aid ) { $url = wp_get_attachment_url( $aid ) ?: ''; }
                    }
                    if ( ! $url ) { $url = get_post_meta( $entry->ID, '_reunion_' . $key, true ); }
                    $row[] = $url ? esc_url_raw( $url ) : '';
                } else {
                    $row[] = get_post_meta( $entry->ID, '_reunion_' . $key, true );
                }
            }
            $row[] = get_post_meta( $entry->ID, '_reunion_submitted_at', true );
            $row[] = get_post_meta( $entry->ID, '_reunion_approved_at', true );

            $row = array_map( array( $this, 'csv_safe_value' ), $row );

            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }
}
