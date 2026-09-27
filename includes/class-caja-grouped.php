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

        // Encolar assets específicos de carrito y checkout
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_cart_assets'));
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

        $val = get_post_meta($product->get_id(), '_batllie_grouped_fixed_price', true);
        if ($val !== '' && is_numeric($val) && floatval($val) > 0) {
            return floatval($val);
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
     * Obtener la cantidad requerida para un producto agrupado
     * 1. Consulta post meta `_batllie_grouped_target_qty`
     * 2. Si no está definido, detecta automáticamente del título
     * 3. Si es un producto agrupado en Batllié, por defecto es una caja de 6 unidades
     */
    public static function get_target_qty($product) {
        if (!is_a($product, 'WC_Product')) {
            $product = wc_get_product($product);
        }
        if (!$product || !$product->is_type('grouped')) {
            return 0;
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

        // Predeterminado para cualquier producto agrupado / caja en Batllié
        return 6;
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

        echo '<div class="options_group show_if_grouped" style="background:#f0fdf4; padding:16px 18px; border-left:4px solid #10b981; margin:15px 0; border-radius:4px;">';
        echo '<p style="margin:0 0 12px 0; font-weight:700; color:#065f46; font-size:14px; display:flex; align-items:center; gap:8px;"><span style="font-size:18px;">📦</span> ' . __('Configuración de Caja Batllié (Pack Agrupado)', 'emp-caja') . '</p>';

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

        echo '</div>';
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

        wp_localize_script('batllie-caja-grouped-js', 'batllieGroupedConfig', array(
            'productId'       => $product->get_id(),
            'targetQty'       => $target_qty,
            'isFixedPrice'    => ($fixed_price > 0),
            'fixedPrice'      => $fixed_price,
            'childrenPrices'  => $children_prices,
            'currencySymbol'  => html_entity_decode(get_woocommerce_currency_symbol()),
            'decimalSep'      => wc_get_price_decimal_separator(),
            'thousandSep'     => wc_get_price_thousand_separator(),
            'i18n'            => array(
                'boxEmpty'     => __('Caja vacía', 'emp-caja'),
                'units'        => __('unidades', 'emp-caja'),
                'unitSingular' => __('unidad', 'emp-caja'),
                'boxComplete'  => __('¡Caja completa!', 'emp-caja'),
                'selectPrompt' => $target_qty > 0 ? sprintf(__('Seleccioná %d unidades para armar tu caja.', 'emp-caja'), $target_qty) : __('Seleccioná los productos que deseas incluir.', 'emp-caja'),
                'needMore'     => __('Te falta %d %s para completar tu caja de %d.', 'emp-caja'),
                'exactWarning' => __('Debes incluir exactamente %d unidades para armar tu caja. Actualmente seleccionaste %d (te falta %d).', 'emp-caja'),
                'exactExceed'  => __('Debes incluir exactamente %d unidades para armar tu caja. Actualmente seleccionaste %d.', 'emp-caja'),
                'maxReached'   => sprintf(__('Ya alcanzaste el máximo de %d unidades para esta caja.', 'emp-caja'), $target_qty),
                'totalLabel'   => __('Total de tu caja:', 'emp-caja'),
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

        if ($target_qty <= 0 && $fixed_price <= 0) {
            return;
        }

        $initial_price_html = $fixed_price > 0 ? wc_price($fixed_price) : wc_price(0);
        ?>
        <div class="batllie-grouped-box-container" id="batllie-grouped-box-container" data-target-qty="<?php echo esc_attr($target_qty); ?>" data-fixed-price="<?php echo esc_attr($fixed_price); ?>">
            <!-- Encabezado de Progreso de la Caja -->
            <div class="batllie-box-header">
                <div class="batllie-box-title-wrap">
                    <span class="batllie-box-icon">📦</span>
                    <span class="batllie-box-heading"><?php _e('Armá tu caja', 'emp-caja'); ?></span>
                </div>
                <div class="batllie-box-status-badge" id="batllie-box-badge">
                    <?php echo $target_qty > 0 ? __('Caja vacía', 'emp-caja') : __('Personaliza tu selección', 'emp-caja'); ?>
                </div>
            </div>

            <?php if ($target_qty > 0) : ?>
            <!-- Contador y Barra de Progreso -->
            <div class="batllie-box-progress-wrap">
                <div class="batllie-box-count-text">
                    <span class="batllie-box-selected" id="batllie-box-current">0</span>
                    <span class="batllie-box-divider">/</span>
                    <span class="batllie-box-total" id="batllie-box-target"><?php echo esc_html($target_qty); ?></span>
                    <span class="batllie-box-label"><?php _e('unidades elegidas', 'emp-caja'); ?></span>
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
                <span class="batllie-box-summary-label"><?php _e('Total de tu caja:', 'emp-caja'); ?></span>
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

        $box_cart_data = array(
            'batllie_extra_box'         => true,
            'batllie_parent_grouped_id' => $parent_id,
            'batllie_pack_instance_id'  => $pack_instance_id,
            'batllie_box_custom_name'   => $custom_name,
        );

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

            if ($fixed_price > 0) {
                // Hay precio fijo configurado para este producto agrupado
                if ($pack_box_key && $disp_mode === 'box') {
                    // Modo Caja: La caja lleva el precio fijo completo y los alfajores $0 (incluidos)
                    if (isset($cart->cart_contents[$pack_box_key]['data'])) {
                        $cart->cart_contents[$pack_box_key]['data']->set_price($fixed_price);
                    }
                    foreach ($pack_children as $c_key => $c_item) {
                        if (isset($cart->cart_contents[$c_key]['data'])) {
                            $cart->cart_contents[$c_key]['data']->set_price(0);
                        }
                    }
                } else {
                    // Modo Distribuido (o no hay caja de empaque):
                    // Repartir el precio fijo equitativamente entre los alfajores
                    if ($pack_box_key && isset($cart->cart_contents[$pack_box_key]['data'])) {
                        $cart->cart_contents[$pack_box_key]['data']->set_price(0);
                    }

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
            } else {
                // Precio dinámico: La caja siempre es $0 y los alfajores mantienen su precio unitario normal
                if ($pack_box_key && isset($cart->cart_contents[$pack_box_key]['data'])) {
                    $cart->cart_contents[$pack_box_key]['data']->set_price(0);
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
            return esc_html($custom_name) . $badge;
        }
        return $name;
    }

    /**
     * Usar la imagen de la caja del producto agrupado en el carrito en lugar de un placeholder
     */
    public static function filter_extra_box_thumbnail($thumbnail, $cart_item, $cart_item_key) {
        if (!empty($cart_item['batllie_extra_box']) && !empty($cart_item['batllie_parent_grouped_id'])) {
            $parent_product = wc_get_product($cart_item['batllie_parent_grouped_id']);
            if ($parent_product && $parent_product->get_image_id()) {
                return wp_get_attachment_image($parent_product->get_image_id(), 'woocommerce_thumbnail');
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
        } else {
            // Productos individuales que componen el pack / caja
            if (!empty($values['batllie_parent_grouped_id'])) {
                $item->add_meta_data('_batllie_parent_grouped_id', $values['batllie_parent_grouped_id'], true);
            }
            if (!empty($values['batllie_pack_instance_id'])) {
                $item->add_meta_data('_batllie_pack_instance_id', $values['batllie_pack_instance_id'], true);
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

        // Si es producto hijo de un pack
        if (!empty($cart_item['batllie_parent_grouped_id']) && empty($cart_item['batllie_extra_box'])) {
            $parent = wc_get_product($cart_item['batllie_parent_grouped_id']);
            $parent_name = $parent ? $parent->get_name() : __('Combo / Caja', 'emp-caja');

            $item_data[] = array(
                'key'   => __('Parte de', 'emp-caja'),
                'value' => esc_html($parent_name),
            );

            // Si el precio de este producto es 0 porque el importe está cargado en la caja
            if (isset($cart_item['data']) && floatval($cart_item['data']->get_price()) === 0.0) {
                $item_data[] = array(
                    'key'   => __('Precio', 'emp-caja'),
                    'value' => __('Incluido en la caja', 'emp-caja'),
                );
            }
        }

        return $item_data;
    }

    /**
     * Añadir clases CSS a las filas del carrito para identificar elementos de combo
     */
    public static function filter_cart_item_class($class, $cart_item, $cart_item_key) {
        if (!empty($cart_item['batllie_pack_instance_id'])) {
            $class .= ' batllie-combo-item batllie-pack-' . sanitize_html_class($cart_item['batllie_pack_instance_id']);
            if (!empty($cart_item['batllie_extra_box'])) {
                $class .= ' batllie-combo-box-item';
            } else {
                $class .= ' batllie-combo-child-item';
            }
        }
        return $class;
    }

    /**
     * Ocultar enlace de eliminación en ítems hijos cuando hay caja de empaque.
     * El botón de eliminar solo debe mostrarse en la caja principal.
     */
    public static function filter_cart_item_remove_link($remove_link, $cart_item_key) {
        if (!function_exists('WC') || !WC()->cart || !isset(WC()->cart->cart_contents[$cart_item_key])) {
            return $remove_link;
        }

        $item = WC()->cart->cart_contents[$cart_item_key];
        // Si es un ítem hijo de un combo y no es la caja, no mostrar botón de eliminar
        if (!empty($item['batllie_parent_grouped_id']) && empty($item['batllie_extra_box'])) {
            return '';
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
            'i18n' => array(
                'removeComboTooltip' => __('Eliminar combo completo', 'emp-caja'),
                'lockedNotice'       => __('La cantidad de este producto está fijada por la caja. Para modificarla, elimina el combo.', 'emp-caja'),
            ),
        ));
    }
}

