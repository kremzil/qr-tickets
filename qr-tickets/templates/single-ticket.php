<?php
/**
 * Template for displaying single Ticket posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $post;

get_header();

$ticket_id = get_the_ID();
$code      = (string) get_post_meta( $ticket_id, '_qr_code', true );
$qr_url    = (string) get_post_meta( $ticket_id, '_qr_png_url', true );
$type      = (string) get_post_meta( $ticket_id, '_type', true );
$valid_from = (int) get_post_meta( $ticket_id, '_valid_from', true );
$valid_to   = (int) get_post_meta( $ticket_id, '_valid_to', true );
$status     = (string) get_post_meta( $ticket_id, '_status', true );
$email      = (string) get_post_meta( $ticket_id, '_email', true );

if ( $valid_to && time() > $valid_to && 'expired' !== $status ) {
    update_post_meta( $ticket_id, '_status', 'expired' );
    $status = 'expired';
}

$is_expired         = 'expired' === $status;
$timezone           = wp_timezone();
$valid_until_label  = $valid_to ? wp_date( 'H:i', $valid_to, $timezone ) : '';

?>
<div class="qr-ticket-wrapper">
    <header class="qr-ticket-header">
        <h1 class="qr-ticket-title"><?php echo esc_html( get_the_title() ); ?></h1>
        <p class="qr-ticket-meta">
            <span class="qr-ticket-type"><?php echo esc_html( strtoupper( $type ) ); ?></span>
            <?php if ( $valid_until_label ) : ?>
                <span class="qr-ticket-valid-until"><?php printf( esc_html__( 'Valid until %s', 'qr-tickets' ), esc_html( $valid_until_label ) ); ?></span>
            <?php endif; ?>
        </p>
    </header>

    <?php if ( $is_expired ) : ?>
        <div class="qr-ticket-expired">
            <strong><?php esc_html_e( 'Ticket expired', 'qr-tickets' ); ?></strong>
        </div>
    <?php else : ?>
        <?php if ( $qr_url ) : ?>
            <div class="qr-ticket-image">
                <img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'Ticket QR code', 'qr-tickets' ); ?>" />
            </div>
        <?php endif; ?>

        <div class="qr-ticket-code">
            <code id="qr-ticket-code" class="qr-ticket-code-value"><?php echo esc_html( $code ); ?></code>
            <button type="button" id="qr-ticket-copy" class="qr-ticket-button"><?php esc_html_e( 'Copy', 'qr-tickets' ); ?></button>
        </div>

        <?php if ( $valid_to ) : ?>
            <div class="qr-ticket-countdown" data-valid-to="<?php echo esc_attr( $valid_to ); ?>">
                <span class="qr-ticket-countdown-label"><?php esc_html_e( 'Time remaining', 'qr-tickets' ); ?>:</span>
                <span class="qr-ticket-countdown-value" id="qr-ticket-countdown">--:--</span>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( empty( $email ) && ! $is_expired ) : ?>
        <div class="qr-ticket-email">
            <form id="qr-ticket-email-form">
                <label for="qr-ticket-email-input"><?php esc_html_e( 'Send ticket to email', 'qr-tickets' ); ?></label>
                <div class="qr-ticket-email-fields">
                    <input type="email" id="qr-ticket-email-input" name="email" required />
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket_id ); ?>" />
                    <button type="submit" class="qr-ticket-button"><?php esc_html_e( 'Send', 'qr-tickets' ); ?></button>
                </div>
                <p class="qr-ticket-email-feedback" aria-live="polite"></p>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php

do_action( 'qr_tickets_after_ticket', $ticket_id );

get_footer();