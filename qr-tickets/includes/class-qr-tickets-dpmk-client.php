<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_DPMK_Client {

    private $base_url;
    private $client_id;
    private $client_secret;

    public function __construct() {
        $this->base_url      = $this->prepare_base_url( get_option( 'qr_dpmk_base_url', 'https://api.vcl.dpmk.sk' ) );
        $this->client_id     = trim( (string) get_option( 'qr_dpmk_client_id', '' ) );
        $this->client_secret = trim( (string) get_option( 'qr_dpmk_client_secret', '' ) );
    }

    public function is_configured() {
        return ( ! empty( $this->base_url ) && ! empty( $this->client_id ) && ! empty( $this->client_secret ) );
    }

    public function get_token() {
        if ( ! $this->is_configured() ) {
            return false;
        }

        $cached = get_option( 'qr_dpmk_token', array() );
        $now    = time();

        if ( isset( $cached['access_token'], $cached['expires_at'] ) && ( $cached['expires_at'] - 30 ) > $now ) {
            return $cached['access_token'];
        }

        $response = wp_remote_post(
            trailingslashit( $this->base_url ) . 'oauth/token',
            array(
                'timeout' => 8,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode(
                    array(
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $this->client_id,
                        'client_secret' => $this->client_secret,
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) || empty( $body['expires_in'] ) ) {
            return false;
        }

        $token_data = array(
            'access_token' => $body['access_token'],
            'expires_at'   => $now + (int) $body['expires_in'],
        );

        update_option( 'qr_dpmk_token', $token_data, false );

        return $token_data['access_token'];
    }

    public function post( $path, $body ) {
        if ( ! $this->is_configured() ) {
            return array(
                'ok'    => false,
                'code'  => 0,
                'body'  => null,
                'error' => 'DPMK client not configured.',
            );
        }

        $token = $this->get_token();

        if ( ! $token ) {
            return array(
                'ok'    => false,
                'code'  => 0,
                'body'  => null,
                'error' => 'Unable to retrieve token.',
            );
        }

        $url = trailingslashit( $this->base_url ) . ltrim( $path, '/' );

        $attempts      = 0;
        $max_attempts  = 3;
        $refreshed     = false;
        $last_error    = '';
        $last_code     = 0;
        $decoded_body  = null;

        while ( $attempts < $max_attempts ) {
            $attempts++;

            $response = wp_remote_post(
                $url,
                array(
                    'timeout' => 8,
                    'headers' => array(
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                        'Accept'        => 'application/json',
                    ),
                    'body'    => wp_json_encode( $body ),
                )
            );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                continue;
            }

            $last_code = (int) wp_remote_retrieve_response_code( $response );
            $body_raw  = wp_remote_retrieve_body( $response );
            $decoded_body = json_decode( $body_raw, true );

            if ( 401 === $last_code && ! $refreshed ) {
                delete_option( 'qr_dpmk_token' );
                $token = $this->get_token();
                $refreshed = true;
                if ( $token ) {
                    continue;
                }
                $last_error = 'Authentication failed.';
                break;
            }

            if ( $last_code >= 500 && $attempts < $max_attempts ) {
                $last_error = 'Server error ' . $last_code;
                continue;
            }

            $ok = ( $last_code >= 200 && $last_code < 300 );

            return array(
                'ok'    => $ok,
                'code'  => $last_code,
                'body'  => $decoded_body,
                'error' => $ok ? '' : ( isset( $decoded_body['message'] ) ? (string) $decoded_body['message'] : 'Request failed.' ),
            );
        }

        return array(
            'ok'    => false,
            'code'  => $last_code,
            'body'  => $decoded_body,
            'error' => $last_error ? $last_error : 'Request failed.',
        );
    }

    private function prepare_base_url( $url ) {
        $url = trim( (string) $url );

        if ( empty( $url ) ) {
            return '';
        }

        return untrailingslashit( esc_url_raw( $url ) );
    }
}