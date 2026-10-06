<?php
require_once('wp-load.php');

$checkout_id = wc_get_page_id('checkout');
$checkout_post = get_post($checkout_id);

echo "Checkout ID: $checkout_id\n";
echo "Checkout Title: " . ($checkout_post ? $checkout_post->post_title : 'N/A') . "\n";
echo "Has shortcode [woocommerce_checkout]: " . (has_shortcode($checkout_post->post_content, 'woocommerce_checkout') ? 'YES' : 'NO') . "\n";
echo "Has block woocommerce/checkout: " . (has_block('woocommerce/checkout', $checkout_post) ? 'YES' : 'NO') . "\n";
echo "Post content preview:\n" . substr($checkout_post->post_content, 0, 300) . "\n";
