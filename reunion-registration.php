<?php
/**
 * Plugin Name: Reunion Registration Form
 * Description: Registration form with manual payment verification (bKash/Nagad/Rocket + Transaction ID), admin Approve/Reject workflow, auto-generated Registration ID (Batch-Number format), email confirmations, SMS notifications, CSV export, and a Summary dashboard.
 * Version: 3.0.0
 * Author: Sejan
 * Text Domain: reunion-registration
 * Domain Path: /languages
 *
 * ---------------------------------------------------------------------
 * ARCHITECTURE NOTE
 * ---------------------------------------------------------------------
 * This file only does three things: define plugin-wide constants, require
 * every module in includes/, and bootstrap them in dependency order. All
 * business logic lives in includes/ — nothing else belongs here.
 *
 * Modules never call each other's methods directly. They communicate only
 * through WordPress hooks (do_action / apply_filters). For example, the
 * approval workflow (class-approval-workflow.php) has no idea the email
 * or SMS classes exist — it just fires 'reunion_reg_after_approve', and
 * class-email-notifications.php + class-sms-gateway.php each independently
 * listen for that hook in their own constructors. This means any module
 * can be disabled, replaced, or extended without touching any other file.
 * ---------------------------------------------------------------------
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'get_plugin_data' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$_reunion_reg_header = get_plugin_data( __FILE__, false, false );
$_reunion_reg_version = ! empty( $_reunion_reg_header['Version'] ) ? $_reunion_reg_header['Version'] : '3.0.0';

// ---------------------------------------------------------------------
// Plugin-wide constants — the single source of truth for every include
// file. Values are byte-identical to the pre-refactor single-file plugin,
// so existing postmeta keys, option names, and nonce actions keep working
// against the same database without any migration.
// REUNION_REG_VERSION is read dynamically from the header above.
// ---------------------------------------------------------------------
define( 'REUNION_REG_VERSION', $_reunion_reg_version );
define( 'REUNION_REG_PLUGIN_FILE', __FILE__ );
define( 'REUNION_REG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REUNION_REG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'REUNION_REG_CPT_SLUG', 'reunion_reg' );
define( 'REUNION_REG_NONCE_ACTION', 'reunion_reg_submit' );
define( 'REUNION_REG_NONCE_FIELD', 'reunion_reg_nonce' );
define( 'REUNION_REG_ADMIN_ACTION_NONCE', 'reunion_reg_admin_action' );
define( 'REUNION_REG_COUNTER_OPTION', 'reunion_reg_id_counter' );
define( 'REUNION_REG_SMS_SETTINGS_OPTION', 'reunion_reg_sms_settings' );
define( 'REUNION_REG_PAYMENT_SETTINGS_OPTION', 'reunion_reg_payment_settings' );

// ---------------------------------------------------------------------
// Includes — order matters. Pure-data / registration modules first
// (schema, CPT, ID generator have no cross-dependencies), then every
// feature module that reads from them.
// ---------------------------------------------------------------------
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-fields-schema.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-registration-cpt.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-registration-id.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-frontend-form.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-form-submission.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-admin-list-table.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-admin-entry-metabox.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-approval-workflow.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-email-notifications.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-sms-gateway.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-payment-settings.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-csv-export.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-summary-report.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-print-reports.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-gate-checkin.php';
require_once REUNION_REG_PLUGIN_DIR . 'includes/class-data-reset.php';

/**
 * Bootstraps the plugin: instantiates every module in dependency order.
 *
 * Reunion_Reg_Fields_Schema and Reunion_Reg_ID_Generator are static-only
 * utility classes with no hooks of their own, so they are never
 * instantiated — every other module registers its own WordPress hooks
 * inside its own constructor the moment it's created here.
 */
final class Reunion_Registration_Plugin {

    /** @var self|null */
    private static $instance = null;

    public static function init() {
        if ( null !== self::$instance ) {
            return self::$instance;
        }
        self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Custom Post Type must be registered before anything else touches it.
        new Reunion_Reg_CPT();

        // Public-facing: the registration form itself + its submission handler.
        new Reunion_Reg_Frontend_Form();
        new Reunion_Reg_Form_Submission();

        // Admin: registrations list table, entry meta boxes, approve/reject workflow.
        new Reunion_Reg_Admin_List_Table();
        new Reunion_Reg_Admin_Entry_Metabox();
        new Reunion_Reg_Approval_Workflow();

        // Notification channels. Each one independently hooks itself onto
        // 'reunion_reg_after_approve' / 'reunion_reg_after_reject' inside its
        // own constructor — the approval workflow above never calls these,
        // and neither of these two classes knows the other exists.
        new Reunion_Reg_Email_Notifications();
        new Reunion_Reg_SMS_Gateway();

        // Settings pages.
        new Reunion_Reg_Payment_Settings();

        // Reporting.
        new Reunion_Reg_CSV_Export();
        new Reunion_Reg_Summary_Report();
        new Reunion_Reg_Print_Reports();
        new Reunion_Reg_Gate_Checkin();

        // Maintenance.
        new Reunion_Reg_Data_Reset();
    }
}

Reunion_Registration_Plugin::init();
