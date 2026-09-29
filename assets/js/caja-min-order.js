/**
 * Batllie Caja - Control de Mínimo de Compra y Empaque de Alfajores en el Frontend
 * Sincronización reactiva en tiempo real sin recargar página:
 * - Detección instantánea de cambios de cantidad en WooCommerce Blocks y clásico (0ms lag)
 * - Motor de empaque client-side en espejo con el servidor (sin desincronización)
 * - Desglose detallado: Total de alfajores, cajas cerradas completas y caja en armado
 * - Adición de sabores en 1 clic desde el modal/carrito vía AJAX
 * - Concesión de Caja de Cortesía o bloqueo imperativo si faltan 1-2 unidades
 * - Cartel de aviso persistente que se remueve automáticamente al alcanzar el mínimo
 */

(function ($) {
    'use strict';

    const config = window.batllieMinOrderConfig || {};
    const minAmount = parseFloat(config.minAmount) || 0;
    const hasInitialPacking = Boolean(config.packing && config.packing.has_alfajores);

    if (minAmount <= 0 && !hasInitialPacking) {
        return; // Sin restricciones activas
    }

    let lastKnownAmount = parseFloat(config.currentAmount) || 0;
    const alfajorIdSet = new Set((config.availableAlfajores || []).map(function (a) {
        return parseInt(a.id, 10);
    }));

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
     * Comprobar si un ítem representa un alfajor
     */
    function isAlfajorItem(item) {
        if (!item) return false;
        const id = parseInt(item.id || item.product_id, 10);
        if (id && alfajorIdSet.has(id)) return true;

        const name = (item.name || item.title || '').toLowerCase();
        if (name.includes('alfajor')) return true;

        if (Array.isArray(item.categories)) {
            for (let i = 0; i < item.categories.length; i++) {
                const c = item.categories[i];
                const cText = ((c.name || '') + ' ' + (c.slug || '')).toLowerCase();
                if (cText.includes('alfajor')) return true;
            }
        }
        return false;
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
     * Leer el estado actual del carrito (desde React store de Blocks o DOM) en tiempo real
     */
    function getCartStateNow() {
        let totalAlfajores = 0;
        let looseAlfajores = 0;
        let hasGroupedPack = false;
        let cartTotal = 0;
        let foundItems = false;

        // 1. Store de Gutenberg / WooCommerce Blocks
        if (window.wp && window.wp.data && window.wp.data.select) {
            try {
                const cartStore = window.wp.data.select('wc/store/cart');
                if (cartStore && typeof cartStore.getCartData === 'function') {
                    const data = cartStore.getCartData();
                    if (data) {
                        if (data.totals) {
                            const raw = data.totals.total_items || data.totals.total_price || 0;
                            const unit = data.totals.currency_minor_unit !== undefined ? data.totals.currency_minor_unit : 2;
                            cartTotal = parseFloat(raw) / Math.pow(10, unit);
                        }
                        if (Array.isArray(data.items) && data.items.length > 0) {
                            foundItems = true;
                            data.items.forEach(function (item) {
                                if (isAlfajorItem(item)) {
                                    const q = parseInt(item.quantity, 10) || 0;
                                    const isPack = Array.isArray(item.item_data) && item.item_data.some(function (d) {
                                        return d.key === 'batllie_parent_grouped_id' || d.key === '_batllie_pack_instance_id';
                                    });
                                    if (isPack) {
                                        hasGroupedPack = true;
                                    } else {
                                        looseAlfajores += q;
                                    }
                                    totalAlfajores += q;
                                }
                            });
                        }
                    }
                }
            } catch (e) {}
        }

        // 2. Inspección del DOM si Blocks aún no cargó sus datos
        if (!foundItems) {
            let domTotalAlfajores = 0;
            let domFound = false;

            $('.wc-block-cart-items__row, .wc-block-components-cart-line-item, tr.cart_item').each(function () {
                const $row = $(this);
                const text = $row.text().toLowerCase();
                if (text.includes('alfajor')) {
                    domFound = true;
                    let q = 0;
                    const $input = $row.find('input.wc-block-components-quantity-selector__input, input.qty, input[type="number"]');
                    if ($input.length) {
                        q = parseInt($input.val(), 10) || 0;
                    } else {
                        const $qtyBadge = $row.find('.wc-block-components-cart-line-item__quantity, .product-quantity');
                        const match = ($qtyBadge.text() || '').match(/\d+/);
                        if (match) q = parseInt(match[0], 10) || 0;
                    }
                    domTotalAlfajores += q;
                }
            });

            if (domFound) {
                foundItems = true;
                totalAlfajores = domTotalAlfajores;
                looseAlfajores = domTotalAlfajores;
            }
        }

        // 3. Fallback a configuración de PHP si el carrito no tiene cambios
        if (!foundItems && config.packing) {
            totalAlfajores = config.packing.total_alfajores || 0;
            looseAlfajores = config.packing.loose_alfajores || 0;
            hasGroupedPack = Boolean(config.packing.has_prior_box && (totalAlfajores > looseAlfajores));
        }

        return {
            totalAlfajores: totalAlfajores,
            looseAlfajores: looseAlfajores,
            hasGroupedPack: hasGroupedPack,
            cartTotal: (cartTotal > 0) ? cartTotal : (parseFloat(config.currentAmount) || 0)
        };
    }

    /**
     * Motor de Empaque en el Frontend (cálculo en 0ms sin desincronización)
     */
    function analyzePackingClientSide(totalAlfajores, looseCount, hasGroupedPack) {
        if (totalAlfajores <= 0) {
            return {
                has_alfajores: false,
                total_alfajores: 0,
                loose_alfajores: 0,
                has_prior_box: false,
                completed_boxes_6: 0,
                completed_boxes_12: 0,
                completed_boxes_text: '',
                current_box_num: 1,
                current_box_units: 0,
                current_box_capacity: 6,
                status: 'empty',
                is_blocked: false,
                courtesy_allowed: false,
                missing_units: 0,
                missing_for_12: 0,
                message: '',
                boxes: { box_12: 0, box_6: 0, courtesy: 0 }
            };
        }

        const has_prior_box = Boolean(hasGroupedPack || totalAlfajores >= 6);
        let status = 'ok';
        let is_blocked = false;
        let courtesy_allowed = false;
        let missing_units = 0;
        let missing_for_12 = 0;
        let message = '';
        const boxes = { box_12: 0, box_6: 0, courtesy: 0 };
        let completed_boxes_6 = 0;
        let completed_boxes_12 = 0;
        let completed_boxes_text = '';
        let current_box_num = 1;
        let current_box_units = 0;
        let current_box_capacity = 6;

        if (!has_prior_box) {
            // Caso 1: Menos de 1 caja completa (1 a 5 alfajores en total)
            status = 'no_prior_box';
            courtesy_allowed = false;
            missing_units = 6 - totalAlfajores;
            missing_for_12 = 12 - totalAlfajores;
            current_box_num = 1;
            current_box_units = totalAlfajores;
            current_box_capacity = 6;
            is_blocked = false; // No bloquea si cumple mínimo de tienda con otros productos
            message = 'Tenés ' + totalAlfajores + ' alfajor(es) en tu carrito. Sumando solo ' + missing_units + ' más, recibís tu primera Caja Oficial Batllié.';
        } else {
            // Caso 2: Al menos 1 caja completa previa
            if (looseCount === 0) {
                status = 'all_boxed';
                is_blocked = false;
                courtesy_allowed = false;
                current_box_units = 6;
                current_box_capacity = 6;
                message = '¡Tus cajas están completas! Viví la experiencia completa Batllié.';
            } else {
                const c12 = Math.floor(looseCount / 12);
                const rem = looseCount % 12;
                boxes.box_12 = c12;
                completed_boxes_12 = c12;

                if (rem === 0) {
                    status = 'all_boxed';
                    is_blocked = false;
                    current_box_units = 12;
                    current_box_capacity = 12;
                    completed_boxes_text = (c12 > 1) ? (c12 + ' Cajas x 12 armadas') : '1 Caja x 12 armada';
                    message = '¡Tus alfajores forman ' + (c12 > 1 ? c12 + ' cajas completas' : '1 caja completa') + ' de 12 unidades!';
                } else if (rem === 6) {
                    boxes.box_6 = 1;
                    completed_boxes_6 = 1;
                    status = 'all_boxed';
                    is_blocked = false;
                    current_box_units = 6;
                    current_box_capacity = 6;
                    completed_boxes_text = (c12 > 0 ? (c12 + ' Caja x 12 + ') : '') + '1 Caja x 6 armada';
                    message = '¡Tus alfajores forman cajas completas!';
                } else {
                    let extra = 0;
                    if (rem > 6) {
                        boxes.box_6 = 1;
                        completed_boxes_6 = 1;
                        extra = rem - 6; // Entre 1 y 5
                    } else {
                        extra = rem;
                    }

                    current_box_units = extra;
                    current_box_capacity = 6;
                    const totalCompleted = completed_boxes_12 + completed_boxes_6;
                    current_box_num = totalCompleted + 1;

                    if (completed_boxes_12 > 0 && completed_boxes_6 > 0) {
                        completed_boxes_text = completed_boxes_12 + ' Caja x 12 y ' + completed_boxes_6 + ' Caja x 6 ya completas';
                    } else if (completed_boxes_12 > 0) {
                        completed_boxes_text = completed_boxes_12 + (completed_boxes_12 > 1 ? ' Cajas x 12' : ' Caja x 12') + ' ya completa';
                    } else if (completed_boxes_6 > 0) {
                        completed_boxes_text = completed_boxes_6 + (completed_boxes_6 > 1 ? ' Cajas x 6' : ' Caja x 6') + ' ya completa';
                    }

                    const missing_to_6 = 6 - extra;
                    missing_units = missing_to_6;
                    missing_for_12 = 12 - extra;

                    if (missing_to_6 < 3) {
                        // Faltan 1 o 2 unidades -> BLOQUEO IMPERATIVO
                        status = 'imperative_missing';
                        is_blocked = true;
                        courtesy_allowed = false;
                        message = 'Tenés ' + totalAlfajores + ' alfajores en total (' + completed_boxes_text + '). Tu ' + (current_box_num > 1 ? current_box_num + 'ª caja' : 'caja') + ' tiene ' + extra + ' de 6 alfajores. Agregá ' + (missing_to_6 === 1 ? 'el alfajor faltante' : 'los 2 alfajores faltantes') + ' para poder despachar en caja cerrada.';
                    } else {
                        // Faltan 3 o más -> CORTESÍA DISPONIBLE
                        status = 'courtesy_available';
                        is_blocked = false;
                        courtesy_allowed = true;
                        boxes.courtesy = 1;
                        message = 'Tenés ' + totalAlfajores + ' alfajores (' + completed_boxes_text + '). Tu ' + (current_box_num > 1 ? current_box_num + 'ª caja' : 'caja') + ' tiene ' + extra + ' de 6. Con solo ' + missing_to_6 + ' más completás tu caja (o +' + missing_for_12 + ' para Caja de 12). Si no los agregás, ¡te regalamos una Caja de Cortesía para que viajen protegidos!';
                    }
                }
            }
        }

        return {
            has_alfajores: true,
            total_alfajores: totalAlfajores,
            loose_alfajores: looseCount,
            has_prior_box: has_prior_box,
            completed_boxes_6: completed_boxes_6,
            completed_boxes_12: completed_boxes_12,
            completed_boxes_text: completed_boxes_text,
            current_box_num: current_box_num,
            current_box_units: current_box_units,
            current_box_capacity: current_box_capacity,
            status: status,
            is_blocked: is_blocked,
            courtesy_allowed: courtesy_allowed,
            missing_units: missing_units,
            missing_for_12: missing_for_12,
            message: message,
            boxes: boxes
        };
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
     * Quitar avisos de monto mínimo de WooCommerce
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
     * Abrir modal emergente
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
     * Cerrar modal emergente
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

        // 1. Resumen de totales y cajas armadas
        $('#batllie-summary-total-count').text(packing.total_alfajores + ' alfajores');
        const $boxedBadge = $('#batllie-summary-boxed-badge');
        const $boxedText = $('#batllie-summary-boxed-text');
        if (packing.completed_boxes_text) {
            $boxedText.text(packing.completed_boxes_text);
            $boxedBadge.show();
        } else {
            $boxedBadge.hide();
        }

        // 2. Barra de progreso de la caja actual
        const curUnits = packing.current_box_units || 0;
        const curCap = packing.current_box_capacity || 6;
        const pct = Math.min(100, Math.round((curUnits / curCap) * 100));

        const boxLabel = (packing.current_box_num > 1) ? (packing.current_box_num + 'ª Caja en armado:') : 'Caja en armado:';
        $('.batllie-packing-meta-label').text(boxLabel);
        $('#batllie-packing-count').text(curUnits + ' de ' + curCap + ' alfajores');
        $('#batllie-packing-bar-fill').css('width', pct + '%');

        // 3. Títulos, Mensajes y Badges
        if (packing.status === 'all_boxed') {
            $('#batllie-packing-title').text('¡Caja Completa!');
            $('#batllie-packing-subtitle').text(packing.message || 'Tus alfajores viajan en caja cerrada oficial Batllié.');
            $('#batllie-packing-badge').text('¡Caja al 100%! 💌').removeClass('is-missing').addClass('is-complete');
            $('#batllie-btn-accept-courtesy').hide();
        } else if (packing.is_blocked) {
            $('#batllie-packing-title').text('Completá tu Caja para Despachar');
            $('#batllie-packing-subtitle').text(packing.message);
            $('#batllie-packing-badge').text('Faltan ' + packing.missing_units + ' para completar').removeClass('is-complete').addClass('is-missing');
            $('#batllie-btn-accept-courtesy').hide();
        } else if (packing.status === 'courtesy_available') {
            $('#batllie-packing-title').text('¡Mejorá tu Experiencia Batllié!');
            $('#batllie-packing-subtitle').text(packing.message);
            $('#batllie-packing-badge').text('Faltan ' + packing.missing_units + ' para completar').removeClass('is-complete').addClass('is-missing');
            $('#batllie-btn-accept-courtesy').show();
        } else if (packing.status === 'no_prior_box') {
            $('#batllie-packing-title').text('Comenzá tu Experiencia Batllié');
            $('#batllie-packing-subtitle').text(packing.message);
            $('#batllie-packing-badge').text('Faltan ' + packing.missing_units + ' para caja de 6').removeClass('is-complete').addClass('is-missing');
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

        updateCheckoutButtons();

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
     * Comprobación en vivo del carrito (0ms lag)
     */
    function checkCartStateLive() {
        const state = getCartStateNow();
        const packing = analyzePackingClientSide(state.totalAlfajores, state.looseAlfajores, state.hasGroupedPack);

        updatePackingUI(packing);

        if (state.cartTotal !== lastKnownAmount) {
            lastKnownAmount = state.cartTotal;
            applyCartState(state.cartTotal);
            syncMinOrderStatus();
        } else {
            applyCartState(lastKnownAmount);
        }

        return { state: state, packing: packing };
    }

    /**
     * Sincronizar estado del mínimo de compra y empaque vía AJAX con el servidor
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

        // B. Interceptar clic en botón de checkout con verificación reactiva EN VIVO
        const trigger = findCheckoutTrigger(e.target);
        if (trigger) {
            // Evaluar el carrito en vivo en este milisegundo exacto
            const live = checkCartStateLive();
            const packing = live.packing;
            const isBelowMin = (minAmount > 0 && config.isBelowMin);
            const isPackingBlocked = Boolean(packing && packing.is_blocked);
            const isCourtesyAvailable = Boolean(packing && packing.status === 'courtesy_available');
            const hasAcceptedCourtesy = (sessionStorage.getItem('batllie_courtesy_accepted') === '1');

            // Caso 1: Bloqueo por Monto Mínimo
            if (isBelowMin) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                $('#batllie-modal-min-stats').show();
                if (packing && packing.has_alfajores && packing.status !== 'all_boxed') {
                    updatePackingUI(packing);
                    $('#batllie-modal-packing-section').show();
                } else {
                    $('#batllie-modal-packing-section').hide();
                }

                openModal();
                return false;
            }

            // Caso 2: Bloqueo Imperativo de Empaque (faltan 1 o 2 unidades)
            if (isPackingBlocked) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                $('#batllie-modal-min-stats').hide();
                updatePackingUI(packing);
                $('#batllie-modal-packing-section').show();

                openModal();
                return false;
            }

            // Caso 3: Caja de Cortesía disponible (faltan >= 3 unidades, con caja previa)
            if (isCourtesyAvailable && !hasAcceptedCourtesy) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                $('#batllie-modal-min-stats').hide();
                updatePackingUI(packing);
                $('#batllie-modal-packing-section').show();

                openModal();
                return false;
            }

            // Si cajas completas (ej: 12 alfajores, 6 alfajores) y monto mínimo superado: ¡AVANZAR!
            return true;
        }
    }, true);

    // =========================================================================
    // Inicialización y Observación Reactiva de Cambios en el Carrito
    // =========================================================================
    removeMinOrderNoticeElements();
    $(document).ready(function () {
        removeMinOrderNoticeElements();
        $(document).ajaxComplete(function () {
            removeMinOrderNoticeElements();
        });

        // Verificación inicial inmediata
        checkCartStateLive();

        // Suscripción al store de WordPress Data (@wordpress/data)
        if (window.wp && window.wp.data && window.wp.data.subscribe) {
            window.wp.data.subscribe(function () {
                checkCartStateLive();
            });
        }

        // Detección inmediata en inputs y botones del carrito
        $(document).on('input change blur keyup', '.wc-block-components-quantity-selector__input, input.qty, input[type="number"]', function () {
            checkCartStateLive();
        });

        $(document).on('click', '.wc-block-components-quantity-selector__button, button.plus, button.minus, .wc-block-cart-item__remove-link', function () {
            setTimeout(checkCartStateLive, 30);
            setTimeout(checkCartStateLive, 250);
            setTimeout(checkCartStateLive, 600);
        });

        // Chequeo periódico suave (cada 400ms) para garantizar sincronía total
        setInterval(checkCartStateLive, 400);

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

                        $(document.body).trigger('wc_fragment_refresh');
                        $(document.body).trigger('added_to_cart');

                        if (window.wp && window.wp.data && window.wp.data.dispatch) {
                            try {
                                const cartStore = window.wp.data.dispatch('wc/store/cart');
                                if (cartStore && typeof cartStore.invalidateResolutionForStore === 'function') {
                                    cartStore.invalidateResolutionForStore();
                                }
                            } catch (err) {}
                        }

                        checkCartStateLive();

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

        $(document.body).on('updated_wc_div updated_cart_totals added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded', function () {
            checkCartStateLive();
            syncMinOrderStatus();
        });
    });

})(jQuery);
