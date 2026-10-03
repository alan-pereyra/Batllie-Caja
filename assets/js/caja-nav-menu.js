/**
 * Batllie Caja & Pedidos POS - Navegación y Hub de Pedidos (Opción 1)
 * Gestiona el almacenamiento multi-pedido, la barra de cambio rápido en seguimiento
 * y el Hub Modal "Mis Pedidos".
 */
(function ($) {
    'use strict';

    var STORAGE_KEY = 'batllie_recent_orders';
    var LEGACY_KEY  = 'batllie_recent_order_url';
    var navParams   = window.emp_caja_nav_params || {};
    var ajaxUrl     = navParams.ajax_url || '/wp-admin/admin-ajax.php';
    var i18n        = navParams.i18n || {};

    /**
     * Obtener lista de pedidos guardados en localStorage
     */
    function getLocalOrders() {
        var orders = [];
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                var parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    orders = parsed;
                }
            }
        } catch (e) {
            orders = [];
        }

        // Sincronizar con pedidos pasados por PHP si los hay
        if (navParams.recent_orders && Array.isArray(navParams.recent_orders)) {
            var map = {};
            orders.forEach(function (o) {
                if (o && o.id) map[o.id] = o;
            });
            navParams.recent_orders.forEach(function (serverOrder) {
                if (serverOrder && serverOrder.id) {
                    map[serverOrder.id] = $.extend({}, map[serverOrder.id] || {}, serverOrder);
                }
            });
            orders = Object.values(map);
            // Normalizar etiquetas y pasos para estados cancelados o reembolsados
            orders.forEach(function(o) {
                if (o && o.status === 'cancelled') {
                    o.step = 0;
                    o.step_label = 'Pedido cancelado';
                } else if (o && o.status === 'refunded') {
                    o.step = 0;
                    o.step_label = 'Pedido reembolsado';
                } else if (o && o.status === 'failed') {
                    o.step = 0;
                    o.step_label = 'Pedido fallido';
                }
            });

            // Ordenar por ID descendente
            orders.sort(function (a, b) {
                return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0);
            });
        }

        return orders;
    }

    /**
     * Guardar o actualizar un pedido en localStorage y cookies
     */
    function saveLocalOrder(orderObj) {
        if (!orderObj || !orderObj.id) return;

        var orders = getLocalOrders();
        var exists = false;

        for (var i = 0; i < orders.length; i++) {
            if (String(orders[i].id) === String(orderObj.id)) {
                orders[i] = $.extend({}, orders[i], orderObj);
                exists = true;
                break;
            }
        }

        if (!exists) {
            orders.unshift(orderObj);
        }

        // Limitar a los 10 más recientes
        if (orders.length > 10) {
            orders = orders.slice(0, 10);
        }

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(orders));
            if (orderObj.url) {
                localStorage.setItem(LEGACY_KEY, orderObj.url);
            }

            var now = new Date();
            now.setTime(now.getTime() + (60 * 24 * 60 * 60 * 1000));
            var cookieDomain = '; path=/; SameSite=Lax';

            // Cookie multi-pedido compacta para PHP
            var cookieList = orders.map(function (o) {
                return { id: o.id, num: o.number || o.id, key: o.key || '', url: o.url || '' };
            });
            document.cookie = 'batllie_recent_orders=' + encodeURIComponent(JSON.stringify(cookieList)) + '; expires=' + now.toUTCString() + cookieDomain;

            if (orderObj.url) {
                document.cookie = 'batllie_recent_order_url=' + encodeURIComponent(orderObj.url) + '; expires=' + now.toUTCString() + cookieDomain;
            }
            if (orderObj.id) {
                document.cookie = 'batllie_recent_order_id=' + encodeURIComponent(orderObj.id) + '; expires=' + now.toUTCString() + cookieDomain;
            }
            if (orderObj.key) {
                document.cookie = 'batllie_recent_order_key=' + encodeURIComponent(orderObj.key) + '; expires=' + now.toUTCString() + cookieDomain;
            }
        } catch (e) {}

        return orders;
    }

    /**
     * Objeto global para controlar el Hub Modal "Mis Pedidos"
     */
    window.BatlliePedidosHub = {
        $modal: null,

        init: function () {
            this.$modal = $('#batllie-pedidos-modal');
            this.bindEvents();
            this.checkCurrentPageOrder();
            this.syncSwitcherBar();

            // Auto-abrir si viene de redirección inteligente (?batllie_open_pedidos=1)
            if (navParams.auto_open || window.location.search.indexOf('batllie_open_pedidos=1') !== -1 || window.location.search.indexOf('batllie_hub=1') !== -1) {
                this.open();
                // Limpiar parámetro de la URL
                if (window.history && window.history.replaceState) {
                    var cleanUrl = window.location.href
                        .replace(/[?&]batllie_open_pedidos=1/, '')
                        .replace(/[?&]batllie_hub=1/, '');
                    window.history.replaceState({}, document.title, cleanUrl);
                }
            }
        },

        bindEvents: function () {
            var self = this;

            // 1. Clic en "Mis pedidos" del menú o botones con data-batllie-mis-pedidos
            $(document).on('click', '#menu-item-mis-pedidos a, [data-batllie-mis-pedidos]', function (e) {
                var orders = getLocalOrders();

                if (orders.length > 1) {
                    e.preventDefault();
                    self.open();
                } else if (orders.length === 1) {
                    // Si tiene un único pedido, ir directo a su seguimiento
                    if (orders[0].url) {
                        e.preventDefault();
                        window.location.href = orders[0].url;
                    }
                } else {
                    // Si no tiene ningún pedido guardado, abrir el modal con el buscador
                    e.preventDefault();
                    self.open();
                }
            });

            // 2. Botón "Ver todos" desde la barra de seguimiento
            $(document).on('click', '.batllie-open-hub-trigger, #batllie-open-hub-btn', function (e) {
                e.preventDefault();
                self.open();
            });

            // 3. Cerrar modal al hacer clic en cruz o backdrop
            $(document).on('click', '.batllie-pedidos-close-btn, .batllie-pedidos-backdrop', function () {
                self.close();
            });

            // 4. Cerrar con tecla Escape
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && self.$modal && self.$modal.hasClass('is-open')) {
                    self.close();
                }
            });

            // 5. Desplegar formulario de búsqueda por número
            $(document).on('click', '#batllie-toggle-lookup-form', function () {
                var $form = $('#batllie-lookup-order-form');
                $form.slideToggle(200, function () {
                    if ($form.is(':visible')) {
                        $('#batllie-lookup-number-input').focus();
                    }
                });
            });

            // 6. Envío del formulario para buscar y agregar pedido
            $(document).on('submit', '#batllie-lookup-order-form', function (e) {
                e.preventDefault();
                var $form = $(this);
                var $input = $('#batllie-lookup-number-input');
                var orderNum = $.trim($input.val());
                var $btn = $form.find('.batllie-lookup-submit-btn');
                var $msg = $('#batllie-lookup-feedback-msg');

                if (!orderNum) return;

                $btn.prop('disabled', true).find('.btn-txt').hide();
                $btn.find('.btn-spinner').show();
                $msg.hide().removeClass('is-success is-error');

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'emp_caja_lookup_order',
                        order_number: orderNum
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).find('.btn-txt').show();
                        $btn.find('.btn-spinner').hide();

                        if (res && res.success && res.data && res.data.order) {
                            var order = res.data.order;
                            saveLocalOrder(order);
                            self.renderOrders(getLocalOrders());
                            self.syncSwitcherBar();
                            $msg.addClass('is-success').text(res.data.message || i18n.order_added || 'Pedido agregado').fadeIn();
                            $input.val('');
                        } else {
                            var errorMsg = (res && res.data && res.data.message) ? res.data.message : (i18n.order_not_found || 'Pedido no encontrado');
                            $msg.addClass('is-error').text(errorMsg).fadeIn();
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).find('.btn-txt').show();
                        $btn.find('.btn-spinner').hide();
                        $msg.addClass('is-error').text(i18n.order_not_found || 'Error de conexión').fadeIn();
                    }
                });
            });
        },

        open: function () {
            if (!this.$modal || !this.$modal.length) {
                this.$modal = $('#batllie-pedidos-modal');
            }
            if (!this.$modal.length) return;

            // Cerrar menú móvil si está desplegado
            try {
                $('.mobile-menu-close, .close-mobile-menu, [data-dismiss="menu"]').trigger('click');
                $('body').removeClass('mobile-menu-open modal-open');
            } catch (e) {}

            var orders = getLocalOrders();
            this.renderOrders(orders);

            this.$modal.css('display', 'flex');
            // Trigger reflow para animación CSS
            this.$modal[0].offsetHeight;
            this.$modal.addClass('is-open').attr('aria-hidden', 'false');
            $('body').addClass('batllie-hub-modal-active');
        },

        close: function () {
            if (!this.$modal || !this.$modal.length) return;

            this.$modal.removeClass('is-open').attr('aria-hidden', 'true');
            $('body').removeClass('batllie-hub-modal-active');
            setTimeout(function () {
                $('#batllie-pedidos-modal').css('display', 'none');
            }, 250);
        },

        renderOrders: function (orders) {
            var $list = $('#batllie-pedidos-list-container');
            if (!$list.length) return;

            if (!orders || !orders.length) {
                $list.html(
                    '<div class="batllie-pedidos-empty">' +
                        '<div class="batllie-pedidos-empty-icon">🛍️</div>' +
                        '<h4>No encontramos pedidos guardados</h4>' +
                        '<p>Si realizaste un pedido recientemente, ingresá tu número a continuación para verlo aquí.</p>' +
                    '</div>'
                );
                return;
            }

            var html = '';
            orders.forEach(function (ord) {
                var step = parseInt(ord.step, 10) || 1;
                var stepLabel = ord.step_label || 'En preparación';
                if (ord.status === 'cancelled') {
                    step = 0;
                    stepLabel = 'Pedido cancelado';
                } else if (ord.status === 'refunded') {
                    step = 0;
                    stepLabel = 'Pedido reembolsado';
                } else if (ord.status === 'failed') {
                    step = 0;
                    stepLabel = 'Pedido fallido';
                }
                var pillClass = 'step-' + step + (ord.status ? ' status-' + ord.status : '');
                var dateStr = ord.date || '';
                var totalStr = ord.total || '';
                var itemsSummary = ord.items_summary || '';

                html += '<div class="batllie-hub-order-card" data-order-id="' + ord.id + '">' +
                            '<div class="batllie-hub-card-header">' +
                                '<div class="batllie-hub-order-meta">' +
                                    '<span class="batllie-hub-order-number">#' + (ord.number || ord.id) + '</span>' +
                                    (dateStr ? '<span class="batllie-hub-order-date">' + dateStr + '</span>' : '') +
                                '</div>' +
                                '<div class="batllie-hub-status-pill ' + pillClass + '">' +
                                    '<span class="batllie-hub-pill-dot"></span>' +
                                    '<span>' + stepLabel + '</span>' +
                                '</div>' +
                            '</div>' +
                            '<div class="batllie-hub-card-body">' +
                                (itemsSummary ? '<div class="batllie-hub-items-text">' + itemsSummary + '</div>' : '') +
                                (totalStr ? '<div class="batllie-hub-total-row">' +
                                    '<span class="batllie-hub-total-label">Total:</span>' +
                                    '<span class="batllie-hub-total-value">' + totalStr + '</span>' +
                                '</div>' : '') +
                            '</div>' +
                            '<div class="batllie-hub-card-actions">' +
                                '<a href="' + (ord.url || '#') + '" class="batllie-hub-track-btn">' +
                                    '<span>⚡ Ver Seguimiento en Vivo</span>' +
                                    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>' +
                                '</a>' +
                            '</div>' +
                        '</div>';
            });

            $list.html(html);
        },

        checkCurrentPageOrder: function () {
            var currentUrl = window.location.href;
            if (currentUrl.indexOf('order-received') !== -1 || currentUrl.indexOf('view-order') !== -1) {
                var $card = $('.batllie-order-tracking-card');
                var orderId = $card.length ? $card.data('order-id') : null;
                var orderKey = $card.length ? $card.data('order-key') : null;
                var step = $card.length ? parseInt($card.data('current-step'), 10) : 1;
                var stepLabel = $card.length ? $card.find('.status-highlight').text() : '';

                // Extraer número de pedido del título o URL si es necesario
                var orderNum = orderId;
                var titleText = $card.find('.batllie-tracking-title').text();
                var m = titleText.match(/#(\d+)/);
                if (m && m[1]) {
                    orderNum = m[1];
                } else {
                    var urlMatch = currentUrl.match(/order-received\/(\d+)/);
                    if (urlMatch && urlMatch[1]) {
                        orderId = orderId || parseInt(urlMatch[1], 10);
                        orderNum = orderNum || urlMatch[1];
                    }
                }

                if (orderId) {
                    saveLocalOrder({
                        id: parseInt(orderId, 10),
                        number: String(orderNum),
                        key: orderKey || '',
                        url: currentUrl,
                        step: step,
                        step_label: stepLabel || 'En preparación'
                    });
                }
            }
        },

        syncSwitcherBar: function () {
            var orders = getLocalOrders();
            var $switcher = $('#batllie-orders-switcher');
            if (!$switcher.length) return;

            if (orders.length > 1) {
                $switcher.show();
                $switcher.find('.batllie-switcher-count').text(orders.length);

                // Obtener ID actual
                var currentCardId = parseInt($('.batllie-order-tracking-card').data('order-id'), 10) || 0;

                var chipsHtml = '';
                orders.forEach(function (ord) {
                    var isCurrent = (parseInt(ord.id, 10) === currentCardId);
                    var chipCls = isCurrent ? 'is-current' : '';
                    var stepNum = parseInt(ord.step, 10) || 1;
                    var label = ord.step_label || 'En preparación';

                    chipsHtml += '<a href="' + (ord.url || '#') + '" class="batllie-switcher-chip ' + chipCls + '" data-order-id="' + ord.id + '">' +
                                    '<span class="batllie-chip-dot step-' + stepNum + '"></span>' +
                                    '<strong class="batllie-chip-num">#' + (ord.number || ord.id) + '</strong>' +
                                    '<span class="batllie-chip-step-txt">' + label + '</span>' +
                                    (isCurrent ? '<span class="batllie-chip-current-badge">Actual</span>' : '') +
                                 '</a>';
                });

                $switcher.find('.batllie-switcher-chips-scroll').html(chipsHtml);
            } else {
                $switcher.hide();
            }
        }
    };

    $(document).ready(function () {
        window.BatlliePedidosHub.init();
    });

})(jQuery);
