/**
 * Batllie Caja - Control de Mínimo de Compra en el Frontend
 * Intercepta intentos de ir a pagar cuando el carrito no alcanza el mínimo,
 * muestra alerta con el monto faltante y actualiza en tiempo real.
 */

(function ($) {
    'use strict';

    const config = window.batllieMinOrderConfig || {};
    if (!config.minAmount || parseFloat(config.minAmount) <= 0) {
        return; // Límite desactivado
    }

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
     * Comprobar si un elemento es o está contenido en un botón de checkout
     */
    function findCheckoutTrigger(element) {
        if (!element || element === document) return null;
        const $el = $(element);
        const match = $el.closest(CHECKOUT_SELECTORS);
        if (match.length) return match[0];

        // Verificar por href directo si es un enlace
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
     * Abrir modal emergente de aviso
     */
    function openModal() {
        const $backdrop = $('#batllie-min-order-backdrop');
        if (!$backdrop.length) return;

        $backdrop.css('display', 'flex');
        // Pequeño timeout para animación CSS
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
     * Sincronizar estado del mínimo de compra vía AJAX (tras cambios en carrito)
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
                    const data = res.data;
                    config.isBelowMin = data.is_below_min;
                    config.currentAmount = data.current_amount;
                    config.missingAmount = data.missing_amount;
                    config.percentage = data.percentage;

                    // Actualizar botones de pago
                    updateCheckoutButtons(data.is_below_min);

                    // Actualizar datos dentro del modal
                    $('.batllie-stat-current').html(data.current_formatted);
                    $('.batllie-stat-missing').html(data.missing_formatted);
                    $('.batllie-min-progress-bar').css('width', data.percentage + '%');

                    // Actualizar banner en la página de carrito
                    const $banner = $('#batllie-min-order-cart-banner');
                    if ($banner.length) {
                        if (data.is_below_min) {
                            $banner.removeClass('is-met').addClass('is-below');
                            $banner.find('.batllie-min-banner-icon').text('⚠️');
                            $banner.find('.batllie-min-banner-title').html('Monto mínimo de compra: <strong class="batllie-min-val">' + data.min_formatted + '</strong>');
                            $banner.find('.batllie-banner-missing-text').html(data.missing_formatted);
                            $banner.find('.batllie-min-banner-progress-bar').css('width', data.percentage + '%');
                        } else {
                            $banner.removeClass('is-below').addClass('is-met');
                            $banner.find('.batllie-min-banner-icon').text('🎉');
                            $banner.find('.batllie-min-banner-title').text((config.i18n && config.i18n.successTitle) || '¡Monto mínimo de compra alcanzado!');
                            $banner.find('.batllie-min-banner-subtitle').text((config.i18n && config.i18n.successSubtitle) || 'Ya puedes ir a pagar tu pedido sin inconvenientes.');
                            $banner.find('.batllie-min-banner-progress-bar').css('width', '100%');

                            // Si el modal estaba abierto y ya alcanzó el mínimo, cerrarlo
                            closeModal();
                        }
                    }
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
        if (!config.isBelowMin) {
            return;
        }

        const trigger = findCheckoutTrigger(e.target);
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            openModal();

            // Animación de shake en el banner si está presente
            const banner = document.getElementById('batllie-min-order-cart-banner');
            if (banner) {
                banner.classList.remove('batllie-shake');
                void banner.offsetWidth; // Forzar reflow
                banner.classList.add('batllie-shake');
            }

            return false;
        }
    }, true); // useCapture = true garantiza ejecución antes que cualquier otro handler

    // =========================================================================
    // Inicialización y Eventos DOM
    // =========================================================================
    $(document).ready(function () {
        if (config.isBelowMin) {
            updateCheckoutButtons(true);
        }

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

        // Escuchar cambios DOM para WooCommerce Blocks (Gutenberg / React)
        if (window.MutationObserver) {
            const cartRoot = document.querySelector('.woocommerce-cart, .wc-block-cart, .wp-block-woocommerce-cart, form.woocommerce-cart-form');
            if (cartRoot) {
                let debounceTimer = null;
                const observer = new MutationObserver(function () {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(syncMinOrderStatus, 120);
                });
                observer.observe(cartRoot, { childList: true, subtree: true });
            }
        }
    });

})(jQuery);
