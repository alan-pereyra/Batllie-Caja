<?php
/**
 * Clase para Gestión de Pedidos WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Orders {

    /**
     * Registrar estados personalizados en WordPress / WooCommerce
     */
    public static function register_custom_order_statuses() {
        register_post_status('wc-enviando', array(
            'label'                     => _x('Enviando', 'Order status', 'emp-caja'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Enviando (%s)', 'Enviando (%s)', 'emp-caja')
        ));

        register_post_status('wc-recibido-problema', array(
            'label'                     => _x('Recibido (con inconvenientes)', 'Order status', 'emp-caja'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Recibido (con inconvenientes) (%s)', 'Recibido (con inconvenientes) (%s)', 'emp-caja')
        ));
    }

    /**
     * Reorganizar y nombrar los estados de venta en el orden exacto solicitado
     */
    public static function add_custom_order_statuses($order_statuses) {
        return array(
            'wc-pending'           => __('Pendiente', 'emp-caja'),
            'wc-processing'        => __('En preparación', 'emp-caja'),
            'wc-enviando'          => __('Enviando', 'emp-caja'),
            'wc-completed'         => __('Completado', 'emp-caja'),
            'wc-recibido-problema' => __('Recibido (con inconvenientes)', 'emp-caja'),
            'wc-cancelled'         => __('Cancelado', 'emp-caja'),
            'wc-refunded'          => __('Reembolzado', 'emp-caja'),
        );
    }

    /**
     * Hacer que el número de teléfono sea 100% obligatorio en WooCommerce (Clásico y Bloques)
     */
    public static function make_phone_required_hooks() {
        if (get_option('woocommerce_checkout_phone_field') !== 'required') {
            update_option('woocommerce_checkout_phone_field', 'required');
        }

        // Forzar opción a nivel de WooCommerce
        add_filter('pre_option_woocommerce_checkout_phone_field', function() {
            return 'required';
        });

        // Forzar en campos de facturación
        add_filter('woocommerce_billing_fields', array(__CLASS__, 'filter_billing_phone_required'), 9999, 1);

        // Forzar en campos del checkout
        add_filter('woocommerce_checkout_fields', array(__CLASS__, 'filter_checkout_phone_required'), 9999, 1);

        // Forzar en campos de dirección por defecto
        add_filter('woocommerce_default_address_fields', array(__CLASS__, 'filter_default_phone_required'), 9999, 1);

        // Validación estricta del lado del servidor al procesar el checkout clásico
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'validate_checkout_phone'), 10, 2);

        // Validación estricta en Checkout de Bloques (WooCommerce Store API)
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'validate_store_api_checkout_phone'), 10, 2);

        // Normalizar automáticamente el teléfono argentino al crear el pedido
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'normalize_order_phone_on_create'), 20, 2);

        // Asegurar atributos requirePhoneField en los bloques de la página de checkout
        self::ensure_checkout_blocks_phone_required();

        // Script frontend para eliminar "(opcional)" en React/Gutenberg y forzar required
        add_action('wp_footer', array(__CLASS__, 'render_checkout_phone_fix_script'), 999);
    }

    public static function ensure_checkout_blocks_phone_required() {
        $checkout_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : 10;
        if (!$checkout_page_id || $checkout_page_id <= 0) {
            $checkout_page_id = 10;
        }

        $post = get_post($checkout_page_id);
        if (!$post || empty($post->post_content)) {
            return;
        }

        $content = $post->post_content;
        $needs_update = false;

        // Reemplazar requirePhoneField:false por true
        if (strpos($content, '"requirePhoneField":false') !== false) {
            $content = str_replace('"requirePhoneField":false', '"requirePhoneField":true', $content);
            $needs_update = true;
        }

        // Si los bloques no tienen atributos, agregarlos
        $replacements = array(
            '<!-- wp:woocommerce/checkout-shipping-address-block /-->' => '<!-- wp:woocommerce/checkout-shipping-address-block {"showPhoneField":true,"requirePhoneField":true} /-->',
            '<!-- wp:woocommerce/checkout-billing-address-block /-->'  => '<!-- wp:woocommerce/checkout-billing-address-block {"showPhoneField":true,"requirePhoneField":true} /-->',
        );

        foreach ($replacements as $orig => $repl) {
            if (strpos($content, $orig) !== false) {
                $content = str_replace($orig, $repl, $content);
                $needs_update = true;
            }
        }

        if ($needs_update) {
            wp_update_post(array(
                'ID'           => $checkout_page_id,
                'post_content' => $content,
            ));
        }
    }

    public static function filter_billing_phone_required($fields) {
        if (isset($fields['billing_phone'])) {
            $fields['billing_phone']['required'] = true;
            $fields['billing_phone']['label'] = __('Teléfono', 'woocommerce');
            $fields['billing_phone']['placeholder'] = __('Teléfono *', 'emp-caja');
        }
        return $fields;
    }

    public static function filter_checkout_phone_required($fields) {
        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['required'] = true;
            $fields['billing']['billing_phone']['label'] = __('Teléfono', 'woocommerce');
            $fields['billing']['billing_phone']['placeholder'] = __('Teléfono *', 'emp-caja');
        }
        return $fields;
    }

    public static function filter_default_phone_required($fields) {
        if (isset($fields['phone'])) {
            $fields['phone']['required'] = true;
        }
        return $fields;
    }

    public static function validate_checkout_phone($data, $errors) {
        if (empty($data['billing_phone']) || trim($data['billing_phone']) === '') {
            $errors->add('billing_phone_required', __('<strong>Teléfono</strong> es un campo obligatorio para poder coordinar la entrega de tu pedido.', 'emp-caja'));
        }
    }

    public static function validate_store_api_checkout_phone($order, $request) {
        $billing = is_object($request) && method_exists($request, 'get_param') ? $request->get_param('billing_address') : array();
        $shipping = is_object($request) && method_exists($request, 'get_param') ? $request->get_param('shipping_address') : array();

        $phone = '';
        if (!empty($billing['phone'])) {
            $phone = trim($billing['phone']);
        } elseif (!empty($shipping['phone'])) {
            $phone = trim($shipping['phone']);
        } elseif (method_exists($order, 'get_billing_phone') && $order->get_billing_phone()) {
            $phone = trim($order->get_billing_phone());
        }

        if (empty($phone)) {
            if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'woocommerce_rest_missing_phone',
                    __('El número de teléfono es obligatorio para poder coordinar la entrega de tu pedido.', 'emp-caja'),
                    400
                );
            }
        } else {
            // Normalizar número en el pedido
            $norm = self::normalize_argentine_phone($phone);
            if (!empty($norm['formatted']) && method_exists($order, 'set_billing_phone')) {
                $order->set_billing_phone($norm['formatted']);
                $order->update_meta_data('_caja_phone_wa', $norm['wa']);
            }
        }
    }

    /**
     * Normalizar automáticamente el teléfono al crear pedido en checkout clásico
     */
    public static function normalize_order_phone_on_create($order, $data) {
        if (!is_a($order, 'WC_Order')) {
            return;
        }
        $bphone = $order->get_billing_phone();
        if (!empty($bphone)) {
            $norm = self::normalize_argentine_phone($bphone);
            if (!empty($norm['formatted'])) {
                $order->set_billing_phone($norm['formatted']);
                $order->update_meta_data('_caja_phone_wa', $norm['wa']);
            }
        }
        $sphone = $order->get_shipping_phone();
        if (!empty($sphone)) {
            $norm_s = self::normalize_argentine_phone($sphone);
            if (!empty($norm_s['formatted'])) {
                $order->set_shipping_phone($norm_s['formatted']);
            }
        }
    }

    /**
     * Normalizar teléfonos argentinos para visualización, almacenamiento y WhatsApp
     */
    public static function normalize_argentine_phone($raw_phone) {
        if (empty($raw_phone)) {
            return array(
                'raw'           => '',
                'clean'         => '',
                'formatted'     => '',
                'wa'            => '',
                'international' => '',
            );
        }

        // Dejar solo dígitos
        $digits = preg_replace('/[^0-9]/', '', (string)$raw_phone);

        // Si empieza con 549 (formato internacional móvil Argentina, ej: 5491149472377)
        if (strpos($digits, '549') === 0 && strlen($digits) >= 12) {
            $national = substr($digits, 3);
        } elseif (strpos($digits, '54') === 0 && strlen($digits) >= 12) {
            // Empieza con 54 pero sin 9
            $national = substr($digits, 2);
        } else {
            $national = $digits;
        }

        // Quitar ceros a la izquierda (ej: 011 -> 11)
        $national = ltrim($national, '0');

        // Quitar '15' si está después del código de área o al inicio
        // Ej 1: 1549472377 (10 dígitos empezando con 15) -> asumir Buenos Aires 11 + 49472377
        if (strlen($national) === 10 && strpos($national, '15') === 0) {
            $national = '11' . substr($national, 2);
        }
        // Ej 2: 111549472377 (12 dígitos: 11 + 15 + 8 dígitos) -> 11 + 49472377
        if (strlen($national) === 12 && strpos($national, '1115') === 0) {
            $national = '11' . substr($national, 4);
        }
        // Ej 3: Si tiene 11 dígitos y empieza con 119 (ej: 11949472377) o 911 (ej: 91149472377)
        if (strlen($national) === 11) {
            if (strpos($national, '119') === 0) {
                $national = '11' . substr($national, 3);
            } elseif (strpos($national, '911') === 0) {
                $national = substr($national, 1);
            } elseif (strpos($national, '9') === 0) {
                $national = substr($national, 1);
            }
        }

        // Formatear para lectura amigable
        $formatted = $national;
        if (strlen($national) === 10) {
            if (strpos($national, '11') === 0) {
                $formatted = substr($national, 0, 2) . ' ' . substr($national, 2, 4) . '-' . substr($national, 6);
            } else {
                $formatted = substr($national, 0, 3) . ' ' . substr($national, 3, 3) . '-' . substr($national, 6);
            }
        }

        // Número para WhatsApp (wa.me)
        $wa = '';
        if (!empty($national)) {
            if (strlen($national) === 10) {
                $wa = '549' . $national;
            } elseif (strpos($digits, '549') === 0) {
                $wa = $digits;
            } else {
                $wa = '549' . $national;
            }
        }

        return array(
            'raw'           => $raw_phone,
            'clean'         => $national,
            'formatted'     => $formatted,
            'wa'            => $wa,
            'international' => !empty($national) ? '+54 9 ' . $formatted : '',
        );
    }

    public static function render_checkout_phone_fix_script() {
        if (!is_checkout() && !is_cart() && !is_account_page()) {
            return;
        }
        ?>
        <script>
        (function() {
            function updatePhoneFields() {
                var phoneInputs = document.querySelectorAll('input[type="tel"], input#billing_phone, input#shipping_phone, input[name*="phone"], input[id*="phone"]');
                phoneInputs.forEach(function(input) {
                    if (input.placeholder && input.placeholder.toLowerCase().includes('opcional')) {
                        input.placeholder = input.placeholder.replace(/\s*\(opcional\)/gi, '').trim() + ' *';
                    }
                    if (!input.placeholder || input.placeholder === 'Teléfono' || input.placeholder === 'Teléfono *') {
                        input.placeholder = 'Ej: 11 4947-2377 *';
                    }

                    input.required = true;
                    input.setAttribute('required', 'required');
                    input.setAttribute('aria-required', 'true');

                    var label = document.querySelector('label[for="' + input.id + '"]') || 
                                (input.closest('.wc-block-components-text-input') ? input.closest('.wc-block-components-text-input').querySelector('label') : null) ||
                                (input.closest('.form-row') ? input.closest('.form-row').querySelector('label') : null);
                    
                    if (label) {
                        var text = label.textContent || '';
                        if (text.toLowerCase().includes('opcional')) {
                            label.innerHTML = label.innerHTML.replace(/\s*\(opcional\)/gi, '').replace(/<span[^>]*class="[^"]*optional[^"]*"[^>]*>.*?<\/span>/gi, '') + ' <abbr class="required" title="obligatorio">*</abbr>';
                        }
                    }

                    // Agregar mensaje guía debajo del campo si no existe
                    var parent = input.closest('.wc-block-components-text-input') || input.parentElement;
                    if (parent && !parent.querySelector('.caja-phone-help')) {
                        var help = document.createElement('small');
                        help.className = 'caja-phone-help';
                        help.style.cssText = 'display:block;font-size:12px;color:#718096;margin-top:4px;font-style:normal;line-height:1.3;';
                        help.textContent = '🇦🇷 Celular (código de área + número, ej: 11 4947-2377. Sin el 0 ni el 15)';
                        parent.appendChild(help);
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', updatePhoneFields);
            } else {
                updatePhoneFields();
            }

            if (window.MutationObserver) {
                var observer = new MutationObserver(function() {
                    updatePhoneFields();
                });
                observer.observe(document.body, { childList: true, subtree: true });
            }
        })();
        </script>
        <style>
        .wc-block-components-text-input:has(input[name*="phone"]) .wc-block-components-text-input__label span.optional,
        #billing_phone_field .optional {
            display: none !important;
        }
        /* Cifras alineadas y números de teléfono legibles (evita números colgantes de Cormorant Garamond) */
        .woocommerce-order-details, 
        .woocommerce-customer-details, 
        .wc-block-checkout, 
        .wc-block-components-checkout-step,
        .caja-customer-phone, 
        address, 
        [class*="phone"] {
            font-variant-numeric: lining-nums tabular-nums !important;
        }
        </style>
        <?php
    }

    /**
     * Obtener listado de pedidos WooCommerce
     */
    public static function get_orders($status = 'all', $limit = 50, $search = '') {
        if (!class_exists('WooCommerce')) {
            return array();
        }

        $query_args = array(
            'limit'   => intval($limit),
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
            'type'    => 'shop_order',
        );

        if ($status && $status !== 'all') {
            $clean_s = str_replace('wc-', '', sanitize_key($status));
            if ($clean_s === 'enviando') {
                $query_args['status'] = array('wc-enviando', 'wc-on-hold', 'enviando', 'on-hold');
            } elseif ($clean_s === 'recibido-problema') {
                $query_args['status'] = array('wc-recibido-problema', 'wc-failed', 'recibido-problema', 'failed');
            } else {
                $query_args['status'] = array('wc-' . $clean_s, $clean_s);
            }
        } else {
            // Incluir todos los estados de venta registrados
            $all_statuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : array();
            if (empty($all_statuses)) {
                $all_statuses = array('wc-pending', 'wc-processing', 'wc-enviando', 'wc-completed', 'wc-recibido-problema', 'wc-cancelled', 'wc-refunded');
            }
            $query_args['status'] = $all_statuses;
        }

        if (!empty($search)) {
            // Si es numérico buscar por ID
            if (is_numeric($search)) {
                $order = wc_get_order(intval($search));
                if ($order) {
                    $single = self::format_order($order);
                    return $single ? array($single) : array();
                }
            }
        }

        $orders = wc_get_orders($query_args);
        $formatted = array();

        foreach ($orders as $order) {
            $f = self::format_order($order);
            if ($f !== null) {
                $formatted[] = $f;
            }
        }

        return $formatted;
    }

    /**
     * Consolidar ítems idénticos sumando sus cantidades y totales en una sola fila
     */
    public static function consolidate_order_items($items, $currency = null) {
        if (empty($items) || !is_array($items)) {
            return array();
        }

        $consolidated = array();
        foreach ($items as $item) {
            // Clave única basada en ID de producto, nombre y metadatos de variación
            $meta_key = !empty($item['meta']) && is_array($item['meta']) ? implode('|', $item['meta']) : '';
            $id_part = !empty($item['id']) ? $item['id'] : sanitize_title($item['name']);
            $key = $id_part . '_' . md5(mb_strtolower(trim($item['name']), 'UTF-8') . '_' . $meta_key);

            if (!isset($consolidated[$key])) {
                $consolidated[$key] = $item;
                $consolidated[$key]['quantity'] = intval($item['quantity']);
                $consolidated[$key]['total_num'] = floatval($item['total_num']);
                if (empty($consolidated[$key]['total']) && $currency && function_exists('wc_price')) {
                    $consolidated[$key]['total'] = wc_price($consolidated[$key]['total_num'], array('currency' => $currency));
                }
            } else {
                $consolidated[$key]['quantity'] += intval($item['quantity']);
                $consolidated[$key]['total_num'] += floatval($item['total_num']);
                if ($currency && function_exists('wc_price')) {
                    $consolidated[$key]['total'] = wc_price($consolidated[$key]['total_num'], array('currency' => $currency));
                }
                // Si la imagen previa era un placeholder y este ítem tiene imagen real, actualizar
                if (empty($consolidated[$key]['image']) || strpos($consolidated[$key]['image'], 'placeholder') !== false) {
                    if (!empty($item['image']) && strpos($item['image'], 'placeholder') === false) {
                        $consolidated[$key]['image'] = $item['image'];
                    }
                }
            }
        }

        return array_values($consolidated);
    }

    /**
     * Obtener imagen representativa por defecto para una caja
     */
    public static function get_default_box_image_url($capacity = 6) {
        $pkg_id = get_option('_batllie_packaging_product_id', 0);
        if ($pkg_id && function_exists('wc_get_product')) {
            $pkg_prod = wc_get_product($pkg_id);
            if ($pkg_prod && $pkg_prod->get_image_id() && function_exists('wp_get_attachment_image_url')) {
                $url = wp_get_attachment_image_url($pkg_prod->get_image_id(), 'thumbnail');
                if ($url) return $url;
            }
        }
        if (function_exists('wc_placeholder_img_src')) {
            return wc_placeholder_img_src('thumbnail');
        }
        return '';
    }

    /**
     * Empaquetar alfajores sueltos en cajas automáticas (Caja 12, Caja 6, Cortesía)
     * para que en la comanda mostrador/cocina se vean agrupados homogéneamente como packs.
     */
    public static function auto_package_loose_alfajores($raw_standalone, $currency = null, $has_courtesy = false, $has_decision = false, $default_image = '') {
        if (empty($raw_standalone) || !is_array($raw_standalone)) {
            return array('boxes' => array(), 'remaining' => array());
        }

        $loose_alfajores = array();
        $other_standalone = array();

        foreach ($raw_standalone as $item) {
            $name_lower = mb_strtolower($item['name'], 'UTF-8');
            $is_alf = (class_exists('Batllie_Caja_Packing') && Batllie_Caja_Packing::is_alfajor_product($item['id'])) ||
                      (strpos($name_lower, 'alfajor') !== false) ||
                      (strpos($name_lower, 'batllie') !== false);

            if ($is_alf) {
                $loose_alfajores[] = $item;
            } else {
                $other_standalone[] = $item;
            }
        }

        if (empty($loose_alfajores)) {
            return array('boxes' => array(), 'remaining' => $other_standalone);
        }

        // 1. Consolidar ítems sueltos del mismo sabor
        $loose_alfajores = self::consolidate_order_items($loose_alfajores, $currency);

        // 2. Contar unidades sueltas
        $total_loose_units = 0;
        foreach ($loose_alfajores as $la) {
            $total_loose_units += intval($la['quantity']);
        }

        // 3. Determinar qué cajas armar (Prioridad 12 unidades)
        $boxes_to_create = array();
        if ($total_loose_units >= 6 || ($total_loose_units > 0 && $has_courtesy)) {
            $units_left = $total_loose_units;

            while ($units_left >= 12) {
                $boxes_to_create[] = array('capacity' => 12, 'type' => 'box_12', 'is_courtesy' => false);
                $units_left -= 12;
            }

            if ($units_left >= 6) {
                $boxes_to_create[] = array('capacity' => 6, 'type' => 'box_6', 'is_courtesy' => false);
                $units_left -= 6;
            }

            if ($units_left > 0 && $has_courtesy) {
                $boxes_to_create[] = array('capacity' => $units_left, 'type' => 'courtesy', 'is_courtesy' => true);
                $units_left = 0;
            }
        }

        if (empty($boxes_to_create)) {
            // No alcanzan para armar una caja de 6 ni tienen cortesía
            return array('boxes' => array(), 'remaining' => array_merge($loose_alfajores, $other_standalone));
        }

        // 4. Distribuir alfajores sueltos en las cajas
        $pool = $loose_alfajores;
        $pool_idx = 0;
        $auto_boxes = array();

        foreach ($boxes_to_create as $b_idx => $b_spec) {
            $cap = $b_spec['capacity'];
            $needed = $cap;
            $box_items = array();
            $box_total_num = 0.0;

            while ($needed > 0 && $pool_idx < count($pool)) {
                $cur = $pool[$pool_idx];
                $cur_qty = intval($cur['quantity']);
                $unit_price = ($cur_qty > 0) ? (floatval($cur['total_num']) / $cur_qty) : 0.0;

                if ($cur_qty <= $needed) {
                    $box_items[] = $cur;
                    $box_total_num += floatval($cur['total_num']);
                    $needed -= $cur_qty;
                    $pool_idx++;
                } else {
                    $take_qty = $needed;
                    $take_total = $take_qty * $unit_price;

                    $sub = $cur;
                    $sub['quantity'] = $take_qty;
                    $sub['total_num'] = $take_total;
                    if ($currency && function_exists('wc_price')) {
                        $sub['total'] = wc_price($take_total, array('currency' => $currency));
                    }
                    $box_items[] = $sub;
                    $box_total_num += $take_total;

                    // Restar del pool
                    $pool[$pool_idx]['quantity'] -= $take_qty;
                    $pool[$pool_idx]['total_num'] -= $take_total;
                    if ($currency && function_exists('wc_price')) {
                        $pool[$pool_idx]['total'] = wc_price($pool[$pool_idx]['total_num'], array('currency' => $currency));
                    }

                    $needed = 0;
                }
            }

            $box_name = ($b_spec['is_courtesy']) 
                ? __('Caja de Cortesía Batllié', 'emp-caja')
                : sprintf(__('Caja %d unidades', 'emp-caja'), $cap);

            $box_badge = ($b_spec['is_courtesy']) ? '🎁 CAJA DE CORTESÍA' : '📦 PACK / CAJA';

            // Si el pedido tiene decisión correcta (completó la caja en el modal), asignar el indicador a la última caja armada
            $is_target_felicitacion = ($has_decision && $b_idx === (count($boxes_to_create) - 1));

            $formatted_box_total = ($currency && function_exists('wc_price')) ? wc_price($box_total_num, array('currency' => $currency)) : ('$ ' . number_format($box_total_num, 2, ',', '.'));

            $auto_boxes[] = array(
                'item_id'               => 'auto_box_' . ($b_idx + 1),
                'id'                    => 0,
                'name'                  => $box_name,
                'image'                 => $default_image,
                'quantity'              => 1,
                'total_num'             => $box_total_num,
                'total'                 => $formatted_box_total,
                'meta'                  => array(),
                'is_box'                => true,
                'is_auto_box'           => true,
                'box_badge'             => $box_badge,
                'box_units'             => $cap,
                'box_total'             => $formatted_box_total,
                'box_total_num'         => $box_total_num,
                'has_decision_correcta' => $is_target_felicitacion,
                'has_aumento_pedido'    => $is_target_felicitacion,
                'has_courtesy'          => $b_spec['is_courtesy'],
                'pack_items'            => $box_items,
            );
        }

        $remaining_loose = array();
        while ($pool_idx < count($pool)) {
            if ($pool[$pool_idx]['quantity'] > 0) {
                $remaining_loose[] = $pool[$pool_idx];
            }
            $pool_idx++;
        }

        return array(
            'boxes'     => $auto_boxes,
            'remaining' => array_merge($remaining_loose, $other_standalone)
        );
    }

    /**
     * Formatear datos de un pedido para consumo JSON / Frontend
     */
    public static function format_order($order) {
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($order);
        }
        if (!$order || is_a($order, 'WC_Order_Refund') || (method_exists($order, 'get_type') && $order->get_type() === 'shop_order_refund')) {
            return null;
        }

        $order_id = $order->get_id();
        $items = array();
        $raw_boxes = array();
        $raw_standalone = array();
        $child_items = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $meta_display = array();
            
            // Metadatos formateados públicos (excluyendo prefijo '_' para ocultar claves internas)
            $meta_data = $item->get_formatted_meta_data('_');
            foreach ($meta_data as $meta) {
                $meta_display[] = strip_tags($meta->display_key . ': ' . $meta->display_value);
            }

            // Imagen del producto
            $image_url = '';
            if ($product) {
                $image_id = $product->get_image_id();
                // Si es caja de empaque, usar la imagen específica de la caja configurada
                if ($item->get_meta('_batllie_extra_box') === 'yes' || ($product && $product->get_id() == get_option('_batllie_packaging_product_id', 0))) {
                    $box_img_meta = $item->get_meta('_batllie_box_image_id');
                    if ($box_img_meta) {
                        $image_id = $box_img_meta;
                    } else {
                        $parent_grouped_id = $item->get_meta('_batllie_parent_grouped_id');
                        if ($parent_grouped_id) {
                            $box_cfg_img = get_post_meta($parent_grouped_id, '_batllie_grouped_box_image_id', true);
                            if ($box_cfg_img) {
                                $image_id = $box_cfg_img;
                            } elseif (!$image_id) {
                                $parent_product = wc_get_product($parent_grouped_id);
                                if ($parent_product && $parent_product->get_image_id()) {
                                    $image_id = $parent_product->get_image_id();
                                }
                            }
                        }
                    }
                }
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
            } else {
                $image_url = wc_placeholder_img_src('thumbnail');
            }

            $is_extra_box = ($item->get_meta('_batllie_extra_box') === 'yes') || ($product && $product->get_id() == get_option('_batllie_packaging_product_id', 0));
            $pack_instance_id = $item->get_meta('_batllie_pack_instance_id');
            $parent_grouped_id = $item->get_meta('_batllie_parent_grouped_id');

            $line_data = array(
                'item_id'           => $item_id,
                'id'                => $item->get_product_id(),
                'name'              => $item->get_name(),
                'image'             => $image_url,
                'quantity'          => $item->get_quantity(),
                'total_num'         => floatval($item->get_total()),
                'total'             => wc_price($item->get_total(), array('currency' => $order->get_currency())),
                'meta'              => $meta_display,
                'is_box'            => $is_extra_box,
                'pack_instance_id'  => $pack_instance_id,
                'parent_grouped_id' => $parent_grouped_id,
                'pack_items'        => array(),
                'box_total'         => '',
                'box_units'         => 0,
            );

            if ($is_extra_box) {
                $raw_boxes[$item_id] = $line_data;
            } elseif (!empty($pack_instance_id) || !empty($parent_grouped_id)) {
                $child_items[$item_id] = $line_data;
            } else {
                $raw_standalone[$item_id] = $line_data;
            }
        }

        // Si hay cajas pero los alfajores no tenían metadatos de lote (ej: pedidos creados previamente),
        // asociar los productos alfajores a la caja si hay 1 sola caja en la orden
        if (!empty($raw_boxes) && empty($child_items) && !empty($raw_standalone)) {
            if (count($raw_boxes) === 1) {
                $child_items = $raw_standalone;
                $raw_standalone = array();
            }
        }

        // Asociar cada producto hijo a su caja correspondiente
        foreach ($child_items as $c_id => $child) {
            $assigned = false;
            // 1. Coincidencia exacta por pack_instance_id
            if (!empty($child['pack_instance_id'])) {
                foreach ($raw_boxes as $b_id => &$box) {
                    if (!empty($box['pack_instance_id']) && $box['pack_instance_id'] === $child['pack_instance_id']) {
                        $box['pack_items'][] = $child;
                        $assigned = true;
                        break;
                    }
                }
                unset($box);
            }

            // 2. Coincidencia por parent_grouped_id si hay una sola caja con ese parent
            if (!$assigned && !empty($child['parent_grouped_id'])) {
                foreach ($raw_boxes as $b_id => &$box) {
                    if (!empty($box['parent_grouped_id']) && $box['parent_grouped_id'] == $child['parent_grouped_id']) {
                        $box['pack_items'][] = $child;
                        $assigned = true;
                        break;
                    }
                }
                unset($box);
            }

            // 3. Fallback: si hay 1 sola caja en la orden, asignar a esa caja
            if (!$assigned && count($raw_boxes) === 1) {
                $single_box_key = array_key_first($raw_boxes);
                $raw_boxes[$single_box_key]['pack_items'][] = $child;
                $assigned = true;
            }

            // Si no pudo asignarse a ninguna caja, queda como producto suelto
            if (!$assigned) {
                $raw_standalone[$c_id] = $child;
            }
        }

        // Calcular el precio total y unidades de cada caja
        foreach ($raw_boxes as $b_id => &$box) {
            // Consolidar sabores repetidos dentro de la caja si fueron agregados por separado
            if (!empty($box['pack_items'])) {
                $box['pack_items'] = self::consolidate_order_items($box['pack_items'], $order->get_currency());
            }

            $box_total_num = $box['total_num'];
            $box_units = 0;
            foreach ($box['pack_items'] as $sub) {
                $box_total_num += $sub['total_num'];
                $box_units += $sub['quantity'];
            }
            $box['box_total_num'] = $box_total_num;
            $box['box_total']     = wc_price($box_total_num, array('currency' => $order->get_currency()));
            $box['box_units']     = $box_units;
        }
        unset($box);

        // Si no se descontó el stock de empaque todavía, procesarlo ahora para asegurar sincronización y metadatos
        if ($order->get_meta('_batllie_box_stock_deducted') !== 'yes' && class_exists('Batllie_Caja_Packing')) {
            Batllie_Caja_Packing::handle_order_box_stock_deduction($order->get_id(), array(), $order);
        }

        // Obtener imagen de caja representativa para las cajas automáticas
        $default_box_img = !empty($raw_boxes) ? reset($raw_boxes)['image'] : self::get_default_box_image_url();
        $has_courtesy    = ($order->get_meta('_batllie_has_courtesy_box') === 'yes');
        $has_decision    = ($order->get_meta('_batllie_decision_correcta') === 'yes') || ($order->get_meta('_batllie_aumento_pedido') === 'yes');

        // Empaquetar alfajores sueltos en cajas automáticas homogéneas (Caja 6, Caja 12, Cortesía)
        $packaged_loose = self::auto_package_loose_alfajores($raw_standalone, $order->get_currency(), $has_courtesy, $has_decision, $default_box_img);

        // Construir listado final: primero las cajas de packs, luego cajas automáticas de alfajores sueltos, y finalmente productos sueltos restantes
        $items = array_merge(array_values($raw_boxes), $packaged_loose['boxes'], $packaged_loose['remaining']);

        $date_created = $order->get_date_created();
        $time_diff = $date_created ? human_time_diff($date_created->getTimestamp(), current_time('timestamp')) : '';
        $time_formatted = $date_created ? $date_created->date_i18n('d/m/Y H:i') : '';

        // Formato con nombre de día y fecha para encabezado de historial (ej: Viernes 25/09/2026)
        $order_date_formatted = '';
        $order_date_key = '';
        if ($date_created) {
            $ts = $date_created->getTimestamp();
            $day_name = date_i18n('l', $ts);
            $day_capitalized = mb_convert_case($day_name, MB_CASE_TITLE, 'UTF-8');
            $date_str = date_i18n('d/m/Y', $ts);
            $order_date_formatted = $day_capitalized . ' ' . $date_str;
            $order_date_key = date_i18n('Y-m-d', $ts);
        }

        // Datos del cliente
        $first_name = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '';
        $last_name  = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '';
        $customer_name = trim($first_name . ' ' . $last_name);
        if (empty($customer_name)) {
            $customer_name = __('Cliente mostrador / Anónimo', 'emp-caja');
        }

        $phone = method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : '';
        $phone_data = self::normalize_argentine_phone($phone);

        // Formateo limpio de dirección evitando concatenación sin espacios
        $addr_1 = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1();
        $addr_2 = $order->get_shipping_address_2() ? $order->get_shipping_address_2() : $order->get_billing_address_2();
        $city = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
        $state = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
        $postcode = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();

        $parts = array_filter(array($addr_1, $addr_2, $city, $state, $postcode));
        $clean_address = implode(', ', $parts);
        if (empty($clean_address)) {
            $raw_addr = $order->get_formatted_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();
            $clean_address = trim(strip_tags(str_replace('<br/>', ', ', $raw_addr)));
        }

        $status = $order->get_status();
        
        $statuses_labels = array(
            'pending'           => __('Pendiente', 'emp-caja'),
            'processing'        => __('En preparación', 'emp-caja'),
            'enviando'          => __('Enviando', 'emp-caja'),
            'on-hold'           => __('Enviando', 'emp-caja'),
            'completed'         => __('Completado', 'emp-caja'),
            'recibido-problema' => __('Recibido (con inconvenientes)', 'emp-caja'),
            'failed'            => __('Recibido (con inconvenientes)', 'emp-caja'),
            'cancelled'         => __('Cancelado', 'emp-caja'),
            'refunded'          => __('Reembolzado', 'emp-caja'),
        );
        $status_name = isset($statuses_labels[$status]) ? $statuses_labels[$status] : wc_get_order_status_name($status);

        // Estado de Pago (personalizado para la caja)
        $payment_status = $order->get_meta('_caja_payment_status');
        if (empty($payment_status)) {
            if (in_array($status, array('processing', 'completed'))) {
                $payment_status = 'pagado';
            } elseif ($order->get_payment_method() === 'cod') {
                $payment_status = 'efectivo_entrega';
            } elseif ($status === 'refunded') {
                $payment_status = 'devolucion';
            } else {
                $payment_status = 'pendiente';
            }
        }

        // Estado de Envío (personalizado para la caja)
        $shipping_status = $order->get_meta('_caja_shipping_status');
        if (empty($shipping_status)) {
            $shipping_status = 'no_gestionado';
        }

        // Si el estado principal es Recibido (completed), sincronizar automáticamente el envío a recibido sin problemas
        if ($status === 'completed' && ($shipping_status === 'no_gestionado' || $shipping_status === 'esperando_repartidor' || $shipping_status === 'enviando' || $shipping_status === 'demorado')) {
            $shipping_status = 'entregado';
        } elseif ($status === 'recibido-problema' && ($shipping_status === 'no_gestionado' || $shipping_status === 'esperando_repartidor' || $shipping_status === 'enviando' || $shipping_status === 'demorado')) {
            $shipping_status = 'entregado_problemas';
        }

        $boxes_used = (array) ($order->get_meta('_batllie_boxes_used') ?: array());
        $saved_bags = (array) ($order->get_meta('_batllie_packaging_bags') ?: array());
        $packing_summary = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_order_boxes_summary($order) : null;

        return array(
            'id'              => $order_id,
            'number'          => $order->get_order_number(),
            'order_key'       => $order->get_order_key(),
            'status'          => $status,
            'status_name'     => $status_name,
            'status_badge'    => self::get_status_badge_class($status),
            'customer_name'   => $customer_name,
            'phone'           => !empty($phone_data['formatted']) ? $phone_data['formatted'] : $phone,
            'phone_clean'     => $phone_data['clean'],
            'phone_formatted' => $phone_data['formatted'],
            'phone_wa'        => $phone_data['wa'],
            'address'         => $clean_address,
            'customer_note'   => $order->get_customer_note(),
            'caja_note'       => (string) $order->get_meta('_caja_staff_note'),
            'payment_method'  => $order->get_payment_method_title() ? $order->get_payment_method_title() : __('No especificado', 'emp-caja'),
            'payment_status'  => $payment_status,
            'shipping_status' => $shipping_status,
            'shipping_method' => $order->get_shipping_method(),
            'total'           => wc_price($order->get_total(), array('currency' => $order->get_currency())),
            'total_raw'       => (float) $order->get_total(),
            'items'           => $items,
            'items_count'     => $order->get_item_count(),
            'decision_correcta'    => ($order->get_meta('_batllie_decision_correcta') === 'yes' || $order->get_meta('_batllie_aumento_pedido') === 'yes'),
            'aumento_pedido'       => ($order->get_meta('_batllie_aumento_pedido') === 'yes' || $order->get_meta('_batllie_decision_correcta') === 'yes'),
            'tarjeta_incluida'     => ($order->get_meta('_batllie_tarjeta_incluida') === 'yes'),
            'has_courtesy_box'     => ($order->get_meta('_batllie_has_courtesy_box') === 'yes'),
            'boxes_used'           => $boxes_used,
            'boxes_6_qty'          => isset($boxes_used['box_6']) ? intval($boxes_used['box_6']) : (!empty($packing_summary['box_6']) ? intval($packing_summary['box_6']) : 0),
            'boxes_12_qty'         => isset($boxes_used['box_12']) ? intval($boxes_used['box_12']) : (!empty($packing_summary['box_12']) ? intval($packing_summary['box_12']) : 0),
            'bag_large'            => isset($saved_bags['large']) ? intval($saved_bags['large']) : ($order->meta_exists('_batllie_bags_large_qty') ? intval($order->get_meta('_batllie_bags_large_qty')) : 1),
            'bag_small'            => isset($saved_bags['small']) ? intval($saved_bags['small']) : intval($order->get_meta('_batllie_bags_small_qty') ?: 0),
            'control_pedido_verified' => ($order->get_meta('_batllie_control_pedido_verified') === 'yes'),
            'packing_summary'      => $packing_summary,
            'time_diff'            => !empty($time_diff) ? sprintf(__('Hace %s', 'emp-caja'), $time_diff) : '',
            'time_formatted'       => $time_formatted,
            'order_date_formatted' => $order_date_formatted,
            'order_date_key'       => $order_date_key,
            'order_date'           => $date_created ? $date_created->date_i18n('d/m/Y') : '',
            'timestamp'            => $date_created ? $date_created->getTimestamp() : 0,
            'timeline'             => self::get_order_timeline($order),
        );
    }

    /**
     * Obtener clase CSS según el estado de WooCommerce
     */
    public static function get_status_badge_class($status) {
        switch ($status) {
            case 'pending':
                return 'caja-badge-pending';
            case 'processing':
                return 'caja-badge-processing';
            case 'enviando':
            case 'on-hold':
                return 'caja-badge-enviando';
            case 'completed':
                return 'caja-badge-completed';
            case 'recibido-problema':
            case 'failed':
                return 'caja-badge-recibido-problema';
            case 'cancelled':
                return 'caja-badge-cancelled';
            case 'refunded':
                return 'caja-badge-refunded';
            default:
                return 'caja-badge-default';
        }
    }

    /**
     * Actualizar estado de un pedido
     */
    public static function update_status($order_id, $new_status) {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Quitar prefijo 'wc-' si viene incluido
        $clean_status = str_replace('wc-', '', sanitize_key($new_status));

        // En la terminal de caja: si no se chequeó el pago todavía, el resto de pasos no pueden iniciarse
        if (!in_array($clean_status, array('pending', 'cancelled', 'refunded'), true)) {
            $payment_status = $order->get_meta('_caja_payment_status');
            if ($payment_status !== 'pagado' && $payment_status !== 'efectivo_entrega') {
                return new WP_Error('payment_required', __('Para iniciar los siguientes pasos, primero debes chequear y confirmar el pago (Pagado o Efectivo en entrega).', 'emp-caja'));
            }
        }

        // Si el estado principal pasa a "Recibido" (completed), actualizar automáticamente el estado del envío a "entregado" (Recibido sin problemas)
        if ($clean_status === 'completed') {
            $order->update_meta_data('_caja_shipping_status', 'entregado');
            self::add_timeline_event($order, __('llegó a destino (sin inconvenientes)', 'emp-caja'), '🏁', 'shipping_dest');
            self::add_timeline_event($order, __('pedido completado', 'emp-caja'), '✅', 'status_comp');
        } elseif ($clean_status === 'recibido-problema') {
            $order->update_meta_data('_caja_shipping_status', 'entregado_problemas');
            self::add_timeline_event($order, __('llegó a destino (con inconvenientes)', 'emp-caja'), '🛑', 'shipping_dest');
            self::add_timeline_event($order, __('pedido completado con inconvenientes', 'emp-caja'), '⚠️', 'status_comp');
        } elseif ($clean_status === 'processing') {
            self::add_timeline_event($order, __('en preparación', 'emp-caja'), '👨‍🍳', 'status_prep');
        } elseif ($clean_status === 'enviando' || $clean_status === 'on-hold') {
            self::add_timeline_event($order, __('se inició el proceso de envío', 'emp-caja'), '🛵', 'shipping_out');
        } elseif ($clean_status === 'cancelled') {
            self::add_timeline_event($order, __('pedido cancelado', 'emp-caja'), '❌', 'status');
        } elseif ($clean_status === 'refunded') {
            self::add_timeline_event($order, __('pedido reembolzado', 'emp-caja'), '🔄', 'status');
        }

        $order->update_status($clean_status, __('Estado modificado desde terminal Batllie Caja', 'emp-caja'));
        return self::format_order($order);
    }

    /**
     * Actualizar aclaración / nota de compra del pedido
     */
    public static function update_note($order_id, $note) {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $clean_note = sanitize_textarea_field(wp_unslash($note));
        $order->update_meta_data('_caja_staff_note', $clean_note);
        $order->save();

        self::add_timeline_event($order, __('se añadió una nota adicional al pedido', 'emp-caja'), '📝', 'note');

        return self::format_order($order);
    }

    /**
     * Actualizar estado personalizado (pago o envío)
     */
    public static function update_custom_status($order_id, $field, $value) {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $clean_field = sanitize_key($field);
        $clean_val   = sanitize_key($value);

        if ($clean_field === 'payment_status') {
            $allowed = array('pagado', 'pendiente', 'efectivo_entrega', 'pendiente_devolucion', 'devolucion');
            if (!in_array($clean_val, $allowed)) {
                return false;
            }
            $order->update_meta_data('_caja_payment_status', $clean_val);
            $order->save();

            if ($clean_val === 'pagado') {
                self::add_timeline_event($order, __('se confirmó el pago', 'emp-caja'), '💳', 'payment');
            } elseif ($clean_val === 'efectivo_entrega') {
                self::add_timeline_event($order, __('cobro: efectivo en entrega', 'emp-caja'), '💵', 'payment');
            } elseif ($clean_val === 'pendiente_devolucion') {
                self::add_timeline_event($order, __('pendiente de devolución', 'emp-caja'), '⏳', 'payment');
            } elseif ($clean_val === 'devolucion') {
                self::add_timeline_event($order, __('devolución confirmada', 'emp-caja'), '🔄', 'payment');
            }
        } elseif ($clean_field === 'shipping_status') {
            $allowed = array('no_gestionado', 'esperando_repartidor', 'enviando', 'demorado', 'entregado', 'entregado_problemas');
            if (!in_array($clean_val, $allowed)) {
                return false;
            }

            // Si no se chequeó el pago todavía, el envío no puede iniciarse
            if ($clean_val !== 'no_gestionado') {
                $pay_st = $order->get_meta('_caja_payment_status');
                if ($pay_st !== 'pagado' && $pay_st !== 'efectivo_entrega') {
                    return false;
                }
            }

            $order->update_meta_data('_caja_shipping_status', $clean_val);
            $order->save();

            if ($clean_val === 'esperando_repartidor') {
                self::add_timeline_event($order, __('se coordinó el envío', 'emp-caja'), '⏳', 'shipping_coord');
            } elseif ($clean_val === 'enviando') {
                $curr_st = $order->get_status();
                if ($curr_st === 'pending' || $curr_st === 'processing') {
                    $order->update_status('enviando', __('Repartidor enviando pedido', 'emp-caja'));
                }
                self::add_timeline_event($order, __('el envío salió a su destino', 'emp-caja'), '🛵', 'shipping_out');
            } elseif ($clean_val === 'demorado') {
                self::add_timeline_event($order, __('el repartidor con demora', 'emp-caja'), '⚠️', 'shipping_delay');
            } elseif ($clean_val === 'entregado') {
                $order->update_status('completed', __('Pedido marcado como recibido sin problemas desde terminal Batllie Caja', 'emp-caja'));
                self::add_timeline_event($order, __('llegó a destino (sin inconvenientes)', 'emp-caja'), '🏁', 'shipping_dest');
                self::add_timeline_event($order, __('pedido completado', 'emp-caja'), '✅', 'status_comp');
            } elseif ($clean_val === 'entregado_problemas') {
                $order->update_status('recibido-problema', __('Pedido marcado como recibido con problemas desde terminal Batllie Caja', 'emp-caja'));
                self::add_timeline_event($order, __('llegó a destino (con inconvenientes)', 'emp-caja'), '🛑', 'shipping_dest');
                self::add_timeline_event($order, __('pedido completado con inconvenientes', 'emp-caja'), '⚠️', 'status_comp');
            }
        } else {
            return false;
        }

        return self::format_order($order);
    }

    /**
     * Añadir un evento a la línea de tiempo del pedido
     */
    public static function add_timeline_event($order, $text, $icon = '⏱️', $type = 'general') {
        if (!class_exists('WooCommerce')) {
            return;
        }
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($order);
        }
        if (!$order) {
            return;
        }

        $raw = $order->get_meta('_caja_timeline');
        $timeline = array();
        if (is_array($raw)) {
            foreach ($raw as $ev) {
                if (is_array($ev) && !empty($ev['text']) && !empty($ev['time']) && $ev['text'] !== 'undefined') {
                    $timeline[] = $ev;
                }
            }
        }

        $now = current_time('timestamp');
        $time_str = date_i18n('H:i', $now);
        $date_str = date_i18n('d/m', $now);
        $day_name = date_i18n('l', $now);
        $day_cap  = mb_convert_case($day_name, MB_CASE_TITLE, 'UTF-8');
        $d_str    = date_i18n('d/m/Y', $now);
        $date_formatted = $day_cap . ' ' . $d_str;
        $date_key = date_i18n('Y-m-d', $now);

        $last_event = end($timeline);
        if ($last_event && isset($last_event['text']) && $last_event['text'] === $text && isset($last_event['time']) && $last_event['time'] === $time_str) {
            return;
        }

        $timeline[] = array(
            'time'           => $time_str,
            'date'           => $date_str,
            'date_formatted' => $date_formatted,
            'date_key'       => $date_key,
            'timestamp'      => $now,
            'text'           => $text,
            'icon'           => $icon,
            'type'           => $type,
        );

        $order->update_meta_data('_caja_timeline', $timeline);
        $order->save();
    }

    /**
     * Obtener y compilar la línea de tiempo / historial completo del pedido
     */
    public static function get_order_timeline($order) {
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($order);
        }
        if (!$order) {
            return array();
        }

        $raw = $order->get_meta('_caja_timeline');
        $timeline = array();
        $needs_meta_cleanup = false;
        if (is_array($raw)) {
            foreach ($raw as $ev) {
                if (is_array($ev) && !empty($ev['text']) && !empty($ev['time']) && $ev['text'] !== 'undefined') {
                    $timeline[] = $ev;
                } else {
                    $needs_meta_cleanup = true;
                }
            }
        } elseif (!empty($raw)) {
            $needs_meta_cleanup = true;
        }

        $date_created = $order->get_date_created();
        $date_paid    = $order->get_date_paid();

        // 1. Evento de llegada del pedido
        $has_created = false;
        foreach ($timeline as $ev) {
            if (isset($ev['type']) && $ev['type'] === 'created') {
                $has_created = true;
                break;
            }
        }
        if (!$has_created && $date_created) {
            array_unshift($timeline, array(
                'time'      => $date_created->date_i18n('H:i'),
                'date'      => $date_created->date_i18n('d/m'),
                'timestamp' => $date_created->getTimestamp(),
                'text'      => __('llegó el pedido', 'emp-caja'),
                'icon'      => '📥',
                'type'      => 'created'
            ));
        }

        // 2. Evento de confirmación de pago
        $has_paid = false;
        foreach ($timeline as $ev) {
            if (isset($ev['type']) && $ev['type'] === 'payment') {
                $has_paid = true;
                break;
            }
        }
        if (!$has_paid) {
            $pay_status = $order->get_meta('_caja_payment_status');
            $status     = $order->get_status();
            if ($date_paid) {
                $timeline[] = array(
                    'time'      => $date_paid->date_i18n('H:i'),
                    'date'      => $date_paid->date_i18n('d/m'),
                    'timestamp' => $date_paid->getTimestamp(),
                    'text'      => __('se confirmó el pago', 'emp-caja'),
                    'icon'      => '💳',
                    'type'      => 'payment'
                );
            } elseif ($pay_status === 'pagado' || in_array($status, array('processing', 'completed'))) {
                $ts = $date_created ? $date_created->getTimestamp() : current_time('timestamp');
                $timeline[] = array(
                    'time'      => date_i18n('H:i', $ts),
                    'date'      => date_i18n('d/m', $ts),
                    'timestamp' => $ts,
                    'text'      => __('se confirmó el pago', 'emp-caja'),
                    'icon'      => '💳',
                    'type'      => 'payment'
                );
            }
        }

        // 3. Reconstruir hitos clave para pedidos ya existentes
        $status   = $order->get_status();
        $shipping = $order->get_meta('_caja_shipping_status');
        $base_ts  = $date_created ? $date_created->getTimestamp() : current_time('timestamp');

        $has_prep = false;
        $has_coord = false;
        $has_ship = false;
        $has_dest = false;
        $has_comp = false;
        foreach ($timeline as $ev) {
            if (isset($ev['type'])) {
                if ($ev['type'] === 'status_prep') $has_prep = true;
                if ($ev['type'] === 'shipping_coord') $has_coord = true;
                if ($ev['type'] === 'shipping_out') $has_ship = true;
                if ($ev['type'] === 'shipping_dest') $has_dest = true;
                if ($ev['type'] === 'status_comp') $has_comp = true;
            }
        }

        if (!$has_prep && in_array($status, array('processing', 'enviando', 'completed', 'recibido-problema'))) {
            $timeline[] = array(
                'time'      => date_i18n('H:i', $base_ts + 60),
                'date'      => date_i18n('d/m', $base_ts + 60),
                'timestamp' => $base_ts + 60,
                'text'      => __('en preparación', 'emp-caja'),
                'icon'      => '👨‍🍳',
                'type'      => 'status_prep'
            );
        }

        if (!$has_coord && in_array($shipping, array('esperando_repartidor', 'enviando', 'demorado', 'entregado', 'entregado_problemas'))) {
            $timeline[] = array(
                'time'      => date_i18n('H:i', $base_ts + 120),
                'date'      => date_i18n('d/m', $base_ts + 120),
                'timestamp' => $base_ts + 120,
                'text'      => __('se coordinó el envío', 'emp-caja'),
                'icon'      => '⏳',
                'type'      => 'shipping_coord'
            );
        }

        if (!$has_ship && in_array($shipping, array('enviando', 'demorado', 'entregado', 'entregado_problemas'))) {
            $timeline[] = array(
                'time'      => date_i18n('H:i', $base_ts + 360),
                'date'      => date_i18n('d/m', $base_ts + 360),
                'timestamp' => $base_ts + 360,
                'text'      => __('el envío salió a su destino', 'emp-caja'),
                'icon'      => '🛵',
                'type'      => 'shipping_out'
            );
        }

        if (!$has_dest && in_array($shipping, array('entregado', 'entregado_problemas'))) {
            $timeline[] = array(
                'time'      => date_i18n('H:i', $base_ts + 900),
                'date'      => date_i18n('d/m', $base_ts + 900),
                'timestamp' => $base_ts + 900,
                'text'      => $shipping === 'entregado' ? __('llegó a destino (sin inconvenientes)', 'emp-caja') : __('llegó a destino (con inconvenientes)', 'emp-caja'),
                'icon'      => $shipping === 'entregado' ? '🏁' : '🛑',
                'type'      => 'shipping_dest'
            );
        }

        if (!$has_comp && in_array($status, array('completed', 'recibido-problema'))) {
            $timeline[] = array(
                'time'      => date_i18n('H:i', $base_ts + 900),
                'date'      => date_i18n('d/m', $base_ts + 900),
                'timestamp' => $base_ts + 900,
                'text'      => $status === 'completed' ? __('pedido completado', 'emp-caja') : __('pedido completado con inconvenientes', 'emp-caja'),
                'icon'      => '✅',
                'type'      => 'status_comp'
            );
        }

        // Filtrar estrictamente cualquier elemento no válido o con 'undefined'
        $timeline = array_values(array_filter($timeline, function($e) {
            return is_array($e) && !empty($e['text']) && !empty($e['time']) && $e['text'] !== 'undefined' && $e['time'] !== 'undefined';
        }));

        usort($timeline, function($a, $b) {
            return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
        });

        foreach ($timeline as &$ev) {
            $ev_ts = isset($ev['timestamp']) && is_numeric($ev['timestamp']) ? (int)$ev['timestamp'] : 0;
            if ($ev_ts > 0) {
                if (empty($ev['date_formatted'])) {
                    $day_name = date_i18n('l', $ev_ts);
                    $day_cap  = mb_convert_case($day_name, MB_CASE_TITLE, 'UTF-8');
                    $d_str    = date_i18n('d/m/Y', $ev_ts);
                    $ev['date_formatted'] = $day_cap . ' ' . $d_str;
                }
                if (empty($ev['date_key'])) {
                    $ev['date_key'] = date_i18n('Y-m-d', $ev_ts);
                }
            }
        }
        unset($ev);

        if ($needs_meta_cleanup) {
            $order->update_meta_data('_caja_timeline', $timeline);
            $order->save();
        }

        return $timeline;
    }

    /**
     * Sondeo de nuevos pedidos (Polling)
     */
    public static function poll_orders($last_seen_id = 0, $active_status = 'all') {
        if (!class_exists('WooCommerce')) {
            return array('new_orders_count' => 0, 'highest_id' => $last_seen_id, 'orders' => array());
        }

        $all_orders = self::get_orders($active_status, 40);
        $highest_id = $last_seen_id;
        $new_orders_count = 0;

        foreach ($all_orders as $order) {
            if (!empty($order['id'])) {
                if ($order['id'] > $last_seen_id) {
                    $new_orders_count++;
                }
                if ($order['id'] > $highest_id) {
                    $highest_id = $order['id'];
                }
            }
        }

        return array(
            'new_orders_count' => $new_orders_count,
            'highest_id'       => $highest_id,
            'orders'           => $all_orders
        );
    }

    /**
     * AJAX: Guardar cambios de empaque (cajas de 6, cajas de 12, bolsas grandes y chicas)
     */
    public static function ajax_update_packaging() {
        check_ajax_referer('batllie_caja_nonce', 'security');
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(array('message' => __('ID de pedido inválido.', 'emp-caja')));
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Pedido no encontrado.', 'emp-caja')));
        }

        $existing_boxes = $order->get_meta('_batllie_boxes_used') ?: array();
        $existing_bags  = $order->get_meta('_batllie_packaging_bags') ?: array();

        $boxes_6_raw = isset($_POST['boxes_6']) ? $_POST['boxes_6'] : (isset($_POST['box_6']) ? $_POST['box_6'] : null);
        $boxes_12_raw = isset($_POST['boxes_12']) ? $_POST['boxes_12'] : (isset($_POST['box_12']) ? $_POST['box_12'] : null);
        $bags_large_raw = isset($_POST['bags_large']) ? $_POST['bags_large'] : (isset($_POST['bag_large']) ? $_POST['bag_large'] : null);
        $bags_small_raw = isset($_POST['bags_small']) ? $_POST['bags_small'] : (isset($_POST['bag_small']) ? $_POST['bag_small'] : null);

        $boxes_6 = ($boxes_6_raw !== null) ? max(0, intval($boxes_6_raw)) : (isset($existing_boxes['box_6']) ? intval($existing_boxes['box_6']) : intval($order->get_meta('_batllie_boxes_6_qty') ?: 0));
        $boxes_12 = ($boxes_12_raw !== null) ? max(0, intval($boxes_12_raw)) : (isset($existing_boxes['box_12']) ? intval($existing_boxes['box_12']) : intval($order->get_meta('_batllie_boxes_12_qty') ?: 0));
        $bags_large = ($bags_large_raw !== null) ? max(0, intval($bags_large_raw)) : (isset($existing_bags['large']) ? intval($existing_bags['large']) : ($order->meta_exists('_batllie_bags_large_qty') ? intval($order->get_meta('_batllie_bags_large_qty')) : 1));
        $bags_small = ($bags_small_raw !== null) ? max(0, intval($bags_small_raw)) : (isset($existing_bags['small']) ? intval($existing_bags['small']) : intval($order->get_meta('_batllie_bags_small_qty') ?: 0));

        $boxes_used = array(
            'box_6'  => $boxes_6,
            'box_12' => $boxes_12,
        );
        $order->update_meta_data('_batllie_boxes_used', $boxes_used);
        $order->update_meta_data('_batllie_boxes_6_qty', $boxes_6);
        $order->update_meta_data('_batllie_boxes_12_qty', $boxes_12);

        $bags_used = array(
            'large' => $bags_large,
            'small' => $bags_small,
        );
        $order->update_meta_data('_batllie_packaging_bags', $bags_used);
        $order->update_meta_data('_batllie_bags_large_qty', $bags_large);
        $order->update_meta_data('_batllie_bags_small_qty', $bags_small);

        $order->save();

        self::add_timeline_event(
            $order,
            sprintf(__('Empaque modificado: %d Cajas x 12, %d Cajas x 6, %d Bolsas Grandes, %d Bolsas Chicas', 'emp-caja'), $boxes_12, $boxes_6, $bags_large, $bags_small),
            '📦',
            'packing'
        );

        wp_send_json_success(array(
            'order'   => self::format_order($order),
            'message' => __('Control de empaque guardado con éxito.', 'emp-caja'),
        ));
    }

    /**
     * AJAX: Confirmar control de pedido y despachar con repartidor
     */
    public static function ajax_verify_control_pedido() {
        check_ajax_referer('batllie_caja_nonce', 'security');
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(array('message' => __('ID de pedido inválido.', 'emp-caja')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Pedido no encontrado.', 'emp-caja')));
        }

        // Marcar control de pedido verificado
        $order->update_meta_data('_batllie_control_pedido_verified', 'yes');
        $order->update_meta_data('_caja_shipping_status', 'enviando');
        $order->update_status('enviando', __('Control de pedido completado al 100%. Paquete entregado al repartidor.', 'emp-caja'));

        self::add_timeline_event($order, __('Control de pedido verificado al 100%. Paquete entregado al repartidor.', 'emp-caja'), '📋', 'shipping_out');
        self::add_timeline_event($order, __('el envío salió a su destino', 'emp-caja'), '🛵', 'shipping_out');

        $order->save();

        wp_send_json_success(array(
            'order'   => self::format_order($order),
            'message' => __('¡Control de pedido verificado! Envío iniciado con éxito.', 'emp-caja'),
        ));
    }
}
