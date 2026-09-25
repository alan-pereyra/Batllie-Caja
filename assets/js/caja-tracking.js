/**
 * Batllie Caja & Pedidos POS - Script de Seguimiento en Vivo
 * Actualiza automáticamente el estado y la barra de progreso sin recargar la página
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $cards = $('.batllie-order-tracking-card');
        if (!$cards.length) {
            return;
        }

        // Posicionar el card de seguimiento arriba al principio de la página
        var $orderContainer = $('.woocommerce-order, .entry-content .woocommerce');
        if ($orderContainer.length && !$orderContainer.children().first().is($cards)) {
            $orderContainer.prepend($cards);
        }

        // Eliminar sección tradicional de gracias por tu pedido y resumen inicial
        $('.woocommerce-thankyou-order-received, .woocommerce-order-overview').remove();

        var ajaxUrl = (typeof emp_caja_tracking_params !== 'undefined' && emp_caja_tracking_params.ajax_url) 
            ? emp_caja_tracking_params.ajax_url 
            : '/wp-admin/admin-ajax.php';
        
        var pollInterval = (typeof emp_caja_tracking_params !== 'undefined' && emp_caja_tracking_params.poll_interval)
            ? parseInt(emp_caja_tracking_params.poll_interval, 10) * 1000
            : 10000;

        var checkmarkSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

        $cards.each(function() {
            var $card = $(this);
            var orderId = $card.data('order-id');
            var orderKey = $card.data('order-key');
            var currentStep = parseInt($card.data('current-step'), 10) || 1;

            if (!orderId || !orderKey) {
                return;
            }

            function updateUI(step, stepLabel) {
                step = parseInt(step, 10);
                if (isNaN(step) || step < 1) step = 1;
                if (step > 4) step = 4;

                currentStep = step;
                $card.data('current-step', step);

                // Calcular ancho de la barra conectora (0% en paso 1, 33.3% en paso 2, 66.6% en paso 3, 100% en paso 4)
                var fillPercent = 0;
                if (step === 2) fillPercent = 33.33;
                else if (step === 3) fillPercent = 66.66;
                else if (step === 4) fillPercent = 100;

                $card.find('.batllie-tracking-track-fill').css('width', fillPercent + '%');

                // Actualizar cada uno de los 4 pasos
                $card.find('.batllie-tracking-step').each(function() {
                    var $stepElem = $(this);
                    var stepNum = parseInt($stepElem.data('step'), 10);
                    var $badge = $stepElem.find('.batllie-step-badge');

                    $stepElem.removeClass('is-completed is-active is-pending');

                    if (stepNum < step) {
                        $stepElem.addClass('is-completed');
                        $badge.html(checkmarkSvg);
                    } else if (stepNum === step) {
                        $stepElem.addClass('is-active');
                        $badge.text(stepNum);
                    } else {
                        $stepElem.addClass('is-pending');
                        $badge.text(stepNum);
                    }
                });

                // Actualizar texto en barra inferior
                if (stepLabel) {
                    $card.find('.status-highlight').text(stepLabel);
                }
            }

            // Inicializar ancho de barra
            updateUI(currentStep);

            // Intervalo de sondeo en vivo
            var timer = setInterval(function() {
                $.ajax({
                    url: ajaxUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_get_order_live_status',
                        order_id: orderId,
                        order_key: orderKey
                    },
                    success: function(response) {
                        if (response && response.success && response.data) {
                            var newStep = parseInt(response.data.step, 10);
                            var newLabel = response.data.step_label;
                            if (newStep !== currentStep) {
                                updateUI(newStep, newLabel);
                            }

                            // Si el pedido ya pasó a "pagado" desde la caja, ocultar y remover la sección de comprobante
                            if (response.data.show_receipt_pending === false || response.data.is_paid === true) {
                                var $receiptSection = $card.find('.batllie-tracking-receipt-pending');
                                if ($receiptSection.length && $receiptSection.is(':visible')) {
                                    $receiptSection.slideUp(400, function() {
                                        $(this).remove();
                                    });
                                }
                            }

                            // Si el pedido ya llegó al paso 4 (recibido) Y no tiene comprobante pendiente, detener sondeo
                            var stillPendingReceipt = (response.data.show_receipt_pending === true);
                            if (currentStep >= 4 && !stillPendingReceipt) {
                                clearInterval(timer);
                            }
                        }
                    },
                    error: function() {
                        // Silencioso ante pérdidas temporales de red
                    }
                });
            }, pollInterval);
        });
    });
})(jQuery);
