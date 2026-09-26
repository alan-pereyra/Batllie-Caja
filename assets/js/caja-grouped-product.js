/**
 * Batllie Caja - Control de Productos Agrupados con Cantidad Fija / Packs
 * Gestiona la selección exacta de unidades, cálculo de precio en vivo y bloqueo del botón.
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        const $form = $('form.grouped_form');
        if (!$form.length) {
            return;
        }

        const $boxContainer = $('#batllie-grouped-box-container');
        if (!$boxContainer.length) {
            return;
        }

        const config = window.batllieGroupedConfig || {};
        const targetQty = parseInt($boxContainer.data('target-qty') || config.targetQty, 10);
        if (!targetQty || targetQty <= 0) {
            return;
        }

        const prices = config.childrenPrices || {};
        const currencySymbol = config.currencySymbol || '$';
        const decimalSep = config.decimalSep || ',';
        const thousandSep = config.thousandSep || '.';
        const i18n = config.i18n || {};

        const $submitBtn = $form.find('.single_add_to_cart_button');
        const $table = $form.find('.woocommerce-grouped-product-list');

        /**
         * Forzar que la tabla, la tarjeta de progreso y el botón se apilen verticalmente
         * ocupando el 100% del ancho del formulario.
         */
        function enforceLayout() {
            $form.attr('style', function (i, s) {
                return (s || '') + '; display: flex !important; flex-direction: column !important; flex-wrap: nowrap !important; width: 100% !important; max-width: 100% !important; float: none !important; clear: both !important;';
            });
            $table.attr('style', function (i, s) {
                return (s || '') + '; width: 100% !important; max-width: 100% !important; float: none !important; clear: both !important; display: table !important; margin-bottom: 20px !important;';
            });
            $boxContainer.attr('style', function (i, s) {
                return (s || '') + '; width: 100% !important; max-width: 100% !important; display: block !important; clear: both !important; float: none !important; box-sizing: border-box !important;';
            });
            $submitBtn.attr('style', function (i, s) {
                return (s || '') + '; width: 100% !important; max-width: 100% !important; display: block !important; float: none !important; box-sizing: border-box !important;';
            });
        }
        enforceLayout();

        /**
         * Formatear precio numérico con el formato local de WooCommerce
         */
        function formatMoney(amount) {
            const parts = amount.toFixed(2).split('.');
            let integerPart = parts[0];
            const decimalPart = parts[1];

            // Miles
            integerPart = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);

            return `<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">${currencySymbol}</span>&nbsp;${integerPart}${decimalSep}${decimalPart}</bdi></span>`;
        }

        /**
         * Extraer el ID de producto hijo desde el atributo name="quantity[123]"
         */
        function getChildId($input) {
            const name = $input.attr('name') || '';
            const match = name.match(/quantity\[(\d+)\]/);
            return match ? parseInt(match[1], 10) : null;
        }

        /**
         * Extraer precio unitario de la fila si no vino en config
         */
        function getUnitPrice($input) {
            const childId = getChildId($input);
            if (childId && typeof prices[childId] !== 'undefined') {
                return parseFloat(prices[childId]);
            }

            // Fallback: leer del DOM en la celda de precio
            const $row = $input.closest('.woocommerce-grouped-product-list-item');
            const $priceCell = $row.find('.woocommerce-grouped-product-list-item__price');
            const $ins = $priceCell.find('ins .woocommerce-Price-amount, .woocommerce-Price-amount').last();
            if ($ins.length) {
                let text = $ins.text().replace(currencySymbol, '').trim();
                // Quitar puntos de miles y cambiar coma por punto
                text = text.replace(new RegExp('\\' + thousandSep, 'g'), '').replace(decimalSep, '.');
                const val = parseFloat(text);
                if (!isNaN(val)) {
                    return val;
                }
            }
            return 0;
        }

        /**
         * Mostrar alerta interactiva
         */
        function showAlert(message) {
            const $alert = $('#batllie-box-alert');
            const $text = $('#batllie-alert-text');
            $text.text(message);
            $alert.stop(true, true).fadeIn(200);

            // Efecto shake
            $boxContainer.removeClass('batllie-shake');
            void $boxContainer[0].offsetWidth; // reflow
            $boxContainer.addClass('batllie-shake');

            // Scroll si no está en vista
            const rect = $alert[0].getBoundingClientRect();
            if (rect.top < 0 || rect.bottom > window.innerHeight) {
                $alert[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        /**
         * Ocultar alerta interactiva
         */
        function hideAlert() {
            $('#batllie-box-alert').fadeOut(150);
            $boxContainer.removeClass('batllie-shake');
        }

        /**
         * Calcular estado total, actualizar UI y controlar botones
         */
        function updateGroupedState() {
            let totalSelected = 0;
            let totalPrice = 0;

            const $inputs = $table.find('input.qty');

            $inputs.each(function () {
                const $inp = $(this);
                let q = parseInt($inp.val(), 10);
                if (isNaN(q) || q < 0) {
                    q = 0;
                    $inp.val(0);
                }
                const unitPrice = getUnitPrice($inp);
                totalSelected += q;
                totalPrice += q * unitPrice;
            });

            // Si se pasó del máximo permitido (por haber escrito en el teclado)
            if (totalSelected > targetQty) {
                const excess = totalSelected - targetQty;
                // Reducir del último input modificado
                const $active = $(document.activeElement);
                if ($active.is('input.qty') && parseInt($active.val(), 10) >= excess) {
                    $active.val(parseInt($active.val(), 10) - excess);
                }
                // Recalcular
                return updateGroupedState();
            }

            // Elementos del DOM
            const $current = $('#batllie-box-current');
            const $target = $('#batllie-box-target');
            const $fill = $('#batllie-box-progress-fill');
            const $badge = $('#batllie-box-badge');
            const $msg = $('#batllie-box-message');
            const $totalDisplay = $('#batllie-box-total-price');

            $current.text(totalSelected);
            $target.text(targetQty);

            // Porcentaje barra de progreso
            const pct = Math.min(100, Math.round((totalSelected / targetQty) * 100));
            $fill.css('width', pct + '%');

            // Badge y Mensaje según estado
            $badge.removeClass('badge-empty badge-incomplete badge-complete');
            $fill.removeClass('fill-complete');

            if (totalSelected === 0) {
                $badge.addClass('badge-empty').text(i18n.boxEmpty || 'Caja vacía');
                $msg.text(i18n.selectPrompt || `Seleccioná ${targetQty} unidades para armar tu caja.`);
            } else if (totalSelected < targetQty) {
                const diff = targetQty - totalSelected;
                $badge.addClass('badge-incomplete').text(diff === 1 ? 'Falta 1 unidad' : `Faltan ${diff} unidades`);
                $msg.text(`Te falta${diff === 1 ? ' 1 producto' : 'n ' + diff + ' productos'} para completar tu caja de ${targetQty}.`);
            } else {
                $badge.addClass('badge-complete').text(i18n.boxComplete || '¡Caja completa!');
                $fill.addClass('fill-complete');
                $msg.text(`¡Excelente! Tenés las ${targetQty} unidades seleccionadas. Ya podés añadirla al carrito.`);
                hideAlert();
            }

            // Precio total dinámico
            $totalDisplay.html(formatMoney(totalPrice));

            // Control de botones "+" de las filas
            const $plusButtons = $table.find('.emp-qty-plus, .plus');
            if (totalSelected >= targetQty) {
                $plusButtons.addClass('batllie-plus-locked').attr('aria-disabled', 'true');
            } else {
                $plusButtons.removeClass('batllie-plus-locked').removeAttr('aria-disabled');
            }

            // Control de botón "Añadir al carrito"
            if (totalSelected === targetQty) {
                $submitBtn.removeClass('batllie-btn-disabled').removeAttr('aria-disabled');
            } else {
                $submitBtn.addClass('batllie-btn-disabled').attr('aria-disabled', 'true');
            }
        }

        // Interceptar clicks en botón "+" para evitar superar targetQty
        $form.on('click', '.emp-qty-plus, .plus', function (e) {
            let totalSelected = 0;
            $table.find('input.qty').each(function () {
                totalSelected += parseInt($(this).val(), 10) || 0;
            });

            if (totalSelected >= targetQty) {
                e.preventDefault();
                e.stopPropagation();
                showAlert(i18n.maxReached || `Ya alcanzaste el máximo de ${targetQty} unidades para esta caja.`);
                return false;
            }
        });

        // Interceptar clicks en botón "-" para no bajar de 0
        $form.on('click', '.emp-qty-minus, .minus', function (e) {
            const $inp = $(this).closest('.quantity').find('input.qty');
            const current = parseInt($inp.val(), 10) || 0;
            if (current <= 0) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        });

        // Eventos en inputs de cantidad
        $table.on('input change keyup', 'input.qty', function () {
            updateGroupedState();
        });

        // Detectar cambios realizados por los steppers del tema con un observador corto
        $table.on('click', '.emp-qty-btn, .plus, .minus', function () {
            setTimeout(updateGroupedState, 50);
            setTimeout(updateGroupedState, 150);
        });

        // Interceptar click en el botón de Añadir al Carrito
        $submitBtn.on('click', function (e) {
            let totalSelected = 0;
            $table.find('input.qty').each(function () {
                totalSelected += parseInt($(this).val(), 10) || 0;
            });

            if (totalSelected !== targetQty) {
                e.preventDefault();
                e.stopPropagation();

                const diff = targetQty - totalSelected;
                let message;
                if (diff > 0) {
                    message = `Debes incluir exactamente ${targetQty} unidades para armar tu caja. Actualmente seleccionaste ${totalSelected} (te falta${diff === 1 ? ' 1' : 'n ' + diff}).`;
                } else {
                    message = `Debes incluir exactamente ${targetQty} unidades para armar tu caja. Actualmente seleccionaste ${totalSelected}.`;
                }

                showAlert(message);
                return false;
            }
        });

        // Inicializar estado al cargar la página
        updateGroupedState();
    });

})(jQuery);
