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

        // Redirección inteligente para la pantalla del pedido actual
        add_action('template_redirect', array(__CLASS__, 'handle_mis_pedidos_redirect'));

        // Guardar cookies de pedido reciente al completar o visualizar pedido
        add_action('woocommerce_thankyou', array(__CLASS__, 'save_recent_order_cookie'), 1, 1);
        add_action('woocommerce_before_thankyou', array(__CLASS__, 'save_recent_order_cookie'), 1, 1);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'save_recent_order_cookie'), 10, 1);

        // Registrar Shortcode por si se desea incrustar en cualquier página personalizada
        add_shortcode('batllie_order_tracking', array(__CLASS__, 'render_tracking_shortcode'));

        // Registrar y encolar estilos y scripts para frontend
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_frontend_assets'));

        // Endpoint AJAX en tiempo real (público y privado)
        add_action('wp_ajax_emp_caja_get_order_live_status', array(__CLASS__, 'ajax_get_order_live_status'));
        add_action('wp_ajax_nopriv_emp_caja_get_order_live_status', array(__CLASS__, 'ajax_get_order_live_status'));
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

        // 2. Si hay cookie de ID de pedido reciente
        if (!empty($_COOKIE['batllie_recent_order_id'])) {
            $order_id = intval($_COOKIE['batllie_recent_order_id']);
            $order    = wc_get_order($order_id);
            if ($order) {
                if (!empty($_COOKIE['batllie_recent_order_key'])) {
                    $order_key = sanitize_text_field(wp_unslash($_COOKIE['batllie_recent_order_key']));
                    if ($order->get_order_key() === $order_key) {
                        return $order->get_checkout_order_received_url();
                    }
                } else {
                    return $order->get_checkout_order_received_url();
                }
            }
        }

        // 3. Si el usuario está autenticado, buscar su último pedido
        if (is_user_logged_in() && function_exists('wc_get_orders')) {
            $orders = wc_get_orders(array(
                'customer' => get_current_user_id(),
                'limit'    => 1,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ));
            if (!empty($orders)) {
                return $orders[0]->get_checkout_order_received_url();
            }
        }

        // 4. Si hay sesión activa de WooCommerce con pedido reciente
        if (function_exists('WC') && WC()->session) {
            $order_id = WC()->session->get('order_awaiting_payment');
            if (!$order_id) {
                $order_id = WC()->session->get('last_order_id');
            }
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    return $order->get_checkout_order_received_url();
                }
            }
        }

        // 5. Fallback a endpoint de redirección inteligente
        return home_url('/?batllie_mis_pedidos=1');
    }

    /**
     * Guardar cookies del pedido actual del cliente
     */
    public static function save_recent_order_cookie($order_id) {
        if (!$order_id) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $url = $order->get_checkout_order_received_url();
        $key = $order->get_order_key();

        if (!headers_sent()) {
            $expiry = time() + (30 * DAY_IN_SECONDS);
            setcookie('batllie_recent_order_id', strval($order_id), $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
            setcookie('batllie_recent_order_key', $key, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
            setcookie('batllie_recent_order_url', $url, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
        }
    }

    /**
     * Redirigir hacia la pantalla del pedido actual o mi cuenta
     */
    public static function handle_mis_pedidos_redirect() {
        if (isset($_GET['batllie_mis_pedidos'])) {
            $order_url = self::get_customer_current_order_url();
            if ($order_url && strpos($order_url, 'batllie_mis_pedidos') === false) {
                wp_safe_redirect($order_url);
                exit;
            }

            if (is_user_logged_in() && function_exists('wc_get_account_endpoint_url')) {
                wp_safe_redirect(wc_get_account_endpoint_url('orders'));
                exit;
            }

            if (function_exists('wc_get_page_permalink')) {
                wp_safe_redirect(wc_get_page_permalink('myaccount'));
                exit;
            }

            wp_safe_redirect(home_url('/'));
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

        wp_register_script(
            'batllie-caja-tracking-js',
            EMP_CAJA_URL . 'assets/js/caja-tracking.js',
            array('jquery'),
            EMP_CAJA_VERSION,
            true
        );

        // Encolar script de navegación de pedidos en todo el frontend para sincronizar "Mis pedidos"
        wp_enqueue_script(
            'batllie-caja-nav-menu',
            EMP_CAJA_URL . 'assets/js/caja-nav-menu.js',
            array('jquery'),
            EMP_CAJA_VERSION,
            true
        );

        wp_localize_script('batllie-caja-nav-menu', 'emp_caja_nav_params', array(
            'my_orders_url' => self::get_customer_current_order_url(),
        ));

        // Cargar en checkout, orden recibida o ver pedido
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
        if ($status === 'completed' || $status === 'recibido-problema' || $shipping_status === 'entregado' || $shipping_status === 'entregado_problemas') {
            $step = 4;
            $step_label = __('Pedido recibido, que lo disfrutes', 'emp-caja');
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

        // WhatsApp para envío de comprobante
        $wa_number = apply_filters('batllie_caja_whatsapp_number', '5491149472377', $order);
        $clean_wa = preg_replace('/[^0-9]/', '', $wa_number);
        $wa_text = sprintf(__('Hola! Te adjunto el comprobante de transferencia para el Pedido #%s.', 'emp-caja'), $order->get_order_number());
        $whatsapp_url = 'https://api.whatsapp.com/send?phone=' . $clean_wa . '&text=' . rawurlencode($wa_text);

        return array(
            'order_id'             => $order->get_id(),
            'order_number'         => $order->get_order_number(),
            'order_key'            => $order->get_order_key(),
            'step'                 => $step,
            'step_label'           => $step_label,
            'status'               => $status,
            'shipping_status'      => $shipping_status,
            'payment_status'       => $caja_pay_status,
            'is_bacs'              => $is_bacs,
            'is_paid'              => $is_paid,
            'show_receipt_pending' => $show_receipt_pending,
            'whatsapp_url'         => $whatsapp_url,
            'is_cancelled'         => in_array($status, array('cancelled', 'refunded', 'failed')),
        );
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
            'payment_status'       => $tracking['payment_status'],
            'is_paid'              => $tracking['is_paid'],
            'show_receipt_pending' => $tracking['show_receipt_pending'],
        ));
    }
}
