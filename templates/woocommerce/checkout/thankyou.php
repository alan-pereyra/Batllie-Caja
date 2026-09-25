<?php
/**
 * Thankyou page customizada para Batllie Caja
 * Reemplaza la sección "Gracias por tu pedido" y resumen inicial por el widget de seguimiento en vivo al inicio
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.1.0
 */

defined('ABSPATH') || exit;
?>

<div class="woocommerce-order">

	<?php
	if ($order) :

		do_action('woocommerce_before_thankyou', $order->get_id());
		?>

		<?php if ($order->has_status('failed')) : ?>

			<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed"><?php esc_html_e('Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce'); ?></p>

			<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
				<a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="button pay"><?php esc_html_e('Pay', 'woocommerce'); ?></a>
				<?php if (is_user_logged_in()) : ?>
					<a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="button pay"><?php esc_html_e('My account', 'woocommerce'); ?></a>
				<?php endif; ?>
			</p>

		<?php else : ?>

			<?php
			// Renderizar el widget de seguimiento de pedido en vivo en la parte superior reemplazando "Gracias por tu pedido"
			Batllie_Caja_Tracking::render_tracking_widget($order->get_id());
			?>

			<style>
			@media (max-width: 768px) {
				.woocommerce-customer-details,
				.woocommerce-order-details + .woocommerce-customer-details {
					width: 100% !important;
					margin-top: 30px !important;
					padding: 0 !important;
					box-sizing: border-box !important;
					clear: both !important;
				}
				.woocommerce-customer-details .woocommerce-columns--addresses,
				.woocommerce-customer-details .woocommerce-columns--2,
				.woocommerce-customer-details .woocommerce-columns,
				.woocommerce-customer-details .col2-set,
				.woocommerce-columns--addresses.col2-set,
				.woocommerce-columns.col2-set {
					display: flex !important;
					flex-direction: column !important;
					width: 100% !important;
					max-width: 100% !important;
					float: none !important;
					clear: both !important;
					gap: 24px !important;
					margin-left: 0 !important;
					margin-right: 0 !important;
					box-sizing: border-box !important;
				}
				.woocommerce-customer-details .woocommerce-column--shipping-address,
				.woocommerce-customer-details .col-2.woocommerce-column--shipping-address,
				.woocommerce-customer-details .col-2,
				.woocommerce-columns--addresses .woocommerce-column--shipping-address,
				.woocommerce-columns--addresses .col-2 {
					order: 1 !important;
					width: 100% !important;
					max-width: 100% !important;
					min-width: 100% !important;
					float: none !important;
					margin: 0 !important;
					box-sizing: border-box !important;
				}
				.woocommerce-customer-details .woocommerce-column--billing-address,
				.woocommerce-customer-details .col-1.woocommerce-column--billing-address,
				.woocommerce-customer-details .col-1,
				.woocommerce-columns--addresses .woocommerce-column--billing-address,
				.woocommerce-columns--addresses .col-1 {
					order: 2 !important;
					width: 100% !important;
					max-width: 100% !important;
					min-width: 100% !important;
					float: none !important;
					margin: 0 !important;
					box-sizing: border-box !important;
				}
				.woocommerce-customer-details .woocommerce-column,
				.woocommerce-customer-details address {
					width: 100% !important;
					max-width: 100% !important;
					min-width: 100% !important;
					box-sizing: border-box !important;
					display: block !important;
				}
				.woocommerce-customer-details .woocommerce-column__title {
					width: 100% !important;
					margin-bottom: 12px !important;
					font-size: 1.35rem !important;
					white-space: normal !important;
				}
			}
			</style>

		<?php endif; ?>

		<?php do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); ?>
		<?php do_action('woocommerce_thankyou', $order->get_id()); ?>

	<?php else : ?>

		<?php
		// Si no hay orden específica cargada, no se muestra el mensaje genérico
		?>

	<?php endif; ?>

</div>
