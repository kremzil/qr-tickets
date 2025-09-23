<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Admin {

    private $option_name = 'qr_tickets_settings';

    private $fields = null;

    private $dpmk_fields = null;

    public function __construct() {
        // Fields are initialized lazily in getters.
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

        foreach ( $this->get_fields() as $id => $field ) {
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

        $this->register_dpmk_settings();
    }

    private function register_dpmk_settings() {
        register_setting( 'qr_dpmk_settings_group', 'qr_dpmk_base_url', array( $this, 'sanitize_base_url' ) );
        register_setting( 'qr_dpmk_settings_group', 'qr_dpmk_client_id', array( $this, 'sanitize_text' ) );
        register_setting( 'qr_dpmk_settings_group', 'qr_dpmk_client_secret', array( $this, 'sanitize_text' ) );
        register_setting( 'qr_dpmk_settings_group', 'qr_dpmk_ticket_30_id', array( $this, 'sanitize_int' ) );
        register_setting( 'qr_dpmk_settings_group', 'qr_dpmk_ticket_60_id', array( $this, 'sanitize_int' ) );
        register_setting( 'qr_dpmk_settings_group', 'qr_dpmk_enable_delayless_with_device', array( $this, 'sanitize_checkbox' ) );
        register_setting( 'qr_dpmk_settings_group', 'qr_dpmk_test_mode', array( $this, 'sanitize_checkbox' ) );

        add_settings_section(
            'qr_tickets_dpmk',
            __( 'Municipality (DPMK)', 'qr-tickets' ),
            '__return_null',
            'qr_tickets_dpmk'
        );

        foreach ( $this->get_dpmk_fields() as $field ) {
            add_settings_field(
                $field['id'],
                $field['label'],
                array( $this, 'render_dpmk_field' ),
                'qr_tickets_dpmk',
                'qr_tickets_dpmk',
                $field
            );
        }
    }

    public function sanitize( $input ) {
        $sanitized = array();

        foreach ( $this->get_fields() as $id => $field ) {
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

    public function render_dpmk_field( $args ) {
        $option      = $args['option'];
        $type        = isset( $args['type'] ) ? $args['type'] : 'text';
        $default     = isset( $args['default'] ) ? $args['default'] : '';
        $description = isset( $args['description'] ) ? $args['description'] : '';
        $value       = get_option( $option, $default );

        if ( 'checkbox' === $type ) {
            printf( '<input type="hidden" name="%1$s" value="0" />', esc_attr( $option ) );
            printf(
                '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
                esc_attr( $option ),
                esc_attr( $option ),
                checked( '1', (string) $value, false ),
                esc_html( $description )
            );
            return;
        }

        $input_type = 'password' === $type ? 'password' : 'text';

        printf(
            '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" />',
            esc_attr( $input_type ),
            esc_attr( $option ),
            esc_attr( $option ),
            esc_attr( $value )
        );

        if ( ! empty( $description ) ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
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
                    settings_fields( 'qr_dpmk_settings_group' );
                    do_settings_sections( 'qr_tickets' );
                    do_settings_sections( 'qr_tickets_dpmk' );
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

    private function get_fields() {
        if ( null === $this->fields ) {
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

        return $this->fields;
    }

    private function get_dpmk_fields() {
        if ( null === $this->dpmk_fields ) {
            $this->dpmk_fields = array(
                array(
                    'id'          => 'qr_dpmk_base_url',
                    'label'       => __( 'Base URL', 'qr-tickets' ),
                    'option'      => 'qr_dpmk_base_url',
                    'default'     => 'https://api.vcl.dpmk.sk',
                    'type'        => 'text',
                    'description' => __( 'Root endpoint for the DPMK API.', 'qr-tickets' ),
                ),
                array(
                    'id'      => 'qr_dpmk_client_id',
                    'label'   => __( 'Client ID', 'qr-tickets' ),
                    'option'  => 'qr_dpmk_client_id',
                    'type'    => 'text',
                ),
                array(
                    'id'      => 'qr_dpmk_client_secret',
                    'label'   => __( 'Client Secret', 'qr-tickets' ),
                    'option'  => 'qr_dpmk_client_secret',
                    'type'    => 'password',
                ),
                array(
                    'id'      => 'qr_dpmk_ticket_30_id',
                    'label'   => __( '30-minute Ticket ID', 'qr-tickets' ),
                    'option'  => 'qr_dpmk_ticket_30_id',
                    'type'    => 'text',
                ),
                array(
                    'id'      => 'qr_dpmk_ticket_60_id',
                    'label'   => __( '60-minute Ticket ID', 'qr-tickets' ),
                    'option'  => 'qr_dpmk_ticket_60_id',
                    'type'    => 'text',
                ),
                array(
                    'id'          => 'qr_dpmk_enable_delayless_with_device',
                    'label'       => __( 'Enable delayless with device QR', 'qr-tickets' ),
                    'option'      => 'qr_dpmk_enable_delayless_with_device',
                    'type'        => 'checkbox',
                    'description' => __( 'Send device QR code when it is passed to the checkout.', 'qr-tickets' ),
                ),
                array(
                    'id'          => 'qr_dpmk_test_mode',
                    'label'       => __( 'Test mode (use stub)', 'qr-tickets' ),
                    'option'      => 'qr_dpmk_test_mode',
                    'type'        => 'checkbox',
                    'description' => __( 'Do not call the DPMK API and reuse the legacy stub.', 'qr-tickets' ),
                ),
            );
        }

        return $this->dpmk_fields;
    }

    public function sanitize_base_url( $value ) {
        $value = trim( (string) $value );

        if ( empty( $value ) ) {
            return 'https://api.vcl.dpmk.sk';
        }

        return untrailingslashit( esc_url_raw( $value ) );
    }

    public function sanitize_text( $value ) {
        return sanitize_text_field( $value );
    }

    public function sanitize_int( $value ) {
        return (string) absint( $value );
    }

    public function sanitize_checkbox( $value ) {
        return $value ? '1' : '';
    }
}