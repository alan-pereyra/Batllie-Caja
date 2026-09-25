<?php
/**
 * Clase para Gestión de Productos WooCommerce en Caja
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Products {

    /**
     * Listar productos WooCommerce
     */
    public static function get_products($search = '', $category = '', $limit = 50, $page = 1) {
        if (!class_exists('WooCommerce')) {
            return array();
        }

        $query_args = array(
            'status'   => 'publish',
            'limit'    => intval($limit),
            'page'     => intval($page),
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
        );

        if (!empty($search)) {
            $query_args['s'] = sanitize_text_field($search);
        }

        if (!empty($category)) {
            $query_args['category'] = array(sanitize_text_field($category));
        }

        $products = wc_get_products($query_args);
        $formatted = array();

        foreach ($products as $product) {
            $formatted[] = self::format_product($product);
        }

        return $formatted;
    }

    /**
     * Formatear datos de un producto
     */
    public static function format_product($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product) {
            return null;
        }

        // Obtener categorías
        $category_names = array();
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $category_names[] = $term->name;
            }
        }

        // Imagen
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : wc_placeholder_img_src('medium');

        $manage_stock = $product->get_manage_stock();
        $stock_qty = $product->get_stock_quantity();
        $is_low_stock = false;

        if ($manage_stock) {
            if ($stock_qty !== null && $stock_qty <= 0) {
                $stock_badge = 'caja-badge-outofstock';
                $stock_label = __('Agotado', 'emp-caja');
            } elseif ($stock_qty !== null && $stock_qty <= 5) {
                $stock_badge = 'caja-badge-lowstock';
                $stock_label = __('Stock bajo', 'emp-caja');
                $is_low_stock = true;
            } else {
                $stock_badge = 'caja-badge-instock';
                $stock_label = __('En stock', 'emp-caja');
            }
        } else {
            $stock_badge = $product->is_in_stock() ? 'caja-badge-instock' : 'caja-badge-outofstock';
            $stock_label = $product->is_in_stock() ? __('Ilimitado', 'emp-caja') : __('Sin stock', 'emp-caja');
        }

        return array(
            'id'                 => $product->get_id(),
            'name'               => $product->get_name(),
            'sku'                => $product->get_sku() ? $product->get_sku() : __('S/N', 'emp-caja'),
            'price'              => wc_price($product->get_price()),
            'price_raw'          => (float) $product->get_price(),
            'regular_price'      => $product->get_regular_price(),
            'sale_price'         => $product->get_sale_price(),
            'is_on_sale'         => $product->is_on_sale(),
            'categories'         => implode(', ', $category_names),
            'stock_status'       => $product->get_stock_status(),
            'manage_stock'       => $manage_stock,
            'stock_quantity_raw' => $stock_qty !== null ? intval($stock_qty) : null,
            'stock_quantity'     => $stock_qty !== null ? intval($stock_qty) : __('Ilimitado', 'emp-caja'),
            'is_low_stock'       => $is_low_stock,
            'stock_badge'        => $stock_badge,
            'stock_label'        => $stock_label,
            'raw_sku'            => $product->get_sku(),
            'category_ids'       => $product->get_category_ids(),
            'raw_description'    => $product->get_short_description() ?: $product->get_description(),
            'image_url'          => $image_url,
            'description'        => wp_trim_words(strip_tags($product->get_short_description()), 15)
        );
    }

    /**
     * Crear un nuevo producto en WooCommerce
     */
    public static function create_product($data) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('wc_missing', __('WooCommerce no está activo.', 'emp-caja'));
        }

        $name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
        if (empty($name)) {
            return new WP_Error('empty_name', __('El nombre del producto es obligatorio.', 'emp-caja'));
        }

        $regular_price = isset($data['regular_price']) ? wc_format_decimal($data['regular_price']) : '';
        if ($regular_price === '') {
            return new WP_Error('empty_price', __('El precio es obligatorio.', 'emp-caja'));
        }

        $sale_price   = !empty($data['sale_price']) ? wc_format_decimal($data['sale_price']) : '';
        $sku          = isset($data['sku']) ? sanitize_text_field($data['sku']) : '';
        $category_id  = isset($data['category_id']) ? intval($data['category_id']) : 0;
        $manage_stock = isset($data['manage_stock']) && $data['manage_stock'] === 'yes';
        $stock_qty    = isset($data['stock_quantity']) && $manage_stock ? intval($data['stock_quantity']) : null;
        $description  = isset($data['description']) ? wp_kses_post($data['description']) : '';

        // Crear producto simple WC
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status('publish');
        $product->set_regular_price($regular_price);

        if (!empty($sale_price)) {
            $product->set_sale_price($sale_price);
            $product->set_price($sale_price);
        } else {
            $product->set_price($regular_price);
        }

        if (!empty($sku)) {
            $product->set_sku($sku);
        }

        if ($manage_stock) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_qty);
            $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }

        if (!empty($description)) {
            $product->set_short_description($description);
        }

        // Asignar categoría si se especificó
        if ($category_id > 0) {
            $product->set_category_ids(array($category_id));
        }

        $product_id = $product->save();

        if (!$product_id) {
            return new WP_Error('save_error', __('No se pudo guardar el producto en WooCommerce.', 'emp-caja'));
        }

        return self::format_product($product);
    }

    /**
     * Obtener listado de categorías de productos para selects
     */
    public static function get_categories() {
        if (!class_exists('WooCommerce')) {
            return array();
        }

        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ));

        $cats = array();
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if ($term->slug !== 'uncategorized' && $term->slug !== 'sin-categorizar') {
                    $cats[] = array(
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug
                    );
                }
            }
        }

        return $cats;
    }

    /**
     * Actualizar inventario / stock y datos de un producto WooCommerce
     */
    public static function update_stock($product_id, $data) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('wc_missing', __('WooCommerce no está activo.', 'emp-caja'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', __('Producto no encontrado.', 'emp-caja'));
        }

        $mode = isset($data['mode']) ? sanitize_key($data['mode']) : 'add';
        $quantity = isset($data['quantity']) ? intval($data['quantity']) : 0;
        $manage_stock = isset($data['manage_stock']) ? ($data['manage_stock'] === 'yes' || $data['manage_stock'] === true || $data['manage_stock'] === '1') : true;

        $current_stock = $product->get_stock_quantity();
        if ($current_stock === null) {
            $current_stock = 0;
        }

        // Si el modo es 'add' (ingreso de mercadería), se suma la cantidad que ingresó al stock existente.
        // Ejemplo: 15 actuales + 30 ingresadas = 45 total.
        if ($mode === 'add') {
            $new_stock = max(0, $current_stock + $quantity);
        } else {
            // Si el modo es 'set' (recuento / fijar total directo), se establece la cantidad indicada.
            $new_stock = max(0, $quantity);
        }

        $product->set_manage_stock($manage_stock);

        if ($manage_stock) {
            $product->set_stock_quantity($new_stock);
            $product->set_stock_status($new_stock > 0 ? 'instock' : 'outofstock');
        } else {
            if (!empty($data['stock_status'])) {
                $product->set_stock_status(sanitize_key($data['stock_status']));
            }
        }

        // Precios opcionales
        if (isset($data['regular_price']) && $data['regular_price'] !== '') {
            $product->set_regular_price(wc_format_decimal($data['regular_price']));
        }
        if (isset($data['sale_price'])) {
            $sale = wc_format_decimal($data['sale_price']);
            $product->set_sale_price($sale);
            if ($sale !== '' && (float)$sale > 0) {
                $product->set_price($sale);
            } else {
                $product->set_price($product->get_regular_price());
            }
        }

        $product->save();

        if (function_exists('wc_update_product_stock')) {
            wc_update_product_stock($product, $new_stock, 'set');
        }

        return self::format_product($product);
    }

    /**
     * Actualizar datos completos de un producto WooCommerce (Modificación de Producto)
     */
    public static function update_product($product_id, $data) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('wc_missing', __('WooCommerce no está activo.', 'emp-caja'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', __('Producto no encontrado.', 'emp-caja'));
        }

        if (isset($data['name']) && !empty(trim($data['name']))) {
            $product->set_name(sanitize_text_field($data['name']));
        }

        if (isset($data['regular_price']) && $data['regular_price'] !== '') {
            $reg = wc_format_decimal($data['regular_price']);
            $product->set_regular_price($reg);
        }

        if (isset($data['sale_price'])) {
            $sale = wc_format_decimal($data['sale_price']);
            $product->set_sale_price($sale);
            if ($sale !== '' && (float)$sale > 0) {
                $product->set_price($sale);
            } else {
                $product->set_price($product->get_regular_price());
            }
        }

        if (isset($data['sku'])) {
            $product->set_sku(sanitize_text_field($data['sku']));
        }

        if (isset($data['category_id']) && intval($data['category_id']) > 0) {
            $product->set_category_ids(array(intval($data['category_id'])));
        }

        if (isset($data['description'])) {
            $product->set_short_description(wp_kses_post($data['description']));
        }

        if (isset($data['manage_stock'])) {
            $manage_stock = ($data['manage_stock'] === 'yes' || $data['manage_stock'] === true || $data['manage_stock'] === '1');
            $product->set_manage_stock($manage_stock);

            if ($manage_stock && isset($data['stock_quantity'])) {
                $qty = intval($data['stock_quantity']);
                $product->set_stock_quantity($qty);
                $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            }
        }

        $product->save();

        if (isset($qty) && function_exists('wc_update_product_stock')) {
            wc_update_product_stock($product, $qty, 'set');
        }

        return self::format_product($product);
    }
}
