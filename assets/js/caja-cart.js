/**
 * Batllie Caja - Comportamiento de Packs / Combos en el Carrito y Checkout
 * Compatible con Carrito Clásico y WooCommerce Cart Block (Gutenberg / React)
 * Soporta múltiples instancias de cajas independientes simultáneas.
 */

(function ($) {
    'use strict';

    const config = window.batllieCartConfig || {};
    const i18n = config.i18n || {};
    const removeTitle = i18n.removeComboTooltip || 'Eliminar combo completo';
    const flavorsTitle = i18n.includedFlavors || 'Sabores incluidos:';
    let isProcessing = false;

    /**
     * Extraer ID de pack desde un elemento marcador HTML resistente a sanitización
     */
    function getPackIdFromEl($marker) {
        if (!$marker || !$marker.length) return '';

        // 1. Atributo title (permitido por el sanitizador de WooCommerce Blocks)
        const title = $marker.attr('title') || '';
        if (title.indexOf('pack_') === 0) {
            return title;
        }

        // 2. Clase CSS batllie-pid-[packId] (permitido por el sanitizador de WooCommerce Blocks)
        const classStr = $marker.attr('class') || '';
        const match = classStr.match(/\bbatllie-pid-([a-zA-Z0-9_-]+)/);
        if (match && match[1]) {
            return match[1];
        }

        // 3. Atributo data-pack-id (en entornos clásicos o sin sanitizador estricto)
        const dataPack = $marker.attr('data-pack-id') || '';
        if (dataPack) {
            return dataPack;
        }

        return '';
    }

    /**
     * Actualizar badges del carrito en header y footer móvil
     */
    function updateCartCounterBadges(count) {
        if (typeof count !== 'number' || isNaN(count)) {
            return;
        }
        const safeCount = Math.max(0, count);

        // Actualizar números en badges
        $('#mini-cart-count, #mini-cart-count-footer').text(safeCount);

        if (safeCount > 0) {
            $('#span-woocommerce-counter, .woo-counter-cart-number, .woo-counter-cart-number-desktop').removeClass('d-none');
            $('#btn-woocommerce-cart, a.fa-shopping-cart.show-desktop').addClass('has-items fadein');
        } else {
            $('#span-woocommerce-counter, .woo-counter-cart-number, .woo-counter-cart-number-desktop').addClass('d-none');
            $('#btn-woocommerce-cart, a.fa-shopping-cart.show-desktop').removeClass('has-items');
        }
    }

    /**
     * Procesar filas y tarjetas de productos en el carrito
     */
    function processCartItems() {
        if (isProcessing) return;
        isProcessing = true;

        try {
            // =====================================================================
            // 1. Carrito Clásico (Tabla HTML estándar de WooCommerce)
            // =====================================================================
            const classicPackFlavors = {};

            $('.woocommerce-cart-form table.cart tr.batllie-combo-item').each(function () {
                const $row = $(this);

                // Bloquear botones stepper
                $row.find('.emp-qty-btn, .plus, .minus').hide();
                $row.find('input.qty').prop('readonly', true).attr('tabindex', '-1');

                const classStr = $row.attr('class') || '';
                const match = classStr.match(/\bbatllie-pack-([a-zA-Z0-9_-]+)/);
                const packId = match ? match[1] : 'pack_default';
                const hasBoxInClassic = $('.woocommerce-cart-form table.cart tr.batllie-combo-box-item.batllie-pack-' + packId).length > 0;

                if ($row.hasClass('batllie-combo-child-item') && hasBoxInClassic) {
                    // Bloquear botones stepper
                    $row.find('.emp-qty-btn, .plus, .minus').hide();
                    $row.find('input.qty').prop('readonly', true).attr('tabindex', '-1');
                    // Ocultar botón eliminar individual (se borra desde la caja principal)
                    $row.find('a.remove').hide();

                    // Asegurar que la fila del hijo permanezca SIEMPRE visible como sub-ítem
                    $row.show().css({
                        'display': '',
                        'visibility': 'visible',
                        'opacity': '1',
                        'height': '',
                        'overflow': ''
                    });
                } else if ($row.hasClass('batllie-combo-box-item')) {
                    // En la caja principal: tooltip de eliminación
                    const $removeBtn = $row.find('a.remove');
                    if ($removeBtn.length && !$removeBtn.attr('data-batllie-processed')) {
                        $removeBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                        $removeBtn.attr('data-batllie-processed', 'true');
                    }
                } else {
                    // Si no tiene caja física, asegurar que la fila permanezca visible
                    $row.show().css({
                        'display': '',
                        'visibility': 'visible',
                        'height': '',
                        'overflow': ''
                    });
                }
            });

            // Limpiar cualquier resumen redundante inyectado anteriormente en la caja
            $('.woocommerce-cart-form table.cart .batllie-box-flavors-summary').remove();

            // =====================================================================
            // 2. WooCommerce Cart Block (Gutenberg / React) & Order Summary Block
            // =====================================================================
            const $cartBlockRows = $('.wc-block-cart-items .wc-block-cart-items__row, .wc-block-cart-items tr.wc-block-cart-items__row, .wc-block-cart__item, .wc-block-cart-item, .wc-block-components-order-summary-item');
            if ($cartBlockRows.length) {
                let currentBoxPackId = null;
                let autoPackCounter = 0;
                const packFlavors = {};

                // Paso 2.0: Identificar qué packs tienen realmente una caja física en el carrito
                const boxPackIds = new Set();
                $cartBlockRows.each(function () {
                    const $r = $(this);
                    const rowText = $r.text().toLowerCase();
                    const $bMarker = $r.find('.batllie-box-marker, [class*="batllie-box-marker"]');
                    let isB = $bMarker.length > 0 ||
                              $r.hasClass('batllie-combo-box-item') ||
                              rowText.includes('caja de empaque') ||
                              rowText.includes('empaque incluido') ||
                              rowText.includes('caja principal');
                    if (isB) {
                        let pid = getPackIdFromEl($bMarker);
                        if (!pid) {
                            const cStr = $r.attr('class') || '';
                            const m = cStr.match(/\bbatllie-pack-([a-zA-Z0-9_-]+)/);
                            if (m && m[1]) pid = m[1];
                        }
                        if (pid) {
                            boxPackIds.add(pid);
                        }
                    }
                });

                // Paso 2.1: Clasificar filas y marcar cajas e hijos
                $cartBlockRows.each(function () {
                    try {
                        const $row = $(this);
                        const rowText = $row.text().toLowerCase();

                        const $boxMarker   = $row.find('.batllie-box-marker, [class*="batllie-box-marker"]');
                        const $childMarker = $row.find('.batllie-child-marker, [class*="batllie-child-marker"]');

                        let isBox   = $boxMarker.length > 0;
                        if (!isBox) {
                            isBox = rowText.includes('caja de empaque') ||
                                    rowText.includes('empaque incluido') ||
                                    rowText.includes('caja principal') ||
                                    $row.hasClass('batllie-combo-box-item');
                        }

                        let isChild = false;
                        let packId = '';

                        if (isBox) {
                            packId = getPackIdFromEl($boxMarker);
                            if (!packId) {
                                autoPackCounter++;
                                packId = 'pack_auto_' + autoPackCounter;
                            }
                            boxPackIds.add(packId);
                            currentBoxPackId = packId;

                            $row.addClass('batllie-is-combo-box batllie-pack-' + packId)
                                .removeClass('batllie-is-combo-child')
                                .attr('data-batllie-pack-id', packId);

                            // Bloquear stepper en la caja (solo mostrar la cantidad fija, ej: 1)
                            $row.find('.wc-block-components-quantity-selector__button').hide();
                            $row.find('.wc-block-components-quantity-selector__input').prop('readonly', true).attr('tabindex', '-1');

                            // Asegurar que el botón de eliminar de la caja sea visible y funcional
                            const $trashBtn = $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"], a.remove');
                            $trashBtn.show().css({'display': '', 'visibility': 'visible', 'pointer-events': 'auto'});
                            if ($trashBtn.length && !$trashBtn.attr('data-batllie-processed')) {
                                $trashBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                                $trashBtn.attr('data-batllie-processed', 'true');
                            }

                            // Ocultar metadatos técnicos redundantes
                            $row.find('.wc-block-components-product-details').hide();

                        } else {
                            // Buscar packId del hijo
                            packId = getPackIdFromEl($childMarker);
                            if (!packId) {
                                const classStr = $row.attr('class') || '';
                                const m = classStr.match(/\bbatllie-pack-([a-zA-Z0-9_-]+)/);
                                if (m && m[1]) packId = m[1];
                            }
                            if (!packId && currentBoxPackId) {
                                packId = currentBoxPackId;
                            }

                            const hasChildSignal = ($childMarker.length > 0) ||
                                                   $row.hasClass('batllie-combo-child-item') ||
                                                   rowText.includes('incluido en la caja') ||
                                                   rowText.includes('included in box');

                            // SOLO se oculta como hijo si REALMENTE existe una caja física para este pack en el carrito
                            if (hasChildSignal && packId && boxPackIds.has(packId)) {
                                isChild = true;
                            }
                        }

                        if (isChild) {
                            $row.addClass('batllie-is-combo-child batllie-pack-' + packId)
                                .removeClass('batllie-is-combo-box')
                                .attr('data-batllie-pack-id', packId);

                            // Asegurar que el ítem hijo permanezca SIEMPRE visible con su imagen y cantidad
                            $row.show().css({
                                'display': '',
                                'visibility': 'visible',
                                'opacity': '1',
                                'height': '',
                                'min-height': '',
                                'max-height': '',
                                'overflow': ''
                            });

                            // Bloquear controles de cantidad y ocultar papelera individual (se elimina desde la caja)
                            $row.find('.wc-block-components-quantity-selector__button').hide();
                            $row.find('.wc-block-components-quantity-selector__input, input.qty').prop('readonly', true).attr('tabindex', '-1');
                            $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"], a.remove').hide();

                            // Asegurar que la imagen del producto hijo sea visible
                            $row.find('.wc-block-cart-item__image, [class*="product-image"], img').show().css({
                                'display': '',
                                'visibility': 'visible',
                                'opacity': '1'
                            });

                            // Mostrar badge 'Incluido' si el precio es $0 o está vacío
                            const $childPrice = $row.find('.wc-block-cart-item__total, .wc-block-components-product-price, .wc-block-components-order-summary-item__total-price');
                            if ($childPrice.length && !$childPrice.find('.batllie-included-in-box').length) {
                                const priceText = $childPrice.text().trim();
                                if (priceText.includes('$ 0') || priceText.includes('$0') || priceText === '' || priceText.includes('Gratis')) {
                                    $childPrice.html('<span class="batllie-included-in-box">' + ((window.batllieCartConfig && window.batllieCartConfig.i18n && window.batllieCartConfig.i18n.includedInBox) || 'Incluido') + '</span>');
                                }
                            }

                        } else if (!isBox) {
                            // Producto normal o producto de combo sin caja física:
                            currentBoxPackId = null;

                            // Si la fila tenía clases residuales de combo hijo, limpiarlas y asegurar que sea visible
                            if ($row.hasClass('batllie-is-combo-child')) {
                                $row.removeClass('batllie-is-combo-child batllie-combo-delete-active')
                                    .removeAttr('data-batllie-pack-id');

                                $row.show().css({
                                    'display': '',
                                    'visibility': 'visible',
                                    'height': '',
                                    'min-height': '',
                                    'max-height': '',
                                    'overflow': '',
                                    'margin': '',
                                    'padding': '',
                                    'border': '',
                                    'opacity': '',
                                    'pointer-events': 'auto'
                                });
                            }
                        }
                    } catch (rowError) {
                        console.error('Error procesando fila de carrito:', rowError);
                    }
                });

                // Limpiar cualquier resumen de sabores redundante inyectado en la caja
                $('.batllie-box-flavors-summary').remove();
            }

            // =====================================================================
            // 3. Sincronizar Badges del Carrito (Header & Footer Móvil)
            // =====================================================================
            let totalCount = 0;
            const countedPacks = new Set();

            // Sumar 1 por cada caja
            $('.batllie-is-combo-box, tr.batllie-combo-box-item').each(function () {
                const pid = $(this).attr('data-batllie-pack-id') || $(this).attr('class');
                if (!pid || !countedPacks.has(pid)) {
                    if (pid) countedPacks.add(pid);
                    const qtyVal = parseInt($(this).find('input.qty, .wc-block-components-quantity-selector__input').val(), 10);
                    totalCount += (!isNaN(qtyVal) && qtyVal > 0) ? qtyVal : 1;
                }
            });

            // Sumar productos sueltos regulares (ignorando empaques gratuitos y niños de combo)
            $('.wc-block-cart-items .wc-block-cart-items__row, .wc-block-cart__item, .woocommerce-cart-form table.cart tr.cart_item').each(function () {
                const $r = $(this);
                if ($r.hasClass('batllie-is-combo-child') || $r.hasClass('batllie-is-combo-box') ||
                    $r.hasClass('batllie-combo-child-item') || $r.hasClass('batllie-combo-box-item')) {
                    return;
                }
                const txt = $r.text().toLowerCase();
                if (txt.includes('caja de empaque') || txt.includes('empaque incluido')) {
                    return;
                }
                const qtyVal = parseInt($r.find('input.qty, .wc-block-components-quantity-selector__input').val(), 10);
                totalCount += (!isNaN(qtyVal) && qtyVal > 0) ? qtyVal : 1;
            });

            // Si Store API de Gutenberg tiene el conteo oficial calculado por PHP, sincronizar con él
            if (window.wp && window.wp.data && typeof window.wp.data.select === 'function') {
                try {
                    const storeCart = window.wp.data.select('wc/store/cart');
                    if (storeCart && typeof storeCart.getCartData === 'function') {
                        const data = storeCart.getCartData();
                        if (data && typeof data.items_count === 'number' && data.items_count > 0) {
                            totalCount = data.items_count;
                        }
                    }
                } catch (e) {}
            }

            // Si el bloque de Gutenberg aún está en estado de carga ('is-loading') y el conteo dio 0,
            // no sobreescribir el badge del header/footer para no borrar el conteo renderizado por el servidor.
            const isBlockLoading = $('.wp-block-woocommerce-cart.is-loading').length > 0;
            if (isBlockLoading && totalCount === 0) {
                return;
            }

            updateCartCounterBadges(totalCount);

        } finally {
            isProcessing = false;
        }
    }

    /**
     * Asegurar que todas las etiquetas del carrito y checkout de WooCommerce Blocks se muestren en español
     */
    function translateCartStrings() {
        try {
            // 1. Inyectar traducciones oficiales en el diccionario de WordPress / Gutenberg i18n
            if (window.wp && window.wp.i18n && typeof window.wp.i18n.setLocaleData === 'function') {
                window.wp.i18n.setLocaleData({
                    "": { "domain": "woocommerce", "lang": "es" },
                    "Proceed to Checkout": ["Finalizar compra"],
                    "Add coupons": ["Agregar cupón"],
                    "Añadir cupones": ["Agregar cupón"],
                    "Estimated total": ["Total estimado"],
                    "Free": ["Gratis"],
                    "FREE": ["Gratis"],
                    "Save %s": ["Ahorrás %s"],
                    "Save": ["Ahorro"],
                    "Place Order": ["Realizar pedido"],
                    "Shipping address": ["Dirección de envío"],
                    "Billing address": ["Dirección de facturación"],
                    "Payment options": ["Medios de pago"]
                }, "woocommerce");
            }

            // 2. Reemplazo exclusivo para WooCommerce Clásico (no React)
            $('a.checkout-button').each(function () {
                const $btn = $(this);
                if ($btn.text().trim() === 'Proceed to Checkout') {
                    $btn.text('Finalizar compra');
                }
            });
        } catch (e) {}
    }

    /**
     * Aplicar badge de porcentaje de descuento (en verde con signo negativo a la derecha)
     * en el medio de pago que ofrece descuento (ej: Transferencia -15%)
     */
    function applyPaymentDiscountBadges() {
        try {
            const discountConfig = (config.paymentDiscounts) || {};
            const percent = discountConfig.percent || 15;
            const badgeText = '-' + percent + '%';
            const discountGateways = Array.isArray(discountConfig.gateways) ? discountConfig.gateways : ['bacs'];

            // 1. Selector para WooCommerce Blocks (React)
            $('.wc-block-components-radio-control__option').each(function () {
                const $option = $(this);
                const $input = $option.find('input[type="radio"]');
                const inputVal = ($input.val() || '').toLowerCase();
                const inputId = ($input.attr('id') || '').toLowerCase();
                const optionFor = ($option.attr('for') || '').toLowerCase();
                const optionText = ($option.text() || '').toLowerCase();

                let isDiscountMethod = false;
                for (let i = 0; i < discountGateways.length; i++) {
                    const gw = discountGateways[i].toLowerCase();
                    if (inputVal === gw || inputId.includes(gw) || optionFor.includes(gw)) {
                        isDiscountMethod = true;
                        break;
                    }
                }
                if (!isDiscountMethod) {
                    if (optionText.includes('transferencia') || optionText.includes('transferí')) {
                        isDiscountMethod = true;
                    }
                }

                // SIEMPRE eliminar cualquier badge que haya quedado pegado dentro del span de título (eliminar el de la izquierda)
                $option.find('.wc-block-components-radio-control__label .batllie-payment-discount-badge').remove();

                const $labelGroup = $option.find('.wc-block-components-radio-control__label-group').first();

                if (isDiscountMethod && $labelGroup.length) {
                    // Mantener únicamente el badge como hijo directo de label-group (a la derecha)
                    let $directBadges = $labelGroup.children('.batllie-payment-discount-badge');
                    if ($directBadges.length === 0) {
                        $labelGroup.append('<span class="batllie-payment-discount-badge">' + badgeText + '</span>');
                    } else {
                        if ($directBadges.first().text() !== badgeText) {
                            $directBadges.first().text(badgeText);
                        }
                        if ($directBadges.length > 1) {
                            $directBadges.slice(1).remove();
                        }
                    }
                    // Remover cualquier otro badge huérfano dentro de la opción
                    $option.find('.batllie-payment-discount-badge').not($labelGroup.children('.batllie-payment-discount-badge')).remove();
                } else {
                    // Si no tiene descuento, eliminar cualquier badge
                    $option.find('.batllie-payment-discount-badge').remove();
                }
            });

            // 2. Selector para Checkout Clásico de WooCommerce
            $('.woocommerce-checkout .wc_payment_methods li, .woocommerce-checkout ul.payment_methods li').each(function () {
                const $li = $(this);
                const $input = $li.find('input[type="radio"]');
                const val = ($input.val() || '').toLowerCase();
                const liClass = ($li.attr('class') || '').toLowerCase();
                const liText = ($li.text() || '').toLowerCase();

                let isDiscountMethod = false;
                for (let i = 0; i < discountGateways.length; i++) {
                    const gw = discountGateways[i].toLowerCase();
                    if (val === gw || liClass.includes('payment_method_' + gw)) {
                        isDiscountMethod = true;
                        break;
                    }
                }
                if (!isDiscountMethod && (liText.includes('transferencia') || liText.includes('bacs'))) {
                    isDiscountMethod = true;
                }

                const $label = $li.find('label').first();
                if ($label.length) {
                    $label.find('.emp-gateway-badge').remove();
                    if (isDiscountMethod) {
                        let $directBadges = $label.children('.batllie-payment-discount-badge');
                        if ($directBadges.length === 0) {
                            $label.append('<span class="batllie-payment-discount-badge">' + badgeText + '</span>');
                        } else {
                            if ($directBadges.first().text() !== badgeText) {
                                $directBadges.first().text(badgeText);
                            }
                            if ($directBadges.length > 1) {
                                $directBadges.slice(1).remove();
                            }
                        }
                    } else {
                        $label.find('.batllie-payment-discount-badge').remove();
                    }
                }
            });
        } catch (e) {}
    }

    $(document).ready(function () {
        translateCartStrings();
        processCartItems();
        applyPaymentDiscountBadges();

        // Polling inicial para capturar renderizado asíncrono de WooCommerce Blocks
        let discountPollCount = 0;
        const discountPoll = setInterval(function () {
            applyPaymentDiscountBadges();
            discountPollCount++;
            if (discountPollCount > 15) {
                clearInterval(discountPoll);
            }
        }, 250);

        // Re-procesar tras eventos de actualización de carrito y checkout en WooCommerce clásico
        $(document.body).on('updated_wc_div updated_cart_totals wc_fragments_refreshed payment_method_selected updated_checkout', function () {
            translateCartStrings();
            processCartItems();
            applyPaymentDiscountBadges();
        });

        // Re-procesar tras mutaciones del DOM (para WooCommerce Blocks basado en React)
        if (window.MutationObserver) {
            const targetNode = document.querySelector('.woocommerce, .wc-block-cart, .wp-block-woocommerce-cart, .wc-block-checkout, .wp-block-woocommerce-checkout, body');
            if (targetNode) {
                let debounceTimer = null;
                const observer = new MutationObserver(function () {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(function () {
                        translateCartStrings();
                        processCartItems();
                        applyPaymentDiscountBadges();
                    }, 50);
                });
                observer.observe(targetNode, { childList: true, subtree: true });
            }
        }

        // Suscribirse a cambios reactivos de WooCommerce Store API (Gutenberg)
        if (window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function') {
            let lastSubscribedCount = -1;
            window.wp.data.subscribe(function () {
                try {
                    const storeCart = window.wp.data.select('wc/store/cart');
                    if (storeCart && typeof storeCart.getCartData === 'function') {
                        const data = storeCart.getCartData();
                        if (data && typeof data.items_count === 'number' && data.items_count !== lastSubscribedCount) {
                            lastSubscribedCount = data.items_count;
                            translateCartStrings();
                            processCartItems();
                            applyPaymentDiscountBadges();
                        }
                    }
                } catch (e) {}
            });
        }

        // Efecto visual al hacer clic en eliminar una caja o combo específico
        $(document).on('click', '.batllie-combo-box-item a.remove, .batllie-is-combo-box [class*="remove"]', function () {
            const $boxRow = $(this).closest('.batllie-is-combo-box, .batllie-combo-box-item');
            const packId = $boxRow.attr('data-batllie-pack-id');
            if (packId) {
                $('.batllie-pack-' + packId).addClass('batllie-combo-delete-active');
            } else {
                $boxRow.addClass('batllie-combo-delete-active');
            }
        });

        // =====================================================================
        // GESTIÓN DE REEMPLAZO RÁPIDO DE SABORES AGOTADOS EN COMBOS (Alternativa A)
        // =====================================================================
        // Abrir modal de reemplazo
        $(document).on('click', '.batllie-open-replacement-modal, .batllie-replacement-alert-action-btn', function (e) {
            e.preventDefault();
            $('#batllie-combo-replacement-backdrop').css('display', 'flex').attr('aria-hidden', 'false');
        });

        // Cerrar modal de reemplazo
        $(document).on('click', '#batllie-replacement-modal-close-btn', function (e) {
            e.preventDefault();
            $('#batllie-combo-replacement-backdrop').hide().attr('aria-hidden', 'true');
        });

        $(document).on('click', '#batllie-combo-replacement-backdrop', function (e) {
            if ($(e.target).is('#batllie-combo-replacement-backdrop')) {
                $(this).hide().attr('aria-hidden', 'true');
            }
        });

        // Selección de nuevo sabor como reemplazo
        $(document).on('click', '.batllie-replacement-select-btn, .batllie-replacement-card', function (e) {
            const $card = $(this).closest('.batllie-replacement-card');
            const $btn = $card.find('.batllie-replacement-select-btn');
            if ($btn.prop('disabled')) return;

            e.preventDefault();

            const repId = $card.data('replacement-id');
            const cartKey = $card.data('cart-key');
            const packId = $card.data('pack-id');

            const originalText = $btn.find('.btn-text').text() || 'Elegir';
            $btn.prop('disabled', true).find('.btn-text').text('Cambiando...');
            $('.batllie-replacement-select-btn').prop('disabled', true);

            const ajaxUrl = (window.batllieCartConfig && window.batllieCartConfig.ajaxUrl) || (window.batllieMinOrderConfig && window.batllieMinOrderConfig.ajaxUrl) || '/wp-admin/admin-ajax.php';
            const nonce = (window.batllieCartConfig && window.batllieCartConfig.nonce) || (window.batllieMinOrderConfig && window.batllieMinOrderConfig.nonce) || '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_replace_combo_flavor',
                    nonce: nonce,
                    replacement_product_id: repId,
                    depleted_cart_item_key: cartKey,
                    pack_instance_id: packId
                },
                success: function (res) {
                    if (res && res.success) {
                        $btn.find('.btn-text').text('¡Listo!');
                        if (res.data && res.data.redirect) {
                            window.location.href = res.data.redirect;
                        } else {
                            window.location.reload();
                        }
                    } else {
                        alert((res && res.data && res.data.message) || 'Error al cambiar de sabor. Por favor recarga la página.');
                        $btn.prop('disabled', false).find('.btn-text').text(originalText);
                        $('.batllie-replacement-select-btn').prop('disabled', false);
                    }
                },
                error: function () {
                    alert('Error de conexión. Intenta nuevamente.');
                    $btn.prop('disabled', false).find('.btn-text').text(originalText);
                    $('.batllie-replacement-select-btn').prop('disabled', false);
                }
            });
        });

        // Quitar combo completo si el usuario no desea reemplazar
        $(document).on('click', '.batllie-replacement-remove-combo-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const cartKey = $btn.data('cart-key');
            if (!cartKey) return;

            $btn.prop('disabled', true).text('Quitando combo...');
            const ajaxUrl = (window.batllieCartConfig && window.batllieCartConfig.ajaxUrl) || (window.batllieMinOrderConfig && window.batllieMinOrderConfig.ajaxUrl) || '/wp-admin/admin-ajax.php';
            const nonce = (window.batllieCartConfig && window.batllieCartConfig.nonce) || (window.batllieMinOrderConfig && window.batllieMinOrderConfig.nonce) || '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_remove_combo_pack',
                    nonce: nonce,
                    cart_item_key: cartKey
                },
                success: function (res) {
                    if (res && res.data && res.data.redirect) {
                        window.location.href = res.data.redirect;
                    } else {
                        window.location.reload();
                    }
                },
                error: function () {
                    window.location.reload();
                }
            });
        });
    });

})(jQuery);
