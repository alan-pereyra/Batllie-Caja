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
                <div class="caja-pos-header-left" style="display:flex; align-items:center; gap:6px; min-width:0;">
                    <span style="font-size:1.15rem; flex-shrink:0;">📦</span>
                    <strong class="caja-pos-header-title" style="color:var(--caja-text); font-size:0.95rem; white-space:nowrap;"><?php _e('Productos', 'emp-caja'); ?></strong>
                    <span id="caja-pos-cart-badge-count" class="caja-pos-cart-badge-count" style="font-size:0.72rem; padding:2px 6px;">0</span>
                </div>
                <div class="caja-pos-header-actions" style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <button type="button" class="caja-pos-btn-ghost-sm" id="caja-pos-open-history-btn" title="<?php esc_attr_e('Ver Historial de Ventas', 'emp-caja'); ?>">
                        <span>📜</span>
                        <span class="caja-btn-txt-responsive"><?php _e('Historial', 'emp-caja'); ?></span>
                        <span id="caja-pos-history-badge-top" class="caja-pos-cart-badge-count" style="display:none; font-size:0.68rem; padding:1px 5px; margin-left:2px;">0</span>
                    </button>
                    <button type="button" class="caja-pos-btn-ghost-sm" id="caja-pos-clear-cart" title="<?php esc_attr_e('Vaciar selección', 'emp-caja'); ?>">
                        <span>🗑️</span>
                        <span class="caja-btn-txt-responsive"><?php _e('Vaciar', 'emp-caja'); ?></span>
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
        <div class="caja-pos-card caja-pos-checkout-card" id="caja-pos-checkout-card">
            <!-- Cuerpo scrolleable del cobro -->
            <div class="caja-pos-checkout-scrollable">
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

                <!-- Submenú de Despacho (Solo visible si "Pedido por Despachar" está activo) -->
                <div class="caja-pos-dispatch-subgroup" id="caja-pos-dispatch-subgroup" style="display:none;">
                    <div class="caja-pos-dispatch-chips-row">
                        <button type="button" class="caja-pos-dispatch-chip active" data-delivery="con_envio" id="btn-delivery-con-envio">
                            <span>🚚</span> <span><?php _e('Con Envío', 'emp-caja'); ?></span>
                        </button>
                        <button type="button" class="caja-pos-dispatch-chip" data-delivery="retiro_local" id="btn-delivery-retiro-local">
                            <span>🏪</span> <span><?php _e('Retira posteriormente por el local', 'emp-caja'); ?></span>
                        </button>
                    </div>
                    <input type="hidden" id="caja-pos-selected-delivery" value="con_envio" />
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

                <!-- Estado de Cobro (Desplegable 100% de ancho - Solo visible en Pedido por Despachar) -->
                <div class="caja-pos-status-dropdown-wrap" id="caja-pos-status-dropdown-wrap" style="display:none;">
                    <select id="caja-pos-selected-status" class="caja-input caja-pos-status-select">
                        <option value="pagado" selected>🟢 <?php _e('Ya está pagado', 'emp-caja'); ?></option>
                        <option value="pendiente">🟠 <?php _e('Falta pagar (Pendiente de cobro)', 'emp-caja'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Datos del Cliente (Nombre, DNI, Teléfono, Email, Dirección) -->
            <div class="caja-pos-customer-subgroup" id="caja-pos-customer-subgroup" style="margin-bottom: 14px; background: rgba(255,255,255,0.02); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 12px; transition: border-color 0.2s ease;">
                <!-- Cabecera colapsable: Título y Badge alineados a la izquierda en la misma línea con base de texto coincidente -->
                <div class="caja-pos-customer-header" id="caja-pos-customer-header" style="display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; width: 100%;">
                    <div class="caja-pos-cust-header-left" style="display: flex; align-items: baseline; gap: 8px; flex: 0 1 auto;">
                        <span class="caja-pos-cust-icon" style="font-size: 0.95rem; line-height: 1; align-self: center;">👤</span>
                        <span class="caja-pos-cust-title" style="font-size: 0.92rem; font-weight: 700; color: var(--caja-text, #ffffff); line-height: 1.2; margin: 0; padding: 0; white-space: nowrap;"><?php _e('Datos del Cliente', 'emp-caja'); ?></span>
                        <span id="caja-pos-cust-req-badge" class="caja-pos-cust-req-badge" style="font-size: 0.72rem; font-weight: 600; line-height: 1.2; padding: 2px 8px; border-radius: 4px; background: rgba(255,255,255,0.08); color: var(--caja-text-muted, #94a3b8); white-space: nowrap;"><?php _e('Opcional', 'emp-caja'); ?></span>
                    </div>
                    <span id="caja-pos-cust-toggle-icon" class="caja-pos-cust-toggle-icon" style="margin-left: auto; color: var(--caja-text-muted, #94a3b8); font-size: 0.82rem; transition: transform 0.2s ease;">▼</span>
                </div>

                <!-- Contenido colapsable (se despliega si es obligatorio o si se hace clic) -->
                <div class="caja-pos-customer-body" id="caja-pos-customer-body" style="margin-top: 12px; display: none;">
                    <!-- Buscador con colores invertidos (Fondo claro, texto oscuro) y mismo diseño redondeado que inputs inferiores -->
                    <!-- Buscador predictivo de clientes (solo búsqueda) -->
                    <div class="caja-pos-cust-search-wrap" style="position: relative; margin-bottom: 14px;">
                        <div style="position: relative; display: flex; align-items: center; width: 100%;">
                            <input type="text" id="caja-pos-cust-search-input" class="caja-pos-text-input caja-pos-cust-search-inverted" placeholder="<?php esc_attr_e('Buscar cliente registrado', 'emp-caja'); ?>" autocomplete="off" style="width: 100%; border-radius: 8px !important; padding: 8px 36px 8px 10px !important; font-size: 0.85rem !important; background-color: var(--caja-text, #f8fafc) !important; color: var(--caja-bg, #0f172a) !important; font-weight: 600 !important; border: 1px solid #cbd5e1 !important; box-sizing: border-box !important;" />
                            <button type="button" id="caja-pos-cust-search-clear" style="display: none; position: absolute; right: 10px; background: transparent; border: none; color: #0f172a; font-size: 1.1rem; font-weight: 700; cursor: pointer; padding: 2px 6px; border-radius: 50%; line-height: 1;" title="<?php esc_attr_e('Limpiar búsqueda', 'emp-caja'); ?>">✕</button>
                        </div>
                        <!-- Dropdown de resultados de clientes -->
                        <div id="caja-pos-cust-search-results" class="caja-pos-cust-results-dropdown" style="display: none;"></div>
                    </div>

                    <!-- Divisor visual claro entre buscador y formulario -->
                    <div style="display: flex; align-items: center; gap: 8px; margin: 0 0 16px 0;">
                        <div style="flex: 1; height: 1px; background: rgba(255,255,255,0.08);"></div>
                        <span style="font-size: 0.68rem; color: var(--caja-text-muted, #94a3b8); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;"><?php _e('o completa los datos', 'emp-caja'); ?></span>
                        <div style="flex: 1; height: 1px; background: rgba(255,255,255,0.08);"></div>
                    </div>
                    
                    <div class="caja-pos-cust-fields-grid" style="display: flex; flex-direction: column; gap: 18px;">
                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 4px;">
                            <label for="caja-pos-cust-name" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0; padding: 0; line-height: 1.1;">
                                <?php _e('Nombre y Apellido', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="text" id="caja-pos-cust-name" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: Juan Pérez', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 4px;">
                            <label for="caja-pos-cust-dni" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0; padding: 0; line-height: 1.1;">
                                <?php _e('DNI / CUIT', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="text" id="caja-pos-cust-dni" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 35123456', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 4px;">
                            <label for="caja-pos-cust-phone" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0; padding: 0; line-height: 1.1;">
                                <?php _e('Teléfono / WhatsApp', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="tel" id="caja-pos-cust-phone" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 11 2345 6789', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 4px;">
                            <label for="caja-pos-cust-email" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0; padding: 0; line-height: 1.1;">
                                <?php _e('Email', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="email" id="caja-pos-cust-email" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: cliente@gmail.com', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 4px;">
                            <label for="caja-pos-cust-address" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0; padding: 0; line-height: 1.1;">
                                <?php _e('Dirección de Envío / Localidad', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="text" id="caja-pos-cust-address" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Calle, número, depto, ciudad...', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>
                    </div>

                    <!-- Botones para Editar Datos del Cliente (Guardar / Descartar) -->
                    <div id="caja-pos-cust-edit-wrap" style="display: none; margin-top: 14px; width: 100%;">
                        <button type="button" class="caja-btn-tool" id="caja-pos-cust-edit-btn" style="width: 100%; justify-content: center; padding: 10px 14px; font-size: 0.85rem; font-weight: 600; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.4); color: #60a5fa; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-sizing: border-box;">
                            <span id="caja-pos-cust-edit-icon">✏️</span>
                            <span id="caja-pos-cust-edit-text"><?php _e('Editar datos del cliente', 'emp-caja'); ?></span>
                        </button>

                        <div id="caja-pos-cust-edit-actions" style="display: none; width: 100%; gap: 8px;">
                            <button type="button" class="caja-btn-tool" id="caja-pos-cust-save-btn" style="flex: 1; justify-content: center; padding: 10px 14px; font-size: 0.85rem; font-weight: 700; background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.5); color: #34d399; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-sizing: border-box;">
                                <span>💾</span>
                                <span><?php _e('Guardar cambios', 'emp-caja'); ?></span>
                            </button>
                            <button type="button" class="caja-btn-tool" id="caja-pos-cust-discard-btn" style="flex: 1; justify-content: center; padding: 10px 14px; font-size: 0.85rem; font-weight: 600; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #f87171; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-sizing: border-box;">
                                <span>✕</span>
                                <span><?php _e('Descartar cambios', 'emp-caja'); ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empaque y Bolsas Utilizadas (Sección colapsable opcional) -->
            <div class="caja-pos-packaging-subgroup" id="caja-pos-packaging-subgroup" style="margin-bottom: 14px; background: rgba(255,255,255,0.02); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 12px; transition: border-color 0.2s ease;">
                <!-- Cabecera colapsable: Título y Badge alineados a la izquierda -->
                <div class="caja-pos-packaging-header" id="caja-pos-packaging-header" style="display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; width: 100%;">
                    <div class="caja-pos-pkg-header-left" style="display: flex; align-items: baseline; gap: 8px; flex: 0 1 auto;">
                        <span class="caja-pos-pkg-icon" style="font-size: 0.95rem; line-height: 1; align-self: center;">📦</span>
                        <span class="caja-pos-pkg-title" style="font-size: 0.92rem; font-weight: 700; color: var(--caja-text, #ffffff); line-height: 1.2; margin: 0; padding: 0; white-space: nowrap;"><?php _e('Empaque / Bolsas', 'emp-caja'); ?></span>
                        <span id="caja-pos-pkg-req-badge" class="caja-pos-pkg-req-badge" style="font-size: 0.72rem; font-weight: 600; line-height: 1.2; padding: 2px 8px; border-radius: 4px; background: rgba(255,255,255,0.08); color: var(--caja-text-muted, #94a3b8); white-space: nowrap;"><?php _e('Opcional', 'emp-caja'); ?></span>
                        <span id="caja-pos-pkg-count-badge" class="caja-pos-pkg-count-badge" style="display: none; font-size: 0.72rem; font-weight: 700; line-height: 1.2; padding: 2px 8px; border-radius: 4px; background: var(--caja-primary, #10b981); color: #ffffff; white-space: nowrap;">0</span>
                    </div>
                    <span id="caja-pos-pkg-toggle-icon" class="caja-pos-pkg-toggle-icon" style="margin-left: auto; color: var(--caja-text-muted, #94a3b8); font-size: 0.82rem; transition: transform 0.2s ease;">▼</span>
                </div>

                <!-- Contenido colapsable: Lista de paquetes y bolsas -->
                <div class="caja-pos-packaging-body" id="caja-pos-packaging-body" style="margin-top: 12px; display: none;">
                    <div id="caja-pos-packaging-list" class="caja-pos-packaging-list" style="display: flex; flex-direction: column; gap: 6px;">
                        <!-- Se renderiza dinámicamente con packagingProducts -->
                    </div>

                    <!-- Sección Desplegable "+ Agregar nuevo empaque" -->
                    <div class="caja-pkg-add-section" style="margin-top: 10px;">
                        <button type="button" class="caja-pkg-add-toggle btn-toggle-add-pkg-pos" id="caja-pos-btn-toggle-add-pkg">
                            <span>➕ <?php _e('Agregar nuevo empaque', 'emp-caja'); ?></span>
                            <span class="caja-thematic-toggle-arrow" id="caja-pos-add-pkg-arrow">▼</span>
                        </button>
                        <div class="caja-pkg-add-content" id="caja-pos-pkg-add-content" style="display: none;">
                            <!-- Opción A: Vincular producto existente de WooCommerce -->
                            <div id="caja-pos-link-candidate-wrap" style="margin-bottom: 12px; display: none;">
                                <label class="caja-pkg-sublabel"><?php _e('Vincular producto existente de WooCommerce:', 'emp-caja'); ?></label>
                                <div class="caja-pkg-link-row">
                                    <select class="caja-select-existing-pkg" id="caja-pos-sel-exist-pkg">
                                        <option value="">-- <?php _e('Seleccionar producto', 'emp-caja'); ?> --</option>
                                    </select>
                                    <button type="button" class="caja-btn caja-btn-xs caja-btn-primary" id="caja-pos-btn-link-pkg">+ <?php _e('Vincular', 'emp-caja'); ?></button>
                                </div>
                            </div>

                            <!-- Opción B: Crear nuevo producto en el acto -->
                            <div>
                                <label class="caja-pkg-sublabel">+ <?php _e('Crear nuevo empaque oficial en WooCommerce:', 'emp-caja'); ?></label>
                                <div class="caja-pkg-create-inputs-row">
                                    <input type="text" class="caja-input-new-pkg-name" id="caja-pos-new-pkg-name" placeholder="<?php esc_attr_e('Ej: Caja Reforzada Mediana', 'emp-caja'); ?>" />
                                    <input type="number" min="0" class="caja-input-new-pkg-stock" id="caja-pos-new-pkg-stock" placeholder="<?php esc_attr_e('Stock', 'emp-caja'); ?>" value="50" title="<?php esc_attr_e('Stock inicial', 'emp-caja'); ?>" />
                                </div>
                                <button type="button" class="caja-btn caja-btn-xs caja-btn-primary btn-create-dyn-pkg" id="caja-pos-btn-create-pkg">+ <?php _e('Crear y agregar', 'emp-caja'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Campo de Nota de la Venta -->
            <div class="caja-pos-note-group">
                <label for="caja-pos-note" class="caja-pos-field-label"><?php _e('Nota de la venta', 'emp-caja'); ?></label>
                <textarea id="caja-pos-note" class="caja-input caja-pos-note-textarea" rows="2" placeholder="<?php esc_attr_e('Nota o aclaraciones de la venta...', 'emp-caja'); ?>"></textarea>
            </div>

                <!-- Lista de productos y precios en el Subtotal -->
                <div class="caja-pos-summary-items" id="caja-pos-summary-items">
                    <div class="caja-pos-summary-empty"><?php _e('Sin productos seleccionados', 'emp-caja'); ?></div>
                </div>
            </div> <!-- /.caja-pos-checkout-scrollable -->

            <!-- Footer fijo del cobro (Totales y Botón Confirmar) -->
            <div class="caja-pos-checkout-footer">
                <div class="caja-pos-totals-block">
                    <div class="caja-pos-total-line caja-pos-subtotal-row">
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

                <!-- Aviso dinámico si falta pagar con tiempo real de cancelación de WooCommerce -->
                <div id="caja-pos-unpaid-warning" class="caja-pos-unpaid-warning" style="display:none;">
                    <span class="caja-pos-unpaid-icon">⏱️</span>
                    <span id="caja-pos-unpaid-text"></span>
                </div>
            </div>
        </div>
    </div> <!-- /.caja-pos-stack -->

    <!-- MODAL: HISTORIAL DE VENTAS POS -->
    <div id="caja-modal-pos-history" class="caja-modal" style="display:none; z-index: 100050;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog" style="max-width: 860px; width: 95%;">
            <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display: flex; justify-content: space-between; align-items: center; padding: 12px 18px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 1.3rem;">📜</span>
                    <h3 class="caja-modal-title" style="margin: 0; font-size: 1.1rem; color: var(--caja-text, #f8fafc);"><?php _e('Historial', 'emp-caja'); ?></h3>
                    <span id="caja-pos-history-count" class="caja-pos-cart-badge-count" style="display:none; font-size:0.75rem; padding: 2px 7px;">0</span>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button type="button" id="caja-pos-history-refresh-btn" style="background: transparent; border: none; font-size: 1.15rem; cursor: pointer; padding: 2px; color: var(--caja-text-muted, #94a3b8);" title="<?php esc_attr_e('Actualizar historial', 'emp-caja'); ?>">
                        <span>🔄</span>
                    </button>
                    <button type="button" class="caja-modal-close" id="caja-pos-history-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>" style="background: transparent; border: none; font-size: 1.4rem; color: var(--caja-text-muted, #94a3b8); cursor: pointer; line-height: 1;">&times;</button>
                </div>
            </div>
            <div class="caja-modal-body" id="caja-pos-history-body" style="padding: 14px; max-height: 75vh; overflow-y: auto;">
                <div style="text-align: center; padding: 30px; color: var(--caja-text-muted, #94a3b8);">
                    <span class="caja-spinner">⏳</span>
                    <p style="margin-top: 8px;"><?php _e('Cargando historial de ventas...', 'emp-caja'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
