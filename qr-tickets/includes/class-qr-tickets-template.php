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

        $ticket_id      = get_queried_object_id();
        $ticket_payload = $this->build_ticket_payload( $ticket_id );

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

        wp_enqueue_script(
            'qr-tickets-ticket-save',
            plugins_url( 'assets/js/ticket-save.js', QR_TICKETS_PLUGIN_FILE ),
            array( 'qr-tickets-ticket' ),
            QR_TICKETS_VERSION,
            true
        );

        wp_localize_script(
            'qr-tickets-ticket',
            'QRTicketsTicket',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'qr_ticket_send_email' ),
                'copySuccess' => __( 'Copied', 'qr-tickets' ),
                'copyError'   => __( 'Copy failed', 'qr-tickets' ),
                'ticket'      => $ticket_payload,
                'i18n'        => array(
                    'savePrompt'      => __( 'Save ticket on this device', 'qr-tickets' ),
                    'saveSuccess'     => __( 'Ticket saved for offline use.', 'qr-tickets' ),
                    'saveError'       => __( 'Unable to save ticket. Please try again.', 'qr-tickets' ),
                    'saving'          => __( 'Saving...', 'qr-tickets' ),
                    'alreadySaved'    => __( 'Ticket already saved on this device.', 'qr-tickets' ),
                    'installPrompt'   => __( 'Install TicketKE', 'qr-tickets' ),
                    'installError'    => __( 'Unable to start installation.', 'qr-tickets' ),
                    'storageMissing'  => __( 'Offline storage is not supported in this browser.', 'qr-tickets' ),
                ),
                'cache'       => array(
                    'qr' => 'qr-tickets-qr',
                ),
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

    private function build_ticket_payload( $ticket_id ) {
        if ( ! $ticket_id ) {
            return array();
        }

        $ticket = get_post( $ticket_id );

        if ( ! $ticket || 'ticket' !== $ticket->post_type ) {
            return array();
        }

        $code        = (string) get_post_meta( $ticket_id, '_qr_code', true );
        $type        = (string) get_post_meta( $ticket_id, '_type', true );
        $valid_from  = (int) get_post_meta( $ticket_id, '_valid_from', true );
        $valid_to    = (int) get_post_meta( $ticket_id, '_valid_to', true );
        $status      = (string) get_post_meta( $ticket_id, '_status', true );
        $email       = (string) get_post_meta( $ticket_id, '_email', true );
        $sync_status = (string) get_post_meta( $ticket_id, '_sync_status', true );
        $sync_error  = (string) get_post_meta( $ticket_id, '_provider_last_error', true );
        $qr_url      = (string) get_post_meta( $ticket_id, '_qr_png_url', true );
        $provider_qr = (string) get_post_meta( $ticket_id, '_provider_qr', true );

        if ( $provider_qr ) {
            if ( ( function_exists( 'str_starts_with' ) && str_starts_with( $provider_qr, 'data:' ) ) || 0 === strpos( $provider_qr, 'data:' ) ) {
                $qr_url = $provider_qr;
            } else {
                $qr_url = 'data:image/png;base64,' . $provider_qr;
            }
        }

        $structured = array(
            'id'                  => $ticket_id,
            'title'               => get_the_title( $ticket_id ),
            'permalink'           => get_permalink( $ticket_id ),
            'code'                => $code,
            'type'                => $type,
            'status'              => $status,
            'valid_from'          => $valid_from,
            'valid_to'            => $valid_to,
            'email'               => $email,
            'sync_status'         => $sync_status,
            'provider_last_error' => $sync_error,
        );

        if ( ! empty( $ticket->post_content ) ) {
            $structured['content'] = apply_filters( 'the_content', $ticket->post_content );
        }

        return array(
            'id'        => $ticket_id,
            'structured' => $structured,
            'qrUrl'     => $qr_url,
        );
    }
}
