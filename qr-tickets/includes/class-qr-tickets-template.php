<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Template {

    public function register() {
        add_filter( 'single_template', array( $this, 'filter_single_template' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_qr_ticket_send_email', array( $this, 'handle_send_email' ) );
        add_action( 'wp_ajax_nopriv_qr_ticket_send_email', array( $this, 'handle_send_email' ) );
    }

    public function filter_single_template( $template ) {
        if ( is_singular( 'ticket' ) ) {
            $plugin_template = trailingslashit( QR_TICKETS_PLUGIN_DIR ) . 'templates/single-ticket.php';

            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        return $template;
    }

    public function enqueue_assets() {
        if ( ! is_singular( 'ticket' ) ) {
            return;
        }

        wp_enqueue_style(
            'qr-tickets-ticket',
            plugins_url( 'assets/qr-ticket.css', QR_TICKETS_PLUGIN_FILE ),
            array(),
            QR_TICKETS_VERSION
        );

        wp_enqueue_script(
            'qr-tickets-ticket',
            plugins_url( 'assets/js/qr-ticket.js', QR_TICKETS_PLUGIN_FILE ),
            array( 'jquery' ),
            QR_TICKETS_VERSION,
            true
        );

        wp_localize_script(
            'qr-tickets-ticket',
            'QRTicketsTicket',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'qr_ticket_send_email' ),
                'copySuccess' => __( 'Copied', 'qr-tickets' ),
                'copyError'   => __( 'Copy failed', 'qr-tickets' ),
            )
        );
    }

    public function handle_send_email() {
        check_ajax_referer( 'qr_ticket_send_email', 'nonce' );

        $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
        $email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        if ( ! $ticket_id || 'ticket' !== get_post_type( $ticket_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ticket.', 'qr-tickets' ) ) );
        }

        if ( ! $email ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email.', 'qr-tickets' ) ) );
        }

        $status     = get_post_meta( $ticket_id, '_status', true );
        $valid_to   = (int) get_post_meta( $ticket_id, '_valid_to', true );
        $is_expired = ( 'expired' === $status ) || ( $valid_to && time() > $valid_to );

        if ( $is_expired ) {
            wp_send_json_error( array( 'message' => __( 'Ticket expired.', 'qr-tickets' ) ) );
        }

        $send_count = (int) get_post_meta( $ticket_id, '_email_send_count', true );

        if ( $send_count >= 2 ) {
            wp_send_json_error( array( 'message' => __( 'Email was already sent.', 'qr-tickets' ) ) );
        }

        $order_id = (int) get_post_meta( $ticket_id, '_order_id', true );
        $order    = $order_id ? wc_get_order( $order_id ) : null;

        $subject = __( 'Your QR Ticket', 'qr-tickets' );
        $link    = get_permalink( $ticket_id );
        $body    = sprintf(
            "%s\n%s",
            __( 'Thank you for your purchase. Your ticket is available here:', 'qr-tickets' ),
            $link
        );

        $sent = wp_mail( $email, $subject, $body );

        if ( ! $sent ) {
            wp_send_json_error( array( 'message' => __( 'Failed to send email.', 'qr-tickets' ) ) );
        }

        update_post_meta( $ticket_id, '_email_send_count', $send_count + 1 );

        $existing_email = get_post_meta( $ticket_id, '_email', true );

        if ( empty( $existing_email ) ) {
            update_post_meta( $ticket_id, '_email', $email );
        }

        if ( $order instanceof WC_Order ) {
            $order->add_order_note( sprintf( 'Ticket emailed to %s', $email ) );
        }

        wp_send_json_success( array( 'message' => __( 'Email sent.', 'qr-tickets' ) ) );
    }
}