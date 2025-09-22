<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRTickets_Loader {

    private $cpt;

    private $admin;

    private $directpay;

    private $issuer;

    private $template;

    private $cron;

    public function __construct() {
        $this->cpt       = new QRTickets_CPT();
        $this->admin     = new QRTickets_Admin();
        $this->directpay = class_exists( 'QRTickets_DirectPay' ) ? new QRTickets_DirectPay() : null;
        $this->issuer    = class_exists( 'QRTickets_Issuer' ) ? new QRTickets_Issuer() : null;
        $this->template  = class_exists( 'QRTickets_Template' ) ? new QRTickets_Template() : null;
        $this->cron      = class_exists( 'QRTickets_Cron' ) ? new QRTickets_Cron() : null;
    }

    public function init() {
        $this->cpt->register();

        if ( $this->directpay ) {
            $this->directpay->register();
        }

        if ( $this->issuer ) {
            $this->issuer->register();
        }

        if ( $this->template ) {
            $this->template->register();
        }

        if ( $this->cron ) {
            $this->cron->register();
        }

        if ( is_admin() ) {
            $this->admin->register();
        }
    }
}
