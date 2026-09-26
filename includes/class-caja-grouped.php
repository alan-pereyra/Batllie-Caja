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

    public static function init() {
        // Campo en Administración de WooCommerce (panel de edición de producto)
        add_action('woocommerce_product_options_grouped', array(__CLASS__, 'add_admin_target_qty_field'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_admin_target_qty_field'));

        // Encolar scripts y estilos en la página del producto agrupado
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend_assets'));

        // Inyectar interfaz de control y progreso dentro del formulario del producto agrupado
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_grouped_box_controls'), 15);

        // Validación en el servidor (Seguridad PHP en wp_loaded antes del handler de WooCommerce)
        add_action('wp_loaded', array(__CLASS__, 'validate_grouped_add_to_cart'), 10);
    }

    /**
     * Obtener la cantidad requerida para un producto agrupado
     * 1. Consulta post meta `_batllie_grouped_target_qty`
     * 2. Si no está definido, busca patrones como "x 6", "x 12", "x6", "x12" en el título
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
            return absint($meta_val);
        }

        // Detección automática por título (ej: "Caja Batllié Degustación x 6 unidades")
        $title = $product->get_name();
        if (preg_match('/\b(?:x|de)\s*(\d{1,2})\b/i', $title, $matches)) {
            return absint($matches[1]);
        }

        return 0;
    }

    /**
     * Campo en la pestaña "Productos enlazados / Agrupados" en wp-admin
     */
    public static function add_admin_target_qty_field() {
        global $post;
        $current_val = get_post_meta($post->ID, '_batllie_grouped_target_qty', true);

        // Autodetección como placeholder sugerido
        $title = get_the_title($post->ID);
        $suggested = 0;
        if (preg_match('/\b(?:x|de)\s*(\d{1,2})\b/i', $title, $m)) {
            $suggested = absint($m[1]);
        }

        echo '<div class="options_group show_if_grouped" style="background:#f8fafc; padding:10px 15px; border-left:4px solid #10b981; margin:15px 0;">';
        woocommerce_wp_text_input(array(
            'id'          => '_batllie_grouped_target_qty',
            'label'       => __('Cantidad fija del pack / caja', 'emp-caja'),
            'placeholder' => $suggested > 0 ? sprintf(__('Autodetectado: %d (o ingresa un número)', 'emp-caja'), $suggested) : __('Ej: 6', 'emp-caja'),
            'description' => __('Número exacto de unidades que el cliente debe seleccionar para poder comprar el pack (ej: 6 para caja de 6). El botón de añadir al carrito se bloqueará hasta alcanzar exactamente este número.', 'emp-caja'),
            'desc_tip'    => true,
            'type'        => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min'  => '0',
            ),
            'value'       => $current_val,
        ));
        echo '</div>';
    }

    /**
     * Guardar el campo de cantidad requerida
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
    }

    /**
     * Encolar CSS y JS en frontend para productos agrupados con cantidad fija
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
        if ($target_qty <= 0) {
            return;
        }

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
        wp_localize_script('batllie-caja-grouped-js', 'batllieGroupedConfig', array(
            'productId'       => $product->get_id(),
            'targetQty'       => $target_qty,
            'childrenPrices'  => $children_prices,
            'currencySymbol'  => html_entity_decode(get_woocommerce_currency_symbol()),
            'decimalSep'      => wc_get_price_decimal_separator(),
            'thousandSep'     => wc_get_price_thousand_separator(),
            'i18n'            => array(
                'boxEmpty'     => __('Caja vacía', 'emp-caja'),
                'units'        => __('unidades', 'emp-caja'),
                'unitSingular' => __('unidad', 'emp-caja'),
                'boxComplete'  => __('¡Caja completa!', 'emp-caja'),
                'selectPrompt' => sprintf(__('Seleccioná %d unidades para armar tu caja.', 'emp-caja'), $target_qty),
                'needMore'     => __('Te falta %d %s para completar tu caja de %d.', 'emp-caja'),
                'exactWarning' => __('Debes incluir exactamente %d unidades para armar tu caja. Actualmente seleccionaste %d (te falta %d).', 'emp-caja'),
                'exactExceed'  => __('Debes incluir exactamente %d unidades para armar tu caja. Actualmente seleccionaste %d.', 'emp-caja'),
                'maxReached'   => __('Ya alcanzaste el máximo de %d unidades para esta caja.', 'emp-caja'),
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
        if ($target_qty <= 0) {
            return;
        }

        ?>
        <div class="batllie-grouped-box-container" id="batllie-grouped-box-container" data-target-qty="<?php echo esc_attr($target_qty); ?>">
            <!-- Encabezado de Progreso de la Caja -->
            <div class="batllie-box-header">
                <div class="batllie-box-title-wrap">
                    <span class="batllie-box-icon">📦</span>
                    <span class="batllie-box-heading"><?php _e('Armá tu caja', 'emp-caja'); ?></span>
                </div>
                <div class="batllie-box-status-badge" id="batllie-box-badge">
                    <?php _e('Caja vacía', 'emp-caja'); ?>
                </div>
            </div>

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

            <!-- Resumen de Precio Total Dinámico -->
            <div class="batllie-box-summary-row">
                <span class="batllie-box-summary-label"><?php _e('Total de tu caja:', 'emp-caja'); ?></span>
                <span class="batllie-box-summary-price" id="batllie-box-total-price">
                    <?php echo wc_price(0); ?>
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
     * Validación en el servidor: rechazar si la cantidad de ítems no coincide exactamente con target_qty
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
        if ($target_qty <= 0) {
            return;
        }

        $quantities = isset($_REQUEST['quantity']) && is_array($_REQUEST['quantity']) ? wp_unslash($_REQUEST['quantity']) : array();
        $total_qty  = 0;
        foreach ($quantities as $child_id => $qty) {
            $total_qty += max(0, intval($qty));
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
}
