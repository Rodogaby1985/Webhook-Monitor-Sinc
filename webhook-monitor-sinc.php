<?php
/*
Plugin Name: Webhook Monitor & Sinc
Description: Monitorea y reactiva automáticamente los webhooks clave de WooCommerce, envía alertas por email, y permite resincronizar pedidos desde el admin con indicador visual.
Version: 1.0
Author: Rodogaby1985 & Copilot
*/

if (!defined('ABSPATH')) exit;

// ------------- CONFIGURACIÓN FIJA DE WEBHOOKS -------------
define('WMS_WEBHOOKS_CLAVE', ['Ninox - Orden creada', 'Ninox - Orden actualizada']);

// ------------- SETTINGS & OPTION MANAGEMENT -------------
function wms_default_settings() {
    return [
        'cron_enabled' => 1,
        'cron_hour' => 23,
        'cron_minute' => 59,
        'alert_emails' => get_option('admin_email'),
    ];
}

function wms_get_settings() {
    $defaults = wms_default_settings();
    $settings = get_option('wms_settings', []);
    return array_merge($defaults, $settings);
}

function wms_save_settings($settings) {
    update_option('wms_settings', $settings);
}

// ------------- ADMIN SETTINGS PAGE -------------
add_action('admin_menu', function() {
    add_options_page(
        'Webhook Monitor & Sinc',
        'Webhook Monitor & Sinc',
        'manage_options',
        'wms-settings',
        'wms_settings_page'
    );
});

function wms_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = wms_get_settings();

    if ($_POST && check_admin_referer('wms_save_settings')) {
        $settings['cron_enabled'] = isset($_POST['cron_enabled']) ? 1 : 0;
        $settings['cron_hour'] = intval($_POST['cron_hour']);
        $settings['cron_minute'] = intval($_POST['cron_minute']);
        $settings['alert_emails'] = sanitize_text_field($_POST['alert_emails']);
        wms_save_settings($settings);
        wms_update_cron();
        echo '<div class="updated"><p>¡Opciones guardadas!</p></div>';
    }

    ?>
    <div class="wrap">
        <h2>Webhook Monitor & Sinc - Ajustes</h2>
        <form method="post">
            <?php wp_nonce_field('wms_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Activar revisión diaria (cron)</th>
                    <td><input type="checkbox" name="cron_enabled" <?php checked($settings['cron_enabled'], 1); ?> /></td>
                </tr>
                <tr>
                    <th scope="row">Horario de revisión (cron)</th>
                    <td>
                        <input type="number" name="cron_hour" min="0" max="23" value="<?php echo esc_attr($settings['cron_hour']); ?>" /> :
                        <input type="number" name="cron_minute" min="0" max="59" value="<?php echo esc_attr($settings['cron_minute']); ?>" />
                        <span>(hora:minuto, formato 24h)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Correos electrónicos de alerta</th>
                    <td>
                        <input type="text" name="alert_emails" value="<?php echo esc_attr($settings['alert_emails']); ?>" style="width:400px;" />
                        <br><em>Separados por coma. Ejemplo: correo1@mail.com,correo2@mail.com</em>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Webhooks clave</th>
                    <td>
                        <input type="text" value="<?php echo esc_attr(implode(', ', WMS_WEBHOOKS_CLAVE)); ?>" readonly />
                        <br><em>Configurados en el código del plugin, no editables aquí.</em>
                    </td>
                </tr>
            </table>
            <p><input type="submit" class="button-primary" value="Guardar opciones"></p>
        </form>
    </div>
    <?php
}

// ------------- CRON MANAGEMENT -------------
function wms_update_cron() {
    $settings = wms_get_settings();
    $timestamp = wms_next_cron_time($settings['cron_hour'], $settings['cron_minute']);
    wp_clear_scheduled_hook('wms_daily_check');
    if ($settings['cron_enabled']) {
        wp_schedule_event($timestamp, 'daily', 'wms_daily_check');
    }
}
register_activation_hook(__FILE__, 'wms_update_cron');
register_deactivation_hook(__FILE__, 'wms_remove_cron');
function wms_remove_cron() {
    wp_clear_scheduled_hook('wms_daily_check');
    wms_send_alert('El plugin "Webhook Monitor & Sinc" ha sido desactivado.');
}
function wms_next_cron_time($hour, $minute) {
    $now = current_time('timestamp');
    $next = mktime($hour, $minute, 0, date('n', $now), date('j', $now), date('Y', $now));
    if ($next <= $now) $next += DAY_IN_SECONDS;
    return $next;
}
add_action('wms_daily_check', 'wms_webhook_check');

// ------------- WEBHOOK CHECK AND ALERT -------------
function wms_webhook_check() {
    if (!class_exists('WC_Webhook')) return;
    $webhooks = WC_Webhook::get_webhooks();
    $settings = wms_get_settings();
    foreach ($webhooks as $webhook) {
        if (in_array($webhook->get_name(), WMS_WEBHOOKS_CLAVE)) {
            if ($webhook->get_status() !== 'active') {
                $webhook->set_status('active');
                $webhook->save();
                wms_send_alert('¡El webhook "' . $webhook->get_name() . '" estaba desactivado y fue reactivado automáticamente!');
            }
        }
    }
}

// ------------- ALERT EMAIL FUNCTION -------------
function wms_send_alert($message) {
    $settings = wms_get_settings();
    $emails = array_map('trim', explode(',', $settings['alert_emails']));
    $subject = 'Alerta Webhook Monitor & Sinc';
    foreach ($emails as $email) {
        wp_mail($email, $subject, $message);
    }
}

// ------------- ADMIN WEBHOOK CHECK ON LOAD -------------
add_action('admin_init', 'wms_webhook_check');

// ------------- PEDIDO RELOAD BUTTON IN ADMIN LIST -------------
add_filter('manage_edit-shop_order_columns', function($columns) {
    $columns['wms_sync_status'] = __('Sinc.', 'wms');
    return $columns;
});
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'wms_sync_status') {
        $nxsync = get_post_meta($post_id, 'nxsync', true);
        $nxsync_status = get_post_meta($post_id, 'nxsync_status', true);
        $color = ($nxsync && $nxsync_status) ? 'green' : 'red';
        $icon = $color === 'green' ? '✅' : '🔄';
        $url = wp_nonce_url(admin_url('admin-ajax.php?action=wms_reload_order&order_id=' . $post_id), 'wms_reload_' . $post_id);
        echo '<a href="' . $url . '" title="Forzar resincronización">' . $icon . '</a>';
    }
}, 10, 2);

// ------------- AJAX HANDLER FOR RELOAD -------------
add_action('wp_ajax_wms_reload_order', function() {
    if (!current_user_can('manage_woocommerce')) wp_die('Sin permisos');
    $order_id = intval($_GET['order_id'] ?? 0);
    check_admin_referer('wms_reload_' . $order_id);
    $order = wc_get_order($order_id);
    if (!$order) wp_die('Pedido no encontrado');
    $current_status = $order->get_status();
    // Cambiar a un estado diferente y volver, para disparar webhooks
    $new_status = ($current_status !== 'processing') ? 'processing' : 'on-hold';
    $order->update_status($new_status, 'Sincronización forzada WMS');
    $order->update_status($current_status, 'Sincronización revertida WMS');
    wp_redirect(admin_url('edit.php?post_type=shop_order'));
    exit;
});