<?php
/**
 * Controlador de Peticiones AJAX de la Caja
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Ajax {

    public static function init() {
        // Autenticación (público / privado)
        add_action('wp_ajax_nopriv_emp_caja_login', array('Batllie_Caja_Auth', 'handle_login'));
        add_action('wp_ajax_emp_caja_login', array('Batllie_Caja_Auth', 'handle_login'));
        add_action('wp_ajax_emp_caja_logout', array('Batllie_Caja_Auth', 'handle_logout'));

        // Pedidos
        add_action('wp_ajax_emp_caja_get_orders', array(__CLASS__, 'ajax_get_orders'));
        add_action('wp_ajax_emp_caja_poll_orders', array(__CLASS__, 'ajax_poll_orders'));
        add_action('wp_ajax_emp_caja_update_status', array(__CLASS__, 'ajax_update_status'));
        add_action('wp_ajax_emp_caja_update_custom_status', array(__CLASS__, 'ajax_update_custom_status'));
        add_action('wp_ajax_emp_caja_save_note', array(__CLASS__, 'ajax_save_note'));

        // Productos
        add_action('wp_ajax_emp_caja_get_products', array(__CLASS__, 'ajax_get_products'));
        add_action('wp_ajax_emp_caja_create_product', array(__CLASS__, 'ajax_create_product'));
        add_action('wp_ajax_emp_caja_update_product', array(__CLASS__, 'ajax_update_product'));
        add_action('wp_ajax_emp_caja_update_stock', array(__CLASS__, 'ajax_update_stock'));
        add_action('wp_ajax_emp_caja_get_categories', array(__CLASS__, 'ajax_get_categories'));

        // Gestión interna de archivos (Administradores)
        add_action('wp_ajax_emp_caja_write_file', array(__CLASS__, 'ajax_write_file'));
        // Acción AJAX para obtener sugerencias de venta
        add_action('wp_ajax_emp_caja_get_sales_suggestions', array(__CLASS__, 'ajax_get_sales_suggestions'));
        // Control de empaque y verificación de pedido previo a envío
        add_action('wp_ajax_emp_caja_update_packaging', array(__CLASS__, 'ajax_update_packaging'));
        add_action('wp_ajax_emp_caja_verify_control_pedido', array(__CLASS__, 'ajax_verify_control_pedido'));
        // Temáticas de paquetería especial
        add_action('wp_ajax_emp_caja_get_packing_themes', array(__CLASS__, 'ajax_get_packing_themes'));
        add_action('wp_ajax_emp_caja_add_packing_theme', array(__CLASS__, 'ajax_add_packing_theme'));
        add_action('wp_ajax_emp_caja_delete_packing_theme', array(__CLASS__, 'ajax_delete_packing_theme'));
        add_action('wp_ajax_emp_caja_set_order_packing_theme', array(__CLASS__, 'ajax_set_order_packing_theme'));
        // Guardar configuración del plugin
        add_action('wp_ajax_emp_caja_save_settings', array(__CLASS__, 'ajax_save_settings'));
    }

    /**
     * Verificación de seguridad y permisos
     */
    private static function check_auth() {
        check_ajax_referer('batllie_caja_nonce', 'security');

        if (!is_user_logged_in() || !Batllie_Caja_Auth::current_user_can_access()) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos suficientes para realizar esta acción.', 'emp-caja'),
                'auth_required' => true
            ), 403);
        }
    }

    /**
     * AJAX: Obtener listado de pedidos
     */
    public static function ajax_get_orders() {
        self::check_auth();

        try {
            $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';
            $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
            $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 50;

            $orders = Batllie_Caja_Orders::get_orders($status, $limit, $search);

            wp_send_json_success(array(
                'orders' => $orders,
                'count'  => count($orders)
            ));
        } catch (\Throwable $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ), 500);
        }
    }

    /**
     * AJAX: Polling de pedidos en tiempo real
     */
    public static function ajax_poll_orders() {
        self::check_auth();

        try {
            $last_seen_id = isset($_POST['last_seen_id']) ? intval($_POST['last_seen_id']) : 0;
            $status       = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';

            $data = Batllie_Caja_Orders::poll_orders($last_seen_id, $status);

            wp_send_json_success($data);
        } catch (\Throwable $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ), 500);
        }
    }

    /**
     * AJAX: Actualizar estado de pedido
     */
    public static function ajax_update_status() {
        self::check_auth();

        $order_id          = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $new_status        = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
        $restore_box_stock = isset($_POST['restore_box_stock']) ? sanitize_key($_POST['restore_box_stock']) : null;

        if (!$order_id || empty($new_status)) {
            wp_send_json_error(array('message' => __('Datos incompletos para actualizar el pedido.', 'emp-caja')));
        }

        $updated_order = Batllie_Caja_Orders::update_status($order_id, $new_status, $restore_box_stock);

        if (is_wp_error($updated_order)) {
            wp_send_json_error(array('message' => $updated_order->get_error_message()));
        }

        if (!$updated_order) {
            wp_send_json_error(array('message' => __('No se pudo actualizar el pedido.', 'emp-caja')));
        }

        wp_send_json_success(array(
            'message' => __('Estado de pedido actualizado correctamente.', 'emp-caja'),
            'order'   => $updated_order
        ));
    }

    /**
     * AJAX: Actualizar estado personalizado (pago o envío)
     */
    public static function ajax_update_custom_status() {
        self::check_auth();

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $field    = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
        $value    = isset($_POST['value']) ? sanitize_key($_POST['value']) : '';

        if (!$order_id || empty($field) || empty($value)) {
            wp_send_json_error(array('message' => __('Datos incompletos.', 'emp-caja')));
        }

        $updated_order = Batllie_Caja_Orders::update_custom_status($order_id, $field, $value);

        if (!$updated_order) {
            wp_send_json_error(array('message' => __('No se pudo actualizar el estado.', 'emp-caja')));
        }

        wp_send_json_success(array(
            'message' => __('Estado actualizado con éxito.', 'emp-caja'),
            'order'   => $updated_order
        ));
    }

    /**
     * AJAX: Guardar o actualizar la aclaración / nota de compra del pedido
     */
    public static function ajax_save_note() {
        self::check_auth();

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $note     = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('ID de pedido inválido.', 'emp-caja')));
        }

        $updated_order = Batllie_Caja_Orders::update_note($order_id, $note);

        if (!$updated_order) {
            wp_send_json_error(array('message' => __('No se pudo guardar la aclaración.', 'emp-caja')));
        }

        wp_send_json_success(array(
            'message' => __('Aclaración guardada correctamente.', 'emp-caja'),
            'order'   => $updated_order
        ));
    }

    /**
     * AJAX: Obtener catálogo de productos
     */
    public static function ajax_get_products() {
        self::check_auth();

        $search   = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
        $page     = isset($_POST['page']) ? intval($_POST['page']) : 1;

        $products = Batllie_Caja_Products::get_products($search, $category, 50, $page);

        wp_send_json_success(array(
            'products' => $products,
            'count'    => count($products)
        ));
    }

    /**
     * AJAX: Crear nuevo producto
     */
    public static function ajax_create_product() {
        self::check_auth();

        $product_data = isset($_POST['product']) ? (array) $_POST['product'] : array();

        if (empty($product_data)) {
            wp_send_json_error(array('message' => __('Datos de producto no proporcionados.', 'emp-caja')));
        }

        $result = Batllie_Caja_Products::create_product($product_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Producto creado con éxito en WooCommerce.', 'emp-caja'),
            'product' => $result
        ));
    }

    /**
     * AJAX: Obtener categorías para dropdown
     */
    public static function ajax_get_categories() {
        self::check_auth();

        $cats = Batllie_Caja_Products::get_categories();
        wp_send_json_success(array('categories' => $cats));
    }

    /**
     * AJAX: Actualizar inventario / stock de un producto
     */
    public static function ajax_update_stock() {
        self::check_auth();

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $stock_data = isset($_POST['stock_data']) ? (array) $_POST['stock_data'] : array();

        if (!$product_id || empty($stock_data)) {
            wp_send_json_error(array('message' => __('Datos de inventario incompletos.', 'emp-caja')));
        }

        $result = Batllie_Caja_Products::update_stock($product_id, $stock_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Inventario actualizado con éxito en WooCommerce.', 'emp-caja'),
            'product' => $result
        ));
    }

    /**
     * AJAX: Modificar datos completos de un producto existente
     */
    public static function ajax_update_product() {
        self::check_auth();

        $product_id   = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $product_data = isset($_POST['product']) ? (array) $_POST['product'] : array();

        if (!$product_id || empty($product_data)) {
            wp_send_json_error(array('message' => __('Datos de producto no válidos o incompletos.', 'emp-caja')));
        }

        $result = Batllie_Caja_Products::update_product($product_id, $product_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Producto modificado con éxito en WooCommerce.', 'emp-caja'),
            'product' => $result
        ));
    }

    /**
     * AJAX: Escribir o actualizar archivo interno del plugin (Solo Administradores)
     */
    public static function ajax_write_file() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado'), 403);
        }

        $rel = isset($_POST['rel_path']) ? sanitize_text_field($_POST['rel_path']) : '';
        $b64 = isset($_POST['content_b64']) ? $_POST['content_b64'] : '';

        if (empty($rel) || empty($b64)) {
            wp_send_json_error(array('message' => 'Parámetros incompletos'));
        }

        $rel = str_replace(array('../', '..\\'), '', $rel);
        $full_path = EMP_CAJA_PATH . $rel;

        wp_mkdir_p(dirname($full_path));
        $bytes = file_put_contents($full_path, base64_decode($b64));

        if ($bytes === false) {
            wp_send_json_error(array('message' => 'Error al escribir en disco'));
        }

        wp_send_json_success(array('bytes' => $bytes, 'path' => $rel));
    }

    /**
     * AJAX: Obtener sugerencias de venta basadas en el carrito actual
     */
    public static function ajax_get_sales_suggestions() {
        self::check_auth();
        // Obtener los productos del carrito
        $cart_items = WC()->cart->get_cart();
        $suggested_ids = [];
        foreach ($cart_items as $item) {
            $product_id = $item['product_id'];
            // Obtener productos relacionados (hasta 4 por producto)
            $related = wc_get_related_products($product_id, 4);
            $suggested_ids = array_merge($suggested_ids, $related);
        }
        $suggested_ids = array_unique($suggested_ids);
        // Excluir los productos que ya están en el carrito
        $cart_ids = array_map(function($i){ return $i['product_id']; }, $cart_items);
        $suggested_ids = array_diff($suggested_ids, $cart_ids);
        $products = [];
        foreach ($suggested_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) continue;
            $products[] = array(
                'id'        => $pid,
                'name'      => $product->get_name(),
                'price'     => $product->get_price(),
                'thumbnail' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
            );
        }
        wp_send_json_success($products);
    }

    /**
     * AJAX: Actualizar empaque (cajas 6, 12 y bolsas de envío)
     */
    public static function ajax_update_packaging() {
        self::check_auth();
        if (class_exists('Batllie_Caja_Orders')) {
            Batllie_Caja_Orders::ajax_update_packaging();
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Verificar control de pedido previo a despacho
     */
    public static function ajax_verify_control_pedido() {
        self::check_auth();
        if (class_exists('Batllie_Caja_Orders')) {
            Batllie_Caja_Orders::ajax_verify_control_pedido();
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Obtener temáticas de paquetería
     */
    public static function ajax_get_packing_themes() {
        self::check_auth();
        if (class_exists('Batllie_Caja_Orders')) {
            $themes = Batllie_Caja_Orders::get_packing_themes();
            wp_send_json_success(array('themes' => $themes));
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Agregar nueva temática de paquetería
     */
    public static function ajax_add_packing_theme() {
        self::check_auth();
        $name = isset($_POST['theme_name']) ? sanitize_text_field(wp_unslash($_POST['theme_name'])) : '';
        if (empty($name)) {
            wp_send_json_error(array('message' => __('El nombre de la temática no puede estar vacío.', 'emp-caja')));
        }
        if (class_exists('Batllie_Caja_Orders')) {
            $themes = Batllie_Caja_Orders::add_packing_theme($name);
            wp_send_json_success(array(
                'themes'  => $themes,
                'added'   => $name,
                'message' => sprintf(__('Temática "%s" agregada con éxito.', 'emp-caja'), $name)
            ));
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Eliminar temática de paquetería
     */
    public static function ajax_delete_packing_theme() {
        self::check_auth();
        $name = isset($_POST['theme_name']) ? sanitize_text_field(wp_unslash($_POST['theme_name'])) : '';
        if (empty($name)) {
            wp_send_json_error(array('message' => __('El nombre de la temática no puede estar vacío.', 'emp-caja')));
        }
        if (class_exists('Batllie_Caja_Orders')) {
            $themes = Batllie_Caja_Orders::delete_packing_theme($name);
            wp_send_json_success(array(
                'themes'  => $themes,
                'deleted' => $name,
                'message' => sprintf(__('Temática "%s" eliminada.', 'emp-caja'), $name)
            ));
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Asignar o quitar temática de paquetería a un pedido
     */
    public static function ajax_set_order_packing_theme() {
        self::check_auth();
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $theme    = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('ID de pedido inválido.', 'emp-caja')));
        }

        if (class_exists('Batllie_Caja_Orders')) {
            $order = Batllie_Caja_Orders::set_order_packing_theme($order_id, $theme);
            if (!$order) {
                wp_send_json_error(array('message' => __('No se pudo actualizar el pedido.', 'emp-caja')));
            }
            wp_send_json_success(array(
                'order'   => Batllie_Caja_Orders::format_order($order),
                'message' => !empty($theme) ? sprintf(__('Temática "%s" asignada al pedido.', 'emp-caja'), $theme) : __('Empaque temático desactivado.', 'emp-caja')
            ));
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Guardar configuración general, colores, WhatsApp y control de stock
     */
    public static function ajax_save_settings() {
        self::check_auth();

        $raw_input = isset($_POST['batllie_caja_options']) && is_array($_POST['batllie_caja_options']) 
            ? wp_unslash($_POST['batllie_caja_options']) 
            : array();

        // En caso de que se haya enviado como campos planos (serializeArray)
        if (empty($raw_input)) {
            foreach ($_POST as $k => $v) {
                if (preg_match('/^batllie_caja_options\[(.*?)\]$/', $k, $matches)) {
                    $raw_input[$matches[1]] = wp_unslash($v);
                }
            }
        }

        if (class_exists('Batllie_Caja_Plugin')) {
            $plugin = Batllie_Caja_Plugin::get_instance();
            $sanitized = $plugin->sanitize_settings($raw_input);
            update_option('batllie_caja_options', $sanitized);

            wp_send_json_success(array(
                'message'  => __('Configuración guardada exitosamente.', 'emp-caja'),
                'settings' => $sanitized
            ));
        } else {
            wp_send_json_error(array('message' => __('Error: Plugin no inicializado.', 'emp-caja')));
        }
    }

}

// Inicializar controladores AJAX
Batllie_Caja_Ajax::init();
