<?php
/**
 * Template de la Pantalla "Venta Caja" (Punto de Venta POS)
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
$user_display = ($current_user && $current_user->exists()) ? $current_user->display_name : __('Administrador', 'emp-caja');
?>

<div class="caja-pos-container" id="caja-pos-main-container">
    <!-- Layout Principal en 2 Columnas (Catálogo y Ticket de Cobro) -->
    <div class="caja-pos-grid">
        <!-- Columna Izquierda: Selector y Catálogo Rápido -->
        <div class="caja-pos-catalog-panel">
            <!-- Barra de Búsqueda y Selector Rápido -->
            <div class="caja-pos-search-bar-wrap">
                <div class="caja-pos-search-input-box">
                    <span class="caja-pos-search-icon">🔍</span>
                    <input type="text" id="caja-pos-search-input" class="caja-pos-search-input" placeholder="<?php esc_attr_e('Buscar producto por nombre o SKU...', 'emp-caja'); ?>" autocomplete="off" />
                    <button type="button" class="caja-pos-search-clear" id="caja-pos-search-clear" style="display:none;" title="<?php esc_attr_e('Limpiar búsqueda', 'emp-caja'); ?>">&times;</button>
                </div>

                <!-- Selector dropdown rápido para sumar producto directo con cantidad -->
                <div class="caja-pos-quick-add-row">
                    <select id="caja-pos-product-select" class="caja-pos-product-select">
                        <option value=""><?php _e('-- Seleccionar producto del catálogo --', 'emp-caja'); ?></option>
                    </select>
                    
                    <div class="caja-pos-qty-stepper">
                        <button type="button" class="caja-pos-qty-btn" id="caja-pos-quick-qty-minus">-</button>
                        <input type="number" id="caja-pos-quick-qty-input" value="1" min="1" step="1" />
                        <button type="button" class="caja-pos-qty-btn" id="caja-pos-quick-qty-plus">+</button>
                    </div>

                    <button type="button" class="caja-btn caja-btn-primary caja-pos-add-btn" id="caja-pos-quick-add-btn">
                        <span>➕</span>
                        <span><?php _e('Agregar', 'emp-caja'); ?></span>
                    </button>
                </div>
            </div>

            <!-- Grilla Rápida de Productos (Tarjetas Táctiles) -->
            <div class="caja-pos-catalog-section">
                <div class="caja-pos-catalog-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:1.1rem;">📦</span>
                        <strong><?php _e('Catálogo de Productos', 'emp-caja'); ?></strong>
                        <span class="caja-badge" id="caja-pos-catalog-count" style="background:rgba(255,255,255,0.08); font-size:0.75rem;">0 productos</span>
                    </div>
                    <div class="caja-pos-catalog-filter">
                        <button type="button" class="caja-pos-filter-pill active" data-filter="all"><?php _e('Todos', 'emp-caja'); ?></button>
                        <button type="button" class="caja-pos-filter-pill" data-filter="instock"><?php _e('Con Stock', 'emp-caja'); ?></button>
                    </div>
                </div>

                <div class="caja-pos-products-grid" id="caja-pos-products-grid">
                    <!-- Rellenado dinámicamente con JavaScript -->
                    <div class="caja-pos-loading-catalog">
                        <span class="caja-spinner">⏳</span>
                        <p><?php _e('Cargando productos...', 'emp-caja'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Columna Derecha: Ticket de Venta / Resumen y Cobro -->
        <div class="caja-pos-ticket-panel">
            <div class="caja-pos-ticket-card">
                <!-- Encabezado del Ticket -->
                <div class="caja-pos-ticket-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:1.2rem;">🧾</span>
                        <div>
                            <h3 style="margin:0; font-size:1.05rem; color:#ffffff;"><?php _e('Ticket de Venta', 'emp-caja'); ?></h3>
                            <span style="font-size:0.78rem; color:var(--caja-text-muted, #94a3b8);"><?php _e('Productos cargados en la orden', 'emp-caja'); ?></span>
                        </div>
                    </div>
                    <button type="button" class="caja-btn-tool" id="caja-pos-clear-cart" title="<?php esc_attr_e('Vaciar ticket', 'emp-caja'); ?>" style="font-size:0.8rem; padding:4px 8px;">
                        <span>🗑️ <?php _e('Vaciar', 'emp-caja'); ?></span>
                    </button>
                </div>

                <!-- Lista de Ítems en el Carrito POS -->
                <div class="caja-pos-cart-items" id="caja-pos-cart-items">
                    <div class="caja-pos-empty-cart" id="caja-pos-empty-cart">
                        <span style="font-size:2rem; opacity:0.4;">🛒</span>
                        <p style="margin:8px 0 0; color:var(--caja-text-muted, #94a3b8); font-size:0.88rem;"><?php _e('El ticket está vacío.<br>Hacé clic en un producto o buscalo para agregarlo.', 'emp-caja'); ?></p>
                    </div>
                </div>

                <!-- Opciones de Entrega y Cobro -->
                <div class="caja-pos-checkout-options">
                    <!-- 1. Modo de Orden: Compra Realizada vs Pedido por Despachar -->
                    <div class="caja-pos-option-group">
                        <label class="caja-pos-group-label">
                            <span>🚚</span>
                            <strong><?php _e('Modo de Entrega:', 'emp-caja'); ?></strong>
                        </label>
                        <div class="caja-pos-mode-toggle-group">
                            <button type="button" class="caja-pos-mode-btn active" data-mode="compra_realizada" id="btn-pos-mode-completed">
                                <span class="caja-pos-mode-icon">🟢</span>
                                <div>
                                    <strong><?php _e('Compra Realizada', 'emp-caja'); ?></strong>
                                    <small><?php _e('Entregada en el momento (In Situ)', 'emp-caja'); ?></small>
                                </div>
                            </button>
                            <button type="button" class="caja-pos-mode-btn" data-mode="pedido_despachar" id="btn-pos-mode-dispatch">
                                <span class="caja-pos-mode-icon">📦</span>
                                <div>
                                    <strong><?php _e('Pedido por Despachar', 'emp-caja'); ?></strong>
                                    <small><?php _e('A cola de pedidos en vivo / despacho', 'emp-caja'); ?></small>
                                </div>
                            </button>
                        </div>
                        <input type="hidden" id="caja-pos-selected-mode" value="compra_realizada" />
                    </div>

                    <!-- 2. Método de Pago -->
                    <div class="caja-pos-option-group">
                        <label class="caja-pos-group-label">
                            <span>💳</span>
                            <strong><?php _e('Método de Pago:', 'emp-caja'); ?></strong>
                        </label>
                        <div class="caja-pos-payment-chips">
                            <button type="button" class="caja-pos-pay-chip active" data-pay="efectivo" id="btn-pay-efectivo">
                                <span>💵</span>
                                <span><?php _e('Efectivo', 'emp-caja'); ?></span>
                            </button>
                            <button type="button" class="caja-pos-pay-chip" data-pay="tarjeta" id="btn-pay-tarjeta">
                                <span>💳</span>
                                <span><?php _e('Tarjeta / Débito', 'emp-caja'); ?></span>
                            </button>
                            <button type="button" class="caja-pos-pay-chip" data-pay="transferencia" id="btn-pay-transferencia">
                                <span>📱</span>
                                <span><?php _e('Transferencia', 'emp-caja'); ?></span>
                            </button>
                        </div>
                        <input type="hidden" id="caja-pos-selected-pay" value="efectivo" />
                    </div>

                    <!-- 2.B. Campo Desplegable para N° de Operación de Tarjeta -->
                    <div class="caja-pos-option-group" id="caja-pos-card-op-wrap" style="display:none;">
                        <label class="caja-pos-group-label" for="caja-pos-card-op">
                            <span>🔢</span>
                            <strong><?php _e('N° de Operación / Cupón de Tarjeta:', 'emp-caja'); ?></strong>
                        </label>
                        <input type="text" id="caja-pos-card-op" class="caja-input caja-pos-input" placeholder="<?php esc_attr_e('Ej: 0049210 o autorización del posnet...', 'emp-caja'); ?>" />
                        <small class="caja-form-hint" style="font-size:0.75rem; color:#94a3b8;"><?php _e('Quedará registrado en la orden y en el comprobante.', 'emp-caja'); ?></small>
                    </div>

                    <!-- 3. Estado de Cobro (Independiente del método) -->
                    <div class="caja-pos-option-group">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label class="caja-pos-group-label" style="margin-bottom:0;">
                                <span>💰</span>
                                <strong><?php _e('Estado del Cobro:', 'emp-caja'); ?></strong>
                            </label>
                            <span id="caja-pos-pay-status-hint" class="caja-badge" style="background:rgba(16,185,129,0.15); color:#10b981; font-size:0.72rem;"><?php _e('Cobrado', 'emp-caja'); ?></span>
                        </div>
                        <div class="caja-pos-status-toggle-group">
                            <button type="button" class="caja-pos-status-btn active" data-status="pagado" id="btn-status-pagado">
                                <span>🟢</span>
                                <span><?php _e('Ya está pagado', 'emp-caja'); ?></span>
                            </button>
                            <button type="button" class="caja-pos-status-btn" data-status="pendiente" id="btn-status-pendiente">
                                <span>🟠</span>
                                <span><?php _e('Falta pagar (Pendiente)', 'emp-caja'); ?></span>
                            </button>
                        </div>
                        <input type="hidden" id="caja-pos-selected-status" value="pagado" />
                    </div>

                    <!-- 4. Nota o Aclaraciones de la Venta -->
                    <div class="caja-pos-option-group">
                        <label class="caja-pos-group-label" for="caja-pos-note">
                            <span>📝</span>
                            <strong><?php _e('Nota o Motivo de la Venta (Opcional):', 'emp-caja'); ?></strong>
                        </label>
                        <input type="text" id="caja-pos-note" class="caja-input caja-pos-input" placeholder="<?php esc_attr_e('Ej: Cliente retira por local, abonará al cadete, etc...', 'emp-caja'); ?>" />
                    </div>
                </div>

                <!-- Resumen Numérico y Botón de Cobro -->
                <div class="caja-pos-ticket-summary">
                    <div class="caja-pos-summary-row">
                        <span><?php _e('Artículos totales:', 'emp-caja'); ?></span>
                        <strong id="caja-pos-total-items-count">0</strong>
                    </div>
                    <div class="caja-pos-summary-row">
                        <span><?php _e('Subtotal:', 'emp-caja'); ?></span>
                        <strong id="caja-pos-subtotal-val">$0,00</strong>
                    </div>
                    <div class="caja-pos-summary-divider"></div>
                    <div class="caja-pos-summary-row caja-pos-total-row">
                        <span style="font-size:1.1rem; font-weight:700; color:#ffffff;"><?php _e('Total a Cobrar:', 'emp-caja'); ?></span>
                        <strong id="caja-pos-total-val" style="font-size:1.5rem; color:#10b981; font-weight:800;">$0,00</strong>
                    </div>

                    <button type="button" class="caja-btn caja-pos-checkout-btn" id="caja-pos-checkout-btn" disabled>
                        <span class="caja-pos-checkout-icon">✅</span>
                        <span class="caja-pos-checkout-text" id="caja-pos-checkout-text"><?php _e('Cobrar y Entregar Ahora', 'emp-caja'); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Sección Inferior: Historial de Ventas de Caja -->
    <div class="caja-pos-history-section">
        <div class="caja-pos-history-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:1.3rem;">📜</span>
                <div>
                    <h3 class="caja-pos-history-title"><?php _e('Historial de Ventas de Caja', 'emp-caja'); ?></h3>
                    <p style="margin:0; font-size:0.8rem; color:var(--caja-text-muted, #94a3b8);"><?php _e('Registro de las últimas ventas y pedidos generados desde esta terminal.', 'emp-caja'); ?></p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="caja-badge" id="caja-pos-history-count" style="background:rgba(255,255,255,0.08); font-size:0.8rem;">0 ventas</span>
                <button type="button" class="caja-btn-tool" id="caja-pos-history-refresh-btn" title="<?php esc_attr_e('Actualizar historial', 'emp-caja'); ?>">
                    <span>🔄 <?php _e('Actualizar', 'emp-caja'); ?></span>
                </button>
            </div>
        </div>

        <div class="caja-pos-history-body" id="caja-pos-history-body">
            <!-- Rellenado dinámicamente con JavaScript -->
            <div style="text-align:center; padding:30px; color:#64748b;">
                <span class="caja-spinner">⏳</span>
                <p style="margin-top:8px;"><?php _e('Cargando historial de ventas...', 'emp-caja'); ?></p>
            </div>
        </div>
    </div>
</div>
