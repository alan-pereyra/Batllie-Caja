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

            <select id="caja-filter-product-stock" class="caja-select">
                <option value=""><?php _e('Todos los niveles de stock', 'emp-caja'); ?></option>
                <option value="instock"><?php _e('✅ En stock', 'emp-caja'); ?></option>
                <option value="lowstock"><?php _e('⚠️ Stock bajo (≤ 5)', 'emp-caja'); ?></option>
                <option value="outofstock"><?php _e('🛑 Agotado / Sin stock', 'emp-caja'); ?></option>
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
                    <th style="width: 150px; text-align: center;"><?php _e('Acciones / Stock', 'emp-caja'); ?></th>
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

    <!-- Modal para Control y Renovación de Stock / Edición de Producto -->
    <div id="caja-modal-edit-stock" class="caja-modal" style="display:none;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog">
            <div class="caja-modal-header">
                <h3>📦 <?php _e('Control y Renovación de Stock', 'emp-caja'); ?></h3>
                <button type="button" class="caja-modal-close" id="caja-stock-modal-close-btn">&times;</button>
            </div>

            <form id="caja-edit-stock-form" class="caja-modal-body">
                <input type="hidden" id="stock-modal-prod-id" name="product_id" value="" />
                <input type="hidden" id="stock-modal-mode" name="mode" value="add" />

                <div id="caja-edit-stock-error" class="caja-alert caja-alert-danger" style="display:none;"></div>

                <!-- Cabecera del producto seleccionado -->
                <div class="caja-stock-prod-summary">
                    <img id="stock-modal-thumb" src="" alt="" class="caja-prod-thumb" />
                    <div class="caja-stock-prod-info">
                        <strong id="stock-modal-prod-title"></strong>
                        <div class="caja-stock-prod-meta">
                            <span class="caja-sku-badge" id="stock-modal-sku"></span>
                            <span class="caja-cat-badge" id="stock-modal-cat"></span>
                        </div>
                    </div>
                </div>

                <!-- Banner de Stock Actual -->
                <div class="caja-stock-current-banner">
                    <span class="caja-stock-current-label"><?php _e('Stock Actual en WooCommerce:', 'emp-caja'); ?></span>
                    <div class="caja-stock-current-badge-wrap">
                        <strong class="caja-stock-current-val" id="stock-modal-current-qty">0</strong>
                        <span class="caja-badge" id="stock-modal-current-badge"></span>
                    </div>
                </div>

                <!-- Selector de Modo de Carga / Renovación -->
                <div class="caja-stock-mode-selector">
                    <button type="button" class="caja-stock-mode-btn active" data-mode="add" id="btn-mode-add">
                        <span class="caja-mode-icon">➕</span>
                        <span class="caja-mode-title"><?php _e('Sumar Ingreso de Mercadería', 'emp-caja'); ?></span>
                        <small><?php _e('Llegaron unidades nuevas', 'emp-caja'); ?></small>
                    </button>
                    <button type="button" class="caja-stock-mode-btn" data-mode="set" id="btn-mode-set">
                        <span class="caja-mode-icon">✏️</span>
                        <span class="caja-mode-title"><?php _e('Fijar Total Directo', 'emp-caja'); ?></span>
                        <small><?php _e('Recuento manual de inventario', 'emp-caja'); ?></small>
                    </button>
                </div>

                <!-- Panel Modo 1: Sumar Ingreso de Mercadería -->
                <div id="caja-panel-mode-add" class="caja-stock-panel">
                    <div class="caja-form-group">
                        <label for="stock-incoming-qty">
                            <strong><?php _e('¿Cuántas unidades nuevas ingresaron?', 'emp-caja'); ?></strong>
                        </label>
                        <div class="caja-stock-input-wrap">
                            <span class="caja-input-prefix">+</span>
                            <input type="number" id="stock-incoming-qty" min="1" step="1" placeholder="Ej: 30" class="caja-stock-large-input" />
                        </div>
                        <small class="caja-form-hint"><?php _e('Ingresá las unidades que llegaron. El sistema las sumará al stock existente automáticamente.', 'emp-caja'); ?></small>
                    </div>

                    <!-- Vista previa en vivo del cálculo -->
                    <div class="caja-stock-calc-preview" id="caja-stock-calc-preview">
                        <div class="caja-calc-row">
                            <span><?php _e('Stock actual:', 'emp-caja'); ?></span>
                            <strong id="calc-current-num">0</strong>
                        </div>
                        <div class="caja-calc-row caja-calc-incoming">
                            <span><?php _e('+ Unidades que ingresan:', 'emp-caja'); ?></span>
                            <strong id="calc-incoming-num">+0</strong>
                        </div>
                        <div class="caja-calc-divider"></div>
                        <div class="caja-calc-row caja-calc-total">
                            <span><?php _e('Nuevo Stock Total Resultante:', 'emp-caja'); ?></span>
                            <strong id="calc-result-num">0</strong>
                        </div>
                    </div>
                </div>

                <!-- Panel Modo 2: Fijar Total Directo -->
                <div id="caja-panel-mode-set" class="caja-stock-panel" style="display:none;">
                    <div class="caja-form-group">
                        <label for="stock-direct-qty">
                            <strong><?php _e('Cantidad total exacta en stock:', 'emp-caja'); ?></strong>
                        </label>
                        <input type="number" id="stock-direct-qty" min="0" step="1" placeholder="Ej: 45" class="caja-stock-large-input" />
                        <small class="caja-form-hint"><?php _e('Reemplazará el stock actual por este número exacto.', 'emp-caja'); ?></small>
                    </div>
                </div>

                <!-- Opciones avanzadas de producto (Precios y Gestión) -->
                <details class="caja-stock-extra-details">
                    <summary><?php _e('⚙️ Editar Precios y Configuración (Opcional)', 'emp-caja'); ?></summary>
                    <div class="caja-extra-fields-wrap">
                        <div class="caja-form-row">
                            <div class="caja-form-group caja-col">
                                <label for="stock-prod-price"><?php _e('Precio Regular ($)', 'emp-caja'); ?></label>
                                <input type="number" step="0.01" min="0" id="stock-prod-price" />
                            </div>
                            <div class="caja-form-group caja-col">
                                <label for="stock-prod-sale-price"><?php _e('Precio Oferta ($)', 'emp-caja'); ?></label>
                                <input type="number" step="0.01" min="0" id="stock-prod-sale-price" />
                            </div>
                        </div>
                        <div class="caja-form-group">
                            <label class="caja-checkbox-label">
                                <input type="checkbox" id="stock-prod-manage-stock" value="yes" checked />
                                <span><?php _e('Activar control de inventario de WooCommerce para este producto', 'emp-caja'); ?></span>
                            </label>
                        </div>
                    </div>
                </details>

                <div class="caja-modal-footer">
                    <button type="button" class="caja-btn caja-btn-secondary" id="caja-stock-modal-cancel-btn">
                        <?php _e('Cancelar', 'emp-caja'); ?>
                    </button>
                    <button type="submit" class="caja-btn caja-btn-primary" id="caja-stock-modal-submit-btn">
                        <span class="caja-btn-text"><?php _e('💾 Actualizar Inventario', 'emp-caja'); ?></span>
                        <span class="caja-btn-spinner" style="display:none;"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
