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
        /**
         * Calcular estado total, actualizar UI y controlar botones
         */
        function updateGroupedState() {
            let totalSelected = 0;
            const $inputs = $table.find('input.qty');

            // Leer cantidades actuales
            $inputs.each(function () {
                const $inp = $(this);
                let q = parseInt($inp.val(), 10);
                if (isNaN(q) || q < 0) {
                    q = 0;
                    $inp.val(0);
                }
                totalSelected += q;
            });

            // Si por tipeo manual se superó el límite, recortar el exceso de forma lineal SIN recursión
            if (totalSelected > targetQty) {
                let excess = totalSelected - targetQty;
                // Primero reducir del input actualmente activo si es un input de cantidad
                const active = document.activeElement;
                if (active && active.classList && active.classList.contains('qty')) {
                    const cur = parseInt(active.value, 10) || 0;
                    const reduce = Math.min(cur, excess);
                    active.value = cur - reduce;
                    excess -= reduce;
                }
                // Si aún sobra exceso, recortar de los demás inputs
                if (excess > 0) {
                    for (let i = $inputs.length - 1; i >= 0 && excess > 0; i--) {
                        const inp = $inputs[i];
                        const cur = parseInt(inp.value, 10) || 0;
                        const reduce = Math.min(cur, excess);
                        inp.value = cur - reduce;
                        excess -= reduce;
                    }
                }
                // Recalcular total real ya recortado
                totalSelected = 0;
                $inputs.each(function () {
                    const q = parseInt($(this).val(), 10) || 0;
                    totalSelected += q;
                });
            }

            // Calcular precio total exacto
            let totalPrice = 0;
            $inputs.each(function () {
                const $inp = $(this);
                const q = parseInt($inp.val(), 10) || 0;
                const unitPrice = getUnitPrice($inp);
                totalPrice += q * unitPrice;
            });

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

        /**
         * Manejador de clic en los botones + y - del stepper.
         * Se registra en la fase de captura (true) para ejecutarse ANTES de que el script
         * del tema (complements.js) interfiera, evitando el bug donde parseFloat("") || 1 salta de 0 a 2.
         */
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.emp-qty-btn, .plus, .minus');
            if (!btn) return;

            const form = btn.closest('form.grouped_form');
            if (!form) return; // Solo actuar en el formulario de producto agrupado

            // Frenar inmediatamente la propagación hacia complements.js del tema
            e.stopImmediatePropagation();
            e.preventDefault();

            const container = btn.closest('.quantity');
            if (!container) return;
            const input = container.querySelector('input.qty');
            if (!input) return;

            let currentVal = parseInt(input.value, 10);
            if (isNaN(currentVal) || currentVal < 0) {
                currentVal = 0;
            }

            const isMinus = btn.classList.contains('emp-qty-minus') || btn.classList.contains('minus') || btn.textContent.trim() === '-';
            const isPlus = btn.classList.contains('emp-qty-plus') || btn.classList.contains('plus') || btn.textContent.trim() === '+';

            if (isMinus) {
                if (currentVal > 0) {
                    input.value = currentVal - 1;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
                updateGroupedState();
                return;
            }

            if (isPlus) {
                // Calcular total actual
                let total = 0;
                form.querySelectorAll('input.qty').forEach(inp => {
                    const v = parseInt(inp.value, 10);
                    total += (isNaN(v) || v < 0) ? 0 : v;
                });

                if (total >= targetQty) {
                    const maxMsg = (i18n.maxReached || `Ya alcanzaste el máximo de ${targetQty} unidades para esta caja.`).replace('%d', targetQty);
                    showAlert(maxMsg);
                    return;
                }

                // Incrementar exactamente en 1 (ej: de 0 a 1, de 1 a 2)
                input.value = currentVal + 1;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('input', { bubbles: true }));
                updateGroupedState();
                return;
            }
        }, true); // useCapture = true para interceptar antes del tema

        // Eventos en inputs de cantidad (tipeo directo)
        $table.on('input change keyup', 'input.qty', function () {
            updateGroupedState();
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

        // Asegurar que todos los inputs tengan un 0 inicial limpio si vienen vacíos
        $table.find('input.qty').each(function () {
            if ($(this).val() === '' || isNaN(parseInt($(this).val(), 10))) {
                $(this).val(0);
            }
        });

        // Inicializar estado al cargar la página
        updateGroupedState();
    });

})(jQuery);
