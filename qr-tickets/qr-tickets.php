<?php
/**
 * Plugin Name: QR Tickets
 * Description: QR Tickets plugin to manage ticket custom post types and settings.
 * Version: 1.0.0
 * Author: Codex AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'QR_TICKETS_VERSION', '1.0.0' );
define( 'QR_TICKETS_PLUGIN_FILE', __FILE__ );
define( 'QR_TICKETS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$includes = glob( QR_TICKETS_PLUGIN_DIR . 'includes/*.php' );

if ( $includes ) {
    sort( $includes );

    foreach ( $includes as $include_file ) {
        require_once $include_file;
    }
}

function qr_tickets_init() {
    if ( class_exists( 'QRTickets_Loader' ) ) {
        $loader = new QRTickets_Loader();
        $loader->init();
    }
}
add_action( 'plugins_loaded', 'qr_tickets_init' );

function qr_tickets_load_textdomain() {
    load_plugin_textdomain( 'qr-tickets', false, dirname( plugin_basename( QR_TICKETS_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', 'qr_tickets_load_textdomain' );

function qr_tickets_activate() {
    if ( ! class_exists( 'QRTickets_CPT' ) ) {
        require_once QR_TICKETS_PLUGIN_DIR . 'includes/class-qr-tickets-cpt.php';
    }

    if ( ! class_exists( 'QRTickets_DirectPay' ) ) {
        require_once QR_TICKETS_PLUGIN_DIR . 'includes/class-qr-tickets-directpay.php';
    }

    if ( ! class_exists( 'QRTickets_Account' ) ) {
        require_once QR_TICKETS_PLUGIN_DIR . 'includes/class-qr-tickets-account.php';
    }

    $cpt = new QRTickets_CPT();
    $cpt->register();
    $cpt->register_post_type();

    if ( class_exists( 'QRTickets_DirectPay' ) && method_exists( 'QRTickets_DirectPay', 'register_rewrite_rules' ) ) {
        QRTickets_DirectPay::register_rewrite_rules();
    }

    if ( class_exists( 'QRTickets_Account' ) && method_exists( 'QRTickets_Account', 'add_endpoint' ) ) {
        QRTickets_Account::add_endpoint();
    }

    if ( class_exists( 'QRTickets_Cron' ) ) {
        QRTickets_Cron::schedule_event();
    }

    flush_rewrite_rules();
}
register_activation_hook( QR_TICKETS_PLUGIN_FILE, 'qr_tickets_activate' );

function qr_tickets_deactivate() {
    if ( class_exists( 'QRTickets_Cron' ) ) {
        QRTickets_Cron::clear_event();
    }

    flush_rewrite_rules();
}
register_deactivation_hook( QR_TICKETS_PLUGIN_FILE, 'qr_tickets_deactivate' );




