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
            wp_send_json_error(array('message' => __('Permisos insuficientes para registrar ventas.', 'emp-caja')), 403);
        }

        $raw_items = isset($_POST['items']) ? $_POST['items'] : array();
        if (is_string($raw_items)) {
            $raw_items = json_decode(stripslashes($raw_items), true);
        }

        if (empty($raw_items) || !is_array($raw_items)) {
            wp_send_json_error(array('message' => __('El ticket de venta está vacío. Selecciona al menos un producto.', 'emp-caja')));
        }

        $mode           = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'compra_realizada';
        $delivery_type  = isset($_POST['delivery_type']) ? sanitize_text_field($_POST['delivery_type']) : 'con_envio';
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'efectivo';
        $payment_status = isset($_POST['payment_status']) ? sanitize_text_field($_POST['payment_status']) : 'pagado';
        $card_op        = isset($_POST['card_op_number']) ? sanitize_text_field($_POST['card_op_number']) : '';
        $note           = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        $current_user = wp_get_current_user();
        $cashier_name = ($current_user && $current_user->exists()) ? $current_user->display_name : __('Administrador', 'emp-caja');
        $customer_id  = ($current_user && $current_user->exists()) ? $current_user->ID : 0;

        // Determinar estado de la orden en WooCommerce
        // Si es 'compra_realizada': se completa la venta en el acto
        // Si es 'pedido_despachar': pasa a preparación (processing) para figurar en Pedidos en Vivo
        $order_status = ($mode === 'compra_realizada') ? 'completed' : 'processing';

        $order = wc_create_order(array(
            'status'      => $order_status,
            'customer_id' => $customer_id,
        ));

        if (is_wp_error($order) || !$order) {
            wp_send_json_error(array('message' => __('Error al crear la orden en WooCommerce.', 'emp-caja')));
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

        // Datos del cliente / cajero y notas
        $order->set_billing_first_name($cashier_name);
        $order->set_billing_last_name(__('Venta Mostrador', 'emp-caja'));

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
        $order->update_meta_data('_created_by_caja_pos', true);

        if ($mode === 'compra_realizada') {
            $order->update_meta_data('_caja_shipping_status', 'entregado');
            $order->update_meta_data('_caja_delivery_type', 'in_situ');
            $order->update_meta_data('_caja_is_pickup', 'no');
            $mode_label = __('Compra In Situ', 'emp-caja');
        } else {
            $order->update_meta_data('_caja_shipping_status', 'no_gestionado');
            $order->update_meta_data('_caja_delivery_type', $delivery_type);

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
        $order->save();

        // Descontar inventario automáticamente mediante WooCommerce
        wc_reduce_stock_levels($order->get_id());

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

}

// Inicializar controladores AJAX
Batllie_Caja_Ajax::init();
