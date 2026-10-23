<?php
/**
 * Template del Panel Principal (Dashboard) de Caja
 */

if (!defined('ABSPATH')) {
    exit;
}

$user_id = get_current_user_id();
$user_perms = class_exists('Batllie_Caja_Employees') ? Batllie_Caja_Employees::get_user_permissions($user_id) : array(
    'is_admin' => current_user_can('manage_options') || current_user_can('manage_woocommerce'),
    'can_view_products' => true,
    'can_view_orders' => true,
    'can_change_sector' => true
);
$is_admin = !empty($user_perms['is_admin']);
$can_view_products = !empty($user_perms['can_view_products']);
$can_view_orders = !empty($user_perms['can_view_orders']);
$default_active_tab = $can_view_orders ? 'tab-orders' : 'tab-pos-sale';
?>

<div class="caja-dashboard">
    <!-- Barra Superior / Header de la Caja -->
    <header class="caja-topbar batllie-caja-nav">
        <div class="caja-brand">
            <div class="caja-logo-badge">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <span class="caja-title"><?php _e('Caja POS', 'emp-caja'); ?></span>
            <!-- Desplegable Rápido de Pantallas: En Vivo / Productos / Configuración General -->
            <div class="caja-screen-switcher-wrap" id="caja-screen-switcher-wrap" title="<?php esc_attr_e('Seleccionar pantalla', 'emp-caja'); ?>">
                <span class="caja-pulse-dot" id="caja-screen-dot"></span>
                <span class="caja-screen-select-label" id="caja-screen-select-label">
                    <?php 
                    if ($default_active_tab === 'tab-orders') {
                        echo '🔴 ' . __('Pedidos en Vivo', 'emp-caja');
                    } else {
                        echo '🛒 ' . __('Venta Caja', 'emp-caja');
                    }
                    ?>
                </span>
                <span class="caja-badge-count" id="caja-orders-count" style="font-size:0.75rem; padding: 2px 7px; border-radius: 999px; background: #10b981; color:#fff; font-weight:700; margin-left: 6px; display:inline-flex; align-items:center; pointer-events:none;">0</span>
                <span class="caja-screen-select-arrow">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </span>
                <select id="caja-screen-select" class="caja-screen-select" onchange="if(window.BatllieCajaApp&&window.BatllieCajaApp.switchTab){window.BatllieCajaApp.switchTab(this.value);}" aria-label="<?php esc_attr_e('Cambiar de pantalla', 'emp-caja'); ?>">
                    <?php if ($can_view_orders): ?>
                    <option value="tab-orders" <?php echo ($default_active_tab === 'tab-orders') ? 'selected' : ''; ?>>🔴 <?php _e('Pedidos en Vivo', 'emp-caja'); ?></option>
                    <?php endif; ?>
                    <?php if ($can_view_products): ?>
                    <option value="tab-products">📦 <?php _e('Productos', 'emp-caja'); ?></option>
                    <?php endif; ?>
                    <option value="tab-pos-sale" <?php echo ($default_active_tab === 'tab-pos-sale') ? 'selected' : ''; ?>>🛒 <?php _e('Venta Caja', 'emp-caja'); ?></option>
                    <?php if ($is_admin): ?>
                    <option value="tab-registers">🗄️ <?php _e('Control de Cajas', 'emp-caja'); ?></option>
                    <option value="tab-employees">👥 <?php _e('Configuración Empleados', 'emp-caja'); ?></option>
                    <option value="tab-settings">⚙️ <?php _e('Configuración General', 'emp-caja'); ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <!-- Controles de la Barra Superior -->
        <div class="caja-actions">
            <!-- Botón de Audio / Alerta Sonora -->
            <button type="button" class="caja-btn-tool" id="caja-toggle-sound" title="<?php esc_attr_e('Activar/Desactivar sonido de alarma', 'emp-caja'); ?>">
                <span class="sound-icon-on">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                        <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                    </svg>
                </span>
                <span class="sound-icon-off" style="display:none;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                        <line x1="23" y1="9" x2="17" y2="15"></line>
                        <line x1="17" y1="9" x2="23" y2="15"></line>
                    </svg>
                </span>
                <span class="caja-btn-tool-label"><?php _e('Activa', 'emp-caja'); ?></span>
            </button>

            <!-- Botón Probar Sonido -->
            <button type="button" class="caja-btn-tool caja-btn-sound-test" id="caja-test-sound" title="<?php esc_attr_e('Probar campana sonora', 'emp-caja'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <span><?php _e('Test', 'emp-caja'); ?></span>
            </button>


            <!-- Botón Instalar como App (PWA) -->
            <button type="button" class="caja-btn-tool" id="caja-btn-install-pwa" style="display:none;" title="<?php esc_attr_e('Instalar Caja POS como Aplicación', 'emp-caja'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                <span class="caja-btn-tool-label"><?php _e('Instalar', 'emp-caja'); ?></span>
            </button>

            <!-- Usuario y Logout -->
            <div class="caja-user-menu">
                <span class="caja-user-name" id="caja-user-display"><?php echo esc_html(wp_get_current_user()->display_name); ?></span>
                <button type="button" class="caja-btn-logout" id="caja-logout-btn" title="<?php esc_attr_e('Cerrar Sesión', 'emp-caja'); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <!-- Banner de Alerta de Nuevo Pedido -->
    <div id="caja-new-order-banner" class="caja-banner-alert" style="display:none;">
        <div class="caja-banner-content">
            <span class="caja-banner-icon">🔔</span>
            <div class="caja-banner-text">
                <strong id="caja-banner-title"><?php _e('¡Nuevo Pedido Entrante!', 'emp-caja'); ?></strong>
                <span id="caja-banner-subtitle"><?php _e('Se ha recibido una nueva orden en WooCommerce.', 'emp-caja'); ?></span>
            </div>
            <div class="caja-sound-wave">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
            <button type="button" class="caja-btn caja-btn-sm caja-banner-dismiss" id="caja-dismiss-banner">
                <?php _e('Entendido', 'emp-caja'); ?>
            </button>
        </div>
    </div>

    <!-- Contenido de Pestañas -->
    <main class="caja-main-content">
        <?php if ($can_view_orders): ?>
        <!-- Pestaña 1: Pedidos -->
        <section id="tab-orders" class="caja-tab-panel <?php echo ($default_active_tab === 'tab-orders') ? 'active' : ''; ?>" style="<?php echo ($default_active_tab === 'tab-orders') ? '' : 'display:none;'; ?>">
            <?php include EMP_CAJA_PATH . 'templates/tab-orders.php'; ?>
        </section>
        <?php endif; ?>

        <?php if ($can_view_products): ?>
        <!-- Pestaña 2: Productos -->
        <section id="tab-products" class="caja-tab-panel" style="display:none;">
            <?php include EMP_CAJA_PATH . 'templates/tab-products.php'; ?>
        </section>
        <?php endif; ?>

        <!-- Pestaña 3: Venta Caja -->
        <section id="tab-pos-sale" class="caja-tab-panel <?php echo ($default_active_tab === 'tab-pos-sale') ? 'active' : ''; ?>" style="<?php echo ($default_active_tab === 'tab-pos-sale') ? '' : 'display:none;'; ?>">
            <?php include EMP_CAJA_PATH . 'templates/tab-pos-sale.php'; ?>
        </section>

        <?php if ($is_admin): ?>
        <!-- Pestaña 4: Configuración General -->
        <section id="tab-settings" class="caja-tab-panel" style="display:none;">
            <?php include EMP_CAJA_PATH . 'templates/tab-settings.php'; ?>
        </section>

        <!-- Pestaña 5: Configuración Empleados (Solo Administradores) -->
        <section id="tab-employees" class="caja-tab-panel" style="display:none;">
            <?php include EMP_CAJA_PATH . 'templates/tab-employees.php'; ?>
        </section>

        <!-- Pestaña 6: Control de Cajas (Solo Administradores) -->
        <section id="tab-registers" class="caja-tab-panel" style="display:none;">
            <?php include EMP_CAJA_PATH . 'templates/tab-registers.php'; ?>
        </section>
        <?php endif; ?>
    </main>


    <!-- Modal Pantalla Completa: Control de Pedido -->
    <div id="caja-modal-control-pedido" class="caja-control-pedido-fullscreen" style="display:none;">
        <div class="caja-control-pedido-container">
            <div class="caja-control-pedido-header">
                <div class="caja-control-header-left">
                    <span class="caja-control-header-icon">📋</span>
                    <h2 class="caja-control-header-title" id="caja-control-title"><?php _e('Control de Pedido', 'emp-caja'); ?></h2>
                </div>
                <button type="button" class="caja-control-close-btn" id="caja-control-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>
            <div class="caja-control-pedido-intro">
                <span id="caja-control-intro-text"><?php _e('Chequeá todos los productos incluidos en cada caja, los sueltos y las bolsas de envío antes de entregar al repartidor:', 'emp-caja'); ?></span>
            </div>
            <div class="caja-control-pedido-body" id="caja-control-body">
                <!-- Se genera dinámicamente -->
            </div>
            <div class="caja-control-pedido-footer">
                <div class="caja-control-counter">
                    <span id="caja-control-checked-count">0</span> de <span id="caja-control-total-count">0</span> <?php _e('verificados', 'emp-caja'); ?>
                </div>
                <button type="button" class="caja-btn-iniciar-envio" id="caja-btn-iniciar-envio" disabled>
                    <span>🚀 <?php _e('Iniciar envío', 'emp-caja'); ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Historial Completo del Cliente -->
    <div id="caja-modal-customer-history" class="caja-modal" style="display:none; z-index: 100050;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog" style="max-width: 820px; width: 95%;">
            <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5rem;">📜</span>
                    <div>
                        <h3 class="caja-modal-title" id="caja-cust-hist-title" style="margin: 0; font-size: 1.25rem;"><?php _e('Historial de Cliente', 'emp-caja'); ?></h3>
                        <p id="caja-cust-hist-sub" style="margin: 2px 0 0 0; font-size: 0.85rem; color: var(--caja-text-muted, #94a3b8);"></p>
                    </div>
                </div>
                <button type="button" class="caja-modal-close" id="caja-cust-hist-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>
            <div class="caja-modal-body" id="caja-cust-hist-body" style="padding: 16px 0 20px 0; max-height: 75vh; overflow-y: auto;">
                <div class="caja-loading-spinner-wrap" style="text-align: center; padding: 40px 16px;">
                    <span class="caja-spinner">⏳</span>
                    <p style="margin-top: 10px; color: var(--caja-text-muted, #94a3b8);"><?php _e('Cargando historial de compras...', 'emp-caja'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Notificaciones Toast -->
    <div id="caja-toast" class="caja-toast" style="display:none;"></div>
</div>
