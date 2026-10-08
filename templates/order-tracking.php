<?php
/**
 * Plantilla de Seguimiento de Pedidos en Vivo (Customer Facing)
 * Se muestra en la pantalla de compra realizada (WooCommerce Order Received) y en Ver Pedido.
 * 
 * Variables disponibles:
 * @var WC_Order $order
 * @var array $tracking (order_id, order_number, step, step_label, order_key)
 * @var array $options (paleta de colores dinámica de Batllie Caja)
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_step = isset($tracking['step']) ? intval($tracking['step']) : 1;
$current_label = isset($tracking['step_label']) ? $tracking['step_label'] : __('Preparando tu pedido', 'emp-caja');
$order_id = isset($tracking['order_id']) ? $tracking['order_id'] : $order->get_id();
$order_key = isset($tracking['order_key']) ? $tracking['order_key'] : $order->get_order_key();
$order_number = isset($tracking['order_number']) ? $tracking['order_number'] : $order->get_order_number();

$current_shipping = isset($tracking['shipping_status']) ? $tracking['shipping_status'] : '';
$is_door = ($current_shipping === 'en_puerta') || !empty($tracking['is_door']);
$door_active_class = $is_door ? ' is-door-active' : '';

// Porcentaje de la barra de avance
$fill_percent = 0;
if ($current_step === 2) {
    $fill_percent = 33.33;
} elseif ($current_step === 3) {
    $fill_percent = 66.66;
} elseif ($current_step >= 4) {
    $fill_percent = 100;
}

$checkmark_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
?>

<!-- Variables dinámicas de color vinculadas a la configuración de la caja -->
<style>
:root, #batllie-order-tracking-<?php echo esc_attr($order_id); ?> {
    --caja-bg: <?php echo esc_attr($options['bg_color']); ?>;
    --caja-header-bg: <?php echo esc_attr($options['header_bg']); ?>;
    --caja-card-bg: <?php echo esc_attr($options['card_bg']); ?>;
    --caja-card-border: <?php echo esc_attr($options['card_border']); ?>;
    --caja-text: <?php echo esc_attr($options['text_color']); ?>;
    --caja-text-muted: <?php echo esc_attr($options['text_muted']); ?>;
    --caja-primary: <?php echo esc_attr($options['primary_color']); ?>;
    --caja-primary-hover: <?php echo esc_attr($options['primary_hover']); ?>;
    --caja-btn-text: <?php echo esc_attr($options['btn_text']); ?>;
    --caja-status-pending: <?php echo esc_attr($options['status_pending']); ?>;
    --caja-status-processing: <?php echo esc_attr($options['status_processing']); ?>;
    --caja-status-enviando: <?php echo esc_attr($options['status_enviando']); ?>;
    --caja-status-completed: <?php echo esc_attr($options['status_completed']); ?>;
    --caja-status-recibido-problema: <?php echo esc_attr($options['status_recibido_problema']); ?>;
    --caja-status-cancelled: <?php echo esc_attr($options['status_cancelled']); ?>;
    --caja-status-refunded: <?php echo esc_attr($options['status_refunded']); ?>;
}
</style>

<div id="batllie-order-tracking-<?php echo esc_attr($order_id); ?>" 
     class="batllie-order-tracking-card<?php echo esc_attr($door_active_class); ?>" 
     data-order-id="<?php echo esc_attr($order_id); ?>" 
     data-order-number="<?php echo esc_attr($order_number); ?>" 
     data-order-key="<?php echo esc_attr($order_key); ?>" 
     data-current-step="<?php echo esc_attr($current_step); ?>"
     data-order-status="<?php echo esc_attr(isset($tracking['status']) ? $tracking['status'] : ''); ?>"
     data-shipping-status="<?php echo esc_attr($current_shipping); ?>"
     data-is-door="<?php echo $is_door ? '1' : '0'; ?>"
     data-is-paid="<?php echo !empty($tracking['is_paid']) ? '1' : '0'; ?>">

    <!-- Barra de Selector Rápido Multi-Pedido (Opción 1) -->
    <?php
    $all_recent_orders = Batllie_Caja_Tracking::get_customer_recent_orders();
    $has_multiple_orders = count($all_recent_orders) > 1;
    ?>
    <div class="batllie-tracking-switcher" id="batllie-orders-switcher" style="<?php echo $has_multiple_orders ? '' : 'display:none;'; ?>">
        <div class="batllie-switcher-header">
            <span class="batllie-switcher-title">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><polyline points="3.29 7 12 12 20.71 7"/></svg>
                <?php _e('Tus pedidos recientes:', 'emp-caja'); ?>
            </span>
            <button type="button" class="batllie-switcher-all-btn batllie-open-hub-trigger" title="<?php esc_attr_e('Ver todos mis pedidos', 'emp-caja'); ?>">
                <?php _e('Ver todos', 'emp-caja'); ?> (<span class="batllie-switcher-count"><?php echo count($all_recent_orders); ?></span>) &rarr;
            </button>
        </div>
        <div class="batllie-switcher-chips-scroll">
            <?php foreach ($all_recent_orders as $ord_item) : 
                $is_current = (intval($ord_item['id']) === intval($order_id));
                $chip_cls = $is_current ? 'is-current' : '';
                $step_num = isset($ord_item['step']) ? intval($ord_item['step']) : 1;
            ?>
                <a href="<?php echo esc_url($ord_item['url']); ?>" class="batllie-switcher-chip <?php echo esc_attr($chip_cls); ?>" data-order-id="<?php echo esc_attr($ord_item['id']); ?>">
                    <span class="batllie-chip-dot step-<?php echo esc_attr($step_num); ?>"></span>
                    <strong class="batllie-chip-num">#<?php echo esc_html($ord_item['number']); ?></strong>
                    <span class="batllie-chip-step-txt"><?php echo esc_html($ord_item['step_label']); ?></span>
                    <?php if ($is_current) : ?>
                        <span class="batllie-chip-current-badge"><?php _e('Actual', 'emp-caja'); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Cartel Especial: El repartidor está en la puerta -->
    <div class="batllie-tracking-door-screen" id="batllie-door-screen-<?php echo esc_attr($order_id); ?>" style="<?php echo $is_door ? 'display:flex;' : 'display:none;'; ?>">
        <div class="batllie-door-beacon">
            <div class="batllie-door-icon-circle">
                <svg class="batllie-door-bell-icon" viewBox="0 0 24 24" width="46" height="46" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
            </div>
            <div class="batllie-door-ripple-1"></div>
            <div class="batllie-door-ripple-2"></div>
        </div>
        <h2 class="batllie-door-title"><?php _e('El repartidor está en la puerta', 'emp-caja'); ?></h2>
        <p class="batllie-door-subtitle"><?php _e('Que disfrutes tu pedido', 'emp-caja'); ?></p>
    </div>

    <!-- Encabezado con título e indicación de tiempo real -->
    <div class="batllie-tracking-header">
        <div class="batllie-tracking-title-group">
            <h3 class="batllie-tracking-title">
                <?php printf(__('Seguimiento de tu Pedido #%s', 'emp-caja'), esc_html($order_number)); ?>
            </h3>
            <p class="batllie-tracking-subtitle">
                <?php _e('Mantené abierta esta ventana para saber en tiempo real de tu pedido', 'emp-caja'); ?>
            </p>
        </div>
    </div>

    <!-- Sección Subir Comprobante (Transferencia sin pagar aún) -->
    <?php if (!empty($tracking['show_receipt_pending'])) : ?>
    <?php
    $display_total = !empty($tracking['order_total']) ? $tracking['order_total'] : (isset($order) && is_a($order, 'WC_Order') ? $order->get_formatted_order_total() : '');
    $display_alias = !empty($tracking['bacs_alias']) ? $tracking['bacs_alias'] : '';
    if (empty($display_alias)) {
        $bacs_accs = get_option('woocommerce_bacs_accounts', array());
        if (!empty($bacs_accs) && is_array($bacs_accs) && !empty($bacs_accs[0]['account_name'])) {
            $display_alias = preg_replace('/^alias:\s*/i', '', trim($bacs_accs[0]['account_name']));
        }
    }
    ?>
    <div class="batllie-tracking-receipt-pending" id="batllie-receipt-pending-<?php echo esc_attr($order_id); ?>">
        <div class="batllie-receipt-content">
            <h4 class="batllie-receipt-title">
                <?php _e('Subir comprobante', 'emp-caja'); ?>
            </h4>

            <div class="batllie-receipt-meta-box">
                <?php if (!empty($display_total)) : ?>
                <div class="batllie-receipt-meta-item batllie-receipt-meta-total">
                    <span class="batllie-receipt-meta-label"><?php _e('Total:', 'emp-caja'); ?></span>
                    <span class="batllie-receipt-meta-value batllie-receipt-total-val"><?php echo wp_kses_post($display_total); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($display_alias)) : ?>
                <div class="batllie-receipt-meta-item batllie-receipt-meta-alias">
                    <span class="batllie-receipt-meta-label"><?php _e('Alias:', 'emp-caja'); ?></span>
                    <span class="batllie-receipt-meta-value batllie-receipt-alias-val" id="batllie-alias-val-<?php echo esc_attr($order_id); ?>"><?php echo esc_html($display_alias); ?></span>
                    <button type="button" 
                            class="batllie-receipt-copy-btn" 
                            data-copy-text="<?php echo esc_attr($display_alias); ?>"
                            title="<?php esc_attr_e('Copiar alias', 'emp-caja'); ?>"
                            aria-label="<?php esc_attr_e('Copiar alias', 'emp-caja'); ?>">
                        <svg class="batllie-copy-icon" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        <span class="batllie-copy-label"><?php _e('Copiar', 'emp-caja'); ?></span>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <a href="<?php echo esc_attr($tracking['whatsapp_url']); ?>" 
               target="_blank" 
               rel="noopener noreferrer" 
               class="batllie-receipt-wa-btn">
                <svg class="batllie-receipt-wa-icon" viewBox="0 0 448 512" width="22" height="22" fill="currentColor" aria-hidden="true">
                    <path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/>
                </svg>
                <span><?php _e('Enviar comprobante', 'emp-caja'); ?></span>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stepper de 4 Etapas -->
    <div class="batllie-tracking-stepper">
        <!-- Pista de fondo y relleno con gradiente -->
        <div class="batllie-tracking-track-bg">
            <div class="batllie-tracking-track-fill" style="width: <?php echo esc_attr($fill_percent); ?>%;"></div>
        </div>

        <!-- 1. En preparación -->
        <?php
        $cls_1 = 'is-pending';
        $badge_1 = '1';
        if ($current_step > 1) {
            $cls_1 = 'is-completed';
            $badge_1 = $checkmark_svg;
        } elseif ($current_step === 1) {
            $cls_1 = 'is-active';
        }
        ?>
        <div class="batllie-tracking-step <?php echo esc_attr($cls_1); ?>" data-step="1">
            <div class="batllie-step-icon-wrap">
                <!-- Icono Gorro de Chef / Cocina -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"/>
                    <line x1="6" y1="17" x2="18" y2="17"/>
                </svg>
                <div class="batllie-step-badge"><?php echo $badge_1; ?></div>
            </div>
            <div class="batllie-step-text">
                <?php echo esc_html(__('Preparando tu pedido', 'emp-caja')); ?>
            </div>
        </div>

        <!-- 2. Esperando repartidor -->
        <?php
        $cls_2 = 'is-pending';
        $badge_2 = '2';
        if ($current_step > 2) {
            $cls_2 = 'is-completed';
            $badge_2 = $checkmark_svg;
        } elseif ($current_step === 2) {
            $cls_2 = 'is-active';
        }
        ?>
        <div class="batllie-tracking-step <?php echo esc_attr($cls_2); ?>" data-step="2">
            <div class="batllie-step-icon-wrap">
                <!-- Icono Paquete con Reloj de Espera -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16.5 9.4 7.55 4.24a1.78 1.78 0 0 0-2.5 1.55v8.42a1.78 1.78 0 0 0 .86 1.53l6.59 3.8"/>
                    <polyline points="3.29 7 12 12 20.71 7"/>
                    <line x1="12" y1="22" x2="12" y2="12"/>
                    <circle cx="18" cy="18" r="4"/>
                    <polyline points="18 16 18 18 19.5 19"/>
                </svg>
                <div class="batllie-step-badge"><?php echo $badge_2; ?></div>
            </div>
            <div class="batllie-step-text">
                <?php _e('Esperando que el repartidor recoja tu pedido', 'emp-caja'); ?>
            </div>
        </div>

        <!-- 3. Enviando -->
        <?php
        $cls_3 = 'is-pending';
        $badge_3 = '3';
        if ($current_step > 3) {
            $cls_3 = 'is-completed';
            $badge_3 = $checkmark_svg;
        } elseif ($current_step === 3) {
            $cls_3 = 'is-active';
        }
        ?>
        <div class="batllie-tracking-step <?php echo esc_attr($cls_3); ?>" data-step="3">
            <div class="batllie-step-icon-wrap">
                <!-- Icono Moto de Reparto -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="5.5" cy="17.5" r="3.5"/>
                    <circle cx="18.5" cy="17.5" r="3.5"/>
                    <path d="M15 6h-3l-3 6h6l2-3h3"/>
                    <path d="M9 17h6"/>
                    <circle cx="13" cy="5" r="1"/>
                </svg>
                <div class="batllie-step-badge"><?php echo $badge_3; ?></div>
            </div>
            <div class="batllie-step-text">
                <?php _e('El repartidor está enviando tu pedido', 'emp-caja'); ?>
            </div>
        </div>

        <!-- 4. Pedido recibido -->
        <?php
        $cls_4 = 'is-pending';
        $badge_4 = '4';
        if ($current_step >= 4) {
            $cls_4 = 'is-active is-completed';
            $badge_4 = $checkmark_svg;
        }
        ?>
        <div class="batllie-tracking-step <?php echo esc_attr($cls_4); ?>" data-step="4">
            <div class="batllie-step-icon-wrap">
                <!-- Icono Caja de Entrega con Sonrisa -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                    <path d="M3.29 7 12 12 20.71 7"/>
                    <line x1="12" y1="22" x2="12" y2="12"/>
                    <path d="M9.5 15.5c1.2 1 3.8 1 5 0"/>
                </svg>
                <div class="batllie-step-badge"><?php echo $badge_4; ?></div>
            </div>
            <div class="batllie-step-text">
                <?php _e('Pedido recibido, que lo disfrutes', 'emp-caja'); ?>
            </div>
        </div>
    </div>

    <!-- Barra inferior informativa -->
    <div class="batllie-tracking-footer-bar">
        <div class="batllie-tracking-current-status">
            <span><?php _e('Estado actual:', 'emp-caja'); ?></span>
            <span class="status-highlight"><?php echo esc_html($current_label); ?></span>
        </div>
        <div class="batllie-tracking-live-tag">
            <span>⚡</span> <?php _e('Sincronizado con la cocina y repartidores', 'emp-caja'); ?>
        </div>
    </div>
</div>
