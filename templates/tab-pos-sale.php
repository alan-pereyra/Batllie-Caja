<?php
/**
 * Template de la Pantalla "Venta Caja" (Punto de Venta POS)
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="caja-pos-container" id="caja-pos-main-container">
    <div class="caja-pos-layout">
        <!-- Columna 1 (En desktop izquierda / en mobile colapsable): Catálogo de Productos y Búsqueda -->
        <div class="caja-pos-catalog-panel" id="caja-pos-catalog-panel">
            <div class="caja-pos-card caja-pos-catalog-card">
                <div class="caja-pos-catalog-card-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:1.1rem;">📦</span>
                        <strong style="color:var(--caja-text); font-size:0.98rem;"><?php _e('Catálogo de Productos', 'emp-caja'); ?></strong>
                        <span class="caja-badge" id="caja-pos-catalog-count" style="background:rgba(255,255,255,0.08); font-size:0.75rem;">0 productos</span>
                    </div>
                </div>
                <div class="caja-pos-search-header">
                    <div class="caja-pos-search-box">
                        <span class="caja-pos-search-icon">🔍</span>
                        <input type="text" id="caja-pos-search-input" class="caja-pos-search-input" placeholder="<?php esc_attr_e('Buscar producto por nombre o SKU...', 'emp-caja'); ?>" autocomplete="off" />
                        <button type="button" class="caja-pos-search-clear" id="caja-pos-search-clear" style="display:none;">&times;</button>
                    </div>
                    <div class="caja-pos-filter-pills">
                        <button type="button" class="caja-pos-filter-pill active" data-filter="all"><?php _e('Todos', 'emp-caja'); ?></button>
                        <button type="button" class="caja-pos-filter-pill" data-filter="instock"><?php _e('Con Stock', 'emp-caja'); ?></button>
                    </div>
                </div>

                <!-- Grilla de productos para añadir con 1 clic -->
                <div class="caja-pos-products-grid" id="caja-pos-products-grid">
                    <div class="caja-pos-loading-catalog">
                        <span class="caja-spinner">⏳</span>
                        <p><?php _e('Cargando productos...', 'emp-caja'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Columna 2 (Ticket y Opciones de Cobro) -->
        <div class="caja-pos-checkout-panel">
            <!-- Tarjeta 1: Lista de Productos en el Carrito (Ticket) -->
            <div class="caja-pos-card caja-pos-cart-card">
                <div class="caja-pos-cart-card-header">
                    <div class="caja-pos-cart-card-title-wrap">
                        <span class="caja-pos-cart-icon">🛒</span>
                        <strong class="caja-pos-cart-card-title"><?php _e('Ticket de Venta', 'emp-caja'); ?></strong>
                        <span class="caja-badge" id="caja-pos-cart-badge-count" style="display:none; background:rgba(255,255,255,0.1); font-size:0.75rem;">0</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button type="button" class="caja-pos-btn-ghost-sm" id="caja-pos-clear-cart" title="<?php esc_attr_e('Vaciar ticket', 'emp-caja'); ?>">
                            <span>🗑️ <?php _e('Vaciar', 'emp-caja'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- Buscador Rápido de Producto para el Ticket -->
                <div class="caja-pos-cart-quicksearch-wrap">
                    <div class="caja-pos-search-box">
                        <span class="caja-pos-search-icon">🔍</span>
                        <input type="text" id="caja-pos-cart-quicksearch" class="caja-pos-search-input" placeholder="<?php esc_attr_e('Buscar producto para sumar al ticket...', 'emp-caja'); ?>" autocomplete="off" />
                        <button type="button" class="caja-pos-search-clear" id="caja-pos-cart-quicksearch-clear" style="display:none;">&times;</button>
                    </div>
                    <div class="caja-pos-quicksearch-dropdown" id="caja-pos-quicksearch-dropdown" style="display:none;"></div>
                </div>

                <!-- Lista de Ítems (Foto + Título + Qty x Price + Stepper [- qty +] + Borrar) -->
                <div class="caja-pos-cart-items-list" id="caja-pos-cart-items">
                    <div class="caja-pos-empty-cart-state" id="caja-pos-empty-cart">
                        <span style="font-size:2rem; opacity:0.35;">🛒</span>
                        <p style="margin:8px 0 0; color:var(--caja-text-muted, #94a3b8); font-size:0.88rem;">
                            <?php _e('El ticket está vacío.<br>Tocá en <strong>"🔍 Catálogo"</strong> o seleccioná productos para agregarlos.', 'emp-caja'); ?>
                        </p>
                    </div>
                </div>

                <!-- Flecha expandible -->
                <div class="caja-pos-cart-expand-trigger" id="caja-pos-cart-expand-trigger">
                    <span class="caja-pos-chevron">⌵</span>
                </div>
            </div>

            <!-- Tarjeta 2: Opciones de Entrega, Pago, Notas y Cobro -->
            <div class="caja-pos-card caja-pos-checkout-card">
                <!-- Selector de Modo de Entrega tipo Switch iOS -->
                <div class="caja-pos-switches-group">
                    <div class="caja-pos-switch-row is-active" data-mode="compra_realizada" id="pos-row-compra-realizada">
                        <div class="caja-pos-switch-pill">
                            <span class="caja-pos-switch-knob">✓</span>
                        </div>
                        <div class="caja-pos-switch-label">
                            <strong class="caja-pos-switch-title"><?php _e('Compra Realizada', 'emp-caja'); ?></strong>
                            <span class="caja-pos-switch-sub"><?php _e('(Entregada)', 'emp-caja'); ?></span>
                        </div>
                    </div>

                    <div class="caja-pos-switch-row" data-mode="pedido_despachar" id="pos-row-pedido-despachar">
                        <div class="caja-pos-switch-pill">
                            <span class="caja-pos-switch-knob">📦</span>
                        </div>
                        <div class="caja-pos-switch-label">
                            <strong class="caja-pos-switch-title"><?php _e('Pedido por Despachar', 'emp-caja'); ?></strong>
                            <span class="caja-pos-switch-sub"><?php _e('(Mostrador/Envío)', 'emp-caja'); ?></span>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="caja-pos-selected-mode" value="compra_realizada" />

                <!-- Métodos de Pago y Estado de Cobro -->
                <div class="caja-pos-payment-subgroup">
                    <div class="caja-pos-pay-chips-row">
                        <button type="button" class="caja-pos-pay-chip active" data-pay="efectivo" id="btn-pay-efectivo">
                            <span>💵</span> <span><?php _e('Efectivo', 'emp-caja'); ?></span>
                        </button>
                        <button type="button" class="caja-pos-pay-chip" data-pay="tarjeta" id="btn-pay-tarjeta">
                            <span>💳</span> <span><?php _e('Tarjeta', 'emp-caja'); ?></span>
                        </button>
                        <button type="button" class="caja-pos-pay-chip" data-pay="transferencia" id="btn-pay-transferencia">
                            <span>📱</span> <span><?php _e('Transferencia', 'emp-caja'); ?></span>
                        </button>
                    </div>
                    <input type="hidden" id="caja-pos-selected-pay" value="efectivo" />

                    <!-- Campo Desplegable de Tarjeta -->
                    <div id="caja-pos-card-op-wrap" class="caja-pos-card-op-wrap" style="display:none;">
                        <label for="caja-pos-card-op" class="caja-pos-field-label"><?php _e('N° de Operación / Cupón:', 'emp-caja'); ?></label>
                        <input type="text" id="caja-pos-card-op" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 0049210 o autorización...', 'emp-caja'); ?>" />
                    </div>

                    <!-- Estado de Cobro (Ya está pagado vs Falta pagar) -->
                    <div class="caja-pos-status-row">
                        <div class="caja-pos-status-pill-toggle">
                            <button type="button" class="caja-pos-status-btn active" data-status="pagado" id="btn-status-pagado">
                                <span>🟢</span> <span><?php _e('Ya está pagado', 'emp-caja'); ?></span>
                            </button>
                            <button type="button" class="caja-pos-status-btn" data-status="pendiente" id="btn-status-pendiente">
                                <span>🟠</span> <span><?php _e('Falta pagar', 'emp-caja'); ?></span>
                            </button>
                        </div>
                        <input type="hidden" id="caja-pos-selected-status" value="pagado" />
                    </div>
                </div>

                <!-- Campo de Nota de la Venta -->
                <div class="caja-pos-note-group">
                    <label for="caja-pos-note" class="caja-pos-field-label"><?php _e('Nota de la venta', 'emp-caja'); ?></label>
                    <textarea id="caja-pos-note" class="caja-input caja-pos-note-textarea" rows="2" placeholder="<?php esc_attr_e('Nota o aclaraciones de la venta...', 'emp-caja'); ?>"></textarea>
                </div>

                <!-- Resumen de Totales -->
                <div class="caja-pos-totals-block">
                    <div class="caja-pos-total-line">
                        <span><?php _e('Subtotal:', 'emp-caja'); ?></span>
                        <strong id="caja-pos-subtotal-val">$0,00</strong>
                    </div>
                    <div class="caja-pos-total-line caja-pos-total-line-grand">
                        <span><?php _e('Total:', 'emp-caja'); ?></span>
                        <strong id="caja-pos-total-val">$0,00</strong>
                    </div>
                </div>

                <!-- Botón de Acción Principal -->
                <button type="button" class="caja-btn caja-pos-main-confirm-btn" id="caja-pos-checkout-btn" disabled>
                    <span id="caja-pos-checkout-text"><?php _e('Confirmar y Cobrar', 'emp-caja'); ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Historial de Ventas (Abajo - Colapsado por defecto) -->
    <div class="caja-pos-card caja-pos-history-card">
        <div class="caja-pos-history-card-header" id="caja-pos-history-header-toggle" style="cursor: pointer; user-select: none;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:1.2rem;">📜</span>
                <strong style="color:var(--caja-text); font-size:1rem;"><?php _e('Historial de Ventas de Caja', 'emp-caja'); ?></strong>
                <span class="caja-badge" id="caja-pos-history-count" style="display:none; background:rgba(255,255,255,0.08); font-size:0.75rem;">0 ventas</span>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <button type="button" class="caja-btn-tool" id="caja-pos-history-refresh-btn" style="display:none;" title="<?php esc_attr_e('Actualizar historial', 'emp-caja'); ?>">
                    <span>🔄 <?php _e('Actualizar', 'emp-caja'); ?></span>
                </button>
                <span class="caja-pos-history-toggle-text" style="font-size:0.8rem; color:var(--caja-text-muted);"><?php _e('Ver historial', 'emp-caja'); ?></span>
                <span class="caja-pos-history-chevron" id="caja-pos-history-chevron" style="color:var(--caja-text-muted); font-size:0.85rem; transition:transform 0.2s ease;">▼</span>
            </div>
        </div>
        <div class="caja-pos-history-body" id="caja-pos-history-body" style="display:none;">
            <!-- Se carga únicamente al tocar el encabezado -->
            <div style="text-align:center; padding:30px; color:var(--caja-text-muted);">
                <span class="caja-spinner">⏳</span>
                <p style="margin-top:8px;"><?php _e('Cargando historial de ventas...', 'emp-caja'); ?></p>
            </div>
        </div>
    </div>
</div>
