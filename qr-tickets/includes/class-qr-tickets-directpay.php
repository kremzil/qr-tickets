<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_DirectPay {

    const OPTION_NAME = 'qr_tickets_settings';
    const QUERY_VAR   = 'qr_ticket_purchase';

    private $type_map = array(
        '30m' => 'product_30m_id',
        '60m' => 'product_60m_id',
    );

    public function register() {
        add_action( 'init', array( $this, 'register_routes' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_request' ) );
        add_shortcode( 'qr_buy_button', array( $this, 'render_shortcode' ) );
    }

    public static function register_rewrite_rules() {
        add_rewrite_rule( '^buy/(30m|60m)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
    }

    public function register_routes() {
        self::register_rewrite_rules();
    }

    public function add_query_vars( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function handle_request() {
        $type = get_query_var( self::QUERY_VAR );

        if ( empty( $type ) ) {
            return;
        }

        $type = sanitize_key( $type );

        if ( ! $this->is_supported_type( $type ) ) {
            $this->redirect_home();
        }

        if ( ! function_exists( 'wc_create_order' ) ) {
            $this->redirect_checkout_with_error( __( 'Store temporarily unavailable.', 'qr-tickets' ) );
        }

        $product_id = $this->get_product_id_for_type( $type );

        if ( ! $product_id ) {
            $this->redirect_home();
        }

        $product = wc_get_product( $product_id );

        if ( ! $product || ! $product->is_purchasable() || 'publish' !== $product->get_status() ) {
            $this->redirect_home();
        }

        if ( $this->should_require_preflight() ) {
            $preflight = $this->run_dpmk_preflight( $type );

            if ( is_wp_error( $preflight ) ) {
                $this->log_preflight_failure( $preflight );
                $this->render_preflight_error_page( $preflight );
            }
        }

        $email     = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
        $locale    = isset( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( $_GET['locale'] ) ) : '';
        $city_id   = isset( $_GET['city_id'] ) ? sanitize_text_field( wp_unslash( $_GET['city_id'] ) ) : '';
        $route_id  = isset( $_GET['route_id'] ) ? sanitize_text_field( wp_unslash( $_GET['route_id'] ) ) : '';
        $device_qr = isset( $_GET['qrcode'] ) ? sanitize_text_field( wp_unslash( $_GET['qrcode'] ) ) : '';

        $current_user  = is_user_logged_in() ? wp_get_current_user() : null;
        $user_id       = 0;
        $account_email = '';

        if ( $current_user instanceof WP_User && $current_user->exists() ) {
            $user_id       = (int) $current_user->ID;
            $account_email = sanitize_email( $current_user->user_email );
        }

        if ( $account_email ) {
            $email = $account_email;
        }

        $order = wc_create_order(
            array(
                'status'      => 'pending',
                'customer_id' => $user_id,
            )
        );

        if ( is_wp_error( $order ) ) {
            $this->redirect_checkout_with_error( __( 'Unable to create order.', 'qr-tickets' ) );
        }

        if ( method_exists( $order, 'set_created_via' ) ) {
            $order->set_created_via( 'qr-tickets-directpay' );
        }

        if ( method_exists( $order, 'set_customer_id' ) ) {
            $order->set_customer_id( $user_id );
        }

        if ( $user_id ) {
            $order->update_meta_data( '_customer_user', $user_id );
        }

        if ( method_exists( $order, 'set_customer_ip_address' ) && class_exists( 'WC_Geolocation' ) ) {
            $order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
        }

        if ( method_exists( $order, 'set_customer_user_agent' ) && function_exists( 'wc_get_user_agent' ) ) {
            $order->set_customer_user_agent( wc_get_user_agent() );
        }

        $order->add_order_note(
            sprintf(
                'DirectPay init: type=%s, product_id=%d, email=%s, user_id=%s',
                $type,
                $product_id,
                $email ? $email : 'n/a',
                $user_id ? $user_id : 'none'
            )
        );

        $order->add_product( $product, 1 );

        if ( $email ) {
            $order->set_billing_email( $email );
        }

        $order->update_meta_data( '_qr_ticket_type', $type );
        $order->update_meta_data( '_qr_intent_at', time() );

        if ( $locale ) {
            $order->update_meta_data( '_qr_locale', $locale );
        }

        if ( $city_id ) {
            $order->update_meta_data( '_qr_city_id', $city_id );
        }

        if ( $route_id ) {
            $order->update_meta_data( '_qr_route_id', $route_id );
        }

        if ( $device_qr ) {
            $order->update_meta_data( '_qr_device_qr', $device_qr );
        }

        $order->calculate_totals();

        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            $order->save();
            $this->redirect_checkout_with_error( __( 'Barion temporarily unavailable', 'qr-tickets' ) );
        }

        $gateways_instance  = WC()->payment_gateways();
        $available_gateways = $gateways_instance ? $gateways_instance->get_available_payment_gateways() : array();
        $barion             = isset( $available_gateways['barion'] ) ? $available_gateways['barion'] : null;

        if ( ! $barion ) {
            $order->save();
            $this->redirect_checkout_with_error( __( 'Barion temporarily unavailable', 'qr-tickets' ) );
        }

        if ( WC()->session ) {
            WC()->session->set( 'chosen_payment_method', 'barion' );
            WC()->session->set( 'order_awaiting_payment', $order->get_id() );
            WC()->session->save_data();
        }

        if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
            define( 'WOOCOMMERCE_CHECKOUT', true );
        }

        if ( class_exists( 'WC_Payment_Gateway' ) && $barion instanceof WC_Payment_Gateway ) {
            $order->set_payment_method( $barion );
        } else {
            $order->set_payment_method( 'barion' );
        }

        $order->save();

        if ( method_exists( $barion, 'process_payment' ) ) {
            $result = $barion->process_payment( $order->get_id() );

            if ( is_wp_error( $result ) ) {
                $order->add_order_note( sprintf( 'DirectPay gateway result: error %s', $result->get_error_message() ) );
            } elseif ( is_array( $result ) && isset( $result['result'] ) ) {
                if ( 'success' === $result['result'] && ! empty( $result['redirect'] ) ) {
                    $order->add_order_note( sprintf( 'DirectPay gateway result: success redirect=%s', $result['redirect'] ) );
                    $order->save();
                    $this->safe_redirect( $result['redirect'], true );
                }

                $order->add_order_note( sprintf( 'DirectPay gateway result: %s', $result['result'] ) );
            }
        }

        $fallback_url = $order->get_checkout_payment_url();
        $order->add_order_note( sprintf( 'DirectPay fallback to order-pay URL: %s', $fallback_url ) );
        $order->save();
        $this->safe_redirect( $fallback_url );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'type'  => '30m',
                'label' => '',
                'email' => '',
                'class' => 'button',
            ),
            $atts,
            'qr_buy_button'
        );

        $type = sanitize_key( $atts['type'] );

        if ( ! $this->is_supported_type( $type ) ) {
            return '';
        }

        $label = $atts['label'] ? sanitize_text_field( $atts['label'] ) : sprintf( __( 'Buy %s', 'qr-tickets' ), strtoupper( $type ) );
        $email = $atts['email'] ? sanitize_email( $atts['email'] ) : '';

        $class = 'button';

        if ( ! empty( $atts['class'] ) ) {
            $class_parts = preg_split( '/\s+/', $atts['class'], -1, PREG_SPLIT_NO_EMPTY );
            $class_parts = array_filter( array_map( 'sanitize_html_class', $class_parts ) );

            if ( $class_parts ) {
                $class = implode( ' ', $class_parts );
            }
        }

        $path = 'buy/' . $type;
        $url  = home_url( user_trailingslashit( $path ) );

        if ( $email ) {
            $url = add_query_arg( array( 'email' => $email ), $url );
        }

        return sprintf(
            '<a href="%1$s" class="%2$s">%3$s</a>',
            esc_url( $url ),
            esc_attr( $class ),
            esc_html( $label )
        );
    }

    private function is_supported_type( $type ) {
        return isset( $this->type_map[ $type ] );
    }

    private function get_product_id_for_type( $type ) {
        if ( ! $this->is_supported_type( $type ) ) {
            return 0;
        }

        $settings = get_option( self::OPTION_NAME, array() );
        $key      = $this->type_map[ $type ];

        return isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;
    }


    private function should_require_preflight() {
        if ( ! class_exists( 'QRTickets_DPMK_Service' ) ) {
            return false;
        }

        if ( QRTickets_DPMK_Service::is_test_mode() ) {
            return false;
        }

        return (bool) get_option( 'qr_dpmk_require_preflight', '1' );
    }

    private function run_dpmk_preflight( $type ) {
        $missing = array();

        $base_url      = trim( (string) get_option( 'qr_dpmk_base_url', '' ) );
        $client_id     = trim( (string) get_option( 'qr_dpmk_client_id', '' ) );
        $client_secret = trim( (string) get_option( 'qr_dpmk_client_secret', '' ) );
        $ticket_30     = absint( get_option( 'qr_dpmk_ticket_30_id', 0 ) );
        $ticket_60     = absint( get_option( 'qr_dpmk_ticket_60_id', 0 ) );

        if ( '' === $base_url ) {
            $missing[] = 'base_url';
        }

        if ( '' === $client_id ) {
            $missing[] = 'client_id';
        }

        if ( '' === $client_secret ) {
            $missing[] = 'client_secret';
        }

        if ( ! $ticket_30 ) {
            $missing[] = 'ticket_30_id';
        }

        if ( ! $ticket_60 ) {
            $missing[] = 'ticket_60_id';
        }

        if ( $missing ) {
            return new WP_Error(
                'qr_dpmk_preflight_config',
                $this->normalize_preflight_message( 'Missing configuration: ' . implode( ', ', $missing ) ),
                array( 'type' => $type )
            );
        }

        $ready = QRTickets_DPMK_Service::ensure_ready();

        if ( empty( $ready['ok'] ) ) {
            $message = isset( $ready['message'] ) ? $ready['message'] : __( 'Provider unavailable.', 'qr-tickets' );

            return new WP_Error(
                'qr_dpmk_preflight_unavailable',
                $this->normalize_preflight_message( $message ),
                array( 'type' => $type )
            );
        }

        $provider_ticket_id = QRTickets_DPMK_Service::map_ticket_id( $type );

        if ( ! $provider_ticket_id ) {
            return new WP_Error(
                'qr_dpmk_preflight_missing_mapping',
                $this->normalize_preflight_message( sprintf( 'Ticket ID mapping missing for type %s.', $type ) ),
                array( 'type' => $type )
            );
        }

        $client   = new QRTickets_DPMK_Client();
        $response = $client->get( '/api/ticket' );

        if ( empty( $response['ok'] ) ) {
            $error_message = $response['error'] ? $response['error'] : ( $response['code'] ? 'HTTP ' . $response['code'] : 'Unknown error' );

            return new WP_Error(
                'qr_dpmk_preflight_request_failed',
                $this->normalize_preflight_message( $error_message ),
                array( 'type' => $type )
            );
        }

        $items = array();

        if ( isset( $response['body']['data'] ) && is_array( $response['body']['data'] ) ) {
            $items = $response['body']['data'];
        } elseif ( is_array( $response['body'] ) ) {
            $items = $response['body'];
        }

        $found = false;
        $provider_ticket_id = (int) $provider_ticket_id;

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $ticket_id = isset( $item['ticket_id'] ) ? (int) $item['ticket_id'] : 0;

            if ( $ticket_id === $provider_ticket_id ) {
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            return new WP_Error(
                'qr_dpmk_preflight_ticket_missing',
                $this->normalize_preflight_message( sprintf( 'Ticket %d not reported by provider.', $provider_ticket_id ) ),
                array( 'type' => $type )
            );
        }

        return true;
    }

    private function log_preflight_failure( WP_Error $error ) {
        $detail = $this->normalize_preflight_message( $error->get_error_message() );
        $data   = $error->get_error_data();
        $type   = is_array( $data ) && isset( $data['type'] ) ? (string) $data['type'] : '';
        $prefix = $type ? sprintf( ' type=%s', $type ) : '';
        $message = sprintf( 'DPMK preflight failed%s: %s', $prefix, $detail ? $detail : $error->get_error_code() );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->error( $message, array( 'source' => 'qr-tickets' ) );
        } else {
            error_log( $message );
        }
    }

    private function render_preflight_error_page( WP_Error $error ) {
        status_header( 503 );
        nocache_headers();

        $title    = __( 'Purchase temporarily unavailable', 'qr-tickets' );
        $subtitle = __( 'Ticket provider is unavailable. Please try again later.', 'qr-tickets' );
        $detail   = $this->normalize_preflight_message( $error->get_error_message() );

        $html  = '<div class="qr-ticket-error-page">';
        $html .= '<h1>' . esc_html( $title ) . '</h1>';
        $html .= '<p class="qr-ticket-error-subtitle">' . esc_html( $subtitle ) . '</p>';

        if ( $detail ) {
            $html .= '<p class="qr-ticket-error-detail"><small>' . esc_html( $detail ) . '</small></p>';
        }

        $html .= '<p><button type="button" class="qr-ticket-error-back" onclick="history.back();return false;">' . esc_html__( 'Back', 'qr-tickets' ) . '</button></p>';
        $html .= '</div>';

        wp_die( $html, $title, array( 'response' => 503 ) );
    }

    private function normalize_preflight_message( $message ) {
        if ( ! is_scalar( $message ) ) {
            return '';
        }

        $message = trim( (string) $message );
        $message = str_replace( array( chr( 13 ), chr( 10 ) ), ' ', $message );

        if ( function_exists( 'mb_substr' ) ) {
            $message = mb_substr( $message, 0, 180 );
        } else {
            $message = substr( $message, 0, 180 );
        }

        return $message;
    }

    private function redirect_home() {
        $this->safe_redirect( home_url( '/' ) );
    }

    private function redirect_checkout_with_error( $message ) {
        if ( function_exists( 'wc_add_notice' ) && $message ) {
            wc_add_notice( $message, 'error' );
        }

        $checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
        $this->safe_redirect( $checkout_url );
    }

    private function safe_redirect( $url, $allow_external = false ) {
        if ( $allow_external ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );

            if ( $host ) {
                add_filter(
                    'allowed_redirect_hosts',
                    static function ( $hosts ) use ( $host ) {
                        $hosts[] = $host;
                        return array_unique( $hosts );
                    }
                );
            }
        }

        wp_safe_redirect( $url );
        exit;
    }
}
