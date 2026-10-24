<?php
/**
 * Pestaña de Pedidos en Vivo
 */

if (!defined('ABSPATH')) {
    exit;
}

// Calcular etiquetas dinámicas de días para los filtros (Zona horaria de Argentina)
$site_tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Argentina/Buenos_Aires');
$now_dt  = new DateTime('now', $site_tz);

$dias_esp = array(
    0 => 'Domingo',
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sábado',
);

$dt_1day = (clone $now_dt)->modify('-1 day');
$label_1day = $dias_esp[(int) $dt_1day->format('w')] . ' ' . $dt_1day->format('d/m');

$dt_2days = (clone $now_dt)->modify('-2 days');
$label_2days = $dias_esp[(int) $dt_2days->format('w')] . ' ' . $dt_2days->format('d/m');

$settings = class_exists('Batllie_Caja_Plugin') ? Batllie_Caja_Plugin::get_color_settings() : array();
$configured_slots = $settings['shipping_slots'] ?? array('09:00', '12:00', '18:00');
if (is_string($configured_slots)) $configured_slots = explode(',', $configured_slots);
sort($configured_slots);
?>

<div class="caja-orders-container">
    <!-- Barra de Filtros y Búsqueda -->
    <div class="caja-filter-bar">
        <!-- Desplegables de Filtro (3 columnas) -->
        <div class="caja-top-filters-grid">
            <div class="caja-filter-select-col">
                <select id="caja-filter-status" class="caja-select-filter">
                    <option value="all"><?php _e('Todos los estados', 'emp-caja'); ?></option>
                    <option value="pending"><?php _e('Pendiente', 'emp-caja'); ?></option>
                    <option value="processing"><?php _e('En preparación', 'emp-caja'); ?></option>
                    <option value="enviando"><?php _e('Enviando', 'emp-caja'); ?></option>
                    <option value="completed"><?php _e('Completado', 'emp-caja'); ?></option>
                    <option value="recibido-problema"><?php _e('Recibido (con inconvenientes)', 'emp-caja'); ?></option>
                    <option value="cancelled"><?php _e('Cancelado', 'emp-caja'); ?></option>
                    <option value="refunded"><?php _e('Reembolzado', 'emp-caja'); ?></option>
                </select>
            </div>

            <div class="caja-filter-select-col">
                <select id="caja-filter-slot" class="caja-select-filter">
                    <option value="all"><?php _e('Todos los horarios', 'emp-caja'); ?></option>
                    <?php foreach ($configured_slots as $c_slot): 
                        $c_slot = trim($c_slot);
                        if (empty($c_slot)) continue;
                    ?>
                        <option value="<?php echo esc_attr($c_slot); ?>">
                            <?php echo esc_html(sprintf(__('🕒 Tanda %s hs', 'emp-caja'), $c_slot)); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="tomorrow"><?php _e('🕒 Tandas de mañana', 'emp-caja'); ?></option>
                    <option value="none"><?php _e('Sin horario asignado', 'emp-caja'); ?></option>
                </select>
            </div>

            <div class="caja-filter-select-col">
                <select id="caja-filter-gastro-sector" class="caja-select-filter" style="margin-bottom:8px;">
                    <option value="all"><?php _e('📍 Sectores / Mesas', 'emp-caja'); ?></option>
                    <option value="barra"><?php _e('🍸 Barras', 'emp-caja'); ?></option>
                    <option value="mesa"><?php _e('🍽️ Mesas', 'emp-caja'); ?></option>
                    <option value="takeaway"><?php _e('🛍️ Take Away', 'emp-caja'); ?></option>
                </select>
                <select id="caja-filter-time" class="caja-select-filter">
                    <option value="nuevos" selected><?php _e('Nuevos', 'emp-caja'); ?></option>
                    <option value="30_min"><?php _e('30 minutos', 'emp-caja'); ?></option>
                    <option value="1_hour"><?php _e('1 hora', 'emp-caja'); ?></option>
                    <option value="2_hours"><?php _e('2 horas', 'emp-caja'); ?></option>
                    <option value="1_day"><?php echo esc_html($label_1day); ?></option>
                    <option value="2_days"><?php echo esc_html($label_2days); ?></option>
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

    <!-- Modal: Devolución / Reembolso de Pedido Completo -->
    <div id="caja-modal-order-refund" class="caja-modal" style="display:none; z-index: 100060;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog" style="max-width: 540px; width: 95%;">
            <div class="caja-modal-header" style="background:#0f172a; color:#f8fafc; border-bottom:1px solid #334155; display:flex; justify-content:space-between; align-items:center; padding:14px 18px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:1.4rem;">🔄</span>
                    <div>
                        <h3 class="caja-modal-title" id="caja-refund-modal-title" style="margin:0; font-size:1.15rem; font-weight:700; color:#f8fafc;">
                            <?php _e('Devolución de Pedido', 'emp-caja'); ?>
                        </h3>
                        <p id="caja-refund-modal-subtitle" style="margin:2px 0 0; font-size:0.8rem; color:#94a3b8;"></p>
                    </div>
                </div>
                <button type="button" class="caja-modal-close" id="caja-refund-close-btn" style="color:#94a3b8; font-size:1.5rem; background:none; border:none; cursor:pointer;" aria-label="<?php esc_attr_e('Cerrar', 'emp-caja'); ?>">&times;</button>
            </div>

            <div class="caja-modal-body" style="background:#1e293b; color:#f8fafc; padding:18px 20px; max-height:78vh; overflow-y:auto;">
                <!-- Tarjeta Resumen del Pedido -->
                <div style="background:rgba(15,23,42,0.6); border:1px solid rgba(255,255,255,0.08); border-radius:8px; padding:12px 14px; margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                        <div>
                            <span id="caja-refund-order-number-display" style="font-size:1.05rem; font-weight:800; color:#f8fafc;">#---</span>
                            <span id="caja-refund-customer-display" style="font-size:0.88rem; color:#94a3b8; margin-left:8px;">👤 Consumidor Final</span>
                        </div>
                        <div>
                            <span style="font-size:0.75rem; color:#94a3b8; text-transform:uppercase; font-weight:600; display:block; text-align:right;">Total Pedido:</span>
                            <strong id="caja-refund-order-total-display" style="font-size:1.2rem; color:#10b981;">$0,00</strong>
                        </div>
                    </div>
                    <div id="caja-refund-items-preview" style="margin-top:8px; padding-top:8px; border-top:1px dashed rgba(255,255,255,0.1); font-size:0.82rem; color:#cbd5e1; max-height:80px; overflow-y:auto;">
                        <!-- Ítems del pedido -->
                    </div>
                </div>

                <!-- Paso 1: Retorno de Mercadería (Stock) -->
                <div style="margin-bottom:18px;">
                    <label style="display:block; font-size:0.84rem; font-weight:700; color:#f8fafc; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px;">
                        📦 <?php _e('1. ¿Se devuelve la mercadería?', 'emp-caja'); ?>
                    </label>
                    <div class="caja-refund-stock-selector" style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <button type="button" class="caja-refund-stock-btn active" data-restore="yes" style="background:#0f172a; border:2px solid #10b981; border-radius:8px; padding:12px; text-align:left; cursor:pointer; color:#f8fafc; transition:all 0.2s;">
                            <div style="font-size:1.3rem; margin-bottom:4px;">↩️</div>
                            <strong style="font-size:0.88rem; display:block; color:#10b981;"><?php _e('Sí, se devuelve', 'emp-caja'); ?></strong>
                            <span style="font-size:0.74rem; color:#94a3b8; line-height:1.3; display:block; margin-top:2px;"><?php _e('Se reincorpora al stock de productos automáticamente.', 'emp-caja'); ?></span>
                        </button>
                        <button type="button" class="caja-refund-stock-btn" data-restore="no" style="background:#0f172a; border:2px solid #334155; border-radius:8px; padding:12px; text-align:left; cursor:pointer; color:#f8fafc; transition:all 0.2s;">
                            <div style="font-size:1.3rem; margin-bottom:4px;">🚫</div>
                            <strong style="font-size:0.88rem; display:block; color:#f8fafc;"><?php _e('No se devuelve', 'emp-caja'); ?></strong>
                            <span style="font-size:0.74rem; color:#94a3b8; line-height:1.3; display:block; margin-top:2px;"><?php _e('Mercadería no reincorporada (consumida / merma).', 'emp-caja'); ?></span>
                        </button>
                    </div>
                    <input type="hidden" id="caja-refund-restore-stock-val" value="yes" />
                </div>

                <!-- Paso 2: Origen del Dinero -->
                <div style="margin-bottom:18px;">
                    <label style="display:block; font-size:0.84rem; font-weight:700; color:#f8fafc; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px;">
                        💵 <?php _e('2. ¿De dónde sale el dinero de la devolución?', 'emp-caja'); ?>
                    </label>
                    <div class="caja-refund-money-options" style="display:flex; flex-direction:column; gap:10px;">
                        <!-- Opción 1: Caja en Efectivo -->
                        <div class="caja-refund-money-card active" data-source="cash_box" style="background:#0f172a; border:2px solid #3b82f6; border-radius:8px; padding:12px; cursor:pointer; transition:all 0.2s;">
                            <div style="display:flex; align-items:flex-start; gap:10px;">
                                <input type="radio" name="caja_refund_source_radio" id="caja-refund-radio-cash" value="cash_box" checked style="margin-top:3px; accent-color:#3b82f6; cursor:pointer;" />
                                <div style="flex:1;">
                                    <label for="caja-refund-radio-cash" style="font-weight:700; font-size:0.9rem; color:#f8fafc; cursor:pointer; display:block;">
                                        💵 <?php _e('Caja de Dinero en Efectivo', 'emp-caja'); ?>
                                    </label>
                                    <p style="margin:2px 0 0; font-size:0.77rem; color:#94a3b8;">
                                        <?php _e('Se descuenta de una caja física y genera un egreso en su historial.', 'emp-caja'); ?>
                                    </p>
                                    <div id="caja-refund-cashbox-select-wrap" style="margin-top:10px;">
                                        <label style="font-size:0.76rem; font-weight:600; color:#cbd5e1; display:block; margin-bottom:4px;">
                                            <?php _e('Seleccionar caja física donde se extrae el dinero:', 'emp-caja'); ?>
                                        </label>
                                        <select id="caja-refund-cashbox-select" style="width:100%; background:#1e293b; border:1px solid #475569; color:#f8fafc; padding:8px 10px; border-radius:6px; font-size:0.86rem;">
                                            <?php
                                            $boxes = class_exists('Batllie_Caja_Register') ? Batllie_Caja_Register::get_money_boxes_with_balances() : array();
                                            if (!empty($boxes)) {
                                                foreach ($boxes as $b) {
                                                    $code_label = !empty($b['code']) ? '[' . esc_html($b['code']) . '] ' : '';
                                                    $bal_display = isset($b['current_cash']) ? ' (Saldo: $' . number_format(floatval($b['current_cash']), 2, ',', '.') . ')' : '';
                                                    echo '<option value="' . esc_attr($b['id']) . '">' . $code_label . esc_html($b['name']) . $bal_display . '</option>';
                                                }
                                            } else {
                                                echo '<option value="box_1">' . __('Caja Principal', 'emp-caja') . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Opción 2: Cuenta de Transferencia -->
                        <div class="caja-refund-money-card" data-source="transfer" style="background:#0f172a; border:2px solid #334155; border-radius:8px; padding:12px; cursor:pointer; transition:all 0.2s;">
                            <div style="display:flex; align-items:flex-start; gap:10px;">
                                <input type="radio" name="caja_refund_source_radio" id="caja-refund-radio-transfer" value="transfer" style="margin-top:3px; accent-color:#3b82f6; cursor:pointer;" />
                                <div style="flex:1;">
                                    <label for="caja-refund-radio-transfer" style="font-weight:700; font-size:0.9rem; color:#f8fafc; cursor:pointer; display:block;">
                                        📱 <?php _e('Cuenta de Transferencia / Banco', 'emp-caja'); ?>
                                    </label>
                                    <p style="margin:2px 0 0; font-size:0.77rem; color:#94a3b8;">
                                        <?php _e('Se descuenta de la cuenta de transferencias bancarias / billetera digital.', 'emp-caja'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Opción 3: Sin Modificar Cuentas -->
                        <div class="caja-refund-money-card" data-source="none" style="background:#0f172a; border:2px solid #334155; border-radius:8px; padding:12px; cursor:pointer; transition:all 0.2s;">
                            <div style="display:flex; align-items:flex-start; gap:10px;">
                                <input type="radio" name="caja_refund_source_radio" id="caja-refund-radio-none" value="none" style="margin-top:3px; accent-color:#3b82f6; cursor:pointer;" />
                                <div style="flex:1;">
                                    <label for="caja-refund-radio-none" style="font-weight:700; font-size:0.9rem; color:#f8fafc; cursor:pointer; display:block;">
                                        ⚖️ <?php _e('Sin modificar el total de ninguna cuenta', 'emp-caja'); ?>
                                    </label>
                                    <p style="margin:2px 0 0; font-size:0.77rem; color:#94a3b8;">
                                        <?php _e('Se registra la devolución sin afectar el saldo de ninguna caja ni cuenta.', 'emp-caja'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="caja-refund-money-source-val" value="cash_box" />
                </div>

                <!-- Paso 3: Importe y Motivo -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:8px;">
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; color:#cbd5e1; display:block; margin-bottom:4px;">
                            <?php _e('Importe a Devolver ($):', 'emp-caja'); ?>
                        </label>
                        <input type="number" step="0.01" id="caja-refund-amount-input" style="width:100%; box-sizing:border-box; background:#0f172a; border:1px solid #475569; color:#10b981; font-weight:700; font-size:1.05rem; padding:8px 10px; border-radius:6px;" />
                    </div>
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; color:#cbd5e1; display:block; margin-bottom:4px;">
                            <?php _e('Motivo / Aclaración (opcional):', 'emp-caja'); ?>
                        </label>
                        <input type="text" id="caja-refund-note-input" placeholder="<?php esc_attr_e('Ej: Devolución solicitada', 'emp-caja'); ?>" style="width:100%; box-sizing:border-box; background:#0f172a; border:1px solid #475569; color:#f8fafc; padding:8px 10px; border-radius:6px; font-size:0.86rem;" />
                    </div>
                </div>

                <div id="caja-refund-alert-error" style="display:none; margin-top:12px; background:rgba(239,68,68,0.2); border:1px solid #ef4444; border-radius:6px; padding:8px 12px; color:#fca5a5; font-size:0.84rem;"></div>
            </div>

            <div class="caja-modal-footer" style="background:#0f172a; border-top:1px solid #334155; padding:12px 20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="caja-btn caja-btn-secondary" id="caja-refund-cancel-btn" style="padding:8px 16px; font-size:0.88rem; background:#334155; color:#cbd5e1; border:none; border-radius:6px; cursor:pointer;">
                    <?php _e('Cancelar', 'emp-caja'); ?>
                </button>
                <button type="button" class="caja-btn caja-btn-danger" id="caja-refund-confirm-btn" style="padding:8px 18px; font-size:0.88rem; font-weight:700; background:#ef4444; color:#fff; border:none; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                    <span>✓</span> <?php _e('Confirmar Devolución', 'emp-caja'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

