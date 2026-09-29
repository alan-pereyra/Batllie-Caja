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
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'handle_order_box_stock_deduction'), 20, 1);
        add_action('woocommerce_new_order', array(__CLASS__, 'handle_order_box_stock_deduction'), 20, 1);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'handle_order_box_stock_deduction_on_status'), 20, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_box_stock_deduction_on_status'), 20, 1);
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'handle_order_box_stock_restoration'), 20, 1);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'handle_order_box_stock_restoration'), 20, 1);

        // Meta Box en WooCommerce Admin para configurar producto de caja y caja asociada a agrupados
        if (is_admin()) {
            add_action('add_meta_boxes', array(__CLASS__, 'register_wc_product_meta_box'));
            add_action('save_post_product', array(__CLASS__, 'save_wc_product_meta_box'), 10, 2);
        }

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
                update_post_meta($saved_id, '_batllie_is_official_box', 'box_' . $capacity);
                return (int) $saved_id;
            }
        }

        // Buscar producto por slug
        $slug = 'caja-empaque-batllie-' . $capacity;
        $existing = get_page_by_path($slug, OBJECT, 'product');
        if ($existing && $existing->post_status !== 'trash') {
            update_option($opt_key, $existing->ID);
            update_post_meta($existing->ID, '_batllie_is_official_box', 'box_' . $capacity);
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
            update_post_meta($new_id, '_batllie_is_official_box', 'box_' . $capacity);
            return (int) $new_id;
        }

        return 0;
    }

    /**
     * Obtener el ID del producto de WooCommerce asignado como Caja Oficial
     */
    public static function get_official_box_id($capacity) {
        $capacity = ($capacity == 12) ? 12 : 6;
        $opt_key  = ($capacity == 12) ? self::OPTION_BOX_12_ID : self::OPTION_BOX_6_ID;
        $saved_id = (int) get_option($opt_key, 0);

        if ($saved_id && function_exists('wc_get_product')) {
            $p = wc_get_product($saved_id);
            if ($p && $p->exists() && $p->get_status() !== 'trash') {
                return $saved_id;
            }
        }

        return (int) self::get_or_create_box_product($capacity);
    }

    /**
     * Obtener el nombre del producto asignado como Caja Oficial
     */
    public static function get_official_box_name($capacity) {
        $id = self::get_official_box_id($capacity);
        if ($id && function_exists('wc_get_product')) {
            $p = wc_get_product($id);
            if ($p) {
                return $p->get_name();
            }
        }
        return sprintf(__('Caja Batllié x %d unidades', 'emp-caja'), $capacity);
    }

    /**
     * Asignar un producto de WooCommerce como la Caja Oficial activa
     */
    public static function set_official_box_id($capacity, $product_id) {
        $capacity = ($capacity == 12) ? 12 : 6;
        $opt_key  = ($capacity == 12) ? self::OPTION_BOX_12_ID : self::OPTION_BOX_6_ID;
        $product_id = (int) $product_id;

        $old_id = (int) get_option($opt_key, 0);
        if ($old_id && $old_id !== $product_id) {
            delete_post_meta($old_id, '_batllie_is_official_box');
        }

        update_option($opt_key, $product_id);

        if ($product_id > 0 && function_exists('wc_get_product')) {
            update_post_meta($product_id, '_batllie_is_official_box', 'box_' . $capacity);
            update_post_meta($product_id, '_batllie_box_capacity', $capacity);

            // Asegurar que gestione stock en WooCommerce
            $p = wc_get_product($product_id);
            if ($p) {
                if (!$p->get_manage_stock()) {
                    $p->set_manage_stock(true);
                    if ($p->get_stock_quantity() === null) {
                        $p->set_stock_quantity(0);
                    }
                    $p->save();
                }
            }
        }

        return true;
    }

    /**
     * Obtener el rol de caja oficial de un producto ('box_6', 'box_12' o '')
     */
    public static function get_product_box_role($product_id) {
        $product_id = (int) $product_id;
        if (!$product_id) return '';
        $box_6_id = (int) get_option(self::OPTION_BOX_6_ID, 0);
        if ($product_id === $box_6_id) return 'box_6';
        $box_12_id = (int) get_option(self::OPTION_BOX_12_ID, 0);
        if ($product_id === $box_12_id) return 'box_12';

        $meta = get_post_meta($product_id, '_batllie_is_official_box', true);
        return in_array($meta, array('box_6', 'box_12'), true) ? $meta : '';
    }

    /**
     * Obtener el producto de caja asignado a un producto agrupado
     */
    public static function get_box_product_for_grouped($grouped_id, $capacity_hint = 6) {
        $grouped_id = (int) $grouped_id;
        if ($grouped_id > 0) {
            $assigned = get_post_meta($grouped_id, '_batllie_packaging_box_product_id', true);
            if (!empty($assigned) && is_numeric($assigned) && (int)$assigned > 0) {
                $p = wc_get_product((int)$assigned);
                if ($p && $p->exists() && $p->get_status() !== 'trash') {
                    return (int)$assigned;
                }
            } elseif ($assigned === 'box_12') {
                return self::get_official_box_id(12);
            } elseif ($assigned === 'box_6') {
                return self::get_official_box_id(6);
            }
        }

        if ($grouped_id > 0 && function_exists('wc_get_product')) {
            $gp = wc_get_product($grouped_id);
            if ($gp) {
                $gname = mb_strtolower($gp->get_name(), 'UTF-8');
                if (strpos($gname, '12') !== false) {
                    return self::get_official_box_id(12);
                } elseif (strpos($gname, '6') !== false) {
                    return self::get_official_box_id(6);
                }
            }
        }

        return self::get_official_box_id($capacity_hint);
    }

    /**
     * Obtener todos los productos candidatos a ser cajas de empaque
     */
    public static function get_all_box_candidates() {
        if (!function_exists('wc_get_products')) return array();

        $candidates = array();
        $b6_id  = self::get_official_box_id(6);
        $b12_id = self::get_official_box_id(12);
        $included_ids = array();

        if ($b6_id && function_exists('wc_get_product')) {
            $p6 = wc_get_product($b6_id);
            if ($p6 && $p6->exists() && $p6->get_status() !== 'trash') {
                $candidates[] = array(
                    'id'    => $b6_id,
                    'name'  => $p6->get_name(),
                    'stock' => $p6->get_stock_quantity() !== null ? (int)$p6->get_stock_quantity() : __('Ilimitado', 'emp-caja'),
                );
                $included_ids[] = $b6_id;
            }
        }

        if ($b12_id && function_exists('wc_get_product') && !in_array($b12_id, $included_ids, true)) {
            $p12 = wc_get_product($b12_id);
            if ($p12 && $p12->exists() && $p12->get_status() !== 'trash') {
                $candidates[] = array(
                    'id'    => $b12_id,
                    'name'  => $p12->get_name(),
                    'stock' => $p12->get_stock_quantity() !== null ? (int)$p12->get_stock_quantity() : __('Ilimitado', 'emp-caja'),
                );
                $included_ids[] = $b12_id;
            }
        }

        $products = wc_get_products(array(
            'status'     => 'publish',
            'limit'      => -1,
            'visibility' => 'any',
            'return'     => 'objects',
        ));

        foreach ($products as $p) {
            if ($p->is_type('grouped')) continue;
            $pid = $p->get_id();
            if (in_array($pid, $included_ids, true)) continue;

            $pname = $p->get_name();
            $lower = mb_strtolower($pname, 'UTF-8');

            $is_cand = (strpos($lower, 'caja') !== false || strpos($lower, 'empaque') !== false || strpos($lower, 'pack') !== false);
            if ($is_cand) {
                $candidates[] = array(
                    'id'    => $pid,
                    'name'  => $pname,
                    'stock' => $p->get_stock_quantity() !== null ? (int)$p->get_stock_quantity() : __('Ilimitado', 'emp-caja'),
                );
                $included_ids[] = $pid;
            }
        }

        return $candidates;
    }

    /**
     * Registrar Meta Box de Empaque y Stock en la edición de productos de WooCommerce
     */
    public static function register_wc_product_meta_box() {
        add_meta_box(
            'batllie_caja_product_packing_mb',
            __('📦 Empaque y Control de Stock Batllié', 'emp-caja'),
            array(__CLASS__, 'render_wc_product_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Renderizar Meta Box de Empaque y Stock en WooCommerce
     */
    public static function render_wc_product_meta_box($post) {
        wp_nonce_field('batllie_caja_product_packing_action', 'batllie_caja_product_packing_nonce');
        $product = wc_get_product($post->ID);
        $is_grouped = $product && $product->is_type('grouped');

        $b6_id   = self::get_official_box_id(6);
        $b6_name = self::get_official_box_name(6);
        $b12_id  = self::get_official_box_id(12);
        $b12_name = self::get_official_box_name(12);

        $current_role = self::get_product_box_role($post->ID);
        $assigned_box = get_post_meta($post->ID, '_batllie_packaging_box_product_id', true);
        $candidates   = self::get_all_box_candidates();

        ?>
        <div class="batllie-packing-mb-wrap" style="font-size:13px;">
            <?php if ($is_grouped): ?>
                <p><strong><?php _e('Caja física de empaque asociada:', 'emp-caja'); ?></strong></p>
                <select name="_batllie_packaging_box_product_id" style="width:100%; max-width:100%;">
                    <option value="0" <?php selected(!$assigned_box || $assigned_box === '0' || $assigned_box === 'auto'); ?>><?php _e('⚡ Automático (Detectar según unidades 6 o 12)', 'emp-caja'); ?></option>
                    <option value="<?php echo esc_attr($b6_id); ?>" <?php selected($assigned_box == $b6_id || $assigned_box === 'box_6'); ?>><?php echo esc_html(sprintf(__('📦 Caja Oficial 6u (%s)', 'emp-caja'), $b6_name)); ?></option>
                    <option value="<?php echo esc_attr($b12_id); ?>" <?php selected($assigned_box == $b12_id || $assigned_box === 'box_12'); ?>><?php echo esc_html(sprintf(__('📦 Caja Oficial 12u (%s)', 'emp-caja'), $b12_name)); ?></option>
                    <?php foreach ($candidates as $cand): ?>
                        <?php if ($cand['id'] != $b6_id && $cand['id'] != $b12_id): ?>
                            <option value="<?php echo esc_attr($cand['id']); ?>" <?php selected($assigned_box == $cand['id']); ?>><?php echo esc_html($cand['name'] . ' (' . $cand['stock'] . ' disp.)'); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <p class="description" style="margin-top:6px; color:#666;">
                    <?php _e('Al comprar este producto agrupado, se descontará del inventario físico 1 unidad de esta caja seleccionada.', 'emp-caja'); ?>
                </p>
            <?php else: ?>
                <p><strong><?php _e('Rol de Empaque / Caja Oficial:', 'emp-caja'); ?></strong></p>
                <select name="_batllie_official_box_role" id="_batllie_official_box_role" data-prev-val="<?php echo esc_attr($current_role ?: 'none'); ?>" style="width:100%; max-width:100%;">
                    <option value="none" <?php selected(empty($current_role) || $current_role === 'none'); ?>><?php _e('⚪ Producto estándar normal', 'emp-caja'); ?></option>
                    <option value="box_6" <?php selected($current_role === 'box_6'); ?>><?php _e('📦 Asignar como Caja Oficial de 6 unidades', 'emp-caja'); ?></option>
                    <option value="box_12" <?php selected($current_role === 'box_12'); ?>><?php _e('📦 Asignar como Caja Oficial de 12 unidades', 'emp-caja'); ?></option>
                </select>
                <p class="description" style="margin-top:6px; color:#666;">
                    <?php _e('Si se asigna como caja oficial, el sistema descontará el stock físico de este producto para los pedidos que agrupen alfajores.', 'emp-caja'); ?>
                </p>
                <script type="text/javascript">
                jQuery(function($){
                    $('#_batllie_official_box_role').on('change', function(){
                        var val = $(this).val();
                        var prevVal = $(this).data('prev-val') || 'none';
                        var currentId = <?php echo (int) $post->ID; ?>;
                        var b6Id = <?php echo (int) $b6_id; ?>;
                        var b12Id = <?php echo (int) $b12_id; ?>;

                        if (val === 'box_6' && b6Id && b6Id !== currentId) {
                            var confirmed = confirm("¿Está seguro de reemplazar el producto que se estaba considerando para las cajas de seis?\n\nActualmente la caja oficial es: \"<?php echo esc_js($b6_name); ?>\".\n\nSi confirma, a partir de ahora este producto será tomado como referencia para el empaque y control de stock de las cajas de 6 unidades.");
                            if (!confirmed) {
                                $(this).val(prevVal);
                                return;
                            }
                        } else if (val === 'box_12' && b12Id && b12Id !== currentId) {
                            var confirmed = confirm("¿Está seguro de reemplazar el producto que se estaba considerando para las cajas de doce?\n\nActualmente la caja oficial es: \"<?php echo esc_js($b12_name); ?>\".\n\nSi confirma, a partir de ahora este producto será tomado como referencia para el empaque y control de stock de las cajas de 12 unidades.");
                            if (!confirmed) {
                                $(this).val(prevVal);
                                return;
                            }
                        }
                        $(this).data('prev-val', val);
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Guardar Meta Box de Empaque en WooCommerce
     */
    public static function save_wc_product_meta_box($post_id, $post) {
        if (!isset($_POST['batllie_caja_product_packing_nonce']) || !wp_verify_nonce($_POST['batllie_caja_product_packing_nonce'], 'batllie_caja_product_packing_action')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['_batllie_packaging_box_product_id'])) {
            $p_box = sanitize_text_field($_POST['_batllie_packaging_box_product_id']);
            update_post_meta($post_id, '_batllie_packaging_box_product_id', $p_box);
        }

        if (isset($_POST['_batllie_official_box_role'])) {
            $role = sanitize_key($_POST['_batllie_official_box_role']);
            if ($role === 'box_6') {
                self::set_official_box_id(6, $post_id);
            } elseif ($role === 'box_12') {
                self::set_official_box_id(12, $post_id);
            } elseif ($role === 'none') {
                if (self::get_official_box_id(6) === $post_id) {
                    delete_option(self::OPTION_BOX_6_ID);
                }
                if (self::get_official_box_id(12) === $post_id) {
                    delete_option(self::OPTION_BOX_12_ID);
                }
                delete_post_meta($post_id, '_batllie_is_official_box');
            }
        }
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
        $id = self::get_official_box_id($capacity);
        if (!$id) return 0;
        $product = wc_get_product($id);
        if (!$product) return 0;
        return $product->get_stock_quantity() !== null ? (int) $product->get_stock_quantity() : 999;
    }

    /**
     * Actualizar stock de una caja física
     */
    public static function set_box_stock($capacity, $quantity) {
        $id = self::get_official_box_id($capacity);
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

        // Seguimiento de aumento de pedido: Si tuvo caja incompleta y luego la completó
        if ($missing_units > 0) {
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('batllie_had_incomplete_box', 'yes');
            }
            if (!headers_sent()) {
                setcookie('batllie_had_incomplete_box', 'yes', time() + 86400, '/');
            }
        } elseif ($status === 'all_boxed' && $loose_count >= 6) {
            $had_incomplete = (isset($_COOKIE['batllie_had_incomplete_box']) && $_COOKIE['batllie_had_incomplete_box'] === 'yes') ||
                              (function_exists('WC') && WC()->session && WC()->session->get('batllie_had_incomplete_box') === 'yes');
            if ($had_incomplete) {
                if (function_exists('WC') && WC()->session) {
                    WC()->session->set('batllie_aumento_pedido', 'yes');
                    WC()->session->set('batllie_decision_correcta', 'yes');
                }
                if (!headers_sent()) {
                    setcookie('batllie_aumento_pedido', 'yes', time() + 86400, '/');
                }
            }
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

            if (function_exists('WC') && WC()->session) {
                WC()->session->set('batllie_aumento_pedido', 'yes');
                WC()->session->set('batllie_decision_correcta', 'yes');
            }
            if (!headers_sent()) {
                setcookie('batllie_aumento_pedido', 'yes', time() + 86400, '/');
            }

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

        $had_incomplete = (isset($_COOKIE['batllie_had_incomplete_box']) && $_COOKIE['batllie_had_incomplete_box'] === 'yes') ||
                          (function_exists('WC') && WC()->session && WC()->session->get('batllie_had_incomplete_box') === 'yes');
        $increased_order = (isset($_COOKIE['batllie_aumento_pedido']) && $_COOKIE['batllie_aumento_pedido'] === 'yes') ||
                           (function_exists('WC') && WC()->session && WC()->session->get('batllie_aumento_pedido') === 'yes');

        if ($order && ($increased_order || $had_incomplete)) {
            $order->update_meta_data('_batllie_aumento_pedido', 'yes');
            $order->update_meta_data('_batllie_decision_correcta', 'yes');
        }
    }

    /**
     * Acciones al cambiar estado del pedido para descontar stock de empaque si no se descontó en checkout
     */
    public static function handle_order_box_stock_deduction_on_status($order_id) {
        self::handle_order_box_stock_deduction($order_id);
    }

    /**
     * Restaurar stock de cajas de empaque si el pedido es cancelado o reembolsado
     */
    public static function handle_order_box_stock_restoration($order_id) {
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        if ($order->get_meta('_batllie_box_stock_deducted') !== 'yes') {
            return;
        }

        $details = $order->get_meta('_batllie_box_stock_deducted_details');
        if (!empty($details) && is_array($details)) {
            foreach ($details as $box_id => $qty) {
                if ($qty <= 0) continue;
                $bp = wc_get_product($box_id);
                if ($bp && $bp->managing_stock()) {
                    wc_update_product_stock($bp, $qty, 'increase');
                }
            }
        }

        $order->update_meta_data('_batllie_box_stock_deducted', 'restored');
        if (class_exists('Batllie_Caja_Orders')) {
            Batllie_Caja_Orders::add_timeline_event(
                $order,
                __('Stock de cajas físicas devuelto al inventario por cancelación o reembolso.', 'emp-caja'),
                '↩️',
                'system'
            );
        }
        $order->save();
    }

    /**
     * Descontar stock de cajas físicas y registrar metadatos de "Decisión Correcta" al crear o procesar el pedido.
     * Descuenta tanto las cajas de packs agrupados como las cajas formadas por alfajores sueltos.
     */
    public static function handle_order_box_stock_deduction($order_id, $data = null, $order = null) {
        if (!$order && $order_id) {
            $order = wc_get_order($order_id);
        }
        if (!$order) return;

        // Evitar doble descuento si ya fue procesado
        if ($order->get_meta('_batllie_box_stock_deducted') === 'yes') {
            return;
        }

        $b6_id  = self::get_official_box_id(6);
        $b12_id = self::get_official_box_id(12);

        $box_stock_to_deduct = array(); // [ product_id => qty ]
        $pack_groups         = array(); // [ instance_key => [ 'parent_id' => X, 'units' => Y ] ]
        $loose_alfajores     = 0;
        $total_alfajores     = 0;

        foreach ($order->get_items() as $item_id => $item) {
            $qty = $item->get_quantity();
            $item_name = mb_strtolower($item->get_name(), 'UTF-8');
            $prod_id = $item->get_product_id();

            $is_extra_box = ($item->get_meta('_batllie_extra_box') === 'yes');
            $is_pack_parent = $is_extra_box || 
                              (strpos($item_name, 'caja x') !== false) || 
                              (strpos($item_name, 'caja ') !== false && (strpos($item_name, 'unidades') !== false || strpos($item_name, 'unidad') !== false));
            $parent_grouped_id = (int) $item->get_meta('_batllie_parent_grouped_id');
            $pack_instance_id  = $item->get_meta('_batllie_pack_instance_id');

            if ($parent_grouped_id || $pack_instance_id) {
                $instance_key = $pack_instance_id ?: ('p_' . $parent_grouped_id);
                if (!isset($pack_groups[$instance_key])) {
                    $pack_groups[$instance_key] = array(
                        'parent_id' => $parent_grouped_id,
                        'units'     => 0,
                    );
                }
                $pack_groups[$instance_key]['units'] += $qty;
                $total_alfajores += $qty;
            } elseif ($is_pack_parent) {
                // El ítem padre de la caja está como línea de pedido
                $cap = (strpos($item_name, '12') !== false) ? 12 : 6;
                $box_prod_id = self::get_box_product_for_grouped($prod_id, $cap);
                if (!isset($box_stock_to_deduct[$box_prod_id])) {
                    $box_stock_to_deduct[$box_prod_id] = 0;
                }
                $box_stock_to_deduct[$box_prod_id] += $qty;
            } else {
                $is_alf = self::is_alfajor_product($prod_id) || (strpos($item_name, 'alfajor') !== false) || (strpos($item_name, 'batllie') !== false);
                if ($is_alf) {
                    $loose_alfajores += $qty;
                    $total_alfajores += $qty;
                }
            }
        }

        // Si los packs agrupados fueron agregados como sub-ítems
        foreach ($pack_groups as $p) {
            $cap = ($p['units'] >= 12) ? 12 : 6;
            $box_prod_id = self::get_box_product_for_grouped($p['parent_id'], $cap);
            $boxes_needed = ($cap === 12) ? max(1, floor($p['units'] / 12)) : max(1, floor($p['units'] / 6));
            if (!isset($box_stock_to_deduct[$box_prod_id])) {
                $box_stock_to_deduct[$box_prod_id] = 0;
            }
            $box_stock_to_deduct[$box_prod_id] += $boxes_needed;
        }

        // 2. Empaquetar alfajores sueltos y asignar a las cajas oficiales
        $has_courtesy_meta = ($order->get_meta('_batllie_has_courtesy_box') === 'yes');
        if ($loose_alfajores > 0) {
            $u_left = $loose_alfajores;
            $loose_b12 = 0;
            $loose_b6  = 0;

            while ($u_left >= 12) {
                $loose_b12++;
                $u_left -= 12;
            }
            if ($u_left >= 6) {
                $loose_b6++;
                $u_left -= 6;
            }
            if ($u_left > 0 && $has_courtesy_meta) {
                $loose_b6++; // La caja de cortesía utiliza la caja física de 6
                $u_left = 0;
            }

            if ($loose_b12 > 0) {
                if (!isset($box_stock_to_deduct[$b12_id])) {
                    $box_stock_to_deduct[$b12_id] = 0;
                }
                $box_stock_to_deduct[$b12_id] += $loose_b12;
            }
            if ($loose_b6 > 0) {
                if (!isset($box_stock_to_deduct[$b6_id])) {
                    $box_stock_to_deduct[$b6_id] = 0;
                }
                $box_stock_to_deduct[$b6_id] += $loose_b6;
            }
        }

        // 3. Ejecutar descuento de stock para cada producto de caja física
        $deductions_log = array();
        foreach ($box_stock_to_deduct as $box_id => $qty) {
            if ($qty <= 0) continue;
            $bp = wc_get_product($box_id);
            if ($bp) {
                if ($bp->managing_stock()) {
                    wc_update_product_stock($bp, $qty, 'decrease');
                }
                $deductions_log[] = "{$qty}x {$bp->get_name()}";
            }
        }

        // 4. Determinar si califica para "Decisión Correcta"
        $has_upsell_add = false;
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_batllie_added_via_upsell') === 'yes') {
                $has_upsell_add = true;
                break;
            }
        }

        $session_aumento = false;
        if (function_exists('WC') && WC()->session) {
            $session_aumento = (WC()->session->get('batllie_aumento_pedido') === 'yes') || (WC()->session->get('batllie_had_incomplete_box') === 'yes');
        }
        $cookie_aumento = (!empty($_COOKIE['batllie_aumento_pedido']) && $_COOKIE['batllie_aumento_pedido'] === 'yes') ||
                          (!empty($_COOKIE['batllie_had_incomplete_box']) && $_COOKIE['batllie_had_incomplete_box'] === 'yes');
        $order_had_aumento = ($order->get_meta('_batllie_aumento_pedido') === 'yes');

        $decision_correcta = $has_upsell_add || $session_aumento || $cookie_aumento || $order_had_aumento || ($loose_alfajores > 0 && ($loose_alfajores % 6 === 0));

        $order->update_meta_data('_batllie_box_stock_deducted', 'yes');
        $order->update_meta_data('_batllie_box_stock_deducted_details', $box_stock_to_deduct);
        $order->update_meta_data('_batllie_decision_correcta', $decision_correcta ? 'yes' : 'no');
        $order->update_meta_data('_batllie_aumento_pedido', $decision_correcta ? 'yes' : 'no');

        if (!empty($deductions_log) && class_exists('Batllie_Caja_Orders')) {
            Batllie_Caja_Orders::add_timeline_event(
                $order,
                sprintf(__('Stock de empaque descontado: %s', 'emp-caja'), implode(', ', $deductions_log)),
                '📦',
                'system'
            );
        }

        if ($decision_correcta && class_exists('Batllie_Caja_Orders')) {
            Batllie_Caja_Orders::add_timeline_event(
                $order,
                __('🚀 ¡El cliente aumentó su pedido! Agregó alfajores extra para completar su caja por sugerencia de la web.', 'emp-caja'),
                '🚀',
                'system'
            );
        }

        $order->save();
    }
}
