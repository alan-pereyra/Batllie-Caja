<?php
/**
 * Pestaña de Pedidos en Vivo
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="caja-orders-container">
    <!-- Barra de Filtros y Búsqueda -->
    <div class="caja-filter-bar">
        <div class="caja-status-filters" id="caja-status-filters">
            <button type="button" class="caja-filter-btn active" data-status="all">
                <?php _e('Todos', 'emp-caja'); ?>
            </button>
            <button type="button" class="caja-filter-btn filter-pending" data-status="pending">
                <span class="caja-status-dot dot-pending"></span>
                <?php _e('Pendientes', 'emp-caja'); ?>
            </button>
            <button type="button" class="caja-filter-btn filter-processing" data-status="processing">
                <span class="caja-status-dot dot-processing"></span>
                <?php _e('En Preparación', 'emp-caja'); ?>
            </button>
            <button type="button" class="caja-filter-btn filter-completed" data-status="completed">
                <span class="caja-status-dot dot-completed"></span>
                <?php _e('Completados', 'emp-caja'); ?>
            </button>
            <button type="button" class="caja-filter-btn filter-cancelled" data-status="cancelled">
                <span class="caja-status-dot dot-cancelled"></span>
                <?php _e('Cancelados', 'emp-caja'); ?>
            </button>
            <button type="button" class="caja-filter-btn filter-shipping-waiting" data-status="shipping-esperando_repartidor">
                <span class="caja-status-dot dot-shipping-waiting"></span>
                <?php _e('Pendiente de envío', 'emp-caja'); ?>
            </button>
            <button type="button" class="caja-filter-btn filter-shipping-sending" data-status="shipping-enviando">
                <span class="caja-status-dot dot-shipping-sending"></span>
                <?php _e('Enviando', 'emp-caja'); ?>
            </button>
        </div>

        <div class="caja-filter-right">
            <!-- Buscador de pedidos -->
            <div class="caja-search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="caja-search-orders" placeholder="<?php esc_attr_e('Buscar por N° pedido o cliente...', 'emp-caja'); ?>" />
            </div>

            <!-- Botón de recarga manual -->
            <button type="button" class="caja-btn-tool" id="caja-manual-refresh" title="<?php esc_attr_e('Actualizar pedidos ahora', 'emp-caja'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <polyline points="1 20 1 14 7 14"></polyline>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
            </button>
        </div>
    </div>

    <!-- Indicador de carga -->
    <div id="caja-orders-loading" class="caja-loading-state" style="display:none;">
        <div class="caja-spinner"></div>
        <p><?php _e('Cargando pedidos de WooCommerce...', 'emp-caja'); ?></p>
    </div>

    <!-- Lista de Tarjetas de Pedidos -->
    <div id="caja-orders-grid" class="caja-cards-grid">
        <!-- Renderizado dinámico vía JavaScript -->
    </div>

    <!-- Estado vacío -->
    <div id="caja-orders-empty" class="caja-empty-state" style="display:none;">
        <div class="caja-empty-icon">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="9" cy="21" r="1"></circle>
                <circle cx="20" cy="21" r="1"></circle>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
            </svg>
        </div>
        <h3><?php _e('No hay pedidos en esta sección', 'emp-caja'); ?></h3>
        <p><?php _e('Los nuevos pedidos recibidos en WooCommerce aparecerán aquí de forma automática.', 'emp-caja'); ?></p>
    </div>
</div>
