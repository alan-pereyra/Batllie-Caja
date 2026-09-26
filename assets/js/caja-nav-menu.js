/**
 * Batllie Caja & Pedidos POS - Navegación de Pedidos
 * Gestiona el enlace "Mis pedidos" hacia la pantalla del pedido actual
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var currentUrl = window.location.href;

        // 1. Si estamos en la página de confirmación/seguimiento del pedido, almacenar la URL
        if (currentUrl.indexOf('order-received') !== -1 || currentUrl.indexOf('view-order') !== -1) {
            try {
                localStorage.setItem('batllie_recent_order_url', currentUrl);
                var now = new Date();
                now.setTime(now.getTime() + (30 * 24 * 60 * 60 * 1000));
                document.cookie = 'batllie_recent_order_url=' + encodeURIComponent(currentUrl) + '; expires=' + now.toUTCString() + '; path=/; SameSite=Lax';
            } catch (e) {}
        }

        // 2. Obtener URL de pedido guardada en este dispositivo
        var savedOrderUrl = null;
        try {
            savedOrderUrl = localStorage.getItem('batllie_recent_order_url');
        } catch (e) {}

        if (!savedOrderUrl) {
            var match = document.cookie.match(/batllie_recent_order_url=([^;]+)/);
            if (match && match[1]) {
                savedOrderUrl = decodeURIComponent(match[1]);
            }
        }

        // 3. Fallback URL si no hay pedido reciente guardado en el navegador
        var defaultUrl = (typeof emp_caja_nav_params !== 'undefined' && emp_caja_nav_params.my_orders_url)
            ? emp_caja_nav_params.my_orders_url
            : '/batllie/?batllie_mis_pedidos=1';

        var finalUrl = savedOrderUrl || defaultUrl;

        // 4. Actualizar enlaces existentes en el menú
        var $existingItem = $('#menu-item-mis-pedidos');
        if ($existingItem.length) {
            if (savedOrderUrl) {
                $existingItem.find('a').attr('href', savedOrderUrl);
            }
        } else {
            // Si el HTML no traía el elemento (por ejemplo por caché de página), insertarlo dinámicamente
            var $menuUl = $('ul.mobile-menu-items, ul.navbar-nav');
            if ($menuUl.length && $('#menu-item-mis-pedidos').length === 0) {
                var itemHtml = '<li id="menu-item-mis-pedidos" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-mis-pedidos" onclick="if(typeof openMobileMenu===\'function\')openMobileMenu();"><a href="' + finalUrl + '" data-batllie-mis-pedidos="1">Mis pedidos</a></li>';
                var $social = $menuUl.find('.d-flex.show-mobile, .d-flex.mt-5');
                if ($social.length) {
                    $social.before(itemHtml);
                } else {
                    $menuUl.append(itemHtml);
                }
            }
        }

        // 5. Interceptar click para asegurar la URL más reciente guardada en este navegador
        $(document).on('click', '#menu-item-mis-pedidos a, [data-batllie-mis-pedidos]', function(e) {
            try {
                var latest = localStorage.getItem('batllie_recent_order_url');
                if (latest && latest !== $(this).attr('href')) {
                    $(this).attr('href', latest);
                }
            } catch (err) {}
        });
    });
})(jQuery);
