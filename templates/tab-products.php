<?php
/**
 * Pestaña de Gestión de Productos
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="caja-products-container">
    <!-- Barra Superior de Productos -->
    <div class="caja-products-toolbar">
        <div class="caja-products-search">
            <div class="caja-search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="caja-search-products" placeholder="<?php esc_attr_e('Buscar producto por nombre o SKU...', 'emp-caja'); ?>" />
            </div>

            <select id="caja-filter-product-cat" class="caja-select">
                <option value=""><?php _e('Todas las categorías', 'emp-caja'); ?></option>
            </select>
        </div>

        <button type="button" class="caja-btn caja-btn-primary" id="caja-btn-open-new-product">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <span><?php _e('Cargar Nuevo Producto', 'emp-caja'); ?></span>
        </button>
    </div>

    <!-- Indicador de carga -->
    <div id="caja-products-loading" class="caja-loading-state" style="display:none;">
        <div class="caja-spinner"></div>
        <p><?php _e('Cargando catálogo de productos...', 'emp-caja'); ?></p>
    </div>

    <!-- Grilla/Tabla de Productos -->
    <div id="caja-products-grid" class="caja-products-table-wrapper">
        <table class="caja-table">
            <thead>
                <tr>
                    <th style="width: 60px;"><?php _e('Foto', 'emp-caja'); ?></th>
                    <th><?php _e('Producto', 'emp-caja'); ?></th>
                    <th><?php _e('SKU', 'emp-caja'); ?></th>
                    <th><?php _e('Categoría', 'emp-caja'); ?></th>
                    <th><?php _e('Precio', 'emp-caja'); ?></th>
                    <th><?php _e('Stock', 'emp-caja'); ?></th>
                </tr>
            </thead>
            <tbody id="caja-products-tbody">
                <!-- Se rellena dinámicamente -->
            </tbody>
        </table>
    </div>

    <!-- Estado vacío de productos -->
    <div id="caja-products-empty" class="caja-empty-state" style="display:none;">
        <h3><?php _e('No se encontraron productos', 'emp-caja'); ?></h3>
        <p><?php _e('Puedes agregar productos pulsando en "Cargar Nuevo Producto".', 'emp-caja'); ?></p>
    </div>

    <!-- Modal para Cargar Nuevo Producto -->
    <div id="caja-modal-new-product" class="caja-modal" style="display:none;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog">
            <div class="caja-modal-header">
                <h3><?php _e('➕ Cargar Nuevo Producto a WooCommerce', 'emp-caja'); ?></h3>
                <button type="button" class="caja-modal-close" id="caja-modal-close-btn">&times;</button>
            </div>

            <form id="caja-new-product-form" class="caja-modal-body">
                <div id="caja-new-product-error" class="caja-alert caja-alert-danger" style="display:none;"></div>

                <div class="caja-form-group">
                    <label for="new-prod-name"><?php _e('Nombre del Producto *', 'emp-caja'); ?></label>
                    <input type="text" id="new-prod-name" name="name" required placeholder="<?php esc_attr_e('Ej. Combo Hamburguesa Doble', 'emp-caja'); ?>" />
                </div>

                <div class="caja-form-row">
                    <div class="caja-form-group caja-col">
                        <label for="new-prod-price"><?php _e('Precio Regular *', 'emp-caja'); ?></label>
                        <input type="number" step="0.01" min="0" id="new-prod-price" name="regular_price" required placeholder="0.00" />
                    </div>
                    <div class="caja-form-group caja-col">
                        <label for="new-prod-sale-price"><?php _e('Precio de Oferta (Opcional)', 'emp-caja'); ?></label>
                        <input type="number" step="0.01" min="0" id="new-prod-sale-price" name="sale_price" placeholder="0.00" />
                    </div>
                </div>

                <div class="caja-form-row">
                    <div class="caja-form-group caja-col">
                        <label for="new-prod-category"><?php _e('Categoría', 'emp-caja'); ?></label>
                        <select id="new-prod-category" name="category_id" class="caja-select">
                            <option value="0"><?php _e('-- Seleccionar Categoría --', 'emp-caja'); ?></option>
                        </select>
                    </div>
                    <div class="caja-form-group caja-col">
                        <label for="new-prod-sku"><?php _e('Código / SKU (Opcional)', 'emp-caja'); ?></label>
                        <input type="text" id="new-prod-sku" name="sku" placeholder="PROD-001" />
                    </div>
                </div>

                <div class="caja-form-group">
                    <label class="caja-checkbox-label">
                        <input type="checkbox" id="new-prod-manage-stock" name="manage_stock" value="yes" />
                        <span><?php _e('¿Gestionar inventario / stock para este producto?', 'emp-caja'); ?></span>
                    </label>
                </div>

                <div class="caja-form-group" id="caja-stock-qty-group" style="display:none;">
                    <label for="new-prod-stock-qty"><?php _e('Cantidad en Stock', 'emp-caja'); ?></label>
                    <input type="number" min="0" id="new-prod-stock-qty" name="stock_quantity" value="10" />
                </div>

                <div class="caja-form-group">
                    <label for="new-prod-desc"><?php _e('Descripción Corta / Ingredientes', 'emp-caja'); ?></label>
                    <textarea id="new-prod-desc" name="description" rows="3" placeholder="<?php esc_attr_e('Detalles del producto...', 'emp-caja'); ?>"></textarea>
                </div>

                <div class="caja-modal-footer">
                    <button type="button" class="caja-btn caja-btn-secondary" id="caja-modal-cancel-btn">
                        <?php _e('Cancelar', 'emp-caja'); ?>
                    </button>
                    <button type="submit" class="caja-btn caja-btn-primary" id="caja-modal-submit-btn">
                        <span class="caja-btn-text"><?php _e('Guardar Producto', 'emp-caja'); ?></span>
                        <span class="caja-btn-spinner" style="display:none;"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
