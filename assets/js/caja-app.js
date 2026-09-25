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
                const oLen = (o.timeline && Array.isArray(o.timeline)) ? o.timeline.length : 0;
                const nLen = (n.timeline && Array.isArray(n.timeline)) ? n.timeline.length : 0;
                if (oLen !== nLen) return true;
            }
            return false;
        },
        init: function() {
            this.bindEvents();

            if (this.config.isUserLoggedIn) {
                this.initDashboard();

                // Soportar navegación directa por URL / Hash (#productos o ?tab=productos)
                const urlParams = new URLSearchParams(window.location.search);
                const hash = window.location.hash.toLowerCase();
                if (hash === '#productos' || hash === '#tab-products' || urlParams.get('tab') === 'productos' || urlParams.get('tab') === 'products') {
                    this.switchTab('tab-products');
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
            $('#caja-login-form').on('submit', function(e) {
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

            // Filtro Desplegable de Tiempo
            $(document).on('change', '#caja-filter-time', function() {
                self.currentTimeFilter = $(this).val();
                self.applyFilters();
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

            // Cambio de estado de pago personalizado
            $(document).on('change', '.caja-payment-select', function() {
                const orderId = $(this).data('order-id');
                const val = $(this).val();
                self.updateCustomStatus(orderId, 'payment_status', val, $(this));
            });

            // Cambio de estado de envío personalizado
            $(document).on('change', '.caja-shipping-select', function() {
                const orderId = $(this).data('order-id');
                const val = $(this).val();
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
                select.removeClass('status-bg-pending status-bg-processing status-bg-enviando status-bg-completed status-bg-recibido-problema status-bg-cancelled status-bg-refunded status-bg-on-hold status-bg-failed');
                select.addClass('status-bg-' + newStatus);
                self.updateOrderStatus(orderId, newStatus, select);
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
                $('#caja-stock-qty-group').hide();
                $('#caja-new-product-error').hide();
                $('#caja-modal-new-product').fadeIn(200);
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

            // Soporte para botón atrás/adelante en el historial del navegador
            $(window).on('hashchange', function() {
                const h = window.location.hash.toLowerCase();
                if (h === '#productos' || h === '#tab-products') {
                    if (self.activeTab !== 'tab-products') self.switchTab('tab-products');
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
                    username: $('#caja-username').val(),
                    password: $('#caja-password').val()
                },
                success: function(res) {
                    if (res.success) {
                        $btn.find('.caja-btn-text').text('Acceso concedido...');
                        window.location.reload();
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
            if (tabId === 'tab-products') {
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
                if (tabId === 'tab-products') {
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
                const newHash = tabId === 'tab-products' ? '#productos' : '#pedidos';
                window.history.replaceState(null, null, newHash);
            }

            if (tabId === 'tab-products' && !this.isProductsLoaded) {
                this.loadProducts();
                this.loadCategories();
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

            orders.forEach(order => {
                let itemsHtml = '';
                if (order.items && order.items.length) {
                    order.items.forEach(item => {
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
                if (order.timestamp) {
                    try {
                        const d = new Date(order.timestamp * 1000);
                        if (!orderDateDisplay) {
                            const days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                            const dayName = days[d.getDay()];
                            const dayNum = String(d.getDate()).padStart(2, '0');
                            const monthNum = String(d.getMonth() + 1).padStart(2, '0');
                            const yearNum = d.getFullYear();
                            orderDateDisplay = `${dayName} ${dayNum}/${monthNum}/${yearNum}`;
                        }
                        if (!orderDateKey) {
                            const y = d.getFullYear();
                            const m = String(d.getMonth() + 1).padStart(2, '0');
                            const day = String(d.getDate()).padStart(2, '0');
                            orderDateKey = `${y}-${m}-${day}`;
                        }
                    } catch(e) {}
                }
                if (!orderDateDisplay && order.time_formatted) {
                    orderDateDisplay = order.time_formatted.split(' ')[0];
                }

                // Funciones auxiliares para fechas de eventos individuales
                const getEventDateFormatted = (ev) => {
                    if (ev.date_formatted) return ev.date_formatted;
                    if (ev.timestamp) {
                        try {
                            const d = new Date(ev.timestamp * 1000);
                            const days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                            const dayName = days[d.getDay()];
                            const dayNum = String(d.getDate()).padStart(2, '0');
                            const monthNum = String(d.getMonth() + 1).padStart(2, '0');
                            const yearNum = d.getFullYear();
                            return `${dayName} ${dayNum}/${monthNum}/${yearNum}`;
                        } catch(e) {}
                    }
                    return ev.date || '';
                };

                const getEventDateKey = (ev) => {
                    if (ev.date_key) return ev.date_key;
                    if (ev.timestamp) {
                        try {
                            const d = new Date(ev.timestamp * 1000);
                            const y = d.getFullYear();
                            const m = String(d.getMonth() + 1).padStart(2, '0');
                            const day = String(d.getDate()).padStart(2, '0');
                            return `${y}-${m}-${day}`;
                        } catch(e) {}
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

                html += `
                    <div class="caja-order-card" id="caja-order-card-${order.id}">
                        <div class="caja-card-header">
                            <div class="caja-order-num-wrap">
                                <span class="caja-order-number">#${order.number}</span>
                                <span class="caja-order-time">${order.time_diff || order.time_formatted}</span>
                            </div>
                            <span class="caja-status-pill ${order.status_badge}">${order.status_name}</span>
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
                            ${itemsHtml}
                        </div>

                        <!-- Medio de Pago y Estado del Envío -->
                        <div class="caja-order-meta-grid">
                            <div class="caja-meta-box">
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

                            <div class="caja-meta-box">
                                <div class="caja-meta-header">
                                    <span class="caja-meta-icon">🛵</span>
                                    <span class="caja-meta-title">Envío:</span>
                                </div>
                                <div class="caja-meta-select-wrap">
                                    <select class="caja-status-select caja-shipping-select status-ship-${order.shipping_status}" data-order-id="${order.id}">
                                        <option value="no_gestionado" ${order.shipping_status === 'no_gestionado' ? 'selected' : ''}>📦 No gestionado</option>
                                        <option value="esperando_repartidor" ${order.shipping_status === 'esperando_repartidor' ? 'selected' : ''}>⏳ Esperando al repartidor</option>
                                        <option value="enviando" ${order.shipping_status === 'enviando' ? 'selected' : ''}>🚀 Repartidor enviando</option>
                                        <option value="demorado" ${order.shipping_status === 'demorado' ? 'selected' : ''}>⚠️ Repartidor con demora</option>
                                        <option value="entregado" ${order.shipping_status === 'entregado' ? 'selected' : ''}>🏁 Recibido sin problemas</option>
                                        <option value="entregado_problemas" ${order.shipping_status === 'entregado_problemas' ? 'selected' : ''}>🛑 Recibido con problemas</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="caja-card-footer">
                            <div class="caja-order-total-block">
                                <span class="caja-total-label">Total a cobrar:</span>
                                <span class="caja-total-val">${order.total}</span>
                            </div>

                            <div class="caja-order-status-select-wrap">
                                <div class="caja-dropdown-relative">
                                    <select class="caja-main-status-dropdown status-bg-${order.status}" data-order-id="${order.id}">
                                        <option value="pending" ${order.status === 'pending' ? 'selected' : ''}>Pendiente</option>
                                        <option value="processing" ${order.status === 'processing' ? 'selected' : ''}>En preparación</option>
                                        <option value="enviando" ${order.status === 'enviando' || order.status === 'on-hold' ? 'selected' : ''}>Enviando</option>
                                        <option value="completed" ${order.status === 'completed' ? 'selected' : ''}>Recibido</option>
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

        updateOrderStatus: function(orderId, newStatus, $btn) {
            const self = this;
            const $card = $(`#caja-order-card-${orderId}`);

            $card.addClass('caja-card-updating');

            // Si el estado principal pasa a "Recibido" (completed), actualizar automáticamente el select del envío en el DOM
            if (newStatus === 'completed') {
                const $shipSelect = $card.find('.caja-shipping-select');
                $shipSelect.val('entregado');
            } else if (newStatus === 'recibido-problema') {
                const $shipSelect = $card.find('.caja-shipping-select');
                $shipSelect.val('entregado_problemas');
            }

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'emp_caja_update_status',
                    security: self.config.nonce,
                    order_id: orderId,
                    new_status: newStatus
                },
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

        loadCategories: function() {
            const self = this;
            if (self.categoriesLoaded) return;

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
                        const $filter = $('#caja-filter-product-cat');
                        const $select = $('#new-prod-category');

                        res.data.categories.forEach(cat => {
                            $filter.append(`<option value="${cat.id}" data-slug="${cat.slug}">${cat.name}</option>`);
                            $select.append(`<option value="${cat.id}">${cat.name}</option>`);
                        });
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
                html += `
                    <tr id="caja-prod-row-${p.id}">
                        <td>
                            <img src="${p.image_url}" alt="${p.name}" class="caja-prod-thumb" />
                        </td>
                        <td>
                            <strong class="caja-prod-title">${p.name}</strong>
                            ${p.description ? `<div class="caja-prod-desc">${p.description}</div>` : ''}
                        </td>
                        <td><span class="caja-sku-badge">${p.sku}</span></td>
                        <td><span class="caja-cat-badge">${p.categories || 'Sin categoría'}</span></td>
                        <td>
                            <strong class="caja-prod-price">${p.price}</strong>
                            ${p.is_on_sale ? `<span class="caja-badge-sale">Oferta</span>` : ''}
                        </td>
                        <td>
                            <span class="caja-badge ${p.stock_badge}">${p.stock_label}</span>
                            <small class="caja-stock-num">(${p.stock_quantity})</small>
                        </td>
                        <td style="text-align: center; white-space: nowrap;">
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

        openEditProductModal: function(productId) {
            const self = this;
            const p = self.cachedProducts.find(item => item.id === productId);
            if (!p) return;

            $('#edit-prod-id').val(p.id);
            $('#edit-prod-name').val(p.name);
            $('#edit-prod-price').val(p.regular_price || p.price_raw || '');
            $('#edit-prod-sale-price').val(p.sale_price || '');
            $('#edit-prod-sku').val(p.raw_sku || (p.sku !== 'S/N' ? p.sku : ''));
            $('#edit-prod-desc').val(p.raw_description || '');

            const catId = (p.category_ids && p.category_ids.length > 0) ? p.category_ids[0] : 0;
            $('#edit-prod-category').val(catId);

            if (p.manage_stock) {
                $('#edit-prod-manage-stock').prop('checked', true);
                $('#caja-edit-stock-qty-group').show();
                $('#edit-prod-stock-qty').val(p.stock_quantity_raw !== null ? p.stock_quantity_raw : 0);
            } else {
                $('#edit-prod-manage-stock').prop('checked', false);
                $('#caja-edit-stock-qty-group').hide();
                $('#edit-prod-stock-qty').val(0);
            }

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

            const productData = {
                name: $('#edit-prod-name').val(),
                regular_price: $('#edit-prod-price').val(),
                sale_price: $('#edit-prod-sale-price').val(),
                category_id: $('#edit-prod-category').val(),
                sku: $('#edit-prod-sku').val(),
                manage_stock: $('#edit-prod-manage-stock').is(':checked') ? 'yes' : 'no',
                stock_quantity: $('#edit-prod-stock-qty').val(),
                description: $('#edit-prod-desc').val()
            };

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
                        self.filterProductsInDom();
                        $('#caja-modal-edit-product').fadeOut(150);
                        self.showToast(`✅ Producto "${updated.name}" modificado con éxito`);
                    } else {
                        $err.text(res.data && res.data.message ? res.data.message : 'Error al modificar el producto.').fadeIn(150);
                    }
                },
                error: function() {
                    $err.text('Error de comunicación con el servidor.').fadeIn(150);
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

            const productData = {
                name: $('#new-prod-name').val(),
                regular_price: $('#new-prod-price').val(),
                sale_price: $('#new-prod-sale-price').val(),
                sku: $('#new-prod-sku').val(),
                category_id: $('#new-prod-category').val(),
                manage_stock: $('#new-prod-manage-stock').is(':checked') ? 'yes' : 'no',
                stock_quantity: $('#new-prod-stock-qty').val(),
                description: $('#new-prod-desc').val()
            };

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
                        self.cachedProducts.unshift(res.data.product);
                        self.renderProducts(self.cachedProducts);
                        $('#caja-modal-new-product').fadeOut(150);
                        $form[0].reset();
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
        }
    };

    window.BatllieCajaApp = App;

    $(document).ready(function() {
        App.init();
    });

})(jQuery);
