<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_CPT {

    const POST_TYPE = 'ticket';

    public function register() {
        add_action( 'init', array( $this, 'register_post_type' ) );
    }

    public function register_post_type() {
        $labels = array(
            'name'               => __( 'Tickets', 'qr-tickets' ),
            'singular_name'      => __( 'Ticket', 'qr-tickets' ),
            'menu_name'          => __( 'QR Tickets', 'qr-tickets' ),
            'name_admin_bar'     => __( 'Ticket', 'qr-tickets' ),
            'add_new'            => __( 'Add New', 'qr-tickets' ),
            'add_new_item'       => __( 'Add New Ticket', 'qr-tickets' ),
            'edit_item'          => __( 'Edit Ticket', 'qr-tickets' ),
            'new_item'           => __( 'New Ticket', 'qr-tickets' ),
            'view_item'          => __( 'View Ticket', 'qr-tickets' ),
            'view_items'         => __( 'View Tickets', 'qr-tickets' ),
            'search_items'       => __( 'Search Tickets', 'qr-tickets' ),
            'not_found'          => __( 'No tickets found.', 'qr-tickets' ),
            'not_found_in_trash' => __( 'No tickets found in Trash.', 'qr-tickets' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-tickets',
            'supports'           => array( 'title', 'editor', 'custom-fields' ),
            'has_archive'        => false,
            'rewrite'            => array(
                'slug'       => 'ticket',
                'with_front' => false,
            ),
            'capability_type'    => 'post',
        );

        register_post_type( self::POST_TYPE, $args );
    }
}
