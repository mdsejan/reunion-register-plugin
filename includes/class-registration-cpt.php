<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the Custom Post Type used as storage for every registration
 * entry, and provides the status helpers (get_status / status_badge_html)
 * that every other admin-facing module relies on.
 */
class Reunion_Reg_CPT {

    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
    }

    /**
     * Register the Custom Post Type used as storage for entries.
     */
    public function register_post_type() {
        register_post_type( REUNION_REG_CPT_SLUG, array(
            'labels' => array(
                'name'          => 'Registrations',
                'singular_name' => 'Registration',
                'menu_name'     => 'Reunion Registrations',
                'all_items'     => 'All Registrations',
            ),
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'menu_icon'          => 'dashicons-groups',
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'supports'           => array( 'title' ),
            'has_archive'        => false,
            'exclude_from_search' => true,
            'show_in_rest'       => false,
        ) );
    }

    public function disable_block_editor( $use_block_editor, $post_type ) {
        if ( $post_type === REUNION_REG_CPT_SLUG ) {
            return false;
        }
        return $use_block_editor;
    }

    public static function get_status( $post_id ) {
        $status = get_post_meta( $post_id, '_reunion_status', true );
        return $status ? $status : 'pending';
    }

    public static function status_badge_html( $status ) {
        $labels = Reunion_Reg_Fields_Schema::get_statuses();
        $label  = isset( $labels[ $status ] ) ? $labels[ $status ] : 'Pending';

        $colors = array(
            'pending'  => array( '#fff8e1', '#8a6d3b' ),
            'approved' => array( '#e6f4ea', '#1e4620' ),
            'rejected' => array( '#fdecea', '#611a15' ),
        );
        $c = isset( $colors[ $status ] ) ? $colors[ $status ] : $colors['pending'];

        return sprintf(
            '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
            esc_attr( $c[0] ),
            esc_attr( $c[1] ),
            esc_html( $label )
        );
    }
}
