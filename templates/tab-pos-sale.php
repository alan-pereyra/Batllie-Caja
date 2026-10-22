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
                <!-- SELECTOR DE UBICACIÓN / SECTOR / MESA / BARRA -->
                <div class="caja-gastro-sector-group" id="caja-gastro-sector-group">
                    <div class="caja-gastro-sector-header">
                        <div class="caja-gastro-header-left">
                            <span class="caja-gastro-icon">📍</span>
                            <strong class="caja-gastro-title"><?php _e('Sector / Ubicación', 'emp-caja'); ?></strong>
                        </div>
                        <span id="caja-gastro-selected-badge" class="caja-gastro-badge">🍸 Barra Principal</span>
                    </div>
                    <!-- Chips táctiles de sectores rápidos -->
                    <div class="caja-gastro-chips-row" id="caja-gastro-chips-row">
                        <button type="button" class="caja-gastro-chip active" data-sector="Barra Principal" data-icon="🍸">
                            <span>🍸</span> <span>Barra Principal</span>
                        </button>
                        <button type="button" class="caja-gastro-chip" data-sector="Barra VIP" data-icon="⭐">
                            <span>⭐</span> <span>Barra VIP</span>
                        </button>
                        <button type="button" class="caja-gastro-chip" data-sector="Terraza" data-icon="🌿">
                            <span>🌿</span> <span>Terraza</span>
                        </button>
                        <button type="button" class="caja-gastro-chip" data-sector="Mesa" data-icon="🍽️" id="caja-gastro-btn-mesa">
                            <span>🍽️</span> <span>Mesa</span>
                        </button>
                        <button type="button" class="caja-gastro-chip" data-sector="Take Away" data-icon="🛍️">
                            <span>🛍️</span> <span>Take Away</span>
                        </button>
                    </div>
                    <!-- Sub-panel de Mesas (Visible cuando se selecciona 'Mesa') -->
                    <div class="caja-gastro-tables-wrap" id="caja-gastro-tables-wrap" style="display:none;">
                        <label class="caja-gastro-sublabel"><?php _e('Mesa asignada:', 'emp-caja'); ?></label>
                        <div class="caja-gastro-table-chips-row" id="caja-gastro-table-chips-row">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <button type="button" class="caja-gastro-table-chip <?php echo ($m === 1) ? 'active' : ''; ?>" data-table="<?php echo esc_attr($m); ?>">
                                    <?php echo esc_html($m); ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                        <div class="caja-gastro-table-custom-row">
                            <input type="text" id="caja-gastro-custom-table" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('O escribe número/nombre de mesa...', 'emp-caja'); ?>" />
                        </div>
                    </div>
                    <input type="hidden" id="caja-pos-selected-sector" value="Barra Principal" />
                    <input type="hidden" id="caja-pos-selected-table" value="" />
                </div>
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

                <!-- Calculadora de Efectivo y Vuelto Rápido (Speed Cash) -->
                <div id="caja-pos-cash-calc-wrap" class="caja-pos-cash-calc-wrap" style="margin-top: 10px; background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; padding: 10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--caja-primary, #10b981); display:flex; align-items:center; gap:5px;">
                            <span>💵</span> <?php _e('Cobro Rápido en Efectivo', 'emp-caja'); ?>
                        </span>
                        <button type="button" class="caja-cash-bill-clear" id="btn-cash-clear" title="<?php esc_attr_e('Limpiar monto', 'emp-caja'); ?>" style="background:none; border:none; color:#ef4444; font-size:0.72rem; font-weight:700; cursor:pointer;">✕ <?php _e('Limpiar', 'emp-caja'); ?></button>
                    </div>
                    <!-- Fila de billetes táctiles rápidos -->
                    <div class="caja-cash-bills-row" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
                        <button type="button" class="caja-cash-bill-btn caja-cash-exact-btn" id="btn-cash-exact">✓ <?php _e('Exacto', 'emp-caja'); ?></button>
                        <button type="button" class="caja-cash-bill-btn" data-bill="1000">+$1.000</button>
                        <button type="button" class="caja-cash-bill-btn" data-bill="2000">+$2.000</button>
                        <button type="button" class="caja-cash-bill-btn" data-bill="5000">+$5.000</button>
                        <button type="button" class="caja-cash-bill-btn" data-bill="10000">+$10.000</button>
                        <button type="button" class="caja-cash-bill-btn" data-bill="20000">+$20.000</button>
                    </div>
                    <div class="caja-cash-inputs-row" style="display:flex; gap:10px; align-items:center;">
                        <div style="flex:1; position:relative;">
                            <span style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--caja-text-muted, #94a3b8);">$</span>
                            <input type="number" step="any" min="0" id="caja-pos-cash-tendered" class="caja-input caja-pos-cash-input" placeholder="0" style="padding-left:24px; font-weight:700; width:100%; box-sizing:border-box;" title="<?php esc_attr_e('Monto entregado por el cliente', 'emp-caja'); ?>" />
                        </div>
                        <div class="caja-cash-change-display" id="caja-cash-change-display" style="flex:1; background:rgba(0,0,0,0.3); border:1px solid rgba(16, 185, 129, 0.4); border-radius:6px; padding:7px 10px; text-align:center;">
                            <span class="caja-cash-change-lbl" style="font-size:0.68rem; text-transform:uppercase; letter-spacing:0.5px; color:var(--caja-text-muted, #94a3b8); display:block;"><?php _e('Vuelto:', 'emp-caja'); ?></span>
                            <strong class="caja-cash-change-val" id="caja-cash-change-val" style="font-size:1.15rem; color:#34d399; font-weight:800;">$0,00</strong>
                        </div>
                    </div>
                </div>

                <!-- Campo Desplegable de Tarjeta -->
                <div id="caja-pos-card-op-wrap" class="caja-pos-card-op-wrap" style="display:none;">
                    <label for="caja-pos-card-op" class="caja-pos-field-label"><?php _e('N° de Operación / Cupón:', 'emp-caja'); ?></label>
                    <input type="text" id="caja-pos-card-op" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 0049210 o autorización...', 'emp-caja'); ?>" />
                </div>

                <!-- Estado de Cobro (Desplegable 100% de ancho - Solo visible en Pedido por Despachar) -->
                <div class="caja-pos-status-dropdown-wrap" id="caja-pos-status-dropdown-wrap" style="display:none;">
                    <select id="caja-pos-selected-status" class="caja-input caja-pos-status-select">
                        <option value="pagado" selected>🟢 <?php _e('Paga ahora', 'emp-caja'); ?></option>
                        <option value="pendiente">🟠 <?php _e('Pendiente de pago', 'emp-caja'); ?></option>
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
                    
                    <div class="caja-pos-cust-fields-grid" style="display: flex; flex-direction: column;">
                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                            <label for="caja-pos-cust-name" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                <?php _e('Nombre y Apellido', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="text" id="caja-pos-cust-name" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: Juan Pérez', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                            <label for="caja-pos-cust-dni" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                <?php _e('DNI / CUIT', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="text" id="caja-pos-cust-dni" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 35123456', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                            <label for="caja-pos-cust-phone" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                <?php _e('Teléfono / WhatsApp', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="tel" id="caja-pos-cust-phone" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 11 2345 6789', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                            <label for="caja-pos-cust-email" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                <?php _e('Email', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="email" id="caja-pos-cust-email" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: cliente@gmail.com', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                            <label for="caja-pos-cust-address" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                <?php _e('Dirección del Cliente / Localidad', 'emp-caja'); ?> <span class="caja-pos-req-star" style="color: #ef4444; display: none;">*</span>
                            </label>
                            <input type="text" id="caja-pos-cust-address" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Calle, número, depto, ciudad...', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                        </div>

                        <!-- Opción: Envío a otra dirección (Desplegable) -->
                        <div class="caja-pos-ship-diff-wrap" id="caja-pos-ship-diff-wrap" style="display: none; margin-top: 4px; padding-top: 14px; margin-bottom: 16px; border-top: 1px dashed rgba(255,255,255,0.12);">
                            <label class="caja-pos-ship-diff-label" for="caja-pos-ship-diff-checkbox" style="display: inline-flex; align-items: center; gap: 9px; cursor: pointer; user-select: none;">
                                <input type="checkbox" id="caja-pos-ship-diff-checkbox" class="caja-pos-checkbox" style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--caja-primary, #10b981);" />
                                <span style="font-size: 0.88rem; font-weight: 700; color: var(--caja-text, #ffffff);"><?php _e('Envío a otra dirección', 'emp-caja'); ?></span>
                            </label>

                            <div id="caja-pos-ship-diff-fields" style="display: none; margin-top: 12px; margin-bottom: 4px; padding: 14px; background: rgba(0,0,0,0.22); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px;">
                                <div style="font-size: 0.76rem; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px; margin-bottom: 14px;">
                                    <span>🚚</span>
                                    <span><?php _e('Datos del Destinatario y Dirección de Entrega', 'emp-caja'); ?></span>
                                </div>

                                <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                                    <label for="caja-pos-ship-name" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                        <?php _e('Nombre y Apellido (Quien recibe)', 'emp-caja'); ?> <span class="caja-pos-ship-req-star" style="color: #ef4444;">*</span>
                                    </label>
                                    <input type="text" id="caja-pos-ship-name" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: María Gómez', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                                </div>

                                <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                                    <label for="caja-pos-ship-dni" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                        <?php _e('DNI / CUIT (Destinatario)', 'emp-caja'); ?>
                                    </label>
                                    <input type="text" id="caja-pos-ship-dni" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 28999888', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                                </div>

                                <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px;">
                                    <label for="caja-pos-ship-phone" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                        <?php _e('Teléfono / WhatsApp (Destinatario)', 'emp-caja'); ?> <span class="caja-pos-ship-req-star" style="color: #ef4444;">*</span>
                                    </label>
                                    <input type="tel" id="caja-pos-ship-phone" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Ej: 11 9876 5432', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                                </div>

                                <div class="caja-pos-cust-field" style="display: flex; flex-direction: column; gap: 5px; margin-bottom: 4px;">
                                    <label for="caja-pos-ship-address" class="caja-pos-cust-lbl" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text, #ffffff); font-weight: 700; display: block; margin: 0 0 3px 0; padding: 0; line-height: 1.1;">
                                        <?php _e('Dirección de Envío / Localidad (Destino)', 'emp-caja'); ?> <span class="caja-pos-ship-req-star" style="color: #ef4444;">*</span>
                                    </label>
                                    <input type="text" id="caja-pos-ship-address" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Calle, número, depto, ciudad de entrega...', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box;" />
                                </div>
                            </div>
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

            <!-- Calculadora de Envíos (Visible cuando es 'Pedido por Despachar' y 'Con Envío') -->
            <div class="caja-pos-shipping-calc-group" id="caja-pos-shipping-calc-group" style="display: none; margin-bottom: 14px; background: rgba(56, 189, 248, 0.03); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 8px; padding: 12px; transition: border-color 0.2s ease;">
                <!-- Cabecera de la Calculadora -->
                <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 10px;">
                    <span style="font-size: 0.95rem; line-height: 1; align-self: center;">🚚</span>
                    <span style="font-size: 0.92rem; font-weight: 700; color: #38bdf8; line-height: 1.2; margin: 0; padding: 0;"><?php _e('Calculadora de Envíos', 'emp-caja'); ?></span>
                    <span id="caja-pos-shipping-applied-badge" style="display: none; font-size: 0.72rem; font-weight: 700; line-height: 1.2; padding: 2px 8px; border-radius: 4px; background: var(--caja-primary, #10b981); color: #ffffff; white-space: nowrap;"><?php _e('Envío Aplicado', 'emp-caja'); ?></span>
                </div>

                <!-- Fila de Cálculo por Código Postal -->
                <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">
                    <div style="flex: 1; position: relative;">
                        <input type="text" id="caja-pos-calc-postcode" class="caja-input caja-pos-text-input" placeholder="<?php esc_attr_e('Código Postal (CP)', 'emp-caja'); ?>" style="width: 100%; box-sizing: border-box; text-align: center; font-weight: 700; letter-spacing: 0.5px;" maxlength="10" />
                    </div>
                    <button type="button" id="caja-pos-btn-calc-shipping" class="caja-btn caja-btn-primary" style="flex: 1; justify-content: center; padding: 9px 12px; font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; gap: 6px; white-space: nowrap; cursor: pointer;">
                        <span id="caja-pos-btn-calc-icon">⚡</span>
                        <span id="caja-pos-btn-calc-text"><?php _e('Calcular envío', 'emp-caja'); ?></span>
                    </button>
                </div>

                <!-- Contenedor dinámico de Tarifas Encontradas -->
                <div id="caja-pos-shipping-rates-wrap" style="display: none; margin-bottom: 10px;">
                    <div id="caja-pos-shipping-rates-list" style="display: flex; flex-direction: column; gap: 6px;"></div>
                </div>

                <!-- Opción manual o ingreso personalizado -->
                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed rgba(255,255,255,0.08);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--caja-text-muted, #94a3b8); font-weight: 600;"><?php _e('O ingresar costo manual:', 'emp-caja'); ?></span>
                        <button type="button" id="caja-pos-clear-shipping-link" style="display: none; background: none; border: none; font-size: 0.72rem; color: #ef4444; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 0;"><?php _e('Quitar costo', 'emp-caja'); ?></button>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <div style="flex: 1; position: relative;">
                            <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--caja-text-muted, #94a3b8); font-weight: 700; font-size: 0.88rem;">$</span>
                            <input type="number" step="0.01" min="0" id="caja-pos-custom-shipping-cost" class="caja-input caja-pos-text-input" placeholder="0.00" style="width: 100%; box-sizing: border-box; padding-left: 24px; font-weight: 700;" />
                        </div>
                        <button type="button" id="caja-pos-btn-apply-custom-shipping" class="caja-btn" style="background: rgba(255,255,255,0.08); border: 1px solid var(--caja-card-border, #334155); color: var(--caja-text, #ffffff); font-size: 0.82rem; font-weight: 600; padding: 9px 14px; cursor: pointer; white-space: nowrap;">
                            <?php _e('Aplicar', 'emp-caja'); ?>
                        </button>
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
                <textarea id="caja-pos-note" class="caja-input caja-pos-note-textarea" rows="2" placeholder="<?php esc_attr_e('Nota o aclaraciones de la venta (ej: sin hielo, punto de cocción)...', 'emp-caja'); ?>"></textarea>
            </div>

            <!-- Selector de Propina / Servicio (Gastronomía) -->
            <div class="caja-pos-tip-group" id="caja-pos-tip-group" style="margin-bottom: 12px; background: rgba(16, 185, 129, 0.04); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 8px; padding: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #34d399; display: flex; align-items: center; gap: 5px;">
                        <span>🪙</span> <span><?php _e('Propina / Servicio', 'emp-caja'); ?></span>
                    </div>
                    <strong id="caja-pos-tip-val-badge" style="color: #34d399; font-size: 0.82rem; font-weight: 700;">$0,00</strong>
                </div>
                <div class="caja-pos-tip-chips-row" style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button" class="caja-pos-tip-chip active" data-tip="0" style="flex: 1; min-width: 48px; padding: 6px 4px; font-size: 0.78rem; font-weight: 700; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.06); color: var(--caja-text, #ffffff); cursor: pointer;">0%</button>
                    <button type="button" class="caja-pos-tip-chip" data-tip="5" style="flex: 1; min-width: 48px; padding: 6px 4px; font-size: 0.78rem; font-weight: 700; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.06); color: var(--caja-text, #ffffff); cursor: pointer;">5%</button>
                    <button type="button" class="caja-pos-tip-chip" data-tip="10" style="flex: 1; min-width: 48px; padding: 6px 4px; font-size: 0.78rem; font-weight: 700; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.06); color: var(--caja-text, #ffffff); cursor: pointer;">10%</button>
                    <button type="button" class="caja-pos-tip-chip" data-tip="15" style="flex: 1; min-width: 48px; padding: 6px 4px; font-size: 0.78rem; font-weight: 700; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.06); color: var(--caja-text, #ffffff); cursor: pointer;">15%</button>
                    <button type="button" class="caja-pos-tip-chip" data-tip="custom" id="caja-pos-tip-custom-btn" style="flex: 1; min-width: 48px; padding: 6px 4px; font-size: 0.78rem; font-weight: 700; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.06); color: var(--caja-text, #ffffff); cursor: pointer;"><?php _e('Libre', 'emp-caja'); ?></button>
                </div>
                <div id="caja-pos-tip-custom-wrap" style="display: none; margin-top: 8px;">
                    <div style="position: relative;">
                        <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-weight: 700; color: #34d399;">$</span>
                        <input type="number" step="any" min="0" id="caja-pos-custom-tip-input" class="caja-input" placeholder="0.00" style="width: 100%; box-sizing: border-box; padding-left: 24px; font-weight: 700; font-size: 0.85rem;" />
                    </div>
                </div>
                <input type="hidden" id="caja-pos-selected-tip" value="0" />
                <input type="hidden" id="caja-pos-tip-amount" value="0" />
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
                    <div class="caja-pos-total-line caja-pos-shipping-total-row" id="caja-pos-shipping-total-row" style="display:none; color: #38bdf8;">
                        <span id="caja-pos-shipping-total-label"><?php _e('Envío:', 'emp-caja'); ?></span>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <strong id="caja-pos-shipping-total-val">$0,00</strong>
                            <button type="button" id="caja-pos-remove-shipping-btn" title="<?php esc_attr_e('Quitar costo de envío', 'emp-caja'); ?>" style="background: none; border: none; color: #ef4444; font-size: 0.9rem; cursor: pointer; padding: 0 4px; line-height: 1;">✕</button>
                        </div>
                    </div>
                    <div class="caja-pos-total-line caja-pos-tip-total-row" id="caja-pos-tip-total-row" style="display:none; color: #34d399;">
                        <span id="caja-pos-tip-total-label"><?php _e('Propina / Servicio:', 'emp-caja'); ?></span>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <strong id="caja-pos-tip-total-val">$0,00</strong>
                            <button type="button" id="caja-pos-remove-tip-btn" title="<?php esc_attr_e('Quitar propina', 'emp-caja'); ?>" style="background: none; border: none; color: #ef4444; font-size: 0.9rem; cursor: pointer; padding: 0 4px; line-height: 1;">✕</button>
                        </div>
                    </div>
                    <div class="caja-pos-total-line caja-pos-total-line-grand">
                        <span><?php _e('Total a pagar:', 'emp-caja'); ?></span>
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

    <!-- MODAL / BOTTOM SHEET: SELECTOR DE VARIACIONES POS -->
    <div id="caja-modal-pos-variable" class="caja-modal caja-pos-bottom-sheet-modal" style="display:none; z-index: 100060;">
        <div class="caja-modal-backdrop" id="caja-pos-var-backdrop"></div>
        <div class="caja-modal-dialog caja-pos-var-dialog">
            <div class="caja-pos-var-header">
                <div class="caja-pos-var-header-left">
                    <div class="caja-pos-var-thumb" id="caja-pos-var-thumb">
                        <img src="" alt="" id="caja-pos-var-thumb-img" />
                    </div>
                    <div class="caja-pos-var-info">
                        <h3 class="caja-pos-var-title" id="caja-pos-var-title"></h3>
                        <div class="caja-pos-var-sub">
                            <strong class="caja-pos-var-price" id="caja-pos-var-price"></strong>
                            <span class="caja-pos-var-stock-badge" id="caja-pos-var-stock"></span>
                        </div>
                    </div>
                </div>
                <button type="button" class="caja-pos-var-close" id="caja-pos-var-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>

            <div class="caja-pos-var-body" id="caja-pos-var-body">
                <!-- Se llena dinámicamente con los atributos y pastillas (Talles, Colores, etc.) -->
            </div>

            <!-- Sección de Stepper y Botón Agregar -->
            <div class="caja-pos-var-footer">
                <div class="caja-pos-var-stepper-wrap">
                    <button type="button" class="caja-pos-var-btn-qty" id="caja-pos-var-btn-minus">−</button>
                    <span class="caja-pos-var-qty-val" id="caja-pos-var-qty">1</span>
                    <button type="button" class="caja-pos-var-btn-qty" id="caja-pos-var-btn-plus">+</button>
                </div>
                <button type="button" class="caja-btn caja-pos-var-confirm-btn" id="caja-pos-var-add-btn">
                    <span id="caja-pos-var-add-text"><?php _e('Agregar a la venta', 'emp-caja'); ?></span>
                </button>
            </div>

            <!-- Resumen de variaciones ya agregadas para este producto -->
            <div class="caja-pos-var-current-items" id="caja-pos-var-current-items" style="display:none;">
                <div class="caja-pos-var-current-title"><?php _e('En esta venta:', 'emp-caja'); ?></div>
                <div class="caja-pos-var-current-list" id="caja-pos-var-current-list"></div>
            </div>
        </div>
    </div>
</div>
