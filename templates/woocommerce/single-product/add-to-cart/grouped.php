<?php
/**
 * Grouped product add to cart override for Batllie Caja
 * Soporta modo 'Caja personalizable' y modo 'Combo predeterminado / fijo'.
 */

defined( 'ABSPATH' ) || exit;

global $product, $post;

do_action( 'woocommerce_before_add_to_cart_form' ); ?>

<form class="cart grouped_form<?php echo (class_exists('Batllie_Caja_Grouped') && Batllie_Caja_Grouped::is_predefined_combo($product)) ? ' batllie-is-predefined-combo' : ''; ?>" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype='multipart/form-data'>
    <table cellspacing="0" class="woocommerce-grouped-product-list group_table<?php echo (class_exists('Batllie_Caja_Grouped') && Batllie_Caja_Grouped::is_predefined_combo($product)) ? ' batllie-is-predefined-combo-table' : ''; ?>">
        <tbody>
            <?php
            $previous_post           = $post;
            $grouped_product_child_ids = $product->get_children();
            $show_add_to_cart_button = false;
            $is_predefined_combo     = class_exists('Batllie_Caja_Grouped') && Batllie_Caja_Grouped::is_predefined_combo($product);
            $predefined_quantities   = $is_predefined_combo ? Batllie_Caja_Grouped::get_predefined_quantities($product) : array();

            do_action( 'woocommerce_grouped_product_list_before', $grouped_product_child_ids, $product );

            foreach ( $grouped_product_child_ids as $grouped_product_child_id ) {
                $grouped_product_child = wc_get_product( $grouped_product_child_id );

                if ( ! $grouped_product_child || ! $grouped_product_child->is_type( 'simple' ) ) {
                    continue;
                }

                $child_id  = $grouped_product_child->get_id();
                $fixed_qty = isset( $predefined_quantities[ $child_id ] ) ? absint( $predefined_quantities[ $child_id ] ) : 0;

                // En combos predeterminados/fijos: los que tengan 0 unidades directamente NO deben aparecer
                if ( $is_predefined_combo && $fixed_qty <= 0 ) {
                    continue;
                }

                // En cajas personalizables: los alfajores ocultos no deben ser seleccionables
                if ( ! $is_predefined_combo ) {
                    $child_vis = method_exists( $grouped_product_child, 'get_catalog_visibility' ) ? $grouped_product_child->get_catalog_visibility() : 'visible';
                    if ( $child_vis === 'hidden' || ! $grouped_product_child->is_visible() ) {
                        continue;
                    }
                }

                if ( $grouped_product_child->is_purchasable() ) {
                    $show_add_to_cart_button = true;
                }

                // Clases del elemento de fila
                $list_item_class = 'woocommerce-grouped-product-list-item ' . ( $is_predefined_combo ? 'batllie-combo-fixed-item ' : '' );
                $list_item_class .= implode( ' ', wc_get_product_class( '', $grouped_product_child ) );
                ?>
                <tr id="product-<?php echo esc_attr( $child_id ); ?>" class="<?php echo esc_attr( $list_item_class ); ?>">
                    <td class="woocommerce-grouped-product-list-item__quantity">
                        <?php if ( $is_predefined_combo ) : ?>
                            <!-- Cantidad fija del combo (no editable) -->
                            <div class="batllie-combo-fixed-qty-wrap">
                                <span class="batllie-combo-fixed-badge"><?php echo esc_html( $fixed_qty ); ?> u.</span>
                                <input type="hidden" name="quantity[<?php echo esc_attr( $child_id ); ?>]" value="<?php echo esc_attr( $fixed_qty ); ?>" class="qty" />
                            </div>
                        <?php elseif ( ! $grouped_product_child->is_purchasable() || $grouped_product_child->has_options() || ! $grouped_product_child->is_in_stock() ) : ?>
                            <?php woocommerce_template_loop_add_to_cart(); ?>
                        <?php elseif ( $grouped_product_child->is_sold_individually() ) : ?>
                            <input type="checkbox" name="<?php echo esc_attr( 'quantity[' . $child_id . ']' ); ?>" value="1" class="wc-grouped-product-add-to-cart-checkbox" id="<?php echo esc_attr( 'quantity-' . $child_id ); ?>" />
                            <label for="<?php echo esc_attr( 'quantity-' . $child_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Buy one of this item', 'woocommerce' ); ?></label>
                        <?php else : ?>
                            <?php
                            do_action( 'woocommerce_before_add_to_cart_quantity' );

                            woocommerce_quantity_input(
                                array(
                                    'input_name'  => 'quantity[' . $child_id . ']',
                                    'input_value' => isset( $_POST['quantity'][ $child_id ] ) ? wc_stock_amount( wc_clean( wp_unslash( $_POST['quantity'][ $child_id ] ) ) ) : '',
                                    'min_value'   => apply_filters( 'woocommerce_quantity_input_min', 0, $grouped_product_child ),
                                    'max_value'   => apply_filters( 'woocommerce_quantity_input_max', $grouped_product_child->get_max_purchase_quantity(), $grouped_product_child ),
                                    'placeholder' => '0',
                                )
                            );

                            do_action( 'woocommerce_after_add_to_cart_quantity' );
                            ?>
                        <?php endif; ?>
                    </td>
                    <td class="woocommerce-grouped-product-list-item__label">
                        <label for="product-<?php echo esc_attr( $child_id ); ?>">
                            <?php echo $grouped_product_child->is_visible() ? '<a href="' . esc_url( apply_filters( 'woocommerce_grouped_product_list_link', $grouped_product_child->get_permalink(), $grouped_product_child ) ) . '">' . $grouped_product_child->get_name() . '</a>' : $grouped_product_child->get_name(); ?>
                        </label>
                    </td>
                    <?php if ( ! $is_predefined_combo ) : ?>
                        <?php do_action( 'woocommerce_grouped_product_list_before_price', $grouped_product_child ); ?>
                        <td class="woocommerce-grouped-product-list-item__price">
                            <?php
                            echo $grouped_product_child->get_price_html();
                            echo wc_get_formatted_variation( $grouped_product_child, true );
                            ?>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php
            }
            $post = $previous_post;
            setup_postdata( $post );

            do_action( 'woocommerce_grouped_product_list_after', $grouped_product_child_ids, $product );
            ?>
        </tbody>
    </table>

    <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" />

    <?php if ( $show_add_to_cart_button ) : ?>
        <?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

        <button type="submit" class="single_add_to_cart_button button alt"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>

        <?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
    <?php endif; ?>
</form>

<?php do_action( 'woocommerce_after_add_to_cart_form' ); ?>
