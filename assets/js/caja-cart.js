/**
 * Batllie Caja - Comportamiento de Packs / Combos en el Carrito y Checkout
 * Compatible con Carrito Clásico y WooCommerce Cart Block (Gutenberg / React)
 */

(function ($) {
    'use strict';

    const config = window.batllieCartConfig || {};
    const i18n = config.i18n || {};
    const removeTitle = i18n.removeComboTooltip || 'Eliminar combo completo';

    /**
     * Procesar filas y tarjetas de productos en el carrito
     */
    function processCartItems() {
        // =====================================================================
        // 1. Carrito Clásico (Tabla HTML estándar de WooCommerce)
        // =====================================================================
        $('.woocommerce-cart-form table.cart tr.batllie-combo-item').each(function () {
            const $row = $(this);

            // Bloquear botones stepper
            $row.find('.emp-qty-btn, .plus, .minus').hide();
            $row.find('input.qty').prop('readonly', true).attr('tabindex', '-1');

            if ($row.hasClass('batllie-combo-child-item')) {
                // En productos hijos, ocultar por completo el botón de eliminar
                $row.find('td.product-remove a.remove, .product-remove').empty().hide();
            } else if ($row.hasClass('batllie-combo-box-item')) {
                // En la caja principal, personalizar el tooltip de eliminación
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
        if (!$cartBlockRows.length) {
            return;
        }

        const boxes = [];
        const children = [];

        $cartBlockRows.each(function () {
            const $row = $(this);
            const rowText = $row.text().toLowerCase();

            const isComboBox   = rowText.includes('caja de empaque') || rowText.includes('empaque incluido');
            const isComboChild = !isComboBox && (rowText.includes('parte de') || rowText.includes('incluido en la caja') || (rowText.includes('ahorro') && rowText.includes('$ 0,00')));

            if (isComboBox) {
                $row.addClass('batllie-is-combo-box').removeClass('batllie-is-combo-child');
                boxes.push($row);

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
            } else if (isComboChild) {
                $row.addClass('batllie-is-combo-child').removeClass('batllie-is-combo-box');
                children.push($row);

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
            }
        });

        // ---------------------------------------------------------------------
        // Reordenamiento y estructura de submenú jerárquico en Cart Block
        // ---------------------------------------------------------------------
        if (boxes.length > 0 && children.length > 0) {
            const $firstBox = boxes[0];
            const $firstChild = children[0];

            // Si la caja está situada después de los hijos en el DOM, moverla antes
            if ($firstBox.index() > $firstChild.index()) {
                $firstChild.before($firstBox);
            }

            // Inyectar o asegurar el encabezado del submenú "Contenido de la caja"
            let $heading = $firstBox.next('.batllie-combo-submenu-heading');
            if (!$heading.length) {
                $heading = $(
                    '<div class="batllie-combo-submenu-heading">' +
                        '<span class="batllie-tree-icon">↳</span> ' +
                        '<span class="batllie-heading-text">Contenido de la caja:</span>' +
                    '</div>'
                );
                $firstBox.after($heading);
            }

            // Colocar todos los hijos secuencialmente después del encabezado
            let $anchor = $heading;
            children.forEach(function ($child) {
                if ($child.prev()[0] !== $anchor[0]) {
                    $anchor.after($child);
                }
                $anchor = $child;

                // Re-asegurar que el botón de eliminar esté oculto
                $child.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"], [aria-label*="eliminar" i], [aria-label*="remove" i], a.remove').hide().css({
                    'display': 'none',
                    'visibility': 'hidden',
                    'pointer-events': 'none',
                    'width': '0',
                    'height': '0',
                    'opacity': '0'
                });
            });
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
            const targetNode = document.querySelector('.woocommerce, .wc-block-cart, .wp-block-woocommerce-cart');
            if (targetNode) {
                let debounceTimer = null;
                const observer = new MutationObserver(function () {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(processCartItems, 50);
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
