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

        isOrderVentaCaja: function(order) {
            if (!order) return false;
            // Si el pedido está en cualquier estado activo de preparación o despacho, NUNCA es venta caja cerrada
            if (['pending', 'processing', 'enviando', 'on-hold', 'recibido-problema'].indexOf(order.status) !== -1) {
                return false;
            }
            // Si tiene costo de envío, dirección de envío o método de despacho
            const hasShipping = Boolean(
                (parseFloat(order.shipping_total) > 0) ||
                (order.pos_mode === 'pedido_despachar') ||
                (order.delivery_type === 'con_envio') ||
                (order.delivery_type === 'retiro_local') ||
                (order.is_local_pickup === true || order.is_local_pickup === 'yes') ||
                (order.address && order.address.length > 0 && order.pos_mode !== 'compra_realizada')
            );
            if (hasShipping) {
                return false;
            }
            // Solo es venta caja cerrada si es compra_realizada o in_situ
            return Boolean(order.pos_mode === 'compra_realizada' || order.delivery_type === 'in_situ' || (order.is_venta_caja && order.pos_mode !== 'pedido_despachar'));
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
                if (JSON.stringify(o.dynamic_packaging || []) !== JSON.stringify(n.dynamic_packaging || [])) return true;
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
            this.currentGastroSectorFilter = 'all';
            this.gastroSector = (this.config && this.config.gastroSectors && this.config.gastroSectors[0]) || 'Barra Principal';
            this.gastroTable = '';
            this.cashTendered = 0;
            this.cashChange = 0;
            this.cashBillsCount = { 100:0, 200:0, 500:0, 1000:0, 2000:0, 5000:0, 10000:0, 20000:0 };
            this.mgmtGridCols = localStorage.getItem('caja_mgmt_grid_cols') || '2';
            this.tipPercent = 0;
            this.tipAmount = 0;
            this.cachedPackingThemes = (this.config && this.config.packingThemes) || [];
            this.packagingProducts = (this.config && this.config.packagingProducts) || (window.batllieCajaConfig && window.batllieCajaConfig.packagingProducts) || [];
            this.packagingCandidates = (this.config && this.config.packagingCandidates) || (window.batllieCajaConfig && window.batllieCajaConfig.packagingCandidates) || [];
            this.bindEvents();
            this.initPWA();

            if (this.config.isUserLoggedIn) {
                this.initDashboard();
                this.initRegisterSessionUI();

                // Soportar navegación directa por URL / Hash (#productos o ?tab=productos, #configuracion, #empleados, #cajas)
                const urlParams = new URLSearchParams(window.location.search);
                const hash = window.location.hash.toLowerCase();
                const perms = this.config.currentUserPermissions || {};

                if ((hash === '#cajas' || hash === '#control-cajas' || hash === '#tab-registers' || urlParams.get('tab') === 'cajas' || urlParams.get('tab') === 'registers') && (perms.is_admin || perms.can_manage_registers)) {
                    this.switchTab('tab-registers');
                } else if ((hash === '#empleados' || hash === '#tab-employees' || urlParams.get('tab') === 'empleados' || urlParams.get('tab') === 'employees') && perms.is_admin) {
                    this.switchTab('tab-employees');
                } else if ((hash === '#configuracion' || hash === '#tab-settings' || urlParams.get('tab') === 'configuracion' || urlParams.get('tab') === 'settings') && perms.is_admin) {
                    this.switchTab('tab-settings');
                } else if ((hash === '#productos' || hash === '#tab-products' || urlParams.get('tab') === 'productos' || urlParams.get('tab') === 'products') && perms.can_view_products !== false) {
                    this.switchTab('tab-products');
                } else if (hash === '#venta-caja' || hash === '#pos' || hash === '#tab-pos-sale' || urlParams.get('tab') === 'pos' || urlParams.get('tab') === 'venta-caja' || perms.can_view_orders === false) {
                    this.switchTab('tab-pos-sale');
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

            // Selector desplegable rápido de pantallas en cabecera
            $(document).on('change input blur', '#caja-screen-select', function() {
                const target = $(this).val();
                if (target) {
                    const selectedText = $(this).find(`option[value="${target}"]`).text();
                    if (selectedText) {
                        $('#caja-screen-select-label').text(selectedText);
                    }
                    self.switchTab(target);
                }
            });

            // Al tocar o clickear en el contenedor del selector, abrir inmediatamente el selector nativo
            $(document).on('click touchstart', '#caja-screen-switcher-wrap', function(e) {
                const sel = document.getElementById('caja-screen-select');
                if (sel && e.target !== sel) {
                    sel.focus();
                    if (typeof sel.showPicker === 'function') {
                        try { sel.showPicker(); } catch(err) {}
                    }
                }
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

            // Filtro Desplegable de Sector / Mesa Gastronómico
            $(document).on('change', '#caja-filter-gastro-sector', function() {
                self.currentGastroSectorFilter = $(this).val();
                self.applyFilters();
            });

            // Botones de impresión de comanda y ticket en tarjetas de pedidos en vivo
            $(document).on('click', '.caja-btn-print-comanda', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const orderId = $(this).data('order-id');
                const order = (self.cachedOrders || []).find(o => o.id == orderId);
                if (order) {
                    self.printGastroTicket(order, 'comanda');
                }
            });

            $(document).on('click', '.caja-btn-print-ticket', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const orderId = $(this).data('order-id');
                const order = (self.cachedOrders || []).find(o => o.id == orderId);
                if (order) {
                    self.printGastroTicket(order, 'ticket');
                }
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

                // Determinar si es retiro en el local para la etiqueta de Enviando
                const cachedOrder = self.cachedOrders.find(o => o.id == orderId);
                const isPickup = cachedOrder && (cachedOrder.is_local_pickup === true || cachedOrder.is_local_pickup === 'yes');
                const enviandoLabel = isPickup ? 'Esperando a ser retirado' : 'Enviando';

                // Bloqueo de pasos posteriores si el pago no fue aprobado
                if (isApproved) {
                    $mainDropdown.find('option[value="processing"]').prop('disabled', false).text('En preparación');
                    $mainDropdown.find('option[value="enviando"]').prop('disabled', false).text(enviandoLabel);
                    $mainDropdown.find('option[value="completed"]').prop('disabled', false).text('Completado');
                    $shipSelect.find('option').prop('disabled', false);
                    if ($mainDropdown.val() === 'enviando' || $mainDropdown.val() === 'on-hold') {
                        $controlBtnWrap.show();
                    } else {
                        $controlBtnWrap.hide();
                    }
                } else {
                    $mainDropdown.find('option[value="processing"]').prop('disabled', true).text('En preparación 🔒 (requiere pago)');
                    $mainDropdown.find('option[value="enviando"]').prop('disabled', true).text(`${enviandoLabel} 🔒 (requiere pago)`);
                    $mainDropdown.find('option[value="completed"]').prop('disabled', true).text('Completado 🔒 (requiere pago)');
                    $shipSelect.find('option:not([value="no_gestionado"])').prop('disabled', true);
                    $controlBtnWrap.hide();
                }

                // Actualizar clases de color status-pay-*
                $(this).removeClass('status-pay-pagado status-pay-pendiente status-pay-efectivo_entrega status-pay-pendiente_devolucion status-pay-devolucion')
                       .addClass('status-pay-' + val);

                // Actualizar objeto en caché
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

                const isVentaMostrador = !!(cachedOrder && (cachedOrder.is_venta_mostrador || (cachedOrder.customer_name && cachedOrder.customer_name.toLowerCase().includes('venta mostrador'))));
                const isVentaCaja = self.isOrderVentaCaja(cachedOrder);

                if (isVentaCaja) {
                    select.removeClass('status-bg-pending status-bg-processing status-bg-enviando status-bg-completed status-bg-recibido-problema status-bg-cancelled status-bg-refunded status-bg-on-hold status-bg-failed is-verified-primary');
                    select.addClass('status-bg-' + newStatus);
                    $grid.hide();
                    $payBox.hide();
                    $pkgBox.hide();
                    $shipBox.hide();
                    $controlBtnWrap.hide();
                    self.updateOrderStatus(orderId, newStatus, select, { restore_box_stock: 'yes' });
                    return;
                }

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
                const isPickup = cachedOrder && (cachedOrder.is_local_pickup === true || cachedOrder.is_local_pickup === 'yes');

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
                    setVisible($shipBox, !isPickup);
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
                    setVisible($shipBox, !isPickup);
                    $controlBtnWrap.hide();
                    // Sincronización: selector de la moto se pone en Recibido sin problemas
                    $shipSelect.val('entregado');
                    $shipSelect.removeClass('status-ship-no_gestionado status-ship-esperando_repartidor status-ship-enviando status-ship-demorado status-ship-en_puerta status-ship-entregado status-ship-entregado_problemas')
                               .addClass('status-ship-entregado');
                } else if (newStatus === 'recibido-problema') {
                    setVisible($payBox, false);
                    setVisible($pkgBox, false);
                    setVisible($shipBox, !isPickup);
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
            // EVENTOS DE CONTROL DE EMPAQUE DINÁMICO (Con stock WooCommerce)
            // -------------------------------------------------------------
            // Abrir vista de edición de empaque
            $(document).on('click', '.caja-btn-modify-pkg', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const order = self.cachedOrders.find(o => o.id == orderId);
                if (order && (order.control_pedido_verified === true || order.control_pedido_verified === 'yes')) {
                    alert('⚠️ Este pedido ya fue verificado y no se puede modificar su empaque.');
                    return;
                }
                if (order) {
                    (self.packagingProducts || []).forEach(pkg => {
                        const assigned = (order.dynamic_packaging || []).find(d => d.id == pkg.id);
                        $(`#pkg-dyn-input-${pkg.id}-${orderId}`).val(assigned ? assigned.qty : 0);
                    });
                }
                $(`#caja-pkg-summary-${orderId}`).hide();
                $(`#caja-pkg-edit-${orderId}`).slideDown(150);
            });

            // Cancelar edición de empaque
            $(document).on('click', '.btn-cancel-pkg-dyn', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const order = self.cachedOrders.find(o => o.id == orderId);
                if (order) {
                    // Restaurar valores previos
                    (self.packagingProducts || []).forEach(pkg => {
                        const assigned = (order.dynamic_packaging || []).find(d => d.id == pkg.id);
                        $(`#pkg-dyn-input-${pkg.id}-${orderId}`).val(assigned ? assigned.qty : 0);
                    });
                }
                $(`#caja-pkg-edit-${orderId}`).slideUp(150, function() {
                    $(`#caja-pkg-summary-${orderId}`).show();
                });
            });

            // Stepper de empaque dinámico (+ / - con validación de stock)
            $(document).on('click', '.btn-step-dyn-pkg', function(e) {
                e.preventDefault();
                const pkgId = $(this).data('pkg-id');
                const action = $(this).data('action');
                const orderId = $(this).data('order-id');
                const order = self.cachedOrders.find(o => o.id == orderId);
                if (order && (order.control_pedido_verified === true || order.control_pedido_verified === 'yes')) {
                    return;
                }

                const pkg = (self.packagingProducts || []).find(p => p.id == pkgId);
                const $input = $(`#pkg-dyn-input-${pkgId}-${orderId}`);
                let val = parseInt($input.val(), 10) || 0;

                if (action === 'plus') {
                    // Validar stock de WooCommerce
                    if (pkg) {
                        if (!pkg.in_stock || (pkg.stock !== null && pkg.stock <= 0)) {
                            alert(`⚠️ "${pkg.name}" está sin stock en WooCommerce y no se puede seleccionar.`);
                            return;
                        }
                        if (pkg.managing_stock && pkg.stock !== null && (val + 1) > pkg.stock) {
                            alert(`⚠️ No puedes seleccionar más unidades que las existentes en WooCommerce (Stock disponible: ${pkg.stock}).`);
                            return;
                        }
                    }
                    val++;
                } else if (action === 'minus') {
                    val = Math.max(0, val - 1);
                }
                $input.val(val);
            });

            // Guardar cambios en empaque dinámico
            $(document).on('click', '.btn-save-pkg-dyn', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $btn = $(this);

                // Ocultar vista de edición inmediatamente para evitar que openPackagingEditIds la vuelva a abrir
                $(`#caja-pkg-edit-${orderId}`).hide();
                $(`#caja-pkg-summary-${orderId}`).show();

                // Si el usuario escribió un nuevo empaque pero no tocó "+ Crear y agregar", auto-crearlo y asignarlo
                const $nameInput = $(`#caja-new-pkg-name-${orderId}`);
                const pendingNewName = $nameInput.length ? $.trim($nameInput.val()) : '';
                if (pendingNewName) {
                    const pendingStock = parseInt($(`#caja-new-pkg-stock-${orderId}`).val(), 10) || 50;
                    $btn.prop('disabled', true).text('Creando y guardando...');
                    self.addPackagingItem(0, pendingNewName, pendingStock, orderId, function(success) {
                        $btn.prop('disabled', false).text('💾 Guardar empaque');
                        $nameInput.val('');
                        if (!success) {
                            $(`#caja-pkg-edit-${orderId}`).show();
                            $(`#caja-pkg-summary-${orderId}`).hide();
                        }
                    });
                    return;
                }

                // Si el usuario seleccionó un producto existente pero no tocó "+ Vincular", auto-vincularlo y asignarlo
                const $selExist = $(`#caja-sel-exist-${orderId}`);
                const pendingPid = $selExist.length ? parseInt($selExist.val(), 10) : 0;
                if (pendingPid) {
                    $btn.prop('disabled', true).text('Vinculando y guardando...');
                    self.addPackagingItem(pendingPid, '', 0, orderId, function(success) {
                        $btn.prop('disabled', false).text('💾 Guardar empaque');
                        $selExist.val('');
                        if (!success) {
                            $(`#caja-pkg-edit-${orderId}`).show();
                            $(`#caja-pkg-summary-${orderId}`).hide();
                        }
                    });
                    return;
                }

                const items = [];
                let totalQty = 0;
                $(`.pkg-dyn-field-${orderId}`).each(function() {
                    const pid = $(this).data('pkg-id');
                    const qty = parseInt($(this).val(), 10) || 0;
                    items.push({ id: pid, qty: qty });
                    totalQty += qty;
                });

                if (totalQty === 0) {
                    $(`#caja-pkg-edit-${orderId}`).show();
                    $(`#caja-pkg-summary-${orderId}`).hide();
                    alert('⚠️ Por favor indica la cantidad del empaque usando el botón (+) antes de guardar.');
                    return;
                }

                $btn.prop('disabled', true).text('Guardando...');

                self.saveDynamicPackaging(orderId, items, function(success) {
                    $btn.prop('disabled', false).text('💾 Guardar empaque');
                    if (!success) {
                        $(`#caja-pkg-edit-${orderId}`).show();
                        $(`#caja-pkg-summary-${orderId}`).hide();
                    }
                });
            });

            // Toggle acordeón "+ Agregar nuevo empaque"
            $(document).on('click', '.btn-toggle-add-pkg', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $content = $(`#caja-pkg-add-content-${orderId}`);
                const $arrow = $(this).find('.caja-thematic-toggle-arrow');
                $(this).toggleClass('active');
                $arrow.toggleClass('open');
                $content.stop(true, true).slideToggle(160);
            });

            // Vincular producto existente de WooCommerce
            $(document).on('click', '.btn-link-existing-pkg', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $sel = $(`#caja-sel-exist-${orderId}`);
                const pid = parseInt($sel.val(), 10);
                if (!pid) {
                    alert('⚠️ Por favor selecciona un producto de WooCommerce para vincular.');
                    return;
                }
                const $btn = $(this);
                $btn.prop('disabled', true).text('Vinculando...');
                self.addPackagingItem(pid, '', 0, orderId, function() {
                    $btn.prop('disabled', false).text('+ Vincular');
                    $sel.val('');
                });
            });

            // Crear nuevo empaque oficial en WooCommerce en el acto
            $(document).on('click', '.btn-create-dyn-pkg', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const $nameInput = $(`#caja-new-pkg-name-${orderId}`);
                const $stockInput = $(`#caja-new-pkg-stock-${orderId}`);
                const name = $.trim($nameInput.val());
                const stock = parseInt($stockInput.val(), 10) || 50;

                if (!name) {
                    alert('⚠️ Por favor escribe el nombre de la nueva caja o empaque.');
                    $nameInput.focus();
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text('Creando...');
                self.addPackagingItem(0, name, stock, orderId, function(success) {
                    $btn.prop('disabled', false).text('+ Crear y agregar');
                    if (success) {
                        $nameInput.val('');
                    }
                });
            });

            // Eliminar producto de empaque oficial
            $(document).on('click', '.btn-del-dyn-pkg', function(e) {
                e.preventDefault();
                const pkgId = $(this).data('pkg-id');
                const pkgName = $(this).data('pkg-name') || 'este empaque';

                if (confirm(`¿Estás seguro de quitar "${pkgName}" del listado oficial de empaques?`)) {
                    self.deletePackagingItem(pkgId);
                }
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

            // Acción 2 permitida: Iniciar envío / Entregar pedido tras chequear el 100% de los checkboxes
            $(document).on('click', '#caja-btn-iniciar-envio', function(e) {
                e.preventDefault();
                const orderId = self.currentControlOrderId;
                if (!orderId) return;

                const isPickup = self.isCurrentControlPickup;
                const total = $('.caja-control-chk').length;
                const checked = $('.caja-control-chk:checked').length;
                if (checked < total || total === 0) {
                    alert(isPickup ? 'Debes chequear todos los productos y empaques antes de entregar el pedido.' : 'Debes chequear todos los productos y empaques antes de iniciar el envío.');
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text(isPickup ? 'Entregando pedido...' : 'Iniciando envío...');

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
                        $btn.html(isPickup ? '<span>🏪 Entregar pedido</span>' : '<span>🚀 Iniciar envío</span>');

                        if (res.success) {
                            const order = self.cachedOrders.find(o => o.id == orderId);
                            if (order) {
                                order.control_pedido_verified = true;
                                if (isPickup) {
                                    order.status = 'completed';
                                    order.shipping_status = 'entregado';
                                } else {
                                    order.status = 'enviando';
                                    order.shipping_status = 'enviando';
                                }
                            }
                            self.applyFilters();
                            if (isPickup) {
                                self.showToast('🏪 Control de pedido completado al 100%. ¡Pedido entregado al cliente con éxito!');
                            } else {
                                self.showToast('🚀 Control de pedido completado al 100%. Repartidor enviando.');
                            }
                        } else {
                            alert(res.data && res.data.message ? res.data.message : 'Error al verificar control de pedido.');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).html(isPickup ? '<span>🏪 Entregar pedido</span>' : '<span>🚀 Iniciar envío</span>');
                        alert('Error de conexión al procesar el pedido.');
                    }
                });
            });

            // -------------------------------------------------------------
            // EVENTOS DE EXPERIENCIA BATLLIÉ Y HISTORIAL DE CLIENTE
            // -------------------------------------------------------------
            // Checkbox para incluir tarjeta de Experiencia Batllié
            $(document).on('change', '.caja-exp-card-checkbox', function() {
                const orderId = $(this).data('order-id');
                const isChecked = $(this).is(':checked');
                const $textWrap = $(`#caja-exp-text-container-${orderId}`);

                if (isChecked) {
                    $textWrap.stop(true, true).slideDown(150);
                } else {
                    $textWrap.stop(true, true).slideUp(150);
                }

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'emp_caja_toggle_experiencia',
                        security: self.config.nonce,
                        order_id: orderId,
                        included: isChecked ? 'yes' : 'no'
                    },
                    success: function(res) {
                        if (res.success) {
                            const ord = self.cachedOrders.find(o => o.id == orderId);
                            if (ord && ord.experiencia_data) {
                                ord.experiencia_data.card_included = isChecked;
                            }
                            self.showToast(isChecked ? '✨ Tarjeta de cortesía incluida.' : 'Tarjeta de cortesía desmarcada.');
                        }
                    }
                });
            });

            // Guardar dedicatoria de tarjeta de experiencia
            $(document).on('click', '.caja-exp-btn-save-text', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const text = $(`#caja-exp-text-${orderId}`).val();
                const $feedback = $(`#caja-exp-feedback-${orderId}`);
                const $btn = $(this);

                $btn.prop('disabled', true).text('Guardando...');
                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'emp_caja_save_experiencia_texto',
                        security: self.config.nonce,
                        order_id: orderId,
                        text: text
                    },
                    success: function(res) {
                        $btn.prop('disabled', false).text('Guardar');
                        if (res.success) {
                            const ord = self.cachedOrders.find(o => o.id == orderId);
                            if (ord && ord.experiencia_data) {
                                ord.experiencia_data.card_text = text;
                            }
                            $feedback.text('✓ Guardado').fadeIn(150);
                            setTimeout(() => $feedback.fadeOut(300), 2500);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Guardar');
                    }
                });
            });

            // Clic en el nombre del cliente -> Abrir Historial de Pedidos y Experiencia Batllié
            $(document).on('click', '.caja-customer-history-trigger', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                const customerName = $(this).data('customer-name') || 'Cliente';
                self.openCustomerHistoryModal(orderId, customerName);
            });

            // Cerrar modal de Historial de Cliente
            $(document).on('click', '#caja-cust-hist-close-btn, #caja-modal-customer-history .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-customer-history').fadeOut(150);
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

            // Switcher de Grilla de Control de Productos (2 o 3 columnas)
            $(document).on('click', '.caja-mgmt-layout-btn', function(e) {
                e.preventDefault();
                $('.caja-mgmt-layout-btn').removeClass('active');
                $(this).addClass('active');

                const cols = String($(this).data('cols') || '2');
                self.mgmtGridCols = cols;
                localStorage.setItem('caja_mgmt_grid_cols', cols);

                const $grid = $('#caja-products-grid');
                $grid.removeClass('is-cols-2 is-cols-3').addClass('is-cols-' + cols);
            });

            $(window).on('resize.cajaMgmt', function() {
                const isMobile = window.innerWidth <= 768;
                const $grid = $('#caja-products-grid');
                if (isMobile) {
                    $grid.removeClass('is-cols-3').addClass('is-cols-2');
                    $('.caja-mgmt-layout-btn').removeClass('active');
                    $('.caja-mgmt-layout-btn[data-cols="2"]').addClass('active');
                } else {
                    const c = self.mgmtGridCols || '2';
                    $grid.removeClass('is-cols-2 is-cols-3').addClass('is-cols-' + c);
                    $('.caja-mgmt-layout-btn').removeClass('active');
                    $(`.caja-mgmt-layout-btn[data-cols="${c}"]`).addClass('active');
                }
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

            // ==========================================
            // EVENTOS: APERTURA Y CIERRE DE CAJA (ARQUEO)
            // ==========================================
            $(document).on('click', '#caja-pos-btn-open-register-top', function(e) {
                e.preventDefault();
                self.openRegisterModal();
            });

            $(document).on('click', '#caja-open-reg-close-btn, #caja-modal-open-register .caja-modal-backdrop', function(e) {
                e.preventDefault();
                self.closeRegisterModal();
            });

            $(document).on('click', '.caja-open-cash-chip', function(e) {
                e.preventDefault();
                const amt = parseFloat($(this).data('amount')) || 0;
                $('#caja-open-reg-cash').val(amt);
            });

            $(document).on('submit', '#caja-open-reg-form', function(e) {
                e.preventDefault();
                self.submitOpenRegister();
            });

            $(document).on('click', '#caja-pos-btn-close-register', function(e) {
                e.preventDefault();
                self.openCloseRegisterModal();
            });

            $(document).on('click', '#caja-close-reg-close-btn, #caja-close-reg-cancel-btn, #caja-modal-close-register .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-close-register').fadeOut(150);
            });

            $(document).on('click', '.caja-btn-qty-bill', function(e) {
                e.preventDefault();
                const denom = $(this).data('denom');
                const action = $(this).data('action');
                const $input = $(`.caja-bill-input[data-denom="${denom}"]`);
                let val = parseInt($input.val(), 10) || 0;
                if (action === 'plus') {
                    val++;
                } else if (action === 'minus') {
                    val = Math.max(0, val - 1);
                }
                $input.val(val);
                self.recalcCloseRegisterArqueo();
            });

            $(document).on('input change', '.caja-bill-input', function() {
                self.recalcCloseRegisterArqueo();
            });

            $(document).on('input change', '#caja-close-reg-manual-cash', function() {
                self.recalcCloseRegisterArqueo();
            });

            $(document).on('click', '#caja-close-reg-clear-bills', function(e) {
                e.preventDefault();
                $('.caja-bill-input').val(0);
                $('#caja-close-reg-manual-cash').val('');
                self.recalcCloseRegisterArqueo();
            });

            $(document).on('click', '#caja-close-reg-confirm-btn', function(e) {
                e.preventDefault();
                self.submitCloseRegister();
            });

            // Solicitudes de Apertura y Cierre de Caja (Cajero y Admin)
            $(document).on('click', '#caja-btn-check-pending-reg', function(e) {
                e.preventDefault();
                self.checkUserRegisterRequest();
            });

            $(document).on('click', '#caja-btn-cancel-pending-reg', function(e) {
                e.preventDefault();
                self.cancelUserRegisterRequest();
            });

            $(document).on('click', '#caja-btn-reload-requests', function(e) {
                e.preventDefault();
                self.loadPendingRegisterRequests();
            });

            $(document).on('click', '.caja-btn-approve-request', function(e) {
                e.preventDefault();
                const requestId = $(this).data('id');
                const type = $(this).data('type');
                const user = $(this).data('user') || 'el cajero';
                if (!window.confirm(`¿Aprobar ${type === 'open' ? 'la apertura' : 'el cierre'} de caja solicitada por ${user}?`)) {
                    return;
                }
                self.approveRegisterRequest(requestId);
            });

            $(document).on('click', '.caja-btn-reject-request', function(e) {
                e.preventDefault();
                const requestId = $(this).data('id');
                const type = $(this).data('type');
                const user = $(this).data('user') || 'el cajero';
                $('#caja-reject-request-id').val(requestId);
                $('#caja-reject-request-summary').html(`Rechazar <strong>${type === 'open' ? 'Apertura' : 'Cierre'}</strong> solicitado por <strong>${user}</strong>.`);
                $('#caja-reject-request-reason').val('');
                $('#caja-modal-reject-request').fadeIn(150);
            });

            $(document).on('click', '#caja-close-modal-reject-request, #caja-btn-cancel-reject-request, #caja-modal-reject-request .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-reject-request').fadeOut(150);
            });

            $(document).on('click', '#caja-btn-confirm-reject-request', function(e) {
                e.preventDefault();
                const reqId = $('#caja-reject-request-id').val();
                const reason = $.trim($('#caja-reject-request-reason').val() || '');
                if (!reason) {
                    window.alert('Por favor indica el motivo del rechazo para que el cajero sepa qué corregir.');
                    $('#caja-reject-request-reason').focus();
                    return;
                }
                self.rejectRegisterRequest(reqId, reason);
            });

            // ==========================================
            // EVENTOS: CONTROL DE CAJAS (ADMINISTRADOR Y ENCARGADO)
            // ==========================================
            $(document).on('click', '.caja-collapsible-trigger', function(e) {
                if ($(e.target).closest('button, a, input, select, textarea').length) return;
                e.preventDefault();
                const $trigger = $(this);
                const $section = $trigger.closest('.caja-collapsible-section');
                const targetId = $trigger.data('target');
                const $body = targetId ? $(targetId) : $section.find('.caja-collapsible-body');

                $body.stop(true, true).slideToggle(200, function() {
                    if ($body.is(':visible')) {
                        $section.removeClass('is-collapsed').addClass('is-open');
                    } else {
                        $section.removeClass('is-open').addClass('is-collapsed');
                    }
                });
            });

            $(document).on('click', '#caja-reg-btn-refresh, .caja-reg-btn-refresh-live, #caja-reg-btn-apply-filters', function(e) {
                e.preventDefault();
                const $btn = $(this);
                $btn.addClass('is-spinning');
                setTimeout(function() {
                    $btn.removeClass('is-spinning');
                }, 800);
                self.loadPendingRegisterRequests();
                self.loadMoneyBoxes();
                self.loadRegistersHistory();
            });

            // Gestión de Cajas de Dinero y Puestos
            $(document).on('click', '#caja-btn-open-new-box-modal, #caja-btn-empty-new-box', function(e) {
                e.preventDefault();
                self.openMoneyBoxModal();
            });

            $(document).on('click', '.caja-btn-edit-money-box', function(e) {
                e.preventDefault();
                const boxId = $(this).data('id');
                self.openMoneyBoxModal(boxId);
            });

            $(document).on('click', '.caja-btn-delete-money-box', function(e) {
                e.preventDefault();
                const boxId = $(this).data('id');
                const boxName = $(this).data('name') || 'esta caja';
                if (!confirm(`¿Estás seguro de que deseas eliminar la caja de dinero "${boxName}"? Sus puestos asignados quedarán liberados.`)) {
                    return;
                }

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_delete_money_box',
                        security: self.config.nonce,
                        box_id: boxId
                    },
                    success: function(res) {
                        if (res && res.success) {
                            self.showToast(res.data.message || 'Caja eliminada correctamente.', 'success');
                            self.cachedMoneyBoxes = res.data.boxes || [];
                            self.assignedPuestosMap = res.data.assigned_puestos_map || {};
                            self.renderMoneyBoxes();
                        } else {
                            self.showToast((res && res.data && res.data.message) ? res.data.message : 'Error al eliminar caja.', 'error');
                        }
                    },
                    error: function() {
                        self.showToast('Error de conexión al eliminar caja.', 'error');
                    }
                });
            });

            $(document).on('click', '#caja-mb-modal-close, #caja-mb-btn-cancel, #caja-modal-money-box .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-money-box').fadeOut(150);
            });

            // Agregar nuevo puesto desde el modal
            $(document).on('click', '#caja-mb-btn-add-new-puesto', function(e) {
                e.preventDefault();
                const $input = $('#caja-mb-new-puesto-name');
                const puestoName = ($input.val() || '').trim();
                if (!puestoName) {
                    alert('Por favor escribe el nombre del mostrador o puesto.');
                    return;
                }

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_add_puesto',
                        security: self.config.nonce,
                        puesto_name: puestoName
                    },
                    success: function(res) {
                        if (res && res.success) {
                            $input.val('');
                            self.availablePuestos = res.data.available_puestos || [];
                            const currentBoxId = $('#caja-mb-id').val();
                            const currentBox = currentBoxId ? (self.cachedMoneyBoxes || []).find(b => b.id === currentBoxId) : null;
                            self.renderModalPuestosChecklist(currentBox);
                            // Marcar el recién agregado
                            $(`#caja-mb-puestos-container input[value="${puestoName}"]`).prop('checked', true);
                            self.showToast(`Puesto "${puestoName}" agregado.`, 'success');
                        } else {
                            alert((res && res.data && res.data.message) ? res.data.message : 'Error al agregar puesto.');
                        }
                    },
                    error: function() {
                        alert('Error de conexión al agregar puesto.');
                    }
                });
            });

            // Guardar Caja de Dinero
            $(document).on('submit', '#caja-money-box-form', function(e) {
                e.preventDefault();
                const $form = $(this);
                const $btn = $('#caja-mb-btn-save');
                const origHtml = $btn.html();

                const boxId = $('#caja-mb-id').val();
                const name = ($('#caja-mb-name').val() || '').trim();
                const code = ($('#caja-mb-code').val() || '').trim();
                const notes = ($('#caja-mb-notes').val() || '').trim();
                const status = $('#caja-mb-status').val() || 'active';

                const assignedPuestos = [];
                $('input[name="caja_mb_puestos[]"]:checked').each(function() {
                    assignedPuestos.push($(this).val());
                });

                if (!name) {
                    $('#caja-mb-alert-error').show().text('El nombre de la caja de dinero es obligatorio.');
                    return;
                }

                $('#caja-mb-alert-error').hide().text('');
                $btn.prop('disabled', true).html('Guardando...');

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_save_money_box',
                        security: self.config.nonce,
                        box: {
                            id: boxId,
                            name: name,
                            code: code,
                            notes: notes,
                            status: status,
                            assigned_puestos: assignedPuestos
                        }
                    },
                    success: function(res) {
                        $btn.prop('disabled', false).html(origHtml);
                        if (res && res.success) {
                            $('#caja-modal-money-box').fadeOut(150);
                            self.showToast(res.data.message || 'Caja guardada correctamente.', 'success');
                            self.cachedMoneyBoxes = res.data.boxes || [];
                            self.assignedPuestosMap = res.data.assigned_puestos_map || {};
                            self.availablePuestos = res.data.available_puestos || [];
                            self.renderMoneyBoxes();
                        } else {
                            const err = (res && res.data && res.data.message) ? res.data.message : 'Error al guardar la caja de dinero.';
                            $('#caja-mb-alert-error').show().text(err);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).html(origHtml);
                        $('#caja-mb-alert-error').show().text('Error de conexión con el servidor al guardar la caja.');
                    }
                });
            });

            // Gestión de Control de Dinero y Movimientos
            $(document).on('click', '.caja-btn-control-cash', function(e) {
                e.preventDefault();
                const boxId = $(this).data('id');
                self.openCashControlModal(boxId);
            });

            $(document).on('click', '.caja-ctrl-type-btn', function(e) {
                e.preventDefault();
                $('.caja-ctrl-type-btn').removeClass('active');
                $(this).addClass('active');
                const t = $(this).data('type');
                $('#caja-ctrl-selected-type').val(t);
                self.updateCashControlPreview();
            });

            $(document).on('click', '.caja-ctrl-preset-btn', function(e) {
                e.preventDefault();
                const addVal = parseFloat($(this).data('val')) || 0;
                const currentVal = parseFloat($('#caja-ctrl-amount').val()) || 0;
                $('#caja-ctrl-amount').val((currentVal + addVal).toFixed(2));
                self.updateCashControlPreview();
            });

            $(document).on('input keyup change', '#caja-ctrl-amount', function() {
                self.updateCashControlPreview();
            });

            $(document).on('click', '#caja-ctrl-modal-close, #caja-ctrl-btn-cancel, #caja-modal-cash-control .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-cash-control').fadeOut(150);
            });

            $(document).on('submit', '#caja-cash-control-form', function(e) {
                e.preventDefault();
                const boxId = $('#caja-ctrl-box-id').val();
                const type = $('#caja-ctrl-selected-type').val();
                const amount = parseFloat($('#caja-ctrl-amount').val()) || 0;
                const concept = ($('#caja-ctrl-concept').val() || '').trim();
                const notes = ($('#caja-ctrl-notes').val() || '').trim();

                if (amount <= 0 && type !== 'adjustment') {
                    $('#caja-ctrl-alert-error').show().text('Por favor ingresa un monto válido mayor a 0.');
                    return;
                }
                if (!concept) {
                    $('#caja-ctrl-alert-error').show().text('Debes ingresar un motivo o concepto para este movimiento.');
                    return;
                }

                const $btn = $('#caja-ctrl-btn-save');
                const origHtml = $btn.html();
                $btn.prop('disabled', true).html('<span>⏳</span> Guardando...');

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_record_cash_movement',
                        security: self.config.nonce,
                        cash_box_id: boxId,
                        type: type,
                        amount: amount,
                        concept: concept,
                        notes: notes
                    },
                    success: function(res) {
                        $btn.prop('disabled', false).html(origHtml);
                        if (res && res.success) {
                            $('#caja-modal-cash-control').fadeOut(150);
                            self.showToast(res.data.message || 'Movimiento registrado con éxito.', 'success');
                            if (res.data.boxes) {
                                self.cachedMoneyBoxes = res.data.boxes;
                                self.renderMoneyBoxes();
                            } else {
                                self.loadMoneyBoxes();
                            }
                            self.loadRegistersHistory();
                        } else {
                            const err = (res && res.data && res.data.message) ? res.data.message : 'Error al registrar movimiento.';
                            $('#caja-ctrl-alert-error').show().text(err);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).html(origHtml);
                        $('#caja-ctrl-alert-error').show().text('Error de conexión con el servidor.');
                    }
                });
            });

            // Modal de Historial de Movimientos de la Caja
            $(document).on('click', '.caja-btn-box-movements', function(e) {
                e.preventDefault();
                const boxId = $(this).data('id');
                self.openBoxMovementsModal(boxId);
            });

            $(document).on('change', '#caja-bmov-filter-type, #caja-bmov-filter-preset', function() {
                if (self.currentAuditedBoxId) {
                    self.loadBoxMovements(self.currentAuditedBoxId);
                }
            });

            $(document).on('click', '#caja-bmov-btn-reload', function(e) {
                e.preventDefault();
                if (self.currentAuditedBoxId) {
                    self.loadBoxMovements(self.currentAuditedBoxId);
                }
            });

            $(document).on('click', '#caja-bmov-modal-close, #caja-modal-box-movements .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-box-movements').fadeOut(150);
            });

            $(document).on('click', '#caja-bmov-btn-print', function(e) {
                e.preventDefault();
                self.printBoxMovementsReport();
            });

            $(document).on('change', '#caja-reg-filter-money-box, #caja-reg-filter-sector, #caja-reg-filter-user, #caja-reg-filter-status, #caja-reg-filter-from, #caja-reg-filter-to', function() {
                self.loadRegistersHistory();
            });

            $(document).on('change', '#caja-reg-filter-preset', function() {
                if ($(this).val() === 'custom') {
                    $('#caja-reg-custom-dates').css('display', 'flex');
                } else {
                    $('#caja-reg-custom-dates').hide();
                    self.loadRegistersHistory();
                }
            });

            $(document).on('click', '.caja-reg-btn-view-movements', function(e) {
                e.preventDefault();
                const sessionId = $(this).data('session-id');
                self.viewSessionMovements(sessionId);
            });

            $(document).on('click', '#caja-mov-close-btn, #caja-modal-session-movements .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-session-movements').fadeOut(150);
            });

            $(document).on('click', '#caja-mov-print-btn, .caja-reg-btn-ticket', function(e) {
                e.preventDefault();
                const sessionId = $(this).data('session-id') || (self.currentAuditedSession ? self.currentAuditedSession.id : null);
                if (sessionId) {
                    self.printSessionZTicket(sessionId);
                }
            });

            $(document).on('click', '.caja-reg-btn-force-close', function(e) {
                e.preventDefault();
                const sessionId = $(this).data('session-id');
                self.forceCloseRegisterByAdmin(sessionId);
            });

            // Soporte para botón atrás/adelante en el historial del navegador
            $(window).on('hashchange', function() {
                const h = window.location.hash.toLowerCase();
                if (!h) return; // NUNCA forzar pedidos si el hash está vacío
                const perms = self.config.currentUserPermissions || {};
                const isAdmin = Boolean(perms.is_admin || perms.role === 'administrator' || perms.role === 'shop_manager');

                if ((h === '#cajas' || h === '#control-cajas' || h === '#tab-registers') && (isAdmin || perms.can_manage_registers)) {
                    if (self.activeTab !== 'tab-registers') self.switchTab('tab-registers');
                } else if ((h === '#empleados' || h === '#tab-employees') && isAdmin) {
                    if (self.activeTab !== 'tab-employees') self.switchTab('tab-employees');
                } else if ((h === '#productos' || h === '#tab-products') && perms.can_view_products !== false) {
                    if (self.activeTab !== 'tab-products') self.switchTab('tab-products');
                } else if ((h === '#configuracion' || h === '#tab-settings') && isAdmin) {
                    if (self.activeTab !== 'tab-settings') self.switchTab('tab-settings');
                } else if (h === '#venta-caja' || h === '#tab-pos-sale' || h === '#pos') {
                    if (self.activeTab !== 'tab-pos-sale') self.switchTab('tab-pos-sale');
                } else if (h === '#pedidos' || h === '#tab-orders') {
                    if (perms.can_view_orders !== false) {
                        if (self.activeTab !== 'tab-orders') self.switchTab('tab-orders');
                    } else {
                        if (self.activeTab !== 'tab-pos-sale') self.switchTab('tab-pos-sale');
                    }
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

            // Selector de modo de stock: Sumar ingreso vs Restar mercadería vs Fijar directo
            $(document).on('click', '.caja-stock-mode-btn', function() {
                const mode = $(this).data('mode');
                $('.caja-stock-mode-btn').removeClass('active');
                $(this).addClass('active');
                $('#stock-modal-mode').val(mode);

                $('#caja-panel-mode-add, #caja-panel-mode-subtract, #caja-panel-mode-set').hide();

                if (mode === 'add') {
                    $('#caja-panel-mode-add').show();
                    $('#stock-incoming-qty').focus();
                } else if (mode === 'subtract') {
                    $('#caja-panel-mode-subtract').show();
                    $('#stock-subtract-qty').focus();
                } else {
                    $('#caja-panel-mode-set').show();
                    $('#stock-direct-qty').focus();
                }
            });

            // Cálculo reactivo en vivo al tipear unidades que ingresan (Sumar)
            $('#stock-incoming-qty').on('input', function() {
                const incoming = parseInt($(this).val()) || 0;
                const current = parseInt($('#calc-current-num').text()) || 0;
                const total = Math.max(0, current + incoming);

                $('#calc-incoming-num').text(incoming > 0 ? `+${incoming}` : '+0');
                $('#calc-result-num').text(total);
            });

            // Cálculo reactivo en vivo al tipear unidades que se restan (Restar)
            $('#stock-subtract-qty').on('input', function() {
                const sub = parseInt($(this).val()) || 0;
                const current = parseInt($('#calc-sub-current-num').text()) || 0;
                const total = Math.max(0, current - sub);

                $('#calc-sub-incoming-num').text(sub > 0 ? `-${sub}` : '-0');
                $('#calc-sub-result-num').text(total);
            });

            // Reactividad al cambiar el switch de activar control de stock
            $('#stock-prod-manage-stock').on('change', function() {
                if (!$(this).is(':checked')) {
                    $('#stock-modal-current-badge').attr('class', 'caja-badge caja-badge-success').text('Ilimitado');
                }
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

            const perms = this.config.currentUserPermissions || {};
            if (perms.is_admin || perms.can_view_orders !== false) {
                this.loadOrders(true);
                this.startPolling();
            }
        },

        updateSoundUI: function(enabled) {
            if (enabled) {
                $('#caja-toggle-sound .sound-icon-on').show();
                $('#caja-toggle-sound .sound-icon-off').hide();
                $('#caja-toggle-sound .caja-btn-tool-label').text('Activa');
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
            const app = window.BatllieCajaApp || this || App;
            if (!tabId) return;

            const perms = (app.config && app.config.currentUserPermissions) || (window.batllieCajaConfig && window.batllieCajaConfig.currentUserPermissions) || {};
            const isAdmin = Boolean(
                perms.is_admin === true || 
                perms.is_admin === 1 || 
                perms.is_admin === '1' || 
                perms.is_admin === 'true' || 
                perms.role === 'administrator' || 
                perms.role === 'shop_manager'
            );

            // Si la opción existe en el desplegable en el DOM, el usuario fue autorizado en el servidor
            const optionExists = $(`#caja-screen-select option[value="${tabId}"]`).length > 0;

            // Validar permisos de acceso antes de cambiar de pestaña solo si no está en el DOM
            if (tabId === 'tab-products' && perms.can_view_products === false && !optionExists) {
                tabId = 'tab-pos-sale';
            }
            if (tabId === 'tab-orders' && perms.can_view_orders === false && !optionExists) {
                tabId = 'tab-pos-sale';
            }
            if ((tabId === 'tab-settings' || tabId === 'tab-employees') && !isAdmin && !optionExists) {
                tabId = 'tab-pos-sale';
            }
            if (tabId === 'tab-registers' && !isAdmin && !perms.can_manage_registers && !optionExists) {
                tabId = 'tab-pos-sale';
            }

            $('.caja-tab-btn').removeClass('active');
            $(`.caja-tab-btn[data-tab="${tabId}"]`).addClass('active');

            $('.caja-tab-panel').removeClass('active').css('display', 'none').hide();
            const $targetPanel = $(`#${tabId}`);
            if ($targetPanel.length) {
                $targetPanel.addClass('active').css('display', 'block').show();
            }
            app.activeTab = tabId;

            if (tabId === 'tab-pos-sale') {
                $('.caja-main-content').addClass('is-pos-tab');
            } else {
                $('.caja-main-content').removeClass('is-pos-tab');
            }

            // Sincronizar el selector desplegable rápido de pantallas y su etiqueta visible
            const $screenSelect = $('#caja-screen-select');
            if ($screenSelect.length) {
                if ($screenSelect.val() !== tabId) {
                    $screenSelect.val(tabId);
                }
                const selectedText = $screenSelect.find(`option[value="${tabId}"]`).text();
                if (selectedText) {
                    $('#caja-screen-select-label').text(selectedText);
                }
            }

            const $switcherWrap = $('#caja-screen-switcher-wrap');
            const $screenDot = $('#caja-screen-dot');
            if ($switcherWrap.length) {
                $switcherWrap.removeClass('is-orders is-products is-settings is-pos is-employees is-registers');
                $screenDot.removeClass('dot-blue dot-purple dot-green dot-emerald');

                if (tabId === 'tab-products') {
                    $switcherWrap.addClass('is-products');
                    $screenDot.addClass('dot-blue');
                } else if (tabId === 'tab-pos-sale') {
                    $switcherWrap.addClass('is-pos');
                    $screenDot.addClass('dot-emerald');
                } else if (tabId === 'tab-settings' || tabId === 'tab-employees' || tabId === 'tab-registers') {
                    $switcherWrap.addClass('is-settings');
                    $screenDot.addClass('dot-purple');
                } else {
                    $switcherWrap.addClass('is-orders');
                    $screenDot.addClass('dot-green');
                }
            }

            // Actualizar el botón 'En Vivo' / 'Ir a Caja' si existe
            const $livePill = $('#caja-live-status');
            if ($livePill.length) {
                if (tabId === 'tab-products' || tabId === 'tab-settings' || tabId === 'tab-employees' || tabId === 'tab-pos-sale' || tabId === 'tab-registers') {
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
            }

            // Actualizar apariencia y texto del botón de cambio de pantallas (si existe)
            const $toggleBtn = $('#caja-btn-screen-toggle');
            if ($toggleBtn.length) {
                if (tabId === 'tab-products' || tabId === 'tab-settings' || tabId === 'tab-employees' || tabId === 'tab-pos-sale' || tabId === 'tab-registers') {
                    const count = app.cachedOrders ? app.cachedOrders.length : 0;
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

            // Inicializaciones de pestaña protegidas con try/catch para evitar bloqueos
            try {
                if (tabId === 'tab-registers') {
                    if (typeof app.loadPendingRegisterRequests === 'function') app.loadPendingRegisterRequests();
                    if (typeof app.loadRegistersHistory === 'function') app.loadRegistersHistory();
                    if (typeof app.loadMoneyBoxes === 'function') app.loadMoneyBoxes();
                } else if (tabId === 'tab-pos-sale') {
                    if (typeof app.updateRegisterBarUI === 'function') {
                        app.updateRegisterBarUI(app.activeRegisterSession);
                    }
                    if (typeof app.initPosTab === 'function') {
                        app.initPosTab();
                    }
                } else if (tabId === 'tab-products' && !app.isProductsLoaded) {
                    if (typeof app.loadProducts === 'function') app.loadProducts();
                    if (typeof app.loadCategories === 'function') app.loadCategories();
                } else if (tabId === 'tab-settings' && typeof app.initSettingsTab === 'function') {
                    app.initSettingsTab();
                } else if (tabId === 'tab-employees' && typeof app.initEmployeesTab === 'function') {
                    app.initEmployeesTab();
                }
            } catch (err) {
                console.error('Error inicializando pantalla ' + tabId, err);
            }

            // Actualizar URL hash para navegación y marcadores directos
            if (window.history && window.history.replaceState) {
                let newHash = '#pedidos';
                if (tabId === 'tab-products') newHash = '#productos';
                else if (tabId === 'tab-pos-sale') newHash = '#venta-caja';
                else if (tabId === 'tab-settings') newHash = '#configuracion';
                else if (tabId === 'tab-employees') newHash = '#empleados';
                else if (tabId === 'tab-registers') newHash = '#cajas';
                try {
                    window.history.replaceState(null, null, newHash);
                } catch(e) {}
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
            const perms = self.config.currentUserPermissions || {};
            if (!perms.is_admin && perms.can_view_orders === false) {
                return;
            }
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

                            // Manejo en tiempo real de solicitudes de apertura y cierre de caja
                            if (self.canManageRegisters) {
                                if (res.data.pending_register_requests_count !== undefined) {
                                    const pCount = parseInt(res.data.pending_register_requests_count, 10) || 0;
                                    const $badge = $('#caja-pending-requests-badge');
                                    $badge.text(pCount);
                                    if (pCount > 0) {
                                        $badge.addClass('has-pending');
                                    } else {
                                        $badge.removeClass('has-pending');
                                    }

                                    if (self.lastKnownPendingRequestsCount !== undefined && pCount > self.lastKnownPendingRequestsCount) {
                                        if (self.audio) self.audio.playNewOrderSound();
                                        self.showToast(`📬 ${pCount === 1 ? 'Nueva solicitud de caja pendiente' : pCount + ' solicitudes de caja pendientes'} de aprobación`, 'warning', 6000);
                                    }
                                    self.lastKnownPendingRequestsCount = pCount;

                                    if (self.activeTab === 'tab-registers' && res.data.pending_register_requests) {
                                        self.renderPendingRegisterRequests(res.data.pending_register_requests);
                                    }
                                }
                            } else {
                                // Para cajeros: detectar si su solicitud pendiente fue aprobada o rechazada
                                if (res.data.user_register_request) {
                                    const latestReq = res.data.user_register_request;
                                    const prevPending = self.userPendingRegisterRequest;

                                    if (prevPending && prevPending.status === 'pending') {
                                        if (latestReq.status === 'approved') {
                                            self.userPendingRegisterRequest = null;
                                            self.activeRegisterSession = res.data.active_session || null;
                                            self.updateRegisterBarUI(self.activeRegisterSession);
                                            if (latestReq.request_type === 'open') {
                                                self.showToast('🎉 ¡Tu apertura de caja fue aprobada! Ya puedes registrar ventas.', 'success', 8000);
                                            } else {
                                                self.showToast('✅ Tu cierre de caja fue aprobado por administración.', 'success', 8000);
                                            }
                                        } else if (latestReq.status === 'rejected') {
                                            self.userPendingRegisterRequest = null;
                                            self.updateRegisterBarUI(self.activeRegisterSession);
                                            const reason = latestReq.admin_notes || 'Sin motivo especificado';
                                            self.showToast(`❌ Tu solicitud de ${latestReq.request_type === 'open' ? 'apertura' : 'cierre'} fue rechazada: ${reason}`, 'error', 10000);
                                            window.alert(`❌ Tu solicitud de ${latestReq.request_type === 'open' ? 'apertura' : 'cierre'} de caja fue rechazada por la administración.\n\nMotivo:\n${reason}`);
                                        }
                                    }
                                }
                            }
                        }
                    }
                });
            }, interval);
        },

        renderOrders: function(orders) {
            const self = this;
            if (orders === undefined) {
                self.applyFilters();
                return;
            }
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

            const openPackagingEditIds = [];
            $('.caja-pkg-edit-view:visible').each(function() {
                const id = $(this).attr('id');
                if (id && id.startsWith('caja-pkg-edit-')) {
                    openPackagingEditIds.push(id.replace('caja-pkg-edit-', ''));
                }
            });

            const openPackagingAddIds = [];
            $('.caja-pkg-add-content:visible').each(function() {
                const id = $(this).attr('id');
                if (id && id.startsWith('caja-pkg-add-content-')) {
                    openPackagingAddIds.push(id.replace('caja-pkg-add-content-', ''));
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
                    const boxName = spec.courtesy ? 'Caja de Cortesía' : ('Caja ' + spec.cap + ' unidades');
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

                let recipientHtml = '';
                if (order.has_different_shipping && order.shipping_name) {
                    let shipWa = order.shipping_phone_wa || '';
                    if (!shipWa && order.shipping_phone) {
                        shipWa = String(order.shipping_phone).replace(/\D+/g, '');
                    }
                    if (shipWa && !shipWa.startsWith('549') && !shipWa.startsWith('54')) {
                        shipWa = '549' + shipWa.replace(/^0+/, '').replace(/^15/, '');
                    }
                    let recipientPhoneHtml = '';
                    if (order.shipping_phone) {
                        recipientPhoneHtml = `
                            <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                                <a href="https://wa.me/${shipWa}" target="_blank" style="color: #22c55e; text-decoration: none; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px; font-weight: 600;" title="Contactar destinatario por WhatsApp">
                                    <span>📱</span>
                                    <span>${self.escapeHtml(order.shipping_phone)}</span>
                                    <span style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 4px; padding: 1px 4px; font-size: 0.68rem; color: #4ade80;">WhatsApp</span>
                                </a>
                            </div>
                        `;
                    }
                    recipientHtml = `
                        <div class="caja-shipping-recipient-box" style="margin-top: 6px; margin-bottom: 6px; padding: 7px 10px; background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 6px; font-size: 0.82rem; line-height: 1.35;">
                            <div style="font-weight: 700; color: #38bdf8; display: flex; align-items: center; gap: 5px; margin-bottom: 2px;">
                                <span>🚚</span>
                                <span>Destinatario: ${self.escapeHtml(order.shipping_name)}</span>
                            </div>
                            ${order.shipping_dni ? `<div style="color: var(--caja-text-muted, #94a3b8); font-size: 0.78rem;">🪪 DNI Destinatario: <strong style="color: var(--caja-text, #ffffff);">${self.escapeHtml(order.shipping_dni)}</strong></div>` : ''}
                            ${recipientPhoneHtml}
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
                const isPickup = (order.is_local_pickup === true || order.is_local_pickup === 'yes');
                const isVentaMostrador = !!(order.is_venta_mostrador || (order.customer_name && order.customer_name.toLowerCase().includes('venta mostrador')));
                const isVentaCaja = self.isOrderVentaCaja(order);
                let showPaymentBox = false;
                let showPackagingBox = false;
                let showShippingBox = false;

                if (isVentaCaja) {
                    showPaymentBox = false;
                    showPackagingBox = false;
                    showShippingBox = false;
                } else if (order.status === 'pending') {
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
                    showShippingBox = !isPickup;
                } else if (order.status === 'completed' || order.status === 'recibido-problema' || order.status === 'failed') {
                    showPaymentBox = false;
                    showPackagingBox = false;
                    showShippingBox = !isPickup;
                } else if (order.status === 'cancelled' || order.status === 'refunded') {
                    showPaymentBox = true;
                    showPackagingBox = false;
                    showShippingBox = false;
                } else {
                    showPaymentBox = true;
                    showPackagingBox = false;
                    showShippingBox = false;
                }

                let showMetaGrid = !isVentaCaja && (showPaymentBox || showPackagingBox || showShippingBox);

                const showControlPedidoBtn = !isVentaCaja && (order.status === 'enviando' || order.status === 'on-hold');

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

                let experienciaHtml = '';

                let courtesyHtml = '';
                if (order.has_courtesy_box) {
                    courtesyHtml = `
                        <div class="caja-courtesy-banner">
                            🎁 <strong>Incluye Caja de Cortesía</strong> (Agrupación concedida).
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

                let cleanCustomerName = order.customer_name || '';
                if (isVentaMostrador) {
                    cleanCustomerName = cleanCustomerName.replace(/Venta\s+Mostrador/gi, '').trim();
                }
                const lowerCust = cleanCustomerName.toLowerCase().trim();
                if (!cleanCustomerName || lowerCust === 'cliente mostrador' || lowerCust.includes('cliente mostrador') || lowerCust === 'cliente' || lowerCust === 'mostrador' || lowerCust.includes('anónimo') || lowerCust.includes('anonimo')) {
                    cleanCustomerName = 'Consumidor Final';
                }

                html += `
                    <div class="caja-order-card" id="caja-order-card-${order.id}">
                        <div class="caja-card-header">
                            <div class="caja-order-num-wrap">
                                <span class="caja-order-number">#${order.number}</span>
                                <span class="caja-order-time">${order.time_diff || order.time_formatted}</span>
                            </div>
                            <div class="caja-card-header-badges">
                                ${!isVentaCaja && order.shipping_slot_badge ? `
                                    <span class="caja-dispatch-pill" title="Tanda de despacho: ${order.shipping_slot_label || order.shipping_slot_badge}">
                                        <span class="caja-dispatch-icon">🚚</span>
                                        <span class="caja-dispatch-text">${order.shipping_slot_badge}</span>
                                    </span>
                                ` : (!isVentaCaja && order.shipping_method && order.shipping_method.toLowerCase().indexOf('local') !== -1 ? `
                                    <span class="caja-dispatch-pill caja-dispatch-pickup" title="Retiro en local">
                                        <span class="caja-dispatch-icon">🏬</span>
                                        <span class="caja-dispatch-text">Retiro local</span>
                                    </span>
                                ` : '')}
                                ${order.gastro_sector ? `
                                    <span class="caja-gastro-pill" title="Sector: ${self.escapeHtml(order.gastro_sector)}${order.gastro_table ? ' · Mesa ' + self.escapeHtml(order.gastro_table) : ''}">
                                        <span class="caja-gastro-pill-icon">${order.gastro_icon || '🍸'}</span>
                                        <span class="caja-gastro-pill-text">${self.escapeHtml(order.gastro_sector)}${order.gastro_table ? ' · M' + self.escapeHtml(order.gastro_table) : ''}</span>
                                    </span>
                                ` : ''}
                                <span class="caja-status-pill ${order.status_badge}">${order.status_name}</span>
                            </div>
                        </div>

                        <div class="caja-customer-block">
                            ${isVentaMostrador ? (function() {
                                const cajaSector = order.gastro_sector || (order.caja_session_code ? `Caja ${order.caja_session_code}` : 'Barra Principal');
                                const cajaIcon = order.gastro_icon || (typeof self.getSectorIcon === 'function' ? self.getSectorIcon(cajaSector) : '🍸');
                                const tablePart = order.gastro_table ? ` · M${self.escapeHtml(order.gastro_table)}` : '';
                                const cashierName = order.caja_cashier || 'Admin';

                                const rawPm = (order.payment_method || '').toLowerCase();
                                let pmIcon = '💵';
                                let pmText = order.payment_method || 'Efectivo';
                                if (rawPm.includes('tarjeta') || rawPm.includes('card') || rawPm.includes('pos')) {
                                    pmIcon = '💳';
                                    pmText = 'Tarjeta';
                                } else if (rawPm.includes('transferencia') || rawPm.includes('bacs')) {
                                    pmIcon = '📱';
                                    pmText = 'Transferencia';
                                } else if (rawPm.includes('mercado') || rawPm.includes('mp')) {
                                    pmIcon = '💠';
                                    pmText = 'Mercado Pago';
                                } else if (rawPm.includes('efectivo') || rawPm.includes('cod')) {
                                    pmIcon = '💵';
                                    pmText = 'Efectivo';
                                }

                                return `
                                    <div class="caja-badge-venta-mostrador-row">
                                        <div class="caja-badge-venta-mostrador">
                                            <span class="caja-vm-item"><span class="caja-vm-icon">${cajaIcon}</span> <span>Caja: <strong>${self.escapeHtml(cajaSector)}${tablePart}</strong></span></span>
                                            <span class="caja-vm-sep">•</span>
                                            <span class="caja-vm-item"><span class="caja-vm-icon">👤</span> <span>Cajero: <strong>${self.escapeHtml(cashierName)}</strong></span></span>
                                            <span class="caja-vm-sep">•</span>
                                            <span class="caja-vm-item"><span class="caja-vm-icon">${pmIcon}</span> <span>Pago: <strong>${self.escapeHtml(pmText)}</strong></span></span>
                                        </div>
                                    </div>
                                `;
                            })() : ''}
                            <div class="caja-customer-header-row">
                                <div class="caja-customer-name caja-customer-history-trigger" data-order-id="${order.id}" data-customer-name="${self.escapeHtml(cleanCustomerName)}" style="cursor:pointer;" title="Hacé clic para ver el historial de pedidos de este cliente">
                                    <span>👤 ${cleanCustomerName}</span>
                                    <span class="caja-cust-history-pill" style="font-size:0.74rem; font-weight:700; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); border-radius:10px; padding:2px 7px; margin-left:6px; display:inline-flex; align-items:center; gap:3px; vertical-align:middle;">
                                        📜 Historial
                                    </span>
                                </div>
                            </div>
                            ${phoneHtml}
                            ${order.dni ? `<div class="caja-customer-dni" style="font-size:0.83rem; color:var(--caja-text-muted); margin-bottom:4px;">🪪 DNI/CUIT: <strong style="color:var(--caja-text);">${self.escapeHtml(order.dni)}</strong></div>` : ''}
                            ${recipientHtml}
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
                            ${experienciaHtml}
                            ${itemsHtml}
                        </div>

                        <div class="caja-card-footer">
                            <div class="caja-order-total-block">
                                <span class="caja-total-label">Total a cobrar:</span>
                                <span class="caja-total-val">${order.total}</span>
                                ${order.gastro_tip_amount ? `<span class="caja-gastro-card-tip" style="font-size:0.75rem; color:#10b981; font-weight:700; margin-left:8px;" title="Propina / Servicio incluido">(incluye $${self.formatCurrencyNumber(order.gastro_tip_amount)} propina)</span>` : ''}
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

                                    <!-- Vista resumen de empaques oficiales (solo lectura por defecto) -->
                                    <div class="caja-pkg-summary-view" id="caja-pkg-summary-${order.id}">
                                        <div class="caja-pkg-item-row" style="margin-top:4px;">
                                            <div id="caja-pkg-summary-content-${order.id}" style="display:flex; flex-wrap:wrap; gap:4px; margin-top:2px;">
                                                ${(() => {
                                                    const activePkgs = (order.dynamic_packaging || []).filter(p => (parseInt(p.qty, 10) || 0) > 0);
                                                    return activePkgs.length > 0
                                                        ? activePkgs.map(p => `<span class="caja-pkg-summary-tag">${self.escapeHtml(p.name)}: <strong>${p.qty}</strong></span>`).join('')
                                                        : '<em style="color:#64748b; font-size:0.83rem;">Sin empaque seleccionado</em>';
                                                })()}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Vista edición de empaques dinámicos (habilitada SOLO si se toca 'Modificar') -->
                                    <div class="caja-pkg-edit-view" id="caja-pkg-edit-${order.id}" style="display:none; margin-top:8px; padding-top:8px; border-top:1px dashed rgba(255,255,255,0.1);">
                                        <div class="caja-pkg-dynamic-list" id="caja-pkg-list-${order.id}">
                                            ${(self.packagingProducts && self.packagingProducts.length > 0) ? self.packagingProducts.map(pkg => {
                                                const assigned = (order.dynamic_packaging || []).find(d => d.id == pkg.id);
                                                const currQty = assigned ? assigned.qty : 0;
                                                const isOutOfStock = (!pkg.in_stock || (pkg.stock !== null && pkg.stock <= 0));
                                                return `
                                                    <div class="caja-pkg-dynamic-row" data-pkg-id="${pkg.id}">
                                                        <div class="caja-pkg-info-col">
                                                            <div style="display:flex; align-items:center; gap:6px;">
                                                                <span class="caja-pkg-name" title="${self.escapeHtml(pkg.name)}">${self.escapeHtml(pkg.name)}</span>
                                                                <button type="button" class="caja-btn-del-pkg btn-del-dyn-pkg" data-pkg-id="${pkg.id}" data-pkg-name="${self.escapeHtml(pkg.name)}" title="Quitar este empaque del catálogo">🗑️</button>
                                                            </div>
                                                            <div>
                                                                ${!isOutOfStock
                                                                    ? `<span class="caja-pkg-stock-badge in-stock">🟢 Stock: ${pkg.stock !== null ? pkg.stock : 'Disp.'}</span>`
                                                                    : `<span class="caja-pkg-stock-badge out-of-stock">🔴 Sin stock (Agotado)</span>`
                                                                }
                                                            </div>
                                                        </div>
                                                        <div class="caja-pkg-stepper-wrap">
                                                            <div class="caja-stepper">
                                                                <button type="button" class="caja-step-btn btn-step-dyn-pkg" data-action="minus" data-pkg-id="${pkg.id}" data-order-id="${order.id}">−</button>
                                                                <input type="number" min="0" step="1" id="pkg-dyn-input-${pkg.id}-${order.id}" data-pkg-id="${pkg.id}" value="${currQty}" class="caja-pkg-input pkg-dyn-field-${order.id}" readonly />
                                                                <button type="button" class="caja-step-btn btn-step-dyn-pkg ${isOutOfStock ? 'is-disabled' : ''}" ${isOutOfStock ? 'disabled="disabled"' : ''} data-action="plus" data-pkg-id="${pkg.id}" data-order-id="${order.id}" title="${isOutOfStock ? 'Sin existencias en WooCommerce' : 'Añadir 1'}">+</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                `;
                                            }).join('') : `
                                                <div style="font-size:0.8rem; color:#94a3b8; font-style:italic; padding:6px 0;">No hay empaques oficiales configurados. Agrega uno abajo.</div>
                                            `}
                                        </div>

                                        <!-- Sección Desplegable "+ Agregar nuevo empaque" -->
                                        <div class="caja-pkg-add-section" style="margin-top:10px;">
                                            <button type="button" class="caja-pkg-add-toggle btn-toggle-add-pkg" data-order-id="${order.id}">
                                                <span>➕ Agregar nuevo empaque</span>
                                                <span class="caja-thematic-toggle-arrow">▼</span>
                                            </button>
                                            <div class="caja-pkg-add-content" id="caja-pkg-add-content-${order.id}" style="display:none;">
                                                <!-- Opción A: Vincular producto existente -->
                                                ${(self.packagingCandidates && self.packagingCandidates.length > 0) ? `
                                                    <div style="margin-bottom:12px;">
                                                        <label class="caja-pkg-sublabel">Vincular producto existente de WooCommerce:</label>
                                                        <div class="caja-pkg-link-row">
                                                            <select class="caja-select-existing-pkg" id="caja-sel-exist-${order.id}">
                                                                <option value="">-- Seleccionar producto --</option>
                                                                ${self.packagingCandidates.map(c => `
                                                                    <option value="${c.id}">${self.escapeHtml(c.name)} (Stock: ${c.stock})</option>
                                                                `).join('')}
                                                            </select>
                                                            <button type="button" class="caja-btn caja-btn-xs caja-btn-primary btn-link-existing-pkg" data-order-id="${order.id}">+ Vincular</button>
                                                        </div>
                                                    </div>
                                                ` : ''}

                                                <!-- Opción B: Crear nuevo producto en el acto -->
                                                <div>
                                                    <label class="caja-pkg-sublabel">+ Crear nuevo empaque oficial en WooCommerce:</label>
                                                    <div class="caja-pkg-create-inputs-row">
                                                        <input type="text" class="caja-input-new-pkg-name" id="caja-new-pkg-name-${order.id}" placeholder="Ej: Caja Reforzada Mediana" />
                                                        <input type="number" min="0" class="caja-input-new-pkg-stock" id="caja-new-pkg-stock-${order.id}" placeholder="Stock" value="50" title="Stock inicial" />
                                                    </div>
                                                    <button type="button" class="caja-btn caja-btn-xs caja-btn-primary btn-create-dyn-pkg" data-order-id="${order.id}">+ Crear y agregar</button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="caja-pkg-edit-actions" style="display:flex; gap:6px; justify-content:flex-end; margin-top:10px; padding-top:8px; border-top:1px solid rgba(255,255,255,0.06);">
                                            <button type="button" class="caja-btn caja-btn-xs caja-btn-secondary btn-cancel-pkg-dyn" data-order-id="${order.id}">Cancelar</button>
                                            <button type="button" class="caja-btn caja-btn-xs caja-btn-primary btn-save-pkg-dyn" data-order-id="${order.id}">💾 Guardar empaque</button>
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
                                        ${isVentaCaja ? `
                                            <option value="completed" ${order.status === 'completed' ? 'selected' : ''}>Completado</option>
                                            <option value="refunded" ${order.status === 'refunded' ? 'selected' : ''}>Reembolzado</option>
                                        ` : `
                                            <option value="pending" ${order.status === 'pending' ? 'selected' : ''}>Pendiente</option>
                                            <option value="processing" ${order.status === 'processing' ? 'selected' : ''} ${(!isPayApproved && order.status !== 'processing') ? 'disabled' : ''}>En preparación${(!isPayApproved && order.status !== 'processing') ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="enviando" ${order.status === 'enviando' || order.status === 'on-hold' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>${isPickup ? 'Esperando a ser retirado' : 'Enviando'}${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="completed" ${order.status === 'completed' ? 'selected' : ''} ${!isPayApproved ? 'disabled' : ''}>Completado${!isPayApproved ? ' 🔒 (requiere pago)' : ''}</option>
                                            <option value="recibido-problema" ${order.status === 'recibido-problema' || order.status === 'failed' ? 'selected' : ''}>Recibido (con inconvenientes)</option>
                                            <option value="cancelled" ${order.status === 'cancelled' ? 'selected' : ''}>Cancelado</option>
                                            <option value="refunded" ${order.status === 'refunded' ? 'selected' : ''}>Reembolzado</option>
                                        `}
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
                            <!-- Botones Gastro: Comanda & Ticket -->
                            <div class="caja-gastro-card-actions" style="display:flex; gap:6px; margin:8px 0;">
                                <button type="button" class="caja-btn-print-comanda" data-order-id="${order.id}" title="Imprimir Comanda de Preparación">
                                    <span>🖨️ Comanda</span>
                                </button>
                                <button type="button" class="caja-btn-print-ticket" data-order-id="${order.id}" title="Imprimir Ticket Térmico para Cliente">
                                    <span>🧾 Ticket</span>
                                </button>
                            </div>

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

            // Restaurar paneles de empaque dinámico abiertos
            openPackagingEditIds.forEach(id => {
                const $edit = $(`#caja-pkg-edit-${id}`);
                const $summary = $(`#caja-pkg-summary-${id}`);
                if ($edit.length) {
                    $edit.show();
                    if ($summary.length) $summary.hide();
                }
            });

            openPackagingAddIds.forEach(id => {
                const $panel = $(`#caja-pkg-add-content-${id}`);
                if ($panel.length) {
                    $panel.show();
                    const $btn = $(`.btn-toggle-add-pkg[data-order-id="${id}"]`);
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
                    const matchVentaMostrador = ord.is_venta_mostrador && 'venta mostrador'.includes(kw);
                    if (!matchNum && !matchName && !matchPhone && !matchNote && !matchVentaMostrador) {
                        return false;
                    }
                }

                // 4. Filtro de Horarios de Envío (Tandas)
                // Filtro Gastro (Sector / Mesa / Barra)
                if (self.currentGastroSectorFilter && self.currentGastroSectorFilter !== 'all') {
                    if (!ord.gastro_sector || ord.gastro_sector !== self.currentGastroSectorFilter) {
                        return false;
                    }
                }

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

            const cachedOrder = self.cachedOrders.find(o => o.id == orderId);
            const isVentaMostrador = !!(cachedOrder && (cachedOrder.is_venta_mostrador || (cachedOrder.customer_name && cachedOrder.customer_name.toLowerCase().includes('venta mostrador'))));
            const isVentaCaja = self.isOrderVentaCaja(cachedOrder);

            const setVisible = ($el, show) => {
                if (show) {
                    $el.removeClass('caja-meta-hidden').show();
                } else {
                    $el.addClass('caja-meta-hidden').hide();
                }
            };

            if (isVentaCaja) {
                setVisible($payBox, false);
                setVisible($pkgBox, false);
                setVisible($shipBox, false);
                setVisible($grid, false);
            } else {
                setVisible($payBox, true);
                setVisible($pkgBox, false);
                setVisible($shipBox, false);
                const hasVisibleMeta = $payBox.is(':visible') || $pkgBox.is(':visible') || $shipBox.is(':visible');
                setVisible($grid, hasVisibleMeta);
            }
            $controlBtnWrap.hide();

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

        loadProducts: function(showLoading, callback) {
            const self = this;
            if (showLoading !== false) {
                $('#caja-products-loading').show();
            }

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_get_products',
                    security: self.config.nonce,
                    limit: 250
                },
                success: function(res) {
                    if (res.success && res.data) {
                        self.cachedProducts = res.data.products || [];
                        self.isProductsLoaded = true;
                        if (typeof self.renderProducts === 'function') {
                            self.renderProducts(self.cachedProducts);
                        }
                        if (typeof self.renderPosCategoriesAccordion === 'function') {
                            self.renderPosCategoriesAccordion();
                        }
                        if (typeof callback === 'function') {
                            callback(self.cachedProducts);
                        }
                    } else {
                        $('#caja-pos-categories-accordion').html(`
                            <div style="text-align: center; padding: 30px; color: var(--caja-text-muted);">
                                ${res.data && res.data.message ? res.data.message : 'No se encontraron productos.'}
                            </div>
                        `);
                        if (typeof callback === 'function') callback([]);
                    }
                },
                error: function(xhr, status, err) {
                    console.error('Error al cargar productos:', err);
                    $('#caja-pos-products-grid').html(`
                        <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: var(--caja-status-cancelled, #ef4444);">
                            Error de conexión al cargar productos.<br>
                            <button type="button" class="caja-btn caja-btn-tool" id="caja-pos-retry-btn" style="margin-top:10px;">🔄 Reintentar</button>
                        </div>
                    `);
                    if (typeof callback === 'function') callback([]);
                },
                complete: function() {
                    if (showLoading !== false) {
                        $('#caja-products-loading').hide();
                    }
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
            const $grid = $('#caja-products-grid');
            const $empty = $('#caja-products-empty');

            // Determinar columnas activas (móvil forzado a 2; PC según configuración 2 o 3)
            const isMobile = window.innerWidth <= 768;
            const currentCols = isMobile ? '2' : (this.mgmtGridCols || '2');
            $grid.removeClass('is-cols-2 is-cols-3').addClass('is-cols-' + currentCols);
            $('.caja-mgmt-layout-btn').removeClass('active');
            $(`.caja-mgmt-layout-btn[data-cols="${currentCols}"]`).addClass('active');

            if (!products || products.length === 0) {
                $grid.empty().hide();
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
            $grid.show();

            let html = '';
            products.forEach(p => {
                const imgSrc = p.image_url || '';

                // Formato de Stock: solamente cantidad de stock
                let stockClass = 'is-in-stock';
                let stockText = 'En stock';
                const qtyRaw = p.stock_quantity_raw !== undefined ? p.stock_quantity_raw : (p.stock_quantity !== null ? parseInt(p.stock_quantity, 10) : null);

                if (p.stock_status === 'outofstock' || (qtyRaw !== null && qtyRaw <= 0)) {
                    stockClass = 'is-out-of-stock';
                    stockText = 'Agotado (0)';
                } else if (p.is_low_stock || (qtyRaw !== null && qtyRaw <= 5)) {
                    stockClass = 'is-low-stock';
                    stockText = `Stock: ${p.stock_quantity} (Bajo)`;
                } else if (p.stock_quantity !== null && p.stock_quantity !== '') {
                    stockClass = 'is-in-stock';
                    stockText = `En stock (${p.stock_quantity})`;
                } else if (p.stock_label) {
                    stockText = p.stock_label;
                }

                html += `
                    <div class="caja-mgmt-prod-card caja-btn-open-edit-prod" data-product-id="${p.id}" id="caja-prod-row-${p.id}" title="Click para modificar ${this.escapeHtml(p.name)}">
                        <div class="caja-mgmt-prod-thumb">
                            ${imgSrc ? `
                                <img src="${imgSrc}" alt="${this.escapeHtml(p.name)}" class="caja-mgmt-prod-img" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';" />
                                <div class="caja-mgmt-fallback-icon" style="display:none;">📦</div>
                            ` : `
                                <div class="caja-mgmt-fallback-icon">📦</div>
                            `}
                        </div>
                        <div class="caja-mgmt-prod-body">
                            <div class="caja-mgmt-prod-title" title="${this.escapeHtml(p.name)}">${this.escapeHtml(p.name)}</div>
                            <div class="caja-mgmt-prod-stock">
                                <span class="caja-mgmt-stock-badge ${stockClass}">${stockText}</span>
                                ${p.is_dynamic_stock ? `<span class="caja-mgmt-dyn-stock" title="${p.bottleneck_item ? 'Limitado por: ' + p.bottleneck_item : ''}">⚡ Dinámico</span>` : ''}
                            </div>
                            <div class="caja-mgmt-prod-price-row">
                                <strong class="caja-mgmt-prod-price">${p.price}</strong>
                                ${p.is_on_sale ? `<span class="caja-badge-sale">Oferta</span>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });

            $grid.html(html);
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

            // Solo productos simples (no el actual, ni agrupados, ni variables)
            const candidates = (self.cachedProducts || []).filter(p => {
                return p.id !== currentProductId && p.product_type === 'simple';
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

        saveDynamicPackaging: function(orderId, items, callback) {
            const self = this;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_update_packaging',
                    security: self.config.nonce,
                    order_id: orderId,
                    items: items
                },
                success: function(res) {
                    if (res && res.success) {
                        const updated = res.data && res.data.order;
                        if (updated) {
                            const idx = self.cachedOrders.findIndex(o => o.id == orderId);
                            if (idx !== -1) {
                                self.cachedOrders[idx] = updated;
                            }
                            self.applyFilters();
                        }
                        self.showToast('📦 Control de empaque guardado correctamente.');
                        if (typeof callback === 'function') callback(true, updated);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al guardar el empaque.');
                        if (typeof callback === 'function') callback(false);
                    }
                },
                error: function() {
                    alert('Error de conexión al guardar el empaque.');
                    if (typeof callback === 'function') callback(false);
                }
            });
        },

        addPackagingItem: function(productId, newName, initialStock, orderId, callback) {
            const self = this;
            if (typeof orderId === 'function') {
                callback = orderId;
                orderId = null;
            }
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_add_packaging_item',
                    security: self.config.nonce,
                    product_id: productId || 0,
                    name: newName || '',
                    initial_stock: initialStock || 0
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        if (res.data.packaging_products) {
                            self.packagingProducts = res.data.packaging_products;
                        }
                        if (res.data.candidates) {
                            self.packagingCandidates = res.data.candidates;
                        }

                        // Si se agregó desde una tarjeta de pedido, auto-asignarlo con cantidad 1 y guardar el pedido
                        if (orderId) {
                            const ord = self.cachedOrders.find(o => o.id == orderId);
                            if (ord) {
                                if (!ord.dynamic_packaging || !Array.isArray(ord.dynamic_packaging)) {
                                    ord.dynamic_packaging = [];
                                }
                                const addedId = res.data.added_id || (res.data.item ? res.data.item.id : productId);
                                const addedName = res.data.added_name || (res.data.item ? res.data.item.name : newName);
                                const existingIdx = ord.dynamic_packaging.findIndex(p => p.id == addedId);
                                if (existingIdx !== -1) {
                                    ord.dynamic_packaging[existingIdx].qty = Math.max(1, parseInt(ord.dynamic_packaging[existingIdx].qty, 10) || 1);
                                } else {
                                    ord.dynamic_packaging.push({
                                        id: addedId,
                                        name: addedName,
                                        qty: 1
                                    });
                                }

                                self.saveDynamicPackaging(orderId, ord.dynamic_packaging, function(saveOk) {
                                    self.showToast('✨ Empaque agregado y asignado al pedido.');
                                    if (typeof callback === 'function') callback(saveOk, res.data);
                                });
                                return;
                            }
                        }

                        self.applyFilters();
                        self.showToast('✨ Empaque agregado correctamente.');
                        if (typeof callback === 'function') callback(true, res.data);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al agregar el empaque.');
                        if (typeof callback === 'function') callback(false);
                    }
                },
                error: function() {
                    alert('Error de conexión al agregar el empaque.');
                    if (typeof callback === 'function') callback(false);
                }
            });
        },

        deletePackagingItem: function(productId, callback) {
            const self = this;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_delete_packaging_item',
                    security: self.config.nonce,
                    product_id: productId
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        if (res.data.packaging_products) {
                            self.packagingProducts = res.data.packaging_products;
                        }
                        if (res.data.candidates) {
                            self.packagingCandidates = res.data.candidates;
                        }
                        self.applyFilters();
                        self.showToast('🗑️ Empaque quitado del listado oficial.');
                        if (typeof callback === 'function') callback(true, res.data);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Error al eliminar el empaque.');
                        if (typeof callback === 'function') callback(false);
                    }
                },
                error: function() {
                    alert('Error de conexión al eliminar el empaque.');
                    if (typeof callback === 'function') callback(false);
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

            // 3. Empaques y bolsas de envío (Dinámico o legado)
            const hasDynamicPackaging = Array.isArray(order.dynamic_packaging);
            const dynamicPkgs = hasDynamicPackaging
                ? order.dynamic_packaging.filter(p => (parseInt(p.qty, 10) || 0) > 0)
                : [];

            if (dynamicPkgs.length > 0) {
                html += `
                    <div class="caja-control-box-card caja-control-bags-card">
                        <div class="caja-control-box-header">
                            <span class="caja-control-box-icon">🛍️</span>
                            <span class="caja-control-box-title">Empaque y Bolsas de envío</span>
                            <span class="caja-control-box-badge">${dynamicPkgs.length} ítem${dynamicPkgs.length === 1 ? '' : 's'}</span>
                        </div>
                        <div class="caja-control-subitems-list">
                `;
                dynamicPkgs.forEach(pkg => {
                    const qty = parseInt(pkg.qty, 10) || 1;
                    html += `
                        <div class="caja-control-item-row">
                            <div class="caja-control-item-left">
                                <span class="caja-control-no-thumb">🛍️</span>
                                <div class="caja-control-item-info">
                                    <span class="caja-control-item-qty">${qty}x</span>
                                    <span class="caja-control-item-name">${self.escapeHtml(pkg.name)}</span>
                                </div>
                            </div>
                            <label class="caja-control-check-wrap" title="Verificar empaque">
                                <input type="checkbox" class="caja-control-chk" />
                            </label>
                        </div>
                    `;
                });
                html += `
                        </div>
                    </div>
                `;
            } else if (!hasDynamicPackaging) {
                const bagLargeQty = (order.bag_large !== undefined && order.bag_large !== null) ? parseInt(order.bag_large, 10) : 1;
                const bagSmallQty = (order.bag_small !== undefined && order.bag_small !== null) ? parseInt(order.bag_small, 10) : 0;
                const bagLargeName = (self.config && self.config.officialBagLargeName) || 'Bolsa grande de envío';
                const bagSmallName = (self.config && self.config.officialBagSmallName) || 'Bolsa chica de envío';

                if (bagLargeQty > 0 || bagSmallQty > 0) {
                    html += `
                        <div class="caja-control-box-card caja-control-bags-card">
                            <div class="caja-control-box-header">
                                <span class="caja-control-box-icon">🛍️</span>
                                <span class="caja-control-box-title">Bolsas de envío</span>
                            </div>
                            <div class="caja-control-subitems-list">
                    `;
                    if (bagLargeQty > 0) {
                        html += `
                            <div class="caja-control-item-row">
                                <div class="caja-control-item-left">
                                    <span class="caja-control-no-thumb">🛍️</span>
                                    <div class="caja-control-item-info">
                                        <span class="caja-control-item-qty">${bagLargeQty}x</span>
                                        <span class="caja-control-item-name">${self.escapeHtml(bagLargeName)}</span>
                                    </div>
                                </div>
                                <label class="caja-control-check-wrap" title="Verificar bolsa">
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
                                <label class="caja-control-check-wrap" title="Verificar bolsa">
                                    <input type="checkbox" class="caja-control-chk" />
                                </label>
                            </div>
                        `;
                    }
                    html += `
                            </div>
                        </div>
                    `;
                }
            }

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

            const isPickup = (
                order.is_local_pickup === true ||
                order.is_local_pickup === 'yes' ||
                order.delivery_type === 'retiro_local' ||
                (order.shipping_method && order.shipping_method.toLowerCase().indexOf('local') !== -1) ||
                order.status === 'on-hold'
            );
            self.isCurrentControlPickup = isPickup;

            if (isPickup) {
                $('#caja-control-intro-text').text('Chequeá todos los productos incluidos en cada caja, los sueltos y empaques antes de entregar al cliente:');
                $('#caja-btn-iniciar-envio').html('<span>🏪 Entregar pedido</span>');
            } else {
                $('#caja-control-intro-text').text('Chequeá todos los productos incluidos en cada caja, los sueltos y las bolsas de envío antes de entregar al repartidor:');
                $('#caja-btn-iniciar-envio').html('<span>🚀 Iniciar envío</span>');
            }

            $('#caja-btn-iniciar-envio').prop('disabled', true).removeClass('is-ready');

            $('body').addClass('caja-modal-locked');
            $body.scrollTop(0);
            $('#caja-modal-control-pedido').fadeIn(200);
        },

        openCustomerHistoryModal: function(orderId, customerName) {
            const self = this;
            const $modal = $('#caja-modal-customer-history');
            const $title = $('#caja-cust-hist-title');
            const $sub = $('#caja-cust-hist-sub');
            const $body = $('#caja-cust-hist-body');
            $body.css({ 'padding-left': '0', 'padding-right': '0' });

            $title.text('Historial de ' + customerName);
            $sub.text('Consultando pedidos registrados...');
            $body.html(`
                <div class="caja-loading-spinner-wrap" style="text-align: center; padding: 40px 16px;">
                    <span class="caja-spinner" style="font-size:2rem; display:inline-block; animation:spin 1s infinite linear;">⏳</span>
                    <p style="margin-top: 10px; color: var(--caja-text-muted, #94a3b8); font-size:0.95rem;">Cargando historial de compras...</p>
                </div>
            `);
            $modal.fadeIn(200);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_get_customer_history',
                    security: self.config.nonce,
                    order_id: orderId
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        const data = res.data;
                        const c = data.customer || {};
                        const s = data.stats || {};
                        const orders = data.orders || [];

                        $title.text(c.name || customerName);
                        $sub.text(`${c.phone ? '📱 ' + c.phone : ''} ${c.email ? ' • ✉️ ' + c.email : ''}`);

                        let ordersTableHtml = '';
                        if (orders.length > 0) {
                            ordersTableHtml = `
                                <div style="overflow-x:auto; width:100%; -webkit-overflow-scrolling:touch;">
                                    <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left; margin:0;">
                                        <thead>
                                            <tr style="border-top:1px solid var(--caja-card-border, #334155); border-bottom:2px solid var(--caja-card-border, #334155); background:rgba(255,255,255,0.02); color:var(--caja-text-muted, #94a3b8);">
                                                <th style="padding:10px 10px 10px 16px;">Pedido</th>
                                                <th style="padding:10px 10px;">Fecha</th>
                                                <th style="padding:10px 10px;">Estado</th>
                                                <th style="padding:10px 10px;">Total</th>
                                                <th style="padding:10px 16px 10px 10px;">Productos</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;

                            orders.forEach(ord => {
                                const highlightStyle = ord.is_current ? 'background:rgba(248,240,208,0.08); font-weight:600;' : '';
                                ordersTableHtml += `
                                    <tr style="border-bottom:1px solid var(--caja-card-border, #334155); ${highlightStyle}">
                                        <td style="padding:10px 10px 10px 16px; white-space:nowrap;">
                                            #${ord.number}
                                            ${ord.is_current ? '<span style="font-size:0.7rem; background:#f8f0d0; color:#162a1f; padding:1px 5px; border-radius:4px; margin-left:4px; font-weight:700;">Actual</span>' : ''}
                                        </td>
                                        <td style="padding:10px 10px; white-space:nowrap; color:var(--caja-text-muted, #94a3b8);">${ord.date}</td>
                                        <td style="padding:10px 10px; white-space:nowrap;">
                                            <span class="caja-status-pill ${ord.status_badge}" style="font-size:0.75rem; padding:2px 7px;">${ord.status_name}</span>
                                        </td>
                                        <td style="padding:10px 10px; font-weight:700; white-space:nowrap;">${ord.total}</td>
                                        <td style="padding:10px 16px 10px 10px; font-size:0.82rem; color:var(--caja-text-muted, #94a3b8); min-width:200px; max-width:320px;">
                                            ${ord.items_summary}
                                        </td>
                                    </tr>
                                `;
                            });

                            ordersTableHtml += `
                                        </tbody>
                                    </table>
                                </div>
                            `;
                        } else {
                            ordersTableHtml = `<p style="text-align:center; color:var(--caja-text-muted, #94a3b8); padding:24px 16px;">No se encontraron pedidos previos.</p>`;
                        }

                        const html = `
                            <!-- KPIs resumen -->
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:20px; padding:0 16px;">
                                <div style="background:var(--caja-card-bg, #1e293b); border:1px solid var(--caja-card-border, #334155); border-radius:10px; padding:12px 14px;">
                                    <span style="font-size:0.78rem; color:var(--caja-text-muted, #94a3b8); display:block;">Total pedidos realizados</span>
                                    <strong style="font-size:1.35rem; color:var(--caja-text, #ffffff);">${s.total_orders}</strong>
                                </div>
                                <div style="background:var(--caja-card-bg, #1e293b); border:1px solid var(--caja-card-border, #334155); border-radius:10px; padding:12px 14px;">
                                    <span style="font-size:0.78rem; color:var(--caja-text-muted, #94a3b8); display:block;">Pedidos completados</span>
                                    <strong style="font-size:1.35rem; color:#10b981;">${s.qualifying_orders}</strong>
                                </div>
                                <div style="background:var(--caja-card-bg, #1e293b); border:1px solid var(--caja-card-border, #334155); border-radius:10px; padding:12px 14px;">
                                    <span style="font-size:0.78rem; color:var(--caja-text-muted, #94a3b8); display:block;">Total gastado histórico</span>
                                    <strong style="font-size:1.2rem; color:var(--caja-text, #ffffff);">${s.total_spent}</strong>
                                </div>
                            </div>

                            <!-- Listado de pedidos (directo sin contenedor anidado y sin márgenes laterales) -->
                            <div style="margin-top:20px;">
                                <div style="padding:0 16px; margin-bottom:10px;">
                                    <h4 style="margin:0; font-size:0.95rem; display:flex; align-items:center; gap:6px; color:var(--caja-text, #ffffff);">
                                        <span>📋</span> Todos los pedidos del cliente
                                    </h4>
                                </div>
                                ${ordersTableHtml}
                            </div>
                        `;

                        $body.html(html);
                    } else {
                        $body.html('<p style="color:#ef4444; text-align:center; padding:20px;">No se pudo obtener el historial del cliente.</p>');
                    }
                },
                error: function() {
                    $body.html('<p style="color:#ef4444; text-align:center; padding:20px;">Error de red al consultar el historial.</p>');
                }
            });
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
                    </div>
                `;

                $container.append(cardHtml);
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
                    can_share_box: 'none',
                    share_box_max_alfajores: 6,
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
                can_share_box: 'none'
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
                    const cand = (self.cachedProducts || []).find(p => p.id === id);
                    if (cand && cand.product_type !== 'simple') {
                        return; // Omitir productos no simples por seguridad
                    }
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

            // Cálculo reactivo Sumar
            $('#calc-current-num').text(currentQty);
            $('#calc-incoming-num').text('+0');
            $('#calc-result-num').text(currentQty);

            // Cálculo reactivo Restar
            $('#calc-sub-current-num').text(currentQty);
            $('#calc-sub-incoming-num').text('-0');
            $('#calc-sub-result-num').text(currentQty);

            $('#stock-incoming-qty').val('');
            $('#stock-subtract-qty').val('');
            $('#stock-direct-qty').val(currentQty);
            $('#stock-modal-note').val('');

            $('#stock-prod-price').val(p.regular_price || '');
            $('#stock-prod-sale-price').val(p.sale_price || '');
            $('#stock-prod-manage-stock').prop('checked', p.manage_stock !== false);

            // Reiniciar botones de modo a "Sumar ingreso"
            $('.caja-stock-mode-btn').removeClass('active');
            $('#btn-mode-add').addClass('active');
            $('#caja-panel-mode-add').show();
            $('#caja-panel-mode-subtract').hide();
            $('#caja-panel-mode-set').hide();

            // Renderizar Historial de Cambios de Stock
            self.renderStockHistory(p.stock_history || []);

            $('#caja-modal-edit-stock').fadeIn(200);
            setTimeout(() => {
                $('#stock-incoming-qty').focus();
            }, 100);
        },

        renderStockHistory: function(history) {
            const self = this;
            const $list = $('#stock-modal-history-list');
            const $count = $('#stock-modal-history-count');
            $list.empty();

            if (!history || !Array.isArray(history) || history.length === 0) {
                $count.text('0 registros');
                $list.html(`
                    <div style="text-align:center; padding:18px 12px; color:#64748b; font-size:0.83rem; background:rgba(255,255,255,0.02); border-radius:6px;">
                        No hay registros de cambios de stock para este producto todavía.
                    </div>
                `);
                return;
            }

            $count.text(`${history.length} registro${history.length === 1 ? '' : 's'}`);

            history.forEach(entry => {
                let badgeClass = 'is-add';
                let badgeIcon = '➕';
                let badgeText = `Ingreso: +${entry.quantity || entry.qty || 0}`;
                if (entry.mode === 'subtract') {
                    badgeClass = 'is-subtract';
                    badgeIcon = '➖';
                    badgeText = `Egreso: -${entry.quantity || entry.qty || 0}`;
                } else if (entry.mode === 'set') {
                    badgeClass = 'is-set';
                    badgeIcon = '✏️';
                    badgeText = `Recuento: ${entry.new_stock !== undefined ? entry.new_stock : (entry.quantity || 0)}`;
                }

                const dateStr = entry.date_formatted || (entry.timestamp ? new Date(entry.timestamp * 1000).toLocaleString('es-AR') : '');
                const noteHtml = entry.note ? `<div class="caja-stock-history-note">💬 "${self.escapeHtml(entry.note)}"</div>` : '';
                const userStr = entry.user ? `<span class="caja-stock-history-user">👤 ${self.escapeHtml(entry.user)}</span>` : '';

                const itemHtml = `
                    <div class="caja-stock-history-item">
                        <div class="caja-stock-history-header">
                            <span class="caja-stock-history-badge ${badgeClass}">
                                <span>${badgeIcon}</span>
                                <span>${badgeText}</span>
                            </span>
                            <span class="caja-stock-history-date">${dateStr}</span>
                        </div>
                        <div class="caja-stock-history-detail">
                            <span>Stock: <strong>${entry.old_stock !== undefined ? entry.old_stock : '-'}</strong> ➔ <strong>${entry.new_stock !== undefined ? entry.new_stock : '-'}</strong></span>
                        </div>
                        ${noteHtml}
                        ${userStr}
                    </div>
                `;
                $list.append(itemHtml);
            });
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
            const subtractQty = $('#stock-subtract-qty').val();
            const directQty = $('#stock-direct-qty').val();
            const note = $('#stock-modal-note').val();

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_update_stock',
                    security: self.config.nonce,
                    product_id: productId,
                    mode: mode,
                    incoming_quantity: incomingQty,
                    subtract_quantity: subtractQty,
                    direct_quantity: directQty,
                    note: note,
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
                can_share_box: 'none'
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

        initPWA: function() {
            const self = this;

            // Asegurar etiqueta manifest en el <head> si no estuviera ya presente
            if (!document.querySelector('link[rel="manifest"]')) {
                const manifestUrl = (self.config && self.config.manifestUrl) || '/?batllie_caja_manifest=1';
                const link = document.createElement('link');
                link.rel = 'manifest';
                link.href = manifestUrl;
                document.head.appendChild(link);
            }

            // 1. Registrar Service Worker con Network-First
            if ('serviceWorker' in navigator) {
                const swUrl = (self.config && self.config.swUrl) || '/?batllie_caja_sw=1';
                navigator.serviceWorker.register(swUrl, { scope: '/' })
                    .then(function(reg) {
                        // SW registrado correctamente
                    })
                    .catch(function(err) {
                        console.warn('Caja POS Service Worker no pudo registrarse:', err);
                    });
            }

            // 2. Controlar evento de instalación de PWA (botón Instalar App)
            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                self.deferredInstallPrompt = e;

                const $installBtn = $('#caja-btn-install-pwa');
                if ($installBtn.length) {
                    $installBtn.show();
                    $installBtn.off('click').on('click', function() {
                        if (self.deferredInstallPrompt) {
                            self.deferredInstallPrompt.prompt();
                            self.deferredInstallPrompt.userChoice.then(function(choiceResult) {
                                if (choiceResult.outcome === 'accepted') {
                                    self.showToast('¡Instalando Caja POS en tu dispositivo!');
                                    $installBtn.hide();
                                }
                                self.deferredInstallPrompt = null;
                            });
                        }
                    });
                }
            });

            // 3. App instalada satisfactoriamente
            window.addEventListener('appinstalled', function() {
                self.deferredInstallPrompt = null;
                $('#caja-btn-install-pwa').hide();
                self.showToast('Caja POS instalada como aplicación.');
            });
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
            } else {
                // Fallback con swatch de color nativo interactivo para navegadores móviles
                $('#tab-settings .caja-color-field').each(function() {
                    const $input = $(this);
                    if (!$input.next('.caja-color-native-swatch').length) {
                        const val = $input.val() || '#000000';
                        const $colorInput = $('<input type="color" class="caja-color-native-swatch" style="margin-left:8px; vertical-align:middle; width:36px; height:32px; padding:0; border:1px solid rgba(255,255,255,0.2); border-radius:6px; cursor:pointer; background:none;">').val(val);
                        $colorInput.on('input change', function() {
                            $input.val($(this).val());
                        });
                        $input.on('input change', function() {
                            $colorInput.val($(this).val());
                        });
                        $input.after($colorInput);
                    }
                });
            }

            // Horarios de despacho (Shipping Slots) en pantalla POS
            $(document).off('click.cajaSlotAdd', '#caja-btn-add-slot').on('click.cajaSlotAdd', '#caja-btn-add-slot', function(e) {
                e.preventDefault();
                const val = ($('#caja-new-slot-input').val() || '').trim();
                if (!val || !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(val)) {
                    alert('Por favor ingresá un horario válido en formato HH:MM (ej: 20:00)');
                    return;
                }
                const parts = val.split(':');
                const formatted = ('0' + parseInt(parts[0], 10)).slice(-2) + ':' + ('0' + parseInt(parts[1], 10)).slice(-2);
                
                let exists = false;
                $('.caja-slot-chip').each(function() {
                    if ($(this).attr('data-slot') === formatted) exists = true;
                });
                if (exists) {
                    alert('El horario ' + formatted + ' ya está en la lista.');
                    return;
                }

                const chipHtml = '<div class="caja-slot-chip" data-slot="' + formatted + '">' +
                    '<input type="hidden" name="batllie_caja_options[shipping_slots][]" value="' + formatted + '" />' +
                    '<span class="caja-slot-icon">🕒</span> ' +
                    '<span class="caja-slot-text">' + formatted + ' hs</span> ' +
                    '<button type="button" class="caja-slot-remove-btn" title="Eliminar horario">✕</button>' +
                '</div>';

                $('#caja-slots-list').append(chipHtml);
                $('#caja-new-slot-input').val('');
            });

            $(document).off('click.cajaSlotRemove', '.caja-slot-remove-btn').on('click.cajaSlotRemove', '.caja-slot-remove-btn', function(e) {
                e.preventDefault();
                $(this).closest('.caja-slot-chip').remove();
            });

            // Tiempo límite de cancelación de pedidos pendientes en pantalla POS
            function formatHoldStockPreviewPos(mins) {
                mins = parseInt(mins, 10);
                if (!mins || mins <= 0) return 'tiempo determinado';
                if (mins >= 1440 && mins % 1440 === 0) {
                    const d = Math.floor(mins / 1440);
                    return `${d} ${d === 1 ? 'día' : 'días'}`;
                }
                if (mins >= 60) {
                    const h = Math.floor(mins / 60);
                    const r = mins % 60;
                    if (r === 0) {
                        return `${h} ${h === 1 ? 'hora' : 'horas'}`;
                    } else {
                        return `${h} ${h === 1 ? 'hora' : 'horas'} y ${r} ${r === 1 ? 'minuto' : 'minutos'}`;
                    }
                }
                return `${mins} ${mins === 1 ? 'minuto' : 'minutos'}`;
            }

            function updateHoldStockPreviewPos() {
                const val = parseInt($('#caja_setting_hold_stock_minutes').val(), 10) || 60;
                const text = formatHoldStockPreviewPos(val);
                $('#caja-hold-stock-preview-text').html(`El cliente tiene un máximo de <strong>${text}</strong> para pagar el pedido, sino se cancela automáticamente.`);
                if ($('#caja_select_hold_stock_presets option[value="' + val + '"]').length) {
                    $('#caja_select_hold_stock_presets').val(val);
                } else {
                    $('#caja_select_hold_stock_presets').val('');
                }
            }

            $(document).off('input.cajaHoldStock change.cajaHoldStock', '#caja_setting_hold_stock_minutes')
                .on('input.cajaHoldStock change.cajaHoldStock', '#caja_setting_hold_stock_minutes', updateHoldStockPreviewPos);

            $(document).off('change.cajaHoldStockPreset', '#caja_select_hold_stock_presets')
                .on('change.cajaHoldStockPreset', '#caja_select_hold_stock_presets', function() {
                    const v = $(this).val();
                    if (v) {
                        $('#caja_setting_hold_stock_minutes').val(v).trigger('input');
                    }
                });

            updateHoldStockPreviewPos();

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

            let formData = $form.serialize();
            if (formData.indexOf('action=emp_caja_save_settings') === -1) {
                if (formData.indexOf('action=') !== -1) {
                    formData = formData.replace(/action=[^&]*/, 'action=emp_caja_save_settings');
                } else {
                    formData += '&action=emp_caja_save_settings';
                }
            }
            if (typeof batllieCajaConfig !== 'undefined' && batllieCajaConfig.nonce) {
                if (formData.indexOf('security=') !== -1) {
                    formData = formData.replace(/security=[^&]*/, 'security=' + encodeURIComponent(batllieCajaConfig.nonce));
                } else {
                    formData += '&security=' + encodeURIComponent(batllieCajaConfig.nonce);
                }
            }

            const ajaxUrl = (typeof batllieCajaConfig !== 'undefined' && batllieCajaConfig.ajaxUrl)
                ? batllieCajaConfig.ajaxUrl
                : (self.config && self.config.ajaxUrl ? self.config.ajaxUrl : '/wp-admin/admin-ajax.php');

            $.ajax({
                url: ajaxUrl,
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
                error: function(xhr, status, error) {
                    $btns.prop('disabled', false).removeClass('is-loading');
                    let msg = 'Error de comunicación con el servidor.';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        msg = xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.data && parsed.data.message) {
                                msg = parsed.data.message;
                            }
                        } catch(e) {
                            if (xhr.status === 403) {
                                msg = 'La sesión ha expirado o no tienes permisos. Por favor recarga la página.';
                            } else if (xhr.status === 400 || xhr.responseText === '0') {
                                msg = 'Error en el servidor: acción no registrada o inválida.';
                            }
                        }
                    }
                    $feedback.addClass('is-error').text('❌ ' + msg);
                    self.showToast('❌ ' + msg);
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

            if (s.hold_stock_minutes !== undefined) {
                const hsm = parseInt(s.hold_stock_minutes, 10);
                if (typeof batllieCajaConfig !== 'undefined') {
                    batllieCajaConfig.holdStockMinutes = hsm;
                }
                if (this.config) {
                    this.config.holdStockMinutes = hsm;
                }
                this.updatePosUnpaidWarning();
            }
        },

        /* ==========================================================================
           MÓDULO VENTA CAJA (PUNTO DE VENTA POS UNIFICADO)
           ========================================================================== */
        posCart: [],
        posEventsBound: false,
        posHistoryLoaded: false,
        posSearchQuery: '',
        currentPosShippingCost: 0,
        currentPosShippingMethodTitle: '',
        currentPosShippingMethodId: '',

        initPosTab: function() {
            const self = this;
            this.bindPosEvents();
            this.updatePosCustomerRequirements();
            this.updatePosUnpaidWarning();
            this.renderPosPackagingSection();

            const perms = this.config.currentUserPermissions || {};
            if (!perms.is_admin && !perms.can_change_sector && perms.assigned_sectors && perms.assigned_sectors.length) {
                this.gastroSector = perms.assigned_sectors[0];
                this.gastroTable = '';
            } else if (!this.gastroSector) {
                this.gastroSector = $('#caja-pos-selected-sector').val() || 'Barra Principal';
            }

            if (this.gastroSector && this.gastroSector.toLowerCase().indexOf('take') !== -1) {
                $('#caja-pos-selected-mode').val('pedido_despachar');
                $('.caja-pos-switch-row').removeClass('is-active');
                $('#pos-row-pedido-despachar').addClass('is-active');
                $('#caja-pos-dispatch-subgroup').show();
                $('#caja-pos-status-dropdown-wrap').show();
                $('#caja-pos-checkout-btn').addClass('is-dispatch');
                $('#caja-pos-checkout-text').text('Enviar a Cola de Despacho');
                $('#caja-pos-customer-body').show();
                $('#caja-pos-cust-toggle-icon').text('▲');
            }

            const needsProducts = !this.cachedProducts || !this.cachedProducts.length;
            const needsCategories = !this.cachedCategories || !this.cachedCategories.length;

            if (needsProducts || needsCategories) {
                $('#caja-pos-categories-accordion').html(`
                    <div class="caja-pos-loading-catalog" style="text-align: center; padding: 40px 20px;">
                        <span class="caja-spinner">⏳</span>
                        <p style="margin-top: 10px; color: var(--caja-text-muted, #94a3b8);">Cargando categorías y productos...</p>
                    </div>
                `);

                this.loadCategories(function() {
                    self.loadProducts(false, function() {
                        self.renderPosCategoriesAccordion();
                    });
                });
            } else {
                this.renderPosCategoriesAccordion();
            }
        },

        renderPosCategoriesAccordion: function() {
            const self = this;
            const $container = $('#caja-pos-categories-accordion');
            if (!$container.length) return;

            const query = (this.posSearchQuery || '').trim().toLowerCase();

            // Filtrar productos excluyendo cajas y empaques (no se venden como productos de góndola)
            const availableProducts = (this.cachedProducts || []).filter(p => {
                if (p.is_packaging) return false;
                if (self.packagingProducts && self.packagingProducts.some(pkg => pkg.id == p.id)) return false;
                const pCats = (p.categories || '').toLowerCase();
                const pSlugs = Array.isArray(p.category_slugs) ? p.category_slugs.map(s => String(s).toLowerCase()) : [];
                if (pCats.includes('empaques') || pSlugs.includes('empaques')) return false;
                if (p.is_official_box || p.official_box_role) return false;
                const pName = (p.name || '').toLowerCase();
                if (pName.includes('caja de empaque') || pName.includes('(empaque)') || pName.includes('paquete / caja')) return false;
                return true;
            });

            // MODO BÚSQUEDA ACTIVA
            if (query.length > 0) {
                let matches = availableProducts.filter(p => {
                    const nameMatch = p.name && p.name.toLowerCase().includes(query);
                    const skuMatch = p.sku && p.sku.toLowerCase().includes(query);
                    return nameMatch || skuMatch;
                });

                if (matches.length === 0) {
                    $container.html(`
                        <div style="text-align: center; padding: 40px 20px; color: var(--caja-text-muted);">
                            <span style="font-size: 2.2rem; display:block; margin-bottom: 8px;">🔍</span>
                            No se encontraron productos coincidentes con "<strong>${self.escapeHtml(this.posSearchQuery)}</strong>".
                        </div>
                    `);
                    return;
                }

                let prodsHtml = '<div class="caja-pos-cat-body" style="padding: 12px 14px; background: transparent;">';
                matches.forEach(p => {
                    prodsHtml += self.buildPosProductRowHtml(p);
                });
                prodsHtml += '</div>';

                $container.html(prodsHtml);
                return;
            }

            // MODO ACORDEÓN DE CATEGORÍAS
            let categoriesMap = new Map();

            // 1. Agregar categorías desde backend
            if (this.cachedCategories && this.cachedCategories.length) {
                this.cachedCategories.forEach(c => {
                    const cSlug = (c.slug || '').toLowerCase();
                    const cName = (c.name || '').toLowerCase();
                    if (cSlug === 'empaques' || cName === 'empaques') return;
                    categoriesMap.set(Number(c.id), {
                        id: Number(c.id),
                        name: c.name,
                        slug: c.slug,
                        image_url: c.image_url || '',
                        products: []
                    });
                });
            }

            // 2. Distribuir productos disponibles en las categorías
            if (availableProducts.length) {
                availableProducts.forEach(p => {
                    const catIds = Array.isArray(p.category_ids) ? p.category_ids.map(Number) : [];
                    const catSlugs = Array.isArray(p.category_slugs) ? p.category_slugs : [];

                    if (catIds.length > 0) {
                        catIds.forEach((cid, idx) => {
                            const slug = catSlugs[idx] || '';
                            if (slug.toLowerCase() === 'empaques') return;
                            if (!categoriesMap.has(cid)) {
                                const name = p.categories ? p.categories.split(',')[idx] || ('Categoría ' + cid) : ('Categoría ' + cid);
                                if (name.trim().toLowerCase() === 'empaques') return;
                                categoriesMap.set(cid, {
                                    id: cid,
                                    name: name.trim(),
                                    slug: slug,
                                    image_url: '',
                                    products: []
                                });
                            }
                            categoriesMap.get(cid).products.push(p);
                        });
                    } else {
                        const uncatId = 999999;
                        if (!categoriesMap.has(uncatId)) {
                            categoriesMap.set(uncatId, {
                                id: uncatId,
                                name: 'Sin categorizar',
                                slug: 'sin-categorizar',
                                image_url: '',
                                products: []
                            });
                        }
                        categoriesMap.get(uncatId).products.push(p);
                    }
                });
            }

            // Filtrar solo categorías que contengan productos y no sean Empaques
            let catList = Array.from(categoriesMap.values()).filter(c => {
                if (!c.products || !c.products.length) return false;
                const cSlug = (c.slug || '').toLowerCase();
                const cName = (c.name || '').toLowerCase();
                return cSlug !== 'empaques' && cName !== 'empaques';
            });
            catList.sort((a, b) => a.name.localeCompare(b.name));

            if (catList.length === 0) {
                $container.html(`
                    <div style="text-align: center; padding: 40px 20px; color: var(--caja-text-muted);">
                        <span style="font-size: 2.2rem; display:block; margin-bottom: 8px;">📦</span>
                        No hay productos disponibles para mostrar.
                    </div>
                `);
                return;
            }

            const perms = this.config.currentUserPermissions || {};
            const layout = self.productLayout || $('#caja-pos-current-layout').val() || perms.product_layout || 'grid';
            const gridCols = self.productGridCols || $('#caja-pos-current-grid-cols').val() || perms.product_grid_cols || '2';
            self.productLayout = layout;
            self.productGridCols = gridCols;

            const bodyClass = (layout === 'grid') 
                ? `is-grid-layout is-grid-cols-${gridCols}` 
                : 'is-list-layout';

            // Recordar qué categorías estaban abiertas antes del re-render (si aplica)
            const openCatIds = [];
            $container.find('.caja-pos-cat-item.is-open').each(function() {
                const cid = $(this).data('cat-id');
                if (cid !== undefined && cid !== null) openCatIds.push(String(cid));
            });

            let html = '';
            catList.forEach((c, idx) => {
                // Ordenar productos de la categoría por nombre
                c.products.sort((a, b) => a.name.localeCompare(b.name));

                // Calcular cuántas unidades seleccionadas hay en esta categoría
                let catSelectedQty = 0;
                c.products.forEach(prod => {
                    const inCart = self.posCart.find(i => i.id == prod.id);
                    if (inCart) catSelectedQty += inCart.quantity;
                });

                let prodsHtml = '';
                c.products.forEach(p => {
                    prodsHtml += self.buildPosProductRowHtml(p);
                });

                const isOpen = openCatIds.includes(String(c.id));
                const displayStyle = isOpen 
                    ? (layout === 'grid' ? 'display:grid;' : 'display:flex;') 
                    : 'display:none;';

                html += `
                    <div class="caja-pos-cat-item ${isOpen ? 'is-open' : ''}" data-cat-id="${c.id}">
                        <div class="caja-pos-cat-header">
                            <div class="caja-pos-cat-header-left">
                                <span class="caja-pos-cat-icon">📁</span>
                                <span class="caja-pos-cat-title">${self.escapeHtml(c.name)}</span>
                                <span class="caja-pos-cat-count">(${c.products.length} ${c.products.length === 1 ? 'producto' : 'productos'})</span>
                            </div>
                            <div class="caja-pos-cat-header-right">
                                <span class="caja-pos-cat-badge-qty" id="caja-pos-cat-badge-${c.id}" style="${catSelectedQty > 0 ? '' : 'display:none;'}">${catSelectedQty}</span>
                                <span class="caja-pos-cat-chevron">▼</span>
                            </div>
                        </div>
                        <div class="caja-pos-cat-body ${bodyClass}" style="${displayStyle}">
                            ${prodsHtml}
                        </div>
                    </div>
                `;
            });

            $container.html(html);
        },

        buildPosProductRowHtml: function(p) {
            const self = this;
            const isVariable = (p.product_type === 'variable' && p.variations && p.variations.length > 0);

            // Cantidad total seleccionada para este producto (sumando variaciones si existen)
            let totalQtyInCart = 0;
            self.posCart.forEach(i => {
                if (i.product_id == p.id || i.id == p.id) {
                    totalQtyInCart += i.quantity;
                }
            });

            const isOutOfStock = p.manage_stock && (p.stock_quantity === null || p.stock_quantity <= 0);

            const rawPrice = (p.price_raw !== undefined && p.price_raw !== null) ? parseFloat(p.price_raw) : parseFloat(p.regular_price || 0);
            const price = '$' + self.formatCurrencyNumber(rawPrice);
            const imgSrc = p.image_url || p.image || '';

            let stockBadge = '';
            if (isVariable) {
                stockBadge = `<span class="caja-pos-prod-stock is-variable">${p.variations.length} opciones</span>`;
            } else if (!p.manage_stock) {
                stockBadge = '<span class="caja-pos-prod-stock has-stock">Stock ilimitado</span>';
            } else if (p.stock_quantity !== null && p.stock_quantity > 0) {
                stockBadge = `<span class="caja-pos-prod-stock has-stock">${p.stock_quantity} disp.</span>`;
            } else {
                stockBadge = '<span class="caja-pos-prod-stock no-stock">Agotado</span>';
            }

            let stepperHtml = '';
            if (isVariable) {
                if (totalQtyInCart > 0) {
                    stepperHtml = `
                        <button type="button" class="caja-pos-btn-var-trigger has-items" data-id="${p.id}" title="Ver o modificar opciones seleccionadas">
                            <span class="caja-pos-var-count">${totalQtyInCart}</span>
                            <span class="caja-pos-var-label">Opciones ▾</span>
                        </button>
                    `;
                } else {
                    stepperHtml = `
                        <button type="button" class="caja-pos-btn-var-trigger" data-id="${p.id}" title="Elegir variación">
                            <span class="caja-pos-var-label">Elegir ▾</span>
                        </button>
                    `;
                }
            } else {
                stepperHtml = `
                    <button type="button" class="caja-pos-btn-qty btn-minus" data-id="${p.id}" title="Restar" ${totalQtyInCart === 0 ? 'disabled' : ''}>−</button>
                    <span class="caja-pos-qty-val" data-id="${p.id}">${totalQtyInCart}</span>
                    <button type="button" class="caja-pos-btn-qty btn-plus" data-id="${p.id}" title="Sumar" ${isOutOfStock ? 'disabled' : ''}>+</button>
                `;
            }

            if (self.productLayout === 'grid') {
                return `
                    <div class="caja-pos-prod-card ${totalQtyInCart > 0 ? 'has-selected' : ''} ${isOutOfStock ? 'is-out-of-stock' : ''} ${isVariable ? 'is-variable-row' : ''}" data-id="${p.id}">
                        <div class="caja-pos-prod-card-thumb">
                            ${imgSrc ? `<img src="${imgSrc}" alt="${self.escapeHtml(p.name)}" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='';" /><span class="caja-pos-fallback-icon" style="display:none;">🍸</span>` : '<span class="caja-pos-fallback-icon">🍸</span>'}
                            ${stockBadge ? `<div class="caja-pos-card-stock-pill">${stockBadge}</div>` : ''}
                        </div>
                        <div class="caja-pos-prod-card-body">
                            <div class="caja-pos-prod-card-title" title="${self.escapeHtml(p.name)}">${self.escapeHtml(p.name)}</div>
                            <div class="caja-pos-prod-card-price">${price}</div>
                            <div class="caja-pos-prod-card-stepper">
                                ${stepperHtml}
                            </div>
                        </div>
                    </div>
                `;
            }

            return `
                <div class="caja-pos-prod-row ${totalQtyInCart > 0 ? 'has-selected' : ''} ${isOutOfStock ? 'is-out-of-stock' : ''} ${isVariable ? 'is-variable-row' : ''}" data-id="${p.id}">
                    <div class="caja-pos-prod-thumb">
                        ${imgSrc ? `<img src="${imgSrc}" alt="${self.escapeHtml(p.name)}" style="width:100%; height:100%; object-fit:cover; border-radius:8px;" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='';" /><span style="display:none;">📦</span>` : '📦'}
                    </div>
                    <div class="caja-pos-prod-details">
                        <div class="caja-pos-prod-title" title="${self.escapeHtml(p.name)}">${self.escapeHtml(p.name)}</div>
                        <div class="caja-pos-prod-sub">
                            <strong class="caja-pos-prod-price">${price}</strong>
                            ${stockBadge}
                        </div>
                    </div>
                    <div class="caja-pos-prod-stepper">
                        ${stepperHtml}
                    </div>
                </div>
            `;
        },

        bindPosEvents: function() {
            if (this.posEventsBound) return;
            this.posEventsBound = true;
            const self = this;

            // Búsqueda rápida
            $('#caja-pos-search-input').on('input', function() {
                const val = $(this).val();
                self.posSearchQuery = val;
                if (val.length > 0) {
                    $('#caja-pos-search-clear').show();
                } else {
                    $('#caja-pos-search-clear').hide();
                }
                self.renderPosCategoriesAccordion();
            });

            // Limpiar búsqueda
            $('#caja-pos-search-clear').on('click', function() {
                $('#caja-pos-search-input').val('');
                self.posSearchQuery = '';
                $(this).hide();
                self.renderPosCategoriesAccordion();
            });

            // Conmutador de vista de productos (Lista vs Grilla 2 o 3 columnas)
            $(document).on('click', '.caja-pos-layout-btn', function(e) {
                e.preventDefault();
                $('.caja-pos-layout-btn').removeClass('active');
                $(this).addClass('active');

                const layout = $(this).data('layout');
                const cols = String($(this).data('cols') || '2');

                self.productLayout = layout;
                self.productGridCols = cols;
                $('#caja-pos-current-layout').val(layout);
                $('#caja-pos-current-grid-cols').val(cols);

                self.renderPosCategoriesAccordion();
            });

            // Click táctil en tarjeta de producto simple para sumar +1 rápido
            $(document).on('click', '.caja-pos-prod-card:not(.is-variable-row)', function(e) {
                if ($(e.target).closest('.caja-pos-prod-card-stepper').length) return;
                const prodId = parseInt($(this).data('id'));
                if (prodId && !$(this).hasClass('is-out-of-stock')) {
                    self.changePosCartQuantity(prodId, 1);
                }
            });

            // Click táctil en tarjeta de producto variable
            $(document).on('click', '.caja-pos-prod-card.is-variable-row', function(e) {
                if ($(e.target).closest('.caja-pos-btn-var-trigger').length) return;
                const prodId = parseInt($(this).data('id'));
                if (prodId) {
                    self.openPosVariableModal(prodId);
                }
            });

            // Toggle de acordeón de categorías
            $(document).on('click', '.caja-pos-cat-header', function(e) {
                e.preventDefault();
                const $item = $(this).closest('.caja-pos-cat-item');
                const $body = $item.find('.caja-pos-cat-body');

                if ($item.hasClass('is-open')) {
                    $item.removeClass('is-open');
                    $body.stop(true, false).slideUp(180, function() {
                        $(this).hide();
                    });
                } else {
                    $item.addClass('is-open');
                    $body.stop(true, false).slideDown(180, function() {
                        if ($(this).hasClass('is-grid-layout')) {
                            $(this).css('display', 'grid');
                        } else {
                            $(this).css('display', 'flex');
                        }
                    });
                }
            });

            // Stepper: Sumar unidad (+)
            $(document).on('click', '.caja-pos-btn-qty.btn-plus', function(e) {
                e.stopPropagation();
                const prodId = parseInt($(this).data('id'));
                if (prodId) {
                    self.changePosCartQuantity(prodId, 1);
                }
            });

            // Stepper: Restar unidad (-)
            $(document).on('click', '.caja-pos-btn-qty.btn-minus', function(e) {
                e.stopPropagation();
                const prodId = parseInt($(this).data('id'));
                if (prodId) {
                    self.changePosCartQuantity(prodId, -1);
                }
            });

            // Eventos de Productos Variables (Selector de Opciones y Modal)
            $(document).on('click', '.caja-pos-btn-var-trigger', function(e) {
                e.stopPropagation();
                const prodId = parseInt($(this).data('id'));
                if (prodId) {
                    self.openPosVariableModal(prodId);
                }
            });

            $(document).on('click', '.caja-pos-prod-row.is-variable-row', function(e) {
                if ($(e.target).closest('.caja-pos-prod-stepper').length) {
                    return;
                }
                const prodId = parseInt($(this).data('id'));
                if (prodId) {
                    self.openPosVariableModal(prodId);
                }
            });

            $(document).on('click', '#caja-pos-var-close-btn, #caja-pos-var-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-pos-variable').fadeOut(150);
                self.currentVarModal = null;
            });

            $(document).on('click', '.caja-pos-var-pill:not(:disabled)', function(e) {
                e.preventDefault();
                if (!self.currentVarModal) return;
                const attrSlug = $(this).attr('data-attr');
                const val = $(this).attr('data-val');
                if (attrSlug && val) {
                    self.currentVarModal.selectedAttrs[attrSlug] = val.toLowerCase();
                    self.updatePosVarModalState();
                }
            });

            $(document).on('click', '#caja-pos-var-btn-minus', function(e) {
                e.preventDefault();
                if (!self.currentVarModal) return;
                self.currentVarModal.qty = Math.max(1, (self.currentVarModal.qty || 1) - 1);
                self.updatePosVarModalState();
            });

            $(document).on('click', '#caja-pos-var-btn-plus', function(e) {
                e.preventDefault();
                if (!self.currentVarModal) return;
                const matched = self.currentVarModal.matchedVar;
                const newQty = (self.currentVarModal.qty || 1) + 1;
                if (matched && matched.manage_stock && matched.stock_quantity !== null) {
                    if (newQty > matched.stock_quantity) {
                        self.showToast(`Stock máximo de esta variación: ${matched.stock_quantity}.`, 'warning');
                        return;
                    }
                }
                self.currentVarModal.qty = newQty;
                self.updatePosVarModalState();
            });

            $(document).on('click', '#caja-pos-var-add-btn:not(:disabled)', function(e) {
                e.preventDefault();
                self.addCurrentVariableToCart();
            });

            $(document).on('click', '.caja-pos-var-chip-remove', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const key = $(this).attr('data-cart-key');
                if (!key || !self.posCart) return;

                const existingIndex = self.posCart.findIndex(i => (i.cartKey === key || `${i.product_id}_${i.variation_id}` === key));
                if (existingIndex !== -1) {
                    const item = self.posCart[existingIndex];
                    if (item.quantity > 1) {
                        item.quantity -= 1;
                    } else {
                        self.posCart.splice(existingIndex, 1);
                    }
                    const prodId = item.product_id || item.id;
                    self.updatePosProductRowState(prodId);
                    self.renderCurrentProductVariationsInModal(prodId);
                }
            });

            // Vaciar selección completa
            $('#caja-pos-clear-cart').on('click', function() {
                if (self.posCart.length === 0) return;
                self.clearPosCart();
            });

            // Conmutador Modo de Orden estilo Switch iOS (Compra Realizada vs Pedido por Despachar)
            $(document).on('click', '.caja-pos-switch-row', function() {
                const mode = $(this).data('mode');
                const perms = self.config.currentUserPermissions || {};

                if (mode === 'pedido_despachar') {
                    // "solo si tiene el permiso de ambos una caja y take away, por despachar está vinculado solamente a take away, si no tiene esta opción no se habilita take away o vise versa"
                    const hasTakeawayPerm = perms.is_admin || (perms.can_dispatch_orders && perms.assigned_sectors && perms.assigned_sectors.some(s => s.toLowerCase().indexOf('take') !== -1));
                    if (!hasTakeawayPerm) {
                        self.showToast('No tienes permisos habilitados para Take Away ni pedidos por despachar.', 'warning');
                        return false;
                    }

                    // "sino si es una caja distinta y si toca en pedidos por despachar cambie a take away"
                    const $takeawayChip = $('.caja-gastro-chip').filter(function() {
                        return ($(this).data('sector') || '').toLowerCase().indexOf('take') !== -1;
                    });

                    if ($takeawayChip.length) {
                        $('.caja-gastro-chip').removeClass('active');
                        $takeawayChip.addClass('active');
                        const icon = $takeawayChip.data('icon') || '🛍️';
                        const sec = $takeawayChip.data('sector') || 'Take Away';
                        self.gastroSector = sec;
                        $('#caja-pos-selected-sector').val(sec);
                        $('#caja-gastro-tables-wrap').slideUp(150);
                        self.gastroTable = '';
                        const lockPrefix = (!perms.is_admin && !perms.can_change_sector) ? '🔒 ' : '';
                        $('#caja-gastro-selected-badge').text(`${lockPrefix}${icon} ${sec}`);
                    }

                    // Abrir datos del cliente automáticamente
                    $('#caja-pos-customer-body').slideDown(180);
                    $('#caja-pos-cust-toggle-icon').text('▲');
                } else if (mode === 'compra_realizada') {
                    // Si estaba en Take Away y toca Compra Realizada, cambia a la primera barra disponible
                    if ((self.gastroSector || '').toLowerCase().indexOf('take') !== -1) {
                        const $barChip = $('.caja-gastro-chip').filter(function() {
                            return ($(this).data('sector') || '').toLowerCase().indexOf('take') === -1;
                        }).first();
                        if ($barChip.length) {
                            $('.caja-gastro-chip').removeClass('active');
                            $barChip.addClass('active');
                            const icon = $barChip.data('icon') || '🍸';
                            const sec = $barChip.data('sector') || 'Barra Principal';
                            self.gastroSector = sec;
                            $('#caja-pos-selected-sector').val(sec);
                            const lockPrefix = (!perms.is_admin && !perms.can_change_sector) ? '🔒 ' : '';
                            $('#caja-gastro-selected-badge').text(`${lockPrefix}${icon} ${sec}`);
                        }
                    }
                }

                $('.caja-pos-switch-row').removeClass('is-active');
                $(this).addClass('is-active');
                $('#caja-pos-selected-mode').val(mode);

                const $btn = $('#caja-pos-checkout-btn');
                const $text = $('#caja-pos-checkout-text');
                const $dispatchSubgroup = $('#caja-pos-dispatch-subgroup');
                const $statusDropdownWrap = $('#caja-pos-status-dropdown-wrap');

                if (mode === 'compra_realizada') {
                    $btn.removeClass('is-dispatch');
                    $text.text('Confirmar y Cobrar');
                    $dispatchSubgroup.slideUp(180);
                    $statusDropdownWrap.slideUp(180);
                    $('#caja-pos-selected-status').val('pagado');
                } else {
                    $btn.addClass('is-dispatch');
                    $text.text('Enviar a Cola de Despacho');
                    $dispatchSubgroup.slideDown(180);
                    $statusDropdownWrap.slideDown(180);
                }
                self.updatePosCustomerRequirements();
                self.updatePosUnpaidWarning();
            });

            // Estado de pago en Despacho (Pagado vs Falta Pagar)
            $(document).on('change', '#caja-pos-selected-status', function() {
                self.updatePosUnpaidWarning();
            });

            // Toggle de la sección colapsable de Empaque / Bolsas
            $(document).on('click', '#caja-pos-packaging-header', function() {
                const $body = $('#caja-pos-packaging-body');
                const $icon = $('#caja-pos-pkg-toggle-icon');
                if ($body.is(':visible')) {
                    $body.slideUp(180);
                    $icon.text('▼');
                } else {
                    $body.slideDown(180);
                    $icon.text('▲');
                }
            });

            // Stepper de empaque: sumar unidad (+)
            $(document).on('click', '.caja-pos-pkg-btn-plus', function(e) {
                e.stopPropagation();
                const pkgId = parseInt($(this).data('id'), 10);
                const pkg = (self.packagingProducts || []).find(p => p.id == pkgId);
                const $val = $(`#caja-pos-pkg-qty-${pkgId}`);
                let qty = parseInt($val.text(), 10) || 0;

                if (pkg && pkg.managing_stock && pkg.stock !== null && (qty + 1) > pkg.stock) {
                    self.showToast(`Stock máximo alcanzado para ${pkg.name} (${pkg.stock} disp.)`, 'warning');
                    return;
                }
                qty++;
                $val.text(qty);
                $(this).siblings('.caja-pos-pkg-btn-minus').prop('disabled', false);
                $(this).closest('.caja-pos-pkg-row').addClass('has-selected');
                self.updatePosPackagingBadge();
            });

            // Stepper de empaque: restar unidad (-)
            $(document).on('click', '.caja-pos-pkg-btn-minus', function(e) {
                e.stopPropagation();
                const pkgId = parseInt($(this).data('id'), 10);
                const $val = $(`#caja-pos-pkg-qty-${pkgId}`);
                let qty = parseInt($val.text(), 10) || 0;
                if (qty > 0) {
                    qty--;
                    $val.text(qty);
                }
                if (qty === 0) {
                    $(this).prop('disabled', true);
                    $(this).closest('.caja-pos-pkg-row').removeClass('has-selected');
                }
                self.updatePosPackagingBadge();
            });

            // Toggle de la sección "+ Agregar nuevo empaque" en POS
            $(document).on('click', '#caja-pos-btn-toggle-add-pkg', function(e) {
                e.preventDefault();
                const $content = $('#caja-pos-pkg-add-content');
                const $arrow = $('#caja-pos-add-pkg-arrow');
                if ($content.is(':visible')) {
                    $content.slideUp(180);
                    $arrow.text('▼');
                } else {
                    $content.slideDown(180);
                    $arrow.text('▲');
                }
            });

            // Vincular producto existente de WooCommerce como empaque desde POS
            $(document).on('click', '#caja-pos-btn-link-pkg', function(e) {
                e.preventDefault();
                const pId = parseInt($('#caja-pos-sel-exist-pkg').val(), 10);
                if (!pId) {
                    self.showToast('Selecciona un producto para vincular.', 'warning');
                    return;
                }
                const $btn = $(this);
                const origText = $btn.text();
                $btn.prop('disabled', true).text('Vinculando...');

                self.addPackagingItem(pId, '', 0, null, function(ok) {
                    $btn.prop('disabled', false).text(origText);
                    if (ok) {
                        $('#caja-pos-pkg-add-content').slideUp(180);
                        $('#caja-pos-add-pkg-arrow').text('▼');
                        self.renderPosPackagingSection();
                        if (typeof self.renderPosCategoriesAccordion === 'function') {
                            self.renderPosCategoriesAccordion();
                        }
                    }
                });
            });

            // Crear nuevo producto de empaque oficial desde POS
            $(document).on('click', '#caja-pos-btn-create-pkg', function(e) {
                e.preventDefault();
                const name = $.trim($('#caja-pos-new-pkg-name').val() || '');
                const stock = parseInt($('#caja-pos-new-pkg-stock').val(), 10) || 0;

                if (!name) {
                    self.showToast('Ingresa un nombre para el nuevo empaque.', 'warning');
                    $('#caja-pos-new-pkg-name').focus();
                    return;
                }

                const $btn = $(this);
                const origText = $btn.text();
                $btn.prop('disabled', true).text('Creando...');

                self.addPackagingItem(0, name, stock, null, function(ok) {
                    $btn.prop('disabled', false).text(origText);
                    if (ok) {
                        $('#caja-pos-new-pkg-name').val('');
                        $('#caja-pos-pkg-add-content').slideUp(180);
                        $('#caja-pos-add-pkg-arrow').text('▼');
                        self.renderPosPackagingSection();
                        if (typeof self.renderPosCategoriesAccordion === 'function') {
                            self.renderPosCategoriesAccordion();
                        }
                    }
                });
            });

            // Selector de Entrega en Despacho (Con Envío vs Retiro por el Local)
            $(document).on('click', '.caja-pos-dispatch-chip', function() {
                $('.caja-pos-dispatch-chip').removeClass('active');
                $(this).addClass('active');
                const delivery = $(this).data('delivery');
                $('#caja-pos-selected-delivery').val(delivery);
                self.updatePosCustomerRequirements();
            });

            // Selector de Método de Pago
            $('.caja-pos-pay-chip').on('click', function() {
                $('.caja-pos-pay-chip').removeClass('active');
                $(this).addClass('active');
                const pay = $(this).data('pay');
                $('#caja-pos-selected-pay').val(pay);

                if (pay === 'tarjeta') {
                    $('#caja-pos-card-op-wrap').slideDown(150);
                    setTimeout(() => $('#caja-pos-card-op').focus(), 160);
                } else {
                    $('#caja-pos-card-op-wrap').slideUp(150);
                }

                if (pay === 'efectivo') {
                    $('#caja-pos-cash-calc-wrap').slideDown(150);
                    self.recalcCashChange();
                } else {
                    $('#caja-pos-cash-calc-wrap').slideUp(150);
                }
            });

            // Selector de Sector / Barra Gastro
            $(document).on('click', '.caja-gastro-chip', function(e) {
                e.preventDefault();
                if ($(this).hasClass('is-locked')) return;

                const perms = self.config.currentUserPermissions || {};
                const sector = $(this).data('sector') || 'Barra Principal';
                const isMesa = sector.toLowerCase().indexOf('mesa') !== -1;
                const isTakeAway = sector.toLowerCase().indexOf('take') !== -1;

                // Bloqueo de Mesas para usuarios que no son Administradores
                if (isMesa && !perms.is_admin && !perms.can_change_sector) {
                    self.showToast('Solo los administradores pueden alternar a mesas.', 'error');
                    return;
                }

                // Bloqueo de Take Away si no tiene permisos de despacho / Take Away
                if (isTakeAway) {
                    const hasTakeawayPerm = perms.is_admin || (perms.can_dispatch_orders && perms.assigned_sectors && perms.assigned_sectors.some(s => s.toLowerCase().indexOf('take') !== -1));
                    if (!hasTakeawayPerm) {
                        self.showToast('No tienes permisos habilitados para Take Away ni pedidos por despachar.', 'warning');
                        return;
                    }
                }

                $('.caja-gastro-chip').removeClass('active');
                $(this).addClass('active');

                const icon = $(this).data('icon') || '🍸';
                self.gastroSector = sector;
                $('#caja-pos-selected-sector').val(sector);

                if (isMesa) {
                    $('#caja-gastro-tables-wrap').slideDown(150);
                    if (!self.gastroTable) {
                        self.gastroTable = '1';
                        $('.caja-gastro-table-chip').removeClass('active');
                        $('.caja-gastro-table-chip[data-table="1"]').addClass('active');
                    }
                    $('#caja-gastro-selected-badge').text(`${icon} ${sector} #${self.gastroTable}`);
                } else {
                    $('#caja-gastro-tables-wrap').slideUp(150);
                    self.gastroTable = '';
                    const lockPrefix = (!perms.is_admin && !perms.can_change_sector) ? '🔒 ' : '';
                    $('#caja-gastro-selected-badge').text(`${lockPrefix}${icon} ${sector}`);
                }

                // Vinculación bidireccional Take Away <-> Pedido por Despachar
                if (isTakeAway) {
                    // "Si tiene habilitada la opción take away y toca esa operación el pedido cambia a pedido por despachar y se tienen que tomar los datos"
                    $('.caja-pos-switch-row').removeClass('is-active');
                    $('#pos-row-pedido-despachar').addClass('is-active');
                    $('#caja-pos-selected-mode').val('pedido_despachar');

                    const $btn = $('#caja-pos-checkout-btn');
                    const $text = $('#caja-pos-checkout-text');
                    $btn.addClass('is-dispatch');
                    $text.text('Enviar a Cola de Despacho');
                    $('#caja-pos-dispatch-subgroup').slideDown(180);
                    $('#caja-pos-status-dropdown-wrap').slideDown(180);

                    // Abrir datos del cliente automáticamente
                    $('#caja-pos-customer-body').slideDown(180);
                    $('#caja-pos-cust-toggle-icon').text('▲');
                    self.updatePosCustomerRequirements();
                } else {
                    // Si cambia a una barra o mesa (distinta de Take Away), vuelve a Compra Realizada
                    if ($('#caja-pos-selected-mode').val() === 'pedido_despachar') {
                        $('.caja-pos-switch-row').removeClass('is-active');
                        $('#pos-row-compra-realizada').addClass('is-active');
                        $('#caja-pos-selected-mode').val('compra_realizada');

                        const $btn = $('#caja-pos-checkout-btn');
                        const $text = $('#caja-pos-checkout-text');
                        $btn.removeClass('is-dispatch');
                        $text.text('Confirmar y Cobrar');
                        $('#caja-pos-dispatch-subgroup').slideUp(180);
                        $('#caja-pos-status-dropdown-wrap').slideUp(180);
                        $('#caja-pos-selected-status').val('pagado');
                        self.updatePosCustomerRequirements();
                    }
                }
            });

            // Selector de Mesa Rápida
            $(document).on('click', '.caja-gastro-table-chip', function(e) {
                e.preventDefault();
                $('.caja-gastro-table-chip').removeClass('active');
                $(this).addClass('active');
                $('#caja-gastro-custom-table').val('');

                const tbl = $(this).data('table');
                self.gastroTable = String(tbl);
                $('#caja-gastro-selected-badge').text(`🍽️ Mesa #${self.gastroTable}`);
            });

            // Mesa personalizada manual
            $(document).on('input', '#caja-gastro-custom-table', function() {
                const val = $.trim($(this).val());
                if (val) {
                    $('.caja-gastro-table-chip').removeClass('active');
                    self.gastroTable = val;
                    $('#caja-gastro-selected-badge').text(`🍽️ Mesa: ${val}`);
                } else {
                    self.gastroTable = '1';
                    $('.caja-gastro-table-chip[data-table="1"]').addClass('active');
                    $('#caja-gastro-selected-badge').text('🍽️ Mesa #1');
                }
            });

            // Speed Cash: Steppers de Billetes (+ y -)
            $(document).on('click', '.caja-cash-btn-minus', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const bill = parseInt($(this).data('bill'), 10) || 0;
                if (!self.cashBillsCount) {
                    self.cashBillsCount = { 100:0, 200:0, 500:0, 1000:0, 2000:0, 5000:0, 10000:0, 20000:0 };
                }
                if (self.cashBillsCount[bill] && self.cashBillsCount[bill] > 0) {
                    self.cashBillsCount[bill]--;
                    self.updateCashBillsUI();
                }
            });

            $(document).on('click', '.caja-cash-btn-plus, .caja-cash-bill-label', function(e) {
                e.preventDefault();
                const bill = parseInt($(this).data('bill'), 10) || 0;
                if (!self.cashBillsCount) {
                    self.cashBillsCount = { 100:0, 200:0, 500:0, 1000:0, 2000:0, 5000:0, 10000:0, 20000:0 };
                }
                if (!self.cashBillsCount[bill]) {
                    self.cashBillsCount[bill] = 0;
                }
                self.cashBillsCount[bill]++;
                self.updateCashBillsUI();
            });

            // Compatibilidad legacy de botones de billetes simples
            $(document).on('click', '.caja-cash-bill-btn[data-bill]', function(e) {
                e.preventDefault();
                const billVal = parseFloat($(this).data('bill')) || 0;
                const currentVal = parseFloat($('#caja-pos-cash-tendered').val()) || 0;
                const newVal = currentVal + billVal;
                $('#caja-pos-cash-tendered').val(newVal);
                self.recalcCashChange();
            });

            // Speed Cash: Monto Exacto
            $(document).on('click', '#btn-cash-exact', function(e) {
                e.preventDefault();
                self.resetCashBills();
                const total = self.currentGrandTotal || self.currentPosTotal || 0;
                $('#caja-pos-cash-tendered').val(total);
                self.recalcCashChange();
            });

            // Speed Cash: Limpiar
            $(document).on('click', '#btn-cash-clear', function(e) {
                e.preventDefault();
                self.resetCashBills();
                $('#caja-pos-cash-tendered').val('');
                self.recalcCashChange();
            });

            // Speed Cash: Input manual de monto entregado
            $(document).on('input', '#caja-pos-cash-tendered', function() {
                self.recalcCashChange();
            });

            // Propinas: Botones de porcentaje
            $(document).on('click', '.caja-pos-tip-chip', function(e) {
                e.preventDefault();
                $('.caja-pos-tip-chip').removeClass('active');
                $(this).addClass('active');

                const tipVal = $(this).data('tip');
                if (tipVal === 'custom') {
                    $('#caja-pos-tip-custom-wrap').slideDown(150);
                    $('#caja-pos-custom-tip-input').focus();
                    self.tipPercent = null;
                } else {
                    $('#caja-pos-tip-custom-wrap').slideUp(150);
                    self.tipPercent = parseFloat(tipVal) || 0;
                    self.tipAmount = 0;
                    self.updatePosTotals();
                }
            });

            // Propina manual libre
            $(document).on('input', '#caja-pos-custom-tip-input', function() {
                const manualVal = parseFloat($(this).val()) || 0;
                self.tipPercent = null;
                self.tipAmount = Math.max(0, manualVal);
                self.updatePosTotals();
            });

            // Quitar propina
            $(document).on('click', '#caja-pos-remove-tip-btn', function(e) {
                e.preventDefault();
                $('#caja-pos-custom-tip-input').val('');
                $('#caja-pos-tip-custom-wrap').slideUp(150);
                $('.caja-pos-tip-chip').removeClass('active');
                $('.caja-pos-tip-chip[data-tip="0"]').addClass('active');
                self.tipPercent = 0;
                self.tipAmount = 0;
                self.updatePosTotals();
            });

            // Buscador predictivo de clientes existentes
            let custSearchDebounce = null;
            $('#caja-pos-cust-search-input').on('input', function() {
                const term = $.trim($(this).val());
                const $clearBtn = $('#caja-pos-cust-search-clear');
                const $dropdown = $('#caja-pos-cust-search-results');

                if (term.length > 0) {
                    $clearBtn.show();
                } else {
                    $clearBtn.hide();
                    $dropdown.hide().empty();
                    return;
                }

                if (term.length < 2) {
                    $dropdown.hide().empty();
                    return;
                }

                clearTimeout(custSearchDebounce);
                custSearchDebounce = setTimeout(function() {
                    $dropdown.html('<div class="caja-pos-cust-result-empty"><span class="caja-spinner">⏳</span> Buscando clientes...</div>').show();

                    $.ajax({
                        url: self.config.ajaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'emp_caja_search_customers',
                            security: self.config.nonce,
                            term: term
                        },
                        success: function(res) {
                            if (res && res.success && res.data && res.data.customers && res.data.customers.length > 0) {
                                let html = '';
                                res.data.customers.forEach(function(c, idx) {
                                    const metaParts = [];
                                    if (c.dni) metaParts.push('<span>🪪 ' + self.escapeHtml(c.dni) + '</span>');
                                    if (c.phone) metaParts.push('<span>📞 ' + self.escapeHtml(c.phone) + '</span>');
                                    if (c.email) metaParts.push('<span>✉️ ' + self.escapeHtml(c.email) + '</span>');
                                    if (c.address) metaParts.push('<span>📍 ' + self.escapeHtml(c.address) + '</span>');

                                    html += '<div class="caja-pos-cust-result-item" data-idx="' + idx + '">';
                                    html += '  <div class="caja-pos-cust-result-name">';
                                    html += '    <span>' + self.escapeHtml(c.name) + '</span>';
                                    if (c.source) {
                                        html += '    <span class="caja-pos-cust-result-badge">' + self.escapeHtml(c.source) + '</span>';
                                    }
                                    html += '  </div>';
                                    if (metaParts.length > 0) {
                                        html += '  <div class="caja-pos-cust-result-meta">' + metaParts.join('') + '</div>';
                                    }
                                    html += '</div>';
                                });
                                $dropdown.html(html).show();
                                $dropdown.data('customers', res.data.customers);
                            } else {
                                const isEmailLike = term.indexOf('@') !== -1 && term.indexOf('.') !== -1;
                                let emptyHtml = '<div class="caja-pos-cust-result-empty">No se encontraron clientes coincidentes</div>';
                                if (isEmailLike) {
                                    emptyHtml += '<div class="caja-pos-cust-use-email-btn" data-email="' + self.escapeHtml(term) + '" style="padding:10px 12px; cursor:pointer; background:rgba(16,185,129,0.12); border-top:1px solid rgba(16,185,129,0.25); color:#34d399; font-weight:600; font-size:0.84rem; text-align:left; display:flex; align-items:center; gap:6px;">' +
                                        '<span>➕</span> <span>Usar como nuevo cliente: <strong>' + self.escapeHtml(term) + '</strong></span>' +
                                    '</div>';
                                }
                                $dropdown.html(emptyHtml).show();
                            }
                        },
                        error: function() {
                            $dropdown.html('<div class="caja-pos-cust-result-empty">Error al buscar clientes</div>').show();
                        }
                    });
                }, 250);
            });

            // Asignar email escrito en buscador a campos de cliente nuevo
            $(document).on('click', '.caja-pos-cust-use-email-btn', function(e) {
                e.stopPropagation();
                const email = $(this).data('email');
                $('#caja-pos-cust-email').val(email);
                $('#caja-pos-customer-body').slideDown(180);
                $('#caja-pos-cust-toggle-icon').text('▲');
                $('#caja-pos-cust-search-results').hide().empty();
                $('#caja-pos-cust-search-input').val('');
                $('#caja-pos-cust-search-clear').hide();
                $('#caja-pos-cust-name').focus();
                self.showToast('Email asignado. Completa el nombre del cliente.', 'info');
            });

            // Desplegar/Colapsar sección de datos del cliente
            $(document).on('click', '#caja-pos-customer-header', function(e) {
                const $body = $('#caja-pos-customer-body');
                const $icon = $('#caja-pos-cust-toggle-icon');
                $body.slideToggle(180, function() {
                    $icon.text($body.is(':visible') ? '▲' : '▼');
                });
            });

            // Seleccionar cliente de la lista predictiva
            $(document).on('click', '.caja-pos-cust-result-item', function(e) {
                e.stopPropagation();
                const $item = $(this);
                const idx = $item.data('idx');
                const customers = $('#caja-pos-cust-search-results').data('customers') || [];
                const c = customers[idx];
                if (!c) return;

                $('#caja-pos-cust-name').val(c.name || '');
                $('#caja-pos-cust-dni').val(c.dni || '');
                $('#caja-pos-cust-phone').val(c.phone || '');
                $('#caja-pos-cust-email').val(c.email || '');
                $('#caja-pos-cust-address').val(c.address || '');

                if (c.postcode) {
                    $('#caja-pos-calc-postcode').val(c.postcode);
                } else if (c.address && !$('#caja-pos-calc-postcode').val()) {
                    const cpMatch = c.address.match(/(?:cp|c\.p\.|código postal)?\s*(\b[1-9]\d{3}\b)/i);
                    if (cpMatch && cpMatch[1]) {
                        $('#caja-pos-calc-postcode').val(cpMatch[1]);
                    }
                }

                // Guardar copia de respaldo de los datos originales del cliente
                self.posActiveCustomerData = {
                    id: c.id || '',
                    name: c.name || '',
                    dni: c.dni || '',
                    phone: c.phone || '',
                    email: c.email || '',
                    address: c.address || ''
                };

                // Bloquear campos para evitar edición accidental
                self.lockPosCustomerFields(true);
                $('#caja-pos-cust-edit-wrap').show();
                $('#caja-pos-cust-edit-btn').show();
                $('#caja-pos-cust-edit-actions').hide();

                // Limpiar el buscador para que vuelva a su placeholder y no duplique ni reemplace el campo Nombre
                $('#caja-pos-cust-search-input').val('');
                $('#caja-pos-cust-search-clear').hide();
                $('#caja-pos-cust-search-results').hide().empty();

                self.updatePosCustomerRequirements();
                self.showToast('Cliente ' + c.name + ' seleccionado.', 'success');
            });

            // Botón habilitar edición de datos de cliente
            $(document).on('click', '#caja-pos-cust-edit-btn', function(e) {
                e.preventDefault();
                self.lockPosCustomerFields(false);
                $(this).hide();
                $('#caja-pos-cust-edit-actions').css('display', 'flex');
                $('#caja-pos-cust-name').focus();
                self.showToast('Edición habilitada. Puedes modificar los datos.', 'info');
            });

            // Botón descartar cambios y restaurar datos previamente guardados
            $(document).on('click', '#caja-pos-cust-discard-btn', function(e) {
                e.preventDefault();
                if (self.posActiveCustomerData) {
                    $('#caja-pos-cust-name').val(self.posActiveCustomerData.name || '');
                    $('#caja-pos-cust-dni').val(self.posActiveCustomerData.dni || '');
                    $('#caja-pos-cust-phone').val(self.posActiveCustomerData.phone || '');
                    $('#caja-pos-cust-email').val(self.posActiveCustomerData.email || '');
                    $('#caja-pos-cust-address').val(self.posActiveCustomerData.address || '');
                }
                self.lockPosCustomerFields(true);
                $('#caja-pos-cust-edit-actions').hide();
                $('#caja-pos-cust-edit-btn').show();
                self.updatePosCustomerRequirements();
                self.showToast('Cambios descartados. Se restauraron los datos originales.', 'info');
            });

            // Botón guardar cambios y bloquear edición nuevamente
            $(document).on('click', '#caja-pos-cust-save-btn', function(e) {
                e.preventDefault();
                const newName = $.trim($('#caja-pos-cust-name').val() || '');
                const newDni = $.trim($('#caja-pos-cust-dni').val() || '');
                const newPhone = $.trim($('#caja-pos-cust-phone').val() || '');
                const newEmail = $.trim($('#caja-pos-cust-email').val() || '');
                const newAddress = $.trim($('#caja-pos-cust-address').val() || '');

                if (!self.posActiveCustomerData) {
                    self.posActiveCustomerData = {};
                }
                self.posActiveCustomerData.name = newName;
                self.posActiveCustomerData.dni = newDni;
                self.posActiveCustomerData.phone = newPhone;
                self.posActiveCustomerData.email = newEmail;
                self.posActiveCustomerData.address = newAddress;

                // Bloquear nuevamente la edición
                self.lockPosCustomerFields(true);
                $('#caja-pos-cust-edit-actions').hide();
                $('#caja-pos-cust-edit-btn').show();
                self.updatePosCustomerRequirements();

                // Persistir en base de datos si es un cliente registrado
                if (self.posActiveCustomerData.id && String(self.posActiveCustomerData.id).indexOf('user_') === 0) {
                    $.ajax({
                        url: self.config.ajaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'emp_caja_update_customer',
                            security: self.config.nonce,
                            customer_id: self.posActiveCustomerData.id,
                            name: newName,
                            dni: newDni,
                            phone: newPhone,
                            email: newEmail,
                            address: newAddress
                        }
                    });
                }

                self.showToast('Cambios guardados con éxito.', 'success');
            });

            // Botón limpiar input de búsqueda
            $('#caja-pos-cust-search-clear').on('click', function(e) {
                e.stopPropagation();
                $('#caja-pos-cust-search-input').val('');
                $('#caja-pos-cust-search-results').hide().empty();
                $(this).hide();
            });

            // Cerrar lista flotante al hacer clic afuera
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.caja-pos-cust-search-wrap').length) {
                    $('#caja-pos-cust-search-results').hide();
                }
            });

            // Toggle Envío a otra dirección
            $(document).on('change', '#caja-pos-ship-diff-checkbox', function() {
                const isChecked = $(this).is(':checked');
                if (isChecked) {
                    $('#caja-pos-ship-diff-fields').slideDown(180);
                    $('#caja-pos-cust-address').closest('.caja-pos-cust-field').find('.caja-pos-req-star').hide();
                } else {
                    $('#caja-pos-ship-diff-fields').slideUp(180);
                    const mode = $('#caja-pos-selected-mode').val() || 'compra_realizada';
                    const deliveryType = $('#caja-pos-selected-delivery').val() || 'con_envio';
                    if (mode === 'pedido_despachar' && deliveryType === 'con_envio') {
                        $('#caja-pos-cust-address').closest('.caja-pos-cust-field').find('.caja-pos-req-star').show();
                    }
                }
            });

            // Confirmar y Cobrar Venta
            $('#caja-pos-checkout-btn').on('click', function() {
                self.handlePosCheckout();
            });

            // Abrir Modal de Historial de Ventas POS
            $(document).on('click', '#caja-pos-open-history-btn', function(e) {
                e.preventDefault();
                const perms = self.config.currentUserPermissions || {};
                if (!perms.is_admin && perms.can_view_orders === false) {
                    self.showToast('No tienes permisos para ver el historial de pedidos.', 'warning');
                    return;
                }
                $('#caja-modal-pos-history').fadeIn(150);
                $('body').addClass('caja-modal-locked');
                self.loadPosHistory();
            });

            // Cerrar Modal de Historial de Ventas POS
            $(document).on('click', '#caja-pos-history-close-btn, #caja-modal-pos-history .caja-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-pos-history').fadeOut(150);
                $('body').removeClass('caja-modal-locked');
            });

            // Actualizar Historial de Ventas manualmente con el botón
            $(document).on('click', '#caja-pos-history-refresh-btn', function(e) {
                e.stopPropagation();
                const $span = $(this).find('span');
                $span.css({
                    'display': 'inline-block',
                    'transition': 'transform 0.5s ease',
                    'transform': 'rotate(360deg)'
                });
                setTimeout(function() {
                    $span.css({ 'transition': 'none', 'transform': 'rotate(0deg)' });
                }, 500);
                self.loadPosHistory();
            });

            // Auto-extraer Código Postal al escribir o cambiar la dirección si el CP está vacío
            $(document).on('blur change', '#caja-pos-cust-address, #caja-pos-ship-address', function() {
                const val = $.trim($(this).val());
                if (val && !$('#caja-pos-calc-postcode').val()) {
                    const cpMatch = val.match(/(?:cp|c\.p\.|código postal)?\s*(\b[1-9]\d{3}\b)/i);
                    if (cpMatch && cpMatch[1]) {
                        $('#caja-pos-calc-postcode').val(cpMatch[1]);
                    }
                }
            });

            // Botón Calcular Envío en tiempo real
            $(document).on('click', '#caja-pos-btn-calc-shipping', function(e) {
                e.preventDefault();
                if (!self.posCart || !self.posCart.length) {
                    self.showToast('Agrega productos al pedido para calcular el envío.', 'warning');
                    return;
                }

                let postcode = $.trim($('#caja-pos-calc-postcode').val());
                const isShipDiff = $('#caja-pos-ship-diff-checkbox').is(':checked');
                const destAddress = isShipDiff ? $.trim($('#caja-pos-ship-address').val()) : $.trim($('#caja-pos-cust-address').val());

                if (!postcode && destAddress) {
                    const cpMatch = destAddress.match(/(?:cp|c\.p\.|código postal)?\s*(\b[1-9]\d{3}\b)/i);
                    if (cpMatch && cpMatch[1]) {
                        postcode = cpMatch[1];
                        $('#caja-pos-calc-postcode').val(postcode);
                    }
                }

                if (!postcode) {
                    self.showToast('Ingresa un Código Postal para calcular el costo de envío.', 'warning');
                    $('#caja-pos-calc-postcode').focus();
                    return;
                }

                const $btn = $(this);
                const $icon = $('#caja-pos-btn-calc-icon');
                const $text = $('#caja-pos-btn-calc-text');
                const origText = $text.text();

                $btn.prop('disabled', true);
                $icon.html('<span class="caja-spinner" style="font-size:0.85rem;">⏳</span>');
                $text.text('Calculando...');

                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_calculate_shipping',
                        security: self.config.nonce,
                        items: JSON.stringify(self.posCart),
                        postcode: postcode,
                        address: destAddress
                    },
                    success: function(res) {
                        const $wrap = $('#caja-pos-shipping-rates-wrap');
                        const $list = $('#caja-pos-shipping-rates-list');

                        if (res && res.success && res.data && res.data.rates && res.data.rates.length > 0) {
                            let html = '';
                            res.data.rates.forEach((rate, idx) => {
                                const isSelected = (idx === 0);
                                if (isSelected) {
                                    self.currentPosShippingCost = rate.cost;
                                    self.currentPosShippingMethodTitle = rate.label;
                                    self.currentPosShippingMethodId = rate.id;
                                    $('#caja-pos-custom-shipping-cost').val(rate.cost.toFixed(2));
                                }
                                html += `
                                    <div class="caja-pos-shipping-rate-card ${isSelected ? 'selected' : ''}" 
                                         data-rate-id="${self.escapeHtml(rate.id)}" 
                                         data-cost="${rate.cost}" 
                                         data-title="${self.escapeHtml(rate.label)}" 
                                         style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: ${isSelected ? 'rgba(16, 185, 129, 0.12)' : 'rgba(255,255,255,0.04)'}; border: 1px solid ${isSelected ? '#10b981' : 'var(--caja-card-border, #334155)'}; border-radius: 6px; cursor: pointer; transition: all 0.2s ease;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="caja-pos-rate-radio" style="font-size: 0.95rem; color: ${isSelected ? '#10b981' : '#94a3b8'};">${isSelected ? '✔' : '⚪'}</span>
                                            <div style="display: flex; flex-direction: column;">
                                                <strong style="font-size: 0.85rem; color: var(--caja-text, #ffffff); line-height: 1.2;">${self.escapeHtml(rate.label)}</strong>
                                                <span style="font-size: 0.72rem; color: var(--caja-text-muted, #94a3b8);">${self.escapeHtml(rate.method_id)}</span>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.95rem; font-weight: 700; color: #38bdf8;">
                                            $${self.formatCurrencyNumber(rate.cost)}
                                        </div>
                                    </div>
                                `;
                            });
                            $list.html(html);
                            $wrap.slideDown(150);
                            self.updatePosTotals();
                            self.showToast('Tarifa de envío calculada y agregada al total.', 'success');
                        } else {
                            const msg = (res && res.data && res.data.message) ? res.data.message : 'No se encontraron tarifas para este código postal.';
                            $list.html(`
                                <div style="padding: 10px 12px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 6px; font-size: 0.8rem; color: #fbbf24; line-height: 1.35;">
                                    ⚠️ ${self.escapeHtml(msg)}
                                </div>
                            `);
                            $wrap.slideDown(150);
                            $('#caja-pos-custom-shipping-cost').focus();
                        }
                    },
                    error: function() {
                        self.showToast('Error al conectar con la calculadora de envíos.', 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $icon.text('⚡');
                        $text.text(origText);
                    }
                });
            });

            // Seleccionar tarjeta de tarifa calculada
            $(document).on('click', '.caja-pos-shipping-rate-card', function() {
                const $card = $(this);
                const rateId = $card.data('rate-id');
                const cost = parseFloat($card.data('cost')) || 0;
                const title = $card.data('title') || '';

                $('.caja-pos-shipping-rate-card').removeClass('selected').css({
                    'border-color': 'var(--caja-card-border, #334155)',
                    'background': 'rgba(255,255,255,0.04)'
                }).find('.caja-pos-rate-radio').text('⚪').css('color', '#94a3b8');

                $card.addClass('selected').css({
                    'border-color': '#10b981',
                    'background': 'rgba(16, 185, 129, 0.12)'
                }).find('.caja-pos-rate-radio').text('✔').css('color', '#10b981');

                self.currentPosShippingCost = cost;
                self.currentPosShippingMethodTitle = title;
                self.currentPosShippingMethodId = rateId;
                $('#caja-pos-custom-shipping-cost').val(cost.toFixed(2));
                self.updatePosTotals();
                self.showToast('Tarifa seleccionada: ' + title, 'info');
            });

            // Aplicar costo manual de envío
            $(document).on('click', '#caja-pos-btn-apply-custom-shipping', function(e) {
                e.preventDefault();
                const manualVal = parseFloat($('#caja-pos-custom-shipping-cost').val());
                if (isNaN(manualVal) || manualVal < 0) {
                    self.showToast('Ingresa un costo de envío válido.', 'warning');
                    return;
                }

                $('.caja-pos-shipping-rate-card').removeClass('selected').css({
                    'border-color': 'var(--caja-card-border, #334155)',
                    'background': 'rgba(255,255,255,0.04)'
                }).find('.caja-pos-rate-radio').text('⚪').css('color', '#94a3b8');

                self.currentPosShippingCost = manualVal;
                self.currentPosShippingMethodTitle = 'Envío a domicilio (Manual)';
                self.currentPosShippingMethodId = 'custom_shipping';
                self.updatePosTotals();
                self.showToast('Costo de envío manual aplicado: $' + self.formatCurrencyNumber(manualVal), 'success');
            });

            // Presionar Enter en el campo de costo manual aplica el envío directamente
            $(document).on('keydown', '#caja-pos-custom-shipping-cost', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#caja-pos-btn-apply-custom-shipping').trigger('click');
                }
            });

            // Presionar Enter en el código postal ejecuta el cálculo
            $(document).on('keydown', '#caja-pos-calc-postcode', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#caja-pos-btn-calc-shipping').trigger('click');
                }
            });

            // Quitar costo de envío
            $(document).on('click', '#caja-pos-remove-shipping-btn, #caja-pos-clear-shipping-link', function(e) {
                e.preventDefault();
                self.currentPosShippingCost = 0;
                self.currentPosShippingMethodTitle = '';
                self.currentPosShippingMethodId = '';
                $('#caja-pos-custom-shipping-cost').val('');
                $('.caja-pos-shipping-rate-card').removeClass('selected').css({
                    'border-color': 'var(--caja-card-border, #334155)',
                    'background': 'rgba(255,255,255,0.04)'
                }).find('.caja-pos-rate-radio').text('⚪').css('color', '#94a3b8');
                self.updatePosTotals();
                self.showToast('Costo de envío removido.', 'info');
            });
        },

        changePosCartQuantity: function(prodId, delta) {
            const self = this;
            if (!this.cachedProducts) return;

            // Validar que la caja esté abierta si se intenta sumar productos
            if (delta > 0 && !this.activeRegisterSession) {
                if (this.userPendingRegisterRequest && this.userPendingRegisterRequest.status === 'pending') {
                    this.showToast('⏳ Tu solicitud de apertura de caja está pendiente de aprobación por administración.', 'warning', 4000);
                    return;
                }
                this.showToast('Debes abrir la caja antes de agregar productos al ticket.', 'warning');
                this.openRegisterModal();
                return;
            }

            const prod = this.cachedProducts.find(p => p.id == prodId);
            if (!prod) {
                this.showToast('Producto no encontrado.', 'error');
                return;
            }

            // Si es producto variable, abrir el selector de variaciones
            if (prod.product_type === 'variable' && prod.variations && prod.variations.length > 0) {
                self.openPosVariableModal(prodId);
                return;
            }

            const existing = this.posCart.find(i => i.id == prodId && (!i.variation_id || i.variation_id == 0));
            const currentQty = existing ? existing.quantity : 0;
            const newQty = Math.max(0, currentQty + delta);

            // Validar stock si intenta sumar
            if (delta > 0 && prod.manage_stock && prod.stock_quantity !== null) {
                if (newQty > prod.stock_quantity) {
                    this.showToast(`Stock máximo alcanzado (${prod.stock_quantity} disponibles).`, 'warning');
                    return;
                }
            }

            const rawPrice = (prod.price_raw !== undefined && prod.price_raw !== null) ? parseFloat(prod.price_raw) : parseFloat(prod.regular_price || 0);
            const prodImg = prod.image_url || prod.image || '';

            if (newQty === 0) {
                this.posCart = this.posCart.filter(i => !(i.id == prodId && (!i.variation_id || i.variation_id == 0)));
            } else {
                if (existing) {
                    existing.quantity = newQty;
                } else {
                    this.posCart.push({
                        id: prod.id,
                        product_id: prod.id,
                        variation_id: 0,
                        name: prod.name,
                        price: rawPrice,
                        image: prodImg,
                        stock: prod.stock_quantity,
                        manage_stock: prod.manage_stock,
                        quantity: newQty
                    });
                }
            }

            self.updatePosProductRowState(prodId);
        },

        updatePosProductRowState: function(prodId) {
            const self = this;
            const $row = $(`.caja-pos-prod-row[data-id="${prodId}"], .caja-pos-prod-card[data-id="${prodId}"]`);

            let totalQty = 0;
            self.posCart.forEach(i => {
                if (i.product_id == prodId || i.id == prodId) {
                    totalQty += i.quantity;
                }
            });

            if ($row.length) {
                const isVariable = $row.hasClass('is-variable-row');
                if (isVariable) {
                    const $trigger = $row.find('.caja-pos-btn-var-trigger');
                    if (totalQty > 0) {
                        $row.addClass('has-selected');
                        $trigger.addClass('has-items').html(`
                            <span class="caja-pos-var-count">${totalQty}</span>
                            <span class="caja-pos-var-label">Opciones ▾</span>
                        `);
                    } else {
                        $row.removeClass('has-selected');
                        $trigger.removeClass('has-items').html(`
                            <span class="caja-pos-var-label">Elegir ▾</span>
                        `);
                    }
                } else {
                    $row.find(`.caja-pos-qty-val[data-id="${prodId}"]`).text(totalQty);
                    const $minusBtn = $row.find(`.caja-pos-btn-qty.btn-minus[data-id="${prodId}"]`);
                    if (totalQty === 0) {
                        $minusBtn.prop('disabled', true);
                        $row.removeClass('has-selected');
                    } else {
                        $minusBtn.prop('disabled', false);
                        $row.addClass('has-selected');
                    }
                }
            }

            self.updateCategoryBadges();
            self.updatePosTotals();
        },

        openPosVariableModal: function(productId) {
            const self = this;

            // Validar que la caja esté abierta antes de configurar variaciones
            if (!this.activeRegisterSession) {
                if (this.userPendingRegisterRequest && this.userPendingRegisterRequest.status === 'pending') {
                    this.showToast('⏳ Tu solicitud de apertura de caja está pendiente de aprobación por administración.', 'warning', 4000);
                    return;
                }
                this.showToast('Debes abrir la caja antes de agregar productos al ticket.', 'warning');
                this.openRegisterModal();
                return;
            }

            if (!this.cachedProducts) return;

            const prod = this.cachedProducts.find(p => p.id == productId);
            if (!prod || !prod.variations || prod.variations.length === 0) {
                this.showToast('Este producto no tiene variaciones disponibles.', 'warning');
                return;
            }

            const varAttrs = (prod.attributes || []).filter(a => a.variation);
            if (varAttrs.length === 0) {
                this.showToast('No se encontraron atributos para este producto.', 'warning');
                return;
            }

            // Seleccionar por defecto la primera variación que tenga stock (o la primera)
            let defaultVar = prod.variations.find(v => v.is_in_stock && (v.stock_quantity === null || v.stock_quantity > 0));
            if (!defaultVar) {
                defaultVar = prod.variations[0];
            }

            const initialAttrs = {};
            varAttrs.forEach(a => {
                const aSlug = (a.slug || '').toLowerCase();
                const aNoPa = aSlug.replace(/^pa_/, '');
                let chosenVal = '';
                if (defaultVar && defaultVar.attributes) {
                    chosenVal = defaultVar.attributes[aSlug] || 
                                defaultVar.attributes['attribute_' + aSlug] || 
                                defaultVar.attributes[aNoPa] || 
                                defaultVar.attributes['attribute_' + aNoPa] || 
                                defaultVar.attributes['pa_' + aNoPa] || 
                                '';
                }
                if (!chosenVal && a.options_list && a.options_list.length > 0) {
                    chosenVal = a.options_list[0].slug;
                }
                initialAttrs[aSlug] = (chosenVal || '').toLowerCase();
            });

            this.currentVarModal = {
                product: prod,
                varAttrs: varAttrs,
                selectedAttrs: initialAttrs,
                qty: 1,
                matchedVar: defaultVar
            };

            $('#caja-pos-var-title').text(prod.name);

            let bodyHtml = '';
            varAttrs.forEach(a => {
                const aSlug = (a.slug || '').toLowerCase();
                const currVal = initialAttrs[aSlug] || '';

                bodyHtml += `
                    <div class="caja-pos-var-attr-group" data-attr-slug="${self.escapeHtml(aSlug)}">
                        <div class="caja-pos-var-attr-title">
                            <span>${self.escapeHtml(a.name)}:</span>
                            <span class="caja-pos-var-attr-selected-val" id="caja-pos-var-val-lbl-${self.escapeHtml(aSlug)}"></span>
                        </div>
                        <div class="caja-pos-var-pills-row">
                `;

                const opts = a.options_list || [];
                opts.forEach(opt => {
                    const optSlug = (opt.slug || opt.name).toLowerCase();
                    const optName = opt.name || opt.slug;
                    const isActive = (optSlug === currVal);

                    bodyHtml += `
                        <button type="button" class="caja-pos-var-pill ${isActive ? 'active' : ''}" 
                            data-attr="${self.escapeHtml(aSlug)}" 
                            data-val="${self.escapeHtml(optSlug)}" 
                            data-name="${self.escapeHtml(optName)}">
                            ${self.escapeHtml(optName)}
                        </button>
                    `;
                });

                bodyHtml += `
                        </div>
                    </div>
                `;
            });

            $('#caja-pos-var-body').html(bodyHtml);
            $('#caja-pos-var-qty').text('1');

            self.renderCurrentProductVariationsInModal(prod.id);
            self.updatePosVarModalState();

            $('#caja-modal-pos-variable').fadeIn(150);
        },

        updatePosVarModalState: function() {
            const self = this;
            if (!this.currentVarModal) return;

            const p = this.currentVarModal.product;
            const sel = this.currentVarModal.selectedAttrs;
            const varAttrs = this.currentVarModal.varAttrs;

            // Encontrar la variación que coincide con todos los atributos seleccionados
            let matched = null;
            if (p.variations && p.variations.length > 0) {
                matched = p.variations.find(v => {
                    return Object.keys(sel).every(k => {
                        const chosenVal = sel[k];
                        if (!chosenVal) return true;
                        const kNoPa = k.replace(/^pa_/, '');
                        const vVal = v.attributes ? (
                            v.attributes[k] || 
                            v.attributes['attribute_' + k] || 
                            v.attributes[kNoPa] || 
                            v.attributes['attribute_' + kNoPa] || 
                            v.attributes['pa_' + kNoPa] || 
                            ''
                        ) : '';
                        return !vVal || vVal === '' || vVal.toLowerCase() === chosenVal.toLowerCase();
                    });
                });
            }
            this.currentVarModal.matchedVar = matched;

            // Actualizar etiquetas de valores seleccionados y clases .active en pastillas
            varAttrs.forEach(a => {
                const aSlug = (a.slug || '').toLowerCase();
                const chosen = sel[aSlug] || '';
                const $group = $(`.caja-pos-var-attr-group[data-attr-slug="${aSlug}"]`);

                $group.find('.caja-pos-var-pill').each(function() {
                    const pVal = ($(this).attr('data-val') || '').toLowerCase();
                    if (pVal === chosen) {
                        $(this).addClass('active');
                        $(`#caja-pos-var-val-lbl-${aSlug}`).text($(this).attr('data-name') || pVal);
                    } else {
                        $(this).removeClass('active');
                    }
                });
            });

            const rawPrice = (matched && matched.price > 0) ? matched.price : (p.price_raw || parseFloat(p.regular_price || 0));
            const imgSrc = (matched && matched.image_url) ? matched.image_url : (p.image_url || p.image || '');

            if (imgSrc) {
                $('#caja-pos-var-thumb-img').attr('src', imgSrc).show();
            } else {
                $('#caja-pos-var-thumb-img').hide();
            }

            $('#caja-pos-var-price').text('$' + self.formatCurrencyNumber(rawPrice));

            let isAvailable = true;
            let stockText = 'Stock ilimitado';
            let isOut = false;

            if (!matched) {
                isAvailable = false;
                stockText = 'Combinación no disponible';
                isOut = true;
            } else if (matched.manage_stock && matched.stock_quantity !== null) {
                if (matched.stock_quantity <= 0 && !matched.backorders_allowed) {
                    isAvailable = false;
                    stockText = 'Agotado';
                    isOut = true;
                } else {
                    stockText = `${matched.stock_quantity} disponibles`;
                }
            } else if (p.manage_stock && p.stock_quantity !== null && typeof p.stock_quantity === 'number') {
                if (p.stock_quantity <= 0 && !p.backorders_allowed) {
                    isAvailable = false;
                    stockText = 'Agotado';
                    isOut = true;
                } else {
                    stockText = `${p.stock_quantity} disponibles`;
                }
            } else if (!matched.is_in_stock && (matched.manage_stock || p.manage_stock)) {
                isAvailable = false;
                stockText = 'Agotado';
                isOut = true;
            } else {
                // Si el comercio no gestiona stock o el producto es ilimitado
                isAvailable = true;
                stockText = 'Stock ilimitado';
                isOut = false;
            }

            const $stockBadge = $('#caja-pos-var-stock');
            $stockBadge.text(stockText);
            if (isOut) {
                $stockBadge.addClass('is-out');
            } else {
                $stockBadge.removeClass('is-out');
            }

            const qty = this.currentVarModal.qty || 1;
            $('#caja-pos-var-qty').text(qty);

            const $addBtn = $('#caja-pos-var-add-btn');
            const $addText = $('#caja-pos-var-add-text');

            if (!isAvailable) {
                $addBtn.prop('disabled', true);
                $addText.text(stockText);
            } else {
                $addBtn.prop('disabled', false);
                const lineTotal = rawPrice * qty;
                $addText.text(`Agregar a la venta • $${self.formatCurrencyNumber(lineTotal)}`);
            }
        },

        renderCurrentProductVariationsInModal: function(productId) {
            const self = this;
            const $wrap = $('#caja-pos-var-current-items');
            const $list = $('#caja-pos-var-current-list');

            const items = self.posCart.filter(i => (i.product_id == productId || i.id == productId));
            if (!items || items.length === 0) {
                $wrap.hide();
                $list.empty();
                return;
            }

            let html = '';
            items.forEach(item => {
                const label = item.variation_label || item.name;
                const cartKey = item.cartKey || `${item.product_id}_${item.variation_id}`;
                html += `
                    <div class="caja-pos-var-cart-chip" data-cart-key="${self.escapeHtml(cartKey)}">
                        <span>${self.escapeHtml(label)}</span>
                        <strong>(x${item.quantity})</strong>
                        <button type="button" class="caja-pos-var-chip-remove" data-cart-key="${self.escapeHtml(cartKey)}" title="Quitar 1">✕</button>
                    </div>
                `;
            });

            $list.html(html);
            $wrap.show();
        },

        addCurrentVariableToCart: function() {
            const self = this;
            if (!this.currentVarModal) return;

            const p = this.currentVarModal.product;
            const matched = this.currentVarModal.matchedVar;
            const qty = this.currentVarModal.qty || 1;

            if (!matched) {
                this.showToast('Combinación no disponible.', 'warning');
                return;
            }

            if ((matched.manage_stock || p.manage_stock) && !matched.is_in_stock && !matched.backorders_allowed) {
                this.showToast('Esta combinación está agotada.', 'warning');
                return;
            }

            if (matched.manage_stock && matched.stock_quantity !== null && !matched.backorders_allowed) {
                const inCart = self.posCart.find(i => (i.cartKey === `${p.id}_${matched.id}` || (i.product_id == p.id && i.variation_id == matched.id)));
                const currentQtyInCart = inCart ? inCart.quantity : 0;
                if ((currentQtyInCart + qty) > matched.stock_quantity) {
                    this.showToast(`Stock máximo de esta variación: ${matched.stock_quantity}.`, 'warning');
                    return;
                }
            }

            const labelParts = [];
            if (this.currentVarModal.varAttrs && matched.attributes) {
                this.currentVarModal.varAttrs.forEach(a => {
                    const aSlug = (a.slug || '').toLowerCase();
                    const aNoPa = aSlug.replace(/^pa_/, '');
                    const val = matched.attributes[aSlug] || 
                                matched.attributes['attribute_' + aSlug] || 
                                matched.attributes[aNoPa] || 
                                matched.attributes['attribute_' + aNoPa] || 
                                matched.attributes['pa_' + aNoPa] || 
                                '';
                    if (val) {
                        let friendlyName = val;
                        if (a.options_list) {
                            const found = a.options_list.find(o => o.slug === val.toLowerCase());
                            if (found) friendlyName = found.name;
                        }
                        labelParts.push(`${a.name}: ${friendlyName}`);
                    }
                });
            }
            const variationLabel = labelParts.length > 0 ? labelParts.join(' • ') : (matched.name || '');
            const cartKey = `${p.id}_${matched.id}`;
            const existing = self.posCart.find(i => i.cartKey === cartKey || (i.product_id == p.id && i.variation_id == matched.id));

            const rawPrice = (matched.price > 0) ? matched.price : (p.price_raw || parseFloat(p.regular_price || 0));
            const imgSrc = matched.image_url || p.image_url || p.image || '';

            if (existing) {
                existing.quantity += qty;
            } else {
                self.posCart.push({
                    id: p.id,
                    product_id: p.id,
                    variation_id: matched.id,
                    cartKey: cartKey,
                    name: p.name,
                    variation_label: variationLabel,
                    attributes: matched.raw_attributes || matched.attributes,
                    price: rawPrice,
                    image: imgSrc,
                    stock: matched.stock_quantity,
                    manage_stock: matched.manage_stock,
                    quantity: qty
                });
            }

            self.updatePosProductRowState(p.id);
            self.showToast(`✅ ${p.name} (${variationLabel}) agregado (${qty})`);

            $('#caja-modal-pos-variable').fadeOut(150);
            this.currentVarModal = null;
        },

        updateCategoryBadges: function() {
            const self = this;
            $('.caja-pos-cat-item').each(function() {
                const $badge = $(this).find('.caja-pos-cat-badge-qty');
                let catTotal = 0;

                $(this).find('.caja-pos-prod-row, .caja-pos-prod-card').each(function() {
                    const pId = $(this).data('id');
                    let pTotal = 0;
                    self.posCart.forEach(i => {
                        if (i.product_id == pId || i.id == pId) pTotal += i.quantity;
                    });
                    catTotal += pTotal;
                });

                if (catTotal > 0) {
                    $badge.text(catTotal).show();
                } else {
                    $badge.text('0').hide();
                }
            });
        },

        updatePosTotals: function() {
            const self = this;
            let totalItems = 0;
            let totalPrice = 0;
            let itemsHtml = '';

            this.posCart.forEach(item => {
                const lineTotal = item.price * item.quantity;
                totalItems += item.quantity;
                totalPrice += lineTotal;

                const varLabelHtml = item.variation_label ? `<div class="caja-pos-summary-item-var" style="font-size: 11px; color: #10b981; font-weight: 500; margin-top: 1px;">↳ ${self.escapeHtml(item.variation_label)}</div>` : '';

                itemsHtml += `
                    <div class="caja-pos-summary-item-row">
                        <div class="caja-pos-summary-item-name">
                            <span class="caja-pos-summary-item-qty">${item.quantity}x</span>
                            <span class="caja-pos-summary-item-title" title="${self.escapeHtml(item.name)}">${self.escapeHtml(item.name)}</span>
                            ${item.quantity > 1 ? `<span class="caja-pos-summary-unit-price">($${self.formatCurrencyNumber(item.price)} c/u)</span>` : ''}
                            ${varLabelHtml}
                        </div>
                        <div class="caja-pos-summary-item-price">$${self.formatCurrencyNumber(lineTotal)}</div>
                    </div>
                `;
            });

            const $summaryContainer = $('#caja-pos-summary-items');
            if ($summaryContainer.length) {
                if (totalItems > 0) {
                    $summaryContainer.html(itemsHtml).show();
                } else {
                    $summaryContainer.html('<div class="caja-pos-summary-empty">Sin productos seleccionados</div>').show();
                }
            }

            const $btn = $('#caja-pos-checkout-btn');
            const $badge = $('#caja-pos-cart-badge-count');

            const mode = $('#caja-pos-selected-mode').val() || 'compra_realizada';
            const deliveryType = $('#caja-pos-selected-delivery').val() || 'con_envio';
            const isConEnvio = (mode === 'pedido_despachar' && deliveryType === 'con_envio');
            const shippingCost = isConEnvio ? (parseFloat(self.currentPosShippingCost) || 0) : 0;
            // Cálculo de Propina / Servicio Gastro
            let tip = 0;
            if (self.tipPercent !== null && self.tipPercent !== undefined && self.tipPercent > 0) {
                tip = Math.round((totalPrice * (self.tipPercent / 100)) * 100) / 100;
                self.tipAmount = tip;
            } else if (self.tipAmount > 0) {
                tip = self.tipAmount;
            } else {
                tip = 0;
                self.tipAmount = 0;
            }

            $('#caja-pos-tip-amount').val(tip);
            if (tip > 0) {
                const tipLabel = (self.tipPercent && self.tipPercent > 0) ? `Propina / Servicio (${self.tipPercent}%):` : 'Propina / Servicio:';
                $('#caja-pos-tip-total-label').text(tipLabel);
                $('#caja-pos-tip-total-val').text('$' + self.formatCurrencyNumber(tip));
                $('#caja-pos-tip-val-badge').text('$' + self.formatCurrencyNumber(tip));
                $('#caja-pos-tip-total-row').show();
            } else {
                $('#caja-pos-tip-val-badge').text('$0,00');
                $('#caja-pos-tip-total-row').hide();
            }

            const grandTotal = totalPrice + shippingCost + tip;
            self.currentGrandTotal = grandTotal;

            $('#caja-pos-subtotal-val').text('$' + self.formatCurrencyNumber(totalPrice));

            if (isConEnvio && shippingCost > 0) {
                const shipLabel = self.currentPosShippingMethodTitle ? `🚚 ${self.currentPosShippingMethodTitle}:` : '🚚 Envío:';
                $('#caja-pos-shipping-total-label').text(shipLabel);
                $('#caja-pos-shipping-total-val').text('$' + self.formatCurrencyNumber(shippingCost));
                $('#caja-pos-shipping-total-row').show();
                $('#caja-pos-shipping-applied-badge').show();
                $('#caja-pos-clear-shipping-link').show();
            } else {
                $('#caja-pos-shipping-total-row').hide();
                $('#caja-pos-shipping-applied-badge').hide();
                $('#caja-pos-clear-shipping-link').hide();
            }

            $('#caja-pos-total-val').text('$' + self.formatCurrencyNumber(grandTotal));

            self.currentPosTotal = grandTotal;
            self.recalcCashChange();
            self.updatePosCustomerRequirements();

            if (totalItems > 0) {
                $badge.text(totalItems).addClass('has-items').show();
                $btn.prop('disabled', false);
            } else {
                $badge.text('0').removeClass('has-items');
                $btn.prop('disabled', true);
            }
        },

        clearPosCart: function(silent) {
            this.posCart = [];
            this.currentPosTotal = 0;
            this.currentGrandTotal = 0;
            this.tipPercent = 0;
            this.tipAmount = 0;
            this.cashTendered = 0;
            this.cashChange = 0;
            this.resetCashBills();
            $('.caja-pos-tip-chip').removeClass('active');
            $('.caja-pos-tip-chip[data-tip="0"]').addClass('active');
            $('#caja-pos-tip-custom-wrap').hide();
            $('#caja-pos-custom-tip-input').val('');
            $('#caja-pos-tip-amount').val('0');
            $('#caja-pos-cash-tendered').val('');
            $('#caja-cash-change-val').text('$0,00').css('color', '#34d399');
            $('.caja-pos-qty-val').text('0');
            $('.caja-pos-btn-qty.btn-minus').prop('disabled', true);
            $('.caja-pos-btn-var-trigger').removeClass('has-items').html('<span class="caja-pos-var-label">Elegir ▾</span>');
            $('.caja-pos-prod-row, .caja-pos-prod-card').removeClass('has-selected');
            $('.caja-pos-cat-badge-qty').text('0').hide();
            // Reset de opciones de despacho
            $('.caja-pos-dispatch-chip').removeClass('active');
            $('#btn-delivery-con-envio').addClass('active');
            $('#caja-pos-selected-delivery').val('con_envio');
            if ($('#caja-pos-selected-mode').val() !== 'pedido_despachar') {
                $('#caja-pos-dispatch-subgroup').hide();
                $('#caja-pos-status-dropdown-wrap').hide();
                $('#caja-pos-selected-status').val('pagado');
            }

            // Reset de calculadora de envíos
            this.currentPosShippingCost = 0;
            this.currentPosShippingMethodTitle = '';
            this.currentPosShippingMethodId = '';
            $('#caja-pos-calc-postcode').val('');
            $('#caja-pos-custom-shipping-cost').val('');
            $('#caja-pos-shipping-rates-list').empty();
            $('#caja-pos-shipping-rates-wrap').hide();
            $('#caja-pos-shipping-total-row').hide();
            $('#caja-pos-shipping-applied-badge').hide();
            $('#caja-pos-clear-shipping-link').hide();

            this.updatePosTotals();
            this.updatePosCustomerRequirements();
            this.updatePosUnpaidWarning();

            // Reset de empaques y bolsas seleccionados
            $('.caja-pos-pkg-qty-val').text('0');
            $('.caja-pos-pkg-btn-minus').prop('disabled', true);
            $('.caja-pos-pkg-row').removeClass('has-selected');
            this.updatePosPackagingBadge();

            // Reset de envío a otra dirección
            $('#caja-pos-ship-diff-checkbox').prop('checked', false);
            $('#caja-pos-ship-diff-fields').hide();
            $('#caja-pos-ship-name, #caja-pos-ship-dni, #caja-pos-ship-phone, #caja-pos-ship-address').val('');

            $('#caja-pos-checkout-btn').prop('disabled', true);
            if (!silent) {
                this.showToast('Selección vaciada.', 'info');
            }
        },

        lockPosCustomerFields: function(locked) {
            const $fields = $('#caja-pos-cust-name, #caja-pos-cust-dni, #caja-pos-cust-phone, #caja-pos-cust-email, #caja-pos-cust-address');
            if (locked) {
                $fields.prop('readonly', true).addClass('is-locked');
            } else {
                $fields.prop('readonly', false).removeClass('is-locked');
            }
        },

        updatePosCustomerRequirements: function() {
            const self = this;
            if (!$('#caja-pos-customer-subgroup').length) return;
            const mode = $('#caja-pos-selected-mode').val() || 'compra_realizada';
            const deliveryType = $('#caja-pos-selected-delivery').val() || 'con_envio';
            const threshold = parseFloat((self.config && self.config.posCustomerThreshold) || 0);
            const total = self.currentPosTotal || 0;

            const $badge = $('#caja-pos-cust-req-badge');
            const $stars = $('.caja-pos-req-star');
            const $body = $('#caja-pos-customer-body');
            const $toggleIcon = $('#caja-pos-cust-toggle-icon');
            const isConEnvio = (mode === 'pedido_despachar' && deliveryType === 'con_envio');
            const isShipDiff = $('#caja-pos-ship-diff-checkbox').is(':checked');

            if (isConEnvio) {
                $badge.css({ 'background': 'rgba(239, 68, 68, 0.2)', 'color': '#ef4444' }).text('Obligatorio (Envío)');
                $stars.show();
                $('#caja-pos-ship-diff-wrap').show();
                $('#caja-pos-shipping-calc-group').slideDown(180);
                if (isShipDiff) {
                    $('#caja-pos-cust-address').closest('.caja-pos-cust-field').find('.caja-pos-req-star').hide();
                }
                if (!$body.is(':visible')) {
                    $body.slideDown(180);
                    $toggleIcon.text('▲');
                }
            } else if (threshold > 0 && total >= threshold) {
                $badge.css({ 'background': 'rgba(245, 158, 11, 0.2)', 'color': '#f59e0b' }).text(`Obligatorio (Monto ≥ $${self.formatCurrencyNumber(threshold)})`);
                $stars.show();
                $('#caja-pos-ship-diff-wrap').hide();
                $('#caja-pos-ship-diff-checkbox').prop('checked', false);
                $('#caja-pos-ship-diff-fields').hide();
                $('#caja-pos-shipping-calc-group').slideUp(180);
                if (!$body.is(':visible')) {
                    $body.slideDown(180);
                    $toggleIcon.text('▲');
                }
            } else {
                $badge.css({ 'background': 'rgba(255, 255, 255, 0.08)', 'color': 'var(--caja-text-muted, #94a3b8)' }).text('Opcional');
                $stars.hide();
                $('#caja-pos-ship-diff-wrap').hide();
                $('#caja-pos-ship-diff-checkbox').prop('checked', false);
                $('#caja-pos-ship-diff-fields').hide();
                $('#caja-pos-shipping-calc-group').slideUp(180);
            }
        },

        formatCurrencyNumber: function(num) {
            return (parseFloat(num) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        updatePosUnpaidWarning: function() {
            const mode = $('#caja-pos-selected-mode').val() || 'compra_realizada';
            const status = $('#caja-pos-selected-status').val() || 'pagado';
            const $warning = $('#caja-pos-unpaid-warning');
            const $text = $('#caja-pos-unpaid-text');

            if (mode === 'pedido_despachar' && status === 'pendiente') {
                const timeText = this.getCancelTimeText();
                $text.html(`El cliente tiene un máximo de <strong>${timeText}</strong> para pagar el pedido, sino se cancela automáticamente.`);
                $warning.slideDown(150);
            } else {
                $warning.slideUp(150);
            }
        },

        getCancelTimeText: function() {
            const mins = parseInt(
                (this.config && this.config.holdStockMinutes !== undefined)
                    ? this.config.holdStockMinutes
                    : (window.batllieCajaConfig && window.batllieCajaConfig.holdStockMinutes !== undefined ? window.batllieCajaConfig.holdStockMinutes : 60),
                10
            );

            if (!mins || mins <= 0) {
                return 'tiempo determinado';
            }
            if (mins >= 1440 && mins % 1440 === 0) {
                const days = Math.floor(mins / 1440);
                return `${days} ${days === 1 ? 'día' : 'días'}`;
            }
            if (mins >= 60) {
                const hours = Math.floor(mins / 60);
                const rem = mins % 60;
                if (rem === 0) {
                    return `${hours} ${hours === 1 ? 'hora' : 'horas'}`;
                } else {
                    return `${hours} ${hours === 1 ? 'hora' : 'horas'} y ${rem} ${rem === 1 ? 'minuto' : 'minutos'}`;
                }
            }
            return `${mins} ${mins === 1 ? 'minuto' : 'minutos'}`;
        },

        renderPosPackagingSection: function() {
            const self = this;
            const $list = $('#caja-pos-packaging-list');
            if (!$list.length) return;

            if (!self.packagingProducts) {
                // Si aún no están cargados, consultar vía AJAX
                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_get_packaging_items',
                        security: self.config.nonce
                    },
                    success: function(res) {
                        if (res && res.success && res.data) {
                            self.packagingProducts = res.data.packaging_products || [];
                            self.packagingCandidates = res.data.candidates || [];
                            self.renderPosPackagingSection();
                        } else {
                            $list.html(`
                                <div style="text-align: center; padding: 12px 8px; color: var(--caja-text-muted, #94a3b8); font-size: 0.8rem;">
                                    No hay productos de empaque o bolsas configurados.
                                </div>
                            `);
                        }
                    },
                    error: function() {
                        $list.html(`
                            <div style="text-align: center; padding: 12px 8px; color: var(--caja-text-muted, #94a3b8); font-size: 0.8rem;">
                                No hay productos de empaque o bolsas configurados.
                            </div>
                        `);
                    }
                });
                return;
            }

            // Preservar cantidades seleccionadas si ya había alguna
            const currentSelected = {};
            $('.caja-pos-pkg-qty-val').each(function() {
                const id = $(this).data('id');
                const qty = parseInt($(this).text(), 10) || 0;
                if (id && qty > 0) currentSelected[id] = qty;
            });

            if (!self.packagingProducts.length) {
                $list.html(`
                    <div style="text-align: center; padding: 12px 8px; color: var(--caja-text-muted, #94a3b8); font-size: 0.8rem;">
                        No hay productos de empaque o bolsas configurados. Puedes agregar uno abajo.
                    </div>
                `);
            } else {
                let html = '';
                self.packagingProducts.forEach(pkg => {
                    const stockText = (pkg.managing_stock && pkg.stock !== null) 
                        ? (pkg.stock > 0 ? `${pkg.stock} disp.` : '<span style="color:#ef4444; font-weight:700;">Sin stock</span>') 
                        : 'Stock ilimitado';
                    const isOutOfStock = (pkg.managing_stock && pkg.stock !== null && pkg.stock <= 0);
                    const imgSrc = pkg.image || '';
                    const qty = currentSelected[pkg.id] || 0;
                    const hasSelected = qty > 0 ? ' has-selected' : '';
                    const minusDisabled = qty > 0 ? '' : 'disabled';

                    html += `
                        <div class="caja-pos-pkg-row${hasSelected}" data-pkg-id="${pkg.id}">
                            <div class="caja-pos-pkg-row-left" style="display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0;">
                                <div class="caja-pos-pkg-thumb">
                                    ${imgSrc ? `<img src="${imgSrc}" alt="${self.escapeHtml(pkg.name)}" />` : '📦'}
                                </div>
                                <div style="display: flex; flex-direction: column; min-width: 0; overflow: hidden;">
                                    <span class="caja-pos-pkg-name" title="${self.escapeHtml(pkg.name)}">${self.escapeHtml(pkg.name)}</span>
                                    <span class="caja-pos-pkg-stock-lbl">${stockText}</span>
                                </div>
                            </div>
                            <div class="caja-pos-stepper" style="display: inline-flex; align-items: center; gap: 4px; background: rgba(30, 41, 59, 0.8); border: 1px solid var(--caja-card-border, #334155); border-radius: 6px; padding: 2px 4px; flex-shrink: 0;">
                                <button type="button" class="caja-pos-btn-qty caja-pos-pkg-btn-minus" data-id="${pkg.id}" ${minusDisabled} style="width: 26px; height: 26px; border: none; background: transparent; color: #ffffff; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; border-radius: 4px;">−</button>
                                <span class="caja-pos-qty-val caja-pos-pkg-qty-val" id="caja-pos-pkg-qty-${pkg.id}" data-id="${pkg.id}" style="min-width: 22px; text-align: center; font-size: 0.85rem; font-weight: 700; color: var(--caja-text, #ffffff);">${qty}</span>
                                <button type="button" class="caja-pos-btn-qty caja-pos-pkg-btn-plus" data-id="${pkg.id}" ${isOutOfStock ? 'disabled' : ''} style="width: 26px; height: 26px; border: none; background: transparent; color: #ffffff; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; border-radius: 4px;">+</button>
                            </div>
                        </div>
                    `;
                });
                $list.html(html);
            }

            // Actualizar selector de candidatos existentes para vincular
            const $candWrap = $('#caja-pos-link-candidate-wrap');
            const $selExist = $('#caja-pos-sel-exist-pkg');
            if ($selExist.length) {
                if (self.packagingCandidates && self.packagingCandidates.length > 0) {
                    let optHtml = '<option value="">-- Seleccionar producto --</option>';
                    self.packagingCandidates.forEach(c => {
                        optHtml += `<option value="${c.id}">${self.escapeHtml(c.name)} (Stock: ${c.stock})</option>`;
                    });
                    $selExist.html(optHtml);
                    $candWrap.show();
                } else {
                    $selExist.html('<option value="">-- No hay productos candidatos --</option>');
                    $candWrap.hide();
                }
            }

            self.updatePosPackagingBadge();
        },

        updatePosPackagingBadge: function() {
            let total = 0;
            $('.caja-pos-pkg-qty-val').each(function() {
                total += parseInt($(this).text(), 10) || 0;
            });

            const $reqBadge = $('#caja-pos-pkg-req-badge');
            const $countBadge = $('#caja-pos-pkg-count-badge');

            if (total > 0) {
                $countBadge.text(total + (total === 1 ? ' usado' : ' usados')).show();
                $reqBadge.hide();
            } else {
                $countBadge.hide();
                $reqBadge.show();
            }
        },

        getUsedPackaging: function() {
            const list = [];
            $('.caja-pos-pkg-qty-val').each(function() {
                const id = parseInt($(this).data('id'), 10);
                const qty = parseInt($(this).text(), 10) || 0;
                if (id && qty > 0) {
                    list.push({ id: id, qty: qty });
                }
            });
            return list;
        },

        handlePosCheckout: function() {
            const self = this;
            if (!this.posCart || this.posCart.length === 0) {
                this.showToast('El ticket de venta está vacío.', 'warning');
                return;
            }

            // Validar que la caja esté abierta antes de cobrar
            if (!this.activeRegisterSession) {
                if (this.userPendingRegisterRequest && this.userPendingRegisterRequest.status === 'pending') {
                    this.showToast('⏳ Tu solicitud de apertura de caja está pendiente de aprobación por administración.', 'warning', 5000);
                    return;
                }
                this.showToast('La caja se encuentra cerrada. Debes abrir la caja e ingresar el fondo inicial.', 'warning');
                this.openRegisterModal();
                return;
            }

            const perms = self.config.currentUserPermissions || {};
            const deliveryType = $('#caja-pos-selected-delivery').val() || 'con_envio';
            let mode = $('#caja-pos-selected-mode').val() || 'compra_realizada';
            const isShipDiffCheck = $('#caja-pos-ship-diff-checkbox').is(':checked');
            const hasCustAddr = Boolean($.trim($('#caja-pos-cust-address').val() || ''));
            const hasShipAddr = Boolean($.trim($('#caja-pos-ship-address').val() || ''));
            if ((parseFloat(self.currentPosShippingCost) || 0) > 0 || (deliveryType === 'con_envio' && (isShipDiffCheck ? hasShipAddr : hasCustAddr))) {
                mode = 'pedido_despachar';
            }
            if (!perms.is_admin && !perms.can_dispatch_orders) {
                mode = 'compra_realizada';
            }
            const paymentMethod = $('#caja-pos-selected-pay').val() || 'efectivo';
            const paymentStatus = (mode === 'compra_realizada') ? 'pagado' : ($('#caja-pos-selected-status').val() || 'pagado');
            const cardOp = $('#caja-pos-card-op').val() || '';
            const note = $('#caja-pos-note').val() || '';

            // Datos del cliente
            const custName = $.trim($('#caja-pos-cust-name').val() || '');
            const custDni = $.trim($('#caja-pos-cust-dni').val() || '');
            const custPhone = $.trim($('#caja-pos-cust-phone').val() || '');
            const custEmail = $.trim($('#caja-pos-cust-email').val() || '');
            const custAddress = $.trim($('#caja-pos-cust-address').val() || '');

            // Envío a otra dirección
            const shipDiff = $('#caja-pos-ship-diff-checkbox').is(':checked');
            const shipName = $.trim($('#caja-pos-ship-name').val() || '');
            const shipDni = $.trim($('#caja-pos-ship-dni').val() || '');
            const shipPhone = $.trim($('#caja-pos-ship-phone').val() || '');
            const shipAddress = $.trim($('#caja-pos-ship-address').val() || '');

            // Validación de obligatoriedad de datos del cliente
            const canViewCustomer = (perms.is_admin || perms.can_view_customer_data !== false);
            const isConEnvio = (mode === 'pedido_despachar' && deliveryType === 'con_envio');
            const threshold = parseFloat((self.config && self.config.posCustomerThreshold) || 0);
            const total = self.currentPosTotal || 0;
            const isThresholdMet = (canViewCustomer && threshold > 0 && total >= threshold);

            if (isConEnvio && canViewCustomer) {
                let missing = [];
                if (!custName) missing.push('Nombre y Apellido del Cliente');
                if (!custDni) missing.push('DNI / CUIT del Cliente');
                if (!custPhone) missing.push('Teléfono del Cliente');
                if (!custEmail) missing.push('Email del Cliente');

                if (shipDiff) {
                    if (!shipName) missing.push('Nombre del Destinatario');
                    if (!shipAddress) missing.push('Dirección de Envío (Destino)');
                    if (!shipPhone) missing.push('Teléfono del Destinatario');
                } else {
                    if (!custAddress) missing.push('Dirección del Cliente / Envío');
                }

                if (missing.length > 0) {
                    alert('Para pedidos con envío es obligatorio completar los siguientes datos:\n• ' + missing.join('\n• '));
                    if (!custName) $('#caja-pos-cust-name').focus();
                    else if (!custDni) $('#caja-pos-cust-dni').focus();
                    else if (!custPhone) $('#caja-pos-cust-phone').focus();
                    else if (!custEmail) $('#caja-pos-cust-email').focus();
                    else if (!shipDiff && !custAddress) $('#caja-pos-cust-address').focus();
                    else if (shipDiff && !shipName) $('#caja-pos-ship-name').focus();
                    else if (shipDiff && !shipAddress) $('#caja-pos-ship-address').focus();
                    else if (shipDiff && !shipPhone) $('#caja-pos-ship-phone').focus();
                    return;
                }
            } else if (isThresholdMet) {
                let missing = [];
                if (!custName) missing.push('Nombre y Apellido');
                if (!custDni) missing.push('DNI / CUIT');
                if (!custPhone) missing.push('Teléfono');
                if (!custAddress) missing.push('Dirección');

                if (missing.length > 0) {
                    alert(`Para ventas a partir de $${self.formatCurrencyNumber(threshold)} es obligatorio registrar los datos del cliente:\n• ` + missing.join('\n• '));
                    if (!custName) $('#caja-pos-cust-name').focus();
                    else if (!custDni) $('#caja-pos-cust-dni').focus();
                    else if (!custPhone) $('#caja-pos-cust-phone').focus();
                    else if (!custAddress) $('#caja-pos-cust-address').focus();
                    return;
                }
            }

            if (custEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(custEmail)) {
                alert('Por favor ingresa una dirección de Email válida.');
                $('#caja-pos-cust-email').focus();
                return;
            }

            const $btn = $('#caja-pos-checkout-btn');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="caja-spinner">⏳</span> Procesando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_create_pos_order',
                    security: self.config.nonce,
                    items: JSON.stringify(self.posCart),
                    mode: mode,
                    delivery_type: deliveryType,
                    payment_method: paymentMethod,
                    payment_status: paymentStatus,
                    card_op_number: cardOp,
                    note: note,
                    customer_name: custName,
                    customer_dni: custDni,
                    customer_phone: custPhone,
                    customer_email: custEmail,
                    customer_address: custAddress,
                    ship_different: shipDiff ? '1' : '0',
                    shipping_name: shipName,
                    shipping_dni: shipDni,
                    shipping_phone: shipPhone,
                    shipping_address: shipAddress,
                    shipping_cost: (mode === 'pedido_despachar' && deliveryType === 'con_envio') ? (parseFloat(self.currentPosShippingCost) || 0) : 0,
                    shipping_method_title: (mode === 'pedido_despachar' && deliveryType === 'con_envio') ? (self.currentPosShippingMethodTitle || '') : '',
                    shipping_method_id: (mode === 'pedido_despachar' && deliveryType === 'con_envio') ? (self.currentPosShippingMethodId || '') : '',
                    shipping_postcode: $.trim($('#caja-pos-calc-postcode').val()),
                    used_packaging: JSON.stringify(self.getUsedPackaging()),
                    gastro_sector: self.gastroSector || 'Barra Principal',
                    gastro_table: self.gastroTable || '',
                    tip_amount: self.tipAmount || 0,
                    cash_tendered: self.cashTendered || 0,
                    cash_change: self.cashChange || 0,
                    cash_breakdown: JSON.stringify(self.cashBillsCount || {})
                },
                success: function(res) {
                    if (res && res.success) {
                        // 1. Guardar copia de items y empaques para descuento de stock antes de vaciar
                        const purchasedItems = [...self.posCart];
                        const usedPackaging = self.getUsedPackaging();

                        // 2. Limpiar inmediatamente el carrito, notas y campos para que no se duplique
                        self.clearPosCart(true);
                        $('#caja-pos-note').val('');
                        $('#caja-pos-card-op').val('');
                        $('#caja-pos-cust-name, #caja-pos-cust-dni, #caja-pos-cust-phone, #caja-pos-cust-email, #caja-pos-cust-address').val('');
                        $('#caja-pos-ship-diff-checkbox').prop('checked', false);
                        $('#caja-pos-ship-diff-fields').hide();
                        $('#caja-pos-ship-name, #caja-pos-ship-dni, #caja-pos-ship-phone, #caja-pos-ship-address').val('');
                        self.lockPosCustomerFields(false);
                        self.posActiveCustomerData = null;
                        $('#caja-pos-cust-edit-wrap').hide();
                        $('#caja-pos-cust-edit-actions').hide();
                        $('#caja-pos-cust-edit-btn').show();
                        $('#caja-pos-cust-search-input').val('');
                        $('#caja-pos-cust-search-clear').hide();
                        $('#caja-pos-cust-search-results').hide().empty();
                        self.updatePosCustomerRequirements();

                        // 3. Mantener el botón deshabilitado con su texto original (previene doble clic)
                        $btn.prop('disabled', true).html(originalHtml);

                        // 4. Descontar inventario localmente en caché si aplica
                        if (self.cachedProducts && purchasedItems.length > 0) {
                            try {
                                purchasedItems.forEach(cartItem => {
                                    const p = self.cachedProducts.find(x => x.id == cartItem.id);
                                    if (p && p.manage_stock && p.stock_quantity !== null) {
                                        p.stock_quantity = Math.max(0, p.stock_quantity - cartItem.quantity);
                                        p.stock_label = p.stock_quantity > 0 ? (p.stock_quantity + ' en stock') : 'Agotado';
                                    }
                                });
                                if (self.isProductsLoaded && typeof self.renderProducts === 'function') {
                                    self.renderProducts(self.cachedProducts);
                                }
                            } catch(e) {
                                console.error('Error actualizando stock local', e);
                            }
                        }

                        // Descontar inventario local de empaques utilizados
                        if (usedPackaging && usedPackaging.length > 0 && self.packagingProducts) {
                            usedPackaging.forEach(u => {
                                const p = self.packagingProducts.find(pkg => pkg.id == u.id);
                                if (p && p.managing_stock && p.stock !== null) {
                                    p.stock = Math.max(0, p.stock - u.qty);
                                }
                            });
                            self.renderPosPackagingSection();
                        }

                        // 5. Re-renderizar el acordeón para resetear todos los steppers a 0
                        if (typeof self.renderPosCategoriesAccordion === 'function') {
                            self.renderPosCategoriesAccordion();
                        }

                        // 6. Si fue pedido por despachar, actualizar pedidos en vivo inmediatamente
                        if (mode === 'pedido_despachar' && typeof self.loadOrders === 'function') {
                            self.loadOrders(false);
                        }

                        // 7. Actualizar historial de ventas de caja
                        if (typeof self.loadPosHistory === 'function') {
                            self.loadPosHistory();
                        }

                        // 8. Sonido seguro
                        if (self.audio && typeof self.audio.playNewOrderSound === 'function') {
                            try {
                                self.audio.playNewOrderSound();
                            } catch(e) {}
                        }

                        // Actualizar sesión de caja activa y barra superior
                        if (res.data.active_session) {
                            self.activeRegisterSession = res.data.active_session;
                            self.updateRegisterBarUI(res.data.active_session);
                        }

                        self.showToast(res.data.message || 'Venta registrada con éxito.', 'success');

                        // 9. Mostrar modal de confirmación
                        self.showPosSuccessDialog(res.data);
                    } else {
                        $btn.prop('disabled', false).html(originalHtml);
                        self.showToast(res && res.data && res.data.message ? res.data.message : 'Error al registrar la venta.', 'error');
                    }
                },
                error: function(xhr) {
                    $btn.prop('disabled', false).html(originalHtml);
                    let errMsg = 'Error de conexión al registrar la venta.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.require_register) {
                        self.activeRegisterSession = null;
                        self.updateRegisterBarUI(null);
                        self.openRegisterModal();
                    }
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errMsg = xhr.responseJSON.data.message;
                    } else if (xhr && xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.data && parsed.data.message) {
                                errMsg = parsed.data.message;
                            }
                        } catch(e) {}
                    }
                    self.showToast(errMsg, 'error');
                }
            });
        },

        showPosSuccessDialog: function(data) {
            const self = this;
            const modeText = data.mode === 'compra_realizada' ? '🟢 Compra Realizada (Entregada)' : '📦 Pedido por Despachar';
            const payText = data.payment_status === 'pagado' ? '🟢 Paga ahora' : '🟠 Pendiente de pago';
            
            const modalHtml = `
                <div id="caja-pos-success-modal" class="caja-modal" style="display:flex; z-index:100060;">
                    <div class="caja-modal-backdrop"></div>
                    <div class="caja-modal-dialog" style="max-width:440px; text-align:center; padding:24px 20px; background:var(--caja-card-bg, #1e293b); border:1px solid var(--caja-card-border, #334155); color:var(--caja-text, #ffffff); box-shadow:0 15px 35px rgba(0,0,0,0.5);">
                        <span style="font-size:3rem; display:block; margin-bottom:10px;">🎉</span>
                        <h3 style="margin:0 0 8px; color:var(--caja-text, #ffffff); font-size:1.35rem; font-weight:800;">¡Venta #${data.order_number || data.order_id} Registrada!</h3>
                        <p style="color:var(--caja-text, #ffffff); opacity:0.95; font-size:0.95rem; margin:0 0 16px;">${self.escapeHtml(data.message || '')}</p>
                        
                        <div style="background:rgba(0,0,0,0.3); border:1px solid var(--caja-card-border, rgba(255,255,255,0.15)); border-radius:8px; padding:14px; margin-bottom:20px; font-size:0.92rem; text-align:left;">
                            ${data.gastro_sector ? `
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                                    <span style="color:var(--caja-text, #ffffff); font-weight:700;">Sector / Ubicación:</span>
                                    <span style="color:#38bdf8; font-weight:700;">📍 ${self.escapeHtml(data.gastro_sector)}${data.gastro_table ? ' · M' + self.escapeHtml(data.gastro_table) : ''}</span>
                                </div>
                            ` : ''}
                            ${data.tip_amount ? `
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                                    <span style="color:var(--caja-text, #ffffff); font-weight:700;">Propina / Servicio:</span>
                                    <span style="color:#34d399; font-weight:700;">+$${self.formatCurrencyNumber(data.tip_amount)}</span>
                                </div>
                            ` : ''}
                            ${data.cash_change ? `
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center; background:rgba(16,185,129,0.15); padding:6px 8px; border-radius:6px; border:1px solid rgba(16,185,129,0.3);">
                                    <span style="color:#34d399; font-weight:800;">💵 Vuelto a Entregar:</span>
                                    <strong style="color:#34d399; font-size:1.15rem; font-weight:900;">$${self.formatCurrencyNumber(data.cash_change)}</strong>
                                </div>
                            ` : ''}
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                                <span style="color:var(--caja-text, #ffffff); font-weight:700;">Total:</span>
                                <strong style="color:var(--caja-primary, #10b981); font-size:1.15rem; font-weight:800;">${data.total || ''}</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                                <span style="color:var(--caja-text, #ffffff); font-weight:700;">Modo:</span>
                                <span style="color:var(--caja-text, #ffffff); font-weight:600;">${modeText}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span style="color:var(--caja-text, #ffffff); font-weight:700;">Estado de Cobro:</span>
                                <span style="color:var(--caja-text, #ffffff); font-weight:600;">${payText}</span>
                            </div>
                        </div>

                        <!-- Botones de Impresión Gastro Rápida -->
                        <div style="display:flex; gap:8px; margin-bottom:12px;">
                            <button type="button" class="caja-btn" id="btn-pos-success-print-comanda" style="flex:1; padding:10px; font-weight:700; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.2); color:#fff; border-radius:6px; cursor:pointer;">
                                <span>🖨️ Comanda</span>
                            </button>
                            <button type="button" class="caja-btn" id="btn-pos-success-print-ticket" style="flex:1; padding:10px; font-weight:700; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.2); color:#fff; border-radius:6px; cursor:pointer;">
                                <span>🧾 Ticket</span>
                            </button>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <button type="button" class="caja-btn caja-btn-primary" id="btn-close-pos-success" style="width:100%; padding:12px; font-size:1rem; font-weight:700;">
                                <span>Nueva Venta</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;

            $('#caja-pos-success-modal').remove();
            $('body').append(modalHtml);

            $('#btn-pos-success-print-comanda').on('click', function() {
                self.printGastroTicket(data.order || data, 'comanda');
            });

            $('#btn-pos-success-print-ticket').on('click', function() {
                self.printGastroTicket(data.order || data, 'ticket');
            });

            $('#btn-close-pos-success, #caja-pos-success-modal .caja-modal-backdrop').on('click', function() {
                $('#caja-pos-success-modal').fadeOut(150, function() { $(this).remove(); });
                self.clearPosCart(true);
                $('#caja-pos-note').val('');
                $('#caja-pos-card-op').val('');
                $('#caja-pos-checkout-btn').prop('disabled', true);
            });
        },

        recalcCashChange: function() {
            const total = this.currentGrandTotal || this.currentPosTotal || 0;
            const $tenderedInput = $('#caja-pos-cash-tendered');
            const tendered = parseFloat($tenderedInput.val()) || 0;
            this.cashTendered = tendered;

            const $changeVal = $('#caja-cash-change-val');
            if (tendered >= total && total > 0) {
                const change = Math.round((tendered - total) * 100) / 100;
                this.cashChange = change;
                $changeVal.text('$' + this.formatCurrencyNumber(change)).css('color', '#34d399');
            } else if (tendered > 0 && tendered < total) {
                const diff = Math.round((total - tendered) * 100) / 100;
                this.cashChange = 0;
                $changeVal.text('Faltan $' + this.formatCurrencyNumber(diff)).css('color', '#f87171');
            } else {
                this.cashChange = 0;
                $changeVal.text('$0,00').css('color', '#34d399');
            }
        },

        updateCashBillsUI: function() {
            let totalFromBills = 0;
            let anyCount = false;
            if (!this.cashBillsCount) {
                this.cashBillsCount = { 100:0, 200:0, 500:0, 1000:0, 2000:0, 5000:0, 10000:0, 20000:0 };
            }

            for (const b in this.cashBillsCount) {
                const qty = parseInt(this.cashBillsCount[b], 10) || 0;
                const denom = parseInt(b, 10) || 0;
                const $item = $(`.caja-cash-bill-item[data-bill="${b}"]`);
                const $badge = $(`#caja-bill-badge-${b}`);

                if (qty > 0) {
                    anyCount = true;
                    totalFromBills += (denom * qty);
                    $item.addClass('has-count');
                    $badge.text('x' + qty);
                } else {
                    $item.removeClass('has-count');
                    $badge.text('0');
                }
            }

            if (anyCount) {
                $('#caja-pos-cash-tendered').val(totalFromBills);
            } else {
                $('#caja-pos-cash-tendered').val('');
            }
            this.recalcCashChange();
        },

        resetCashBills: function() {
            if (!this.cashBillsCount) {
                this.cashBillsCount = {};
            }
            const denoms = [100, 200, 500, 1000, 2000, 5000, 10000, 20000];
            for (const b of denoms) {
                this.cashBillsCount[b] = 0;
            }
            $('.caja-cash-bill-item').removeClass('has-count');
            $('.caja-cash-bill-badge').text('0');
        },

        printGastroTicket: function(order, type) {
            if (!order) return;
            const self = this;
            const isComanda = (type === 'comanda');
            const venueName = (self.config && self.config.gastroVenueName) ? self.config.gastroVenueName : (document.title || 'POS Gastro');
            const ticketFooter = (self.config && self.config.gastroTicketFooter) ? self.config.gastroTicketFooter : '¡Muchas gracias por su visita!';

            const orderNum = order.number || order.order_number || order.id || '---';
            const sectorName = order.gastro_sector || (order.meta && order.meta._caja_gastro_sector) || 'Barra Principal';
            const tableName = order.gastro_table || (order.meta && order.meta._caja_gastro_mesa) || '';
            const locationStr = tableName ? `${sectorName} · MESA #${tableName}` : sectorName;
            
            // Fecha y hora
            const now = new Date();
            const dateStr = order.time_formatted || order.date_created || now.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

            // Cliente
            let customerName = order.customer_name || 'Consumidor Final';
            if (customerName.toLowerCase().includes('mostrador') || customerName.toLowerCase().includes('anónimo') || customerName.toLowerCase().includes('anonimo')) {
                customerName = 'Consumidor Final';
            }
            const customerNote = order.customer_note || order.note || '';

            // Items
            let items = [];
            if (Array.isArray(order.items) && order.items.length > 0) {
                items = order.items;
            } else if (order.line_items && Array.isArray(order.line_items)) {
                items = order.line_items;
            } else if (self.posCart && self.posCart.length > 0 && !order.items) {
                items = self.posCart;
            }

            let itemsTableHtml = '';
            items.forEach(it => {
                const qty = it.quantity || 1;
                const name = it.name || it.product_name || 'Producto';
                const price = it.price ? parseFloat(it.price) : (it.total_num ? parseFloat(it.total_num) : 0);
                const lineTotal = it.total ? (typeof it.total === 'string' ? it.total : '$' + self.formatCurrencyNumber(it.total)) : ('$' + self.formatCurrencyNumber(price * qty));
                const variation = it.variation_label || '';

                if (isComanda) {
                    itemsTableHtml += `
                        <tr style="border-bottom: 1px dashed #444;">
                            <td style="padding: 6px 2px; font-size: 16px; font-weight: 900; width: 35px; vertical-align: top;">${qty}x</td>
                            <td style="padding: 6px 2px; font-size: 14px; font-weight: 700;">
                                ${self.escapeHtml(name)}
                                ${variation ? `<div style="font-size: 12px; font-weight: 600; color: #222;">↳ ${self.escapeHtml(variation)}</div>` : ''}
                            </td>
                        </tr>
                    `;
                } else {
                    itemsTableHtml += `
                        <tr style="border-bottom: 1px dotted #ccc;">
                            <td style="padding: 4px 2px; font-size: 13px; font-weight: 800; width: 28px; vertical-align: top;">${qty}</td>
                            <td style="padding: 4px 2px; font-size: 12px; vertical-align: top;">
                                ${self.escapeHtml(name)}
                                ${variation ? `<div style="font-size: 11px; color: #555;">↳ ${self.escapeHtml(variation)}</div>` : ''}
                            </td>
                            <td style="padding: 4px 2px; font-size: 12px; font-weight: 700; text-align: right; vertical-align: top; white-space: nowrap;">${lineTotal}</td>
                        </tr>
                    `;
                }
            });

            // Totales para Ticket
            const subtotalStr = order.subtotal ? order.subtotal : (order.total || '$0');
            const totalStr = order.total || '$0';
            const tipVal = parseFloat(order.gastro_tip_amount || (order.meta && order.meta._caja_tip_amount) || order.tip_amount) || 0;
            const tenderedVal = parseFloat(order.gastro_cash_tendered || (order.meta && order.meta._caja_cash_tendered) || order.cash_tendered) || 0;
            const changeVal = parseFloat(order.gastro_cash_change || (order.meta && order.meta._caja_cash_change) || order.cash_change) || 0;
            const paymentMethod = order.payment_method || order.payment_method_title || 'Efectivo';

            let totalsHtml = '';
            if (!isComanda) {
                totalsHtml = `
                    <div style="border-top: 1px dashed #000; margin-top: 6px; padding-top: 6px;">
                        ${tipVal > 0 ? `
                            <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 3px;">
                                <span>Propina / Servicio:</span>
                                <strong>+$${self.formatCurrencyNumber(tipVal)}</strong>
                            </div>
                        ` : ''}
                        <div style="display: flex; justify-content: space-between; font-size: 15px; font-weight: 900; margin-top: 4px; border-top: 1px solid #000; padding-top: 4px;">
                            <span>TOTAL:</span>
                            <span>${totalStr}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 11px; margin-top: 4px; color: #444;">
                            <span>Medio de Pago:</span>
                            <span>${self.escapeHtml(paymentMethod)}</span>
                        </div>
                        ${tenderedVal > 0 ? `
                            <div style="display: flex; justify-content: space-between; font-size: 11px; margin-top: 2px; color: #444;">
                                <span>Abona con:</span>
                                <span>$${self.formatCurrencyNumber(tenderedVal)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 800; margin-top: 2px;">
                                <span>Vuelto:</span>
                                <span>$${self.formatCurrencyNumber(changeVal)}</span>
                            </div>
                        ` : ''}
                    </div>
                `;
            }

            const printHtml = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <title>${isComanda ? 'Comanda #' + orderNum : 'Ticket #' + orderNum}</title>
                    <style>
                        @page {
                            size: 80mm auto;
                            margin: 0mm;
                        }
                        @media print {
                            body {
                                margin: 0;
                                padding: 3mm 2mm;
                            }
                        }
                        body {
                            font-family: 'Courier New', Courier, monospace, sans-serif;
                            font-size: 12px;
                            line-height: 1.25;
                            color: #000;
                            background: #fff;
                            width: 76mm;
                            max-width: 76mm;
                            margin: 0 auto;
                            padding: 4mm 2mm;
                            box-sizing: border-box;
                        }
                        .text-center { text-align: center; }
                        .text-right { text-align: right; }
                        .bold { font-weight: bold; }
                        .banner {
                            border: 2px solid #000;
                            padding: 6px;
                            margin: 8px 0;
                            text-align: center;
                        }
                        .banner-title {
                            font-size: 17px;
                            font-weight: 900;
                            letter-spacing: 0.5px;
                            text-transform: uppercase;
                        }
                        .banner-sub {
                            font-size: 13px;
                            font-weight: 800;
                            margin-top: 3px;
                        }
                        .divider {
                            border-top: 1px dashed #000;
                            margin: 6px 0;
                        }
                        .double-divider {
                            border-top: 2px solid #000;
                            margin: 6px 0;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                        }
                    </style>
                </head>
                <body>
                    ${isComanda ? `
                        <div class="banner">
                            <div class="banner-title">*** COMANDA ***</div>
                            <div class="banner-sub">📍 ${self.escapeHtml(locationStr)}</div>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:800; margin-bottom:4px;">
                            <span>PEDIDO #${orderNum}</span>
                            <span>${dateStr}</span>
                        </div>
                        ${customerNote ? `
                            <div style="background:#f0f0f0; border:1px solid #000; padding:5px; margin:6px 0; font-size:12px; font-weight:bold;">
                                ⚠️ NOTA: ${self.escapeHtml(customerNote)}
                            </div>
                        ` : ''}
                        <div class="divider"></div>
                        <table>
                            <tbody>
                                ${itemsTableHtml}
                            </tbody>
                        </table>
                        <div class="divider"></div>
                        <div class="text-center" style="font-size:10px; margin-top:8px; font-weight:bold;">
                            --- ENVIADO A PREPARACIÓN ---
                        </div>
                    ` : `
                        <div class="text-center">
                            <div style="font-size: 16px; font-weight: 900; text-transform: uppercase;">${self.escapeHtml(venueName)}</div>
                            <div style="font-size: 11px; margin-top: 2px;">COMPROBANTE DE VENTA</div>
                            <div class="divider"></div>
                            <div style="font-size: 13px; font-weight: 800; margin: 4px 0;">📍 ${self.escapeHtml(locationStr)}</div>
                            <div style="display: flex; justify-content: space-between; font-size: 11px; margin-top: 4px;">
                                <span>Ticket: #${orderNum}</span>
                                <span>${dateStr}</span>
                            </div>
                            ${customerName ? `
                                <div style="font-size: 11px; text-align: left; margin-top: 3px;">
                                    Cliente: <strong>${self.escapeHtml(customerName)}</strong>
                                </div>
                            ` : ''}
                        </div>
                        <div class="divider"></div>
                        <table>
                            <thead>
                                <tr style="border-bottom: 1px dashed #000; font-size: 11px;">
                                    <th style="text-align: left; padding: 2px 0;">Cant</th>
                                    <th style="text-align: left; padding: 2px 0;">Detalle</th>
                                    <th style="text-align: right; padding: 2px 0;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${itemsTableHtml}
                            </tbody>
                        </table>
                        ${totalsHtml}
                        ${customerNote ? `
                            <div style="font-size: 11px; margin-top: 6px; padding: 4px; background: #fafafa; border: 1px solid #ddd;">
                                <em>Nota: ${self.escapeHtml(customerNote)}</em>
                            </div>
                        ` : ''}
                        <div class="divider"></div>
                        <div class="text-center" style="font-size: 11px; margin-top: 6px;">
                            ${self.escapeHtml(ticketFooter)}
                        </div>
                    `}
                </body>
                </html>
            `;

            // Imprimir vía iframe oculto sin popup bloqueable
            let $iframe = $('#caja-thermal-print-frame');
            if (!$iframe.length) {
                $iframe = $('<iframe id="caja-thermal-print-frame" style="position:fixed; right:0; bottom:0; width:1px; height:1px; border:none; opacity:0; z-index:-1;"></iframe>').appendTo('body');
            }
            const iframeDoc = $iframe[0].contentWindow || $iframe[0].contentDocument;
            const doc = iframeDoc.document || iframeDoc;
            doc.open();
            doc.write(printHtml);
            doc.close();

            setTimeout(function() {
                try {
                    $iframe[0].contentWindow.focus();
                    $iframe[0].contentWindow.print();
                } catch(e) {
                    console.error('Error al imprimir ticket térmico:', e);
                }
            }, 300);
        },

        loadPosHistory: function() {
            const self = this;
            const perms = self.config.currentUserPermissions || {};
            if (!perms.is_admin && perms.can_view_orders === false) {
                return;
            }
            const $body = $('#caja-pos-history-body');
            const $count = $('#caja-pos-history-count');
            if (!$body.length) return;

            $body.html(`
                <div style="text-align:center; padding:30px; color:#64748b;">
                    <span class="caja-spinner">⏳</span>
                    <p style="margin-top:8px;">Cargando historial de ventas...</p>
                </div>
            `);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_get_pos_history',
                    security: self.config.nonce
                },
                success: function(res) {
                    if (res.success && res.data && res.data.history) {
                        self.posHistoryLoaded = true;
                        const history = res.data.history;
                        $count.text(`${history.length}`).show();
                        $('#caja-pos-history-badge-top').text(`${history.length}`).show();

                        if (history.length === 0) {
                            $body.html(`
                                <div style="text-align:center; padding:30px; color:#64748b; font-size:0.9rem;">
                                    No hay ventas registradas todavía desde esta terminal.
                                </div>
                            `);
                            return;
                        }

                        let rows = '';
                        history.forEach(sale => {
                            const isCompleted = sale.mode === 'compra_realizada';
                            const modeBadge = isCompleted 
                                ? '<span class="caja-pos-badge-mode is-completed">🟢 Compra Realizada</span>'
                                : '<span class="caja-pos-badge-mode is-dispatch">📦 Por Despachar</span>';

                            const isPaid = sale.payment_status === 'pagado';
                            const payStatusBadge = isPaid
                                ? '<span class="caja-pos-badge-pay is-paid">🟢 Paga ahora</span>'
                                : '<span class="caja-pos-badge-warning-unpaid">🟠 Pendiente de pago</span>';

                            let payDetails = self.escapeHtml(sale.payment_method || 'Efectivo');
                            if (sale.card_op) {
                                payDetails += ` <small style="opacity:0.75;">(Op: #${self.escapeHtml(sale.card_op)})</small>`;
                            }

                            const noteHtml = sale.note ? `<div style="font-size:0.8rem; color:#94a3b8; font-style:italic;">💬 "${self.escapeHtml(sale.note)}"</div>` : '<span style="color:#64748b;">-</span>';

                            rows += `
                                <tr>
                                    <td>
                                        <strong style="color:#38bdf8; font-size:0.95rem;">#${self.escapeHtml(sale.number || sale.id)}</strong>
                                        <div style="font-size:0.75rem; color:#64748b;">${self.escapeHtml(sale.status_name || sale.status)}</div>
                                    </td>
                                    <td>${sale.date || '-'}</td>
                                    <td>${modeBadge}</td>
                                    <td>
                                        <div><strong>${payDetails}</strong></div>
                                        <div style="margin-top:2px;">${payStatusBadge}</div>
                                    </td>
                                    <td><strong style="color:#10b981; font-size:0.95rem;">${sale.total || ''}</strong></td>
                                    <td>${noteHtml}</td>
                                    <td><small style="color:#94a3b8;">${self.escapeHtml(sale.cashier || 'Admin')}</small></td>
                                </tr>
                            `;
                        });

                        $body.html(`
                            <div style="overflow-x:auto;">
                                <table class="caja-pos-history-table">
                                    <thead>
                                        <tr>
                                            <th>Orden</th>
                                            <th>Fecha y Hora</th>
                                            <th>Modo</th>
                                            <th>Pago y Cobro</th>
                                            <th>Total</th>
                                            <th>Nota</th>
                                            <th>Cajero</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${rows}
                                    </tbody>
                                </table>
                            </div>
                        `);
                    } else {
                        $body.html(`
                            <div style="text-align:center; padding:20px; color:#ef4444;">
                                Error al cargar el historial de ventas.
                            </div>
                        `);
                    }
                },
                error: function() {
                    $body.html(`
                        <div style="text-align:center; padding:20px; color:#ef4444;">
                            Error de conexión al cargar historial.
                        </div>
                    `);
                }
            });
        },

        /* ==========================================================================
           MÓDULO CONFIGURACIÓN DE EMPLEADOS Y ROLES (ADMINISTRADOR)
           ========================================================================== */
        cachedEmployees: [],
        cachedSectors: [],
        isEmployeesLoaded: false,
        employeesEventsBound: false,

        initEmployeesTab: function() {
            if (!this.employeesEventsBound) {
                this.bindEmployeesEvents();
                this.employeesEventsBound = true;
            }
            this.loadEmployees();
        },

        bindEmployeesEvents: function() {
            const self = this;

            // Abrir modal nuevo empleado
            $(document).on('click', '#caja-emp-btn-add', function(e) {
                e.preventDefault();
                self.openEmployeeModal(0);
            });

            // Refrescar lista de empleados
            $(document).on('click', '#caja-emp-btn-refresh', function(e) {
                e.preventDefault();
                self.loadEmployees(true);
            });

            // Cerrar modal de empleados
            $(document).on('click', '#caja-emp-modal-close, #caja-emp-modal-cancel, #caja-emp-modal-backdrop', function(e) {
                e.preventDefault();
                $('#caja-modal-employee').fadeOut(150);
            });

            // Generar contraseña aleatoria
            $(document).on('click', '#caja-emp-btn-gen-pass', function(e) {
                e.preventDefault();
                const pass = self.generateRandomPassword(10);
                $('#caja-emp-password').val(pass);
                self.showToast('Contraseña generada: ' + pass, 'info');
            });

            // Guardar formulario empleado
            $(document).on('submit', '#caja-emp-form', function(e) {
                e.preventDefault();
                self.saveEmployee();
            });

            // Editar empleado
            $(document).on('click', '.caja-emp-btn-edit', function(e) {
                e.preventDefault();
                const userId = $(this).data('id');
                self.openEmployeeModal(userId);
            });

            // Alternar estado activo / deshabilitado
            $(document).on('click', '.caja-emp-status-badge[data-id]', function(e) {
                e.preventDefault();
                const userId = $(this).data('id');
                self.toggleEmployeeStatus(userId);
            });

            // Eliminar empleado
            $(document).on('click', '.caja-emp-btn-delete', function(e) {
                e.preventDefault();
                const userId = $(this).data('id');
                const username = $(this).data('username');
                if (confirm('¿Estás seguro de que deseas eliminar al empleado "' + username + '"? Esta acción no se puede deshacer.')) {
                    self.deleteEmployee(userId);
                }
            });

            // Cambio de rol en modal (auto ajustar defaults de permisos)
            $(document).on('change', 'input[name="caja_emp_role_radio"]', function() {
                const role = $(this).val();
                if (role === 'administrator') {
                    $('#caja-emp-can-view-products').prop('checked', true);
                    $('#caja-emp-can-view-orders').prop('checked', true);
                    $('#caja-emp-can-change-sector').prop('checked', true);
                    $('#caja-emp-sectors-checklist input[type="checkbox"]').prop('checked', true);
                } else if (role === 'caja_encargado') {
                    $('#caja-emp-can-view-products').prop('checked', true);
                    $('#caja-emp-can-view-orders').prop('checked', true);
                    $('#caja-emp-can-change-sector').prop('checked', false);
                } else if (role === 'caja_cajero') {
                    // Por defecto cajero NO ve productos a menos que el admin lo habilite
                    $('#caja-emp-can-view-products').prop('checked', false);
                    $('#caja-emp-can-view-orders').prop('checked', true);
                    $('#caja-emp-can-change-sector').prop('checked', false);
                }
            });
        },

        loadEmployees: function(forceRefresh) {
            const self = this;
            if (this.isEmployeesLoaded && !forceRefresh && this.cachedEmployees.length) {
                this.renderEmployeesTable();
                return;
            }

            $('#caja-emp-tbody').html(`
                <tr>
                    <td colspan="6" style="text-align:center; padding:40px; color:var(--caja-text-muted, #94a3b8);">
                        <span class="caja-spinner">⏳</span> Cargando personal...
                    </td>
                </tr>
            `);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_get_employees',
                    security: self.config.nonce
                },
                success: function(res) {
                    if (res && res.success) {
                        self.cachedEmployees = res.data.employees || [];
                        self.cachedSectors = res.data.sectors || [];
                        self.isEmployeesLoaded = true;
                        self.renderEmployeesTable();
                    } else {
                        const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al cargar empleados.';
                        $('#caja-emp-tbody').html(`
                            <tr>
                                <td colspan="6" style="text-align:center; padding:30px; color:#f87171;">
                                    ⚠️ ${msg}
                                </td>
                            </tr>
                        `);
                    }
                },
                error: function() {
                    $('#caja-emp-tbody').html(`
                        <tr>
                            <td colspan="6" style="text-align:center; padding:30px; color:#f87171;">
                                ⚠️ Error de conexión al cargar empleados.
                            </td>
                        </tr>
                    `);
                }
            });
        },

        renderEmployeesTable: function() {
            const self = this;
            const employees = this.cachedEmployees || [];

            let total = employees.length;
            let cajeros = 0;
            employees.forEach(function(e) {
                if ((e.role === 'caja_cajero' || e.role_name === 'Cajero') && e.status === 'active') cajeros++;
            });

            $('#caja-emp-stat-total').text(total);
            $('#caja-emp-stat-cajeros').text(cajeros);

            if (!employees.length) {
                $('#caja-emp-tbody').html(`
                    <tr>
                        <td colspan="6" style="text-align:center; padding:40px; color:var(--caja-text-muted, #94a3b8);">
                            No hay empleados registrados. Haz click en "+ Nuevo Empleado" para crear el primero.
                        </td>
                    </tr>
                `);
                return;
            }

            let html = '';
            employees.forEach(function(emp) {
                let roleBadgeClass = 'caja-emp-badge-cajero';
                let roleIcon = '🍸';
                if (emp.role === 'administrator') {
                    roleBadgeClass = 'caja-emp-badge-admin';
                    roleIcon = '👑';
                } else if (emp.role === 'caja_encargado') {
                    roleBadgeClass = 'caja-emp-badge-encargado';
                    roleIcon = '📋';
                }

                // Barras asignadas
                let sectorsHtml = '';
                if (emp.is_admin) {
                    sectorsHtml = '<span class="caja-emp-sector-tag" style="background:rgba(168,85,247,0.15); border-color:rgba(168,85,247,0.3); color:#c084fc;">🌟 Todas las Barras y Mesas</span>';
                } else if (emp.assigned_sectors && emp.assigned_sectors.length) {
                    emp.assigned_sectors.forEach(function(sec) {
                        let icon = '🍸';
                        if (sec.toLowerCase().indexOf('vip') !== -1) icon = '⭐';
                        else if (sec.toLowerCase().indexOf('terraza') !== -1) icon = '🌿';
                        else if (sec.toLowerCase().indexOf('take') !== -1) icon = '🛍️';
                        sectorsHtml += `<span class="caja-emp-sector-tag">${icon} ${self.escapeHtml(sec)}</span>`;
                    });
                } else {
                    sectorsHtml = '<span class="caja-emp-sector-tag" style="color:var(--caja-text-muted);">Sin barra fija</span>';
                }

                // Permisos badges
                let permsHtml = `
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <span class="caja-emp-perm-pill ${emp.can_view_products ? 'is-yes' : 'is-no'}">
                            ${emp.can_view_products ? '✓' : '✗'} Productos (${emp.can_view_products ? 'Habilitado' : 'Solo Venta Caja'})
                        </span>
                        <span class="caja-emp-perm-pill ${emp.can_view_orders ? 'is-yes' : 'is-no'}">
                            ${emp.can_view_orders ? '✓' : '✗'} Pedidos en Vivo / Historial
                        </span>
                        <span class="caja-emp-perm-pill ${emp.can_change_sector ? 'is-yes' : 'is-no'}">
                            ${emp.can_change_sector ? '✓' : '✗'} Cambiar Mesas/Barras
                        </span>
                        <span class="caja-emp-perm-pill ${emp.can_view_customer_data ? 'is-yes' : 'is-no'}">
                            ${emp.can_view_customer_data ? '✓' : '✗'} Datos de Clientes
                        </span>
                        <span class="caja-emp-perm-pill ${emp.can_view_packaging ? 'is-yes' : 'is-no'}">
                            ${emp.can_view_packaging ? '✓' : '✗'} Empaque y Bolsas
                        </span>
                        <span class="caja-emp-perm-pill ${emp.can_dispatch_orders ? 'is-yes' : 'is-no'}">
                            ${emp.can_dispatch_orders ? '✓' : '✗'} Pedido por Despachar
                        </span>
                    </div>
                `;

                // Status badge
                let statusBadge = '';
                if (emp.is_current_user) {
                    statusBadge = `<span class="caja-emp-status-badge active" style="cursor:default;" title="Usuario actual en sesión">● Activo (Tú)</span>`;
                } else {
                    statusBadge = emp.status === 'active' 
                        ? `<span class="caja-emp-status-badge active" data-id="${emp.id}" title="Click para deshabilitar">● Activo</span>`
                        : `<span class="caja-emp-status-badge disabled" data-id="${emp.id}" title="Click para habilitar">✕ Deshabilitado</span>`;
                }

                // Actions
                let actionsHtml = `
                    <div class="caja-emp-actions-wrap">
                        <button type="button" class="caja-emp-action-btn caja-emp-btn-edit" data-id="${emp.id}" title="Editar empleado">
                            ✏️
                        </button>
                `;
                if (!emp.is_current_user) {
                    actionsHtml += `
                        <button type="button" class="caja-emp-action-btn btn-delete caja-emp-btn-delete" data-id="${emp.id}" data-username="${self.escapeHtml(emp.username)}" title="Eliminar empleado">
                            🗑️
                        </button>
                    `;
                }
                actionsHtml += '</div>';

                html += `
                    <tr>
                        <td>
                            <div class="caja-emp-name-cell">
                                <span class="caja-emp-name-title">${self.escapeHtml(emp.name)}</span>
                                <span class="caja-emp-name-sub">@${self.escapeHtml(emp.username)} ${emp.email ? '· ' + self.escapeHtml(emp.email) : ''}</span>
                            </div>
                        </td>
                        <td>
                            <span class="caja-emp-badge ${roleBadgeClass}">
                                ${roleIcon} ${self.escapeHtml(emp.role_name || emp.role)}
                            </span>
                        </td>
                        <td>
                            <div style="max-width:240px; display:flex; flex-wrap:wrap;">
                                ${sectorsHtml}
                            </div>
                        </td>
                        <td>${permsHtml}</td>
                        <td style="text-align:center;">${statusBadge}</td>
                        <td style="text-align:right;">${actionsHtml}</td>
                    </tr>
                `;
            });

            $('#caja-emp-tbody').html(html);
        },

        openEmployeeModal: function(userId) {
            const self = this;
            userId = parseInt(userId, 10) || 0;

            if (userId > 0) {
                // Modo Edición
                const emp = (this.cachedEmployees || []).find(function(e) { return e.id === userId; });
                if (!emp) return;

                $('#caja-emp-modal-title').text('Editar Empleado');
                $('#caja-emp-modal-sub').text('Modifica los accesos y permisos de @' + emp.username);
                $('#caja-emp-id').val(emp.id);
                $('#caja-emp-name').val(emp.name || '');
                $('#caja-emp-username').val(emp.username || '').prop('readonly', true).css('opacity', '0.7');
                $('#caja-emp-email').val(emp.email || '');
                $('#caja-emp-password').val('').removeAttr('required');
                $('#caja-emp-pass-req').hide();
                $('#caja-emp-pass-help').show();

                $(`input[name="caja_emp_role_radio"][value="${emp.role}"]`).prop('checked', true);

                // Checkboxes de sectores
                $('#caja-emp-sectors-checklist input[type="checkbox"]').prop('checked', false);
                if (emp.assigned_sectors && emp.assigned_sectors.length) {
                    emp.assigned_sectors.forEach(function(sec) {
                        $(`#caja-emp-sectors-checklist input[value="${sec}"]`).prop('checked', true);
                    });
                }

                $('#caja-emp-can-view-products').prop('checked', !!emp.can_view_products);
                $('#caja-emp-can-view-orders').prop('checked', !!emp.can_view_orders);
                $('#caja-emp-can-change-sector').prop('checked', !!emp.can_change_sector);
                $('#caja-emp-can-view-customer-data').prop('checked', !!emp.can_view_customer_data);
                $('#caja-emp-can-view-packaging').prop('checked', !!emp.can_view_packaging);
                $('#caja-emp-can-dispatch-orders').prop('checked', !!emp.can_dispatch_orders);
                $('#caja-emp-product-layout').val(emp.product_layout || 'grid');
                $('#caja-emp-product-grid-cols').val(emp.product_grid_cols || '2');
                $('#caja-emp-allow-layout-toggle').prop('checked', emp.allow_layout_toggle !== false && emp.allow_layout_toggle !== '0');
                $('#caja-emp-status').prop('checked', emp.status !== 'disabled');
            } else {
                // Modo Nuevo Empleado
                $('#caja-emp-modal-title').text('Nuevo Empleado');
                $('#caja-emp-modal-sub').text('Crea un nuevo usuario con rol y barras asignadas');
                $('#caja-emp-id').val('0');
                $('#caja-emp-name').val('');
                $('#caja-emp-username').val('').prop('readonly', false).css('opacity', '1');
                $('#caja-emp-email').val('');
                $('#caja-emp-password').val(self.generateRandomPassword(10)).attr('required', 'required');
                $('#caja-emp-pass-req').show();
                $('#caja-emp-pass-help').hide();

                $('input[name="caja_emp_role_radio"][value="caja_cajero"]').prop('checked', true);

                // Marcar la primera barra por defecto
                $('#caja-emp-sectors-checklist input[type="checkbox"]').prop('checked', false);
                $('#caja-emp-sectors-checklist input[type="checkbox"]').first().prop('checked', true);

                // Permisos defaults para cajero: productos OFF (solo venta caja), pedidos ON, mesas OFF, cliente OFF, empaque OFF, despachar OFF
                $('#caja-emp-can-view-products').prop('checked', false);
                $('#caja-emp-can-view-orders').prop('checked', true);
                $('#caja-emp-can-change-sector').prop('checked', false);
                $('#caja-emp-can-view-customer-data').prop('checked', false);
                $('#caja-emp-can-view-packaging').prop('checked', false);
                $('#caja-emp-can-dispatch-orders').prop('checked', false);
                $('#caja-emp-product-layout').val('grid');
                $('#caja-emp-product-grid-cols').val('2');
                $('#caja-emp-allow-layout-toggle').prop('checked', true);
                $('#caja-emp-status').prop('checked', true);
            }

            $('#caja-modal-employee').fadeIn(150);
        },

        saveEmployee: function() {
            const self = this;
            const $btn = $('#caja-emp-modal-save');
            const $spinner = $btn.find('.caja-emp-save-spinner');
            const $txt = $('#caja-emp-save-txt');

            const id = parseInt($('#caja-emp-id').val(), 10) || 0;
            const name = $.trim($('#caja-emp-name').val());
            const username = $.trim($('#caja-emp-username').val());
            const email = $.trim($('#caja-emp-email').val());
            const password = $('#caja-emp-password').val();
            const role = $('input[name="caja_emp_role_radio"]:checked').val() || 'caja_cajero';

            const assignedSectors = [];
            $('#caja-emp-sectors-checklist input[type="checkbox"]:checked').each(function() {
                assignedSectors.push($(this).val());
            });

            if (!assignedSectors.length && role !== 'administrator') {
                self.showToast('Debes asignarle al menos una barra al cajero.', 'error');
                return;
            }

            const canViewProducts = $('#caja-emp-can-view-products').is(':checked') ? '1' : '0';
            const canViewOrders = $('#caja-emp-can-view-orders').is(':checked') ? '1' : '0';
            const canChangeSector = $('#caja-emp-can-change-sector').is(':checked') ? '1' : '0';
            const canViewCustomerData = $('#caja-emp-can-view-customer-data').is(':checked') ? '1' : '0';
            const canViewPackaging = $('#caja-emp-can-view-packaging').is(':checked') ? '1' : '0';
            const canDispatchOrders = $('#caja-emp-can-dispatch-orders').is(':checked') ? '1' : '0';
            const productLayout = $('#caja-emp-product-layout').val() || 'grid';
            const productGridCols = $('#caja-emp-product-grid-cols').val() || '2';
            const allowLayoutToggle = $('#caja-emp-allow-layout-toggle').is(':checked') ? '1' : '0';
            const status = $('#caja-emp-status').is(':checked') ? 'active' : 'disabled';

            $btn.prop('disabled', true);
            $spinner.show();
            $txt.text('Guardando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_save_employee',
                    security: self.config.nonce,
                    employee: {
                        id: id,
                        name: name,
                        username: username,
                        email: email,
                        password: password,
                        role: role,
                        assigned_sectors: assignedSectors,
                        can_view_products: canViewProducts,
                        can_view_orders: canViewOrders,
                        can_change_sector: canChangeSector,
                        can_view_customer_data: canViewCustomerData,
                        can_view_packaging: canViewPackaging,
                        can_dispatch_orders: canDispatchOrders,
                        product_layout: productLayout,
                        product_grid_cols: productGridCols,
                        allow_layout_toggle: allowLayoutToggle,
                        status: status
                    }
                },
                success: function(res) {
                    $btn.prop('disabled', false);
                    $spinner.hide();
                    $txt.text('Guardar Empleado');

                    if (res && res.success) {
                        $('#caja-modal-employee').fadeOut(150);
                        self.showToast(res.data.message || 'Empleado guardado correctamente.', 'success');
                        self.loadEmployees(true);
                    } else {
                        const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al guardar empleado.';
                        self.showToast(msg, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $spinner.hide();
                    $txt.text('Guardar Empleado');
                    self.showToast('Error de conexión al guardar.', 'error');
                }
            });
        },

        toggleEmployeeStatus: function(userId) {
            const self = this;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_toggle_employee_status',
                    security: self.config.nonce,
                    user_id: userId
                },
                success: function(res) {
                    if (res && res.success) {
                        self.showToast(res.data.message || 'Estado actualizado.', 'success');
                        self.loadEmployees(true);
                    } else {
                        const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al cambiar estado.';
                        self.showToast(msg, 'error');
                    }
                },
                error: function() {
                    self.showToast('Error de conexión.', 'error');
                }
            });
        },

        deleteEmployee: function(userId) {
            const self = this;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_delete_employee',
                    security: self.config.nonce,
                    user_id: userId
                },
                success: function(res) {
                    if (res && res.success) {
                        self.showToast(res.data.message || 'Empleado eliminado.', 'success');
                        self.loadEmployees(true);
                    } else {
                        const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al eliminar empleado.';
                        self.showToast(msg, 'error');
                    }
                },
                error: function() {
                    self.showToast('Error de conexión.', 'error');
                }
            });
        },

        generateRandomPassword: function(len) {
            len = len || 10;
            const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
            let res = '';
            for (let i = 0; i < len; i++) {
                res += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return res;
        },

        // ==========================================
        // GESTIÓN DE SESIONES DE CAJA Y ARQUEO DE DINERO
        // ==========================================
        activeRegisterSession: null,
        currentAuditedSession: null,

        getSectorIcon: function(sector) {
            const s = (sector || '').toLowerCase();
            if (s.indexOf('vip') !== -1) return '⭐';
            if (s.indexOf('terraza') !== -1) return '🌿';
            if (s.indexOf('mesa') !== -1) return '🍽️';
            if (s.indexOf('take') !== -1) return '🛍️';
            if (s.indexOf('boleteria') !== -1 || s.indexOf('entrada') !== -1) return '🎟️';
            if (s.indexOf('pista') !== -1) return '🎵';
            return '🍸';
        },

        initRegisterSessionUI: function() {
            const self = this;
            this.canManageRegisters = Boolean(this.config && this.config.canManageRegisters);
            this.activeRegisterSession = this.config.activeSession || null;
            this.userPendingRegisterRequest = (this.config && this.config.userPendingRegisterRequest) || null;
            this.lastKnownPendingRequestsCount = parseInt(this.config && this.config.pendingRegisterRequestsCount, 10) || 0;

            if (this.canManageRegisters) {
                const pCount = this.lastKnownPendingRequestsCount;
                const $badge = $('#caja-pending-requests-badge');
                $badge.text(pCount);
                if (pCount > 0) {
                    $badge.addClass('has-pending');
                } else {
                    $badge.removeClass('has-pending');
                }
            }

            this.updateRegisterBarUI(this.activeRegisterSession);

            // Si entra a Venta Caja y no tiene caja abierta ni solicitud pendiente, abrir modal de apertura automáticamente
            if (!this.activeRegisterSession && !this.userPendingRegisterRequest && (this.activeTab === 'tab-pos-sale' || window.location.hash === '#venta-caja' || window.location.hash === '#pos')) {
                setTimeout(function() {
                    self.openRegisterModal();
                }, 350);
            }
        },

        updateRegisterBarUI: function(session) {
            const $activeBox = $('#caja-pos-reg-active-wrap');
            const $closedBox = $('#caja-pos-reg-closed-wrap');
            const $pendingBox = $('#caja-pos-reg-pending-wrap');

            const pending = this.userPendingRegisterRequest;

            if (pending && pending.status === 'pending') {
                $activeBox.hide();
                $closedBox.hide();
                $pendingBox.css('display', 'flex');

                const isOpening = (pending.request_type === 'open');
                const title = isOpening ? '⏳ Solicitud de Apertura de Caja enviada' : '⏳ Solicitud de Cierre y Arqueo enviada';
                const desc = isOpening 
                    ? 'Tu solicitud está en cola de aprobación por un administrador o encargado. La caja se habilitará automáticamente al ser autorizada.'
                    : 'Tu arqueo de cierre fue enviado a la administración para su revisión y aprobación final.';
                
                $('#caja-pos-pending-reg-title').text(title);
                $('#caja-pos-pending-reg-desc').text(desc);

                let detailsHtml = `<strong>Sector:</strong> ${pending.sector || 'Barra Principal'}`;
                if (isOpening) {
                    detailsHtml += ` • <strong>Fondo Solicitado:</strong> $${this.formatCurrencyNumber(pending.opening_cash)}`;
                } else {
                    detailsHtml += ` • <strong>Efectivo Contado:</strong> $${this.formatCurrencyNumber(pending.counted_cash)}`;
                    const diffNum = parseFloat(pending.difference) || 0;
                    if (diffNum !== 0) {
                        detailsHtml += ` • <span style="color:${diffNum < 0 ? '#f87171' : '#fbbf24'};"><strong>Diferencia:</strong> ${diffNum < 0 ? '-' : '+'}$${this.formatCurrencyNumber(Math.abs(diffNum))}</span>`;
                    }
                }
                if (pending.created_at) {
                    detailsHtml += ` • <strong>Enviada:</strong> ${pending.created_at}`;
                }
                $('#caja-pos-pending-reg-details').html(detailsHtml);
                return;
            }

            $pendingBox.hide();

            if (session && session.status === 'open') {
                $closedBox.hide();
                $activeBox.css('display', 'flex');

                $('#caja-pos-reg-sector-label').text(session.sector || 'Barra Principal');
                $('#caja-pos-reg-code-label').text(session.session_code || `#${session.id}`);
                $('#caja-pos-reg-opening-label').text('$' + this.formatCurrencyNumber(session.opening_cash));
                $('#caja-pos-reg-sales-label').text('$' + this.formatCurrencyNumber(session.total_sales));
                $('#caja-pos-reg-orders-count').text(session.orders_count || 0);

                // Sincronizar sector en el POS si está asignado
                if (session.sector && this.gastroSector !== session.sector) {
                    this.gastroSector = session.sector;
                    $(`.caja-gastro-chip[data-sector="${session.sector}"]`).addClass('active').siblings().removeClass('active');
                }
            } else {
                $activeBox.hide();
                $closedBox.css('display', 'flex');
            }
        },

        openRegisterModal: function() {
            if (!this.canManageRegisters) {
                $('#caja-open-reg-submit-btn').html('<span>📨</span> <span>Solicitar Apertura de Caja</span>');
                $('#caja-modal-open-register .caja-modal-title').text('Solicitud de Apertura de Caja');
                $('#caja-modal-open-register .caja-modal-header p').text('Envía el monto inicial para que un administrador o encargado autorice tu turno.');
            } else {
                $('#caja-open-reg-submit-btn').html('<span>🟢</span> <span>Iniciar Turno y Abrir Caja</span>');
                $('#caja-modal-open-register .caja-modal-title').text('Apertura de Caja (Iniciar Turno)');
                $('#caja-modal-open-register .caja-modal-header p').text('Ingresa el fondo de cambio inicial para comenzar a cobrar.');
            }
            $('#caja-modal-open-register').fadeIn(150);
            $('#caja-open-reg-cash').focus().select();
        },

        closeRegisterModal: function() {
            $('#caja-modal-open-register').fadeOut(150);
        },

        submitOpenRegister: function() {
            const self = this;
            const sector = $('#caja-open-reg-sector').val() || 'Barra Principal';
            const cash = parseFloat($('#caja-open-reg-cash').val()) || 0;
            const notes = $.trim($('#caja-open-reg-notes').val() || '');

            const $btn = $('#caja-open-reg-submit-btn');
            const origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="caja-spinner">⏳</span> Procesando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_open_register',
                    security: self.config.nonce,
                    sector: sector,
                    opening_cash: cash,
                    notes: notes
                },
                success: function(res) {
                    $btn.prop('disabled', false).html(origHtml);
                    if (res && res.success && res.data) {
                        if (res.data.is_pending_approval) {
                            self.userPendingRegisterRequest = res.data.request;
                            self.updateRegisterBarUI(null);
                            self.closeRegisterModal();
                            self.showToast(res.data.message || 'Solicitud de apertura enviada a administración.', 'info', 6000);
                            return;
                        }
                        if (res.data.session) {
                            self.userPendingRegisterRequest = null;
                            self.activeRegisterSession = res.data.session;
                            self.updateRegisterBarUI(res.data.session);
                            self.closeRegisterModal();
                            self.showToast(res.data.message || 'Caja abierta con éxito.', 'success');
                            return;
                        }
                    }
                    const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al abrir caja.';
                    self.showToast(msg, 'error');
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    self.showToast('Error de conexión al abrir la caja.', 'error');
                }
            });
        },

        openCloseRegisterModal: function() {
            const self = this;
            const session = this.activeRegisterSession;
            if (!session) {
                this.showToast('No hay una caja abierta para cerrar.', 'warning');
                return;
            }

            if (!this.canManageRegisters) {
                $('#caja-close-reg-confirm-btn').html('<span>📨</span> <span>Enviar Solicitud de Cierre</span>');
            } else {
                $('#caja-close-reg-confirm-btn').html('<span>🔒</span> <span>Confirmar y Cerrar Caja</span>');
            }

            $('#caja-close-reg-sub').text(`Turno: ${session.session_code} • Sector: ${session.sector} • Apertura: ${session.opened_time_str || ''}`);
            $('#caja-close-calc-opening').text('$' + this.formatCurrencyNumber(session.opening_cash));
            $('#caja-close-calc-cash-sales').text('$' + this.formatCurrencyNumber(session.cash_sales));
            $('#caja-close-calc-tips-cash').text('$' + this.formatCurrencyNumber(session.tips_cash));
            $('#caja-close-calc-expected').text('$' + this.formatCurrencyNumber(session.expected_cash));
            $('#caja-close-calc-digital').text('$' + this.formatCurrencyNumber(session.digital_sales));

            // Resetear inputs de billetes
            $('.caja-bill-input').val(0);
            $('.caja-bill-subtotal').text('$0,00');
            $('#caja-close-reg-manual-cash').val('');
            $('#caja-close-reg-notes').val('');

            this.recalcCloseRegisterArqueo();
            $('#caja-modal-close-register').fadeIn(150);
        },

        recalcCloseRegisterArqueo: function() {
            const session = this.activeRegisterSession;
            const expected = session ? (parseFloat(session.expected_cash) || 0) : 0;

            let billsTotal = 0;
            const denominations = [20000, 10000, 2000, 1000, 500, 200, 100];
            const self = this;
            denominations.forEach(denom => {
                const qty = parseInt($(`.caja-bill-input[data-denom="${denom}"]`).val(), 10) || 0;
                const sub = qty * denom;
                billsTotal += sub;
                $(`#caja-bill-sub-${denom}`).text('$' + self.formatCurrencyNumber(sub));
            });

            $('#caja-close-reg-bills-total').text('$' + this.formatCurrencyNumber(billsTotal));

            const manualVal = parseFloat($('#caja-close-reg-manual-cash').val());
            const counted = (!isNaN(manualVal) && manualVal >= 0 && $('#caja-close-reg-manual-cash').val() !== '') ? manualVal : billsTotal;

            $('#caja-close-calc-counted').text('$' + this.formatCurrencyNumber(counted));

            const diff = counted - expected;
            const $badge = $('#caja-close-diff-badge');

            if (diff === 0) {
                $badge.css({
                    'background': 'rgba(16, 185, 129, 0.15)',
                    'color': '#10b981',
                    'border': '1px solid rgba(16, 185, 129, 0.3)'
                }).html('✅ Caja Cuadrada Exacta ($0,00)');
            } else if (diff < 0) {
                $badge.css({
                    'background': 'rgba(239, 68, 68, 0.15)',
                    'color': '#f87171',
                    'border': '1px solid rgba(239, 68, 68, 0.3)'
                }).html(`❌ Faltante de Dinero: -$${this.formatCurrencyNumber(Math.abs(diff))}`);
            } else {
                $badge.css({
                    'background': 'rgba(245, 158, 11, 0.15)',
                    'color': '#fbbf24',
                    'border': '1px solid rgba(245, 158, 11, 0.3)'
                }).html(`⚠️ Sobrante de Dinero: +$${this.formatCurrencyNumber(diff)}`);
            }
        },

        submitCloseRegister: function() {
            const self = this;
            const session = this.activeRegisterSession;
            if (!session) {
                this.showToast('No hay una caja abierta para cerrar.', 'warning');
                return;
            }

            const denominations = [20000, 10000, 2000, 1000, 500, 200, 100];
            const breakdown = {};
            let billsTotal = 0;
            denominations.forEach(denom => {
                const qty = parseInt($(`.caja-bill-input[data-denom="${denom}"]`).val(), 10) || 0;
                breakdown[denom] = qty;
                billsTotal += (qty * denom);
            });

            const manualVal = parseFloat($('#caja-close-reg-manual-cash').val());
            const counted = (!isNaN(manualVal) && manualVal >= 0 && $('#caja-close-reg-manual-cash').val() !== '') ? manualVal : billsTotal;
            const notes = $.trim($('#caja-close-reg-notes').val() || '');
            const expected = parseFloat(session.expected_cash) || 0;
            const diff = counted - expected;

            if (diff !== 0 && !notes) {
                const confirmed = window.confirm(`Existe una diferencia de arqueo (${diff < 0 ? 'Faltante: -$' : 'Sobrante: +$'}${self.formatCurrencyNumber(Math.abs(diff))}).\n\n¿Deseas cerrar la caja sin agregar una nota explicativa?`);
                if (!confirmed) {
                    $('#caja-close-reg-notes').focus();
                    return;
                }
            }

            const $btn = $('#caja-close-reg-confirm-btn');
            const origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="caja-spinner">⏳</span> Cerrando caja...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_close_register',
                    security: self.config.nonce,
                    session_id: session.id,
                    counted_cash: counted,
                    cash_breakdown: JSON.stringify(breakdown),
                    closing_notes: notes
                },
                success: function(res) {
                    $btn.prop('disabled', false).html(origHtml);
                    if (res && res.success && res.data) {
                        if (res.data.is_pending_approval) {
                            self.userPendingRegisterRequest = res.data.request;
                            $('#caja-modal-close-register').fadeOut(150);
                            self.updateRegisterBarUI(self.activeRegisterSession);
                            self.showToast(res.data.message || 'Solicitud de cierre enviada a administración.', 'info', 6000);
                            return;
                        }
                        if (res.data.session) {
                            const closedSession = res.data.session;
                            self.userPendingRegisterRequest = null;
                            self.activeRegisterSession = null;
                            self.updateRegisterBarUI(null);
                            $('#caja-modal-close-register').fadeOut(150);
                            self.showToast(res.data.message || 'Caja cerrada correctamente.', 'success');

                            // Preguntar si desea imprimir el ticket Z
                            setTimeout(function() {
                                if (window.confirm('¿Deseas imprimir el Ticket de Cierre Z y Arqueo de Dinero?')) {
                                    self.printSessionZTicket(closedSession.id);
                                }
                            }, 300);
                            return;
                        }
                    }
                    const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al cerrar caja.';
                    self.showToast(msg, 'error');
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    self.showToast('Error de conexión al cerrar la caja.', 'error');
                }
            });
        },

        // ==========================================
        // SOLICITUDES DE APERTURA Y CIERRE DE CAJA
        // ==========================================
        loadPendingRegisterRequests: function() {
            const self = this;
            const $container = $('#caja-pending-requests-container');
            if (!$container.length) return;

            $container.html(`
                <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 20px; text-align: center; color: var(--caja-text-muted);">
                    <span class="caja-spinner">⏳</span> Cargando solicitudes pendientes...
                </div>
            `);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_get_register_requests',
                    security: self.config.nonce,
                    status: 'pending'
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        const requests = res.data.requests || [];
                        self.renderPendingRegisterRequests(requests);
                    } else {
                        $container.html(`
                            <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 20px; text-align: center; color: #f87171;">
                                ${res && res.data && res.data.message ? res.data.message : 'Error al cargar solicitudes.'}
                            </div>
                        `);
                    }
                },
                error: function() {
                    $container.html(`
                        <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 20px; text-align: center; color: #f87171;">
                            Error de conexión al cargar solicitudes.
                        </div>
                    `);
                }
            });
        },

        renderPendingRegisterRequests: function(requests) {
            const self = this;
            const $container = $('#caja-pending-requests-container');
            const $badge = $('#caja-pending-requests-badge');
            if (!$container.length) return;

            const count = (requests && Array.isArray(requests)) ? requests.length : 0;
            $badge.text(count);
            if (count > 0) {
                $badge.addClass('has-pending');
            } else {
                $badge.removeClass('has-pending');
            }

            if (!count) {
                $container.html(`
                    <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 22px; text-align: center; background: var(--caja-card-bg, #1e293b); border-radius: 8px; border: 1px dashed var(--caja-card-border, #334155); color: var(--caja-text-muted, #94a3b8);">
                        <span>✅ No hay solicitudes de apertura o cierre pendientes de aprobación.</span>
                    </div>
                `);
                return;
            }

            let html = '';
            requests.forEach(req => {
                const isOpen = (req.request_type === 'open');
                const typeClass = isOpen ? 'open' : 'close';
                const typeIcon = isOpen ? '🟢' : '🔒';
                const typeTitle = isOpen ? 'Solicitud de Apertura' : 'Solicitud de Cierre y Arqueo';
                
                let amountHtml = '';
                if (isOpen) {
                    amountHtml = `
                        <div class="caja-pending-amount-row">
                            <span style="color:var(--caja-text-muted);">Fondo Inicial Solicitado:</span>
                            <strong style="color:#10b981; font-size:1.05rem;">$${self.formatCurrencyNumber(req.opening_cash)}</strong>
                        </div>
                    `;
                } else {
                    const counted = parseFloat(req.counted_cash) || 0;
                    const expected = parseFloat(req.expected_cash) || 0;
                    const diff = parseFloat(req.difference) || 0;

                    let diffBadge = '';
                    if (diff === 0) {
                        diffBadge = '<span style="color:#10b981; font-weight:700;">✅ Cuadrada Exacta</span>';
                    } else if (diff < 0) {
                        diffBadge = `<span style="color:#f87171; font-weight:700;">❌ Faltante: -$${self.formatCurrencyNumber(Math.abs(diff))}</span>`;
                    } else {
                        diffBadge = `<span style="color:#fbbf24; font-weight:700;">⚠️ Sobrante: +$${self.formatCurrencyNumber(diff)}</span>`;
                    }

                    let billsHtml = '';
                    if (req.cash_breakdown && typeof req.cash_breakdown === 'object') {
                        const chips = [];
                        Object.keys(req.cash_breakdown).forEach(denom => {
                            const q = parseInt(req.cash_breakdown[denom], 10) || 0;
                            if (q > 0) {
                                chips.push(`<span class="caja-pending-bill-chip">$${self.formatCurrencyNumber(denom)}: <strong>×${q}</strong></span>`);
                            }
                        });
                        if (chips.length > 0) {
                            billsHtml = `<div class="caja-pending-bills-wrap"><span style="font-size:0.7rem; color:var(--caja-text-muted); width:100%;">Detalle de billetes:</span>${chips.join('')}</div>`;
                        }
                    }

                    amountHtml = `
                        <div class="caja-pending-amount-row">
                            <span style="color:var(--caja-text-muted);">Efectivo Contado:</span>
                            <strong style="color:#10b981; font-size:1.05rem;">$${self.formatCurrencyNumber(counted)}</strong>
                        </div>
                        <div class="caja-pending-amount-row">
                            <span style="color:var(--caja-text-muted);">Efectivo Esperado:</span>
                            <strong style="color:#38bdf8;">$${self.formatCurrencyNumber(expected)}</strong>
                        </div>
                        <div class="caja-pending-amount-row">
                            <span style="color:var(--caja-text-muted);">Estado Arqueo:</span>
                            ${diffBadge}
                        </div>
                        ${billsHtml}
                    `;
                }

                const notesHtml = req.notes ? `<div class="caja-pending-notes">"${self.escapeHtml(req.notes)}"</div>` : '';

                html += `
                    <div class="caja-pending-card type-${typeClass}" id="caja-pending-card-${req.id}">
                        <div>
                            <div class="caja-pending-card-header">
                                <span class="caja-pending-type-tag ${typeClass}">
                                    <span>${typeIcon}</span> ${typeTitle}
                                </span>
                                <span class="caja-pending-time">${req.created_at || ''}</span>
                            </div>

                            <div class="caja-pending-user-info">
                                <div class="caja-pending-avatar">${(req.user_name || 'U').charAt(0).toUpperCase()}</div>
                                <div>
                                    <div class="caja-pending-user-name">${self.escapeHtml(req.user_name || 'Cajero')}</div>
                                    <div class="caja-pending-user-sector">📍 ${self.escapeHtml(req.sector || 'Barra Principal')}${req.cash_box_name ? ' • 💵 ' + self.escapeHtml(req.cash_box_name) : ''}</div>
                                </div>
                            </div>

                            <div class="caja-pending-amounts-box">
                                ${amountHtml}
                            </div>

                            ${notesHtml}
                        </div>

                        <div class="caja-pending-actions">
                            <button type="button" class="caja-btn-approve-request" data-id="${req.id}" data-type="${req.request_type}" data-user="${self.escapeHtml(req.user_name || '')}">
                                <span>✅</span> Aprobar
                            </button>
                            <button type="button" class="caja-btn-reject-request" data-id="${req.id}" data-type="${req.request_type}" data-user="${self.escapeHtml(req.user_name || '')}">
                                <span>❌</span> Rechazar
                            </button>
                        </div>
                    </div>
                `;
            });

            $container.html(html);
        },

        approveRegisterRequest: function(requestId) {
            const self = this;
            const $btn = $(`.caja-btn-approve-request[data-id="${requestId}"]`);
            const origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="caja-spinner">⏳</span> Aprobando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_approve_register_request',
                    security: self.config.nonce,
                    request_id: requestId
                },
                success: function(res) {
                    $btn.prop('disabled', false).html(origHtml);
                    if (res && res.success) {
                        self.showToast(res.data.message || 'Solicitud aprobada correctamente.', 'success');
                        self.loadPendingRegisterRequests();
                        if (typeof self.loadMoneyBoxes === 'function') self.loadMoneyBoxes();
                        if (typeof self.loadRegistersHistory === 'function') self.loadRegistersHistory();
                    } else {
                        const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al aprobar solicitud.';
                        self.showToast(msg, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    self.showToast('Error de conexión al procesar la aprobación.', 'error');
                }
            });
        },

        rejectRegisterRequest: function(requestId, adminNotes) {
            const self = this;
            const $btn = $('#caja-btn-confirm-reject-request');
            const origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="caja-spinner">⏳</span> Rechazando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_reject_register_request',
                    security: self.config.nonce,
                    request_id: requestId,
                    admin_notes: adminNotes
                },
                success: function(res) {
                    $btn.prop('disabled', false).html(origHtml);
                    if (res && res.success) {
                        $('#caja-modal-reject-request').fadeOut(150);
                        self.showToast(res.data.message || 'Solicitud rechazada.', 'info');
                        self.loadPendingRegisterRequests();
                    } else {
                        const msg = (res && res.data && res.data.message) ? res.data.message : 'Error al rechazar solicitud.';
                        self.showToast(msg, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    self.showToast('Error de conexión al rechazar solicitud.', 'error');
                }
            });
        },

        checkUserRegisterRequest: function() {
            const self = this;
            const $btn = $('#caja-btn-check-pending-reg');
            const origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="caja-spinner">⏳</span> Consultando...');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_check_user_register_request',
                    security: self.config.nonce
                },
                success: function(res) {
                    $btn.prop('disabled', false).html(origHtml);
                    if (res && res.success && res.data) {
                        const req = res.data.request;
                        if (!req || req.status === 'approved') {
                            self.userPendingRegisterRequest = null;
                            self.activeRegisterSession = res.data.active_session || null;
                            self.updateRegisterBarUI(self.activeRegisterSession);
                            self.showToast(res.data.message || '¡Tu solicitud ha sido aprobada!', 'success');
                        } else if (req.status === 'rejected') {
                            self.userPendingRegisterRequest = null;
                            self.updateRegisterBarUI(self.activeRegisterSession);
                            const reason = req.admin_notes || 'Sin motivo especificado';
                            self.showToast(`Solicitud rechazada: ${reason}`, 'error', 8000);
                            window.alert(`❌ Tu solicitud fue rechazada por administración.\n\nMotivo:\n${reason}`);
                        } else {
                            self.showToast('Tu solicitud aún está pendiente de aprobación por administración.', 'info');
                        }
                    } else {
                        self.showToast((res && res.data && res.data.message) ? res.data.message : 'Estado verificado.', 'info');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    self.showToast('Error de conexión al verificar solicitud.', 'error');
                }
            });
        },

        cancelUserRegisterRequest: function() {
            const self = this;
            const req = self.userPendingRegisterRequest;
            if (!req) return;

            if (!window.confirm('¿Seguro que deseas cancelar esta solicitud pendiente de apertura o cierre?')) {
                return;
            }

            const $btn = $('#caja-btn-cancel-pending-reg');
            const origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="caja-spinner">⏳</span>');

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_cancel_register_request',
                    security: self.config.nonce,
                    request_id: req.id
                },
                success: function(res) {
                    $btn.prop('disabled', false).html(origHtml);
                    if (res && res.success) {
                        self.userPendingRegisterRequest = null;
                        self.updateRegisterBarUI(self.activeRegisterSession);
                        self.showToast(res.data.message || 'Solicitud cancelada.', 'info');
                    } else {
                        self.showToast((res && res.data && res.data.message) ? res.data.message : 'Error al cancelar.', 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    self.showToast('Error de conexión al cancelar.', 'error');
                }
            });
        },

        // ==========================================
        // GESTIÓN DE CAJAS DE DINERO Y PUESTOS (ADMIN Y ENCARGADO)
        // REGLA: Ningún puesto puede pertenecer a más de una caja de dinero
        // ==========================================
        loadMoneyBoxes: function() {
            const self = this;
            const $grid = $('#caja-money-boxes-grid');
            if (!$grid.length) return;

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_get_money_boxes',
                    security: self.config.nonce
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        self.cachedMoneyBoxes = res.data.boxes || [];
                        self.availablePuestos = res.data.available_puestos || [];
                        self.assignedPuestosMap = res.data.assigned_puestos_map || {};
                        self.renderMoneyBoxes();
                    } else {
                        $grid.html(`
                            <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 24px; text-align: center; color: #f87171;">
                                ${res && res.data && res.data.message ? res.data.message : 'Error al cargar cajas de dinero.'}
                            </div>
                        `);
                    }
                },
                error: function() {
                    $grid.html(`
                        <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 24px; text-align: center; color: #f87171;">
                            Error de conexión al cargar cajas de dinero.
                        </div>
                    `);
                }
            });
        },

        renderMoneyBoxes: function() {
            const self = this;
            const $grid = $('#caja-money-boxes-grid');
            const boxes = self.cachedMoneyBoxes || [];

            $('#caja-money-boxes-count').text(boxes.length);

            // Actualizar selector de filtro de historial
            const $filterBox = $('#caja-reg-filter-money-box');
            if ($filterBox.length) {
                const currentVal = $filterBox.val() || 'all';
                let optHtml = '<option value="all">💵 Todas las cajas de dinero</option>';
                boxes.forEach(b => {
                    optHtml += `<option value="${self.escapeHtml(b.id)}">💵 ${self.escapeHtml(b.name)} ${b.code ? `(${self.escapeHtml(b.code)})` : ''}</option>`;
                });
                $filterBox.html(optHtml).val(currentVal);
            }

            if (boxes.length === 0) {
                $grid.html(`
                    <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 36px 20px; text-align: center; background: var(--caja-card-bg, #1e293b); border-radius: 8px; border: 1px dashed var(--caja-card-border, #334155); color: var(--caja-text-muted, #94a3b8);">
                        <span style="font-size: 2.2rem; display:block; margin-bottom: 8px;">💵</span>
                        <strong style="font-size: 1rem; color:var(--caja-text); display:block; margin-bottom: 4px;">No hay cajas de dinero registradas</strong>
                        <p style="font-size: 0.85rem; margin-bottom: 16px;">Crea tu primera caja de dinero para vincularla a tus mostradores o barras de atención.</p>
                        <button type="button" class="caja-btn caja-btn-primary" id="caja-btn-empty-new-box">
                            <span>➕</span> <span>Crear Primera Caja de Dinero</span>
                        </button>
                    </div>
                `);
                return;
            }

            let html = '';
            boxes.forEach(box => {
                const isActive = (box.status !== 'inactive');
                const puestos = Array.isArray(box.assigned_puestos) ? box.assigned_puestos : [];
                const isOpen = Boolean(box.is_open);
                const currentCash = parseFloat(box.current_cash) || 0;
                const activeSess = box.active_session;
                const stats = box.today_stats || {};

                let puestosChips = '';
                if (puestos.length > 0) {
                    puestos.forEach(p => {
                        let icon = '🏪';
                        const pLow = p.toLowerCase();
                        if (pLow.indexOf('vip') !== -1) icon = '⭐';
                        else if (pLow.indexOf('terraza') !== -1) icon = '🌿';
                        else if (pLow.indexOf('take') !== -1) icon = '🛍️';
                        else if (pLow.indexOf('barra') !== -1) icon = '🍸';
                        else if (pLow.indexOf('boleteria') !== -1 || pLow.indexOf('entrada') !== -1) icon = '🎟️';

                        puestosChips += `<span class="caja-puesto-chip"><span>${icon}</span> <span>${self.escapeHtml(p)}</span></span>`;
                    });
                } else {
                    puestosChips = `<span class="caja-puesto-chip is-empty">⚠️ Sin mostradores asignados</span>`;
                }

                // Desglose de turno activo o métricas del día
                let miniStatsHtml = '';
                if (isOpen && activeSess) {
                    miniStatsHtml = `
                        <div class="caja-mb-mini-stats">
                            <div class="caja-mb-mini-stat-item"><span>Fondo Inicial:</span> <strong>$${self.formatCurrencyNumber(activeSess.opening_cash)}</strong></div>
                            <div class="caja-mb-mini-stat-item"><span>Ventas Ef.:</span> <strong style="color:#34d399;">+$${self.formatCurrencyNumber(activeSess.cash_sales)}</strong></div>
                            <div class="caja-mb-mini-stat-item"><span>Ingresos:</span> <strong style="color:#10b981;">+$${self.formatCurrencyNumber(activeSess.cash_in || 0)}</strong></div>
                            <div class="caja-mb-mini-stat-item"><span>Retiros:</span> <strong style="color:#f87171;">-$${self.formatCurrencyNumber(activeSess.cash_out || 0)}</strong></div>
                        </div>
                    `;
                } else {
                    miniStatsHtml = `
                        <div class="caja-mb-mini-stats">
                            <div class="caja-mb-mini-stat-item"><span>Ventas Hoy:</span> <strong>$${self.formatCurrencyNumber(stats.total_sales || 0)}</strong></div>
                            <div class="caja-mb-mini-stat-item"><span>Movimientos:</span> <strong>${box.movements_count || 0}</strong></div>
                        </div>
                    `;
                }

                html += `
                    <div class="caja-money-box-card ${!isActive ? 'is-inactive' : ''}" data-id="${self.escapeHtml(box.id)}">
                        <div>
                            <div class="caja-mb-card-header">
                                <div>
                                    <h3 class="caja-mb-card-title">
                                        <span>💵</span>
                                        <span>${self.escapeHtml(box.name)}</span>
                                        ${box.code ? `<span class="caja-mb-card-code">${self.escapeHtml(box.code)}</span>` : ''}
                                    </h3>
                                </div>
                                <span class="caja-mb-card-status ${isActive ? 'active' : 'inactive'}">
                                    ${isActive ? '🟢 Activa' : '⚪ Inactiva'}
                                </span>
                            </div>

                            ${box.notes ? `<div class="caja-mb-card-notes">${self.escapeHtml(box.notes)}</div>` : ''}

                            <!-- Bloque de Saldo de Dinero en Vivo -->
                            <div class="caja-mb-balance-block">
                                <div class="caja-mb-balance-header">
                                    <span class="caja-mb-balance-label">Efectivo en Caja:</span>
                                    <span class="caja-mb-session-badge ${isOpen ? 'open' : 'closed'}">
                                        ${isOpen ? `🟢 ${self.escapeHtml(activeSess.user_name)} (${self.escapeHtml(activeSess.sector)})` : '🔒 Sin turno activo'}
                                    </span>
                                </div>
                                <div class="caja-mb-balance-amount">
                                    <span>$</span><span>${self.formatCurrencyNumber(currentCash)}</span>
                                </div>
                                ${miniStatsHtml}
                            </div>

                            <span class="caja-mb-card-puestos-label">Puestos / Mostradores Vinculados (${puestos.length}):</span>
                            <div class="caja-mb-card-puestos-list">
                                ${puestosChips}
                            </div>
                        </div>

                        <div class="caja-mb-card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <div style="display:flex; gap:6px;">
                                <button type="button" class="caja-btn caja-btn-primary caja-btn-sm caja-btn-control-cash" data-id="${self.escapeHtml(box.id)}" data-name="${self.escapeHtml(box.name)}" title="Ingreso, Retiro o Conteo Físico de Dinero">
                                    <span>💵</span> <span>Controlar Dinero</span>
                                </button>
                                <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm caja-btn-box-movements" data-id="${self.escapeHtml(box.id)}" data-name="${self.escapeHtml(box.name)}" title="Ver todos los movimientos guardados de esta caja">
                                    <span>📜</span> <span>Historial (${box.movements_count || 0})</span>
                                </button>
                            </div>
                            <div style="display:flex; gap:6px;">
                                <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm caja-btn-edit-money-box" data-id="${self.escapeHtml(box.id)}" title="Editar caja y reasignar puestos">
                                    <span>✏️</span>
                                </button>
                                <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm caja-btn-delete-money-box" data-id="${self.escapeHtml(box.id)}" data-name="${self.escapeHtml(box.name)}" title="Eliminar caja" style="color: #f87171;">
                                    <span>🗑️</span>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });

            $grid.html(html);
        },

        openMoneyBoxModal: function(boxId = null) {
            const self = this;
            const $modal = $('#caja-modal-money-box');
            if (!$modal.length) return;

            $('#caja-mb-alert-error').hide().text('');
            $('#caja-mb-new-puesto-name').val('');

            let box = null;
            if (boxId) {
                box = (self.cachedMoneyBoxes || []).find(b => b.id === boxId);
            }

            if (box) {
                $('#caja-mb-modal-title').text('Editar Caja de Dinero');
                $('#caja-mb-id').val(box.id);
                $('#caja-mb-name').val(box.name);
                $('#caja-mb-code').val(box.code || '');
                $('#caja-mb-notes').val(box.notes || '');
                $('#caja-mb-status').val(box.status || 'active');
            } else {
                $('#caja-mb-modal-title').text('Nueva Caja de Dinero');
                $('#caja-mb-id').val('');
                $('#caja-mb-name').val('');
                $('#caja-mb-code').val('');
                $('#caja-mb-notes').val('');
                $('#caja-mb-status').val('active');
            }

            self.renderModalPuestosChecklist(box);
            $modal.fadeIn(200);
        },

        renderModalPuestosChecklist: function(currentBox = null) {
            const self = this;
            const $container = $('#caja-mb-puestos-container');
            const available = self.availablePuestos || [];
            const assignedMap = self.assignedPuestosMap || {};
            const currentBoxPuestos = (currentBox && Array.isArray(currentBox.assigned_puestos)) ? currentBox.assigned_puestos : [];
            const currentBoxId = currentBox ? currentBox.id : null;

            if (available.length === 0) {
                $container.html('<div style="color:var(--caja-text-muted); font-size:0.85rem; padding:8px;">No hay mostradores o puestos configurados. Agrega uno abajo.</div>');
                return;
            }

            let html = '';
            available.forEach(p => {
                const isAssignedToThis = currentBoxPuestos.some(cp => cp.toLowerCase() === p.toLowerCase());
                const assignedToOther = assignedMap[p] && (!currentBoxId || assignedMap[p].box_id !== currentBoxId);

                let icon = '🏪';
                const pLow = p.toLowerCase();
                if (pLow.indexOf('vip') !== -1) icon = '⭐';
                else if (pLow.indexOf('terraza') !== -1) icon = '🌿';
                else if (pLow.indexOf('take') !== -1) icon = '🛍️';
                else if (pLow.indexOf('barra') !== -1) icon = '🍸';
                else if (pLow.indexOf('boleteria') !== -1 || pLow.indexOf('entrada') !== -1) icon = '🎟️';

                if (assignedToOther) {
                    const otherBoxName = assignedMap[p].box_name || 'otra caja';
                    html += `
                        <label class="caja-mb-puesto-item is-disabled" title="Este puesto ya pertenece a ${self.escapeHtml(otherBoxName)}. Desvinculalo de esa caja si deseas asignarlo aquí.">
                            <div class="caja-mb-puesto-item-left">
                                <input type="checkbox" disabled />
                                <span style="font-size:0.86rem; color:var(--caja-text-muted); text-decoration:line-through;">${icon} ${self.escapeHtml(p)}</span>
                            </div>
                            <span class="caja-badge-puesto-locked">🔒 Asignado a: ${self.escapeHtml(otherBoxName)}</span>
                        </label>
                    `;
                } else {
                    html += `
                        <label class="caja-mb-puesto-item">
                            <div class="caja-mb-puesto-item-left">
                                <input type="checkbox" name="caja_mb_puestos[]" value="${self.escapeHtml(p)}" ${isAssignedToThis ? 'checked' : ''} />
                                <span style="font-size:0.88rem; font-weight:600; color:var(--caja-text, #f8fafc);">${icon} ${self.escapeHtml(p)}</span>
                            </div>
                            <span style="font-size:0.75rem; color:#10b981;">✓ Disponible</span>
                        </label>
                    `;
                }
            });

            $container.html(html);
        },

        // ==========================================
        // CONTROL DE DINERO EN CAJA (INGRESO / RETIRO / ARQUEO)
        // ==========================================
        openCashControlModal: function(boxId) {
            const self = this;
            const $modal = $('#caja-modal-cash-control');
            if (!$modal.length) return;

            const box = (self.cachedMoneyBoxes || []).find(b => b.id === boxId);
            if (!box) {
                self.showToast('Caja no encontrada.', 'error');
                return;
            }

            $('#caja-ctrl-alert-error').hide().text('');
            $('#caja-ctrl-box-id').val(box.id);
            $('#caja-ctrl-amount').val('');
            $('#caja-ctrl-concept').val('');
            $('#caja-ctrl-notes').val('');

            const curCash = parseFloat(box.current_cash) || 0;
            $('#caja-ctrl-current-balance-raw').val(curCash);
            $('#caja-ctrl-current-balance-display').text('$' + self.formatCurrencyNumber(curCash));

            $('#caja-ctrl-modal-title').text(`Control de Dinero: ${box.name}`);
            $('#caja-ctrl-modal-subtitle').text(`${box.code ? `[${box.code}] ` : ''}Puestos: ${Array.isArray(box.assigned_puestos) ? box.assigned_puestos.join(', ') : 'Ninguno'}`);

            // Insignia de turno
            const $badge = $('#caja-ctrl-session-badge-wrapper');
            if (box.is_open && box.active_session) {
                $badge.html(`
                    <span class="caja-mb-session-badge open" style="margin:0;">
                        🟢 Turno Abierto: ${self.escapeHtml(box.active_session.user_name)}
                    </span>
                    <span style="font-size:0.72rem; color:var(--caja-text-muted); display:block; margin-top:2px;">
                        ${self.escapeHtml(box.active_session.sector)}
                    </span>
                `);
            } else {
                $badge.html(`
                    <span class="caja-mb-session-badge closed" style="margin:0;">
                        🔒 Sin turno activo
                    </span>
                `);
            }

            // Seleccionar por defecto Ingreso
            $('.caja-ctrl-type-btn').removeClass('active');
            $('.caja-ctrl-type-btn[data-type="cash_in"]').addClass('active');
            $('#caja-ctrl-selected-type').val('cash_in');

            self.updateCashControlPreview();
            $modal.fadeIn(200);
        },

        updateCashControlPreview: function() {
            const self = this;
            const curBalance = parseFloat($('#caja-ctrl-current-balance-raw').val()) || 0;
            const type = $('#caja-ctrl-selected-type').val();
            const amount = parseFloat($('#caja-ctrl-amount').val()) || 0;

            let newBalance = curBalance;
            let flowText = '';
            let flowColor = '#10b981';

            if (type === 'cash_in') {
                newBalance = curBalance + amount;
                flowText = `+ $${self.formatCurrencyNumber(amount)}`;
                flowColor = '#10b981';
                $('#caja-ctrl-amount-label').text('Importe a Ingresar ($):');
                $('#caja-ctrl-concept').attr('placeholder', 'Ej: Aporte de cambio en billetes de $500 / sencillo inicial');
                $('#caja-ctrl-save-btn-text').text('Confirmar Ingreso de Dinero');
                $('#caja-ctrl-presets-row').show();
            } else if (type === 'cash_out') {
                newBalance = Math.max(0, curBalance - amount);
                flowText = `- $${self.formatCurrencyNumber(amount)}`;
                flowColor = '#f87171';
                $('#caja-ctrl-amount-label').text('Importe a Retirar ($):');
                $('#caja-ctrl-concept').attr('placeholder', 'Ej: Retiro para tesorería / Sangría / Pago a distribuidor de hielo');
                $('#caja-ctrl-save-btn-text').text('Confirmar Retiro de Dinero');
                $('#caja-ctrl-presets-row').show();
            } else if (type === 'adjustment') {
                newBalance = amount;
                const diff = amount - curBalance;
                flowText = `${diff >= 0 ? '+' : '-'} $${self.formatCurrencyNumber(Math.abs(diff))}`;
                flowColor = diff >= 0 ? '#10b981' : '#f87171';
                $('#caja-ctrl-amount-label').text('Efectivo Real Contado en Auditoría ($):');
                $('#caja-ctrl-concept').attr('placeholder', 'Ej: Ajuste por recuento físico en gaveta');
                $('#caja-ctrl-save-btn-text').text('Confirmar Ajuste de Saldo');
                $('#caja-ctrl-presets-row').hide();
            }

            $('#caja-ctrl-preview-balance').text('$' + self.formatCurrencyNumber(newBalance));
            $('#caja-ctrl-preview-flow').text(flowText).css('color', flowColor);
        },

        // ==========================================
        // HISTORIAL DE MOVIMIENTOS DE LA CAJA (MODAL)
        // ==========================================
        openBoxMovementsModal: function(boxId) {
            const self = this;
            const $modal = $('#caja-modal-box-movements');
            if (!$modal.length) return;

            self.currentAuditedBoxId = boxId;
            const box = (self.cachedMoneyBoxes || []).find(b => b.id === boxId);

            if (box) {
                $('#caja-bmov-title').text(`Movimientos: ${box.name}`);
                $('#caja-bmov-subtitle').text(`${box.code ? `[${box.code}] ` : ''}Puestos: ${Array.isArray(box.assigned_puestos) ? box.assigned_puestos.join(', ') : 'Ninguno'}`);
                $('#caja-bmov-stat-current').text('$' + self.formatCurrencyNumber(box.current_cash || 0));
            }

            // Reset filtros
            $('#caja-bmov-filter-type').val('all');
            $('#caja-bmov-filter-preset').val('today');

            self.loadBoxMovements(boxId);
            $modal.fadeIn(200);
        },

        loadBoxMovements: function(boxId) {
            const self = this;
            const $tbody = $('#caja-bmov-table-body');
            const type = $('#caja-bmov-filter-type').val() || 'all';
            const preset = $('#caja-bmov-filter-preset').val() || 'today';

            $tbody.html(`
                <tr>
                    <td colspan="8" style="padding: 24px; text-align: center; color: var(--caja-text-muted, #94a3b8);">
                        <span class="caja-spinner">⏳</span> Cargando movimientos...
                    </td>
                </tr>
            `);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_get_box_movements',
                    security: self.config.nonce,
                    cash_box_id: boxId,
                    type: type,
                    date_preset: preset
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        const movs = res.data.movements || [];
                        const totals = res.data.totals || {};
                        const box = res.data.box;
                        self.cachedBoxMovementsData = res.data;

                        // Actualizar totales en tarjetas
                        $('#caja-bmov-stat-inflow').text('$' + self.formatCurrencyNumber(totals.total_cash_in || 0));
                        $('#caja-bmov-stat-outflow').text('$' + self.formatCurrencyNumber(totals.total_cash_out || 0));
                        $('#caja-bmov-stat-sales').text('$' + self.formatCurrencyNumber(totals.total_sales || 0));

                        if (!movs.length) {
                            $tbody.html(`
                                <tr>
                                    <td colspan="8" style="padding: 30px; text-align: center; color: var(--caja-text-muted, #94a3b8);">
                                        No hay movimientos registrados para los filtros seleccionados.
                                    </td>
                                </tr>
                            `);
                            return;
                        }

                        let html = '';
                        movs.forEach(m => {
                            let typeBadge = '';
                            if (m.type === 'opening') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-opening">🟢 Apertura</span>';
                            } else if (m.type === 'cash_in') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-cash_in">📥 Ingreso</span>';
                            } else if (m.type === 'cash_out') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-cash_out">📤 Retiro</span>';
                            } else if (m.type === 'closing') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-closing">🔒 Cierre</span>';
                            } else if (m.type === 'adjustment') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-adjustment">⚖️ Ajuste</span>';
                            } else {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-sale">🛒 Venta</span>';
                            }

                            let cashFlowHtml = '<span style="color:var(--caja-text-muted);">-</span>';
                            if (m.cash_flow > 0) {
                                cashFlowHtml = `<span style="color:#10b981; font-weight:700;">+$${self.formatCurrencyNumber(m.cash_flow)}</span>`;
                            } else if (m.cash_flow < 0) {
                                cashFlowHtml = `<span style="color:#f87171; font-weight:700;">-$${self.formatCurrencyNumber(Math.abs(m.cash_flow))}</span>`;
                            }

                            html += `
                                <tr style="border-bottom: 1px solid var(--caja-card-border, #334155);">
                                    <td style="padding: 10px; font-weight:600; color:#38bdf8; white-space:nowrap;">
                                        ${m.date_str} <span style="color:var(--caja-text-muted); font-size:0.78rem;">${m.time_exact}</span>
                                    </td>
                                    <td style="padding: 10px;">${typeBadge}</td>
                                    <td style="padding: 10px; font-weight:600;">
                                        <div>${self.escapeHtml(m.concept)}</div>
                                        ${m.metadata && m.metadata.notes ? `<div style="font-size:0.75rem; color:var(--caja-text-muted); font-style:italic;">Nota: ${self.escapeHtml(m.metadata.notes)}</div>` : ''}
                                    </td>
                                    <td style="padding: 10px; font-size:0.82rem; color:var(--caja-text-muted); white-space:nowrap;">
                                        👤 ${self.escapeHtml(m.user_name || 'Admin')}
                                    </td>
                                    <td style="padding: 10px; font-size:0.82rem; white-space:nowrap;">
                                        ${self.escapeHtml(m.payment_method || 'efectivo')}
                                    </td>
                                    <td style="padding: 10px; text-align:right; font-weight:700; color:#fbbf24; white-space:nowrap;">
                                        $${self.formatCurrencyNumber(m.amount)}
                                    </td>
                                    <td style="padding: 10px; text-align:right; white-space:nowrap;">
                                        ${cashFlowHtml}
                                    </td>
                                    <td style="padding: 10px; text-align:right; font-weight:800; color:#10b981; white-space:nowrap;">
                                        $${self.formatCurrencyNumber(m.balance_after)}
                                    </td>
                                </tr>
                            `;
                        });
                        $tbody.html(html);
                    } else {
                        $tbody.html(`
                            <tr>
                                <td colspan="8" style="padding: 24px; text-align: center; color: #f87171;">
                                    ${res && res.data && res.data.message ? res.data.message : 'Error al cargar movimientos.'}
                                </td>
                            </tr>
                        `);
                    }
                },
                error: function() {
                    $tbody.html(`
                        <tr>
                            <td colspan="8" style="padding: 24px; text-align: center; color: #f87171;">
                                Error de conexión al cargar movimientos de la caja.
                            </td>
                        </tr>
                    `);
                }
            });
        },

        printBoxMovementsReport: function() {
            const self = this;
            const data = self.cachedBoxMovementsData;
            if (!data || !data.movements) {
                alert('No hay datos para imprimir.');
                return;
            }

            const box = data.box || {};
            const movs = data.movements;
            const totals = data.totals || {};

            const w = window.open('', '_blank', 'width=800,height=600');
            if (!w) return;

            let rowsHtml = '';
            movs.forEach(m => {
                rowsHtml += `
                    <tr>
                        <td>${m.date_str} ${m.time_exact}</td>
                        <td>${m.type}</td>
                        <td>${m.concept}</td>
                        <td>${m.user_name}</td>
                        <td style="text-align:right;">$${self.formatCurrencyNumber(m.amount)}</td>
                        <td style="text-align:right;">${m.cash_flow >= 0 ? '+' : ''}$${self.formatCurrencyNumber(m.cash_flow)}</td>
                        <td style="text-align:right;"><strong>$${self.formatCurrencyNumber(m.balance_after)}</strong></td>
                    </tr>
                `;
            });

            w.document.write(`
                <html>
                <head>
                    <title>Reporte de Movimientos - ${box.name || 'Caja'}</title>
                    <style>
                        body { font-family: monospace; padding: 20px; font-size: 13px; color: #111; }
                        h2, h4 { margin: 0 0 6px 0; }
                        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
                        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; font-size: 11px; text-align: left; }
                        th { background: #f4f4f4; }
                        .totals { margin: 14px 0; padding: 10px; background: #fafafa; border: 1px solid #eee; display: flex; gap: 20px; }
                    </style>
                </head>
                <body>
                    <h2>REPORTE DE MOVIMIENTOS DE CAJA</h2>
                    <h4>Caja: ${box.name || ''} [${box.code || ''}]</h4>
                    <p style="font-size:11px; margin:0 0 10px 0;">Fecha de emisión: ${new Date().toLocaleString()}</p>
                    <div class="totals">
                        <div><strong>Ingresos:</strong> $${self.formatCurrencyNumber(totals.total_cash_in || 0)}</div>
                        <div><strong>Retiros:</strong> $${self.formatCurrencyNumber(totals.total_cash_out || 0)}</div>
                        <div><strong>Ventas:</strong> $${self.formatCurrencyNumber(totals.total_sales || 0)}</div>
                        <div><strong>Flujo Neto:</strong> $${self.formatCurrencyNumber(totals.net_cash_flow || 0)}</div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Horario</th>
                                <th>Tipo</th>
                                <th>Concepto</th>
                                <th>Usuario</th>
                                <th style="text-align:right;">Monto</th>
                                <th style="text-align:right;">Flujo</th>
                                <th style="text-align:right;">Saldo Caja</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                    </table>
                    <script>window.onload = function() { window.print(); };<\/script>
                </body>
                </html>
            `);
            w.document.close();
        },

        // ==========================================
        // CONTROL DE CAJAS (ADMINISTRADOR Y ENCARGADO)
        // ==========================================
        loadRegistersHistory: function() {
            const self = this;
            const cashBoxId = $('#caja-reg-filter-money-box').val() || 'all';
            const sector = $('#caja-reg-filter-sector').val() || 'all';
            const userId = $('#caja-reg-filter-user').val() || 'all';
            const status = $('#caja-reg-filter-status').val() || 'all';
            const preset = $('#caja-reg-filter-preset').val() || 'today';
            const dateFrom = $('#caja-reg-filter-from').val() || '';
            const dateTo = $('#caja-reg-filter-to').val() || '';

            $('#caja-reg-table-body').html(`
                <tr>
                    <td colspan="12" style="padding: 30px; text-align: center; color: var(--caja-text-muted, #94a3b8);">
                        <span class="caja-spinner">⏳</span> Cargando registros de cajas...
                    </td>
                </tr>
            `);

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_get_registers_history',
                    security: self.config.nonce,
                    cash_box_id: cashBoxId,
                    sector: sector,
                    user_id: userId,
                    status: status,
                    date_preset: preset,
                    date_from: dateFrom,
                    date_to: dateTo
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        self.renderRegistersMetrics(res.data.metrics);
                        self.renderLiveRegisters(res.data.active_sessions);
                        self.renderRegistersTable(res.data.sessions);
                    } else {
                        $('#caja-reg-table-body').html(`
                            <tr>
                                <td colspan="12" style="padding: 30px; text-align: center; color: #f87171;">
                                    ${res && res.data && res.data.message ? res.data.message : 'Error al cargar registros.'}
                                </td>
                            </tr>
                        `);
                    }
                },
                error: function() {
                    $('#caja-reg-table-body').html(`
                        <tr>
                            <td colspan="12" style="padding: 30px; text-align: center; color: #f87171;">
                                Error de conexión al cargar registros de cajas.
                            </td>
                        </tr>
                    `);
                }
            });
        },

        renderRegistersMetrics: function(m) {
            if (!m) return;
            $('#caja-reg-stat-open').text(m.active_open_count || 0);
            $('#caja-reg-stat-cash').text('$' + this.formatCurrencyNumber(m.sum_cash_in_registers || 0));
            $('#caja-reg-stat-card').text('$' + this.formatCurrencyNumber(m.sum_card_sales || 0));
            $('#caja-reg-stat-transfer').text('$' + this.formatCurrencyNumber(m.sum_transfer_sales || 0));
            $('#caja-reg-stat-digital').text('$' + this.formatCurrencyNumber(m.sum_digital_sales || 0));
            $('#caja-reg-stat-total').text('$' + this.formatCurrencyNumber(m.sum_total_sales || 0));
        },

        renderLiveRegisters: function(activeSessions) {
            const self = this;
            const $grid = $('#caja-reg-live-grid');
            if (!activeSessions || !activeSessions.length) {
                $grid.html(`
                    <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 24px; text-align: center; background: var(--caja-card-bg, #1e293b); border-radius: 8px; border: 1px dashed var(--caja-card-border, #334155); color: var(--caja-text-muted, #94a3b8);">
                        <span>🔒 No hay cajas abiertas actualmente en ningún sector.</span>
                    </div>
                `);
                return;
            }

            let html = '';
            activeSessions.forEach(s => {
                const icon = self.getSectorIcon(s.sector);
                html += `
                    <div class="caja-reg-live-card" style="background: var(--caja-card-bg, #1e293b); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 8px; padding: 14px 16px; position:relative;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                            <div>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <span class="caja-pulse-dot" style="background:#10b981; width:8px; height:8px; border-radius:50%; box-shadow:0 0 6px #10b981;"></span>
                                    <strong style="color:var(--caja-text, #f8fafc); font-size:1rem;">${icon} ${s.sector}</strong>
                                </div>
                                <div style="font-size:0.8rem; color:var(--caja-text-muted, #94a3b8); margin-top:2px;">
                                    👤 ${s.user_name} • <span style="color:#10b981;">${s.session_code}</span>
                                </div>
                            </div>
                            <span style="font-size:0.75rem; background:rgba(16, 185, 129, 0.15); color:#10b981; padding:2px 8px; border-radius:12px; font-weight:700;">
                                Abierta ${s.opened_time_str}
                            </span>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap:8px; background:rgba(0,0,0,0.2); border-radius:6px; padding:8px 10px; margin-bottom:12px; font-size:0.82rem;">
                            <div>
                                <span style="color:var(--caja-text-muted); display:block; font-size:0.72rem;">FONDO INICIAL</span>
                                <strong style="color:var(--caja-text);">$${self.formatCurrencyNumber(s.opening_cash)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); display:block; font-size:0.72rem;">EFECTIVO EN CAJA</span>
                                <strong style="color:#10b981;">$${self.formatCurrencyNumber(s.expected_cash)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); display:block; font-size:0.72rem;">💳 TARJETA</span>
                                <strong style="color:#60a5fa;">$${self.formatCurrencyNumber(s.card_sales)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); display:block; font-size:0.72rem;">📱 TRANSFERENCIA</span>
                                <strong style="color:#c084fc;">$${self.formatCurrencyNumber(s.transfer_sales)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); display:block; font-size:0.72rem;">TOTAL VENTAS (${s.orders_count})</span>
                                <strong style="color:#fbbf24;">$${self.formatCurrencyNumber(s.total_sales)}</strong>
                            </div>
                        </div>

                        <div style="display:flex; gap:6px;">
                            <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm caja-reg-btn-view-movements" data-session-id="${s.id}" style="flex:1; justify-content:center; font-size:0.78rem; padding:6px 8px;">
                                <span>👁️</span> <span>Auditar Horarios</span>
                            </button>
                            <button type="button" class="caja-btn caja-btn-danger caja-btn-sm caja-reg-btn-force-close" data-session-id="${s.id}" style="font-size:0.78rem; padding:6px 10px;" title="Cerrar caja forzosamente">
                                <span>🔒</span>
                            </button>
                        </div>
                    </div>
                `;
            });
            $grid.html(html);
        },

        renderRegistersTable: function(sessions) {
            const self = this;
            const $tbody = $('#caja-reg-table-body');
            if (!sessions || !sessions.length) {
                $tbody.html(`
                    <tr>
                        <td colspan="12" style="padding: 30px; text-align: center; color: var(--caja-text-muted, #94a3b8);">
                            No se encontraron turnos de caja para los filtros seleccionados.
                        </td>
                    </tr>
                `);
                return;
            }

            let html = '';
            sessions.forEach(s => {
                const icon = self.getSectorIcon(s.sector);
                const isOpen = (s.status === 'open');
                
                let diffBadge = '-';
                if (!isOpen) {
                    if (s.difference === 0) {
                        diffBadge = `<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:700; background:rgba(16,185,129,0.15); color:#10b981;">Cuadrada</span>`;
                    } else if (s.difference < 0) {
                        diffBadge = `<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:700; background:rgba(239,68,68,0.15); color:#f87171;" title="Faltante">-$${self.formatCurrencyNumber(Math.abs(s.difference))}</span>`;
                    } else {
                        diffBadge = `<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:700; background:rgba(245,158,11,0.15); color:#fbbf24;" title="Sobrante">+$${self.formatCurrencyNumber(s.difference)}</span>`;
                    }
                } else {
                    diffBadge = `<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:700; background:rgba(59,130,246,0.15); color:#60a5fa;">En curso</span>`;
                }

                html += `
                    <tr style="border-bottom: 1px solid var(--caja-card-border, #334155);">
                        <td style="padding: 10px 14px; font-weight: 600; color: #10b981;">${s.session_code}</td>
                        <td style="padding: 10px 14px;">
                            <div>${icon} ${s.sector}</div>
                            ${s.cash_box_name ? `<div style="font-size:0.75rem; color:#38bdf8; font-weight:600; margin-top:2px;">💵 ${self.escapeHtml(s.cash_box_name)}</div>` : ''}
                        </td>
                        <td style="padding: 10px 14px;">${s.user_name}</td>
                        <td style="padding: 10px 14px; font-size:0.8rem; color:var(--caja-text-muted);">${s.opened_full_str || s.opened_at}</td>
                        <td style="padding: 10px 14px; font-size:0.8rem;">
                            ${isOpen ? '<span style="color:#10b981; font-weight:700;">🟢 Abierta</span>' : (s.closed_full_str || s.closed_at)}
                        </td>
                        <td style="padding: 10px 14px; text-align:right;">$${self.formatCurrencyNumber(s.opening_cash)}</td>
                        <td style="padding: 10px 14px; text-align:right; color:#10b981;">$${self.formatCurrencyNumber(s.cash_sales)}</td>
                        <td style="padding: 10px 14px; text-align:right;">
                            <div style="color:#60a5fa; font-weight:600;">$${self.formatCurrencyNumber(s.digital_sales)}</div>
                            <div style="font-size:0.72rem; color:var(--caja-text-muted); margin-top:2px;">
                                💳 $${self.formatCurrencyNumber(s.card_sales)} <span style="opacity:0.4;">|</span> 📱 $${self.formatCurrencyNumber(s.transfer_sales)}
                            </div>
                        </td>
                        <td style="padding: 10px 14px; text-align:right; font-weight:700; color:#fbbf24;">$${self.formatCurrencyNumber(s.total_sales)}</td>
                        <td style="padding: 10px 14px; text-align:right; font-weight:700; color:var(--caja-text);">
                            ${isOpen ? `<span style="color:#38bdf8;">Esperado: $${self.formatCurrencyNumber(s.expected_cash)}</span>` : `$${self.formatCurrencyNumber(s.counted_cash)}`}
                        </td>
                        <td style="padding: 10px 14px; text-align:center;">${diffBadge}</td>
                        <td style="padding: 10px 14px; text-align:center;">
                            <div style="display:flex; justify-content:center; gap:6px;">
                                <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm caja-reg-btn-view-movements" data-session-id="${s.id}" title="Ver movimientos y horarios">
                                    <span>👁️</span> <span>Movimientos</span>
                                </button>
                                ${!isOpen ? `
                                    <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm caja-reg-btn-ticket" data-session-id="${s.id}" title="Imprimir Ticket Z">
                                        <span>🖨️</span>
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });
            $tbody.html(html);
        },

        viewSessionMovements: function(sessionId) {
            const self = this;
            $('#caja-modal-session-movements').fadeIn(150);
            $('#caja-mov-summary-card').html('<div style="grid-column:1/-1; text-align:center; padding:15px; color:var(--caja-text-muted);"><span class="caja-spinner">⏳</span> Cargando auditoría...</div>');
            $('#caja-mov-table-body').html('<tr><td colspan="8" style="text-align:center; padding:20px; color:var(--caja-text-muted);"><span class="caja-spinner">⏳</span> Cargando movimientos segundo a segundo...</td></tr>');
            $('#caja-mov-breakdown-card').hide();
            $('#caja-mov-sales-bills-card').hide();

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_get_session_movements',
                    security: self.config.nonce,
                    session_id: sessionId
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        const s = res.data.session;
                        const movs = res.data.movements || [];
                        const salesBills = res.data.cash_bills_summary;
                        self.currentAuditedSession = s;

                        $('#caja-mov-title').text(`Movimientos de Caja: ${s.session_code}`);
                        $('#caja-mov-subtitle').text(`Sector: ${s.sector} • Cajero: ${s.user_name} • Apertura: ${s.opened_full_str}`);

                        // Summary card
                        $('#caja-mov-summary-card').html(`
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">ESTADO</span>
                                <strong style="color:${s.status === 'open' ? '#10b981' : '#cbd5e1'}; font-size:0.95rem;">${s.status === 'open' ? '🟢 Abierta' : '🔒 Cerrada'}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">FONDO INICIAL</span>
                                <strong style="color:var(--caja-text); font-size:0.95rem;">$${self.formatCurrencyNumber(s.opening_cash)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">VENTAS EFECTIVO</span>
                                <strong style="color:#10b981; font-size:0.95rem;">$${self.formatCurrencyNumber(s.cash_sales)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">💳 TARJETA</span>
                                <strong style="color:#60a5fa; font-size:0.95rem;">$${self.formatCurrencyNumber(s.card_sales)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">📱 TRANSFERENCIA</span>
                                <strong style="color:#c084fc; font-size:0.95rem;">$${self.formatCurrencyNumber(s.transfer_sales)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">PROPINAS</span>
                                <strong style="color:#34d399; font-size:0.95rem;">$${self.formatCurrencyNumber(s.total_tips)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">TOTAL FACTURADO</span>
                                <strong style="color:#fbbf24; font-size:0.95rem;">$${self.formatCurrencyNumber(s.total_sales)}</strong>
                            </div>
                            <div>
                                <span style="color:var(--caja-text-muted); font-size:0.72rem; display:block;">ARQUEO CONTADO</span>
                                <strong style="color:var(--caja-text); font-size:0.95rem;">$${self.formatCurrencyNumber(s.status === 'open' ? s.expected_cash : s.counted_cash)}</strong>
                            </div>
                        `);

                        // Desglose de billetes recibidos en cobros de ventas durante el turno
                        if (salesBills && salesBills.total_count > 0) {
                            const sChips = [];
                            const bDenoms = Object.keys(salesBills.breakdown || {}).map(Number).sort((a,b) => b - a);
                            for (const d of bDenoms) {
                                const q = salesBills.breakdown[d];
                                if (q > 0) {
                                    sChips.push(`
                                        <span style="background:rgba(56,189,248,0.12); color:#38bdf8; border:1px solid rgba(56,189,248,0.3); border-radius:6px; padding:4px 10px; font-size:0.82rem; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                            <strong>$${self.formatCurrencyNumber(d)}</strong> × ${q} = <span style="color:#7dd3fc;">$${self.formatCurrencyNumber(d * q)}</span>
                                        </span>
                                    `);
                                }
                            }
                            if (sChips.length > 0) {
                                $('#caja-mov-sales-bills-chips').html(sChips.join(''));
                                $('#caja-mov-sales-bills-summary-badge').text(`${salesBills.total_count} billetes · Total $${self.formatCurrencyNumber(salesBills.total_amount)}`);
                                $('#caja-mov-sales-bills-card').show();
                            } else {
                                $('#caja-mov-sales-bills-card').hide();
                            }
                        } else {
                            $('#caja-mov-sales-bills-card').hide();
                        }

                        // Breakdown de arqueo físico al cierre
                        if (s.cash_breakdown && typeof s.cash_breakdown === 'object') {
                            const chips = [];
                            for (const [denom, qty] of Object.entries(s.cash_breakdown)) {
                                if (qty > 0) {
                                    chips.push(`<span style="background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); border-radius:4px; padding:3px 8px; font-size:0.8rem; font-weight:600;">$${self.formatCurrencyNumber(denom)} x ${qty} = $${self.formatCurrencyNumber(denom * qty)}</span>`);
                                }
                            }
                            if (chips.length > 0) {
                                $('#caja-mov-breakdown-chips').html(chips.join(''));
                                $('#caja-mov-breakdown-card').show();
                            }
                        }

                        // Movements table
                        $('#caja-mov-count-badge').text(movs.length);
                        if (!movs.length) {
                            $('#caja-mov-table-body').html('<tr><td colspan="8" style="text-align:center; padding:20px; color:var(--caja-text-muted);">No se registraron ventas durante este turno.</td></tr>');
                            return;
                        }

                        let movHtml = '';
                        movs.forEach(m => {
                            let itemsList = '';
                            if (m.items && m.items.length) {
                                itemsList = m.items.map(it => `<div style="font-size:0.8rem;">• ${it.quantity}x ${it.name}</div>`).join('');
                            } else if (m.concept) {
                                itemsList = `<div style="font-size:0.8rem; font-weight:600;">${self.escapeHtml(m.concept)}</div>`;
                            }

                            // Detalle de billetes usados en este cobro específico
                            let billsDetailHtml = '';
                            if (m.cash_breakdown && typeof m.cash_breakdown === 'object') {
                                const bParts = [];
                                const itemDenoms = Object.keys(m.cash_breakdown).map(Number).sort((a,b) => b - a);
                                for (const idenom of itemDenoms) {
                                    const iqty = m.cash_breakdown[idenom];
                                    if (iqty > 0) {
                                        bParts.push(`${iqty}x $${self.formatCurrencyNumber(idenom)}`);
                                    }
                                }
                                if (bParts.length > 0) {
                                    billsDetailHtml = `<div style="margin-top:4px; font-size:0.75rem; color:#34d399; font-weight:600; background:rgba(16,185,129,0.1); padding:2px 6px; border-radius:4px; display:inline-block;">💵 Billetes: ${bParts.join(', ')}</div>`;
                                }
                            }

                            let typeBadge = '';
                            if (m.type === 'opening') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-opening">🟢 Apertura</span>';
                            } else if (m.type === 'cash_in') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-cash_in">📥 Ingreso</span>';
                            } else if (m.type === 'cash_out') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-cash_out">📤 Retiro</span>';
                            } else if (m.type === 'closing') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-closing">🔒 Cierre</span>';
                            } else if (m.type === 'adjustment') {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-adjustment">⚖️ Ajuste</span>';
                            } else {
                                typeBadge = '<span class="caja-mov-badge caja-mov-badge-sale">🛒 Venta</span>';
                            }

                            const isCash = m.is_cash;
                            const pmBadge = isCash 
                                ? '<span style="background:rgba(16,185,129,0.15); color:#10b981; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:700;">💵 Efectivo</span>'
                                : '<span style="background:rgba(59,130,246,0.15); color:#60a5fa; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:700;">💳 Digital</span>';

                            const cashAmount = (m.balance_after !== undefined) ? m.balance_after : (m.running_cash || 0);

                            movHtml += `
                                <tr style="border-bottom: 1px solid var(--caja-card-border, #334155);">
                                    <td style="padding:8px 10px; font-weight:700; color:#38bdf8; white-space:nowrap;">⏰ ${m.time_exact}</td>
                                    <td style="padding:8px 10px; font-weight:600; white-space:nowrap;">
                                        ${m.order_number ? `#${m.order_number}` : typeBadge}
                                    </td>
                                    <td style="padding:8px 10px; font-size:0.78rem; color:var(--caja-text-muted);">${m.sector || ''} ${m.table ? '('+m.table+')' : ''}</td>
                                    <td style="padding:8px 10px;">${itemsList} ${billsDetailHtml}</td>
                                    <td style="padding:8px 10px; white-space:nowrap;">${pmBadge}</td>
                                    <td style="padding:8px 10px; text-align:right; font-weight:700; color:#fbbf24;">$${self.formatCurrencyNumber(m.total || m.amount || 0)}</td>
                                    <td style="padding:8px 10px; text-align:right; color:#34d399;">${m.tip > 0 ? '$'+self.formatCurrencyNumber(m.tip) : '-'}</td>
                                    <td style="padding:8px 10px; text-align:right; font-weight:700; color:#10b981;">$${self.formatCurrencyNumber(cashAmount)}</td>
                                </tr>
                            `;
                        });
                        $('#caja-mov-table-body').html(movHtml);
                    } else {
                        $('#caja-mov-summary-card').html('<div style="grid-column:1/-1; color:#f87171; padding:10px;">Error al cargar datos.</div>');
                    }
                },
                error: function() {
                    $('#caja-mov-summary-card').html('<div style="grid-column:1/-1; color:#f87171; padding:10px;">Error de conexión.</div>');
                }
            });
        },

        forceCloseRegisterByAdmin: function(sessionId) {
            const self = this;
            const input = window.prompt('¿Estás seguro de forzar el cierre de esta caja?\n\nIngresa el dinero físico contado en la caja:');
            if (input === null) return;

            const counted = parseFloat(input) || 0;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_close_register',
                    security: self.config.nonce,
                    session_id: sessionId,
                    counted_cash: counted,
                    closing_notes: 'Cierre forzado desde panel de administrador.'
                },
                success: function(res) {
                    if (res && res.success) {
                        self.showToast('Caja cerrada con éxito.', 'success');
                        self.loadRegistersHistory();
                        if (self.activeRegisterSession && self.activeRegisterSession.id == sessionId) {
                            self.activeRegisterSession = null;
                            self.updateRegisterBarUI(null);
                        }
                    } else {
                        self.showToast((res && res.data && res.data.message) ? res.data.message : 'Error al forzar cierre.', 'error');
                    }
                },
                error: function() {
                    self.showToast('Error de conexión.', 'error');
                }
            });
        },

        printSessionZTicket: function(sessionId) {
            const self = this;
            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'emp_caja_get_session_movements',
                    security: self.config.nonce,
                    session_id: sessionId
                },
                success: function(res) {
                    if (res && res.success && res.data) {
                        const s = res.data.session;
                        const movs = res.data.movements || [];

                        const venueName = self.config.gastroVenueName || 'Empralidad POS';
                        const w = window.open('', '_blank', 'width=380,height=600');
                        if (!w) {
                            alert('Habilita las ventanas emergentes para imprimir el comprobante.');
                            return;
                        }

                        let movLines = '';
                        movs.forEach(m => {
                            let bSub = '';
                            if (m.cash_breakdown && typeof m.cash_breakdown === 'object') {
                                const bp = [];
                                const bdList = Object.keys(m.cash_breakdown).map(Number).sort((a,b) => b - a);
                                for (const bd of bdList) {
                                    if (m.cash_breakdown[bd] > 0) bp.push(`${m.cash_breakdown[bd]}x$${self.formatCurrencyNumber(bd)}`);
                                }
                                if (bp.length > 0) bSub = `<div style="font-size:9px; color:#555;">${bp.join(', ')}</div>`;
                            }
                            movLines += `
                                <tr>
                                    <td>${m.time_exact}</td>
                                    <td>#${m.order_number}${bSub}</td>
                                    <td>${m.is_cash ? 'EFECT' : 'DIGIT'}</td>
                                    <td style="text-align:right;">$${self.formatCurrencyNumber(m.total)}</td>
                                </tr>
                            `;
                        });

                        let billsTicketHtml = '';
                        if (res.data.cash_bills_summary && res.data.cash_bills_summary.total_count > 0) {
                            const bs = res.data.cash_bills_summary;
                            const bLines = [];
                            const bDenoms = Object.keys(bs.breakdown || {}).map(Number).sort((a,b) => b - a);
                            for (const bd of bDenoms) {
                                const bq = bs.breakdown[bd];
                                if (bq > 0) {
                                    bLines.push(`<div class="row"><span>$${self.formatCurrencyNumber(bd)} x ${bq}:</span><span>$${self.formatCurrencyNumber(bd * bq)}</span></div>`);
                                }
                            }
                            if (bLines.length > 0) {
                                billsTicketHtml = `
                                    <div class="divider"></div>
                                    <div style="font-size:11px; text-align:center; margin-bottom:4px; font-weight:bold;">BILLETES RECIBIDOS EN COBROS</div>
                                    ${bLines.join('')}
                                    <div class="row" style="font-weight:bold; margin-top:2px;"><span>Total billetes (${bs.total_count}):</span><span>$${self.formatCurrencyNumber(bs.total_amount)}</span></div>
                                `;
                            }
                        }

                        w.document.write(`
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <meta charset="utf-8">
                                <title>Ticket Z - ${s.session_code}</title>
                                <style>
                                    body { font-family: monospace; font-size: 12px; margin: 0; padding: 10px; color: #000; }
                                    .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 8px; }
                                    .row { display: flex; justify-content: space-between; margin-bottom: 4px; }
                                    .divider { border-top: 1px dashed #000; margin: 8px 0; }
                                    table { width: 100%; font-size: 11px; border-collapse: collapse; }
                                    th { text-align: left; border-bottom: 1px solid #000; padding: 2px 0; }
                                    td { padding: 2px 0; }
                                    @media print { body { width: 58mm; padding: 0; } }
                                </style>
                            </head>
                            <body>
                                <div class="header">
                                    <h3 style="margin:0 0 4px 0;">${venueName}</h3>
                                    <div style="font-weight:bold;">CIERRE DE CAJA / TICKET Z</div>
                                    <div>${s.session_code}</div>
                                </div>
                                <div class="row"><span>Barra / Sector:</span><strong>${s.sector}</strong></div>
                                <div class="row"><span>Cajero:</span><strong>${s.user_name}</strong></div>
                                <div class="row"><span>Apertura:</span><span>${s.opened_full_str}</span></div>
                                <div class="row"><span>Cierre:</span><span>${s.closed_full_str || 'En curso'}</span></div>
                                <div class="divider"></div>
                                <div class="row"><span>Fondo Inicial:</span><strong>$${self.formatCurrencyNumber(s.opening_cash)}</strong></div>
                                <div class="row"><span>Ventas Efectivo:</span><strong>$${self.formatCurrencyNumber(s.cash_sales)}</strong></div>
                                <div class="row"><span>Ventas Tarjeta:</span><strong>$${self.formatCurrencyNumber(s.card_sales)}</strong></div>
                                <div class="row"><span>Ventas Transferencia:</span><strong>$${self.formatCurrencyNumber(s.transfer_sales)}</strong></div>
                                <div class="row"><span>Total Digitales:</span><span>$${self.formatCurrencyNumber(s.digital_sales)}</span></div>
                                <div class="row"><span>Propinas:</span><strong>$${self.formatCurrencyNumber(s.total_tips)}</strong></div>
                                <div class="divider"></div>
                                <div class="row" style="font-size:14px;"><span>TOTAL FACTURADO:</span><strong>$${self.formatCurrencyNumber(s.total_sales)}</strong></div>
                                <div class="row"><span>Total Pedidos:</span><span>${s.orders_count}</span></div>
                                <div class="divider"></div>
                                <div class="row"><span>Efectivo Esperado:</span><strong>$${self.formatCurrencyNumber(s.expected_cash)}</strong></div>
                                <div class="row"><span>Efectivo Contado:</span><strong>$${self.formatCurrencyNumber(s.counted_cash)}</strong></div>
                                <div class="row" style="font-size:13px; font-weight:bold;">
                                    <span>DIFERENCIA:</span>
                                    <span>${s.difference === 0 ? '$0,00 (EXACTA)' : (s.difference < 0 ? '-$'+self.formatCurrencyNumber(Math.abs(s.difference))+' (FALTANTE)' : '+$'+self.formatCurrencyNumber(s.difference)+' (SOBRANTE)')}</span>
                                </div>
                                ${s.closing_notes ? `<div class="divider"></div><div><strong>Notas:</strong> ${s.closing_notes}</div>` : ''}
                                ${billsTicketHtml}
                                <div class="divider"></div>
                                <div style="font-size:11px; text-align:center; margin-bottom:4px; font-weight:bold;">DETALLE DE VENTAS (${movs.length})</div>
                                <table>
                                    <thead><tr><th>HORA</th><th>PEDIDO</th><th>PAGO</th><th style="text-align:right;">TOTAL</th></tr></thead>
                                    <tbody>${movLines}</tbody>
                                </table>
                                <div class="divider"></div>
                                <div style="text-align:center; font-size:10px; margin-top:8px;">
                                    Comprobante interno no válido como factura.<br>
                                    ${new Date().toLocaleString('es-AR')}
                                </div>
                                <script>window.onload = function() { window.print(); };<\/script>
                            </body>
                            </html>
                        `);
                        w.document.close();
                    }
                }
            });
        }
    };

    window.BatllieCajaApp = App;

    $(document).ready(function() {
        App.init();
    });

})(jQuery);
