<?php
/**
 * Controlador de Monto Mínimo de Compra en la Tienda
 * Permite configurar un monto mínimo de compra en WooCommerce,
 * bloquea la finalización de compra si no se alcanza,
 * e informa al cliente en tiempo real cuánto le falta para llegar al mínimo.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Min_Order {

    private static $banner_rendered = false;

    public static function init() {
        // Encolar assets frontend (CSS y JS)
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

        // Limpiar notices de monto mínimo almacenados previamente en la sesión de WooCommerce
        add_action('init', array(__CLASS__, 'clear_min_order_notices'), 1);
        add_action('wp', array(__CLASS__, 'clear_min_order_notices'), 1);
        add_action('woocommerce_init', array(__CLASS__, 'clear_min_order_notices'), 1);
        add_action('woocommerce_before_shop_loop', array(__CLASS__, 'clear_min_order_notices'), 1);
        add_action('woocommerce_before_cart', array(__CLASS__, 'clear_min_order_notices'), 1);
        add_action('woocommerce_before_single_product', array(__CLASS__, 'clear_min_order_notices'), 1);

        // Suprimir cualquier intento de registrar notice de monto mínimo a nivel global en WooCommerce
        add_filter('woocommerce_add_error', array(__CLASS__, 'filter_woocommerce_notices'), 999);
        add_filter('woocommerce_add_notice', array(__CLASS__, 'filter_woocommerce_notices'), 999);

        // Ocultar avisos de error en catálogo y páginas del tema Empralidad
        add_action('wp_head', array(__CLASS__, 'render_hide_notices_css'), 999);

        // Redirección si intentan ingresar a la URL de checkout directamente sin cumplir el mínimo
        add_action('template_redirect', array(__CLASS__, 'redirect_checkout_if_below_min'), 10);

        // Verificación de ítems en carrito / checkout (mantiene limpia la cola de avisos)
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'validate_cart_items'));

        // Validación al enviar el pedido en checkout clásico
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'validate_checkout_order'), 10, 2);

        // Validación en WooCommerce Blocks Store API (Checkout Block)
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'validate_store_api_order'), 10, 2);

        // Banner informativo en la página del carrito
        add_action('woocommerce_before_cart', array(__CLASS__, 'render_cart_banner'), 5);
        add_action('woocommerce_before_cart_table', array(__CLASS__, 'render_cart_banner'), 5);

        // Fila informativa de empaque oficial en tabla de totales del carrito y checkout
        add_action('woocommerce_cart_totals_before_order_total', array(__CLASS__, 'render_cart_totals_packaging_row'), 20);
        add_action('woocommerce_review_order_before_order_total', array(__CLASS__, 'render_cart_totals_packaging_row'), 20);

        // Inyectar modal en el footer para feedback inmediato al hacer clic en pagar
        add_action('wp_footer', array(__CLASS__, 'render_min_order_modal'), 50);

        // Endpoint AJAX para comprobación dinámica en tiempo real
        add_action('wp_ajax_emp_caja_get_min_order_status', array(__CLASS__, 'ajax_get_min_order_status'));
        add_action('wp_ajax_nopriv_emp_caja_get_min_order_status', array(__CLASS__, 'ajax_get_min_order_status'));
    }

    /**
     * Obtener el monto mínimo de compra configurado
     */
    public static function get_min_purchase_amount() {
        $options = Batllie_Caja_Plugin::get_color_settings();
        return isset($options['min_purchase_amount']) ? floatval($options['min_purchase_amount']) : 0.0;
    }

    /**
     * Obtener el monto actual del carrito aplicable al mínimo de compra
     */
    public static function get_cart_amount() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0.0;
        }

        // Subtotal de los productos tras descuentos e impuestos de productos
        $amount = floatval(WC()->cart->get_cart_contents_total()) + floatval(WC()->cart->get_cart_contents_tax());

        // Si por alguna razón da 0 o menor pero hay productos en el carrito, usamos get_subtotal()
        if ($amount <= 0 && !WC()->cart->is_empty()) {
            $amount = floatval(WC()->cart->get_subtotal());
        }

        return max(0.0, round($amount, 2));
    }

    /**
     * Encolar scripts y estilos frontend
     */
    public static function enqueue_assets() {
        $min = self::get_min_purchase_amount();
        $packing = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::analyze_cart() : null;
        if ($min <= 0 && (empty($packing) || empty($packing['has_alfajores']))) {
            if (!function_exists('is_cart') || (!is_cart() && !is_checkout())) {
                return;
            }
        }

        wp_enqueue_style(
            'batllie-caja-min-order-css',
            EMP_CAJA_URL . 'assets/css/caja-min-order.css',
            array(),
            EMP_CAJA_VERSION
        );

        wp_enqueue_script(
            'batllie-caja-min-order-js',
            EMP_CAJA_URL . 'assets/js/caja-min-order.js',
            array('jquery'),
            EMP_CAJA_VERSION,
            true
        );

        $amount    = self::get_cart_amount();
        $is_empty  = (!WC() || !WC()->cart || WC()->cart->is_empty());
        $is_below  = (!$is_empty && $amount < $min);
        $missing   = max(0.0, $min - $amount);
        $pct       = ($min > 0) ? min(100, round(($amount / $min) * 100)) : 100;
        $options   = Batllie_Caja_Plugin::get_color_settings();
        $shop_url  = home_url('/');
        $cart_url  = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/carrito/');

        // Inyectar variables de paleta configurable para frontend (Modal y Banner de Carrito)
        $custom_css = "
        :root, #batllie-min-order-backdrop, #batllie-min-order-modal, #batllie-min-order-cart-banner {
            --caja-bg: {$options['bg_color']};
            --caja-header-bg: {$options['header_bg']};
            --caja-card-bg: {$options['card_bg']};
            --caja-card-border: {$options['card_border']};
            --caja-text: {$options['text_color']};
            --caja-text-muted: {$options['text_muted']};
            --caja-primary: {$options['primary_color']};
            --caja-primary-hover: {$options['primary_hover']};
            --caja-btn-text: {$options['btn_text']};
            --caja-status-pending: {$options['status_pending']};
            --caja-status-processing: {$options['status_processing']};
            --caja-status-enviando: {$options['status_enviando']};
            --caja-status-completed: {$options['status_completed']};
            --caja-status-recibido-problema: {$options['status_recibido_problema']};
            --caja-status-cancelled: {$options['status_cancelled']};
            --caja-status-refunded: {$options['status_refunded']};
        }";
        wp_add_inline_style('batllie-caja-min-order-css', $custom_css);

        $hide_notices_css = "
        /* Ocultar avisos de error en catálogo y páginas del tema Empralidad */
        body:not(.woocommerce-checkout) .woocommerce-notices-wrapper .woocommerce-error,
        article.woo-page-margin .woocommerce-notices-wrapper,
        body.home .woocommerce-notices-wrapper,
        body.post-type-archive-product .woocommerce-notices-wrapper,
        body.tax-product_cat .woocommerce-notices-wrapper,
        body.tax-product_tag .woocommerce-notices-wrapper,
        body.woocommerce-shop .woocommerce-notices-wrapper {
            display: none !important;
        }";
        wp_add_inline_style('batllie-caja-min-order-css', $hide_notices_css);
        if (wp_style_is('emp_styles', 'registered') || wp_style_is('emp_styles', 'enqueued')) {
            wp_add_inline_style('emp_styles', $hide_notices_css);
        }

        $box6Img = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_image_url(6, 'medium') : '';
        $box12Img = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_image_url(12, 'medium') : '';
        $priority = $options['packing_priority'] ?? '12';
        $priorityBoxImg = ($priority == 6) ? $box6Img : $box12Img;

        wp_localize_script('batllie-caja-min-order-js', 'batllieMinOrderConfig', array(
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('batllie_caja_nonce'),
            'minAmount'        => $min,
            'currentAmount'    => $amount,
            'missingAmount'    => $missing,
            'isBelowMin'       => $is_below,
            'isEmpty'          => $is_empty,
            'minFormatted'     => wc_price($min),
            'currentFormatted' => wc_price($amount),
            'missingFormatted' => wc_price($missing),
            'percentage'       => $pct,
            'shopUrl'          => $shop_url,
            'cartUrl'          => $cart_url,
            'checkoutUrl'      => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/finalizar-compra/'),
            'primaryColor'     => $options['primary_color'] ?? '#10b981',
            'packing'          => $packing,
            'box6Image'        => $box6Img,
            'box12Image'       => $box12Img,
            'priorityBoxImage' => $priorityBoxImg,
            'availableAlfajores' => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_available_alfajores_for_upsell() : array(),
            'i18n'             => array(
                'modalTitle'      => __('Monto Mínimo de Compra', 'emp-caja'),
                'modalDesc'       => __('Para poder finalizar tu compra y proceder al pago, el pedido debe alcanzar el monto mínimo requerido.', 'emp-caja'),
                'requiredLabel'   => __('Mínimo requerido:', 'emp-caja'),
                'currentLabel'    => __('Tu carrito actual:', 'emp-caja'),
                'missingLabel'    => __('Faltan agregar:', 'emp-caja'),
                'tip'             => __('Agrega más productos a tu carrito para alcanzar el mínimo y completar tu compra.', 'emp-caja'),
                'btnKeepShopping' => __('Seguir Comprando', 'emp-caja'),
                'btnClose'        => __('Volver al Carrito', 'emp-caja'),
                'successTitle'    => __('¡Monto mínimo alcanzado!', 'emp-caja'),
                'successSubtitle' => __('Ya puedes finalizar tu compra sin inconvenientes.', 'emp-caja'),
            )
        ));
    }

    /**
     * Redirección de seguridad si intentan ingresar a /checkout/ estando por debajo del mínimo
     */
    public static function redirect_checkout_if_below_min() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        // Permitir páginas de confirmación (thankyou / order-received) y pago directo de pedido existente (order-pay)
        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('order-pay'))) {
            return;
        }

        $min = self::get_min_purchase_amount();
        if ($min <= 0 || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $amount = self::get_cart_amount();
        if ($amount < $min) {
            self::clear_min_order_notices();
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }

    /**
     * Limpiar notices de monto mínimo de compra en la sesión de WooCommerce
     */
    public static function clear_min_order_notices() {
        if (!function_exists('wc_get_notices') || !function_exists('wc_set_notices')) {
            return;
        }

        $all_notices = wc_get_notices();
        if (empty($all_notices)) {
            return;
        }

        $modified = false;
        foreach (array('error', 'notice', 'success') as $type) {
            if (!empty($all_notices[$type])) {
                $filtered = array();
                foreach ($all_notices[$type] as $notice) {
                    $text = is_array($notice) ? ($notice['notice'] ?? '') : (string)$notice;
                    // Si contiene la frase de monto mínimo, omitirla de la cola
                    if (stripos($text, 'mínimo') === false && stripos($text, 'minimo') === false && stripos($text, 'faltan') === false) {
                        $filtered[] = $notice;
                    } else {
                        $modified = true;
                    }
                }
                $all_notices[$type] = $filtered;
            }
        }

        if ($modified) {
            wc_set_notices($all_notices);
        }
    }

    /**
     * Filtro para anular cualquier intento de registrar notice de monto mínimo a nivel global en WooCommerce
     */
    public static function filter_woocommerce_notices($message) {
        if (!is_string($message)) {
            return $message;
        }
        if (stripos($message, 'mínimo') !== false || stripos($message, 'minimo') !== false || stripos($message, 'faltan') !== false) {
            return false;
        }
        return $message;
    }

    /**
     * Inyectar estilos en el encabezado para ocultar avisos de error en catálogo y páginas del tema Empralidad
     */
    public static function render_hide_notices_css() {
        echo "<style id='batllie-hide-woo-notices'>
        body:not(.woocommerce-checkout) .woocommerce-notices-wrapper .woocommerce-error,
        article.woo-page-margin .woocommerce-notices-wrapper,
        body.home .woocommerce-notices-wrapper,
        body.post-type-archive-product .woocommerce-notices-wrapper,
        body.tax-product_cat .woocommerce-notices-wrapper,
        body.tax-product_tag .woocommerce-notices-wrapper,
        body.woocommerce-shop .woocommerce-notices-wrapper {
            display: none !important;
        }
        </style>\n";
    }

    /**
     * Validar ítems en el carrito al cargar o refrescar la página
     */
    public static function validate_cart_items() {
        // En lugar de imprimir avisos rojos en el catálogo o carrito, aseguramos que la sesión quede limpia
        self::clear_min_order_notices();
    }

    /**
     * Bloquear checkout clásico si el carrito no cumple el mínimo
     */
    public static function validate_checkout_order($data, $errors) {
        $min = self::get_min_purchase_amount();
        if ($min <= 0 || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $amount = self::get_cart_amount();
        if ($amount < $min) {
            $missing = $min - $amount;
            $errors->add(
                'batllie_min_order_error',
                sprintf(
                    __('El monto mínimo de compra es de %s. Te faltan %s para llegar al mínimo y poder ir a pagar.', 'emp-caja'),
                    wp_strip_all_tags(wc_price($min)),
                    wp_strip_all_tags(wc_price($missing))
                )
            );
        }
    }

    /**
     * Bloquear checkout en WooCommerce Blocks Store API si el carrito no cumple el mínimo
     */
    public static function validate_store_api_order($order, $request) {
        $min = self::get_min_purchase_amount();
        if ($min <= 0 || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $amount = self::get_cart_amount();
        if ($amount < $min) {
            $missing = $min - $amount;
            if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'woocommerce_rest_min_order',
                    sprintf(
                        __('El monto mínimo de compra es de %s. Te faltan %s para llegar al mínimo y poder ir a pagar.', 'emp-caja'),
                        wp_strip_all_tags(wc_price($min)),
                        wp_strip_all_tags(wc_price($missing))
                    ),
                    400
                );
            }
        }
    }

    /**
     * Renderizar el banner informativo en la página de carrito
     */
    public static function render_cart_banner() {
        if (self::$banner_rendered) {
            return;
        }

        $min = self::get_min_purchase_amount();
        $packing = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::analyze_cart() : null;

        if ($min <= 0 && (empty($packing) || empty($packing['has_alfajores']))) {
            return;
        }

        self::$banner_rendered = true;
        $amount   = self::get_cart_amount();
        $is_below = ($min > 0 && $amount < $min);
        $missing  = max(0.0, $min - $amount);
        $pct      = ($min > 0) ? min(100, round(($amount / $min) * 100)) : 100;
        $pkg_text = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_cart_packaging_text($packing) : null;
        ?>
        <div id="batllie-min-order-cart-banner" class="batllie-min-order-banner <?php echo $is_below ? 'is-below' : 'is-met'; ?>">
            <div class="batllie-min-banner-inner">
                <div class="batllie-min-banner-icon">
                    <div class="batllie-min-banner-icon-box icon-warning">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="batllie-min-banner-icon-box icon-success">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                </div>
                <div class="batllie-min-banner-content">
                    <?php if ($is_below): ?>
                        <div class="batllie-min-banner-title">
                            <?php _e('Monto mínimo de compra:', 'emp-caja'); ?> <strong class="batllie-min-val"><?php echo wc_price($min); ?></strong>
                        </div>
                        <div class="batllie-min-banner-subtitle">
                            <?php printf(
                                __('Te faltan %s para llegar al mínimo y poder ir a pagar.', 'emp-caja'),
                                '<strong class="batllie-banner-missing-text">' . wc_price($missing) . '</strong>'
                            ); ?>
                        </div>
                    <?php else: ?>
                        <div class="batllie-min-banner-title">
                            <?php _e('¡Monto mínimo de compra alcanzado!', 'emp-caja'); ?>
                        </div>
                        <div class="batllie-min-banner-subtitle">
                            <?php _e('Ya puedes ir a pagar tu pedido sin inconvenientes.', 'emp-caja'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($pkg_text['has_alfajores'])): ?>
                        <div class="batllie-banner-packaging-note" id="batllie-banner-packaging-note">
                            <span class="batllie-banner-pkg-icon">📦</span>
                            <span class="batllie-banner-pkg-text" id="batllie-banner-pkg-text"><?php echo esc_html($pkg_text['sentence']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($min > 0): ?>
                <div class="batllie-min-banner-progress-wrap">
                    <div class="batllie-min-banner-progress-bar" style="width: <?php echo esc_attr($pct); ?>%;"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Fila informativa de Empaque Oficial en la tabla de totales del carrito y checkout
     */
    public static function render_cart_totals_packaging_row() {
        $packing = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::analyze_cart() : null;
        if (empty($packing) || empty($packing['has_alfajores'])) {
            return;
        }
        $pkg_text = Batllie_Caja_Packing::get_cart_packaging_text($packing);
        ?>
        <tr class="batllie-cart-packing-totals-row" id="batllie-cart-packing-totals-row">
            <th><?php _e('Empaque Oficial:', 'emp-caja'); ?></th>
            <td data-title="<?php esc_attr_e('Empaque Oficial', 'emp-caja'); ?>">
                <span class="batllie-cart-packing-pill" id="batllie-cart-packing-pill">
                    📦 <strong id="batllie-cart-packing-pill-text"><?php echo esc_html($pkg_text['short']); ?></strong>
                </span>
            </td>
        </tr>
        <?php
    }

    /**
     * Renderizar el modal popup en el footer para feedback inmediato al hacer clic en pagar
     */
    public static function render_min_order_modal() {
        $min     = self::get_min_purchase_amount();
        $packing = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::analyze_cart() : null;

        // Si no hay monto mínimo y tampoco hay alfajores en el carrito, no renderizar
        if ($min <= 0 && (empty($packing) || empty($packing['has_alfajores']))) {
            return;
        }

        $amount    = self::get_cart_amount();
        $missing   = max(0.0, $min - $amount);
        $is_below  = ($min > 0 && $amount < $min);
        $shop_url  = home_url('/');
        $alfajores = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_available_alfajores_for_upsell() : array();
        $box6Img = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_image_url(6, 'medium') : '';
        $box12Img = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_image_url(12, 'medium') : '';
        $cur_cap   = !empty($packing['target_box_capacity']) ? intval($packing['target_box_capacity']) : (!empty($packing['current_box_capacity']) ? intval($packing['current_box_capacity']) : 6);
        $total_alf = !empty($packing['total_alfajores']) ? intval($packing['total_alfajores']) : 0;
        if (empty($packing['target_box_capacity']) && $total_alf > 0) {
            $rem_12 = $total_alf % 12;
            if ($rem_12 > 6) {
                $cur_cap = 12;
            } elseif ($rem_12 > 0 && $rem_12 <= 6 && empty($packing['has_mixed_box'])) {
                $cur_cap = 6;
            }
        }
        if (!empty($packing['has_mixed_box']) && !empty($packing['mixed_box_info']['box_capacity'])) {
            $cur_cap = intval($packing['mixed_box_info']['box_capacity']);
        }
        $cur_units = isset($packing['current_box_units']) ? intval($packing['current_box_units']) : 0;
        if ($cur_units <= 0 && !empty($packing['missing_units']) && intval($packing['missing_units']) < $cur_cap) {
            $cur_units = max(0, $cur_cap - intval($packing['missing_units']));
        } elseif ($cur_units <= 0 && $total_alf > 0) {
            $cur_units = ($total_alf % $cur_cap) ?: $cur_cap;
        }
        $pct_box       = ($cur_cap > 0) ? min(100, round(($cur_units / $cur_cap) * 100)) : 0;
        $initialBoxImg = ($cur_cap == 12) ? $box12Img : $box6Img;
        ?>
        <div id="batllie-min-order-backdrop" class="batllie-min-order-backdrop" style="display:none;" aria-hidden="true">
            <div id="batllie-min-order-modal" class="batllie-min-order-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Información de tu Pedido', 'emp-caja'); ?>">
                <button type="button" class="batllie-min-modal-close" id="batllie-min-modal-close-btn" aria-label="<?php esc_attr_e('Cerrar aviso', 'emp-caja'); ?>">&times;</button>
                
                <div class="batllie-min-modal-body">
                    <!-- Sección A: Estadísticas de Monto Mínimo (3 datos planos) -->
                    <div id="batllie-modal-min-stats" class="batllie-min-modal-stats" style="<?php echo $is_below ? '' : 'display:none;'; ?>">
                        <div class="batllie-min-stat-row">
                            <span class="batllie-stat-label"><?php _e('Mínimo requerido:', 'emp-caja'); ?></span>
                            <span class="batllie-stat-val batllie-stat-min"><?php echo wc_price($min); ?></span>
                        </div>
                        <div class="batllie-min-stat-row">
                            <span class="batllie-stat-label"><?php _e('Tu carrito actual:', 'emp-caja'); ?></span>
                            <span class="batllie-stat-val batllie-stat-current"><?php echo wc_price($amount); ?></span>
                        </div>
                        <div class="batllie-min-stat-row batllie-stat-missing-row">
                            <span class="batllie-stat-label"><?php _e('Faltan agregar:', 'emp-caja'); ?></span>
                            <span class="batllie-stat-val batllie-stat-missing"><?php echo wc_price($missing); ?></span>
                        </div>
                    </div>

                    <!-- Sección B: Motor de Empaque, Barra de Progreso y Gustos 1-Click -->
                    <div id="batllie-modal-packing-section" class="batllie-modal-packing-section" style="<?php echo (!empty($packing['has_alfajores']) && $packing['status'] !== 'all_boxed') ? '' : 'display:none;'; ?>">
                        <div class="batllie-packing-header">
                            <h3 id="batllie-packing-title" class="batllie-packing-title">
                                <span class="batllie-packing-title-main"><?php _e('Tomaste una buena decisión', 'emp-caja'); ?></span>
                                <span class="batllie-packing-title-sub"><?php _e('pero podría ser aún mejor', 'emp-caja'); ?></span>
                            </h3>
                            <div class="batllie-modal-box-image-wrap" id="batllie-modal-box-image-wrap" style="<?php echo !empty($initialBoxImg) ? '' : 'display:none;'; ?>">
                                <img id="batllie-modal-box-image" src="<?php echo esc_url($initialBoxImg); ?>" alt="<?php esc_attr_e('Caja oficial', 'emp-caja'); ?>" class="batllie-modal-box-image" />
                            </div>
                        </div>

                        <!-- Barra de Progreso Gamificada con colores de marca (plana, sin fondo) -->
                        <div class="batllie-packing-progress-container" id="batllie-packing-progress-container" style="background: transparent; border: none; box-shadow: none; padding: 4px 0 8px 0; margin: 8px 0 12px 0;">
                            <div class="batllie-packing-bar-wrap">
                                <?php 
                                $pct_box = ($cur_cap > 0) ? min(100, round(($cur_units / $cur_cap) * 100)) : 0;
                                ?>
                                <div id="batllie-packing-bar-fill" class="batllie-packing-bar-fill" style="width: <?php echo esc_attr($pct_box); ?>%;"></div>
                            </div>
                            <div class="batllie-packing-progress-status">
                                <span id="batllie-packing-badge" class="batllie-packing-badge">
                                    <?php 
                                    if (!empty($packing['missing_units'])) {
                                        echo esc_html(sprintf(__('Faltan solo %d para completar', 'emp-caja'), $packing['missing_units']));
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>

                        <!-- Selector Rápido de Gustos en 1-Clic -->
                        <?php if (!empty($alfajores)): ?>
                            <div class="batllie-packing-flavors-head">
                                <span><?php _e('Sumá un alfajor con 1 clic:', 'emp-caja'); ?></span>
                            </div>
                            <div id="batllie-packing-flavors-grid" class="batllie-packing-flavors-grid">
                                <?php foreach ($alfajores as $alf): 
                                    $rem_stock = isset($alf['remaining_stock']) ? intval($alf['remaining_stock']) : 999;
                                    if ($rem_stock <= 0) continue;
                                ?>
                                    <div class="batllie-flavor-chip" data-id="<?php echo esc_attr($alf['id']); ?>" data-remaining-stock="<?php echo esc_attr($rem_stock); ?>">
                                        <?php if (!empty($alf['image'])): ?>
                                            <img src="<?php echo esc_url($alf['image']); ?>" alt="<?php echo esc_attr($alf['clean_name']); ?>" class="batllie-flavor-thumb" />
                                        <?php endif; ?>
                                        <div class="batllie-flavor-info">
                                            <span class="batllie-flavor-name"><?php echo esc_html($alf['clean_name']); ?></span>
                                            <span class="batllie-flavor-price"><?php echo $alf['price_fmt']; ?></span>
                                        </div>
                                        <button type="button" class="batllie-flavor-add-btn" data-id="<?php echo esc_attr($alf['id']); ?>" data-remaining-stock="<?php echo esc_attr($rem_stock); ?>" aria-label="<?php echo esc_attr(sprintf(__('Agregar %s', 'emp-caja'), $alf['clean_name'])); ?>">
                                            <span class="btn-icon">+</span>
                                            <span class="btn-txt">1</span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="batllie-min-modal-footer">
                    <!-- Botón para continuar sin agregar alfajores extras (habilitado si no está por debajo del mínimo de compra) -->
                    <button type="button" class="batllie-min-btn batllie-min-btn-courtesy" id="batllie-btn-accept-courtesy" style="<?php echo (!$is_below) ? '' : 'display:none;'; ?>">
                        <span><?php _e('Continuar de todas formas', 'emp-caja'); ?></span>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Endpoint AJAX para consultar estado del mínimo de compra y empaque en tiempo real
     */
    public static function ajax_get_min_order_status() {
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->calculate_totals();
        }
        $min      = self::get_min_purchase_amount();
        $amount   = self::get_cart_amount();
        $is_empty = (!WC() || !WC()->cart || WC()->cart->is_empty());
        $is_below = (!$is_empty && $min > 0 && $amount < $min);
        $missing  = max(0.0, $min - $amount);
        $pct      = ($min > 0) ? min(100, round(($amount / $min) * 100)) : 100;
        $packing  = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::analyze_cart() : null;

        if (!$is_below) {
            self::clear_min_order_notices();
        }

        wp_send_json_success(array(
            'min_amount'         => $min,
            'current_amount'     => $amount,
            'missing_amount'     => $missing,
            'is_below_min'       => $is_below,
            'is_empty'           => $is_empty,
            'min_formatted'      => wc_price($min),
            'current_formatted'  => wc_price($amount),
            'missing_formatted'  => wc_price($missing),
            'percentage'         => $pct,
            'packing'            => $packing,
            'availableAlfajores' => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_available_alfajores_for_upsell() : array(),
        ));
    }
}
