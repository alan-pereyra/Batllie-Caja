/**
 * Batllie Caja - Control de Mínimo de Compra en el Frontend
 * Sincronización en tiempo real ante cualquier cambio en el carrito:
 * - Interceptación de Fetch de WooCommerce Blocks (Store API)
 * - Suscripción al Store de WordPress Data (@wordpress/data)
 * - Observación de mutaciones DOM y eventos jQuery clásicos
 * - Verificación en tiempo real al hacer clic en pagar (Checkout)
 * 
 * Regla: El cartel de aviso de monto mínimo NO se puede descartar manualmente
 * por el usuario; solo se elimina automáticamente cuando el carrito alcanza el monto mínimo.
 */

(function ($) {
    'use strict';

    const config = window.batllieMinOrderConfig || {};
    const minAmount = parseFloat(config.minAmount) || 0;
    if (minAmount <= 0) {
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
     * Eliminar botones de descarte (✕) de notices relacionadas con el monto mínimo.
     */
    function removeNoticeDismissButtons() {
        const notices = document.querySelectorAll(
            '.wc-block-components-notice-banner, .woocommerce-error, .woocommerce-message, .woocommerce-NoticeGroup, [role="alert"]'
        );

        notices.forEach(function (notice) {
            const text = (notice.textContent || '').toLowerCase();
            if (text.includes('mínimo') || text.includes('minimo') || text.includes('faltan')) {
                notice.classList.add('batllie-min-order-persistent-notice');
                
                const dismissButtons = notice.querySelectorAll(
                    '.wc-block-components-notice-banner__dismiss-button, .notice-dismiss, .close, button.close, a.close, [aria-label*="dismiss" i], [aria-label*="descartar" i], [aria-label*="cerrar" i], [aria-label*="close" i]'
                );
                dismissButtons.forEach(function (btn) {
                    btn.remove();
                });
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
                $notice.find('.wc-block-components-notice-banner__dismiss-button, .notice-dismiss, .close, [aria-label*="dismiss" i], [aria-label*="descartar" i], [aria-label*="cerrar" i]').remove();

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
        const pct = minAmount > 0 ? Math.min(100, Math.round((currentAmount / minAmount) * 100)) : 100;

        config.currentAmount = currentAmount;
        config.missingAmount = missing;
        config.isBelowMin = isBelow;
        config.percentage = pct;

        const minFormatted = formatMoney(minAmount);
        const currentFormatted = formatMoney(currentAmount);
        const missingFormatted = formatMoney(missing);

        // Actualizar botones de pago
        updateCheckoutButtons(isBelow);

        // Actualizar datos dentro del modal
        $('.batllie-stat-min').html(minFormatted);
        $('.batllie-stat-current').html(currentFormatted);
        $('.batllie-stat-missing').html(missingFormatted);
        $('.batllie-min-progress-bar').css('width', pct + '%');

        if (!isBelow) {
            // ¡Monto alcanzado o carrito vacío! Cerrar modal si estaba abierto y quitar el cartel de aviso
            closeModal();
            removeMinOrderNoticeElements();
        } else {
            // Por debajo del mínimo: actualizar textos de notices existentes
            updateNoticeTexts(minFormatted, missingFormatted);
            removeNoticeDismissButtons();
        }

        // Actualizar banner si existe en la página de carrito
        const $banner = $('#batllie-min-order-cart-banner');
        if ($banner.length) {
            if (isBelow) {
                $banner.removeClass('is-met').addClass('is-below');
                $banner.find('.batllie-min-banner-title').html('Monto mínimo de compra: <strong class="batllie-min-val">' + minFormatted + '</strong>');
                $banner.find('.batllie-banner-missing-text').html(missingFormatted);
                $banner.find('.batllie-min-banner-progress-bar').css('width', pct + '%');
            } else {
                $banner.removeClass('is-below').addClass('is-met');
                $banner.find('.batllie-min-banner-title').text((config.i18n && config.i18n.successTitle) || '¡Monto mínimo de compra alcanzado!');
                $banner.find('.batllie-min-banner-subtitle').text((config.i18n && config.i18n.successSubtitle) || 'Ya puedes ir a pagar tu pedido sin inconvenientes.');
                $banner.find('.batllie-min-banner-progress-bar').css('width', '100%');
            }
        }
    }

    /**
     * Leer el monto del carrito desde el Store de Gutenberg / WooCommerce Blocks
     */
    function readWpDataCart() {
        try {
            if (window.wp && window.wp.data && window.wp.data.select) {
                const cartStore = window.wp.data.select('wc/store/cart');
                if (cartStore && typeof cartStore.getCartData === 'function') {
                    const cartData = cartStore.getCartData();
                    if (cartData && cartData.totals) {
                        const raw = cartData.totals.total_items || cartData.totals.total_price || 0;
                        const unit = cartData.totals.currency_minor_unit !== undefined ? cartData.totals.currency_minor_unit : 2;
                        return parseFloat(raw) / Math.pow(10, unit);
                    }
                }
            }
        } catch (e) {}
        return null;
    }

    /**
     * Leer el monto del carrito directamente del DOM como respaldo
     */
    function readDomCartTotal() {
        const elements = document.querySelectorAll(
            '.wp-block-woocommerce-cart-order-summary-subtotal-block .wc-block-formatted-money-amount, ' +
            '.wc-block-components-totals-subtotal .wc-block-formatted-money-amount, ' +
            '.cart-subtotal .woocommerce-Price-amount bdi, ' +
            '.order-total .woocommerce-Price-amount bdi, ' +
            '.wc-block-components-totals-item__value'
        );
        for (let i = 0; i < elements.length; i++) {
            const text = elements[i].textContent || '';
            const cleaned = text.replace(/[^0-9,\.]/g, '').trim();
            if (cleaned) {
                let val;
                if (cleaned.includes(',')) {
                    val = parseFloat(cleaned.replace(/\./g, '').replace(',', '.'));
                } else {
                    val = parseFloat(cleaned);
                }
                if (!isNaN(val) && val > 0) {
                    return val;
                }
            }
        }
        return null;
    }

    /**
     * Refrescar el estado del carrito desde la mejor fuente disponible inmediatamente
     */
    function refreshCartState() {
        const wpAmt = readWpDataCart();
        if (wpAmt !== null) {
            applyCartState(wpAmt);
            return wpAmt;
        }

        const domAmt = readDomCartTotal();
        if (domAmt !== null) {
            applyCartState(domAmt);
            return domAmt;
        }

        return null;
    }

    /**
     * Sincronizar estado del mínimo de compra vía AJAX (con el servidor)
     */
    let isSyncing = false;
    function syncMinOrderStatus() {
        refreshCartState();

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
                    applyCartState(res.data.current_amount);
                }
            },
            complete: function () {
                isSyncing = false;
            }
        });
    }

    // =========================================================================
    // 1. Interceptar Fetch de WooCommerce Blocks (Store API en Tiempo Real)
    // =========================================================================
    if (window.fetch) {
        const originalFetch = window.fetch;
        window.fetch = function () {
            const args = arguments;
            const url = (args[0] && (typeof args[0] === 'string' ? args[0] : args[0].url)) || '';

            return originalFetch.apply(this, args).then(function (response) {
                if (url.indexOf('/wc/store/v1/cart') !== -1) {
                    try {
                        response.clone().json().then(function (cartData) {
                            if (cartData && cartData.totals) {
                                const raw = cartData.totals.total_items || cartData.totals.total_price || 0;
                                const unit = cartData.totals.currency_minor_unit !== undefined ? cartData.totals.currency_minor_unit : 2;
                                const currentAmt = parseFloat(raw) / Math.pow(10, unit);
                                applyCartState(currentAmt);
                            }
                        }).catch(function () {});
                    } catch (err) {}
                }
                return response;
            });
        };
    }

    // =========================================================================
    // 2. Intercepción de Clics en Fase de Captura (Máxima Prioridad)
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
                    e.stopImmediatePropagation();
                    dismissTrigger.remove();
                    return false;
                }
            }
        }

        // B. Interceptar clic en botón de checkout si está por debajo del mínimo
        const trigger = findCheckoutTrigger(e.target);
        if (trigger) {
            // Re-evaluar estado del carrito en este milisegundo exacto
            refreshCartState();

            // Si el monto alcanza el mínimo, ¡permitir pagar sin trabas!
            if (!config.isBelowMin) {
                return true;
            }

            // SÓLO si realmente no alcanza el mínimo, bloquear y abrir modal informativo
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
    }, true); // useCapture = true garantiza ejecución antes que cualquier otro handler

    // =========================================================================
    // 3. Inicialización y Observación de Cambios en el Carrito
    // =========================================================================
    $(document).ready(function () {
        removeNoticeDismissButtons();

        // Aplicar estado inicial
        if (config.currentAmount !== undefined) {
            applyCartState(config.currentAmount);
        } else {
            refreshCartState();
        }

        // Suscripción al store de WordPress Data (@wordpress/data)
        if (window.wp && window.wp.data && window.wp.data.subscribe) {
            window.wp.data.subscribe(function () {
                removeNoticeDismissButtons();
                const amt = readWpDataCart();
                if (amt !== null && amt !== config.currentAmount) {
                    applyCartState(amt);
                }
            });
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
            removeNoticeDismissButtons();
            syncMinOrderStatus();
        });

        // Escuchar cambios DOM para WooCommerce Blocks (Gutenberg / React)
        if (window.MutationObserver) {
            let debounceTimer = null;
            const observer = new MutationObserver(function () {
                removeNoticeDismissButtons();
                refreshCartState();
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(syncMinOrderStatus, 180);
            });

            const targetNode = document.querySelector('.woocommerce-cart, .wc-block-cart, .wp-block-woocommerce-cart, form.woocommerce-cart-form') || document.body;
            observer.observe(targetNode, { childList: true, subtree: true });
        }
    });

})(jQuery);
