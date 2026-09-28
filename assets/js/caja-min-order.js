/**
 * Batllie Caja - Control de Mínimo de Compra en el Frontend
 * Sincronización en tiempo real sin recargar página:
 * - Integración nativa con @wordpress/data para WooCommerce Blocks
 * - Detección de cambios de subtotal y cálculo dinámico de faltante
 * - Verificación antes de proceder al checkout
 * - Cartel de aviso persistente (sin botón de cerrar) que se remueve automáticamente al alcanzar el mínimo
 */

(function ($) {
    'use strict';

    const config = window.batllieMinOrderConfig || {};
    const minAmount = parseFloat(config.minAmount) || 0;
    if (minAmount <= 0) {
        return; // Límite desactivado
    }

    let lastKnownAmount = parseFloat(config.currentAmount) || 0;

    const CHECKOUT_SELECTORS = [
        'a.checkout-button',
        '.checkout-button',
        'a[href*="/checkout"]',
        'a[href*="/finalizar-compra"]',
        '.wc-block-cart__submit-button',
        '.wc-block-cart__submit a',
        '.wc-block-components-checkout-place-order-button',
        'button[name="woocommerce_checkout_place_order"]',
        '#place_order',
        '.woocommerce-mini-cart__buttons .checkout',
        'a.button.checkout'
    ].join(', ');

    /**
     * Formatear monto monetario en formato argentino ($ 10.000,00)
     */
    function formatMoney(amount) {
        const num = parseFloat(amount) || 0;
        const parts = num.toFixed(2).split('.');
        const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        const decimalPart = parts[1];
        return '$ ' + integerPart + ',' + decimalPart;
    }

    /**
     * Comprobar si un elemento es o está contenido en un botón de checkout
     */
    function findCheckoutTrigger(element) {
        if (!element || element === document) return null;
        const $el = $(element);
        const match = $el.closest(CHECKOUT_SELECTORS);
        if (match.length) return match[0];

        const link = $el.closest('a');
        if (link.length) {
            const href = link.attr('href') || '';
            if (href.indexOf('checkout') !== -1 || href.indexOf('finalizar-compra') !== -1) {
                return link[0];
            }
        }
        return null;
    }

    /**
     * Actualizar estado visual de los botones de checkout
     */
    function updateCheckoutButtons(isBlocked) {
        $(CHECKOUT_SELECTORS).each(function () {
            const $btn = $(this);
            if (isBlocked) {
                $btn.addClass('batllie-checkout-blocked');
                $btn.attr('data-batllie-min-blocked', 'true');
            } else {
                $btn.removeClass('batllie-checkout-blocked');
                $btn.removeAttr('data-batllie-min-blocked');
            }
        });
    }

    /**
     * Quitar el cartel de monto mínimo del DOM y de los stores de React (WooCommerce Blocks)
     */
    function removeMinOrderNoticeElements() {
        if (window.wp && window.wp.data && window.wp.data.dispatch && window.wp.data.select) {
            try {
                const notices = window.wp.data.select('core/notices').getNotices();
                if (Array.isArray(notices)) {
                    notices.forEach(function (n) {
                        const content = ((n.content || '') + ' ' + (n.id || '')).toLowerCase();
                        if (content.includes('mínimo') || content.includes('minimo') || content.includes('faltan')) {
                            window.wp.data.dispatch('core/notices').removeNotice(n.id);
                        }
                    });
                }
            } catch (e) {}
        }

        $('.wc-block-components-notice-banner, .woocommerce-error, .woocommerce-NoticeGroup, .batllie-min-order-persistent-notice')
            .filter(function () {
                const text = ($(this).text() || '').toLowerCase();
                return text.includes('mínimo') || text.includes('minimo') || text.includes('faltan') || $(this).hasClass('batllie-min-order-persistent-notice');
            })
            .slideUp(250, function () {
                $(this).remove();
            });
    }

    /**
     * Actualizar el cartel de error con los montos actualizados mientras siga por debajo del mínimo
     */
    function updateNoticeTexts(minFormatted, missingFormatted) {
        $('.wc-block-components-notice-banner, .woocommerce-error, .batllie-min-order-persistent-notice')
            .filter(function () {
                const text = ($(this).text() || '').toLowerCase();
                return text.includes('mínimo') || text.includes('minimo') || text.includes('faltan') || $(this).hasClass('batllie-min-order-persistent-notice');
            })
            .each(function () {
                const $notice = $(this);
                $notice.addClass('batllie-min-order-persistent-notice');
                $notice.show();

                const $content = $notice.find('.wc-block-components-notice-banner__content, p, span').first();
                if ($content.length) {
                    $content.html('El monto mínimo de compra es de <strong>' + minFormatted + '</strong>. Te faltan <strong>' + missingFormatted + '</strong> para llegar al mínimo y poder ir a pagar.');
                }
            });
    }

    /**
     * Abrir modal emergente de aviso
     */
    function openModal() {
        const $backdrop = $('#batllie-min-order-backdrop');
        if (!$backdrop.length) return;

        $backdrop.css('display', 'flex');
        setTimeout(function () {
            $backdrop.addClass('is-visible');
        }, 10);

        $('body').css('overflow', 'hidden');
    }

    /**
     * Cerrar modal emergente de aviso
     */
    function closeModal() {
        const $backdrop = $('#batllie-min-order-backdrop');
        if (!$backdrop.length) return;

        $backdrop.removeClass('is-visible');
        setTimeout(function () {
            $backdrop.css('display', 'none');
            $('body').css('overflow', '');
        }, 260);
    }

    /**
     * Aplicar el estado del carrito en tiempo real de forma inmediata
     */
    function applyCartState(currentAmount) {
        currentAmount = Math.max(0, Math.round(parseFloat(currentAmount || 0) * 100) / 100);

        const isBelow = (currentAmount > 0 && currentAmount < minAmount);
        const missing = Math.max(0, Math.round((minAmount - currentAmount) * 100) / 100);

        config.currentAmount = currentAmount;
        config.missingAmount = missing;
        config.isBelowMin = isBelow;

        const minFormatted = formatMoney(minAmount);
        const currentFormatted = formatMoney(currentAmount);
        const missingFormatted = formatMoney(missing);

        // Actualizar botones de pago
        updateCheckoutButtons(isBelow);

        // Actualizar datos dentro del modal
        $('.batllie-stat-min').html(minFormatted);
        $('.batllie-stat-current').html(currentFormatted);
        $('.batllie-stat-missing').html(missingFormatted);

        if (!isBelow) {
            closeModal();
            removeMinOrderNoticeElements();
        } else {
            updateNoticeTexts(minFormatted, missingFormatted);
        }

        // Actualizar banner si existe en la página de carrito
        const $banner = $('#batllie-min-order-cart-banner');
        if ($banner.length) {
            if (isBelow) {
                $banner.removeClass('is-met').addClass('is-below');
                $banner.find('.batllie-min-banner-title').html('Monto mínimo de compra: <strong class="batllie-min-val">' + minFormatted + '</strong>');
                $banner.find('.batllie-banner-missing-text').html(missingFormatted);
            } else {
                $banner.removeClass('is-below').addClass('is-met');
                $banner.find('.batllie-min-banner-title').text((config.i18n && config.i18n.successTitle) || '¡Monto mínimo de compra alcanzado!');
                $banner.find('.batllie-min-banner-subtitle').text((config.i18n && config.i18n.successSubtitle) || 'Ya puedes ir a pagar tu pedido sin inconvenientes.');
            }
        }
    }

    /**
     * Leer el monto del carrito desde el Store de Gutenberg / WooCommerce Blocks
     */
    function checkWpCart() {
        if (!window.wp || !window.wp.data || !window.wp.data.select) return;
        try {
            const cartStore = window.wp.data.select('wc/store/cart');
            if (!cartStore || typeof cartStore.getCartData !== 'function') return;
            const cartData = cartStore.getCartData();
            if (!cartData || !cartData.totals) return;

            const raw = cartData.totals.total_items || cartData.totals.total_price || 0;
            const unit = cartData.totals.currency_minor_unit !== undefined ? cartData.totals.currency_minor_unit : 2;
            const amt = parseFloat(raw) / Math.pow(10, unit);

            if (amt !== lastKnownAmount) {
                lastKnownAmount = amt;
                applyCartState(amt);
            }
        } catch (e) {}
    }

    /**
     * Sincronizar estado del mínimo de compra vía AJAX (con el servidor)
     */
    let isSyncing = false;
    function syncMinOrderStatus() {
        if (isSyncing) return;
        isSyncing = true;

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'emp_caja_get_min_order_status',
                security: config.nonce
            },
            success: function (res) {
                if (res && res.success && res.data) {
                    lastKnownAmount = res.data.current_amount;
                    applyCartState(res.data.current_amount);
                }
            },
            complete: function () {
                isSyncing = false;
            }
        });
    }

    // =========================================================================
    // Intercepción de Clics en Fase de Captura (Máxima Prioridad)
    // =========================================================================
    document.addEventListener('click', function (e) {
        // A. Bloquear intentos de descartar el cartel de monto mínimo
        const dismissTrigger = e.target.closest(
            '.wc-block-components-notice-banner__dismiss-button, .notice-dismiss, button.close, [aria-label*="dismiss" i], [aria-label*="descartar" i], [aria-label*="cerrar" i]'
        );
        if (dismissTrigger) {
            const noticeParent = dismissTrigger.closest('.wc-block-components-notice-banner, .woocommerce-error, .batllie-min-order-persistent-notice');
            if (noticeParent) {
                const text = (noticeParent.textContent || '').toLowerCase();
                if (text.includes('mínimo') || text.includes('minimo') || text.includes('faltan')) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }
        }

        // B. Interceptar clic en botón de checkout si está por debajo del mínimo
        const trigger = findCheckoutTrigger(e.target);
        if (trigger) {
            // Verificar estado en este instante
            checkWpCart();

            // Si el monto alcanza el mínimo, permitir avanzar normalmente
            if (!config.isBelowMin) {
                return true;
            }

            // Si no alcanza el mínimo, bloquear y abrir modal informativo
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            openModal();

            const banner = document.getElementById('batllie-min-order-cart-banner');
            if (banner) {
                banner.classList.remove('batllie-shake');
                void banner.offsetWidth;
                banner.classList.add('batllie-shake');
            }

            return false;
        }
    }, true);

    // =========================================================================
    // Inicialización y Observación de Cambios en el Carrito
    // =========================================================================
    $(document).ready(function () {
        // Aplicar estado inicial
        if (config.currentAmount !== undefined) {
            applyCartState(config.currentAmount);
        }

        // Suscripción al store de WordPress Data (@wordpress/data)
        if (window.wp && window.wp.data && window.wp.data.subscribe) {
            window.wp.data.subscribe(function () {
                checkWpCart();
            });
        }

        // Chequeo periódico suave (cada 700ms) para detectar cambios en el carrito de bloques
        setInterval(checkWpCart, 700);

        // Eventos de cierre del modal
        $(document).on('click', '#batllie-min-modal-close-btn, #batllie-min-modal-dismiss-btn', function (e) {
            e.preventDefault();
            closeModal();
        });

        $(document).on('click', '#batllie-min-order-backdrop', function (e) {
            if (e.target === this) {
                closeModal();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                closeModal();
            }
        });

        // Escuchar eventos de actualización de carrito en WooCommerce clásico
        $(document.body).on('updated_wc_div updated_cart_totals added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded', function () {
            syncMinOrderStatus();
        });
    });

})(jQuery);
