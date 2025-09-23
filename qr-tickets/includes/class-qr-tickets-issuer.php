<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Issuer {

    const META_TICKET_ID = '_qr_ticket_id';

    public function register() {
        add_action( 'woocommerce_payment_complete', array( $this, 'maybe_issue_ticket' ), 20 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_issue_ticket' ), 20 );
        add_action( 'woocommerce_thankyou', array( $this, 'redirect_to_ticket' ), 1 );
    }

    public function maybe_issue_ticket( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $existing_ticket_id = (int) $order->get_meta( self::META_TICKET_ID );

        if ( $existing_ticket_id ) {
            return;
        }

        $type = sanitize_key( (string) $order->get_meta( '_qr_ticket_type' ) );

        if ( ! in_array( $type, array( '30m', '60m' ), true ) ) {
            return;
        }

        list( $email, $email_source ) = $this->determine_email( $order );

        $current_email = sanitize_email( $order->get_billing_email() );
        $email_changed = false;

        if ( $email && $email !== $current_email ) {
            $order->set_billing_email( $email );
            $email_changed = true;
        }

        $order->add_order_note( sprintf( 'Email source: %s', $email_source ) );

        $ticket_id = $this->create_ticket_post( $order, $type, $email );

        if ( ! $ticket_id ) {
            if ( $email_changed ) {
                $order->save();
            }

            $order->add_order_note( 'Ticket issuance failed: ticket post not created.' );
            return;
        }

        $order->update_meta_data( self::META_TICKET_ID, $ticket_id );
        $order->save();

        $sync_status = QRTickets_DPMK_Service::maybe_sync_ticket( $ticket_id, $order, $type );

        if ( 'stub' === $sync_status ) {
            $this->notify_municipality( $order, $ticket_id, $type, $email );
        }
    }

    public function redirect_to_ticket( $order_id ) {
        if ( headers_sent() ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $ticket_id = (int) $order->get_meta( self::META_TICKET_ID );

        if ( ! $ticket_id ) {
            return;
        }

        $permalink = get_permalink( $ticket_id );

        if ( ! $permalink ) {
            return;
        }

        wp_safe_redirect( $permalink );
        exit;
    }

    private function determine_email( WC_Order $order ) {
        $billing_email = sanitize_email( $order->get_billing_email() );

        if ( $billing_email ) {
            return array( $billing_email, 'billing' );
        }

        $meta_sources = array(
            '_barion_payer_email'   => 'barion_meta:_barion_payer_email',
            '_barion_email'         => 'barion_meta:_barion_email',
            '_payment_method_email' => 'meta:_payment_method_email',
            'barion_payer_email'    => 'barion_meta:barion_payer_email',
        );

        foreach ( $meta_sources as $key => $source ) {
            $value = $order->get_meta( $key, true );

            if ( ! $value ) {
                continue;
            }

            $email = sanitize_email( is_scalar( $value ) ? (string) $value : '' );

            if ( $email ) {
                return array( $email, $source );
            }
        }

        $response_meta  = $order->get_meta( '_barion_response', true );
        $response_email = $this->extract_email_from_value( $response_meta );

        if ( $response_email ) {
            return array( $response_email, 'barion_response' );
        }

        foreach ( $order->get_meta_data() as $meta ) {
            $value = isset( $meta->value ) ? $meta->value : null;

            if ( null === $value ) {
                continue;
            }

            $candidate = $this->extract_email_from_value( $value );

            if ( $candidate ) {
                $key = isset( $meta->key ) ? $meta->key : '';
                return array( $candidate, $key ? 'meta:' . $key : 'meta' );
            }
        }

        return array( '', 'absent' );
    }

    private function extract_email_from_value( $value ) {
        if ( is_string( $value ) ) {
            $email = sanitize_email( $value );

            if ( $email ) {
                return $email;
            }

            $decoded = json_decode( $value, true );

            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $this->search_email_in_array( $decoded );
            }

            return '';
        }

        if ( is_array( $value ) || is_object( $value ) ) {
            return $this->search_email_in_array( (array) $value );
        }

        return '';
    }

    private function search_email_in_array( array $data ) {
        foreach ( $data as $value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                $found = $this->search_email_in_array( (array) $value );

                if ( $found ) {
                    return $found;
                }
            } elseif ( is_string( $value ) ) {
                $maybe_email = sanitize_email( $value );

                if ( $maybe_email ) {
                    return $maybe_email;
                }
            }
        }

        return '';
    }

    private function create_ticket_post( WC_Order $order, $type, $email ) {
        $code = $this->generate_unique_code();

        if ( ! $code ) {
            return 0;
        }

        $user_id = (int) $order->get_customer_id();

        if ( ! $email && $user_id ) {
            $user = get_user_by( 'id', $user_id );

            if ( $user instanceof WP_User ) {
                $email = sanitize_email( $user->user_email );
            }
        }

        $valid_from = time();
        $duration   = '60m' === $type ? 60 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
        $valid_to   = $valid_from + $duration;

        $gross_amount = 0.0;
        $line_items   = $order->get_items( 'line_item' );

        if ( ! empty( $line_items ) ) {
            $first_item   = reset( $line_items );
            $item_total   = (float) $first_item->get_total();
            $item_tax     = (float) $first_item->get_total_tax();
            $gross_amount = $item_total + $item_tax;
        }

        $amount_cents = (int) round( $gross_amount * 100 );
        $currency     = $order->get_currency();

        $ticket_id = wp_insert_post(
            array(
                'post_type'   => 'ticket',
                'post_status' => 'publish',
                'post_title'  => sprintf( 'Ticket %s', $code ),
                'post_author' => $user_id,
            ),
            true
        );

        if ( is_wp_error( $ticket_id ) ) {
            return 0;
        }

        update_post_meta( $ticket_id, '_qr_code', $code );
        update_post_meta( $ticket_id, '_type', $type );
        update_post_meta( $ticket_id, '_qr_ticket_type', $type );
        update_post_meta( $ticket_id, '_valid_from', $valid_from );
        update_post_meta( $ticket_id, '_valid_to', $valid_to );
        update_post_meta( $ticket_id, '_status', 'active' );
        update_post_meta( $ticket_id, '_order_id', $order->get_id() );
        update_post_meta( $ticket_id, '_user_id', $user_id );
        update_post_meta( $ticket_id, '_email', $email );
        update_post_meta( $ticket_id, '_amount_cents', $amount_cents );
        update_post_meta( $ticket_id, '_currency', $currency );
        update_post_meta( $ticket_id, '_provider_attempts', 0 );
        update_post_meta( $ticket_id, '_sync_status', 'pending' );

        $qr_attachment = $this->generate_qr_attachment( $ticket_id, $code );

        if ( $qr_attachment ) {
            update_post_meta( $ticket_id, '_qr_png_url', wp_get_attachment_url( $qr_attachment ) );
        }

        $order->add_order_note( sprintf( 'Ticket issued: #%d, code %s', $ticket_id, $code ) );

        return $ticket_id;
    }

    private function generate_unique_code() {
        $alphabet = str_split( 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789' );

        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $raw = '';

            for ( $i = 0; $i < 8; $i++ ) {
                $index = ord( random_bytes( 1 ) ) % count( $alphabet );
                $raw  .= $alphabet[ $index ];
            }

            $code = strtoupper( substr( $raw, 0, 3 ) . '-' . substr( $raw, 3, 3 ) . '-' . substr( $raw, 6, 2 ) );

            $existing = new WP_Query(
                array(
                    'post_type'      => 'ticket',
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'meta_key'       => '_qr_code',
                    'meta_value'     => $code,
                    'fields'         => 'ids',
                )
            );

            if ( empty( $existing->posts ) ) {
                return $code;
            }
        }

        return '';
    }

    private function generate_qr_attachment( $ticket_id, $code ) {
        $file_name = sprintf( 'qr-ticket-%d.png', $ticket_id );
        $png_data  = $this->request_qr_png( $code );

        if ( empty( $png_data ) ) {
            $png_data = $this->generate_placeholder_png( $code );
        }

        if ( empty( $png_data ) ) {
            return 0;
        }

        $upload = wp_upload_bits( $file_name, null, $png_data );

        if ( ! empty( $upload['error'] ) ) {
            return 0;
        }

        $file_path = $upload['file'];
        $filetype  = wp_check_filetype( $file_name, null );

        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => sprintf( 'QR Ticket %d', $ticket_id ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment( $attachment, $file_path, $ticket_id );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        set_post_thumbnail( $ticket_id, $attachment_id );

        return $attachment_id;
    }

    private function request_qr_png( $code ) {
        $api_url = add_query_arg(
            array(
                'size' => '512x512',
                'data' => $code,
            ),
            'https://api.qrserver.com/v1/create-qr-code/'
        );

        $response = wp_remote_get(
            $api_url,
            array(
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $status_code ) {
            return '';
        }

        return wp_remote_retrieve_body( $response );
    }

    private function generate_placeholder_png( $code ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            return '';
        }

        $size  = 512;
        $image = imagecreatetruecolor( $size, $size );
        $white = imagecolorallocate( $image, 255, 255, 255 );
        $black = imagecolorallocate( $image, 0, 0, 0 );
        imagefill( $image, 0, 0, $white );

        $hash = md5( $code );
        $grid = 29;
        $cell = (int) floor( ( $size - 40 ) / $grid );

        for ( $y = 0; $y < $grid; $y++ ) {
            for ( $x = 0; $x < $grid; $x++ ) {
                $index = ( $y * $grid + $x ) % strlen( $hash );
                $bit   = hexdec( $hash[ $index ] ) % 2;

                if ( $bit ) {
                    imagefilledrectangle(
                        $image,
                        20 + $x * $cell,
                        20 + $y * $cell,
                        20 + ( $x + 1 ) * $cell,
                        20 + ( $y + 1 ) * $cell,
                        $black
                    );
                }
            }
        }

        ob_start();
        imagepng( $image );
        $data = ob_get_clean();
        imagedestroy( $image );

        return $data;
    }

    private function notify_municipality( WC_Order $order, $ticket_id, $type, $email ) {
        $settings = get_option( 'qr_tickets_settings', array() );
        $endpoint = isset( $settings['muni_stub_url'] ) ? esc_url_raw( $settings['muni_stub_url'] ) : '';

        if ( empty( $endpoint ) ) {
            return;
        }

        $valid_from = (int) get_post_meta( $ticket_id, '_valid_from', true );
        $valid_to   = (int) get_post_meta( $ticket_id, '_valid_to', true );
        $code       = (string) get_post_meta( $ticket_id, '_qr_code', true );

        $payload = array(
            'code'       => $code,
            'type'       => $type,
            'valid_from' => $valid_from,
            'valid_to'   => $valid_to,
            'order_id'   => $order->get_id(),
            'email'      => $email,
        );

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 5,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $payload ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( sprintf( 'Municipality stub error: %s', $response->get_error_message() ) );
            return;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $body        = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 200 ) : substr( $body, 0, 200 );

        $order->add_order_note( sprintf( 'Municipality stub response: HTTP %d %s', $status_code, $body ) );
    }
}
