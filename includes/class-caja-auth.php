<?php
/**
 * Clase para Autenticación y Control de Acceso
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Auth {

    /**
     * Procesar Inicio de Sesión Vía AJAX
     */
    public static function handle_login() {
        check_ajax_referer('batllie_caja_nonce', 'security');

        $username = isset($_POST['username']) ? sanitize_user(wp_unslash($_POST['username'])) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';

        if (empty($username) || empty($password)) {
            wp_send_json_error(array(
                'message' => __('Por favor ingresa usuario y contraseña.', 'emp-caja')
            ));
        }

        $credentials = array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true
        );

        $user = wp_signon($credentials, is_ssl());

        if (is_wp_error($user)) {
            wp_send_json_error(array(
                'message' => __('Usuario o contraseña incorrectos.', 'emp-caja')
            ));
        }

        // Verificar permisos mínimos (administrador, gestor de tienda o rol con capacidades)
        if (!user_can($user, 'manage_woocommerce') && !user_can($user, 'edit_posts') && !user_can($user, 'read')) {
            wp_logout();
            wp_send_json_error(array(
                'message' => __('Tu cuenta no tiene permisos para operar la caja.', 'emp-caja')
            ));
        }

        wp_set_current_user($user->ID);

        wp_send_json_success(array(
            'message'  => __('Inicio de sesión exitoso.', 'emp-caja'),
            'nonce'    => wp_create_nonce('batllie_caja_nonce'),
            'user'     => array(
                'name'  => $user->display_name,
                'email' => $user->user_email
            )
        ));
    }

    /**
     * Procesar Cierre de Sesión Vía AJAX
     */
    public static function handle_logout() {
        check_ajax_referer('batllie_caja_nonce', 'security');
        wp_logout();
        wp_send_json_success(array(
            'message' => __('Sesión finalizada.', 'emp-caja'),
            'nonce'   => wp_create_nonce('batllie_caja_nonce')
        ));
    }

    /**
     * Comprobar si el usuario actual puede operar la caja
     */
    public static function current_user_can_access() {
        if (!is_user_logged_in()) {
            return false;
        }
        // Permitir a administradores, editores, gestores de tienda y usuarios autenticados
        return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders') || current_user_can('read');
    }
}
