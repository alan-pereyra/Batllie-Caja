/**
 * Batllie Caja - Control de Mínimo de Compra y Empaque de Alfajores en el Frontend
 * Sincronización reactiva en tiempo real sin recargar página:
 * - Detección instantánea de cambios de cantidad en WooCommerce Blocks y clásico (0ms lag)
 * - Motor de empaque client-side en espejo con el servidor (sin desincronización)
 * - Desglose detallado: Total de alfajores, cajas cerradas completas y caja en armado
 * - Adición de sabores en 1 clic desde el modal sin recarga de página y con soporte multi-click continuo
 * - Redirección automática inmediata a finalizar compra al completar la caja requerida
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

    let isModalOpen = false;
    let modalAddedAlfajores = 0;
    let redirectingToCheckout = false;
    let quickAddQueue = [];
    let isProcessingQuickAdd = false;

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
     * Leer el estado actual del carrito en tiempo real (0ms lag)
     * Prioriza inputs del DOM que el cliente está manipulando, luego Store API de Blocks
     */
    function getCartStateNow() {
        let totalAlfajores = 0;
        let looseAlfajores = 0;
        let hasGroupedPack = false;
        let cartTotal = 0;
        let foundDomItems = false;
        let domTotalAlfajores = 0;
        let domLooseAlfajores = 0;
        let domHasGroupedPack = false;

        // 1. Inspección prioritaria del DOM si hay filas de carrito visibles (reflejo en 0ms de lo que el usuario ve)
        const $cartRows = $('.wc-block-cart-items__row, .wc-block-components-cart-line-item, tr.cart_item');
        if ($cartRows.length > 0) {
            $cartRows.each(function () {
                const $row = $(this);
                const text = ($row.text() || '').toLowerCase();
                let isAlfajor = text.includes('alfajor');
                if (!isAlfajor) {
                    const rowProdId = parseInt($row.find('[data-product_id]').attr('data-product_id') || $row.attr('data-product_id'), 10);
                    const rowVarId = parseInt($row.find('[data-variation_id]').attr('data-variation_id') || $row.attr('data-variation_id'), 10);
                    if ((rowProdId && alfajorIdSet.has(rowProdId)) || (rowVarId && alfajorIdSet.has(rowVarId))) {
                        isAlfajor = true;
                    }
                }
                if (isAlfajor) {
                    foundDomItems = true;
                    let q = 0;
                    const $input = $row.find('input.wc-block-components-quantity-selector__input, input.qty, input[type="number"]');
                    if ($input.length) {
                        q = parseInt($input.val(), 10) || 0;
                    } else {
                        const $qtyBadge = $row.find('.wc-block-components-cart-line-item__quantity, .product-quantity');
                        const match = ($qtyBadge.text() || '').match(/\d+/);
                        if (match) q = parseInt(match[0], 10) || 0;
                    }

                    const isPack = $row.hasClass('batllie-pack-child') || 
                                   $row.attr('data-batllie-pack') || 
                                   text.includes('combo') || 
                                   text.includes('caja x');

                    if (isPack) {
                        domHasGroupedPack = true;
                    } else {
                        domLooseAlfajores += q;
                    }
                    domTotalAlfajores += q;
                }
            });
        }

        if (foundDomItems) {
            totalAlfajores = domTotalAlfajores;
            looseAlfajores = domLooseAlfajores;
            hasGroupedPack = domHasGroupedPack;
        } else if (window.wp && window.wp.data && window.wp.data.select) {
            // 2. Store de Gutenberg / WooCommerce Blocks si el DOM no tiene filas hidratadas
            try {
                const cartStore = window.wp.data.select('wc/store/cart');
                if (cartStore && typeof cartStore.getCartData === 'function') {
                    const data = cartStore.getCartData();
                    if (data && Array.isArray(data.items) && data.items.length > 0) {
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
            } catch (e) {}
        }

        // Sumar adiciones realizadas dentro del modal activo
        if (modalAddedAlfajores > 0) {
            totalAlfajores += modalAddedAlfajores;
            looseAlfajores += modalAddedAlfajores;
        }

        // 3. Fallback a configuración de PHP si no se encontraron ítems
        if (totalAlfajores === 0 && config.packing) {
            totalAlfajores = (config.packing.total_alfajores || 0) + modalAddedAlfajores;
            looseAlfajores = (config.packing.loose_alfajores || 0) + modalAddedAlfajores;
            hasGroupedPack = Boolean(config.packing.has_prior_box && (totalAlfajores > looseAlfajores));
        }

        // Obtener monto total del carrito
        if (window.wp && window.wp.data && window.wp.data.select) {
            try {
                const cartStore = window.wp.data.select('wc/store/cart');
                if (cartStore && typeof cartStore.getCartData === 'function') {
                    const data = cartStore.getCartData();
                    if (data && data.totals) {
                        const raw = data.totals.total_items || data.totals.total_price || 0;
                        const unit = data.totals.currency_minor_unit !== undefined ? data.totals.currency_minor_unit : 2;
                        cartTotal = parseFloat(raw) / Math.pow(10, unit);
                    }
                }
            } catch (e) {}
        }

        if (cartTotal <= 0) {
            cartTotal = parseFloat(config.currentAmount) || 0;
        }

        return {
            totalAlfajores: totalAlfajores,
            looseAlfajores: looseAlfajores,
            hasGroupedPack: hasGroupedPack,
            cartTotal: cartTotal
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

        if (config.packing && config.packing.has_mixed_box) {
            const sharedCap = parseInt(config.packing.current_box_capacity, 10) || 6;
            const diff = totalAlfajores - (config.packing.total_alfajores || 0);
            const baseUnits = parseInt(config.packing.current_box_units, 10) || Math.max(0, sharedCap - (parseInt(config.packing.missing_units, 10) || 0));
            const newUnits = Math.min(sharedCap, Math.max(0, baseUnits + diff));
            const missing = Math.max(0, sharedCap - newUnits);
            return Object.assign({}, config.packing, {
                total_alfajores: totalAlfajores,
                loose_alfajores: looseCount,
                current_box_units: newUnits,
                missing_units: missing,
                status: (missing === 0) ? 'all_boxed' : 'courtesy_available'
            });
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
            message = 'Tenés ' + totalAlfajores + ' unidad(es) en tu carrito. Sumando solo ' + missing_units + ' más, completás tu primera caja oficial.';
        } else {
            // Caso 2: Al menos 1 caja completa previa
            if (looseCount === 0) {
                status = 'all_boxed';
                is_blocked = false;
                courtesy_allowed = false;
                current_box_units = 6;
                current_box_capacity = 6;
                message = '¡Tus paquetes están completos!';
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
                    message = '¡Tus productos forman ' + (c12 > 1 ? c12 + ' cajas completas' : '1 caja completa') + ' de 12 unidades!';
                } else if (rem === 6) {
                    boxes.box_6 = 1;
                    completed_boxes_6 = 1;
                    status = 'all_boxed';
                    is_blocked = false;
                    current_box_units = 6;
                    current_box_capacity = 6;
                    completed_boxes_text = (c12 > 0 ? (c12 + ' Caja x 12 + ') : '') + '1 Caja x 6 armada';
                    message = '¡Tus productos forman paquetes completos!';
                } else {
                    const totalCompleted = completed_boxes_12 + completed_boxes_6;
                    current_box_num = totalCompleted + 1;

                    if (completed_boxes_12 > 0 && completed_boxes_6 > 0) {
                        completed_boxes_text = completed_boxes_12 + ' Caja x 12 y ' + completed_boxes_6 + ' Caja x 6 ya completas';
                    } else if (completed_boxes_12 > 0) {
                        completed_boxes_text = completed_boxes_12 + (completed_boxes_12 > 1 ? ' Cajas x 12' : ' Caja x 12') + ' ya completa';
                    } else if (completed_boxes_6 > 0) {
                        completed_boxes_text = completed_boxes_6 + (completed_boxes_6 > 1 ? ' Cajas x 6' : ' Caja x 6') + ' ya completa';
                    }

                    if (rem > 6) {
                        // Remanente entre 7 y 11 unidades -> Completa Caja de 12
                        current_box_units = rem;
                        current_box_capacity = 12;
                        const missing_to_12 = 12 - rem;
                        missing_units = missing_to_12;
                        missing_for_12 = missing_to_12;

                        status = 'courtesy_available';
                        is_blocked = false;
                        courtesy_allowed = true;
                        boxes.courtesy = 1;
                        message = 'Tenés ' + totalAlfajores + ' unidades (' + (completed_boxes_text || 'paquete en armado') + '). Tu ' + (current_box_num > 1 ? current_box_num + 'º paquete' : 'paquete') + ' tiene ' + rem + ' de 12. Con solo ' + missing_to_12 + ' más completás tu Caja de 12. Si no los agregás, ¡te asignamos un empaque de cortesía para que viajen protegidos!';
                    } else {
                        // Remanente entre 1 y 5 unidades -> Completa Caja de 6
                        current_box_units = rem;
                        current_box_capacity = 6;
                        const missing_to_6 = 6 - rem;
                        missing_units = missing_to_6;
                        missing_for_12 = 12 - rem;

                        status = 'courtesy_available';
                        is_blocked = false;
                        courtesy_allowed = true;
                        boxes.courtesy = 1;
                        message = 'Tenés ' + totalAlfajores + ' unidades (' + (completed_boxes_text || 'paquete en armado') + '). Tu ' + (current_box_num > 1 ? current_box_num + 'º paquete' : 'paquete') + ' tiene ' + rem + ' de 6. Con solo ' + missing_to_6 + ' más completás tu paquete (o +' + missing_for_12 + ' para Caja de 12). Si no los agregás, ¡te asignamos un empaque de cortesía para que viajen protegidos!';
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
            target_box_capacity: current_box_capacity,
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

        isModalOpen = true;

        if (config.packing) {
            updatePackingUI(config.packing);
        }
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

        isModalOpen = false;
        $backdrop.removeClass('is-visible');
        setTimeout(function () {
            $backdrop.css('display', 'none');
            $('body').css('overflow', '');
        }, 260);
    }

    /**
     * Formatear resumen legible de cajas asignadas (ej: 1 Caja x 12, 2 Cajas x 12, etc.)
     */
    function formatCartPackagingSummary(packing) {
        if (!packing || !packing.has_alfajores) {
            return null;
        }

        if (packing.packaging_summary_short) {
            return {
                has_alfajores: true,
                short: packing.packaging_summary_short,
                sentence: 'Tus productos se despachan en ' + packing.packaging_summary_short + '.'
            };
        }

        const b = packing.boxes || {};
        const b12 = parseInt(b.box_12, 10) || 0;
        const b6 = parseInt(b.box_6, 10) || 0;
        const courtesy = parseInt(b.courtesy, 10) || 0;
        const parts = [];

        if (b12 > 0) {
            parts.push(b12 > 1 ? (b12 + ' Cajas x 12') : '1 Caja x 12');
        }
        if (b6 > 0) {
            parts.push(b6 > 1 ? (b6 + ' Cajas x 6') : '1 Caja x 6');
        }
        if (courtesy > 0) {
            parts.push(courtesy > 1 ? (courtesy + ' Cajas de Cortesía') : '1 Caja de Cortesía');
        }

        const short = parts.length > 0 ? parts.join(' + ') : 'A granel';
        const sentence = 'Tus productos se despachan en ' + short + '.';

        return {
            has_alfajores: true,
            short: short,
            sentence: sentence
        };
    }

    /**
     * Actualizar la interfaz de empaque y barra de progreso de cajas
     */
    function updatePackingUI(packing) {
        if (!packing || !packing.has_alfajores) {
            $('#batllie-banner-packaging-note').hide();
            $('#batllie-cart-packing-totals-row').hide();
            $('#batllie-modal-box-image-wrap').hide();

            // Si hay monto mínimo por alcanzar y tenemos productos sugeridos disponibles
            const hasSuggestions = Boolean(config.availableAlfajores && config.availableAlfajores.length > 0);
            if (config.isBelowMin && hasSuggestions) {
                const $sec = $('#batllie-modal-packing-section');
                $sec.show();

                const curAmt = parseFloat(config.currentAmount) || 0;
                const minAmt = parseFloat(config.minAmount) || 0;
                const pct = (minAmt > 0) ? Math.min(100, Math.round((curAmt / minAmt) * 100)) : 100;

                $('#batllie-packing-bar-fill').css({
                    'width': pct + '%',
                    'min-width': (curAmt > 0 ? '12px' : '0px')
                });

                const mainTitle = 'Completá tu pedido';
                const subTitle = 'Sumá sugeridos con 1 clic para llegar al mínimo';
                $('#batllie-packing-title').html('<span class="batllie-packing-title-main">' + mainTitle + '</span> <span class="batllie-packing-title-sub">' + subTitle + '</span>');

                const missingFmt = formatMoney(config.missingAmount);
                $('#batllie-packing-badge').text('Faltan ' + missingFmt + ' para el mínimo requerido').removeClass('is-complete').addClass('is-missing');
                $('#batllie-btn-accept-courtesy').hide();
            } else {
                $('#batllie-modal-packing-section').hide();
            }
            return;
        }

        config.packing = packing;
        const $sec = $('#batllie-modal-packing-section');
        $sec.show();

        // 1. Resumen de totales y cajas armadas (Oculto a pedido para optimizar espacio vertical en móvil)
        $('#batllie-packing-summary-wrap').hide();

        // 2. Barra de progreso de la caja actual
        let curCap = parseInt(packing.target_box_capacity || packing.current_box_capacity, 10) || 6;
        let curUnits = parseInt(packing.current_box_units, 10);
        const totalAlf = parseInt(packing.total_alfajores, 10) || 0;

        // Auto-detección estricta de capacidad objetivo si hay más de 6 alfajores
        if (totalAlf > 0) {
            const r12 = totalAlf % 12;
            if (r12 > 6) {
                curCap = 12;
            } else if (r12 > 0 && r12 <= 6 && !packing.has_mixed_box) {
                curCap = 6;
            }
        }
        if (packing.has_mixed_box && packing.mixed_box_info && packing.mixed_box_info.box_capacity) {
            curCap = parseInt(packing.mixed_box_info.box_capacity, 10);
        }

        if (isNaN(curUnits) || curUnits <= 0) {
            if (packing.missing_units !== undefined && parseInt(packing.missing_units, 10) < curCap && parseInt(packing.missing_units, 10) > 0) {
                curUnits = Math.max(0, curCap - parseInt(packing.missing_units, 10));
            } else if (totalAlf > 0) {
                curUnits = (totalAlf % curCap) || curCap;
            } else {
                curUnits = 0;
            }
        }
        const pct = (curCap > 0) ? Math.min(100, Math.round((curUnits / curCap) * 100)) : 0;

        $('#batllie-packing-bar-fill').css({
            'width': pct + '%',
            'min-width': (curUnits > 0 ? '12px' : '0px')
        });

        // 3. Títulos, Mensajes y Badges (La imagen de la caja se oculta siempre a pedido)
        $('#batllie-modal-box-image-wrap').hide();

        if (packing.status === 'all_boxed') {
            $('#batllie-packing-title').html('<span class="batllie-packing-title-main">¡Pedido Listo!</span>');
            $('#batllie-packing-badge').text('¡Todo completo! 💌').removeClass('is-missing').addClass('is-complete');
            $('#batllie-btn-accept-courtesy').hide();
        } else {
            const mainTitle = 'Completá tu pedido';
            const subTitle = 'Sumá sugeridos con 1 clic';
            $('#batllie-packing-title').html('<span class="batllie-packing-title-main">' + mainTitle + '</span> <span class="batllie-packing-title-sub">' + subTitle + '</span>');

            const missingUnits = (packing.missing_units !== undefined) ? packing.missing_units : Math.max(0, curCap - curUnits);
            const badgeText = packing.custom_badge || ('Faltan ' + missingUnits + ' para completar');
            $('#batllie-packing-badge').text(badgeText).removeClass('is-complete').addClass('is-missing');

            // Siempre permitir avanzar si no está por debajo del monto mínimo
            if (!config.isBelowMin) {
                $('#batllie-btn-accept-courtesy').show();
            } else {
                $('#batllie-btn-accept-courtesy').hide();
            }
        }

        // 4. Actualización del empaque en el banner del carrito y tabla de totales
        const pkgSummary = formatCartPackagingSummary(packing);
        if (pkgSummary) {
            let $bannerNote = $('#batllie-banner-packaging-note');
            if (!$bannerNote.length && $('#batllie-min-order-cart-banner .batllie-min-banner-content').length) {
                $('#batllie-min-order-cart-banner .batllie-min-banner-content').append(
                    '<div class="batllie-banner-packaging-note" id="batllie-banner-packaging-note">' +
                        '<span class="batllie-banner-pkg-icon">📦</span> ' +
                        '<span class="batllie-banner-pkg-text" id="batllie-banner-pkg-text"></span>' +
                    '</div>'
                );
                $bannerNote = $('#batllie-banner-packaging-note');
            }
            if ($bannerNote.length) {
                $('#batllie-banner-pkg-text').text(pkgSummary.sentence);
                $bannerNote.show();
            }

            let $totalsRow = $('#batllie-cart-packing-totals-row');
            if (!$totalsRow.length) {
                const $targetTable = $('.woocommerce-cart .cart_totals table tbody, .woocommerce-checkout #order_review table tfoot');
                if ($targetTable.length) {
                    const rowHtml = '<tr class="batllie-cart-packing-totals-row" id="batllie-cart-packing-totals-row">' +
                        '<th>Empaque Oficial:</th>' +
                        '<td data-title="Empaque Oficial">' +
                            '<span class="batllie-cart-packing-pill" id="batllie-cart-packing-pill">' +
                                '📦 <strong id="batllie-cart-packing-pill-text">' + pkgSummary.short + '</strong>' +
                            '</span>' +
                        '</td>' +
                    '</tr>';
                    const $orderTotal = $targetTable.find('.order-total');
                    if ($orderTotal.length) {
                        $orderTotal.before(rowHtml);
                    } else {
                        $targetTable.append(rowHtml);
                    }
                    $totalsRow = $('#batllie-cart-packing-totals-row');
                }
            }
            if ($totalsRow.length) {
                $('#batllie-cart-packing-pill-text').text(pkgSummary.short);
                $totalsRow.show();
            }
        } else {
            $('#batllie-banner-packaging-note').hide();
            $('#batllie-cart-packing-totals-row').hide();
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
        if (redirectingToCheckout) return;
        if (isProcessingQuickAdd || quickAddQueue.length > 0) return;

        // Si el modal está abierto y se agregaron productos adentro, la UI del modal
        // es conducida por las respuestas del servidor y no debe ser sobreescrita por scraping de fondo
        if (isModalOpen && modalAddedAlfajores > 0) return;

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
     * Renderizar reactivamente la lista de alfajores disponibles para upsell en el modal
     */
    function renderAvailableAlfajores(alfajores) {
        if (!Array.isArray(alfajores)) return;
        const $grid = $('#batllie-packing-flavors-grid');
        const $head = $('.batllie-packing-flavors-head');

        // Filtrar exclusivamente alfajores con stock real disponible > 0
        const validAlfajores = alfajores.filter(function (a) {
            const rem = (a.remaining_stock !== undefined && a.remaining_stock !== null) ? parseInt(a.remaining_stock, 10) : 999;
            return rem > 0;
        });

        if (validAlfajores.length === 0) {
            alfajorIdSet.clear();
            if ($grid.length) $grid.empty().hide();
            if ($head.length) $head.hide();
            return;
        }

        const newSignature = validAlfajores.map(function (a) {
            const rem = (a.remaining_stock !== undefined && a.remaining_stock !== null) ? parseInt(a.remaining_stock, 10) : 999;
            return a.id + ':' + rem;
        }).join(',');

        const curSignature = $grid.find('.batllie-flavor-chip').map(function () {
            return $(this).data('id') + ':' + $(this).attr('data-remaining-stock');
        }).get().join(',');

        if (newSignature === curSignature) return;

        alfajorIdSet.clear();
        validAlfajores.forEach(function (a) {
            alfajorIdSet.add(parseInt(a.id, 10));
        });

        let html = '';
        validAlfajores.forEach(function (alf) {
            const rem = (alf.remaining_stock !== undefined && alf.remaining_stock !== null) ? parseInt(alf.remaining_stock, 10) : 999;
            html += '<div class="batllie-flavor-chip" data-id="' + alf.id + '" data-remaining-stock="' + rem + '">';
            if (alf.image) {
                html += '<img src="' + alf.image + '" alt="' + (alf.clean_name || alf.name) + '" class="batllie-flavor-thumb" />';
            }
            html += '<div class="batllie-flavor-info">';
            html += '<span class="batllie-flavor-name">' + (alf.clean_name || alf.name) + '</span>';
            html += '<span class="batllie-flavor-price">' + (alf.price_fmt || formatMoney(alf.price)) + '</span>';
            html += '</div>';
            html += '<button type="button" class="batllie-flavor-add-btn" data-id="' + alf.id + '" data-remaining-stock="' + rem + '" aria-label="Agregar ' + (alf.clean_name || alf.name) + '">';
            html += '<span class="btn-icon">+</span><span class="btn-txt">1</span>';
            html += '</button>';
            html += '</div>';
        });

        $grid.html(html).show();
        $head.html('<span>Sumá un producto sugerido con 1 clic:</span>').show();
    }

    /**
     * Sincronizar estado del mínimo de compra y empaque vía AJAX con el servidor
     */
    let isSyncing = false;
    function syncMinOrderStatus() {
        if (isSyncing || redirectingToCheckout) return;
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
                    if (res.data.packing && (!isModalOpen || modalAddedAlfajores === 0)) {
                        updatePackingUI(res.data.packing);
                    }
                    if (res.data.availableAlfajores) {
                        renderAvailableAlfajores(res.data.availableAlfajores);
                    }
                    applyCartState(res.data.current_amount);
                }
            },
            complete: function () {
                isSyncing = false;
            }
        });
    }

    /**
     * Paso optimista inmediato en 0ms al tocar un alfajor en el modal
     */
    function optimisticQuickAddStep() {
        const curPacking = config.packing || {};
        let cap = parseInt(curPacking.target_box_capacity || curPacking.current_box_capacity, 10) || 6;
        let totalAlf = (parseInt(curPacking.total_alfajores, 10) || 0) + 1;
        const r12 = totalAlf % 12;
        if (r12 > 6) {
            cap = 12;
        } else if (r12 > 0 && r12 <= 6 && !curPacking.has_mixed_box) {
            cap = 6;
        }
        if (curPacking.has_mixed_box && curPacking.mixed_box_info && curPacking.mixed_box_info.box_capacity) {
            cap = parseInt(curPacking.mixed_box_info.box_capacity, 10);
        }

        let curUnits = parseInt(curPacking.current_box_units, 10);
        if (isNaN(curUnits) || curUnits <= 0) {
            if (curPacking.missing_units !== undefined && parseInt(curPacking.missing_units, 10) < cap && parseInt(curPacking.missing_units, 10) > 0) {
                curUnits = Math.max(0, cap - parseInt(curPacking.missing_units, 10));
            } else if (curPacking.total_alfajores) {
                curUnits = (curPacking.total_alfajores % cap) || cap;
            } else {
                curUnits = 0;
            }
        }
        curUnits += 1;
        let missingUnits = Math.max(0, cap - curUnits);

        let status = curPacking.status;
        let isBlocked = curPacking.is_blocked;
        let message = curPacking.message;

        if (curUnits >= cap || missingUnits === 0) {
            status = 'all_boxed';
            isBlocked = false;
            curUnits = cap;
            missingUnits = 0;
            message = '¡Tus paquetes están completos!';
        } else if (missingUnits < 3) {
            status = 'imperative_missing';
            isBlocked = true;
            message = 'Tenés ' + totalAlf + ' productos en total. Tu ' + (curPacking.current_box_num > 1 ? curPacking.current_box_num + 'º paquete' : 'paquete') + ' tiene ' + curUnits + ' de ' + cap + ' unidades. Agregá ' + (missingUnits === 1 ? 'la unidad faltante' : ('las ' + missingUnits + ' unidades faltantes')) + ' para poder despachar en caja cerrada.';
        }

        const optimisticPacking = Object.assign({}, curPacking, {
            has_alfajores: true,
            total_alfajores: totalAlf,
            current_box_units: curUnits,
            current_box_capacity: cap,
            target_box_capacity: cap,
            current_box_num: curPacking.current_box_num || 1,
            missing_units: missingUnits,
            custom_badge: (missingUnits > 0) ? ('Faltan ' + missingUnits + ' para completar') : '¡Caja al 100%! 💌',
            status: status,
            is_blocked: isBlocked,
            message: message
        });

        config.packing = optimisticPacking;
        updatePackingUI(optimisticPacking);
    }

    /**
     * Ejecutor de cola secuencial para adición rápida de alfajores (+1)
     * Soporta múltiples clics rápidos sin perder ningún toque ni recargar la página
     */
    function processQuickAddQueue() {
        if (isProcessingQuickAdd || quickAddQueue.length === 0) return;
        isProcessingQuickAdd = true;

        const task = quickAddQueue.shift();

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'emp_caja_quick_add_alfajor',
                product_id: task.productId,
                quantity: 1,
                security: config.nonce
            },
            success: function (res) {
                if (res && res.success && res.data) {
                    if (res.data.current_amount !== undefined) {
                        lastKnownAmount = res.data.current_amount;
                        applyCartState(res.data.current_amount);
                    }

                    // Notificar a Blocks silenciosamente sin gatillar recarga de página
                    if (window.wp && window.wp.data && window.wp.data.dispatch) {
                        try {
                            const cartStore = window.wp.data.dispatch('wc/store/cart');
                            if (cartStore && typeof cartStore.invalidateResolutionForStore === 'function') {
                                cartStore.invalidateResolutionForStore();
                            }
                        } catch (err) {}
                    }

                    // Cuando se vacía la cola, aplicar el análisis final autorizado del servidor
                    if (quickAddQueue.length === 0) {
                        if (res.data.analysis) {
                            config.packing = res.data.analysis;
                            updatePackingUI(res.data.analysis);
                        }
                        if (res.data.availableAlfajores) {
                            renderAvailableAlfajores(res.data.availableAlfajores);
                        }

                        const analysis = res.data.analysis;
                        const isAllBoxed = analysis && (analysis.status === 'all_boxed' || analysis.missing_units === 0);
                        const isMinMet = !res.data.is_below_min;

                        // Si el usuario intentaba finalizar compra y completó su caja o mínimo: ¡REDIRECCIÓN DIRECTA A CHECKOUT!
                        const isReadyToProceed = isMinMet && (!analysis || !analysis.has_alfajores || isAllBoxed);
                        if (isReadyToProceed && window.batllieIntendedCheckout) {
                            redirectingToCheckout = true;
                            $('#batllie-packing-title').html('<span class="batllie-packing-title-main">¡Pedido Completo! 🎉</span>');
                            $('#batllie-packing-subtitle').text('Requerimiento alcanzado. Redirigiendo a finalizar compra...');
                            $('#batllie-packing-badge').text('¡Listo! Redirigiendo... 💌').removeClass('is-missing').addClass('is-complete');
                            $('.batllie-flavor-add-btn').prop('disabled', true).css('opacity', '0.6');

                            const targetUrl = res.data.checkout_url || window.batllieCheckoutUrl || config.checkoutUrl || (config.shopUrl + 'finalizar-compra/');

                            setTimeout(function () {
                                closeModal();
                                window.location.href = targetUrl;
                            }, 550);
                        }
                    }
                } else {
                    modalAddedAlfajores = Math.max(0, modalAddedAlfajores - 1);
                    if (res && res.data && res.data.availableAlfajores) {
                        renderAvailableAlfajores(res.data.availableAlfajores);
                    }
                    if (task.$btn) {
                        task.$btn.removeClass('is-pulsing is-loading');
                    }
                }
            },
            error: function () {
                modalAddedAlfajores = Math.max(0, modalAddedAlfajores - 1);
                syncMinOrderStatus();
            },
            complete: function () {
                isProcessingQuickAdd = false;
                if (task.$btn) {
                    task.$btn.removeClass('is-loading');
                }
                if (quickAddQueue.length > 0) {
                    processQuickAddQueue();
                }
            }
        });
    }

    /**
     * Encolar clic rápido en sabor (+1)
     */
    function enqueueQuickAdd(productId, $btn) {
        if (redirectingToCheckout) return;

        try {
            document.cookie = "batllie_had_incomplete_box=yes; path=/; max-age=86400";
            document.cookie = "batllie_aumento_pedido=yes; path=/; max-age=86400";
        } catch (e) {}

        // Feedback táctil instantáneo en el botón
        $btn.removeClass('is-pulsing');
        if ($btn[0]) void $btn[0].offsetWidth;
        $btn.addClass('is-pulsing is-loading');

        modalAddedAlfajores++;
        optimisticQuickAddStep();

        quickAddQueue.push({
            productId: productId,
            $btn: $btn
        });

        processQuickAddQueue();
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
            // Guardar que la intención explícita del usuario es Finalizar Compra
            window.batllieIntendedCheckout = true;
            window.batllieCheckoutUrl = $(trigger).attr('href') || config.checkoutUrl || (config.shopUrl + 'finalizar-compra/');

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
                if ((packing && packing.has_alfajores && packing.status !== 'all_boxed') || (config.availableAlfajores && config.availableAlfajores.length > 0)) {
                    updatePackingUI(packing);
                    $('#batllie-modal-packing-section').show();
                } else {
                    $('#batllie-modal-packing-section').hide();
                }

                openModal();
                return false;
            }

            // Caso 2: Incentivo de Empaque (si tiene alfajores y la caja no está completa, incentivar con opción de continuar)
            if (packing && packing.has_alfajores && packing.status !== 'all_boxed' && !hasAcceptedCourtesy) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                $('#batllie-modal-min-stats').hide();
                updatePackingUI(packing);
                $('#batllie-modal-packing-section').show();

                openModal();
                return false;
            }

            // Si cajas completas y monto mínimo superado: ¡AVANZAR DIRECTAMENTE!
            window.batllieIntendedCheckout = false;
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

        // Chequeo periódico suave (cada 400ms) para mantener sincronía sin colisionar
        setInterval(checkCartStateLive, 400);

        // Evento: Agregar alfajor rápido en 1 clic (+1) con control estricto de stock disponible
        $(document).on('click', '.batllie-flavor-add-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(this);
            const productId = $btn.data('id');
            if (!productId || redirectingToCheckout || $btn.prop('disabled')) return;

            const $chip = $btn.closest('.batllie-flavor-chip');
            let remaining = parseInt($chip.attr('data-remaining-stock'), 10);
            if (isNaN(remaining)) remaining = 999;

            if (remaining <= 0) {
                $btn.prop('disabled', true).css('pointer-events', 'none');
                $chip.fadeOut(200, function () {
                    $(this).remove();
                    if ($('#batllie-packing-flavors-grid .batllie-flavor-chip').length === 0) {
                        $('.batllie-packing-flavors-head, #batllie-packing-flavors-grid').hide();
                    }
                });
                return;
            }

            // Descontar una unidad del stock local disponible
            remaining--;
            $chip.attr('data-remaining-stock', remaining);
            $btn.attr('data-remaining-stock', remaining);

            // Si se agotó el stock disponible (incluso si había 1 solo y se seleccionó):
            // Desaparece en el momento y no deja agregar más de uno
            if (remaining <= 0) {
                $btn.prop('disabled', true).css({ 'pointer-events': 'none', 'opacity': '0.5' });
                $chip.css({
                    'pointer-events': 'none',
                    'transition': 'opacity 0.25s ease, transform 0.25s ease',
                    'opacity': '0.3',
                    'transform': 'scale(0.95)'
                });
                setTimeout(function () {
                    $chip.slideUp(200, function () {
                        $(this).remove();
                        if ($('#batllie-packing-flavors-grid .batllie-flavor-chip').length === 0) {
                            $('.batllie-packing-flavors-head, #batllie-packing-flavors-grid').hide();
                        }
                    });
                }, 300);
            }

            enqueueQuickAdd(productId, $btn);
        });

        // Soporte para hacer clic en todo el chip (cómodo en móviles)
        $(document).on('click', '.batllie-flavor-chip', function (e) {
            if ($(e.target).closest('.batllie-flavor-add-btn').length) return;
            const $btn = $(this).find('.batllie-flavor-add-btn');
            if ($btn.length && !$btn.prop('disabled')) {
                $btn.trigger('click');
            }
        });

        // Evento: Aceptar Caja de Cortesía y avanzar directamente al checkout
        $(document).on('click', '#batllie-btn-accept-courtesy', function (e) {
            e.preventDefault();
            sessionStorage.setItem('batllie_courtesy_accepted', '1');
            closeModal();

            const targetUrl = window.batllieCheckoutUrl || config.checkoutUrl || (config.shopUrl + 'finalizar-compra/');
            window.location.href = targetUrl;
        });

        // Eventos de cierre del modal: si se agregaron ítems y decide volver al carrito, recargamos
        $(document).on('click', '#batllie-min-modal-close-btn, #batllie-min-modal-dismiss-btn', function (e) {
            e.preventDefault();
            window.batllieIntendedCheckout = false;
            closeModal();
            if (modalAddedAlfajores > 0) {
                window.location.reload();
            }
        });

        $(document).on('click', '#batllie-min-order-backdrop', function (e) {
            if (e.target === this) {
                window.batllieIntendedCheckout = false;
                closeModal();
                if (modalAddedAlfajores > 0) {
                    window.location.reload();
                }
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                window.batllieIntendedCheckout = false;
                closeModal();
                if (modalAddedAlfajores > 0) {
                    window.location.reload();
                }
            }
        });

        $(document.body).on('updated_wc_div updated_cart_totals removed_from_cart wc_fragments_refreshed wc_fragments_loaded', function () {
            checkCartStateLive();
            syncMinOrderStatus();
        });
    });

})(jQuery);
