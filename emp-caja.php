<?php
/**
 * Plugin Name: Batllie Caja & Pedidos POS
 * Plugin URI: https://empralidad.com.ar/batllie
 * Description: Sistema de Caja y Control de Pedidos en tiempo real para WooCommerce con sonido de alerta, vista aislada para mostrador/cocina, gestión de estados, alta de productos y colores 100% personalizables. Shortcode: [batllie_caja].
 * Version: 1.3.6
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
define('EMP_CAJA_VERSION', '1.3.6');
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

        // Hacer obligatorio el número de teléfono en WooCommerce checkout
        Batllie_Caja_Orders::make_phone_required_hooks();

        // Hooks principales
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array('Batllie_Caja_Orders', 'register_custom_order_statuses'));
        add_filter('wc_order_statuses', array('Batllie_Caja_Orders', 'add_custom_order_statuses'));
        add_action('template_redirect', array($this, 'hide_admin_bar_on_caja'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_shortcode('batllie_caja', array($this, 'render_caja_shortcode'));

        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        }
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
    }

    /**
     * Inicializar dependencias
     */
    public function init() {
        // Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
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
            'status_completed'          => '#10b981', // 4. Recibido (verde)
            'status_recibido_problema'  => '#ea580c', // 5. Recibido con inconvenientes (naranja)
            'status_cancelled'          => '#ef4444', // 6. Cancelado (rojo)
            'status_refunded'           => '#64748b', // 7. Reembolzado (gris pizarra)
            'poll_interval'             => 10,        // Segundos de sondeo
            'sound_enabled'             => 'yes',     // Sonido activo por defecto
            'force_isolated'            => 'yes'      // Ocultar cabeceras y pie del tema en la vista
        );

        $saved = get_option('batllie_caja_options', array());
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Registrar estilos y scripts frontend
     */
    public function register_assets() {
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
     * Renderizar Shortcode [batllie_caja]
     */
    public function render_caja_shortcode($atts) {
        $atts = shortcode_atts(array(
            'isolated' => 'yes',
        ), $atts, 'batllie_caja');

        // Encolar assets
        wp_enqueue_style('batllie-caja-css');
        wp_enqueue_script('batllie-caja-audio');
        wp_enqueue_script('batllie-caja-app');

        $options = self::get_color_settings();

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
            'currencySymbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
            'i18n'           => array(
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

        $output['poll_interval']  = isset($input['poll_interval']) ? max(5, intval($input['poll_interval'])) : 10;
        $output['sound_enabled']  = (isset($input['sound_enabled']) && $input['sound_enabled'] === 'yes') ? 'yes' : 'no';
        $output['force_isolated'] = (isset($input['force_isolated']) && $input['force_isolated'] === 'yes') ? 'yes' : 'no';

        return $output;
    }

    /**
     * Vista de Configuración de Colores en el Admin
     */
    public function render_admin_settings() {
        $options = self::get_color_settings();
        ?>
        <div class="wrap" style="max-width: 900px;">
            <h1><span class="dashicons dashicons-store" style="font-size:30px; margin-right:10px;"></span> <?php _e('Batllie Caja - Configuración de Colores y Opciones', 'emp-caja'); ?></h1>
            <p><?php _e('Personaliza todos los colores del panel de caja, botones, tarjetas, estados y comportamiento del sonido.', 'emp-caja'); ?></p>

            <div class="notice notice-info" style="padding: 12px; margin-bottom: 20px;">
                <strong><?php _e('Uso en tu sitio:', 'emp-caja'); ?></strong>
                <?php _e('Crea una página nueva en WordPress y pega el shortcode:', 'emp-caja'); ?>
                <code>[batllie_caja]</code>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('batllie_caja_settings_group'); ?>

                <h2><?php _e('🎨 Colores Generales del Panel', 'emp-caja'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Color de Fondo Principal', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[bg_color]" value="<?php echo esc_attr($options['bg_color']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color Barra Superior / Header', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[header_bg]" value="<?php echo esc_attr($options['header_bg']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color de Tarjetas / Paneles', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[card_bg]" value="<?php echo esc_attr($options['card_bg']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Borde de Tarjetas', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[card_border]" value="<?php echo esc_attr($options['card_border']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color de Texto Principal', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[text_color]" value="<?php echo esc_attr($options['text_color']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color de Texto Secundario', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[text_muted]" value="<?php echo esc_attr($options['text_muted']); ?>" class="caja-color-field" /></td>
                    </tr>
                </table>

                <h2><?php _e('🔘 Botones y Acentos', 'emp-caja'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Botón Primario / Acento', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[primary_color]" value="<?php echo esc_attr($options['primary_color']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Botón Primario (Hover)', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[primary_hover]" value="<?php echo esc_attr($options['primary_hover']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color Texto de Botones', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[btn_text]" value="<?php echo esc_attr($options['btn_text']); ?>" class="caja-color-field" /></td>
                    </tr>
                </table>

                <h2><?php _e('🏷️ Colores de Estados de Pedido', 'emp-caja'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('1. Pendiente', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_pending]" value="<?php echo esc_attr($options['status_pending']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('2. En preparación', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_processing]" value="<?php echo esc_attr($options['status_processing']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('3. Enviando', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_enviando]" value="<?php echo esc_attr($options['status_enviando']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('4. Recibido', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_completed]" value="<?php echo esc_attr($options['status_completed']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('5. Recibido (con inconvenientes)', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_recibido_problema]" value="<?php echo esc_attr($options['status_recibido_problema']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('6. Cancelado', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_cancelled]" value="<?php echo esc_attr($options['status_cancelled']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('7. Reembolzado', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_refunded]" value="<?php echo esc_attr($options['status_refunded']); ?>" class="caja-color-field" /></td>
                    </tr>
                </table>

                <h2><?php _e('⚙️ Opciones de Notificación y Visualización', 'emp-caja'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Frecuencia de Actualización', 'emp-caja'); ?></th>
                        <td>
                            <input type="number" min="5" max="60" name="batllie_caja_options[poll_interval]" value="<?php echo esc_attr($options['poll_interval']); ?>" class="small-text" /> 
                            <span><?php _e('segundos (comprueba nuevos pedidos en segundo plano).', 'emp-caja'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Sonido Activo por Defecto', 'emp-caja'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="batllie_caja_options[sound_enabled]" value="yes" <?php checked($options['sound_enabled'], 'yes'); ?> />
                                <?php _e('Reproducir campana sonora al ingresar un nuevo pedido', 'emp-caja'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Modo Vista Aislada', 'emp-caja'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="batllie_caja_options[force_isolated]" value="yes" <?php checked($options['force_isolated'], 'yes'); ?> />
                                <?php _e('Ocultar automáticamente menús, cabecera y pie de página del tema en la página de caja', 'emp-caja'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Guardar Cambios de Configuración', 'emp-caja')); ?>
            </form>
        </div>
        <?php
    }
}

// Iniciar Plugin
Batllie_Caja_Plugin::get_instance();
