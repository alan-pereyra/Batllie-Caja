<?php
/**
 * Template de la Pantalla "Venta Caja" (Punto de Venta POS)
 */

if (!defined('ABSPATH')) {
    exit;
}

$pos_user_id = get_current_user_id();
$pos_perms = class_exists('Batllie_Caja_Employees') ? Batllie_Caja_Employees::get_user_permissions($pos_user_id) : array(
    'is_admin' => true,
    'can_change_sector' => true,
    'assigned_sectors' => array('Barra Principal', 'Barra VIP', 'Terraza', 'Take Away')
);
$can_change_sector = !empty($pos_perms['can_change_sector']) || !empty($pos_perms['is_admin']);
$can_view_orders   = !empty($pos_perms['can_view_orders']) || !empty($pos_perms['is_admin']);
$can_view_pkg      = !empty($pos_perms['can_view_packaging']) || !empty($pos_perms['is_admin']);
$assigned_sectors  = !empty($pos_perms['assigned_sectors']) ? $pos_perms['assigned_sectors'] : array('Barra Principal');

// Vinculación estricta Take Away <-> Pedido por Despachar:
// "solo si tiene el permiso de ambos una caja y take away, por despachar está vinculado solamente a take away, si no tiene esta opción no se habilita take away o vise versa"
$user_has_takeaway = false;
foreach ($assigned_sectors as $sec) {
    if (stripos($sec, 'take') !== false) {
        $user_has_takeaway = true;
        break;
    }
}

if (!empty($pos_perms['is_admin'])) {
    $can_dispatch = true;
    $can_takeaway = true;
} else {
    $can_dispatch_raw = !empty($pos_perms['can_dispatch_orders']);
    $can_dispatch = ($can_dispatch_raw && $user_has_takeaway);
    $can_takeaway = $can_dispatch;

    // Si no tiene ambos permisos, filtrar Take Away de los sectores asignados
    if (!$can_takeaway) {
        $assigned_sectors = array_values(array_filter($assigned_sectors, function($s) {
            return (stripos($s, 'take') === false);
        }));
        if (empty($assigned_sectors)) {
            $assigned_sectors = array('Barra Principal');
        }
    }
}

// "se tienen que tomar los datos": Para despachar / Take Away los datos de cliente siempre deben estar disponibles
$can_view_customer = !empty($pos_perms['can_view_customer_data']) || !empty($pos_perms['is_admin']) || $can_dispatch;
$default_sector    = !empty($assigned_sectors[0]) ? $assigned_sectors[0] : 'Barra Principal';

if (!function_exists('emp_caja_get_sector_icon_helper')) {
    function emp_caja_get_sector_icon_helper($s) {
        if (stripos($s, 'vip') !== false) return '⭐';
        if (stripos($s, 'terraza') !== false) return '🌿';
        if (stripos($s, 'mesa') !== false) return '🍽️';
        if (stripos($s, 'take') !== false) return '🛍️';
        if (stripos($s, 'boleteria') !== false || stripos($s, 'entrada') !== false) return '🎟️';
        if (stripos($s, 'pista') !== false) return '🎵';
        return '🍸';
    }
}
$default_icon = emp_caja_get_sector_icon_helper($default_sector);
?>

<div class="caja-pos-container" id="caja-pos-main-container">
    <!-- BARRA DE ESTADO DE SESIÓN DE CAJA (ARQUEO / TURNO ACTIVO) -->
    <div id="caja-pos-register-bar" class="caja-pos-register-bar" style="margin-bottom: 12px;">
        <div id="caja-pos-reg-active-wrap" class="caja-pos-reg-active-box" style="display:none; background: var(--caja-card-bg, #1e293b); border: 1px solid rgba(16, 185, 129, 0.35); border-radius: 8px; padding: 10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="caja-pos-reg-info-left" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:0.88rem;">
                <span class="caja-pulse-dot" style="background:#10b981; width:9px; height:9px; border-radius:50%; box-shadow:0 0 8px #10b981; flex-shrink:0;"></span>
                <span class="caja-pos-reg-badge-status" style="color:#10b981; font-weight:700;">🟢 <?php _e('Caja Abierta', 'emp-caja'); ?>:</span>
                <strong id="caja-pos-reg-sector-label" class="caja-pos-reg-highlight" style="color:var(--caja-text, #f8fafc);"></strong>
                <span class="caja-pos-reg-sep" style="color:var(--caja-card-border, #334155);">|</span>
                <span class="caja-pos-reg-sub" id="caja-pos-reg-code-label" style="color:var(--caja-text-muted, #94a3b8); font-size:0.82rem;"></span>
                <span class="caja-pos-reg-sep" style="color:var(--caja-card-border, #334155);">|</span>
                <span class="caja-pos-reg-sub" style="color:var(--caja-text-muted, #94a3b8);"><?php _e('Fondo:', 'emp-caja'); ?> <strong id="caja-pos-reg-opening-label" style="color:#10b981;">$0,00</strong></span>
                <span class="caja-pos-reg-sep" style="color:var(--caja-card-border, #334155);">|</span>
                <span class="caja-pos-reg-sub" style="color:var(--caja-text-muted, #94a3b8);"><?php _e('Ventas:', 'emp-caja'); ?> <strong id="caja-pos-reg-sales-label" style="color:#34d399;">$0,00</strong> (<span id="caja-pos-reg-orders-count">0</span>)</span>
            </div>
            <div class="caja-pos-reg-actions-right">
                <button type="button" class="caja-btn caja-btn-danger caja-btn-sm" id="caja-pos-btn-close-register" title="<?php esc_attr_e('Cerrar Caja y realizar arqueo de dinero', 'emp-caja'); ?>" style="padding: 6px 14px; font-size:0.82rem;">
                    <span>🔒</span>
                    <span><?php _e('Cerrar Caja (Arqueo)', 'emp-caja'); ?></span>
                </button>
            </div>
        </div>

        <div id="caja-pos-reg-closed-wrap" class="caja-pos-reg-closed-box" style="display:none; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.35); border-radius: 8px; padding: 12px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div class="caja-pos-reg-closed-left" style="display:flex; align-items:center; gap:10px;">
                <span style="font-size: 1.35rem; flex-shrink:0;">⚠️</span>
                <div>
                    <strong style="color: #f87171; font-size: 0.92rem; display:block;"><?php _e('Caja Cerrada - Se requiere Apertura de Turno', 'emp-caja'); ?></strong>
                    <span style="color: var(--caja-text-muted, #94a3b8); font-size: 0.8rem;"><?php _e('Debes abrir la caja e ingresar tu fondo inicial en efectivo antes de poder registrar ventas y cobros.', 'emp-caja'); ?></span>
                </div>
            </div>
            <button type="button" class="caja-btn caja-btn-primary caja-btn-sm" id="caja-pos-btn-open-register-top" style="padding: 8px 16px; font-size:0.85rem;">
                <span>🟢</span>
                <span><?php _e('Abrir Turno / Caja', 'emp-caja'); ?></span>
            </button>
        </div>
    </div>

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
                    <?php if ($can_view_orders) : ?>
                    <button type="button" class="caja-pos-btn-ghost-sm" id="caja-pos-open-history-btn" title="<?php esc_attr_e('Ver Historial de Ventas', 'emp-caja'); ?>">
                        <span>📜</span>
                        <span class="caja-btn-txt-responsive"><?php _e('Historial', 'emp-caja'); ?></span>
                        <span id="caja-pos-history-badge-top" class="caja-pos-cart-badge-count" style="display:none; font-size:0.68rem; padding:1px 5px; margin-left:2px;">0</span>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="caja-pos-btn-ghost-sm" id="caja-pos-clear-cart" title="<?php esc_attr_e('Vaciar selección', 'emp-caja'); ?>">
                        <span>🗑️</span>
                        <span class="caja-btn-txt-responsive"><?php _e('Vaciar', 'emp-caja'); ?></span>
                    </button>
                </div>
            </div>

            <!-- Buscador Rápido de Productos y Selector de Vista -->
            <div class="caja-pos-search-header" style="display:flex; gap:8px; align-items:center;">
                <div class="caja-pos-search-box" style="flex:1;">
                    <span class="caja-pos-search-icon">🔍</span>
                    <input type="text" id="caja-pos-search-input" class="caja-pos-search-input" placeholder="<?php esc_attr_e('Buscar producto por nombre o SKU...', 'emp-caja'); ?>" autocomplete="off" />
                    <button type="button" class="caja-pos-search-clear" id="caja-pos-search-clear" style="display:none;">&times;</button>
                </div>
                <?php 
                $allow_toggle = !empty($pos_perms['allow_layout_toggle']) || !empty($pos_perms['is_admin']);
                $curr_layout  = !empty($pos_perms['product_layout']) ? $pos_perms['product_layout'] : 'grid';
                $curr_cols    = !empty($pos_perms['product_grid_cols']) ? $pos_perms['product_grid_cols'] : '2';
                ?>
                <input type="hidden" id="caja-pos-current-layout" value="<?php echo esc_attr($curr_layout); ?>" />
                <input type="hidden" id="caja-pos-current-grid-cols" value="<?php echo esc_attr($curr_cols); ?>" />

                <?php if ($allow_toggle): ?>
                <div class="caja-pos-layout-switcher" id="caja-pos-layout-switcher" style="display:flex; background:rgba(255,255,255,0.06); border:1px solid var(--caja-card-border, #1f2937); border-radius:8px; padding:2px; flex-shrink:0;">
                    <button type="button" class="caja-pos-layout-btn <?php echo ($curr_layout === 'list') ? 'active' : ''; ?>" data-layout="list" data-cols="1" title="<?php esc_attr_e('Vista Lista Compacta', 'emp-caja'); ?>">
                        <span>☰</span>
                    </button>
                    <button type="button" class="caja-pos-layout-btn <?php echo ($curr_layout === 'grid' && $curr_cols === '2') ? 'active' : ''; ?>" data-layout="grid" data-cols="2" title="<?php esc_attr_e('Grilla 2 Columnas (Grande)', 'emp-caja'); ?>">
                        <span>⊞</span> <span style="font-size:0.75rem; font-weight:700;">2</span>
                    </button>
                    <button type="button" class="caja-pos-layout-btn <?php echo ($curr_layout === 'grid' && $curr_cols === '3') ? 'active' : ''; ?>" data-layout="grid" data-cols="3" title="<?php esc_attr_e('Grilla 3 Columnas (Mediana)', 'emp-caja'); ?>">
                        <span>▦</span> <span style="font-size:0.75rem; font-weight:700;">3</span>
                    </button>
                </div>
                <?php endif; ?>
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
                        <span id="caja-gastro-selected-badge" class="caja-gastro-badge">
                            <?php echo (!$can_change_sector ? '🔒 ' : '') . esc_html($default_icon . ' ' . $default_sector); ?>
                        </span>
                    </div>

                    <!-- Chips táctiles de sectores rápidos -->
                    <div class="caja-gastro-chips-row" id="caja-gastro-chips-row">
                        <?php if ($can_change_sector): ?>
                            <!-- Administradores: Acceso total a todas las barras, terraza, take away y mesas -->
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
                            <?php if ($can_takeaway): ?>
                            <button type="button" class="caja-gastro-chip" data-sector="Take Away" data-icon="🛍️">
                                <span>🛍️</span> <span>Take Away</span>
                            </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Cajeros sin permiso de cambio: fijados a su(s) barra(s) autorizada(s) -->
                            <?php if (count($assigned_sectors) <= 1): ?>
                                <button type="button" class="caja-gastro-chip active is-locked" data-sector="<?php echo esc_attr($default_sector); ?>" data-icon="<?php echo esc_attr($default_icon); ?>" title="<?php esc_attr_e('Barra asignada fija por administración', 'emp-caja'); ?>" style="cursor:default;">
                                    <span>🔒</span> <span><?php echo esc_html($default_sector); ?></span>
                                </button>
                            <?php else: ?>
                                <?php foreach ($assigned_sectors as $idx => $s): 
                                    $s_icon = emp_caja_get_sector_icon_helper($s);
                                ?>
                                    <button type="button" class="caja-gastro-chip <?php echo ($idx === 0) ? 'active' : ''; ?>" data-sector="<?php echo esc_attr($s); ?>" data-icon="<?php echo esc_attr($s_icon); ?>">
                                        <span><?php echo esc_html($s_icon); ?></span> <span><?php echo esc_html($s); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($can_change_sector): ?>
                    <!-- Sub-panel de Mesas (Solo accesible para Administradores) -->
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
                    <?php endif; ?>

                    <input type="hidden" id="caja-pos-selected-sector" value="<?php echo esc_attr($default_sector); ?>" />
                    <input type="hidden" id="caja-pos-selected-table" value="" />
                </div>
                <!-- Selector de Modo de Entrega tipo Switch iOS -->
            <?php if ($can_dispatch) : ?>
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
            <?php endif; ?>
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

                <!-- Calculadora de Efectivo y Vuelto Rápido (Speed Cash con Stepper +/-) -->
                <div id="caja-pos-cash-calc-wrap" class="caja-pos-cash-calc-wrap" style="margin-top: 10px; background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; padding: 10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--caja-primary, #10b981); display:flex; align-items:center; gap:5px;">
                            <span>💵</span> <?php _e('Cobro Rápido en Efectivo', 'emp-caja'); ?>
                        </span>
                        <button type="button" class="caja-cash-bill-clear" id="btn-cash-clear" title="<?php esc_attr_e('Limpiar monto', 'emp-caja'); ?>" style="background:none; border:none; color:#ef4444; font-size:0.72rem; font-weight:700; cursor:pointer;">✕ <?php _e('Limpiar', 'emp-caja'); ?></button>
                    </div>
                    <!-- Grilla de billetes con botones +/- por denominación -->
                    <div class="caja-cash-bills-grid" id="caja-pos-cash-bills-grid">
                        <button type="button" class="caja-cash-exact-card" id="btn-cash-exact" title="<?php esc_attr_e('Cobrar importe exacto del ticket', 'emp-caja'); ?>">
                            <span class="caja-cash-exact-check">✓</span>
                            <span class="caja-cash-exact-text"><?php _e('Exacto', 'emp-caja'); ?></span>
                        </button>
                        <?php 
                        $pos_bills = array(100, 200, 500, 1000, 2000, 5000, 10000, 20000);
                        foreach ($pos_bills as $b) : 
                            $b_label = '$' . number_format($b, 0, ',', '.');
                        ?>
                        <div class="caja-cash-bill-item" data-bill="<?php echo $b; ?>">
                            <button type="button" class="caja-cash-btn-step caja-cash-btn-minus" data-action="minus" data-bill="<?php echo $b; ?>" title="<?php esc_attr_e('Restar 1 billete', 'emp-caja'); ?>">−</button>
                            <div class="caja-cash-bill-label" data-action="plus" data-bill="<?php echo $b; ?>" title="<?php esc_attr_e('Sumar 1 billete', 'emp-caja'); ?>">
                                <span class="caja-cash-bill-badge" id="caja-bill-badge-<?php echo $b; ?>">0</span>
                                <span class="caja-cash-bill-name"><?php echo $b_label; ?></span>
                            </div>
                            <button type="button" class="caja-cash-btn-step caja-cash-btn-plus" data-action="plus" data-bill="<?php echo $b; ?>" title="<?php esc_attr_e('Sumar 1 billete', 'emp-caja'); ?>">+</button>
                        </div>
                        <?php endforeach; ?>
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
            <?php if ($can_view_customer) : ?>
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
            <?php endif; ?>

            <!-- Empaque y Bolsas Utilizadas (Sección colapsable opcional) -->
            <?php if ($can_view_pkg) : ?>
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
            <?php endif; ?>

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

    <!-- MODAL: APERTURA DE CAJA (INICIAR TURNO) -->
    <div id="caja-modal-open-register" class="caja-modal" style="display:none; z-index: 100070;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog" style="max-width: 520px; width: 95%;">
            <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5rem;">🟢</span>
                    <div>
                        <h3 class="caja-modal-title" style="margin: 0; font-size: 1.2rem;"><?php _e('Apertura de Caja (Iniciar Turno)', 'emp-caja'); ?></h3>
                        <p style="margin: 2px 0 0 0; font-size: 0.85rem; color: var(--caja-text-muted, #94a3b8);"><?php _e('Ingresa el fondo de cambio inicial para comenzar a cobrar.', 'emp-caja'); ?></p>
                    </div>
                </div>
                <button type="button" class="caja-modal-close" id="caja-open-reg-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>

            <form id="caja-open-reg-form" class="caja-modal-body" style="padding: 20px 18px;">
                <!-- Selección de Sector / Barra -->
                <div class="caja-form-group" style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 0.85rem; font-weight: 600; color: var(--caja-text, #f8fafc); margin-bottom: 6px;">
                        <?php _e('Barra / Sector asignado:', 'emp-caja'); ?>
                    </label>
                    <select id="caja-open-reg-sector" class="caja-input" style="width: 100%; font-size: 0.95rem; padding: 10px 12px;">
                        <?php foreach ($assigned_sectors as $sec) : ?>
                            <option value="<?php echo esc_attr($sec); ?>" <?php echo ($sec === $default_sector) ? 'selected' : ''; ?>>
                                <?php echo emp_caja_get_sector_icon_helper($sec) . ' ' . esc_html($sec); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Monto Inicial en Efectivo -->
                <div class="caja-form-group" style="margin-bottom: 16px;">
                    <label style="display:block; font-size: 0.85rem; font-weight: 600; color: var(--caja-text, #f8fafc); margin-bottom: 6px;">
                        💵 <?php _e('Fondo Inicial en Efectivo (Cambio disponible en caja):', 'emp-caja'); ?>
                    </label>
                    <div style="position:relative;">
                        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--caja-text-muted); font-size:1.1rem; font-weight:700;">$</span>
                        <input type="number" id="caja-open-reg-cash" class="caja-input" style="padding-left: 28px; font-size: 1.25rem; font-weight: 700; color: #10b981; width: 100%;" min="0" step="100" value="0" placeholder="0.00" required />
                    </div>

                    <!-- Botones rápidos de monto inicial -->
                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top: 10px;">
                        <button type="button" class="caja-pos-quick-bill caja-open-cash-chip" data-amount="0">$0</button>
                        <button type="button" class="caja-pos-quick-bill caja-open-cash-chip" data-amount="5000">+$5.000</button>
                        <button type="button" class="caja-pos-quick-bill caja-open-cash-chip" data-amount="10000">+$10.000</button>
                        <button type="button" class="caja-pos-quick-bill caja-open-cash-chip" data-amount="20000">+$20.000</button>
                        <button type="button" class="caja-pos-quick-bill caja-open-cash-chip" data-amount="50000">+$50.000</button>
                    </div>
                </div>

                <!-- Observaciones de Apertura -->
                <div class="caja-form-group" style="margin-bottom: 20px;">
                    <label style="display:block; font-size: 0.85rem; font-weight: 600; color: var(--caja-text-muted, #94a3b8); margin-bottom: 6px;">
                        📝 <?php _e('Notas u Observaciones (Opcional):', 'emp-caja'); ?>
                    </label>
                    <textarea id="caja-open-reg-notes" class="caja-input" rows="2" style="width: 100%; font-size: 0.88rem;" placeholder="<?php esc_attr_e('Ej: Se inicia turno con cambio en billetes de 1000 y 500...', 'emp-caja'); ?>"></textarea>
                </div>

                <!-- Botón Confirmar Apertura -->
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="caja-btn caja-btn-primary" id="caja-open-reg-submit-btn" style="flex:1; justify-content:center; padding: 12px; font-size: 1rem; font-weight:700;">
                        <span>🟢</span>
                        <span><?php _e('Iniciar Turno y Abrir Caja', 'emp-caja'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CIERRE DE CAJA Y ARQUEO DE DINERO (CONTADOR DE BILLETES) -->
    <div id="caja-modal-close-register" class="caja-modal" style="display:none; z-index: 100075;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog" style="max-width: 820px; width: 95%;">
            <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5rem;">🔒</span>
                    <div>
                        <h3 class="caja-modal-title" style="margin: 0; font-size: 1.25rem;"><?php _e('Cierre de Caja y Arqueo de Dinero', 'emp-caja'); ?></h3>
                        <p id="caja-close-reg-sub" style="margin: 2px 0 0 0; font-size: 0.85rem; color: var(--caja-text-muted, #94a3b8);"></p>
                    </div>
                </div>
                <button type="button" class="caja-modal-close" id="caja-close-reg-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>

            <div class="caja-modal-body" style="padding: 16px 20px; max-height: 80vh; overflow-y: auto;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 16px;">
                    
                    <!-- COLUMNA 1: CONTADOR DE BILLETES FÍSICOS -->
                    <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 14px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                            <strong style="color: var(--caja-text, #f8fafc); font-size: 0.92rem; display:flex; align-items:center; gap: 6px;">
                                <span>💵</span>
                                <span><?php _e('Contador de Billetes en Caja', 'emp-caja'); ?></span>
                            </strong>
                            <button type="button" class="caja-pos-btn-ghost-sm" id="caja-close-reg-clear-bills" style="font-size:0.75rem; padding:2px 8px;">
                                <span>🗑️</span> <?php _e('Limpiar', 'emp-caja'); ?>
                            </button>
                        </div>

                        <!-- Grilla de denominaciones de billetes -->
                        <div class="caja-bill-counter-list" style="display:flex; flex-direction:column; gap:6px;">
                            <?php 
                            $denominations = array(20000, 10000, 5000, 2000, 1000, 500, 200, 100);
                            foreach ($denominations as $denom) :
                            ?>
                            <div class="caja-bill-row" style="display:flex; align-items:center; justify-content:space-between; background: var(--caja-card-bg, #1e293b); border: 1px solid var(--caja-card-border, #334155); border-radius: 6px; padding: 6px 10px;">
                                <span style="font-size: 0.85rem; font-weight: 600; min-width: 90px; color: var(--caja-text, #f8fafc);">
                                    $<?php echo number_format($denom, 0, ',', '.'); ?>
                                </span>
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <button type="button" class="caja-btn-qty-bill" data-denom="<?php echo $denom; ?>" data-action="minus" style="width:28px; height:28px; background:rgba(255,255,255,0.08); border:none; border-radius:4px; color:#fff; font-weight:bold; cursor:pointer;">−</button>
                                    <input type="number" class="caja-bill-input" data-denom="<?php echo $denom; ?>" min="0" value="0" style="width: 50px; text-align:center; padding: 4px; background: rgba(0,0,0,0.3); border: 1px solid var(--caja-card-border, #334155); border-radius: 4px; color:#fff; font-size:0.85rem;" />
                                    <button type="button" class="caja-btn-qty-bill" data-denom="<?php echo $denom; ?>" data-action="plus" style="width:28px; height:28px; background:rgba(255,255,255,0.08); border:none; border-radius:4px; color:#fff; font-weight:bold; cursor:pointer;">+</button>
                                </div>
                                <span class="caja-bill-subtotal" id="caja-bill-sub-<?php echo $denom; ?>" style="font-size: 0.85rem; font-weight: 700; color: #10b981; min-width: 80px; text-align:right;">
                                    $0,00
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Total calculado por billetes -->
                        <div style="margin-top: 12px; padding: 10px 12px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 6px; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size: 0.85rem; color: #10b981; font-weight: 600;"><?php _e('Total Billetes Contados:', 'emp-caja'); ?></span>
                            <strong id="caja-close-reg-bills-total" style="font-size: 1.15rem; color: #10b981;">$0,00</strong>
                        </div>

                        <!-- Opcional: Monto manual directo -->
                        <div style="margin-top: 10px;">
                            <label style="font-size: 0.78rem; color: var(--caja-text-muted, #94a3b8); display:block; margin-bottom: 4px;">
                                <?php _e('O ingresar total efectivo contado directo:', 'emp-caja'); ?>
                            </label>
                            <input type="number" id="caja-close-reg-manual-cash" class="caja-input" placeholder="0.00" min="0" step="100" style="width: 100%; font-size: 0.9rem; padding: 6px 10px;" />
                        </div>
                    </div>

                    <!-- COLUMNA 2: CORRESPONDENCIA ESPERADO VS CONTADO (ARQUEO) -->
                    <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 14px; display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <strong style="color: var(--caja-text, #f8fafc); font-size: 0.92rem; display:block; margin-bottom: 12px;">
                                ⚖️ <?php _e('Correspondencia de Dinero (Arqueo)', 'emp-caja'); ?>
                            </strong>

                            <div style="display:flex; flex-direction:column; gap:8px; font-size:0.86rem; margin-bottom: 14px;">
                                <div style="display:flex; justify-content:space-between; color:var(--caja-text-muted, #94a3b8);">
                                    <span>(+) <?php _e('Fondo Inicial de Apertura:', 'emp-caja'); ?></span>
                                    <strong id="caja-close-calc-opening" style="color:var(--caja-text);">$0,00</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; color:var(--caja-text-muted, #94a3b8);">
                                    <span>(+) <?php _e('Ventas en Efectivo:', 'emp-caja'); ?></span>
                                    <strong id="caja-close-calc-cash-sales" style="color:#10b981;">$0,00</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; color:var(--caja-text-muted, #94a3b8);">
                                    <span>(+) <?php _e('Propinas en Efectivo:', 'emp-caja'); ?></span>
                                    <strong id="caja-close-calc-tips-cash" style="color:#34d399;">$0,00</strong>
                                </div>
                                <div style="border-top: 1px dashed var(--caja-card-border, #334155); padding-top: 6px; display:flex; justify-content:space-between; font-weight:700;">
                                    <span style="color:var(--caja-text);"><?php _e('(=) Total Efectivo Esperado:', 'emp-caja'); ?></span>
                                    <strong id="caja-close-calc-expected" style="color:#38bdf8; font-size:1.05rem;">$0,00</strong>
                                </div>
                            </div>

                            <!-- Comparación con lo contado -->
                            <div style="background: var(--caja-card-bg, #1e293b); border: 1px solid var(--caja-card-border, #334155); border-radius: 6px; padding: 12px; margin-bottom: 14px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 6px;">
                                    <span style="font-size:0.85rem; color:var(--caja-text-muted);"><?php _e('Efectivo Contado en Mano:', 'emp-caja'); ?></span>
                                    <strong id="caja-close-calc-counted" style="font-size:1.1rem; color:#10b981;">$0,00</strong>
                                </div>
                                <div id="caja-close-diff-badge" style="padding: 8px 10px; border-radius: 6px; text-align:center; font-weight:700; font-size:0.92rem; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                                    ✅ <?php _e('Caja Cuadrada Exacta', 'emp-caja'); ?>
                                </div>
                            </div>

                            <!-- Resumen Digital informativo -->
                            <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-bottom: 12px;">
                                <strong style="color: #60a5fa; display:block; margin-bottom: 3px;">💳 <?php _e('Cobros Electrónicos (No ingresan a caja física):', 'emp-caja'); ?></strong>
                                <div style="display:flex; justify-content:space-between; color:var(--caja-text-muted);">
                                    <span><?php _e('Tarjetas / MP / Transferencias:', 'emp-caja'); ?></span>
                                    <strong id="caja-close-calc-digital" style="color: #93c5fd;">$0,00</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Observaciones de Cierre -->
                        <div>
                            <label style="font-size: 0.8rem; color: var(--caja-text-muted, #94a3b8); display:block; margin-bottom: 4px;">
                                📝 <?php _e('Observaciones de Cierre (Justificación de faltante o sobrante):', 'emp-caja'); ?>
                            </label>
                            <textarea id="caja-close-reg-notes" class="caja-input" rows="2" style="width: 100%; font-size: 0.85rem;" placeholder="<?php esc_attr_e('Notas del cierre de turno...', 'emp-caja'); ?>"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción del Modal -->
                <div style="display:flex; justify-content:space-between; align-items:center; border-top: 1px solid var(--caja-card-border, #334155); padding-top: 14px; flex-wrap:wrap; gap:10px;">
                    <button type="button" class="caja-btn caja-btn-secondary" id="caja-close-reg-cancel-btn">
                        <?php _e('Cancelar', 'emp-caja'); ?>
                    </button>

                    <div style="display:flex; gap:10px;">
                        <button type="button" class="caja-btn caja-btn-danger" id="caja-close-reg-confirm-btn" style="padding: 10px 22px; font-weight:700;">
                            <span>🔒</span>
                            <span><?php _e('Confirmar y Cerrar Caja', 'emp-caja'); ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
