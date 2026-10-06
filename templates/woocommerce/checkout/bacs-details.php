<?php
/**
 * Bank Details (BACS) Template limpia para Batllie
 * Muestra exclusivamente 'Nuestros detalles bancarios', Alias y Banco
 *
 * @package WooCommerce\Templates
 * @version 8.1.0
 */

defined('ABSPATH') || exit;

if (!empty($bacs_accounts)) :
?>
	<section class="woocommerce-bacs-bank-details">
		<h2 class="wc-bacs-bank-details-heading"><?php esc_html_e('Nuestros detalles bancarios', 'emp-caja'); ?></h2>

		<?php
		$i = -1;
		foreach ($bacs_accounts as $account) :
			$i++;

			$account_name   = !empty($account['account_name']) ? esc_attr(wp_unslash($account['account_name'])) : '';
			$bank_name      = !empty($account['bank_name']) ? esc_attr(wp_unslash($account['bank_name'])) : '';
			$account_number = !empty($account['account_number']) ? esc_attr(wp_unslash($account['account_number'])) : '';
			?>
			<?php if (!empty($account_name)) : ?>
				<h3 class="wc-bacs-bank-details-account-name"><?php echo esc_html($account_name); ?></h3>
			<?php endif; ?>

			<?php if (!empty($bank_name) || !empty($account_number)) : ?>
				<ul class="wc-bacs-bank-details order_details bacs_details">
					<?php if (!empty($bank_name)) : ?>
						<li class="bank_name">
							<?php esc_html_e('Banco:', 'emp-caja'); ?> <strong><?php echo esc_html($bank_name); ?></strong>
						</li>
					<?php endif; ?>
					<?php if (!empty($account_number)) : ?>
						<li class="account_number">
							<?php esc_html_e('Número de cuenta:', 'emp-caja'); ?> <strong class="emp-arial-sans"><?php echo esc_html($account_number); ?></strong>
						</li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		<?php endforeach; ?>
	</section>
<?php endif; ?>
