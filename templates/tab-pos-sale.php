<?php
/**
 * Template de la Pantalla "Venta Caja" (Punto de Venta POS)
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="caja-pos-container" id="caja-pos-main-container">
    <div class="caja-pos-stack">
        <!-- 1. SECCIÓN UNIFICADA: PRODUCTOS -->
        <div class="caja-pos-card caja-pos-products-card" id="caja-pos-products-card">
            <div class="caja-pos-card-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:1.15rem;">📦</span>
                    <strong style="color:var(--caja-text); font-size:1.02rem;"><?php _e('Productos', 'emp-caja'); ?></strong>
                    <span class="caja-badge" id="caja-pos-cart-badge-count" style="background:rgba(255,255,255,0.08); font-size:0.75rem;">0 seleccionados</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button type="button" class="caja-pos-btn-ghost-sm" id="caja-pos-clear-cart" title="<?php esc_attr_e('Vaciar selección', 'emp-caja'); ?>">
                        <span>🗑️ <?php _e('Vaciar', 'emp-caja'); ?></span>
                    </button>
                </div>
            </div>

            <!-- Buscador Rápido de Productos -->
            <div class="caja-pos-search-header">
                <div class="caja-pos-search-box">
                    <span class="caja-pos-search-icon">🔍</span>
                    <input type="text" id="caja-pos-search-input" class="caja-pos-search-input" placeholder="<?php esc_attr_e('Buscar producto por nombre o SKU...', 'emp-caja'); ?>" autocomplete="off" />
                    <button type="button" class="caja-pos-search-clear" id="caja-pos-search-clear" style="display:none;">&times;</button>
                </div>
            </div>

            <!-- Lista Acordeón de Categorías y Productos -->
            <div class="caja-pos-accordion-container" id="caja-pos-categories-accordion">
                <div class="caja-pos-loading-catalog" style="text-align:center; padding:36px 16px; color:var(--caja-text-muted);">
                    <span class="caja-spinner">⏳</span>
                    <p style="margin-top:8px;"><?php _e('Cargando productos...', 'emp-caja'); ?></p>
                </div>
            </div>
        </div>

        <!-- 2. SECCIÓN DE COBRO -->
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

                <!-- Estado de Cobro (Desplegable 100% de ancho) -->
                <div class="caja-pos-status-dropdown-wrap">
                    <select id="caja-pos-selected-status" class="caja-input caja-pos-status-select">
                        <option value="pagado" selected>🟢 <?php _e('Ya está pagado', 'emp-caja'); ?></option>
                        <option value="pendiente">🟠 <?php _e('Falta pagar (Pendiente de cobro)', 'emp-caja'); ?></option>
                    </select>
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

        <!-- 3. HISTORIAL DE VENTAS -->
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
                <div style="text-align:center; padding:30px; color:var(--caja-text-muted);">
                    <span class="caja-spinner">⏳</span>
                    <p style="margin-top:8px;"><?php _e('Cargando historial de ventas...', 'emp-caja'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
