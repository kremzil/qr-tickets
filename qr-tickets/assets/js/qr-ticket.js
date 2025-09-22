(function($) {
    function formatTime(seconds) {
        if (seconds < 0) {
            seconds = 0;
        }

        var minutes = Math.floor(seconds / 60);
        var secs = seconds % 60;
        return minutes.toString().padStart(2, '0') + ':' + secs.toString().padStart(2, '0');
    }

    function initCountdown() {
        var container = $('.qr-ticket-countdown');

        if (!container.length) {
            return;
        }

        var target = parseInt(container.data('valid-to'), 10);

        if (!target) {
            return;
        }

        function tick() {
            var now = Math.floor(Date.now() / 1000);
            var remaining = target - now;

            $('#qr-ticket-countdown').text(formatTime(remaining));

            if (remaining <= 0) {
                clearInterval(timer);
                window.location.reload();
            }
        }

        tick();
        var timer = setInterval(tick, 1000);
    }

    function initCopy() {
        $('#qr-ticket-copy').on('click', function() {
            var text = $('#qr-ticket-code').text();

            if (!text) {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    alert(QRTicketsTicket.copySuccess || 'Copied');
                }).catch(function() {
                    alert(QRTicketsTicket.copyError || 'Copy failed');
                });
            } else {
                var textarea = $('<textarea>').val(text).appendTo('body');
                textarea.select();

                try {
                    document.execCommand('copy');
                    alert(QRTicketsTicket.copySuccess || 'Copied');
                } catch (err) {
                    alert(QRTicketsTicket.copyError || 'Copy failed');
                }

                textarea.remove();
            }
        });
    }

    function initEmailForm() {
        $('#qr-ticket-email-form').on('submit', function(event) {
            event.preventDefault();

            var $form = $(this);
            var email = $('#qr-ticket-email-input').val();
            var ticketId = $form.find('input[name="ticket_id"]').val();
            var feedback = $form.find('.qr-ticket-email-feedback');

            feedback.text('');

            $.ajax({
                url: QRTicketsTicket.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'qr_ticket_send_email',
                    nonce: QRTicketsTicket.nonce,
                    email: email,
                    ticket_id: ticketId
                }
            }).done(function(response) {
                if (response.success) {
                    feedback.text(response.data.message);
                    $form.find('button[type="submit"]').prop('disabled', true);
                } else if (response.data && response.data.message) {
                    feedback.text(response.data.message);
                }
            }).fail(function() {
                feedback.text('Error sending email.');
            });
        });
    }

    $(function() {
        initCountdown();
        initCopy();
        initEmailForm();
    });
})(jQuery);