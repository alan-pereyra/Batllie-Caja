<?php
/**
 * Clase para el Seguimiento de Pedidos en Vivo (Customer-Facing Tracking)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Tracking {

    /**
     * Registro para evitar renderizado duplicado en una misma petición
     */
    private static $rendered_orders = array();

    /**
     * Inicializar hooks y endpoints
     */
    public static function init() {
        // Reemplazar la plantilla de Thank You / Order Received de WooCommerce
        add_filter('woocommerce_locate_template', array(__CLASS__, 'override_thankyou_template'), 20, 3);
        add_filter('wc_get_template', array(__CLASS__, 'override_wc_get_template'), 20, 5);

        // Ocultar texto por defecto de "Gracias. Tu pedido ha sido recibido."
        add_filter('woocommerce_thankyou_order_received_text', '__return_empty_string', 999);

        // Ocultar instrucciones y textos sobrantes de BACS en la página de orden recibida
        add_action('woocommerce_before_thankyou', array(__CLASS__, 'clean_bacs_instructions'), 1, 1);

        // Mostrar arriba al principio en la pantalla de compra realizada (Thank You / Order Received)
        add_action('woocommerce_before_thankyou', array(__CLASS__, 'render_tracking_widget'), 2, 1);
        add_action('woocommerce_thankyou', array(__CLASS__, 'render_tracking_widget'), 2, 1);

        // Mostrar en la pantalla de "Mi Cuenta > Ver Pedido"
        add_action('woocommerce_view_order', array(__CLASS__, 'render_tracking_widget'), 1, 1);

        // Ocultar acciones de pedido (Pagar / Cancelar) en la confirmación de compra y vista de pedido
        add_filter('woocommerce_my_account_my_orders_actions', array(__CLASS__, 'hide_order_actions'), 999, 2);

        // Añadir enlace "Mis pedidos" al menú de navegación
        add_filter('wp_nav_menu_items', array(__CLASS__, 'add_mis_pedidos_menu_item'), 10, 2);
        add_filter('wp_nav_menu', array(__CLASS__, 'filter_wp_nav_menu'), 10, 2);

        // Redirección inteligente para la pantalla de pedidos
        add_action('template_redirect', array(__CLASS__, 'handle_mis_pedidos_redirect'));

        // Guardar cookies de pedido reciente al completar o visualizar pedido
        add_action('woocommerce_thankyou', array(__CLASS__, 'save_recent_order_cookie'), 1, 1);
        add_action('woocommerce_before_thankyou', array(__CLASS__, 'save_recent_order_cookie'), 1, 1);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'save_recent_order_cookie'), 10, 1);

        // Modal "Mis Pedidos" (Hub de Pedidos) en el footer para todo el sitio
        add_action('wp_footer', array(__CLASS__, 'render_hub_modal'));

        // Registrar Shortcode por si se desea incrustar en cualquier página personalizada
        add_shortcode('batllie_order_tracking', array(__CLASS__, 'render_tracking_shortcode'));

        // Registrar y encolar estilos y scripts para frontend
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_frontend_assets'));

        // Endpoint AJAX en tiempo real (público y privado)
        add_action('wp_ajax_emp_caja_get_order_live_status', array(__CLASS__, 'ajax_get_order_live_status'));
        add_action('wp_ajax_nopriv_emp_caja_get_order_live_status', array(__CLASS__, 'ajax_get_order_live_status'));

        // Endpoints AJAX para Multi-Pedidos (público y privado)
        add_action('wp_ajax_emp_caja_get_recent_orders', array(__CLASS__, 'ajax_get_recent_orders'));
        add_action('wp_ajax_nopriv_emp_caja_get_recent_orders', array(__CLASS__, 'ajax_get_recent_orders'));
        add_action('wp_ajax_emp_caja_lookup_order', array(__CLASS__, 'ajax_lookup_order'));
        add_action('wp_ajax_nopriv_emp_caja_lookup_order', array(__CLASS__, 'ajax_lookup_order'));
    }

    /**
     * Ocultar botones de acciones (Pagar / Cancelar) en la tabla de detalles del pedido
     */
    public static function hide_order_actions($actions, $order) {
        return array();
    }

    /**
     * Obtener la URL de seguimiento del pedido actual del cliente
     */
    public static function get_customer_current_order_url() {
        // 1. Si hay cookie directa con la URL del pedido
        if (!empty($_COOKIE['batllie_recent_order_url'])) {
            $candidate_url = esc_url_raw(wp_unslash($_COOKIE['batllie_recent_order_url']));
            if (!empty($candidate_url) && (strpos($candidate_url, 'order-received') !== false || strpos($candidate_url, 'view-order') !== false)) {
                return $candidate_url;
            }
        }

        // 2. Si hay lista multi-pedidos guardada
        $recent = self::get_customer_recent_orders(1);
        if (!empty($recent)) {
            return $recent[0]['url'];
        }

        // 3. Fallback a endpoint inteligente
        return home_url('/?batllie_mis_pedidos=1');
    }

    /**
     * Formatear resumen de pedido para listados y selector rápido
     */
    public static function format_order_summary_data($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return null;
        }
        $tracking = self::get_order_tracking_data($order);
        if (!$tracking) {
            return null;
        }

        $created = $order->get_date_created();
        $date_formatted = '';
        if ($created) {
            $timestamp = $created->getTimestamp();
            $now = time();
            $today_str     = class_exists('Batllie_Caja_Orders') ? Batllie_Caja_Orders::format_datetime('Y-m-d', $now) : date('Y-m-d', $now);
            $yesterday_str = class_exists('Batllie_Caja_Orders') ? Batllie_Caja_Orders::format_datetime('Y-m-d', $now - DAY_IN_SECONDS) : date('Y-m-d', $now - DAY_IN_SECONDS);
            $order_day_str = class_exists('Batllie_Caja_Orders') ? Batllie_Caja_Orders::format_datetime('Y-m-d', $timestamp) : date('Y-m-d', $timestamp);
            $time_str      = class_exists('Batllie_Caja_Orders') ? Batllie_Caja_Orders::format_datetime('H:i', $timestamp) : date('H:i', $timestamp);

            if ($order_day_str === $today_str) {
                $date_formatted = sprintf(__('Hoy, %s hs', 'emp-caja'), $time_str);
            } elseif ($order_day_str === $yesterday_str) {
                $date_formatted = sprintf(__('Ayer, %s hs', 'emp-caja'), $time_str);
            } else {
                $full_date = class_exists('Batllie_Caja_Orders') ? Batllie_Caja_Orders::format_datetime('d/m/Y H:i', $timestamp) : date('d/m/Y H:i', $timestamp);
                $date_formatted = $full_date . ' hs';
            }
        }

        // Resumen breve de productos
        $item_names = array();
        foreach ($order->get_items() as $item) {
            $qty = $item->get_quantity();
            $item_names[] = ($qty > 1 ? $qty . 'x ' : '') . $item->get_name();
            if (count($item_names) >= 3) {
                break;
            }
        }
        $total_items = count($order->get_items());
        $items_summary = implode(', ', $item_names);
        if ($total_items > 3) {
            $items_summary .= sprintf(__(' y %d más...', 'emp-caja'), $total_items - 3);
        }

        return array(
            'id'            => $order->get_id(),
            'number'        => $order->get_order_number(),
            'key'           => $order->get_order_key(),
            'url'           => $order->get_checkout_order_received_url(),
            'date'          => $date_formatted,
            'total'         => $order->get_formatted_order_total(),
            'status'        => $tracking['status'],
            'step'          => $tracking['step'],
            'step_label'    => $tracking['step_label'],
            'is_paid'       => $tracking['is_paid'],
            'is_active'     => !in_array($tracking['status'], array('completed', 'cancelled', 'refunded', 'failed')),
            'items_summary' => $items_summary,
        );
    }

    /**
     * Obtener lista de pedidos recientes del cliente actual
     */
    public static function get_customer_recent_orders($limit = 10) {
        $orders_data = array();
        $seen_ids = array();

        // 1. Si el usuario está autenticado, obtener sus pedidos de WooCommerce
        if (is_user_logged_in() && function_exists('wc_get_orders')) {
            $user_orders = wc_get_orders(array(
                'customer' => get_current_user_id(),
                'limit'    => $limit,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ));
            if (!empty($user_orders)) {
                foreach ($user_orders as $ord) {
                    if ($ord && is_a($ord, 'WC_Order')) {
                        $id = $ord->get_id();
                        $seen_ids[$id] = true;
                        $formatted = self::format_order_summary_data($ord);
                        if ($formatted) {
                            $orders_data[] = $formatted;
                        }
                    }
                }
            }
        }

        // 2. Revisar cookie multi-pedidos 'batllie_recent_orders'
        if (!empty($_COOKIE['batllie_recent_orders'])) {
            $cookie_raw = wp_unslash($_COOKIE['batllie_recent_orders']);
            $cookie_list = json_decode($cookie_raw, true);
            if (is_array($cookie_list)) {
                foreach ($cookie_list as $entry) {
                    $ord_id = isset($entry['id']) ? intval($entry['id']) : 0;
                    $ord_key = isset($entry['key']) ? sanitize_text_field($entry['key']) : '';
                    if ($ord_id && empty($seen_ids[$ord_id])) {
                        $ord = wc_get_order($ord_id);
                        if ($ord && is_a($ord, 'WC_Order')) {
                            if (!is_user_logged_in() && !empty($ord_key) && $ord->get_order_key() !== $ord_key) {
                                continue;
                            }
                            $seen_ids[$ord_id] = true;
                            $formatted = self::format_order_summary_data($ord);
                            if ($formatted) {
                                $orders_data[] = $formatted;
                            }
                        }
                    }
                }
            }
        }

        // 3. Revisar cookie de pedido reciente único legacy 'batllie_recent_order_id'
        if (!empty($_COOKIE['batllie_recent_order_id'])) {
            $legacy_id = intval($_COOKIE['batllie_recent_order_id']);
            if ($legacy_id && empty($seen_ids[$legacy_id])) {
                $ord = wc_get_order($legacy_id);
                if ($ord && is_a($ord, 'WC_Order')) {
                    $legacy_key = !empty($_COOKIE['batllie_recent_order_key']) ? sanitize_text_field(wp_unslash($_COOKIE['batllie_recent_order_key'])) : '';
                    if (!is_user_logged_in() && !empty($legacy_key) && $ord->get_order_key() !== $legacy_key) {
                        // Ignorar si no coincide clave
                    } else {
                        $seen_ids[$legacy_id] = true;
                        $formatted = self::format_order_summary_data($ord);
                        if ($formatted) {
                            $orders_data[] = $formatted;
                        }
                    }
                }
            }
        }

        // 4. Si hay sesión activa de WooCommerce con pedido reciente
        if (function_exists('WC') && WC()->session) {
            $session_id = WC()->session->get('order_awaiting_payment');
            if (!$session_id) {
                $session_id = WC()->session->get('last_order_id');
            }
            if ($session_id && empty($seen_ids[$session_id])) {
                $ord = wc_get_order($session_id);
                if ($ord && is_a($ord, 'WC_Order')) {
                    $seen_ids[$session_id] = true;
                    $formatted = self::format_order_summary_data($ord);
                    if ($formatted) {
                        $orders_data[] = $formatted;
                    }
                }
            }
        }

        return array_slice($orders_data, 0, $limit);
    }

    /**
     * Guardar cookies del pedido actual del cliente (soporta historial multi-pedido)
     */
    public static function save_recent_order_cookie($order_id) {
        if (!$order_id) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }

        $url = $order->get_checkout_order_received_url();
        $key = $order->get_order_key();
        $expiry = time() + (60 * DAY_IN_SECONDS);

        // Actualizar lista multi-pedido
        $existing = array();
        if (!empty($_COOKIE['batllie_recent_orders'])) {
            $decoded = json_decode(wp_unslash($_COOKIE['batllie_recent_orders']), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $updated = array(
            array(
                'id'  => $order->get_id(),
                'num' => $order->get_order_number(),
                'key' => $key,
                'url' => $url,
            )
        );

        foreach ($existing as $item) {
            if (isset($item['id']) && intval($item['id']) !== intval($order_id)) {
                $updated[] = $item;
            }
            if (count($updated) >= 10) {
                break;
            }
        }

        if (!headers_sent()) {
            setcookie('batllie_recent_order_id', strval($order_id), $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
            setcookie('batllie_recent_order_key', $key, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
            setcookie('batllie_recent_order_url', $url, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
            setcookie('batllie_recent_orders', wp_json_encode($updated), $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
        }
    }

    /**
     * Redirigir hacia la pantalla de pedidos inteligente
     */
    public static function handle_mis_pedidos_redirect() {
        if (isset($_GET['batllie_mis_pedidos'])) {
            $recent_orders = self::get_customer_recent_orders();
            $count = count($recent_orders);

            if ($count === 1) {
                // Solo tiene 1 pedido -> directo a la pantalla de seguimiento
                wp_safe_redirect($recent_orders[0]['url']);
                exit;
            }

            // Si tiene 2 o más pedidos (o 0), redirigir a inicio con el parámetro para abrir el Hub Modal
            wp_safe_redirect(add_query_arg('batllie_open_pedidos', '1', home_url('/')));
            exit;
        }
    }

    /**
     * Añadir enlace "Mis pedidos" al menú de navegación (items)
     */
    public static function add_mis_pedidos_menu_item($items, $args) {
        if (strpos($items, 'menu-item-mis-pedidos') !== false) {
            return $items;
        }

        $url = self::get_customer_current_order_url();
        $label = __('Mis pedidos', 'emp-caja');
        $item_html = '<li id="menu-item-mis-pedidos" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-mis-pedidos" onclick="if(typeof openMobileMenu===\'function\')openMobileMenu();"><a href="' . esc_url($url) . '" data-batllie-mis-pedidos="1">' . esc_html($label) . '</a></li>';

        if (preg_match('/(<div[^>]*show-mobile[^>]*>)/i', $items, $matches)) {
            $items = str_replace($matches[1], $item_html . $matches[1], $items);
        } else {
            $items .= $item_html;
        }

        return $items;
    }

    /**
     * Filtro sobre el nav_menu completo como garantía para temas personalizados
     */
    public static function filter_wp_nav_menu($nav_menu, $args) {
        if (strpos($nav_menu, 'menu-item-mis-pedidos') !== false) {
            return $nav_menu;
        }

        $url = self::get_customer_current_order_url();
        $label = __('Mis pedidos', 'emp-caja');
        $item_html = '<li id="menu-item-mis-pedidos" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-mis-pedidos" onclick="if(typeof openMobileMenu===\'function\')openMobileMenu();"><a href="' . esc_url($url) . '" data-batllie-mis-pedidos="1">' . esc_html($label) . '</a></li>';

        if (preg_match('/(<div[^>]*show-mobile[^>]*>)/i', $nav_menu, $matches)) {
            return str_replace($matches[1], $item_html . $matches[1], $nav_menu);
        }

        if (strpos($nav_menu, '</ul>') !== false) {
            return str_replace('</ul>', $item_html . '</ul>', $nav_menu);
        }

        return $nav_menu;
    }

    /**
     * Limpiar instrucciones de BACS para que solo se muestren los datos bancarios limpios
     */
    public static function clean_bacs_instructions($order_id) {
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            if (isset($gateways['bacs'])) {
                $gateways['bacs']->instructions = '';
            }
        }
    }

    /**
     * Filtro para sobreescribir la plantilla checkout/thankyou.php y checkout/bacs-details.php
     */
    public static function override_thankyou_template($template, $template_name, $template_path) {
        if ($template_name === 'checkout/thankyou.php' || $template_name === 'checkout/bacs-details.php') {
            $custom = EMP_CAJA_PATH . 'templates/woocommerce/' . $template_name;
            if (file_exists($custom)) {
                return $custom;
            }
        }
        return $template;
    }

    /**
     * Filtro alternativo wc_get_template para sobreescribir la plantilla checkout/thankyou.php y checkout/bacs-details.php
     */
    public static function override_wc_get_template($located, $template_name, $args, $template_path, $default_path) {
        if ($template_name === 'checkout/thankyou.php' || $template_name === 'checkout/bacs-details.php') {
            $custom = EMP_CAJA_PATH . 'templates/woocommerce/' . $template_name;
            if (file_exists($custom)) {
                return $custom;
            }
        }
        return $located;
    }

    /**
     * Registrar assets para frontend
     */
    public static function register_frontend_assets() {
        wp_register_style(
            'batllie-caja-tracking-css',
            EMP_CAJA_URL . 'assets/css/caja-tracking.css',
            array(),
            EMP_CAJA_VERSION
        );

        // Encolar estilos de seguimiento y Hub modal en todo el frontend
        wp_enqueue_style('batllie-caja-tracking-css');

        // Inyectar paleta de colores de administración para el frontend (Hub modal, barra rápida, seguimiento)
        $options = Batllie_Caja_Plugin::get_color_settings();
        $custom_css = "
        :root, #batllie-pedidos-modal, .batllie-order-tracking-card {
            --caja-bg: {$options['bg_color']};
            --caja-header-bg: {$options['header_bg']};
            --caja-card-bg: {$options['card_bg']};
            --caja-card-border: {$options['card_border']};
            --caja-text: {$options['text_color']};
            --caja-text-muted: {$options['text_muted']};
            --caja-primary: {$options['primary_color']};
            --caja-primary-hover: {$options['primary_hover']};
            --caja-btn-text: {$options['btn_text']};
            --caja-status-pending: {$options['status_pending']};
            --caja-status-processing: {$options['status_processing']};
            --caja-status-enviando: {$options['status_enviando']};
            --caja-status-completed: {$options['status_completed']};
            --caja-status-recibido-problema: {$options['status_recibido_problema']};
            --caja-status-cancelled: {$options['status_cancelled']};
            --caja-status-refunded: {$options['status_refunded']};
        }";
        wp_add_inline_style('batllie-caja-tracking-css', $custom_css);

        wp_register_script(
            'batllie-caja-tracking-js',
            EMP_CAJA_URL . 'assets/js/caja-tracking.js',
            array('jquery'),
            EMP_CAJA_VERSION,
            true
        );

        // Encolar script de navegación de pedidos en todo el frontend para sincronizar "Mis pedidos" y el Hub
        wp_enqueue_script(
            'batllie-caja-nav-menu',
            EMP_CAJA_URL . 'assets/js/caja-nav-menu.js',
            array('jquery'),
            EMP_CAJA_VERSION,
            true
        );

        $recent_orders = self::get_customer_recent_orders();

        wp_localize_script('batllie-caja-nav-menu', 'emp_caja_nav_params', array(
            'ajax_url'            => admin_url('admin-ajax.php'),
            'my_orders_url'       => self::get_customer_current_order_url(),
            'recent_orders'       => $recent_orders,
            'has_multiple_orders' => (count($recent_orders) > 1),
            'auto_open'           => isset($_GET['batllie_open_pedidos']),
            'i18n'                => array(
                'mis_pedidos'     => __('Mis pedidos', 'emp-caja'),
                'searching'       => __('Buscando pedido...', 'emp-caja'),
                'order_not_found' => __('No encontramos un pedido con ese número. Verificá el número e intentá nuevamente.', 'emp-caja'),
                'order_added'     => __('¡Pedido encontrado y agregado a tu lista!', 'emp-caja'),
                'actual'          => __('Actual', 'emp-caja'),
                'ver_todos'       => __('Ver todos', 'emp-caja'),
                'ver_seguimiento' => __('⚡ Ver Seguimiento en Vivo', 'emp-caja'),
            ),
        ));

        // Cargar script de seguimiento en vivo en checkout, orden recibida o ver pedido
        if (is_checkout() || (function_exists('is_order_received_page') && is_order_received_page()) || (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('view-order')))) {
            self::enqueue_tracking_assets();
        }
    }

    /**
     * Encolar estilos y scripts con parámetros localizados
     */
    public static function enqueue_tracking_assets() {
        wp_enqueue_style('batllie-caja-tracking-css');
        wp_enqueue_script('batllie-caja-tracking-js');

        $options = Batllie_Caja_Plugin::get_color_settings();
        $poll_interval = !empty($options['poll_interval']) ? intval($options['poll_interval']) : 8;

        wp_localize_script('batllie-caja-tracking-js', 'emp_caja_tracking_params', array(
            'ajax_url'      => admin_url('admin-ajax.php'),
            'poll_interval' => max(5, $poll_interval),
        ));
    }

    /**
     * Obtener los datos calculados de seguimiento para un pedido
     */
    public static function get_order_tracking_data($order) {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        if (!$order || !is_a($order, 'WC_Order')) {
            return false;
        }

        $status = $order->get_status();
        $shipping_status = $order->get_meta('_caja_shipping_status');

        $step = 1;
        $step_label = __('Batllie está preparando tu pedido', 'emp-caja');

        // Mapeo de etapas
        if ($status === 'cancelled') {
            $step = 0;
            $step_label = __('Pedido cancelado', 'emp-caja');
        } elseif ($status === 'refunded') {
            $step = 0;
            $step_label = __('Pedido reembolsado', 'emp-caja');
        } elseif ($status === 'failed') {
            $step = 0;
            $step_label = __('Pedido fallido', 'emp-caja');
        } elseif ($status === 'completed' || $status === 'recibido-problema' || $shipping_status === 'entregado' || $shipping_status === 'entregado_problemas') {
            $step = 4;
            $step_label = __('Pedido recibido, que lo disfrutes', 'emp-caja');
        } elseif ($shipping_status === 'en_puerta') {
            $step = 3;
            $step_label = __('El repartidor está en la puerta', 'emp-caja');
        } elseif ($shipping_status === 'enviando' || $shipping_status === 'demorado' || $status === 'enviando') {
            $step = 3;
            $step_label = __('El repartidor está enviando tu pedido', 'emp-caja');
        } elseif ($shipping_status === 'esperando_repartidor') {
            $step = 2;
            $step_label = __('Esperando que el repartidor recoja tu pedido', 'emp-caja');
        } else {
            $step = 1;
            $step_label = __('Batllie está preparando tu pedido', 'emp-caja');
        }

        // Detección de pago por transferencia y estado de comprobante
        $payment_method = $order->get_payment_method();
        $payment_method_title = $order->get_payment_method_title();
        $is_bacs = ($payment_method === 'bacs') || (stripos($payment_method_title, 'transferencia') !== false);

        $caja_pay_status = $order->get_meta('_caja_payment_status');
        if (empty($caja_pay_status)) {
            if (in_array($status, array('processing', 'completed'))) {
                $caja_pay_status = 'pagado';
            } elseif ($payment_method === 'cod') {
                $caja_pay_status = 'efectivo_entrega';
            } elseif ($status === 'refunded') {
                $caja_pay_status = 'devolucion';
            } else {
                $caja_pay_status = 'pendiente';
            }
        }
        $is_paid = ($caja_pay_status === 'pagado') || in_array($status, array('processing', 'completed'));
        $show_receipt_pending = $is_bacs && !$is_paid;

        // Obtener Alias de la cuenta bancaria BACS si aplica
        $bacs_alias = '';
        $bacs_bank  = '';
        if ($is_bacs) {
            $bacs_accounts = get_option('woocommerce_bacs_accounts', array());
            if (!empty($bacs_accounts) && is_array($bacs_accounts)) {
                foreach ($bacs_accounts as $acc) {
                    if (!empty($acc['account_name'])) {
                        $bacs_alias = trim($acc['account_name']);
                    }
                    if (!empty($acc['bank_name'])) {
                        $bacs_bank = trim($acc['bank_name']);
                    }
                    if (!empty($bacs_alias)) break;
                }
            }
            if (empty($bacs_alias)) {
                $bacs_settings = get_option('woocommerce_bacs_settings', array());
                if (!empty($bacs_settings['account_name'])) {
                    $bacs_alias = trim($bacs_settings['account_name']);
                }
            }
        }
        $clean_alias = preg_replace('/^alias:\s*/i', '', $bacs_alias);

        // WhatsApp para envío de comprobante con información completa del pedido
        $caja_opts  = class_exists('Batllie_Caja_Plugin') ? Batllie_Caja_Plugin::get_color_settings() : array();
        $default_wa = !empty($caja_opts['whatsapp_number']) ? $caja_opts['whatsapp_number'] : '5491149472377';
        $wa_number  = apply_filters('batllie_caja_whatsapp_number', $default_wa, $order);
        $clean_wa   = preg_replace('/[^0-9]/', '', $wa_number);
        $wa_text    = self::generate_order_whatsapp_message($order, $clean_alias);
        $whatsapp_url = 'https://api.whatsapp.com/send?phone=' . $clean_wa . '&text=' . rawurlencode($wa_text);

        return array(
            'order_id'             => $order->get_id(),
            'order_number'         => $order->get_order_number(),
            'order_key'            => $order->get_order_key(),
            'order_total'          => $order->get_formatted_order_total(),
            'order_total_raw'      => (float) $order->get_total(),
            'bacs_alias'           => $clean_alias,
            'bacs_bank'            => $bacs_bank,
            'step'                 => $step,
            'step_label'           => $step_label,
            'status'               => $status,
            'shipping_status'      => $shipping_status,
            'is_door'              => ($shipping_status === 'en_puerta'),
            'payment_status'       => $caja_pay_status,
            'is_bacs'              => $is_bacs,
            'is_paid'              => $is_paid,
            'show_receipt_pending' => $show_receipt_pending,
            'whatsapp_url'         => $whatsapp_url,
            'is_cancelled'         => in_array($status, array('cancelled', 'refunded', 'failed')),
        );
    }

    /**
     * Generar mensaje completo y formateado para WhatsApp con todos los detalles del pedido
     */
    public static function generate_order_whatsapp_message($order, $clean_alias = '') {
        if (!$order || !is_a($order, 'WC_Order')) {
            return '';
        }

        $currency = $order->get_currency();
        $order_number = $order->get_order_number();

        // Cliente
        $first_name = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '';
        $last_name  = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '';
        $customer_name = trim($first_name . ' ' . $last_name);
        $phone = method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : '';

        // Dirección de entrega o facturación
        $addr_1 = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $addr_2 = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $city = $order->get_shipping_city() ?: $order->get_billing_city();
        $state = $order->get_shipping_state() ?: $order->get_billing_state();
        $parts = array_filter(array($addr_1, $addr_2, $city, $state));
        $address = implode(', ', $parts);

        // Envío y Pago
        $shipping_method = $order->get_shipping_method();
        $payment_method  = $order->get_payment_method_title();
        $customer_note   = $order->get_customer_note();

        // Items pedidos
        $items_lines = array();
        foreach ($order->get_items() as $item) {
            $qty = $item->get_quantity();
            $name = $item->get_name();
            $price_str = strip_tags(html_entity_decode(wc_price($item->get_total(), array('currency' => $currency))));

            // Metas de variaciones (ej: molienda, peso, etc.)
            $metas = array();
            foreach ($item->get_formatted_meta_data('') as $meta) {
                if (strpos($meta->key, '_') === 0) continue;
                $metas[] = wp_strip_all_tags($meta->display_key . ': ' . $meta->display_value);
            }
            $meta_info = !empty($metas) ? ' (' . implode(', ', $metas) . ')' : '';

            $items_lines[] = "• " . $qty . "x " . $name . $meta_info . " - " . $price_str;
        }

        $subtotal_str = strip_tags(html_entity_decode(wc_price($order->get_subtotal(), array('currency' => $currency))));
        $shipping_total = (float) $order->get_shipping_total();
        $shipping_str = ($shipping_total > 0) ? strip_tags(html_entity_decode(wc_price($shipping_total, array('currency' => $currency)))) : __('Gratis', 'emp-caja');
        $total_str = strip_tags(html_entity_decode(wc_price($order->get_total(), array('currency' => $currency))));

        $lines = array();
        $lines[] = __('¡Hola! Te adjunto el comprobante de transferencia para mi pedido:', 'emp-caja');
        $lines[] = "";
        $lines[] = sprintf(__('📋 *DETALLES DEL PEDIDO #%s*', 'emp-caja'), $order_number);
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━";

        if ($customer_name) {
            $lines[] = sprintf(__('👤 *Cliente:* %s', 'emp-caja'), $customer_name);
        }
        if ($phone) {
            $lines[] = sprintf(__('📞 *Teléfono:* %s', 'emp-caja'), $phone);
        }
        if ($address) {
            $lines[] = sprintf(__('📍 *Dirección:* %s', 'emp-caja'), $address);
        }
        if ($shipping_method) {
            $lines[] = sprintf(__('🚚 *Envío:* %s', 'emp-caja'), $shipping_method);
        }
        if ($payment_method) {
            $pay_txt = $payment_method;
            if ($clean_alias) {
                $pay_txt .= " (" . sprintf(__('Alias: %s', 'emp-caja'), $clean_alias) . ")";
            }
            $lines[] = sprintf(__('💳 *Pago:* %s', 'emp-caja'), $pay_txt);
        }

        $lines[] = "";
        $lines[] = __('🛒 *PRODUCTOS:*', 'emp-caja');
        foreach ($items_lines as $il) {
            $lines[] = $il;
        }

        $lines[] = "";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━";
        if ($subtotal_str && $subtotal_str !== $total_str) {
            $lines[] = sprintf(__('💰 *Subtotal:* %s', 'emp-caja'), $subtotal_str);
            $lines[] = sprintf(__('🚚 *Costo de envío:* %s', 'emp-caja'), $shipping_str);
        }
        $lines[] = sprintf(__('💵 *TOTAL A TRANSFERIR:* *%s*', 'emp-caja'), $total_str);
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━";

        if ($customer_note) {
            $lines[] = "";
            $lines[] = sprintf(__('📝 *Nota del pedido:* %s', 'emp-caja'), $customer_note);
        }

        $full_message = implode("\n", $lines);
        return apply_filters('batllie_caja_order_whatsapp_message', $full_message, $order);
    }

    /**
     * Renderizar el widget de seguimiento
     */
    public static function render_tracking_widget($order_id) {
        if (!$order_id) {
            return;
        }

        if (isset(self::$rendered_orders[$order_id])) {
            return;
        }
        self::$rendered_orders[$order_id] = true;

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Asegurar que los assets estén encolados
        self::enqueue_tracking_assets();

        $tracking = self::get_order_tracking_data($order);
        $options = Batllie_Caja_Plugin::get_color_settings();

        include EMP_CAJA_PATH . 'templates/order-tracking.php';
    }

    /**
     * Shortcode [batllie_order_tracking order_id="..."]
     */
    public static function render_tracking_shortcode($atts) {
        $atts = shortcode_atts(array(
            'order_id' => 0,
        ), $atts, 'batllie_order_tracking');

        $order_id = intval($atts['order_id']);

        if (!$order_id && isset($_GET['order_id'])) {
            $order_id = intval($_GET['order_id']);
        }

        if (!$order_id) {
            return '';
        }

        ob_start();
        self::render_tracking_widget($order_id);
        return ob_get_clean();
    }

    /**
     * AJAX endpoint para verificar el estado en vivo
     */
    public static function ajax_get_order_live_status() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order_key = isset($_GET['order_key']) ? sanitize_text_field($_GET['order_key']) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('ID de pedido no especificado', 'emp-caja')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Pedido no encontrado', 'emp-caja')));
        }

        // Verificar la clave del pedido por seguridad
        if (!empty($order_key) && $order->get_order_key() !== $order_key) {
            wp_send_json_error(array('message' => __('Clave de pedido inválida', 'emp-caja')));
        }

        $tracking = self::get_order_tracking_data($order);

        wp_send_json_success(array(
            'order_id'             => $tracking['order_id'],
            'step'                 => $tracking['step'],
            'step_label'           => $tracking['step_label'],
            'status'               => $tracking['status'],
            'shipping_status'      => $tracking['shipping_status'],
            'is_door'              => !empty($tracking['is_door']),
            'payment_status'       => $tracking['payment_status'],
            'is_paid'              => $tracking['is_paid'],
            'show_receipt_pending' => $tracking['show_receipt_pending'],
        ));
    }

    /**
     * AJAX endpoint para obtener pedidos recientes del cliente actual
     */
    public static function ajax_get_recent_orders() {
        $orders = self::get_customer_recent_orders();
        wp_send_json_success(array(
            'orders' => $orders,
        ));
    }

    /**
     * AJAX endpoint para buscar y agregar un pedido a la lista del cliente
     */
    public static function ajax_lookup_order() {
        $order_num = isset($_POST['order_number']) ? sanitize_text_field(wp_unslash($_POST['order_number'])) : '';
        $order_num = trim(str_replace('#', '', $order_num));

        if (empty($order_num)) {
            wp_send_json_error(array('message' => __('Por favor ingresá un número de pedido válido.', 'emp-caja')));
        }

        $order = null;
        if (is_numeric($order_num)) {
            $order = wc_get_order(intval($order_num));
        }
        if (!$order && function_exists('wc_get_orders')) {
            $found = wc_get_orders(array(
                'limit'        => 1,
                'order_number' => $order_num,
            ));
            if (!empty($found)) {
                $order = $found[0];
            }
        }

        if (!$order || !is_a($order, 'WC_Order')) {
            wp_send_json_error(array('message' => sprintf(__('No encontramos el pedido #%s. Verificá el número e intentá nuevamente.', 'emp-caja'), esc_html($order_num))));
        }

        // Guardar en cookies del cliente
        self::save_recent_order_cookie($order->get_id());

        $summary = self::format_order_summary_data($order);
        wp_send_json_success(array(
            'message' => sprintf(__('¡Pedido #%s agregado con éxito!', 'emp-caja'), esc_html($order->get_order_number())),
            'order'   => $summary,
        ));
    }

    /**
     * Renderizar el Modal "Mis Pedidos" (Hub de Pedidos) en el footer de todas las páginas
     */
    public static function render_hub_modal() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $options = Batllie_Caja_Plugin::get_color_settings();
        $recent_orders = self::get_customer_recent_orders();
        ?>
        <div id="batllie-pedidos-modal" class="batllie-pedidos-modal" style="display:none;
            --caja-bg: <?php echo esc_attr($options['bg_color']); ?>;
            --caja-header-bg: <?php echo esc_attr($options['header_bg']); ?>;
            --caja-card-bg: <?php echo esc_attr($options['card_bg']); ?>;
            --caja-card-border: <?php echo esc_attr($options['card_border']); ?>;
            --caja-text: <?php echo esc_attr($options['text_color']); ?>;
            --caja-text-muted: <?php echo esc_attr($options['text_muted']); ?>;
            --caja-primary: <?php echo esc_attr($options['primary_color']); ?>;
            --caja-primary-hover: <?php echo esc_attr($options['primary_hover']); ?>;
            --caja-btn-text: <?php echo esc_attr($options['btn_text']); ?>;
            --caja-status-pending: <?php echo esc_attr($options['status_pending']); ?>;
            --caja-status-processing: <?php echo esc_attr($options['status_processing']); ?>;
            --caja-status-enviando: <?php echo esc_attr($options['status_enviando']); ?>;
            --caja-status-completed: <?php echo esc_attr($options['status_completed']); ?>;
            --caja-status-recibido-problema: <?php echo esc_attr($options['status_recibido_problema']); ?>;
            --caja-status-cancelled: <?php echo esc_attr($options['status_cancelled']); ?>;
            --caja-status-refunded: <?php echo esc_attr($options['status_refunded']); ?>;
        " aria-hidden="true">
            <div class="batllie-pedidos-backdrop"></div>
            <div class="batllie-pedidos-dialog" role="dialog" aria-modal="true" aria-labelledby="batllie-pedidos-modal-title">
                
                <!-- Encabezado del Modal -->
                <div class="batllie-pedidos-dialog-header">
                    <div class="batllie-pedidos-title-wrap">
                        <div class="batllie-pedidos-icon-box">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                                <polyline points="3.29 7 12 12 20.71 7"/>
                                <line x1="12" y1="22" x2="12" y2="12"/>
                            </svg>
                        </div>
                        <div>
                            <h3 id="batllie-pedidos-modal-title" class="batllie-pedidos-title"><?php _e('Mis Pedidos', 'emp-caja'); ?></h3>
                            <p class="batllie-pedidos-subtitle"><?php _e('Seguí el estado de tus compras en tiempo real', 'emp-caja'); ?></p>
                        </div>
                    </div>
                    <button type="button" class="batllie-pedidos-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
                </div>

                <!-- Cuerpo del Modal: Lista de Pedidos -->
                <div class="batllie-pedidos-dialog-body">
                    <div class="batllie-pedidos-list" id="batllie-pedidos-list-container">
                        <?php if (!empty($recent_orders)) : ?>
                            <?php foreach ($recent_orders as $ord) : 
                                $step = isset($ord['step']) ? intval($ord['step']) : 1;
                                $step_label = !empty($ord['step_label']) ? $ord['step_label'] : __('Batllie está preparando tu pedido', 'emp-caja');
                                if (!empty($ord['status'])) {
                                    if ($ord['status'] === 'cancelled') {
                                        $step = 0;
                                        $step_label = __('Pedido cancelado', 'emp-caja');
                                    } elseif ($ord['status'] === 'refunded') {
                                        $step = 0;
                                        $step_label = __('Pedido reembolsado', 'emp-caja');
                                    } elseif ($ord['status'] === 'failed') {
                                        $step = 0;
                                        $step_label = __('Pedido fallido', 'emp-caja');
                                    }
                                }
                                $pill_class = 'step-' . $step;
                                if (!empty($ord['status'])) {
                                    $pill_class .= ' status-' . esc_attr($ord['status']);
                                }
                            ?>
                                <div class="batllie-hub-order-card" data-order-id="<?php echo esc_attr($ord['id']); ?>">
                                    <div class="batllie-hub-card-header">
                                        <div class="batllie-hub-order-meta">
                                            <span class="batllie-hub-order-number">#<?php echo esc_html($ord['number']); ?></span>
                                            <span class="batllie-hub-order-date"><?php echo esc_html($ord['date']); ?></span>
                                        </div>
                                        <div class="batllie-hub-status-pill <?php echo esc_attr($pill_class); ?>">
                                            <span class="batllie-hub-pill-dot"></span>
                                            <span><?php echo esc_html($step_label); ?></span>
                                        </div>
                                    </div>

                                    <div class="batllie-hub-card-body">
                                        <?php if (!empty($ord['items_summary'])) : ?>
                                            <div class="batllie-hub-items-text"><?php echo esc_html($ord['items_summary']); ?></div>
                                        <?php endif; ?>
                                        <div class="batllie-hub-total-row">
                                            <span class="batllie-hub-total-label"><?php _e('Total:', 'emp-caja'); ?></span>
                                            <span class="batllie-hub-total-value"><?php echo wp_kses_post($ord['total']); ?></span>
                                        </div>
                                    </div>

                                    <div class="batllie-hub-card-actions">
                                        <a href="<?php echo esc_url($ord['url']); ?>" class="batllie-hub-track-btn">
                                            <span><?php _e('⚡ Ver Seguimiento en Vivo', 'emp-caja'); ?></span>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="batllie-pedidos-empty">
                                <div class="batllie-pedidos-empty-icon">🛍️</div>
                                <h4><?php _e('No encontramos pedidos guardados', 'emp-caja'); ?></h4>
                                <p><?php _e('Si realizaste un pedido recientemente, ingresá tu número a continuación para verlo aquí.', 'emp-caja'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Buscador / Recuperador de pedidos -->
                    <div class="batllie-pedidos-lookup-box">
                        <div class="batllie-lookup-toggle">
                            <span><?php _e('¿Tenés otro pedido?', 'emp-caja'); ?></span>
                            <button type="button" class="batllie-lookup-toggle-btn" id="batllie-toggle-lookup-form">
                                <?php _e('Agregar por número', 'emp-caja'); ?> &darr;
                            </button>
                        </div>
                        <form class="batllie-lookup-form" id="batllie-lookup-order-form" style="display:none;">
                            <div class="batllie-lookup-row">
                                <input type="text" class="batllie-lookup-input" id="batllie-lookup-number-input" placeholder="<?php esc_attr_e('Ej: 184', 'emp-caja'); ?>" required>
                                <button type="submit" class="batllie-lookup-submit-btn">
                                    <span class="btn-txt"><?php _e('Buscar', 'emp-caja'); ?></span>
                                    <span class="btn-spinner" style="display:none;">⏳</span>
                                </button>
                            </div>
                            <div class="batllie-lookup-msg" id="batllie-lookup-feedback-msg" style="display:none;"></div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }
}

