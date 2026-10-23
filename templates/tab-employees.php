<?php
/**
 * Template de la Pantalla "Configuración Empleados"
 * Solo accesible para Administradores
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options') && !current_user_can('administrator')) {
    echo '<div class="caja-alert caja-alert-danger" style="margin:20px; padding:16px; background:#ef444422; border:1px solid #ef4444; border-radius:8px; color:#f87171;">' . __('Acceso restringido únicamente a administradores.', 'emp-caja') . '</div>';
    return;
}

$all_sectors = class_exists('Batllie_Caja_Employees') ? Batllie_Caja_Employees::get_available_sectors() : array('Barra Principal', 'Barra VIP', 'Terraza', 'Boletería / Entrada');
?>

<div class="caja-employees-container">
    <!-- Barra Superior de Configuración de Empleados -->
    <div class="caja-settings-topbar">
        <div class="caja-settings-title-group">
            <h1 class="caja-settings-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <span><?php _e('Configuración Empleados y Permisos', 'emp-caja'); ?></span>
            </h1>
            <p class="caja-settings-subtitle"><?php _e('Administra los usuarios de tu equipo, asigna barras específicas a cada cajero y define qué pantallas pueden visualizar.', 'emp-caja'); ?></p>
        </div>

        <div class="caja-settings-topbar-actions">
            <button type="button" class="caja-btn caja-btn-secondary caja-btn-screen-switch" data-target="tab-pos-sale" title="<?php esc_attr_e('Ir a Venta Caja', 'emp-caja'); ?>">
                <span>🛒</span>
                <span><?php _e('Ir a Venta Caja', 'emp-caja'); ?></span>
            </button>

            <button type="button" class="caja-btn caja-btn-primary" id="caja-emp-btn-add">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span><?php _e('Nuevo Empleado', 'emp-caja'); ?></span>
            </button>
        </div>
    </div>

    <!-- Tarjetas de Métricas Rápidas -->
    <div class="caja-emp-stats-grid">
        <div class="caja-emp-stat-card">
            <div class="caja-emp-stat-icon" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa;">👥</div>
            <div class="caja-emp-stat-info">
                <span class="caja-emp-stat-value" id="caja-emp-stat-total">0</span>
                <span class="caja-emp-stat-label"><?php _e('Empleados Registrados', 'emp-caja'); ?></span>
            </div>
        </div>

        <div class="caja-emp-stat-card">
            <div class="caja-emp-stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">🍸</div>
            <div class="caja-emp-stat-info">
                <span class="caja-emp-stat-value" id="caja-emp-stat-cajeros">0</span>
                <span class="caja-emp-stat-label"><?php _e('Cajeros Activos', 'emp-caja'); ?></span>
            </div>
        </div>

        <div class="caja-emp-stat-card">
            <div class="caja-emp-stat-icon" style="background: rgba(168, 85, 247, 0.15); color: #c084fc;">📍</div>
            <div class="caja-emp-stat-info">
                <span class="caja-emp-stat-value"><?php echo count($all_sectors); ?></span>
                <span class="caja-emp-stat-label"><?php _e('Barras / Sectores Disponibles', 'emp-caja'); ?></span>
            </div>
        </div>
    </div>

    <!-- Tabla de Empleados -->
    <div class="caja-card caja-emp-table-card">
        <div class="caja-card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:1.2rem;">📋</span>
                <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--caja-text, #f8fafc);"><?php _e('Lista de Personal y Roles', 'emp-caja'); ?></h3>
            </div>
            <button type="button" class="caja-btn caja-btn-secondary caja-btn-sm" id="caja-emp-btn-refresh" title="<?php esc_attr_e('Actualizar lista', 'emp-caja'); ?>">
                <span>🔄</span>
                <span><?php _e('Actualizar', 'emp-caja'); ?></span>
            </button>
        </div>

        <div class="caja-emp-table-responsive">
            <table class="caja-emp-table" id="caja-emp-table">
                <thead>
                    <tr>
                        <th><?php _e('Empleado', 'emp-caja'); ?></th>
                        <th><?php _e('Rol', 'emp-caja'); ?></th>
                        <th><?php _e('Barras Asignadas', 'emp-caja'); ?></th>
                        <th><?php _e('Permisos de Visualización', 'emp-caja'); ?></th>
                        <th style="text-align:center;"><?php _e('Estado', 'emp-caja'); ?></th>
                        <th style="text-align:right;"><?php _e('Acciones', 'emp-caja'); ?></th>
                    </tr>
                </thead>
                <tbody id="caja-emp-tbody">
                    <tr>
                        <td colspan="6" style="text-align:center; padding:40px; color:var(--caja-text-muted, #94a3b8);">
                            <span class="caja-spinner">⏳</span> <?php _e('Cargando empleados...', 'emp-caja'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Crear / Editar Empleado -->
<div id="caja-modal-employee" class="caja-modal" style="display:none; z-index: 100060;">
    <div class="caja-modal-backdrop" id="caja-emp-modal-backdrop"></div>
    <div class="caja-modal-dialog caja-emp-modal-dialog">
        <div class="caja-modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:1.4rem;" id="caja-emp-modal-icon">👤</span>
                <div>
                    <h3 class="caja-modal-title" id="caja-emp-modal-title"><?php _e('Nuevo Empleado', 'emp-caja'); ?></h3>
                    <p style="margin:2px 0 0 0; font-size:0.82rem; color:var(--caja-text-muted, #94a3b8);" id="caja-emp-modal-sub"><?php _e('Configura los accesos, barra asignada y permisos', 'emp-caja'); ?></p>
                </div>
            </div>
            <button type="button" class="caja-modal-close" id="caja-emp-modal-close">&times;</button>
        </div>

        <form id="caja-emp-form">
            <input type="hidden" id="caja-emp-id" value="0" />

            <div class="caja-modal-body" style="padding: 20px; max-height: 75vh; overflow-y: auto;">
                <!-- Bloque 1: Datos de Acceso -->
                <div class="caja-emp-form-section">
                    <h4 class="caja-emp-section-title">
                        <span>🔑</span> <?php _e('Datos de Acceso', 'emp-caja'); ?>
                    </h4>

                    <div class="caja-emp-form-grid">
                        <div class="caja-emp-form-field">
                            <label for="caja-emp-name"><?php _e('Nombre y Apellido', 'emp-caja'); ?> *</label>
                            <input type="text" id="caja-emp-name" class="caja-input" placeholder="Ej: Lucas Benítez" required autocomplete="off" />
                        </div>

                        <div class="caja-emp-form-field">
                            <label for="caja-emp-username"><?php _e('Nombre de Usuario (Login)', 'emp-caja'); ?> *</label>
                            <input type="text" id="caja-emp-username" class="caja-input" placeholder="Ej: lucas.barra1" required autocomplete="off" />
                            <small class="caja-help-text"><?php _e('Usuario para ingresar a la caja POS.', 'emp-caja'); ?></small>
                        </div>

                        <div class="caja-emp-form-field">
                            <label for="caja-emp-email"><?php _e('Correo Electrónico', 'emp-caja'); ?></label>
                            <input type="email" id="caja-emp-email" class="caja-input" placeholder="lucas@boliche.com" autocomplete="off" />
                        </div>

                        <div class="caja-emp-form-field">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <label for="caja-emp-password"><?php _e('Contraseña', 'emp-caja'); ?> <span id="caja-emp-pass-req">*</span></label>
                                <button type="button" class="caja-emp-btn-gen-pass" id="caja-emp-btn-gen-pass" title="<?php esc_attr_e('Generar contraseña aleatoria', 'emp-caja'); ?>">
                                    ⚡ <?php _e('Generar', 'emp-caja'); ?>
                                </button>
                            </div>
                            <div style="position:relative;">
                                <input type="text" id="caja-emp-password" class="caja-input" placeholder="Contraseña de acceso" autocomplete="new-password" />
                            </div>
                            <small class="caja-help-text" id="caja-emp-pass-help" style="display:none;"><?php _e('Dejá en blanco si no querés cambiar la contraseña actual.', 'emp-caja'); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Bloque 2: Rol del Empleado -->
                <div class="caja-emp-form-section" style="margin-top:20px;">
                    <h4 class="caja-emp-section-title">
                        <span>🎭</span> <?php _e('Rol en el Boliche / Local', 'emp-caja'); ?>
                    </h4>

                    <div class="caja-emp-roles-selector">
                        <label class="caja-emp-role-card">
                            <input type="radio" name="caja_emp_role_radio" value="caja_cajero" checked />
                            <div class="caja-emp-role-card-inner">
                                <span class="caja-emp-role-icon">🍸</span>
                                <div class="caja-emp-role-text">
                                    <strong><?php _e('Cajero', 'emp-caja'); ?></strong>
                                    <p><?php _e('Opera cobros en su(s) barra(s) asignada(s). Sin acceso a mesas.', 'emp-caja'); ?></p>
                                </div>
                            </div>
                        </label>

                        <label class="caja-emp-role-card">
                            <input type="radio" name="caja_emp_role_radio" value="caja_encargado" />
                            <div class="caja-emp-role-card-inner">
                                <span class="caja-emp-role-icon">📋</span>
                                <div class="caja-emp-role-text">
                                    <strong><?php _e('Encargado / Supervisor', 'emp-caja'); ?></strong>
                                    <p><?php _e('Monitorea pedidos, comandas y ventas generales.', 'emp-caja'); ?></p>
                                </div>
                            </div>
                        </label>

                        <label class="caja-emp-role-card">
                            <input type="radio" name="caja_emp_role_radio" value="administrator" />
                            <div class="caja-emp-role-card-inner">
                                <span class="caja-emp-role-icon">👑</span>
                                <div class="caja-emp-role-text">
                                    <strong><?php _e('Administrador', 'emp-caja'); ?></strong>
                                    <p><?php _e('Acceso total a configuración, productos y cambio entre mesas y barras.', 'emp-caja'); ?></p>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Bloque 3: Asignación de Barras / Sectores -->
                <div class="caja-emp-form-section" style="margin-top:20px;" id="caja-emp-sectors-section">
                    <h4 class="caja-emp-section-title">
                        <span>📍</span> <?php _e('Barras / Sectores Asignados', 'emp-caja'); ?>
                    </h4>
                    <p style="font-size:0.83rem; color:var(--caja-text-muted, #94a3b8); margin:-4px 0 12px 0;">
                        <?php _e('Selecciona la o las barras donde este empleado podrá cobrar ventas. Si se selecciona sólo una, quedará fija.', 'emp-caja'); ?>
                    </p>

                    <div class="caja-emp-sectors-checklist" id="caja-emp-sectors-checklist">
                        <?php foreach ($all_sectors as $sector): ?>
                            <label class="caja-emp-sector-item">
                                <input type="checkbox" name="caja_emp_assigned_sectors[]" value="<?php echo esc_attr($sector); ?>" />
                                <span class="caja-emp-sector-label">
                                    <?php
                                    $icon = '🍸';
                                    if (stripos($sector, 'vip') !== false) $icon = '⭐';
                                    elseif (stripos($sector, 'terraza') !== false) $icon = '🌿';
                                    elseif (stripos($sector, 'boleteria') !== false || stripos($sector, 'entrada') !== false) $icon = '🎟️';
                                    elseif (stripos($sector, 'pista') !== false) $icon = '🎵';
                                    elseif (stripos($sector, 'take') !== false) $icon = '🛍️';
                                    echo $icon . ' ' . esc_html($sector);
                                    ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Bloque 4: Permisos de Visualización y Operación -->
                <div class="caja-emp-form-section" style="margin-top:20px;">
                    <h4 class="caja-emp-section-title">
                        <span>🛡️</span> <?php _e('Permisos de Visualización y Operación', 'emp-caja'); ?>
                    </h4>

                    <div class="caja-emp-permissions-list">
                        <!-- Permiso A: Ver Control de Productos -->
                        <div class="caja-emp-perm-row">
                            <div class="caja-emp-perm-info">
                                <strong><?php _e('¿Puede ver y modificar productos (Control de Productos)?', 'emp-caja'); ?></strong>
                                <p><?php _e('Si se desactiva, se ocultará la pestaña de Productos y sólo quedará habilitada la pantalla de Venta Caja.', 'emp-caja'); ?></p>
                            </div>
                            <label class="caja-switch-ios">
                                <input type="checkbox" id="caja-emp-can-view-products" />
                                <span class="caja-switch-slider"></span>
                            </label>
                        </div>

                        <!-- Permiso B: Ver Pedidos en Vivo -->
                        <div class="caja-emp-perm-row">
                            <div class="caja-emp-perm-info">
                                <strong><?php _e('¿Puede ver el monitor de pedidos en vivo y comandas?', 'emp-caja'); ?></strong>
                                <p><?php _e('Permite visualizar la pantalla con el flujo de pedidos entrantes y estados de entrega.', 'emp-caja'); ?></p>
                            </div>
                            <label class="caja-switch-ios">
                                <input type="checkbox" id="caja-emp-can-view-orders" checked />
                                <span class="caja-switch-slider"></span>
                            </label>
                        </div>

                        <!-- Permiso C: Cambiar entre Mesas y Barras -->
                        <div class="caja-emp-perm-row">
                            <div class="caja-emp-perm-info">
                                <strong><?php _e('¿Puede intercambiar libremente entre mesas y barras?', 'emp-caja'); ?></strong>
                                <p><?php _e('Por defecto sólo permitido para Administradores. Si está desactivado, el cajero queda fijado a sus barras asignadas sin acceso a mesas.', 'emp-caja'); ?></p>
                            </div>
                            <label class="caja-switch-ios">
                                <input type="checkbox" id="caja-emp-can-change-sector" />
                                <span class="caja-switch-slider"></span>
                            </label>
                        </div>

                        <!-- Permiso D: Ver y Cargar Datos del Cliente en Venta Caja -->
                        <div class="caja-emp-perm-row">
                            <div class="caja-emp-perm-info">
                                <strong><?php _e('¿Puede ver y cargar datos del cliente en Venta Caja?', 'emp-caja'); ?></strong>
                                <p><?php _e('Si se desactiva, se oculta la sección de datos del cliente (nombre, teléfono, DNI, dirección) para agilizar el cobro en barra.', 'emp-caja'); ?></p>
                            </div>
                            <label class="caja-switch-ios">
                                <input type="checkbox" id="caja-emp-can-view-customer-data" />
                                <span class="caja-switch-slider"></span>
                            </label>
                        </div>

                        <!-- Permiso E: Ver Sección de Empaque y Bolsas en Venta Caja -->
                        <div class="caja-emp-perm-row">
                            <div class="caja-emp-perm-info">
                                <strong><?php _e('¿Puede ver sección de empaque y bolsas en Venta Caja?', 'emp-caja'); ?></strong>
                                <p><?php _e('Si se desactiva, se oculta la sección de empaque y selección de bolsas.', 'emp-caja'); ?></p>
                            </div>
                            <label class="caja-switch-ios">
                                <input type="checkbox" id="caja-emp-can-view-packaging" />
                                <span class="caja-switch-slider"></span>
                            </label>
                        </div>

                        <!-- Permiso F: Cambiar a Pedido por Despachar -->
                        <div class="caja-emp-perm-row">
                            <div class="caja-emp-perm-info">
                                <strong><?php _e('¿Puede cambiar de venta realizada a pedido por despachar?', 'emp-caja'); ?></strong>
                                <p><?php _e('Si se desactiva, el cajero solo podrá cobrar ventas en modo Compra Realizada (entregada en el acto), sin acceso a despachos ni envíos.', 'emp-caja'); ?></p>
                            </div>
                            <label class="caja-switch-ios">
                                <input type="checkbox" id="caja-emp-can-dispatch-orders" />
                                <span class="caja-switch-slider"></span>
                            </label>
                        </div>

                        <!-- Permiso G: Estado de la Cuenta -->
                        <div class="caja-emp-perm-row" style="border-top: 1px dashed var(--caja-card-border, #334155); margin-top:8px; padding-top:14px;">
                            <div class="caja-emp-perm-info">
                                <strong><?php _e('Estado de la cuenta (Habilitado)', 'emp-caja'); ?></strong>
                                <p><?php _e('Si se desactiva, el usuario no podrá iniciar sesión en la caja POS.', 'emp-caja'); ?></p>
                            </div>
                            <label class="caja-switch-ios">
                                <input type="checkbox" id="caja-emp-status" checked />
                                <span class="caja-switch-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="caja-modal-footer" style="padding:16px 20px; display:flex; justify-content:flex-end; gap:10px; border-top:1px solid var(--caja-card-border, #334155);">
                <button type="button" class="caja-btn caja-btn-secondary" id="caja-emp-modal-cancel">
                    <?php _e('Cancelar', 'emp-caja'); ?>
                </button>
                <button type="submit" class="caja-btn caja-btn-primary" id="caja-emp-modal-save">
                    <span class="caja-emp-save-spinner" style="display:none;">⏳</span>
                    <span id="caja-emp-save-txt"><?php _e('Guardar Empleado', 'emp-caja'); ?></span>
                </button>
            </div>
        </form>
    </div>
</div>
