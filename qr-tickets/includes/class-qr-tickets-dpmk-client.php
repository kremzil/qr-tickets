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

    public function get( $path, $query_args = array() ) {
        return $this->request(
            'GET',
            $path,
            array(
                'query'        => $query_args,
                'timeout'      => 5,
                'max_attempts' => 2,
            )
        );
    }

    public function post( $path, $body ) {
        return $this->request(
            'POST',
            $path,
            array(
                'body'         => $body,
                'timeout'      => 8,
                'max_attempts' => 3,
            )
        );
    }

    private function request( $method, $path, $args = array() ) {
        $body         = isset( $args['body'] ) ? $args['body'] : null;
        $query        = isset( $args['query'] ) ? (array) $args['query'] : array();
        $timeout      = isset( $args['timeout'] ) ? (int) $args['timeout'] : 8;
        $max_attempts = isset( $args['max_attempts'] ) ? (int) $args['max_attempts'] : 3;

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

        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $attempts     = 0;
        $refreshed    = false;
        $last_error   = '';
        $last_code    = 0;
        $decoded_body = null;

        while ( $attempts < $max_attempts ) {
            $attempts++;

            $request_args = array(
                'timeout' => $timeout,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ),
            );

            if ( 'POST' === $method ) {
                $request_args['headers']['Content-Type'] = 'application/json';
                $request_args['body'] = wp_json_encode( $body );
                $response = wp_remote_post( $url, $request_args );
            } else {
                $response = wp_remote_get( $url, $request_args );
            }

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();

                if ( $attempts >= $max_attempts ) {
                    break;
                }

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
                    $attempts--;
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
            $error_message = '';

            if ( ! $ok ) {
                if ( isset( $decoded_body['message'] ) ) {
                    $error_message = (string) $decoded_body['message'];
                } elseif ( isset( $decoded_body['error'] ) && is_scalar( $decoded_body['error'] ) ) {
                    $error_message = (string) $decoded_body['error'];
                } elseif ( $last_error ) {
                    $error_message = $last_error;
                } else {
                    $error_message = 'Request failed.';
                }
            }

            return array(
                'ok'    => $ok,
                'code'  => $last_code,
                'body'  => $decoded_body,
                'error' => $ok ? '' : $error_message,
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