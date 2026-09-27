/**
 * Batllie Caja - Comportamiento de Packs / Combos en el Carrito y Checkout
 * Compatible con Carrito Clásico y WooCommerce Cart Block (Gutenberg / React)
 * Soporta múltiples instancias de cajas simultáneas de forma independiente.
 */

(function ($) {
    'use strict';

    const config = window.batllieCartConfig || {};
    const i18n = config.i18n || {};
    const removeTitle = i18n.removeComboTooltip || 'Eliminar combo completo';
    let isProcessing = false;

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
                const packs = {}; // packId => { box: $el, children: [] }
                const orphanBoxes = [];
                const orphanChildren = [];

                $cartBlockRows.each(function () {
                    const $row = $(this);
                    const rowText = $row.text().toLowerCase();

                    const $boxMarker   = $row.find('.batllie-box-marker');
                    const $childMarker = $row.find('.batllie-child-marker');

                    let isBox   = $boxMarker.length > 0;
                    let isChild = $childMarker.length > 0;

                    if (!isBox && !isChild) {
                        isBox   = rowText.includes('caja de empaque') || rowText.includes('empaque incluido');
                        isChild = !isBox && (rowText.includes('parte de') || rowText.includes('incluido en la caja') || (rowText.includes('ahorro') && (rowText.includes('0,00') || rowText.includes('0.00'))));
                    }

                    if (isBox) {
                        $row.addClass('batllie-is-combo-box').removeClass('batllie-is-combo-child');

                        // Bloquear stepper
                        $row.find('.wc-block-components-quantity-selector__button').hide();
                        $row.find('.wc-block-components-quantity-selector__input').prop('readonly', true).attr('tabindex', '-1');

                        // Asegurar que el botón de eliminar de la caja sea visible y funcional
                        const $trashBtn = $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"]');
                        $trashBtn.show().css({'display': '', 'visibility': 'visible', 'pointer-events': 'auto'});
                        if ($trashBtn.length && !$trashBtn.attr('data-batllie-processed')) {
                            $trashBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                            $trashBtn.attr('data-batllie-processed', 'true');
                        }

                        const packId = $boxMarker.attr('data-pack-id');
                        if (packId) {
                            if (!packs[packId]) packs[packId] = { box: null, children: [] };
                            packs[packId].box = $row;
                        } else {
                            orphanBoxes.push($row);
                        }
                    } else if (isChild) {
                        $row.addClass('batllie-is-combo-child').removeClass('batllie-is-combo-box');

                        // Bloquear stepper
                        $row.find('.wc-block-components-quantity-selector__button').hide();
                        $row.find('.wc-block-components-quantity-selector__input').prop('readonly', true).attr('tabindex', '-1');

                        // Ocultar TOTALMENTE el botón de eliminar en los alfajores hijos
                        $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"], [aria-label*="eliminar" i], [aria-label*="remove" i], a.remove').hide().css({
                            'display': 'none',
                            'visibility': 'hidden',
                            'pointer-events': 'none',
                            'width': '0',
                            'height': '0',
                            'opacity': '0'
                        });

                        // Ocultar TOTALMENTE el precio y cualquier badge de ahorro o descuento en los hijos
                        $row.find('.wc-block-components-product-price, .wc-block-cart-item__prices, .wc-block-components-formatted-money-amount, [class*="product-price"], [class*="item__prices"], [class*="discount"], [class*="saving"], [class*="badge"]').hide().css({
                            'display': 'none',
                            'visibility': 'hidden',
                            'opacity': '0',
                            'height': '0',
                            'overflow': 'hidden'
                        });

                        const packId = $childMarker.attr('data-pack-id');
                        if (packId) {
                            if (!packs[packId]) packs[packId] = { box: null, children: [] };
                            packs[packId].children.push($row);
                        } else {
                            orphanChildren.push($row);
                        }
                    }
                });

                // Emparejar cajas y productos huérfanos sin marker por proximidad en el DOM
                if (orphanBoxes.length > 0) {
                    orphanBoxes.forEach(function ($box, idx) {
                        const autoId = 'auto_pack_' + idx;
                        packs[autoId] = { box: $box, children: [] };
                    });

                    orphanChildren.forEach(function ($ch) {
                        let assigned = false;
                        for (let i = orphanBoxes.length - 1; i >= 0; i--) {
                            if ($ch.index() > orphanBoxes[i].index()) {
                                packs['auto_pack_' + i].children.push($ch);
                                assigned = true;
                                break;
                            }
                        }
                        if (!assigned && orphanBoxes.length > 0) {
                            packs['auto_pack_0'].children.push($ch);
                        }
                    });
                }

                // Aplicar estructura y submenú por cada instancia de caja independiente
                Object.keys(packs).forEach(function (pid) {
                    const pack = packs[pid];
                    if (!pack.box || !pack.children.length) return;

                    const $box = pack.box;
                    const $children = pack.children;

                    // Si la caja está situada después de su primer hijo, moverla antes
                    if ($box.index() > $children[0].index()) {
                        $children[0].before($box);
                    }

                    // Inyectar o asegurar el encabezado del submenú para esta caja específica
                    let $heading = $box.next('.batllie-combo-submenu-heading');
                    if (!$heading.length || $heading.attr('data-for-pack') !== pid) {
                        $heading = $(
                            '<div class="batllie-combo-submenu-heading" data-for-pack="' + pid + '">' +
                                '<span class="batllie-tree-icon">↳</span> ' +
                                '<span class="batllie-heading-text">Contenido de la caja:</span>' +
                            '</div>'
                        );
                        $box.after($heading);
                    }

                    // Colocar secuencialmente los hijos de esta caja después de su encabezado
                    let $anchor = $heading;
                    $children.forEach(function ($child) {
                        if ($child.prev()[0] !== $anchor[0]) {
                            $anchor.after($child);
                        }
                        $anchor = $child;

                        // Re-asegurar que el botón de eliminar y los precios sigan ocultos
                        $child.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"], [aria-label*="eliminar" i], [aria-label*="remove" i], a.remove').hide().css({
                            'display': 'none',
                            'visibility': 'hidden',
                            'pointer-events': 'none',
                            'width': '0',
                            'height': '0',
                            'opacity': '0'
                        });

                        $child.find('.wc-block-components-product-price, .wc-block-cart-item__prices, .wc-block-components-formatted-money-amount, [class*="product-price"], [class*="item__prices"], [class*="discount"], [class*="saving"], [class*="badge"]').hide().css({
                            'display': 'none',
                            'visibility': 'hidden',
                            'opacity': '0',
                            'height': '0'
                        });
                    });
                });
            }
        } finally {
            isProcessing = false;
        }
    }

    $(document).ready(function () {
        processCartItems();

        // Re-procesar tras eventos de actualización de carrito en WooCommerce clásico
        $(document.body).on('updated_wc_div updated_cart_totals', function () {
            processCartItems();
        });

        // Re-procesar tras mutaciones del DOM (para WooCommerce Blocks basado en React)
        if (window.MutationObserver) {
            const targetNode = document.querySelector('.woocommerce, .wc-block-cart, .wp-block-woocommerce-cart, body');
            if (targetNode) {
                let debounceTimer = null;
                const observer = new MutationObserver(function () {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(processCartItems, 60);
                });
                observer.observe(targetNode, { childList: true, subtree: true });
            }
        }

        // Efecto visual al hacer clic en eliminar el combo
        $(document).on('click', '.batllie-combo-box-item a.remove, .batllie-is-combo-box [class*="remove"]', function () {
            $('.batllie-combo-item, .batllie-is-combo-child, .batllie-is-combo-box').addClass('batllie-combo-delete-active');
        });
    });

})(jQuery);
