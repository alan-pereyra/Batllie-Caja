<?php
/**
 * Controlador de Empaque de Alfajores, Stock de Cajas y Gamificación
 * 
 * Gestiona:
 * 1. Control de stock físico de Cajas de 6 y Cajas de 12 en WooCommerce.
 * 2. Motor de empaque (Packing Engine) para alfajores sueltos y packs.
 * 3. Reglas de Caja de Cortesía:
 *    - Requiere al menos una caja previa completa.
 *    - Bloqueo imperativo si faltan menos de 3 unidades (faltan 1 o 2).
 *    - Concesión de Caja de Cortesía si faltan 3 o más unidades.
 *    - Recomendación persuasiva no bloqueante si hay menos de 1 caja completa.
 * 4. Descuento automático de stock de cajas físicas al confirmarse el pedido.
 * 5. Identificación de "Decisión Correcta" y alerta en el POS para incluir tarjeta de felicitaciones.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Packing {

    const OPTION_BOX_6_ID  = '_batllie_packing_box_6_id';
    const OPTION_BOX_12_ID = '_batllie_packing_box_12_id';

    public static function init() {
        // Asegurar que los productos de cajas existan al inicializar
        add_action('init', array(__CLASS__, 'ensure_packaging_products'), 5);

        // Descontar stock de cajas de empaque al procesar un pedido
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'handle_order_box_stock_deduction'), 20, 3);

        // Endpoint AJAX para consultar estado del empaque en frontend (Carrito y Checkout)
        add_action('wp_ajax_emp_caja_get_packing_status', array(__CLASS__, 'ajax_get_packing_status'));
        add_action('wp_ajax_nopriv_emp_caja_get_packing_status', array(__CLASS__, 'ajax_get_packing_status'));

        // Endpoint AJAX para agregar un alfajor rápido en 1-click desde el modal/banner
        add_action('wp_ajax_emp_caja_quick_add_alfajor', array(__CLASS__, 'ajax_quick_add_alfajor'));
        add_action('wp_ajax_nopriv_emp_caja_quick_add_alfajor', array(__CLASS__, 'ajax_quick_add_alfajor'));

        // Endpoint AJAX para marcar tarjeta de felicitación en la pantalla de Caja POS
        add_action('wp_ajax_emp_caja_toggle_tarjeta', array(__CLASS__, 'ajax_toggle_tarjeta'));

        // Guardar metadata de upsell en el ítem del pedido para acreditar Decisión Correcta
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_upsell_meta_to_order_item'), 10, 4);

        // Validación en servidor al enviar checkout clásico si faltan 1 o 2 unidades (bloqueo imperativo)
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'validate_checkout_packing'), 15, 2);

        // Validación en Store API (WooCommerce Blocks Checkout)
        add_action('woocommerce_store_api_checkout_update_order_from_request', array(__CLASS__, 'validate_store_api_packing'), 15, 2);
    }

    /**
     * Obtener o crear los productos físicos de packaging (Caja x6 y Caja x12) con stock activo
     */
    public static function get_or_create_box_product($capacity) {
        $capacity = ($capacity == 12) ? 12 : 6;
        $opt_key  = ($capacity == 12) ? self::OPTION_BOX_12_ID : self::OPTION_BOX_6_ID;
        $saved_id = get_option($opt_key, 0);

        if ($saved_id) {
            $p = wc_get_product($saved_id);
            if ($p && $p->exists() && $p->get_status() !== 'trash') {
                return (int) $saved_id;
            }
        }

        // Buscar producto por slug
        $slug = 'caja-empaque-batllie-' . $capacity;
        $existing = get_page_by_path($slug, OBJECT, 'product');
        if ($existing && $existing->post_status !== 'trash') {
            update_option($opt_key, $existing->ID);
            return (int) $existing->ID;
        }

        if (!class_exists('WC_Product_Simple')) {
            return 0;
        }

        // Crear producto físico de packaging con control de stock nativo
        $box = new WC_Product_Simple();
        $box->set_name(sprintf(__('Caja Batllié x %d unidades (Empaque)', 'emp-caja'), $capacity));
        $box->set_slug($slug);
        $box->set_status('publish');
        $box->set_catalog_visibility('hidden');
        $box->set_virtual(false);
        $box->set_price(0);
        $box->set_regular_price(0);
        $box->set_manage_stock(true);
        $box->set_stock_quantity(100); // Stock inicial predeterminado
        $box->set_sold_individually(false);
        $box->set_reviews_allowed(false);
        $box->set_sku('CAJA-EMP-' . $capacity);

        $new_id = $box->save();
        if ($new_id) {
            update_option($opt_key, $new_id);
            update_post_meta($new_id, '_batllie_box_capacity', $capacity);
            return (int) $new_id;
        }

        return 0;
    }

    /**
     * Asegurar que existan los dos productos de empaque
     */
    public static function ensure_packaging_products() {
        self::get_or_create_box_product(6);
        self::get_or_create_box_product(12);
    }

    /**
     * Obtener stock disponible de una caja física
     */
    public static function get_box_stock($capacity) {
        $id = self::get_or_create_box_product($capacity);
        if (!$id) return 0;
        $product = wc_get_product($id);
        if (!$product) return 0;
        return $product->get_stock_quantity() !== null ? (int) $product->get_stock_quantity() : 999;
    }

    /**
     * Actualizar stock de una caja física
     */
    public static function set_box_stock($capacity, $quantity) {
        $id = self::get_or_create_box_product($capacity);
        if (!$id) return false;
        $product = wc_get_product($id);
        if (!$product) return false;
        $product->set_stock_quantity(intval($quantity));
        $product->save();
        return true;
    }

    /**
     * Comprobar si un producto de WooCommerce es un alfajor
     */
    public static function is_alfajor_product($product_id) {
        if (!$product_id) return false;
        if (!function_exists('wc_get_product')) return false;
        $product = wc_get_product($product_id);
        if (!$product) return false;

        $name = mb_strtolower($product->get_name(), 'UTF-8');
        if (strpos($name, 'alfajor') !== false) {
            return true;
        }

        // Revisar categorías del producto
        $terms = get_the_terms($product_id, 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $t) {
                $slug = mb_strtolower($t->slug, 'UTF-8');
                $tname = mb_strtolower($t->name, 'UTF-8');
                if (strpos($slug, 'alfajor') !== false || strpos($tname, 'alfajor') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Obtener listado de alfajores activos en stock para ofrecer en el selector 1-Click
     */
    public static function get_available_alfajores_for_upsell() {
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        );

        $posts = get_posts($args);
        $alfajores = array();

        foreach ($posts as $post) {
            $p = wc_get_product($post->ID);
            if (!$p || !$p->is_purchasable() || !$p->is_in_stock()) {
                continue;
            }
            if ($p->is_type('grouped')) {
                continue; // Solo alfajores individuales
            }

            if (self::is_alfajor_product($post->ID)) {
                $image_id = $p->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                $alfajores[] = array(
                    'id'        => $p->get_id(),
                    'name'      => $p->get_name(),
                    'clean_name'=> trim(str_ireplace('Alfajor', '', $p->get_name())),
                    'price'     => floatval($p->get_price()),
                    'price_fmt' => wc_price($p->get_price()),
                    'image'     => $image_url,
                );
            }
        }

        return $alfajores;
    }

    /**
     * Motor de Análisis de Empaque (Packing Engine)
     * Analiza el carrito actual y devuelve la distribución de cajas y reglas de cortesía/bloqueo.
     */
    public static function analyze_cart($cart = null) {
        if ($cart === null && function_exists('WC') && WC()->cart) {
            $cart = WC()->cart;
        }

        if (!$cart || $cart->is_empty()) {
            return array(
                'has_alfajores'       => false,
                'total_alfajores'     => 0,
                'loose_alfajores'     => 0,
                'has_prior_box'       => false,
                'status'              => 'empty',
                'is_blocked'          => false,
                'courtesy_allowed'    => false,
                'missing_units'       => 0,
                'missing_for_12'      => 0,
                'message_type'        => 'none',
                'message'             => '',
                'boxes'               => array('box_12' => 0, 'box_6' => 0, 'courtesy' => 0),
                'stock_box_6'         => self::get_box_stock(6),
                'stock_box_12'        => self::get_box_stock(12),
                'available_alfajores' => array(),
            );
        }

        $options = Batllie_Caja_Plugin::get_color_settings();
        $priority = $options['packing_priority'] ?? '12'; // '12' (priorizar cajas de 12) o '6'

        $pack_groups     = array();
        $loose_alfajores = array();
        $other_items     = array();
        $total_alfajores = 0;
        $loose_count     = 0;

        foreach ($cart->get_cart() as $cart_item_key => $item) {
            $product_id = $item['product_id'];
            $qty        = (int) $item['quantity'];

            // Ítems que son parte de un pack agrupado cerrado
            if (!empty($item['batllie_parent_grouped_id']) || !empty($item['batllie_pack_instance_id'])) {
                $instance_id = $item['batllie_pack_instance_id'] ?? ('p_' . $item['batllie_parent_grouped_id']);
                if (!isset($pack_groups[$instance_id])) {
                    $pack_groups[$instance_id] = array(
                        'parent_id' => $item['batllie_parent_grouped_id'] ?? 0,
                        'units'     => 0,
                    );
                }
                $pack_groups[$instance_id]['units'] += $qty;
                $total_alfajores += $qty;
            } elseif (self::is_alfajor_product($product_id)) {
                // Alfajor suelto
                $loose_alfajores[$cart_item_key] = array(
                    'product_id' => $product_id,
                    'quantity'   => $qty,
                    'name'       => $item['data']->get_name(),
                );
                $loose_count += $qty;
                $total_alfajores += $qty;
            } else {
                $other_items[$cart_item_key] = $qty;
            }
        }

        // Cajas ya asignadas por packs agrupados
        $boxes_from_packs = array('box_12' => 0, 'box_6' => 0);
        foreach ($pack_groups as $p) {
            $units = $p['units'];
            if ($units >= 12) {
                $boxes_from_packs['box_12'] += floor($units / 12);
                $rem = $units % 12;
                if ($rem >= 6) {
                    $boxes_from_packs['box_6'] += floor($rem / 6);
                }
            } elseif ($units >= 6) {
                $boxes_from_packs['box_6'] += floor($units / 6);
            }
        }

        // 1. Verificar si existe al menos una caja previa completa
        // Existe caja previa si hay packs agrupados completos o si el total de alfajores en la compra es >= 6
        $has_prior_box = (count($pack_groups) > 0 || $total_alfajores >= 6);

        $status           = 'ok';
        $is_blocked       = false;
        $courtesy_allowed = false;
        $missing_units    = 0;
        $missing_for_12   = 0;
        $message_type     = 'none';
        $message          = '';
        $boxes_from_loose = array('box_12' => 0, 'box_6' => 0, 'courtesy' => 0);

        // CASO 1: NO HAY CAJA PREVIA COMPLETA (total alfajores de 1 a 5)
        if (!$has_prior_box) {
            if ($total_alfajores > 0) {
                $status           = 'no_prior_box';
                $courtesy_allowed = false;
                $missing_units    = 6 - $total_alfajores;
                $missing_for_12   = 12 - $total_alfajores;
                $message_type     = 'recommendation';
                $is_blocked       = false; // No bloquea si supera el mínimo de tienda con otros productos
                $message          = sprintf(
                    __('Tomaste una decisión muy valiosa al elegir nuestros productos. Pero tu experiencia podría ser aún mejor: sumando solo %d alfajor(es) más, recibís tu primera Caja Oficial Batllié.', 'emp-caja'),
                    $missing_units
                );
            }
        } else {
            // CASO 2: SÍ HAY AL MENOS UNA CAJA PREVIA COMPLETA
            if ($loose_count === 0) {
                // Todos los alfajores están en cajas cerradas de packs
                $status           = 'all_boxed';
                $is_blocked       = false;
                $courtesy_allowed = false;
                $message_type     = 'complete';
                $message          = __('¡Tus cajas están completas! Viví la experiencia completa Batllié.', 'emp-caja');
            } else {
                // Hay alfajores sueltos que deben agruparse
                // Lógica de prioridad (Prioridad 12):
                $c12 = floor($loose_count / 12);
                $rem = $loose_count % 12;

                $boxes_from_loose['box_12'] = $c12;

                if ($rem === 0) {
                    // Múltiplo exacto de 12
                    $status       = 'all_boxed';
                    $is_blocked   = false;
                    $message_type = 'complete';
                    $message      = __('¡Tus alfajores forman cajas completas de 12 unidades!', 'emp-caja');
                } elseif ($rem === 6) {
                    // Múltiplo exacto de 6
                    $boxes_from_loose['box_6'] = 1;
                    $status       = 'all_boxed';
                    $is_blocked   = false;
                    $message_type = 'complete';
                    $message      = __('¡Tus alfajores forman cajas completas!', 'emp-caja');
                } else {
                    // Hay un remanente que no llena una caja exacta
                    if ($rem > 6) {
                        // Llena 1 de 6 y sobran (rem - 6) alfajores
                        $boxes_from_loose['box_6'] = 1;
                        $extra = $rem - 6; // Entre 1 y 5
                    } else {
                        // Sobran rem alfajores (entre 1 y 5)
                        $extra = $rem;
                    }

                    // Faltantes para completar la siguiente caja de 6
                    $missing_to_6  = 6 - $extra;
                    $missing_units = $missing_to_6;
                    $missing_for_12 = 12 - $extra;

                $completed_boxes_desc = array();
                $b12_count = $boxes_from_packs['box_12'] + $boxes_from_loose['box_12'];
                $b6_count  = $boxes_from_packs['box_6'] + $boxes_from_loose['box_6'];

                if ($b12_count > 0) {
                    $completed_boxes_desc[] = sprintf(_n('%d Caja x 12', '%d Cajas x 12', $b12_count, 'emp-caja'), $b12_count);
                }
                if ($b6_count > 0) {
                    $completed_boxes_desc[] = sprintf(_n('%d Caja x 6', '%d Cajas x 6', $b6_count, 'emp-caja'), $b6_count);
                }
                $completed_boxes_text = !empty($completed_boxes_desc) ? (implode(' y ', $completed_boxes_desc) . __(' ya armada', 'emp-caja')) : '';
                $current_box_num = ($b12_count + $b6_count) + 1;
                $current_box_units = $extra;
                $current_box_capacity = 6;

                // REGLA CLAVE: ¿Faltan MENOS de 3 unidades (faltan 1 o 2)?
                if ($missing_to_6 < 3) {
                    // Faltan 1 o 2 alfajores: BLOQUEO IMPERATIVO
                    $status           = 'imperative_missing';
                    $is_blocked       = true;
                    $courtesy_allowed = false;
                    $message_type     = 'imperative';

                    if (!empty($completed_boxes_text)) {
                        $message = sprintf(
                            __('Tenés %d alfajores en total (%s). Tu %dª caja tiene %d de 6 alfajores. Agregá %s para poder despachar todo en caja cerrada.', 'emp-caja'),
                            $total_alfajores,
                            $completed_boxes_text,
                            $current_box_num,
                            $extra,
                            ($missing_to_6 === 1 ? __('el alfajor faltante', 'emp-caja') : sprintf(__('los %d alfajores faltantes', 'emp-caja'), $missing_to_6))
                        );
                    } else {
                        $message = sprintf(
                            __('Tenés %d alfajores en total. Estás a solo %d alfajor(es) de completar tu caja para poder despachar tu pedido.', 'emp-caja'),
                            $total_alfajores,
                            $missing_to_6
                        );
                    }
                } else {
                    // Faltan 3 o más unidades (ej: sobran 3, faltan 3):
                    // SE PERMITE AVANZAR Y SE OFRECE CAJA DE CORTESÍA SI NO AGREGA
                    $status           = 'courtesy_available';
                    $is_blocked       = false;
                    $courtesy_allowed = true;
                    $boxes_from_loose['courtesy'] = 1;
                    $message_type     = 'upsell';

                    if (!empty($completed_boxes_text)) {
                        $message = sprintf(
                            __('Tenés %d alfajores en total (%s). Tu %dª caja tiene %d de 6. Con solo %d más completás tu caja (o +%d para Caja de 12). Si no los agregás, ¡te regalamos una Caja de Cortesía para que viajen protegidos!', 'emp-caja'),
                            $total_alfajores,
                            $completed_boxes_text,
                            $current_box_num,
                            $extra,
                            $missing_to_6,
                            $missing_for_12
                        );
                    } else {
                        $message = sprintf(
                            __('Tu pedido está excelente, pero podría ser aún mejor: con solo %d alfajor(es) más completás tu caja (o +%d para la Caja Premium de 12). Si no los agregás, ¡te regalamos una Caja de Cortesía para que viajen protegidos!', 'emp-caja'),
                            $missing_to_6,
                            $missing_for_12
                        );
                    }
                }
            }
        }
    }

        $total_boxes = array(
            'box_12'   => $boxes_from_packs['box_12'] + $boxes_from_loose['box_12'],
            'box_6'    => $boxes_from_packs['box_6'] + $boxes_from_loose['box_6'],
            'courtesy' => $boxes_from_loose['courtesy'],
        );

        $completed_boxes_desc = array();
        if ($total_boxes['box_12'] > 0) {
            $completed_boxes_desc[] = sprintf(_n('%d Caja x 12', '%d Cajas x 12', $total_boxes['box_12'], 'emp-caja'), $total_boxes['box_12']);
        }
        if ($total_boxes['box_6'] > 0) {
            $completed_boxes_desc[] = sprintf(_n('%d Caja x 6', '%d Cajas x 6', $total_boxes['box_6'], 'emp-caja'), $total_boxes['box_6']);
        }
        $completed_boxes_text = !empty($completed_boxes_desc) ? (implode(' y ', $completed_boxes_desc) . __(' ya armada', 'emp-caja')) : '';

        if (!isset($current_box_num)) {
            $current_box_num = ($total_boxes['box_12'] + $total_boxes['box_6']) + 1;
        }
        if (!isset($current_box_units)) {
            $current_box_units = ($status === 'all_boxed') ? 6 : ($total_alfajores % 6 ?: 6);
        }
        if (!isset($current_box_capacity)) {
            $current_box_capacity = 6;
        }

        return array(
            'has_alfajores'        => ($total_alfajores > 0),
            'total_alfajores'      => $total_alfajores,
            'loose_alfajores'      => $loose_count,
            'has_prior_box'        => $has_prior_box,
            'completed_boxes_6'    => $total_boxes['box_6'],
            'completed_boxes_12'   => $total_boxes['box_12'],
            'completed_boxes_text' => $completed_boxes_text,
            'current_box_num'      => $current_box_num,
            'current_box_units'    => $current_box_units,
            'current_box_capacity' => $current_box_capacity,
            'status'               => $status,
            'is_blocked'           => $is_blocked,
            'courtesy_allowed'     => $courtesy_allowed,
            'missing_units'        => $missing_units,
            'missing_for_12'       => $missing_for_12,
            'message_type'         => $message_type,
            'message'              => $message,
            'boxes'                => $total_boxes,
            'stock_box_6'          => self::get_box_stock(6),
            'stock_box_12'         => self::get_box_stock(12),
            'available_alfajores'  => self::get_available_alfajores_for_upsell(),
        );
    }

    /**
     * Obtener resumen detallado de cajas asignadas para un pedido (Comanda Mostrador / Cocina)
     * Soporta cálculo retroactivo si el pedido fue creado sin metadata
     */
    public static function get_order_boxes_summary($order) {
        if (!$order) {
            return null;
        }

        $boxes = $order->get_meta('_batllie_boxes_used');
        $has_courtesy_meta = ($order->get_meta('_batllie_has_courtesy_box') === 'yes');

        $box_12   = !empty($boxes['box_12']) ? intval($boxes['box_12']) : 0;
        $box_6    = !empty($boxes['box_6']) ? intval($boxes['box_6']) : 0;
        $courtesy = !empty($boxes['courtesy']) ? intval($boxes['courtesy']) : ($has_courtesy_meta ? 1 : 0);

        // Si no hay cajas guardadas, calcular retroactivamente separando packs de alfajores sueltos
        $boxes_from_packs_12 = 0;
        $boxes_from_packs_6  = 0;
        $loose_alfajores     = 0;
        $total_alfajores     = 0;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $item_name  = mb_strtolower($item->get_name(), 'UTF-8');
            $qty        = $item->get_quantity();

            $is_extra_box = (method_exists($item, 'get_meta') && $item->get_meta('_batllie_extra_box') === 'yes');
            $is_pack_parent = $is_extra_box || 
                              (strpos($item_name, 'caja x') !== false) || 
                              (strpos($item_name, 'caja ') !== false && (strpos($item_name, 'unidades') !== false || strpos($item_name, 'unidad') !== false));
            $is_pack_child = method_exists($item, 'get_meta') && (!empty($item->get_meta('_batllie_parent_grouped_id')) || !empty($item->get_meta('_batllie_pack_instance_id')));

            if ($is_pack_parent) {
                if (strpos($item_name, '12') !== false) {
                    $boxes_from_packs_12 += $qty;
                } else {
                    $boxes_from_packs_6 += $qty;
                }
            } elseif ($is_pack_child) {
                $total_alfajores += $qty;
            } else {
                $is_alf = self::is_alfajor_product($product_id) || (strpos($item_name, 'alfajor') !== false) || (strpos($item_name, 'batllie') !== false);
                if ($is_alf) {
                    $loose_alfajores += $qty;
                    $total_alfajores += $qty;
                }
            }
        }

        if ($box_12 === 0 && $box_6 === 0) {
            $box_12 = $boxes_from_packs_12;
            $box_6  = $boxes_from_packs_6;

            if ($loose_alfajores >= 12) {
                $box_12 += floor($loose_alfajores / 12);
                $rem = $loose_alfajores % 12;
                if ($rem >= 6) {
                    $box_6 += floor($rem / 6);
                    $rem = $rem % 6;
                }
                if ($rem > 0 && ($has_courtesy_meta || $courtesy > 0)) {
                    $courtesy = 1;
                }
            } elseif ($loose_alfajores >= 6) {
                $box_6 += floor($loose_alfajores / 6);
                $rem = $loose_alfajores % 6;
                if ($rem > 0 && ($has_courtesy_meta || $courtesy > 0)) {
                    $courtesy = 1;
                }
            } elseif ($has_courtesy_meta || $courtesy > 0) {
                $courtesy = 1;
            }
        }

        $desc_parts = array();
        if ($box_12 > 0) {
            $desc_parts[] = sprintf(_n('%d Caja x 12', '%d Cajas x 12', $box_12, 'emp-caja'), $box_12);
        }
        if ($box_6 > 0) {
            $desc_parts[] = sprintf(_n('%d Caja x 6', '%d Cajas x 6', $box_6, 'emp-caja'), $box_6);
        }
        if ($courtesy > 0) {
            $desc_parts[] = __('🎁 Caja de Cortesía', 'emp-caja');
        }

        $boxed_units = ($box_12 * 12) + ($box_6 * 6);
        $loose_remaining = max(0, $total_alfajores - $boxed_units);
        if ($loose_remaining > 0 && $courtesy === 0) {
            if (empty($desc_parts)) {
                $desc_parts[] = sprintf(_n('%d alfajor suelto', '%d alfajores sueltos', $loose_remaining, 'emp-caja'), $loose_remaining);
            } else {
                $desc_parts[] = sprintf(_n('+ %d suelto', '+ %d sueltos', $loose_remaining, 'emp-caja'), $loose_remaining);
            }
        }

        $summary_text = !empty($desc_parts) ? implode(' + ', $desc_parts) : '';
        $has_boxes = ($box_12 > 0 || $box_6 > 0 || $courtesy > 0 || $total_alfajores > 0);

        return array(
            'has_boxes'       => $has_boxes,
            'total_alfajores' => $total_alfajores,
            'box_12'          => $box_12,
            'box_6'           => $box_6,
            'courtesy'        => $courtesy,
            'loose'           => $loose_remaining,
            'summary_text'    => $summary_text,
        );
    }

    /**
     * Obtener texto descriptivo de empaque para el cliente en el carrito
     */
    public static function get_cart_packaging_text($packing = null) {
        if ($packing === null) {
            $packing = self::analyze_cart();
        }

        if (empty($packing) || empty($packing['has_alfajores'])) {
            return array(
                'has_alfajores' => false,
                'short'         => '',
                'sentence'      => '',
                'total'         => 0,
            );
        }

        $total = $packing['total_alfajores'] ?? 0;
        $b12   = $packing['boxes']['box_12'] ?? 0;
        $b6    = $packing['boxes']['box_6'] ?? 0;
        $court = $packing['boxes']['courtesy'] ?? 0;

        $parts = array();
        if ($b12 > 0) {
            $parts[] = sprintf(_n('%d Caja x 12', '%d Cajas x 12', $b12, 'emp-caja'), $b12);
        }
        if ($b6 > 0) {
            $parts[] = sprintf(_n('%d Caja x 6', '%d Cajas x 6', $b6, 'emp-caja'), $b6);
        }
        if ($court > 0) {
            $parts[] = __('🎁 Caja de Cortesía de regalo', 'emp-caja');
        }

        $short = implode(' + ', $parts);
        if (empty($short)) {
            $short = sprintf(_n('%d alfajor', '%d alfajores', $total, 'emp-caja'), $total);
        }

        if ($packing['status'] === 'all_boxed') {
            if ($b12 > 0 && $b6 === 0) {
                $sentence = sprintf(
                    _n('Tus %d alfajores viajarán protegidos en %s Oficial Batllié.', 'Tus %d alfajores viajarán protegidos en %s Oficiales Batllié.', $b12, 'emp-caja'),
                    $total,
                    $short
                );
            } else {
                $sentence = sprintf(
                    __('Tus %d alfajores viajarán protegidos en %s.', 'emp-caja'),
                    $total,
                    $short
                );
            }
        } elseif ($court > 0) {
            $sentence = sprintf(
                __('Tus %d alfajores viajarán en %s.', 'emp-caja'),
                $total,
                $short
            );
        } else {
            $sentence = sprintf(
                __('Tus %d alfajores se asignan a %s.', 'emp-caja'),
                $total,
                $short
            );
        }

        return array(
            'has_alfajores' => true,
            'short'         => $short,
            'sentence'      => $sentence,
            'total'         => $total,
        );
    }

    /**
     * Endpoint AJAX para consultar estado del empaque
     */
    public static function ajax_get_packing_status() {
        check_ajax_referer('batllie_caja_nonce', 'security');
        $analysis = self::analyze_cart();
        wp_send_json_success($analysis);
    }

    /**
     * Endpoint AJAX para añadir alfajor rápido en 1 clic
     */
    public static function ajax_quick_add_alfajor() {
        check_ajax_referer('batllie_caja_nonce', 'security');
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity   = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;

        if (!$product_id || !self::is_alfajor_product($product_id)) {
            wp_send_json_error(array('message' => __('Producto no válido.', 'emp-caja')));
        }

        $passed = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity);
        if ($passed) {
            WC()->cart->add_to_cart($product_id, $quantity, 0, array(), array(
                '_batllie_added_via_upsell' => 'yes',
            ));
            WC()->cart->calculate_totals();

            // Re-analizar carrito tras la adición
            $analysis = self::analyze_cart();
            $min      = class_exists('Batllie_Caja_Min_Order') ? Batllie_Caja_Min_Order::get_min_purchase_amount() : 0.0;
            $amount   = class_exists('Batllie_Caja_Min_Order') ? Batllie_Caja_Min_Order::get_cart_amount() : 0.0;
            $missing  = max(0.0, $min - $amount);
            $is_below = ($min > 0 && $amount < $min);

            wp_send_json_success(array(
                'added'             => true,
                'analysis'          => $analysis,
                'min_amount'        => $min,
                'current_amount'    => $amount,
                'missing_amount'    => $missing,
                'is_below_min'      => $is_below,
                'current_formatted' => wc_price($amount),
                'missing_formatted' => wc_price($missing),
                'cart_url'          => wc_get_cart_url(),
                'checkout_url'      => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/finalizar-compra/'),
            ));
        }

        wp_send_json_error(array('message' => __('No se pudo añadir el producto al carrito.', 'emp-caja')));
    }

    /**
     * Guardar metadata de upsell en el ítem del pedido para acreditar Decisión Correcta
     */
    public static function save_upsell_meta_to_order_item($item, $cart_item_key, $values, $order) {
        if (!empty($values['_batllie_added_via_upsell'])) {
            $item->add_meta_data('_batllie_added_via_upsell', 'yes', true);
        }
    }

    /**
     * Endpoint AJAX para marcar tarjeta de felicitación en la pantalla de Caja POS
     */
    public static function ajax_toggle_tarjeta() {
        check_ajax_referer('batllie_caja_nonce', 'security');
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $incluida = !empty($_POST['incluida']) && $_POST['incluida'] === 'yes';

        if (!$order_id) {
            wp_send_json_error();
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error();
        }

        $order->update_meta_data('_batllie_tarjeta_incluida', $incluida ? 'yes' : 'no');
        $order->save();

        // Registrar en timeline de la caja
        $log_msg = $incluida ? __('Se incluyó la tarjeta de felicitación en el paquete.', 'emp-caja') : __('Se desmarcó la tarjeta de felicitación.', 'emp-caja');
        if (class_exists('Batllie_Caja_Orders')) {
            Batllie_Caja_Orders::add_timeline_event($order, $log_msg, '💌', 'note');
        }

        wp_send_json_success(array(
            'order_id' => $order_id,
            'incluida' => $incluida,
        ));
    }

    /**
     * Validación en el checkout clásico: Bloquear si faltan 1 o 2 unidades (bloqueo imperativo)
     */
    public static function validate_checkout_packing($data, $errors) {
        $analysis = self::analyze_cart();
        if (!empty($analysis['is_blocked']) && !empty($analysis['missing_units'])) {
            $errors->add(
                'batllie_packing_imperative_error',
                sprintf(
                    __('Estás a solo %d alfajor(es) de completar tu caja. Por favor agregalos a tu pedido para poder despacharlo en caja cerrada.', 'emp-caja'),
                    $analysis['missing_units']
                )
            );
        }
    }

    /**
     * Validación en WooCommerce Blocks Store API: Bloquear si faltan 1 o 2 unidades
     */
    public static function validate_store_api_packing($order, $request) {
        $analysis = self::analyze_cart();
        if (!empty($analysis['is_blocked']) && !empty($analysis['missing_units'])) {
            if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'woocommerce_rest_packing_blocked',
                    sprintf(
                        __('Estás a solo %d alfajor(es) de completar tu caja. Por favor agregalos a tu pedido para poder despacharlo en caja cerrada.', 'emp-caja'),
                        $analysis['missing_units']
                    ),
                    400
                );
            }
        }
    }

    /**
     * Descontar stock de cajas físicas y registrar metadatos de "Decisión Correcta" al crear el pedido
     */
    public static function handle_order_box_stock_deduction($order_id, $data, $order) {
        if (!$order_id || !$order) return;

        // Analizar productos del pedido
        $analysis = self::analyze_cart();
        $boxes = $analysis['boxes'];

        // 1. Descontar stock de Cajas de 12 físicas
        if (!empty($boxes['box_12']) && $boxes['box_12'] > 0) {
            $box_12_id = self::get_or_create_box_product(12);
            if ($box_12_id) {
                $p12 = wc_get_product($box_12_id);
                if ($p12 && $p12->managing_stock()) {
                    wc_update_product_stock($p12, $boxes['box_12'], 'decrease');
                }
            }
        }

        // 2. Descontar stock de Cajas de 6 físicas (incluyendo cajas de cortesía concedidas)
        $qty_box_6 = (!empty($boxes['box_6']) ? $boxes['box_6'] : 0) + (!empty($boxes['courtesy']) ? $boxes['courtesy'] : 0);
        if ($qty_box_6 > 0) {
            $box_6_id = self::get_or_create_box_product(6);
            if ($box_6_id) {
                $p6 = wc_get_product($box_6_id);
                if ($p6 && $p6->managing_stock()) {
                    wc_update_product_stock($p6, $qty_box_6, 'decrease');
                }
            }
        }

        // 3. Determinar si califica para "Decisión Correcta"
        // Califica si el cliente completó todas sus cajas (status == all_boxed o no le quedaron sueltos fuera de caja)
        // O si añadió algún ítem vía el botón de upsell
        $has_upsell_add = false;
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_batllie_added_via_upsell') === 'yes') {
                $has_upsell_add = true;
                break;
            }
        }

        $decision_correcta = ($has_upsell_add || ($analysis['has_prior_box'] && $analysis['status'] === 'all_boxed'));

        $order->update_meta_data('_batllie_boxes_used', $boxes);
        $order->update_meta_data('_batllie_has_courtesy_box', (!empty($boxes['courtesy']) && $boxes['courtesy'] > 0) ? 'yes' : 'no');
        $order->update_meta_data('_batllie_decision_correcta', $decision_correcta ? 'yes' : 'no');
        $order->update_meta_data('_batllie_tarjeta_incluida', 'no');
        $order->save();
    }
}
