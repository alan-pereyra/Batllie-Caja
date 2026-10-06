/**
 * Batllie Caja - Aplicación Principal Frontend
 */

(function($) {
    'use strict';

    const App = {
        config: window.batllieCajaConfig || {},
        audio: window.BatllieCajaAudio,
        pollTimer: null,
        lastSeenOrderId: 0,
        activeTab: 'tab-orders',
        currentStatusFilter: 'all',
        currentTimeFilter: 'nuevos',
        searchKeyword: '',
        isProductsLoaded: false,
        categoriesLoaded: false,
        cachedOrders: [],
        isUserInteracting: false,
        pendingOrdersUpdate: null,

        isDropdownOrControlInUse: function() {
            if (this.isUserInteracting) return true;
            const el = document.activeElement;
            if (!el) return false;
            const tag = el.tagName;
            if (tag === 'SELECT' || tag === 'INPUT' || tag === 'TEXTAREA') {
                return true;
            }
            return false;
        },

        haveOrdersChanged: function(oldOrders, newOrders) {
            if (!oldOrders || !newOrders) return true;
            if (oldOrders.length !== newOrders.length) return true;

            for (let i = 0; i < newOrders.length; i++) {
                const n = newOrders[i];
                const o = oldOrders[i];
                if (!o || o.id !== n.id) return true;
                if (o.status !== n.status) return true;
                if (o.payment_status !== n.payment_status) return true;
                if (o.shipping_status !== n.shipping_status) return true;
                if ((o.customer_note || '') !== (n.customer_note || '')) return true;
                if ((o.caja_note || '') !== (n.caja_note || '')) return true;
                if (o.total !== n.total) return true;
                if (o.boxes_6_qty !== n.boxes_6_qty) return true;
                if (o.boxes_12_qty !== n.boxes_12_qty) return true;
                if (o.bag_large !== n.bag_large) return true;
                if (o.bag_small !== n.bag_small) return true;
                if (o.control_pedido_verified !== n.control_pedido_verified) return true;
                if ((o.packing_theme || '') !== (n.packing_theme || '')) return true;
                if ((o.shipping_slot || '') !== (n.shipping_slot || '')) return true;
                if ((o.shipping_slot_badge || '') !== (n.shipping_slot_badge || '')) return true;
                const oLen = (o.timeline && Array.isArray(o.timeline)) ? o.timeline.length : 0;
                const nLen = (n.timeline && Array.isArray(n.timeline)) ? n.timeline.length : 0;
                if (oLen !== nLen) return true;
            }
            return false;
        },
        init: function() {
            this.currentSlotFilter = 'all';
            this.cachedPackingThemes = (this.config && this.config.packingThemes) || [];
            this.bindEvents();

            if (this.config.isUserLoggedIn) {
                this.initDashboard();

                // Soportar navegación directa por URL / Hash (#productos o ?tab=productos, #configuracion)
                const urlParams = new URLSearchParams(window.location.search);
                const hash = window.location.hash.toLowerCase();
                if (hash === '#productos' || hash === '#tab-products' || urlParams.get('tab') === 'productos' || urlParams.get('tab') === 'products') {
                    this.switchTab('tab-products');
                } else if (hash === '#configuracion' || hash === '#tab-settings' || urlParams.get('tab') === 'configuracion' || urlParams.get('tab') === 'settings') {
                    this.switchTab('tab-settings');
                }
            } else {
                // Si el usuario intentó acceder con parámetros en la URL (?username=...&password=...)
                const urlParams = new URLSearchParams(window.location.search);
                const u = urlParams.get('username');
                const p = urlParams.get('password');
                if (u || p) {
                    if (u) $('#caja-username').val(u);
                    if (p) $('#caja-password').val(p);
                    // Limpiar la URL inmediatamente para proteger las credenciales
                    if (window.history && window.history.replaceState) {
                        const cleanUrl = window.location.pathname + window.location.hash;
                        window.history.replaceState({}, document.title, cleanUrl);
                    }
                    if (u && p) {
                        setTimeout(function() {
                            $('#caja-login-form').trigger('submit');
                        }, 150);
                    }
                }
            }
        },

        bindEvents: function() {
            const self = this;

            // Unlock audio context en el primer toque/click
            $(document).one('click touchstart', function() {
                if (self.audio) self.audio.unlock();
            });

            // Login
            $(document).on('submit', '#caja-login-form', function(e) {
                e.preventDefault();
                self.handleLogin($(this));
            });

            // Logout
            $(document).on('click', '#caja-logout-btn', function(e) {
                e.preventDefault();
                self.handleLogout();
            });

            // Navegación de Pestañas
            $(document).on('click', '.caja-tab-btn', function() {
                const tabId = $(this).data('tab');
                self.switchTab(tabId);
            });

            // Botón 'En Vivo' / 'Ir a Caja': Alterna entre Pedidos y Productos en la misma posición
            $(document).on('click', '#caja-live-status', function(e) {
                e.preventDefault();
                self.toggleScreen();
            });

            // Botón de cambio de pantalla entre sí (alternador directo)
            $(document).on('click', '#caja-btn-screen-toggle', function(e) {
                e.preventDefault();
                self.toggleScreen();
            });

            // Botones rápidos de cambio de pantalla contextuales
            $(document).on('click', '.caja-btn-screen-switch', function(e) {
                e.preventDefault();
                const target = $(this).data('target');
                if (target) {
                    self.switchTab(target);
                } else {
                    self.toggleScreen();
                }
            });

            // Filtro Desplegable de Estados
            $(document).on('change', '#caja-filter-status', function() {
                self.currentStatusFilter = $(this).val();
                self.applyFilters();
            });

            // Filtro Desplegable de Horarios de Envío (Tandas)
            $(document).on('change', '#caja-filter-slot', function() {
                self.currentSlotFilter = $(this).val();
                self.applyFilters();
            });

            // Filtro Desplegable de Tiempo
            $(document).on('change', '#caja-filter-time', function() {
                self.currentTimeFilter = $(this).val();
                self.applyFilters();
            });

            // Agregar nuevo horario de despacho en configuración
            $(document).on('click', '#caja-btn-add-slot', function(e) {
                e.preventDefault();
                const $input = $('#caja-new-slot-input');
                const rawVal = $.trim($input.val());
                if (!rawVal || !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(rawVal)) {
                    alert('Por favor ingresá un horario válido (ej: 14:00 o 20:00).');
                    return;
                }
                const formatted = rawVal.length === 4 ? '0' + rawVal : rawVal;
                let exists = false;
                $('#caja-slots-list .caja-slot-chip').each(function() {
                    if ($(this).data('slot') === formatted) exists = true;
                });
                if (exists) {
                    alert('Ese horario ya está en la lista.');
                    return;
                }
                const chipHtml = `
                    <div class="caja-slot-chip" data-slot="${formatted}">
                        <input type="hidden" name="batllie_caja_options[shipping_slots][]" value="${formatted}" />
                        <span class="caja-slot-icon">🕒</span>
                        <span class="caja-slot-text">${formatted} hs</span>
                        <button type="button" class="caja-slot-remove-btn" title="Eliminar horario">✕</button>
                    </div>
                `;
                $('#caja-slots-list').append(chipHtml);
                $input.val('');
            });

            // Eliminar horario de despacho en configuración
            $(document).on('click', '.caja-slot-remove-btn', function(e) {
                e.preventDefault();
                $(this).closest('.caja-slot-chip').remove();
            });

            // Guardar aclaración / nota de compra del pedido
            $(document).on('click', '.caja-btn-save-note', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $btn = $(this);
                const $textarea = $(`.caja-order-note-textarea[data-order-id="${orderId}"]`);
                const noteText = $textarea.val();
                self.saveOrderNote(orderId, noteText, $btn);
            });

            // Botón para ver todos los pedidos desde el estado vacío
            $(document).on('click', '.caja-btn-show-all', function() {
                $('#caja-filter-time').val('all');
                self.currentTimeFilter = 'all';
                self.applyFilters();
            });

            // Toggle de tarjeta de felicitación por Decisión Correcta / Aumentó su pedido
            $(document).on('change', '.caja-felicitacion-checkbox', function() {
                const orderId = $(this).data('order-id');
                const isChecked = $(this).is(':checked');
                const $card = $(this).closest('.caja-felicitacion-card, .caja-box-felicitacion-card');

                const ajaxUrl = (self.config && self.config.ajaxUrl) || (window.batllieCajaConfig && window.batllieCajaConfig.ajaxUrl) || '';
                const nonce = (self.config && self.config.nonce) || (window.batllieCajaConfig && window.batllieCajaConfig.nonce) || '';

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'emp_caja_toggle_tarjeta',
                        security: nonce,
                        order_id: orderId,
                        incluida: isChecked ? 'yes' : 'no'
                    },
                    success: function(res) {
                        if (res && res.success) {
                            if (isChecked) {
                                $card.addClass('is-included');
                            } else {
                                $card.removeClass('is-included');
                            }
                        }
                    }
                });
            });

            // Cambio de estado de pago personalizado (con bloqueo estricto si no está aprobado)
            $(document).on('change', '.caja-payment-select', function() {
                const orderId = $(this).data('order-id');
                const val = $(this).val();
                const $card = $(`#caja-order-card-${orderId}`);
                const $mainDropdown = $card.find('.caja-main-status-dropdown');
                const $shipSelect = $card.find('.caja-shipping-select');
                const $controlBtnWrap = $card.find('.caja-control-pedido-btn-wrap');
                const isApproved = (val === 'pagado' || val === 'efectivo_entrega');

                // Bloqueo de pasos posteriores si el pago no fue aprobado
                if (isApproved) {
                    $mainDropdown.find('option[value="processing"]').prop('disabled', false).text('En preparación');
                    $mainDropdown.find('option[value="enviando"]').prop('disabled', false).text('Enviando');
                    $mainDropdown.find('option[value="completed"]').prop('disabled', false).text('Completado');
                    $shipSelect.find('option').prop('disabled', false);
                    if ($mainDropdown.val() === 'enviando' || $mainDropdown.val() === 'on-hold') {
                        $controlBtnWrap.show();
                    } else {
                        $controlBtnWrap.hide();
                    }
                } else {
                    $mainDropdown.find('option[value="processing"]').prop('disabled', true).text('En preparación 🔒 (requiere pago)');
                    $mainDropdown.find('option[value="enviando"]').prop('disabled', true).text('Enviando 🔒 (requiere pago)');
                    $mainDropdown.find('option[value="completed"]').prop('disabled', true).text('Completado 🔒 (requiere pago)');
                    $shipSelect.find('option:not([value="no_gestionado"])').prop('disabled', true);
                    $controlBtnWrap.hide();
                }

                // Actualizar clases de color status-pay-*
                $(this).removeClass('status-pay-pagado status-pay-pendiente status-pay-efectivo_entrega status-pay-pendiente_devolucion status-pay-devolucion')
                       .addClass('status-pay-' + val);

                // Actualizar objeto en caché
                const cachedOrder = self.cachedOrders.find(o => o.id == orderId);
                if (cachedOrder) {
                    cachedOrder.payment_status = val;
                }

                self.updateCustomStatus(orderId, 'payment_status', val, $(this));
            });

            // Cambio de estado de envío personalizado (bloqueado si el pago no está aprobado)
            $(document).on('change', '.caja-shipping-select', function() {
                const orderId = $(this).data('order-id');
                const val = $(this).val();
                const $card = $(`#caja-order-card-${orderId}`);
                const $mainDropdown = $card.find('.caja-main-status-dropdown');
                const $paySelect = $card.find('.caja-payment-select');
                const payVal = $paySelect.val();
                const isApproved = (payVal === 'pagado' || payVal === 'efectivo_entrega');

                if (val !== 'no_gestionado' && !isApproved) {
                    alert('⚠️ No se puede gestionar el envío porque el pago aún no ha sido confirmado (Pagado o Efectivo en entrega).');
                    $(this).val('no_gestionado');
                    return;
                }

                // Actualizar clases de color status-ship-*
                $(this).removeClass('status-ship-no_gestionado status-ship-esperando_repartidor status-ship-enviando status-ship-demorado status-ship-en_puerta status-ship-entregado status-ship-entregado_problemas')
                       .addClass('status-ship-' + val);

                const isShippingEnviando = (val === 'enviando' || val === 'demorado' || val === 'en_puerta' || val === 'entregado' || val === 'entregado_problemas');
                if (isShippingEnviando) {
                    $card.find('.caja-felicitacion-card, .caja-box-felicitacion-card').slideUp(200);
                } else {
                    $card.find('.caja-felicitacion-card, .caja-box-felicitacion-card').slideDown(200);
                }

                if (val === 'entregado') {
                    // Sincronización: El botón grande cambia a Recibido (completed)
                    $mainDropdown.val('completed');
                    $mainDropdown.removeClass('status-bg-pending status-bg-processing status-bg-enviando status-bg-completed status-bg-recibido-problema status-bg-cancelled status-bg-refunded status-bg-on-hold status-bg-failed')
                                 .addClass('status-bg-completed');
                    $card.find('.caja-control-pedido-btn-wrap').hide();
                } else if (val === 'entregado_problemas') {
                    // Sincronización: El botón grande cambia a Recibido (con problemas)
                    $mainDropdown.val('recibido-problema');
                    $mainDropdown.removeClass('status-bg-pending status-bg-processing status-bg-enviando status-bg-completed status-bg-recibido-problema status-bg-cancelled status-bg-refunded status-bg-on-hold status-bg-failed')
                                 .addClass('status-bg-recibido-problema');
                    $card.find('.caja-control-pedido-btn-wrap').hide();
                }

                self.updateCustomStatus(orderId, 'shipping_status', val, $(this));
            });

            // Búsqueda de pedidos
            $('#caja-search-orders').on('input', function() {
                self.searchKeyword = $(this).val().toLowerCase().trim();
                self.applyFilters();
            });

            // Recarga manual de pedidos
            $('#caja-manual-refresh').on('click', function() {
                $(this).addClass('caja-spin');
                self.loadOrders(false, function() {
                    $('#caja-manual-refresh').removeClass('caja-spin');
                });
            });

            // Cambio de estado principal de la venta mediante menú desplegable a todo el ancho
            $(document).on('change', '.caja-main-status-dropdown', function() {
                const select = $(this);
                const orderId = select.data('order-id');
                const newStatus = select.val();
                const $card = $(`#caja-order-card-${orderId}`);
                const $grid = $(`#caja-meta-grid-${orderId}`);
                const $payBox = $(`#caja-meta-payment-${orderId}`);
                const $pkgBox = $(`#caja-meta-packaging-${orderId}`);
                const $shipBox = $(`#caja-meta-shipping-${orderId}`);
                const $paySelect = $card.find('.caja-payment-select');
                const $shipSelect = $card.find('.caja-shipping-select');
                const $controlBtnWrap = $card.find('.caja-control-pedido-btn-wrap');
                const payVal = $paySelect.val();
                const isApproved = (payVal === 'pagado' || payVal === 'efectivo_entrega');

                // Validación estricta: si el pago no fue chequeado, los siguientes pasos están bloqueados
                const cachedOrder = self.cachedOrders.find(o => o.id == orderId);
                const currentStatus = cachedOrder ? cachedOrder.status : 'pending';

                if ((newStatus === 'processing' || newStatus === 'enviando' || newStatus === 'completed') && !isApproved) {
                    alert('⚠️ No se puede avanzar el pedido porque el pago aún no ha sido confirmado (Pagado o Efectivo en entrega).');
                    select.val(currentStatus);
                    return;
                }

                const setVisible = ($el, show) => {
                    if (show) {
                        $el.removeClass('caja-meta-hidden').show();
                    } else {
                        $el.addClass('caja-meta-hidden').hide();
                    }
                };

                const isPkgVerified = cachedOrder && (cachedOrder.control_pedido_verified === true || cachedOrder.control_pedido_verified === 'yes');

                // Ajustar visibilidad dinámica del menú ubicado ARRIBA del botón de estado
                if (newStatus === 'pending') {
                    setVisible($payBox, true);
                    setVisible($pkgBox, false);
                    setVisible($shipBox, false);
                    $controlBtnWrap.hide();
                } else if (newStatus === 'processing') {
                    setVisible($payBox, !isApproved);
                    setVisible($pkgBox, !isPkgVerified);
                    setVisible($shipBox, false);
                    $controlBtnWrap.hide();
                } else if (newStatus === 'enviando' || newStatus === 'on-hold') {
                    setVisible($payBox, false);
                    setVisible($pkgBox, !isPkgVerified);
                    setVisible($shipBox, true);
                    $controlBtnWrap.show();
                    const $controlBtn = $controlBtnWrap.find('.caja-btn-control-pedido');
                    if (isPkgVerified) {
                        $controlBtn.addClass('is-verified').removeClass('status-bg-enviando');
                    } else {
                        $controlBtn.removeClass('is-verified').addClass('status-bg-enviando');
                    }
                } else if (newStatus === 'completed') {
                    setVisible($payBox, false);
                    setVisible($pkgBox, false);
                    setVisible($shipBox, true);
                    $controlBtnWrap.hide();
                    // Sincronización: selector de la moto se pone en Recibido sin problemas
                    $shipSelect.val('entregado');
                    $shipSelect.removeClass('status-ship-no_gestionado status-ship-esperando_repartidor status-ship-enviando status-ship-demorado status-ship-en_puerta status-ship-entregado status-ship-entregado_problemas')
                               .addClass('status-ship-entregado');
                } else if (newStatus === 'recibido-problema') {
                    setVisible($payBox, false);
                    setVisible($pkgBox, false);
                    setVisible($shipBox, true);
                    $controlBtnWrap.hide();
                    // Sincronización: selector de la moto se pone en Recibido con problemas
                    $shipSelect.val('entregado_problemas');
                    $shipSelect.removeClass('status-ship-no_gestionado status-ship-esperando_repartidor status-ship-enviando status-ship-demorado status-ship-en_puerta status-ship-entregado status-ship-entregado_problemas')
                               .addClass('status-ship-entregado_problemas');
                } else if (newStatus === 'cancelled') {
                    setVisible($payBox, true);
                    setVisible($pkgBox, false);
                    setVisible($shipBox, false);
                    $controlBtnWrap.hide();
                } else if (newStatus === 'refunded') {
                    // Si se reembolsa, se debe elegir entre devolver el stock de los paquetes o no
                    self.openRefundStockModal(orderId, cachedOrder, select, currentStatus);
                    return;
                }

                const hasVisibleMeta = $payBox.is(':visible') || $pkgBox.is(':visible') || $shipBox.is(':visible');
                setVisible($grid, hasVisibleMeta);

                select.removeClass('status-bg-pending status-bg-processing status-bg-enviando status-bg-completed status-bg-recibido-problema status-bg-cancelled status-bg-refunded status-bg-on-hold status-bg-failed is-verified-primary');
                select.addClass('status-bg-' + newStatus);
                if (isPkgVerified && (newStatus === 'enviando' || newStatus === 'on-hold')) {
                    select.addClass('is-verified-primary');
                }

                const isMainFinished = (newStatus === 'completed' || newStatus === 'recibido-problema' || newStatus === 'cancelled' || newStatus === 'refunded');
                if (isMainFinished) {
                    $card.find('.caja-felicitacion-card, .caja-box-felicitacion-card').slideUp(200);
                }

                self.updateOrderStatus(orderId, newStatus, select);
            });

            // -------------------------------------------------------------
            // EVENTOS DE CONTROL DE EMPAQUE (Cajas 6/12 y Bolsas de envío)
            // -------------------------------------------------------------
            // Abrir vista de edición de cajas oficiales
            $(document).on('click', '.caja-btn-modify-pkg', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const order = self.cachedOrders.find(o => o.id == orderId);
                if (order && (order.control_pedido_verified === true || order.control_pedido_verified === 'yes')) {
                    alert('⚠️ Este pedido ya fue verificado y no se puede modificar su empaque.');
                    return;
                }
                $(`#caja-pkg-summary-${orderId}`).hide();
                $(`#caja-pkg-edit-${orderId}`).slideDown(150);
            });

            // Cancelar edición de cajas oficiales
            $(document).on('click', '.btn-cancel-pkg-boxes', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const order = self.cachedOrders.find(o => o.id == orderId);
                if (order) {
                    $(`#pkg-input-box-12-${orderId}`).val(order.boxes_12_qty || 0);
                    $(`#pkg-input-box-6-${orderId}`).val(order.boxes_6_qty || 0);
                }
                $(`#caja-pkg-edit-${orderId}`).slideUp(150, function() {
                    $(`#caja-pkg-summary-${orderId}`).show();
                });
            });

            // Stepper de cajas oficiales (+ / -)
            $(document).on('click', '.btn-step-box', function(e) {
                e.preventDefault();
                const type = $(this).data('type');
                const action = $(this).data('action');
                const orderId = $(this).data('order-id');
                const $input = $(`#pkg-input-box-${type}-${orderId}`);
                let val = parseInt($input.val(), 10) || 0;
                if (action === 'plus') {
                    val++;
                } else if (action === 'minus') {
                    val = Math.max(0, val - 1);
                }
                $input.val(val);
            });

            // Guardar cambios en cajas oficiales
            $(document).on('click', '.btn-save-pkg-boxes', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const box12 = parseInt($(`#pkg-input-box-12-${orderId}`).val(), 10) || 0;
                const box6 = parseInt($(`#pkg-input-box-6-${orderId}`).val(), 10) || 0;
                const bagLarge = parseInt($(`#pkg-input-bag-large-${orderId}`).val(), 10) || 1;
                const bagSmall = parseInt($(`#pkg-input-bag-small-${orderId}`).val(), 10) || 0;

                const $btn = $(this);
                $btn.prop('disabled', true).text('Guardando...');

                self.savePackaging(orderId, box6, box12, bagLarge, bagSmall, function(success) {
                    $btn.prop('disabled', false).text('💾 Guardar cajas');
                    if (success) {
                        $(`#caja-pkg-edit-${orderId}`).slideUp(150, function() {
                            $(`#caja-pkg-summary-${orderId}`).show();
                        });
                    }
                });
            });

            // Stepper de bolsas grandes y chicas (+ / - con persistencia)
            $(document).on('click', '.btn-step-bag', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const order = self.cachedOrders.find(o => o.id == orderId);
                if (order && (order.control_pedido_verified === true || order.control_pedido_verified === 'yes')) {
                    return;
                }
                const type = $(this).data('type');
                const action = $(this).data('action');
                const $input = $(`#pkg-input-bag-${type}-${orderId}`);
                let val = parseInt($input.val(), 10) || 0;
                if (action === 'plus') {
                    val++;
                } else if (action === 'minus') {
                    val = Math.max(0, val - 1);
                }
                $input.val(val);

                const box12 = parseInt($(`#pkg-input-box-12-${orderId}`).val(), 10) || 0;
                const box6 = parseInt($(`#pkg-input-box-6-${orderId}`).val(), 10) || 0;
                const bagLarge = parseInt($(`#pkg-input-bag-large-${orderId}`).val(), 10) || 1;
                const bagSmall = parseInt($(`#pkg-input-bag-small-${orderId}`).val(), 10) || 0;

                self.savePackaging(orderId, box6, box12, bagLarge, bagSmall);
            });

            // -------------------------------------------------------------
            // EVENTOS DE PAQUETERÍA TEMÁTICA ESPECIAL (COLAPSABLE Y OPCIONAL)
            // -------------------------------------------------------------
            // Toggle sección paquetería temática
            $(document).on('click', '.caja-btn-thematic-toggle', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $content = $(`#caja-thematic-content-${orderId}`);
                const $arrow = $(this).find('.caja-thematic-toggle-arrow');
                $(this).toggleClass('active');
                $arrow.toggleClass('open');
                $content.stop(true, true).slideToggle(180);
            });

            // Checkbox "Es un paquete con temática especial"
            $(document).on('change', '.caja-chk-is-thematic', function() {
                const orderId = $(this).data('order-id');
                const isChecked = $(this).is(':checked');
                const $fields = $(`#caja-thematic-fields-${orderId}`);
                const $select = $(`#caja-select-theme-${orderId}`);

                if (isChecked) {
                    $fields.stop(true, true).slideDown(150);
                    const currentTheme = $select.val();
                    if (currentTheme) {
                        self.setOrderPackingTheme(orderId, currentTheme);
                    }
                } else {
                    $fields.stop(true, true).slideUp(150);
                    $select.val('');
                    self.setOrderPackingTheme(orderId, '');
                }
            });

            // Cambio en el desplegable de temática
            $(document).on('change', '.caja-select-packing-theme', function() {
                const orderId = $(this).data('order-id');
                const theme = $(this).val();
                const $chk = $(`.caja-chk-is-thematic[data-order-id="${orderId}"]`);
                const $delBtn = $(`.caja-btn-del-theme[data-order-id="${orderId}"]`);

                if (theme) {
                    $chk.prop('checked', true);
                    if ($delBtn.length) {
                        $delBtn.data('theme', theme).show();
                    }
                    self.setOrderPackingTheme(orderId, theme);
                } else {
                    $chk.prop('checked', false);
                    if ($delBtn.length) {
                        $delBtn.hide();
                    }
                    $(`#caja-thematic-fields-${orderId}`).stop(true, true).slideUp(150);
                    self.setOrderPackingTheme(orderId, '');
                }
            });

            // Botón agregar nueva temática
            $(document).on('click', '.caja-btn-add-theme', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $input = $(`#caja-input-new-theme-${orderId}`);
                const themeName = $.trim($input.val());
                if (!themeName) {
                    alert('Por favor ingrese el nombre de la temática especial.');
                    $input.focus();
                    return;
                }
                self.addNewPackingTheme(themeName, orderId);
            });

            // Enter en el campo de texto de nueva temática
            $(document).on('keypress', '.caja-input-new-theme', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    const orderId = $(this).closest('.caja-thematic-add-row').find('.caja-btn-add-theme').data('order-id');
                    const themeName = $.trim($(this).val());
                    if (!themeName) return;
                    self.addNewPackingTheme(themeName, orderId);
                }
            });

            // Botón eliminar temática de la lista global
            $(document).on('click', '.caja-btn-del-theme', function(e) {
                e.preventDefault();
                const theme = $(this).data('theme') || $(`#caja-select-theme-${$(this).data('order-id')}`).val();
                const orderId = $(this).data('order-id');
                if (!theme) return;
                if (confirm(`¿Eliminar la temática "${theme}" de la lista disponible?`)) {
                    self.deletePackingTheme(theme, orderId);
                }
            });

            // -------------------------------------------------------------
            // EVENTOS DE CONTROL DE PEDIDO (PANTALLA COMPLETA PREVIA A ENVÍO)
            // -------------------------------------------------------------
            // Botón "Control de pedido": abre modal en pantalla completa
            $(document).on('click', '.caja-btn-control-pedido', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const order = self.cachedOrders.find(o => o.id == orderId);
                if (!order) return;

                const isApproved = (order.payment_status === 'pagado' || order.payment_status === 'efectivo_entrega');
                if (!isApproved) {
                    alert('⚠️ Debe confirmar el pago (Pagado o Efectivo en entrega) antes de realizar el control de pedido.');
                    return;
                }

                self.openControlPedidoModal(order);
            });

            // Cerrar modal de Control de Pedido (Acción 1 permitida: la X)
            $(document).on('click', '#caja-control-close-btn', function(e) {
                e.preventDefault();
                $('body').removeClass('caja-modal-locked');
                $('#caja-modal-control-pedido').fadeOut(150);
            });

            // Cambios en los checkboxes del control de pedido
            $(document).on('change', '.caja-control-chk', function() {
                const total = $('.caja-control-chk').length;
                const checked = $('.caja-control-chk:checked').length;
                $('#caja-control-checked-count').text(checked);
                $('#caja-control-total-count').text(total);

                if (total > 0 && checked === total) {
                    $('#caja-btn-iniciar-envio').prop('disabled', false).addClass('is-ready');
                } else {
                    $('#caja-btn-iniciar-envio').prop('disabled', true).removeClass('is-ready');
                }
            });

            // Acción 2 permitida: Iniciar envío tras chequear el 100% de los checkboxes
            $(document).on('click', '#caja-btn-iniciar-envio', function(e) {
                e.preventDefault();
                const orderId = self.currentControlOrderId;
                if (!orderId) return;

                const total = $('.caja-control-chk').length;
                const checked = $('.caja-control-chk:checked').length;
                if (checked < total || total === 0) {
                    alert('Debes chequear todos los productos y empaques antes de iniciar el envío.');
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text('Iniciando envío...');

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'emp_caja_verify_control_pedido',
                        security: self.config.nonce,
                        order_id: orderId
                    },
                    success: function(res) {
                        $('body').removeClass('caja-modal-locked');
                        $('#caja-modal-control-pedido').fadeOut(150);
                        $btn.html('<span>🚀 Iniciar envío</span>');

                        if (res.success) {
                            const order = self.cachedOrders.find(o => o.id == orderId);
                            if (order) {
                                order.control_pedido_verified = true;
                                order.status = 'enviando';
                                order.shipping_status = 'enviando';
                            }
                            self.renderOrders(self.cachedOrders);
                            self.showToast('🚀 Control de pedido completado al 100%. Repartidor enviando.');
                        } else {
                            alert(res.data && res.data.message ? res.data.message : 'Error al verificar control de pedido.');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).html('<span>🚀 Iniciar envío</span>');
                        alert('Error de conexión al iniciar envío.');
                    }
                });
            });

            // -------------------------------------------------------------
            // EVENTOS DE PRODUCTOS RECOMENDADOS (Crear y Editar producto)
            // -------------------------------------------------------------
            // Filtro de búsqueda en lista de recomendados
            $(document).on('input', '#new-rec-search-filter, #edit-rec-search-filter', function() {
                const query = ($(this).val() || '').toLowerCase().trim();
                const isEdit = $(this).attr('id').startsWith('edit');
                const containerId = isEdit ? 'edit-prod-rec-list' : 'new-prod-rec-list';
                $(`#${containerId} .caja-child-select-item`).each(function() {
                    const search = $(this).data('search') || '';
                    if (!query || search.includes(query)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Marcar todos recomendados
            $(document).on('click', '#btn-new-rec-select-all, #btn-rec-select-all', function(e) {
                e.preventDefault();
                const isEdit = $(this).attr('id') === 'btn-rec-select-all';
                const containerId = isEdit ? 'edit-prod-rec-list' : 'new-prod-rec-list';
                const badgeId = isEdit ? 'edit-rec-selected-badge' : 'new-rec-selected-badge';
                $(`#${containerId} .caja-child-select-item:visible .caja-rec-chk`).prop('checked', true);
                self.updateRecommendationsCount(containerId, badgeId);
            });

            // Desmarcar todos recomendados
            $(document).on('click', '#btn-new-rec-deselect-all, #btn-rec-deselect-all', function(e) {
                e.preventDefault();
                const isEdit = $(this).attr('id') === 'btn-rec-deselect-all';
                const containerId = isEdit ? 'edit-prod-rec-list' : 'new-prod-rec-list';
                const badgeId = isEdit ? 'edit-rec-selected-badge' : 'new-rec-selected-badge';
                $(`#${containerId} .caja-rec-chk`).prop('checked', false);
                self.updateRecommendationsCount(containerId, badgeId);
            });

            // Cambio en checkbox de recomendado
            $(document).on('change', '.caja-rec-chk', function() {
                const $list = $(this).closest('.caja-children-checklist-container');
                const containerId = $list.attr('id');
                const isEdit = containerId === 'edit-prod-rec-list';
                const badgeId = isEdit ? 'edit-rec-selected-badge' : 'new-rec-selected-badge';
                self.updateRecommendationsCount(containerId, badgeId);
            });

            // Alarma Sonora: Toggle
            $('#caja-toggle-sound').on('click', function() {
                const isEnabled = self.audio.toggle();
                self.updateSoundUI(isEnabled);
            });

            // Probar sonido
            $('#caja-test-sound').on('click', function() {
                if (self.audio) {
                    self.audio.unlock();
                    self.audio.playNewOrderSound();
                    self.showToast('🔔 Campana de prueba reproducida');
                }
            });

            // Cerrar banner de nuevo pedido
            $('#caja-dismiss-banner').on('click', function() {
                $('#caja-new-order-banner').slideUp(200);
            });

            // Pantalla Completa
            $('#caja-fullscreen-btn').on('click', function() {
                self.toggleFullScreen();
            });

            // Desplegar / ocultar información detallada e historial del pedido
            $(document).on('click', '.caja-btn-details-toggle:not(.caja-btn-notes-toggle)', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $panel = $(`#caja-details-${orderId}`);
                const $arrow = $(this).find('.caja-toggle-arrow');
                const $text = $(this).find('.caja-toggle-text');
                const $btn = $(this);

                $panel.slideToggle(200, function() {
                    if ($panel.is(':visible')) {
                        $btn.addClass('active');
                        $text.text('Ocultar información detallada');
                        $arrow.text('▲');
                    } else {
                        $btn.removeClass('active');
                        $text.text('Ver información detallada');
                        $arrow.text('▼');
                    }
                });
            });

            // Desplegar / ocultar notas adicionales del pedido (quien gestiona la caja)
            $(document).on('click', '.caja-btn-notes-toggle', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $panel = $(`#caja-notes-${orderId}`);
                const $arrow = $(this).find('.caja-toggle-arrow');
                const $text = $(this).find('.caja-toggle-text');
                const $btn = $(this);

                $panel.slideToggle(200, function() {
                    if ($panel.is(':visible')) {
                        $btn.addClass('active');
                        $text.text('Ocultar notas adicionales');
                        $arrow.text('▲');
                    } else {
                        $btn.removeClass('active');
                        $text.text('Notas adicionales');
                        $arrow.text('▼');
                    }
                });
            });

            // Protección de desplegables y controles durante recargas automáticas
            $(document).on('focusin', 'select, input, textarea', function() {
                self.isUserInteracting = true;
            });

            $(document).on('focusout', 'select, input, textarea', function() {
                setTimeout(function() {
                    const el = document.activeElement;
                    const isStillActive = el && (el.tagName === 'SELECT' || el.tagName === 'INPUT' || el.tagName === 'TEXTAREA');
                    if (!isStillActive) {
                        self.isUserInteracting = false;
                        if (self.pendingOrdersUpdate) {
                            const pending = self.pendingOrdersUpdate;
                            self.pendingOrdersUpdate = null;
                            if (self.haveOrdersChanged(self.cachedOrders, pending)) {
                                self.cachedOrders = pending;
                                self.applyFilters();
                            }
                        }
                    }
                }, 350);
            });

            $(document).on('mousedown pointerdown', 'select', function() {
                self.isUserInteracting = true;
            });

            // Búsqueda y Filtro de Productos
            $('#caja-search-products').on('input', function() {
                self.filterProductsInDom();
            });

            $('#caja-filter-product-cat').on('change', function() {
                self.filterProductsInDom();
            });

            $('#caja-filter-product-stock').on('change', function() {
                self.filterProductsInDom();
            });

            // Modal de Nuevo Producto
            $('#caja-btn-open-new-product').on('click', function() {
                $('#caja-new-product-form')[0].reset();
                $('#new-prod-type').val('simple');
                $('#new-prod-box-role-group').show();
                $('#new-prod-image-id').val('');
                $('#new-prod-thumb').hide().attr('src', '');
                $('#new-prod-remove-img-btn').hide();
                $('#new-prod-visibility').val('visible');
                $('#new-prod-featured').prop('checked', false);
                $('#new-prod-box-role').val('none').data('prev-val', 'none');
                $('#new-prod-can-share-box').val('none');
                $('#new-prod-share-max-group').hide();
                $('#new-prod-share-max-alfajores').val('6');
                $('#new-prod-can-share-box').off('change').on('change', function() {
                    if ($(this).val() !== 'none') {
                        $('#new-prod-share-max-group').slideDown(150);
                    } else {
                        $('#new-prod-share-max-group').slideUp(150);
                    }
                });
                $('#caja-stock-qty-group').hide();
                $('#caja-new-product-error').hide();
                if (self.cachedCategories && self.cachedCategories.length > 0) {
                    self.populateCategorySelects();
                } else {
                    self.loadCategories();
                }
                // Load sales suggestions via AJAX
$.ajax({
    url: self.config.ajaxUrl,
    type: 'POST',
    data: {
        action: 'emp_caja_get_sales_suggestions',
        security: self.config.nonce
    },
    success: function(res) {
        var $select = $('#new-prod-sales-suggestions');
        $select.empty();
        $select.append('<option value="none">' + (typeof wc_i18n !== 'undefined' ? wc_i18n.__('-- Ninguna --') : '-- Ninguna --') + '</option>');
        if (res && res.success && res.data && res.data.length) {
            res.data.forEach(function(p) {
                var txt = p.name + ' ($' + p.price + ')';
                $select.append('<option value="' + p.id + '">' + txt + '</option>');
            });
        }
    },
    error: function() {
        console.warn('Failed to load sales suggestions');
    }
});
self.renderRecommendationsList('new-prod-rec-list', [], 0, 'new-rec-selected-badge');
$('#caja-modal-new-product').fadeIn(200);
            });

            $('#new-prod-type').on('change', function() {
                const type = $(this).val();
                if (type === 'simple') {
                    $('#new-prod-box-role-group').slideDown(150);
                } else {
                    $('#new-prod-box-role-group').slideUp(150);
                    $('#new-prod-box-role').val('none');
                }
            });

            $('#caja-modal-close-btn, #caja-modal-cancel-btn').on('click', function() {
                $('#caja-modal-new-product').fadeOut(150);
            });

            $('#new-prod-manage-stock').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#caja-stock-qty-group').slideDown(150);
                } else {
                    $('#caja-stock-qty-group').slideUp(150);
                }
            });

            // Selección de Foto de Producto (Nuevo)
            $('#new-prod-choose-img-btn').on('click', function(e) {
                e.preventDefault();
                self.openMediaUploader({
                    title: 'Seleccionar Foto para el Nuevo Producto',
                    buttonText: 'Usar esta foto',
                    onSelect: function(media) {
                        $('#new-prod-image-id').val(media.id);
                        $('#new-prod-thumb').attr('src', media.url).show();
                        $('#new-prod-remove-img-btn').show();
                    }
                });
            });

            $('#new-prod-remove-img-btn').on('click', function(e) {
                e.preventDefault();
                $('#new-prod-image-id').val('');
                $('#new-prod-thumb').hide().attr('src', '');
                $(this).hide();
            });

            // Selección de Foto de Producto (Modificar)
            $('#edit-prod-choose-img-btn').on('click', function(e) {
                e.preventDefault();
                self.openMediaUploader({
                    title: 'Modificar Foto del Producto',
                    buttonText: 'Asignar como foto de producto',
                    onSelect: function(media) {
                        $('#edit-prod-image-id').val(media.id);
                        $('#edit-prod-thumb').attr('src', media.url).show();
                        $('#edit-prod-remove-img-btn').show();
                    }
                });
            });

            $('#edit-prod-remove-img-btn').on('click', function(e) {
                e.preventDefault();
                $('#edit-prod-image-id').val('0');
                $('#edit-prod-thumb').hide().attr('src', '');
                $(this).hide();
            });

            // Filtro y selección rápida en productos agrupados
            $('#edit-grouped-search-filter').on('input', function() {
                const query = ($(this).val() || '').toLowerCase().trim();
                $('#edit-prod-children-list .caja-child-select-item').each(function() {
                    const search = $(this).data('search') || '';
                    if (!query || search.includes(query)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Cambio de modo de agrupación (Caja personalizable vs Combo predeterminado / fijo)
            $('input[name="grouped_combo_mode"]').on('change', function() {
                const mode = $(this).val();
                $('.caja-radio-pill').removeClass('active');
                $(this).closest('.caja-radio-pill').addClass('active');

                if (mode === 'predefined') {
                    $('#edit-prod-children-list .caja-child-qty-wrap').show();
                    $('#edit-grouped-mode-hint').text('Definí qué productos componen este combo y cuántas unidades fijas incluye cada uno:');
                } else {
                    $('#edit-prod-children-list .caja-child-qty-wrap').hide();
                    $('#edit-grouped-mode-hint').text('Seleccioná cuáles productos simples se incluyen dentro de esta caja agrupada:');
                }
                self.updateGroupedSelectedCount();
            });

            // Cambios de cantidad en productos del combo
            $(document).on('input change', '#edit-prod-children-list .caja-child-qty-input', function() {
                let v = parseInt($(this).val());
                if (isNaN(v) || v < 1) {
                    v = 1;
                    $(this).val(1);
                }
                self.updateGroupedSelectedCount();
            });

            $('#btn-grouped-select-all').on('click', function(e) {
                e.preventDefault();
                const $visibleItems = $('#edit-prod-children-list .caja-child-select-item:visible');
                $visibleItems.find('.caja-child-chk').prop('checked', true);
                $visibleItems.find('.caja-child-qty-input').prop('disabled', false);
                self.updateGroupedSelectedCount();
            });

            $('#btn-grouped-deselect-all').on('click', function(e) {
                e.preventDefault();
                const $items = $('#edit-prod-children-list .caja-child-select-item');
                $items.find('.caja-child-chk').prop('checked', false);
                $items.find('.caja-child-qty-input').prop('disabled', true);
                self.updateGroupedSelectedCount();
            });

            $(document).on('change', '#edit-prod-children-list .caja-child-chk', function() {
                const isChecked = $(this).is(':checked');
                const $item = $(this).closest('.caja-child-select-item');
                $item.find('.caja-child-qty-input').prop('disabled', !isChecked);
                self.updateGroupedSelectedCount();
            });

            // Envío formulario nuevo producto
            $('#caja-new-product-form').on('submit', function(e) {
                e.preventDefault();
                self.handleCreateProduct($(this));
            });

            // Modal de Modificación Completa de Producto (Datos, Precios, SKU, etc.)
            $(document).on('click', '.caja-btn-open-edit-prod', function(e) {
                e.preventDefault();
                const productId = parseInt($(this).data('product-id'));
                self.openEditProductModal(productId);
            });

            $('#caja-edit-prod-close-btn, #caja-edit-prod-cancel-btn').on('click', function() {
                $('#caja-modal-edit-product').fadeOut(150);
            });

            $('#edit-prod-manage-stock').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#caja-edit-stock-qty-group').slideDown(150);
                } else {
                    $('#caja-edit-stock-qty-group').slideUp(150);
                }
            });

            $('#caja-edit-product-form').on('submit', function(e) {
                e.preventDefault();
                self.handleUpdateProduct($(this));
            });

            // Toggle de acordeones desplegables en modales de producto
            $(document).on('click', '.caja-btn-modal-accordion', function(e) {
                e.preventDefault();
                const target = $(this).data('target');
                const $target = $(target);
                const $btn = $(this);
                const $arrow = $btn.find('.caja-toggle-arrow');

                $target.slideToggle(180, function() {
                    if ($target.is(':visible')) {
                        $btn.addClass('active');
                        $arrow.text('▲');
                    } else {
                        $btn.removeClass('active');
                        $arrow.text('▼');
                    }
                });
            });

            // Selector de Imagen física de la caja de empaque (Configuración Pack Agrupado)
            $('#edit-grouped-choose-box-img-btn').on('click', function(e) {
                e.preventDefault();
                self.openMediaUploader({
                    title: 'Seleccionar Imagen de la Caja (Empaque)',
                    buttonText: 'Usar como imagen de la caja',
                    onSelect: function(media) {
                        $('#edit-grouped-box-image-id').val(media.id);
                        $('#edit-grouped-box-img-preview').html(`<img src="${media.url}" style="width:100%; height:100%; object-fit:cover;" />`);
                        $('#edit-grouped-remove-box-img-btn').show();
                    }
                });
            });

            $('#edit-grouped-remove-box-img-btn').on('click', function(e) {
                e.preventDefault();
                $('#edit-grouped-box-image-id').val('');
                $('#edit-grouped-box-img-preview').html('<span style="font-size:20px;">📦</span>');
                $(this).hide();
            });

            // Toggle desglose de precio fijo al cambiar tipo de precio de la caja agrupada
            $('#edit-grouped-pricing-type').on('change', function() {
                if ($(this).val() === 'variable') {
                    $('#edit-grouped-fixed-display-group').slideUp(150);
                } else {
                    $('#edit-grouped-fixed-display-group').slideDown(150);
                }
            });

            // Eventos para Producto Variable: Añadir atributo, eliminar, generar variaciones
            $('#caja-btn-add-attribute').on('click', function(e) {
                e.preventDefault();
                const $container = $('#caja-attributes-list');
                const rowHtml = `
                    <div class="caja-attribute-row">
                        <div class="caja-form-row">
                            <div class="caja-form-group caja-col" style="flex: 1;">
                                <label><strong>Nombre del Atributo</strong></label>
                                <input type="text" class="caja-attr-name" value="" placeholder="Ej: Sabor, Tamaño..." />
                            </div>
                            <div class="caja-form-group caja-col" style="flex: 2;">
                                <label><strong>Opciones (separadas con | )</strong></label>
                                <input type="text" class="caja-attr-options" value="" placeholder="Ej: Negro | Blanco | Pistacho" />
                            </div>
                            <div class="caja-form-group caja-col caja-align-bottom" style="flex: 0 0 auto;">
                                <button type="button" class="caja-btn caja-btn-danger caja-btn-sm caja-remove-attr-btn" title="Eliminar atributo">🗑️</button>
                            </div>
                        </div>
                    </div>
                `;
                $container.append(rowHtml);
            });

            $(document).on('click', '.caja-remove-attr-btn', function(e) {
                e.preventDefault();
                $(this).closest('.caja-attribute-row').remove();
            });

            $('#caja-btn-generate-variations').on('click', function(e) {
                e.preventDefault();
                const attrs = self.collectVariableAttributes();
                if (!attrs.length) {
                    alert('Debes definir al menos un atributo con opciones para generar variaciones.');
                    return;
                }

                const optionsArrays = attrs.map(a => {
                    return a.options.split('|').map(s => s.trim()).filter(Boolean);
                });

                if (optionsArrays.some(arr => !arr.length)) {
                    alert('Todos los atributos deben tener al menos una opción definida.');
                    return;
                }

                function cartesian(arrays) {
                    return arrays.reduce((acc, curr) => {
                        return acc.flatMap(a => curr.map(c => [...a, c]));
                    }, [[]]);
                }

                const combinations = cartesian(optionsArrays);
                const parentPrice = $('#edit-prod-price').val() || '';
                const parentSale = $('#edit-prod-sale-price').val() || '';

                const existingVariations = self.collectVariationsList();
                const newVariations = [];

                combinations.forEach(comb => {
                    const attrMap = {};
                    attrs.forEach((a, i) => {
                        attrMap[a.name] = comb[i];
                    });

                    const match = existingVariations.find(ev => {
                        return attrs.every(a => ev.attributes && (ev.attributes[a.name] || '').toLowerCase() === (attrMap[a.name] || '').toLowerCase());
                    });

                    if (match) {
                        newVariations.push(match);
                    } else {
                        newVariations.push({
                            id: 0,
                            enabled: true,
                            regular_price: parentPrice,
                            sale_price: parentSale,
                            sku: '',
                            manage_stock: false,
                            stock_quantity: 10,
                            image_id: 0,
                            image_url: '',
                            attributes: attrMap
                        });
                    }
                });

                self.renderVariationsList(newVariations, attrs);
            });

            $('#caja-btn-add-variation').on('click', function(e) {
                e.preventDefault();
                const attrs = self.collectVariableAttributes();
                const parentPrice = $('#edit-prod-price').val() || '';
                const parentSale = $('#edit-prod-sale-price').val() || '';
                const currentVars = self.collectVariationsList();

                currentVars.push({
                    id: 0,
                    enabled: true,
                    regular_price: parentPrice,
                    sale_price: parentSale,
                    sku: '',
                    manage_stock: false,
                    stock_quantity: 10,
                    image_id: 0,
                    image_url: '',
                    attributes: {}
                });

                self.renderVariationsList(currentVars, attrs);
            });

            $(document).on('click', '.caja-remove-var-btn', function(e) {
                e.preventDefault();
                const $card = $(this).closest('.caja-variation-card');
                const id = parseInt($card.data('id')) || 0;
                if (id > 0) {
                    if (confirm('¿Eliminar esta variación de producto?')) {
                        $card.data('deleted', true).slideUp(150);
                    }
                } else {
                    $card.slideUp(150, function() { $(this).remove(); });
                }
            });

            $(document).on('change', '.caja-var-manage-stock', function() {
                const $col = $(this).closest('.caja-variation-card-body').find('.caja-var-stock-col');
                if ($(this).is(':checked')) {
                    $col.slideDown(150);
                } else {
                    $col.slideUp(150);
                }
            });

            $(document).on('click', '.caja-var-choose-img-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const $box = $btn.closest('.caja-var-img-box');
                const $img = $box.find('.caja-var-thumb');
                const $input = $box.find('.caja-var-image-id');

                self.openMediaUploader({
                    title: 'Seleccionar Foto para la Variación',
                    buttonText: 'Asignar a variación',
                    onSelect: function(media) {
                        $input.val(media.id);
                        $img.attr('src', media.url).show();
                    }
                });
            });

            // Confirmación al reemplazar una caja oficial existente
            $('#new-prod-box-role, #edit-prod-box-role').on('change', function() {
                const newRole = $(this).val();
                const prevRole = $(this).data('prev-val') || 'none';
                const isEdit = $(this).attr('id') === 'edit-prod-box-role';
                const currentProdId = isEdit ? (parseInt($('#edit-prod-id').val()) || 0) : 0;

                if (newRole === 'box_6') {
                    const existingId = parseInt(self.config.officialBox6Id) || 0;
                    if (existingId > 0 && existingId !== currentProdId) {
                        const existingName = self.config.officialBox6Name || `ID ${existingId}`;
                        const confirmed = window.confirm(`¿Está seguro de reemplazar el producto que se estaba considerando para las cajas de seis?\n\nActualmente asignado: "${existingName}".\nAl confirmar, este producto pasará a ser la nueva caja oficial para descontar inventario.`);
                        if (!confirmed) {
                            $(this).val(prevRole);
                            return;
                        }
                    }
                } else if (newRole === 'box_12') {
                    const existingId = parseInt(self.config.officialBox12Id) || 0;
                    if (existingId > 0 && existingId !== currentProdId) {
                        const existingName = self.config.officialBox12Name || `ID ${existingId}`;
                        const confirmed = window.confirm(`¿Está seguro de reemplazar el producto que se estaba considerando para las cajas de doce?\n\nActualmente asignado: "${existingName}".\nAl confirmar, este producto pasará a ser la nueva caja oficial para descontar inventario.`);
                        if (!confirmed) {
                            $(this).val(prevRole);
                            return;
                        }
                    }
                }

                $(this).data('prev-val', newRole);
            });

            // Soporte para botón atrás/adelante en el historial del navegador
            $(window).on('hashchange', function() {
                const h = window.location.hash.toLowerCase();
                if (h === '#productos' || h === '#tab-products') {
                    if (self.activeTab !== 'tab-products') self.switchTab('tab-products');
                } else if (h === '#configuracion' || h === '#tab-settings') {
                    if (self.activeTab !== 'tab-settings') self.switchTab('tab-settings');
                } else if (h === '#pedidos' || h === '#tab-orders' || !h) {
                    if (self.activeTab !== 'tab-orders') self.switchTab('tab-orders');
                }
            });

            // Modal de Control y Renovación de Stock
            $(document).on('click', '.caja-btn-open-stock-modal', function(e) {
                e.preventDefault();
                const productId = parseInt($(this).data('product-id'));
                self.openStockModal(productId);
            });

            $('#caja-stock-modal-close-btn, #caja-stock-modal-cancel-btn').on('click', function() {
                $('#caja-modal-edit-stock').fadeOut(150);
            });

            // Selector de modo de stock: Sumar ingreso vs Fijar directo
            $(document).on('click', '.caja-stock-mode-btn', function() {
                const mode = $(this).data('mode');
                $('.caja-stock-mode-btn').removeClass('active');
                $(this).addClass('active');
                $('#stock-modal-mode').val(mode);

                if (mode === 'add') {
                    $('#caja-panel-mode-add').show();
                    $('#caja-panel-mode-set').hide();
                    $('#stock-incoming-qty').focus();
                } else {
                    $('#caja-panel-mode-add').hide();
                    $('#caja-panel-mode-set').show();
                    $('#stock-direct-qty').focus();
                }
            });

            // Cálculo reactivo en vivo al tipear unidades que ingresan
            $('#stock-incoming-qty').on('input', function() {
                const incoming = parseInt($(this).val()) || 0;
                const current = parseInt($('#calc-current-num').text()) || 0;
                const total = Math.max(0, current + incoming);

                $('#calc-incoming-num').text(incoming > 0 ? `+${incoming}` : '+0');
                $('#calc-result-num').text(total);
            });

            // Envío formulario de actualización de stock
            $('#caja-edit-stock-form').on('submit', function(e) {
                e.preventDefault();
                self.handleUpdateStock($(this));
            });
        },

        getArgDateKey: function(dateOrTimestamp) {
            let d;
            if (typeof dateOrTimestamp === 'number') {
                d = new Date(dateOrTimestamp * 1000);
            } else if (dateOrTimestamp instanceof Date) {
                d = dateOrTimestamp;
            } else {
                d = new Date(dateOrTimestamp);
            }
            try {
                const formatter = new Intl.DateTimeFormat('en-CA', {
                    timeZone: 'America/Argentina/Buenos_Aires',
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                });
                return formatter.format(d);
            } catch (e) {
                const argMs = d.getTime() - (3 * 3600 * 1000);
                const argDate = new Date(argMs);
                const y = argDate.getUTCFullYear();
                const m = String(argDate.getUTCMonth() + 1).padStart(2, '0');
                const day = String(argDate.getUTCDate()).padStart(2, '0');
                return `${y}-${m}-${day}`;
            }
        },

        getArgDayLabel: function(daysAgo) {
            const targetDate = new Date(Date.now() - (daysAgo * 86400000));
            try {
                const formatter = new Intl.DateTimeFormat('es-AR', {
                    timeZone: 'America/Argentina/Buenos_Aires',
                    weekday: 'long',
                    day: '2-digit',
                    month: '2-digit'
                });
                const parts = formatter.formatToParts(targetDate);
                let weekday = '', day = '', month = '';
                parts.forEach(p => {
                    if (p.type === 'weekday') weekday = p.value;
                    if (p.type === 'day') day = p.value;
                    if (p.type === 'month') month = p.value;
                });
                if (weekday) {
                    weekday = weekday.charAt(0).toUpperCase() + weekday.slice(1);
                }
                return `${weekday} ${day}/${month}`;
            } catch (e) {
                const dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                const argMs = targetDate.getTime() - (3 * 3600 * 1000);
                const d = new Date(argMs);
                const weekday = dias[d.getUTCDay()];
                const day = String(d.getUTCDate()).padStart(2, '0');
                const month = String(d.getUTCMonth() + 1).padStart(2, '0');
                return `${weekday} ${day}/${month}`;
            }
        },

        updateTimeFilterLabels: function() {
            const label1 = this.getArgDayLabel(1);
            const label2 = this.getArgDayLabel(2);

            const $opt1 = $('#caja-filter-time option[value="1_day"]');
            const $opt2 = $('#caja-filter-time option[value="2_days"]');

            if ($opt1.length && label1) {
                $opt1.text(label1);
            }
            if ($opt2.length && label2) {
                $opt2.text(label2);
            }
        },

        initDashboard: function() {
            this.updateTimeFilterLabels();
            if (this.audio) {
                this.audio.setEnabled(this.config.soundEnabled);
                this.updateSoundUI(this.config.soundEnabled);
            }

            this.loadOrders(true);
            this.startPolling();
        },

        updateSoundUI: function(enabled) {
            if (enabled) {
                $('#caja-toggle-sound .sound-icon-on').show();
                $('#caja-toggle-sound .sound-icon-off').hide();
                $('#caja-toggle-sound .caja-btn-tool-label').text('Alarma Activa');
                $('#caja-toggle-sound').removeClass('caja-btn-tool-muted');
            } else {
                $('#caja-toggle-sound .sound-icon-on').hide();
                $('#caja-toggle-sound .sound-icon-off').show();
                $('#caja-toggle-sound .caja-btn-tool-label').text('Silenciado');
                $('#caja-toggle-sound').addClass('caja-btn-tool-muted');
            }
        },

        handleLogin: function($form) {
            const self = this;
            const $btn = $form.find('#caja-login-btn');
            const $err = $('#caja-login-error');

            $err.hide();
            $btn.prop('disabled', true);
            $btn.find('.caja-btn-spinner').show();
            $btn.find('.caja-btn-text').text('Validando acceso...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_login',
                    security: self.config.nonce,
                    username: $.trim($('#caja-username').val()),
                    password: $('#caja-password').val()
                },
                success: function(res) {
                    if (res.success) {
                        $btn.find('.caja-btn-text').text('Acceso concedido...');
                        window.location.href = window.location.pathname;
                    } else {
                        $err.text(res.data && res.data.message ? res.data.message : 'Error al iniciar sesión.').fadeIn(150);
                    }
                },
                error: function() {
                    $err.text('Error de conexión con el servidor.').fadeIn(150);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.caja-btn-spinner').hide();
                    $btn.find('.caja-btn-text').text('Acceder a la Caja');
                }
            });
        },

        handleLogout: function() {
            const self = this;
            if (!confirm('¿Deseas cerrar la sesión de la caja?')) return;

            clearInterval(self.pollTimer);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_logout',
                    security: self.config.nonce
                },
                success: function(res) {
                    window.location.reload();
                }
            });
        },

        toggleScreen: function() {
            if (this.activeTab === 'tab-orders') {
                this.switchTab('tab-products');
            } else {
                this.switchTab('tab-orders');
            }
        },

        switchTab: function(tabId) {
            $('.caja-tab-btn').removeClass('active');
            $(`.caja-tab-btn[data-tab="${tabId}"]`).addClass('active');

            $('.caja-tab-panel').hide();
            $(`#${tabId}`).fadeIn(150);
            this.activeTab = tabId;

            // Actualizar el botón 'En Vivo' / 'Ir a Caja' en la misma posición exacta
            const $livePill = $('#caja-live-status');
            if (tabId === 'tab-products' || tabId === 'tab-settings') {
                $livePill.addClass('caja-live-pill-products');
                $livePill.attr('title', 'Toca para ir a la Caja');
                $livePill.html(`
                    <span class="caja-pulse-dot dot-blue"></span>
                    <span class="caja-live-text">Ir a Caja</span>
                `);
            } else {
                $livePill.removeClass('caja-live-pill-products');
                $livePill.attr('title', 'Toca para ir a Productos');
                $livePill.html(`
                    <span class="caja-pulse-dot"></span>
                    <span class="caja-live-text">En Vivo</span>
                `);
            }

            // Actualizar apariencia y texto del botón de cambio de pantallas (si existe)
            const $toggleBtn = $('#caja-btn-screen-toggle');
            if ($toggleBtn.length) {
                if (tabId === 'tab-products' || tabId === 'tab-settings') {
                    const count = this.cachedOrders ? this.cachedOrders.length : 0;
                    $toggleBtn.addClass('active-in-products');
                    $toggleBtn.html(`
                        <span class="caja-toggle-arrow">⬅</span>
                        <span class="caja-toggle-icon">📋</span>
                        <span class="caja-toggle-text">Volver a Pedidos</span>
                        <span class="caja-badge-count">${count}</span>
                    `);
                } else {
                    $toggleBtn.removeClass('active-in-products');
                    $toggleBtn.html(`
                        <span class="caja-toggle-icon">📦</span>
                        <span class="caja-toggle-text">Cambiar a Productos</span>
                        <span class="caja-toggle-arrow">➔</span>
                    `);
                }
            }

            // Actualizar URL hash para navegación y marcadores directos
            if (window.history && window.history.replaceState) {
                let newHash = '#pedidos';
                if (tabId === 'tab-products') newHash = '#productos';
                else if (tabId === 'tab-settings') newHash = '#configuracion';
                window.history.replaceState(null, null, newHash);
            }

            if (tabId === 'tab-products' && !this.isProductsLoaded) {
                this.loadProducts();
                this.loadCategories();
            } else if (tabId === 'tab-settings') {
                this.initSettingsTab();
            }
        },

        loadOrders: function(showLoading, callback) {
            const self = this;
            if (showLoading) $('#caja-orders-loading').show();

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_get_orders',
                    security: self.config.nonce,
                    status: 'all',
                    limit: 100
                },
                success: function(res) {
                    if (res.success && res.data) {
                        self.cachedOrders = res.data.orders || [];
                        self.updateTimeFilterLabels();
                        $('#caja-orders-count, #caja-products-orders-badge').text(self.cachedOrders.length);
                        self.applyFilters();

                        // Actualizar id más alto conocido
                        self.cachedOrders.forEach(o => {
                            if (o.id > self.lastSeenOrderId) {
                                self.lastSeenOrderId = o.id;
                            }
                        });
                    }
                },
                complete: function() {
                    $('#caja-orders-loading').hide();
                    if (typeof callback === 'function') callback();
                }
            });
        },

        startPolling: function() {
            const self = this;
            const interval = self.config.pollInterval || 10000;

            if (self.pollTimer) clearInterval(self.pollTimer);

            self.pollTimer = setInterval(function() {
                if (!self.config.isUserLoggedIn) return;

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'emp_caja_poll_orders',
                        security: self.config.nonce,
                        last_seen_id: self.lastSeenOrderId,
                        status: 'all'
                    },
                    success: function(res) {
                        if (res.success && res.data) {
                            const newCount = res.data.new_orders_count || 0;
                            const highestId = res.data.highest_id;

                            if (newCount > 0 && highestId > self.lastSeenOrderId) {
                                self.lastSeenOrderId = highestId;

                                // 🔔 DISPARAR ALERTA SONORA
                                if (self.audio) {
                                    self.audio.playNewOrderSound();
                                }

                                // Mostrar banner visual
                                $('#caja-banner-title').text(`¡Nuevo Pedido Entrante #${highestId}!`);
                                $('#caja-new-order-banner').slideDown(200);

                                // Recargar pedidos en pantalla
                                self.loadOrders(false);
                                self.showToast(`🔔 ¡Nuevo pedido #${highestId} recibido!`);
                            } else if (res.data.orders) {
                                const newOrders = res.data.orders;
                                if (self.haveOrdersChanged(self.cachedOrders, newOrders)) {
                                    if (self.isDropdownOrControlInUse()) {
                                        self.pendingOrdersUpdate = newOrders;
                                    } else {
                                        self.cachedOrders = newOrders;
                                        self.applyFilters();
                                    }
                                } else {
                                    // Si no hubo cambios de estado, actualizar únicamente la hora relativa sin tocar el DOM ni cerrar ningún desplegable
                                    newOrders.forEach(no => {
                                        if (no.time_diff) {
                                            $(`#caja-order-card-${no.id} .caja-order-time`).text(no.time_diff);
                                        }
                                    });
                                }
                            }
                        }
                    }
                });
            }, interval);
        },

        renderOrders: function(orders) {
            const self = this;
            const $grid = $('#caja-orders-grid');
            const $empty = $('#caja-orders-empty');

            // Memorizar estado de paneles desplegados y notas antes de re-renderizar
            const openDetailIds = [];
            $('.caja-details-collapse:visible').each(function() {
                const id = $(this).attr('id');
                if (id && id.startsWith('caja-details-')) {
                    openDetailIds.push(id.replace('caja-details-', ''));
                }
            });

            const openNotesIds = [];
            $('.caja-notes-collapse:visible').each(function() {
                const id = $(this).attr('id');
                if (id && id.startsWith('caja-notes-')) {
                    openNotesIds.push(id.replace('caja-notes-', ''));
                }
            });

            const openThematicIds = [];
            $('.caja-thematic-content:visible').each(function() {
                const id = $(this).attr('id');
                if (id && id.startsWith('caja-thematic-content-')) {
                    openThematicIds.push(id.replace('caja-thematic-content-', ''));
                }
            });

            const draftNotes = {};
            $('.caja-order-note-textarea').each(function() {
                const id = $(this).data('order-id');
                if (id) {
                    draftNotes[id] = $(this).val();
                }
            });

            if (!orders || orders.length === 0) {
                $grid.empty();
                if (self.currentTimeFilter === 'nuevos') {
                    $empty.html(`
                        <div class="caja-empty-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="9" cy="21" r="1"></circle>
                                <circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                        </div>
                        <h3>No hay pedidos nuevos (< 30 min)</h3>
                        <p>Los pedidos anteriores se agrupan en las opciones del filtro de tiempo.</p>
                        <button type="button" class="caja-btn-show-all" style="margin-top:14px; padding:10px 20px; background:var(--caja-primary, #10b981); color:var(--caja-bg, #0f172a); border-radius:8px; border:none; font-weight:700; cursor:pointer; font-size:0.95rem;">
                            Ver todos los pedidos anteriores
                        </button>
                    `).show();
                } else if (self.currentTimeFilter === '1_day' || self.currentTimeFilter === '2_days') {
                    const dayLabel = (self.currentTimeFilter === '1_day') ? self.getArgDayLabel(1) : self.getArgDayLabel(2);
                    $empty.html(`
                        <div class="caja-empty-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="9" cy="21" r="1"></circle>
                                <circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                        </div>
                        <h3>No hay pedidos para ${dayLabel}</h3>
                        <p>No se registraron ventas en la fecha seleccionada.</p>
                        <button type="button" class="caja-btn-show-all" style="margin-top:14px; padding:10px 20px; background:var(--caja-primary, #10b981); color:var(--caja-bg, #0f172a); border-radius:8px; border:none; font-weight:700; cursor:pointer; font-size:0.95rem;">
                            Ver todos los pedidos
                        </button>
                    `).show();
                } else {
                    $empty.html(`
                        <div class="caja-empty-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="9" cy="21" r="1"></circle>
                                <circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                        </div>
                        <h3>No hay pedidos en esta sección</h3>
                        <p>Los pedidos que coincidan con los filtros seleccionados aparecerán aquí automáticamente.</p>
                        <button type="button" class="caja-btn-show-all" style="margin-top:14px; padding:10px 20px; background:var(--caja-primary, #10b981); color:var(--caja-bg, #0f172a); border-radius:8px; border:none; font-weight:700; cursor:pointer; font-size:0.95rem;">
                            Ver todos los pedidos
                        </button>
                    `).show();
                }
                return;
            }

            $empty.hide();
            let html = '';

            // Consolidar ítems idénticos para que aparezcan en una sola fila (ej: 4x y 1x -> 5x)
            function parseMoneyVal(val, numVal) {
                if (numVal !== undefined && numVal !== null && !isNaN(parseFloat(numVal))) {
                    return parseFloat(numVal);
                }
                if (typeof val === 'string') {
                    const clean = val.replace(/<[^>]+>/g, '').replace(/[^0-9,\.-]/g, '').trim();
                    const normalized = clean.replace(/\./g, '').replace(',', '.');
                    const parsed = parseFloat(normalized);
                    if (!isNaN(parsed)) return parsed;
                }
                return 0;
            }

            function formatMoneyAr(amount) {
                const num = parseFloat(amount) || 0;
                const parts = num.toFixed(2).split('.');
                const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                const decimalPart = parts[1];
                return '$ ' + integerPart + ',' + decimalPart;
            }

            function consolidateItemsForDisplay(itemsList) {
                if (!Array.isArray(itemsList) || !itemsList.length) return [];
                const map = new Map();
                const out = [];

                itemsList.forEach(rawItem => {
                    const it = Object.assign({}, rawItem);

                    if (it.is_box || (it.pack_items && it.pack_items.length > 0)) {
                        if (it.pack_items && it.pack_items.length > 0) {
                            it.pack_items = consolidateItemsForDisplay(it.pack_items);
                        }
                        out.push(it);
                        return;
                    }

                    const metaKey = Array.isArray(it.meta) ? it.meta.join('|') : (it.meta || '');
                    const nameKey = (it.name || '').toLowerCase().trim();
                    const groupKey = (it.id ? it.id : nameKey) + '___' + nameKey + '___' + metaKey;

                    const qty = parseInt(it.quantity, 10) || 1;
                    const priceNum = parseMoneyVal(it.total, it.total_num);

                    if (map.has(groupKey)) {
                        const existing = map.get(groupKey);
                        existing.quantity = (parseInt(existing.quantity, 10) || 1) + qty;
                        const existingPriceNum = parseMoneyVal(existing.total, existing.total_num);
                        const newTotalNum = existingPriceNum + priceNum;
                        existing.total_num = newTotalNum;
                        existing.total = formatMoneyAr(newTotalNum);

                        if ((!existing.image || existing.image.includes('placeholder')) && it.image && !it.image.includes('placeholder')) {
                            existing.image = it.image;
                        }
                    } else {
                        it.quantity = qty;
                        it.total_num = priceNum;
                        map.set(groupKey, it);
                        out.push(it);
                    }
                });

                return out;
            }

            function groupLooseAlfajoresIntoBoxes(itemsList, order) {
                if (!Array.isArray(itemsList) || !itemsList.length) return [];
                const boxes = [];
                const looseAlfajores = [];
                const otherItems = [];

                itemsList.forEach(rawItem => {
                    if (rawItem.is_box || (rawItem.pack_items && rawItem.pack_items.length > 0)) {
                        boxes.push(rawItem);
                    } else {
                        const name = (rawItem.name || '').toLowerCase();
                        const isAlf = name.includes('alfajor') || name.includes('batllie');
                        if (isAlf) {
                            looseAlfajores.push(rawItem);
                        } else {
                            otherItems.push(rawItem);
                        }
                    }
                });

                // Si el pedido tiene caja mixta pero ninguna de las cajas actuales es una caja mixta,
                // desarmar las cajas automáticas recibidas para permitir re-empaquetar con el producto mixto
                if (order && order.has_mixed_box && !boxes.some(b => b.is_mixed_box)) {
                    for (let i = boxes.length - 1; i >= 0; i--) {
                        if (boxes[i].is_auto_box && boxes[i].pack_items && boxes[i].pack_items.length) {
                            looseAlfajores.push(...boxes[i].pack_items);
                            boxes.splice(i, 1);
                        }
                    }
                }

                if (!looseAlfajores.length) {
                    return boxes.concat(otherItems);
                }

                // Consolidar ítems sueltos duplicados
                const consolidatedLoose = consolidateItemsForDisplay(looseAlfajores);
                let totalLooseUnits = 0;
                consolidatedLoose.forEach(it => {
                    totalLooseUnits += parseInt(it.quantity, 10) || 1;
                });

                const hasCourtesy = Boolean(order && order.has_courtesy_box);
                const hasDecision = Boolean(order && order.decision_correcta);

                // Armar cajas automáticas de alfajores sueltos
                const autoBoxes = [];
                let pool = consolidatedLoose.map(it => Object.assign({}, it));
                let poolIdx = 0;

                const defaultImg = (boxes.length && boxes[0].image) ? boxes[0].image : '';

                // Si el pedido tiene caja mixta y aún no fue empaquetada
                let mixedProductItem = null;
                const remainingOther = [];
                if (order && order.has_mixed_box && otherItems.length) {
                    const mInfo = order.mixed_box_info || {};
                    const mTargetName = (mInfo.product_name || '').toLowerCase();
                    otherItems.forEach(it => {
                        const itName = (it.name || '').toLowerCase();
                        if (!mixedProductItem && (
                            (mTargetName && (itName.includes(mTargetName) || mTargetName.includes(itName))) ||
                            otherItems.length === 1
                        )) {
                            mixedProductItem = it;
                        } else {
                            remainingOther.push(it);
                        }
                    });
                } else {
                    remainingOther.push(...otherItems);
                }

                if (mixedProductItem && totalLooseUnits > 0) {
                    const mInfo = order.mixed_box_info || {};
                    const mCap = parseInt(mInfo.box_capacity, 10) || 12;
                    const maxAlf = parseInt(mInfo.max_alfajores, 10) || 6;
                    const takeAlf = Math.min(totalLooseUnits, maxAlf);
                    let needed = takeAlf;
                    const mBoxItems = [mixedProductItem];
                    let mBoxTotalNum = parseMoneyVal(mixedProductItem.total, mixedProductItem.total_num);

                    while (needed > 0 && poolIdx < pool.length) {
                        const cur = pool[poolIdx];
                        const curQty = parseInt(cur.quantity, 10) || 1;
                        const curPrice = parseMoneyVal(cur.total, cur.total_num);
                        const unitPrice = curQty > 0 ? (curPrice / curQty) : 0;

                        if (curQty <= needed) {
                            mBoxItems.push(cur);
                            mBoxTotalNum += curPrice;
                            needed -= curQty;
                            poolIdx++;
                        } else {
                            const takeQty = needed;
                            const takePrice = takeQty * unitPrice;
                            const sub = Object.assign({}, cur, {
                                quantity: takeQty,
                                total_num: takePrice,
                                total: formatMoneyAr(takePrice)
                            });
                            mBoxItems.push(sub);
                            mBoxTotalNum += takePrice;
                            pool[poolIdx].quantity = curQty - takeQty;
                            pool[poolIdx].total_num = curPrice - takePrice;
                            pool[poolIdx].total = formatMoneyAr(curPrice - takePrice);
                            needed = 0;
                        }
                    }

                    autoBoxes.push({
                        item_id: 'client_box_mixed_1',
                        id: 0,
                        name: 'Caja ' + mCap + ' unidades (Mixta)',
                        image: defaultImg,
                        quantity: 1,
                        is_box: true,
                        is_auto_box: true,
                        is_mixed_box: true,
                        box_badge: '📦 PACK / CAJA',
                        box_units: mCap,
                        box_total: formatMoneyAr(mBoxTotalNum),
                        box_total_num: mBoxTotalNum,
                        has_decision_correcta: hasDecision,
                        has_courtesy: false,
                        pack_items: mBoxItems
                    });

                    totalLooseUnits -= takeAlf;
                }

                // Determinamos capacidades a llenar para los alfajores restantes (Prioridad 12 unidades)
                const caps = [];
                let uLeft = 0;
                for (let i = poolIdx; i < pool.length; i++) {
                    uLeft += parseInt(pool[i].quantity, 10) || 0;
                }
                while (uLeft >= 12) {
                    caps.push({ cap: 12, courtesy: false });
                    uLeft -= 12;
                }
                if (uLeft >= 6) {
                    caps.push({ cap: 6, courtesy: false });
                    uLeft -= 6;
                }
                if (uLeft > 0 && hasCourtesy && !autoBoxes.length) {
                    caps.push({ cap: uLeft, courtesy: true });
                    uLeft = 0;
                }

                caps.forEach((spec, idx) => {
                    let needed = spec.cap;
                    const boxItems = [];
                    let boxTotalNum = 0;

                    while (needed > 0 && poolIdx < pool.length) {
                        const cur = pool[poolIdx];
                        const curQty = parseInt(cur.quantity, 10) || 1;
                        const curPrice = parseMoneyVal(cur.total, cur.total_num);
                        const unitPrice = curQty > 0 ? (curPrice / curQty) : 0;

                        if (curQty <= needed) {
                            boxItems.push(cur);
                            boxTotalNum += curPrice;
                            needed -= curQty;
                            poolIdx++;
                        } else {
                            const takeQty = needed;
                            const takePrice = takeQty * unitPrice;
                            const sub = Object.assign({}, cur, {
                                quantity: takeQty,
                                total_num: takePrice,
                                total: formatMoneyAr(takePrice)
                            });
                            boxItems.push(sub);
                            boxTotalNum += takePrice;

                            pool[poolIdx].quantity = curQty - takeQty;
                            pool[poolIdx].total_num = curPrice - takePrice;
                            pool[poolIdx].total = formatMoneyAr(curPrice - takePrice);

                            needed = 0;
                        }
                    }

                    const isTargetFelicitacion = (hasDecision && idx === (caps.length - 1));
                    const boxName = spec.courtesy ? 'Caja de Cortesía Batllié' : ('Caja ' + spec.cap + ' unidades');
                    const boxBadge = spec.courtesy ? '🎁 CAJA DE CORTESÍA' : '📦 PACK / CAJA';

                    autoBoxes.push({
                        item_id: 'client_box_' + (idx + 1),
                        id: 0,
                        name: boxName,
                        image: defaultImg,
                        quantity: 1,
                        is_box: true,
                        is_auto_box: true,
                        box_badge: boxBadge,
                        box_units: spec.cap,
                        box_total: formatMoneyAr(boxTotalNum),
                        box_total_num: boxTotalNum,
                        has_decision_correcta: isTargetFelicitacion,
                        has_courtesy: spec.courtesy,
                        pack_items: boxItems
                    });
                });

                const remainingLoose = [];
                while (poolIdx < pool.length) {
                    if (pool[poolIdx].quantity > 0) {
                        remainingLoose.push(pool[poolIdx]);
                    }
                    poolIdx++;
                }

                return boxes.concat(autoBoxes, remainingLoose, remainingOther);
            }

            orders.forEach(order => {
                const hasShippedOrFinished = (
                    order.shipping_status === 'enviando' || 
                    order.shipping_status === 'demorado' || 
                    order.shipping_status === 'en_puerta' || 
                    order.shipping_status === 'entregado' || 
                    order.shipping_status === 'entregado_problemas' || 
                    order.status === 'completed' || 
                    order.status === 'recibido-problema' || 
                    order.status === 'cancelled' || 
                    order.status === 'refunded'
                );

                let itemsHtml = '';
                let renderedBoxFelicitacion = false;
                if (order.items && order.items.length) {
                    const displayItems = groupLooseAlfajoresIntoBoxes(order.items, order);
                    displayItems.forEach(item => {
                        if (item.is_box || (item.pack_items && item.pack_items.length > 0)) {
                            // Renderizar tarjeta de Pack / Caja agrupada con sus productos anidados
                            let subItemsHtml = '';
                            if (item.pack_items && item.pack_items.length) {
                                item.pack_items.forEach(sub => {
                                    let subImg = sub.image ? `<img src="${sub.image}" class="caja-subitem-thumb" alt="${sub.name}" />` : '';
                                    let subMeta = '';
                                    if (sub.meta && sub.meta.length) {
                                        subMeta = `<span class="caja-subitem-meta">(${sub.meta.join(', ')})</span>`;
                                    }
                                    subItemsHtml += `
                                        <div class="caja-pack-subitem">
                                            <span class="caja-subitem-tree">↳</span>
                                            ${subImg}
                                            <span class="caja-subitem-qty">${sub.quantity}x</span>
                                            <span class="caja-subitem-name">${sub.name} ${subMeta}</span>
                                            <span class="caja-subitem-price">${sub.total}</span>
                                        </div>
                                    `;
                                });
                            }

                            let isAumento = Boolean(item.has_aumento_pedido || item.has_decision_correcta);
                            let boxFelicitacionHtml = '';
                            if (isAumento && !hasShippedOrFinished) {
                                renderedBoxFelicitacion = true;
                                boxFelicitacionHtml = `
                                    <div class="caja-box-felicitacion-card caja-box-aumento-card ${order.tarjeta_incluida ? 'is-included' : ''}">
                                        <div class="caja-box-felicitacion-header">
                                            <span class="caja-felicitacion-badge" style="background:#059669; color:#fff; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:5px; display:inline-flex; align-items:center; gap:4px;">
                                                🚀 ¡AUMENTÓ SU PEDIDO!
                                            </span>
                                            <span class="caja-box-felicitacion-desc" style="color:var(--caja-text, #e2e8f0); font-size:0.83rem; margin-top:3px; display:block;">
                                                El cliente iba a pedir menos unidades, pero agregó alfajores extra por recomendación de la web para completar esta caja.
                                            </span>
                                        </div>
                                        <label class="caja-felicitacion-toggle-label" style="margin-top:6px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                            <input type="checkbox" class="caja-felicitacion-checkbox" data-order-id="${order.id}" ${order.tarjeta_incluida ? 'checked' : ''} />
                                            <span style="font-size:0.82rem; font-weight:600;">Incluir tarjeta de felicitación en esta caja</span>
                                        </label>
                                    </div>
                                `;
                            }

                            let badgeText = item.box_badge || (item.has_courtesy ? '🎁 CAJA DE CORTESÍA' : '📦 PACK / CAJA');
                            let imgHtml = item.image ? `<img src="${item.image}" class="caja-pack-box-thumb" alt="${item.name}" />` : '';
                            let unitsBadge = item.box_units ? `<span class="caja-pack-units-badge">${item.box_units} u.</span>` : '';
                            let boxPrice = item.box_total || item.total;

                            itemsHtml += `
                                <div class="caja-pack-box-card">
                                    <div class="caja-pack-box-header">
                                        ${imgHtml}
                                        <div class="caja-pack-box-title-wrap">
                                            <div class="caja-pack-box-top-line">
                                                <span class="caja-pack-box-badge">${badgeText}</span>
                                                ${unitsBadge}
                                            </div>
                                            <span class="caja-pack-box-name">${item.name}</span>
                                        </div>
                                        <span class="caja-pack-box-total">${boxPrice}</span>
                                    </div>
                                    <div class="caja-pack-box-items">
                                        ${subItemsHtml}
                                    </div>
                                    ${boxFelicitacionHtml}
                                </div>
                            `;
                        } else {
                            // Ítem estándar individual fuera de pack
                            let metaHtml = '';
                            if (item.meta && item.meta.length) {
                                metaHtml = `<div class="caja-item-meta">${item.meta.join(', ')}</div>`;
                            }
                            let imgHtml = item.image ? `<img src="${item.image}" class="caja-item-thumb" alt="${item.name}" />` : '';
                            itemsHtml += `
                                <div class="caja-order-item">
                                    ${imgHtml}
                                    <span class="caja-item-qty">${item.quantity}x</span>
                                    <div class="caja-item-info">
                                        <span class="caja-item-name">${item.name}</span>
                                        ${metaHtml}
                                    </div>
                                    <span class="caja-item-price">${item.total}</span>
                                </div>
                            `;
                        }
                    });
                }

                let phoneHtml = '';
                if (order.phone) {
                    let phoneWa = order.phone_wa || order.phone_clean || '';
                    if (phoneWa && !phoneWa.startsWith('549') && !phoneWa.startsWith('54')) {
                        phoneWa = '549' + phoneWa.replace(/^0+/, '').replace(/^15/, '');
                    }
                    phoneHtml = `
                        <div class="caja-phone-row">
                            <a href="https://wa.me/${phoneWa}" target="_blank" class="caja-customer-phone" title="Contactar por WhatsApp">
                                <span class="caja-phone-icon">📱</span>
                                <span class="caja-phone-text">${order.phone_formatted || order.phone}</span>
                                <span class="caja-phone-whatsapp-tag">WhatsApp</span>
                            </a>
                        </div>
                    `;
                }

                let noteHtml = '';
                if (order.customer_note) {
                    noteHtml = `
                        <div class="caja-order-note">
                            <strong>Nota:</strong> ${order.customer_note}
                        </div>
                    `;
                }

                // Formatear fecha del pedido para el encabezado del historial
                let orderDateDisplay = order.order_date_formatted || '';
                let orderDateKey = order.order_date_key || '';

                const formatArDate = (ts) => {
                    if (!ts) return null;
                    try {
                        const d = new Date(ts * 1000);
                        const formatter = new Intl.DateTimeFormat('es-AR', {
                            timeZone: 'America/Argentina/Buenos_Aires',
                            weekday: 'long',
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric'
                        });
                        const parts = formatter.formatToParts(d);
                        let weekday = '', day = '', month = '', year = '';
                        parts.forEach(p => {
                            if (p.type === 'weekday') weekday = p.value.charAt(0).toUpperCase() + p.value.slice(1);
                            if (p.type === 'day') day = p.value;
                            if (p.type === 'month') month = p.value;
                            if (p.type === 'year') year = p.value;
                        });
                        return {
                            display: `${weekday} ${day}/${month}/${year}`,
                            key: `${year}-${month}-${day}`
                        };
                    } catch(e) {
                        return null;
                    }
                };

                if (order.timestamp) {
                    const ar = formatArDate(order.timestamp);
                    if (!orderDateDisplay && ar) {
                        orderDateDisplay = ar.display;
                    }
                    if (!orderDateKey && ar) {
                        orderDateKey = ar.key;
                    }
                }
                if (!orderDateDisplay && order.time_formatted) {
                    orderDateDisplay = order.time_formatted.split(' ')[0];
                }

                // Funciones auxiliares para fechas de eventos individuales
                const getEventDateFormatted = (ev) => {
                    if (ev.date_formatted) return ev.date_formatted;
                    if (ev.timestamp) {
                        const ar = formatArDate(ev.timestamp);
                        if (ar) return ar.display;
                    }
                    return ev.date || '';
                };

                const getEventDateKey = (ev) => {
                    if (ev.date_key) return ev.date_key;
                    if (ev.timestamp) {
                        const ar = formatArDate(ev.timestamp);
                        if (ar) return ar.key;
                    }
                    return '';
                };

                // Generar historial cronológico para la sección de información detallada
                let timelineHtml = '';
                const validEvents = (order.timeline && Array.isArray(order.timeline))
                    ? order.timeline.filter(e => e && typeof e === 'object' && e.text && e.text !== 'undefined' && e.time && e.time !== 'undefined')
                    : [];

                if (validEvents.length) {
                    timelineHtml = '<div class="caja-timeline-list">';
                    let lastDateKey = orderDateKey;

                    validEvents.forEach((event, idx) => {
                        const isLast = (idx === validEvents.length - 1);
                        const eventDateKey = getEventDateKey(event);
                        const eventDateDisplay = getEventDateFormatted(event);

                        // Si este evento ocurrió en un día distinto al anterior, insertar separador con la fecha del nuevo día
                        if (eventDateKey && lastDateKey && eventDateKey !== lastDateKey) {
                            timelineHtml += `
                                <div class="caja-timeline-day-divider">
                                    <div class="caja-timeline-order-date caja-timeline-date-subsequent">
                                        <span class="caja-timeline-date-icon">📅</span>
                                        <span class="caja-timeline-date-val">${eventDateDisplay}</span>
                                    </div>
                                </div>
                            `;
                            lastDateKey = eventDateKey;
                        } else if (!lastDateKey && eventDateKey) {
                            lastDateKey = eventDateKey;
                        }

                        timelineHtml += `
                            <div class="caja-timeline-item ${isLast ? 'timeline-latest' : ''}">
                                <div class="caja-timeline-time-col">
                                    <span class="caja-timeline-time">${event.time || ''}</span>
                                </div>
                                <div class="caja-timeline-axis-col">
                                    <span class="caja-timeline-dot"></span>
                                    ${!isLast ? '<span class="caja-timeline-bar"></span>' : ''}
                                </div>
                                <div class="caja-timeline-content-col">
                                    <span class="caja-timeline-icon">${event.icon || '•'}</span>
                                    <span class="caja-timeline-text">${event.text || ''}</span>
                                </div>
                            </div>
                        `;
                    });
                    timelineHtml += '</div>';
                } else {
                    timelineHtml = '<div class="caja-timeline-empty">Sin historial registrado</div>';
                }

                // Reglas de visibilidad condicional para el menú de pago, empaque y envío (ubicado ARRIBA del selector de estado):
                const isPayApproved = (order.payment_status === 'pagado' || order.payment_status === 'efectivo_entrega');
                const isVerified = (order.control_pedido_verified === true || order.control_pedido_verified === 'yes');
                let showPaymentBox = false;
                let showPackagingBox = false;
                let showShippingBox = false;

                if (order.status === 'pending') {
                    showPaymentBox = true;
                    showPackagingBox = false;
                    showShippingBox = false;
                } else if (order.status === 'processing') {
                    showPaymentBox = !isPayApproved;
                    showPackagingBox = !isVerified;
                    showShippingBox = false;
                } else if (order.status === 'enviando' || order.status === 'on-hold') {
                    showPaymentBox = false;
                    showPackagingBox = !isVerified;
                    showShippingBox = true;
                } else if (order.status === 'completed' || order.status === 'recibido-problema' || order.status === 'failed') {
                    showPaymentBox = false;
                    showPackagingBox = false;
                    showShippingBox = true;
                } else if (order.status === 'cancelled' || order.status === 'refunded') {
                    showPaymentBox = true;
                    showPackagingBox = false;
                    showShippingBox = false;
                } else {
                    showPaymentBox = true;
                    showPackagingBox = false;
                    showShippingBox = false;
                }

                let showMetaGrid = (showPaymentBox || showPackagingBox || showShippingBox);

                const showControlPedidoBtn = (order.status === 'enviando' || order.status === 'on-hold');

                let felicitacionHtml = '';
                if ((order.aumento_pedido || order.decision_correcta) && !hasShippedOrFinished) {
                    felicitacionHtml = `
                        <div class="caja-felicitacion-card caja-box-aumento-card ${order.tarjeta_incluida ? 'is-included' : ''}">
                            <div class="caja-felicitacion-header">
                                <span class="caja-felicitacion-badge" style="background:#059669; color:#fff; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:5px; display:inline-flex; align-items:center; gap:4px;">
                                    🚀 ¡AUMENTÓ SU PEDIDO!
                                </span>
                                <span class="caja-felicitacion-desc" style="color:var(--caja-text, #e2e8f0); font-size:0.83rem; margin-top:3px; display:block;">
                                    El cliente iba a pedir menos unidades, pero agregó alfajores extra por recomendación de la web.
                                </span>
                            </div>
                            <label class="caja-felicitacion-toggle-label" style="margin-top:6px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="checkbox" class="caja-felicitacion-checkbox" data-order-id="${order.id}" ${order.tarjeta_incluida ? 'checked' : ''} />
                                <span style="font-size:0.82rem; font-weight:600;">Incluir tarjeta de felicitación en el paquete</span>
                            </label>
                        </div>
                    `;
                }

                let courtesyHtml = '';
                if (order.has_courtesy_box) {
                    courtesyHtml = `
                        <div class="caja-courtesy-banner">
                            🎁 <strong>Incluye Caja de Cortesía Batllié</strong> (Agrupación concedida).
                        </div>
                    `;
                }

                let packingHtml = '';
                let pkg = order.packing_summary;

                // Fallback reactivo del lado cliente si no viene packing_summary desde el servidor
                if (!pkg || !pkg.has_boxes) {
                    let totalAlf = 0;
                    if (Array.isArray(order.items)) {
                        order.items.forEach(function (it) {
                            const name = (it.name || '').toLowerCase();
                            if (name.includes('alfajor') || name.includes('batllie') || name.includes('suizo') || name.includes('chocolate') || name.includes('negro') || name.includes('blanco')) {
                                totalAlf += parseInt(it.quantity, 10) || 0;
                            }
                        });
                    }

                    if (totalAlf > 0) {
                        let b12 = Math.floor(totalAlf / 12);
                        let rem = totalAlf % 12;
                        let b6 = 0;
                        if (rem >= 6) {
                            b6 = Math.floor(rem / 6);
                            rem = rem % 6;
                        }
                        let court = order.has_courtesy_box ? 1 : 0;
                        let parts = [];
                        if (b12 > 0) parts.push(b12 > 1 ? (b12 + ' Cajas x 12') : '1 Caja x 12');
                        if (b6 > 0) parts.push(b6 > 1 ? (b6 + ' Cajas x 6') : '1 Caja x 6');
                        if (court > 0) parts.push('🎁 Caja de Cortesía');
                        if (rem > 0 && court === 0) parts.push(rem + ' suelto' + (rem > 1 ? 's' : ''));

                        pkg = {
                            has_boxes: (parts.length > 0 || totalAlf > 0),
                            total_alfajores: totalAlf,
                            summary_text: parts.join(' + ')
                        };
                    }
                }

                if (pkg && pkg.has_boxes && pkg.summary_text) {
                    packingHtml = `
                        <div class="caja-order-packing-badge">
                            <span class="caja-packing-icon">📦</span>
                            <span class="caja-packing-label">Empaque asignado:</span>
                            <strong class="caja-packing-value">${pkg.summary_text}</strong>
                            ${pkg.total_alfajores ? `<span class="caja-packing-count">(${pkg.total_alfajores} alfajores)</span>` : ''}
                        </div>
                    `;
                }

                html += `
                    <div class="caja-order-card" id="caja-order-card-${order.id}">
                        <div class="caja-card-header">
                            <div class="caja-order-num-wrap">
                                <span class="caja-order-number">#${order.number}</span>
                                <span class="caja-order-time">${order.time_diff || order.time_formatted}</span>
                            </div>
                            <div class="caja-card-header-badges">
                                ${order.shipping_slot_badge ? `
                                    <span class="caja-dispatch-pill" title="Tanda de despacho: ${order.shipping_slot_label || order.shipping_slot_badge}">
                                        <span class="caja-dispatch-icon">🚚</span>
                                        <span class="caja-dispatch-text">${order.shipping_slot_badge}</span>
                                    </span>
                                ` : (order.shipping_method && order.shipping_method.toLowerCase().indexOf('local') !== -1 ? `
                                    <span class="caja-dispatch-pill caja-dispatch-pickup" title="Retiro en local">
                                        <span class="caja-dispatch-icon">🏬</span>
                                        <span class="caja-dispatch-text">Retiro local</span>
                                    </span>
                                ` : '')}
                                <span class="caja-status-pill ${order.status_badge}">${order.status_name}</span>
                            </div>
                        </div>

                        <div class="caja-customer-block">
                            <div class="caja-customer-name">👤 ${order.customer_name}</div>
                            ${phoneHtml}
                            ${order.address ? `<div class="caja-customer-address">📍 ${order.address}</div>` : ''}
                            ${order.customer_note ? `
                                <div class="caja-customer-note-row">
                                    <span class="caja-customer-note-icon">📝</span>
                                    <div class="caja-customer-note-body">
                                        <strong class="caja-customer-note-label">Nota del cliente:</strong>
                                        <span class="caja-customer-note-val">${order.customer_note}</span>
                                    </div>
                                </div>
                            ` : ''}
                        </div>

                        <div class="caja-order-items-list">
                            ${(!renderedBoxFelicitacion && felicitacionHtml) ? felicitacionHtml : ''}
                            ${itemsHtml}
                        </div>

                        <div class="caja-card-footer">
                            <div class="caja-order-total-block">
                                <span class="caja-total-label">Total a cobrar:</span>
                                <span class="caja-total-val">${order.total}</span>
                            </div>

                            <!-- Menú de Pago, Control de Empaque y Envío (ubicado ARRIBA del selector de estado) -->
                            <div class="caja-order-meta-grid ${showMetaGrid ? '' : 'caja-meta-hidden'}" id="caja-meta-grid-${order.id}" style="${showMetaGrid ? '' : 'display:none;'}">
                                
                                <!-- Recuadro 1: Medio y Estado del Pago -->
                                <div class="caja-meta-box caja-meta-payment-box ${showPaymentBox ? '' : 'caja-meta-hidden'}" id="caja-meta-payment-${order.id}" style="${showPaymentBox ? '' : 'display:none;'}">
                                    <div class="caja-meta-header">
                                        <span class="caja-meta-icon">💳</span>
                                        <span class="caja-meta-title">Pago:</span>
                                        <strong class="caja-meta-val">${order.payment_method}</strong>
                                    </div>
                                    <div class="caja-meta-select-wrap">
                                        <select class="caja-status-select caja-payment-select status-pay-${order.payment_status}" data-order-id="${order.id}">
                                            <option value="pagado" ${order.payment_status === 'pagado' ? 'selected' : ''}>✅ Pagado</option>
                                            <option value="pendiente" ${order.payment_status === 'pendiente' ? 'selected' : ''}>⏳ Pendiente de pago</option>
                                            <option value="efectivo_entrega" ${order.payment_status === 'efectivo_entrega' ? 'selected' : ''}>💵 Efectivo en entrega</option>
                                            <option value="pendiente_devolucion" ${order.payment_status === 'pendiente_devolucion' ? 'selected' : ''}>🔄 Pendiente devolución</option>
                                            <option value="devolucion" ${order.payment_status === 'devolucion' ? 'selected' : ''}>↩️ Devolución</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Recuadro 2: Control de Empaque (Cajas oficiales 6/12 y Bolsas de envío grandes/chicas) -->
                                <div class="caja-meta-box caja-meta-packaging-box ${showPackagingBox ? '' : 'caja-meta-hidden'}" id="caja-meta-packaging-${order.id}" style="${showPackagingBox ? '' : 'display:none;'}">
                                    <div class="caja-meta-header" style="display:flex; justify-content:space-between; align-items:center;">
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <span class="caja-meta-icon">📦</span>
                                            <span class="caja-meta-title">Control de empaque:</span>
                                        </div>
                                        ${!isVerified ? `<button type="button" class="caja-btn-modify-pkg" data-order-id="${order.id}">✏️ Modificar</button>` : ''}
                                    </div>

                                    <!-- Vista resumen de cajas oficiales (solo lectura por defecto) -->
                                    <div class="caja-pkg-summary-view" id="caja-pkg-summary-${order.id}">
                                        <div class="caja-pkg-item-row" style="display:flex; justify-content:space-between; align-items:center; margin-top:4px;">
                                            <span class="caja-pkg-label" style="font-size:0.83rem; color:#94a3b8;">Cajas oficiales:</span>
                                            <span class="caja-pkg-val" id="caja-pkg-boxes-val-${order.id}" style="font-size:0.85rem; color:#f1f5f9;">
                                                ${(order.boxes_12_qty > 0 || order.boxes_6_qty > 0)
                                                    ? `x12: <strong>${order.boxes_12_qty || 0}</strong> &nbsp;|&nbsp; x6: <strong>${order.boxes_6_qty || 0}</strong>`
                                                    : '<em style="color:#64748b;">0 cajas</em>'
                                                }
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Vista edición de cajas oficiales (habilitada SOLO si se toca 'Modificar') -->
                                    <div class="caja-pkg-edit-view" id="caja-pkg-edit-${order.id}" style="display:none; margin-top:8px; padding-top:8px; border-top:1px dashed rgba(255,255,255,0.1);">
                                        <div class="caja-pkg-stepper-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                            <span class="caja-pkg-label" style="font-size:0.83rem; color:#cbd5e1;">Cajas de 12:</span>
                                            <div class="caja-stepper">
                                                <button type="button" class="caja-step-btn btn-step-box" data-action="minus" data-type="12" data-order-id="${order.id}">−</button>
                                                <input type="number" min="0" step="1" id="pkg-input-box-12-${order.id}" value="${order.boxes_12_qty || 0}" class="caja-pkg-input" readonly />
                                                <button type="button" class="caja-step-btn btn-step-box" data-action="plus" data-type="12" data-order-id="${order.id}">+</button>
                                            </div>
                                        </div>
                                        <div class="caja-pkg-stepper-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                            <span class="caja-pkg-label" style="font-size:0.83rem; color:#cbd5e1;">Cajas de 6:</span>
                                            <div class="caja-stepper">
                                                <button type="button" class="caja-step-btn btn-step-box" data-action="minus" data-type="6" data-order-id="${order.id}">−</button>
                                                <input type="number" min="0" step="1" id="pkg-input-box-6-${order.id}" value="${order.boxes_6_qty || 0}" class="caja-pkg-input" readonly />
                                                <button type="button" class="caja-step-btn btn-step-box" data-action="plus" data-type="6" data-order-id="${order.id}">+</button>
                                            </div>
                                        </div>
                                        <div class="caja-pkg-edit-actions" style="display:flex; gap:6px; justify-content:flex-end;">
                                            <button type="button" class="caja-btn caja-btn-xs caja-btn-secondary btn-cancel-pkg-boxes" data-order-id="${order.id}">Cancelar</button>
                                            <button type="button" class="caja-btn caja-btn-xs caja-btn-primary btn-save-pkg-boxes" data-order-id="${order.id}">💾 Guardar cajas</button>
                                        </div>
                                    </div>

                                    <!-- Bolsas grandes y chicas de envío -->
                                    <div class="caja-pkg-bags-row" style="margin-top:8px; padding-top:6px; border-top:1px solid rgba(255,255,255,0.06);">
                                        <div class="caja-pkg-stepper-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                            <span class="caja-pkg-label" style="font-size:0.83rem; color:#94a3b8;" title="${self.escapeHtml(self.config.officialBagLargeName || 'Bolsa grande')}">🛍️ Bolsas grandes:</span>
                                            ${!isVerified ? `
                                                <div class="caja-stepper">
                                                    <button type="button" class="caja-step-btn btn-step-bag" data-action="minus" data-type="large" data-order-id="${order.id}">−</button>
                                                    <input type="number" min="0" step="1" id="pkg-input-bag-large-${order.id}" value="${order.bag_large !== undefined ? order.bag_large : 1}" class="caja-pkg-input" readonly />
                                                    <button type="button" class="caja-step-btn btn-step-bag" data-action="plus" data-type="large" data-order-id="${order.id}">+</button>
                                                </div>
                                            ` : `
                                                <span class="caja-pkg-val" style="font-size:0.85rem; font-weight:700; color:#f1f5f9;">${order.bag_large !== undefined ? order.bag_large : 1}</span>
                                            `}
                                        </div>
                                        <div class="caja-pkg-stepper-row" style="display:flex; justify-content:space-between; align-items:center;">
                                            <span class="caja-pkg-label" style="font-size:0.83rem; color:#94a3b8;" title="${self.escapeHtml(self.config.officialBagSmallName || 'Bolsa chica')}">🛍️ Bolsas chicas:</span>
                                            ${!isVerified ? `
                                                <div class="caja-stepper">
                                                    <button type="button" class="caja-step-btn btn-step-bag" data-action="minus" data-type="small" data-order-id="${order.id}">−</button>
                                                    <input type="number" min="0" step="1" id="pkg-input-bag-small-${order.id}" value="${order.bag_small || 0}" class="caja-pkg-input" readonly />
                                                    <button type="button" class="caja-step-btn btn-step-bag" data-action="plus" data-type="small" data-order-id="${order.id}">+</button>
                                                </div>
                                            ` : `
                                                <span class="caja-pkg-val" style="font-size:0.85rem; font-weight:700; color:#f1f5f9;">${order.bag_small || 0}</span>
                                            `}
                                        </div>
                                    </div>

                                    <!-- SECCIÓN PAQUETERÍA TEMÁTICA ESPECIAL (COLAPSABLE Y OPCIONAL) -->
                                    <div class="caja-pkg-thematic-wrap">
                                        <button type="button" class="caja-btn-thematic-toggle ${order.packing_theme ? 'has-theme' : ''}" data-order-id="${order.id}">
                                            <span class="caja-thematic-toggle-title">
                                                <span class="caja-thematic-icon">🎁</span>
                                                <span>Paquetería temática</span>
                                                ${order.packing_theme ? `
                                                    <span class="caja-thematic-active-tag" id="caja-thematic-tag-${order.id}">${self.escapeHtml(order.packing_theme)}</span>
                                                ` : `
                                                    <span class="caja-thematic-opt-tag" id="caja-thematic-tag-${order.id}">(Opcional)</span>
                                                `}
                                            </span>
                                            <span class="caja-thematic-toggle-arrow">▼</span>
                                        </button>

                                        <div class="caja-thematic-content" id="caja-thematic-content-${order.id}" style="display:none;">
                                            <!-- Opción extra: checkbox 'Es un paquete con temática especial' -->
                                            <label class="caja-thematic-checkbox-label">
                                                <input type="checkbox" class="caja-chk-is-thematic" data-order-id="${order.id}" ${order.packing_theme ? 'checked' : ''} ${isVerified ? 'disabled' : ''} />
                                                <span>Es un paquete con temática especial</span>
                                            </label>

                                            <!-- Zona de selección y creación de temáticas -->
                                            <div class="caja-thematic-fields" id="caja-thematic-fields-${order.id}" style="${order.packing_theme ? '' : 'display:none;'}">
                                                <div class="caja-thematic-select-row">
                                                    <label class="caja-thematic-sublabel" for="caja-select-theme-${order.id}">Seleccionar temática:</label>
                                                    <div style="display:flex; gap:6px; align-items:center;">
                                                        <select class="caja-select-packing-theme" id="caja-select-theme-${order.id}" data-order-id="${order.id}" ${isVerified ? 'disabled' : ''}>
                                                            <option value="">-- Sin temática especial (Estándar) --</option>
                                                            ${(self.cachedPackingThemes || []).map(t => `
                                                                <option value="${self.escapeHtml(t)}" ${order.packing_theme === t ? 'selected' : ''}>${self.escapeHtml(t)}</option>
                                                            `).join('')}
                                                        </select>
                                                        ${!isVerified ? `
                                                            <button type="button" class="caja-btn-del-theme" data-order-id="${order.id}" data-theme="${self.escapeHtml(order.packing_theme || '')}" title="Eliminar temática seleccionada de la lista" style="${order.packing_theme ? '' : 'display:none;'} background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#fca5a5; border-radius:5px; padding:5px 8px; font-size:0.75rem; cursor:pointer;">🗑️</button>
                                                        ` : ''}
                                                    </div>
                                                </div>

                                                ${!isVerified ? `
                                                    <!-- Agregar temáticas directamente desde ahí -->
                                                    <div class="caja-thematic-add-row" style="margin-top:6px;">
                                                        <label class="caja-thematic-sublabel">+ Crear nueva temática:</label>
                                                        <div class="caja-thematic-add-inline">
                                                            <input type="text" class="caja-input-new-theme" id="caja-input-new-theme-${order.id}" placeholder="Ej: Cumpleaños, San Valentín..." maxlength="40" />
                                                            <button type="button" class="caja-btn-add-theme" data-order-id="${order.id}">+ Agregar</button>
                                                        </div>
                                                    </div>
                                                ` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Recuadro 3: Estado del Envío -->
                                <div class="caja-meta-box caja-meta-shipping-box ${showShippingBox ? '' : 'caja-meta-hidden'}" id="caja-meta-shipping-${order.id}" style="${showShippingBox ? '' : 'display:none;'}">
                                    <div class="caja-meta-header">
                                        <span class="caja-meta-icon">🛵</span>
                                        <span class="caja-meta-title">Envío:</span>
                                    </div>
                                    <div class="caja-meta-select-wrap">
                                        <select class="caja-status-select caja-shipping-select status-ship-${order.shipping_status}" data-order-id="${order.id}">
                                            <option value="no_gestionado" ${order.shipping_status === 'no_gestionado' ? 'selected' : ''}>📦 No gestionado</option>
                                            <option value="esperando_repartidor" ${order.shipping_status === 'esperando_repartidor' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>⏳ Esperando al repartidor${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="enviando" ${order.shipping_status === 'enviando' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>🚀 Repartidor enviando${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="demorado" ${order.shipping_status === 'demorado' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>⚠️ Repartidor con demora${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="en_puerta" ${order.shipping_status === 'en_puerta' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>🚪 El repartidor está en la puerta${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="entregado" ${order.shipping_status === 'entregado' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>🏁 Recibido sin problemas${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="entregado_problemas" ${order.shipping_status === 'entregado_problemas' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>🛑 Recibido con problemas${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Botón previo a darle el paquete al repartidor: Control de pedido (solo se muestra cuando el estado es enviando) -->
                            <div class="caja-control-pedido-btn-wrap" style="display:${showControlPedidoBtn ? 'block' : 'none'};">
                                <button type="button" class="caja-btn-control-pedido ${isVerified ? 'is-verified' : 'status-bg-enviando'}" data-order-id="${order.id}">
                                    📋 Control de pedido${isVerified ? ' (✓ Verificado)' : ''}
                                </button>
                            </div>

                            <!-- Selector de Pasos Principal (ubicado ABAJO) -->
                            <div class="caja-order-status-select-wrap">
                                <div class="caja-dropdown-relative">
                                    <select class="caja-main-status-dropdown status-bg-${order.status} ${isVerified && (order.status === 'enviando' || order.status === 'on-hold') ? 'is-verified-primary' : ''}" data-order-id="${order.id}">
                                        <option value="pending" ${order.status === 'pending' ? 'selected' : ''}>Pendiente</option>
                                        <option value="processing" ${order.status === 'processing' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>En preparación${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                        <option value="enviando" ${order.status === 'enviando' || order.status === 'on-hold' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>Enviando${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                        <option value="completed" ${order.status === 'completed' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>Completado${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                        <option value="recibido-problema" ${order.status === 'recibido-problema' || order.status === 'failed' ? 'selected' : ''}>Recibido (con inconvenientes)</option>
                                        <option value="cancelled" ${order.status === 'cancelled' ? 'selected' : ''}>Cancelado</option>
                                        <option value="refunded" ${order.status === 'refunded' ? 'selected' : ''}>Reembolzado</option>
                                    </select>
                                    <span class="caja-dropdown-arrow">▼</span>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones y detalles colapsables directamente debajo del botón grande de estado -->
                        <div class="caja-card-bottom-actions">
                            <!-- Botón 1: Ver información detallada -->
                            <div class="caja-details-accordion">
                                <button type="button" class="caja-btn-details-toggle" data-order-id="${order.id}">
                                    <span class="caja-toggle-left">
                                        <span class="caja-toggle-icon">ℹ️</span>
                                        <span class="caja-toggle-text">Ver información detallada</span>
                                    </span>
                                    <span class="caja-toggle-arrow">▼</span>
                                </button>
                                <div class="caja-details-collapse" id="caja-details-${order.id}" style="display:none;">
                                    <div class="caja-timeline-wrap">
                                        <div class="caja-timeline-header-title">📋 Historial de eventos:</div>
                                        <div class="caja-timeline-order-date">
                                            <span class="caja-timeline-date-icon">📅</span>
                                            <span class="caja-timeline-date-label">Fecha del pedido:</span>
                                            <span class="caja-timeline-date-val">${orderDateDisplay}</span>
                                        </div>
                                        ${timelineHtml}
                                    </div>
                                    ${order.billing_email ? `<div class="caja-details-subinfo"><span>✉️ Email:</span> <strong>${order.billing_email}</strong></div>` : ''}
                                    ${order.shipping_total ? `<div class="caja-details-subinfo"><span>🛵 Costo de envío:</span> <strong>${order.shipping_total}</strong></div>` : ''}
                                    ${order.packing_theme ? `<div class="caja-details-subinfo" style="color:#fef08a;"><span>🎁 Paquetería temática:</span> <strong>${self.escapeHtml(order.packing_theme)}</strong></div>` : ''}
                                </div>
                            </div>

                            <!-- Botón 2: Notas adicionales colapsable con el mismo estilo (quien gestiona la caja) -->
                            <div class="caja-notes-accordion">
                                <button type="button" class="caja-btn-details-toggle caja-btn-notes-toggle" data-order-id="${order.id}">
                                    <span class="caja-toggle-left">
                                        <span class="caja-toggle-icon">📝</span>
                                        <span class="caja-toggle-text">Notas adicionales</span>
                                    </span>
                                    <span class="caja-toggle-arrow">▼</span>
                                </button>
                                <div class="caja-details-collapse caja-notes-collapse" id="caja-notes-${order.id}" style="display:none;">
                                    <div class="caja-order-note-block-inner">
                                        <div class="caja-note-field-wrap">
                                            <textarea class="caja-order-note-textarea" data-order-id="${order.id}" placeholder="Escribir una nota o aclaración interna..." rows="2">${order.caja_note || ''}</textarea>
                                            <div class="caja-note-actions-row">
                                                <span class="caja-note-saved-msg" id="caja-note-saved-${order.id}" style="display:none;"></span>
                                                <button type="button" class="caja-btn-save-note" data-order-id="${order.id}">
                                                    <span class="caja-note-btn-text">Guardar nota</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            $grid.html(html);

            // Restaurar paneles de detalles abiertos
            openDetailIds.forEach(id => {
                const $panel = $(`#caja-details-${id}`);
                if ($panel.length) {
                    $panel.show();
                    const $btn = $(`.caja-btn-details-toggle[data-order-id="${id}"]`).not('.caja-btn-notes-toggle');
                    $btn.addClass('active');
                    $btn.find('.caja-toggle-arrow').text('▲');
                    $btn.find('.caja-toggle-text').text('Ocultar información detallada');
                }
            });

            // Restaurar paneles de notas adicionales abiertas
            openNotesIds.forEach(id => {
                const $panel = $(`#caja-notes-${id}`);
                if ($panel.length) {
                    $panel.show();
                    const $btn = $(`.caja-btn-notes-toggle[data-order-id="${id}"]`);
                    $btn.addClass('active');
                    $btn.find('.caja-toggle-arrow').text('▲');
                    $btn.find('.caja-toggle-text').text('Ocultar notas adicionales');
                }
            });

            // Restaurar borradores de notas no guardadas
            Object.keys(draftNotes).forEach(id => {
                const $ta = $(`.caja-order-note-textarea[data-order-id="${id}"]`);
                if ($ta.length && draftNotes[id] !== undefined) {
                    $ta.val(draftNotes[id]);
                }
            });

            // Restaurar paneles de paquetería temática abiertos
            openThematicIds.forEach(id => {
                const $panel = $(`#caja-thematic-content-${id}`);
                if ($panel.length) {
                    $panel.show();
                    const $btn = $(`.caja-btn-thematic-toggle[data-order-id="${id}"]`);
                    $btn.addClass('active');
                    $btn.find('.caja-thematic-toggle-arrow').addClass('open');
                }
            });
        },

        applyFilters: function() {
            const self = this;
            const nowSec = Math.floor(Date.now() / 1000);

            // Fechas clave de Argentina para los filtros específicos de 1 día (ayer) y 2 días (anteayer)
            const targetDay1Key = self.getArgDateKey(new Date(Date.now() - 86400000));
            const targetDay2Key = self.getArgDateKey(new Date(Date.now() - (2 * 86400000)));

            const filtered = self.cachedOrders.filter(ord => {
                // 1. Filtro de Estado
                if (self.currentStatusFilter && self.currentStatusFilter !== 'all') {
                    const s = ord.status;
                    if (self.currentStatusFilter === 'enviando') {
                        if (s !== 'enviando' && s !== 'on-hold') return false;
                    } else if (self.currentStatusFilter === 'recibido-problema') {
                        if (s !== 'recibido-problema' && s !== 'failed') return false;
                    } else if (s !== self.currentStatusFilter) {
                        return false;
                    }
                }

                // 2. Filtro de Tiempo
                const ageSec = Math.max(0, nowSec - ord.timestamp);
                const ageMin = ageSec / 60;
                const ageHours = ageSec / 3600;

                switch (self.currentTimeFilter) {
                    case 'nuevos':
                        // Desde los nuevos para adelante (< 30 min)
                        if (ageMin >= 30) return false;
                        break;
                    case '30_min':
                        // 30 minutos y más antiguos
                        if (ageMin < 30) return false;
                        break;
                    case '1_hour':
                        // 1 hora y más antiguos
                        if (ageHours < 1) return false;
                        break;
                    case '2_hours':
                        // 2 horas y más antiguos
                        if (ageHours < 2) return false;
                        break;
                    case '1_day':
                        // Muestra exactamente los pedidos del día anterior (ej: Jueves 24/09)
                        if (!ord.timestamp) return false;
                        if (self.getArgDateKey(ord.timestamp) !== targetDay1Key) return false;
                        break;
                    case '2_days':
                        // Muestra exactamente los pedidos de hace 2 días (ej: Miércoles 23/09)
                        if (!ord.timestamp) return false;
                        if (self.getArgDateKey(ord.timestamp) !== targetDay2Key) return false;
                        break;
                    case 'all':
                    default:
                        break;
                }

                // 3. Filtro de Búsqueda
                if (self.searchKeyword) {
                    const kw = self.searchKeyword;
                    const matchNum = ord.number && ord.number.toString().includes(kw);
                    const matchName = ord.customer_name && ord.customer_name.toLowerCase().includes(kw);
                    const matchPhone = ord.phone && ord.phone.includes(kw);
                    const matchNote = ord.customer_note && ord.customer_note.toLowerCase().includes(kw);
                    if (!matchNum && !matchName && !matchPhone && !matchNote) {
                        return false;
                    }
                }

                // 4. Filtro de Horarios de Envío (Tandas)
                if (self.currentSlotFilter && self.currentSlotFilter !== 'all') {
                    if (self.currentSlotFilter === 'none') {
                        if (ord.shipping_slot) return false;
                    } else if (self.currentSlotFilter === 'tomorrow') {
                        if (!ord.shipping_slot_is_tomorrow) return false;
                    } else {
                        if (ord.shipping_slot !== self.currentSlotFilter) return false;
                    }
                }

                return true;
            });

            // Ordenar: siempre de los más nuevos a los más antiguos
            filtered.sort((a, b) => b.timestamp - a.timestamp);

            self.renderOrders(filtered);
            $('#caja-orders-count').text(filtered.length);
        },

        saveOrderNote: function(orderId, noteText, $btn) {
            const self = this;
            const $card = $(`#caja-order-card-${orderId}`);
            const $msg = $card.find(`#caja-note-saved-${orderId}`);
            const originalText = $btn.find('.caja-note-btn-text').text();

            $btn.prop('disabled', true);
            $btn.find('.caja-note-btn-text').text('Guardando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_save_note',
                    security: self.config.nonce,
                    order_id: orderId,
                    note: noteText
                },
                success: function(res) {
                    if (res.success) {
                        $btn.find('.caja-note-btn-text').text('✓ Guardado');
                        $msg.text('✓ Guardado en WooCommerce').fadeIn(150);
                        setTimeout(() => {
                            $btn.find('.caja-note-btn-text').text(originalText);
                            $msg.fadeOut(300);
                        }, 2500);

                        // Actualizar en caché local
                        const found = self.cachedOrders.find(o => o.id === orderId);
                        if (found) {
                            found.caja_note = noteText;
                        }
                        self.showToast('Nota adicional guardada con éxito');
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al guardar la nota');
                        $btn.find('.caja-note-btn-text').text(originalText);
                    }
                },
                error: function() {
                    alert('Error de comunicación con el servidor al guardar la nota');
                    $btn.find('.caja-note-btn-text').text(originalText);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },

        openRefundStockModal: function(orderId, order, $select, currentStatus) {
            const self = this;
            const $modal = $('#caja-modal-refund-stock');
            const $details = $('#caja-refund-modal-details');

            let pkgInfo = '';
            if (order && order.packaging_summary_text) {
                pkgInfo = `<strong>📦 Empaque del pedido:</strong> ${order.packaging_summary_text}`;
            } else if (order && (order.boxes_12_qty > 0 || order.boxes_6_qty > 0)) {
                const parts = [];
                if (order.boxes_12_qty > 0) parts.push(`${order.boxes_12_qty}x Caja Oficial x 12`);
                if (order.boxes_6_qty > 0) parts.push(`${order.boxes_6_qty}x Caja Oficial x 6`);
                pkgInfo = `<strong>📦 Cajas asociadas:</strong> ${parts.join(', ')}`;
            } else {
                pkgInfo = '<strong>📦 Cajas físicas:</strong> Cajas de empaque asociadas al pedido.';
            }

            $details.html(pkgInfo);

            const closeModal = function() {
                $modal.fadeOut(150);
                $('#caja-btn-refund-restore-yes, #caja-btn-refund-restore-no, #caja-btn-refund-cancel, #caja-refund-modal-close-btn').off('.refundModal');
            };

            $('#caja-btn-refund-restore-yes').off('.refundModal').on('click.refundModal', function(e) {
                e.preventDefault();
                closeModal();
                self.applyRefundStatus(orderId, 'refunded', $select, 'yes');
            });

            $('#caja-btn-refund-restore-no').off('.refundModal').on('click.refundModal', function(e) {
                e.preventDefault();
                closeModal();
                self.applyRefundStatus(orderId, 'refunded', $select, 'no');
            });

            $('#caja-btn-refund-cancel, #caja-refund-modal-close-btn, #caja-modal-refund-stock .caja-modal-backdrop').off('.refundModal').on('click.refundModal', function(e) {
                e.preventDefault();
                closeModal();
                $select.val(currentStatus);
            });

            $modal.fadeIn(200);
        },

        applyRefundStatus: function(orderId, newStatus, $select, restoreBoxStock) {
            const self = this;
            const $card = $(`#caja-order-card-${orderId}`);
            const $grid = $(`#caja-meta-grid-${orderId}`);
            const $payBox = $(`#caja-meta-payment-${orderId}`);
            const $pkgBox = $(`#caja-meta-packaging-${orderId}`);
            const $shipBox = $(`#caja-meta-shipping-${orderId}`);
            const $controlBtnWrap = $card.find('.caja-control-pedido-btn-wrap');

            const setVisible = ($el, show) => {
                if (show) {
                    $el.removeClass('caja-meta-hidden').show();
                } else {
                    $el.addClass('caja-meta-hidden').hide();
                }
            };

            setVisible($payBox, true);
            setVisible($pkgBox, false);
            setVisible($shipBox, false);
            $controlBtnWrap.hide();

            const hasVisibleMeta = $payBox.is(':visible') || $pkgBox.is(':visible') || $shipBox.is(':visible');
            setVisible($grid, hasVisibleMeta);

            $select.removeClass('status-bg-pending status-bg-processing status-bg-enviando status-bg-completed status-bg-recibido-problema status-bg-cancelled status-bg-refunded status-bg-on-hold status-bg-failed is-verified-primary');
            $select.addClass('status-bg-refunded');

            $card.find('.caja-felicitacion-card, .caja-box-felicitacion-card').slideUp(200);

            self.updateOrderStatus(orderId, 'refunded', $select, { restore_box_stock: restoreBoxStock });
        },

        updateOrderStatus: function(orderId, newStatus, $btn, extraData) {
            const self = this;
            const $card = $(`#caja-order-card-${orderId}`);
            extraData = extraData || {};

            $card.addClass('caja-card-updating');

            // Si el estado principal pasa a "Recibido" (completed), actualizar automáticamente el select del envío en el DOM
            if (newStatus === 'completed') {
                const $shipSelect = $card.find('.caja-shipping-select');
                $shipSelect.val('entregado');
            } else if (newStatus === 'recibido-problema') {
                const $shipSelect = $card.find('.caja-shipping-select');
                $shipSelect.val('entregado_problemas');
            }

            const postData = $.extend({
                action: 'emp_caja_update_status',
                security: self.config.nonce,
                order_id: orderId,
                new_status: newStatus
            }, extraData);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: postData,
                success: function(res) {
                    if (res.success && res.data && res.data.order) {
                        const updated = res.data.order;
                        // Actualizar en caché local
                        const idx = self.cachedOrders.findIndex(o => o.id === orderId);
                        if (idx !== -1) {
                            self.cachedOrders[idx] = updated;
                        }
                        self.applyFilters();
                        self.showToast(`Pedido #${orderId} actualizado a ${updated.status_name}`);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al actualizar pedido');
                    }
                },
                error: function() {
                    alert('Error en la comunicación con el servidor');
                },
                complete: function() {
                    $card.removeClass('caja-card-updating');
                }
            });
        },

        updateCustomStatus: function(orderId, field, value, $select) {
            const self = this;
            const $card = $(`#caja-order-card-${orderId}`);
            $card.addClass('caja-card-updating');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_update_custom_status',
                    security: self.config.nonce,
                    order_id: orderId,
                    field: field,
                    value: value
                },
                success: function(res) {
                    if (res.success && res.data && res.data.order) {
                        const updated = res.data.order;
                        const idx = self.cachedOrders.findIndex(o => o.id === orderId);
                        if (idx !== -1) {
                            self.cachedOrders[idx] = updated;
                        }
                        self.applyFilters();
                        self.showToast('Estado de cobro/envío actualizado');
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al actualizar');
                    }
                },
                error: function() {
                    alert('Error de comunicación con el servidor');
                },
                complete: function() {
                    $card.removeClass('caja-card-updating');
                }
            });
        },

        loadProducts: function() {
            const self = this;
            $('#caja-products-loading').show();

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_get_products',
                    security: self.config.nonce
                },
                success: function(res) {
                    if (res.success && res.data) {
                        self.cachedProducts = res.data.products || [];
                        self.isProductsLoaded = true;
                        self.renderProducts(self.cachedProducts);
                    }
                },
                complete: function() {
                    $('#caja-products-loading').hide();
                }
            });
        },

        populateCategorySelects: function() {
            const self = this;
            if (!self.cachedCategories || !self.cachedCategories.length) return;

            const $filter = $('#caja-filter-product-cat');
            const $newSelect = $('#new-prod-category');
            const $editSelect = $('#edit-prod-category');

            const currentFilterVal = $filter.val();
            const currentNewVal = $newSelect.val();
            const currentEditVal = $editSelect.val();

            $filter.find('option:not(:first)').remove();
            $newSelect.find('option:not(:first)').remove();
            $editSelect.find('option:not(:first)').remove();

            self.cachedCategories.forEach(cat => {
                $filter.append(`<option value="${cat.id}" data-slug="${cat.slug}">${cat.name}</option>`);
                $newSelect.append(`<option value="${cat.id}">${cat.name}</option>`);
                $editSelect.append(`<option value="${cat.id}">${cat.name}</option>`);
            });

            if (currentFilterVal) $filter.val(currentFilterVal);
            if (currentNewVal) $newSelect.val(currentNewVal);
            if (currentEditVal) $editSelect.val(currentEditVal);
        },

        loadCategories: function(callback) {
            const self = this;
            if (self.categoriesLoaded && self.cachedCategories) {
                self.populateCategorySelects();
                if (typeof callback === 'function') callback(self.cachedCategories);
                return;
            }

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_get_categories',
                    security: self.config.nonce
                },
                success: function(res) {
                    if (res.success && res.data && res.data.categories) {
                        self.categoriesLoaded = true;
                        self.cachedCategories = res.data.categories;
                        self.populateCategorySelects();
                        if (typeof callback === 'function') callback(self.cachedCategories);
                    }
                }
            });
        },

        renderProducts: function(products) {
            const $tbody = $('#caja-products-tbody');
            const $empty = $('#caja-products-empty');
            const $tableWrap = $('#caja-products-grid');

            if (!products || products.length === 0) {
                $tbody.empty();
                $tableWrap.hide();
                const hasFilters = ($('#caja-search-products').val() || '').trim() !== '' ||
                                   ($('#caja-filter-product-cat').val() || '').trim() !== '' ||
                                   ($('#caja-filter-product-stock').val() || '').trim() !== '';
                if (hasFilters) {
                    $empty.html(`
                        <h3>No se encontraron productos</h3>
                        <p>No hay productos que coincidan con los filtros seleccionados.</p>
                        <button type="button" class="caja-btn caja-btn-secondary" id="caja-btn-reset-filters" style="margin-top: 10px;">
                            <span>Limpiar filtros</span>
                        </button>
                    `);
                    $('#caja-btn-reset-filters').off('click').on('click', () => {
                        $('#caja-search-products').val('');
                        $('#caja-filter-product-cat').val('');
                        $('#caja-filter-product-stock').val('');
                        this.filterProductsInDom();
                    });
                } else {
                    $empty.html(`
                        <h3>No se encontraron productos</h3>
                        <p>Puedes agregar productos pulsando en "Cargar Nuevo Producto".</p>
                    `);
                }
                $empty.show();
                return;
            }

            $empty.hide();
            $tableWrap.show();

            let html = '';
            products.forEach(p => {
                const isGrouped = p.product_type === 'grouped';
                const isHidden = p.catalog_visibility === 'hidden';
                const isFeatured = !!p.is_featured;

                let badgesHtml = '';
                if (isGrouped) {
                    if (p.is_predefined) {
                        badgesHtml += ` <span class="caja-badge-grouped" style="background:#059669;" title="Combo Predeterminado Fijo (Cantidades fijadas por la tienda)">🎁 Combo Fijo</span>`;
                    } else {
                        badgesHtml += ` <span class="caja-badge-grouped" title="Producto Agrupado (Caja Personalizable)">📦 Agrupado</span>`;
                    }
                }
                if (isFeatured) {
                    badgesHtml += ` <span class="caja-badge-featured" title="Producto Destacado">⭐ Destacado</span>`;
                }
                if (isHidden) {
                    badgesHtml += ` <span class="caja-badge-hidden" title="Oculto para los clientes">🚫 Oculto</span>`;
                }
                if (p.is_official_box) {
                    const boxRoleLabel = p.official_box_role === 'box_6' ? '6u' : (p.official_box_role === 'box_12' ? '12u' : '');
                    badgesHtml += ` <span class="caja-badge-official-box" title="Caja oficial de empaque utilizada para descontar stock en los pedidos">📦 Caja Oficial ${boxRoleLabel}</span>`;
                }

                html += `
                    <tr id="caja-prod-row-${p.id}" class="caja-prod-row">
                        <td class="caja-td-foto caja-td-thumb caja-td-imagen" style="padding: 14px 0;">
                            <img src="${p.image_url}" alt="${p.name}" class="caja-prod-thumb" />
                        </td>
                        <td class="caja-td-producto caja-td-name caja-td-title">
                            <strong class="caja-prod-title">${p.name}</strong>
                            ${badgesHtml}
                        </td>
                        <td class="caja-td-stock">
                            <span class="caja-badge ${p.stock_badge}">${p.stock_label}</span>
                            <small class="caja-stock-num">(${p.stock_quantity})</small>
                            ${p.is_dynamic_stock ? `<div style="font-size:0.75rem; color:#059669; font-weight:600; margin-top:2px;" title="${p.bottleneck_item ? 'Limitado por: ' + p.bottleneck_item : ''}">⚡ Dinámico</div>` : ''}
                        </td>
                        <td class="caja-td-precio caja-td-price">
                            <strong class="caja-prod-price">${p.price}</strong>
                            ${p.is_on_sale ? `<span class="caja-badge-sale">Oferta</span>` : ''}
                        </td>
                        <td class="caja-td-categoria caja-td-category caja-td-cat">
                            <span class="caja-cat-badge">${p.categories || 'Sin categoría'}</span>
                        </td>
                        <td class="caja-td-acciones caja-td-actions" style="text-align: center; white-space: nowrap;">
                            <button type="button" class="caja-btn caja-btn-sm caja-btn-secondary caja-btn-open-edit-prod" data-product-id="${p.id}" title="Modificar todos los datos del producto (Nombre, Precios, Categoría, SKU, Descripción)" style="margin-right: 4px; padding: 6px 10px;">
                                <span>✏️ Modificar</span>
                            </button>
                            <button type="button" class="caja-btn caja-btn-sm caja-btn-stock-action caja-btn-open-stock-modal" data-product-id="${p.id}" title="Cargar y renovar stock (Sumar ingresos)" style="padding: 6px 10px;">
                                <span class="caja-btn-icon">📦</span>
                                <span>Stock</span>
                            </button>
                        </td>
                    </tr>
                `;
            });

            $tbody.html(html);
        },

        filterProductsInDom: function() {
            const search = ($('#caja-search-products').val() || '').toLowerCase().trim();
            const selectedCat = ($('#caja-filter-product-cat').val() || '').trim();
            const stockFilter = $('#caja-filter-product-stock').val();

            // Normalizador de texto: sin tildes, minúsculas, espacios simples
            const normalize = str => (str || '')
                .toString()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[-_\s]+/g, ' ')
                .trim();

            const filtered = this.cachedProducts.filter(p => {
                const matchesSearch = !search || 
                    (p.name && p.name.toLowerCase().includes(search)) || 
                    (p.sku && p.sku.toLowerCase().includes(search));

                let matchesCat = true;
                if (selectedCat && selectedCat !== 'all' && selectedCat !== '') {
                    const catIdNum = parseInt(selectedCat, 10);
                    const selSlug = selectedCat.toLowerCase();
                    const selNorm = normalize(selectedCat);

                    // 1. Coincidencia por ID numérico de categoría (WooCommerce term_id)
                    const matchId = !isNaN(catIdNum) && Array.isArray(p.category_ids) && 
                        p.category_ids.map(Number).includes(catIdNum);

                    // 2. Coincidencia por slug de categoría
                    const matchSlug = Array.isArray(p.category_slugs) && 
                        p.category_slugs.map(s => String(s).toLowerCase()).includes(selSlug);

                    // 3. Coincidencia por nombre normalizado (soporta tildes, espacios o guiones)
                    const prodCatNorm = normalize(p.categories);
                    const matchName = prodCatNorm.length > 0 && (
                        prodCatNorm === selNorm ||
                        prodCatNorm.split(',').some(c => c.trim() === selNorm) ||
                        prodCatNorm.includes(selNorm)
                    );

                    matchesCat = matchId || matchSlug || matchName;
                }

                let matchesStock = true;
                if (stockFilter === 'instock') {
                    matchesStock = p.stock_status === 'instock' && (p.stock_quantity_raw === null || p.stock_quantity_raw > 0);
                } else if (stockFilter === 'lowstock') {
                    matchesStock = p.is_low_stock || (p.stock_quantity_raw !== null && p.stock_quantity_raw > 0 && p.stock_quantity_raw <= 5);
                } else if (stockFilter === 'outofstock') {
                    matchesStock = p.stock_status === 'outofstock' || (p.stock_quantity_raw !== null && p.stock_quantity_raw <= 0);
                }

                return matchesSearch && matchesCat && matchesStock;
            });

            this.renderProducts(filtered);
        },

        openMediaUploader: function(options) {
            if (typeof wp === 'undefined' || !wp.media) {
                alert('La librería multimedia de WordPress no está disponible. Verifique que la página haya cargado completamente.');
                return;
            }

            const frame = wp.media({
                title: options.title || 'Seleccionar o Subir Imagen',
                button: { text: options.buttonText || 'Usar esta foto' },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                let url = attachment.url;
                if (attachment.sizes && attachment.sizes.medium) {
                    url = attachment.sizes.medium.url;
                } else if (attachment.sizes && attachment.sizes.thumbnail) {
                    url = attachment.sizes.thumbnail.url;
                }
                if (options.onSelect) {
                    options.onSelect({
                        id: attachment.id,
                        url: url
                    });
                }
            });

            frame.open();
        },

        renderGroupedChildrenList: function(selectedIds, currentProductId, predefinedQtys, isPredefined) {
            const self = this;
            const $list = $('#edit-prod-children-list');
            $list.empty();

            selectedIds = Array.isArray(selectedIds) ? selectedIds.map(Number) : [];
            predefinedQtys = (predefinedQtys && typeof predefinedQtys === 'object') ? predefinedQtys : {};

            // Solo productos simples o productos que no sean el actual ni agrupados
            const candidates = (self.cachedProducts || []).filter(p => {
                return p.id !== currentProductId && p.product_type !== 'grouped';
            });

            if (candidates.length === 0) {
                $list.html('<div style="padding:10px; color:#94a3b8; font-size:0.85rem; text-align:center;">No hay productos simples disponibles en la tienda para asociar a esta agrupación.</div>');
                $('#edit-grouped-selected-badge').text('0 seleccionados');
                return;
            }

            let html = '';
            candidates.forEach(cand => {
                const isChecked = selectedIds.includes(Number(cand.id));
                const qty = predefinedQtys[cand.id] || 1;
                const thumb = cand.image_url || '';
                const sku = cand.sku || 'S/N';
                const normName = (cand.name || '').toLowerCase();
                const normSku = (cand.sku || '').toLowerCase();

                html += `
                    <div class="caja-child-select-item" data-search="${normName} ${normSku}" data-id="${cand.id}">
                        <label class="caja-child-main-label">
                            <input type="checkbox" class="caja-child-chk" value="${cand.id}" ${isChecked ? 'checked' : ''} />
                            ${thumb ? `<img src="${thumb}" alt="" class="caja-child-thumb" />` : ''}
                            <div class="caja-child-info">
                                <strong>${cand.name}</strong>
                                <div class="caja-child-meta">
                                    <span>${cand.price || ''}</span>
                                </div>
                            </div>
                        </label>
                        <div class="caja-child-qty-wrap" style="${isPredefined ? '' : 'display:none;'}">
                            <span class="caja-child-qty-prefix">x</span>
                            <input type="number" min="1" step="1" class="caja-child-qty-input" value="${qty}" ${isChecked ? '' : 'disabled'} title="Cantidad fija en el combo" />
                            <span class="caja-child-qty-suffix">u.</span>
                        </div>
                    </div>
                `;
            });

            $list.html(html);
            self.updateGroupedSelectedCount();
        },

        updateGroupedSelectedCount: function() {
            const isPredefined = $('input[name="grouped_combo_mode"]:checked').val() === 'predefined';
            const $checked = $('#edit-prod-children-list .caja-child-chk:checked');
            const count = $checked.length;

            if (isPredefined) {
                let totalUnits = 0;
                $checked.each(function() {
                    const $item = $(this).closest('.caja-child-select-item');
                    const qty = parseInt($item.find('.caja-child-qty-input').val()) || 1;
                    totalUnits += qty;
                });
                $('#edit-grouped-selected-badge').text(`${count} producto${count === 1 ? '' : 's'} (${totalUnits} u. en combo)`);
            } else {
                $('#edit-grouped-selected-badge').text(`${count} seleccionado${count === 1 ? '' : 's'}`);
            }
        },

        renderRecommendationsList: function(containerId, selectedIds, currentProductId, badgeId) {
            const self = this;
            const $list = $(`#${containerId}`);
            if (!$list.length) return;
            $list.empty();

            selectedIds = Array.isArray(selectedIds) ? selectedIds.map(Number) : [];

            // Excluir el producto actual
            const candidates = (self.cachedProducts || []).filter(p => {
                return !currentProductId || p.id !== currentProductId;
            });

            if (candidates.length === 0) {
                $list.html('<div style="padding:10px; color:#94a3b8; font-size:0.85rem; text-align:center;">No hay productos disponibles para recomendar.</div>');
                if (badgeId) $(`#${badgeId}`).text('0 seleccionados');
                return;
            }

            let html = '';
            candidates.forEach(cand => {
                const isChecked = selectedIds.includes(Number(cand.id));
                const thumb = cand.image_url || '';
                const normName = (cand.name || '').toLowerCase();
                const normSku = (cand.sku || '').toLowerCase();

                html += `
                    <div class="caja-child-select-item" data-search="${normName} ${normSku}" data-id="${cand.id}">
                        <label class="caja-child-main-label" style="display:flex; align-items:center; gap:10px; cursor:pointer; width:100%;">
                            <input type="checkbox" class="caja-rec-chk" value="${cand.id}" ${isChecked ? 'checked' : ''} />
                            ${thumb ? `<img src="${thumb}" alt="" class="caja-child-thumb" style="width:36px; height:36px; border-radius:4px; object-fit:cover;" />` : '<span style="font-size:1.3rem;">📦</span>'}
                            <div class="caja-child-info">
                                <strong>${cand.name}</strong>
                                <div class="caja-child-meta" style="font-size:0.8rem; color:#94a3b8;">
                                    <span>${cand.price || ''}</span>
                                </div>
                            </div>
                        </label>
                    </div>
                `;
            });

            $list.html(html);
            self.updateRecommendationsCount(containerId, badgeId);
        },

        updateRecommendationsCount: function(containerId, badgeId) {
            if (!badgeId) return;
            const count = $(`#${containerId} .caja-rec-chk:checked`).length;
            $(`#${badgeId}`).text(`${count} seleccionado${count === 1 ? '' : 's'}`);
        },

        savePackaging: function(orderId, box6, box12, bagLarge, bagSmall, callback) {
            const self = this;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_update_packaging',
                    security: self.config.nonce,
                    order_id: orderId,
                    box_6: box6,
                    box_12: box12,
                    bag_large: bagLarge,
                    bag_small: bagSmall
                },
                success: function(res) {
                    if (res.success && res.data && res.data.order) {
                        const updated = res.data.order;
                        const idx = self.cachedOrders.findIndex(o => o.id == orderId);
                        if (idx !== -1) {
                            self.cachedOrders[idx].boxes_6_qty = updated.boxes_6_qty;
                            self.cachedOrders[idx].boxes_12_qty = updated.boxes_12_qty;
                            self.cachedOrders[idx].bag_large = updated.bag_large;
                            self.cachedOrders[idx].bag_small = updated.bag_small;
                            if (updated.packing_summary) {
                                self.cachedOrders[idx].packing_summary = updated.packing_summary;
                            }
                        }
                        const boxesText = (updated.boxes_12_qty > 0 || updated.boxes_6_qty > 0)
                            ? `x12: <strong>${updated.boxes_12_qty}</strong> &nbsp;|&nbsp; x6: <strong>${updated.boxes_6_qty}</strong>`
                            : '<em style="color:#64748b;">0 cajas</em>';
                        $(`#caja-pkg-boxes-val-${orderId}`).html(boxesText);
                        self.showToast('✅ Empaque actualizado.');
                        if (typeof callback === 'function') callback(true);
                    } else {
                        if (typeof callback === 'function') callback(false);
                    }
                },
                error: function() {
                    if (typeof callback === 'function') callback(false);
                }
            });
        },

        setOrderPackingTheme: function(orderId, theme, callback) {
            const self = this;
            const $tag = $(`#caja-thematic-tag-${orderId}`);
            const $btn = $(`.caja-btn-thematic-toggle[data-order-id="${orderId}"]`);
            const $delBtn = $(`.caja-btn-del-theme[data-order-id="${orderId}"]`);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_set_order_packing_theme',
                    security: self.config.nonce,
                    order_id: orderId,
                    theme: theme
                },
                success: function(res) {
                    if (res.success && res.data) {
                        const updated = res.data.order;
                        const idx = self.cachedOrders.findIndex(o => o.id == orderId);
                        if (idx !== -1) {
                            self.cachedOrders[idx].packing_theme = updated.packing_theme;
                            self.cachedOrders[idx].is_thematic_packaging = updated.is_thematic_packaging;
                            if (updated.timeline) {
                                self.cachedOrders[idx].timeline = updated.timeline;
                            }
                        }

                        if (theme) {
                            $btn.addClass('has-theme');
                            $tag.removeClass('caja-thematic-opt-tag').addClass('caja-thematic-active-tag').text(theme);
                            if ($delBtn.length) {
                                $delBtn.data('theme', theme).show();
                            }
                            self.showToast(`🎁 Temática "${theme}" asignada.`);
                        } else {
                            $btn.removeClass('has-theme');
                            $tag.removeClass('caja-thematic-active-tag').addClass('caja-thematic-opt-tag').text('(Opcional)');
                            if ($delBtn.length) {
                                $delBtn.hide();
                            }
                            self.showToast('📦 Empaque restablecido a estándar.');
                        }
                        if (typeof callback === 'function') callback(true, updated);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al guardar la temática.');
                        if (typeof callback === 'function') callback(false);
                    }
                },
                error: function() {
                    alert('Error de conexión al guardar la temática.');
                    if (typeof callback === 'function') callback(false);
                }
            });
        },

        addNewPackingTheme: function(themeName, orderId) {
            const self = this;
            const $input = $(`#caja-input-new-theme-${orderId}`);
            const $btn = $(`.caja-btn-add-theme[data-order-id="${orderId}"]`);
            $btn.prop('disabled', true).text('Guardando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_add_packing_theme',
                    security: self.config.nonce,
                    theme_name: themeName
                },
                success: function(res) {
                    $btn.prop('disabled', false).text('+ Agregar');
                    if (res.success && res.data) {
                        if (res.data.themes) {
                            self.cachedPackingThemes = res.data.themes;
                        }
                        $input.val('');
                        self.refreshAllThemeSelects(themeName);
                        if (orderId) {
                            $(`#caja-select-theme-${orderId}`).val(themeName);
                            $(`.caja-chk-is-thematic[data-order-id="${orderId}"]`).prop('checked', true);
                            $(`#caja-thematic-fields-${orderId}`).show();
                            self.setOrderPackingTheme(orderId, themeName);
                        }
                        self.showToast(`✨ Temática "${themeName}" creada exitosamente.`);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al crear la temática.');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('+ Agregar');
                    alert('Error de conexión al agregar la temática.');
                }
            });
        },

        deletePackingTheme: function(themeName, orderId) {
            const self = this;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_delete_packing_theme',
                    security: self.config.nonce,
                    theme_name: themeName
                },
                success: function(res) {
                    if (res.success && res.data) {
                        if (res.data.themes) {
                            self.cachedPackingThemes = res.data.themes;
                        }
                        self.refreshAllThemeSelects();
                        if (orderId) {
                            $(`#caja-select-theme-${orderId}`).val('');
                            $(`.caja-chk-is-thematic[data-order-id="${orderId}"]`).prop('checked', false);
                            $(`#caja-thematic-fields-${orderId}`).slideUp(150);
                            self.setOrderPackingTheme(orderId, '');
                        }
                        self.showToast(`🗑️ Temática "${themeName}" eliminada.`);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al eliminar la temática.');
                    }
                },
                error: function() {
                    alert('Error de conexión al eliminar la temática.');
                }
            });
        },

        refreshAllThemeSelects: function(selectedThemeToPreserve) {
            const self = this;
            $('.caja-select-packing-theme').each(function() {
                const $sel = $(this);
                const currentVal = $sel.val();
                let optionsHtml = '<option value="">-- Sin temática especial (Estándar) --</option>';
                (self.cachedPackingThemes || []).forEach(t => {
                    optionsHtml += `<option value="${self.escapeHtml(t)}">${self.escapeHtml(t)}</option>`;
                });
                $sel.html(optionsHtml);
                if (selectedThemeToPreserve) {
                    $sel.val(selectedThemeToPreserve);
                } else if (currentVal) {
                    $sel.val(currentVal);
                }
            });
        },

        openControlPedidoModal: function(order) {
            const self = this;
            self.currentControlOrderId = order.id;

            $('#caja-control-title').text(`Control de Pedido #${order.number}`);
            const $body = $('#caja-control-body');
            $body.empty();

            let boxIndex = 1;
            let html = '';
            let looseItems = [];

            // 1. Cajas agrupadas y automáticas
            (order.items || []).forEach(it => {
                if (it.is_box) {
                    const subitems = it.pack_items || [];
                    const unitsCount = it.box_units || (subitems.length > 0 ? subitems.reduce((acc, c) => acc + (parseInt(c.quantity, 10) || 1), 0) : (it.quantity || 1));

                    html += `
                        <div class="caja-control-box-card">
                            <div class="caja-control-box-header">
                                <div class="caja-control-box-header-info">
                                    <span class="caja-control-box-icon">📦</span>
                                    <span class="caja-control-box-title">Caja ${boxIndex}: ${self.escapeHtml(it.name)}</span>
                                    <span class="caja-control-box-badge">${unitsCount} unidades</span>
                                </div>
                                <label class="caja-control-check-wrap" title="Verificar caja">
                                    <input type="checkbox" class="caja-control-chk" />
                                </label>
                            </div>
                            <div class="caja-control-subitems-list">
                    `;

                    if (subitems.length > 0) {
                        subitems.forEach(sub => {
                            html += `
                                <div class="caja-control-item-row">
                                    <div class="caja-control-item-left">
                                        ${sub.image ? `<img src="${sub.image}" class="caja-control-thumb" alt="" />` : '<span class="caja-control-no-thumb">🍬</span>'}
                                        <div class="caja-control-item-info">
                                            <span class="caja-control-item-qty">${sub.quantity}x</span>
                                            <span class="caja-control-item-name">${self.escapeHtml(sub.name)}</span>
                                        </div>
                                    </div>
                                    <label class="caja-control-check-wrap">
                                        <input type="checkbox" class="caja-control-chk" />
                                    </label>
                                </div>
                            `;
                        });
                    }

                    html += `
                            </div>
                        </div>
                    `;
                    boxIndex++;
                } else {
                    looseItems.push(it);
                }
            });

            // 2. Productos sueltos / adicionales
            if (looseItems.length > 0) {
                html += `
                    <div class="caja-control-box-card caja-control-loose-card">
                        <div class="caja-control-box-header">
                            <span class="caja-control-box-icon">🍬</span>
                            <span class="caja-control-box-title">Productos sueltos / Adicionales</span>
                            <span class="caja-control-box-badge">${looseItems.length} ítem${looseItems.length === 1 ? '' : 's'}</span>
                        </div>
                        <div class="caja-control-subitems-list">
                `;
                looseItems.forEach(it => {
                    html += `
                        <div class="caja-control-item-row">
                            <div class="caja-control-item-left">
                                ${it.image ? `<img src="${it.image}" class="caja-control-thumb" alt="" />` : '<span class="caja-control-no-thumb">🍬</span>'}
                                <div class="caja-control-item-info">
                                    <span class="caja-control-item-qty">${it.quantity}x</span>
                                    <span class="caja-control-item-name">${self.escapeHtml(it.name)}</span>
                                </div>
                            </div>
                            <label class="caja-control-check-wrap">
                                <input type="checkbox" class="caja-control-chk" />
                            </label>
                        </div>
                    `;
                });
                html += `
                        </div>
                    </div>
                `;
            }

            // 3. Bolsas de envío (grandes y chicas)
            const bagLargeQty = (order.bag_large !== undefined && order.bag_large !== null) ? parseInt(order.bag_large, 10) : 1;
            const bagSmallQty = (order.bag_small !== undefined && order.bag_small !== null) ? parseInt(order.bag_small, 10) : 0;
            const bagLargeName = (self.config && self.config.officialBagLargeName) || 'Bolsa grande de envío';
            const bagSmallName = (self.config && self.config.officialBagSmallName) || 'Bolsa chica de envío';

            html += `
                <div class="caja-control-box-card caja-control-bags-card">
                    <div class="caja-control-box-header">
                        <span class="caja-control-box-icon">🛍️</span>
                        <span class="caja-control-box-title">Bolsas de envío</span>
                    </div>
                    <div class="caja-control-subitems-list">
            `;

            if (bagLargeQty > 0 || (bagLargeQty === 0 && bagSmallQty === 0)) {
                const qtyToShow = bagLargeQty > 0 ? bagLargeQty : 1;
                html += `
                    <div class="caja-control-item-row">
                        <div class="caja-control-item-left">
                            <span class="caja-control-no-thumb">🛍️</span>
                            <div class="caja-control-item-info">
                                <span class="caja-control-item-qty">${qtyToShow}x</span>
                                <span class="caja-control-item-name">${self.escapeHtml(bagLargeName)}</span>
                            </div>
                        </div>
                        <label class="caja-control-check-wrap">
                            <input type="checkbox" class="caja-control-chk" />
                        </label>
                    </div>
                `;
            }

            if (bagSmallQty > 0) {
                html += `
                    <div class="caja-control-item-row">
                        <div class="caja-control-item-left">
                            <span class="caja-control-no-thumb">🛍️</span>
                            <div class="caja-control-item-info">
                                <span class="caja-control-item-qty">${bagSmallQty}x</span>
                                <span class="caja-control-item-name">${self.escapeHtml(bagSmallName)}</span>
                            </div>
                        </div>
                        <label class="caja-control-check-wrap">
                            <input type="checkbox" class="caja-control-chk" />
                        </label>
                    </div>
                `;
            }

            html += `
                    </div>
                </div>
            `;

            // 4. Paquetería temática especial (si aplica al pedido)
            if (order.packing_theme) {
                html += `
                    <div class="caja-control-box-card caja-control-theme-card" style="border: 1px solid rgba(234, 179, 8, 0.4); background: rgba(234, 179, 8, 0.08);">
                        <div class="caja-control-box-header" style="background: rgba(234, 179, 8, 0.18);">
                            <span class="caja-control-box-icon">🎁</span>
                            <span class="caja-control-box-title" style="color:#fef08a;">Paquetería Temática Especial</span>
                            <span class="caja-control-box-badge" style="background:#eab308; color:#0f172a; font-weight:700;">¡Atención!</span>
                        </div>
                        <div class="caja-control-subitems-list">
                            <div class="caja-control-item-row" style="background:transparent;">
                                <div class="caja-control-item-left">
                                    <span class="caja-control-no-thumb" style="font-size:1.3rem;">🎀</span>
                                    <div class="caja-control-item-info">
                                        <span class="caja-control-item-qty" style="color:#eab308; font-weight:700;">Temática:</span>
                                        <span class="caja-control-item-name" style="font-size:0.95rem; font-weight:600; color:#ffffff;">${self.escapeHtml(order.packing_theme)}</span>
                                    </div>
                                </div>
                                <label class="caja-control-check-wrap" title="Verificar paquetería temática">
                                    <input type="checkbox" class="caja-control-chk" />
                                </label>
                            </div>
                        </div>
                    </div>
                `;
            }

            $body.html(html);

            const total = $('.caja-control-chk').length;
            $('#caja-control-checked-count').text(0);
            $('#caja-control-total-count').text(total);
            $('#caja-btn-iniciar-envio').prop('disabled', true).removeClass('is-ready');

            $('body').addClass('caja-modal-locked');
            $body.scrollTop(0);
            $('#caja-modal-control-pedido').fadeIn(200);
        },

        populatePackagingBoxSelect: function(selectedId) {
            const self = this;
            const $select = $('#edit-prod-packaging-box');
            $select.empty();

            $select.append('<option value="0">⚙️ Detección automática (según unidades)</option>');

            const candidates = self.config.boxCandidates || [];
            candidates.forEach(cand => {
                let label = cand.name;
                if (Number(cand.id) === Number(self.config.officialBox6Id)) {
                    label += ' ★ (Oficial 6u)';
                } else if (Number(cand.id) === Number(self.config.officialBox12Id)) {
                    label += ' ★ (Oficial 12u)';
                }
                const isSel = Number(cand.id) === Number(selectedId);
                $select.append(`<option value="${cand.id}" ${isSel ? 'selected' : ''}>${label}</option>`);
            });

            if (selectedId && Number(selectedId) > 0) {
                $select.val(String(selectedId));
            } else {
                $select.val('0');
            }
        },

        renderVariableAttributes: function(attributes) {
            const $container = $('#caja-attributes-list');
            $container.empty();

            if (!attributes || !attributes.length) {
                attributes = [{ name: 'Sabor', options: 'Negro | Blanco' }];
            }

            attributes.forEach(attr => {
                const rowHtml = `
                    <div class="caja-attribute-row">
                        <div class="caja-form-row">
                            <div class="caja-form-group caja-col" style="flex: 1;">
                                <label><strong>Nombre del Atributo</strong></label>
                                <input type="text" class="caja-attr-name" value="${attr.name || ''}" placeholder="Ej: Sabor, Tamaño..." />
                            </div>
                            <div class="caja-form-group caja-col" style="flex: 2;">
                                <label><strong>Opciones (separadas con | )</strong></label>
                                <input type="text" class="caja-attr-options" value="${attr.options || ''}" placeholder="Ej: Negro | Blanco | Pistacho" />
                            </div>
                            <div class="caja-form-group caja-col caja-align-bottom" style="flex: 0 0 auto;">
                                <button type="button" class="caja-btn caja-btn-danger caja-btn-sm caja-remove-attr-btn" title="Eliminar atributo">🗑️</button>
                            </div>
                        </div>
                    </div>
                `;
                $container.append(rowHtml);
            });
        },

        collectVariableAttributes: function() {
            const attrs = [];
            $('#caja-attributes-list .caja-attribute-row').each(function() {
                const name = ($(this).find('.caja-attr-name').val() || '').trim();
                const options = ($(this).find('.caja-attr-options').val() || '').trim();
                if (name && options) {
                    attrs.push({ name, options });
                }
            });
            return attrs;
        },

        renderVariationsList: function(variations, currentAttributes) {
            const self = this;
            const $container = $('#caja-variations-list');
            $container.empty();

            if (!variations || !variations.length) {
                $container.html('<p class="caja-text-muted" style="font-size:12px; font-style:italic; padding:8px 0;">No hay variaciones creadas aún. Podés crearlas pulsando en "⚡ Generar según Atributos" o "➕ Añadir Variación".</p>');
                return;
            }

            const attrs = currentAttributes && currentAttributes.length ? currentAttributes : self.collectVariableAttributes();

            variations.forEach((v, idx) => {
                const vId = v.id || 0;
                const regPrice = v.regular_price || '';
                const salePrice = v.sale_price || '';
                const sku = v.sku || '';
                const manageStock = !!v.manage_stock;
                const stockQty = v.stock_quantity !== '' && v.stock_quantity !== null ? v.stock_quantity : '';
                const isEnabled = v.enabled !== false;
                const imgId = v.image_id || 0;
                const imgUrl = v.image_url || '';
                const canShare = v.can_share_box || 'none';
                const maxAlf = (v.share_box_max_alfajores !== undefined && v.share_box_max_alfajores !== null && v.share_box_max_alfajores !== '') ? v.share_box_max_alfajores : 6;

                let attrSelectorsHtml = '';
                attrs.forEach(a => {
                    const cleanName = a.name.trim();
                    const optionsList = a.options.split('|').map(s => s.trim()).filter(Boolean);
                    const currentVal = (v.attributes && (v.attributes[cleanName] || v.attributes[cleanName.toLowerCase()] || v.attributes['attribute_' + cleanName.toLowerCase()])) || '';

                    let optsHtml = '<option value="">Cualquier ' + cleanName + '...</option>';
                    optionsList.forEach(opt => {
                        const isSel = (currentVal.toLowerCase() === opt.toLowerCase()) ? 'selected' : '';
                        optsHtml += `<option value="${opt}" ${isSel}>${opt}</option>`;
                    });

                    attrSelectorsHtml += `
                        <div class="caja-form-group caja-col">
                            <label><strong>${cleanName}:</strong></label>
                            <select class="caja-select caja-var-attr-select" data-attr-name="${cleanName}">
                                ${optsHtml}
                            </select>
                        </div>
                    `;
                });

                const cardHtml = `
                    <div class="caja-variation-card" data-id="${vId}">
                        <div class="caja-variation-card-header">
                            <div class="caja-variation-title">
                                <strong>Variación #${idx + 1}</strong>
                                ${vId ? `<small class="caja-text-muted" style="margin-left:4px;">(ID: ${vId})</small>` : '<span class="caja-badge caja-badge-info" style="margin-left:4px;">Nueva</span>'}
                            </div>
                            <div class="caja-variation-header-actions">
                                <label class="caja-checkbox-label" style="margin:0; font-size:12px;">
                                    <input type="checkbox" class="caja-var-enabled" ${isEnabled ? 'checked' : ''} />
                                    <span>Activa</span>
                                </label>
                                <button type="button" class="caja-btn caja-btn-danger caja-btn-sm caja-remove-var-btn" title="Eliminar variación">🗑️</button>
                            </div>
                        </div>
                        <div class="caja-variation-card-body">
                            <div class="caja-form-row">
                                ${attrSelectorsHtml}
                            </div>
                            <div class="caja-form-row">
                                <div class="caja-form-group caja-col">
                                    <label>Precio Regular *</label>
                                    <input type="number" step="0.01" min="0" class="caja-var-price" value="${regPrice}" placeholder="0.00" />
                                </div>
                                <div class="caja-form-group caja-col">
                                    <label>Precio Oferta</label>
                                    <input type="number" step="0.01" min="0" class="caja-var-sale-price" value="${salePrice}" placeholder="0.00" />
                                </div>
                                <div class="caja-form-group caja-col">
                                    <label>SKU</label>
                                    <input type="text" class="caja-var-sku" value="${sku}" placeholder="SKU..." />
                                </div>
                            </div>
                            <div class="caja-form-row" style="align-items:center;">
                                <div class="caja-form-group caja-col" style="flex:0 0 auto;">
                                    <label class="caja-checkbox-label">
                                        <input type="checkbox" class="caja-var-manage-stock" ${manageStock ? 'checked' : ''} />
                                        <span>¿Gestionar inventario?</span>
                                    </label>
                                </div>
                                <div class="caja-form-group caja-col caja-var-stock-col" style="${manageStock ? '' : 'display:none;'} flex:1;">
                                    <label>Stock</label>
                                    <input type="number" min="0" step="1" class="caja-var-stock-qty" value="${stockQty}" placeholder="0" />
                                </div>
                                <div class="caja-form-group caja-col" style="flex:0 0 auto;">
                                    <div class="caja-var-img-box" style="display:flex; align-items:center; gap:8px;">
                                        <img class="caja-var-thumb" src="${imgUrl}" style="${imgUrl ? '' : 'display:none;'} width:34px; height:34px; border-radius:4px; object-fit:cover; border:1px solid rgba(255,255,255,0.15);" />
                                        <input type="hidden" class="caja-var-image-id" value="${imgId}" />
                                        <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm caja-var-choose-img-btn" style="padding:4px 8px; font-size:11px;">📷 Foto</button>
                                    </div>
                                </div>
                            </div>
                            <div class="caja-form-row caja-var-packing-row" style="margin-top:8px; padding-top:8px; border-top:1px dashed rgba(255,255,255,0.1); align-items:center;">
                                <div class="caja-form-group caja-col" style="flex:1;">
                                    <label style="font-size:11px;"><strong>🛍️ Compartir Caja Oficial</strong></label>
                                    <select class="caja-select caja-var-share-box" style="font-size:12px;">
                                        <option value="none" ${canShare === 'none' ? 'selected' : ''}>No (Individual)</option>
                                        <option value="box_12" ${canShare === 'box_12' ? 'selected' : ''}>📦 Sí, en Caja de 12</option>
                                        <option value="box_6" ${canShare === 'box_6' ? 'selected' : ''}>📦 Sí, en Caja de 6</option>
                                    </select>
                                </div>
                                <div class="caja-form-group caja-col caja-var-max-group" style="${canShare !== 'none' ? '' : 'display:none;'} flex:1;">
                                    <label style="font-size:11px;"><strong>Máx. Alfajores que caben</strong></label>
                                    <input type="number" min="1" max="12" class="caja-var-max-alfajores" value="${maxAlf}" placeholder="6 o 12" style="font-size:12px;" />
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                $container.append(cardHtml);
            });

            $container.off('change', '.caja-var-share-box').on('change', '.caja-var-share-box', function() {
                const $row = $(this).closest('.caja-var-packing-row');
                if ($(this).val() !== 'none') {
                    $row.find('.caja-var-max-group').show();
                } else {
                    $row.find('.caja-var-max-group').hide();
                }
            });
        },

        collectVariationsList: function() {
            const variations = [];
            $('#caja-variations-list .caja-variation-card').each(function() {
                const $card = $(this);
                if ($card.data('deleted')) {
                    const id = parseInt($card.data('id')) || 0;
                    if (id > 0) {
                        variations.push({ id, delete: true });
                    }
                    return;
                }

                const id = parseInt($card.data('id')) || 0;
                const enabled = $card.find('.caja-var-enabled').is(':checked');
                const regular_price = $card.find('.caja-var-price').val();
                const sale_price = $card.find('.caja-var-sale-price').val();
                const sku = $card.find('.caja-var-sku').val();
                const manage_stock = $card.find('.caja-var-manage-stock').is(':checked');
                const stock_quantity = $card.find('.caja-var-stock-qty').val();
                const image_id = $card.find('.caja-var-image-id').val();
                const can_share_box = $card.find('.caja-var-share-box').val() || 'none';
                const share_box_max_alfajores = parseInt($card.find('.caja-var-max-alfajores').val(), 10) || 6;

                const attributes = {};
                $card.find('.caja-var-attr-select').each(function() {
                    const attrName = $(this).data('attr-name');
                    const val = $(this).val();
                    if (attrName) {
                        attributes[attrName] = val;
                    }
                });

                variations.push({
                    id,
                    enabled,
                    regular_price,
                    sale_price,
                    sku,
                    manage_stock,
                    stock_quantity,
                    image_id,
                    can_share_box,
                    share_box_max_alfajores,
                    attributes
                });
            });
            return variations;
        },

        openEditProductModal: function(productId) {
            const self = this;
            const p = self.cachedProducts.find(item => item.id === productId);
            if (!p) return;

            // Resetear estado de acordeones (todos colapsados inicialmente)
            $('.caja-modal-section-accordion .caja-btn-modal-accordion').removeClass('active').find('.caja-toggle-arrow').text('▼');
            $('.caja-modal-section-accordion .caja-details-collapse').hide();

            $('#edit-prod-id').val(p.id);
            const initialType = p.product_type || 'simple';
            $('#edit-prod-type').val(initialType);
            $('#edit-prod-name').val(p.name);
            $('#edit-prod-price').val(p.regular_price || p.price_raw || '');
            $('#edit-prod-sale-price').val(p.sale_price || '');
            $('#edit-prod-sku').val(p.raw_sku || (p.sku !== 'S/N' ? p.sku : ''));
            $('#edit-prod-desc').val(p.raw_description || '');

            const catId = (p.category_ids && p.category_ids.length > 0) ? p.category_ids[0] : 0;
            if (self.cachedCategories && self.cachedCategories.length > 0) {
                self.populateCategorySelects();
                $('#edit-prod-category').val(catId);
            } else {
                self.loadCategories(function() {
                    $('#edit-prod-category').val(catId);
                });
            }

            // Visibilidad y Destacado
            $('#edit-prod-visibility').val(p.catalog_visibility || 'visible');
            $('#edit-prod-featured').prop('checked', !!p.is_featured);

            // Foto del producto
            $('#edit-prod-image-id').val(p.image_id ? p.image_id : '');
            if (p.image_url) {
                $('#edit-prod-thumb').attr('src', p.image_url).show();
                if (p.image_id && p.image_id > 0) {
                    $('#edit-prod-remove-img-btn').show();
                } else {
                    $('#edit-prod-remove-img-btn').hide();
                }
            } else {
                $('#edit-prod-thumb').hide().attr('src', '');
                $('#edit-prod-remove-img-btn').hide();
            }

            // Datos de Configuración de Pack Agrupado
            $('#edit-grouped-target-qty').val(p.grouped_target_qty !== undefined && p.grouped_target_qty !== null ? p.grouped_target_qty : '');
            $('#edit-grouped-enable-extra-box').prop('checked', !!p.grouped_enable_extra_box);
            const pricingType = p.grouped_pricing_type || ((p.grouped_fixed_price && parseFloat(p.grouped_fixed_price) > 0) ? 'fixed' : 'variable');
            $('#edit-grouped-pricing-type').val(pricingType);
            if (pricingType === 'variable') {
                $('#edit-grouped-fixed-display-group').hide();
            } else {
                $('#edit-grouped-fixed-display-group').show();
            }
            $('#edit-grouped-fixed-price-display').val(p.grouped_fixed_price_display || 'box');
            $('#edit-grouped-custom-price-from').val(p.grouped_custom_price_from || '');
            $('#edit-grouped-box-image-id').val(p.grouped_box_image_id || '');
            if (p.grouped_box_image_url) {
                $('#edit-grouped-box-img-preview').html(`<img src="${p.grouped_box_image_url}" style="width:100%; height:100%; object-fit:cover;" />`);
                $('#edit-grouped-remove-box-img-btn').show();
            } else {
                $('#edit-grouped-box-img-preview').html('<span style="font-size:20px;">📦</span>');
                $('#edit-grouped-remove-box-img-btn').hide();
            }

            if (p.is_dynamic_stock && p.dynamic_stock) {
                $('#edit-grouped-dyn-stock-text').text(`⚡ Stock Dinámico Sincronizado: ${p.stock_quantity} unidades disponibles`);
                if (p.bottleneck_item) {
                    $('#edit-grouped-dyn-bottleneck-text').html(`⚠️ Cuello de botella actual: <strong>${p.bottleneck_item}</strong>`).show();
                } else {
                    $('#edit-grouped-dyn-bottleneck-text').hide();
                }
                $('#edit-grouped-dyn-stock-banner').show();
            } else {
                $('#edit-grouped-dyn-stock-banner').hide();
            }

            // Manejo dinámico según Tipo de Producto (simple, grouped, variable)
            function updateEditProductTypeUI(type) {
                if (type === 'grouped') {
                    $('#edit-prod-sec-caja-oficial').slideUp(150);
                    $('#edit-prod-sec-variable').slideUp(150);
                    $('#edit-prod-grouped-notice').slideDown(150);
                    $('#edit-prod-sec-grouped-children').slideDown(150);
                    $('#edit-prod-sec-grouped-config').slideDown(150);
                    $('#edit-grouped-search-filter').val('');

                    const isPredefined = !!p.is_predefined;
                    if (isPredefined) {
                        $('#edit-grouped-mode-predefined').prop('checked', true);
                        $('#label-grouped-mode-predefined').addClass('active');
                        $('#label-grouped-mode-custom').removeClass('active');
                        $('#edit-grouped-mode-hint').text('Definí qué productos componen este combo y cuántas unidades fijas incluye cada uno:');
                    } else {
                        $('#edit-grouped-mode-custom').prop('checked', true);
                        $('#label-grouped-mode-custom').addClass('active');
                        $('#label-grouped-mode-predefined').removeClass('active');
                        $('#edit-grouped-mode-hint').text('Seleccioná cuáles productos simples se incluyen dentro de esta caja agrupada:');
                    }

                    self.renderGroupedChildrenList(p.children_ids || [], p.id, p.predefined_quantities || {}, isPredefined);
                    self.populatePackagingBoxSelect(p.packaging_box_product_id);
                } else if (type === 'variable') {
                    $('#edit-prod-grouped-notice').slideUp(150);
                    $('#edit-prod-sec-grouped-children').slideUp(150);
                    $('#edit-prod-sec-grouped-config').slideUp(150);
                    $('#edit-prod-sec-caja-oficial').slideUp(150);
                    $('#edit-prod-sec-variable').slideDown(150);

                    self.renderVariableAttributes(p.attributes || []);
                    self.renderVariationsList(p.variations || [], p.attributes || []);
                } else {
                    // simple
                    $('#edit-prod-grouped-notice').slideUp(150);
                    $('#edit-prod-sec-grouped-children').slideUp(150);
                    $('#edit-prod-sec-grouped-config').slideUp(150);
                    $('#edit-prod-sec-variable').slideUp(150);
                    $('#edit-prod-sec-caja-oficial').slideDown(150);

                    const currentBoxRole = p.official_box_role || 'none';
                    $('#edit-prod-box-role').val(currentBoxRole).data('prev-val', currentBoxRole);
                }

                if (type === 'variable') {
                    $('#edit-prod-shared-variable-hint').show();
                } else {
                    $('#edit-prod-shared-variable-hint').hide();
                }
            }

            updateEditProductTypeUI(initialType);

            $('#edit-prod-type').off('change').on('change', function() {
                updateEditProductTypeUI($(this).val());
            });

            // Empaque Compartido
            const canShare = p.can_share_box || 'none';
            const shareMax = (p.share_box_max_alfajores !== undefined && p.share_box_max_alfajores !== null && p.share_box_max_alfajores !== '') ? p.share_box_max_alfajores : 6;
            $('#edit-prod-can-share-box').val(canShare);
            $('#edit-prod-share-max-alfajores').val(shareMax);
            if (canShare !== 'none') {
                $('#edit-prod-share-max-group').show();
            } else {
                $('#edit-prod-share-max-group').hide();
            }

            $('#edit-prod-can-share-box').off('change').on('change', function() {
                if ($(this).val() !== 'none') {
                    $('#edit-prod-share-max-group').slideDown(150);
                } else {
                    $('#edit-prod-share-max-group').slideUp(150);
                }
            });

            if (p.manage_stock) {
                $('#edit-prod-manage-stock').prop('checked', true);
                $('#caja-edit-stock-qty-group').show();
                $('#edit-prod-stock-qty').val(p.stock_quantity_raw !== null ? p.stock_quantity_raw : 0);
            } else {
                $('#edit-prod-manage-stock').prop('checked', false);
                $('#caja-edit-stock-qty-group').hide();
                $('#edit-prod-stock-qty').val(0);
            }

            if (p.is_dynamic_stock && p.dynamic_stock) {
                const bottleneck = p.bottleneck_item ? ` (limitado por: ${p.bottleneck_item})` : '';
                $('#caja-edit-stock-dynamic-hint').html(`⚡ Stock Dinámico Sincronizado: <strong>${p.stock_quantity} disp.</strong>${bottleneck}`).show();
            } else {
                $('#caja-edit-stock-dynamic-hint').hide();
            }

            self.renderRecommendationsList('edit-prod-rec-list', p.recommended_ids || [], p.id, 'edit-rec-selected-badge');

            $('#caja-edit-product-error').hide();
            $('#caja-modal-edit-product').fadeIn(200);
            setTimeout(() => {
                $('#edit-prod-name').focus();
            }, 100);
        },

        handleUpdateProduct: function($form) {
            const self = this;
            const $btn = $('#caja-edit-prod-submit-btn');
            const $err = $('#caja-edit-product-error');
            const productId = parseInt($('#edit-prod-id').val());

            $err.hide();
            $btn.prop('disabled', true);
            $btn.find('.caja-btn-spinner').show();
            $btn.find('.caja-btn-text').text('Guardando cambios...');

            const selectedType = $('#edit-prod-type').val() || 'simple';
            const isGrouped = selectedType === 'grouped';
            const productData = {
                product_type: selectedType,
                name: $('#edit-prod-name').val(),
                regular_price: $('#edit-prod-price').val(),
                sale_price: $('#edit-prod-sale-price').val(),
                category_id: $('#edit-prod-category').val(),
                sku: $('#edit-prod-sku').val(),
                manage_stock: $('#edit-prod-manage-stock').is(':checked') ? 'yes' : 'no',
                stock_quantity: $('#edit-prod-stock-qty').val(),
                description: $('#edit-prod-desc').val(),
                image_id: $('#edit-prod-image-id').val(),
                featured: $('#edit-prod-featured').is(':checked') ? 'yes' : 'no',
                catalog_visibility: $('#edit-prod-visibility').val(),
                official_box_role: (selectedType === 'simple') ? ($('#edit-prod-box-role').val() || 'none') : 'none',
                can_share_box: $('#edit-prod-can-share-box').val() || 'none',
                share_box_max_alfajores: parseInt($('#edit-prod-share-max-alfajores').val(), 10) || 6
            };

            if (isGrouped) {
                productData.packaging_box_product_id = $('#edit-prod-packaging-box').val() || 0;
                productData.grouped_target_qty = $('#edit-grouped-target-qty').val();
                productData.grouped_enable_extra_box = $('#edit-grouped-enable-extra-box').is(':checked') ? 'yes' : 'no';
                productData.grouped_extra_box_name = $('#edit-grouped-extra-box-name').val();
                const pricingType = $('#edit-grouped-pricing-type').val() || 'fixed';
                productData.grouped_pricing_type = pricingType;
                productData.grouped_fixed_price = (pricingType === 'fixed') ? $('#edit-prod-price').val() : '';
                productData.grouped_fixed_price_display = $('#edit-grouped-fixed-price-display').val();
                productData.grouped_custom_price_from = $('#edit-grouped-custom-price-from').val();
                productData.grouped_box_image_id = $('#edit-grouped-box-image-id').val();

                const isPredefined = $('input[name="grouped_combo_mode"]:checked').val() === 'predefined';
                const children = [];
                const predefinedQtys = {};

                $('#edit-prod-children-list .caja-child-chk:checked').each(function() {
                    const id = parseInt($(this).val());
                    children.push(id);
                    if (isPredefined) {
                        const $item = $(this).closest('.caja-child-select-item');
                        const qty = parseInt($item.find('.caja-child-qty-input').val()) || 1;
                        predefinedQtys[id] = qty;
                    }
                });

                productData.children = children;
                productData.is_predefined = isPredefined ? 'yes' : 'no';
                productData.predefined_quantities = predefinedQtys;
            } else if (selectedType === 'variable') {
                productData.attributes = self.collectVariableAttributes();
                productData.variations = self.collectVariationsList();
            }

            const editRecIds = [];
            $('#edit-prod-rec-list .caja-rec-chk:checked').each(function() {
                const idVal = parseInt($(this).val(), 10);
                if (idVal > 0) {
                    editRecIds.push(idVal);
                }
            });
            // Si está vacío, enviamos [0] para que jQuery $.param no descarte la clave en POST
            productData.recommended_ids = editRecIds.length ? editRecIds : [0];

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_update_product',
                    security: self.config.nonce,
                    product_id: productId,
                    product: productData
                },
                success: function(res) {
                    if (res.success && res.data && res.data.product) {
                        const updated = res.data.product;
                        const idx = self.cachedProducts.findIndex(p => p.id === productId);
                        if (idx !== -1) {
                            self.cachedProducts[idx] = updated;
                        }

                        // Actualizar referencias en tiempo real de cajas oficiales
                        if (updated.official_box_role === 'box_6') {
                            self.config.officialBox6Id = updated.id;
                            self.config.officialBox6Name = updated.name;
                            self.cachedProducts.forEach(prod => {
                                if (prod.id !== updated.id && prod.official_box_role === 'box_6') {
                                    prod.official_box_role = 'none';
                                    prod.is_official_box = false;
                                }
                            });
                        } else if (self.config.officialBox6Id === updated.id && updated.official_box_role !== 'box_6') {
                            self.config.officialBox6Id = 0;
                            self.config.officialBox6Name = '';
                        }

                        if (updated.official_box_role === 'box_12') {
                            self.config.officialBox12Id = updated.id;
                            self.config.officialBox12Name = updated.name;
                            self.cachedProducts.forEach(prod => {
                                if (prod.id !== updated.id && prod.official_box_role === 'box_12') {
                                    prod.official_box_role = 'none';
                                    prod.is_official_box = false;
                                }
                            });
                        } else if (self.config.officialBox12Id === updated.id && updated.official_box_role !== 'box_12') {
                            self.config.officialBox12Id = 0;
                            self.config.officialBox12Name = '';
                        }

                        // Mantener lista de cajas candidatas
                        if (self.config.boxCandidates && !self.config.boxCandidates.some(c => c.id === updated.id)) {
                            self.config.boxCandidates.push({ id: updated.id, name: updated.name });
                        }

                        self.filterProductsInDom();
                        $('#caja-modal-edit-product').fadeOut(150);
                        self.showToast(`✅ Producto "${updated.name}" modificado con éxito`);
                    } else {
                        $err.text(res.data && res.data.message ? res.data.message : 'Error al modificar el producto.').fadeIn(150);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('emp_caja_update_product error:', status, error, xhr.responseText);
                    let msg = 'Error de comunicación con el servidor.';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        msg = xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.data && parsed.data.message) {
                                msg = parsed.data.message;
                            }
                        } catch (e) {
                            if (xhr.status) {
                                msg += ` (${xhr.status}: ${xhr.statusText || 'Error interno'})`;
                            }
                        }
                    }
                    $err.text(msg).fadeIn(150);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.caja-btn-spinner').hide();
                    $btn.find('.caja-btn-text').text('💾 Guardar Modificaciones');
                }
            });
        },

        openStockModal: function(productId) {
            const self = this;
            const p = self.cachedProducts.find(item => item.id === productId);
            if (!p) return;

            $('#stock-modal-prod-id').val(p.id);
            $('#stock-modal-mode').val('add');
            $('#caja-edit-stock-error').hide();

            if (p.image_url) {
                $('#stock-modal-thumb').attr('src', p.image_url).show();
            } else {
                $('#stock-modal-thumb').hide();
            }
            $('#stock-modal-prod-title').text(p.name);
            $('#stock-modal-sku').text(p.sku ? `SKU: ${p.sku}` : 'Sin SKU');
            $('#stock-modal-cat').text(p.categories || 'Sin categoría');

            const currentQty = (p.stock_quantity_raw !== null && p.stock_quantity_raw !== undefined) ? parseInt(p.stock_quantity_raw) : 0;
            $('#stock-modal-current-qty').text(currentQty);
            $('#stock-modal-current-badge')
                .attr('class', 'caja-badge ' + (p.stock_badge || ''))
                .attr('title', p.bottleneck_item ? ('Limitado dinámicamente por: ' + p.bottleneck_item) : '')
                .text(p.stock_label || '');

            $('#calc-current-num').text(currentQty);
            $('#calc-incoming-num').text('+0');
            $('#calc-result-num').text(currentQty);

            $('#stock-incoming-qty').val('');
            $('#stock-direct-qty').val(currentQty);

            $('#stock-prod-price').val(p.regular_price || '');
            $('#stock-prod-sale-price').val(p.sale_price || '');
            $('#stock-prod-manage-stock').prop('checked', p.manage_stock !== false);

            // Reiniciar botones de modo a "Sumar ingreso"
            $('.caja-stock-mode-btn').removeClass('active');
            $('#btn-mode-add').addClass('active');
            $('#caja-panel-mode-add').show();
            $('#caja-panel-mode-set').hide();

            $('#caja-modal-edit-stock').fadeIn(200);
            setTimeout(() => {
                $('#stock-incoming-qty').focus();
            }, 100);
        },

        handleUpdateStock: function($form) {
            const self = this;
            const $btn = $('#caja-stock-modal-submit-btn');
            const $err = $('#caja-edit-stock-error');
            const originalText = $btn.find('.caja-btn-text').text();

            $err.hide();
            $btn.prop('disabled', true);
            $btn.find('.caja-btn-spinner').show();
            $btn.find('.caja-btn-text').text('Guardando en WooCommerce...');

            const productId = parseInt($('#stock-modal-prod-id').val());
            const mode = $('#stock-modal-mode').val();
            const incomingQty = $('#stock-incoming-qty').val();
            const directQty = $('#stock-direct-qty').val();

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_update_stock',
                    security: self.config.nonce,
                    product_id: productId,
                    mode: mode,
                    incoming_quantity: incomingQty,
                    direct_quantity: directQty,
                    regular_price: $('#stock-prod-price').val(),
                    sale_price: $('#stock-prod-sale-price').val(),
                    manage_stock: $('#stock-prod-manage-stock').is(':checked') ? 'yes' : 'no'
                },
                success: function(res) {
                    if (res.success && res.data && res.data.product) {
                        const updated = res.data.product;
                        const idx = self.cachedProducts.findIndex(p => p.id === updated.id);
                        if (idx !== -1) {
                            self.cachedProducts[idx] = updated;
                        }
                        self.filterProductsInDom();
                        $('#caja-modal-edit-stock').fadeOut(150);
                        self.showToast(`✅ Stock de "${updated.name}" actualizado a ${updated.stock_quantity}`);
                    } else {
                        $err.text(res.data && res.data.message ? res.data.message : 'Error al actualizar el stock').fadeIn(150);
                    }
                },
                error: function() {
                    $err.text('Error de conexión con el servidor al actualizar stock').fadeIn(150);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.caja-btn-spinner').hide();
                    $btn.find('.caja-btn-text').text(originalText);
                }
            });
        },

        handleCreateProduct: function($form) {
            const self = this;
            const $btn = $('#caja-modal-submit-btn');
            const $err = $('#caja-new-product-error');

            $err.hide();
            $btn.prop('disabled', true);
            $btn.find('.caja-btn-spinner').show();

            const selectedType = $('#new-prod-type').val() || 'simple';
            const productData = {
                product_type: selectedType,
                name: $('#new-prod-name').val(),
                regular_price: $('#new-prod-price').val(),
                sale_price: $('#new-prod-sale-price').val(),
                sku: $('#new-prod-sku').val(),
                category_id: $('#new-prod-category').val(),
                manage_stock: $('#new-prod-manage-stock').is(':checked') ? 'yes' : 'no',
                stock_quantity: $('#new-prod-stock-qty').val(),
                description: $('#new-prod-desc').val(),
                image_id: $('#new-prod-image-id').val(),
                featured: $('#new-prod-featured').is(':checked') ? 'yes' : 'no',
                catalog_visibility: $('#new-prod-visibility').val(),
                official_box_role: (selectedType === 'simple') ? ($('#new-prod-box-role').val() || 'none') : 'none',
                can_share_box: $('#new-prod-can-share-box').val() || 'none',
                share_box_max_alfajores: parseInt($('#new-prod-share-max-alfajores').val(), 10) || 6
            };

            const newRecIds = [];
            $('#new-prod-rec-list .caja-rec-chk:checked').each(function() {
                newRecIds.push(parseInt($(this).val(), 10));
            });
            productData.recommended_ids = newRecIds;

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_create_product',
                    security: self.config.nonce,
                    product: productData
                },
                success: function(res) {
                    if (res.success && res.data && res.data.product) {
                        const newProd = res.data.product;

                        // Actualizar referencias si se definió como caja oficial
                        if (newProd.official_box_role === 'box_6') {
                            self.config.officialBox6Id = newProd.id;
                            self.config.officialBox6Name = newProd.name;
                            self.cachedProducts.forEach(prod => {
                                if (prod.id !== newProd.id && prod.official_box_role === 'box_6') {
                                    prod.official_box_role = 'none';
                                    prod.is_official_box = false;
                                }
                            });
                        } else if (newProd.official_box_role === 'box_12') {
                            self.config.officialBox12Id = newProd.id;
                            self.config.officialBox12Name = newProd.name;
                            self.cachedProducts.forEach(prod => {
                                if (prod.id !== newProd.id && prod.official_box_role === 'box_12') {
                                    prod.official_box_role = 'none';
                                    prod.is_official_box = false;
                                }
                            });
                        }

                        if (self.config.boxCandidates && !self.config.boxCandidates.some(c => c.id === newProd.id)) {
                            self.config.boxCandidates.push({ id: newProd.id, name: newProd.name });
                        }

                        self.cachedProducts.unshift(newProd);
                        self.renderProducts(self.cachedProducts);
                        $('#caja-modal-new-product').fadeOut(150);
                        $form[0].reset();
                        $('#new-prod-image-id').val('');
                        $('#new-prod-thumb').hide().attr('src', '');
                        $('#new-prod-remove-img-btn').hide();
                        $('#new-prod-visibility').val('visible');
                        $('#new-prod-featured').prop('checked', false);
                        $('#new-prod-box-role').val('none').data('prev-val', 'none');
                        self.showToast('¡Producto creado exitosamente en WooCommerce!');
                    } else {
                        $err.text(res.data && res.data.message ? res.data.message : 'Error al crear producto').fadeIn(150);
                    }
                },
                error: function() {
                    $err.text('Error de conexión con el servidor').fadeIn(150);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.caja-btn-spinner').hide();
                }
            });
        },

        toggleFullScreen: function() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {
                    console.log('Error intentando entrar en pantalla completa:', err);
                });
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        },

        showToast: function(message) {
            const $toast = $('#caja-toast');
            $toast.text(message).fadeIn(200);
            setTimeout(function() {
                $toast.fadeOut(300);
            }, 3500);
        },

        escapeHtml: function(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        initSettingsTab: function() {
            const self = this;

            // Inicializar wpColorPicker en los campos de color si está disponible
            if ($.fn.wpColorPicker) {
                $('#tab-settings .caja-color-field').each(function() {
                    if (!$(this).data('wpWpColorPicker')) {
                        $(this).wpColorPicker();
                    }
                });
            }

            // Eventos de guardado
            $('#caja-settings-form').off('submit.cajaSettings').on('submit.cajaSettings', function(e) {
                e.preventDefault();
                self.saveSettings();
            });

            $('.caja-btn-save-settings').off('click.cajaSettings').on('click.cajaSettings', function(e) {
                e.preventDefault();
                self.saveSettings();
            });
        },

        saveSettings: function() {
            const self = this;
            const $form = $('#caja-settings-form');
            const $btns = $('.caja-btn-save-settings');
            const $feedback = $('#caja-settings-feedback');

            $btns.prop('disabled', true).addClass('is-loading');
            $feedback.removeClass('is-success is-error').text('Guardando configuración...').fadeIn(150);

            const formData = $form.serialize();

            $.ajax({
                url: batllieCajaConfig.ajaxUrl,
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(res) {
                    $btns.prop('disabled', false).removeClass('is-loading');
                    if (res && res.success) {
                        const msg = (res.data && res.data.message) ? res.data.message : 'Configuración guardada exitosamente.';
                        $feedback.addClass('is-success').text('✅ ' + msg);
                        self.showToast('✅ ' + msg);

                        if (res.data && res.data.settings) {
                            self.applyLiveSettings(res.data.settings);
                        }

                        setTimeout(function() {
                            $feedback.fadeOut(400);
                        }, 4000);
                    } else {
                        const err = (res && res.data && res.data.message) ? res.data.message : 'Error al guardar la configuración.';
                        $feedback.addClass('is-error').text('❌ ' + err);
                        self.showToast('❌ ' + err);
                    }
                },
                error: function() {
                    $btns.prop('disabled', false).removeClass('is-loading');
                    $feedback.addClass('is-error').text('❌ Error de comunicación con el servidor.');
                    self.showToast('❌ Error de comunicación con el servidor.');
                }
            });
        },

        applyLiveSettings: function(s) {
            if (!s) return;
            const root = document.documentElement;

            if (s.bg_color) root.style.setProperty('--caja-bg', s.bg_color);
            if (s.header_bg) root.style.setProperty('--caja-header-bg', s.header_bg);
            if (s.card_bg) root.style.setProperty('--caja-card-bg', s.card_bg);
            if (s.card_border) root.style.setProperty('--caja-card-border', s.card_border);
            if (s.text_color) root.style.setProperty('--caja-text', s.text_color);
            if (s.text_muted) root.style.setProperty('--caja-text-muted', s.text_muted);
            if (s.primary_color) root.style.setProperty('--caja-primary', s.primary_color);
            if (s.primary_hover) root.style.setProperty('--caja-primary-hover', s.primary_hover);
            if (s.btn_text) root.style.setProperty('--caja-btn-text', s.btn_text);
            if (s.status_pending) root.style.setProperty('--caja-status-pending', s.status_pending);
            if (s.status_processing) root.style.setProperty('--caja-status-processing', s.status_processing);
            if (s.status_enviando) root.style.setProperty('--caja-status-enviando', s.status_enviando);
            if (s.status_completed) root.style.setProperty('--caja-status-completed', s.status_completed);
            if (s.status_recibido_problema) root.style.setProperty('--caja-status-recibido-problema', s.status_recibido_problema);
            if (s.status_cancelled) root.style.setProperty('--caja-status-cancelled', s.status_cancelled);
            if (s.status_refunded) root.style.setProperty('--caja-status-refunded', s.status_refunded);

            if (s.poll_interval && typeof batllieCajaConfig !== 'undefined') {
                batllieCajaConfig.pollInterval = parseInt(s.poll_interval, 10) * 1000;
                if (this.pollTimer) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = setInterval(this.pollNewOrders.bind(this), batllieCajaConfig.pollInterval);
                }
            }

            if (s.shipping_slots && Array.isArray(s.shipping_slots)) {
                const currentVal = $('#caja-filter-slot').val();
                let opts = '<option value="all">Todos los horarios</option>';
                s.shipping_slots.forEach(function(slot) {
                    opts += `<option value="${slot}">🕒 Tanda ${slot} hs</option>`;
                });
                opts += '<option value="tomorrow">🕒 Tandas de mañana</option>';
                opts += '<option value="none">Sin horario asignado</option>';
                const $slotSelect = $('#caja-filter-slot');
                if ($slotSelect.length) {
                    $slotSelect.html(opts);
                    if ($slotSelect.find(`option[value="${currentVal}"]`).length) {
                        $slotSelect.val(currentVal);
                    } else {
                        $slotSelect.val('all');
                        self.currentSlotFilter = 'all';
                    }
                }
            }
        }
    };

    window.BatllieCajaApp = App;

    $(document).ready(function() {
        App.init();
    });

})(jQuery);
