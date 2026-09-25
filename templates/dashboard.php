<?php
/**
 * Template del Panel Principal (Dashboard) de Caja
 */

if (!defined('ABSPATH')) {
    exit;
}
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
            <span class="caja-title"><?php _e('Batllie Caja', 'emp-caja'); ?></span>
            <button type="button" class="caja-live-pill" id="caja-live-status" title="<?php esc_attr_e('Toca para ir a Productos', 'emp-caja'); ?>">
                <span class="caja-pulse-dot"></span>
                <span class="caja-live-text"><?php _e('En Vivo', 'emp-caja'); ?></span>
            </button>
        </div>

        <!-- Pestañas Principales -->
        <nav class="caja-nav-tabs">
            <button class="caja-tab-btn active" data-tab="tab-orders">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                <span><?php _e('Pedidos en Vivo', 'emp-caja'); ?></span>
                <span class="caja-badge-count" id="caja-orders-count">0</span>
            </button>
            <button class="caja-tab-btn" data-tab="tab-products" id="caja-tab-nav-products">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span><?php _e('Carga y Modificación de Productos', 'emp-caja'); ?></span>
            </button>
        </nav>

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
                <span class="caja-btn-tool-label"><?php _e('Alarma Activa', 'emp-caja'); ?></span>
            </button>

            <!-- Botón Probar Sonido -->
            <button type="button" class="caja-btn-tool caja-btn-sound-test" id="caja-test-sound" title="<?php esc_attr_e('Probar campana sonora', 'emp-caja'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <span><?php _e('Test', 'emp-caja'); ?></span>
            </button>

            <!-- Pantalla Completa -->
            <button type="button" class="caja-btn-tool" id="caja-fullscreen-btn" title="<?php esc_attr_e('Pantalla Completa', 'emp-caja'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path>
                </svg>
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
        <!-- Pestaña 1: Pedidos -->
        <section id="tab-orders" class="caja-tab-panel active">
            <?php include EMP_CAJA_PATH . 'templates/tab-orders.php'; ?>
        </section>

        <!-- Pestaña 2: Productos -->
        <section id="tab-products" class="caja-tab-panel" style="display:none;">
            <?php include EMP_CAJA_PATH . 'templates/tab-products.php'; ?>
        </section>
    </main>

    <!-- Notificaciones Toast -->
    <div id="caja-toast" class="caja-toast" style="display:none;"></div>
</div>
