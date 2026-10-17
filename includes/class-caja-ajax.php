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
        add_action('wp_ajax_emp_caja_get_packaging_items', array(__CLASS__, 'ajax_get_packaging_items'));
        add_action('wp_ajax_emp_caja_add_packaging_item', array(__CLASS__, 'ajax_add_packaging_item'));
        add_action('wp_ajax_emp_caja_delete_packaging_item', array(__CLASS__, 'ajax_delete_packaging_item'));
        // Temáticas de paquetería especial
        add_action('wp_ajax_emp_caja_get_packing_themes', array(__CLASS__, 'ajax_get_packing_themes'));
        add_action('wp_ajax_emp_caja_add_packing_theme', array(__CLASS__, 'ajax_add_packing_theme'));
        add_action('wp_ajax_emp_caja_delete_packing_theme', array(__CLASS__, 'ajax_delete_packing_theme'));
        add_action('wp_ajax_emp_caja_set_order_packing_theme', array(__CLASS__, 'ajax_set_order_packing_theme'));
        // Guardar configuración del plugin
        add_action('wp_ajax_emp_caja_save_settings', array(__CLASS__, 'ajax_save_settings'));
        // Venta Caja POS
        add_action('wp_ajax_emp_caja_create_pos_order', array(__CLASS__, 'ajax_create_pos_order'));
        add_action('wp_ajax_emp_caja_get_pos_history', array(__CLASS__, 'ajax_get_pos_history'));
        add_action('wp_ajax_emp_caja_search_customers', array(__CLASS__, 'ajax_search_customers'));
        add_action('wp_ajax_emp_caja_update_customer', array(__CLASS__, 'ajax_update_customer'));

        // Redirección directa a pedidos y auto-login tras definir contraseña
        add_action('woocommerce_customer_reset_password', array(__CLASS__, 'handle_auto_login_on_password_reset'), 10, 1);
        add_filter('woocommerce_customer_reset_password_redirect', array(__CLASS__, 'handle_redirect_to_orders_on_password_reset'), 10, 2);
        add_filter('password_reset_redirect', array(__CLASS__, 'handle_wp_password_reset_redirect'), 10, 2);
    }

    /**
     * Verificación de seguridad y permisos
     */
    private static function check_auth() {
        $nonce_valid = check_ajax_referer('batllie_caja_nonce', 'security', false);
        if (!$nonce_valid) {
            wp_send_json_error(array(
                'message' => __('Token de seguridad inválido o expirado. Por favor recarga la página.', 'emp-caja'),
                'nonce_expired' => true
            ), 403);
        }

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
        $limit    = isset($_POST['limit']) ? min(300, max(1, intval($_POST['limit']))) : 150;

        $products = Batllie_Caja_Products::get_products($search, $category, $limit, $page);

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

        if (empty($stock_data)) {
            $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'add';
            $qty = 0;
            if ($mode === 'add') {
                $qty = isset($_POST['incoming_quantity']) ? intval($_POST['incoming_quantity']) : (isset($_POST['quantity']) ? intval($_POST['quantity']) : 0);
            } elseif ($mode === 'subtract') {
                $qty = isset($_POST['subtract_quantity']) ? intval($_POST['subtract_quantity']) : (isset($_POST['incoming_quantity']) ? intval($_POST['incoming_quantity']) : 0);
            } else {
                $qty = isset($_POST['direct_quantity']) ? intval($_POST['direct_quantity']) : (isset($_POST['quantity']) ? intval($_POST['quantity']) : 0);
            }
            $stock_data = array(
                'mode'          => $mode,
                'quantity'      => $qty,
                'manage_stock'  => isset($_POST['manage_stock']) ? sanitize_text_field($_POST['manage_stock']) : 'yes',
                'regular_price' => isset($_POST['regular_price']) ? sanitize_text_field($_POST['regular_price']) : '',
                'sale_price'    => isset($_POST['sale_price']) ? sanitize_text_field($_POST['sale_price']) : '',
                'note'          => isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '',
            );
        }

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
     * AJAX: Obtener productos oficiales de empaque y candidatos disponibles
     */
    public static function ajax_get_packaging_items() {
        self::check_auth();
        if (class_exists('Batllie_Caja_Orders')) {
            $items = Batllie_Caja_Orders::get_official_packaging_items();
            $candidates = Batllie_Caja_Orders::get_available_packaging_candidates();
            wp_send_json_success(array(
                'packaging_products' => $items,
                'candidates'         => $candidates,
            ));
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Agregar nuevo empaque oficial (vincular o crear nuevo en WooCommerce)
     */
    public static function ajax_add_packaging_item() {
        self::check_auth();
        $product_id    = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $new_name      = !empty($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : (!empty($_POST['new_name']) ? sanitize_text_field(wp_unslash($_POST['new_name'])) : '');
        $initial_stock = isset($_POST['initial_stock']) ? intval($_POST['initial_stock']) : 50;

        if (!$product_id && empty($new_name)) {
            wp_send_json_error(array('message' => __('Debes seleccionar un producto existente o indicar un nombre.', 'emp-caja')));
        }

        if (class_exists('Batllie_Caja_Orders')) {
            $updated = Batllie_Caja_Orders::add_official_packaging_item($product_id, $new_name, $initial_stock);
            if ($updated === false) {
                wp_send_json_error(array('message' => __('No se pudo agregar el producto de empaque.', 'emp-caja')));
            }
            $candidates = Batllie_Caja_Orders::get_available_packaging_candidates();
            wp_send_json_success(array(
                'packaging_products' => is_array($updated) && isset($updated['items']) ? $updated['items'] : $updated,
                'item'               => is_array($updated) && isset($updated['item']) ? $updated['item'] : null,
                'added_id'           => is_array($updated) && isset($updated['item']['id']) ? $updated['item']['id'] : $product_id,
                'added_name'         => is_array($updated) && isset($updated['item']['name']) ? $updated['item']['name'] : $new_name,
                'candidates'         => $candidates,
                'message'            => __('Empaque agregado exitosamente.', 'emp-caja'),
            ));
        } else {
            wp_send_json_error(array('message' => 'Módulo de pedidos no disponible.'));
        }
    }

    /**
     * AJAX: Eliminar producto de empaque oficial
     */
    public static function ajax_delete_packaging_item() {
        self::check_auth();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(array('message' => __('ID de producto inválido.', 'emp-caja')));
        }

        if (class_exists('Batllie_Caja_Orders')) {
            $updated = Batllie_Caja_Orders::delete_official_packaging_item($product_id);
            $candidates = Batllie_Caja_Orders::get_available_packaging_candidates();
            wp_send_json_success(array(
                'packaging_products' => $updated,
                'candidates'         => $candidates,
                'message'            => __('Empaque desvinculado con éxito.', 'emp-caja'),
            ));
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

    /**
     * AJAX: Crear venta / pedido desde Venta Caja (Punto de Venta POS)
     */
    public static function ajax_create_pos_order() {
        self::check_auth();

        if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce') && !is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Permisos insuficientes para registrar ventas.', 'emp-caja')));
            return;
        }

        try {
            $raw_items = isset($_POST['items']) ? $_POST['items'] : array();
            if (is_string($raw_items)) {
                $raw_items = json_decode(stripslashes($raw_items), true);
            }

            if (empty($raw_items) || !is_array($raw_items)) {
                wp_send_json_error(array('message' => __('El ticket de venta está vacío. Selecciona al menos un producto.', 'emp-caja')));
                return;
            }

            $mode           = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'compra_realizada';
            $delivery_type  = isset($_POST['delivery_type']) ? sanitize_text_field($_POST['delivery_type']) : 'con_envio';
            $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'efectivo';
            $payment_status = isset($_POST['payment_status']) ? sanitize_text_field($_POST['payment_status']) : 'pagado';

            // En Compra Realizada (entregada en el local) la mercadería siempre sale cobrada
            if ($mode === 'compra_realizada') {
                $payment_status = 'pagado';
            }

            $card_op        = isset($_POST['card_op_number']) ? sanitize_text_field($_POST['card_op_number']) : '';
            $note           = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

            // Datos del Cliente (Nombre, DNI, Teléfono, Email, Dirección)
            $cust_name    = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
            $cust_dni     = isset($_POST['customer_dni']) ? sanitize_text_field(wp_unslash($_POST['customer_dni'])) : '';
            $cust_phone   = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
            $cust_email   = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
            $cust_address = isset($_POST['customer_address']) ? sanitize_text_field(wp_unslash($_POST['customer_address'])) : '';

            // Validación A: Si es pedido con envío, todos los datos del cliente son OBLIGATORIOS
            if ($mode === 'pedido_despachar' && $delivery_type === 'con_envio') {
                $missing_fields = array();
                if (empty($cust_name))    $missing_fields[] = __('Nombre y Apellido', 'emp-caja');
                if (empty($cust_address)) $missing_fields[] = __('Dirección', 'emp-caja');
                if (empty($cust_dni))     $missing_fields[] = __('DNI / CUIT', 'emp-caja');
                if (empty($cust_phone))   $missing_fields[] = __('Teléfono', 'emp-caja');
                if (empty($cust_email))   $missing_fields[] = __('Email', 'emp-caja');

                if (!empty($missing_fields)) {
                    wp_send_json_error(array(
                        'message' => __('Para pedidos con envío es obligatorio completar todos los datos del cliente: ', 'emp-caja') . implode(', ', $missing_fields)
                    ));
                    return;
                }
            }

            $current_user = wp_get_current_user();
            $cashier_name = ($current_user && $current_user->exists()) ? $current_user->display_name : __('Administrador', 'emp-caja');
            $cashier_id   = ($current_user && $current_user->exists()) ? $current_user->ID : 0;

            // Determinar estado final de la orden en WooCommerce
            // Si es 'compra_realizada': se completa la venta en el acto
            // Si es 'pedido_despachar': pasa a preparación (processing) para figurar en Pedidos en Vivo
            $order_status = ($mode === 'compra_realizada') ? 'completed' : 'processing';

            // Crear la orden inicialmente con customer_id 0 para no asignarla a la cuenta del cajero
            $order = wc_create_order(array(
                'customer_id' => 0,
            ));

            if (is_wp_error($order) || !$order) {
                $err_msg = is_wp_error($order) ? $order->get_error_message() : __('Error al crear la orden en WooCommerce.', 'emp-caja');
                wp_send_json_error(array('message' => $err_msg));
                return;
            }

            // Agregar productos y registrar stocks previos para auditoría
            $stock_movements = array();
            foreach ($raw_items as $item) {
                $product_id = isset($item['id']) ? intval($item['id']) : 0;
                $qty        = isset($item['quantity']) ? max(1, intval($item['quantity'])) : 1;
                $product    = wc_get_product($product_id);

                if (!$product) {
                    continue;
                }

                $stock_before = $product->managing_stock() ? $product->get_stock_quantity() : null;
                $order->add_product($product, $qty);

                $stock_movements[] = array(
                    'prod_id'      => $product_id,
                    'qty'          => $qty,
                    'stock_before' => $stock_before
                );
            }

            // Método de Pago
            if ($payment_method === 'tarjeta') {
                $order->set_payment_method('caja_tarjeta');
                $pay_title = __('Tarjeta', 'emp-caja');
                if (!empty($card_op)) {
                    $pay_title .= ' (Op: #' . $card_op . ')';
                }
                $order->set_payment_method_title($pay_title);
            } elseif ($payment_method === 'transferencia') {
                $order->set_payment_method('bacs');
                $order->set_payment_method_title(__('Transferencia bancaria', 'emp-caja'));
            } else {
                $order->set_payment_method('cod');
                $order->set_payment_method_title(__('Efectivo (Caja POS)', 'emp-caja'));
            }

            // Datos del cliente / facturación y notas
            if (!empty($cust_name)) {
                $name_parts = explode(' ', $cust_name, 2);
                $order->set_billing_first_name($name_parts[0]);
                $order->set_billing_last_name(isset($name_parts[1]) ? $name_parts[1] : '');
                $order->set_shipping_first_name($name_parts[0]);
                $order->set_shipping_last_name(isset($name_parts[1]) ? $name_parts[1] : '');
            } else {
                $order->set_billing_first_name(__('Cliente', 'emp-caja'));
                $order->set_billing_last_name(__('Mostrador', 'emp-caja'));
            }

            if (!empty($cust_phone)) {
                $order->set_billing_phone($cust_phone);
            }
            if (!empty($cust_email)) {
                $order->set_billing_email($cust_email);
            }
            if (!empty($cust_address)) {
                $order->set_billing_address_1($cust_address);
                $order->set_shipping_address_1($cust_address);
            }
            if (!empty($cust_dni)) {
                $order->update_meta_data('_billing_dni', $cust_dni);
                $order->update_meta_data('_caja_customer_dni', $cust_dni);
            }

            // Si el cliente es un usuario registrado (o coincide por email), asociarlo y actualizar sus datos
            if (!empty($cust_email) && email_exists($cust_email)) {
                $matched_user = get_user_by('email', $cust_email);
                if ($matched_user) {
                    $order->set_customer_id($matched_user->ID);
                    if (!empty($name_parts[0])) update_user_meta($matched_user->ID, 'billing_first_name', $name_parts[0]);
                    if (isset($name_parts[1])) update_user_meta($matched_user->ID, 'billing_last_name', $name_parts[1]);
                    if (!empty($cust_phone)) update_user_meta($matched_user->ID, 'billing_phone', $cust_phone);
                    if (!empty($cust_address)) {
                        update_user_meta($matched_user->ID, 'billing_address_1', $cust_address);
                        update_user_meta($matched_user->ID, 'shipping_address_1', $cust_address);
                    }
                    if (!empty($cust_dni)) {
                        update_user_meta($matched_user->ID, '_billing_dni', $cust_dni);
                        update_user_meta($matched_user->ID, '_caja_customer_dni', $cust_dni);
                        update_user_meta($matched_user->ID, 'billing_dni', $cust_dni);
                    }
                }
            }

            if (!empty($note)) {
                $order->set_customer_note($note);
                $order->update_meta_data('_caja_staff_note', $note);
            }

            // Metadatos de auditoría de Caja POS
            $order->update_meta_data('_caja_pos_sale', 'yes');
            $order->update_meta_data('_caja_pos_mode', $mode);
            $order->update_meta_data('_caja_payment_status', $payment_status);
            if (!empty($card_op)) {
                $order->update_meta_data('_caja_card_op_number', $card_op);
            }
            $order->update_meta_data('_caja_pos_cashier', $cashier_name);
            $order->update_meta_data('_caja_pos_cashier_id', $cashier_id);
            $order->update_meta_data('_created_by_caja_pos', true);

            if ($mode === 'compra_realizada') {
                $order->update_meta_data('_caja_shipping_status', 'entregado');
                $order->update_meta_data('_caja_delivery_type', 'in_situ');
                $order->update_meta_data('_caja_is_pickup', 'no');
                $mode_label = __('Compra In Situ', 'emp-caja');
            } else {
                $order->update_meta_data('_caja_shipping_status', 'no_gestionado');
                $order->update_meta_data('_caja_delivery_type', $delivery_type);

                if (class_exists('WC_Order_Item_Shipping')) {
                    $shipping_item = new WC_Order_Item_Shipping();
                    if ($delivery_type === 'retiro_local') {
                        $order->update_meta_data('_caja_is_pickup', 'yes');
                        $shipping_item->set_method_title(__('Retiro por el local', 'emp-caja'));
                        $shipping_item->set_method_id('local_pickup');
                        $mode_label = __('Retiro en Local', 'emp-caja');
                    } else {
                        $order->update_meta_data('_caja_is_pickup', 'no');
                        $shipping_item->set_method_title(__('Con Envío', 'emp-caja'));
                        $shipping_item->set_method_id('flat_rate');
                        $mode_label = __('Con Envío', 'emp-caja');
                    }
                    $shipping_item->set_total(0);
                    $order->add_item($shipping_item);
                } else {
                    if ($delivery_type === 'retiro_local') {
                        $order->update_meta_data('_caja_is_pickup', 'yes');
                        $mode_label = __('Retiro en Local', 'emp-caja');
                    } else {
                        $order->update_meta_data('_caja_is_pickup', 'no');
                        $mode_label = __('Con Envío', 'emp-caja');
                    }
                }
            }

            $now_ts = time();
            $c_day = Batllie_Caja_Orders::format_datetime('l', $now_ts);
            $c_day_cap = mb_convert_case($c_day, MB_CASE_TITLE, 'UTF-8');
            $c_d_str = Batllie_Caja_Orders::format_datetime('d/m/Y', $now_ts);
            $time_str = Batllie_Caja_Orders::format_datetime('H:i', $now_ts);
            $date_key = Batllie_Caja_Orders::format_datetime('Y-m-d', $now_ts);
            $date_str = Batllie_Caja_Orders::format_datetime('d/m', $now_ts);

            if ($mode === 'compra_realizada') {
                $initial_timeline = array(
                    array(
                        'time'           => $time_str,
                        'date'           => $date_str,
                        'date_formatted' => $c_day_cap . ' ' . $c_d_str,
                        'date_key'       => $date_key,
                        'timestamp'      => $now_ts,
                        'text'           => __('llegó el pedido', 'emp-caja'),
                        'icon'           => '📥',
                        'type'           => 'created'
                    ),
                    array(
                        'time'           => $time_str,
                        'date'           => $date_str,
                        'date_formatted' => $c_day_cap . ' ' . $c_d_str,
                        'date_key'       => $date_key,
                        'timestamp'      => $now_ts,
                        'text'           => __('se confirmó el pago', 'emp-caja'),
                        'icon'           => '💳',
                        'type'           => 'payment'
                    ),
                    array(
                        'time'           => $time_str,
                        'date'           => $date_str,
                        'date_formatted' => $c_day_cap . ' ' . $c_d_str,
                        'date_key'       => $date_key,
                        'timestamp'      => $now_ts,
                        'text'           => __('pedido completado', 'emp-caja'),
                        'icon'           => '✅',
                        'type'           => 'status_comp'
                    )
                );
                $order->update_meta_data('_caja_timeline', $initial_timeline);
            } else {
                $initial_timeline = array(
                    array(
                        'time'           => $time_str,
                        'date'           => $date_str,
                        'date_formatted' => $c_day_cap . ' ' . $c_d_str,
                        'date_key'       => $date_key,
                        'timestamp'      => $now_ts,
                        'text'           => __('llegó el pedido', 'emp-caja'),
                        'icon'           => '📥',
                        'type'           => 'created'
                    ),
                    array(
                        'time'           => $time_str,
                        'date'           => $date_str,
                        'date_formatted' => $c_day_cap . ' ' . $c_d_str,
                        'date_key'       => $date_key,
                        'timestamp'      => $now_ts,
                        'text'           => __('se confirmó el pago', 'emp-caja'),
                        'icon'           => '💳',
                        'type'           => 'payment'
                    ),
                    array(
                        'time'           => $time_str,
                        'date'           => $date_str,
                        'date_formatted' => $c_day_cap . ' ' . $c_d_str,
                        'date_key'       => $date_key,
                        'timestamp'      => $now_ts,
                        'text'           => __('en preparación', 'emp-caja'),
                        'icon'           => '📦',
                        'type'           => 'status_prep'
                    )
                );
                $order->update_meta_data('_caja_timeline', $initial_timeline);
            }

            $order->calculate_totals();

            // Validación B: Si el total de la orden supera el monto umbral configurado, los datos son obligatorios
            $options = Batllie_Caja_Plugin::get_color_settings();
            $threshold = floatval($options['pos_customer_threshold'] ?? 0);
            if ($threshold > 0 && floatval($order->get_total()) >= $threshold) {
                $missing_th = array();
                if (empty($cust_name))    $missing_th[] = __('Nombre y Apellido', 'emp-caja');
                if (empty($cust_dni))     $missing_th[] = __('DNI / CUIT', 'emp-caja');
                if (empty($cust_phone))   $missing_th[] = __('Teléfono', 'emp-caja');
                if (empty($cust_address)) $missing_th[] = __('Dirección', 'emp-caja');

                if (!empty($missing_th)) {
                    $order->delete(true);
                    wp_send_json_error(array(
                        'message' => sprintf(__('Para ventas a partir de $%s es obligatorio ingresar: ', 'emp-caja'), number_format($threshold, 2, ',', '.')) . implode(', ', $missing_th)
                    ));
                    return;
                }
            }

            $order->set_status($order_status);
            $order->save();

            // Descontar inventario de forma segura
            try {
                wc_reduce_stock_levels($order->get_id());
            } catch (Throwable $stk_e) {
                error_log('Caja POS aviso reduciendo stock: ' . $stk_e->getMessage());
            }

            // Registrar en el historial de cambios de stock (_caja_stock_history) de cada producto
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Argentina/Buenos_Aires');
            $now_dt = new DateTime('now', $tz);
            $date_formatted = $now_dt->format('d/m/Y H:i');

            foreach ($stock_movements as $mov) {
                $p = wc_get_product($mov['prod_id']);
                $stock_after = ($p && $p->managing_stock()) ? $p->get_stock_quantity() : null;
                $hist = get_post_meta($mov['prod_id'], '_caja_stock_history', true);
                if (!is_array($hist)) {
                    $hist = array();
                }

                $entry = array(
                    'timestamp'      => time(),
                    'date_formatted' => $date_formatted,
                    'mode'           => 'subtract',
                    'quantity'       => $mov['qty'],
                    'old_stock'      => $mov['stock_before'],
                    'new_stock'      => $stock_after,
                    'note'           => sprintf(__('Venta POS #%d (%s - Pago: %s)', 'emp-caja'), $order->get_id(), $mode_label, ucfirst($payment_status)),
                    'user'           => $cashier_name
                );
                array_unshift($hist, $entry);
                if (count($hist) > 60) {
                    $hist = array_slice($hist, 0, 60);
                }
                update_post_meta($mov['prod_id'], '_caja_stock_history', $hist);
            }

            // Agregar notas de auditoría a la orden
            if ($payment_status === 'pendiente' && $mode === 'pedido_despachar') {
                $order->add_order_note(sprintf(__('⚠️ AVISO CAJA POS: Pedido tomado por %s. Pago PENDIENTE (%s). Cobrar antes o al momento del despacho.', 'emp-caja'), $cashier_name, $order->get_payment_method_title()));
            } else {
                $order->add_order_note(sprintf(__('Venta POS registrada por %s (%s). Método: %s. Estado de cobro: %s.', 'emp-caja'), $cashier_name, $mode_label, $order->get_payment_method_title(), ucfirst($payment_status)));
            }

            // Creación automática de cuenta de cliente nuevo presencial y envío de correo con enlace para definir contraseña y ver pedidos
            $caja_options = get_option('batllie_caja_options', array());
            $auto_account = ($caja_options['pos_auto_account'] ?? '1') === '1';

            if ($auto_account && !empty($cust_email) && is_email($cust_email) && !email_exists($cust_email)) {
                try {
                    $new_user_id = self::create_customer_and_send_welcome($cust_email, $cust_name, $cust_phone, $cust_dni, $cust_address, $order);
                    if ($new_user_id && !is_wp_error($new_user_id)) {
                        $order->set_customer_id($new_user_id);
                        $order->save();
                        if (function_exists('wc_update_new_customer_past_orders')) {
                            wc_update_new_customer_past_orders($new_user_id);
                        }
                        $order->add_order_note(sprintf(__('Cuenta de usuario #%d creada automáticamente para %s y correo de bienvenida/seguimiento enviado.', 'emp-caja'), $new_user_id, $cust_email));
                    }
                } catch (Throwable $e_user) {
                    error_log('Error creando cuenta de cliente desde Caja POS: ' . $e_user->getMessage());
                }
            }

            $formatted_order = class_exists('Batllie_Caja_Orders') ? Batllie_Caja_Orders::format_order($order) : null;

            wp_send_json_success(array(
                'order_id'       => $order->get_id(),
                'order_number'   => $order->get_order_number(),
                'total'          => $order->get_formatted_order_total(),
                'mode'           => $mode,
                'payment_status' => $payment_status,
                'order'          => $formatted_order,
                'message'        => ($mode === 'compra_realizada')
                    ? sprintf(__('¡Venta #%s completada y stock descontado con éxito!', 'emp-caja'), $order->get_order_number())
                    : sprintf(__('¡Pedido #%s enviado a cola de despacho!', 'emp-caja'), $order->get_order_number())
            ));
        } catch (Throwable $e) {
            error_log('Error en ajax_create_pos_order: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array(
                'message' => sprintf(__('Error al procesar la venta: %s', 'emp-caja'), $e->getMessage())
            ));
        }
    }

    /**
     * AJAX: Obtener historial de ventas realizadas desde la Caja POS
     */
    public static function ajax_get_pos_history() {
        self::check_auth();

        $args = array(
            'limit'        => 30,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'meta_key'     => '_caja_pos_sale',
            'meta_value'   => 'yes',
            'status'       => array('any'),
        );

        $orders = wc_get_orders($args);
        $history = array();
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Argentina/Buenos_Aires');

        foreach ($orders as $order) {
            if (!$order || !is_a($order, 'WC_Order')) {
                continue;
            }

            $dt = $order->get_date_created();
            $date_str = $dt ? $dt->setTimezone($tz)->format('d/m/Y H:i') : '';
            $mode = $order->get_meta('_caja_pos_mode') ?: 'compra_realizada';
            $payment_status = $order->get_meta('_caja_payment_status') ?: 'pagado';
            $card_op = $order->get_meta('_caja_card_op_number');
            $note = $order->get_customer_note() ?: $order->get_meta('_caja_staff_note');
            $cashier = $order->get_meta('_caja_pos_cashier');

            $history[] = array(
                'id'             => $order->get_id(),
                'number'         => $order->get_order_number(),
                'date'           => $date_str,
                'mode'           => $mode,
                'payment_method' => $order->get_payment_method_title(),
                'payment_status' => $payment_status,
                'card_op'        => $card_op,
                'total'          => $order->get_formatted_order_total(),
                'total_raw'      => $order->get_total(),
                'status'         => $order->get_status(),
                'status_name'    => wc_get_order_status_name($order->get_status()),
                'note'           => $note,
                'cashier'        => $cashier,
            );
        }

        wp_send_json_success(array('history' => $history));
    }

    /**
     * AJAX: Búsqueda predictiva de clientes existentes (Usuarios y Pedidos previos)
     */
    public static function ajax_search_customers() {
        self::check_auth();

        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            wp_send_json_success(array('customers' => array()));
            return;
        }

        $results = array();
        $seen = array(); // Dedup

        // 1. Buscar en Usuarios de WordPress (clientes registrados)
        $user_query = new WP_User_Query(array(
            'search'         => '*' . esc_attr($term) . '*',
            'search_columns' => array('user_login', 'user_email', 'user_nicename', 'display_name'),
            'number'         => 15,
        ));
        $users = (array) $user_query->get_results();
        foreach ($users as $u) {
            $uid = $u->ID;
            $first_name = get_user_meta($uid, 'billing_first_name', true) ?: (get_user_meta($uid, 'first_name', true) ?: $u->display_name);
            $last_name  = get_user_meta($uid, 'billing_last_name', true) ?: get_user_meta($uid, 'last_name', true);
            $full_name  = trim($first_name . ' ' . $last_name);
            $phone      = (string) get_user_meta($uid, 'billing_phone', true);
            $email      = (string) ($u->user_email ?: get_user_meta($uid, 'billing_email', true));
            $address    = (string) (get_user_meta($uid, 'shipping_address_1', true) ?: get_user_meta($uid, 'billing_address_1', true));
            $dni        = (string) (get_user_meta($uid, '_billing_dni', true) ?: (get_user_meta($uid, 'billing_dni', true) ?: get_user_meta($uid, '_caja_customer_dni', true)));

            if (empty($full_name) || $full_name === 'Administrador' || stripos($full_name, 'Venta Mostrador') !== false) {
                continue;
            }

            $dedup_key = !empty($dni) ? ('dni_' . $dni) : (!empty($email) ? ('email_' . strtolower($email)) : (!empty($phone) ? ('phone_' . preg_replace('/[^0-9]/', '', $phone)) : ('name_' . strtolower($full_name))));
            if (!isset($seen[$dedup_key])) {
                $seen[$dedup_key] = true;
                $results[] = array(
                    'id'      => 'user_' . $uid,
                    'name'    => $full_name,
                    'dni'     => $dni,
                    'phone'   => $phone,
                    'email'   => $email,
                    'address' => $address,
                    'source'  => __('Usuario Registrado', 'emp-caja'),
                );
            }
        }

        // 2. Buscar en Pedidos anteriores de WooCommerce (tanto compras online como mostrador)
        global $wpdb;
        $order_ids = array();

        // A. Búsqueda rápida en wp_postmeta por nombre, DNI, teléfono o email
        $wildcard = '%' . $wpdb->esc_like($term) . '%';
        $meta_keys = "('_billing_first_name', '_billing_last_name', '_billing_email', '_billing_phone', '_billing_dni', '_caja_customer_dni', 'billing_dni')";
        
        $sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN {$meta_keys} AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 30";
        $found_ids = $wpdb->get_col($wpdb->prepare($sql, $wildcard));
        if (!empty($found_ids)) {
            $order_ids = array_merge($order_ids, array_map('intval', $found_ids));
        }

        // B. Si WooCommerce usa HPOS o además queremos abarcar la búsqueda estándar de wc_get_orders
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $hpos_orders = wc_get_orders(array(
                's'       => $term,
                'limit'   => 20,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids'
            ));
            if (!empty($hpos_orders)) {
                $order_ids = array_unique(array_merge($order_ids, $hpos_orders));
            }
        }

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;

            $first_name = $order->get_billing_first_name();
            $last_name  = $order->get_billing_last_name();
            $full_name  = trim($first_name . ' ' . $last_name);

            if (empty($full_name) || $full_name === 'Administrador' || stripos($full_name, 'Venta Mostrador') !== false || stripos($full_name, 'Cliente Mostrador') !== false) {
                continue;
            }

            $dni     = (string) ($order->get_meta('_billing_dni') ?: ($order->get_meta('_caja_customer_dni') ?: ($order->get_meta('billing_dni') ?: '')));
            $phone   = (string) $order->get_billing_phone();
            $email   = (string) $order->get_billing_email();
            $address = (string) ($order->get_shipping_address_1() ?: $order->get_billing_address_1());

            $dedup_key = !empty($dni) ? ('dni_' . $dni) : (!empty($email) ? ('email_' . strtolower($email)) : (!empty($phone) ? ('phone_' . preg_replace('/[^0-9]/', '', $phone)) : ('name_' . strtolower($full_name))));
            if (!isset($seen[$dedup_key])) {
                $seen[$dedup_key] = true;
                $results[] = array(
                    'id'      => 'order_' . $order->get_id(),
                    'name'    => $full_name,
                    'dni'     => $dni,
                    'phone'   => $phone,
                    'email'   => $email,
                    'address' => $address,
                    'source'  => sprintf(__('Pedido #%s', 'emp-caja'), $order->get_order_number()),
                );
            }

            if (count($results) >= 20) {
                break;
            }
        }

        wp_send_json_success(array('customers' => $results));
    }

    /**
     * AJAX: Actualizar datos de cliente registrado desde el POS
     */
    public static function ajax_update_customer() {
        self::check_auth();

        $customer_id_raw = isset($_POST['customer_id']) ? sanitize_text_field($_POST['customer_id']) : '';
        $name            = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $dni             = isset($_POST['dni']) ? sanitize_text_field(wp_unslash($_POST['dni'])) : '';
        $phone           = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $email           = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $address         = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';

        if (strpos($customer_id_raw, 'user_') === 0) {
            $user_id = intval(substr($customer_id_raw, 5));
            if ($user_id > 0) {
                $name_parts = !empty($name) ? explode(' ', trim($name), 2) : array('', '');
                $first_name = $name_parts[0];
                $last_name  = isset($name_parts[1]) ? $name_parts[1] : '';

                $user_data = array('ID' => $user_id);
                if (!empty($first_name)) $user_data['first_name'] = $first_name;
                if (!empty($last_name))  $user_data['last_name']  = $last_name;
                if (!empty($name))       $user_data['display_name'] = $name;
                if (!empty($email) && is_email($email)) {
                    $existing_owner = email_exists($email);
                    if (!$existing_owner || $existing_owner === $user_id) {
                        $user_data['user_email'] = $email;
                    }
                }
                wp_update_user($user_data);

                if (!empty($first_name)) update_user_meta($user_id, 'billing_first_name', $first_name);
                if (!empty($last_name))  update_user_meta($user_id, 'billing_last_name', $last_name);
                if (!empty($phone))      update_user_meta($user_id, 'billing_phone', $phone);
                if (!empty($address)) {
                    update_user_meta($user_id, 'billing_address_1', $address);
                    update_user_meta($user_id, 'shipping_address_1', $address);
                }
                if (!empty($dni)) {
                    update_user_meta($user_id, '_billing_dni', $dni);
                    update_user_meta($user_id, 'billing_dni', $dni);
                    update_user_meta($user_id, '_caja_customer_dni', $dni);
                }
            }
        }

        wp_send_json_success(array('message' => __('Datos del cliente guardados con éxito.', 'emp-caja')));
    }

    /**
     * Crear cuenta de usuario de WooCommerce para cliente nuevo presencial
     */
    public static function create_customer_and_send_welcome($email, $name, $phone, $dni, $address, $order) {
        $name_parts = !empty($name) ? explode(' ', trim($name), 2) : array('Cliente', '');
        $first_name = $name_parts[0];
        $last_name  = isset($name_parts[1]) ? $name_parts[1] : '';

        // Generar username único a partir del email o nombre
        $username_base = sanitize_user(current(explode('@', $email)), true);
        if (empty($username_base)) {
            $username_base = 'cliente';
        }
        $username = $username_base;
        $counter = 1;
        while (username_exists($username)) {
            $username = $username_base . $counter;
            $counter++;
        }

        $random_password = wp_generate_password(24, true, true);
        $userdata = array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $random_password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
            'role'         => 'customer',
        );

        $user_id = wp_insert_user($userdata);
        if (is_wp_error($user_id) || !$user_id) {
            return false;
        }

        // Metadatos de facturación y envío en el perfil de WooCommerce
        if (!empty($first_name)) {
            update_user_meta($user_id, 'billing_first_name', $first_name);
            update_user_meta($user_id, 'shipping_first_name', $first_name);
        }
        if (!empty($last_name)) {
            update_user_meta($user_id, 'billing_last_name', $last_name);
            update_user_meta($user_id, 'shipping_last_name', $last_name);
        }
        if (!empty($phone)) {
            update_user_meta($user_id, 'billing_phone', $phone);
        }
        if (!empty($address)) {
            update_user_meta($user_id, 'billing_address_1', $address);
            update_user_meta($user_id, 'shipping_address_1', $address);
        }
        if (!empty($dni)) {
            update_user_meta($user_id, '_billing_dni', $dni);
            update_user_meta($user_id, '_caja_customer_dni', $dni);
            update_user_meta($user_id, 'billing_dni', $dni);
        }

        // Enviar el correo personalizado de bienvenida con botón para definir contraseña y ver pedidos
        self::send_pos_welcome_email($user_id, $order);

        return $user_id;
    }

    /**
     * Enviar correo personalizado al cliente con diseño de la tienda y enlace para definir contraseña y ver su pedido
     */
    public static function send_pos_welcome_email($user_id, $order) {
        $user = get_userdata($user_id);
        if (!$user) return;

        // Generar clave segura de reseteo de contraseña de WordPress
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return;
        }

        $myaccount_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/mi-cuenta/');
        
        // Link directo al endpoint de restablecer/definir contraseña de WooCommerce
        if (function_exists('wc_get_endpoint_url')) {
            $set_password_url = add_query_arg(
                array(
                    'action' => 'rp',
                    'key'    => $key,
                    'login'  => rawurlencode($user->user_login),
                ),
                wc_get_endpoint_url('lost-password', '', $myaccount_url)
            );
        } else {
            $set_password_url = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user->user_login), 'login');
        }

        $site_name = get_bloginfo('name');
        $order_num = $order ? $order->get_order_number() : '';
        $order_total = $order ? $order->get_formatted_order_total() : '';
        $delivery_type = $order ? $order->get_meta('_caja_delivery_type') : '';
        $is_envio = ($delivery_type === 'con_envio');
        $delivery_label = $is_envio ? __('Envío a domicilio', 'emp-caja') : __('Retiro por el local', 'emp-caja');

        $first_name = $user->first_name ?: $user->display_name;

        $subject = sprintf(__('¡Tu cuenta en %s y el seguimiento de tu compra #%s!', 'emp-caja'), $site_name, $order_num);
        $heading = sprintf(__('¡Gracias por tu compra en %s!', 'emp-caja'), $site_name);

        ob_start();
        ?>
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #334155;">
            <p style="font-size: 17px; font-weight: 600; color: #0f172a; margin-top: 0;">
                <?php printf(__('Hola %s,', 'emp-caja'), esc_html($first_name)); ?>
            </p>
            <p>
                <?php printf(__('¡Muchas gracias por tu compra en nuestro local! Hemos creado tu cuenta en <strong>%s</strong> para que puedas seguir el estado de tu pedido en tiempo real y consultar tu historial de compras cuando lo desees.', 'emp-caja'), esc_html($site_name)); ?>
            </p>

            <?php if ($order): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 20px 0;">
                <div style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700; margin-bottom: 8px;">
                    <?php _e('Resumen de tu compra', 'emp-caja'); ?>
                </div>
                <div style="font-size: 14px; margin-bottom: 5px;">
                    <strong><?php _e('Número de Pedido:', 'emp-caja'); ?></strong> #<?php echo esc_html($order_num); ?>
                </div>
                <div style="font-size: 14px; margin-bottom: 5px;">
                    <strong><?php _e('Total:', 'emp-caja'); ?></strong> <?php echo wp_strip_all_tags($order_total); ?>
                </div>
                <div style="font-size: 14px;">
                    <strong><?php _e('Tipo de Entrega:', 'emp-caja'); ?></strong> <?php echo esc_html($delivery_label); ?>
                </div>
            </div>
            <?php endif; ?>

            <p style="margin: 22px 0 12px 0;">
                <?php _e('Para terminar de activar tu cuenta y ver dónde se encuentra tu paquete, haz clic en el siguiente botón para definir tu contraseña:', 'emp-caja'); ?>
            </p>

            <div style="text-align: center; margin: 26px 0;">
                <a href="<?php echo esc_url($set_password_url); ?>" style="background-color: #10b981; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700; font-size: 15px; display: inline-block; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">
                    <?php _e('Definir mi contraseña y ver mi pedido', 'emp-caja'); ?> &rarr;
                </a>
            </div>

            <p style="font-size: 13px; color: #64748b; margin-top: 24px;">
                <?php _e('Una vez que elijas tu contraseña, accederás automáticamente a tu panel de pedidos para ver el estado de preparación y envío.', 'emp-caja'); ?>
            </p>
            <p style="font-size: 12px; color: #94a3b8; margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                <?php printf(__('Si el botón no funciona, copia y pega el siguiente enlace en tu navegador:<br><a href="%s" style="color: #10b981; word-break: break-all;">%s</a>', 'emp-caja'), esc_url($set_password_url), esc_url($set_password_url)); ?>
            </p>
        </div>
        <?php
        $body_content = ob_get_clean();

        if (function_exists('WC') && WC()->mailer()) {
            $mailer = WC()->mailer();
            $email_content = $mailer->wrap_message($heading, $body_content);
            $mailer->send($user->user_email, $subject, $email_content, "Content-Type: text/html; charset=UTF-8\r\n", array());
        } else {
            $headers = array('Content-Type: text/html; charset=UTF-8');
            wp_mail($user->user_email, $subject, $body_content, $headers);
        }
    }

    /**
     * Auto-login automático cuando el cliente define su contraseña vía enlace de reseteo
     */
    public static function handle_auto_login_on_password_reset($user) {
        if ($user && isset($user->ID)) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
        }
    }

    /**
     * Redirigir al cliente directamente a su panel de pedidos (/mi-cuenta/orders/) tras definir la contraseña
     */
    public static function handle_redirect_to_orders_on_password_reset($redirect_url, $user) {
        if (function_exists('wc_get_endpoint_url') && function_exists('wc_get_page_permalink')) {
            return wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount'));
        }
        return $redirect_url;
    }

    /**
     * Redirección fallback para wp-login.php
     */
    public static function handle_wp_password_reset_redirect($redirect_url, $user) {
        if ($user && isset($user->ID)) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
            if (function_exists('wc_get_endpoint_url') && function_exists('wc_get_page_permalink')) {
                return wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount'));
            }
        }
        return $redirect_url;
    }

}

// Inicializar controladores AJAX
Batllie_Caja_Ajax::init();
