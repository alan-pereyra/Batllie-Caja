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

                if ($row.hasClass('batllie-combo-child-item')) {
                    if (!classicPackFlavors[packId]) {
                        classicPackFlavors[packId] = [];
                    }

                    let name = $row.find('td.product-name a, td.product-name').first().text().trim();
                    name = name.replace(/^↳\s*/, '').replace(/\s+/g, ' ').trim();
                    let qty = parseInt($row.find('input.qty').val(), 10) || 1;

                    if (name) {
                        classicPackFlavors[packId].push({ name: name, qty: qty });
                    }

                    // En productos hijos: ocultar totalmente la fila de la tabla
                    $row.hide().css('display', 'none');
                } else if ($row.hasClass('batllie-combo-box-item')) {
                    // En la caja principal: tooltip de eliminación
                    const $removeBtn = $row.find('a.remove');
                    if ($removeBtn.length && !$removeBtn.attr('data-batllie-processed')) {
                        $removeBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                        $removeBtn.attr('data-batllie-processed', 'true');
                    }
                }
            });

            // Inyectar resumen de sabores en cajas de carrito clásico
            $('.woocommerce-cart-form table.cart tr.batllie-combo-box-item').each(function () {
                const $box = $(this);
                const classStr = $box.attr('class') || '';
                const match = classStr.match(/\bbatllie-pack-([a-zA-Z0-9_-]+)/);
                const packId = match ? match[1] : 'pack_default';
                const flavors = classicPackFlavors[packId] || [];

                if (flavors.length) {
                    let $summary = $box.find('.batllie-box-flavors-summary');
                    if (!$summary.length) {
                        $summary = $('<div class="batllie-box-flavors-summary"><div class="batllie-box-flavors-title"><span class="batllie-flavors-icon">✨</span> ' + flavorsTitle + '</div><div class="batllie-box-flavors-pills"></div></div>');
                        $box.find('td.product-name').append($summary);
                    }
                    const $pills = $summary.find('.batllie-box-flavors-pills');
                    $pills.empty();
                    flavors.forEach(function (f) {
                        $pills.append('<span class="batllie-flavor-pill"><strong>' + f.qty + '×</strong> ' + f.name + '</span>');
                    });
                }
            });

            // =====================================================================
            // 2. WooCommerce Cart Block (Gutenberg / React) & Order Summary Block
            // =====================================================================
            const $cartBlockRows = $('.wc-block-cart-items .wc-block-cart-items__row, .wc-block-cart-items tr.wc-block-cart-items__row, .wc-block-cart__item, .wc-block-cart-item, .wc-block-components-order-summary-item');
            if ($cartBlockRows.length) {
                let currentBoxPackId = null;
                let autoPackCounter = 0;
                const packFlavors = {};

                // Paso 2.1: Clasificar filas y marcar cajas e hijos
                $cartBlockRows.each(function () {
                    try {
                        const $row = $(this);
                        const rowText = $row.text().toLowerCase();

                        const $boxMarker   = $row.find('.batllie-box-marker, [class*="batllie-box-marker"]');
                        const $childMarker = $row.find('.batllie-child-marker, [class*="batllie-child-marker"]');

                        let isBox   = $boxMarker.length > 0;
                        let isChild = $childMarker.length > 0;

                        if (!isBox && !isChild) {
                            isBox = rowText.includes('caja de empaque') ||
                                    rowText.includes('empaque incluido') ||
                                    rowText.includes('caja principal') ||
                                    $row.hasClass('batllie-combo-box-item');

                            isChild = !isBox && (
                                rowText.includes('parte de') ||
                                rowText.includes('part of') ||
                                rowText.includes('incluido en la caja') ||
                                rowText.includes('included in box') ||
                                $row.hasClass('batllie-combo-child-item')
                            );
                        }

                        if (isBox) {
                            let packId = getPackIdFromEl($boxMarker);
                            if (!packId) {
                                autoPackCounter++;
                                packId = 'pack_auto_' + autoPackCounter;
                            }
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

                        } else if (isChild) {
                            let packId = getPackIdFromEl($childMarker) || currentBoxPackId || 'pack_item';

                            $row.addClass('batllie-is-combo-child batllie-pack-' + packId)
                                .removeClass('batllie-is-combo-box')
                                .attr('data-batllie-pack-id', packId);

                            // Recopilar sabor y cantidad para mostrar dentro de la caja principal
                            if (!packFlavors[packId]) {
                                packFlavors[packId] = [];
                            }

                            let childName = $row.find('.wc-block-components-product-name, .wc-block-cart-item__product a, a').first().text().trim();
                            childName = childName.replace(/^↳\s*/, '').replace(/\s+/g, ' ').trim();

                            let childQty = parseInt($row.find('input.qty, .wc-block-components-quantity-selector__input').val(), 10);
                            if (!childQty || isNaN(childQty)) {
                                const m = $row.text().match(/(\d+)\s*(?:unidad|unidades|u\b)/i);
                                childQty = m ? parseInt(m[1], 10) : 1;
                            }

                            if (childName) {
                                packFlavors[packId].push({ name: childName, qty: childQty });
                            }

                            // Ocultar estrictamente el ítem hijo para que no aparezca como fila separada
                            $row.hide().css({
                                'display': 'none',
                                'visibility': 'hidden',
                                'height': '0',
                                'overflow': 'hidden',
                                'margin': '0',
                                'padding': '0',
                                'border': 'none'
                            });

                        } else {
                            // Producto normal independiente fuera de cualquier caja
                            currentBoxPackId = null;

                            // Limpiar clases de combo si las tenía previamente
                            $row.removeClass('batllie-is-combo-child batllie-is-combo-box batllie-combo-delete-active')
                                .removeAttr('data-batllie-pack-id');
                            $row.removeClass(function (index, className) {
                                return (className.match(/\bbatllie-[^\s]+/g) || []).join(' ');
                            });

                            // Asegurar que el botón de eliminar esté visible y habilitado
                            $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"], a.remove, button[aria-label*="eliminar"], button[aria-label*="Eliminar"], button[aria-label*="remove"], button[aria-label*="Remove"]')
                                .show()
                                .css({
                                    'display': '',
                                    'visibility': 'visible',
                                    'pointer-events': 'auto',
                                    'width': '',
                                    'height': '',
                                    'opacity': ''
                                });

                            // Asegurar que los precios y totales estén visibles
                            $row.find('.wc-block-components-product-price, .wc-block-cart-item__prices, .wc-block-cart-item__total, .wc-block-components-formatted-money-amount, .wc-block-cart-item__total-price-and-sale-badge-wrapper, [class*="product-price"], [class*="item__prices"]')
                                .show()
                                .css({
                                    'display': '',
                                    'visibility': 'visible',
                                    'opacity': '',
                                    'height': '',
                                    'overflow': '',
                                    'font-size': '',
                                    'line-height': '',
                                    'min-height': ''
                                });

                            // Asegurar que los botones de cantidad y el input sean editables
                            $row.find('.wc-block-components-quantity-selector__button, .emp-qty-btn, .plus, .minus')
                                .show()
                                .css({
                                    'display': '',
                                    'pointer-events': '',
                                    'visibility': '',
                                    'width': '',
                                    'height': '',
                                    'margin': '',
                                    'padding': ''
                                });

                            $row.find('.wc-block-components-quantity-selector__input, input.qty')
                                .prop('readonly', false)
                                .removeAttr('tabindex')
                                .css({
                                    'pointer-events': '',
                                    'border': '',
                                    'background': ''
                                });

                            // Restaurar visualización de detalles
                            $row.find('.wc-block-components-product-details').show();
                        }
                    } catch (rowError) {
                        console.error('Error procesando fila de carrito:', rowError);
                    }
                });

                // Paso 2.2: Inyectar resumen de sabores dentro de cada tarjeta de caja
                $cartBlockRows.filter('.batllie-is-combo-box').each(function () {
                    const $box = $(this);
                    const packId = $box.attr('data-batllie-pack-id');
                    const flavors = packFlavors[packId] || [];

                    if (flavors.length) {
                        let $summary = $box.find('.batllie-box-flavors-summary');
                        if (!$summary.length) {
                            $summary = $('<div class="batllie-box-flavors-summary"><div class="batllie-box-flavors-title"><span class="batllie-flavors-icon">✨</span> ' + flavorsTitle + '</div><div class="batllie-box-flavors-pills"></div></div>');
                            const $target = $box.find('.wc-block-cart-item__product, .wc-block-components-product-name, .wc-block-cart-item__description').first();
                            if ($target.length) {
                                $target.after($summary);
                            } else {
                                $box.append($summary);
                            }
                        }

                        const $pills = $summary.find('.batllie-box-flavors-pills');
                        $pills.empty();
                        flavors.forEach(function (f) {
                            $pills.append('<span class="batllie-flavor-pill"><strong>' + f.qty + '×</strong> ' + f.name + '</span>');
                        });
                    }
                });
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
                        if (data && typeof data.items_count === 'number') {
                            totalCount = data.items_count;
                        }
                    }
                } catch (e) {}
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
            // 1. Inyectar traducciones en el diccionario de WordPress / Gutenberg i18n
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

            // 2. Reemplazo directo en DOM por si React/Gutenberg renderizó antes o con bundle en caché
            // Botón de finalizar compra
            $('.wc-block-cart__submit-button, .wc-block-cart__submit a, a.checkout-button, .wc-block-components-checkout-place-order-button').each(function () {
                const $btn = $(this);
                const txt = $btn.text().trim();
                if (txt === 'Proceed to Checkout') {
                    $btn.contents().filter(function() { return this.nodeType === 3; }).first().replaceWith('Finalizar compra');
                    if ($btn.text().trim() === 'Proceed to Checkout') {
                        $btn.text('Finalizar compra');
                    }
                }
            });

            // Acordeón / enlace de cupones
            $('.wc-block-components-totals-coupon, .wc-block-components-panel__button, .wc-block-components-totals-coupon__title').each(function () {
                const $el = $(this);
                let html = $el.html();
                if (html && (html.indexOf('Add coupons') !== -1 || html.indexOf('Añadir cupones') !== -1)) {
                    $el.html(html.replace(/Add coupons|Añadir cupones/g, 'Agregar cupón'));
                }
            });

            // Total estimado
            $('.wc-block-components-totals-item__label, .wc-block-components-totals-footer-item-tax-value, .wc-block-components-totals-item').each(function () {
                const $el = $(this);
                if ($el.text().indexOf('Estimated total') !== -1) {
                    $el.find('*').addBack().contents().filter(function() {
                        return this.nodeType === 3 && this.nodeValue.indexOf('Estimated total') !== -1;
                    }).each(function() {
                        this.nodeValue = this.nodeValue.replace(/Estimated total/g, 'Total estimado');
                    });
                }
            });

            // Envío FREE / Gratis
            $('.wc-block-components-totals-shipping, .wc-block-components-shipping-rates-control').find('*').each(function () {
                if ($(this).children().length === 0) {
                    const txt = $(this).text().trim();
                    if (txt === 'FREE' || txt === 'Free') {
                        $(this).text('Gratis');
                    }
                }
            });

            // Badge de descuento (Save $ 200,00 -> Ahorrás $ 200,00)
            $('*').filter(function () {
                return $(this).children().length === 0 && $(this).text().indexOf('Save $') !== -1;
            }).each(function () {
                $(this).text($(this).text().replace(/Save \$/g, 'Ahorrás $'));
            });
        } catch (e) {}
    }

    $(document).ready(function () {
        translateCartStrings();
        processCartItems();

        // Re-procesar tras eventos de actualización de carrito en WooCommerce clásico
        $(document.body).on('updated_wc_div updated_cart_totals wc_fragments_refreshed', function () {
            translateCartStrings();
            processCartItems();
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
                    }, 50);
                });
                observer.observe(targetNode, { childList: true, subtree: true });
            }
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
    });

})(jQuery);
