<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Admin {

    private $option_name = 'qr_tickets_settings';

    private $fields = array();

    public function __construct() {
        $this->fields = array(
            'product_30m_id' => array(
                'label' => __( 'Product 30m ID', 'qr-tickets' ),
                'type'  => 'number',
            ),
            'product_60m_id' => array(
                'label' => __( 'Product 60m ID', 'qr-tickets' ),
                'type'  => 'number',
            ),
            'redirect_after_success' => array(
                'label' => __( 'Redirect After Success', 'qr-tickets' ),
                'type'  => 'text',
            ),
            'muni_stub_url' => array(
                'label' => __( 'Muni Stub URL', 'qr-tickets' ),
                'type'  => 'url',
            ),
        );
    }

    public function register() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_settings_page() {
        add_options_page(
            __( 'QR Tickets Settings', 'qr-tickets' ),
            __( 'QR Tickets', 'qr-tickets' ),
            'manage_options',
            'qr-tickets',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'qr_tickets_settings_group', $this->option_name, array( $this, 'sanitize' ) );

        add_settings_section(
            'qr_tickets_main',
            __( 'General Settings', 'qr-tickets' ),
            '__return_null',
            'qr_tickets'
        );

        foreach ( $this->fields as $id => $field ) {
            add_settings_field(
                $id,
                $field['label'],
                array( $this, 'render_field' ),
                'qr_tickets',
                'qr_tickets_main',
                array(
                    'id'   => $id,
                    'type' => $field['type'],
                )
            );
        }
    }

    public function sanitize( $input ) {
        $sanitized = array();

        foreach ( $this->fields as $id => $field ) {
            $value = isset( $input[ $id ] ) ? $input[ $id ] : '';

            switch ( $id ) {
                case 'product_30m_id':
                case 'product_60m_id':
                    $sanitized[ $id ] = absint( $value );
                    break;
                case 'muni_stub_url':
                    $sanitized[ $id ] = esc_url_raw( trim( $value ) );
                    break;
                case 'redirect_after_success':
                default:
                    $sanitized[ $id ] = sanitize_text_field( $value );
                    break;
            }
        }

        return $sanitized;
    }

    public function render_field( $args ) {
        $options = get_option( $this->option_name, array() );
        $id      = $args['id'];
        $type    = $args['type'];
        $value   = isset( $options[ $id ] ) ? $options[ $id ] : '';
        $name    = sprintf( '%s[%s]', $this->option_name, $id );

        printf(
            '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" />',
            esc_attr( $type ),
            esc_attr( $id ),
            esc_attr( $name ),
            esc_attr( $value )
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'QR Tickets Settings', 'qr-tickets' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'qr_tickets_settings_group' );
                    do_settings_sections( 'qr_tickets' );
                    submit_button( __( 'Save Settings', 'qr-tickets' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets( $hook ) {
        if ( 'settings_page_qr-tickets' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'qr-tickets-admin',
            plugins_url( 'assets/admin.css', QR_TICKETS_PLUGIN_FILE ),
            array(),
            QR_TICKETS_VERSION
        );
    }
}
