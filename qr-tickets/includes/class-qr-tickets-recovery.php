<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Recovery {

    const COOKIE_NAME = 'qr_ticket_tokens';
    const MAX_TOKENS  = 5;
    const EXPIRATION  = WEEK_IN_SECONDS;

    public function register() {
        add_action( 'template_redirect', array( $this, 'maybe_set_ticket_cookie' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function maybe_set_ticket_cookie() {
        if ( ! is_singular( 'ticket' ) ) {
            return;
        }

        $ticket_id = get_queried_object_id();

        if ( ! $ticket_id ) {
            return;
        }

        $token = get_post_meta( $ticket_id, '_qr_recovery_token', true );

        if ( empty( $token ) ) {
            $token = wp_generate_uuid4();
            update_post_meta( $ticket_id, '_qr_recovery_token', $token );
        }

        $expires_at = (int) get_post_meta( $ticket_id, '_qr_recovery_expires', true );
        $now        = time();

        if ( $expires_at <= $now ) {
            $expires_at = $now + self::EXPIRATION;
            update_post_meta( $ticket_id, '_qr_recovery_expires', $expires_at );
        }

        $cookie_tokens = $this->get_cookie_tokens();
        $filtered      = array();

        foreach ( $cookie_tokens as $entry ) {
            if ( empty( $entry['token'] ) || $entry['token'] === $token ) {
                continue;
            }

            $filtered[] = $entry;
        }

        $filtered[] = array(
            'token'   => $token,
            'ticket'  => $ticket_id,
            'updated' => $now,
        );

        if ( count( $filtered ) > self::MAX_TOKENS ) {
            $filtered = array_slice( $filtered, - self::MAX_TOKENS );
        }

        $this->set_cookie_tokens( $filtered, $expires_at );
    }

    private function get_cookie_tokens() {
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return array();
        }

        $raw = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );

        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) {
            return array();
        }

        return array_values( array_filter( $data, static function ( $entry ) {
            return is_array( $entry ) && ! empty( $entry['token'] );
        } ) );
    }

    private function set_cookie_tokens( array $tokens, $expires_at ) {
        $value = wp_json_encode( $tokens );

        if ( ! headers_sent() ) {
            $domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : parse_url( home_url( '/' ), PHP_URL_HOST );

            $params = array(
                'expires'  => (int) $expires_at,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            );

            if ( ! empty( $domain ) ) {
                $params['domain'] = $domain;
            }

            setcookie( self::COOKIE_NAME, $value, $params );
        }

        $_COOKIE[ self::COOKIE_NAME ] = $value;
    }

    public function register_rest_routes() {
        register_rest_route(
            'qr-tickets/v1',
            '/recover',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_recover_ticket' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'token' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );
    }

    public function rest_recover_ticket( WP_REST_Request $request ) {
        $token = $request->get_param( 'token' );

        if ( empty( $token ) ) {
            return new WP_Error( 'qr_tickets_missing_token', __( 'Token missing.', 'qr-tickets' ), array( 'status' => 400 ) );
        }

        $ticket_id = $this->find_ticket_by_token( $token );

        if ( ! $ticket_id ) {
            return new WP_Error( 'qr_tickets_not_found', __( 'Ticket not found.', 'qr-tickets' ), array( 'status' => 404 ) );
        }

        $expires_at = (int) get_post_meta( $ticket_id, '_qr_recovery_expires', true );

        if ( $expires_at && $expires_at < time() ) {
            return new WP_Error( 'qr_tickets_expired', __( 'Ticket token expired.', 'qr-tickets' ), array( 'status' => 410 ) );
        }

        $response = array(
            'ticket_id'  => $ticket_id,
            'title'      => get_the_title( $ticket_id ),
            'permalink'  => get_permalink( $ticket_id ),
            'status'     => get_post_meta( $ticket_id, '_status', true ),
            'valid_to'   => (int) get_post_meta( $ticket_id, '_valid_to', true ),
            'token'      => $token,
            'expires_at' => $expires_at,
            'type'       => get_post_meta( $ticket_id, '_type', true ),
        );

        return rest_ensure_response( $response );
    }

    private function find_ticket_by_token( $token ) {
        global $wpdb;

        $meta_key = '_qr_recovery_token';

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                $meta_key,
                $token
            )
        );

        $id = absint( $id );

        return $id ? $id : 0;
    }

    public function enqueue_assets() {
        if ( is_admin() ) {
            return;
        }

        $handle = 'qr-tickets-recovery';

        wp_enqueue_script(
            $handle,
            plugins_url( 'assets/js/ticket-recovery.js', QR_TICKETS_PLUGIN_FILE ),
            array(),
            QR_TICKETS_VERSION,
            true
        );

        wp_localize_script(
            $handle,
            'QRTicketsRecovery',
            array(
                'endpoint' => esc_url_raw( rest_url( 'qr-tickets/v1/recover' ) ),
                'cookie'   => self::COOKIE_NAME,
                'i18n'     => array(
                    'heading'   => __( 'You have an active ticket', 'qr-tickets' ),
                    'openLabel' => __( 'Open ticket', 'qr-tickets' ),
                    'expired'   => __( 'Ticket unavailable', 'qr-tickets' ),
                ),
            )
        );
    }
}
