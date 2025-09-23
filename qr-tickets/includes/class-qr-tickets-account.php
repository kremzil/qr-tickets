<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Account {

    const ENDPOINT = 'my-tickets';

    public function register() {
        add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );
    }

    public static function add_endpoint() {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public function add_menu_item( $items ) {
        if ( ! is_user_logged_in() ) {
            return $items;
        }

        $new_items = array();
        $inserted  = false;

        foreach ( $items as $endpoint => $label ) {
            $new_items[ $endpoint ] = $label;

            if ( 'orders' === $endpoint && ! isset( $items[ self::ENDPOINT ] ) ) {
                $new_items[ self::ENDPOINT ] = __( 'My tickets', 'qr-tickets' );
                $inserted                    = true;
            }
        }

        if ( ! $inserted ) {
            $new_items[ self::ENDPOINT ] = __( 'My tickets', 'qr-tickets' );
        }

        return $new_items;
    }

    public function render_endpoint() {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'You need an account to view tickets.', 'qr-tickets' ) . '</p>';
            return;
        }

        $user    = wp_get_current_user();
        $user_id = (int) $user->ID;
        $email   = sanitize_email( $user->user_email );

        $meta_query = array( 'relation' => 'OR' );

        if ( $user_id ) {
            $meta_query[] = array(
                'key'     => '_user_id',
                'value'   => $user_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }

        if ( $email ) {
            $meta_query[] = array(
                'key'     => '_email',
                'value'   => $email,
                'compare' => '=',
            );
        }

        if ( count( $meta_query ) === 1 ) {
            $meta_query = array();
        }

        $args = array(
            'post_type'      => 'ticket',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( $user_id ) {
            $args['author'] = $user_id;
        }

        if ( $meta_query ) {
            $args['meta_query'] = $meta_query;
        }

        $tickets = new WP_Query( $args );

        if ( ! $tickets->have_posts() ) {
            echo '<p>' . esc_html__( 'No tickets found yet.', 'qr-tickets' ) . '</p>';
            wp_reset_postdata();
            return;
        }

        echo '<table class="qr-tickets-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Code', 'qr-tickets' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'qr-tickets' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'qr-tickets' ) . '</th>';
        echo '<th>' . esc_html__( 'Valid until', 'qr-tickets' ) . '</th>';
        echo '<th class="actions-col" aria-label="' . esc_attr__( 'View', 'qr-tickets' ) . '"></th>';
        echo '</tr></thead><tbody>';

        while ( $tickets->have_posts() ) {
            $tickets->the_post();

            $ticket_id = get_the_ID();
            $code      = (string) get_post_meta( $ticket_id, '_qr_code', true );
            $type      = (string) get_post_meta( $ticket_id, '_type', true );
            $status    = (string) get_post_meta( $ticket_id, '_status', true );
            $valid_to  = (int) get_post_meta( $ticket_id, '_valid_to', true );
            $link      = get_permalink( $ticket_id );

            $timezone = wp_timezone();
            $valid    = $valid_to ? wp_date( 'd.m.y - H:i', $valid_to, $timezone ) : '';
            $is_active = ( 'active' === strtolower( $status ) );
            $badge     = $is_active ? 'ok' : 'bad';
            $row_class = $is_active ? 'is-active' : 'is-expired';

            echo '<tr class="' . esc_attr( $row_class ) . '">';
            echo '<td data-label="' . esc_attr__( 'Code', 'qr-tickets' ) . '"><code class="qr-code">' . esc_html( $code ) . '</code></td>';
            echo '<td data-label="' . esc_attr__( 'Type', 'qr-tickets' ) . '">' . esc_html( strtoupper( $type ) ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'Status', 'qr-tickets' ) . '"><span class="badge ' . esc_attr( $badge ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td>';
            echo '<td data-label="' . esc_attr__( 'Valid until', 'qr-tickets' ) . '">' . esc_html( $valid ) . '</td>';

            if ( $link ) {
                echo '<td class="actions"><a class="btn-xs" href="' . esc_url( $link ) . '">' . esc_html__( 'View', 'qr-tickets' ) . '</a></td>';
            } else {
                echo '<td class="actions">&nbsp;</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';

        wp_reset_postdata();
    }
}