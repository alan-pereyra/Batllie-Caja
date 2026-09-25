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
