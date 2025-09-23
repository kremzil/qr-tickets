<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Cron {

    const EVENT = 'qr_tickets_expire_event';

    public function register() {
        add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
        add_action( 'init', array( $this, 'schedule' ) );
        add_action( self::EVENT, array( $this, 'expire_tickets' ) );
    }

    public static function schedule_event() {
        if ( ! wp_next_scheduled( self::EVENT ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'qr_tickets_minutely', self::EVENT );
        }
    }

    public static function clear_event() {
        $timestamp = wp_next_scheduled( self::EVENT );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::EVENT );
        }
    }

    public function add_schedule( $schedules ) {
        if ( ! isset( $schedules['qr_tickets_minutely'] ) ) {
            $schedules['qr_tickets_minutely'] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display'  => __( 'Every Minute (QR Tickets)', 'qr-tickets' ),
            );
        }

        return $schedules;
    }

    public function schedule() {
        self::schedule_event();
    }

    public function expire_tickets() {
        $query = new WP_Query(
            array(
                'post_type'      => 'ticket',
                'post_status'    => 'publish',
                'posts_per_page' => 50,
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => '_status',
                        'value' => 'active',
                    ),
                    array(
                        'key'     => '_valid_to',
                        'value'   => time(),
                        'compare' => '<',
                        'type'    => 'NUMERIC',
                    ),
                ),
                'fields'         => 'ids',
            )
        );

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $ticket_id ) {
                update_post_meta( $ticket_id, '_status', 'expired' );
            }
        }

        $this->retry_failed_dpmk_syncs();
    }

    private function retry_failed_dpmk_syncs() {
        if ( QRTickets_DPMK_Service::is_test_mode() ) {
            return;
        }

        $client = new QRTickets_DPMK_Client();

        if ( ! $client->is_configured() ) {
            return;
        }

        $last_run = (int) get_option( 'qr_tickets_dpmk_retry_last', 0 );

        if ( ( time() - $last_run ) < 300 ) {
            return;
        }

        update_option( 'qr_tickets_dpmk_retry_last', time(), false );

        $query = new WP_Query(
            array(
                'post_type'      => 'ticket',
                'post_status'    => 'publish',
                'posts_per_page' => 10,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'   => '_sync_status',
                        'value' => 'failed',
                    ),
                    array(
                        'key'     => '_provider_attempts',
                        'value'   => 5,
                        'compare' => '<',
                        'type'    => 'NUMERIC',
                    ),
                ),
            )
        );

        if ( empty( $query->posts ) ) {
            return;
        }

        foreach ( $query->posts as $ticket_id ) {
            QRTickets_DPMK_Service::retry_ticket( $ticket_id );
        }
    }
}