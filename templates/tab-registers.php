<?php
/**
 * Template de la Pantalla "Control de Cajas" (Administrador)
 * Supervisión en tiempo real de cajas, historial de turnos y arqueos con horarios exactos.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options') && !current_user_can('administrator')) {
    echo '<div class="caja-alert caja-alert-danger" style="margin:20px; padding:16px; background:#ef444422; border:1px solid #ef4444; border-radius:8px; color:#f87171;">' . __('Acceso restringido únicamente a administradores.', 'emp-caja') . '</div>';
    return;
}

$all_sectors = class_exists('Batllie_Caja_Employees') ? Batllie_Caja_Employees::get_available_sectors() : array('Barra Principal', 'Barra VIP', 'Terraza', 'Boletería / Entrada', 'Take Away');
$all_employees = class_exists('Batllie_Caja_Employees') ? Batllie_Caja_Employees::get_employees() : array();
?>

<div class="caja-registers-container">
    <!-- Barra Superior de Control de Cajas -->
    <div class="caja-settings-topbar">
        <div class="caja-settings-title-group">
            <h1 class="caja-settings-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                    <line x1="6" y1="12" x2="18" y2="12"></line>
                    <circle cx="12" cy="12" r="2"></circle>
                    <path d="M6 8h.01M6 16h.01M18 8h.01M18 16h.01"></path>
                </svg>
                <span><?php _e('Control de Cajas y Auditoría de Turnos', 'emp-caja'); ?></span>
            </h1>
            <p class="caja-settings-subtitle"><?php _e('Supervisa en tiempo real las cajas abiertas en cada barra, consulta el historial de movimientos segundo a segundo y audita los arqueos de dinero.', 'emp-caja'); ?></p>
        </div>

        <div class="caja-settings-topbar-actions">
            <button type="button" class="caja-btn caja-btn-secondary" id="caja-reg-btn-refresh" title="<?php esc_attr_e('Refrescar datos', 'emp-caja'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 4v6h-6"></path>
                    <path d="M1 20v-6h6"></path>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
                <span><?php _e('Actualizar', 'emp-caja'); ?></span>
            </button>

            <button type="button" class="caja-btn caja-btn-secondary caja-btn-screen-switch" data-target="tab-pos-sale" title="<?php esc_attr_e('Ir a Venta Caja', 'emp-caja'); ?>">
                <span>🛒</span>
                <span><?php _e('Ir a Venta Caja', 'emp-caja'); ?></span>
            </button>
        </div>
    </div>

    <!-- Tarjetas de Métricas Rápidas -->
    <div class="caja-emp-stats-grid">
        <div class="caja-emp-stat-card">
            <div class="caja-emp-stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">🟢</div>
            <div class="caja-emp-stat-info">
                <span class="caja-emp-stat-value" id="caja-reg-stat-open">0</span>
                <span class="caja-emp-stat-label"><?php _e('Cajas Abiertas en Vivo', 'emp-caja'); ?></span>
            </div>
        </div>

        <div class="caja-emp-stat-card">
            <div class="caja-emp-stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">💵</div>
            <div class="caja-emp-stat-info">
                <span class="caja-emp-stat-value" id="caja-reg-stat-cash">$0,00</span>
                <span class="caja-emp-stat-label"><?php _e('Efectivo en Cajas', 'emp-caja'); ?></span>
            </div>
        </div>

        <div class="caja-emp-stat-card">
            <div class="caja-emp-stat-icon" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa;">💳</div>
            <div class="caja-emp-stat-info">
                <span class="caja-emp-stat-value" id="caja-reg-stat-digital">$0,00</span>
                <span class="caja-emp-stat-label"><?php _e('Cobros Tarjeta / Digitales', 'emp-caja'); ?></span>
            </div>
        </div>

        <div class="caja-emp-stat-card">
            <div class="caja-emp-stat-icon" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">📊</div>
            <div class="caja-emp-stat-info">
                <span class="caja-emp-stat-value" id="caja-reg-stat-total">$0,00</span>
                <span class="caja-emp-stat-label"><?php _e('Facturación Total Seleccionada', 'emp-caja'); ?></span>
            </div>
        </div>
    </div>

    <!-- Sección: Cajas Activas en Vivo (Monitoreo de Barras) -->
    <div class="caja-reg-section" style="margin-bottom: 24px;">
        <div class="caja-reg-section-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px;">
            <h2 style="font-size: 1.1rem; color: var(--caja-text, #f8fafc); margin: 0; display:flex; align-items:center; gap: 8px;">
                <span class="caja-pulse-dot" style="background:#10b981; width:10px; height:10px; border-radius:50%; box-shadow:0 0 10px #10b981; display:inline-block;"></span>
                <span><?php _e('Cajas Operando en Vivo', 'emp-caja'); ?></span>
            </h2>
            <span style="font-size:0.85rem; color:var(--caja-text-muted, #94a3b8);"><?php _e('Monitoreo en tiempo real de barras abiertas', 'emp-caja'); ?></span>
        </div>

        <div id="caja-reg-live-grid" class="caja-reg-live-grid">
            <!-- Se carga dinámicamente -->
            <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 24px; text-align: center; background: var(--caja-card-bg, #1e293b); border-radius: 8px; border: 1px dashed var(--caja-card-border, #334155); color: var(--caja-text-muted, #94a3b8);">
                <span>🔒 <?php _e('No hay cajas abiertas actualmente en ningún sector.', 'emp-caja'); ?></span>
            </div>
        </div>
    </div>

    <!-- Sección: Historial Completo y Auditoría de Cajas -->
    <div class="caja-reg-section">
        <div class="caja-reg-section-header" style="margin-bottom: 12px;">
            <h2 style="font-size: 1.1rem; color: var(--caja-text, #f8fafc); margin: 0; display:flex; align-items:center; gap: 8px;">
                <span>📜</span>
                <span><?php _e('Historial y Arqueo de Cajas por Sector', 'emp-caja'); ?></span>
            </h2>
        </div>

        <!-- Filtros de Auditoría -->
        <div class="caja-reg-filters-bar" style="background: var(--caja-card-bg, #1e293b); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <!-- Filtro Sector -->
            <div style="display:flex; flex-direction:column; gap:4px; min-width: 160px; flex: 1;">
                <label style="font-size: 0.75rem; color: var(--caja-text-muted, #94a3b8); text-transform: uppercase; font-weight:600;"><?php _e('Barra / Sector', 'emp-caja'); ?></label>
                <select id="caja-reg-filter-sector" class="caja-input" style="padding: 6px 10px; font-size: 0.85rem;">
                    <option value="all"><?php _e('Todos los sectores', 'emp-caja'); ?></option>
                    <?php foreach ($all_sectors as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filtro Cajero -->
            <div style="display:flex; flex-direction:column; gap:4px; min-width: 160px; flex: 1;">
                <label style="font-size: 0.75rem; color: var(--caja-text-muted, #94a3b8); text-transform: uppercase; font-weight:600;"><?php _e('Cajero / Empleado', 'emp-caja'); ?></label>
                <select id="caja-reg-filter-user" class="caja-input" style="padding: 6px 10px; font-size: 0.85rem;">
                    <option value="all"><?php _e('Todos los empleados', 'emp-caja'); ?></option>
                    <?php foreach ($all_employees as $emp) : ?>
                        <option value="<?php echo esc_attr($emp['id']); ?>"><?php echo esc_html($emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filtro Estado -->
            <div style="display:flex; flex-direction:column; gap:4px; min-width: 130px;">
                <label style="font-size: 0.75rem; color: var(--caja-text-muted, #94a3b8); text-transform: uppercase; font-weight:600;"><?php _e('Estado', 'emp-caja'); ?></label>
                <select id="caja-reg-filter-status" class="caja-input" style="padding: 6px 10px; font-size: 0.85rem;">
                    <option value="all"><?php _e('Todas', 'emp-caja'); ?></option>
                    <option value="open">🟢 <?php _e('Abiertas', 'emp-caja'); ?></option>
                    <option value="closed">🔒 <?php _e('Cerradas', 'emp-caja'); ?></option>
                </select>
            </div>

            <!-- Filtro Período -->
            <div style="display:flex; flex-direction:column; gap:4px; min-width: 140px;">
                <label style="font-size: 0.75rem; color: var(--caja-text-muted, #94a3b8); text-transform: uppercase; font-weight:600;"><?php _e('Período', 'emp-caja'); ?></label>
                <select id="caja-reg-filter-preset" class="caja-input" style="padding: 6px 10px; font-size: 0.85rem;">
                    <option value="today" selected><?php _e('Hoy', 'emp-caja'); ?></option>
                    <option value="yesterday"><?php _e('Ayer', 'emp-caja'); ?></option>
                    <option value="week"><?php _e('Últimos 7 días', 'emp-caja'); ?></option>
                    <option value="all"><?php _e('Histórico completo', 'emp-caja'); ?></option>
                    <option value="custom"><?php _e('Personalizado', 'emp-caja'); ?></option>
                </select>
            </div>

            <!-- Rango Fechas Personalizado -->
            <div id="caja-reg-custom-dates" style="display:none; align-items:center; gap:6px;">
                <input type="date" id="caja-reg-filter-from" class="caja-input" style="padding: 6px 8px; font-size: 0.8rem;" />
                <span style="color:var(--caja-text-muted);">-</span>
                <input type="date" id="caja-reg-filter-to" class="caja-input" style="padding: 6px 8px; font-size: 0.8rem;" />
            </div>

            <!-- Botón Buscar -->
            <div style="align-self: flex-end;">
                <button type="button" class="caja-btn caja-btn-primary" id="caja-reg-btn-apply-filters" style="padding: 7px 16px; font-size: 0.85rem;">
                    <span>🔍</span>
                    <span><?php _e('Filtrar', 'emp-caja'); ?></span>
                </button>
            </div>
        </div>

        <!-- Tabla de Sesiones -->
        <div class="caja-emp-table-wrapper" style="background: var(--caja-card-bg, #1e293b); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; overflow-x: auto;">
            <table class="caja-emp-table" id="caja-reg-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--caja-card-border, #334155); background: rgba(0,0,0,0.15);">
                        <th style="padding: 12px 14px;"><?php _e('Turno / Código', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px;"><?php _e('Caja / Sector', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px;"><?php _e('Cajero', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px;"><?php _e('Apertura', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px;"><?php _e('Cierre', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px; text-align:right;"><?php _e('Fondo Inicial', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px; text-align:right;"><?php _e('Efectivo', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px; text-align:right;"><?php _e('Digital', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px; text-align:right;"><?php _e('Total Venta', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px; text-align:right;"><?php _e('Arqueo Contado', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px; text-align:center;"><?php _e('Diferencia', 'emp-caja'); ?></th>
                        <th style="padding: 12px 14px; text-align:center;"><?php _e('Acciones', 'emp-caja'); ?></th>
                    </tr>
                </thead>
                <tbody id="caja-reg-table-body">
                    <tr>
                        <td colspan="12" style="padding: 30px; text-align: center; color: var(--caja-text-muted, #94a3b8);">
                            <span class="caja-spinner">⏳</span> <?php _e('Cargando registros de cajas...', 'emp-caja'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Detalle de Movimientos y Horarios por Caja (Auditoría) -->
<div id="caja-modal-session-movements" class="caja-modal" style="display:none; z-index: 100060;">
    <div class="caja-modal-backdrop"></div>
    <div class="caja-modal-dialog" style="max-width: 900px; width: 95%;">
        <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.5rem;">⏱️</span>
                <div>
                    <h3 class="caja-modal-title" id="caja-mov-title" style="margin: 0; font-size: 1.2rem;"><?php _e('Movimientos y Horarios de Caja', 'emp-caja'); ?></h3>
                    <p id="caja-mov-subtitle" style="margin: 2px 0 0 0; font-size: 0.85rem; color: var(--caja-text-muted, #94a3b8);"></p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm" id="caja-mov-print-btn" title="<?php esc_attr_e('Imprimir Comprobante de Arqueo / Z', 'emp-caja'); ?>">
                    <span>🖨️</span>
                    <span><?php _e('Imprimir Cierre Z', 'emp-caja'); ?></span>
                </button>
                <button type="button" class="caja-modal-close" id="caja-mov-close-btn" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>
        </div>

        <div class="caja-modal-body" style="padding: 16px; max-height: 75vh; overflow-y: auto;">
            <!-- Tarjeta Resumen de la Sesión -->
            <div id="caja-mov-summary-card" style="background: rgba(0,0,0,0.25); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
                <!-- Dinámico -->
            </div>

            <!-- Desglose de Billetes Contados (si la caja fue cerrada) -->
            <div id="caja-mov-breakdown-card" style="display:none; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                <strong style="display:block; font-size:0.85rem; color:#10b981; margin-bottom:6px;">💵 <?php _e('Arqueo de Efectivo Físico por Billetes:', 'emp-caja'); ?></strong>
                <div id="caja-mov-breakdown-chips" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
            </div>

            <!-- Tabla de Movimientos Horarios -->
            <div style="margin-top: 10px;">
                <h4 style="font-size: 0.95rem; color: var(--caja-text, #f8fafc); margin: 0 0 10px 0; display:flex; align-items:center; gap: 6px;">
                    <span>🧾</span>
                    <span><?php _e('Ventas y Cobros Realizados (Orden Cronológico):', 'emp-caja'); ?></span>
                    <span id="caja-mov-count-badge" class="caja-pos-cart-badge-count" style="font-size:0.75rem; padding:1px 6px;">0</span>
                </h4>

                <div class="caja-emp-table-wrapper" style="border: 1px solid var(--caja-card-border, #334155); border-radius: 6px; overflow-x: auto;">
                    <table class="caja-emp-table" style="width:100%; border-collapse:collapse; font-size:0.84rem;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--caja-card-border, #334155); background: rgba(0,0,0,0.2);">
                                <th style="padding: 10px;"><?php _e('Horario', 'emp-caja'); ?></th>
                                <th style="padding: 10px;"><?php _e('Pedido', 'emp-caja'); ?></th>
                                <th style="padding: 10px;"><?php _e('Sector / Modo', 'emp-caja'); ?></th>
                                <th style="padding: 10px;"><?php _e('Productos Vendidos', 'emp-caja'); ?></th>
                                <th style="padding: 10px;"><?php _e('Medio de Pago', 'emp-caja'); ?></th>
                                <th style="padding: 10px; text-align:right;"><?php _e('Importe', 'emp-caja'); ?></th>
                                <th style="padding: 10px; text-align:right;"><?php _e('Propina', 'emp-caja'); ?></th>
                                <th style="padding: 10px; text-align:right;"><?php _e('Efectivo en Caja', 'emp-caja'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="caja-mov-table-body">
                            <!-- Dinámico -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
