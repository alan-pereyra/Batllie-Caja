/**
 * Batllie Caja - Comportamiento de Packs / Combos en el Carrito y Checkout
 * Compatible con Carrito Clásico y WooCommerce Cart Block (Gutenberg)
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
        // --- 1. Carrito Clásico ---
        $('.woocommerce-cart-form table.cart tr.batllie-combo-item').each(function () {
            const $row = $(this);
            // Bloquear botones stepper
            $row.find('.emp-qty-btn, .plus, .minus').hide();
            $row.find('input.qty').prop('readonly', true).attr('tabindex', '-1');

            // Personalizar botón de eliminar
            const $removeBtn = $row.find('a.remove');
            if ($removeBtn.length && !$removeBtn.attr('data-batllie-processed')) {
                $removeBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                $removeBtn.attr('data-batllie-processed', 'true');
            }
        });

        // --- 2. WooCommerce Cart Block (Gutenberg / React) ---
        $('.wc-block-cart-items .wc-block-cart-items__row, .wc-block-cart__item').each(function () {
            const $row = $(this);
            const rowText = $row.text().toLowerCase();

            // Detectar si la fila contiene metadatos de pack/combo
            const isComboChild = rowText.includes('parte de') || rowText.includes('combo') || rowText.includes('incluido en la caja');
            const isComboBox   = rowText.includes('caja de empaque') || rowText.includes('empaque incluido');

            if (isComboChild || isComboBox) {
                if (isComboChild) {
                    $row.addClass('batllie-is-combo-child');
                }
                if (isComboBox) {
                    $row.addClass('batllie-is-combo-box');
                }

                // Ocultar botones de + y - en el stepper del bloque
                const $stepperBtns = $row.find('.wc-block-components-quantity-selector__button');
                $stepperBtns.hide();

                // Hacer el input de solo lectura
                const $qtyInput = $row.find('.wc-block-components-quantity-selector__input');
                $qtyInput.prop('readonly', true).attr('tabindex', '-1').css('pointer-events', 'none');

                // Personalizar botón papelera
                const $trashBtn = $row.find('.wc-block-cart-item__remove-link, button.wc-block-components-quantity-selector__button--remove, [class*="remove-link"], [class*="remove-button"]');
                if ($trashBtn.length && !$trashBtn.attr('data-batllie-processed')) {
                    $trashBtn.attr('title', removeTitle).attr('aria-label', removeTitle);
                    $trashBtn.attr('data-batllie-processed', 'true');
                }
            }
        });
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
                const observer = new MutationObserver(function () {
                    processCartItems();
                });
                observer.observe(targetNode, { childList: true, subtree: true });
            }
        }

        // Efecto visual al hacer clic en eliminar cualquier ítem del combo
        $(document).on('click', '.batllie-combo-item a.remove, .batllie-is-combo-child [class*="remove"], .batllie-is-combo-box [class*="remove"]', function () {
            $('.batllie-combo-item, .batllie-is-combo-child, .batllie-is-combo-box').addClass('batllie-combo-delete-active');
        });
    });

})(jQuery);
