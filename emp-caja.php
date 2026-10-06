<?php
/**
 * Plugin Name: Batllie Caja & Pedidos POS
 * Plugin URI: https://empralidad.com.ar/batllie
 * Description: Sistema de Caja y Control de Pedidos en tiempo real para WooCommerce con sonido de alerta, vista aislada para mostrador/cocina, gestión de estados, alta de productos y colores 100% personalizables. Shortcode: [batllie_caja].
 * Version: 1.8.50
 * Author: Empralidad / Batllie
 * Author URI: https://empralidad.com.ar
 * Text Domain: emp-caja
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit; // Evitar acceso directo
}

// Constantes del Plugin
define('EMP_CAJA_VERSION', '1.8.50');
define('EMP_CAJA_FILE', __FILE__);
define('EMP_CAJA_PATH', plugin_dir_path(__FILE__));
define('EMP_CAJA_URL', plugin_dir_url(__FILE__));

/**
 * Clase Principal del Plugin
 */
class Batllie_Caja_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Cargar módulos
        $this->includes();

        // Inicializar módulo de seguimiento de pedidos en pantalla de compra
        Batllie_Caja_Tracking::init();

        // Inicializar módulo de productos agrupados / packs con cantidad fija y precio dinámico
        Batllie_Caja_Grouped::init();

        // Inicializar módulo de monto mínimo de compra en la tienda
        Batllie_Caja_Min_Order::init();

        // Inicializar motor de empaque de alfajores, stock de cajas y cortesía
        Batllie_Caja_Packing::init();

        // Hacer obligatorio el número de teléfono en WooCommerce checkout
        Batllie_Caja_Orders::make_phone_required_hooks();

        // Hooks principales
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array('Batllie_Caja_Orders', 'register_custom_order_statuses'));
        add_filter('wc_order_statuses', array('Batllie_Caja_Orders', 'add_custom_order_statuses'));
        add_action('template_redirect', array($this, 'hide_admin_bar_on_caja'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp_head', array($this, 'render_mobile_cart_redirect_script'), 1);
        add_shortcode('batllie_caja', array($this, 'render_caja_shortcode'));

        // Garantizar traducciones al español argentino para textos clave de WooCommerce y bloques de carrito/checkout
        add_filter('gettext', array($this, 'filter_woocommerce_translations'), 20, 3);
        add_filter('ngettext', array($this, 'filter_woocommerce_translations_plural'), 20, 5);

        // Sincronizar número de WhatsApp en theme mods si el tema lo usa
        add_filter('theme_mod_emp_components_nav_wsp_numb', array($this, 'filter_theme_whatsapp_number'));

        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        }
    }

    /**
     * Sincronizar número de WhatsApp configurado con el tema
     */
    public function filter_theme_whatsapp_number($val) {
        $options = self::get_color_settings();
        return !empty($options['whatsapp_number']) ? $options['whatsapp_number'] : $val;
    }

    /**
     * Incluir archivos necesarios
     */
    private function includes() {
        require_once EMP_CAJA_PATH . 'includes/class-caja-auth.php';
        require_once EMP_CAJA_PATH . 'includes/class-caja-orders.php';
        require_once EMP_CAJA_PATH . 'includes/class-caja-products.php';
        require_once EMP_CAJA_PATH . 'includes/class-caja-ajax.php';
        require_once EMP_CAJA_PATH . 'includes/class-caja-tracking.php';
        require_once EMP_CAJA_PATH . 'includes/class-caja-grouped.php';
        require_once EMP_CAJA_PATH . 'includes/class-caja-min-order.php';
        require_once EMP_CAJA_PATH . 'includes/class-caja-packing.php';
    }

    /**
     * Inicializar dependencias
     */
    public function init() {
        // Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
        }

        // Asegurar que la zona horaria de WordPress esté configurada en Argentina
        if (get_option('timezone_string') !== 'America/Argentina/Buenos_Aires') {
            update_option('timezone_string', 'America/Argentina/Buenos_Aires');
        }
    }

    /**
     * Ocultar la barra superior de administración de WordPress en la página de la caja
     */
    public function hide_admin_bar_on_caja() {
        if (!is_admin()) {
            global $post;
            if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'batllie_caja') || strpos($post->post_content, 'batllie_caja') !== false)) {
                add_filter('show_admin_bar', '__return_false', 9999);
            }
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>' . 
             esc_html__('Batllie Caja & Pedidos requiere que WooCommerce esté instalado y activo.', 'emp-caja') . 
             '</p></div>';
    }

    /**
     * Obtener paleta de colores configurada
     */
    public static function get_color_settings() {
        $defaults = array(
            'bg_color'                  => '#0f172a', // Fondo general oscuro
            'header_bg'                 => '#1e293b', // Cabecera / barra superior
            'card_bg'                   => '#1e293b', // Fondo de tarjetas de pedidos
            'card_border'               => '#334155', // Bordes de tarjetas
            'text_color'                => '#f8fafc', // Color texto principal
            'text_muted'                => '#94a3b8', // Color texto secundario
            'primary_color'             => '#10b981', // Color acento / esmeralda
            'primary_hover'             => '#059669', // Hover botón primario
            'btn_text'                  => '#ffffff', // Texto de botones
            'status_pending'            => '#f59e0b', // 1. Pendiente (ámbar)
            'status_processing'         => '#3b82f6', // 2. En preparación (azul)
            'status_enviando'           => '#8b5cf6', // 3. Enviando (violeta)
            'status_completed'          => '#10b981', // 4. Completado (verde)
            'status_recibido_problema'  => '#ea580c', // 5. Recibido con inconvenientes (naranja)
            'status_cancelled'          => '#ef4444', // 6. Cancelado (rojo)
            'status_refunded'           => '#64748b', // 7. Reembolzado (gris pizarra)
            'whatsapp_number'           => '5491149472377', // WhatsApp de contacto y comprobantes
            'poll_interval'             => 10,        // Segundos de sondeo
            'sound_enabled'             => 'yes',     // Sonido activo por defecto
            'force_isolated'            => 'yes',     // Ocultar cabeceras y pie del tema en la vista
            'min_purchase_amount'       => 0,         // Monto mínimo de compra en la tienda (0 = desactivado)
            'packing_priority'          => '12',      // Prioridad de empaque ('12' = Cajas de 12 primero, '6' = Cajas de 6)
            'packing_stock_sync'        => 'yes',     // Descontar automáticamente el stock de cajas de empaque
        );

        $saved = get_option('batllie_caja_options', array());
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Registrar estilos y scripts frontend
     */
    public function register_assets() {
        if (function_exists('wp_enqueue_media')) {
            global $post;
            if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'batllie_caja') || strpos($post->post_content, 'batllie_caja') !== false)) {
                wp_enqueue_media();
            }
        }

        wp_register_style(
            'batllie-caja-css',
            EMP_CAJA_URL . 'assets/css/caja-style.css',
            array(),
            EMP_CAJA_VERSION
        );

        wp_register_script(
            'batllie-caja-audio',
            EMP_CAJA_URL . 'assets/js/caja-audio.js',
            array(),
            EMP_CAJA_VERSION,
            true
        );

        wp_register_script(
            'batllie-caja-app',
            EMP_CAJA_URL . 'assets/js/caja-app.js',
            array('jquery', 'batllie-caja-audio'),
            EMP_CAJA_VERSION,
            true
        );
    }

    /**
     * Filtrar traducciones de WooCommerce para carrito, checkout y badges
     */
    public function filter_woocommerce_translations($translation, $text, $domain) {
        if ($domain === 'woocommerce' || empty($domain)) {
            switch ($text) {
                case 'Proceed to Checkout':
                    return 'Finalizar compra';
                case 'Add coupons':
                case 'Añadir cupones':
                    return 'Agregar cupón';
                case 'Estimated total':
                    return 'Total estimado';
                case 'Free':
                case 'FREE':
                    return 'Gratis';
                case 'Save %s':
                    return 'Ahorrás %s';
                case 'Save':
                    return 'Ahorro';
                case 'Place Order':
                    return 'Realizar pedido';
                case 'Payment options':
                    return 'Medios de pago';
            }
        }
        return $translation;
    }

    /**
     * Filtrar traducciones plurales de WooCommerce
     */
    public function filter_woocommerce_translations_plural($translation, $single, $plural, $number, $domain) {
        if ($domain === 'woocommerce' || empty($domain)) {
            if ($single === 'Save %s') {
                return 'Ahorrás %s';
            }
        }
        return $translation;
    }

    /**
     * Renderizar Shortcode [batllie_caja]
     */
    public function render_caja_shortcode($atts) {
        $atts = shortcode_atts(array(
            'isolated' => 'yes',
        ), $atts, 'batllie_caja');

        // Procesar credenciales enviadas directamente por POST o GET
        if (!is_user_logged_in()) {
            $req_raw = isset($_POST['username']) ? trim(wp_unslash($_POST['username'])) : (isset($_GET['username']) ? trim(wp_unslash($_GET['username'])) : '');
            $req_user = sanitize_user($req_raw);
            $req_pass = isset($_POST['password']) ? trim($_POST['password']) : (isset($_GET['password']) ? trim($_GET['password']) : '');
            if (!empty($req_raw) && !empty($req_pass)) {
                $credentials = array(
                    'user_login'    => !empty($req_user) ? $req_user : $req_raw,
                    'user_password' => $req_pass,
                    'remember'      => true
                );
                $user = wp_signon($credentials, is_ssl());
                if (is_wp_error($user) && strtolower($credentials['user_login']) !== $credentials['user_login']) {
                    $credentials['user_login'] = strtolower($credentials['user_login']);
                    $user_retry = wp_signon($credentials, is_ssl());
                    if (!is_wp_error($user_retry)) {
                        $user = $user_retry;
                    }
                }
                if (is_wp_error($user) && is_email($req_raw)) {
                    $credentials['user_login'] = strtolower($req_raw);
                    $user_retry = wp_signon($credentials, is_ssl());
                    if (!is_wp_error($user_retry)) {
                        $user = $user_retry;
                    }
                }
                if (!is_wp_error($user) && (user_can($user, 'manage_woocommerce') || user_can($user, 'edit_posts') || user_can($user, 'read'))) {
                    wp_set_current_user($user->ID);
                }
            }
        }

        // Encolar media uploader de WordPress para fotos de productos
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        // Encolar assets de la caja
        wp_enqueue_style('batllie-caja-css');
        wp_enqueue_script('batllie-caja-audio');
        wp_enqueue_script('batllie-caja-app');

        // Encolar color-picker solo si el usuario tiene acceso a configuración
        if (is_user_logged_in() && class_exists('Batllie_Caja_Auth') && Batllie_Caja_Auth::current_user_can_access()) {
            if (!wp_script_is('wp-color-picker', 'registered')) {
                wp_register_script('iris', admin_url('js/iris.min.js'), array('jquery-ui-draggable', 'jquery-ui-slider'), '1.1.1', true);
                wp_register_script('wp-color-picker', admin_url('js/color-picker.min.js'), array('iris'), false, true);
            }
            if (!wp_style_is('wp-color-picker', 'registered')) {
                wp_register_style('wp-color-picker', admin_url('css/color-picker.min.css'), array(), false);
            }
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
        }

        $options = self::get_color_settings();
        $priority = $options['packing_priority'] ?? '12';
        $officialBoxId = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_id( intval($priority) ) : 0;
        $priorityBoxImg = '';
        if ( $officialBoxId ) {
            $product = wc_get_product( $officialBoxId );
            if ( $product ) {
                $img_id = $product->get_image_id();
                $priorityBoxImg = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
            }
        }

        // Inyectar variables CSS personalizadas
        $custom_css = "
        :root {
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

        // Ocultar barra superior de WordPress (Admin Bar)
        add_filter('show_admin_bar', '__return_false', 9999);

        if ($options['force_isolated'] === 'yes' || $atts['isolated'] === 'yes') {
            $custom_css .= "
            #wpadminbar,
            #wp-admin-bar-root-default,
            body.batllie-caja-active #wpadminbar,
            body.admin-bar #wpadminbar,
            div#wpadminbar {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                min-height: 0 !important;
                max-height: 0 !important;
                overflow: hidden !important;
            }
            html,
            html.wp-toolbar,
            html[lang] {
                margin-top: 0 !important;
                padding-top: 0 !important;
            }
            body.batllie-caja-active header:not(.caja-topbar):not(.batllie-caja-nav),
            body.batllie-caja-active nav:not(.batllie-caja-nav),
            body.batllie-caja-active footer,
            body.batllie-caja-active .site-header,
            body.batllie-caja-active .site-footer,
            body.batllie-caja-active #masthead,
            body.batllie-caja-active #colophon,
            body.batllie-caja-active aside,
            body.batllie-caja-active .sidebar,
            body.batllie-caja-active #top-notice,
            body.batllie-caja-active #navbar-background,
            body.batllie-caja-active .FullScreenLanding,
            body.batllie-caja-active #main-head,
            body.batllie-caja-active #first-content-page {
                display: none !important;
            }
            body.batllie-caja-active article,
            body.batllie-caja-active article.color-content,
            body.batllie-caja-active .container-fluid,
            body.batllie-caja-active .container {
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }
            body,
            body.admin-bar,
            body.batllie-caja-active {
                background: {$options['bg_color']} !important;
                margin: 0 !important;
                margin-top: 0 !important;
                padding: 0 !important;
                padding-top: 0 !important;
                overflow-x: hidden !important;
                width: 100% !important;
            }";
        }

        wp_add_inline_style('batllie-caja-css', $custom_css);

        // Variables pasadas a JS
        wp_localize_script('batllie-caja-app', 'batllieCajaConfig', array(
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('batllie_caja_nonce'),
            'pollInterval'   => intval($options['poll_interval']) * 1000,
            'soundEnabled'   => ($options['sound_enabled'] === 'yes'),
            'isUserLoggedIn' => is_user_logged_in(),
            'currentUser'    => wp_get_current_user()->display_name,
            'currencySymbol'   => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
            'placeholderImg'   => function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src('medium') : '',
            'priorityBoxImage' => $priorityBoxImg,
            'officialBox6Id'   => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_id(6) : 0,
            'officialBox6Name' => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_name(6) : '',
            'officialBox12Id'  => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_id(12) : 0,
            'officialBox12Name'=> class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_name(12) : '',
            'officialBagLargeId'   => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_bag_id('large') : 0,
            'officialBagLargeName' => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_bag_name('large') : '',
            'officialBagSmallId'   => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_bag_id('small') : 0,
            'officialBagSmallName' => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_bag_name('small') : '',
            'boxCandidates'    => class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_all_box_candidates() : array(),
            'packingThemes'    => class_exists('Batllie_Caja_Orders') ? Batllie_Caja_Orders::get_packing_themes() : array(),
            'i18n'             => array(
                'newOrderAlert'    => __('¡Nuevo Pedido Entrante!', 'emp-caja'),
                'soundOn'          => __('Sonido: ACTIVO', 'emp-caja'),
                'soundOff'         => __('Sonido: SILENCIADO', 'emp-caja'),
                'confirmDelete'    => __('¿Estás seguro?', 'emp-caja'),
                'statusUpdated'    => __('Estado actualizado con éxito', 'emp-caja'),
                'productCreated'   => __('¡Producto creado correctamente!', 'emp-caja'),
                'loading'          => __('Cargando...', 'emp-caja'),
                'error'            => __('Ocurrió un error. Intenta nuevamente.', 'emp-caja'),
            )
        ));

        // Renderizado del template principal
        ob_start();
        include EMP_CAJA_PATH . 'templates/caja-view.php';
        return ob_get_clean();
    }

    /**
     * Menú en Panel de Administración de WordPress
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Batllie Caja', 'emp-caja'),
            __('Batllie Caja', 'emp-caja'),
            'manage_options',
            'batllie-caja-settings',
            array($this, 'render_admin_settings'),
            'dashicons-cart',
            56
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_batllie-caja-settings') {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('wp-color-picker', "
            jQuery(document).ready(function($){
                $('.caja-color-field').wpColorPicker();
            });
        ");
    }

    public function register_settings() {
        register_setting('batllie_caja_settings_group', 'batllie_caja_options', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }

    public function sanitize_settings($input) {
        $output = array();
        $color_keys = array(
            'bg_color', 'header_bg', 'card_bg', 'card_border', 
            'text_color', 'text_muted', 'primary_color', 'primary_hover', 
            'btn_text', 'status_pending', 'status_processing', 
            'status_enviando', 'status_completed', 'status_recibido_problema',
            'status_cancelled', 'status_refunded'
        );

        foreach ($color_keys as $key) {
            if (isset($input[$key])) {
                $output[$key] = sanitize_hex_color($input[$key]);
            }
        }

        $output['whatsapp_number']      = isset($input['whatsapp_number']) ? preg_replace('/[^0-9]/', '', trim($input['whatsapp_number'])) : '5491149472377';
        if (empty($output['whatsapp_number'])) {
            $output['whatsapp_number'] = '5491149472377';
        }
        $output['poll_interval']        = isset($input['poll_interval']) ? max(5, intval($input['poll_interval'])) : 10;
        $output['sound_enabled']        = (isset($input['sound_enabled']) && $input['sound_enabled'] === 'yes') ? 'yes' : 'no';
        $output['force_isolated']       = (isset($input['force_isolated']) && $input['force_isolated'] === 'yes') ? 'yes' : 'no';
        $output['min_purchase_amount']  = isset($input['min_purchase_amount']) ? max(0, floatval(str_replace(',', '.', trim($input['min_purchase_amount'])))) : 0;
        $output['packing_priority']     = (isset($input['packing_priority']) && $input['packing_priority'] === '6') ? '6' : '12';
        $output['packing_stock_sync']   = (isset($input['packing_stock_sync']) && $input['packing_stock_sync'] === 'no') ? 'no' : 'yes';

        if (isset($input['box_6_product_id']) && class_exists('Batllie_Caja_Packing') && intval($input['box_6_product_id']) > 0) {
            $output['box_6_product_id'] = intval($input['box_6_product_id']);
            Batllie_Caja_Packing::set_official_box_id(6, intval($input['box_6_product_id']));
        }
        if (isset($input['box_12_product_id']) && class_exists('Batllie_Caja_Packing') && intval($input['box_12_product_id']) > 0) {
            $output['box_12_product_id'] = intval($input['box_12_product_id']);
            Batllie_Caja_Packing::set_official_box_id(12, intval($input['box_12_product_id']));
        }
        if (isset($input['stock_box_6']) && class_exists('Batllie_Caja_Packing')) {
            $output['stock_box_6'] = intval($input['stock_box_6']);
            Batllie_Caja_Packing::set_box_stock(6, intval($input['stock_box_6']));
        }
        if (isset($input['stock_box_12']) && class_exists('Batllie_Caja_Packing')) {
            $output['stock_box_12'] = intval($input['stock_box_12']);
            Batllie_Caja_Packing::set_box_stock(12, intval($input['stock_box_12']));
        }

        return $output;
    }

    /**
     * Vista de Configuración de Colores y Opciones en el Admin de WordPress
     */
    public function render_admin_settings() {
        ?>
        <div class="wrap caja-admin-settings-wrap" style="max-width: 1000px;">
            <?php include EMP_CAJA_PATH . 'templates/tab-settings.php'; ?>
        </div>
        <?php
    }

    /**
     * Redirigir el botón de carrito en móvil directamente a la página de carrito (/carrito/)
     * en lugar de abrir el menú lateral desplegable.
     */
    public function render_mobile_cart_redirect_script() {
        if (is_admin()) {
            return;
        }

        $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/carrito/');
        ?>
        <script>
        (function() {
            var cartUrl = <?php echo json_encode($cart_url); ?>;

            // Sobrescribir función global showWoocommerceCart si el tema la invoca por onclick
            window.showWoocommerceCart = function(e) {
                if (e && e.preventDefault) e.preventDefault();
                window.location.href = cartUrl;
                return false;
            };

            function fixMobileCartButtons() {
                var buttons = document.querySelectorAll('#btn-woocommerce-cart, a[onclick*="showWoocommerceCart"], .btn-woocommerce-cart');
                buttons.forEach(function(btn) {
                    btn.setAttribute('href', cartUrl);
                    btn.removeAttribute('onclick');
                });
            }

            // Interceptar clics y toques en fase de captura para máxima prioridad
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('#btn-woocommerce-cart, a[onclick*="showWoocommerceCart"], .btn-woocommerce-cart');
                if (btn) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    window.location.href = cartUrl;
                }
            }, true);

            document.addEventListener('touchstart', function(e) {
                var btn = e.target.closest('#btn-woocommerce-cart, a[onclick*="showWoocommerceCart"], .btn-woocommerce-cart');
                if (btn) {
                    btn.setAttribute('href', cartUrl);
                    btn.removeAttribute('onclick');
                }
            }, { passive: true });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixMobileCartButtons);
            } else {
                fixMobileCartButtons();
            }
            window.addEventListener('load', fixMobileCartButtons);
        })();
        </script>
        <?php
    }
}

// Iniciar Plugin
Batllie_Caja_Plugin::get_instance();
