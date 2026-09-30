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

            <select id="caja-filter-product-cat" class="caja-select caja-select-filter">
                <option value=""><?php _e('Todas las categorías', 'emp-caja'); ?></option>
            </select>

            <select id="caja-filter-product-stock" class="caja-select caja-select-filter">
                <option value=""><?php _e('Todos los niveles de stock', 'emp-caja'); ?></option>
                <option value="instock"><?php _e('✅ En stock', 'emp-caja'); ?></option>
                <option value="lowstock"><?php _e('⚠️ Stock bajo (≤ 5)', 'emp-caja'); ?></option>
                <option value="outofstock"><?php _e('🛑 Agotado / Sin stock', 'emp-caja'); ?></option>
            </select>
        </div>

        <div class="caja-products-actions-group">
            <button type="button" class="caja-btn caja-btn-secondary caja-btn-screen-switch" data-target="tab-orders" title="<?php esc_attr_e('Volver a la pantalla de Pedidos en Vivo', 'emp-caja'); ?>">
                <span class="caja-btn-arrow">⬅</span>
                <span class="caja-btn-icon">📋</span>
                <span class="caja-btn-text-responsive"><?php _e('Volver a Pedidos', 'emp-caja'); ?></span>
                <span class="caja-badge-count" id="caja-products-orders-badge">0</span>
            </button>

            <button type="button" class="caja-btn caja-btn-primary" id="caja-btn-open-new-product">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span><?php _e('Cargar Nuevo Producto', 'emp-caja'); ?></span>
            </button>
        </div>
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
                    <th class="caja-th-foto caja-th-thumb caja-th-imagen" style="width: 60px;"><?php _e('Foto', 'emp-caja'); ?></th>
                    <th class="caja-th-producto caja-th-name caja-th-title"><?php _e('Producto', 'emp-caja'); ?></th>
                    <th class="caja-th-stock"><?php _e('Stock', 'emp-caja'); ?></th>
                    <th class="caja-th-precio caja-th-price"><?php _e('Precio', 'emp-caja'); ?></th>
                    <th class="caja-th-categoria caja-th-category caja-th-cat"><?php _e('Categoría', 'emp-caja'); ?></th>
                    <th class="caja-th-acciones caja-th-actions" style="width: 150px; text-align: center;"><?php _e('Acciones / Stock', 'emp-caja'); ?></th>
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

                <!-- Foto del Producto -->
                <div class="caja-form-group caja-image-uploader-wrap">
                    <label><?php _e('Foto del Producto', 'emp-caja'); ?></label>
                    <div class="caja-image-preview-box">
                        <img id="new-prod-thumb" src="" alt="Vista previa" class="caja-image-preview" style="display:none;" />
                        <div class="caja-image-btn-group">
                            <button type="button" class="caja-btn caja-btn-sm caja-btn-secondary" id="new-prod-choose-img-btn">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle; margin-right:4px;">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <span><?php _e('Elegir Foto (Galería WordPress)', 'emp-caja'); ?></span>
                            </button>
                            <button type="button" class="caja-btn caja-btn-sm caja-btn-danger" id="new-prod-remove-img-btn" style="display:none;">
                                <span>🗑️ <?php _e('Quitar', 'emp-caja'); ?></span>
                            </button>
                        </div>
                        <input type="hidden" id="new-prod-image-id" name="image_id" value="" />
                    </div>
                </div>

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
                        <label for="new-prod-visibility"><strong><?php _e('Visibilidad en la Tienda / Web', 'emp-caja'); ?></strong></label>
                        <select id="new-prod-visibility" name="catalog_visibility" class="caja-select">
                            <option value="visible"><?php _e('👁️ Visible (Catálogo y Búsqueda)', 'emp-caja'); ?></option>
                            <option value="hidden"><?php _e('🚫 Oculto (Los clientes NO pueden encontrarlo)', 'emp-caja'); ?></option>
                            <option value="catalog"><?php _e('📁 Solo en Catálogo (Oculto del buscador)', 'emp-caja'); ?></option>
                            <option value="search"><?php _e('🔍 Solo en Búsqueda (Oculto del catálogo)', 'emp-caja'); ?></option>
                        </select>
                    </div>
                    <div class="caja-form-group caja-col caja-align-bottom">
                        <label class="caja-checkbox-label">
                            <input type="checkbox" id="new-prod-featured" name="featured" value="yes" />
                            <span>⭐ <strong><?php _e('Producto Destacado', 'emp-caja'); ?></strong></span>
                        </label>
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
                    <label for="new-prod-type"><strong>⚙️ <?php _e('Tipo de Producto', 'emp-caja'); ?></strong></label>
                    <select id="new-prod-type" name="product_type" class="caja-select">
                        <option value="simple"><?php _e('📦 Producto simple', 'emp-caja'); ?></option>
                        <option value="grouped"><?php _e('🎁 Producto agrupado', 'emp-caja'); ?></option>
                        <option value="variable"><?php _e('🔄 Producto variable', 'emp-caja'); ?></option>
                    </select>
                </div>

                <div class="caja-form-group" id="new-prod-box-role-group">
                    <label for="new-prod-box-role"><strong>📦 <?php _e('Rol de Empaque / Caja Oficial:', 'emp-caja'); ?></strong></label>
                    <select id="new-prod-box-role" name="official_box_role" class="caja-select">
                        <option value="none"></option>
                        <option value="box_6"><?php _e('📦 Asignar como Caja Oficial de 6 unidades', 'emp-caja'); ?></option>
                        <option value="box_12"><?php _e('📦 Asignar como Caja Oficial de 12 unidades', 'emp-caja'); ?></option>
                    </select>
                </div>
<div class="caja-form-group">
    <label for="new-prod-sales-suggestions"><?php _e('Ventas Sugeridas', 'emp-caja'); ?></label>
    <select id="new-prod-sales-suggestions" name="sales_suggestions" class="caja-select">
        <option value="none"><?php _e('-- Ninguna --', 'emp-caja'); ?></option>
        <!-- Opciones de productos sugeridos se cargarán vía AJAX -->
    </select>
</div>
<div class="caja-form-group">

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
                            <span class="caja-sku-badge" id="stock-modal-sku" style="display:none !important;"></span>
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

    <!-- Modal para Modificar Producto Completo (Datos, Precios, Categoría, Descripción) -->
    <div id="caja-modal-edit-product" class="caja-modal" style="display:none;">
        <div class="caja-modal-backdrop"></div>
        <div class="caja-modal-dialog">
            <div class="caja-modal-header">
                <h3>✏️ <?php _e('Modificar Producto', 'emp-caja'); ?></h3>
                <button type="button" class="caja-modal-close" id="caja-edit-prod-close-btn">&times;</button>
            </div>

            <form id="caja-edit-product-form" class="caja-modal-body">
                <input type="hidden" id="edit-prod-id" name="id" value="" />
                <div class="caja-form-group">
                    <label for="edit-prod-type"><strong>⚙️ <?php _e('Tipo de Producto', 'emp-caja'); ?></strong></label>
                    <select id="edit-prod-type" name="product_type" class="caja-select">
                        <option value="simple"><?php _e('📦 Producto simple', 'emp-caja'); ?></option>
                        <option value="grouped"><?php _e('🎁 Producto agrupado', 'emp-caja'); ?></option>
                        <option value="variable"><?php _e('🔄 Producto variable', 'emp-caja'); ?></option>
                    </select>
                </div>
                <div id="caja-edit-product-error" class="caja-alert caja-alert-danger" style="display:none;"></div>

                <!-- Cartel Informativo de Producto Agrupado -->
                <div id="edit-prod-grouped-notice" class="caja-grouped-banner" style="display:none;">
                    <div class="caja-grouped-banner-icon">📦</div>
                    <div class="caja-grouped-banner-content">
                        <strong><?php _e('Producto Agrupado (Caja / Contenedor)', 'emp-caja'); ?></strong>
                        <p><?php _e('Este producto funciona como una caja principal que agrupa otros productos. Podés gestionar la imagen general de la caja y seleccionar qué productos incluye abajo.', 'emp-caja'); ?></p>
                    </div>
                </div>

                <!-- Foto del Producto -->
                <div class="caja-form-group caja-image-uploader-wrap">
                    <label><strong><?php _e('Foto del Producto', 'emp-caja'); ?></strong></label>
                    <div class="caja-image-preview-box">
                        <img id="edit-prod-thumb" src="" alt="Vista previa" class="caja-image-preview" style="display:none;" />
                        <div class="caja-image-btn-group">
                            <button type="button" class="caja-btn caja-btn-sm caja-btn-secondary" id="edit-prod-choose-img-btn">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle; margin-right:4px;">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <span><?php _e('Elegir Foto (Galería WordPress)', 'emp-caja'); ?></span>
                            </button>
                            <button type="button" class="caja-btn caja-btn-sm caja-btn-danger" id="edit-prod-remove-img-btn" style="display:none;">
                                <span>🗑️ <?php _e('Quitar Foto', 'emp-caja'); ?></span>
                            </button>
                        </div>
                        <input type="hidden" id="edit-prod-image-id" name="image_id" value="" />
                    </div>
                </div>

                <div class="caja-form-group">
                    <label for="edit-prod-name"><?php _e('Nombre del Producto *', 'emp-caja'); ?></label>
                    <input type="text" id="edit-prod-name" name="name" required placeholder="<?php esc_attr_e('Nombre...', 'emp-caja'); ?>" />
                </div>

                <div class="caja-form-row">
                    <div class="caja-form-group caja-col">
                        <label for="edit-prod-price"><?php _e('Precio Regular *', 'emp-caja'); ?></label>
                        <input type="number" step="0.01" min="0" id="edit-prod-price" name="regular_price" required placeholder="0.00" />
                    </div>
                    <div class="caja-form-group caja-col">
                        <label for="edit-prod-sale-price"><?php _e('Precio Oferta (Opcional)', 'emp-caja'); ?></label>
                        <input type="number" step="0.01" min="0" id="edit-prod-sale-price" name="sale_price" placeholder="0.00" />
                    </div>
                </div>

                <div class="caja-form-row">
                    <div class="caja-form-group caja-col">
                        <label for="edit-prod-visibility"><strong><?php _e('Visibilidad en la Tienda / Web', 'emp-caja'); ?></strong></label>
                        <select id="edit-prod-visibility" name="catalog_visibility" class="caja-select">
                            <option value="visible"><?php _e('👁️ Visible (Los clientes pueden encontrarlo)', 'emp-caja'); ?></option>
                            <option value="hidden"><?php _e('🚫 Oculto (Los clientes NO pueden encontrarlo)', 'emp-caja'); ?></option>
                            <option value="catalog"><?php _e('📁 Solo en Catálogo (Oculto de búsqueda)', 'emp-caja'); ?></option>
                            <option value="search"><?php _e('🔍 Solo en Búsqueda (Oculto de catálogo)', 'emp-caja'); ?></option>
                        </select>
                    </div>
                    <div class="caja-form-group caja-col caja-align-bottom">
                        <label class="caja-checkbox-label">
                            <input type="checkbox" id="edit-prod-featured" name="featured" value="yes" />
                            <span>⭐ <strong><?php _e('Producto Destacado', 'emp-caja'); ?></strong></span>
                        </label>
                    </div>
                </div>

                <div class="caja-form-row">
                    <div class="caja-form-group caja-col">
                        <label for="edit-prod-category"><?php _e('Categoría', 'emp-caja'); ?></label>
                        <select id="edit-prod-category" name="category_id" class="caja-select">
                            <option value="0"><?php _e('-- Seleccionar Categoría --', 'emp-caja'); ?></option>
                        </select>
                    </div>
                    <div class="caja-form-group caja-col">
                        <label for="edit-prod-sku"><?php _e('Código / SKU (Opcional)', 'emp-caja'); ?></label>
                        <input type="text" id="edit-prod-sku" name="sku" placeholder="PROD-001" />
                    </div>
                </div>

                <div class="caja-form-group">
                    <label class="caja-checkbox-label">
                        <input type="checkbox" id="edit-prod-manage-stock" name="manage_stock" value="yes" />
                        <span><?php _e('¿Gestionar inventario / stock en WooCommerce?', 'emp-caja'); ?></span>
                    </label>
                </div>

                <div class="caja-form-group" id="caja-edit-stock-qty-group">
                    <label for="edit-prod-stock-qty"><?php _e('Cantidad en Stock', 'emp-caja'); ?></label>
                    <input type="number" min="0" id="edit-prod-stock-qty" name="stock_quantity" />
                    <div id="caja-edit-stock-dynamic-hint" style="display:none; margin-top:6px; font-size:12px; color:#059669; font-weight:600; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:4px; padding:6px 10px;"></div>
                </div>

                <!-- ============================================== -->
                <!-- SECCIÓN 1: CAJA OFICIAL (SOLO PRODUCTO SIMPLE) -->
                <!-- ============================================== -->
                <div class="caja-details-accordion caja-modal-section-accordion" id="edit-prod-sec-caja-oficial" style="display:none;">
                    <button type="button" class="caja-btn-details-toggle caja-btn-modal-accordion" data-target="#edit-collapse-caja-oficial">
                        <span class="caja-toggle-left">
                            <span class="caja-toggle-icon">📦</span>
                            <span class="caja-toggle-text"><strong><?php _e('Caja Oficial', 'emp-caja'); ?></strong></span>
                        </span>
                        <span class="caja-toggle-arrow">▼</span>
                    </button>
                    <div class="caja-details-collapse" id="edit-collapse-caja-oficial" style="display:none;">
                        <div class="caja-form-group" style="margin-bottom:0;">
                            <label for="edit-prod-box-role"><strong>📦 <?php _e('Rol de Empaque / Caja Oficial:', 'emp-caja'); ?></strong></label>
                            <select id="edit-prod-box-role" name="official_box_role" class="caja-select">
                                <option value="none"></option>
                                <option value="box_6"><?php _e('📦 Asignar como Caja Oficial de 6 unidades', 'emp-caja'); ?></option>
                                <option value="box_12"><?php _e('📦 Asignar como Caja Oficial de 12 unidades', 'emp-caja'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ========================================================================= -->
                <!-- SECCIÓN 2: PRODUCTOS INCLUIDOS EN LA CAJA (SOLO PRODUCTO AGRUPADO)       -->
                <!-- ========================================================================= -->
                <div class="caja-details-accordion caja-modal-section-accordion" id="edit-prod-sec-grouped-children" style="display:none;">
                    <button type="button" class="caja-btn-details-toggle caja-btn-modal-accordion" data-target="#edit-collapse-grouped-children">
                        <span class="caja-toggle-left">
                            <span class="caja-toggle-icon">📦</span>
                            <span class="caja-toggle-text"><strong><?php _e('Productos incluidos en la Agrupación / Caja', 'emp-caja'); ?></strong></span>
                        </span>
                        <span class="caja-toggle-arrow">▼</span>
                    </button>
                    <div class="caja-details-collapse" id="edit-collapse-grouped-children" style="display:none;">
                        <!-- Selector de Modo de Agrupación: Personalizable vs Combo Predeterminado -->
                        <div class="caja-grouped-mode-card" style="margin-bottom:12px;">
                            <span class="caja-mode-card-title"><?php _e('¿Cómo funciona esta caja / agrupación?', 'emp-caja'); ?></span>
                            <div class="caja-grouped-mode-radios">
                                <label class="caja-radio-pill active" id="label-grouped-mode-custom">
                                    <input type="radio" name="grouped_combo_mode" value="custom" id="edit-grouped-mode-custom" checked />
                                    <div class="caja-radio-pill-content">
                                        <strong>📦 Caja personalizable</strong>
                                        <small><?php _e('El cliente elige los productos y cantidades al armar la caja', 'emp-caja'); ?></small>
                                    </div>
                                </label>
                                <label class="caja-radio-pill" id="label-grouped-mode-predefined">
                                    <input type="radio" name="grouped_combo_mode" value="predefined" id="edit-grouped-mode-predefined" />
                                    <div class="caja-radio-pill-content">
                                        <strong>🎁 Combo predeterminado / fijo</strong>
                                        <small><?php _e('La tienda fija la cantidad de cada producto y el cliente compra el combo ya armado', 'emp-caja'); ?></small>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <p class="caja-form-hint" id="edit-grouped-mode-hint" style="margin-top:2px; margin-bottom:8px;">
                            <?php _e('Seleccioná cuáles productos simples se incluyen dentro de esta caja agrupada:', 'emp-caja'); ?>
                        </p>
                        <div class="caja-grouped-filter-row">
                            <input type="text" id="edit-grouped-search-filter" class="caja-input-sm" placeholder="🔍 Filtrar lista de productos..." />
                            <div class="caja-grouped-btn-actions">
                                <button type="button" class="caja-btn caja-btn-secondary" id="btn-grouped-select-all"><?php _e('Marcar todos', 'emp-caja'); ?></button>
                                <button type="button" class="caja-btn caja-btn-secondary" id="btn-grouped-deselect-all"><?php _e('Desmarcar todos', 'emp-caja'); ?></button>
                            </div>
                        </div>
                        <div class="caja-children-checklist-container" id="edit-prod-children-list">
                            <!-- Generado dinámicamente -->
                        </div>
                        <div class="caja-grouped-footer-bar" style="margin-top:8px;">
                            <span class="caja-badge caja-badge-info" id="edit-grouped-selected-badge">0 seleccionados</span>
                        </div>
                    </div>
                </div>

                <!-- ========================================================================= -->
                <!-- SECCIÓN 3: CONFIGURACIÓN DE CAJA BATLLIÉ (SOLO PRODUCTO AGRUPADO)         -->
                <!-- ========================================================================= -->
                <div class="caja-details-accordion caja-modal-section-accordion" id="edit-prod-sec-grouped-config" style="display:none;">
                    <button type="button" class="caja-btn-details-toggle caja-btn-modal-accordion" data-target="#edit-collapse-grouped-config">
                        <span class="caja-toggle-left">
                            <span class="caja-toggle-icon">📦</span>
                            <span class="caja-toggle-text"><strong><?php _e('Configuración de Caja Batllié (Pack Agrupado)', 'emp-caja'); ?></strong></span>
                        </span>
                        <span class="caja-toggle-arrow">▼</span>
                    </button>
                    <div class="caja-details-collapse" id="edit-collapse-grouped-config" style="display:none;">
                        <!-- Cartel Stock Dinámico -->
                        <div id="edit-grouped-dyn-stock-banner" style="background:rgba(16, 185, 129, 0.1); border:1px solid #10b981; border-radius:6px; padding:12px; margin-bottom:14px; display:none;">
                            <div style="font-weight:700; color:#10b981; font-size:13px; display:flex; align-items:center; justify-content:space-between;">
                                <span id="edit-grouped-dyn-stock-text">⚡ Stock Dinámico Sincronizado</span>
                                <span style="font-size:11px; font-weight:600; background:#fff; color:#065f46; padding:2px 8px; border-radius:12px;">Sincronizado en vivo</span>
                            </div>
                            <div id="edit-grouped-dyn-bottleneck-text" style="margin-top:4px; font-size:12px; color:#e2e8f0; font-weight:500;"></div>
                            <small style="display:block; margin-top:4px; font-size:11px; color:#94a3b8;">El stock visible en la tienda y permitido para compra se recalcula automáticamente según el stock disponible de sus alfajores componentes, la caja física de empaque y el stock fijado.</small>
                        </div>

                        <!-- Cantidad fija de la caja -->
                        <div class="caja-form-group">
                            <label for="edit-grouped-target-qty"><strong><?php _e('Cantidad fija de la caja', 'emp-caja'); ?></strong></label>
                            <input type="number" min="0" step="1" id="edit-grouped-target-qty" name="grouped_target_qty" placeholder="<?php esc_attr_e('Ej: 6', 'emp-caja'); ?>" />
                            <small class="caja-form-hint"><?php _e('Número exacto de unidades que el cliente debe elegir para poder comprar la caja (ej: 6 para caja de 6, 12 para caja de 12). Si se deja vacío, el sistema detectará automáticamente la cantidad según el título del producto.', 'emp-caja'); ?></small>
                        </div>

                        <!-- ¿Incluir caja en el carrito? -->
                        <div class="caja-form-group">
                            <label class="caja-checkbox-label">
                                <input type="checkbox" id="edit-grouped-enable-extra-box" name="grouped_enable_extra_box" value="yes" />
                                <span><strong><?php _e('¿Incluir caja en el carrito?', 'emp-caja'); ?></strong></span>
                            </label>
                            <small class="caja-form-hint"><?php _e('Añadir automáticamente el producto de empaque (la caja física) a costo $0 al carrito y al pedido cuando el cliente agregue este pack.', 'emp-caja'); ?></small>
                        </div>

                        <!-- Nombre de la caja adicional -->
                        <div class="caja-form-group">
                            <label for="edit-grouped-extra-box-name"><strong><?php _e('Nombre de la caja adicional', 'emp-caja'); ?></strong></label>
                            <input type="text" id="edit-grouped-extra-box-name" name="grouped_extra_box_name" placeholder="<?php esc_attr_e('Ej: Caja 6 unidades', 'emp-caja'); ?>" />
                            <small class="caja-form-hint"><?php _e('Nombre con el que figurará la caja física en el carrito, pedido y pantalla de Caja POS (a $0). Si se deja vacío, se usará el título del producto.', 'emp-caja'); ?></small>
                        </div>

                        <!-- Caja física de empaque asociada -->
                        <div class="caja-form-group">
                            <label for="edit-prod-packaging-box"><strong>📦 <?php _e('Caja física de empaque asociada', 'emp-caja'); ?></strong></label>
                            <select id="edit-prod-packaging-box" name="packaging_box_product_id" class="caja-select"></select>
                            <small class="caja-form-hint"><?php _e('Caja física de empaque cuyo inventario limitará y se descontará automáticamente al vender este pack o combo.', 'emp-caja'); ?></small>
                        </div>

                        <!-- Precio Fijo del Combo / Caja -->
                        <div class="caja-form-group">
                            <label for="edit-grouped-fixed-price"><strong><?php _e('Precio Fijo del Combo / Caja ($)', 'emp-caja'); ?></strong></label>
                            <input type="number" step="0.01" min="0" id="edit-grouped-fixed-price" name="grouped_fixed_price" placeholder="<?php esc_attr_e('Ej: 17500 (opcional)', 'emp-caja'); ?>" />
                            <small class="caja-form-hint"><?php _e('Si defines un precio fijo, la caja/combo se cobrará exactamente a este importe final sin importar los productos individuales que agrupe ni la cantidad.', 'emp-caja'); ?></small>
                        </div>

                        <!-- Desglose del precio fijo -->
                        <div class="caja-form-group">
                            <label for="edit-grouped-fixed-price-display"><strong><?php _e('Desglose del precio fijo', 'emp-caja'); ?></strong></label>
                            <select id="edit-grouped-fixed-price-display" name="grouped_fixed_price_display" class="caja-select">
                                <option value="box"><?php _e('Asignar precio total a la Caja (Alfajores figuran a $0 incluidos)', 'emp-caja'); ?></option>
                                <option value="distributed"><?php _e('Distribuir equitativamente entre los alfajores (Caja a $0)', 'emp-caja'); ?></option>
                            </select>
                            <small class="caja-form-hint"><?php _e('Define cómo se mostrará el cobro en el carrito y en el pedido cuando el precio fijo esté activo.', 'emp-caja'); ?></small>
                        </div>

                        <!-- Precio "Desde" (Página principal) -->
                        <div class="caja-form-group">
                            <label for="edit-grouped-custom-price-from"><strong><?php _e('Precio "Desde" (Página principal)', 'emp-caja'); ?></strong></label>
                            <input type="number" step="0.01" min="0" id="edit-grouped-custom-price-from" name="grouped_custom_price_from" placeholder="<?php esc_attr_e('Ej: 16800', 'emp-caja'); ?>" />
                            <small class="caja-form-hint"><?php _e('Precio que se muestra en la página principal y catálogo como "Desde $...". Si configuraste un Precio Fijo arriba, este campo no es necesario.', 'emp-caja'); ?></small>
                        </div>

                        <!-- Imagen de la caja de empaque -->
                        <div class="caja-form-group">
                            <label><strong><?php _e('Imagen física de la caja (Empaque)', 'emp-caja'); ?></strong></label>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div id="edit-grouped-box-img-preview" style="width:50px; height:50px; border:1px dashed var(--caja-card-border); border-radius:6px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:rgba(255,255,255,0.02);">
                                    <span style="font-size:20px;">📦</span>
                                </div>
                                <input type="hidden" id="edit-grouped-box-image-id" name="grouped_box_image_id" value="" />
                                <button type="button" class="caja-btn caja-btn-sm caja-btn-secondary" id="edit-grouped-choose-box-img-btn">
                                    <span><?php _e('Seleccionar imagen de la caja', 'emp-caja'); ?></span>
                                </button>
                                <button type="button" class="caja-btn caja-btn-sm caja-btn-danger" id="edit-grouped-remove-box-img-btn" style="display:none;">
                                    <span>🗑️ <?php _e('Quitar', 'emp-caja'); ?></span>
                                </button>
                            </div>
                            <small class="caja-form-hint"><?php _e('Imagen física de la caja que se usará en el carrito de la web y en la sección de Caja POS.', 'emp-caja'); ?></small>
                        </div>
                    </div>
                </div>

                <!-- ========================================================================= -->
                <!-- SECCIÓN 4: ATRIBUTOS Y VARIACIONES (SOLO PRODUCTO VARIABLE)               -->
                <!-- ========================================================================= -->
                <div class="caja-details-accordion caja-modal-section-accordion" id="edit-prod-sec-variable" style="display:none;">
                    <button type="button" class="caja-btn-details-toggle caja-btn-modal-accordion" data-target="#edit-collapse-variable">
                        <span class="caja-toggle-left">
                            <span class="caja-toggle-icon">🔄</span>
                            <span class="caja-toggle-text"><strong><?php _e('Atributos y Variaciones', 'emp-caja'); ?></strong></span>
                        </span>
                        <span class="caja-toggle-arrow">▼</span>
                    </button>
                    <div class="caja-details-collapse" id="edit-collapse-variable" style="display:none;">
                        <!-- Sub-sección 1: Atributos -->
                        <div class="caja-variable-attributes-section" style="margin-bottom:16px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                                <strong>🏷️ <?php _e('Atributos del Producto (para generar variaciones)', 'emp-caja'); ?></strong>
                                <button type="button" class="caja-btn caja-btn-sm caja-btn-secondary" id="caja-btn-add-attribute">
                                    <span>➕ <?php _e('Añadir Atributo', 'emp-caja'); ?></span>
                                </button>
                            </div>
                            <small class="caja-form-hint" style="display:block; margin-bottom:8px;">
                                <?php _e('Ingresá el nombre del atributo (ej: Sabor, Tamaño) y sus opciones separadas por una barra vertical | (ej: Negro | Blanco | Pistacho).', 'emp-caja'); ?>
                            </small>
                            <div id="caja-attributes-list" class="caja-attributes-list">
                                <!-- Filas de atributos -->
                            </div>
                        </div>

                        <!-- Sub-sección 2: Variaciones -->
                        <div class="caja-variable-variations-section">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                                <strong>📦 <?php _e('Variaciones de Producto', 'emp-caja'); ?></strong>
                                <div style="display:flex; gap:6px;">
                                    <button type="button" class="caja-btn caja-btn-sm caja-btn-secondary" id="caja-btn-generate-variations" title="<?php esc_attr_e('Genera automáticamente todas las variaciones posibles según las opciones de los atributos', 'emp-caja'); ?>">
                                        <span>⚡ <?php _e('Generar según Atributos', 'emp-caja'); ?></span>
                                    </button>
                                    <button type="button" class="caja-btn caja-btn-sm caja-btn-secondary" id="caja-btn-add-variation">
                                        <span>➕ <?php _e('Añadir Variación', 'emp-caja'); ?></span>
                                    </button>
                                </div>
                            </div>
                            <div id="caja-variations-list" class="caja-variations-list">
                                <!-- Tarjetas de variaciones generadas -->
                            </div>
                        </div>
                    </div>
                </div>

                <div class="caja-form-group">
                    <label for="edit-prod-desc"><?php _e('Descripción Corta / Ingredientes', 'emp-caja'); ?></label>
                    <textarea id="edit-prod-desc" name="description" rows="3" placeholder="<?php esc_attr_e('Detalles del producto...', 'emp-caja'); ?>"></textarea>
                </div>

                <div class="caja-modal-footer">
                    <button type="button" class="caja-btn caja-btn-secondary" id="caja-edit-prod-cancel-btn">
                        <?php _e('Cancelar', 'emp-caja'); ?>
                    </button>
                    <button type="submit" class="caja-btn caja-btn-primary" id="caja-edit-prod-submit-btn">
                        <span class="caja-btn-text"><?php _e('💾 Guardar Modificaciones', 'emp-caja'); ?></span>
                        <span class="caja-btn-spinner" style="display:none;"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
