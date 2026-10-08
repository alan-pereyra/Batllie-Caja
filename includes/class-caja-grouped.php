<?php
/**
 * Controlador de Productos Agrupados con Cantidad Fija / Packs (Cajas)
 * Permite definir una cantidad exacta de unidades por caja (ej: 6 o 12),
 * validar en tiempo real que no se exceda ni falte, calcular el precio dinámicamente
 * y proteger la validación al añadir al carrito.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Grouped {

    private static $current_submission_pack_id = null;
    private static $added_extra_box_for = array();
    private static $calculating_stock = array();
    public static $is_replacing_combo_flavor = false;
    private static $replacement_modal_rendered = false;

    public static function init() {
        // Campo en Administración de WooCommerce (panel de edición de producto -> pestaña Productos Enlazados)
        add_action('woocommerce_product_options_related', array(__CLASS__, 'add_admin_target_qty_field'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_admin_target_qty_field'));
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'save_admin_target_qty_field_object'));

        // Encolar scripts y estilos en la página del producto agrupado
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend_assets'));

        // Inyectar interfaz de control y progreso dentro del formulario del producto agrupado
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_grouped_box_controls'), 15);

        // Validación en el servidor (Seguridad PHP en wp_loaded antes del handler de WooCommerce)
        add_action('wp_loaded', array(__CLASS__, 'validate_grouped_add_to_cart'), 10);

        // Ocultar precio unitario en el encabezado de productos agrupados con caja fija y mostrar 'Desde' en catálogo
        add_filter('woocommerce_grouped_price_html', array(__CLASS__, 'filter_grouped_price_html'), 10, 2);
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'filter_grouped_get_price_html'), 10, 2);

        // Filtrar productos hijos para combos predeterminados (ocultar estrictamente los que tienen 0 unidades)
        add_filter('woocommerce_grouped_children', array(__CLASS__, 'filter_grouped_children'), 20, 2);

        // Sobrescribir la plantilla de productos agrupados para mostrar una lista limpia no personalizable si es combo fijo
        add_filter('woocommerce_locate_template', array(__CLASS__, 'override_grouped_template'), 20, 3);

        // --- GESTIÓN DE CAJA DE EMPAQUE EXTRA Y PRECIOS ---
        // Asociar pack a los ítems elegidos
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'tag_grouped_child_cart_item'), 10, 4);

        // Añadir automáticamente la caja física de empaque cuando se añade el pack
        add_action('woocommerce_add_to_cart', array(__CLASS__, 'handle_extra_box_add_to_cart'), 20, 6);

        // Sincronizar precios (fijos o dinámicos) y eliminar cajas huérfanas
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'sync_and_price_extra_boxes'), 20, 1);

        // Modificar nombre, badge de cortesía, thumbnail, precio y cantidad en el carrito
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'filter_extra_box_cart_name'), 10, 3);
        add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'filter_extra_box_thumbnail'), 10, 3);
        add_filter('woocommerce_cart_item_price', array(__CLASS__, 'filter_extra_box_price_display'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array(__CLASS__, 'filter_extra_box_price_display'), 10, 3);
        add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'filter_extra_box_cart_quantity'), 10, 3);

        // Guardar metadatos en el pedido para checkout, factura y pantalla de Caja POS
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_extra_box_order_line_item'), 10, 4);

        // Prevenir compra directa de la caja de empaque fuera del flujo del pack
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_packaging_direct_purchase'), 10, 6);
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'filter_packaging_is_purchasable'), 10, 2);

        // --- GESTIÓN DE ELIMINACIÓN ATÓMICA Y CANTIDADES EN CARRITO ---
        // Al eliminar cualquier alfajor o la caja, eliminar el combo completo
        add_action('woocommerce_remove_cart_item', array(__CLASS__, 'handle_remove_grouped_pack_combo'), 10, 2);

        // Prevenir modificar cantidades individuales de un pack en el carrito
        add_filter('woocommerce_update_cart_validation', array(__CLASS__, 'validate_cart_item_quantity_update'), 10, 4);

        // Mostrar etiqueta de combo debajo de cada ítem en carrito y checkout
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'render_pack_cart_item_data'), 10, 2);

        // Clases CSS adicionales para identificar combos en el carrito
        add_filter('woocommerce_cart_item_class', array(__CLASS__, 'filter_cart_item_class'), 10, 3);

        // Ocultar botón de eliminar en ítems hijos del combo (solo permitir eliminar en la caja principal)
        add_filter('woocommerce_cart_item_remove_link', array(__CLASS__, 'filter_cart_item_remove_link'), 10, 2);

        // Ocultar precio en productos hijos (el precio solo debe figurar en la caja principal)
        add_filter('woocommerce_cart_item_price', array(__CLASS__, 'filter_child_cart_item_price_display'), 20, 3);
        add_filter('woocommerce_cart_item_subtotal', array(__CLASS__, 'filter_child_cart_item_price_display'), 20, 3);

        // Configurar la imagen personalizada de la caja para Cart Blocks / Store API y carrito
        add_filter('woocommerce_cart_item_product', array(__CLASS__, 'filter_cart_item_product_object'), 10, 2);

        // --- CONTEO Y UNIFICACIÓN DE ÍTEMS EN EL CARRITO ---
        // Filtrar conteo de ítems del carrito para WooCommerce, Store API, Badges y Mini-Cart
        add_filter('woocommerce_cart_contents_count', array(__CLASS__, 'filter_cart_contents_count'), 20, 1);
        add_filter('woocommerce_add_to_cart_fragments', array(__CLASS__, 'filter_cart_fragments'), 25, 1);

        // Encolar assets de medios en wp-admin para selector de imagen de la caja
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_media_assets'));

        // Encolar assets específicos de carrito y checkout
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_cart_assets'));

        // --- SINCRONIZACIÓN DINÁMICA DE STOCK PARA CAJAS Y COMBOS ---
        add_filter('woocommerce_product_get_manage_stock', array(__CLASS__, 'filter_product_manage_stock'), 20, 2);
        add_filter('woocommerce_product_get_stock_quantity', array(__CLASS__, 'filter_product_stock_quantity'), 20, 2);
        add_filter('woocommerce_product_variation_get_stock_quantity', array(__CLASS__, 'filter_product_stock_quantity'), 20, 2);
        add_filter('woocommerce_product_is_in_stock', array(__CLASS__, 'filter_product_is_in_stock'), 20, 2);
        add_filter('woocommerce_product_get_stock_status', array(__CLASS__, 'filter_product_stock_status'), 20, 2);
        add_filter('woocommerce_get_availability', array(__CLASS__, 'filter_product_availability'), 20, 2);
        add_filter('woocommerce_get_availability_text', array(__CLASS__, 'filter_product_availability_text'), 20, 2);
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_dynamic_box_add_to_cart'), 15, 6);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'validate_dynamic_box_in_cart'));

        // --- ENDPOINTS AJAX Y MODAL PARA REEMPLAZO RÁPIDO DE SABORES AGOTADOS EN COMBOS (ALTERNATIVA A) ---
        add_action('wp_ajax_emp_caja_replace_combo_flavor', array(__CLASS__, 'ajax_replace_combo_flavor'));
        add_action('wp_ajax_nopriv_emp_caja_replace_combo_flavor', array(__CLASS__, 'ajax_replace_combo_flavor'));
        add_action('wp_ajax_emp_caja_remove_combo_pack', array(__CLASS__, 'ajax_remove_combo_pack'));
        add_action('wp_ajax_nopriv_emp_caja_remove_combo_pack', array(__CLASS__, 'ajax_remove_combo_pack'));

        add_action('woocommerce_before_cart', array(__CLASS__, 'render_cart_replacement_warning'), 5);
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'render_cart_replacement_warning'), 5);
        add_action('woocommerce_after_cart', array(__CLASS__, 'render_combo_replacement_modal'), 30);
        add_action('woocommerce_after_checkout_form', array(__CLASS__, 'render_combo_replacement_modal'), 30);
        add_action('wp_footer', array(__CLASS__, 'render_combo_replacement_modal'), 50);
    }

    /**
     * Obtener el precio fijo configurado para un producto agrupado (si existe)
     */
    public static function get_fixed_price($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product || !$product->is_type('grouped')) {
            return 0;
        }

        $pricing_type = get_post_meta($product->get_id(), '_batllie_grouped_pricing_type', true);
        if ($pricing_type === 'variable') {
            return 0;
        }

        $val = get_post_meta($product->get_id(), '_batllie_grouped_fixed_price', true);
        if ($val !== '' && is_numeric($val) && floatval($val) > 0) {
            return floatval($val);
        }

        // Si no está fijado en _batllie_grouped_fixed_price pero hay _regular_price
        $reg = get_post_meta($product->get_id(), '_regular_price', true);
        if ($reg !== '' && is_numeric($reg) && floatval($reg) > 0 && $pricing_type !== 'variable') {
            return floatval($reg);
        }

        return 0;
    }

    /**
     * Obtener el modo de desglose del precio fijo ('box' o 'distributed')
     */
    public static function get_fixed_price_display_mode($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product) {
            return 'box';
        }

        $mode = get_post_meta($product->get_id(), '_batllie_grouped_fixed_price_display', true);
        return in_array($mode, array('box', 'distributed'), true) ? $mode : 'box';
    }

    /**
     * Calcular o recuperar el precio para la página principal y catálogo:
     * Si tiene precio fijo asignado, devuelve ese precio exacto.
     * Si no, devuelve el precio manual o (Cantidad fija) × (Alfajor más económico).
     */
    public static function get_price_from($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product || !$product->is_type('grouped')) {
            return 0;
        }

        // Si tiene precio fijo configurado, ese es el valor exacto
        $fixed_price = self::get_fixed_price($product);
        if ($fixed_price > 0) {
            return $fixed_price;
        }

        $product_id = $product->get_id();

        // 1. Revisar si hay un precio manual fijado por el administrador
        $custom_from = get_post_meta($product_id, '_batllie_grouped_custom_price_from', true);
        if ($custom_from !== '' && is_numeric($custom_from) && floatval($custom_from) > 0) {
            return floatval($custom_from);
        }

        // 2. Calcular automáticamente: cantidad fija de unidades * precio de la opción más barata
        $target_qty = self::get_target_qty($product);
        if ($target_qty <= 0) {
            $target_qty = 6;
        }

        $child_ids = $product->get_children();
        if (empty($child_ids)) {
            return 0;
        }

        $min_price = null;
        foreach ($child_ids as $child_id) {
            $child = wc_get_product($child_id);
            if ($child && $child->is_purchasable()) {
                $p = floatval($child->get_price());
                if ($p > 0 && ($min_price === null || $p < $min_price)) {
                    $min_price = $p;
                }
            }
        }

        if ($min_price !== null && $min_price > 0) {
            return $target_qty * $min_price;
        }

        return 0;
    }

    /**
     * Mostrar '$ [precio]' o 'Desde $ [precio]' en la página principal y catálogo,
     * y mantener oculto el precio unitario en la cabecera del producto individual.
     */
    public static function filter_grouped_price_html($price, $product) {
        global $post;

        // Ocultar únicamente en la cabecera principal de la ficha individual del producto
        if (is_product() && $post && is_a($product, 'WC_Product') && $product->get_id() === $post->ID) {
            return '';
        }

        if (!$product || !is_a($product, 'WC_Product') || !$product->is_type('grouped')) {
            return $price;
        }

        // Si tiene precio fijo, mostrar el importe directo sin el prefijo "Desde"
        $fixed_price = self::get_fixed_price($product);
        if ($fixed_price > 0) {
            return '<span class="batllie-grouped-fixed-price">' . wc_price($fixed_price) . '</span>';
        }

        $price_from = self::get_price_from($product);
        if ($price_from > 0) {
            return '<span class="batllie-grouped-price-from"><span class="batllie-price-from-label">' . esc_html__('Desde ', 'emp-caja') . '</span>' . wc_price($price_from) . '</span>';
        }

        return $price;
    }

    /**
     * Filtro complementario para llamadas directas a get_price_html() en plantillas/bloques
     */
    public static function filter_grouped_get_price_html($price, $product) {
        if ($product && is_a($product, 'WC_Product') && $product->is_type('grouped')) {
            return self::filter_grouped_price_html($price, $product);
        }
        return $price;
    }

    /**
     * Detección inteligente de la cantidad fija de unidades según el título del producto
     * Soporta: "x 6", "x 6u", "x6", "x6u", "x 12", "x 12u", "de 6", "de 6u", "6 unidades", "Caja...", etc.
     */
    public static function detect_qty_from_title($title) {
        if (empty($title)) {
            return 0;
        }

        // 1. Patrón con x, de, pack, caja (ej: x 6u, x 6 u, x6, x6u, de 6, caja de 12)
        if (preg_match('/(?:\bx|\bde|\bpack|\bcaja)\s*(\d{1,2})\s*(?:u(?:nidades?)?)?(?:\b|$)/i', $title, $m)) {
            return absint($m[1]);
        }

        // 2. Patrón de número seguido de 'u' o 'unidades' (ej: 6u, 6 unidades, 12u)
        if (preg_match('/\b(\d{1,2})\s*(?:u(?:nidades?)?|unidades)\b/i', $title, $m)) {
            return absint($m[1]);
        }

        // 3. Fallback inteligente: si contiene la palabra "caja", predeterminar 6
        if (stripos($title, 'caja') !== false) {
            return 6;
        }

        return 0;
    }

    /**
     * Comprobar si un producto agrupado o caja está configurado como un combo predeterminado / fijo
     */
    public static function is_predefined_combo($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product) {
            return false;
        }
        $is_pred = get_post_meta($product->get_id(), '_batllie_grouped_is_predefined', true);
        if ($is_pred === 'no') {
            return false;
        }
        if ($is_pred === 'yes') {
            return true;
        }
        // Fallback solo si nunca se configuró explícitamente el meta _batllie_grouped_is_predefined
        $qtys = get_post_meta($product->get_id(), '_batllie_grouped_predefined_quantities', true);
        return (!empty($qtys) && is_array($qtys));
    }

    /**
     * Obtener el mapa de cantidades predefinidas por producto hijo [child_id => qty]
     */
    public static function get_predefined_quantities($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product) {
            return array();
        }
        if (!self::is_predefined_combo($product)) {
            return array();
        }
        $qtys = get_post_meta($product->get_id(), '_batllie_grouped_predefined_quantities', true);
        if (!is_array($qtys)) {
            return array();
        }
        $clean_qtys = array();
        foreach ($qtys as $cid => $q) {
            $cp = wc_get_product($cid);
            if ($cp && $cp->is_type('simple')) {
                $clean_qtys[$cid] = $q;
            }
        }
        return $clean_qtys;
    }

    /**
     * Filtrar productos hijos de agrupados para combos predeterminados (excluir unidades <= 0 y productos no simples)
     */
    public static function filter_grouped_children($children, $product) {
        if (!$product || !is_a($product, 'WC_Product') || !$product->is_type('grouped')) {
            return $children;
        }

        // Excluir cualquier producto que no sea de tipo simple
        $only_simple = array();
        foreach ((array) $children as $child_id) {
            $cid = absint($child_id);
            $cp = wc_get_product($cid);
            if ($cp && $cp->is_type('simple')) {
                $only_simple[] = $cid;
            }
        }

        if (self::is_predefined_combo($product)) {
            $predefined = self::get_predefined_quantities($product);
            if (!empty($predefined) && is_array($predefined)) {
                $filtered = array();
                foreach ($only_simple as $cid) {
                    if (isset($predefined[$cid]) && absint($predefined[$cid]) > 0) {
                        $filtered[] = $cid;
                    }
                }
                return $filtered;
            }
        }

        return $only_simple;
    }

    /**
     * Sobrescribir la plantilla single-product/add-to-cart/grouped.php para productos agrupados
     */
    public static function override_grouped_template($template, $template_name, $template_path) {
        if ($template_name === 'single-product/add-to-cart/grouped.php') {
            $custom_template = EMP_CAJA_PATH . 'templates/woocommerce/single-product/add-to-cart/grouped.php';
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        }
        return $template;
    }

    /**
     * Obtener la cantidad requerida para un producto agrupado o caja
     * 1. Si es combo predeterminado, suma de las cantidades de cada producto
     * 2. Consulta post meta `_batllie_grouped_target_qty`
     * 3. Si no está definido, detecta automáticamente del título
     * 4. Si es un producto agrupado en Batllié, por defecto es una caja de 6 unidades
     */
    public static function get_target_qty($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product) {
            return 0;
        }

        // Si es un combo predeterminado, la cantidad objetivo es la suma exacta de sus unidades
        if (self::is_predefined_combo($product)) {
            $predefined = self::get_predefined_quantities($product);
            if (!empty($predefined)) {
                $sum = array_sum(array_map('absint', $predefined));
                if ($sum > 0) {
                    return $sum;
                }
            }
        }

        $post_id = $product->get_id();
        $meta_val = get_post_meta($post_id, '_batllie_grouped_target_qty', true);

        if ($meta_val !== '' && is_numeric($meta_val)) {
            $meta_num = absint($meta_val);
            if ($meta_num > 0) {
                return $meta_num;
            }
        }

        // Detección automática por título (ej: "Caja Batllié Degustación x 6 unidades" o "Caja degustación gourmet x 6u")
        $title = $product->get_name();
        $detected = self::detect_qty_from_title($title);
        if ($detected > 0) {
            return $detected;
        }

        return $product->is_type('grouped') ? 6 : 0;
    }

    /**
     * Calcular stock dinámico para cajas, packs y combos basado en sus alfajores componentes
     * y el stock de la caja física oficial (detectando el cuello de botella).
     *
     * @param WC_Product|int $product
     * @return array
     */
    public static function calculate_dynamic_box_stock($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product) {
            return array('is_dynamic' => false);
        }

        $product_id = $product->get_id();

        // Evitar recursión infinita
        if (isset(self::$calculating_stock[$product_id])) {
            return array('is_dynamic' => false);
        }

        $is_grouped    = $product->is_type('grouped');
        $is_predefined = self::is_predefined_combo($product);
        $predefined    = self::get_predefined_quantities($product);
        $target_qty    = self::get_target_qty($product);
        $enable_box    = get_post_meta($product_id, '_batllie_grouped_enable_extra_box', true) === 'yes';
        $p_box_id      = get_post_meta($product_id, '_batllie_packaging_box_product_id', true);

        // Si no es un producto agrupado ni tiene configuración de pack/combo/caja, no es dinámico
        if (!$is_grouped && !$is_predefined && empty($predefined) && !$enable_box && empty($p_box_id)) {
            return array('is_dynamic' => false);
        }

        self::$calculating_stock[$product_id] = true;

        $bottleneck_item = '';
        $min_possible    = PHP_INT_MAX;
        $has_components  = false;

        // 1. Combo predefinido (cantidades fijas por sabor de alfajor)
        if (!empty($predefined) && is_array($predefined)) {
            $has_components = true;
            foreach ($predefined as $child_id => $qty_needed) {
                $c_id = absint($child_id);
                $q_need = max(1, absint($qty_needed));
                if (!$c_id) continue;

                $child = wc_get_product($c_id);
                if (!$child || !$child->is_purchasable() || !$child->is_in_stock()) {
                    $min_possible = 0;
                    $bottleneck_item = $child ? sprintf(__('%s (Agotado)', 'emp-caja'), $child->get_name()) : __('Alfajor faltante', 'emp-caja');
                    break;
                }

                if ($child->managing_stock()) {
                    $c_stock = $child->get_stock_quantity();
                    if ($c_stock !== null) {
                        $possible = (int) floor($c_stock / $q_need);
                        if ($possible < $min_possible) {
                            $min_possible = $possible;
                            $bottleneck_item = sprintf('%s (%d u. en stock, permite %d cajas)', $child->get_name(), $c_stock, $possible);
                        }
                    }
                }
            }
        } elseif ($is_grouped) {
            // 2. Caja agrupada personalizable (el cliente elige entre los sabores hijos disponibles)
            $children = (array) $product->get_children();
            if (!empty($children)) {
                $has_components = true;
                $target = $target_qty > 0 ? $target_qty : 6;
                $total_in_stock = 0;
                $has_managed = false;
                $any_available = false;

                foreach ($children as $c_id) {
                    $child = wc_get_product($c_id);
                    if (!$child || !$child->is_purchasable() || !$child->is_in_stock()) {
                        continue;
                    }
                    $child_vis = method_exists($child, 'get_catalog_visibility') ? $child->get_catalog_visibility() : 'visible';
                    if ($child_vis === 'hidden' || !$child->is_visible()) {
                        continue;
                    }
                    $any_available = true;
                    if ($child->managing_stock()) {
                        $has_managed = true;
                        $total_in_stock += max(0, (int) $child->get_stock_quantity());
                    }
                }

                if (!$any_available) {
                    $min_possible = 0;
                    $bottleneck_item = __('Todos los alfajores están agotados', 'emp-caja');
                } elseif ($has_managed) {
                    $possible = (int) floor($total_in_stock / $target);
                    if ($possible < $min_possible) {
                        $min_possible = $possible;
                        $bottleneck_item = sprintf(__('Stock total de alfajores (%d u. disponibles para %d cajas)', 'emp-caja'), $total_in_stock, $possible);
                    }
                }
            }
        }

        // 3. Evaluar stock de la caja física oficial correspondiente
        $box_id_to_check = 0;
        if (!empty($p_box_id)) {
            $box_id_to_check = absint($p_box_id);
        } else {
            $cap = ($target_qty >= 12) ? 12 : 6;
            if (class_exists('Batllie_Caja_Packing')) {
                $box_id_to_check = Batllie_Caja_Packing::get_official_box_id($cap);
            }
        }

        if ($box_id_to_check > 0 && $box_id_to_check !== $product_id) {
            $box_prod = wc_get_product($box_id_to_check);
            if ($box_prod && $box_prod->managing_stock()) {
                $b_stock = $box_prod->get_stock_quantity();
                if ($b_stock !== null) {
                    $has_components = true;
                    if ($b_stock < $min_possible) {
                        $min_possible = (int) $b_stock;
                        $bottleneck_item = sprintf('%s (%d u. disponibles)', $box_prod->get_name(), $b_stock);
                    }
                }
            }
        }

        // 4. Evaluar si el producto padre tiene límite de stock manual configurado
        $manual_stock = null;
        $raw_stock_meta = get_post_meta($product_id, '_stock', true);
        $managing_stock = get_post_meta($product_id, '_manage_stock', true) === 'yes';
        if ($managing_stock && $raw_stock_meta !== '' && is_numeric($raw_stock_meta)) {
            $manual_stock = (int) $raw_stock_meta;
            if ($manual_stock < $min_possible) {
                $min_possible = $manual_stock;
                $bottleneck_item = sprintf(__('Límite de stock fijado en el producto (%d u.)', 'emp-caja'), $manual_stock);
            }
        }

        unset(self::$calculating_stock[$product_id]);

        if (!$has_components && $manual_stock === null) {
            return array('is_dynamic' => false);
        }

        $calculated = ($min_possible !== PHP_INT_MAX) ? max(0, $min_possible) : $manual_stock;
        $status = ($calculated !== null && $calculated <= 0) ? 'outofstock' : 'instock';

        return array(
            'is_dynamic'      => true,
            'stock_quantity'  => $calculated,
            'stock_status'    => $status,
            'bottleneck_item' => $bottleneck_item,
            'manual_stock'    => $manual_stock,
        );
    }

    /**
     * Forzar que productos con componentes se consideren gestionados por stock si tienen componentes
     */
    public static function filter_product_manage_stock($manage_stock, $product) {
        if (!$product) return $manage_stock;
        $info = self::calculate_dynamic_box_stock($product);
        if (!empty($info['is_dynamic'])) {
            return true;
        }
        return $manage_stock;
    }

    /**
     * Filtro del stock numérico de WooCommerce
     */
    public static function filter_product_stock_quantity($stock, $product) {
        if (!$product) return $stock;
        $info = self::calculate_dynamic_box_stock($product);
        if (!empty($info['is_dynamic']) && $info['stock_quantity'] !== null) {
            return $info['stock_quantity'];
        }
        return $stock;
    }

    /**
     * Filtro de disponibilidad booleana en stock
     */
    public static function filter_product_is_in_stock($is_in_stock, $product) {
        if (!$product) return $is_in_stock;
        $info = self::calculate_dynamic_box_stock($product);
        if (!empty($info['is_dynamic'])) {
            return $info['stock_status'] === 'instock';
        }
        return $is_in_stock;
    }

    /**
     * Filtro del estado de stock en texto ('instock' o 'outofstock')
     */
    public static function filter_product_stock_status($status, $product) {
        if (!$product) return $status;
        $info = self::calculate_dynamic_box_stock($product);
        if (!empty($info['is_dynamic'])) {
            return $info['stock_status'];
        }
        return $status;
    }

    /**
     * Filtro del HTML de disponibilidad ('11 disponibles' / 'Agotado')
     */
    public static function filter_product_availability($availability, $product) {
        if (!$product) return $availability;
        $info = self::calculate_dynamic_box_stock($product);
        if (!empty($info['is_dynamic'])) {
            if ($info['stock_status'] === 'outofstock' || $info['stock_quantity'] === 0) {
                return array(
                    'availability' => __('Agotado', 'emp-caja'),
                    'class'        => 'out-of-stock'
                );
            }
            if ($info['stock_quantity'] !== null) {
                return array(
                    'availability' => sprintf(__('%d disponibles', 'emp-caja'), $info['stock_quantity']),
                    'class'        => 'in-stock'
                );
            }
        }
        return $availability;
    }

    /**
     * Filtro del texto plano de disponibilidad
     */
    public static function filter_product_availability_text($text, $product) {
        if (!$product) return $text;
        $info = self::calculate_dynamic_box_stock($product);
        if (!empty($info['is_dynamic'])) {
            if ($info['stock_status'] === 'outofstock' || $info['stock_quantity'] === 0) {
                return __('Agotado', 'emp-caja');
            }
            if ($info['stock_quantity'] !== null) {
                return sprintf(__('%d disponibles', 'emp-caja'), $info['stock_quantity']);
            }
        }
        return $text;
    }

    /**
     * Validar adición al carrito para que nunca se añada más del stock dinámico disponible
     */
    public static function validate_dynamic_box_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array()) {
        $product = wc_get_product($product_id);
        if (!$product) return $passed;

        $info = self::calculate_dynamic_box_stock($product);
        if (!empty($info['is_dynamic'])) {
            if ($info['stock_status'] === 'outofstock' || ($info['stock_quantity'] !== null && $info['stock_quantity'] <= 0)) {
                wc_add_notice(sprintf(__('Lo sentimos, "%s" se encuentra agotado en este momento.', 'emp-caja'), $product->get_name()), 'error');
                return false;
            }

            if ($info['stock_quantity'] !== null) {
                $in_cart = 0;
                if (function_exists('WC') && WC()->cart) {
                    foreach (WC()->cart->get_cart() as $cart_item) {
                        if ($cart_item['product_id'] == $product_id) {
                            $in_cart += $cart_item['quantity'];
                        }
                    }
                }
                $total_requested = $in_cart + $quantity;
                if ($total_requested > $info['stock_quantity']) {
                    wc_add_notice(
                        sprintf(
                            __('No puedes añadir esa cantidad al carrito. Solo hay %d disponibles para "%s"%s.', 'emp-caja'),
                            $info['stock_quantity'],
                            $product->get_name(),
                            !empty($info['bottleneck_item']) ? (' (' . sprintf(__('limitado por: %s', 'emp-caja'), $info['bottleneck_item']) . ')') : ''
                        ),
                        'error'
                    );
                    return false;
                }
            }
        }
        return $passed;
    }

    /**
     * Validar en el carrito y checkout que ningún pack exceda el stock dinámico en vivo
     */
    public static function validate_dynamic_box_in_cart() {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $cart_totals = array();
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            if (!isset($cart_totals[$product_id])) {
                $cart_totals[$product_id] = 0;
            }
            $cart_totals[$product_id] += $cart_item['quantity'];
        }

        foreach ($cart_totals as $product_id => $total_qty_in_cart) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $info = self::calculate_dynamic_box_stock($product);
            if (!empty($info['is_dynamic'])) {
                $avail = $info['stock_quantity'];
                if ($avail !== null && $total_qty_in_cart > $avail) {
                    if ($avail <= 0) {
                        if (self::has_cart_combo_replacements_needed()) {
                            // Gestionado mediante el modal interactivo de reemplazo rápido (Alternativa A)
                            continue;
                        }
                        wc_add_notice(
                            sprintf(
                                __('"%s" se ha agotado debido a la disponibilidad de sus componentes y no puede comprarse en este momento.', 'emp-caja'),
                                $product->get_name()
                            ),
                            'error'
                        );
                    } else {
                        wc_add_notice(
                            sprintf(
                                __('Solo hay %d unidades disponibles de "%s"%s. Por favor ajusta la cantidad en tu carrito.', 'emp-caja'),
                                $avail,
                                $product->get_name(),
                                !empty($info['bottleneck_item']) ? (' (' . sprintf(__('limitado por: %s', 'emp-caja'), $info['bottleneck_item']) . ')') : ''
                            ),
                            'error'
                        );
                    }
                }
            }
        }
    }

    /**
     * Campo en la pestaña "Productos enlazados" en wp-admin
     */
    public static function add_admin_target_qty_field() {
        global $post, $product_object;
        $post_id = $post ? $post->ID : ($product_object ? $product_object->get_id() : 0);
        $current_val      = get_post_meta($post_id, '_batllie_grouped_target_qty', true);
        $enable_extra_box = get_post_meta($post_id, '_batllie_grouped_enable_extra_box', true);
        $extra_box_name   = get_post_meta($post_id, '_batllie_grouped_extra_box_name', true);

        // Autodetección como placeholder sugerido
        $title     = $post ? get_the_title($post_id) : ($product_object ? $product_object->get_name() : '');
        $suggested = self::detect_qty_from_title($title);
        $default_box_name = $title ? (stripos($title, 'caja') !== false ? $title : sprintf(__('Caja %s', 'emp-caja'), $title)) : __('Caja Batllié Degustación x 6 unidades', 'emp-caja');

        echo '<div class="options_group show_if_grouped show_if_simple" style="background:#f0fdf4; padding:16px 18px; border-left:4px solid #10b981; margin:15px 0; border-radius:4px;">';
        echo '<p style="margin:0 0 12px 0; font-weight:700; color:#065f46; font-size:14px; display:flex; align-items:center; gap:8px;"><span style="font-size:18px;">📦</span> ' . __('Configuración de Caja Batllié (Pack Agrupado)', 'emp-caja') . '</p>';

        $dynamic_info = self::calculate_dynamic_box_stock($post_id);
        if (!empty($dynamic_info['is_dynamic'])) {
            $calc_stock = $dynamic_info['stock_quantity'];
            $b_item = !empty($dynamic_info['bottleneck_item']) ? $dynamic_info['bottleneck_item'] : '';
            $status_color = ($calc_stock > 0) ? '#065f46' : '#991b1b';
            $bg_color = ($calc_stock > 0) ? '#d1fae5' : '#fee2e2';
            $border_color = ($calc_stock > 0) ? '#34d399' : '#f87171';

            echo '<div style="background:' . $bg_color . '; border:2px solid ' . $border_color . '; border-radius:6px; padding:12px 14px; margin-bottom:15px;">';
            echo '<div style="font-weight:700; color:' . $status_color . '; font-size:14px; display:flex; align-items:center; justify-content:space-between;">';
            echo '<span>⚡ ' . esc_html__('Stock Dinámico Sincronizado:', 'emp-caja') . ' ' . ($calc_stock > 0 ? sprintf(__('%d unidades disponibles', 'emp-caja'), $calc_stock) : esc_html__('¡AGOTADO (0 u.)!', 'emp-caja')) . '</span>';
            echo '<span style="font-size:11px; font-weight:600; background:#fff; color:#374151; padding:2px 8px; border-radius:12px; border:1px solid ' . $border_color . ';">' . esc_html__('Sincronizado en vivo', 'emp-caja') . '</span>';
            echo '</div>';
            if ($b_item) {
                echo '<p style="margin:6px 0 0 0; font-size:12px; color:#1f2937;">' . sprintf(esc_html__('⚠️ Cuello de botella actual: %s', 'emp-caja'), '<strong>' . esc_html($b_item) . '</strong>') . '</p>';
            }
            echo '<p style="margin:4px 0 0 0; font-size:11px; color:#4b5563;">' . esc_html__('El stock visible en la tienda y permitido para compra se recalcula automáticamente según el stock disponible de sus alfajores componentes, la caja física de empaque y el stock fijado.', 'emp-caja') . '</p>';
            echo '</div>';
        }

        woocommerce_wp_text_input(array(
            'id'          => '_batllie_grouped_target_qty',
            'label'       => __('Cantidad fija de la caja', 'emp-caja'),
            'placeholder' => $suggested > 0 ? sprintf(__('Autodetectado: %d unidades', 'emp-caja'), $suggested) : __('Ej: 6', 'emp-caja'),
            'description' => __('Número exacto de unidades que el cliente debe elegir para poder comprar la caja (ej: 6 para caja de 6, 12 para caja de 12). Si se deja vacío, el sistema detectará automáticamente la cantidad según el título del producto.', 'emp-caja'),
            'desc_tip'    => true,
            'type'        => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min'  => '0',
            ),
            'value'       => $current_val,
        ));

        echo '<div style="margin: 14px 0 12px 0; border-top: 1px dashed #a7f3d0;"></div>';

        woocommerce_wp_checkbox(array(
            'id'          => '_batllie_grouped_enable_extra_box',
            'label'       => __('¿Incluir caja en el carrito?', 'emp-caja'),
            'description' => __('Añadir automáticamente el producto de empaque (la caja física) a costo $0 al carrito y al pedido cuando el cliente agregue este pack.', 'emp-caja'),
            'value'       => $enable_extra_box === 'yes' ? 'yes' : 'no',
            'cbvalue'     => 'yes',
        ));

        woocommerce_wp_text_input(array(
            'id'          => '_batllie_grouped_extra_box_name',
            'label'       => __('Nombre de la caja adicional', 'emp-caja'),
            'placeholder' => sprintf(__('Ej: %s', 'emp-caja'), esc_attr($default_box_name)),
            'description' => __('Nombre con el que figurará la caja física en el carrito, pedido y pantalla de Caja POS (a $0). Si se deja vacío, se usará el título del producto.', 'emp-caja'),
            'desc_tip'    => true,
            'type'        => 'text',
            'value'       => $extra_box_name,
        ));

        echo '<div style="margin: 14px 0 12px 0; border-top: 1px dashed #a7f3d0;"></div>';

        $assigned_box = get_post_meta($post_id, '_batllie_packaging_box_product_id', true);
        $b6_id        = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_id(6) : 0;
        $b6_name      = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_name(6) : '';
        $b12_id       = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_id(12) : 0;
        $b12_name     = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_name(12) : '';
        $candidates   = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_all_box_candidates() : array();

        $box_options = array(
            '0' => __('⚡ Automático (Detectar según unidades 6 o 12)', 'emp-caja'),
        );
        if ($b6_id) {
            $box_options[$b6_id] = sprintf(__('📦 Caja Oficial 6u (%s)', 'emp-caja'), $b6_name);
        }
        if ($b12_id) {
            $box_options[$b12_id] = sprintf(__('📦 Caja Oficial 12u (%s)', 'emp-caja'), $b12_name);
        }
        foreach ($candidates as $cand) {
            if ($cand['id'] != $b6_id && $cand['id'] != $b12_id) {
                $box_options[$cand['id']] = $cand['name'] . ' (' . $cand['stock'] . ' disp.)';
            }
        }

        woocommerce_wp_select(array(
            'id'          => '_batllie_packaging_box_product_id',
            'label'       => __('Caja física de empaque asociada', 'emp-caja'),
            'options'     => $box_options,
            'description' => __('Caja física de empaque cuyo inventario limitará y se descontará automáticamente al vender este pack o combo.', 'emp-caja'),
            'desc_tip'    => true,
            'value'       => $assigned_box ?: '0',
        ));

        echo '<div style="margin: 14px 0 12px 0; border-top: 1px dashed #a7f3d0;"></div>';

        $is_pred_val = get_post_meta($post_id, '_batllie_grouped_is_predefined', true);
        $saved_pred_qtys = get_post_meta($post_id, '_batllie_grouped_predefined_quantities', true);
        if (!is_array($saved_pred_qtys)) $saved_pred_qtys = array();

        woocommerce_wp_checkbox(array(
            'id'          => '_batllie_grouped_is_predefined',
            'label'       => __('¿Es un Combo / Receta Fija?', 'emp-caja'),
            'description' => __('Activa esto si la caja ya viene armada con cantidades fijas de alfajores específicos (ej: 4 negros, 4 blancos, 4 pistacho). El stock de la caja se calculará a partir del stock disponible de sus componentes.', 'emp-caja'),
            'value'       => $is_pred_val === 'yes' ? 'yes' : 'no',
            'cbvalue'     => 'yes',
        ));

        // Obtener productos candidatos para la receta
        $recipe_candidates = array();
        $prod_obj = wc_get_product($post_id);
        $child_ids = ($prod_obj && method_exists($prod_obj, 'get_children')) ? array_map('absint', (array)$prod_obj->get_children()) : array();

        if (!empty($child_ids)) {
            foreach ($child_ids as $cid) {
                $cp = wc_get_product($cid);
                if ($cp) {
                    $recipe_candidates[$cid] = $cp;
                }
            }
        } else {
            $all_prods = wc_get_products(array(
                'status' => 'publish',
                'limit'  => -1,
                'type'   => 'simple',
            ));
            foreach ($all_prods as $ap) {
                if ($ap->get_id() == $post_id) continue;
                if (isset($saved_pred_qtys[$ap->get_id()]) || (class_exists('Batllie_Caja_Packing') && Batllie_Caja_Packing::is_alfajor_product($ap->get_id()))) {
                    $recipe_candidates[$ap->get_id()] = $ap;
                }
            }
        }

        echo '<div id="batllie_recipe_container" style="' . ($is_pred_val === 'yes' ? '' : 'display:none;') . ' margin:12px 0 16px 0; background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:12px;">';
        echo '<p style="margin:0 0 8px 0; font-weight:600; font-size:13px; color:#1e293b;">📋 ' . esc_html__('Composición / Receta de la caja (Unidades de cada alfajor por caja):', 'emp-caja') . '</p>';
        echo '<table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;">';
        echo '<thead><tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1;"><th style="padding:6px 8px;">' . esc_html__('Alfajor / Producto', 'emp-caja') . '</th><th style="padding:6px 8px; width:90px; text-align:center;">' . esc_html__('Stock Disp.', 'emp-caja') . '</th><th style="padding:6px 8px; width:120px; text-align:right;">' . esc_html__('Cant. en Caja', 'emp-caja') . '</th></tr></thead>';
        echo '<tbody>';

        if (empty($recipe_candidates)) {
            echo '<tr><td colspan="3" style="padding:10px; color:#64748b; text-align:center;">' . esc_html__('No se encontraron alfajores disponibles para configurar la receta.', 'emp-caja') . '</td></tr>';
        } else {
            foreach ($recipe_candidates as $cid => $cp) {
                $c_stock = $cp->get_stock_quantity();
                $c_stock_text = ($c_stock !== null) ? (int)$c_stock : __('Ilim.', 'emp-caja');
                $val = isset($saved_pred_qtys[$cid]) ? (int)$saved_pred_qtys[$cid] : 0;
                echo '<tr style="border-bottom:1px solid #f1f5f9;">';
                echo '<td style="padding:6px 8px; font-weight:500;">' . esc_html($cp->get_name()) . '</td>';
                echo '<td style="padding:6px 8px; text-align:center; color:' . ($c_stock !== null && $c_stock <= 5 ? '#dc2626' : '#059669') . ';">' . esc_html($c_stock_text) . '</td>';
                echo '<td style="padding:6px 8px; text-align:right;">';
                echo '<input type="number" name="_batllie_grouped_predefined_quantities[' . esc_attr($cid) . ']" value="' . esc_attr($val) . '" min="0" step="1" style="width:70px; text-align:center; padding:3px 6px;" />';
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '<p class="description" style="margin-top:6px; font-size:11px; color:#64748b;">' . esc_html__('Indica cuántas unidades de cada alfajor requiere esta caja. El stock total de la caja se limitará automáticamente por el alfajor con menor stock disponible.', 'emp-caja') . '</p>';
        echo '</div>';

        echo '<div style="margin: 14px 0 12px 0; border-top: 1px dashed #a7f3d0;"></div>';

        $fixed_price = get_post_meta($post_id, '_batllie_grouped_fixed_price', true);
        $fixed_price_display = get_post_meta($post_id, '_batllie_grouped_fixed_price_display', true) ?: 'box';

        woocommerce_wp_text_input(array(
            'id'          => '_batllie_grouped_fixed_price',
            'label'       => __('Precio Fijo del Combo / Caja ($)', 'emp-caja'),
            'placeholder' => __('Ej: 16800 (opcional)', 'emp-caja'),
            'description' => __('Si defines un precio fijo, la caja/combo se cobrará exactamente a este importe final sin importar los productos individuales que agrupe ni la cantidad (anula la suma de precios individuales). Deja vacío si deseas que el precio sea la suma de los alfajores elegidos.', 'emp-caja'),
            'desc_tip'    => true,
            'type'        => 'text',
            'value'       => $fixed_price,
        ));

        woocommerce_wp_select(array(
            'id'          => '_batllie_grouped_fixed_price_display',
            'label'       => __('Desglose del precio fijo', 'emp-caja'),
            'options'     => array(
                'box'         => __('Asignar precio total a la Caja (Alfajores figuran a $0 incluidos)', 'emp-caja'),
                'distributed' => __('Distribuir equitativamente entre los alfajores (Caja a $0)', 'emp-caja'),
            ),
            'description' => __('Define cómo se mostrará el cobro en el carrito y en el pedido cuando el precio fijo esté activo.', 'emp-caja'),
            'desc_tip'    => true,
            'value'       => $fixed_price_display,
        ));

        echo '<div style="margin: 14px 0 12px 0; border-top: 1px dashed #a7f3d0;"></div>';

        $custom_price_from = get_post_meta($post_id, '_batllie_grouped_custom_price_from', true);
        $auto_price = $post_id ? self::get_price_from($post_id) : 0;

        woocommerce_wp_text_input(array(
            'id'          => '_batllie_grouped_custom_price_from',
            'label'       => __('Precio "Desde" (Página principal)', 'emp-caja'),
            'placeholder' => $auto_price > 0 ? sprintf(__('Autocalculado: %s', 'emp-caja'), wc_price($auto_price)) : __('Ej: 16800', 'emp-caja'),
            'description' => __('Precio que se muestra en la página principal y catálogo como "Desde $..." cuando el precio es dinámico. Si configuraste un Precio Fijo arriba, este campo no es necesario porque se usará directamente el precio fijo.', 'emp-caja'),
            'desc_tip'    => true,
            'type'        => 'text',
            'value'       => $custom_price_from,
        ));

        echo '<div style="margin: 14px 0 12px 0; border-top: 1px dashed #a7f3d0;"></div>';

        $box_image_id  = get_post_meta($post_id, '_batllie_grouped_box_image_id', true);
        $box_image_url = $box_image_id ? wp_get_attachment_image_url($box_image_id, 'medium') : '';

        echo '<div class="form-field _batllie_grouped_box_image_field" style="margin-bottom:12px;">';
        echo '<label style="display:block; font-weight:600; margin-bottom:6px; color:#374151;">' . esc_html__('Imagen de la Caja (Empaque)', 'emp-caja') . '</label>';
        echo '<div style="display:flex; align-items:center; gap:16px;">';
        echo '<div id="batllie-box-image-preview" style="width:70px; height:70px; border:2px dashed #cbd5e1; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#f8fafc; overflow:hidden;">';
        if ($box_image_url) {
            echo '<img src="' . esc_url($box_image_url) . '" style="width:100%; height:100%; object-fit:cover;" />';
        } else {
            echo '<span style="font-size:24px; color:#94a3b8;">📦</span>';
        }
        echo '</div>';
        echo '<div>';
        echo '<input type="hidden" id="_batllie_grouped_box_image_id" name="_batllie_grouped_box_image_id" value="' . esc_attr($box_image_id) . '" />';
        echo '<button type="button" class="button button-secondary" id="batllie-upload-box-image-btn" style="margin-right:8px;">' . ($box_image_id ? esc_html__('Cambiar imagen', 'emp-caja') : esc_html__('Seleccionar imagen de la caja', 'emp-caja')) . '</button>';
        echo '<button type="button" class="button button-link-delete" id="batllie-remove-box-image-btn" style="' . ($box_image_id ? '' : 'display:none;') . '">' . esc_html__('Quitar', 'emp-caja') . '</button>';
        echo '<p class="description" style="margin-top:4px;">' . esc_html__('Imagen física de la caja que se usará en el carrito de la web y en la sección de Caja POS.', 'emp-caja') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#_batllie_grouped_is_predefined').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#batllie_recipe_container').slideDown(150);
                } else {
                    $('#batllie_recipe_container').slideUp(150);
                }
            });

            var mediaUploader;
            $('#batllie-upload-box-image-btn').on('click', function(e) {
                e.preventDefault();
                if (mediaUploader) {
                    mediaUploader.open();
                    return;
                }
                mediaUploader = wp.media({
                    title: '<?php echo esc_js(__('Seleccionar Imagen de la Caja', 'emp-caja')); ?>',
                    button: { text: '<?php echo esc_js(__('Usar esta imagen', 'emp-caja')); ?>' },
                    multiple: false
                });
                mediaUploader.on('select', function() {
                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                    $('#_batllie_grouped_box_image_id').val(attachment.id);
                    var imgUrl = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
                    $('#batllie-box-image-preview').html('<img src="' + imgUrl + '" style="width:100%; height:100%; object-fit:cover;" />');
                    $('#batllie-remove-box-image-btn').show();
                    $('#batllie-upload-box-image-btn').text('<?php echo esc_js(__('Cambiar imagen', 'emp-caja')); ?>');
                });
                mediaUploader.open();
            });
            $('#batllie-remove-box-image-btn').on('click', function(e) {
                e.preventDefault();
                $('#_batllie_grouped_box_image_id').val('');
                $('#batllie-box-image-preview').html('<span style="font-size:24px; color:#94a3b8;">📦</span>');
                $(this).hide();
                $('#batllie-upload-box-image-btn').text('<?php echo esc_js(__('Seleccionar imagen de la caja', 'emp-caja')); ?>');
            });
        });
        </script>
        <?php
    }

    /**
     * Encolar media uploader de WordPress en la edición de productos para seleccionar imagen de la caja
     */
    public static function enqueue_admin_media_assets($hook) {
        if (in_array($hook, array('post.php', 'post-new.php'), true)) {
            $screen = get_current_screen();
            if ($screen && $screen->post_type === 'product') {
                wp_enqueue_media();
            }
        }
    }

    /**
     * Guardar el campo de cantidad requerida y configuración de caja extra (vía post_id)
     */
    public static function save_admin_target_qty_field($post_id) {
        if (isset($_POST['_batllie_grouped_target_qty'])) {
            $val = sanitize_text_field($_POST['_batllie_grouped_target_qty']);
            if ($val === '') {
                delete_post_meta($post_id, '_batllie_grouped_target_qty');
            } else {
                update_post_meta($post_id, '_batllie_grouped_target_qty', absint($val));
            }
        }

        $enable_extra_box = isset($_POST['_batllie_grouped_enable_extra_box']) ? 'yes' : 'no';
        update_post_meta($post_id, '_batllie_grouped_enable_extra_box', $enable_extra_box);

        if (isset($_POST['_batllie_grouped_extra_box_name'])) {
            $box_name = sanitize_text_field($_POST['_batllie_grouped_extra_box_name']);
            if ($box_name === '') {
                delete_post_meta($post_id, '_batllie_grouped_extra_box_name');
            } else {
                update_post_meta($post_id, '_batllie_grouped_extra_box_name', $box_name);
            }
        }

        if (isset($_POST['_batllie_grouped_fixed_price'])) {
            $fixed_val = sanitize_text_field($_POST['_batllie_grouped_fixed_price']);
            if ($fixed_val === '') {
                delete_post_meta($post_id, '_batllie_grouped_fixed_price');
            } else {
                update_post_meta($post_id, '_batllie_grouped_fixed_price', wc_format_decimal($fixed_val));
            }
        }

        if (isset($_POST['_batllie_grouped_fixed_price_display'])) {
            $disp_val = sanitize_text_field($_POST['_batllie_grouped_fixed_price_display']);
            update_post_meta($post_id, '_batllie_grouped_fixed_price_display', in_array($disp_val, array('box', 'distributed'), true) ? $disp_val : 'box');
        }

        if (isset($_POST['_batllie_grouped_custom_price_from'])) {
            $from_val = sanitize_text_field($_POST['_batllie_grouped_custom_price_from']);
            if ($from_val === '') {
                delete_post_meta($post_id, '_batllie_grouped_custom_price_from');
            } else {
                update_post_meta($post_id, '_batllie_grouped_custom_price_from', wc_format_decimal($from_val));
            }
        }

        if (isset($_POST['_batllie_grouped_box_image_id'])) {
            $img_id = sanitize_text_field($_POST['_batllie_grouped_box_image_id']);
            if ($img_id === '') {
                delete_post_meta($post_id, '_batllie_grouped_box_image_id');
            } else {
                update_post_meta($post_id, '_batllie_grouped_box_image_id', absint($img_id));
            }
        }

        if (isset($_POST['_batllie_packaging_box_product_id'])) {
            $p_box = sanitize_text_field($_POST['_batllie_packaging_box_product_id']);
            update_post_meta($post_id, '_batllie_packaging_box_product_id', $p_box);
        }

        $is_pred = isset($_POST['_batllie_grouped_is_predefined']) ? 'yes' : 'no';
        update_post_meta($post_id, '_batllie_grouped_is_predefined', $is_pred);

        if (isset($_POST['_batllie_grouped_predefined_quantities']) && is_array($_POST['_batllie_grouped_predefined_quantities'])) {
            $clean_qtys = array();
            $total_units = 0;
            foreach ($_POST['_batllie_grouped_predefined_quantities'] as $child_id => $c_qty) {
                $c_id = absint($child_id);
                $c_val = absint($c_qty);
                if ($c_id > 0 && $c_val > 0) {
                    $clean_qtys[$c_id] = $c_val;
                    $total_units += $c_val;
                }
            }
            update_post_meta($post_id, '_batllie_grouped_predefined_quantities', $clean_qtys);
            if ($is_pred === 'yes' && $total_units > 0) {
                update_post_meta($post_id, '_batllie_grouped_target_qty', $total_units);
            }
        }
    }

    /**
     * Guardar el campo de cantidad requerida y configuración de caja extra (vía WC_Product object)
     */
    public static function save_admin_target_qty_field_object($product) {
        if (isset($_POST['_batllie_grouped_target_qty'])) {
            $val = sanitize_text_field($_POST['_batllie_grouped_target_qty']);
            if ($val === '') {
                $product->delete_meta_data('_batllie_grouped_target_qty');
            } else {
                $product->update_meta_data('_batllie_grouped_target_qty', absint($val));
            }
        }

        $enable_extra_box = isset($_POST['_batllie_grouped_enable_extra_box']) ? 'yes' : 'no';
        $product->update_meta_data('_batllie_grouped_enable_extra_box', $enable_extra_box);

        if (isset($_POST['_batllie_grouped_extra_box_name'])) {
            $box_name = sanitize_text_field($_POST['_batllie_grouped_extra_box_name']);
            if ($box_name === '') {
                $product->delete_meta_data('_batllie_grouped_extra_box_name');
            } else {
                $product->update_meta_data('_batllie_grouped_extra_box_name', $box_name);
            }
        }

        if (isset($_POST['_batllie_grouped_fixed_price'])) {
            $fixed_val = sanitize_text_field($_POST['_batllie_grouped_fixed_price']);
            if ($fixed_val === '') {
                $product->delete_meta_data('_batllie_grouped_fixed_price');
            } else {
                $product->update_meta_data('_batllie_grouped_fixed_price', wc_format_decimal($fixed_val));
            }
        }

        if (isset($_POST['_batllie_grouped_fixed_price_display'])) {
            $disp_val = sanitize_text_field($_POST['_batllie_grouped_fixed_price_display']);
            $product->update_meta_data('_batllie_grouped_fixed_price_display', in_array($disp_val, array('box', 'distributed'), true) ? $disp_val : 'box');
        }

        if (isset($_POST['_batllie_grouped_custom_price_from'])) {
            $from_val = sanitize_text_field($_POST['_batllie_grouped_custom_price_from']);
            if ($from_val === '') {
                $product->delete_meta_data('_batllie_grouped_custom_price_from');
            } else {
                $product->update_meta_data('_batllie_grouped_custom_price_from', wc_format_decimal($from_val));
            }
        }

        if (isset($_POST['_batllie_grouped_box_image_id'])) {
            $img_id = sanitize_text_field($_POST['_batllie_grouped_box_image_id']);
            if ($img_id === '') {
                $product->delete_meta_data('_batllie_grouped_box_image_id');
            } else {
                $product->update_meta_data('_batllie_grouped_box_image_id', absint($img_id));
            }
        }

        if (isset($_POST['_batllie_packaging_box_product_id'])) {
            $p_box = sanitize_text_field($_POST['_batllie_packaging_box_product_id']);
            $product->update_meta_data('_batllie_packaging_box_product_id', $p_box);
        }

        $is_pred = isset($_POST['_batllie_grouped_is_predefined']) ? 'yes' : 'no';
        $product->update_meta_data('_batllie_grouped_is_predefined', $is_pred);

        if (isset($_POST['_batllie_grouped_predefined_quantities']) && is_array($_POST['_batllie_grouped_predefined_quantities'])) {
            $clean_qtys = array();
            $total_units = 0;
            foreach ($_POST['_batllie_grouped_predefined_quantities'] as $child_id => $c_qty) {
                $c_id = absint($child_id);
                $c_val = absint($c_qty);
                if ($c_id > 0 && $c_val > 0) {
                    $clean_qtys[$c_id] = $c_val;
                    $total_units += $c_val;
                }
            }
            $product->update_meta_data('_batllie_grouped_predefined_quantities', $clean_qtys);
            if ($is_pred === 'yes' && $total_units > 0) {
                $product->update_meta_data('_batllie_grouped_target_qty', $total_units);
            }
        }
    }

    /**
     * Encolar CSS y JS en frontend para todos los productos agrupados
     */
    public static function enqueue_frontend_assets() {
        if (!is_singular('product')) {
            return;
        }

        global $post;
        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('grouped')) {
            return;
        }

        $target_qty = self::get_target_qty($product);

        // Encolar CSS
        wp_enqueue_style(
            'batllie-caja-grouped-css',
            EMP_CAJA_URL . 'assets/css/caja-grouped-product.css',
            array(),
            EMP_CAJA_VERSION
        );

        // Encolar JS
        wp_enqueue_script(
            'batllie-caja-grouped-js',
            EMP_CAJA_URL . 'assets/js/caja-grouped-product.js',
            array('jquery'),
            EMP_CAJA_VERSION,
            true
        );

        // Recopilar precios de los productos hijos para mayor precisión
        $children_prices = array();
        $child_ids = $product->get_children();
        foreach ($child_ids as $child_id) {
            $child = wc_get_product($child_id);
            if ($child) {
                $children_prices[$child_id] = floatval($child->get_price());
            }
        }

        // Localizar variables para JS
        $fixed_price = self::get_fixed_price($product);
        $is_predefined = self::is_predefined_combo($product);
        $predefined_qtys = self::get_predefined_quantities($product);

        wp_localize_script('batllie-caja-grouped-js', 'batllieGroupedConfig', array(
            'productId'       => $product->get_id(),
            'targetQty'       => $target_qty,
            'isPredefined'    => $is_predefined,
            'predefinedQtys'  => $predefined_qtys,
            'isFixedPrice'    => ($fixed_price > 0),
            'fixedPrice'      => $fixed_price,
            'childrenPrices'  => $children_prices,
            'currencySymbol'  => html_entity_decode(get_woocommerce_currency_symbol()),
            'decimalSep'      => wc_get_price_decimal_separator(),
            'thousandSep'     => wc_get_price_thousand_separator(),
            'i18n'            => array(
                'boxEmpty'     => $is_predefined ? __('Combo armado', 'emp-caja') : __('Caja vacía', 'emp-caja'),
                'units'        => __('unidades', 'emp-caja'),
                'unitSingular' => __('unidad', 'emp-caja'),
                'boxComplete'  => __('¡Combo completo!', 'emp-caja'),
                'selectPrompt' => $is_predefined ? __('Este combo incluye los productos seleccionados en las cantidades indicadas.', 'emp-caja') : ($target_qty > 0 ? sprintf(__('Seleccioná %d unidades para armar tu caja.', 'emp-caja'), $target_qty) : __('Seleccioná los productos que deseas incluir.', 'emp-caja')),
                'needMore'     => __('Te falta %d %s para completar tu caja de %d.', 'emp-caja'),
                'exactWarning' => __('Debes incluir exactamente %d unidades para armar tu caja. Actualmente seleccionaste %d (te falta %d).', 'emp-caja'),
                'exactExceed'  => __('Debes incluir exactamente %d unidades para armar tu caja. Actualmente seleccionaste %d.', 'emp-caja'),
                'maxReached'   => sprintf(__('Ya alcanzaste el máximo de %d unidades para esta caja.', 'emp-caja'), $target_qty),
                'totalLabel'   => $is_predefined ? __('Total del combo:', 'emp-caja') : __('Total de tu caja:', 'emp-caja'),
            ),
        ));
    }

    /**
     * Renderizar tarjeta interactiva de estado, progreso, total dinámico y avisos
     */
    public static function render_grouped_box_controls() {
        global $product;
        if (!$product || !$product->is_type('grouped')) {
            return;
        }

        $target_qty = self::get_target_qty($product);
        $fixed_price = self::get_fixed_price($product);
        $is_predefined = self::is_predefined_combo($product);

        if ($target_qty <= 0 && $fixed_price <= 0) {
            return;
        }

        if ($fixed_price > 0) {
            $initial_price_html = wc_price($fixed_price);
        } elseif ($is_predefined) {
            $predefined_qtys = self::get_predefined_quantities($product);
            $sum_var = 0;
            foreach ($predefined_qtys as $cid => $q) {
                $cp = wc_get_product($cid);
                if ($cp) {
                    $sum_var += $q * floatval($cp->get_price());
                }
            }
            $initial_price_html = wc_price($sum_var);
        } else {
            $initial_price_html = wc_price(0);
        }
        $box_heading = $is_predefined ? __('Combo Batllié', 'emp-caja') : __('Armá tu caja', 'emp-caja');
        $box_badge   = $is_predefined ? __('¡Combo listo!', 'emp-caja') : ($target_qty > 0 ? __('Caja vacía', 'emp-caja') : __('Personaliza tu selección', 'emp-caja'));
        ?>
        <div class="batllie-grouped-box-container" id="batllie-grouped-box-container" data-target-qty="<?php echo esc_attr($target_qty); ?>" data-fixed-price="<?php echo esc_attr($fixed_price); ?>" data-is-predefined="<?php echo $is_predefined ? '1' : '0'; ?>">
            <!-- Encabezado de Progreso de la Caja -->
            <div class="batllie-box-header">
                <div class="batllie-box-title-wrap">
                    <span class="batllie-box-icon"><?php echo $is_predefined ? '🎁' : '📦'; ?></span>
                    <span class="batllie-box-heading"><?php echo esc_html($box_heading); ?></span>
                </div>
                <div class="batllie-box-status-badge <?php echo $is_predefined ? 'badge-complete' : ''; ?>" id="batllie-box-badge">
                    <?php echo esc_html($box_badge); ?>
                </div>
            </div>

            <?php if ($is_predefined) : ?>
            <!-- Resumen limpio de Combo Predeterminado / Fijo -->
            <div class="batllie-box-predefined-banner" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px; margin:10px 0 14px 0; display:flex; align-items:center; gap:10px;">
                <span style="font-size:22px; line-height:1;">🎁</span>
                <div style="font-size:13px; color:#166534; line-height:1.4;">
                    <strong style="font-size:14px; display:block; color:#14532d;"><?php printf(esc_html__('Combo predeterminado (%d unidades incluidas)', 'emp-caja'), $target_qty); ?></strong>
                    <?php _e('Esta caja viene armada con las unidades detalladas. ¡Hacé clic abajo para añadirla al carrito!', 'emp-caja'); ?>
                </div>
            </div>
            <?php elseif ($target_qty > 0) : ?>
            <!-- Contador y Barra de Progreso para Cajas Personalizables -->
            <div class="batllie-box-progress-wrap">
                <div class="batllie-box-count-text">
                    <span class="batllie-box-selected" id="batllie-box-current">0</span>
                    <span class="batllie-box-divider">/</span>
                    <span class="batllie-box-total" id="batllie-box-target"><?php echo esc_html($target_qty); ?></span>
                    <span class="batllie-box-label"><?php echo esc_html__('unidades elegidas', 'emp-caja'); ?></span>
                </div>
                <div class="batllie-box-progress-bar">
                    <div class="batllie-box-progress-fill" id="batllie-box-progress-fill" style="width: 0%;"></div>
                </div>
            </div>

            <!-- Mensaje Dinámico de Estado -->
            <div class="batllie-box-message" id="batllie-box-message">
                <?php printf(esc_html__('Seleccioná %d unidades para armar tu caja personalizada.', 'emp-caja'), $target_qty); ?>
            </div>
            <?php endif; ?>

            <!-- Resumen de Precio Total Dinámico o Fijo -->
            <div class="batllie-box-summary-row">
                <span class="batllie-box-summary-label"><?php echo $is_predefined ? __('Total del combo:', 'emp-caja') : __('Total de tu caja:', 'emp-caja'); ?></span>
                <span class="batllie-box-summary-price" id="batllie-box-total-price">
                    <?php echo $initial_price_html; ?>
                </span>
            </div>

            <!-- Aviso interactivo en caso de clic prematuro -->
            <div class="batllie-box-alert" id="batllie-box-alert" style="display: none;" role="alert">
                <span class="batllie-alert-icon">⚠️</span>
                <span class="batllie-alert-text" id="batllie-alert-text"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Validación en el servidor: rechazar si la cantidad de ítems no coincide con las reglas del pack
     */
    public static function validate_grouped_add_to_cart() {
        if (!isset($_REQUEST['add-to-cart']) || !is_numeric($_REQUEST['add-to-cart'])) {
            return;
        }

        $product_id = absint($_REQUEST['add-to-cart']);
        $product    = wc_get_product($product_id);
        if (!$product || !$product->is_type('grouped')) {
            return;
        }

        // Si es un combo predeterminado, forzar las cantidades predefinidas
        $is_predefined = self::is_predefined_combo($product);
        $predefined_qtys = self::get_predefined_quantities($product);
        if ($is_predefined && !empty($predefined_qtys)) {
            if (!isset($_REQUEST['quantity']) || !is_array($_REQUEST['quantity'])) {
                $_REQUEST['quantity'] = array();
                $_POST['quantity'] = array();
            }
            foreach ($predefined_qtys as $child_id => $qty) {
                $_REQUEST['quantity'][$child_id] = $qty;
                $_POST['quantity'][$child_id] = $qty;
            }
            return;
        }

        $target_qty = self::get_target_qty($product);
        $quantities = isset($_REQUEST['quantity']) && is_array($_REQUEST['quantity']) ? wp_unslash($_REQUEST['quantity']) : array();
        $total_qty  = 0;
        foreach ($quantities as $child_id => $qty) {
            $total_qty += max(0, intval($qty));
        }

        if ($target_qty <= 0) {
            if ($total_qty <= 0) {
                unset($_REQUEST['add-to-cart']);
                unset($_POST['add-to-cart']);
                wc_add_notice(__('Debes seleccionar al menos una unidad para armar este pack o combo.', 'emp-caja'), 'error');
            }
            return;
        }

        if ($total_qty !== $target_qty) {
            // Cancelar la adición al carrito anulando el parámetro
            unset($_REQUEST['add-to-cart']);
            unset($_POST['add-to-cart']);

            $diff = $target_qty - $total_qty;
            if ($diff > 0) {
                $msg = sprintf(
                    __('Debes incluir exactamente %d unidades para armar "%s". Actualmente seleccionaste %d (te falta %d).', 'emp-caja'),
                    $target_qty,
                    $product->get_name(),
                    $total_qty,
                    $diff
                );
            } else {
                $msg = sprintf(
                    __('Debes incluir exactamente %d unidades para armar "%s". Actualmente seleccionaste %d (te pasaste por %d).', 'emp-caja'),
                    $target_qty,
                    $product->get_name(),
                    $total_qty,
                    abs($diff)
                );
            }

            wc_add_notice($msg, 'error');
        }
    }

    /**
     * Obtener o crear de forma transparente el producto de tipo empaque/caja virtual en WooCommerce
     */
    public static function get_or_create_packaging_product_id($create_if_missing = true) {
        $option_id = get_option('_batllie_packaging_product_id', 0);
        if ($option_id) {
            $product = wc_get_product($option_id);
            if ($product && $product->exists() && $product->get_status() !== 'trash') {
                return (int) $option_id;
            }
        }

        // Buscar producto existente por slug
        $existing = get_page_by_path('caja-packaging-batllie', OBJECT, 'product');
        if ($existing && $existing->post_status !== 'trash') {
            update_option('_batllie_packaging_product_id', $existing->ID);
            return (int) $existing->ID;
        }

        if (!$create_if_missing || !class_exists('WC_Product_Simple')) {
            return 0;
        }

        $packaging_product = new WC_Product_Simple();
        $packaging_product->set_name(__('Caja de Empaque Batllié', 'emp-caja'));
        $packaging_product->set_slug('caja-packaging-batllie');
        $packaging_product->set_status('publish');
        $packaging_product->set_catalog_visibility('hidden');
        $packaging_product->set_virtual(true);
        $packaging_product->set_price(0);
        $packaging_product->set_regular_price(0);
        $packaging_product->set_manage_stock(false);
        $packaging_product->set_sold_individually(false);
        $packaging_product->set_reviews_allowed(false);

        $new_id = $packaging_product->save();
        if ($new_id) {
            update_option('_batllie_packaging_product_id', $new_id);
            update_post_meta($new_id, '_is_batllie_packaging_product', 'yes');
            return (int) $new_id;
        }

        return 0;
    }

    /**
     * Garantizar que el producto de empaque sea siempre comprable cuando se inyecta programáticamente
     */
    public static function filter_packaging_is_purchasable($purchasable, $product) {
        if ($product) {
            $pkg_id = get_option('_batllie_packaging_product_id', 0);
            if ($pkg_id && $product->get_id() == $pkg_id) {
                return true;
            }
        }
        return $purchasable;
    }

    /**
     * Obtener el nombre asignado a la caja de empaque para un pack específico
     */
    public static function get_extra_box_name($product_id) {
        $custom = get_post_meta($product_id, '_batllie_grouped_extra_box_name', true);
        if (!empty($custom)) {
            return trim($custom);
        }

        $product = wc_get_product($product_id);
        if ($product) {
            $title = $product->get_name();
            if (stripos($title, 'caja') !== false) {
                return $title;
            }
            return sprintf(__('Caja %s', 'emp-caja'), $title);
        }

        return __('Caja de Empaque', 'emp-caja');
    }

    /**
     * Asociar metadatos del pack padre y una clave única de lote a los productos hijos seleccionados
     */
    public static function tag_grouped_child_cart_item($cart_item_data, $product_id, $variation_id = 0, $quantity = 1) {
        if (!is_array($cart_item_data)) {
            $cart_item_data = array();
        }

        if (!empty($cart_item_data['batllie_extra_box'])) {
            return $cart_item_data;
        }

        if (!empty($_REQUEST['add-to-cart']) && is_numeric($_REQUEST['add-to-cart'])) {
            $parent_id = absint($_REQUEST['add-to-cart']);
            $parent    = wc_get_product($parent_id);
            if ($parent && $parent->is_type('grouped')) {
                if (self::$current_submission_pack_id === null) {
                    self::$current_submission_pack_id = 'pack_' . $parent_id . '_' . wp_generate_password(6, false);
                }
                $cart_item_data['batllie_parent_grouped_id'] = $parent_id;
                $cart_item_data['batllie_pack_instance_id']  = self::$current_submission_pack_id;
            }
        }

        return $cart_item_data;
    }

    /**
     * Inyectar automáticamente el ítem de caja de empaque a $0.00 cuando se añade el pack agrupado
     */
    public static function handle_extra_box_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (!empty($cart_item_data['batllie_extra_box'])) {
            return;
        }

        if (empty($_REQUEST['add-to-cart']) || !is_numeric($_REQUEST['add-to-cart'])) {
            return;
        }

        $parent_id = absint($_REQUEST['add-to-cart']);
        $enabled   = get_post_meta($parent_id, '_batllie_grouped_enable_extra_box', true);
        if ($enabled !== 'yes') {
            return;
        }

        $pack_instance_id = !empty($cart_item_data['batllie_pack_instance_id']) ? $cart_item_data['batllie_pack_instance_id'] : (self::$current_submission_pack_id ?: ('pack_' . $parent_id));

        if (!empty(self::$added_extra_box_for[$pack_instance_id])) {
            return;
        }

        self::$added_extra_box_for[$pack_instance_id] = true;

        $packaging_product_id = self::get_or_create_packaging_product_id(true);
        if (!$packaging_product_id || !function_exists('WC') || !WC()->cart) {
            return;
        }

        $custom_name = self::get_extra_box_name($parent_id);

        $box_image_id = get_post_meta($parent_id, '_batllie_grouped_box_image_id', true);
        if (!$box_image_id) {
            $parent_product = wc_get_product($parent_id);
            if ($parent_product && $parent_product->get_image_id()) {
                $box_image_id = $parent_product->get_image_id();
            }
        }

        $box_cart_data = array(
            'batllie_extra_box'         => true,
            'batllie_parent_grouped_id' => $parent_id,
            'batllie_pack_instance_id'  => $pack_instance_id,
            'batllie_box_custom_name'   => $custom_name,
            'batllie_box_image_id'      => $box_image_id,
        );

        if ($box_image_id && $packaging_product_id) {
            $pkg_prod = wc_get_product($packaging_product_id);
            if ($pkg_prod && !$pkg_prod->get_image_id()) {
                set_post_thumbnail($packaging_product_id, $box_image_id);
            }
        }

        WC()->cart->add_to_cart($packaging_product_id, 1, 0, array(), $box_cart_data);
    }

    /**
     * Sincronizar precios (fijos o dinámicos) y purgar cajas huérfanas si se quitan los productos
     */
    public static function sync_and_price_extra_boxes($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (empty($cart) || !is_object($cart) || !method_exists($cart, 'get_cart')) {
            return;
        }

        $active_packs     = array();
        $active_parents   = array();
        $boxes_by_pack    = array();
        $children_by_pack = array();

        foreach ($cart->get_cart() as $key => $item) {
            $inst_id = !empty($item['batllie_pack_instance_id']) ? $item['batllie_pack_instance_id'] : '';
            $p_id    = !empty($item['batllie_parent_grouped_id']) ? (int) $item['batllie_parent_grouped_id'] : 0;
            $qty     = !empty($item['quantity']) ? (int) $item['quantity'] : 0;

            if (!empty($item['batllie_extra_box'])) {
                $boxes_by_pack[$key] = $item;
            } else {
                if ($p_id > 0) {
                    $active_parents[$p_id] = ($active_parents[$p_id] ?? 0) + $qty;
                }
                if (!empty($inst_id)) {
                    $active_packs[$inst_id] = ($active_packs[$inst_id] ?? 0) + $qty;
                    if (!isset($children_by_pack[$inst_id])) {
                        $children_by_pack[$inst_id] = array();
                    }
                    $children_by_pack[$inst_id][$key] = $item;
                }
            }
        }

        // Purgar cajas huérfanas si el usuario borró los productos asociados a la caja
        foreach ($boxes_by_pack as $box_key => $box_item) {
            $inst_id   = !empty($box_item['batllie_pack_instance_id']) ? $box_item['batllie_pack_instance_id'] : '';
            $parent_id = !empty($box_item['batllie_parent_grouped_id']) ? (int) $box_item['batllie_parent_grouped_id'] : 0;

            $has_items = false;
            if (!empty($inst_id) && !empty($active_packs[$inst_id])) {
                $has_items = true;
            } elseif (!empty($parent_id) && !empty($active_parents[$parent_id])) {
                $has_items = true;
            }

            if (!$has_items) {
                $cart->remove_cart_item($box_key);
                unset($boxes_by_pack[$box_key]);
            }
        }

        // Aplicar precios (fijo o dinámico) a cada lote de pack
        $all_pack_instances = array_unique(array_merge(
            array_keys($children_by_pack),
            array_filter(array_column($boxes_by_pack, 'batllie_pack_instance_id'))
        ));

        foreach ($all_pack_instances as $pack_id) {
            $pack_children  = $children_by_pack[$pack_id] ?? array();
            $pack_box_key   = null;
            $pack_parent_id = 0;

            foreach ($boxes_by_pack as $b_key => $b_item) {
                if (!empty($b_item['batllie_pack_instance_id']) && $b_item['batllie_pack_instance_id'] === $pack_id) {
                    $pack_box_key   = $b_key;
                    $pack_parent_id = !empty($b_item['batllie_parent_grouped_id']) ? (int) $b_item['batllie_parent_grouped_id'] : 0;
                    break;
                }
            }

            if (!$pack_parent_id && !empty($pack_children)) {
                $first_child    = reset($pack_children);
                $pack_parent_id = !empty($first_child['batllie_parent_grouped_id']) ? (int) $first_child['batllie_parent_grouped_id'] : 0;
            }

            if (!$pack_parent_id) {
                continue;
            }

            $fixed_price = self::get_fixed_price($pack_parent_id);
            $disp_mode   = self::get_fixed_price_display_mode($pack_parent_id);

            if ($pack_box_key) {
                // Modo Caja Principal: La caja siempre lleva el precio total del pack
                // (sea precio fijo configurado o la suma dinámica de los alfajores elegidos).
                // Todos los alfajores incluidos van a precio 0 para que no figuren precios individuales.
                $box_price = 0;
                if ($fixed_price > 0) {
                    $box_price = $fixed_price;
                } else {
                    // Precio dinámico: suma de los precios regulares de los alfajores seleccionados
                    foreach ($pack_children as $c_key => $c_item) {
                        $c_qty = !empty($c_item['quantity']) ? (int) $c_item['quantity'] : 1;
                        $unit_price = 0;
                        if (!empty($c_item['product_id'])) {
                            $prod_obj = wc_get_product($c_item['product_id']);
                            if ($prod_obj) {
                                $unit_price = floatval($prod_obj->get_price());
                            }
                        }
                        if ($unit_price <= 0 && isset($c_item['data']) && is_object($c_item['data'])) {
                            $unit_price = floatval($c_item['data']->get_regular_price() ?: $c_item['data']->get_price());
                        }
                        $box_price += ($unit_price * $c_qty);
                    }
                }

                // 1. Asignar el importe total a la caja de empaque
                if (isset($cart->cart_contents[$pack_box_key]['data'])) {
                    $cart->cart_contents[$pack_box_key]['data']->set_price($box_price);
                }

                // 2. TODOS los productos hijos dentro de la caja pasan a precio $0
                foreach ($pack_children as $c_key => $c_item) {
                    if (isset($cart->cart_contents[$c_key]['data'])) {
                        $cart->cart_contents[$c_key]['data']->set_price(0);
                    }
                }
            } else {
                // Modo Distribuido (si no hay caja física de empaque en el pack):
                if ($fixed_price > 0) {
                    $total_units = 0;
                    foreach ($pack_children as $c_item) {
                        $total_units += !empty($c_item['quantity']) ? (int) $c_item['quantity'] : 0;
                    }

                    if ($total_units > 0) {
                        $unit_price = round($fixed_price / $total_units, 2);
                        $allocated  = 0;
                        $first_key  = null;

                        foreach ($pack_children as $c_key => $c_item) {
                            if ($first_key === null) {
                                $first_key = $c_key;
                            }
                            $c_qty = !empty($c_item['quantity']) ? (int) $c_item['quantity'] : 1;
                            if (isset($cart->cart_contents[$c_key]['data'])) {
                                $cart->cart_contents[$c_key]['data']->set_price($unit_price);
                            }
                            $allocated += ($unit_price * $c_qty);
                        }

                        // Ajustar residuo de centavos en el primer ítem si corresponde
                        $diff = round($fixed_price - $allocated, 2);
                        if ($diff != 0 && $first_key && isset($cart->cart_contents[$first_key]['data'])) {
                            $first_qty = !empty($cart->cart_contents[$first_key]['quantity']) ? (int) $cart->cart_contents[$first_key]['quantity'] : 1;
                            $adjusted_price = $unit_price + ($diff / $first_qty);
                            $cart->cart_contents[$first_key]['data']->set_price(round($adjusted_price, 2));
                        }
                    }
                }
            }
        }

        // Reordenar cart_contents para que las cajas de empaque aparezcan primero que sus productos hijos
        if (!empty($boxes_by_pack) && !empty($cart->cart_contents)) {
            $sorted_cart  = array();
            $handled_keys = array();

            foreach ($cart->cart_contents as $k => $item) {
                if (in_array($k, $handled_keys, true)) {
                    continue;
                }

                $pack_id = !empty($item['batllie_pack_instance_id']) ? $item['batllie_pack_instance_id'] : '';

                if (!empty($pack_id)) {
                    // Buscar la caja de este pack
                    $box_k = null;
                    foreach ($boxes_by_pack as $bk => $bitem) {
                        if (!empty($bitem['batllie_pack_instance_id']) && $bitem['batllie_pack_instance_id'] === $pack_id) {
                            $box_k = $bk;
                            break;
                        }
                    }

                    // 1. Agregar la caja principal en primer lugar
                    if ($box_k && isset($cart->cart_contents[$box_k]) && !in_array($box_k, $handled_keys, true)) {
                        $sorted_cart[$box_k] = $cart->cart_contents[$box_k];
                        $handled_keys[]      = $box_k;
                    }

                    // 2. Agregar los alfajores hijos inmediatamente después
                    if (!empty($children_by_pack[$pack_id])) {
                        foreach ($children_by_pack[$pack_id] as $ck => $citem) {
                            if (isset($cart->cart_contents[$ck]) && !in_array($ck, $handled_keys, true)) {
                                $sorted_cart[$ck] = $cart->cart_contents[$ck];
                                $handled_keys[]   = $ck;
                            }
                        }
                    }
                } else {
                    $sorted_cart[$k] = $item;
                    $handled_keys[]  = $k;
                }
            }

            // Asegurar que ningún ítem quede rezagado
            foreach ($cart->cart_contents as $k => $item) {
                if (!in_array($k, $handled_keys, true)) {
                    $sorted_cart[$k] = $item;
                }
            }

            $cart->cart_contents = $sorted_cart;
        }
    }

    /**
     * Verificar si una instancia de pack/combo tiene un ítem de caja física activo en el carrito
     */
    public static function pack_has_active_box($pack_instance_id) {
        if (empty($pack_instance_id) || !function_exists('WC') || !WC()->cart) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['batllie_extra_box']) && !empty($item['batllie_pack_instance_id']) && $item['batllie_pack_instance_id'] === $pack_instance_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nombre personalizado y distintivo en el carrito
     */
    public static function filter_extra_box_cart_name($name, $cart_item, $cart_item_key) {
        if (!empty($cart_item['batllie_extra_box'])) {
            $custom_name = !empty($cart_item['batllie_box_custom_name']) ? $cart_item['batllie_box_custom_name'] : __('Caja de Empaque', 'emp-caja');
            $price = isset($cart_item['data']) ? floatval($cart_item['data']->get_price()) : 0;
            if ($price > 0) {
                $badge = ' <span class="batllie-included-badge" style="display:inline-block; font-size:11px; font-weight:600; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius:9999px; padding:2px 8px; margin-left:6px; vertical-align:middle;">' . esc_html__('Combo / Pack', 'emp-caja') . '</span>';
            } else {
                $badge = ' <span class="batllie-included-badge" style="display:inline-block; font-size:11px; font-weight:600; background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; border-radius:9999px; padding:2px 8px; margin-left:6px; vertical-align:middle;">' . esc_html__('Empaque incluido (Sin costo)', 'emp-caja') . '</span>';
            }
            $pack_id = !empty($cart_item['batllie_pack_instance_id']) ? esc_attr($cart_item['batllie_pack_instance_id']) : '';
            $marker  = '<span class="batllie-pack-marker batllie-box-marker batllie-pid-' . $pack_id . '" title="' . $pack_id . '" data-pack-id="' . $pack_id . '" style="display:none!important;"></span>';

            return esc_html($custom_name) . $badge . $marker;
        } elseif (!empty($cart_item['batllie_parent_grouped_id'])) {
            $pack_id = !empty($cart_item['batllie_pack_instance_id']) ? esc_attr($cart_item['batllie_pack_instance_id']) : '';
            if ($pack_id && self::pack_has_active_box($pack_id)) {
                $marker  = '<span class="batllie-pack-marker batllie-child-marker batllie-pid-' . $pack_id . '" title="' . $pack_id . '" data-pack-id="' . $pack_id . '" style="display:none!important;"></span>';
                return $name . $marker;
            }
            return $name;
        }
        return $name;
    }

    /**
     * Usar la imagen de la caja del producto agrupado en el carrito en lugar de un placeholder
     */
    public static function filter_extra_box_thumbnail($thumbnail, $cart_item, $cart_item_key) {
        if (!empty($cart_item['batllie_extra_box'])) {
            $img_id = !empty($cart_item['batllie_box_image_id']) ? $cart_item['batllie_box_image_id'] : 0;
            if (!$img_id && !empty($cart_item['batllie_parent_grouped_id'])) {
                $img_id = get_post_meta($cart_item['batllie_parent_grouped_id'], '_batllie_grouped_box_image_id', true);
                if (!$img_id) {
                    $parent_product = wc_get_product($cart_item['batllie_parent_grouped_id']);
                    if ($parent_product && $parent_product->get_image_id()) {
                        $img_id = $parent_product->get_image_id();
                    }
                }
            }
            if ($img_id) {
                return wp_get_attachment_image($img_id, 'woocommerce_thumbnail');
            }
        }
        return $thumbnail;
    }

    /**
     * Mostrar precio correcto en las columnas de precio y subtotal del carrito
     */
    public static function filter_extra_box_price_display($price_html, $cart_item, $cart_item_key) {
        if (!empty($cart_item['batllie_extra_box'])) {
            $price = isset($cart_item['data']) ? floatval($cart_item['data']->get_price()) : 0;
            if ($price > 0) {
                return '<span class="batllie-combo-price" style="color:#0f172a; font-weight:700;">' . wc_price($price) . '</span>';
            }
            return '<span class="batllie-free-price" style="color:#059669; font-weight:700;">' . esc_html__('Gratis', 'emp-caja') . ' (' . wc_price(0) . ')</span>';
        }
        return $price_html;
    }

    /**
     * Fijar la cantidad en texto fijo "1" en la tabla del carrito para evitar alteraciones
     */
    public static function filter_extra_box_cart_quantity($product_quantity, $cart_item_key, $cart_item) {
        if (!empty($cart_item['batllie_extra_box'])) {
            return '<span class="batllie-fixed-qty" style="font-weight:600; color:#374151;">' . esc_html($cart_item['quantity']) . '</span>';
        }
        return $product_quantity;
    }

    /**
     * Guardar datos de la caja en el ítem de la orden para que figure en el pedido, factura y Caja POS
     */
    public static function save_extra_box_order_line_item($item, $cart_item_key, $values, $order) {
        if (!empty($values['batllie_extra_box'])) {
            $custom_name = !empty($values['batllie_box_custom_name']) ? $values['batllie_box_custom_name'] : __('Caja de Empaque', 'emp-caja');
            $item->set_name($custom_name);

            $item_price = isset($values['data']) ? floatval($values['data']->get_price()) : 0;
            if ($item_price <= 0) {
                $item->set_subtotal(0);
                $item->set_total(0);
                $item->add_meta_data('_batllie_included_packaging', __('Empaque incluido (Sin costo)', 'emp-caja'), true);
            } else {
                $item->add_meta_data('_batllie_pack_combo_price', wc_price($item_price), true);
            }

            $item->add_meta_data('_batllie_extra_box', 'yes', true);
            if (!empty($values['batllie_parent_grouped_id'])) {
                $item->add_meta_data('_batllie_parent_grouped_id', $values['batllie_parent_grouped_id'], true);
            }
            if (!empty($values['batllie_pack_instance_id'])) {
                $item->add_meta_data('_batllie_pack_instance_id', $values['batllie_pack_instance_id'], true);
            }
            if (!empty($values['batllie_box_image_id'])) {
                $item->add_meta_data('_batllie_box_image_id', $values['batllie_box_image_id'], true);
            }
        } else {
            // Productos individuales que componen el pack / caja
            if (!empty($values['batllie_parent_grouped_id'])) {
                $item->add_meta_data('_batllie_parent_grouped_id', $values['batllie_parent_grouped_id'], true);
            }
            if (!empty($values['batllie_pack_instance_id'])) {
                $item->add_meta_data('_batllie_pack_instance_id', $values['batllie_pack_instance_id'], true);
            }
            if (!empty($values['batllie_replaced_flavor'])) {
                $item->add_meta_data('_batllie_replaced_flavor', 'yes', true);
            }
        }
    }

    /**
     * Rechazar compra directa del producto de empaque fuera del flujo de packs
     */
    public static function validate_packaging_direct_purchase($passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array()) {
        $pkg_id = get_option('_batllie_packaging_product_id', 0);
        if ($pkg_id && $product_id == $pkg_id) {
            if (empty($cart_item_data['batllie_extra_box'])) {
                wc_add_notice(__('Este producto de packaging solo se incluye automáticamente al armar una caja.', 'emp-caja'), 'error');
                return false;
            }
        }
        return $passed;
    }

    /**
     * Eliminación en Combo: si se elimina cualquier elemento de un pack en el carrito,
     * se eliminan automáticamente todos los demás productos que pertenecen a la misma caja/combo.
     */
    public static function handle_remove_grouped_pack_combo($cart_item_key, $cart) {
        static $is_removing_combo = false;
        if ($is_removing_combo) {
            return;
        }

        // Si estamos reemplazando un sabor agotado dentro del combo, omitir la eliminación atómica del resto del pack
        if (self::$is_replacing_combo_flavor) {
            return;
        }

        if (!isset($cart->cart_contents[$cart_item_key])) {
            return;
        }

        $removed_item = $cart->cart_contents[$cart_item_key];
        $pack_id      = !empty($removed_item['batllie_pack_instance_id']) ? $removed_item['batllie_pack_instance_id'] : '';

        if (empty($pack_id)) {
            return;
        }

        $is_removing_combo = true;

        // Recorrer el carrito y eliminar todos los demás ítems de la misma caja/combo
        $other_keys = array();
        foreach ($cart->cart_contents as $other_key => $other_item) {
            if ($other_key === $cart_item_key) {
                continue;
            }
            if (!empty($other_item['batllie_pack_instance_id']) && $other_item['batllie_pack_instance_id'] === $pack_id) {
                $other_keys[] = $other_key;
            }
        }

        foreach ($other_keys as $k) {
            $cart->remove_cart_item($k);
        }

        $is_removing_combo = false;
    }

    /**
     * Prevenir alterar cantidades individuales de un producto dentro de un pack en el carrito.
     * Si la cantidad es 0, se permite para que se ejecute la eliminación del combo completo.
     */
    public static function validate_cart_item_quantity_update($passed, $cart_item_key, $values, $quantity) {
        if (!empty($values['batllie_pack_instance_id'])) {
            // Si la cantidad es 0, es una acción de eliminar el ítem -> la permitimos para que se dispare la eliminación del combo
            if ($quantity <= 0) {
                return $passed;
            }

            // Si la cantidad intenta modificarse individualmente
            if (isset($values['quantity']) && (int) $values['quantity'] !== (int) $quantity) {
                wc_add_notice(
                    __('No es posible modificar las cantidades de los productos individuales de este combo. Para cambiar los sabores o cantidades, elimina el combo del carrito y vuelve a armarlo con tu nueva selección.', 'emp-caja'),
                    'error'
                );
                return false;
            }
        }
        return $passed;
    }

    /**
     * Mostrar información descriptiva del combo debajo del producto en carrito y checkout
     */
    public static function render_pack_cart_item_data($item_data, $cart_item) {
        if (!is_array($item_data)) {
            $item_data = array();
        }

        // 1. Si es la caja física de empaque del pack
        if (!empty($cart_item['batllie_extra_box'])) {
            $pack_id = !empty($cart_item['batllie_pack_instance_id']) ? esc_attr($cart_item['batllie_pack_instance_id']) : '';
            $marker  = '<span class="batllie-pack-marker batllie-box-marker batllie-pid-' . $pack_id . '" title="' . $pack_id . '" data-pack-id="' . $pack_id . '" style="display:none!important;"></span>';

            $item_data[] = array(
                'key'   => __('Empaque', 'emp-caja'),
                'value' => __('Caja Principal', 'emp-caja') . $marker,
            );
        }
        // 2. Si es producto hijo de un pack
        elseif (!empty($cart_item['batllie_parent_grouped_id'])) {
            $parent      = wc_get_product($cart_item['batllie_parent_grouped_id']);
            $parent_name = $parent ? $parent->get_name() : __('Combo / Caja', 'emp-caja');
            $pack_id     = !empty($cart_item['batllie_pack_instance_id']) ? esc_attr($cart_item['batllie_pack_instance_id']) : '';
            $has_box     = $pack_id && self::pack_has_active_box($pack_id);
            $marker      = $has_box ? '<span class="batllie-pack-marker batllie-child-marker batllie-pid-' . $pack_id . '" title="' . $pack_id . '" data-pack-id="' . $pack_id . '" style="display:none!important;"></span>' : '';

            $item_data[] = array(
                'key'   => __('Parte de', 'emp-caja'),
                'value' => esc_html($parent_name) . $marker,
            );
        }

        return $item_data;
    }

    /**
     * Ocultar precio en productos hijos solo si la caja principal activa lleva el precio consolidado
     */
    public static function filter_child_cart_item_price_display($price_html, $cart_item, $cart_item_key) {
        if (!empty($cart_item['batllie_parent_grouped_id']) && empty($cart_item['batllie_extra_box'])) {
            $pack_id = !empty($cart_item['batllie_pack_instance_id']) ? $cart_item['batllie_pack_instance_id'] : '';
            if ($pack_id && self::pack_has_active_box($pack_id)) {
                return '';
            }
        }
        return $price_html;
    }

    /**
     * Configurar el nombre y la imagen personalizada de la caja para Cart Blocks / Store API y carrito
     */
    public static function filter_cart_item_product_object($product, $cart_item) {
        if (!empty($cart_item['batllie_extra_box'])) {
            $custom_name = !empty($cart_item['batllie_box_custom_name']) ? $cart_item['batllie_box_custom_name'] : '';
            if (!$custom_name && !empty($cart_item['batllie_parent_grouped_id'])) {
                $custom_name = self::get_extra_box_name($cart_item['batllie_parent_grouped_id']);
            }
            if ($custom_name && is_object($product) && method_exists($product, 'set_name')) {
                $product->set_name($custom_name);
            }

            $img_id = !empty($cart_item['batllie_box_image_id']) ? $cart_item['batllie_box_image_id'] : 0;
            if (!$img_id && !empty($cart_item['batllie_parent_grouped_id'])) {
                $img_id = get_post_meta($cart_item['batllie_parent_grouped_id'], '_batllie_grouped_box_image_id', true);
            }
            if ($img_id && is_object($product) && method_exists($product, 'set_image_id')) {
                $product->set_image_id($img_id);
            }
        }
        return $product;
    }

    /**
     * Añadir clases CSS a las filas del carrito para identificar elementos de combo
     */
    public static function filter_cart_item_class($class, $cart_item, $cart_item_key) {
        if (!empty($cart_item['batllie_pack_instance_id'])) {
            $class .= ' batllie-combo-item batllie-pack-' . sanitize_html_class($cart_item['batllie_pack_instance_id']);
            if (!empty($cart_item['batllie_extra_box'])) {
                $class .= ' batllie-combo-box-item';
            } elseif (self::pack_has_active_box($cart_item['batllie_pack_instance_id'])) {
                $class .= ' batllie-combo-child-item';
            }
        }
        return $class;
    }

    /**
     * Ocultar enlace de eliminación en ítems hijos cuando hay caja de empaque activa.
     * Si no hay caja de empaque, cada producto mantiene su enlace de eliminación.
     */
    public static function filter_cart_item_remove_link($remove_link, $cart_item_key) {
        if (!function_exists('WC') || !WC()->cart || !isset(WC()->cart->cart_contents[$cart_item_key])) {
            return $remove_link;
        }

        $item = WC()->cart->cart_contents[$cart_item_key];
        if (!empty($item['batllie_parent_grouped_id']) && empty($item['batllie_extra_box'])) {
            $pack_id = !empty($item['batllie_pack_instance_id']) ? $item['batllie_pack_instance_id'] : '';
            if ($pack_id && self::pack_has_active_box($pack_id)) {
                return '';
            }
        }

        return $remove_link;
    }

    /**
     * Encolar CSS y JS específicos para el carrito y checkout
     */
    public static function enqueue_cart_assets() {
        if (!function_exists('is_cart') || (!is_cart() && !is_checkout())) {
            return;
        }

        wp_enqueue_style(
            'batllie-caja-cart-css',
            EMP_CAJA_URL . 'assets/css/caja-cart.css',
            array(),
            EMP_CAJA_VERSION
        );

        wp_enqueue_script(
            'batllie-caja-cart-js',
            EMP_CAJA_URL . 'assets/js/caja-cart.js',
            array('jquery'),
            EMP_CAJA_VERSION,
            true
        );

        wp_localize_script('batllie-caja-cart-js', 'batllieCartConfig', array(
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('batllie_caja_nonce'),
            'paymentDiscounts' => self::get_payment_discounts_config(),
            'i18n'             => array(
                'removeComboTooltip' => __('Eliminar combo completo', 'emp-caja'),
                'lockedNotice'       => __('La cantidad de este producto está fijada por la caja. Para modificarla, elimina el combo.', 'emp-caja'),
                'includedFlavors'    => __('Sabores incluidos:', 'emp-caja'),
            ),
        ));
    }

    /**
     * Filtrar el conteo de ítems en el carrito para WooCommerce, Store API, Badges y Mini-Cart:
     * 1. Una caja agrupada (pack) con sus alfajores componentes cuenta estrictamente como 1 solo ítem.
     * 2. Los alfajores elegidos sueltos (ej: 6 alfajores) cuentan según las unidades elegidas (6),
     *    y no 7 si se incluye una caja física de packaging a $0.
     *
     * @param int $count
     * @return int
     */
    public static function filter_cart_contents_count($count) {
        if (!function_exists('WC') || !WC()->cart) {
            return $count;
        }

        $cart_contents = WC()->cart->get_cart();
        if (empty($cart_contents)) {
            return 0;
        }

        $packs = array();
        $loose_deduct = 0;

        foreach ($cart_contents as $key => $item) {
            $pack_id = !empty($item['batllie_pack_instance_id']) ? $item['batllie_pack_instance_id'] : '';
            $qty     = !empty($item['quantity']) ? (int) $item['quantity'] : 1;
            $is_box  = !empty($item['batllie_extra_box']);
            $prod_id = !empty($item['product_id']) ? (int) $item['product_id'] : 0;

            if (!empty($pack_id)) {
                if (!isset($packs[$pack_id])) {
                    $packs[$pack_id] = array('has_box' => false, 'box_qty' => 0, 'child_qty' => 0);
                }
                if ($is_box) {
                    $packs[$pack_id]['has_box'] = true;
                    $packs[$pack_id]['box_qty'] += $qty;
                } else {
                    $packs[$pack_id]['child_qty'] += $qty;
                }
            } else {
                // Producto sin pack_id: verificar si es packaging incluido a costo cero
                $is_pkg = $is_box || ($prod_id && (
                    $prod_id === (int) get_option('_batllie_packaging_product_id', 0) ||
                    $prod_id === (int) get_option('_batllie_packing_box_6_id', 0) ||
                    $prod_id === (int) get_option('_batllie_packing_box_12_id', 0)
                ));
                $price = isset($item['data']) ? floatval($item['data']->get_price()) : 0;
                if ($is_pkg && $price <= 0) {
                    $loose_deduct += $qty;
                }
            }
        }

        $pack_deduct = 0;
        foreach ($packs as $pack_info) {
            if ($pack_info['has_box']) {
                // La caja de empaque ya cuenta como box_qty (ej: 1).
                // Todos los alfajores hijos se descuentan para que la caja completa cuente como 1 solo ítem.
                $pack_deduct += $pack_info['child_qty'];
            } else {
                // No hay ítem de caja; todos los alfajores componen 1 pack.
                // Se descuenta (total_hijos - 1) para que el grupo cuente como 1 solo ítem.
                $pack_deduct += max(0, $pack_info['child_qty'] - 1);
            }
        }

        return max(0, $count - ($pack_deduct + $loose_deduct));
    }

    /**
     * Sincronizar fragmentos AJAX de WooCommerce para asegurar que los badges de carrito
     * (#mini-cart-count y #mini-cart-count-footer) reflejen el conteo unificado.
     *
     * @param array $fragments
     * @return array
     */
    public static function filter_cart_fragments($fragments) {
        if (!function_exists('WC') || !WC()->cart) {
            return $fragments;
        }

        $count = WC()->cart->get_cart_contents_count();
        $fragments['#mini-cart-count'] = '<div id="mini-cart-count" class="emp-mini-cart-count">' . $count . '</div>';
        $fragments['#mini-cart-count-footer'] = '<div id="mini-cart-count-footer" class="emp-mini-cart-count icon-color">' . $count . '</div>';

        return $fragments;
    }

    /**
     * =========================================================================
     * GESTIÓN DE REEMPLAZO RÁPIDO DE SABORES AGOTADOS EN COMBOS (ALTERNATIVA A)
     * =========================================================================
     */

    /**
     * Verificar si existen sabores agotados en combos dentro del carrito
     *
     * @return bool
     */
    public static function has_cart_combo_replacements_needed() {
        $needed = self::get_cart_combo_replacements_needed();
        return !empty($needed);
    }

    /**
     * Detectar si en el carrito hay productos pertenecientes a un combo o pack
     * cuyo sabor se haya quedado sin stock suficiente.
     *
     * @return array
     */
    public static function get_cart_combo_replacements_needed() {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return array();
        }

        $cart_contents = WC()->cart->get_cart();
        if (empty($cart_contents)) {
            return array();
        }

        // 1. Mapear totales en carrito por producto
        $cart_totals = array();
        foreach ($cart_contents as $key => $item) {
            $p_id = !empty($item['product_id']) ? absint($item['product_id']) : 0;
            if ($p_id > 0) {
                $cart_totals[$p_id] = ($cart_totals[$p_id] ?? 0) + (int) $item['quantity'];
            }
        }

        $replacements_needed = array();

        foreach ($cart_contents as $cart_item_key => $cart_item) {
            if (empty($cart_item['batllie_pack_instance_id'])) {
                continue;
            }
            if (!empty($cart_item['batllie_extra_box'])) {
                continue; // La caja física de empaque no es un sabor
            }

            $product_id = absint($cart_item['product_id']);
            $product    = !empty($cart_item['data']) && is_object($cart_item['data']) ? $cart_item['data'] : wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $qty_needed = (int) $cart_item['quantity'];
            $pack_id    = $cart_item['batllie_pack_instance_id'];
            $parent_id  = !empty($cart_item['batllie_parent_grouped_id']) ? absint($cart_item['batllie_parent_grouped_id']) : 0;
            $parent     = $parent_id ? wc_get_product($parent_id) : null;
            $combo_name = $parent ? $parent->get_name() : __('Combo / Caja', 'emp-caja');

            $is_in_stock = $product->is_in_stock();
            $managing    = $product->managing_stock();
            $stock_qty   = $managing ? (int) $product->get_stock_quantity() : 999;
            $backorders  = $product->backorders_allowed();

            $has_enough = true;
            if (!$backorders) {
                if (!$is_in_stock || ($managing && $stock_qty < $qty_needed)) {
                    $has_enough = false;
                } elseif ($managing && isset($cart_totals[$product_id]) && $cart_totals[$product_id] > $stock_qty) {
                    $has_enough = false;
                }
            }

            if (!$has_enough) {
                $options = self::get_candidate_replacements_for_pack($product_id, $qty_needed, $parent);

                $img_id  = $product->get_image_id();
                $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

                $replacements_needed[] = array(
                    'cart_item_key'     => $cart_item_key,
                    'depleted_id'       => $product_id,
                    'depleted_name'     => $product->get_name(),
                    'depleted_clean'    => trim(str_ireplace('Alfajor', '', $product->get_name())),
                    'depleted_qty'      => $qty_needed,
                    'depleted_image'    => $img_url,
                    'pack_instance_id'  => $pack_id,
                    'parent_grouped_id' => $parent_id,
                    'combo_name'        => $combo_name,
                    'options'           => $options,
                );
            }
        }

        return $replacements_needed;
    }

    /**
     * Buscar sabores activos y con stock suficiente para ofrecer como reemplazo
     *
     * @param int $depleted_product_id
     * @param int $qty_needed
     * @param WC_Product|null $parent_product
     * @return array
     */
    public static function get_candidate_replacements_for_pack($depleted_product_id, $qty_needed = 1, $parent_product = null) {
        $options = array();
        $seen_ids = array();

        // 1. Priorizar alfajores que forman parte del mismo producto agrupado padre
        if ($parent_product && method_exists($parent_product, 'is_type') && $parent_product->is_type('grouped')) {
            $children_ids = $parent_product->get_children();
            if (!empty($children_ids) && is_array($children_ids)) {
                foreach ($children_ids as $cid) {
                    $cid = absint($cid);
                    if ($cid === absint($depleted_product_id) || isset($seen_ids[$cid])) {
                        continue;
                    }
                    $child_prod = wc_get_product($cid);
                    if (!$child_prod || !$child_prod->is_purchasable() || !$child_prod->is_in_stock()) {
                        continue;
                    }
                    $vis = method_exists($child_prod, 'get_catalog_visibility') ? $child_prod->get_catalog_visibility() : 'visible';
                    if ($vis === 'hidden' || !$child_prod->is_visible()) {
                        continue;
                    }
                    $avail = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_product_available_stock($child_prod) : ($child_prod->get_stock_quantity() ?: 999);
                    if ($avail < $qty_needed) {
                        continue;
                    }

                    $img_id = $child_prod->get_image_id();
                    $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

                    $options[] = array(
                        'id'              => $cid,
                        'name'            => $child_prod->get_name(),
                        'clean_name'      => trim(str_ireplace('Alfajor', '', $child_prod->get_name())),
                        'price'           => floatval($child_prod->get_price()),
                        'price_fmt'       => wc_price($child_prod->get_price()),
                        'image'           => $img_url,
                        'remaining_stock' => $avail,
                    );
                    $seen_ids[$cid] = true;
                }
            }
        }

        // 2. Complementar con el catálogo general de alfajores en stock de la tienda
        if (class_exists('Batllie_Caja_Packing')) {
            $store_alfajores = Batllie_Caja_Packing::get_available_alfajores_for_upsell();
            foreach ($store_alfajores as $alf) {
                $aid = absint($alf['id']);
                if ($aid === absint($depleted_product_id) || isset($seen_ids[$aid])) {
                    continue;
                }
                if ($alf['remaining_stock'] < $qty_needed) {
                    continue;
                }
                $options[] = $alf;
                $seen_ids[$aid] = true;
            }
        }

        return $options;
    }

    /**
     * Renderizar aviso destacado superior en el carrito/checkout si hay sabores agotados en un combo
     */
    public static function render_cart_replacement_warning() {
        $replacements = self::get_cart_combo_replacements_needed();
        if (empty($replacements)) {
            return;
        }

        $rep = reset($replacements);
        ?>
        <div class="batllie-combo-replacement-alert">
            <div class="batllie-replacement-alert-icon">⚠️</div>
            <div class="batllie-replacement-alert-content">
                <div class="batllie-replacement-alert-title">
                    <?php echo sprintf(
                        __('¡Atención! El sabor <strong>%s</strong> (%d u.) de tu <strong>%s</strong> se acaba de agotar.', 'emp-caja'),
                        esc_html($rep['depleted_name']),
                        $rep['depleted_qty'],
                        esc_html($rep['combo_name'])
                    ); ?>
                </div>
                <div class="batllie-replacement-alert-sub">
                    <?php _e('Para no perder tu caja y poder avanzar al pago, elegí por qué sabor reemplazarlo.', 'emp-caja'); ?>
                </div>
            </div>
            <button type="button" class="batllie-replacement-alert-action-btn batllie-open-replacement-modal">
                <?php _e('Elegir reemplazo', 'emp-caja'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Renderizar el Modal de Reemplazo Rápido en Carrito y Checkout
     */
    public static function render_combo_replacement_modal() {
        if (self::$replacement_modal_rendered) {
            return;
        }

        $replacements = self::get_cart_combo_replacements_needed();
        if (empty($replacements)) {
            return;
        }

        self::$replacement_modal_rendered = true;
        $rep = reset($replacements);
        $options = $rep['options'];
        $count_needed = count($replacements);
        ?>
        <div id="batllie-combo-replacement-backdrop" class="batllie-combo-replacement-backdrop" style="display:flex;" aria-hidden="false">
            <div id="batllie-combo-replacement-modal" class="batllie-combo-replacement-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Reemplazo de sabor agotado', 'emp-caja'); ?>">
                <button type="button" class="batllie-replacement-modal-close" id="batllie-replacement-modal-close-btn" aria-label="<?php esc_attr_e('Cerrar aviso', 'emp-caja'); ?>">&times;</button>

                <div class="batllie-replacement-modal-head">
                    <div class="batllie-replacement-head-badge">
                        <span>⚠️ <?php _e('Sabor Agotado en tu Caja', 'emp-caja'); ?></span>
                        <?php if ($count_needed > 1): ?>
                            <span class="batllie-replacement-count-badge"><?php echo sprintf(__('1 de %d pendientes', 'emp-caja'), $count_needed); ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="batllie-replacement-modal-title">
                        <?php echo esc_html($rep['combo_name']); ?>
                    </h3>
                    <div class="batllie-replacement-depleted-box">
                        <?php if (!empty($rep['depleted_image'])): ?>
                            <img src="<?php echo esc_url($rep['depleted_image']); ?>" alt="" class="batllie-depleted-thumb" />
                        <?php endif; ?>
                        <div class="batllie-depleted-info">
                            <span class="batllie-depleted-label"><?php _e('Se agotó:', 'emp-caja'); ?></span>
                            <strong class="batllie-depleted-name"><?php echo esc_html($rep['depleted_name']); ?></strong>
                            <span class="batllie-depleted-qty"><?php echo sprintf(__('(Cantidad a reemplazar: %d u.)', 'emp-caja'), $rep['depleted_qty']); ?></span>
                        </div>
                    </div>
                    <p class="batllie-replacement-instruction">
                        <?php _e('Elegí por cuál de estos sabores disponibles querés reemplazarlo para completar tu caja y avanzar al pago:', 'emp-caja'); ?>
                    </p>
                </div>

                <div class="batllie-replacement-modal-body">
                    <?php if (!empty($options)): ?>
                        <div class="batllie-replacement-grid" id="batllie-replacement-grid">
                            <?php foreach ($options as $opt): ?>
                                <div class="batllie-replacement-card" data-replacement-id="<?php echo esc_attr($opt['id']); ?>" data-cart-key="<?php echo esc_attr($rep['cart_item_key']); ?>" data-pack-id="<?php echo esc_attr($rep['pack_instance_id']); ?>">
                                    <?php if (!empty($opt['image'])): ?>
                                        <img src="<?php echo esc_url($opt['image']); ?>" alt="<?php echo esc_attr($opt['clean_name']); ?>" class="batllie-replacement-thumb" />
                                    <?php endif; ?>
                                    <div class="batllie-replacement-card-info">
                                        <strong class="batllie-replacement-card-name"><?php echo esc_html($opt['clean_name']); ?></strong>
                                        <span class="batllie-replacement-card-stock"><?php echo sprintf(__('%d u. disponibles', 'emp-caja'), $opt['remaining_stock']); ?></span>
                                    </div>
                                    <button type="button" class="batllie-replacement-select-btn" data-replacement-id="<?php echo esc_attr($opt['id']); ?>" data-cart-key="<?php echo esc_attr($rep['cart_item_key']); ?>" data-pack-id="<?php echo esc_attr($rep['pack_instance_id']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Elegir %s', 'emp-caja'), $opt['clean_name'])); ?>">
                                        <span class="btn-text"><?php _e('Elegir', 'emp-caja'); ?></span>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="batllie-replacement-no-stock">
                            <p><?php _e('No hay otros sabores con stock suficiente en este momento.', 'emp-caja'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="batllie-replacement-modal-foot">
                    <button type="button" class="batllie-replacement-remove-combo-btn" data-cart-key="<?php echo esc_attr($rep['cart_item_key']); ?>">
                        <span><?php _e('Quitar este combo del carrito', 'emp-caja'); ?></span>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Endpoint AJAX: Reemplazar un sabor agotado dentro de un combo por otro disponible
     */
    public static function ajax_replace_combo_flavor() {
        check_ajax_referer('batllie_caja_nonce', 'nonce');

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(array('message' => __('No se encontró el carrito.', 'emp-caja')));
        }

        $cart_key = isset($_POST['depleted_cart_item_key']) ? sanitize_text_field(wp_unslash($_POST['depleted_cart_item_key'])) : '';
        $new_id   = isset($_POST['replacement_product_id']) ? absint($_POST['replacement_product_id']) : 0;
        $pack_id  = isset($_POST['pack_instance_id']) ? sanitize_text_field(wp_unslash($_POST['pack_instance_id'])) : '';

        $cart = WC()->cart;
        $cart_contents = $cart->get_cart();

        if (!isset($cart_contents[$cart_key])) {
            wp_send_json_error(array('message' => __('El ítem a reemplazar ya no se encuentra en el carrito.', 'emp-caja')));
        }

        $old_item  = $cart_contents[$cart_key];
        $quantity  = (int) $old_item['quantity'];
        $parent_id = !empty($old_item['batllie_parent_grouped_id']) ? absint($old_item['batllie_parent_grouped_id']) : 0;
        $item_pack = !empty($old_item['batllie_pack_instance_id']) ? $old_item['batllie_pack_instance_id'] : '';

        if ($pack_id && $item_pack && $pack_id !== $item_pack) {
            wp_send_json_error(array('message' => __('El identificador del combo no coincide.', 'emp-caja')));
        }

        $new_product = wc_get_product($new_id);
        if (!$new_product || !$new_product->is_purchasable() || !$new_product->is_in_stock()) {
            wp_send_json_error(array('message' => __('El sabor elegido no está disponible.', 'emp-caja')));
        }

        // Comprobar stock disponible del reemplazo
        if (class_exists('Batllie_Caja_Packing')) {
            $avail = Batllie_Caja_Packing::get_product_available_stock($new_product);
            if ($avail < $quantity) {
                wp_send_json_error(array('message' => sprintf(__('Solo quedan %d unidades de este sabor, se necesitan %d.', 'emp-caja'), $avail, $quantity)));
            }
        }

        // ACTIVAR BYPASS para no eliminar el resto del combo al quitar este sabor
        self::$is_replacing_combo_flavor = true;
        try {
            $cart->remove_cart_item($cart_key);
        } finally {
            self::$is_replacing_combo_flavor = false;
        }

        // Preparar cart_item_data con el mismo pack_instance_id y parent_grouped_id
        $replacement_cart_data = array(
            'batllie_parent_grouped_id' => $parent_id,
            'batllie_pack_instance_id'  => $item_pack,
            'batllie_replaced_flavor'   => true,
        );

        // Añadir nuevo sabor al combo
        $added_key = $cart->add_to_cart($new_id, $quantity, 0, array(), $replacement_cart_data);

        if (!$added_key) {
            wp_send_json_error(array('message' => __('No se pudo agregar el sabor de reemplazo.', 'emp-caja')));
        }

        // Sincronizar precios y recalcular totales
        self::sync_and_price_extra_boxes($cart);
        $cart->calculate_totals();

        // Añadir notice de éxito
        wc_add_notice(
            sprintf(
                __('¡Reemplazaste con éxito por "%s" (%d u.) en tu combo! Ya podés continuar con tu compra.', 'emp-caja'),
                $new_product->get_name(),
                $quantity
            ),
            'success'
        );

        $referer = wp_get_referer();
        $redirect = ($referer && strpos($referer, 'checkout') !== false) ? wc_get_checkout_url() : wc_get_cart_url();

        wp_send_json_success(array(
            'message'  => __('Sabor reemplazado con éxito.', 'emp-caja'),
            'redirect' => $redirect,
        ));
    }

    /**
     * Endpoint AJAX: Quitar un combo completo si el usuario prefiere no reemplazar el sabor
     */
    public static function ajax_remove_combo_pack() {
        check_ajax_referer('batllie_caja_nonce', 'nonce');

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(array('message' => __('No se encontró el carrito.', 'emp-caja')));
        }

        $cart_key = isset($_POST['cart_item_key']) ? sanitize_text_field(wp_unslash($_POST['cart_item_key'])) : '';
        if (!$cart_key || !isset(WC()->cart->cart_contents[$cart_key])) {
            wp_send_json_error(array('message' => __('Ítem no encontrado.', 'emp-caja')));
        }

        WC()->cart->remove_cart_item($cart_key);
        WC()->cart->calculate_totals();

        wc_add_notice(__('Se quitó el combo del carrito.', 'emp-caja'), 'notice');

        wp_send_json_success(array('redirect' => wc_get_cart_url()));
    }

    /**
     * Obtener configuración de descuentos por medio de pago (ej: 15% en Transferencia)
     *
     * @return array
     */
    public static function get_payment_discounts_config() {
        $percent = 15;
        if (function_exists('emp_get_list_price_discount_percent')) {
            $percent = emp_get_list_price_discount_percent();
        } elseif (function_exists('get_theme_mod')) {
            $p = (float) get_theme_mod('emp_wc_list_price_discount_percent', 15);
            if ($p > 0) {
                $percent = $p;
            }
        }

        $gateways = array();
        if (function_exists('WC') && WC()->payment_gateways()) {
            $available = WC()->payment_gateways()->get_available_payment_gateways();
            foreach ($available as $gid => $g) {
                if (function_exists('emp_is_discount_gateway')) {
                    if (emp_is_discount_gateway($gid)) {
                        $gateways[] = $gid;
                    }
                } elseif ($gid === 'bacs') {
                    $gateways[] = 'bacs';
                }
            }
        }
        if (empty($gateways)) {
            $gateways = array('bacs');
        }

        return array(
            'enabled'  => true,
            'percent'  => $percent,
            'badge'    => '-' . $percent . '%',
            'gateways' => array_values(array_unique($gateways)),
        );
    }
}

