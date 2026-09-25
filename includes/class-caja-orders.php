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
            'wc-completed'         => __('Recibido', 'emp-caja'),
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
        }
    }

    public static function render_checkout_phone_fix_script() {
        if (!is_checkout() && !is_cart()) {
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
                        input.placeholder = 'Teléfono *';
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

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $meta_display = array();
            
            // Imagen del producto
            $image_url = '';
            if ($product) {
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
            } else {
                $image_url = wc_placeholder_img_src('thumbnail');
            }

            // Metadatos formateados (variaciones, notas de cocina, etc.)
            $meta_data = $item->get_formatted_meta_data('');
            foreach ($meta_data as $meta) {
                $meta_display[] = strip_tags($meta->display_key . ': ' . $meta->display_value);
            }

            $items[] = array(
                'id'       => $item->get_product_id(),
                'name'     => $item->get_name(),
                'image'    => $image_url,
                'quantity' => $item->get_quantity(),
                'total'    => wc_price($item->get_total(), array('currency' => $order->get_currency())),
                'meta'     => $meta_display
            );
        }

        $date_created = $order->get_date_created();
        $time_diff = $date_created ? human_time_diff($date_created->getTimestamp(), current_time('timestamp')) : '';
        $time_formatted = $date_created ? $date_created->date_i18n('d/m/Y H:i') : '';

        // Datos del cliente
        $first_name = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '';
        $last_name  = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '';
        $customer_name = trim($first_name . ' ' . $last_name);
        if (empty($customer_name)) {
            $customer_name = __('Cliente mostrador / Anónimo', 'emp-caja');
        }

        $phone = method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : '';

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
            'completed'         => __('Recibido', 'emp-caja'),
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

        return array(
            'id'              => $order_id,
            'number'          => $order->get_order_number(),
            'status'          => $status,
            'status_name'     => $status_name,
            'status_badge'    => self::get_status_badge_class($status),
            'customer_name'   => $customer_name,
            'phone'           => $phone,
            'phone_clean'     => preg_replace('/[^0-9]/', '', $phone),
            'address'         => $clean_address,
            'customer_note'   => $order->get_customer_note(),
            'payment_method'  => $order->get_payment_method_title() ? $order->get_payment_method_title() : __('No especificado', 'emp-caja'),
            'payment_status'  => $payment_status,
            'shipping_status' => $shipping_status,
            'shipping_method' => $order->get_shipping_method(),
            'total'           => wc_price($order->get_total(), array('currency' => $order->get_currency())),
            'total_raw'       => (float) $order->get_total(),
            'items'           => $items,
            'items_count'     => $order->get_item_count(),
            'time_diff'       => !empty($time_diff) ? sprintf(__('Hace %s', 'emp-caja'), $time_diff) : '',
            'time_formatted'  => $time_formatted,
            'timestamp'       => $date_created ? $date_created->getTimestamp() : 0,
            'timeline'        => self::get_order_timeline($order),
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
            self::add_timeline_event($order, __('el repartidor salió', 'emp-caja'), '🛵', 'shipping_out');
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
        $order->set_customer_note($clean_note);
        $order->save();

        self::add_timeline_event($order, __('se añadió una aclaración al pedido', 'emp-caja'), '📝', 'note');

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
            $order->update_meta_data('_caja_shipping_status', $clean_val);
            $order->save();

            if ($clean_val === 'esperando_repartidor') {
                self::add_timeline_event($order, __('se coordinó el envío', 'emp-caja'), '⏳', 'shipping_coord');
            } elseif ($clean_val === 'enviando') {
                self::add_timeline_event($order, __('el repartidor salió', 'emp-caja'), '🛵', 'shipping_out');
            } elseif ($clean_val === 'demorado') {
                self::add_timeline_event($order, __('el repartidor con demora', 'emp-caja'), '⚠️', 'shipping_delay');
            } elseif ($clean_val === 'entregado') {
                self::add_timeline_event($order, __('llegó a destino (sin inconvenientes)', 'emp-caja'), '🏁', 'shipping_dest');
            } elseif ($clean_val === 'entregado_problemas') {
                self::add_timeline_event($order, __('llegó a destino (con inconvenientes)', 'emp-caja'), '🛑', 'shipping_dest');
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

        $timeline = (array) $order->get_meta('_caja_timeline');
        if (!is_array($timeline)) {
            $timeline = array();
        }

        $now = current_time('timestamp');
        $time_str = date_i18n('H:i', $now);
        $date_str = date_i18n('d/m', $now);

        $last_event = end($timeline);
        if ($last_event && isset($last_event['text']) && $last_event['text'] === $text && isset($last_event['time']) && $last_event['time'] === $time_str) {
            return;
        }

        $timeline[] = array(
            'time'      => $time_str,
            'date'      => $date_str,
            'timestamp' => $now,
            'text'      => $text,
            'icon'      => $icon,
            'type'      => $type,
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

        $timeline = (array) $order->get_meta('_caja_timeline');
        if (!is_array($timeline)) {
            $timeline = array();
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
                'text'      => __('el repartidor salió', 'emp-caja'),
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

        usort($timeline, function($a, $b) {
            return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
        });

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
}
