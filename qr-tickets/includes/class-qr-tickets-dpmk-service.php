<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_DPMK_Service {

    public static function is_test_mode() {
        return (bool) get_option( 'qr_dpmk_test_mode', '' );
    }

    public static function ensure_ready() {
        if ( self::is_test_mode() ) {
            return array( 'ok' => true );
        }

        $client = new QRTickets_DPMK_Client();

        if ( ! $client->is_configured() ) {
            return array(
                'ok'      => false,
                'message' => __( 'Municipality provider is not configured. Please contact support.', 'qr-tickets' ),
            );
        }

        $token = $client->get_token();

        if ( ! $token ) {
            return array(
                'ok'      => false,
                'message' => __( 'Municipality provider is currently unavailable. Please try again later.', 'qr-tickets' ),
            );
        }

        return array( 'ok' => true );
    }

    public static function maybe_sync_ticket( $ticket_id, WC_Order $order, $type ) {
        update_post_meta( $ticket_id, '_provider', 'DPMK' );

        if ( self::is_test_mode() ) {
            update_post_meta( $ticket_id, '_sync_status', 'stub' );
            update_post_meta( $ticket_id, '_provider_attempts', 0 );
            $order->add_order_note( 'DPMK test mode: stub response applied.' );
            return 'stub';
        }

        $client = new QRTickets_DPMK_Client();

        if ( ! $client->is_configured() ) {
            update_post_meta( $ticket_id, '_sync_status', 'failed' );
            update_post_meta( $ticket_id, '_provider_last_error', 'DPMK configuration missing.' );
            $order->add_order_note( 'DPMK BUY failed: configuration missing.' );
            return 'failed';
        }

        $provider_ticket_id = self::map_ticket_id( $type );

        if ( ! $provider_ticket_id ) {
            update_post_meta( $ticket_id, '_sync_status', 'failed' );
            update_post_meta( $ticket_id, '_provider_last_error', 'Ticket ID mapping missing.' );
            $order->add_order_note( 'DPMK BUY failed: ticket id missing for type ' . $type );
            return 'failed';
        }

        $device_qr = $order->get_meta( '_qr_device_qr', true );
        $payload   = array(
            'ticket_id' => (int) $provider_ticket_id,
            'quantity'  => 1,
        );

        if ( get_option( 'qr_dpmk_enable_delayless_with_device' ) && $device_qr ) {
            $payload['qrcode'] = $device_qr;
        }

        update_post_meta( $ticket_id, '_sync_status', 'pending' );

        $response = $client->post( '/api/ticket/buy', $payload );
        $attempts = (int) get_post_meta( $ticket_id, '_provider_attempts', true );

        if ( $response['ok'] && ! empty( $response['body']['data'][0] ) ) {
            $data = $response['body']['data'][0];

            $code            = isset( $data['code'] ) ? (string) $data['code'] : '';
            $qr_content      = isset( $data['qr_content'] ) ? (string) $data['qr_content'] : '';
            $provider_ticket = isset( $data['ticket_id'] ) ? (int) $data['ticket_id'] : (int) $provider_ticket_id;
            $valid_from      = isset( $data['valid_from'] ) ? strtotime( $data['valid_from'] ) : false;
            $valid_to        = isset( $data['valid_to'] ) ? strtotime( $data['valid_to'] ) : false;

            if ( false === $valid_from ) {
                $valid_from = time();
            }

            if ( false === $valid_to ) {
                $valid_to = $valid_from + MINUTE_IN_SECONDS * ( '60m' === $type ? 60 : 30 );
            }

            update_post_meta( $ticket_id, '_provider_ticket_id', $provider_ticket );
            update_post_meta( $ticket_id, '_provider_code', $code );
            update_post_meta( $ticket_id, '_provider_qr', $qr_content );
            update_post_meta( $ticket_id, '_sync_status', 'ok' );
            update_post_meta( $ticket_id, '_provider_attempts', $attempts + 1 );
            delete_post_meta( $ticket_id, '_provider_last_error' );

            if ( $code ) {
                update_post_meta( $ticket_id, '_qr_code', $code );
            }

            update_post_meta( $ticket_id, '_valid_from', (int) $valid_from );
            update_post_meta( $ticket_id, '_valid_to', (int) $valid_to );

            $qr_src = self::prepare_qr_src( $qr_content );

            if ( $qr_src ) {
                update_post_meta( $ticket_id, '_qr_png_url', $qr_src );
            }

            $order->add_order_note(
                sprintf(
                    'DPMK BUY ok: ticket_id=%d, code=%s, valid_to=%s',
                    $provider_ticket,
                    $code,
                    $valid_to ? wp_date( 'Y-m-d H:i', $valid_to ) : 'n/a'
                )
            );

            return 'ok';
        }

        update_post_meta( $ticket_id, '_sync_status', 'failed' );
        update_post_meta( $ticket_id, '_provider_attempts', $attempts + 1 );

        $error_msg = $response['error'];
        if ( empty( $error_msg ) && ! empty( $response['body']['message'] ) ) {
            $error_msg = (string) $response['body']['message'];
        }

        update_post_meta( $ticket_id, '_provider_last_error', substr( $error_msg, 0, 180 ) );

        $order->add_order_note(
            sprintf(
                'DPMK BUY failed: http=%s error=%s',
                $response['code'],
                $error_msg ? $error_msg : 'unknown'
            )
        );

        return 'failed';
    }

    public static function retry_ticket( $ticket_id ) {
        if ( self::is_test_mode() ) {
            return false;
        }

        $order_id = (int) get_post_meta( $ticket_id, '_order_id', true );

        if ( ! $order_id ) {
            return false;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return false;
        }

        $type = get_post_meta( $ticket_id, '_qr_ticket_type', true );

        if ( ! $type ) {
            $type = get_post_meta( $ticket_id, '_type', true );
        }

        if ( ! $type ) {
            return false;
        }

        return self::maybe_sync_ticket( $ticket_id, $order, $type );
    }

    public static function map_ticket_id( $type ) {
        switch ( strtolower( $type ) ) {
            case '30m':
                return (int) get_option( 'qr_dpmk_ticket_30_id', 0 );
            case '60m':
                return (int) get_option( 'qr_dpmk_ticket_60_id', 0 );
            default:
                return 0;
        }
    }

    private static function prepare_qr_src( $qr_content ) {
        if ( empty( $qr_content ) ) {
            return '';
        }

        if ( str_starts_with( $qr_content, 'data:' ) ) {
            return $qr_content;
        }

        return 'data:image/png;base64,' . $qr_content;
    }
}