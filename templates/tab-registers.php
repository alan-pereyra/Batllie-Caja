<?php
/**
 * Template de la Pantalla "Control de Cajas" (Administrador)
 * Supervisión en tiempo real de cajas, historial de turnos y arqueos con horarios exactos.
 */

if (!defined('ABSPATH')) {
    exit;
}

$user_id = get_current_user_id();
$can_manage_registers = class_exists('Batllie_Caja_Employees') 
    ? Batllie_Caja_Employees::can_manage_registers($user_id) 
    : (current_user_can('manage_options') || current_user_can('administrator') || current_user_can('manage_woocommerce'));

if (!$can_manage_registers) {
    echo '<div class="caja-alert caja-alert-danger" style="margin:20px; padding:16px; background:#ef444422; border:1px solid #ef4444; border-radius:8px; color:#f87171;">' . __('Acceso restringido a Administradores y Encargados.', 'emp-caja') . '</div>';
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

    <!-- Sección: Cajas de Dinero y Asignación de Mostradores / Puestos -->
    <div class="caja-reg-section" style="margin-bottom: 28px;">
        <div class="caja-reg-section-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom: 14px;">
            <div>
                <h2 style="font-size: 1.15rem; color: var(--caja-text, #f8fafc); margin: 0; display:flex; align-items:center; gap: 8px;">
                    <span>💵</span>
                    <span><?php _e('Cajas de Dinero y Asignación a Mostradores', 'emp-caja'); ?></span>
                    <span id="caja-money-boxes-count" class="caja-pos-cart-badge-count" style="font-size:0.75rem; padding:1px 6px;">0</span>
                </h2>
                <p style="margin: 4px 0 0 0; font-size: 0.82rem; color: var(--caja-text-muted, #94a3b8);">
                    <?php _e('Cajas físicas de dinero vinculadas a uno o más puestos de venta. Recuerda que ningún mostrador puede estar asignado a más de una caja a la vez.', 'emp-caja'); ?>
                </p>
            </div>
            <button type="button" class="caja-btn caja-btn-primary" id="caja-btn-open-new-box-modal" style="padding: 8px 16px; font-size:0.86rem;">
                <span>➕</span>
                <span><?php _e('Nueva Caja de Dinero', 'emp-caja'); ?></span>
            </button>
        </div>

        <!-- Grilla de Cajas de Dinero -->
        <div id="caja-money-boxes-grid" class="caja-money-boxes-grid">
            <div class="caja-reg-empty" style="grid-column: 1 / -1; padding: 28px; text-align: center; background: var(--caja-card-bg, #1e293b); border-radius: 8px; border: 1px dashed var(--caja-card-border, #334155); color: var(--caja-text-muted, #94a3b8);">
                <span class="caja-spinner">⏳</span> <?php _e('Cargando cajas de dinero...', 'emp-caja'); ?>
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

        <!-- Filtros de Auditoría y Arqueo (Estilo 100% Ancho idéntico a pedidos en vivo) -->
        <div class="caja-reg-filters-bar">
            <!-- Filtro Caja de Dinero -->
            <div class="caja-reg-filter-field">
                <select id="caja-reg-filter-money-box" class="caja-select-filter">
                    <option value="all"><?php _e('💵 Todas las cajas de dinero', 'emp-caja'); ?></option>
                </select>
            </div>

            <!-- Filtro Sector -->
            <div class="caja-reg-filter-field">
                <select id="caja-reg-filter-sector" class="caja-select-filter">
                    <option value="all"><?php _e('📍 Todos los sectores / barras', 'emp-caja'); ?></option>
                    <?php foreach ($all_sectors as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html('📍 ' . $s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filtro Cajero -->
            <div class="caja-reg-filter-field">
                <select id="caja-reg-filter-user" class="caja-select-filter">
                    <option value="all"><?php _e('👤 Todos los cajeros / empleados', 'emp-caja'); ?></option>
                    <?php foreach ($all_employees as $emp) : ?>
                        <option value="<?php echo esc_attr($emp['id']); ?>"><?php echo esc_html('👤 ' . $emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filtro Estado -->
            <div class="caja-reg-filter-field">
                <select id="caja-reg-filter-status" class="caja-select-filter">
                    <option value="all"><?php _e('🔘 Todos los estados (abiertas y cerradas)', 'emp-caja'); ?></option>
                    <option value="open"><?php _e('🟢 Turnos abiertos', 'emp-caja'); ?></option>
                    <option value="closed"><?php _e('🔒 Turnos cerrados', 'emp-caja'); ?></option>
                </select>
            </div>

            <!-- Filtro Período -->
            <div class="caja-reg-filter-field">
                <select id="caja-reg-filter-preset" class="caja-select-filter">
                    <option value="today" selected><?php _e('📅 Período: Hoy', 'emp-caja'); ?></option>
                    <option value="yesterday"><?php _e('📅 Período: Ayer', 'emp-caja'); ?></option>
                    <option value="week"><?php _e('📅 Período: Últimos 7 días', 'emp-caja'); ?></option>
                    <option value="all"><?php _e('📅 Período: Histórico completo', 'emp-caja'); ?></option>
                    <option value="custom"><?php _e('📅 Período: Personalizado (Rango de fechas)', 'emp-caja'); ?></option>
                </select>
            </div>

            <!-- Rango Fechas Personalizado -->
            <div id="caja-reg-custom-dates" class="caja-reg-custom-dates" style="display:none;">
                <div class="caja-reg-filter-field">
                    <input type="date" id="caja-reg-filter-from" class="caja-select-filter caja-date-filter" title="<?php esc_attr_e('Fecha Desde', 'emp-caja'); ?>" />
                </div>
                <div class="caja-reg-filter-field">
                    <input type="date" id="caja-reg-filter-to" class="caja-select-filter caja-date-filter" title="<?php esc_attr_e('Fecha Hasta', 'emp-caja'); ?>" />
                </div>
            </div>

            <!-- Botón Buscar -->
            <div class="caja-reg-filter-field">
                <button type="button" class="caja-btn caja-btn-primary caja-reg-btn-filter" id="caja-reg-btn-apply-filters">
                    <span>🔍</span>
                    <span><?php _e('Filtrar Historial', 'emp-caja'); ?></span>
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

<!-- Modal: Crear / Editar Caja de Dinero -->
<div id="caja-modal-money-box" class="caja-modal" style="display:none; z-index: 100070;">
    <div class="caja-modal-backdrop"></div>
    <div class="caja-modal-dialog" style="max-width: 620px; width: 95%;">
        <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.5rem;">💵</span>
                <div>
                    <h3 class="caja-modal-title" id="caja-mb-modal-title" style="margin: 0; font-size: 1.15rem;"><?php _e('Nueva Caja de Dinero', 'emp-caja'); ?></h3>
                    <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: var(--caja-text-muted, #94a3b8);"><?php _e('Asigna los mostradores o puestos donde operará esta caja física.', 'emp-caja'); ?></p>
                </div>
            </div>
            <button type="button" class="caja-modal-close" id="caja-mb-modal-close" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
        </div>

        <form id="caja-money-box-form">
            <div class="caja-modal-body" style="padding: 18px 20px; max-height: 75vh; overflow-y: auto;">
                <input type="hidden" id="caja-mb-id" name="id" value="" />

                <!-- Mensaje de Alerta de Error -->
                <div id="caja-mb-alert-error" class="caja-alert caja-alert-danger" style="display:none; margin-bottom: 14px; padding: 10px 14px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 8px; color: #f87171; font-size: 0.86rem;"></div>

                <!-- Nombre y Código -->
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 5px;">
                            <?php _e('Nombre de la Caja de Dinero', 'emp-caja'); ?> <span style="color:#ef4444;">*</span>
                        </label>
                        <input type="text" id="caja-mb-name" name="name" class="caja-input" placeholder="<?php esc_attr_e('Ej: Caja Principal, Caja Mostrador 1', 'emp-caja'); ?>" required style="width:100%; padding: 8px 12px; font-size:0.9rem;" />
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 5px;">
                            <?php _e('Código / ID', 'emp-caja'); ?>
                        </label>
                        <input type="text" id="caja-mb-code" name="code" class="caja-input" placeholder="<?php esc_attr_e('Ej: CJ-01', 'emp-caja'); ?>" style="width:100%; padding: 8px 12px; font-size:0.9rem;" />
                    </div>
                </div>

                <!-- Descripción / Ubicación -->
                <div style="margin-bottom: 14px;">
                    <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 5px;">
                        <?php _e('Ubicación / Notas de la Caja', 'emp-caja'); ?>
                    </label>
                    <input type="text" id="caja-mb-notes" name="notes" class="caja-input" placeholder="<?php esc_attr_e('Ej: Cajón portabilletes bajo mostrador central', 'emp-caja'); ?>" style="width:100%; padding: 8px 12px; font-size:0.88rem;" />
                </div>

                <!-- Estado -->
                <div style="margin-bottom: 16px;">
                    <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 5px;">
                        <?php _e('Estado Operativo', 'emp-caja'); ?>
                    </label>
                    <select id="caja-mb-status" name="status" class="caja-input" style="width:100%; padding: 8px 12px; font-size:0.88rem;">
                        <option value="active"><?php _e('🟢 Activa (Habilitada para cobros y aperturas)', 'emp-caja'); ?></option>
                        <option value="inactive"><?php _e('⚪ Inactiva (En mantenimiento o fuera de servicio)', 'emp-caja'); ?></option>
                    </select>
                </div>

                <!-- Regla Explicativa -->
                <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 0.82rem; color: #93c5fd; display:flex; gap: 8px; align-items:flex-start;">
                    <span style="font-size: 1.1rem; flex-shrink: 0;">ℹ️</span>
                    <div>
                        <strong><?php _e('Regla de Asignación Exclusiva:', 'emp-caja'); ?></strong>
                        <?php _e('Una caja de dinero puede recibir dinero de uno o varios mostradores/puestos, pero <u>ningún mostrador puede estar vinculado a más de una caja de dinero</u>. Si un puesto ya pertenece a otra caja, aparecerá bloqueado.', 'emp-caja'); ?>
                    </div>
                </div>

                <!-- Asignación de Mostradores / Puestos -->
                <div>
                    <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.82rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 8px;">
                        <span><?php _e('Mostradores / Puestos Asignados:', 'emp-caja'); ?></span>
                        <span style="font-size:0.75rem; color:var(--caja-text-muted, #94a3b8); font-weight:normal;"><?php _e('Marca los puestos que depositarán en esta caja', 'emp-caja'); ?></span>
                    </label>

                    <div id="caja-mb-puestos-container" class="caja-mb-puestos-container" style="display:flex; flex-direction:column; gap:8px; background: rgba(0,0,0,0.22); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 12px; max-height: 200px; overflow-y: auto;">
                        <!-- Se llena dinámicamente -->
                    </div>

                    <!-- Agregar nuevo mostrador al vuelo -->
                    <div style="display:flex; gap: 8px; margin-top: 10px; align-items:center;">
                        <input type="text" id="caja-mb-new-puesto-name" class="caja-input" placeholder="<?php esc_attr_e('Agregar nuevo puesto o mostrador (ej: Mostrador 3)...', 'emp-caja'); ?>" style="flex:1; padding: 6px 10px; font-size: 0.82rem;" />
                        <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm" id="caja-mb-btn-add-new-puesto" style="padding: 6px 12px; font-size: 0.8rem; white-space:nowrap;">
                            <span>➕</span>
                            <span><?php _e('Agregar Puesto', 'emp-caja'); ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="caja-modal-footer" style="padding: 14px 20px; border-top: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="caja-btn caja-btn-secondary" id="caja-mb-btn-cancel">
                    <?php _e('Cancelar', 'emp-caja'); ?>
                </button>
                <button type="submit" class="caja-btn caja-btn-primary" id="caja-mb-btn-save">
                    <span>💾</span>
                    <span><?php _e('Guardar Caja de Dinero', 'emp-caja'); ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Controlar Dinero en Caja (Ingreso / Retiro / Arqueo) -->
<div id="caja-modal-cash-control" class="caja-modal" style="display:none; z-index: 100080;">
    <div class="caja-modal-backdrop"></div>
    <div class="caja-modal-dialog" style="max-width: 540px; width: 95%;">
        <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.5rem;" id="caja-ctrl-icon">💵</span>
                <div>
                    <h3 class="caja-modal-title" id="caja-ctrl-modal-title" style="margin: 0; font-size: 1.2rem;"><?php _e('Control de Dinero en Caja', 'emp-caja'); ?></h3>
                    <p id="caja-ctrl-modal-subtitle" style="margin: 2px 0 0 0; font-size: 0.82rem; color: var(--caja-text-muted, #94a3b8);"></p>
                </div>
            </div>
            <button type="button" class="caja-modal-close" id="caja-ctrl-modal-close" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
        </div>

        <form id="caja-cash-control-form">
            <input type="hidden" id="caja-ctrl-box-id" name="cash_box_id" value="" />
            <input type="hidden" id="caja-ctrl-current-balance-raw" value="0" />

            <div class="caja-modal-body" style="padding: 20px;">
                <div id="caja-ctrl-alert-error" class="caja-alert caja-alert-danger" style="display:none; margin-bottom: 14px; padding: 10px 14px; font-size:0.86rem;"></div>

                <!-- Resumen de Saldo Actual -->
                <div style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span style="font-size: 0.72rem; text-transform:uppercase; font-weight:700; color:var(--caja-text-muted, #94a3b8); display:block;"><?php _e('Efectivo Actual en Caja:', 'emp-caja'); ?></span>
                        <strong id="caja-ctrl-current-balance-display" style="font-size: 1.35rem; color:#10b981;">$0,00</strong>
                    </div>
                    <div id="caja-ctrl-session-badge-wrapper" style="text-align:right;">
                        <!-- Insignia si hay turno abierto -->
                    </div>
                </div>

                <!-- Selector de Operación (Pills) -->
                <label style="display:block; font-size:0.8rem; font-weight:700; color:var(--caja-text, #f8fafc); margin-bottom: 8px; text-transform:uppercase; letter-spacing:0.4px;">
                    <?php _e('Tipo de Operación:', 'emp-caja'); ?>
                </label>
                <div class="caja-ctrl-type-selector">
                    <button type="button" class="caja-ctrl-type-btn active type-cash_in" data-type="cash_in">
                        <span style="font-size: 1.25rem;">📥</span>
                        <strong style="font-size:0.86rem;"><?php _e('Ingreso', 'emp-caja'); ?></strong>
                        <span style="font-size:0.72rem; opacity:0.8;"><?php _e('Cambio / Aporte', 'emp-caja'); ?></span>
                    </button>
                    <button type="button" class="caja-ctrl-type-btn type-cash_out" data-type="cash_out">
                        <span style="font-size: 1.25rem;">📤</span>
                        <strong style="font-size:0.86rem;"><?php _e('Retiro', 'emp-caja'); ?></strong>
                        <span style="font-size:0.72rem; opacity:0.8;"><?php _e('Tesorería / Pagos', 'emp-caja'); ?></span>
                    </button>
                    <button type="button" class="caja-ctrl-type-btn type-adjustment" data-type="adjustment">
                        <span style="font-size: 1.25rem;">⚖️</span>
                        <strong style="font-size:0.86rem;"><?php _e('Arqueo / Ajuste', 'emp-caja'); ?></strong>
                        <span style="font-size:0.72rem; opacity:0.8;"><?php _e('Conteo físico auditado', 'emp-caja'); ?></span>
                    </button>
                </div>
                <input type="hidden" id="caja-ctrl-selected-type" name="type" value="cash_in" />

                <!-- Importe -->
                <div style="margin-bottom: 14px;">
                    <label id="caja-ctrl-amount-label" style="display:block; font-size:0.82rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 5px;">
                        <?php _e('Importe a Ingresar ($):', 'emp-caja'); ?>
                    </label>
                    <input type="number" step="0.01" min="0" id="caja-ctrl-amount" name="amount" class="caja-input" placeholder="0.00" style="width:100%; padding: 10px 14px; font-size:1.15rem; font-weight:700;" required />
                    
                    <!-- Presets rápidos -->
                    <div class="caja-ctrl-presets" id="caja-ctrl-presets-row">
                        <button type="button" class="caja-ctrl-preset-btn" data-val="500">+ $500</button>
                        <button type="button" class="caja-ctrl-preset-btn" data-val="1000">+ $1.000</button>
                        <button type="button" class="caja-ctrl-preset-btn" data-val="2000">+ $2.000</button>
                        <button type="button" class="caja-ctrl-preset-btn" data-val="5000">+ $5.000</button>
                        <button type="button" class="caja-ctrl-preset-btn" data-val="10000">+ $10.000</button>
                        <button type="button" class="caja-ctrl-preset-btn" data-val="20000">+ $20.000</button>
                    </div>
                </div>

                <!-- Motivo / Concepto -->
                <div style="margin-bottom: 14px;">
                    <label style="display:block; font-size:0.82rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 5px;">
                        <?php _e('Motivo / Concepto (* Obligatorio):', 'emp-caja'); ?>
                    </label>
                    <input type="text" id="caja-ctrl-concept" name="concept" class="caja-input" placeholder="<?php esc_attr_e('Ej: Aporte de cambio en billetes de $500', 'emp-caja'); ?>" style="width:100%; padding: 8px 12px; font-size:0.88rem;" required />
                </div>

                <!-- Observaciones -->
                <div style="margin-bottom: 14px;">
                    <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--caja-text, #f8fafc); margin-bottom: 5px;">
                        <?php _e('Observaciones / Notas adicionales:', 'emp-caja'); ?>
                    </label>
                    <textarea id="caja-ctrl-notes" name="notes" class="caja-input" rows="2" placeholder="<?php esc_attr_e('Detalles adicionales para auditoría...', 'emp-caja'); ?>" style="width:100%; padding: 8px 12px; font-size:0.85rem;"></textarea>
                </div>

                <!-- Previsualización Dinámica de Nuevo Saldo -->
                <div class="caja-ctrl-preview" id="caja-ctrl-preview-box">
                    <div>
                        <span style="font-size:0.75rem; color:var(--caja-text-muted, #94a3b8); display:block;"><?php _e('Nuevo saldo estimado en caja:', 'emp-caja'); ?></span>
                        <strong id="caja-ctrl-preview-balance" style="font-size:1.15rem; color:#34d399;">$0,00</strong>
                    </div>
                    <span id="caja-ctrl-preview-flow" style="font-size:0.85rem; font-weight:700; color:#10b981;">+ $0,00</span>
                </div>
            </div>

            <div class="caja-modal-footer" style="padding: 14px 20px; border-top: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="caja-btn caja-btn-secondary" id="caja-ctrl-btn-cancel">
                    <?php _e('Cancelar', 'emp-caja'); ?>
                </button>
                <button type="submit" class="caja-btn caja-btn-primary" id="caja-ctrl-btn-save">
                    <span>💾</span>
                    <span id="caja-ctrl-save-btn-text"><?php _e('Confirmar Movimiento', 'emp-caja'); ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Historial Detallado de Movimientos de la Caja -->
<div id="caja-modal-box-movements" class="caja-modal" style="display:none; z-index: 100080;">
    <div class="caja-modal-backdrop"></div>
    <div class="caja-modal-dialog" style="max-width: 960px; width: 95%;">
        <div class="caja-modal-header" style="border-bottom: 1px solid var(--caja-card-border, #334155); display:flex; justify-content:space-between; align-items:center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.5rem;">📜</span>
                <div>
                    <h3 class="caja-modal-title" id="caja-bmov-title" style="margin: 0; font-size: 1.2rem;"><?php _e('Historial de Movimientos de la Caja', 'emp-caja'); ?></h3>
                    <p id="caja-bmov-subtitle" style="margin: 2px 0 0 0; font-size: 0.82rem; color: var(--caja-text-muted, #94a3b8);"></p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm" id="caja-bmov-btn-print" title="<?php esc_attr_e('Imprimir Reporte de Movimientos', 'emp-caja'); ?>">
                    <span>🖨️</span>
                    <span><?php _e('Imprimir', 'emp-caja'); ?></span>
                </button>
                <button type="button" class="caja-modal-close" id="caja-bmov-modal-close" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>
        </div>

        <div class="caja-modal-body" style="padding: 18px; max-height: 75vh; overflow-y: auto;">
            <!-- Tarjetas de Totales del Período -->
            <div class="caja-emp-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); margin-bottom: 16px;">
                <div class="caja-emp-stat-card" style="padding: 10px 14px;">
                    <div class="caja-emp-stat-info">
                        <span class="caja-emp-stat-value" id="caja-bmov-stat-current" style="font-size: 1.2rem; color:#10b981;">$0,00</span>
                        <span class="caja-emp-stat-label"><?php _e('Saldo Efectivo Actual', 'emp-caja'); ?></span>
                    </div>
                </div>
                <div class="caja-emp-stat-card" style="padding: 10px 14px;">
                    <div class="caja-emp-stat-info">
                        <span class="caja-emp-stat-value" id="caja-bmov-stat-inflow" style="font-size: 1.2rem; color:#34d399;">$0,00</span>
                        <span class="caja-emp-stat-label"><?php _e('Ingresos de Efectivo', 'emp-caja'); ?></span>
                    </div>
                </div>
                <div class="caja-emp-stat-card" style="padding: 10px 14px;">
                    <div class="caja-emp-stat-info">
                        <span class="caja-emp-stat-value" id="caja-bmov-stat-outflow" style="font-size: 1.2rem; color:#f87171;">$0,00</span>
                        <span class="caja-emp-stat-label"><?php _e('Retiros de Efectivo', 'emp-caja'); ?></span>
                    </div>
                </div>
                <div class="caja-emp-stat-card" style="padding: 10px 14px;">
                    <div class="caja-emp-stat-info">
                        <span class="caja-emp-stat-value" id="caja-bmov-stat-sales" style="font-size: 1.2rem; color:#60a5fa;">$0,00</span>
                        <span class="caja-emp-stat-label"><?php _e('Ventas Cobradas', 'emp-caja'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Barra de Filtros del Modal -->
            <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--caja-card-border, #334155); border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <!-- Filtro Tipo -->
                <div style="display:flex; flex-direction:column; gap:2px; min-width: 140px; flex: 1;">
                    <label style="font-size:0.72rem; color:var(--caja-text-muted); font-weight:600; text-transform:uppercase;"><?php _e('Tipo de Movimiento', 'emp-caja'); ?></label>
                    <select id="caja-bmov-filter-type" class="caja-input" style="padding: 5px 8px; font-size: 0.82rem;">
                        <option value="all"><?php _e('Todos los tipos', 'emp-caja'); ?></option>
                        <option value="cash_in">📥 <?php _e('Solo Ingresos de Dinero', 'emp-caja'); ?></option>
                        <option value="cash_out">📤 <?php _e('Solo Retiros / Sangrías', 'emp-caja'); ?></option>
                        <option value="sale">🛒 <?php _e('Solo Ventas', 'emp-caja'); ?></option>
                        <option value="opening">🟢 <?php _e('Aperturas de Turno', 'emp-caja'); ?></option>
                        <option value="closing">🔒 <?php _e('Cierres y Arqueos', 'emp-caja'); ?></option>
                        <option value="adjustment">⚖️ <?php _e('Ajustes de Auditoría', 'emp-caja'); ?></option>
                    </select>
                </div>

                <!-- Filtro Período -->
                <div style="display:flex; flex-direction:column; gap:2px; min-width: 130px;">
                    <label style="font-size:0.72rem; color:var(--caja-text-muted); font-weight:600; text-transform:uppercase;"><?php _e('Período', 'emp-caja'); ?></label>
                    <select id="caja-bmov-filter-preset" class="caja-input" style="padding: 5px 8px; font-size: 0.82rem;">
                        <option value="today" selected><?php _e('Hoy', 'emp-caja'); ?></option>
                        <option value="yesterday"><?php _e('Ayer', 'emp-caja'); ?></option>
                        <option value="week"><?php _e('Últimos 7 días', 'emp-caja'); ?></option>
                        <option value="all"><?php _e('Histórico completo', 'emp-caja'); ?></option>
                    </select>
                </div>

                <div style="align-self: flex-end;">
                    <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm" id="caja-bmov-btn-reload" style="padding: 6px 12px; font-size: 0.82rem;">
                        <span>🔄</span> <span><?php _e('Actualizar', 'emp-caja'); ?></span>
                    </button>
                </div>
            </div>

            <!-- Tabla de Movimientos de la Caja -->
            <div class="caja-emp-table-wrapper" style="border: 1px solid var(--caja-card-border, #334155); border-radius: 6px; overflow-x: auto;">
                <table class="caja-emp-table" id="caja-bmov-table" style="width:100%; border-collapse:collapse; font-size:0.84rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--caja-card-border, #334155); background: rgba(0,0,0,0.25);">
                            <th style="padding: 10px;"><?php _e('Horario', 'emp-caja'); ?></th>
                            <th style="padding: 10px;"><?php _e('Tipo', 'emp-caja'); ?></th>
                            <th style="padding: 10px;"><?php _e('Concepto / Motivo', 'emp-caja'); ?></th>
                            <th style="padding: 10px;"><?php _e('Usuario', 'emp-caja'); ?></th>
                            <th style="padding: 10px;"><?php _e('Medio de Pago', 'emp-caja'); ?></th>
                            <th style="padding: 10px; text-align:right;"><?php _e('Monto', 'emp-caja'); ?></th>
                            <th style="padding: 10px; text-align:right;"><?php _e('Impacto Efectivo', 'emp-caja'); ?></th>
                            <th style="padding: 10px; text-align:right;"><?php _e('Saldo en Caja', 'emp-caja'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="caja-bmov-table-body">
                        <tr>
                            <td colspan="8" style="padding: 24px; text-align: center; color: var(--caja-text-muted, #94a3b8);">
                                <span class="caja-spinner">⏳</span> <?php _e('Cargando movimientos...', 'emp-caja'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
