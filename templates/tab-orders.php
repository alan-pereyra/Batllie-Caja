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

    <!-- Modal para Confirmar Reembolso de Stock de Paquetes -->
    <div id="caja-modal-refund-stock" class="caja-modal" style="display:none;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog" style="max-width: 480px;">
            <div class="caja-modal-header" style="background:#1e293b; color:#fff; border-bottom:1px solid #334155;">
                <h3 style="margin:0; font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px;">
                    <span>📦</span> <?php _e('Reembolso de Stock de Paquetes', 'emp-caja'); ?>
                </h3>
                <button type="button" class="caja-modal-close" id="caja-refund-modal-close-btn">&times;</button>
            </div>
            <div class="caja-modal-body" style="padding: 20px; font-size:14px; color:#334155;">
                <p style="margin-top:0; font-size:15px; font-weight:600; color:#0f172a;">
                    <?php _e('¿Deseas devolver al inventario el stock de los paquetes de este pedido?', 'emp-caja'); ?>
                </p>
                <p style="color:#64748b; font-size:13px; line-height:1.5; margin-bottom:12px;">
                    <?php _e('Indica si las cajas físicas de empaque deben retornar al stock disponible o mantenerse descontadas:', 'emp-caja'); ?>
                </p>
                <div id="caja-refund-modal-details" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin:14px 0; font-size:13px; color:#1e293b;">
                    <!-- Se llena dinámicamente con JS -->
                </div>
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:16px;">
                    <button type="button" class="caja-btn caja-btn-primary" id="caja-btn-refund-restore-yes" style="background:#059669; border-color:#059669; justify-content:center; padding:12px; font-weight:600; font-size:14px; color:#fff;">
                        ↩️ <?php _e('Sí, devolver stock de paquetes', 'emp-caja'); ?>
                    </button>
                    <button type="button" class="caja-btn caja-btn-secondary" id="caja-btn-refund-restore-no" style="background:#f1f5f9; color:#475569; border-color:#cbd5e1; justify-content:center; padding:12px; font-weight:600; font-size:14px;">
                        📦 <?php _e('No devolver, mantener descontado', 'emp-caja'); ?>
                    </button>
                </div>
            </div>
            <div class="caja-modal-footer" style="padding:12px 20px; justify-content:flex-end; border-top:1px solid #e2e8f0;">
                <button type="button" class="caja-btn caja-btn-secondary" id="caja-btn-refund-cancel" style="padding:8px 16px;">
                    <?php _e('Cancelar cambio de estado', 'emp-caja'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

