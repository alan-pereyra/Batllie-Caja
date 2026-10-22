<?php
/**
 * Template de la Pantalla de Configuración
 * Compartido entre el panel de administración de WordPress y el panel Caja POS (3ra pantalla)
 */

if (!defined('ABSPATH')) {
    exit;
}

$is_admin = is_admin();
$options = Batllie_Caja_Plugin::get_color_settings();
?>

<div class="caja-settings-container">
    <?php if ($is_admin && isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true'): ?>
    <div class="caja-alert caja-alert-success" style="margin-bottom: 20px; padding: 14px 18px; background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; border-radius: 8px; color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 10px; font-size: 14px;">
        <span style="font-size: 18px;">✅</span>
        <span><?php _e('¡Configuración guardada exitosamente!', 'emp-caja'); ?></span>
    </div>
    <?php endif; ?>

    <form method="post" id="caja-settings-form" action="<?php echo esc_url(admin_url('options.php')); ?>">
        <?php if ($is_admin) { settings_fields('batllie_caja_settings_group'); } ?>
        <?php if (!$is_admin): ?>
        <input type="hidden" name="action" value="emp_caja_save_settings" />
        <?php endif; ?>
        <input type="hidden" name="security" value="<?php echo esc_attr(wp_create_nonce('batllie_caja_nonce')); ?>" />

        <!-- Barra Superior de Configuración -->
        <div class="caja-settings-topbar">
            <div class="caja-settings-title-group">
                <h1 class="caja-settings-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    <span><?php _e('Caja POS - Configuración de Colores y Opciones', 'emp-caja'); ?></span>
                </h1>
                <p class="caja-settings-subtitle"><?php _e('Personaliza todos los colores del panel de caja, botones, tarjetas, estados, WhatsApp de clientes y tandas de envío.', 'emp-caja'); ?></p>
            </div>

            <div class="caja-settings-topbar-actions">
                <?php if (!$is_admin): ?>
                <button type="button" class="caja-btn caja-btn-secondary caja-btn-screen-switch" data-target="tab-orders" title="<?php esc_attr_e('Volver a la pantalla de Pedidos en Vivo', 'emp-caja'); ?>">
                    <span class="caja-btn-arrow">⬅</span>
                    <span class="caja-btn-icon">📋</span>
                    <span><?php _e('Volver a Pedidos', 'emp-caja'); ?></span>
                </button>
                <?php endif; ?>

                <button type="submit" class="caja-btn caja-btn-primary caja-btn-save-settings" id="caja-btn-save-settings-top">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    <span><?php _e('Guardar Cambios', 'emp-caja'); ?></span>
                </button>
            </div>
        </div>

        <div class="caja-settings-notice-info">
            <strong><?php _e('Uso en tu sitio:', 'emp-caja'); ?></strong>
            <?php _e('Crea una página nueva en WordPress y pega el shortcode:', 'emp-caja'); ?>
            <code>[caja_pos]</code>
        </div>

        <!-- 1. WhatsApp de Atención y Comprobantes -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">📱</span>
                    <span><?php _e('WhatsApp de Contacto y Envío de Comprobantes', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Número telefónico al cual los compradores se comunican y envían sus comprobantes de transferencia.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_whatsapp_number"><?php _e('Número de WhatsApp', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="caja_setting_whatsapp_number" name="batllie_caja_options[whatsapp_number]" value="<?php echo esc_attr($options['whatsapp_number'] ?? '5491149472377'); ?>" class="regular-text caja-input" placeholder="5491149472377" />
                            <p class="description">
                                <?php _e('Ingresa el número con código de país y de área sin espacios, guiones ni el signo + (por ejemplo: <code>5491149472377</code>). Este es el número al que el cliente es derivado cuando hace clic en "Enviar comprobante por WhatsApp" en el seguimiento de su pedido.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 2. Colores Generales del Panel -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">🎨</span>
                    <span><?php _e('Colores Generales del Panel', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Colores de fondo, bordes y textos del sistema de caja.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Color de Fondo Principal', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[bg_color]" value="<?php echo esc_attr($options['bg_color']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color Barra Superior / Header', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[header_bg]" value="<?php echo esc_attr($options['header_bg']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color de Tarjetas / Paneles', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[card_bg]" value="<?php echo esc_attr($options['card_bg']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Borde de Tarjetas', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[card_border]" value="<?php echo esc_attr($options['card_border']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color de Texto Principal', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[text_color]" value="<?php echo esc_attr($options['text_color']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color de Texto Secundario', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[text_muted]" value="<?php echo esc_attr($options['text_muted']); ?>" class="caja-color-field" /></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 3. Botones y Acentos -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">🔘</span>
                    <span><?php _e('Botones y Acentos', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Apariencia de botones de acción y elementos interactivos.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Botón Primario / Acento', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[primary_color]" value="<?php echo esc_attr($options['primary_color']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Botón Primario (Hover)', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[primary_hover]" value="<?php echo esc_attr($options['primary_hover']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Color Texto de Botones', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[btn_text]" value="<?php echo esc_attr($options['btn_text']); ?>" class="caja-color-field" /></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 4. Colores de Estados de Pedido -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">🏷️</span>
                    <span><?php _e('Colores de Estados de Pedido', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Distingue rápidamente las órdenes según su estado operativo.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('1. Pendiente', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_pending]" value="<?php echo esc_attr($options['status_pending']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('2. En preparación', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_processing]" value="<?php echo esc_attr($options['status_processing']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('3. Enviando', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_enviando]" value="<?php echo esc_attr($options['status_enviando']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('4. Completado', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_completed]" value="<?php echo esc_attr($options['status_completed']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('5. Recibido (con inconvenientes)', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_recibido_problema]" value="<?php echo esc_attr($options['status_recibido_problema']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('6. Cancelado', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_cancelled]" value="<?php echo esc_attr($options['status_cancelled']); ?>" class="caja-color-field" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('7. Reembolzado', 'emp-caja'); ?></th>
                        <td><input type="text" name="batllie_caja_options[status_refunded]" value="<?php echo esc_attr($options['status_refunded']); ?>" class="caja-color-field" /></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 5. Opciones de Notificación y Visualización -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">⚙️</span>
                    <span><?php _e('Opciones de Notificación y Visualización', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Frecuencia de actualización en vivo, alarmas audibles y diseño aislado.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Frecuencia de Actualización', 'emp-caja'); ?></th>
                        <td>
                            <input type="number" min="5" max="60" name="batllie_caja_options[poll_interval]" value="<?php echo esc_attr($options['poll_interval']); ?>" class="small-text caja-input" /> 
                            <span><?php _e('segundos (comprueba nuevos pedidos en segundo plano).', 'emp-caja'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Sonido Activo por Defecto', 'emp-caja'); ?></th>
                        <td>
                            <input type="hidden" name="batllie_caja_options[sound_enabled]" value="no" />
                            <label class="caja-checkbox-label">
                                <input type="checkbox" name="batllie_caja_options[sound_enabled]" value="yes" <?php checked($options['sound_enabled'], 'yes'); ?> />
                                <span><?php _e('Reproducir campana sonora al ingresar un nuevo pedido', 'emp-caja'); ?></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Modo Vista Aislada', 'emp-caja'); ?></th>
                        <td>
                            <input type="hidden" name="batllie_caja_options[force_isolated]" value="no" />
                            <label class="caja-checkbox-label">
                                <input type="checkbox" name="batllie_caja_options[force_isolated]" value="yes" <?php checked($options['force_isolated'], 'yes'); ?> />
                                <span><?php _e('Ocultar automáticamente menús, cabecera y pie de página del tema en la página de caja', 'emp-caja'); ?></span>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 6. Mínimo de Compra en la Tienda -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">🛒</span>
                    <span><?php _e('Mínimo de Compra en la Tienda', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Restricción mínima en el carrito para permitir el checkout.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Monto Mínimo de Compra ($)', 'emp-caja'); ?></th>
                        <td>
                            <input type="number" step="any" min="0" name="batllie_caja_options[min_purchase_amount]" value="<?php echo esc_attr($options['min_purchase_amount'] ?? 0); ?>" class="regular-text caja-input" placeholder="0 (desactivado)" />
                            <p class="description">
                                <?php _e('Define el importe mínimo que debe sumar el carrito para que un cliente pueda ir a pagar. Si no alcanza este monto, el sistema le impedirá finalizar la compra y le indicará en una alerta y en el carrito exactamente cuánto le falta para llegar al mínimo. Coloca 0 o déjalo vacío para desactivar la restricción.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 7. Tandas y Horarios de Envío -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">🚚</span>
                    <span><?php _e('Gestión de Tandas y Horarios de Envío', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Configura las opciones de despacho por turnos y horarios de entrega para los pedidos.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table caja-form-table">
                    <tr>
                        <th scope="row"><?php _e('Habilitar selección de horarios', 'emp-caja'); ?></th>
                        <td>
                            <input type="hidden" name="batllie_caja_options[shipping_slots_enabled]" value="no" />
                            <label class="caja-switch-label">
                                <input type="checkbox" name="batllie_caja_options[shipping_slots_enabled]" value="yes" <?php checked($options['shipping_slots_enabled'] ?? 'yes', 'yes'); ?> />
                                <span><?php _e('Permitir a los clientes elegir tanda de despacho al momento de comprar en la tienda.', 'emp-caja'); ?></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Margen límite de corte', 'emp-caja'); ?></th>
                        <td>
                            <select name="batllie_caja_options[shipping_slots_cutoff]" class="caja-select" style="max-width: 350px;">
                                <option value="5" <?php selected($options['shipping_slots_cutoff'] ?? 5, 5); ?>><?php _e('5 minutos antes del horario (Recomendado)', 'emp-caja'); ?></option>
                                <option value="10" <?php selected($options['shipping_slots_cutoff'] ?? 5, 10); ?>><?php _e('10 minutos antes del horario', 'emp-caja'); ?></option>
                                <option value="15" <?php selected($options['shipping_slots_cutoff'] ?? 5, 15); ?>><?php _e('15 minutos antes del horario', 'emp-caja'); ?></option>
                                <option value="30" <?php selected($options['shipping_slots_cutoff'] ?? 5, 30); ?>><?php _e('30 minutos antes del horario', 'emp-caja'); ?></option>
                                <option value="60" <?php selected($options['shipping_slots_cutoff'] ?? 5, 60); ?>><?php _e('1 hora antes del horario', 'emp-caja'); ?></option>
                            </select>
                            <p class="description"><?php _e('Si la compra se realiza después del corte (ej. a las 11:56 para la tanda de las 12:00 con 5 min de margen), esa tanda se cierra y se ofrecen las siguientes.', 'emp-caja'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Aplicar a', 'emp-caja'); ?></th>
                        <td>
                            <select name="batllie_caja_options[shipping_slots_apply_to]" class="caja-select" style="max-width: 350px;">
                                <option value="shipping_only" <?php selected($options['shipping_slots_apply_to'] ?? 'shipping_only', 'shipping_only'); ?>><?php _e('Solo a envíos a domicilio (no aplica si eligen retiro en local)', 'emp-caja'); ?></option>
                                <option value="all" <?php selected($options['shipping_slots_apply_to'] ?? 'shipping_only', 'all'); ?>><?php _e('A todos los pedidos (incluido retiro en tienda)', 'emp-caja'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Horarios de despacho configurados', 'emp-caja'); ?></th>
                        <td>
                            <div id="caja-slots-manager" class="caja-slots-manager">
                                <input type="hidden" name="batllie_caja_options[shipping_slots_present]" value="1" />
                                <div class="caja-slots-list" id="caja-slots-list" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
                                    <?php 
                                    $curr_slots = $options['shipping_slots'] ?? array('09:00', '12:00', '18:00');
                                    if (is_string($curr_slots)) $curr_slots = explode(',', $curr_slots);
                                    sort($curr_slots);
                                    foreach ($curr_slots as $slot_val): 
                                        $slot_val = trim($slot_val);
                                        if (empty($slot_val)) continue;
                                    ?>
                                        <div class="caja-slot-chip" data-slot="<?php echo esc_attr($slot_val); ?>">
                                            <input type="hidden" name="batllie_caja_options[shipping_slots][]" value="<?php echo esc_attr($slot_val); ?>" />
                                            <span class="caja-slot-icon">🕒</span>
                                            <span class="caja-slot-text"><?php echo esc_html($slot_val); ?> hs</span>
                                            <button type="button" class="caja-slot-remove-btn" title="<?php esc_attr_e('Eliminar horario', 'emp-caja'); ?>">✕</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="caja-slot-add-bar" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <input type="time" id="caja-new-slot-input" class="caja-input" style="max-width: 140px; height: 38px;" />
                                    <button type="button" id="caja-btn-add-slot" class="caja-btn caja-btn-secondary" style="height: 38px;">
                                        <span>➕ <?php _e('Agregar Horario', 'emp-caja'); ?></span>
                                    </button>
                                </div>
                                <p class="description" style="margin-top:8px;"><?php _e('Podés agregar nuevos horarios (ej: 20:00) o eliminar los existentes haciendo click en ✕. Recordá hacer click en "Guardar Cambios".', 'emp-caja'); ?></p>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 8. Requisitos de Datos del Cliente en Caja POS -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">👤</span>
                    <span><?php _e('Caja POS - Datos del Cliente y Facturación', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Configuración de requisitos para los datos del cliente (Nombre y Apellido, DNI, Teléfono, Dirección, Email) en ventas de mostrador y pedidos con despacho.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_pos_customer_threshold"><?php _e('Monto mínimo para exigir datos en mostrador ($)', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <input type="number" step="any" min="0" id="caja_setting_pos_customer_threshold" name="batllie_caja_options[pos_customer_threshold]" value="<?php echo esc_attr($options['pos_customer_threshold'] ?? '0'); ?>" class="regular-text caja-input" placeholder="0" style="max-width: 220px;" />
                            <p class="description">
                                <?php _e('En ventas con envío, los datos del cliente son <strong>siempre obligatorios</strong>. En compras ya pagadas / retiro por el local, ingresar los datos es opcional a menos que el total de la venta sea <strong>igual o superior a este monto</strong> (por normativa de facturación/AFIP). Ingresa 0 si deseas que en mostrador sea siempre opcional.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_pos_auto_account"><?php _e('Cuenta de usuario y email de seguimiento', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <label class="caja-checkbox-label">
                                <input type="checkbox" id="caja_setting_pos_auto_account" name="batllie_caja_options[pos_auto_account]" value="1" <?php checked($options['pos_auto_account'] ?? '1', '1'); ?> />
                                <span><?php _e('Crear automáticamente cuenta de cliente y enviar correo con botón para definir contraseña y ver pedidos', 'emp-caja'); ?></span>
                            </label>
                            <p class="description">
                                <?php _e('Si se ingresa un email en cualquier venta presencial y el cliente es nuevo, se le creará su cuenta de WooCommerce y se le enviará un correo personalizado con la identidad de la tienda. Al hacer clic, podrá definir su contraseña y accederá directamente a su panel de pedidos para ver el estado de su paquete.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 9. Cancelación Automática de Pedidos sin Pagar -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">⏱️</span>
                    <span><?php _e('Cancelación Automática de Pedidos sin Pagar', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Tiempo límite que tiene el comprador para pagar antes de que WooCommerce cancele el pedido automáticamente y libere el inventario reservado.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_hold_stock_minutes"><?php _e('Tiempo límite para abonar', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <input type="number" min="1" step="1" id="caja_setting_hold_stock_minutes" name="batllie_caja_options[hold_stock_minutes]" value="<?php echo esc_attr($options['hold_stock_minutes'] ?? 60); ?>" class="regular-text caja-input" style="max-width: 130px; font-weight: 700; font-size: 15px;" />
                                <span style="font-weight: 600; color: #475569;"><?php _e('minutos', 'emp-caja'); ?></span>
                                <select id="caja_select_hold_stock_presets" class="caja-select" style="max-width: 290px; padding: 7px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 500;">
                                    <option value=""><?php _e('-- Opciones rápidas sugeridas --', 'emp-caja'); ?></option>
                                    <option value="15" <?php selected($options['hold_stock_minutes'] ?? 60, 15); ?>>15 minutos</option>
                                    <option value="30" <?php selected($options['hold_stock_minutes'] ?? 60, 30); ?>>30 minutos</option>
                                    <option value="45" <?php selected($options['hold_stock_minutes'] ?? 60, 45); ?>>45 minutos</option>
                                    <option value="60" <?php selected($options['hold_stock_minutes'] ?? 60, 60); ?>>1 hora (60 minutos - Por defecto)</option>
                                    <option value="120" <?php selected($options['hold_stock_minutes'] ?? 60, 120); ?>>2 horas (120 minutos)</option>
                                    <option value="180" <?php selected($options['hold_stock_minutes'] ?? 60, 180); ?>>3 horas (180 minutos)</option>
                                    <option value="240" <?php selected($options['hold_stock_minutes'] ?? 60, 240); ?>>4 horas (240 minutos)</option>
                                    <option value="360" <?php selected($options['hold_stock_minutes'] ?? 60, 360); ?>>6 horas (360 minutos)</option>
                                    <option value="720" <?php selected($options['hold_stock_minutes'] ?? 60, 720); ?>>12 horas (720 minutos)</option>
                                    <option value="1440" <?php selected($options['hold_stock_minutes'] ?? 60, 1440); ?>>24 horas / 1 día (1440 minutos)</option>
                                    <option value="2880" <?php selected($options['hold_stock_minutes'] ?? 60, 2880); ?>>48 horas / 2 días (2880 minutos)</option>
                                    <option value="4320" <?php selected($options['hold_stock_minutes'] ?? 60, 4320); ?>>72 horas / 3 días (4320 minutos)</option>
                                </select>
                            </div>
                            <div id="caja-hold-stock-preview" style="margin-top: 12px; padding: 10px 14px; background: rgba(245, 158, 11, 0.09); border: 1px dashed #f59e0b; border-radius: 6px; display: inline-block; font-size: 13px; color: #b45309;">
                                ⏱️ <span><?php _e('Vista previa en Caja POS:', 'emp-caja'); ?></span> <span id="caja-hold-stock-preview-text"></span>
                            </div>
                            <p class="description" style="margin-top: 10px;">
                                <?php _e('Configura cuánto tiempo se reserva el stock para pedidos no abonados. Este ajuste sincroniza en tiempo real con la opción nativa de WooCommerce (<code>woocommerce_hold_stock_minutes</code>). En la terminal de Caja POS, cuando se seleccione un pedido a despachar con pago pendiente, se mostrará automáticamente este tiempo límite en la alerta informativa.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 10. Configuración para Boliches y Restaurantes (Gastronomía) -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">🍸</span>
                    <span><?php _e('Configuración para Boliches y Restaurantes', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Adapta el funcionamiento de la caja y pedidos para locales nocturnos, barras, mesas y comandas.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_gastro_mode"><?php _e('Perfil / Modalidad Principal', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <select id="caja_setting_gastro_mode" name="batllie_caja_options[gastro_mode]" class="caja-select" style="max-width: 380px;">
                                <option value="boliche" <?php selected($options['gastro_mode'] ?? 'boliche', 'boliche'); ?>>🍸 <?php _e('Boliche / Bar Nocturno (Venta rápida en barra)', 'emp-caja'); ?></option>
                                <option value="restaurante" <?php selected($options['gastro_mode'] ?? 'boliche', 'restaurante'); ?>>🍽️ <?php _e('Restaurante / Restobar (Mesas, comandas y salón)', 'emp-caja'); ?></option>
                            </select>
                            <p class="description">
                                <?php _e('Configura las opciones predeterminadas de la interfaz y orden de sectores en la venta rápida.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_gastro_venue_name"><?php _e('Nombre del Local / Marca en Tickets', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="caja_setting_gastro_venue_name" name="batllie_caja_options[gastro_venue_name]" value="<?php echo esc_attr($options['gastro_venue_name'] ?? ''); ?>" class="regular-text caja-input" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
                            <p class="description">
                                <?php _e('Aparece en la cabecera de las comandas para barra/cocina y tickets térmicos de clientes.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_gastro_sectors"><?php _e('Barras, Mesas y Sectores Activos', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <textarea id="caja_setting_gastro_sectors" name="batllie_caja_options[gastro_sectors]" rows="3" class="large-text caja-input" style="max-width: 600px;"><?php echo esc_textarea($options['gastro_sectors'] ?? 'Barra Principal, Barra VIP, Terraza, Boletería / Entrada, Pista, Mesa 1, Mesa 2, Mesa 3, Mesa 4, Mesa 5, Mesa 6, Mesa 7, Mesa 8, Mesa 9, Mesa 10, Take Away / Para Llevar'); ?></textarea>
                            <p class="description">
                                <?php _e('Lista separada por comas de las barras, mesas y puntos de despacho que estarán disponibles como botones rápidos en la pantalla de cobro POS.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Calculadora de Vuelto Rápido (Efectivo)', 'emp-caja'); ?></th>
                        <td>
                            <input type="hidden" name="batllie_caja_options[gastro_enable_quick_cash]" value="no" />
                            <label class="caja-checkbox-label">
                                <input type="checkbox" name="batllie_caja_options[gastro_enable_quick_cash]" value="yes" <?php checked($options['gastro_enable_quick_cash'] ?? 'yes', 'yes'); ?> />
                                <span><?php _e('Mostrar botones táctiles de billetes ($1.000, $2.000, $5.000, $10.000, $20.000) y cálculo instantáneo del vuelto.', 'emp-caja'); ?></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Propina y Servicio Opcional', 'emp-caja'); ?></th>
                        <td>
                            <input type="hidden" name="batllie_caja_options[gastro_enable_tips]" value="no" />
                            <label class="caja-checkbox-label">
                                <input type="checkbox" name="batllie_caja_options[gastro_enable_tips]" value="yes" <?php checked($options['gastro_enable_tips'] ?? 'yes', 'yes'); ?> />
                                <span><?php _e('Habilitar selector de propina sugerida (5%, 10%, 15% o libre) en el cobro de la terminal POS.', 'emp-caja'); ?></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="caja_setting_gastro_ticket_footer"><?php _e('Pie de Ticket / Comprobante', 'emp-caja'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="caja_setting_gastro_ticket_footer" name="batllie_caja_options[gastro_ticket_footer]" value="<?php echo esc_attr($options['gastro_ticket_footer'] ?? '¡Gracias por tu visita! Conserve su ticket.'); ?>" class="regular-text caja-input" />
                            <p class="description">
                                <?php _e('Mensaje final impreso en el ticket térmico.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Barra Inferior de Guardar -->
        <div class="caja-settings-submit-bar">
            <button type="submit" class="caja-btn caja-btn-primary caja-btn-lg caja-btn-save-settings" id="caja-btn-save-settings">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                    <polyline points="7 3 7 8 15 8"></polyline>
                </svg>
                <span><?php _e('Guardar Cambios de Configuración', 'emp-caja'); ?></span>
            </button>
            <span class="caja-settings-feedback" id="caja-settings-feedback" style="display:none;"></span>
        </div>
    </form>
</div>
