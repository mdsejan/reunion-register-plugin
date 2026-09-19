<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Customizes the Registrations admin list table: columns, sortable columns,
 * Status/Batch filter dropdowns, and the Approve/Reject row actions.
 */
class Reunion_Reg_Admin_List_Table {

    public function __construct() {
        add_filter( 'manage_' . REUNION_REG_CPT_SLUG . '_posts_columns', array( $this, 'set_admin_columns' ) );
        add_action( 'manage_' . REUNION_REG_CPT_SLUG . '_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );
        add_filter( 'manage_edit-' . REUNION_REG_CPT_SLUG . '_sortable_columns', array( $this, 'set_sortable_columns' ) );

        // Status/Batch filter dropdowns on the list table
        add_action( 'restrict_manage_posts', array( $this, 'render_status_filter' ) );
        add_action( 'restrict_manage_posts', array( $this, 'render_batch_filter' ) );
        add_filter( 'parse_query', array( $this, 'filter_by_status' ) );

        // Approve / Reject row actions
        add_filter( 'post_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
    }

    /**
     * Admin: custom columns on the Registrations list table.
     */
    public function set_admin_columns( $columns ) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];

        $new_columns['reunion_reg_id']  = 'Reg ID';
        $new_columns['reunion_status']  = 'Status';
        $new_columns['reunion_name']    = 'Name';
        $new_columns['reunion_phone']   = 'Phone';
        $new_columns['reunion_email']   = 'Email';
        $new_columns['reunion_batch']   = 'Batch';
        $new_columns['reunion_tnx_id']  = 'Transaction ID';
        $new_columns['date']            = 'Submitted';

        return $new_columns;
    }

    public function set_sortable_columns( $columns ) {
        $columns['reunion_status'] = 'reunion_status';
        $columns['reunion_batch']  = 'reunion_batch';
        return $columns;
    }

    public function render_admin_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'reunion_reg_id':
                $reg_id = get_post_meta( $post_id, '_reunion_reg_id', true );
                echo $reg_id ? '<strong>' . esc_html( $reg_id ) . '</strong>' : '—';
                break;
            case 'reunion_status':
                echo Reunion_Reg_CPT::status_badge_html( Reunion_Reg_CPT::get_status( $post_id ) );
                break;
            case 'reunion_name':
                echo esc_html( get_post_meta( $post_id, '_reunion_full_name', true ) );
                break;
            case 'reunion_phone':
                echo esc_html( get_post_meta( $post_id, '_reunion_phone', true ) );
                break;
            case 'reunion_email':
                echo esc_html( get_post_meta( $post_id, '_reunion_email', true ) );
                break;
            case 'reunion_batch':
                echo esc_html( get_post_meta( $post_id, '_reunion_batch', true ) );
                break;
            case 'reunion_tnx_id':
                echo esc_html( get_post_meta( $post_id, '_reunion_tnx_id', true ) );
                break;
        }
    }

    public function filter_row_actions( $actions, $post ) {
        if ( $post->post_type !== REUNION_REG_CPT_SLUG ) {
            return $actions;
        }
        unset( $actions['approve'], $actions['reject'] );
        return $actions;
    }

    /**
     * Status filter dropdown above the list table.
     */
    public function render_status_filter() {
        global $typenow;
        if ( $typenow !== REUNION_REG_CPT_SLUG ) {
            return;
        }

        $statuses = Reunion_Reg_Fields_Schema::get_statuses();
        $current  = isset( $_GET['reunion_status_filter'] ) ? sanitize_text_field( $_GET['reunion_status_filter'] ) : '';
        ?>
        <select name="reunion_status_filter">
            <option value="">All Statuses</option>
            <?php foreach ( $statuses as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Batch filter dropdown above the list table — options are the distinct
     * batch values that actually exist among registrations, not the full
     * static year list, so admins only see batches with real entries.
     */
    public function render_batch_filter() {
        global $typenow;
        if ( $typenow !== REUNION_REG_CPT_SLUG ) {
            return;
        }

        $batches = self::get_used_batches();
        $current = isset( $_GET['reunion_batch_filter'] ) ? sanitize_text_field( $_GET['reunion_batch_filter'] ) : '';
        ?>
        <select name="reunion_batch_filter">
            <option value="">All Batches</option>
            <?php foreach ( $batches as $batch ) : ?>
                <option value="<?php echo esc_attr( $batch ); ?>" <?php selected( $current, $batch ); ?>><?php echo esc_html( $batch ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Distinct batch values that currently have at least one registration,
     * newest-looking first. Public + static so class-csv-export.php can reuse
     * it for its own Batch dropdown without duplicating the query — this is a
     * plain data lookup, not the kind of cross-module business-logic coupling
     * the architecture avoids (that's reserved for do_action/apply_filters).
     */
    public static function get_used_batches() {
        global $wpdb;

        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
             AND pm.meta_value != ''
             AND p.post_type = %s
             AND p.post_status = 'publish'
             ORDER BY pm.meta_value DESC",
            '_reunion_batch',
            REUNION_REG_CPT_SLUG
        ) );

        return $results ? $results : array();
    }

    /**
     * Combines the Status and Batch dropdown filters into a single meta_query
     * so both can be applied together on the admin list table.
     */
    public function filter_by_status( $query ) {
        global $pagenow, $typenow;

        if ( ! is_admin() || $pagenow !== 'edit.php' || $typenow !== REUNION_REG_CPT_SLUG || ! $query->is_main_query() ) {
            return $query;
        }

        $meta_query = array();

        if ( ! empty( $_GET['reunion_status_filter'] ) ) {
            $meta_query[] = array(
                'key'   => '_reunion_status',
                'value' => sanitize_text_field( $_GET['reunion_status_filter'] ),
            );
        }

        if ( ! empty( $_GET['reunion_batch_filter'] ) ) {
            $meta_query[] = array(
                'key'   => '_reunion_batch',
                'value' => sanitize_text_field( $_GET['reunion_batch_filter'] ),
            );
        }

        if ( $meta_query ) {
            if ( count( $meta_query ) > 1 ) {
                $meta_query['relation'] = 'AND';
            }
            $query->query_vars['meta_query'] = $meta_query;
        }

        return $query;
    }
}
