/**
 * Batllie Caja - Control de Mínimo de Compra y Empaque de Alfajores en el Frontend
 * Sincronización en tiempo real sin recargar página:
 * - Integración nativa con @wordpress/data para WooCommerce Blocks
 * - Motor de empaque (Packing Engine) con barra de progreso gamificada
 * - Adición de sabores en 1 clic desde el modal/carrito vía AJAX
 * - Concesión de Caja de Cortesía o bloqueo imperativo si faltan 1-2 unidades
 * - Cartel de aviso persistente que se remueve automáticamente al alcanzar el mínimo
 */

(function ($) {
    'use strict';

    const config = window.batllieMinOrderConfig || {};
    const minAmount = parseFloat(config.minAmount) || 0;
    const hasPacking = Boolean(config.packing && config.packing.has_alfajores);

    if (minAmount <= 0 && !hasPacking) {
        return; // Sin restricciones activas
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
     * Determinar si la finalización de compra está bloqueada
     */
    function isCheckoutBlocked() {
        const minBlocked = (minAmount > 0 && config.isBelowMin);
        const packingBlocked = Boolean(config.packing && config.packing.is_blocked);
        return minBlocked || packingBlocked;
    }

    /**
     * Actualizar estado visual de los botones de checkout
     */
    function updateCheckoutButtons(isBlocked) {
        if (isBlocked === undefined) {
            isBlocked = isCheckoutBlocked();
        }
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
            .remove();
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
     * Ejecutar efecto de sacudida en el banner si existe
     */
    function triggerShakeBanner() {
        const banner = document.getElementById('batllie-min-order-cart-banner');
        if (banner) {
            banner.classList.remove('batllie-shake');
            void banner.offsetWidth;
            banner.classList.add('batllie-shake');
        }
    }

    /**
     * Actualizar la interfaz de empaque y barra de progreso de cajas
     */
    function updatePackingUI(packing) {
        if (!packing || !packing.has_alfajores) {
            $('#batllie-modal-packing-section').hide();
            return;
        }

        config.packing = packing;
        const $sec = $('#batllie-modal-packing-section');
        $sec.show();

        const units = parseInt(packing.loose_alfajores, 10) || 0;
        let rem = units % 6;
        if (rem === 0 && units > 0) {
            rem = 6;
        }
        const pct = (rem > 0) ? Math.min(100, Math.round((rem / 6) * 100)) : 0;

        $('#batllie-packing-count').text(rem + ' de 6 alfajores');
        $('#batllie-packing-bar-fill').css('width', pct + '%');

        if (packing.status === 'all_boxed') {
            $('#batllie-packing-title').text('¡Caja Completa!');
            $('#batllie-packing-subtitle').text('Tus alfajores viajan en caja cerrada oficial Batllié.');
            $('#batllie-packing-badge').text('¡Caja al 100%! 💌');
            $('#batllie-packing-badge').removeClass('is-missing').addClass('is-complete');
            $('#batllie-btn-accept-courtesy').hide();
        } else if (packing.is_blocked) {
            $('#batllie-packing-title').text('Completá tu Caja para Despachar');
            $('#batllie-packing-subtitle').text(packing.message || ('Estás a solo ' + packing.missing_units + ' alfajor(es) de completar tu caja.'));
            $('#batllie-packing-badge').text('Faltan solo ' + packing.missing_units + ' para completar');
            $('#batllie-packing-badge').removeClass('is-complete').addClass('is-missing');
            $('#batllie-btn-accept-courtesy').hide();
        } else if (packing.status === 'courtesy_available') {
            $('#batllie-packing-title').text('¡Mejorá tu Experiencia Batllié!');
            $('#batllie-packing-subtitle').text(packing.message || 'Sumá los alfajores restantes para cerrar tu caja o avanzá con nuestra Caja de Cortesía de regalo.');
            $('#batllie-packing-badge').text('Faltan ' + packing.missing_units + ' para completar');
            $('#batllie-packing-badge').removeClass('is-complete').addClass('is-missing');
            $('#batllie-btn-accept-courtesy').show();
        } else if (packing.status === 'no_prior_box') {
            $('#batllie-packing-title').text('Comenzá tu Experiencia Batllié');
            $('#batllie-packing-subtitle').text(packing.message || ('Sumá solo ' + packing.missing_units + ' alfajor(es) más para recibir tu primera caja oficial.'));
            $('#batllie-packing-badge').text('Faltan ' + packing.missing_units + ' para caja de 6');
            $('#batllie-packing-badge').removeClass('is-complete').addClass('is-missing');
            $('#batllie-btn-accept-courtesy').hide();
        }

        updateCheckoutButtons();
    }

    /**
     * Aplicar el estado del carrito en tiempo real de forma inmediata
     */
    function applyCartState(currentAmount) {
        currentAmount = Math.max(0, Math.round(parseFloat(currentAmount || 0) * 100) / 100);

        const isBelow = (minAmount > 0 && currentAmount > 0 && currentAmount < minAmount);
        const missing = Math.max(0, Math.round((minAmount - currentAmount) * 100) / 100);

        config.currentAmount = currentAmount;
        config.missingAmount = missing;
        config.isBelowMin = isBelow;

        const minFormatted = formatMoney(minAmount);
        const currentFormatted = formatMoney(currentAmount);
        const missingFormatted = formatMoney(missing);

        // Actualizar botones de pago
        updateCheckoutButtons();

        // Actualizar datos dentro del modal
        $('.batllie-stat-min').html(minFormatted);
        $('.batllie-stat-current').html(currentFormatted);
        $('.batllie-stat-missing').html(missingFormatted);

        if (!isBelow) {
            $('#batllie-modal-min-stats').hide();
            if (!isCheckoutBlocked()) {
                closeModal();
            }
        } else {
            $('#batllie-modal-min-stats').show();
        }

        removeMinOrderNoticeElements();

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
                syncMinOrderStatus();
            }
        } catch (e) {}
    }

    /**
     * Sincronizar estado del mínimo de compra y empaque vía AJAX
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
                    if (res.data.packing) {
                        updatePackingUI(res.data.packing);
                    }
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

        // B. Interceptar clic en botón de checkout
        const trigger = findCheckoutTrigger(e.target);
        if (trigger) {
            checkWpCart();

            const isBelowMin = (minAmount > 0 && config.isBelowMin);
            const isPackingBlocked = Boolean(config.packing && config.packing.is_blocked);
            const isCourtesyAvailable = Boolean(config.packing && config.packing.status === 'courtesy_available');
            const hasAcceptedCourtesy = (sessionStorage.getItem('batllie_courtesy_accepted') === '1');

            // Caso 1: Bloqueo por Monto Mínimo
            if (isBelowMin) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                $('#batllie-modal-min-stats').show();
                if (config.packing && config.packing.has_alfajores && config.packing.status !== 'all_boxed') {
                    updatePackingUI(config.packing);
                    $('#batllie-modal-packing-section').show();
                } else {
                    $('#batllie-modal-packing-section').hide();
                }

                openModal();
                triggerShakeBanner();
                return false;
            }

            // Caso 2: Bloqueo Imperativo de Empaque (faltan 1 o 2 unidades)
            if (isPackingBlocked) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                $('#batllie-modal-min-stats').hide();
                if (config.packing) {
                    updatePackingUI(config.packing);
                }
                $('#batllie-modal-packing-section').show();

                openModal();
                triggerShakeBanner();
                return false;
            }

            // Caso 3: Caja de Cortesía disponible (faltan >= 3 unidades, con caja previa)
            if (isCourtesyAvailable && !hasAcceptedCourtesy) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                $('#batllie-modal-min-stats').hide();
                if (config.packing) {
                    updatePackingUI(config.packing);
                }
                $('#batllie-modal-packing-section').show();

                openModal();
                return false;
            }

            // Si no hay bloqueo, permitir avanzar normalmente
            return true;
        }
    }, true);

    // =========================================================================
    // Inicialización y Observación de Cambios en el Carrito
    // =========================================================================
    removeMinOrderNoticeElements();
    $(document).ready(function () {
        removeMinOrderNoticeElements();
        $(document).ajaxComplete(function () {
            removeMinOrderNoticeElements();
        });

        // Aplicar estado inicial
        if (config.currentAmount !== undefined) {
            applyCartState(config.currentAmount);
        }
        if (config.packing) {
            updatePackingUI(config.packing);
        }

        // Suscripción al store de WordPress Data (@wordpress/data)
        if (window.wp && window.wp.data && window.wp.data.subscribe) {
            window.wp.data.subscribe(function () {
                checkWpCart();
            });
        }

        // Chequeo periódico suave (cada 700ms) para detectar cambios en el carrito de bloques
        setInterval(checkWpCart, 700);

        // Evento: Agregar alfajor rápido en 1 clic (+1)
        $(document).on('click', '.batllie-flavor-add-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(this);
            const productId = $btn.data('id');
            if (!productId || $btn.hasClass('is-loading')) return;

            $btn.addClass('is-loading');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_quick_add_alfajor',
                    product_id: productId,
                    quantity: 1,
                    security: config.nonce
                },
                success: function (res) {
                    if (res && res.success && res.data) {
                        if (res.data.analysis) {
                            updatePackingUI(res.data.analysis);
                        }
                        if (res.data.current_amount !== undefined) {
                            lastKnownAmount = res.data.current_amount;
                            applyCartState(res.data.current_amount);
                        }

                        // Notificar a WooCommerce de la adición
                        $(document.body).trigger('wc_fragment_refresh');
                        $(document.body).trigger('added_to_cart');

                        // Si hay Store de Gutenberg / WooCommerce Blocks, refrescar
                        if (window.wp && window.wp.data && window.wp.data.dispatch) {
                            try {
                                const cartStore = window.wp.data.dispatch('wc/store/cart');
                                if (cartStore && typeof cartStore.invalidateResolutionForStore === 'function') {
                                    cartStore.invalidateResolutionForStore();
                                }
                            } catch (err) {}
                        }

                        // Si ahora está completa la caja y no está por debajo del mínimo, auto-cerrar
                        if (res.data.analysis && res.data.analysis.status === 'all_boxed' && !res.data.is_below_min) {
                            setTimeout(function () {
                                closeModal();
                            }, 1200);
                        }
                    }
                },
                error: function () {
                    alert('No se pudo agregar el alfajor al pedido. Por favor intentalo nuevamente.');
                },
                complete: function () {
                    $btn.removeClass('is-loading');
                }
            });
        });

        // Evento: Aceptar Caja de Cortesía y avanzar al checkout
        $(document).on('click', '#batllie-btn-accept-courtesy', function (e) {
            e.preventDefault();
            sessionStorage.setItem('batllie_courtesy_accepted', '1');
            closeModal();

            const $firstCheckout = $(CHECKOUT_SELECTORS).first();
            if ($firstCheckout.length) {
                const href = $firstCheckout.attr('href');
                if (href && href !== '#' && href.indexOf('javascript') === -1) {
                    window.location.href = href;
                } else {
                    $firstCheckout[0].click();
                }
            }
        });

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
