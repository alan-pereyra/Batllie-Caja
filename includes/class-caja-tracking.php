<?php
/**
 * Clase para el Seguimiento de Pedidos en Vivo (Customer-Facing Tracking)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Tracking {

    /**
     * Inicializar hooks y endpoints
     */
    public static function init() {
        // Mostrar en la pantalla de compra realizada (Thank You / Order Received)
        add_action('woocommerce_thankyou', array(__CLASS__, 'render_tracking_widget'), 5, 1);

        // Mostrar en la pantalla de "Mi Cuenta > Ver Pedido"
        add_action('woocommerce_view_order', array(__CLASS__, 'render_tracking_widget'), 5, 1);

        // Registrar Shortcode por si se desea incrustar en cualquier página personalizada
        add_shortcode('batllie_order_tracking', array(__CLASS__, 'render_tracking_shortcode'));

        // Registrar y encolar estilos y scripts para frontend
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_frontend_assets'));

        // Endpoint AJAX en tiempo real (público y privado)
        add_action('wp_ajax_emp_caja_get_order_live_status', array(__CLASS__, 'ajax_get_order_live_status'));
        add_action('wp_ajax_nopriv_emp_caja_get_order_live_status', array(__CLASS__, 'ajax_get_order_live_status'));
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

        // Cargar condicionalmente si estamos en la página de orden recibida o ver pedido
        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('view-order'))) {
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

        return array(
            'order_id'        => $order->get_id(),
            'order_number'    => $order->get_order_number(),
            'order_key'       => $order->get_order_key(),
            'step'            => $step,
            'step_label'      => $step_label,
            'status'          => $status,
            'shipping_status' => $shipping_status,
            'is_cancelled'    => in_array($status, array('cancelled', 'refunded', 'failed')),
        );
    }

    /**
     * Renderizar el widget de seguimiento
     */
    public static function render_tracking_widget($order_id) {
        if (!$order_id) {
            return;
        }

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
            'order_id'        => $tracking['order_id'],
            'step'            => $tracking['step'],
            'step_label'      => $tracking['step_label'],
            'status'          => $tracking['status'],
            'shipping_status' => $tracking['shipping_status'],
        ));
    }
}
