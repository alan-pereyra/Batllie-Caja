<?php
/**
 * Template de Formulario de Inicio de Sesión de Caja
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="caja-login-wrapper">
    <div class="caja-login-card">
        <div class="caja-login-header">
            <div class="caja-login-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
            </div>
            <h2><?php _e('Batllie Caja', 'emp-caja'); ?></h2>
            <p><?php _e('Control de Pedidos y Terminal Mostrador', 'emp-caja'); ?></p>
        </div>

        <form id="caja-login-form" class="caja-form">
            <div id="caja-login-error" class="caja-alert caja-alert-danger" style="display:none;"></div>

            <div class="caja-form-group">
                <label for="caja-username"><?php _e('Usuario o Correo Electrónico', 'emp-caja'); ?></label>
                <div class="caja-input-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <input type="text" id="caja-username" name="username" required placeholder="<?php esc_attr_e('Ingresa tu usuario...', 'emp-caja'); ?>" autocomplete="username" />
                </div>
            </div>

            <div class="caja-form-group">
                <label for="caja-password"><?php _e('Contraseña', 'emp-caja'); ?></label>
                <div class="caja-input-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input type="password" id="caja-password" name="password" required placeholder="••••••••" autocomplete="current-password" />
                </div>
            </div>

            <button type="submit" class="caja-btn caja-btn-primary caja-btn-block" id="caja-login-btn">
                <span class="caja-btn-text"><?php _e('Acceder a la Caja', 'emp-caja'); ?></span>
                <span class="caja-btn-spinner" style="display:none;"></span>
            </button>
        </form>

        <div class="caja-login-footer">
            <small><?php _e('Acceso restringido para personal autorizado', 'emp-caja'); ?></small>
        </div>
    </div>
</div>
