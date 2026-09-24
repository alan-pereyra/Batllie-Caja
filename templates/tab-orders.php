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
        <!-- Desplegables de Filtro (50% y 50%) -->
        <div class="caja-top-filters-grid">
            <div class="caja-filter-select-col">
                <select id="caja-filter-status" class="caja-select-filter">
                    <option value="all"><?php _e('Todos los estados', 'emp-caja'); ?></option>
                    <option value="pending"><?php _e('Pendiente', 'emp-caja'); ?></option>
                    <option value="processing"><?php _e('En preparación', 'emp-caja'); ?></option>
                    <option value="enviando"><?php _e('Enviando', 'emp-caja'); ?></option>
                    <option value="completed"><?php _e('Recibido', 'emp-caja'); ?></option>
                    <option value="recibido-problema"><?php _e('Recibido (con inconvenientes)', 'emp-caja'); ?></option>
                    <option value="cancelled"><?php _e('Cancelado', 'emp-caja'); ?></option>
                    <option value="refunded"><?php _e('Reembolzado', 'emp-caja'); ?></option>
                </select>
            </div>

            <div class="caja-filter-select-col">
                <select id="caja-filter-time" class="caja-select-filter">
                    <option value="nuevos" selected><?php _e('Nuevos', 'emp-caja'); ?></option>
                    <option value="30_min"><?php _e('30 minutos', 'emp-caja'); ?></option>
                    <option value="1_hour"><?php _e('1 hora', 'emp-caja'); ?></option>
                    <option value="2_hours"><?php _e('2 horas', 'emp-caja'); ?></option>
                    <option value="1_day"><?php _e('1 día', 'emp-caja'); ?></option>
                    <option value="2_days"><?php _e('2 días', 'emp-caja'); ?></option>
                    <option value="all"><?php _e('Todos', 'emp-caja'); ?></option>
                </select>
            </div>
        </div>

        <div class="caja-search-row">
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
