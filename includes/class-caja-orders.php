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
        } elseif ($clean_status === 'recibido-problema') {
            $order->update_meta_data('_caja_shipping_status', 'entregado_problemas');
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
        } elseif ($clean_field === 'shipping_status') {
            $allowed = array('no_gestionado', 'esperando_repartidor', 'enviando', 'demorado', 'entregado', 'entregado_problemas');
            if (!in_array($clean_val, $allowed)) {
                return false;
            }
            $order->update_meta_data('_caja_shipping_status', $clean_val);
            $order->save();
        } else {
            return false;
        }

        return self::format_order($order);
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
