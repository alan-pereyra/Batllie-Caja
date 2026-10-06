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

$candidates = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_all_box_candidates() : array();
$b6_id = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_id(6) : 0;
$b12_id = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_official_box_id(12) : 0;
$stock_6 = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_box_stock(6) : 0;
$stock_12 = class_exists('Batllie_Caja_Packing') ? Batllie_Caja_Packing::get_box_stock(12) : 0;
?>

<div class="caja-settings-container">
    <!-- Barra Superior de Configuración -->
    <div class="caja-settings-topbar">
        <div class="caja-settings-title-group">
            <h1 class="caja-settings-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
                <span><?php _e('Batllie Caja - Configuración de Colores y Opciones', 'emp-caja'); ?></span>
            </h1>
            <p class="caja-settings-subtitle"><?php _e('Personaliza todos los colores del panel de caja, botones, tarjetas, estados, WhatsApp de clientes y control de stock de empaques.', 'emp-caja'); ?></p>
        </div>

        <div class="caja-settings-topbar-actions">
            <?php if (!$is_admin): ?>
            <button type="button" class="caja-btn caja-btn-secondary caja-btn-screen-switch" data-target="tab-orders" title="<?php esc_attr_e('Volver a la pantalla de Pedidos en Vivo', 'emp-caja'); ?>">
                <span class="caja-btn-arrow">⬅</span>
                <span class="caja-btn-icon">📋</span>
                <span><?php _e('Volver a Pedidos', 'emp-caja'); ?></span>
            </button>
            <?php endif; ?>

            <button type="button" class="caja-btn caja-btn-primary caja-btn-save-settings" id="caja-btn-save-settings-top">
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
        <code>[batllie_caja]</code>
    </div>

    <form method="post" id="caja-settings-form" action="<?php echo esc_url(admin_url('options.php')); ?>">
        <?php if ($is_admin) { settings_fields('batllie_caja_settings_group'); } ?>
        <input type="hidden" name="action" value="emp_caja_save_settings" />
        <input type="hidden" name="security" value="<?php echo esc_attr(wp_create_nonce('batllie_caja_nonce')); ?>" />

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

        <!-- 7. Control de Stock de Cajas y Empaque de Alfajores -->
        <div class="caja-settings-card">
            <div class="caja-settings-card-header">
                <h2>
                    <span class="caja-card-icon">📦</span>
                    <span><?php _e('Control de Stock de Cajas y Empaque de Alfajores', 'emp-caja'); ?></span>
                </h2>
                <p class="caja-settings-card-desc"><?php _e('Reglas de armado de pedidos, prioridad de paquetes x6 y x12, y control de cajas físicas.', 'emp-caja'); ?></p>
            </div>
            <div class="caja-settings-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Prioridad de Llenado de Cajas', 'emp-caja'); ?></th>
                        <td>
                            <select name="batllie_caja_options[packing_priority]" class="caja-select">
                                <option value="12" <?php selected($options['packing_priority'] ?? '12', '12'); ?>><?php _e('Priorizar Cajas de 12 primero (Experiencia Premium, recomendada)', 'emp-caja'); ?></option>
                                <option value="6" <?php selected($options['packing_priority'] ?? '12', '6'); ?>><?php _e('Priorizar Cajas de 6 primero', 'emp-caja'); ?></option>
                            </select>
                            <p class="description">
                                <?php _e('Define cómo se agrupan los alfajores sueltos en el carrito. Si se eligen 15 alfajores con prioridad 12, se formará 1 Caja de 12 y quedarán 3 alfajores sueltos.', 'emp-caja'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Sincronización de Stock', 'emp-caja'); ?></th>
                        <td>
                            <input type="hidden" name="batllie_caja_options[packing_stock_sync]" value="no" />
                            <label class="caja-checkbox-label">
                                <input type="checkbox" name="batllie_caja_options[packing_stock_sync]" value="yes" <?php checked($options['packing_stock_sync'] ?? 'yes', 'yes'); ?> />
                                <span><?php _e('Descontar automáticamente el stock de las cajas de empaque al procesar o confirmar ventas.', 'emp-caja'); ?></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Producto: Caja Oficial x 6 unidades', 'emp-caja'); ?></th>
                        <td>
                            <select name="batllie_caja_options[box_6_product_id]" class="caja-select" style="max-width: 350px;">
                                <?php foreach ($candidates as $cand): ?>
                                    <option value="<?php echo esc_attr($cand['id']); ?>" <?php selected($cand['id'], $b6_id); ?>>
                                        <?php echo esc_html($cand['name'] . ' (ID: ' . $cand['id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Producto de WooCommerce tomado como referencia para descontar y controlar el stock de las cajas de 6 unidades.', 'emp-caja'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Stock disponible: Caja Oficial x 6 unidades', 'emp-caja'); ?></th>
                        <td>
                            <input type="number" min="0" name="batllie_caja_options[stock_box_6]" value="<?php echo esc_attr($stock_6); ?>" class="small-text caja-input" /> 
                            <span><?php _e('cajas físicas de 6 disponibles en depósito.', 'emp-caja'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Producto: Caja Oficial x 12 unidades', 'emp-caja'); ?></th>
                        <td>
                            <select name="batllie_caja_options[box_12_product_id]" class="caja-select" style="max-width: 350px;">
                                <?php foreach ($candidates as $cand): ?>
                                    <option value="<?php echo esc_attr($cand['id']); ?>" <?php selected($cand['id'], $b12_id); ?>>
                                        <?php echo esc_html($cand['name'] . ' (ID: ' . $cand['id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Producto de WooCommerce tomado como referencia para descontar y controlar el stock de las cajas de 12 unidades.', 'emp-caja'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Stock disponible: Caja Oficial x 12 unidades', 'emp-caja'); ?></th>
                        <td>
                            <input type="number" min="0" name="batllie_caja_options[stock_box_12]" value="<?php echo esc_attr($stock_12); ?>" class="small-text caja-input" /> 
                            <span><?php _e('cajas físicas de 12 disponibles en depósito.', 'emp-caja'); ?></span>
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
