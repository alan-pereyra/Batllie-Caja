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

        // Asegurar que las cajas de empaque oficiales siempre aparezcan en la lista del POS para control de stock
        if (empty($search) && empty($category) && class_exists('Batllie_Caja_Packing')) {
            $official_ids = array_filter(array(
                Batllie_Caja_Packing::get_official_box_id(6),
                Batllie_Caja_Packing::get_official_box_id(12),
            ));
            foreach ($official_ids as $box_id) {
                $already_present = false;
                foreach ($formatted as $item) {
                    if ($item['id'] === $box_id) {
                        $already_present = true;
                        break;
                    }
                }
                if (!$already_present) {
                    $box_prod = wc_get_product($box_id);
                    if ($box_prod && $box_prod->exists() && $box_prod->get_status() !== 'trash') {
                        array_unshift($formatted, self::format_product($box_prod));
                    }
                }
            }
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
        $category_slugs = array();
        $category_ids   = array();
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $category_names[] = $term->name;
                $category_slugs[] = $term->slug;
                $category_ids[]   = (int) $term->term_id;
            }
        }

        // Imagen
        $image_id = $product->get_image_id();
        if (!$image_id && $product->is_type('grouped')) {
            $grouped_box_img = get_post_meta($product->get_id(), '_batllie_grouped_box_image_id', true);
            if ($grouped_box_img) {
                $image_id = absint($grouped_box_img);
            }
        }
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : wc_placeholder_img_src('medium');

        $is_featured = $product->is_featured();
        $catalog_visibility = $product->get_catalog_visibility();
        $product_type = $product->get_type();
        $children_ids = array();
        if ($product->is_type('grouped')) {
            $children_ids = array_map('intval', (array) $product->get_children());
        }

        $is_predefined = get_post_meta($product->get_id(), '_batllie_grouped_is_predefined', true) === 'yes';
        $saved_qtys = $is_predefined ? get_post_meta($product->get_id(), '_batllie_grouped_predefined_quantities', true) : array();
        $predefined_quantities = is_array($saved_qtys) ? $saved_qtys : array();

        $manage_stock = $product->get_manage_stock();
        $stock_qty = $product->get_stock_quantity();
        $is_low_stock = false;

        $dynamic_stock_info = null;
        if (class_exists('Batllie_Caja_Grouped')) {
            $dyn = Batllie_Caja_Grouped::calculate_dynamic_box_stock($product);
            if (!empty($dyn['is_dynamic'])) {
                $dynamic_stock_info = $dyn;
                if ($dyn['stock_quantity'] !== null) {
                    $stock_qty = $dyn['stock_quantity'];
                    $manage_stock = true;
                }
            }
        }

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

        $box_role = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_product_box_role($product->get_id()) : '';
        $packaging_box_id = get_post_meta($product->get_id(), '_batllie_packaging_box_product_id', true);

        // Metadatos de Producto Agrupado (Pack / Caja Batllié)
        $grouped_target_qty       = get_post_meta($product->get_id(), '_batllie_grouped_target_qty', true);
        $grouped_enable_extra_box = get_post_meta($product->get_id(), '_batllie_grouped_enable_extra_box', true);
        $grouped_extra_box_name   = get_post_meta($product->get_id(), '_batllie_grouped_extra_box_name', true);
        $grouped_fixed_price      = get_post_meta($product->get_id(), '_batllie_grouped_fixed_price', true);
        $grouped_pricing_type     = get_post_meta($product->get_id(), '_batllie_grouped_pricing_type', true);
        if (empty($grouped_pricing_type)) {
            $grouped_pricing_type = (!empty($grouped_fixed_price) && floatval($grouped_fixed_price) > 0) ? 'fixed' : 'variable';
        }
        $grouped_fixed_price_display = get_post_meta($product->get_id(), '_batllie_grouped_fixed_price_display', true) ?: 'box';
        $grouped_custom_price_from   = get_post_meta($product->get_id(), '_batllie_grouped_custom_price_from', true);
        $grouped_box_image_id     = get_post_meta($product->get_id(), '_batllie_grouped_box_image_id', true);
        $grouped_box_image_url    = $grouped_box_image_id ? wp_get_attachment_image_url($grouped_box_image_id, 'medium') : '';

        // Atributos y Variaciones si es Producto Variable
        $attributes_data = array();
        $variations_data = array();
        if ($product->is_type('variable')) {
            $raw_attributes = $product->get_attributes();
            foreach ($raw_attributes as $attr_slug => $attr) {
                if (is_a($attr, 'WC_Product_Attribute')) {
                    $options = $attr->is_taxonomy() ? wc_get_product_terms($product->get_id(), $attr->get_name(), array('fields' => 'names')) : $attr->get_options();
                    $attributes_data[] = array(
                        'name'      => wc_attribute_label($attr->get_name()),
                        'slug'      => $attr_slug,
                        'options'   => is_array($options) ? implode(' | ', $options) : (string) $options,
                        'variation' => (bool) $attr->get_variation(),
                    );
                } elseif (is_array($attr)) {
                    $options = isset($attr['options']) ? (is_array($attr['options']) ? implode(' | ', $attr['options']) : (string)$attr['options']) : (isset($attr['value']) ? $attr['value'] : '');
                    $attributes_data[] = array(
                        'name'      => isset($attr['name']) ? $attr['name'] : $attr_slug,
                        'slug'      => $attr_slug,
                        'options'   => $options,
                        'variation' => !empty($attr['is_variation']),
                    );
                }
            }

            $variation_ids = $product->get_children();
            foreach ($variation_ids as $vid) {
                $v = wc_get_product($vid);
                if ($v && $v->is_type('variation')) {
                    $v_img_id = $v->get_image_id();
                    $variations_data[] = array(
                        'id'             => $v->get_id(),
                        'attributes'     => $v->get_attributes(),
                        'regular_price'  => $v->get_regular_price(),
                        'sale_price'     => $v->get_sale_price(),
                        'sku'            => $v->get_sku(),
                        'manage_stock'   => $v->get_manage_stock(),
                        'stock_quantity' => $v->get_stock_quantity() !== null ? intval($v->get_stock_quantity()) : '',
                        'image_id'       => $v_img_id ? intval($v_img_id) : 0,
                        'image_url'      => $v_img_id ? wp_get_attachment_image_url($v_img_id, 'thumbnail') : '',
                        'enabled'        => $v->get_status() === 'publish',
                        'can_share_box'  => get_post_meta($vid, '_batllie_can_share_box', true) ?: 'none',
                        'share_box_max_alfajores' => (get_post_meta($vid, '_batllie_share_box_max_alfajores', true) !== '') ? intval(get_post_meta($vid, '_batllie_share_box_max_alfajores', true)) : 6,
                    );
                }
            }
        }

        return array(
            'id'                       => $product->get_id(),
            'name'                     => $product->get_name(),
            'sku'                      => $product->get_sku() ? $product->get_sku() : __('S/N', 'emp-caja'),
            'price'                    => wc_price($product->get_price()),
            'price_raw'                => (float) $product->get_price(),
            'regular_price'            => ($product->get_regular_price() !== '' && $product->get_regular_price() !== null) 
                                            ? $product->get_regular_price() 
                                            : ($product->is_type('grouped') ? ($grouped_fixed_price ?: get_post_meta($product->get_id(), '_regular_price', true) ?: '') : ''),
            'sale_price'               => $product->get_sale_price(),
            'is_on_sale'               => $product->is_on_sale(),
            'categories'               => implode(', ', $category_names),
            'category_ids'             => !empty($category_ids) ? $category_ids : $product->get_category_ids(),
            'category_slugs'           => $category_slugs,
            'stock_status'             => $product->get_stock_status(),
            'manage_stock'             => $manage_stock,
            'stock_quantity_raw'       => $stock_qty !== null ? intval($stock_qty) : null,
            'stock_quantity'           => $stock_qty !== null ? intval($stock_qty) : __('Ilimitado', 'emp-caja'),
            'is_low_stock'             => $is_low_stock,
            'stock_badge'              => $stock_badge,
            'stock_label'              => $stock_label,
            'raw_sku'                  => $product->get_sku(),
            'category_ids'             => $product->get_category_ids(),
            'raw_description'          => $product->get_short_description() ?: $product->get_description(),
            'image_id'                 => $image_id ? intval($image_id) : 0,
            'image_url'                => $image_url,
            'is_featured'              => (bool) $is_featured,
            'catalog_visibility'       => $catalog_visibility,
            'product_type'             => $product_type,
            'children_ids'             => $children_ids,
            'recommended_ids'          => array_values(array_filter(array_map('intval', (array)(get_post_meta($product->get_id(), '_batllie_cart_recommended_ids', true) ?: $product->get_cross_sell_ids() ?: array())))),
            'is_predefined'            => $is_predefined,
            'predefined_quantities'    => $predefined_quantities,
            'official_box_role'        => $box_role,
            'is_official_box'          => !empty($box_role),
            'can_share_box'            => get_post_meta($product->get_id(), '_batllie_can_share_box', true) ?: 'none',
            'share_box_max_alfajores'  => (get_post_meta($product->get_id(), '_batllie_share_box_max_alfajores', true) !== '') ? intval(get_post_meta($product->get_id(), '_batllie_share_box_max_alfajores', true)) : 6,
            'packaging_box_product_id' => $packaging_box_id ?: 0,
            'grouped_target_qty'       => $grouped_target_qty !== '' ? $grouped_target_qty : '',
            'grouped_enable_extra_box' => $grouped_enable_extra_box === 'yes',
            'grouped_extra_box_name'   => $grouped_extra_box_name,
            'grouped_pricing_type'     => $grouped_pricing_type,
            'grouped_fixed_price'      => $grouped_fixed_price,
            'grouped_fixed_price_display' => $grouped_fixed_price_display,
            'grouped_custom_price_from'   => $grouped_custom_price_from,
            'grouped_box_image_id'     => $grouped_box_image_id ? intval($grouped_box_image_id) : 0,
            'grouped_box_image_url'    => $grouped_box_image_url,
            'attributes'               => $attributes_data,
            'variations'               => $variations_data,
            'dynamic_stock'            => $dynamic_stock_info,
            'is_dynamic_stock'         => !empty($dynamic_stock_info),
            'bottleneck_item'          => !empty($dynamic_stock_info['bottleneck_item']) ? $dynamic_stock_info['bottleneck_item'] : '',
            'description'              => wp_trim_words(strip_tags($product->get_short_description()), 15)
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
        $image_id     = isset($data['image_id']) ? intval($data['image_id']) : 0;
        $featured     = !empty($data['featured']) && ($data['featured'] === 'yes' || $data['featured'] === true || $data['featured'] === '1');
        $visibility   = !empty($data['catalog_visibility']) ? sanitize_key($data['catalog_visibility']) : 'visible';

        // Crear producto según tipo seleccionado (simple, grouped, variable)
        $type = isset($data['product_type']) ? sanitize_key($data['product_type']) : 'simple';
        if ($type === 'grouped') {
            $product = new WC_Product_Grouped();
        } elseif ($type === 'variable') {
            $product = new WC_Product_Variable();
        } else {
            $product = new WC_Product_Simple();
        }
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

        if ($image_id > 0) {
            $product->set_image_id($image_id);
        }

        $product->set_featured($featured);

        if (in_array($visibility, array('visible', 'catalog', 'search', 'hidden'), true)) {
            $product->set_catalog_visibility($visibility);
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

        if (!empty($data['official_box_role']) && class_exists('Batllie_Caja_Packing')) {
            $role = sanitize_key($data['official_box_role']);
            if ($role === 'box_6') {
                Batllie_Caja_Packing::set_official_box_id(6, $product_id);
            } elseif ($role === 'box_12') {
                Batllie_Caja_Packing::set_official_box_id(12, $product_id);
            } elseif ($role === 'bag_large') {
                Batllie_Caja_Packing::set_official_bag_id('large', $product_id);
            } elseif ($role === 'bag_small') {
                Batllie_Caja_Packing::set_official_bag_id('small', $product_id);
            }
        }

        if (isset($data['recommended_ids'])) {
            $rec_ids = is_array($data['recommended_ids']) ? array_values(array_filter(array_map('intval', $data['recommended_ids']))) : array();
            update_post_meta($product_id, '_batllie_cart_recommended_ids', $rec_ids);
            if (method_exists($product, 'set_cross_sell_ids')) {
                $product->set_cross_sell_ids($rec_ids);
                $product->save();
            }
        }

        if (isset($data['can_share_box'])) {
            $can_share = sanitize_key($data['can_share_box']);
            update_post_meta($product_id, '_batllie_can_share_box', $can_share);
        }
        if (isset($data['share_box_max_alfajores'])) {
            $share_max = max(1, min(12, intval($data['share_box_max_alfajores'])));
            update_post_meta($product_id, '_batllie_share_box_max_alfajores', $share_max);
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

        try {
            if (isset($data['product_type'])) {
                $allowed_types = array('simple', 'grouped', 'variable');
                $new_type = sanitize_key($data['product_type']);
                if (in_array($new_type, $allowed_types, true)) {
                    $current_type = $product->get_type();
                    if ($current_type !== $new_type) {
                        // Update the product_type term.
                        wp_set_object_terms($product_id, $new_type, 'product_type');
                        // Clear caches to avoid stale data.
                        clean_post_cache($product_id);
                        if (function_exists('wc_delete_product_transients')) {
                            wc_delete_product_transients($product_id);
                        }
                        wp_cache_delete($product_id, 'posts');
                        wp_cache_delete($product_id, 'post_meta');
                        // Re‑instantiate the product as the correct class.
                        if ($new_type === 'grouped') {
                            $product = new WC_Product_Grouped($product_id);
                        } elseif ($new_type === 'variable') {
                            $product = new WC_Product_Variable($product_id);
                        } else {
                            $product = new WC_Product_Simple($product_id);
                        }
                    }
                }
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

        if (isset($data['image_id'])) {
            $img_id = intval($data['image_id']);
            $product->set_image_id($img_id > 0 ? $img_id : 0);
            if ($product->is_type('grouped')) {
                if ($img_id > 0) {
                    update_post_meta($product_id, '_batllie_grouped_box_image_id', $img_id);
                } else {
                    delete_post_meta($product_id, '_batllie_grouped_box_image_id');
                }
            }
        }

        if (isset($data['featured'])) {
            $is_feat = ($data['featured'] === 'yes' || $data['featured'] === true || $data['featured'] === '1');
            $product->set_featured($is_feat);
        }

        if (isset($data['catalog_visibility'])) {
            $vis = sanitize_key($data['catalog_visibility']);
            if (in_array($vis, array('visible', 'catalog', 'search', 'hidden'), true)) {
                $product->set_catalog_visibility($vis);
            }
        }

        if (isset($data['is_predefined'])) {
            $is_pred = ($data['is_predefined'] === 'yes' || $data['is_predefined'] === true || $data['is_predefined'] === '1') ? 'yes' : 'no';
            update_post_meta($product_id, '_batllie_grouped_is_predefined', $is_pred);
        }

        $is_pred = get_post_meta($product_id, '_batllie_grouped_is_predefined', true) === 'yes';

        $clean_qtys = array();
        $total_units = 0;
        if ($is_pred) {
            if (isset($data['predefined_quantities']) && is_array($data['predefined_quantities'])) {
                foreach ($data['predefined_quantities'] as $child_id => $c_qty) {
                    $c_id = absint($child_id);
                    $c_val = absint($c_qty);
                    if ($c_id > 0 && $c_val > 0) {
                        $clean_qtys[$c_id] = $c_val;
                        $total_units += $c_val;
                    }
                }
                update_post_meta($product_id, '_batllie_grouped_predefined_quantities', $clean_qtys);
            }
        } else {
            delete_post_meta($product_id, '_batllie_grouped_predefined_quantities');
        }

        if ($product->is_type('grouped')) {
            if ($is_pred && !empty($clean_qtys)) {
                // En combo predeterminado, los hijos son únicamente los alfajores con cantidad > 0
                $product->set_children(array_keys($clean_qtys));
            } elseif (isset($data['children'])) {
                $children = is_array($data['children']) ? array_map('absint', $data['children']) : array();
                $product->set_children($children);
            }
        }

        if (isset($data['packaging_box_product_id'])) {
            $p_box = sanitize_text_field($data['packaging_box_product_id']);
            update_post_meta($product_id, '_batllie_packaging_box_product_id', $p_box);
        }

        // Metadatos de Configuración de Caja Batllié (Pack Agrupado)
        if ($is_pred && $total_units > 0) {
            update_post_meta($product_id, '_batllie_grouped_target_qty', $total_units);
        } elseif (isset($data['grouped_target_qty'])) {
            $t_qty = sanitize_text_field($data['grouped_target_qty']);
            if ($t_qty === '') {
                delete_post_meta($product_id, '_batllie_grouped_target_qty');
            } else {
                update_post_meta($product_id, '_batllie_grouped_target_qty', absint($t_qty));
            }
        }

        if (isset($data['grouped_enable_extra_box'])) {
            $enable_extra = ($data['grouped_enable_extra_box'] === 'yes' || $data['grouped_enable_extra_box'] === true || $data['grouped_enable_extra_box'] === '1') ? 'yes' : 'no';
            update_post_meta($product_id, '_batllie_grouped_enable_extra_box', $enable_extra);
        }

        if (isset($data['grouped_extra_box_name'])) {
            $b_name = sanitize_text_field($data['grouped_extra_box_name']);
            if ($b_name === '') {
                delete_post_meta($product_id, '_batllie_grouped_extra_box_name');
            } else {
                update_post_meta($product_id, '_batllie_grouped_extra_box_name', $b_name);
            }
        }

        // Tipo de cálculo de precio de la caja agrupada y sincronización con el Precio Regular de arriba
        if (isset($data['grouped_pricing_type'])) {
            $p_type = sanitize_key($data['grouped_pricing_type']);
            update_post_meta($product_id, '_batllie_grouped_pricing_type', $p_type);

            if ($p_type === 'fixed') {
                $reg_to_use = isset($data['regular_price']) && $data['regular_price'] !== '' ? wc_format_decimal($data['regular_price']) : floatval($product->get_regular_price());
                if ($reg_to_use > 0) {
                    update_post_meta($product_id, '_batllie_grouped_fixed_price', $reg_to_use);
                    update_post_meta($product_id, '_regular_price', $reg_to_use);
                    update_post_meta($product_id, '_price', $reg_to_use);
                }
            } else {
                delete_post_meta($product_id, '_batllie_grouped_fixed_price');
            }
        } elseif ($product->is_type('grouped') && isset($data['regular_price'])) {
            $p_type = get_post_meta($product_id, '_batllie_grouped_pricing_type', true);
            if ($p_type !== 'variable') {
                $reg_to_use = wc_format_decimal($data['regular_price']);
                if ($reg_to_use > 0) {
                    update_post_meta($product_id, '_batllie_grouped_fixed_price', $reg_to_use);
                    update_post_meta($product_id, '_batllie_grouped_pricing_type', 'fixed');
                    update_post_meta($product_id, '_regular_price', $reg_to_use);
                    update_post_meta($product_id, '_price', $reg_to_use);
                }
            }
        } elseif (isset($data['grouped_fixed_price'])) {
            $fix_p = sanitize_text_field($data['grouped_fixed_price']);
            if ($fix_p === '') {
                delete_post_meta($product_id, '_batllie_grouped_fixed_price');
            } else {
                update_post_meta($product_id, '_batllie_grouped_fixed_price', wc_format_decimal($fix_p));
            }
        }

        if (isset($data['grouped_fixed_price_display'])) {
            $disp = sanitize_key($data['grouped_fixed_price_display']);
            update_post_meta($product_id, '_batllie_grouped_fixed_price_display', in_array($disp, array('box', 'distributed'), true) ? $disp : 'box');
        }

        if (isset($data['grouped_custom_price_from'])) {
            $from_p = sanitize_text_field($data['grouped_custom_price_from']);
            if ($from_p === '') {
                delete_post_meta($product_id, '_batllie_grouped_custom_price_from');
            } else {
                update_post_meta($product_id, '_batllie_grouped_custom_price_from', wc_format_decimal($from_p));
            }
        }

        if (isset($data['grouped_box_image_id'])) {
            $b_img = sanitize_text_field($data['grouped_box_image_id']);
            if ($b_img === '' || $b_img === '0') {
                delete_post_meta($product_id, '_batllie_grouped_box_image_id');
            } else {
                update_post_meta($product_id, '_batllie_grouped_box_image_id', absint($b_img));
            }
        }

        // Atributos y Variaciones si es Producto Variable
        if ($product->is_type('variable')) {
            if (isset($data['attributes']) && is_array($data['attributes'])) {
                $product_attributes = array();
                $position = 0;
                foreach ($data['attributes'] as $attr_item) {
                    $attr_name = isset($attr_item['name']) ? sanitize_text_field($attr_item['name']) : '';
                    if (empty($attr_name)) continue;

                    $options_raw = isset($attr_item['options']) ? $attr_item['options'] : '';
                    $options = is_array($options_raw) ? array_map('sanitize_text_field', $options_raw) : array_map('trim', explode('|', sanitize_text_field($options_raw)));
                    $options = array_filter($options);
                    if (empty($options)) continue;

                    $attribute = new WC_Product_Attribute();
                    $attribute->set_name($attr_name);
                    $attribute->set_options($options);
                    $attribute->set_position($position++);
                    $attribute->set_visible(true);
                    $attribute->set_variation(true);
                    $product_attributes[sanitize_title($attr_name)] = $attribute;
                }
                $product->set_attributes($product_attributes);
                $product->save();
            }

            if (isset($data['variations']) && is_array($data['variations'])) {
                foreach ($data['variations'] as $v_data) {
                    $v_id = !empty($v_data['id']) ? absint($v_data['id']) : 0;
                    if (!empty($v_data['delete']) && $v_id > 0) {
                        $v_obj = wc_get_product($v_id);
                        if ($v_obj) {
                            $v_obj->delete(true);
                        }
                        continue;
                    }

                    $variation = $v_id > 0 ? wc_get_product($v_id) : new WC_Product_Variation();
                    if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
                        $variation = new WC_Product_Variation();
                    }
                    $variation->set_parent_id($product_id);
                    $variation->set_status(!empty($v_data['enabled']) && $v_data['enabled'] !== 'no' && $v_data['enabled'] !== false ? 'publish' : 'private');

                    if (isset($v_data['regular_price'])) {
                        $variation->set_regular_price(wc_format_decimal($v_data['regular_price']));
                    }
                    if (isset($v_data['sale_price'])) {
                        $variation->set_sale_price(wc_format_decimal($v_data['sale_price']));
                        if ($v_data['sale_price'] !== '' && (float)$v_data['sale_price'] > 0) {
                            $variation->set_price(wc_format_decimal($v_data['sale_price']));
                        } else {
                            $variation->set_price($variation->get_regular_price());
                        }
                    }
                    if (isset($v_data['sku'])) {
                        $variation->set_sku(sanitize_text_field($v_data['sku']));
                    }
                    if (isset($v_data['manage_stock'])) {
                        $m_stock = ($v_data['manage_stock'] === 'yes' || $v_data['manage_stock'] === true || $v_data['manage_stock'] === '1');
                        $variation->set_manage_stock($m_stock);
                        if ($m_stock && isset($v_data['stock_quantity'])) {
                            $v_qty = intval($v_data['stock_quantity']);
                            $variation->set_stock_quantity($v_qty);
                            $variation->set_stock_status($v_qty > 0 ? 'instock' : 'outofstock');
                        }
                    }
                    if (isset($v_data['image_id'])) {
                        $v_img = absint($v_data['image_id']);
                        $variation->set_image_id($v_img ?: '');
                    }
                    if (isset($v_data['attributes']) && is_array($v_data['attributes'])) {
                        $v_attrs = array();
                        foreach ($v_data['attributes'] as $k => $v) {
                            $clean_k = sanitize_title(str_replace('attribute_', '', $k));
                            $v_attrs[$clean_k] = sanitize_text_field($v);
                        }
                        $variation->set_attributes($v_attrs);
                    }
                    $v_saved_id = $variation->save();
                    if ($v_saved_id) {
                        if (isset($v_data['can_share_box'])) {
                            update_post_meta($v_saved_id, '_batllie_can_share_box', sanitize_key($v_data['can_share_box']));
                        }
                        if (isset($v_data['share_box_max_alfajores'])) {
                            update_post_meta($v_saved_id, '_batllie_share_box_max_alfajores', max(1, min(12, intval($v_data['share_box_max_alfajores']))));
                        }
                    }
                }
                WC_Product_Variable::sync($product_id);
            }
        }

        if (isset($data['official_box_role']) && class_exists('Batllie_Caja_Packing')) {
            $role = sanitize_key($data['official_box_role']);
            if ($role === 'box_6') {
                Batllie_Caja_Packing::set_official_box_id(6, $product_id);
            } elseif ($role === 'box_12') {
                Batllie_Caja_Packing::set_official_box_id(12, $product_id);
            } elseif ($role === 'bag_large') {
                Batllie_Caja_Packing::set_official_bag_id('large', $product_id);
            } elseif ($role === 'bag_small') {
                Batllie_Caja_Packing::set_official_bag_id('small', $product_id);
            } elseif ($role === 'none') {
                if (Batllie_Caja_Packing::get_official_box_id(6) === $product_id) {
                    delete_option(Batllie_Caja_Packing::OPTION_BOX_6_ID);
                }
                if (Batllie_Caja_Packing::get_official_box_id(12) === $product_id) {
                    delete_option(Batllie_Caja_Packing::OPTION_BOX_12_ID);
                }
                if (Batllie_Caja_Packing::get_official_bag_id('large') === $product_id) {
                    delete_option(Batllie_Caja_Packing::OPTION_BAG_LARGE_ID);
                }
                if (Batllie_Caja_Packing::get_official_bag_id('small') === $product_id) {
                    delete_option(Batllie_Caja_Packing::OPTION_BAG_SMALL_ID);
                }
                delete_post_meta($product_id, '_batllie_is_official_box');
            }
        }

        if (isset($data['recommended_ids'])) {
            $rec_ids = is_array($data['recommended_ids']) ? array_values(array_filter(array_map('intval', $data['recommended_ids']), function($id) {
                return $id > 0;
            })) : array();
            update_post_meta($product_id, '_batllie_cart_recommended_ids', $rec_ids);
            if (method_exists($product, 'set_cross_sell_ids')) {
                $product->set_cross_sell_ids($rec_ids);
            }
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

        if (isset($data['can_share_box'])) {
            $can_share = sanitize_key($data['can_share_box']);
            update_post_meta($product_id, '_batllie_can_share_box', $can_share);
        }
        if (isset($data['share_box_max_alfajores'])) {
            $share_max = max(1, min(12, intval($data['share_box_max_alfajores'])));
            update_post_meta($product_id, '_batllie_share_box_max_alfajores', $share_max);
        }

        $product->save();

        if (isset($qty) && function_exists('wc_update_product_stock')) {
            wc_update_product_stock($product, $qty, 'set');
        }

            return self::format_product($product);
        } catch (\Throwable $e) {
            error_log('[emp-caja] Error en update_product: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new WP_Error('update_exception', __('Error al actualizar el producto: ', 'emp-caja') . $e->getMessage());
        }
    }
}
