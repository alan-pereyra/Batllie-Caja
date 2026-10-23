<?php
/**
 * Gestión de Empleados, Roles y Permisos de Caja POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Employees {

    /**
     * Inicialización y registro de roles
     */
    public static function init() {
        self::register_roles();
    }

    /**
     * Registrar roles personalizados de Caja si no existen
     */
    public static function register_roles() {
        if (!get_role('caja_cajero')) {
            add_role('caja_cajero', __('Cajero POS', 'emp-caja'), array(
                'read'         => true,
                'edit_posts'   => false,
                'delete_posts' => false,
            ));
        }

        if (!get_role('caja_encargado')) {
            add_role('caja_encargado', __('Encargado / Supervisor POS', 'emp-caja'), array(
                'read'         => true,
                'edit_posts'   => true,
                'delete_posts' => false,
            ));
        }
    }

    /**
     * Obtener lista de sectores/barras configuradas en el sistema
     */
    public static function get_available_sectors() {
        $options = Batllie_Caja_Plugin::get_color_settings();
        if (!empty($options['gastro_sectors'])) {
            $raw = explode(',', $options['gastro_sectors']);
            $sectors = array_values(array_filter(array_map('trim', $raw)));
            if (!empty($sectors)) {
                return $sectors;
            }
        }
        return array('Barra Principal', 'Barra VIP', 'Terraza', 'Boletería / Entrada', 'Pista', 'Take Away / Para Llevar');
    }

    /**
     * Obtener permisos calculados para un usuario
     */
    public static function get_user_permissions($user_id = null) {
        if (empty($user_id)) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return array(
                'is_admin'                => false,
                'role'                    => 'guest',
                'role_name'               => __('Invitado', 'emp-caja'),
                'assigned_sectors'        => array(),
                'can_view_products'       => false,
                'can_view_orders'         => false,
                'can_change_sector'       => false,
                'can_view_customer_data'  => false,
                'can_view_packaging'      => false,
                'can_dispatch_orders'     => false,
                'status'                  => 'disabled',
            );
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return array(
                'is_admin'                => false,
                'role'                    => 'guest',
                'role_name'               => __('Desconocido', 'emp-caja'),
                'assigned_sectors'        => array(),
                'can_view_products'       => false,
                'can_view_orders'         => false,
                'can_change_sector'       => false,
                'can_view_customer_data'  => false,
                'can_view_packaging'      => false,
                'can_dispatch_orders'     => false,
                'status'                  => 'disabled',
            );
        }

        $is_admin = user_can($user, 'manage_options') || user_can($user, 'administrator');

        if ($is_admin) {
            return array(
                'is_admin'                => true,
                'role'                    => 'administrator',
                'role_name'               => __('Administrador', 'emp-caja'),
                'assigned_sectors'        => self::get_available_sectors(),
                'can_view_products'       => true,
                'can_view_orders'         => true,
                'can_change_sector'       => true,
                'can_view_customer_data'  => true,
                'can_view_packaging'      => true,
                'can_dispatch_orders'     => true,
                'product_layout'          => 'grid',
                'product_grid_cols'       => '2',
                'allow_layout_toggle'     => true,
                'status'                  => 'active',
            );
        }

        // Usuario cajero o supervisor
        $roles = (array) $user->roles;
        $primary_role = !empty($roles[0]) ? $roles[0] : 'caja_cajero';
        $meta_role = get_user_meta($user_id, '_caja_role', true);
        if (!empty($meta_role)) {
            $primary_role = $meta_role;
        }

        $role_names = array(
            'caja_cajero'    => __('Cajero', 'emp-caja'),
            'caja_encargado' => __('Encargado', 'emp-caja'),
            'shop_manager'   => __('Gestor de Tienda', 'emp-caja'),
        );
        $role_name = isset($role_names[$primary_role]) ? $role_names[$primary_role] : ucfirst($primary_role);

        // Sectores asignados
        $assigned_sectors = get_user_meta($user_id, '_caja_assigned_sectors', true);
        if (!is_array($assigned_sectors)) {
            $assigned_sectors = !empty($assigned_sectors) ? array_map('trim', explode(',', $assigned_sectors)) : array();
        }
        if (empty($assigned_sectors)) {
            $all = self::get_available_sectors();
            $assigned_sectors = !empty($all) ? array($all[0]) : array('Barra Principal');
        }

        $can_view_products_meta = get_user_meta($user_id, '_caja_can_view_products', true);
        $can_view_products = ($can_view_products_meta === '1' || $can_view_products_meta === true || $can_view_products_meta === 'yes');

        $can_view_orders_meta = get_user_meta($user_id, '_caja_can_view_orders', true);
        // Por defecto cajeros pueden ver pedidos a menos que se configure explícitamente en '0'
        $can_view_orders = ($can_view_orders_meta !== '0' && $can_view_orders_meta !== false && $can_view_orders_meta !== 'no');

        $can_change_sector_meta = get_user_meta($user_id, '_caja_can_change_sector', true);
        // Por defecto solo administradores pueden cambiar entre mesas y barras
        $can_change_sector = ($can_change_sector_meta === '1' || $can_change_sector_meta === true);

        // Datos del cliente en Venta Caja (Por defecto desactivado para cajero si no está explícito)
        $can_view_customer_data_meta = get_user_meta($user_id, '_caja_can_view_customer_data', true);
        $can_view_customer_data = ($can_view_customer_data_meta === '1' || $can_view_customer_data_meta === true || $can_view_customer_data_meta === 'yes');

        // Empaque y bolsas en Venta Caja (Por defecto desactivado para cajero si no está explícito)
        $can_view_packaging_meta = get_user_meta($user_id, '_caja_can_view_packaging', true);
        $can_view_packaging = ($can_view_packaging_meta === '1' || $can_view_packaging_meta === true || $can_view_packaging_meta === 'yes');

        // Cambiar a Pedido por Despachar (Por defecto desactivado para cajero; solo compra realizada)
        $can_dispatch_orders_meta = get_user_meta($user_id, '_caja_can_dispatch_orders', true);
        $can_dispatch_orders = ($can_dispatch_orders_meta === '1' || $can_dispatch_orders_meta === true || $can_dispatch_orders_meta === 'yes');

        // Diseño visual de productos en Venta Caja (Lista compacta vs Grilla de tarjetas)
        $product_layout_meta = get_user_meta($user_id, '_caja_product_layout', true);
        $product_layout = in_array($product_layout_meta, array('list', 'grid')) ? $product_layout_meta : 'grid';

        // Columnas en grilla (2, 3 o 4)
        $product_grid_cols_meta = get_user_meta($user_id, '_caja_product_grid_cols', true);
        $product_grid_cols = in_array($product_grid_cols_meta, array('2', '3', '4')) ? $product_grid_cols_meta : '2';

        // Permitir alternar vista al cajero
        $allow_layout_toggle_meta = get_user_meta($user_id, '_caja_allow_layout_toggle', true);
        $allow_layout_toggle = ($allow_layout_toggle_meta !== '0' && $allow_layout_toggle_meta !== false);

        $status = get_user_meta($user_id, '_caja_status', true);
        if (empty($status)) {
            $status = 'active';
        }

        return array(
            'is_admin'                => false,
            'role'                    => $primary_role,
            'role_name'               => $role_name,
            'assigned_sectors'        => array_values($assigned_sectors),
            'can_view_products'       => $can_view_products,
            'can_view_orders'         => $can_view_orders,
            'can_change_sector'       => $can_change_sector,
            'can_view_customer_data'  => $can_view_customer_data,
            'can_view_packaging'      => $can_view_packaging,
            'can_dispatch_orders'     => $can_dispatch_orders,
            'product_layout'          => $product_layout,
            'product_grid_cols'       => $product_grid_cols,
            'allow_layout_toggle'     => $allow_layout_toggle,
            'status'                  => ($status === 'disabled') ? 'disabled' : 'active',
        );
    }

    /**
     * Obtener listado de empleados para el panel de administración
     */
    public static function get_employees() {
        $users = get_users(array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ));

        $current_user_id = get_current_user_id();
        $employees = array();

        foreach ($users as $user) {
            $user_id = $user->ID;
            $roles = (array) $user->roles;
            $is_admin = in_array('administrator', $roles);
            $is_caja_cajero = in_array('caja_cajero', $roles);
            $is_caja_encargado = in_array('caja_encargado', $roles);
            $has_caja_meta = !empty(get_user_meta($user_id, '_caja_role', true));

            // Filtrar únicamente administradores o usuarios de caja
            if (!$is_admin && !$is_caja_cajero && !$is_caja_encargado && !$has_caja_meta) {
                continue;
            }

            $perms = self::get_user_permissions($user_id);

            $employees[] = array(
                'id'                      => $user_id,
                'username'                => $user->user_login,
                'name'                    => !empty($user->display_name) ? $user->display_name : $user->user_login,
                'email'                   => $user->user_email,
                'role'                    => $perms['role'],
                'role_name'               => $perms['role_name'],
                'is_admin'                => $perms['is_admin'],
                'assigned_sectors'        => $perms['assigned_sectors'],
                'can_view_products'       => $perms['can_view_products'],
                'can_view_orders'         => $perms['can_view_orders'],
                'can_change_sector'       => $perms['can_change_sector'],
                'can_view_customer_data'  => $perms['can_view_customer_data'],
                'can_view_packaging'      => $perms['can_view_packaging'],
                'can_dispatch_orders'     => $perms['can_dispatch_orders'],
                'product_layout'          => $perms['product_layout'],
                'product_grid_cols'       => $perms['product_grid_cols'],
                'allow_layout_toggle'     => $perms['allow_layout_toggle'],
                'status'                  => $perms['status'],
                'is_current_user'         => ($user_id === $current_user_id),
                'registered'              => date_i18n('d/m/Y', strtotime($user->user_registered)),
            );
        }

        return $employees;
    }

    /**
     * Guardar o crear empleado
     */
    public static function save_employee($data) {
        $user_id = !empty($data['id']) ? intval($data['id']) : 0;
        $username = !empty($data['username']) ? sanitize_user(trim($data['username'])) : '';
        $name = !empty($data['name']) ? sanitize_text_field(trim($data['name'])) : '';
        $email = !empty($data['email']) ? sanitize_email(trim($data['email'])) : '';
        $password = !empty($data['password']) ? trim($data['password']) : '';
        $role = !empty($data['role']) ? sanitize_key($data['role']) : 'caja_cajero';
        $status = (!empty($data['status']) && $data['status'] === 'disabled') ? 'disabled' : 'active';

        // Validar rol permitido
        $allowed_roles = array('caja_cajero', 'caja_encargado', 'administrator');
        if (!in_array($role, $allowed_roles)) {
            $role = 'caja_cajero';
        }

        // Sectores asignados
        $assigned_sectors = array();
        if (isset($data['assigned_sectors']) && is_array($data['assigned_sectors'])) {
            $assigned_sectors = array_values(array_filter(array_map('sanitize_text_field', $data['assigned_sectors'])));
        }
        if (empty($assigned_sectors)) {
            $all = self::get_available_sectors();
            $assigned_sectors = !empty($all) ? array($all[0]) : array('Barra Principal');
        }

        // Permisos booleanos
        $can_view_products = (!empty($data['can_view_products']) && ($data['can_view_products'] === '1' || $data['can_view_products'] === true || $data['can_view_products'] === 'true')) ? '1' : '0';
        $can_view_orders = (!empty($data['can_view_orders']) && ($data['can_view_orders'] === '1' || $data['can_view_orders'] === true || $data['can_view_orders'] === 'true')) ? '1' : '0';
        $can_change_sector = (!empty($data['can_change_sector']) && ($data['can_change_sector'] === '1' || $data['can_change_sector'] === true || $data['can_change_sector'] === 'true')) ? '1' : '0';
        $can_view_customer_data = (!empty($data['can_view_customer_data']) && ($data['can_view_customer_data'] === '1' || $data['can_view_customer_data'] === true || $data['can_view_customer_data'] === 'true')) ? '1' : '0';
        $can_view_packaging = (!empty($data['can_view_packaging']) && ($data['can_view_packaging'] === '1' || $data['can_view_packaging'] === true || $data['can_view_packaging'] === 'true')) ? '1' : '0';
        $can_dispatch_orders = (!empty($data['can_dispatch_orders']) && ($data['can_dispatch_orders'] === '1' || $data['can_dispatch_orders'] === true || $data['can_dispatch_orders'] === 'true')) ? '1' : '0';
        $product_layout = (!empty($data['product_layout']) && in_array($data['product_layout'], array('list', 'grid'))) ? sanitize_key($data['product_layout']) : 'grid';
        $product_grid_cols = (!empty($data['product_grid_cols']) && in_array($data['product_grid_cols'], array('2', '3', '4'))) ? sanitize_key($data['product_grid_cols']) : '2';
        $allow_layout_toggle = (!empty($data['allow_layout_toggle']) && ($data['allow_layout_toggle'] === '1' || $data['allow_layout_toggle'] === true || $data['allow_layout_toggle'] === 'true')) ? '1' : '0';

        if ($user_id > 0) {
            // Actualización de empleado existente
            $user = get_userdata($user_id);
            if (!$user) {
                return new WP_Error('user_not_found', __('El usuario no existe.', 'emp-caja'));
            }

            $userdata = array(
                'ID'           => $user_id,
                'display_name' => $name ? $name : $user->display_name,
            );

            if ($email && $email !== $user->user_email) {
                if (email_exists($email) && email_exists($email) != $user_id) {
                    return new WP_Error('email_exists', __('Ese correo electrónico ya está registrado por otro usuario.', 'emp-caja'));
                }
                $userdata['user_email'] = $email;
            }

            if (!empty($password)) {
                $userdata['user_pass'] = $password;
            }

            $update_res = wp_update_user($userdata);
            if (is_wp_error($update_res)) {
                return $update_res;
            }

            // Actualizar rol WP (si no es el usuario actual degradándose a sí mismo)
            if ($user_id !== get_current_user_id() || $role === 'administrator') {
                $user_obj = new WP_User($user_id);
                $user_obj->set_role($role);
            }

        } else {
            // Creación de nuevo empleado
            if (empty($username)) {
                return new WP_Error('missing_username', __('El nombre de usuario es obligatorio.', 'emp-caja'));
            }
            if (username_exists($username)) {
                return new WP_Error('username_exists', __('El nombre de usuario ya existe. Elegí otro.', 'emp-caja'));
            }
            if (empty($password)) {
                // Generar contraseña aleatoria si no fue provista
                $password = wp_generate_password(12, false);
            }
            if (empty($email)) {
                $email = $username . '@local.caja';
            } elseif (email_exists($email)) {
                return new WP_Error('email_exists', __('Ese correo electrónico ya está registrado.', 'emp-caja'));
            }

            $user_id = wp_insert_user(array(
                'user_login'   => $username,
                'user_pass'    => $password,
                'user_email'   => $email,
                'display_name' => $name ? $name : $username,
                'role'         => $role,
            ));

            if (is_wp_error($user_id)) {
                return $user_id;
            }
        }

        // Actualizar metadatos de configuración
        update_user_meta($user_id, '_caja_role', $role);
        update_user_meta($user_id, '_caja_assigned_sectors', $assigned_sectors);
        update_user_meta($user_id, '_caja_can_view_products', $can_view_products);
        update_user_meta($user_id, '_caja_can_view_orders', $can_view_orders);
        update_user_meta($user_id, '_caja_can_change_sector', $can_change_sector);
        update_user_meta($user_id, '_caja_can_view_customer_data', $can_view_customer_data);
        update_user_meta($user_id, '_caja_can_view_packaging', $can_view_packaging);
        update_user_meta($user_id, '_caja_can_dispatch_orders', $can_dispatch_orders);
        update_user_meta($user_id, '_caja_product_layout', $product_layout);
        update_user_meta($user_id, '_caja_product_grid_cols', $product_grid_cols);
        update_user_meta($user_id, '_caja_allow_layout_toggle', $allow_layout_toggle);
        update_user_meta($user_id, '_caja_status', $status);

        return self::get_user_permissions($user_id);
    }

    /**
     * Alternar estado Activo / Deshabilitado
     */
    public static function toggle_status($user_id) {
        $user_id = intval($user_id);
        if ($user_id === get_current_user_id()) {
            return new WP_Error('cannot_toggle_self', __('No podés deshabilitar tu propio usuario actual.', 'emp-caja'));
        }

        $current = get_user_meta($user_id, '_caja_status', true);
        $new_status = ($current === 'disabled') ? 'active' : 'disabled';
        update_user_meta($user_id, '_caja_status', $new_status);

        return $new_status;
    }

    /**
     * Eliminar empleado
     */
    public static function delete_employee($user_id) {
        $user_id = intval($user_id);
        if ($user_id === get_current_user_id()) {
            return new WP_Error('cannot_delete_self', __('No podés eliminar tu propio usuario actual.', 'emp-caja'));
        }

        require_once(ABSPATH . 'wp-admin/includes/user.php');
        $res = wp_delete_user($user_id);
        if (!$res) {
            return new WP_Error('delete_failed', __('No se pudo eliminar el empleado.', 'emp-caja'));
        }

        return true;
    }
}
