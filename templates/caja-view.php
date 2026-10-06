<?php
/**
 * Template principal de la vista de caja
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="batllie-caja-root" class="batllie-caja-container">
    <?php if (!is_user_logged_in() || !Batllie_Caja_Auth::current_user_can_access()) : ?>
        <div id="caja-auth-view">
            <?php include EMP_CAJA_PATH . 'templates/login-form.php'; ?>
        </div>
        <div id="caja-main-view" style="display:none;">
            <?php include EMP_CAJA_PATH . 'templates/dashboard.php'; ?>
        </div>
    <?php else : ?>
        <div id="caja-auth-view" style="display:none;">
            <?php include EMP_CAJA_PATH . 'templates/login-form.php'; ?>
        </div>
        <div id="caja-main-view">
            <?php include EMP_CAJA_PATH . 'templates/dashboard.php'; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    // Limpiar cualquier credencial en URL (?username=...&password=...) para seguridad inmediata
    if (window.location.search && (window.location.search.indexOf('password=') !== -1 || window.location.search.indexOf('username=') !== -1)) {
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
        }
    }
    // Añadir clase al body para modo aislado
    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('batllie-caja-active');
    });
</script>
