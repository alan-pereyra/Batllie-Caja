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
     * Procesar filas y tarjetas de productos en el carrito
     */
    function processCartItems() {
        if (isProcessing) return;
        isProcessing = true;

        try {
            // =====================================================================
            // 1. Carrito Clásico (Tabla HTML estándar de WooCommerce)
            // =====================================================================
            $('.woocommerce-cart-form table.cart tr.batllie-combo-item').each(function () {
                const $row = $(this);

                // Bloquear botones stepper
                $row.find('.emp-qty-btn, .plus, .minus').hide();
                $row.find('input.qty').prop('readonly', true).attr('tabindex', '-1');

                if ($row.hasClass('batllie-combo-child-item')) {
                    // En productos hijos: ocultar totalmente el botón de eliminar y los precios
                    $row.find('td.product-remove a.remove, .product-remove').empty().hide();
                    $row.find('td.product-price, td.product-subtotal').empty().hide();
                } else if ($row.hasClass('batllie-combo-box-item')) {
                    // En la caja principal: tooltip de eliminación
                    const $removeBtn = $row.find('a.remove');
                    if ($removeBtn.length && !$removeBtn.attr('data-batllie-processed')) {
                        $removeBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                        $removeBtn.attr('data-batllie-processed', 'true');
                    }
                }
            });

            // =====================================================================
            // 2. WooCommerce Cart Block (Gutenberg / React)
            // =====================================================================
            const $cartBlockRows = $('.wc-block-cart-items .wc-block-cart-items__row, .wc-block-cart__item, .wc-block-cart-item');
            if ($cartBlockRows.length) {
                let currentBoxPackId = null;
                let autoPackCounter = 0;

                $cartBlockRows.each(function () {
                    const $row = $(this);
                    const rowText = $row.text().toLowerCase();

                    const $boxMarker   = $row.find('.batllie-box-marker');
                    const $childMarker = $row.find('.batllie-child-marker');

                    let isBox   = $boxMarker.length > 0;
                    let isChild = $childMarker.length > 0;

                    if (!isBox && !isChild) {
                        isBox = rowText.includes('caja de empaque') ||
                                rowText.includes('empaque incluido') ||
                                $row.hasClass('batllie-combo-box-item');

                        isChild = !isBox && (
                            rowText.includes('parte de') ||
                            rowText.includes('incluido en la caja') ||
                            (rowText.includes('ahorro') && (rowText.includes('0,00') || rowText.includes('0.00')))
                        );

                        // Fallback de contexto secuencial: si estamos dentro de una caja y el precio es $0 o tiene ahorro
                        if (!isBox && !isChild && currentBoxPackId) {
                            if (rowText.includes('0,00') || rowText.includes('0.00') || rowText.includes('ahorro')) {
                                isChild = true;
                            }
                        }
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
                        const $trashBtn = $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"]');
                        $trashBtn.show().css({'display': '', 'visibility': 'visible', 'pointer-events': 'auto'});
                        if ($trashBtn.length && !$trashBtn.attr('data-batllie-processed')) {
                            $trashBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                            $trashBtn.attr('data-batllie-processed', 'true');
                        }

                    } else if (isChild) {
                        let packId = getPackIdFromEl($childMarker) || currentBoxPackId || 'pack_item';

                        $row.addClass('batllie-is-combo-child batllie-pack-' + packId)
                            .removeClass('batllie-is-combo-box')
                            .attr('data-batllie-pack-id', packId);

                        // Bloquear steppers en el producto hijo
                        $row.find('.wc-block-components-quantity-selector__button').hide();
                        $row.find('.wc-block-components-quantity-selector__input').prop('readonly', true).attr('tabindex', '-1');

                        // Ocultar TOTALMENTE el botón de eliminar en los productos hijos
                        $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"], [aria-label*="eliminar" i], [aria-label*="remove" i], a.remove').hide().css({
                            'display': 'none',
                            'visibility': 'hidden',
                            'pointer-events': 'none',
                            'width': '0',
                            'height': '0',
                            'opacity': '0'
                        });

                        // Ocultar TOTALMENTE el precio y cualquier badge de ahorro o descuento en los hijos
                        $row.find('.wc-block-components-product-price, .wc-block-cart-item__prices, .wc-block-cart-item__total, .wc-block-components-formatted-money-amount, .wc-block-cart-item__total-price-and-sale-badge-wrapper, [class*="product-price"], [class*="item__prices"], [class*="discount"], [class*="saving"], [class*="sale-badge"], [class*="badge"]').hide().css({
                            'display': 'none',
                            'visibility': 'hidden',
                            'opacity': '0',
                            'height': '0',
                            'overflow': 'hidden'
                        });

                    } else {
                        // Producto normal independiente fuera de cualquier caja
                        currentBoxPackId = null;
                    }
                });
            }
        } finally {
            isProcessing = false;
        }
    }

    $(document).ready(function () {
        processCartItems();

        // Re-procesar tras eventos de actualización de carrito en WooCommerce clásico
        $(document.body).on('updated_wc_div updated_cart_totals wc_fragments_refreshed', function () {
            processCartItems();
        });

        // Re-procesar tras mutaciones del DOM (para WooCommerce Blocks basado en React)
        if (window.MutationObserver) {
            const targetNode = document.querySelector('.woocommerce, .wc-block-cart, .wp-block-woocommerce-cart, body');
            if (targetNode) {
                let debounceTimer = null;
                const observer = new MutationObserver(function () {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(processCartItems, 50);
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
