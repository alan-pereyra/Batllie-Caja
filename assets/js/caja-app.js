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
        cachedProducts: [],

        init: function() {
            this.bindEvents();

            if (this.config.isUserLoggedIn) {
                this.initDashboard();
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

            // Búsqueda y Filtro de Productos
            $('#caja-search-products').on('input', function() {
                self.filterProductsInDom();
            });

            $('#caja-filter-product-cat').on('change', function() {
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
        },

        initDashboard: function() {
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

        switchTab: function(tabId) {
            $('.caja-tab-btn').removeClass('active');
            $(`.caja-tab-btn[data-tab="${tabId}"]`).addClass('active');

            $('.caja-tab-panel').hide();
            $(`#${tabId}`).fadeIn(150);
            this.activeTab = tabId;

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
                                // Actualizar si hubo cambios de estado
                                self.cachedOrders = res.data.orders;
                                self.applyFilters();
                            }
                        }
                    }
                });
            }, interval);
        },

        renderOrders: function(orders) {
            const $grid = $('#caja-orders-grid');
            const $empty = $('#caja-orders-empty');

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
                        <button type="button" class="caja-btn-show-all" style="margin-top:14px; padding:10px 20px; background:var(--caja-primary, #10b981); color:#fff; border-radius:8px; border:none; font-weight:700; cursor:pointer; font-size:0.95rem;">
                            Ver todos los pedidos anteriores
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
                    phoneHtml = `
                        <div class="caja-phone-row">
                            <a href="https://wa.me/${order.phone_clean}" target="_blank" class="caja-customer-phone" title="Contactar por WhatsApp">
                                <span class="caja-phone-icon">📱</span>
                                <span class="caja-phone-text">${order.phone}</span>
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
                                    <label class="caja-meta-label">Cobro:</label>
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
                        <!-- Aclaración / Nota de compra -->
                        <div class="caja-order-note-block">
                            <div class="caja-note-label-row">
                                <span class="caja-note-icon">📝</span>
                                <span class="caja-note-title">Aclaración / Nota del Cliente</span>
                            </div>
                            <div class="caja-note-field-wrap">
                                <textarea class="caja-order-note-textarea" data-order-id="${order.id}" placeholder="Escribir una aclaración sobre el pedido..." rows="2">${order.customer_note || ''}</textarea>
                                <div class="caja-note-actions-row">
                                    <span class="caja-note-saved-msg" id="caja-note-saved-${order.id}" style="display:none;"></span>
                                    <button type="button" class="caja-btn-save-note" data-order-id="${order.id}">
                                        <span class="caja-note-btn-text">Guardar nota</span>
                                    </button>
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
                    </div>
                `;
            });

            $grid.html(html);
        },

        applyFilters: function() {
            const self = this;
            const nowSec = Math.floor(Date.now() / 1000);

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
                // "Si selecciona nuevos va desde los nuevos para adelante, si toca 30 minutos los de 30 minutos y más antigüos, 1 día del día anterior y anteriores"
                const ageSec = Math.max(0, nowSec - ord.timestamp);
                const ageMin = ageSec / 60;
                const ageHours = ageSec / 3600;
                const ageDays = ageSec / 86400;

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
                        // 1 día (del día anterior y anteriores)
                        if (ageDays < 1) return false;
                        break;
                    case '2_days':
                        // 2 días y anteriores
                        if (ageDays < 2) return false;
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
                            found.customer_note = noteText;
                        }
                        self.showToast('Aclaración de compra guardada con éxito');
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
                            $filter.append(`<option value="${cat.slug}">${cat.name}</option>`);
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
                $empty.show();
                return;
            }

            $empty.hide();
            $tableWrap.show();

            let html = '';
            products.forEach(p => {
                html += `
                    <tr>
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
                    </tr>
                `;
            });

            $tbody.html(html);
        },

        filterProductsInDom: function() {
            const search = $('#caja-search-products').val().toLowerCase().trim();
            const cat = $('#caja-filter-product-cat').val().toLowerCase();

            const filtered = this.cachedProducts.filter(p => {
                const matchesSearch = !search || 
                    p.name.toLowerCase().includes(search) || 
                    p.sku.toLowerCase().includes(search);
                const matchesCat = !cat || 
                    (p.categories && p.categories.toLowerCase().includes(cat));
                return matchesSearch && matchesCat;
            });

            this.renderProducts(filtered);
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

    $(document).ready(function() {
        App.init();
    });

})(jQuery);
