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
$current_label = isset($tracking['step_label']) ? $tracking['step_label'] : __('Batllie está preparando tu pedido', 'emp-caja');
$order_id = isset($tracking['order_id']) ? $tracking['order_id'] : $order->get_id();
$order_key = isset($tracking['order_key']) ? $tracking['order_key'] : $order->get_order_key();
$order_number = isset($tracking['order_number']) ? $tracking['order_number'] : $order->get_order_number();

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
#batllie-order-tracking-<?php echo esc_attr($order_id); ?> {
    --caja-bg: <?php echo esc_attr($options['bg_color']); ?>;
    --caja-header-bg: <?php echo esc_attr($options['header_bg']); ?>;
    --caja-card-bg: <?php echo esc_attr($options['card_bg']); ?>;
    --caja-card-border: <?php echo esc_attr($options['card_border']); ?>;
    --caja-text: <?php echo esc_attr($options['text_color']); ?>;
    --caja-text-muted: <?php echo esc_attr($options['text_muted']); ?>;
    --caja-primary: <?php echo esc_attr($options['primary_color']); ?>;
    --caja-primary-hover: <?php echo esc_attr($options['primary_hover']); ?>;
    --caja-status-processing: <?php echo esc_attr($options['status_processing']); ?>;
    --caja-status-enviando: <?php echo esc_attr($options['status_enviando']); ?>;
    --caja-status-completed: <?php echo esc_attr($options['status_completed']); ?>;
}
</style>

<div id="batllie-order-tracking-<?php echo esc_attr($order_id); ?>" 
     class="batllie-order-tracking-card" 
     data-order-id="<?php echo esc_attr($order_id); ?>" 
     data-order-key="<?php echo esc_attr($order_key); ?>" 
     data-current-step="<?php echo esc_attr($current_step); ?>">

    <!-- Encabezado con título e indicadores en vivo -->
    <div class="batllie-tracking-header">
        <div class="batllie-tracking-title-group">
            <h3 class="batllie-tracking-title">
                <?php printf(__('Seguimiento de tu Pedido #%s', 'emp-caja'), esc_html($order_number)); ?>
            </h3>
            <p class="batllie-tracking-subtitle">
                <?php _e('Te informamos en tiempo real sobre cada etapa de tu entrega', 'emp-caja'); ?>
            </p>
        </div>
        <div class="batllie-tracking-badges">
            <div class="batllie-tracking-live-pill">
                <span class="batllie-tracking-live-dot"></span>
                <?php _e('Actualización en vivo', 'emp-caja'); ?>
            </div>
        </div>
    </div>

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
                <?php _e('Batllie está preparando tu pedido', 'emp-caja'); ?>
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

    <!-- Sección Comprobante Pendiente (Transferencia sin pagar aún) -->
    <?php if (!empty($tracking['show_receipt_pending'])) : ?>
    <div class="batllie-tracking-receipt-pending" id="batllie-receipt-pending-<?php echo esc_attr($order_id); ?>">
        <div class="batllie-receipt-content">
            <h4 class="batllie-receipt-title">
                <?php _e('Comprobante pendiente', 'emp-caja'); ?>
            </h4>
            <a href="<?php echo esc_url($tracking['whatsapp_url']); ?>" 
               target="_blank" 
               rel="noopener noreferrer" 
               class="batllie-receipt-wa-btn">
                <svg class="batllie-receipt-wa-icon" viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                    <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.816 9.816 0 0 0 12.04 2zm.01 1.67c2.2 0 4.26.86 5.82 2.41a8.17 8.17 0 0 1 2.41 5.83c0 4.54-3.7 8.24-8.24 8.24-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24zm-3.52 4.75c-.19 0-.5.07-.76.35-.26.29-1 1-1 2.42 0 1.43 1.04 2.8 1.18 3 .15.19 2.05 3.19 5.01 4.35 2.46.96 2.96.77 3.49.72.53-.05 1.71-.7 1.95-1.37.24-.68.24-1.26.17-1.38-.07-.11-.26-.18-.55-.33-.29-.15-1.71-.84-1.98-.94-.26-.09-.45-.15-.65.15-.19.29-.75.94-.92 1.13-.17.19-.34.22-.63.07-.29-.15-1.22-.45-2.33-1.44-.86-.77-1.44-1.72-1.61-2.01-.17-.29-.02-.45.13-.59.13-.13.29-.34.43-.51.15-.17.19-.29.29-.48.1-.19.05-.36-.02-.51-.08-.15-.65-1.57-.89-2.15-.24-.57-.48-.49-.66-.5-.17-.01-.36-.01-.55-.01z"/>
                </svg>
                <span><?php _e('Enviar comprobante', 'emp-caja'); ?></span>
            </a>
        </div>
    </div>
    <?php endif; ?>

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
