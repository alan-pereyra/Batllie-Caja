/**
 * Batllie Caja & Pedidos POS - Script de Seguimiento en Vivo
 * Actualiza automáticamente el estado y la barra de progreso sin recargar la página,
 * emitiendo alertas sonoras y notificaciones visuales ante cualquier cambio de estado.
 */

(function($) {
    'use strict';

    // =========================================================================
    // Módulo de Audio Web API para Notificaciones Sonoras
    // =========================================================================
    var audioCtx = null;
    function getAudioContext() {
        try {
            if (!audioCtx) {
                var AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (AudioContextClass) {
                    audioCtx = new AudioContextClass();
                }
            }
            if (audioCtx && audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
        } catch (e) {}
        return audioCtx;
    }

    function unlockAudio() {
        var ctx = getAudioContext();
        if (ctx && ctx.state === 'running') {
            ['click', 'touchstart', 'touchend', 'pointerdown', 'keydown'].forEach(function(evt) {
                document.removeEventListener(evt, unlockAudio, true);
            });
        }
    }
    ['click', 'touchstart', 'touchend', 'pointerdown', 'keydown'].forEach(function(evt) {
        document.addEventListener(evt, unlockAudio, { passive: true, capture: true });
    });

    function playNotificationSound() {
        try {
            var ctx = getAudioContext();
            if (!ctx) return;
            var now = ctx.currentTime;

            // Campana armónica de aviso (D5, A5, D6, A6 armónico)
            var tones = [
                { freq: 587.33, start: 0.00, dur: 0.35, vol: 0.65 },
                { freq: 880.00, start: 0.12, dur: 0.45, vol: 0.75 },
                { freq: 1174.66, start: 0.24, dur: 0.65, vol: 0.85 },
                { freq: 1760.00, start: 0.24, dur: 0.40, vol: 0.35 }
            ];

            tones.forEach(function(t) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(t.freq, now + t.start);
                gain.gain.setValueAtTime(0.0001, now + t.start);
                gain.gain.exponentialRampToValueAtTime(t.vol, now + t.start + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + t.start + t.dur);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(now + t.start);
                osc.stop(now + t.start + t.dur);
            });

            // Haptic feedback en dispositivos móviles compatibles
            if (typeof navigator !== 'undefined' && navigator.vibrate) {
                navigator.vibrate([120, 80, 200]);
            }
        } catch (err) {
            console.warn('Batllie Tracking: Audio alert error', err);
        }
    }

    // =========================================================================
    // Notificaciones Visuales: Toast Flotante y Título de Pestaña
    // =========================================================================
    var toastTimer = null;
    function showStatusToast(message, title) {
        title = title || '¡Actualización de tu pedido!';
        var $toast = $('#batllie-tracking-toast');
        if (!$toast.length) {
            $toast = $(
                '<div id="batllie-tracking-toast" class="batllie-tracking-toast" role="status" aria-live="polite">' +
                    '<div class="batllie-toast-icon">' +
                        '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
                            '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>' +
                            '<path d="M13.73 21a2 2 0 0 1-3.46 0"></path>' +
                        '</svg>' +
                    '</div>' +
                    '<div class="batllie-toast-content">' +
                        '<div class="batllie-toast-title"></div>' +
                        '<div class="batllie-toast-msg"></div>' +
                    '</div>' +
                    '<button type="button" class="batllie-toast-close" aria-label="Cerrar">&times;</button>' +
                '</div>'
            );
            $('body').append($toast);

            $toast.on('click', '.batllie-toast-close', function(e) {
                e.stopPropagation();
                $toast.removeClass('is-visible');
            });

            $toast.on('click', function() {
                var $card = $('.batllie-order-tracking-card').first();
                if ($card.length) {
                    $('html, body').animate({ scrollTop: $card.offset().top - 20 }, 400);
                }
            });
        }

        $toast.find('.batllie-toast-title').text(title);
        $toast.find('.batllie-toast-msg').text(message);

        $toast.removeClass('is-visible');
        void $toast[0].offsetWidth; // trigger reflow
        $toast.addClass('is-visible');

        if (toastTimer) {
            clearTimeout(toastTimer);
        }
        toastTimer = setTimeout(function() {
            $toast.removeClass('is-visible');
        }, 8000);
    }

    var titleFlashInterval = null;
    var originalDocTitle = document.title;
    function flashTabTitle(newLabel) {
        if (!document.hidden) return;
        if (titleFlashInterval) {
            clearInterval(titleFlashInterval);
        }
        var alt = false;
        titleFlashInterval = setInterval(function() {
            if (!document.hidden) {
                clearInterval(titleFlashInterval);
                titleFlashInterval = null;
                document.title = originalDocTitle;
                return;
            }
            document.title = alt ? '🔔 ¡Pedido actualizado!' : originalDocTitle;
            alt = !alt;
        }, 1200);
    }

    $(document).on('visibilitychange', function() {
        if (!document.hidden && titleFlashInterval) {
            clearInterval(titleFlashInterval);
            titleFlashInterval = null;
            document.title = originalDocTitle;
        }
    });

    function sendBrowserNotification(newLabel, orderNumber) {
        if ('Notification' in window) {
            if (Notification.permission === 'granted') {
                try {
                    new Notification('Batllie - Pedido #' + orderNumber, {
                        body: newLabel,
                        icon: '/favicon.ico'
                    });
                } catch (err) {}
            } else if (Notification.permission === 'default') {
                try {
                    Notification.requestPermission();
                } catch (err) {}
            }
        }
    }

    // =========================================================================
    // Seguimiento y Actualización de Pedidos
    // =========================================================================
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

        // Limpiar secciones de instrucciones no deseadas en detalles bancarios BACS
        $('.woocommerce-bacs-bank-details > .emp, .woocommerce-bacs-bank-details > h2:not(.wc-bacs-bank-details-heading), .woocommerce-bacs-bank-details > p, .woocommerce-order > .emp, .woocommerce-order > h2.emp').remove();

        // Ocultar / remover fila de acciones (Pagar / Cancelar) en la tabla de detalles del pedido
        $('.order-actions--heading, .order-actions-button, a.button.pay, a.button.cancel').closest('tr').remove();

        var ajaxUrl = (typeof emp_caja_tracking_params !== 'undefined' && emp_caja_tracking_params.ajax_url) 
            ? emp_caja_tracking_params.ajax_url 
            : '/wp-admin/admin-ajax.php';
        
        var pollInterval = (typeof emp_caja_tracking_params !== 'undefined' && emp_caja_tracking_params.poll_interval)
            ? parseInt(emp_caja_tracking_params.poll_interval, 10) * 1000
            : 10000;

        var checkmarkSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

        // Solicitar amablemente permisos de notificación web en la primera interacción
        $(document).one('click touchstart', function() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission().catch(function() {});
            }
        });

        $cards.each(function() {
            var $card = $(this);
            var orderId = $card.data('order-id');
            var orderKey = $card.data('order-key');
            var orderNumber = $card.data('order-number') || orderId;
            var currentStep = parseInt($card.data('current-step'), 10) || 1;
            var currentLabel = $card.find('.status-highlight').text().trim();
            var currentStatus = $card.data('order-status') || '';
            var currentShipping = $card.data('shipping-status') || '';
            var isDoor = ($card.data('is-door') == '1') || (currentShipping === 'en_puerta');
            var isPaid = ($card.data('is-paid') == '1');

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
                        if (step >= 4) {
                            $stepElem.addClass('is-active is-completed');
                            $badge.html(checkmarkSvg);
                        } else {
                            $stepElem.addClass('is-active');
                            $badge.text(stepNum);
                        }
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

            // Inicializar ancho de barra y estado
            updateUI(currentStep, currentLabel);

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
                            var newLabel = response.data.step_label ? response.data.step_label.trim() : '';
                            var newStatus = response.data.status || '';
                            var newShipping = response.data.shipping_status || '';
                            var newIsPaid = Boolean(response.data.is_paid);
                            var newIsDoor = Boolean(response.data.is_door) || (newShipping === 'en_puerta');

                            var stepChanged = (!isNaN(newStep) && newStep !== currentStep);
                            var labelChanged = (newLabel && newLabel !== currentLabel);
                            var statusChanged = (newStatus && newStatus !== currentStatus);
                            var shippingChanged = (newShipping && newShipping !== currentShipping);
                            var doorStateChanged = (newIsDoor !== isDoor);
                            var paymentBecamePaid = (!isPaid && newIsPaid);

                            if (stepChanged || labelChanged || statusChanged || shippingChanged || doorStateChanged) {
                                // Transición: Repartidor en la puerta
                                if (newIsDoor) {
                                    isDoor = true;
                                    $card.data('is-door', '1');
                                    $card.addClass('is-door-active');
                                    $card.find('.batllie-tracking-header, .batllie-tracking-stepper, .batllie-tracking-footer-bar, .batllie-tracking-receipt-pending').slideUp(250);
                                    $card.find('.batllie-tracking-door-screen').slideDown(350).css('display', 'flex');

                                    // Alertas y notificaciones para el cliente
                                    playNotificationSound();
                                    showStatusToast('Que disfrutes tu pedido', '🚪 ¡El repartidor está en la puerta!');
                                    flashTabTitle('🚪 ¡El repartidor está en la puerta!');
                                    sendBrowserNotification('El repartidor está en la puerta. Que disfrutes tu pedido.', orderNumber);
                                } else if (isDoor && !newIsDoor) {
                                    // Transición: Salida de estado en la puerta (se marca recibido en caja)
                                    isDoor = false;
                                    $card.data('is-door', '0');
                                    $card.removeClass('is-door-active');
                                    $card.find('.batllie-tracking-door-screen').slideUp(250, function() {
                                        $(this).hide();
                                    });
                                    $card.find('.batllie-tracking-header, .batllie-tracking-stepper, .batllie-tracking-footer-bar').slideDown(350);

                                    updateUI(newStep, newLabel);
                                    playNotificationSound();
                                    showStatusToast(newLabel, '¡Pedido #' + orderNumber + ' recibido!');
                                    flashTabTitle(newLabel);
                                    sendBrowserNotification(newLabel, orderNumber);
                                } else {
                                    updateUI(newStep, newLabel);
                                    playNotificationSound();
                                    showStatusToast(newLabel, '¡Pedido #' + orderNumber + ' actualizado!');
                                    flashTabTitle(newLabel);
                                    sendBrowserNotification(newLabel, orderNumber);
                                }

                                currentStep = newStep;
                                currentLabel = newLabel;
                                currentStatus = newStatus;
                                currentShipping = newShipping;

                                // Resaltar tarjeta visualmente
                                $card.addClass('has-updated-pulse');
                                setTimeout(function() {
                                    $card.removeClass('has-updated-pulse');
                                    }, 2400);

                                // Sincronizar actualización con localStorage y chips del selector
                                try {
                                    var raw = localStorage.getItem('batllie_recent_orders');
                                    if (raw) {
                                        var orders = JSON.parse(raw);
                                        if (Array.isArray(orders)) {
                                            for (var i = 0; i < orders.length; i++) {
                                                if (String(orders[i].id) === String(orderId)) {
                                                    orders[i].step = newStep;
                                                    orders[i].step_label = newIsDoor ? 'El repartidor está en la puerta' : newLabel;
                                                    break;
                                                }
                                            }
                                            localStorage.setItem('batllie_recent_orders', JSON.stringify(orders));
                                        }
                                    }
                                    var $chip = $('#batllie-orders-switcher .batllie-switcher-chip[data-order-id="' + orderId + '"]');
                                    if ($chip.length) {
                                        $chip.find('.batllie-chip-dot').attr('class', 'batllie-chip-dot ' + (newIsDoor ? 'step-3 is-door' : ('step-' + newStep)));
                                        $chip.find('.batllie-chip-step-txt').text(newIsDoor ? 'El repartidor está en la puerta' : newLabel);
                                    }
                                } catch (err) {}
                            } else if (paymentBecamePaid) {
                                isPaid = true;
                                $card.data('is-paid', '1');
                                playNotificationSound();
                                showStatusToast('¡Comprobante verificado! Pago acreditado.', '¡Pago confirmado!');
                                flashTabTitle('¡Pago acreditado!');
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

                            // Si el pedido ya llegó al paso 4 (recibido) Y no está en puerta Y no tiene comprobante pendiente, detener sondeo
                            var stillPendingReceipt = (response.data.show_receipt_pending === true);
                            if (currentStep >= 4 && !newIsDoor && !stillPendingReceipt) {
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

        // Botón copiar alias al portapapeles
        $(document).on('click', '.batllie-receipt-copy-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var textToCopy = $btn.attr('data-copy-text') || '';
            if (!textToCopy) return;

            var $label = $btn.find('.batllie-copy-label');
            var originalText = $label.text();

            var onSuccess = function() {
                $btn.addClass('is-copied');
                $label.text('¡Copiado!');
                setTimeout(function() {
                    $btn.removeClass('is-copied');
                    $label.text(originalText || 'Copiar');
                }, 2000);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(onSuccess).catch(function() {
                    copyFallback(textToCopy, onSuccess);
                });
            } else {
                copyFallback(textToCopy, onSuccess);
            }
        });

        function copyFallback(text, cb) {
            var $temp = $('<textarea>');
            $temp.css({ position: 'fixed', left: '-9999px', top: '0', opacity: '0' });
            $('body').append($temp);
            $temp.val(text).select();
            try {
                document.execCommand('copy');
                if (typeof cb === 'function') cb();
            } catch (err) {}
            $temp.remove();
        }
    });
})(jQuery);
